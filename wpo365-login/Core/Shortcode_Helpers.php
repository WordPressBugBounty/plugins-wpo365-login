<?php

namespace Wpo\Core;

use Wpo\Core\Compatibility_Helpers;
use Wpo\Core\Extensions_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Script_Helpers;
use Wpo\Graph\Apps_Db;
use Wpo\Services\Options_Service;
use Wpo\Services\Error_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Wp_Config_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Shortcode_Helpers' ) ) {

	class Shortcode_Helpers {

		public static function add_app_short_code() {

			if ( ! shortcode_exists( 'wpo365-app' ) ) {
				add_shortcode(
					'wpo365-app',
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
					function ( $atts = array(), $content = null, $tag = '' ) {
						$atts = array_change_key_case( (array) $atts, CASE_LOWER );

						if ( ! empty( $atts['id'] ) && is_numeric( $atts['id'] ) ) {
							$id           = (int) $atts['id'];
							$app_instance = Apps_Db::get_app_instance( $id, true );

							if ( is_wp_error( $app_instance ) ) {
								return sprintf( '<div>Could not retrieve WPO365 app. [Error: %s]</div>', $app_instance->get_error_message() );
							}

							if ( empty( $app_instance ) ) {
								return '<div>Could not retrieve WPO365 app. [Error: not found]</div>';
							}

							$active_extensions = Extensions_Helpers::get_active_extensions();
							$premium_with_apps = array( 'intranet', 'integrate', 'apps' );
							$is_premium        = false;

							foreach ( $active_extensions as $slug => $data ) {

								foreach ( $premium_with_apps as $lookup_term ) {

									if ( stripos( $slug, $lookup_term ) !== false ) {

										if ( $active_extensions[ $slug ]['version'] < 41 ) {
											$deprecated_message = 'A deprecated version of a Microsoft 365 (Embed) App has been detected. To ensure continued compatibility with future updates, please update your premium WPO365 plugin(s) as soon as possible.';
											Compatibility_Helpers::compat_warning(
												sprintf(
													'%s -> %s',
													__METHOD__,
													$deprecated_message
												)
											);

											return sprintf( '<div>%s</div>', $deprecated_message );
										}

										$plugin_folder = $active_extensions[ $slug ]['plugin_folder'];
										$is_premium    = true;
										break 2;
									}
								}
							}

							if ( empty( $plugin_folder ) ) {
								$plugin_folder = 'wpo365-login';
							}

							$app_type = $app_instance->appType; // phpcs:ignore
							$elem_id  = uniqid();

							switch ( $app_type ) {
								case 'lib':
									$app_file = $is_premium ? 'list.js' : 'listBasic.js';
									break;
								case 'one':
									$app_file = $is_premium ? 'list.js' : 'listBasic.js';
									break;
								case 'lis':
									$app_file = $is_premium ? 'list.js' : 'listBasic.js';
									break;
								case 'rec':
									$app_file = $is_premium ? 'list.js' : 'listBasic.js';
									break;
								case 'pbi':
									$app_file = $is_premium ? 'pbi.js' : 'pbiBasic.js';
									break;
								case 'emp':
									$app_file = $is_premium ? 'ed.js' : 'edBasic.js';
									break;
								case 'con':
									$app_file = $is_premium ? 'contacts.js' : '';
									break;
								case 'yam':
									$app_file = $is_premium ? 'yammer.js' : '';
									break;
								case 'cal':
									$app_file = $is_premium ? 'calendar.js' : 'calendarBasic.js';
									break;
								case 'sea':
									$app_file = $is_premium ? 'cbs.js' : 'cbsBasic.js';
									break;
								default:
									$app_file = '';
							}

							$script_url = trailingslashit( plugins_url( $plugin_folder ) ) . 'apps/dist/' . $app_file;
							$script_url = add_query_arg( 'rootId', $elem_id, $script_url );
							$script_url = add_query_arg( 'appId', $id, $script_url );

							$script_handle = sprintf( 'wpo365-app-%d', $id );

							wp_enqueue_script( $script_handle, $script_url, array(), $GLOBALS['WPO_CONFIG']['version'] ); // phpcs:ignore

							wp_add_inline_script(
								$script_handle,
								'window.wpo365 = window.wpo365 || {};' .
								'window.wpo365.wpUid = ' . get_current_user_id() . ';' .
								'window.wpo365.appConfigs = window.wpo365.appConfigs || {};' .
								"window.wpo365.appConfigs._$id = {" .
									'config: ' . wp_json_encode( $app_instance->config ) . ',' .
									'userRequirements: ' . wp_json_encode( $app_instance->appliedRequirements->userRequirements ) . ',' . // phpcs:ignore
									"nonce: '" . wp_create_nonce( 'wpo365_fx_nonce' ) . "'," .
									"wpAjaxAdminUrl: '" . admin_url( 'admin-ajax.php' ) . "'," .
								'};' .
								'window.wpo365.blocks = ' . wp_json_encode(
									array(
										'nonce'  => \wp_create_nonce( 'wp_rest' ),
										'apiUrl' => esc_url_raw( \trailingslashit( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] ) ) . 'wp-json/wpo365/v1/graph/',
									)
								) . ';',
								'before'
							);

							return sprintf( '<div id="%s" class="wpo365Apps"></div>', $elem_id );
						}
					}
				);
			}
		}


		/**
		 * Helper method to ensure that short codes are initialized
		 *
		 * @since 7.0
		 *
		 * @return void
		 */
		public static function ensure_pintra_short_code() {
			if ( ! shortcode_exists( 'pintra' ) ) {
				add_shortcode( 'pintra', '\Wpo\Core\Shortcode_Helpers::add_pintra_shortcode' );
			}
		}

		/**
		 * Adds a pintra app launcher into the page
		 *
		 * @since 5.0
		 *
		 * @param array  $atts Shortcode parameters according to WordPress codex.
		 * @param string $content Found in between the short code start and end tag.
		 * @param string $tag Text domain.
		 */
		public static function add_pintra_shortcode( $atts = array(), $content = null, $tag = '' ) { // phpcs:ignore
			// Buffer all output instead of echoing it.
			ob_start();

			$atts  = array_change_key_case( (array) $atts, CASE_LOWER );
			$props = '[]';

			if (
				isset( $atts['props'] )
				&& strlen( trim( $atts['props'] ) ) > 0
			) {
				$result        = array();
				$props         = html_entity_decode( $atts['props'] );
				$prop_kv_pairs = explode( ';', $props );

				foreach ( $prop_kv_pairs as  $prop_kv_pair ) {
					$first_separator = WordPress_Helpers::stripos( $prop_kv_pair, ',' );

					if ( $first_separator === false ) {
						continue;
					}

					$result[ \substr( $prop_kv_pair, 0, $first_separator ) ] = \substr( $prop_kv_pair, $first_separator + 1 );
				}

				/**
				 * @since 39.0  Adding the user ID.
				 */
				$result['wpUserId'] = get_current_user_id();
			}

			/**
			 * @since 28.x  Validates the script URL and replaces the major part of the URL.
			 */

			$script_url = ! empty( $atts['script_url'] ) ? html_entity_decode( $atts['script_url'] ) : '';
			$script_url = self::validate_script_url( $script_url );

			/**
			 * @since 39.x  Deprecated apps will bootstrap using the builtin app-launcher. An app is deemed deprecated
			 *              if the common Toast component is not found in the root of the "apps//dist" folder.
			 */

			$base_url  = site_url();
			$base_path = ABSPATH;

			if ( stripos( $script_url, $base_url ) === 0 ) {
				$clean_url         = explode( '?', $script_url )[0];
				$relative_path     = dirname( str_replace( $base_url, '', $clean_url ) );
				$file_path_pattern = $base_path . rtrim( ltrim( $relative_path, '/' ), '/' ) . '/Toast.*';
				$files             = glob( $file_path_pattern );
				$is_vite_app       = ! empty( $files ); // The Toast component was first introduced when apps were built with vite.

				if ( ! $is_vite_app ) {
					Compatibility_Helpers::compat_warning(
						sprintf(
							'%s -> A deprecated version of a Microsoft 365 (Embed) App has been detected. To ensure continued compatibility with future updates, please update your premium WPO365 plugin(s) as soon as possible.',
							__METHOD__
						)
					);
				}
			}

			/**
			 * @since 39.x  Modern apps will be bootstrapped without pintra-fx. But for apps that are not yet upgraded, we
			 *              must determine the (modern) app type.
			 */

			$app_type = '';

			if ( $is_vite_app ) {
				// All pintra shortcodes for list, library and onedrive will have a hostname attribute.
				if ( isset( $result['hostname'] ) ) {

					if ( isset( $result['oneDrive'] ) && filter_var( $result['oneDrive'], FILTER_VALIDATE_BOOLEAN ) ) {
						$app_type = 'one';
					} elseif ( isset( $result['recent'] ) && filter_var( $result['recent'], FILTER_VALIDATE_BOOLEAN ) ) {
						$app_type = 'rec';
					} elseif ( WordPress_Helpers::stripos( $script_url, 'docs.js' ) > -1 || WordPress_Helpers::stripos( $script_url, 'docsBasic.js' ) > -1 ) {
						$app_type = 'lib';
					} else {
						$app_type = 'lis';
					}

					// The docs(Basic).js file has been dropped.
					$script_url = str_replace( 'docs.js', 'list.js', $script_url );
					$script_url = str_replace( 'docsBasic.js', 'listBasic.js', $script_url );
				}

				if ( stripos( $script_url, 'ed.js' ) !== false || stripos( $script_url, 'edBasic.js' ) ) {
					$app_type = 'emp';
				} elseif ( stripos( $script_url, 'contacts.js' ) !== false ) {
					$app_type = 'con';
				} elseif ( stripos( $script_url, 'yammer.js' ) !== false ) {
					$app_type = 'yam';
				} elseif ( stripos( $script_url, 'pbi.js' ) !== false || stripos( $script_url, 'pbiBasic.js' ) ) {
					$app_type = 'pbi';
				} elseif ( stripos( $script_url, 'calendar.js' ) !== false || stripos( $script_url, 'calendarBasic.js' ) ) {
					$app_type = 'cal';
				} elseif ( stripos( $script_url, 'cbs.js' ) !== false || stripos( $script_url, 'cbsBasic.js' ) ) {
					$app_type = 'sea';
				}

				// Add the app type so the app can tell one, lib, lis and rec apart.
				$result['appType'] = $app_type;
			}

			if ( ! empty( $result ) ) {
				$props = wp_json_encode( $result );
			}

			// Vite built apps have with react.
			if ( ! $is_vite_app ) {
				$react_urls = Script_Helpers::get_react_urls();

				wp_print_script_tag(
					array(
						'crossorigin' => 'anonymous',
						'src'         => $react_urls['react_url'],
					)
				);

				wp_print_script_tag(
					array(
						'crossorigin' => 'anonymous',
						'src'         => $react_urls['react_dom_url'],
					)
				);
			}

			$blocks_js = '' .
				"window.wpo365 = window.wpo365 || {};\n" .
				sprintf(
					"window.wpo365.blocks = %s\n",
					wp_json_encode(
						array(
							'nonce'  => \wp_create_nonce( 'wp_rest' ),
							'apiUrl' => esc_url_raw( \trailingslashit( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] ) ) . 'wp-json/wpo365/v1/graph',
						)
					)
				);

			if ( ! current_theme_supports( 'html5', 'script' ) || ! function_exists( 'wp_print_inline_script_tag' ) ) {
				printf( "<script>%s</script>\n", $blocks_js ); // phpcs:ignore
			} else {
				wp_print_inline_script_tag( $blocks_js );
			}

			$elem_id   = uniqid();
			$script_id = uniqid();
			$app_id    = -1;

			echo( '<div>' );
			printf( '<div id="%s"></div>', sanitize_key( $elem_id ) );

			$url = esc_url( $script_url );
			$url = add_query_arg( 'scriptId', $script_id, $url );
			$url = add_query_arg( 'rootId', $elem_id, $url );
			$url = add_query_arg( 'appId', $app_id, $url );

			$script_tag_atts = array(
				'src'                 => $url,
				'data-nonce'          => wp_create_nonce( 'wpo365_fx_nonce' ),
				'data-wpajaxadminurl' => admin_url( 'admin-ajax.php' ),
				'data-props'          => htmlspecialchars( $props ),
				'id'                  => $script_id,
			);

			// Only apps built with vite are considered modules.
			if ( $is_vite_app ) {
				$script_tag_atts['type'] = 'module';
			}

			$script_tag = wp_get_script_tag( $script_tag_atts );

			if ( $is_vite_app ) {
				$script_tag = str_replace( 'type="text/javascript"', 'type="module"', $script_tag );

				if ( ! preg_match( '/\btype\s*=\s*([\'"])/i', $script_tag ) ) {
					$script_tag = preg_replace(
						'/^<script\b(.*?)>/i',
						'<script$1 type="module">',
						$script_tag,
						1
					);
				}
			}

			echo $script_tag; // phpcs:ignore

			echo( '</div>' );

			$content = ob_get_clean();
			return wp_kses( $content, WordPress_Helpers::get_allowed_html() );
		}

		/**
		 * Helper method to ensure that short codes are initialized
		 *
		 * @since 8.0
		 *
		 * @return void
		 */
		public static function ensure_login_button_short_code_v2() {
			if ( empty( Extensions_Helpers::get_active_extensions() ) ) {
				return;
			}

			if ( ! shortcode_exists( 'wpo365-sign-in-with-microsoft-v2-sc' ) ) {
				add_shortcode( 'wpo365-sign-in-with-microsoft-v2-sc', '\Wpo\Core\Shortcode_Helpers::add_sign_in_with_microsoft_shortcode_V2' );
			}
		}

		/**
		 * Adds the Sign in with Microsoft short code V2
		 *
		 * @since 8.0
		 *
		 * @param array  $params Shortcode parameters according to WordPress codex.
		 * @param string $content Found in between the short code start and end tag.
		 * @param string $tag Text domain.
		 */
		public static function add_sign_in_with_microsoft_shortcode_V2( $params = array(), $content = null, $tag = '' ) { // phpcs:ignore
			if ( empty( $content ) ) {
				return $content;
			}

			// Ensure pintra-redirect is enqueued
			Script_Helpers::enqueue_pintra_redirect();

			$site_url = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

			// Load the js dependency
			ob_start();
			include Extensions_Helpers::get_active_extension_dir( array( 'wpo365-login-premium/wpo365-login.php', 'wpo365-sync-5y/wpo365-sync-5y.php', 'wpo365-login-intranet/wpo365-login.php', 'wpo365-intranet-5y/wpo365-intranet-5y.php', 'wpo365-integrate/wpo365-integrate.php', 'wpo365-pro/wpo365-pro.php', 'wpo365-customers/wpo365-customers.php', 'wpo365-essentials/wpo365-essentials.php' ) ) . '/templates/openid-ssolink.php';
			$js_lib = ob_get_clean();

			// Sanitize the HTML template
			$dom = new \DOMDocument();
			@$dom->loadHTML( $content ); // phpcs:ignore
			$script = $dom->getElementsByTagName( 'script' );
			$remove = array();

			foreach ( $script as $item ) {
				$remove[] = $item;
			}

			foreach ( $remove as $item ) {
				$item->parentNode->removeChild( $item ); // phpcs:ignore
			}

			// Concatenate the two
			$output = $js_lib . $dom->saveHTML();
			return str_replace( '__##PLUGIN_BASE_URL##__', $GLOBALS['WPO_CONFIG']['plugin_url'], $output );
		}

		/**
		 * Helper method to ensure that short code for login button is initialized
		 *
		 * @since 11.0
		 */
		public static function ensure_login_button_short_code() {
			if ( ! shortcode_exists( 'wpo365-login-button' ) ) {
				add_shortcode( 'wpo365-login-button', '\Wpo\Core\Shortcode_Helpers::login_button' );
			}
		}

		/**
		 * Helper to display the Sign in with Microsoft button on a login form.
		 *
		 * @since 10.6
		 *
		 * @param bool $output Whether to return the HTML or not.
		 *
		 * @return void
		 */
		public static function login_button( $output = false ) {
			// Don't render a login button when sso is disabled
			if ( Options_Service::get_global_boolean_var( 'no_sso' ) ) {
				return;
			}

			// Used by the template that is rendered
			$hide_login_button      = Options_Service::get_global_boolean_var( 'hide_login_button' );
			$sign_in_with_microsoft = Options_Service::get_global_string_var( 'sign_in_with_microsoft' );

			if ( empty( $sign_in_with_microsoft ) || $sign_in_with_microsoft === 'Sign in with Microsoft' ) {
				$sign_in_with_microsoft = __( 'Sign in with Microsoft', 'wpo365-login' );
			}

			$sign_in_multi_placeholder = Options_Service::get_global_string_var( 'sign_in_multi_placeholder' );

			if ( empty( $sign_in_multi_placeholder ) || $sign_in_multi_placeholder === 'Select your Identity Provider' ) {
				$sign_in_multi_placeholder = __( 'Select your Identity Provider', 'wpo365-login' );
			}

			$wpo_idps = Wp_Config_Service::get_multiple_idps();

			if ( ! empty( $wpo_idps ) ) {
				$wpo_idps = array_filter(
					$wpo_idps,
					function ( $value ) {
						return ! empty( $value['title'] ) && ! empty( $value['id'] );
					}
				);

				$wpo_idps = array_values( $wpo_idps ); // re-index from 0
			}

			$login_button_template = sprintf(
				'%s/templates/login-button%s.php',
				$GLOBALS['WPO_CONFIG']['plugin_dir'],
				( Options_Service::get_global_boolean_var( 'use_login_button_v1' ) ? '' : '-v2' )
			);

			$_login_button_config = Options_Service::get_global_list_var( 'button_config' );
			$login_button_config  = array();
			$config_elems_count   = count( $_login_button_config );

			for ( $i = 0; $i < $config_elems_count; $i++ ) {
				$login_button_config[ $_login_button_config[ $i ]['key'] ] = $_login_button_config[ $i ]['value'];
			}

			$button_dont_zoom        = ! empty( $login_button_config['buttonDontZoom'] );
			$button_hide_logo        = ! empty( $login_button_config['buttonHideLogo'] );
			$button_border_color     = ! empty( $login_button_config['buttonBorderColor'] ) ? $login_button_config['buttonBorderColor'] : '#8C8C8C';
			$button_border_width     = ! empty( $login_button_config['buttonHideBorder'] ) ? '0px solid' : '1px solid';
			$button_foreground_color = ! empty( $login_button_config['buttonForegroundColor'] ) ? $login_button_config['buttonForegroundColor'] : '#5E5E5E';
			$button_background_color = ! empty( $login_button_config['buttonBackgroundColor'] ) ? $login_button_config['buttonBackgroundColor'] : '#FFFFFF';

			ob_start();
			include $login_button_template;
			$content = ob_get_clean();

			if ( $output ) {
				return wp_kses( $content, WordPress_Helpers::get_allowed_html() );
			}

			echo wp_kses( $content, WordPress_Helpers::get_allowed_html() );
		}

		/**
		 * Helper method to ensure that short code for displaying errors is initialized
		 *
		 * @since 7.8
		 */
		public static function ensure_display_error_message_short_code() {
			if ( empty( Extensions_Helpers::get_active_extensions() ) ) {
				return;
			}

			if ( ! shortcode_exists( 'wpo365-display-error-message-sc' ) ) {
				add_shortcode( 'wpo365-display-error-message-sc', '\Wpo\Core\Shortcode_Helpers::add_display_error_message_shortcode' );
			}
		}

		/**
		 * Adds the error message encapsulated in a div into the page
		 *
		 * @since 7.8
		 *
		 * @param array  $atts Shortcode parameters according to WordPress codex.
		 * @param string $content Found in between the short code start and end tag.
		 * @param string $tag Text domain.
		 */
		public static function add_display_error_message_shortcode( $atts = array(), $content = null, $tag = '' ) { // phpcs:ignore
			$error_code = isset( $_GET['login_errors'] ) // phpcs:ignore
				? sanitize_text_field( wp_unslash( $_GET['login_errors'] ) ) // phpcs:ignore
				: '';

			$error_message = Error_Service::get_error_message( $error_code );

			if ( empty( $error_message ) ) {
				return;
			}

			ob_start();
			include Extensions_Helpers::get_active_extension_dir( array( 'wpo365-login-professional/wpo365-login.php', 'wpo365-customers/wpo365-customers.php', 'wpo365-login-premium/wpo365-login.php', 'wpo365-sync-5y/wpo365-sync-5y.php', 'wpo365-login-intranet/wpo365-login.php', 'wpo365-intranet-5y/wpo365-intranet-5y.php', 'wpo365-customers/wpo365-customers.php', 'wpo365-integrate/wpo365-integrate.php', 'wpo365-pro/wpo365-pro.php', 'wpo365-essentials/wpo365-essentials.php' ) ) . '/templates/error-message.php';
			$content = ob_get_clean();
			return wp_kses( $content, WordPress_Helpers::get_allowed_html() );
		}

		/**
		 * Helper method to ensure that short codes are initialized
		 *
		 * @since 7.0
		 *
		 * @return void
		 */
		public static function ensure_wpo365_redirect_script_sc() {
			if ( ! shortcode_exists( 'wpo365-redirect-script' ) ) {
				add_shortcode( 'wpo365-redirect-script', '\Wpo\Core\Shortcode_Helpers::add_wpo365_redirect_script_sc' );
			}
		}

		/**
		 * Adds a javascript file that WPO365 requires to trigger the "Sign in with Microsoft" flow client-side.
		 *
		 * @since 33.0
		 *
		 * @param array  $atts Shortcode parameters according to WordPress codex.
		 * @param string $content Found in between the short code start and end tag.
		 * @param string $tag Text domain.
		 */
		public static function add_wpo365_redirect_script_sc( $atts = array(), $content = null, $tag = '' ) { // phpcs:ignore
			// Ensure pintra-redirect is enqueued (which would already be enqueued if support for Teams is enabled)
			if ( ! Options_Service::get_global_boolean_var( 'use_teams' ) ) {
				Script_Helpers::enqueue_pintra_redirect();
			}
		}

		/**
		 * Helper method to ensure that short codes are initialized
		 *
		 * @since 8.0
		 *
		 * @return void
		 */
		public static function ensure_sso_button_sc() {
			if ( ! shortcode_exists( 'wpo365-sso-button' ) ) {
				add_shortcode( 'wpo365-sso-button', '\Wpo\Core\Shortcode_Helpers::add_sso_button_sc' );
			}
		}

		/**
		 * Adds the default SSO button with customizations applied
		 *
		 * @since 33.0
		 *
		 * @param array  $params Shortcode parameters according to WordPress codex.
		 * @param string $content Found in between the short code start and end tag.
		 * @param string $tag Text domain.
		 */
		public static function add_sso_button_sc( $params = array(), $content = null, $tag = '' ) { // phpcs:ignore
			// Ensure pintra-redirect is enqueued
			Script_Helpers::enqueue_pintra_redirect();

			// Output the SSO button
			return self::login_button( true );
		}

		/**
		 * Validates the script URL and replaces the major part of the URL to ensure
		 * the script is located in the WPO365 apps/dist folder.
		 *
		 * @since 28.x
		 *
		 * @param mixed $script_url
		 * @return string
		 */
		private static function validate_script_url( $script_url ) {
			if ( empty( $script_url ) ) {
				return '';
			}

			$script_url = html_entity_decode( $script_url );
			$segments   = explode( '/', $script_url );

			if ( empty( $segments ) || count( $segments ) < 4 ) {
				Log_Service::write_log(
					'WARN',
					sprintf(
						'%s -> Pintra script URL is ill-formatted [Url: %s]',
						__METHOD__,
						$script_url
					)
				);
				return '';
			}

			$plugin_folder = array_slice( $segments, -4, 1 )[0];

			if ( substr( $plugin_folder, 0, 6 ) !== 'wpo365' ) {
				Log_Service::write_log(
					'WARN',
					sprintf(
						'%s -> Relative script URL does not start with "wpo365-" [Url: %s]',
						__METHOD__,
						$script_url
					)
				);
				return '';
			}

			$script_relative_url = sprintf(
				'%s/apps/dist/%s',
				$plugin_folder,
				array_pop( $segments )
			);

			return plugins_url( $script_relative_url );
		}
	}
}
