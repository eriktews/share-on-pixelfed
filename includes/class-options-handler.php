<?php
/**
 * Handles WP Admin settings pages and the like.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Options handler class.
 */
class Options_Handler {
	/**
	 * WordPress' default post types.
	 *
	 * @since 0.1.0
	 * @var   array WordPress' default post types, minus 'post' itself.
	 */
	const DEFAULT_POST_TYPES = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'user_request',
		'oembed_cache',
		'wp_block',
	);

	/**
	 * Plugin options.
	 *
	 * @since 0.1.0
	 * @var   array $options Plugin options.
	 */
	private $options = array(
		'pixelfed_host'          => '',
		'pixelfed_client_id'     => '',
		'pixelfed_client_secret' => '',
		'pixelfed_access_token'  => '',
		'post_types'             => array(),
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->options = get_option(
			'share_on_pixelfed_settings',
			$this->options
		);

		add_action( 'admin_menu', array( $this, 'create_menu' ) );
	}

	/**
	 * Registers the plugin settings page.
	 *
	 * @since 0.1.0
	 */
	public function create_menu() {
		add_options_page(
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			'manage_options',
			'share-on-pixelfed',
			array( $this, 'settings_page' )
		);
		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 *
	 * @since 0.1.0
	 */
	public function add_settings() {
		register_setting(
			'share-on-pixelfed-settings-group',
			'share_on_pixelfed_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @since  0.1.0
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			// Post types considered valid.
			$supported_post_types = array_diff(
				get_post_types(),
				self::DEFAULT_POST_TYPES
			);

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types, true ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		if ( isset( $settings['pixelfed_host'] ) ) {
			if ( untrailingslashit( $settings['pixelfed_host'] ) !== $this->options['pixelfed_host'] && wp_http_validate_url( $settings['pixelfed_host'] ) ) {
				// The new URL differs from the old one. Someone's switched
				// instances.
				if ( $this->revoke_access() ) {
					// Update instance URL, forget client ID and secret. A new
					// client ID and secret will be requested the next time this
					// page is visited.
					$this->options['pixelfed_host']          = untrailingslashit( $settings['pixelfed_host'] );
					$this->options['pixelfed_client_id']     = '';
					$this->options['pixelfed_client_secret'] = '';
				} elseif ( '' === $this->options['pixelfed_host'] ) {
					// First time instance's set?
					$this->options['pixelfed_host'] = untrailingslashit( $settings['pixelfed_host'] );
				}
			} elseif ( '' === $settings['pixelfed_host'] ) {
				// Assuming sharing should be disabled.
				$this->options['pixelfed_host'] = '';
				$this->revoke_access();
			}
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 *
	 * @since 0.1.0
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?></h1>

			<h2><?php esc_html_e( 'Settings', 'share-on-pixelfed' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'share-on-pixelfed-settings-group' );

				// Post types considered valid.
				$supported_post_types = array_diff(
					get_post_types(),
					self::DEFAULT_POST_TYPES
				);
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="share_on_pixelfed_settings[pixelfed_host]"><?php esc_html_e( 'Instance', 'share-on-pixelfed' ); ?></label></th>
						<td><input type="text" id="share_on_pixelfed_settings[pixelfed_host]" name="share_on_pixelfed_settings[pixelfed_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['pixelfed_host'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Your Pixelfed instance&rsquo;s URL.', 'share-on-pixelfed' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Supported Post Types', 'share-on-pixelfed' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php
							foreach ( $supported_post_types as $post_type ) :
								$post_type = get_post_type_object( $post_type );
								?>
								<li><label><input type="checkbox" name="share_on_pixelfed_settings[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $this->options['post_types'], true ) ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></label></li>
								<?php
							endforeach;
							?>
						</ul>
						<p class="description"><?php esc_html_e( 'Post types for which sharing to Pixelfed is possible. (Sharing can still be disabled on a per-post basis.)', 'share-on-pixelfed' ); ?></p></td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Authorize Access', 'share-on-pixelfed' ); ?></h2>
			<?php
			if ( ! empty( $this->options['pixelfed_host'] ) ) {
				// A valid instance URL was set.
				if ( empty( $this->options['pixelfed_client_id'] ) || empty( $this->options['pixelfed_client_secret'] ) ) {
					// No app is currently registered. Let's try to fix that!
					$this->register_app();
				}

				if ( ! empty( $this->options['pixelfed_client_id'] ) && ! empty( $this->options['pixelfed_client_secret'] ) ) {
					// An app was successfully registered.
					if ( ! empty( $_GET['code'] ) ) {
						// Access token request.
						if ( $this->request_access_token( sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) ) {
							?>
							<div class="notice notice-success is-dismissible">
								<p><?php esc_html_e( 'Access granted!', 'share-on-pixelfed' ); ?></p>
							</div>
							<?php
						}
					}

					if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), basename( __FILE__ ) ) ) {
						// Request to revoke access.
						$this->revoke_access();
					}

					if ( empty( $this->options['pixelfed_access_token'] ) ) {
						// No access token exists. Echo authorization link.
						$url = $this->options['pixelfed_host'] . '/oauth/authorize?' . http_build_query(
							array(
								'response_type' => 'code',
								'client_id'     => $this->options['pixelfed_client_id'],
								'client_secret' => $this->options['pixelfed_client_secret'],
								'redirect_uri'  => add_query_arg(
									array(
										'page' => 'share-on-pixelfed',
									),
									admin_url( 'options-general.php' )
								), // Redirect here after authorization.
								'scope'         => 'read write',
							)
						);
						?>
						<p><?php esc_html_e( 'Authorize WordPress to read and write to your Pixelfed timeline in order to enable crossposting.', 'share-on-pixelfed' ); ?></p>
						<p><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'share-on-pixelfed' ) ); ?>
						<?php
					} else {
						// An access token exists.
						?>
						<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read and write to your Pixelfed timeline.', 'share-on-pixelfed' ); ?></p>
						<p class="submit">
							<?php
							printf(
								'<a href="%1$s" class="button">%2$s</a>',
								esc_url(
									add_query_arg(
										array(
											'page'     => 'share-on-pixelfed',
											'action'   => 'revoke',
											'_wpnonce' => wp_create_nonce( basename( __FILE__ ) ),
										),
										admin_url( 'options-general.php' )
									)
								),
								esc_html__( 'Revoke Access', 'share-on-pixelfed' )
							);
							?>
						</p>
						<?php
					}
				} else {
					// Still couldn't register our app.
					?>
					<p><?php esc_html_e( 'Something went wrong contacting your Pixelfed instance. Please reload this page to try again.', 'share-on-pixelfed' ); ?></p>
					<?php
				}
			} else {
				// We can't do much without an instance URL.
				?>
				<p><?php esc_html_e( 'Please fill out and save your Pixelfed instance&rsquo;s URL first.', 'share-on-pixelfed' ); ?></p>
				<?php
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
				?>
				<h2><?php esc_html_e( 'Debugging', 'share-on-pixelfed' ); ?></h2>
				<p><?php esc_html_e( 'Below information is not meant to be shared with anyone but may help when troubleshooting issues.', 'share-on-pixelfed' ); ?></p>
				<p><textarea class="widefat" rows="5"><?php print_r( $this->options ); ?></textarea></p><?php // phpcs:ignore WordPress.PHP.DevelopmentFunctions ?>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Registers a new Pixelfed client.
	 *
	 * @since 0.1.0
	 */
	private function register_app() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Register a new app. Should only run once (per host)!
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => __( 'Share on Pixelfed' ),
					'redirect_uris' => add_query_arg(
						array(
							'page' => 'share-on-pixelfed',
						),
						admin_url(
							'options-general.php'
						)
					), // Allowed redirect URLs.
					'scopes'        => 'write:media write:statuses read:accounts read:statuses',
					'website'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['pixelfed_client_id']     = sanitize_text_field( $app->client_id );
			$this->options['pixelfed_client_secret'] = sanitize_text_field( $app->client_secret );
			update_option( 'share_on_pixelfed_settings', $this->options );
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @since 0.1.0
	 * @param string $code Authorization code.
	 */
	private function request_access_token( $code ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'share-on-pixelfed',
						),
						admin_url( 'options-general.php' )
					), // Redirect here after authorization.
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;
			update_option( 'share_on_pixelfed_settings', $this->options );
			return true;
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return false;
	}

	/**
	 * Revokes WordPress' access to Pixelfed.
	 *
	 * @since  0.1.0
	 * @return boolean If access was revoked.
	 */
	private function revoke_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_client_id'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_client_secret'] ) ) {
			return false;
		}

		// Revoke access.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/revoke',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'token'         => $this->options['pixelfed_access_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			// Success. Delete access token.
			$this->options['pixelfed_access_token'] = '';
			update_option( 'share_on_pixelfed_settings', $this->options );

			return true;
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		// Something went wrong.
		return false;
	}

	/**
	 * Returns the plugin options.
	 *
	 * @since  0.2.0
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}
}
