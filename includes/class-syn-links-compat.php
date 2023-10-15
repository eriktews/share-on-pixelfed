<?php
/**
 * Syndication Links compatibility.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * All things Syndication Links.
 */
class Syn_Links_Compat {
	/**
	 * Register Syndication Links callbacks.
	 *
	 * @since 0.8.0
	 */
	public static function register() {
		$options = get_options();

		$post_types = (array) $options['post_types']; // Should make this more robust.

		foreach ( $post_types as $post_type ) {
			add_filter( "get_{$post_type}_syndication_links", array( __CLASS__, 'syndication_links' ), 10, 2 );
		}
	}

	/**
	 * Adds the Pixelfed URL to Syndication Links' list of URLs.
	 *
	 * @param  array $urls      Syndication links.
	 * @param  array $object_id The post we're gathering these links for.
	 * @return array            Modified syndication links.
	 *
	 * @since 0.8.0
	 */
	public static function syndication_links( $urls, $object_id ) {
		$pixelfed_url = get_post_meta( $object_id, '_share_on_pixelfed_url', true );

		if ( ! empty( $pixelfed_url ) ) {
			$urls[] = $pixelfed_url;
		}

		return $urls;
	}
}
