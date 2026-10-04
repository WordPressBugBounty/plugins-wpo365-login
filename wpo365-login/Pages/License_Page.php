<?php

namespace Wpo\Pages;

use Wpo\Core\Extensions_Helpers;
use Wpo\Core\Plugin_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Options_Service;
use Wpo\Services\Log_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Pages\License_Page' ) ) {

	class License_Page {

		private static $extensions = array();

		public function __construct() {

			/**
			 * Load custom updater.
			 */

			// Multisite frontend
			if ( is_multisite() && ! is_network_admin() ) {
				return;
			}

			// Single site frontend
			if ( ! is_multisite() && ! is_admin() ) {
				return;
			}

			// Collect information about all activated extensions
			self::$extensions = Extensions_Helpers::get_active_extensions( false, 'network' );

			// No extensions so no need to add the license page
			if ( empty( self::$extensions ) ) {
				return;
			}

			/**
			 * Add admin page.
			 */
			add_action( 'admin_menu', '\Wpo\Pages\License_Page::license_menu' );
			add_action( 'network_admin_menu', '\Wpo\Pages\License_Page::license_menu' );

			/**
			 * Activate license.
			 */
			add_action( 'admin_init', '\Wpo\Pages\License_Page::activate_license' );

			/**
			 * Deactivate license.
			 */
			add_action( 'admin_init', '\Wpo\Pages\License_Page::deactivate_license' );

			/**
			 * Show activation result.
			 */
			add_action( 'admin_notices', '\Wpo\Pages\License_Page::activation_notice' );
			add_action( 'network_admin_notices', '\Wpo\Pages\License_Page::activation_notice' );
		}

		/**
		 * Adds a "Licenses" submenu page to the main WPO365 admin menu.
		 */
		public static function license_menu() {
			add_submenu_page( 'wpo365-wizard', 'Licenses', 'Licenses', 'delete_users', 'wpo365-manage-licenses', '\Wpo\Pages\License_Page::license_page' );
		}

		public static function activation_notice() {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: shows the result that activate_license() / deactivate_license() put in their redirect URL.
			if ( isset( $_GET['sl_activation'] ) && ! empty( $_GET['message'] ) && isset( $_GET['page'] ) && $_GET['page'] === 'wpo365-manage-licenses' ) {

				$message = sanitize_text_field( wp_unslash( $_GET['message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, see above.

				switch ( $_GET['sl_activation'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, see above.

					case 'false':
						?>
						<div class="notice notice-error" style="background-color: #ffffff;">
							<p><?php echo esc_html( $message ); ?></p>
						</div>
						<?php
						break;

					case 'true':
					default:
						?>
						<div class="notice notice-success" style="background-color: #ffffff;"><?php echo esc_html( $message ); ?></div>
						<?php
						break;
				}
			}
		}

		public static function activate_license() {

			// listen for our activate button to be clicked
			if ( isset( $_POST['activate_license'] ) && isset( $_POST['store_item_id'] ) ) {

				// run a quick security check
				if ( ! check_admin_referer( 'wpo365-manage-licenses', 'wpo365_license_nonce' ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not successfully verify nonce [check admin referrer failed]' );
					return;
				}

				// Same capability as the one required to open the Licenses page.
				if ( ! current_user_can( 'delete_users' ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> The current user is not allowed to manage licenses' );
					return;
				}

				$store_item_id = absint( wp_unslash( $_POST['store_item_id'] ) );

				foreach ( self::$extensions as $slug => $data ) {

					if ( $data['store_item_id'] === $store_item_id ) {
						$extension = $data;
						break;
					}
				}

				if ( empty( $extension ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not find extension for store item id ' . $store_item_id );
					return;
				}

				// retrieve the license from the POSTed data
				$license_key_name = 'license_' . $extension['store_item_id'];
				$license_key      = ! empty( $_POST[ $license_key_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $license_key_name ] ) ) : '';

				// Call the custom API.
				$url      = is_multisite() ? network_home_url() : home_url();
				$response = wp_remote_get( \sprintf( 'https://www.wpo365.com/?edd_action=activate_license&license=%s&item_id=%s&url=%s', $license_key, $extension['store_item_id'], $url ), array( 'sslverify' => ! Options_Service::get_global_boolean_var( 'skip_host_verification' ) ) );

				// make sure the response came back okay
				if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {

					if ( is_wp_error( $response ) ) {
						$message = $response->get_error_message();
					} else {
						Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Error occurred when activating license. Check the next line for the raw response message received.' );
						Log_Service::write_log( 'ERROR', $response );

						$message = __( 'An error occurred, please try again.' );
					}
				} else {

					$license_data = json_decode( wp_remote_retrieve_body( $response ) );

					if ( $license_data->license === 'invalid' ) {

						switch ( $license_data->error ) {

							case 'expired':
								$message = sprintf(
									/* translators: 1: Name of the WPO365 plugin 2: Expiration date */
									__( 'Your license key for <strong>%1$s</strong> expired on %2$s.' ),
									$extension['store_item'],
									date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires ) )
								);
								break;

							case 'disabled':
								$message = sprintf(
									/* translators: %s: Name of the WPO365 plugin */
									__( 'Your license key for <strong>%s</strong> has been disabled / revoked.' ),
									$extension['store_item']
								);
								break;

							case 'missing':
								$message = sprintf(
									/* translators: %s: License key */
									__( 'The license <strong>%s</strong> you entered does not exist.' ),
									$license_key
								);
								break;

							case 'missing_url':
								$message = __( 'URL not provided.' );
								break;

							case 'key_mismatch':
								$message = sprintf(
									/* translators: 1: License key 2: Name of the WPO365 plugin */
									__( 'The license <strong>%1$s</strong> appears to be an invalid license key for %2$s.' ),
									$license_key,
									$extension['store_item']
								);
								break;

							case 'item_name_mismatch':
								$message = sprintf(
									/* translators: 1: License key 2: Name of the WPO365 plugin */
									__( 'The license <strong>%1$s</strong> appears to be invalid for %2$s.' ),
									$license_key,
									$extension['store_item']
								);
								break;

							case 'invalid_item_id':
								$message = sprintf(
									/* translators: %s: Store item ID of the WPO365 plugin */
									__( 'The item ID <strong>%s</strong> appears to be invalid.' ),
									$extension['store_item_id']
								);
								break;

							case 'no_activations_left':
								$message = sprintf(
									/* translators: %s: License key */
									__( 'Your license key <strong>%s</strong> has reached its activation limit.' ),
									$license_key
								);
								break;

							case 'license_not_activable':
								$message = sprintf(
									/* translators: %s: License key */
									__( 'Cannot activate the parent license <strong>%s</strong> of a bundle.' ),
									$license_key
								);
								break;

							default:
								Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Error occurred when activating license. Check the next line for the license data received.' );
								Log_Service::write_log( 'ERROR', $license_data );

								$message = __( 'An error occurred, please try again.' );
								break;
						}
					}
				}

				$option_name = 'license_' . $extension['store_item_id'];
				$base_url    = is_multisite()
					? network_admin_url( 'admin.php?page=wpo365-manage-licenses' )
					: admin_url( 'admin.php?page=wpo365-manage-licenses' );

				if ( ! empty( $message ) ) {
					$redirect = add_query_arg(
						array(
							'sl_activation' => 'false',
							'message'       => rawurlencode( $message ),
						),
						$base_url
					);
				} else {
					Options_Service::add_update_option( $option_name, sprintf( '%s|%s', $license_key, $url ) ); // WPMU > Will update site option because this page is only availabe in the network-admin
					$redirect = add_query_arg(
						array(
							'sl_activation' => 'true',
							'message'       => rawurlencode( 'License for ' . $extension['store_item'] . ' has been successfully activated.' ),
						),
						$base_url
					);
				}

				\Wpo\Core\Plugin_Helpers::check_licenses();

				wp_safe_redirect( $redirect );
				exit();
			}
		}

		public static function deactivate_license() {

			if ( isset( $_POST['deactivate_license'] ) && isset( $_POST['store_item_id'] ) ) {

				// run a quick security check
				if ( ! check_admin_referer( 'wpo365-manage-licenses', 'wpo365_license_nonce' ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not successfully verify nonce [check admin referrer failed]' );
					return;
				}

				// Same capability as the one required to open the Licenses page.
				if ( ! current_user_can( 'delete_users' ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> The current user is not allowed to manage licenses' );
					return;
				}

				$store_item_id = absint( wp_unslash( $_POST['store_item_id'] ) );

				foreach ( self::$extensions as $slug => $data ) {

					if ( $data['store_item_id'] === $store_item_id ) {
						$extension = $data;
						break;
					}
				}

				if ( empty( $extension ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not find extension for store item id ' . $store_item_id );
					return;
				}

				// retrieve the license from the POSTed data
				$license_key_name = 'license_' . $extension['store_item_id'];
				$license_key      = ! empty( $_POST[ $license_key_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $license_key_name ] ) ) : '';

				// Call the custom API.
				$url      = is_multisite() ? network_home_url() : home_url();
				$response = wp_remote_get( \sprintf( 'https://www.wpo365.com/?edd_action=deactivate_license&license=%s&item_id=%s&url=%s', $license_key, $extension['store_item_id'], $url ), array( 'sslverify' => ! Options_Service::get_global_boolean_var( 'skip_host_verification' ) ) );

				// make sure the response came back okay
				if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {

					if ( is_wp_error( $response ) ) {
						$message = $response->get_error_message();
					} else {
						$message = __( 'An error occurred, please try again.' );
					}
				}

				$option_name = 'license_' . $extension['store_item_id'];
				$base_url    = is_multisite()
					? network_admin_url( 'admin.php?page=wpo365-manage-licenses' )
					: admin_url( 'admin.php?page=wpo365-manage-licenses' );

				if ( ! empty( $message ) ) {
					$redirect = add_query_arg(
						array(
							'sl_activation' => 'false',
							'message'       => rawurlencode( $message ),
						),
						$base_url
					);
				} else {
					$license_data = json_decode( wp_remote_retrieve_body( $response ) );

					if ( $license_data->license === 'deactivated' ) {
						$redirect = add_query_arg(
							array(
								'sl_activation' => 'true',
								'message'       => rawurlencode( 'License for ' . $extension['store_item'] . ' has been successfully deactivated.' ),
							),
							$base_url
						);
					} else {
						$redirect = add_query_arg(
							array(
								'sl_activation' => 'true',
								'message'       => rawurlencode( 'License for ' . $extension['store_item'] . ' could not be deactivated. Please try again.' ),
							),
							$base_url
						);
					}
				}

				\Wpo\Core\Plugin_Helpers::check_licenses();

				wp_safe_redirect( $redirect );
				exit();
			}
		}

		public static function license_page() {
			$lic_notices = Wpmu_Helpers::mu_get_transient( 'wpo365_lic_notices' );

			if ( empty( $lic_notices ) ) {
				$lic_notices = array();
			}

			$license_is_active = function ( $license_key, $store_item_id ) {

				// Bail out early if either the license key or the store item id are omitted.
				if ( empty( $license_key ) || empty( $store_item_id ) ) {
					return false;
				}

				$url = is_multisite() ? network_home_url() : home_url();

				$response = wp_remote_get(
					\sprintf( 'https://www.wpo365.com/?edd_action=check_license&license=%s&item_id=%s&url=%s', $license_key, $store_item_id, $url ),
					array(
						'timeout'   => 15,
						'sslverify' => ! Options_Service::get_global_boolean_var( 'skip_host_verification' ),
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$license_data = json_decode( wp_remote_retrieve_body( $response ) );

					if ( ! empty( $license_data->license ) && $license_data->license === 'valid' ) {
						return true;
					}
				}

				return false;
			};

			$license_is_required = Plugin_Helpers::is_license_required();

			?>
			<style>
				.wpo365-license-table {
					background: #ffffff;
					border: 1px solid #cccccc;
					box-sizing: border-box;
					float: left;
					margin: 0 15px 15px 0;
					max-width: 350px;
					min-height: <?php echo( $license_is_required ? '240px' : '300px' ); ?>;
					padding: 14px;
					position: relative;
					position: relative;
					width: 30.5%;
				}

				.wpo365-license-table TH {
					background-color: #f9f9f9;
					border-bottom: 1px solid #cccccc;
					display: block;
					margin: -14px -14px 20px;
					padding: 14px;
					width: 100%;
				}

				.wpo365-license-table TD {
					display: block;
					padding: 0;

				}

				.wpo365-license-table TD input[type=text] {
					margin: 0 0 8px;
					width: 100%;
				}

				.wpo365-license-table TD DIV {
					background: #fafafa;
					border-top: 1px solid #eeeeee;
					bottom: 14px;
					box-sizing: border-box;
					margin: 20px -14px -14px;
					min-height: 67px;
					padding: 14px;
					position: absolute;
					width: 100%;
				}

				.wpo365-license-table TD P {
					margin-bottom: 10px;
				}
			</style>
			<div class="wrap">
				<h2><?php esc_html_e( 'WPO365 | Licenses' ); ?></h2>
				<form method="post">
					<input type="hidden" id="store_item_id" name="store_item_id">
					<table class="form-table">
						<tbody>

							<?php
							foreach ( self::$extensions as $slug => $data ) :
								$license_key_name = 'license_' . $data['store_item_id'];
								$license_key      = '';
								$network_options  = get_site_option( 'wpo365_options' );

								if ( ! empty( $network_options[ $license_key_name ] ) ) {
									$license_option = $network_options[ $license_key_name ];

									if ( WordPress_Helpers::stripos( $license_option, '|' ) > -1 ) {
										$exploded    = explode( '|', $license_option );
										$license_key = $exploded[0];
									} else {
										$license_key = $license_option;
									}
								}
								?>
								<tr valign="top" class="wpo365-license-table">
									<th scope="row" valign="top">
										<?php echo esc_html( $data['store_item'] ); ?>
									</th>
									<?php if ( $license_is_required ) : ?>
										<td>
											<?php wp_nonce_field( 'wpo365-manage-licenses', 'wpo365_license_nonce' ); ?>
											<input type="text" class="regular-text" id="<?php echo esc_attr( $license_key_name ); ?>" name="<?php echo esc_attr( $license_key_name ); ?>" value="<?php echo esc_attr( $license_key ); ?>">

											<?php if ( $license_is_active( $license_key, $data['store_item_id'] ) ) : ?>
												<input type="submit" class="button-secondary" name="deactivate_license" value="<?php esc_attr_e( 'Deactivate License' ); ?>" onclick="document.getElementById('store_item_id').value = <?php echo esc_attr( $data['store_item_id'] ); ?>" />
											<?php else : ?>
												<input type="submit" class="button-secondary" name="activate_license" value="<?php esc_attr_e( 'Activate License' ); ?>" onclick="document.getElementById('store_item_id').value = <?php echo esc_attr( $data['store_item_id'] ); ?>" />
											<?php endif ?>

											<div>
												<p><a href="https://www.wpo365.com/your-account/" target="_blank">Manage Sites</a></p>
											</div>
										</td>
									<?php else : ?>
										<td>
											<p>You're good to go - This environment is recognized as non-productive, so the activation of a license is optional.</p>
											<?php wp_nonce_field( 'wpo365-manage-licenses', 'wpo365_license_nonce' ); ?>
											<input type="text" class="regular-text" id="<?php echo esc_attr( $license_key_name ); ?>" name="<?php echo esc_attr( $license_key_name ); ?>" value="<?php echo esc_attr( $license_key ); ?>">

											<?php if ( $license_is_active( $license_key, $data['store_item_id'] ) ) : ?>
												<input type="submit" class="button-secondary" name="deactivate_license" value="<?php esc_attr_e( 'Deactivate License' ); ?>" onclick="document.getElementById('store_item_id').value = <?php echo esc_attr( $data['store_item_id'] ); ?>" />
											<?php else : ?>
												<input type="submit" class="button-secondary" name="activate_license" value="<?php esc_attr_e( 'Activate License' ); ?>" onclick="document.getElementById('store_item_id').value = <?php echo esc_attr( $data['store_item_id'] ); ?>" />
											<?php endif ?>

											<div>
												<p><a href="https://www.wpo365.com/your-account/" target="_blank">Manage Sites</a></p>
											</div>
										</td>
									<?php endif ?>
								</tr>
							<?php endforeach ?>
						</tbody>
					</table>
				</form>
			</div>
			<hr style="width: 60%; margin: 25px 0px 35px 5px;" />
			<div class="wrap">
				<table style="width: 60%">
					<tr>
						<td>
							<div style="margin-bottom: 25px;">Click the button below to prompt WordPress to check for plugin updates and refresh the cached data for premium WPO365 plugins.</div>
						</td>
					</tr>
					<tr>
						<td>
							<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
								<input type="hidden" name="action" value="wpo365_force_check_for_plugin_updates">
								<input type="hidden" name="request_url" value="<?php echo isset( $_SERVER['REQUEST_URI'] ) ? esc_attr( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : ''; ?>">
								<input type="hidden" name="is_network_admin" value="<?php echo is_network_admin() ? '1' : '0'; ?>">
								<?php wp_nonce_field( 'wpo365_force_check_for_plugin_updates', 'wpo365_force_check_for_plugin_updates_nonce' ); ?>
								<input type="submit" class="button-secondary" value="<?php esc_attr_e( 'Verify license and check for plugin updates' ); ?>" />
							</form>
						</td>
					</tr>
				</table>
			</div>
			<?php
		}
	}
}
