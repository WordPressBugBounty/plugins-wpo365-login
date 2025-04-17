<?php

namespace Wpo\Core;

use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Url_Helpers' ) ) {

	class Url_Helpers {


		/**
		 * Helper method to (try) help ensure that the path segment given ends with a trailing slash.
		 *
		 * @since 1.0
		 *
		 * @param string $path Path that should end with a slash.
		 *
		 * @return string Path with trailing slash if appropriate
		 */
		public static function ensure_trailing_slash_path( $path ) {
			$path           = WordPress_Helpers::trim( $path, '/' );
			$path_segments  = explode( '/', $path );
			$segments_count = count( $path_segments );
			if ( $segments_count > 0 && WordPress_Helpers::stripos( $path_segments[ $segments_count - 1 ], '.' ) === false ) {
				$is_root = empty( $path );
				return $is_root
					? '/'
					: '/' . implode( '/', $path_segments ) . '/';
			}
			return '/' . $path;
		}

		/**
		 * Helper method to (try) help ensure that the url given ends with a trailing slash.
		 *
		 * @since 1.0
		 *
		 * @param string $url Url that should end with a slash.
		 *
		 * @return string Url with trailing slash if appropriate
		 */
		public static function ensure_trailing_slash_url( $url ) {

			if ( empty( $url ) || ! is_string( $url ) ) {
				return null;
			}

			$parsed_url    = wp_parse_url( $url );
			$resulting_url = '';

			if ( ! empty( $parsed_url['scheme'] ) ) {
				$resulting_url .= $parsed_url['scheme'];
			} else {
				return null;
			}

			$resulting_url .= ( '://' );

			if ( ! empty( $parsed_url['user'] ) && ! empty( $parsed_url['pass'] ) ) {
				$resulting_url .= ( $parsed_url['user'] . ':' . $parsed_url['pass'] . '@' );
			}

			if ( ! empty( $parsed_url['host'] ) ) {
				$resulting_url .= $parsed_url['host'];
			} else {
				return null;
			}

			if ( ! empty( $parsed_url['port'] ) ) {
				$resulting_url .= ( ':' . $parsed_url['port'] );
			}

			if ( ! empty( $parsed_url['path'] ) ) {
				$resulting_url .= self::ensure_trailing_slash_path( $parsed_url['path'] );
			} else {
				$resulting_url .= '/';
			}

			if ( ! empty( $parsed_url['query'] ) ) {
				$resulting_url .= ( '?' . $parsed_url['query'] );
			}

			if ( ! empty( $parsed_url['fragment'] ) ) {
				$resulting_url .= ( '#' . $parsed_url['fragment'] );
			}

			return $resulting_url;
		}

		/**
		 * Helper method to determine whether the current URL is the WP REST API.
		 *
		 * @since 7.12
		 *
		 * @return boolean true if the current URL is for the WP REST API otherwise false.
		 */
		public static function is_wp_rest_api() {
			$rest_url             = \get_rest_url();
			$rest_url_wo_protocol = \substr( $rest_url, WordPress_Helpers::stripos( $rest_url, '://' ) + 3 );

			$current_url             = $GLOBALS['WPO_CONFIG']['url_info']['current_url'];
			$current_url_wo_protocol = \substr( $current_url, WordPress_Helpers::stripos( $current_url, '://' ) + 3 );

			if ( WordPress_Helpers::stripos( $current_url_wo_protocol, $rest_url_wo_protocol ) === 0 ) {
				return true;
			}

			return false;
		}

		/**
		 * Will check whether request is for WP REST API and if yes
		 * if a basic authentication header is present (without proofing it).
		 *
		 * @since 7.12
		 *
		 * @return boolean true if found, otherwise false
		 */
		public static function is_basic_auth_api_request() {

			if ( self::is_wp_rest_api() === false ) {
				return false;
			}

			$headers          = getallheaders();
			$headers_to_lower = array_change_key_case( $headers, CASE_LOWER );

			return ( isset( $headers_to_lower['authorization'] ) && WordPress_Helpers::stripos( $headers_to_lower['authorization'], 'basic' ) === 0 );
		}

		/**
		 * Adds custom wp query vars
		 *
		 * @since 3.6
		 *
		 * @param array $vars existing wp query vars.
		 *
		 * @return array updated $vars that now includes custom wp query vars
		 */
		public static function add_query_vars_filter( $vars ) {

			$vars[] = 'login_errors';
			$vars[] = 'stnu'; // show table new users
			$vars[] = 'stne'; // show table existing users
			$vars[] = 'stou'; // show table old users
			$vars[] = 'sjs';  // sync job status
			$vars[] = 'redirect_to';  // redirect to after successfull authentication
			return $vars;
		}

		/**
		 * Get's WordPress default (and possibly custom) login URLs.
		 *
		 * @since 7.17
		 *
		 * @return array Assoc. array with custom login url (possibly empty string) and default login url.
		 */
		public static function get_login_urls() {
			$default_login_url = \wp_login_url();
			$custom_login_url  = Options_Service::get_global_string_var( 'custom_login_url' );

			// Custom login url must be an absolute URL
			if ( WordPress_Helpers::stripos( $custom_login_url, 'http' ) !== 0 ) {

				return array(
					'custom_login_url'  => '',
					'default_login_url' => $default_login_url,
				);
			}

			// Custom login url should not accept a query string
			if ( WordPress_Helpers::stripos( $custom_login_url, '?' ) !== false ) {
				$custom_login_url_arr = explode( '?', $custom_login_url );
				$custom_login_url     = $custom_login_url_arr[0];
			}

			// Custom login url should not accept a hash
			if ( WordPress_Helpers::stripos( $custom_login_url, '#' ) !== false ) {
				$custom_login_url_arr = explode( $custom_login_url, '#' );
				$custom_login_url     = $custom_login_url_arr[0];
			}

			$custom_login_url = self::ensure_trailing_slash_url( $custom_login_url );

			return array(
				'custom_login_url'  => $custom_login_url,
				'default_login_url' => $default_login_url,
			);
		}

		/**
		 * Gets the custom login url if configured and otherwise the default login URL is returned.
		 *
		 * @since 7.17
		 *
		 * @return string Returns custom login url if configured and otherwise the default login URL.
		 */
		public static function get_preferred_login_url() {
			$login_urls = self::get_login_urls();

			return ! empty( $login_urls['custom_login_url'] )
				? $login_urls['custom_login_url']
				: $login_urls['default_login_url'];
		}

		/**
		 * Helper method to determine whether the current URL is the login form.
		 *
		 * @since 7.11
		 *
		 * @return boolean true if the current form is the wp login form.
		 */
		public static function is_wp_login( $uri = null ) {

			if ( empty( $uri ) ) {
				$uri = $GLOBALS['WPO_CONFIG']['url_info']['request_uri'];
			}

			$login_urls = self::get_login_urls();

			array_walk(
				$login_urls,
				function ( &$value, $key ) { // phpcs:ignore
					WordPress_Helpers::rtrim( $value, '/' );
				}
			);

			$custom_login_url_path     = ! empty( $login_urls['custom_login_url'] )
				? wp_parse_url( $login_urls['custom_login_url'], PHP_URL_PATH )
				: '';
			$custom_login_url_detected = ! empty( $custom_login_url_path )
				&& WordPress_Helpers::stripos( $uri, $custom_login_url_path ) !== false;

			$default_login_url_path     = wp_parse_url( $login_urls['default_login_url'], PHP_URL_PATH );
			$default_login_url_detected = ! empty( $default_login_url_path ) && WordPress_Helpers::stripos( $uri, $default_login_url_path ) !== false;

			return ( $custom_login_url_detected || $default_login_url_detected );
		}

		/**
		 * Helper method to determine whether the current URL is the custom logout URL.
		 *
		 * @since 7.11
		 *
		 * @return boolean true if the current form is the wp login form.
		 */
		public static function is_custom_logout_url( $uri = null ) {

			if ( empty( $uri ) ) {
				$uri = $GLOBALS['WPO_CONFIG']['url_info']['request_uri'];
			}

			$custom_logout_url = Options_Service::get_global_string_var( 'error_page_url' );

			// No custom logout URL configured.
			if ( empty( $custom_logout_url ) ) {
				return false;
			}

			$custom_logout_url      = WordPress_Helpers::rtrim( $custom_logout_url, '/' );
			$custom_logout_url_path = wp_parse_url( $custom_logout_url, PHP_URL_PATH );

			return ! empty( $custom_logout_url_path )
				&& WordPress_Helpers::stripos( $uri, $custom_logout_url_path ) !== false;
		}

		/**
		 * Checks whether headers are sent before trying to redirect and if sent falls
		 * back to an alternative method
		 *
		 * @since 4.3
		 *
		 * @param string $url URL to redirect to.
		 * @return void
		 */
		public static function force_redirect( $url ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$location = wp_sanitize_redirect( $url );

			if ( WordPress_Helpers::strpos( $location, '?' ) === false && WordPress_Helpers::strpos( $location, '#' ) === false ) {
				$location = self::ensure_trailing_slash_url( $location );
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Redirecting to ' . $location );

			if ( headers_sent() ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Headers sent when trying to redirect user to ' . $url );
				echo '<script type="text/javascript">';
				printf( 'window.location.href="%s"', wp_kses( $location, WordPress_Helpers::get_allowed_html() ) );
				echo '</script>';
				echo '<noscript>';
				printf( '<meta http-equiv="refresh" content="0;url=%s" />', wp_kses( $location, WordPress_Helpers::get_allowed_html() ) );
				echo '</noscript>';
				exit();
			}

			wp_redirect( $url ); // phpcs:ignore
			exit();
		}

		/**
		 * Helper method to determine the redirect URL which can either be the last page
		 * the user visited before authentication stored in the posted state property, or
		 * if configured the goto_after_signon_url or in case none of these apply the WordPress
		 * home URL. This method can be called from the wpo_redirect_url filter.
		 *
		 * @since 7.1
		 *
		 * @return string URL to send the user once authentication completed
		 */
		public static function get_redirect_url() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			// state is the URL the user requested before being sent to Microsoft and that was sent along with the auth request to Microsoft
			$state_url = $request->get_item( 'state' );

			if ( empty( $state_url ) ) {
				// in case of saml the state url is saved as relay_state
				$state_url = $request->get_item( 'relay_state' );
			}

			// take state if it's not the login URL
			if ( ! empty( $state_url ) && ! self::is_wp_login( $state_url ) ) {
				return $state_url;
			}

			// URL configured by the admin where users should be sent if not following a deep link
			$goto_after_signon_url = Options_Service::get_global_string_var( 'goto_after_signon_url' );

			// fallback to the site's home URL
			if ( empty( $goto_after_signon_url ) ) {
				$goto_after_signon_url = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
			}

			// otherwise use the URL configured by the admin
			return $goto_after_signon_url;
		}

		/**
		 * Sends the user to their final destination after being redirected back to the site by Microsoft.
		 *
		 * @param mixed $wpo_usr
		 *
		 * @return void
		 */
		public static function goto_after( $wpo_usr = null ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Get URL and redirect user (default is the WordPress homepage)
			$redirect_url = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

			if ( \class_exists( '\Wpo\Services\Redirect_Service' ) && \method_exists( '\Wpo\Services\Redirect_Service', 'get_redirect_url' ) ) {
				$groups       = ! empty( $wpo_usr ) ? $wpo_usr->groups : array();
				$is_new_user  = ! empty( $wpo_usr ) ? $wpo_usr->created : false;
				$redirect_url = \Wpo\Services\Redirect_Service::get_redirect_url( $redirect_url, $groups, $is_new_user );
			} else {
				$redirect_url = self::get_redirect_url();
			}

			/**
			 * @since 32.0  Add support for login_redirect filter
			 */

			$wp_usr       = wp_get_current_user();
			$redirect_url = apply_filters( 'login_redirect', $redirect_url, $redirect_url, $wp_usr );

			/**
			 * @since 24.0 Filters the necessity of conducting the URL check below.
			 */

			if ( apply_filters( 'wpo365/url_check/skip', false ) === true ) {
				self::force_redirect( $redirect_url );
			}

			$aad_redirect_uri = Options_Service::get_aad_option( 'redirect_url' );
			$aad_redirect_url = Options_Service::get_global_boolean_var( 'use_saml' )
				? Options_Service::get_aad_option( 'saml_sp_acs_url' )
				: $aad_redirect_uri;

			/**
			 * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
			 */

			$aad_redirect_url = apply_filters( 'wpo365/aad/redirect_uri', $aad_redirect_url );

			if ( WordPress_Helpers::stripos( $aad_redirect_url, 'https://' ) !== false && WordPress_Helpers::stripos( $redirect_url, 'http://' ) === 0 ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Please update your htaccess or similar and ensure that users can only access your website via https:// (URL requested by the user: ' . $redirect_url . ').' );
				$redirect_url = str_replace( 'http://', 'https://', $redirect_url );
			}

			/**
			 * @since 33.0 Use WordPress' builtin API to validate the Redirect URL.
			 */

			$cookie_domain_host = defined( 'COOKIE_DOMAIN' ) && ! empty( COOKIE_DOMAIN ) ? WordPress_Helpers::ltrim( COOKIE_DOMAIN, '.' ) : null;

			if ( ! empty( $cookie_domain_host ) ) {
				add_filter(
					'allowed_redirect_hosts',
					function ( $hosts, $host ) use ( $cookie_domain_host ) { // phpcs:ignore
						$hosts[] = $cookie_domain_host;
						return $hosts;
					},
					10,
					2
				);
			}

			$validated_redirect_url = wp_validate_redirect( $redirect_url, $aad_redirect_url );

			// Check whether WordPress is installed in subdirectory

			if ( ! empty( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] ) ) {

				if ( WordPress_Helpers::stripos( $validated_redirect_url, $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] ) === false ) {
					$validated_redirect_url = $aad_redirect_url;
				}
			}

			if ( strcasecmp( rtrim( $validated_redirect_url, '/' ), rtrim( $redirect_url, '/' ) ) !== 0 ) {
				Log_Service::write_log(
					'ERROR',
					sprintf(
						'%s -> WPO365 has prevented to redirect a user (that just successfully signed in with Microsoft) to an invalid URL [%s]. The user will instead be sent to the Entra Redirect URI [%s].',
						__METHOD__,
						$redirect_url,
						$aad_redirect_url
					)
				);
			}

			self::force_redirect( $validated_redirect_url );
		}

		/**
		 * Tries to make $url absolute by concatenating it with the home URL.
		 *
		 * @since 34.3
		 *
		 * @param string $url
		 * @return string
		 */
		public static function url_ensure_absolute( $url ) {
			Log_Service::write_log(
				'DEBUG',
				sprintf(
					'##### ->  %s [Arg(s): %s]',
					__METHOD__,
					implode( ' ', func_get_args() )
				)
			);

			if ( ! empty( $url ) ) {

				if ( stripos( $url, 'http' ) === 0 ) {
					return $url;
				}

				if ( stripos( $url, '/' ) === 0 || stripos( $url, '?' ) === 0 ) {
					return sprintf(
						'%s%s',
						$GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'],
						ltrim( $url, '/' )
					);
				}
			}

			Log_Service::write_log(
				'WARN',
				sprintf(
					'%s -> Url "%s" is not a valid (relative) URL therefore returning home URL',
					__METHOD__,
					$url
				)
			);

			return $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
		}

		/**
		 * Centrally prepare the state URL which will be the URL where the user eventually will be redirected back to.
		 *
		 * @param mixed $url
		 *
		 * @return string
		 */
		public static function get_state_url( $url = '', $query_args = array() ) {
			Log_Service::write_log( 'DEBUG', sprintf( '##### ->  %s', __METHOD__ ) );

			// if $url is empty take redirect_to or else the current URL as long as it ain't our own post-back (then take referer)
			if ( empty( $url ) ) {

				$redirect_to = ! empty( $_REQUEST['redirect_to'] ) // phpcs:ignore
					? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) // phpcs:ignore
					: null;

				$url = ! empty( $redirect_to )
					? $redirect_to
					: self::get_current_url();

				// take referer (or reset $url) if it's our post-back to the site
				if ( WordPress_Helpers::stripos( $url, 'cb=' ) !== false ) {

					if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
						$url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
					} else {
						$url = null;
					}
				}
			}

			// make $url an absolute URL but if empty take the site's home address
			$url = self::url_ensure_absolute( $url );

			// if $url is a login / logout URL then either update to its redirect_to query arg or take the site's home address
			if ( self::is_wp_login( $url ) || self::is_custom_logout_url( $url ) ) {

				if ( ! self::try_get_redirect_to_query_arg( $url ) ) {
					// URL configured by the admin where users should be sent if not following a deep link
					$goto_after_signon_url = Options_Service::get_global_string_var( 'goto_after_signon_url' );

					// fallback to the site's home URL
					if ( empty( $goto_after_signon_url ) ) {
						$goto_after_signon_url = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
					}

					$url = $goto_after_signon_url;
				}
			}

			/**
			 * @since 30.0  Replace a possible "#" because add_query_arg and parse_url cannot handle it
			 */

			$url = str_replace( '#', '_____', $url );

			/**
			 * @since   16.0    Filters the state url
			 */
			$url = apply_filters( 'wpo365/cookie/remove/url', $url );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			/**
			 * @since   27.0    Adds the IDP ID to the state URL
			 */
			$idp_id = $request->get_item( 'idp_id' );

			if ( ! empty( $idp_id ) ) {
				$url = add_query_arg(
					array( 'idp_id' => $idp_id ),
					$url
				);
			}

			// add possible other arguments that need to be memoized e.g. tfp in case of B2C
			if ( ! empty( $query_args ) ) {

				foreach ( $query_args as $key => $value ) {
					$url = add_query_arg(
						array( $key => $value ),
						$url
					);
				}
			}

			Log_Service::write_log(
				'DEBUG',
				sprintf(
					'%s ->  State URL: %s',
					__METHOD__,
					$url
				)
			);

			return rawurlencode( $url );
		}

		/**
		 * Rebuilds the current URL.
		 *
		 * @since 34.3
		 *
		 * @return string
		 */
		public static function get_current_url() {
			$redirect_url = Options_Service::get_aad_option( 'redirect_url' );
			$redirect_url = Options_Service::get_global_boolean_var( 'use_saml' )
				? Options_Service::get_aad_option( 'saml_sp_acs_url' )
				: $redirect_url;
			$redirect_url = apply_filters( 'wpo365/aad/redirect_uri', $redirect_url );

			return sprintf(
				'%s://%s%s',
				WordPress_Helpers::stripos( $redirect_url, 'https' ) !== false ? 'https' : 'http',
				$GLOBALS['WPO_CONFIG']['url_info']['host'],
				$GLOBALS['WPO_CONFIG']['url_info']['request_uri']
			);
		}

		/**
		 * Will update $url with value of the redirect_to query string parameter and return true .
		 *
		 * @since 34.3
		 *
		 * @param string &$url
		 * @return bool
		 */
		public static function try_get_redirect_to_query_arg( &$url ) {
			if ( ! empty( $url ) ) {
				// Referer may be the login URL with a redirect_to parameter
				$query = wp_parse_url( $url, PHP_URL_QUERY );

				if ( empty( $query ) ) {
					return false;
				} else {
					parse_str( $query, $result );
				}

				if ( ! empty( $result['redirect_to'] ) ) {

					if ( self::is_wp_login( $result['redirect_to'] ) || self::is_custom_logout_url( $result['redirect_to'] ) ) {
						return false;
					}

					$url = esc_url_raw( $result['redirect_to'] );
					return true;
				}
			}

			return false;
		}

		/**
		 * Ensures that the input string starts with a leading forward slash "/".
		 *
		 * @since 14.0
		 *
		 * @param string $str Input string that will be returned with a leading slash.
		 * @return string Input string with a leading slash.
		 */
		public static function leadingslashit( $str ) {
			return '/' . WordPress_Helpers::ltrim( $str, '/' );
		}

		/**
		 * Remove the protocol and www from a URL e.g. https://www.your-site.com/ becomes
		 * ://your-site.com/
		 */
		public static function remove_protocol_and_www( $url ) {
			$wo_https = \str_replace( 'https', '', $url );
			$wo_http  = \str_replace( 'http', '', $wo_https );
			return \str_replace( 'www.', '', $wo_http );
		}

		/**
		 * Helper to make it easier to compare URLs.
		 *
		 * @param mixed $url
		 * @return string
		 */
		public static function undress_url( $url ) {
			$url = strtok( $url, '?' );
			$url = untrailingslashit( $url );
			$url = str_replace( 'https://', '', $url );
			$url = str_replace( 'http://', '', $url );
			return $url;
		}
	}
}
