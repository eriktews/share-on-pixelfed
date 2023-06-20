<?php
/**
 * All things images.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Image handler class.
 */
class Image_Handler {
	/**
	 * Returns a post's first or featured image, with alt text (if any).
	 *
	 * @param  WP_Post $post Post object.
	 * @return array         Attachment array.
	 */
	public static function get_image( $post ) {
		$options = \Share_On_Pixelfed\Share_On_Pixelfed::get_instance()
			->get_options_handler()
			->get_options();

		if ( ! empty( $options['use_first_image'] ) ) {
			// Always parse post content for images and alt text.
			$referenced_images = static::get_referenced_images( $post );

			foreach ( $referenced_images as $id => $alt ) {
				$thumb_id = $id;
				break; // Return only the first ID.
			}
		} elseif ( has_post_thumbnail( $post->ID ) ) {
			// Get post thumbnail (i.e., Featured Image).
			$thumb_id = get_post_thumbnail_id( $post->ID );
		}

		if ( empty( $thumb_id ) ) {
			// Nothing to do.
			return array( 0, '' );
		}

		// Fetch referenced images, but only if we haven't already done so.
		$referenced_images = isset( $referenced_images )
			? $referenced_images
			: static::get_referenced_images( $post );

		// Convert the single image ID into something of the format `array( $id, 'Alt text.' )`.
		return static::add_alt_text( $thumb_id, $referenced_images ); // Can be made simpler, but ... next time.
	}

	/**
	 * Uploads an image and returns a (single) media ID.
	 *
	 * @since 0.7.0
	 *
	 * @param  int    $thumb_id Image ID.
	 * @param  string $alt      Alt text.
	 * @param  int    $post_id  Post ID.
	 * @return string|null      Unique media ID, or nothing on failure.
	 */
	public static function upload_thumbnail( $thumb_id, $alt = '', $post_id = 0 ) {
		$file_path = '';

		// Grab the "large" image.
		$image   = wp_get_attachment_image_src( $thumb_id, apply_filters( 'share_on_pixelfed_image_size', 'large', $thumb_id ) );
		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not,
			// e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original image instead.
			$url = wp_get_attachment_url( $thumb_id ); // Original image URL.
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
		$file_path = apply_filters( 'share_on_pixelfed_image_path', $file_path, $post_id ); // We should deprecate this.

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( '' !== $alt ) {
			error_log( "[Share on Pixelfed] Found the following alt text for the attachment with ID $thumb_id: $alt" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;

			error_log( "[Share on Pixelfed] Here's the `alt` bit of what we're about to send the Pixelfed API: `$body`" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( "[Share on Pixelfed] Did not find alt text for the attachment with ID $thumb_id" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--';

		$options = \Share_On_Pixelfed\Share_On_Pixelfed::get_instance()
			->get_options_handler()
			->get_options();

		$response = wp_remote_post(
			esc_url_raw( $options['pixelfed_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $options['pixelfed_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format' => 'body',
				'body'        => $body,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$media = json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		} elseif ( ! empty( $media->error ) ) {
			update_post_meta( $post_id, '_share_on_pixelfed_error', sanitize_text_field( $media->error ) );
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Returns the file path of the first image inside a post's content.
	 *
	 * @since 0.7.0
	 *
	 * @param  int $post_id Post ID.
	 * @return int|null     Image ID, or nothing on failure.
	 */
	public static function find_first_image( $post_id ) {
		$post = get_post( $post_id );

		// Assumes `src` value is wrapped in quotes. This will almost always be
		// the case.
		preg_match_all( '~<img(?:.+?)src=[\'"]([^\'"]+)[\'"](?:.*?)>~i', $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			$filename = pathinfo( $match, PATHINFO_FILENAME );
			$original = preg_replace( '~-(?:\d+x\d+|scaled|rotated)$~', '', $filename ); // Strip dimensions, etc., off resized images.

			$url = str_replace( $filename, $original, $match );

			// Convert URL back to attachment ID.
			$thumb_id = (int) attachment_url_to_postid( $url );

			if ( 0 === $thumb_id ) {
				// Unknown to WordPress.
				continue;
			}

			return $thumb_id;
		}
	}

	/**
	 * Attempts to find and return in-post images and their alt text.
	 *
	 * @param  WP_Post $post Post object.
	 * @return array         Image array.
	 */
	protected static function get_referenced_images( $post ) {
		$images = array();

		// Wrap post content in a dummy `div`, as there must (!) be a root-level
		// element at all times.
		$html = '<div>' . mb_convert_encoding( $post->post_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) . '</div>';

		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$xpath = new \DOMXPath( $doc );

		foreach ( $xpath->query( '//img' ) as $node ) {
			if ( ! $node->hasAttribute( 'src' ) || empty( $node->getAttribute( 'src' ) ) ) {
				continue;
			}

			$src      = $node->getAttribute( 'src' );
			$filename = pathinfo( $src, PATHINFO_FILENAME );
			$original = preg_replace( '~-(?:\d+x\d+|scaled|rotated)$~', '', $filename ); // Strip dimensions, etc., off resized images.

			$url = str_replace( $filename, $original, $src );

			// Convert URL back to attachment ID.
			$image_id = (int) attachment_url_to_postid( $url );

			if ( 0 === $image_id ) {
				// Unknown to WordPress.
				continue;
			}

			if ( ! isset( $images[ $image_id ] ) || '' === $images[ $image_id ] ) {
				// When an image is already present, overwrite it only if its
				// "known" alt text is empty.
				$images[ $image_id ] = $node->hasAttribute( 'alt' ) ? $node->getAttribute( 'alt' ) : '';
			}
		}

		return $images;
	}

	/**
	 * Returns alt text for a certain image.
	 *
	 * Looks through `$images` first, and falls back on what's stored in the
	 * `wp_postmeta` table.
	 *
	 * @param  int   $image_id          ID of the image we want to upload.
	 * @param  array $referenced_images In-post images and their alt attributes, to look through first.
	 * @return array                    An array with the image ID as its key and this image's alt attributes as its value.
	 */
	protected static function add_alt_text( $image_id, $referenced_images ) {
		if ( isset( $referenced_images[ $image_id ] ) && '' !== $referenced_images[ $image_id ] ) {
			// This image was found inside the post, with alt text.
			$alt = $referenced_images[ $image_id ];
		} else {
			// Fetch alt text from the `wp_postmeta` table.
			$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

			if ( '' === $alt ) {
				$alt = wp_get_attachment_caption( $image_id ); // Fallback to caption. Might return `false`.
			}
		}

		// Avoid double-encoded entities.
		return array(
			$image_id,
			is_string( $alt ) ? html_entity_decode( $alt, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) : '',
		);
	}
}
