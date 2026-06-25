<?php

namespace Wpo\Services;

use Wpo\Core\Url_Helpers;
use Wpo\Core\Version;
use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Error_Service;
use Wpo\Services\Id_Token_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

use Wpo\Tests\Self_Test;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Router_Service' ) ) {

	class Router_Service {


		public static function has_route() {

			// If we detect interim-login then redirect to homepage to force WordPress fallback to "Session expired" UI.
			if ( isset( $_REQUEST['interim-login'] ) && (string) $_REQUEST['interim-login'] === '1' ) { // phpcs:ignore
				wp_safe_redirect( home_url() );
				exit;
			}

			// initiate openidconnect / saml flow
			if ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'openidredirect' ) { // phpcs:ignore
				add_action( 'init', '\Wpo\Services\Router_Service::route_openidredirect' );
				return true;
			}

			// Initiate SSO via the /wpo/sso/start custom endpoint.
			if ( self::detect_sso_start_endpoint() ) {

				if ( ! self::validate_sso_start_params() ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> SSO start endpoint called with invalid or unknown query-string parameters.' );
					add_action( 'init', '\Wpo\Services\Router_Service::route_sso_start_invalid' );
					return true;
				}

				self::store_sso_start_params();
				add_action( 'init', '\Wpo\Services\Router_Service::route_sso_start_initiate' );
				return true;
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$is_oidc_response = $request->get_item( 'is_oidc_response' );
			$is_saml_response = $request->get_item( 'is_saml_response' );
			$mode             = $request->get_item( 'mode' );

			if ( ! self::skip_authentication_response( $is_oidc_response, $is_saml_response ) ) {

				if ( isset( $_REQUEST['error'] ) ) { // phpcs:ignore
					$error_string = sanitize_text_field( wp_unslash( $_REQUEST['error'] ) ) . ( isset( $_REQUEST['error_description'] ) ? \sanitize_text_field( wp_unslash( $_REQUEST['error_description'] ) ) : '' ); // phpcs:ignore
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> ' . $error_string );
					add_action( 'init', '\Wpo\Services\Router_Service::route_openidconnect_error' );
					return true;
				}

				if ( $is_saml_response ) {

					if ( $mode === 'selfTest' ) {
						add_action( 'init', '\Wpo\Services\Router_Service::route_plugin_selftest' );
						return true;
					}

					add_action( 'init', '\Wpo\Services\Router_Service::route_saml2_response' );
					return true;
				}

				if ( Options_Service::get_global_boolean_var( 'use_pkce' ) && method_exists( '\Wpo\Services\Pkce_Service', 'process_state_with_verifier' ) ) {
					\Wpo\Services\Pkce_Service::process_state_with_verifier();
				}

				if ( ! empty( $_REQUEST['id_token'] ) ) { // phpcs:ignore
					$id_token = sanitize_text_field( wp_unslash( $_REQUEST['id_token'] ) ); //phpcs:ignore

					if ( Id_Token_Service::check_audience( $id_token ) === true ) {
						$request->set_item( 'encoded_id_token', $id_token );
						unset( $_REQUEST['id_token'] );

						if ( ! empty( $_REQUEST['code'] ) ) { // phpcs:ignore
							$request->set_item( 'code', sanitize_text_field( wp_unslash( $_REQUEST['code'] ) ) ); // phpcs:ignore
							unset( $_REQUEST['code'] );
						}

						if ( $mode === 'selfTest' ) {
							add_action( 'init', '\Wpo\Services\Router_Service::route_plugin_selftest' );
							return true;
						}

						add_action( 'init', '\Wpo\Services\Router_Service::route_openidconnect_token' );
						return true;
					}

					return false;
				}

				if ( ! empty( $_REQUEST['code'] ) ) { // phpcs:ignore
					$request->set_item( 'code', sanitize_text_field( wp_unslash( $_REQUEST['code'] ) ) ); // phpcs:ignore
					unset( $_REQUEST['code'] );

					if ( $mode === 'selfTest' ) {
						add_action( 'init', '\Wpo\Services\Router_Service::route_plugin_selftest' );
						return true;
					}

					if ( $mode === 'mailAuthorize' ) {
						add_action( 'init', '\Wpo\Services\Router_Service::route_mail_authorize' );
						return true;
					}

					add_action( 'init', '\Wpo\Services\Router_Service::route_openidconnect_code' );
					return true;
				}
			}

			// check for user sync start request via external link
			if ( ! empty( $_REQUEST['wpo365_sync_run'] ) ) { // phpcs:ignore
				add_action(
					'init',
					function () {
						$action = wp_unslash( $_REQUEST['wpo365_sync_run'] ); // phpcs:ignore

						if ( $action === 'start' ) {
							self::start_user_sync();
						} elseif ( $action === 'next' ) {
							self::next_user_sync();
						} else {
							die( 'Invalid action defined for wpo365_sync_run.' );
						}
					}
				);
			}

			return false;
		}

		/**
		 * Registers the /wpo/sso/start custom rewrite rule and flushes if not yet saved.
		 * Called on the 'init' action so it runs per-request for every blog in a network.
		 *
		 * @since 34.x
		 *
		 * @return void
		 */
		public static function add_sso_start_rewrite_rule() {
			add_rewrite_rule( '^wpo/sso/start/?$', 'index.php?wpo_sso_start=1', 'top' );

			$rules = get_option( 'rewrite_rules' );

			if ( empty( $rules ) || ! isset( $rules['^wpo/sso/start/?$'] ) ) {
				flush_rewrite_rules( false );
			}
		}

		/**
		 * Adds the wpo_sso_start query variable so WordPress recognises the rewritten parameter.
		 *
		 * @since 34.x
		 *
		 * @param array $vars Existing registered query variables.
		 * @return array
		 */
		public static function add_sso_start_query_var( $vars ) {
			$vars[] = 'wpo_sso_start';
			return $vars;
		}

		/**
		 * Route handler for a /wpo/sso/start request that failed parameter validation.
		 * Sends the user to the login page with the INVALID_ENDPOINT error code.
		 *
		 * @since 34.x
		 *
		 * @return void
		 */
		public static function route_sso_start_invalid() {
			Authentication_Service::goodbye( Error_Service::INVALID_ENDPOINT );
		}

		/**
		 * Wrapper called at 'init' for a validated /wpo/sso/start request.
		 * Restores redirect_to into $_REQUEST from the property bag — where it was stored
		 * at plugins_loaded time — before calling redirect_to_microsoft().
		 * $_REQUEST cannot be set at plugins_loaded time because WordPress calls wp_magic_quotes()
		 * immediately after plugins_loaded fires, which completely rebuilds $_REQUEST as
		 * array_merge( $_GET, $_POST ), discarding any value injected during plugins_loaded.
		 *
		 * @since 34.x
		 *
		 * @return void
		 */
		public static function route_sso_start_initiate() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			// store_sso_start_params guarantees sso_start_redirect_to is a safe, loop-free
			// URL (either the supplied redirect_to or a configured fallback). Write it into
			// $_REQUEST now (at init time) so Url_Helpers::get_state_url() picks it up;
			// wp_magic_quotes() runs immediately after plugins_loaded and would have wiped
			// anything written there.
			$redirect_to = $request->get_item( 'sso_start_redirect_to' );

			if ( ! empty( $redirect_to ) ) {
				$_REQUEST['redirect_to'] = $redirect_to; // phpcs:ignore
			}

			$login_hint = $request->get_item( 'login_hint' );
			Authentication_Service::redirect_to_microsoft( ! empty( $login_hint ) ? $login_hint : null );
		}

		/**
		 * Handler called at 'init' for a legacy action=openidredirect POST request generated
		 * by pintra-redirect.js after it has completed its client-side iframe/Teams detection.
		 * Delegates directly to redirect_to_microsoft(), which skips the Teams template when
		 * action=openidredirect is present, preventing an infinite loop.
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function route_openidredirect() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );
			Authentication_Service::redirect_to_microsoft();
		}

		/**
		 * Route to redirect user to the configured SAML 2.0 IdP
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function route_saml2_response() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( Options_Service::is_wpo365_configured() ) {
				try {
					$wpo_usr = Authentication_Service::authenticate_saml2_user();
					Url_Helpers::goto_after( $wpo_usr );
				} catch ( \Exception $e ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Could not process SAML 2.0 response (' . $e->getMessage() . ')' );
					Authentication_Service::goodbye( Error_Service::SAML2_ERROR );
				}
			}
		}

		/**
		 * Route to process an incoming id token
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function route_openidconnect_token() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( Options_Service::get_global_boolean_var( 'use_id_token_parser_v2' ) && \class_exists( '\Wpo\Services\Id_Token_Service_Deprecated' ) ) {
				\Wpo\Services\Id_Token_Service_Deprecated::process_openidconnect_token();
			} else {
				Id_Token_Service::process_openidconnect_token();
			}

			$wpo_usr = Authentication_Service::authenticate_oidc_user();
			Url_Helpers::goto_after( $wpo_usr );
		}

		/**
		 * Route to process an incoming authorization code
		 *
		 * @since 18.0
		 *
		 * @return void
		 */
		public static function route_openidconnect_code() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( strcasecmp( Options_Service::get_aad_option( 'oidc_flow' ), 'code' ) === 0 ) {

				/**
				 * @since 33.0 Perform a quick check if the code has already been redeemed (page refresh)
				 */

				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$wp_usr_id       = get_current_user_id();
				$code            = $request->get_item( 'code' );

				if ( $wp_usr_id > 0 && ! empty( $code ) ) {
					$hash           = md5( $code );
					$wpo_auth_value = get_user_meta(
						$wp_usr_id,
						Authentication_Service::USR_META_WPO365_AUTH,
						true
					);

					if ( ! empty( $wpo_auth_value ) ) {
						$wpo_auth_value = json_decode( $wpo_auth_value );

						if ( property_exists( $wpo_auth_value, 'auth_code' ) && $wpo_auth_value->auth_code === $hash ) {
							Log_Service::write_log( 'WARN', __METHOD__ . ' -> Not processing an already redeemed authorization code. Most likely the user refreshed the page.' );
							return;
						}
					}
				}

				if ( Options_Service::get_global_boolean_var( 'use_b2c' ) ) {
					\Wpo\Services\Id_Token_Service_B2c::process_openidconnect_code();
				} elseif ( Options_Service::get_global_boolean_var( 'use_ciam' ) ) {
					\Wpo\Services\Id_Token_Service_Ciam::process_openidconnect_code();
				} else {
					\Wpo\Services\Id_Token_Service::process_openidconnect_code();
				}
			} else {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> An authorization code was received but support for the "authorization code flow" has not been configured.' );
				Authentication_Service::goodbye( Error_Service::CHECK_LOG );
			}

			$wpo_usr = Authentication_Service::authenticate_oidc_user();
			Url_Helpers::goto_after( $wpo_usr );
		}

		/**
		 * Route to sign user out of WordPress and redirect to login page
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function route_openidconnect_error() {
			Authentication_Service::goodbye( Error_Service::CHECK_LOG );
		}

		/**
		 * Route to execute plugin selftest and then redirect user back to results (or landing page)
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function route_plugin_selftest() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Perform a self test
			new Self_Test();

			$redirect_to = Url_Helpers::get_redirect_url();
			$redirect_to = remove_query_arg( 'flushPermaLinks', $redirect_to );
			$redirect_to = remove_query_arg( 'mode', $redirect_to );

			Url_Helpers::force_redirect( $redirect_to );
		}

		/**
		 * Route to execute mail authorization with delegated permissions.
		 *
		 * @since 19.0
		 *
		 * @return void
		 */
		public static function route_mail_authorize() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Try update redirect target (wpo365 configured)
			$redirect_url = Url_Helpers::get_redirect_url();

			// Check if wp_mail has been plugged
			if ( \Wpo\Mail\Mailer::check_wp_mail() === false ) {
				Url_Helpers::force_redirect( $redirect_url );
			}

			// Process the incoming authorization code and request an ID token, access and refresh token.
			$scope = Options_Service::get_global_boolean_var( 'mail_send_shared' ) ? 'https://graph.microsoft.com/Mail.Send.Shared' : 'https://graph.microsoft.com/Mail.Send';
			\Wpo\Services\Id_Token_Service::process_openidconnect_code( $scope, false );

			// Uses the tokens received to create a mail user object
			$authorization_result = \Wpo\Mail\Mail_Authorization_Helpers::authorize_mail_user();

			if ( is_wp_error( $authorization_result ) ) {
				Log_Service::write_log(
					'ERROR',
					sprintf(
						'%s -> Mail authorization failed. [%s]',
						__METHOD__,
						$authorization_result->get_error_message()
					)
				);
			}

			Url_Helpers::force_redirect( $redirect_url );
		}

		/**
		 * Analyzes the state URL, memoizes any of the WPO365 internal parameters and removes those.
		 *
		 * @since 19.0
		 *
		 * @param mixed $url
		 * @param mixed $request
		 * @return string|bool Returns false if the URL is not valid and otherwise the state URL with any of the WPO365 internal parameters removed.
		 */
		public static function process_state_url( $url, $request ) {
			$url = urldecode( $url );
			$url = wp_sanitize_redirect( $url );
			$url = str_replace( '#', '_____', $url ); // Replace the hash because parse_url does not handle it well.

			if ( WordPress_Helpers::stripos( $url, '/' ) === 0 ) {
				$url = sprintf(
					'%s%s',
					$GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'],
					ltrim( $url, '/' )
				);
			}

			if ( filter_var( $url, FILTER_VALIDATE_URL ) === false || WordPress_Helpers::stripos( $url, 'http' ) !== 0 ) {
				return false;
			}

			$query = wp_parse_url( $url, PHP_URL_QUERY );

			if ( empty( $query ) ) {
				$result = array();
			} else {
				parse_str( $query, $result );
			}

			if ( isset( $result['mode'] ) ) {
				$mode = $result['mode'];
				$request->set_item( 'mode', $mode );
				$url = remove_query_arg( 'mode', $url );
			}

			if ( isset( $result['tfp'] ) ) {
				$tfp = $result['tfp'];
				$request->set_item( 'tfp', $tfp );
				$url = remove_query_arg( 'tfp', $url );
			}

			if ( isset( $result['idp_id'] ) ) {
				$idp_id = $result['idp_id'];
				$request->set_item( 'idp_id', $idp_id );
				$url = remove_query_arg( 'idp_id', $url );
			}

			if ( isset( $result['pkce_code_challenge_id'] ) ) {
				$pkce_code_challenge_id = $result['pkce_code_challenge_id'];
				$request->set_item( 'pkce_code_challenge_id', $pkce_code_challenge_id );
				$url = remove_query_arg( 'pkce_code_challenge_id', $url );
			}

			$url = str_replace( '_____', '#', $url );
			return $url;
		}

		/**
		 * Returns true when the current request targets the /wpo/sso/start endpoint.
		 * Detection is based on the raw REQUEST_URI path so it works at plugins_loaded
		 * time, before WordPress has parsed query variables.
		 *
		 * @since 34.x
		 *
		 * @return bool
		 */
		public static function detect_sso_start_endpoint() {
			// 1) Bail out early.
			if ( is_admin() || ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] !== 'GET' ) ) {
				return false;
			}

			// 2) Preferred: rely on WP parsed query vars (only valid after parse_request).
			global $wp;

			if ( $wp && ! empty( $wp->query_vars ) ) {
				// query vars are strings in most cases
				if ( isset( $wp->query_vars['wpo_sso_start'] ) && $wp->query_vars['wpo_sso_start'] === '1' ) {
					return true;
				}
			}

			// 3) Plain permalinks / direct query fallback.
			if ( isset( $_GET['wpo_sso_start'] ) && $_GET['wpo_sso_start'] === '1' ) { // phpcs:ignore
				return true;
			}

			// 4) Last resort: strict path match (segment-accurate, not substring).
			$request_uri = ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore

			if ( $request_uri === '' ) {
				return false;
			}

			$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ?? '';
			$path = rtrim( $path, '/' );

			// Match ".../wpo/sso/start" at end of path, optionally preceded by any segments (/en, /foo, etc.).
			return (bool) preg_match( '#(^|/)wpo/sso/start$#i', $path );
		}

		/**
		 * Validates the query-string parameters supplied with a /wpo/sso/start request.
		 *
		 * Allowed parameters (all optional):
		 *   idp_id      — alphanumeric characters only
		 *   login_hint  — a valid e-mail address
		 *   redirect_to — a valid URL (cross-domain allowed for Multisite mapped-domain setups)
		 *   b2c_policy  — alphanumeric characters plus hyphens and underscores
		 *
		 * Any other parameter causes validation to fail.
		 * Validation is performed via sanitize-and-compare.
		 *
		 * @since 34.x
		 *
		 * @return bool True when all supplied parameters are valid, false otherwise.
		 */
		private static function validate_sso_start_params() {
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore
			$query_string = $request_uri !== '' ? wp_parse_url( $request_uri, PHP_URL_QUERY ) : '';

			if ( empty( $query_string ) ) {
				return true; // All parameters are optional.
			}

			$params = array();
			parse_str( $query_string, $params );

			// idp_id: alphanumeric characters only.
			if ( isset( $params['idp_id'] ) ) {
				$sanitized = preg_replace( '/[^a-zA-Z0-9]/', '', $params['idp_id'] );

				if ( $sanitized !== $params['idp_id'] ) {
					return false;
				}
			}

			// login_hint: must be a valid e-mail address.
			if ( isset( $params['login_hint'] ) ) {
				$sanitized = sanitize_email( $params['login_hint'] );

				if ( $sanitized !== $params['login_hint'] || ! is_email( $params['login_hint'] ) ) {
					return false;
				}
			}

			// redirect_to: must be a structurally valid URL (domain is not restricted).
			if ( isset( $params['redirect_to'] ) ) {
				$sanitized = esc_url_raw( $params['redirect_to'] );

				if ( empty( $sanitized ) || $sanitized !== $params['redirect_to'] ) {
					return false;
				}
			}

			// b2c_policy: alphanumeric, hyphens, and underscores only.
			if ( isset( $params['b2c_policy'] ) ) {
				$sanitized = preg_replace( '/[^a-zA-Z0-9_-]/', '', $params['b2c_policy'] );

				if ( $sanitized !== $params['b2c_policy'] ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Copies any validated /wpo/sso/start parameters that are not already handled by
		 * the existing request-init code into the request property bag.
		 *
		 * Not stored here (handled by existing mechanisms):
		 *   idp_id      — extracted from $_REQUEST by Request_Service::get_instance(true)
		 *   redirect_to — read from $_REQUEST by Url_Helpers::get_state_url()
		 *
		 * @since 34.x
		 *
		 * @return void
		 */
		private static function store_sso_start_params() {
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore
			$query_string = $request_uri !== '' ? wp_parse_url( $request_uri, PHP_URL_QUERY ) : '';

			$params = array();

			if ( ! empty( $query_string ) ) {
				parse_str( $query_string, $params );
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			// Store b2c_policy as tfp for compatibility with the B2C/CIAM flow.
			if ( ! empty( $params['b2c_policy'] ) ) {
				$request->set_item( 'tfp', sanitize_text_field( $params['b2c_policy'] ) );
			}

			// Store login_hint for downstream use (e.g. pre-filling the Microsoft login form).
			if ( ! empty( $params['login_hint'] ) ) {
				$request->set_item( 'login_hint', sanitize_email( $params['login_hint'] ) );
			}

			// If no redirect_to was supplied, or if it points back to /wpo/sso/start (which
			// would cause an infinite redirect loop when the user returns from Microsoft),
			// store a safe fallback in the property bag.
			// The value is written into $_REQUEST at init time by route_sso_start_initiate(),
			// because wp_magic_quotes() runs immediately after plugins_loaded and rebuilds
			// $_REQUEST = array_merge( $_GET, $_POST ), wiping anything set here.
			if ( empty( $params['redirect_to'] ) || Url_Helpers::is_sso_start_url( $params['redirect_to'] ) ) {
				if ( ! empty( $params['redirect_to'] ) ) {
					Log_Service::write_log( 'WARN', sprintf( '%s -> redirect_to points back to /wpo/sso/start; replacing with safe fallback', __METHOD__ ) );
				}

				$redirect_to = Options_Service::get_global_string_var( 'goto_after_signon_url' );

				if ( empty( $redirect_to ) ) {
					$redirect_to = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
				}

				$request->set_item( 'sso_start_redirect_to', $redirect_to );
			}
		}

		/**
		 * Checks if WPO365 should process an authentication response that it has detected by comparing the current URL with the Redirect URI.
		 *
		 * @since 25.0
		 *
		 * @param boolean $is_oidc_response
		 * @param boolean $is_saml_response
		 *
		 * @return bool True if WPO365 should continue processing the authentication response.
		 */
		private static function skip_authentication_response( $is_oidc_response, $is_saml_response ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			// Skip if there is no authentication response to process or the admin has disabled SSO and the Graph Mailer or the Graph Mailer is enabled but the reponse is for mail-authorization
			$no_auth_response = ! $is_oidc_response && ! $is_saml_response;
			$no_sso           = Options_Service::get_global_boolean_var( 'no_sso', false );
			$use_mailer       = Options_Service::get_global_boolean_var( 'use_graph_mailer', false );

			if ( $no_auth_response || ( $no_sso && ! $use_mailer ) || ( $no_sso && $use_mailer && $request->get_item( 'mode' ) !== 'mailAuthorize' ) ) {
				return true; // Nothing to do
			}

			// Don't skip if strict-mode has not been enabled
			if ( ! Options_Service::get_aad_option( 'redirect_url_strict', true ) ) {
				return false;
			}

			$home_url = get_option( 'home' );

			if ( $is_oidc_response ) {
				$redirect_url = $request->get_item( 'mode' ) === 'mailAuthorize' ? Options_Service::get_aad_option( 'mail_redirect_url' ) : Options_Service::get_aad_option( 'redirect_url' );
			}

			if ( $is_saml_response ) {
				$redirect_url = Options_Service::get_aad_option( 'saml_sp_acs_url' );
			}

			if ( isset( $redirect_url ) ) {
				$redirect_url = apply_filters( 'wpo365/aad/redirect_uri', $redirect_url );
			}

			if ( empty( $home_url ) || empty( $redirect_url ) ) {
				Log_Service::write_log(
					'WARN',
					sprintf(
						'%s -> The administrator has configured Redirect URI "strict mode" but either the home address URL (%s) or the AAD redirect URI (%s) appears to be empty and "strict mode" can therefore not be enforced.',
						__METHOD__,
						$home_url,
						$redirect_url
					)
				);
				return false;
			}

			$home_url     = Url_Helpers::undress_url( $home_url );
			$redirect_url = Url_Helpers::undress_url( $redirect_url );

			if ( strcasecmp( $home_url, $redirect_url ) === 0 ) {
				Log_Service::write_log(
					'WARN',
					sprintf(
						'%s -> The administrator has configured Redirect URI "strict mode" but the home address URL (%s) and the AAD redirect URI (%s) appear to be equal and therefore "strict mode" cannot be enforced. For "strict mode", the Redirect URI must end with a specific path e.g. %s',
						__METHOD__,
						$home_url,
						$redirect_url,
						sprintf( '%s/sso_auth/', $home_url )
					)
				);
				return false;
			}

			$current_url = $GLOBALS['WPO_CONFIG']['url_info']['current_url'];

			if ( empty( $current_url ) ) {
				Log_Service::write_log(
					'WARN',
					sprintf(
						'%s -> The administrator has configured Redirect URI "strict mode" but WPO365 cannot determine the current URL and therefore "strict mode" cannot be enforced.',
						__METHOD__
					)
				);
				return false;
			}

			$current_url = Url_Helpers::undress_url( $current_url );

			if ( strcasecmp( $current_url, $redirect_url ) === 0 ) {
				Log_Service::write_log(
					'DEBUG',
					sprintf(
						'%s -> The administrator has configured Redirect URI "strict mode" and the current URL (%s) is equal to the redirect URI (%s) and therefore WPO365 will process the OIDC / SAML 2.0 payload.',
						__METHOD__,
						$current_url,
						$redirect_url
					)
				);
				return false;
			}

			Log_Service::write_log(
				'DEBUG',
				sprintf(
					'%s -> The administrator has configured Redirect URI "strict mode" and the current URL (%s) is not equal to the redirect URI (%s) and therefore WPO365 will not process the OIDC / SAML 2.0 payload.',
					__METHOD__,
					$current_url,
					$redirect_url
				)
			);

			return true;
		}

		/**
		 * Will try to start a user-synchronization job from an external URL.
		 *
		 * @since 37.0
		 *
		 * @return never
		 */
		private static function start_user_sync() {
			$can_custom_log = version_compare( Version::$current, '36.2' ) > 0;

			// Bail out early if no job id has been supplied.
			if ( empty( $_REQUEST['job_id'] ) ) { // phpcs:ignore
				$error = sprintf( '%s -> Can not start a user-synchronization job from an external URL because the job_id parameter was not found', __METHOD__ );
				$can_custom_log && Log_Service::write_to_custom_log( $error, 'sync' );
				Log_Service::write_log( 'ERROR', $error );
				exit( wp_kses( $error, WordPress_Helpers::get_allowed_html() ) );
			}

			$job_id = sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ); // phpcs:ignore

			if ( isset( $_REQUEST['type'] ) && wp_unslash( $_REQUEST['type'] === 'wpToAad' ) ) { // phpcs:ignore

				if ( class_exists( '\Wpo\Sync\Sync_Wp_To_Aad_Service' ) ) {
					\Wpo\Sync\Sync_Wp_To_Aad_Service::sync_users( $job_id ); // phpcs:ignore
					exit();
				}

				Log_Service::write_log( 'ERROR', sprintf( '%s -> Can not start a WP to AAD user-synchronization job because required classes are not installed', __METHOD__ ) );
				exit();
			}

			if ( class_exists( '\Wpo\Sync\SyncV2_Service' ) ) { // phpcs:ignore
				$sync_result = \Wpo\Sync\SyncV2_Service::sync_users( $job_id ); // phpcs:ignore
				$message     = is_wp_error( $sync_result ) ? $sync_result->get_error_message() : '';
				exit( wp_kses( $message, WordPress_Helpers::get_allowed_html() ) );
			}

			$log_message = sprintf( '%s -> Can not start a user-synchronization job because required classes are not installed', __METHOD__ );
			$can_custom_log && Log_Service::write_to_custom_log( $log_message, 'sync' );
			Log_Service::write_log( 'ERROR', $log_message );
			exit( wp_kses( $log_message, WordPress_Helpers::get_allowed_html() ) );
		}

		/**
		 * Will try to process a next batch of users from an external URL.
		 *
		 * @since 37.0
		 *
		 * @return never
		 */
		private static function next_user_sync() {
			$can_custom_log = version_compare( Version::$current, '36.2' ) > 0;

			// Bail out early if no job id has been supplied.
			if ( empty( $_REQUEST['job_id'] ) ) { // phpcs:ignore
				$error = sprintf( '%s -> Can not process a next batch of users from an external URL because the job_id parameter is not found', __METHOD__ );
				$can_custom_log && Log_Service::write_to_custom_log( $error, 'sync' );
				Log_Service::write_log( 'ERROR', $error );
				exit( wp_kses( $error, WordPress_Helpers::get_allowed_html() ) );
			}

			$job_id = sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ); // phpcs:ignore
			$job    = \Wpo\Sync\Sync_Helpers::get_user_sync_job_by_id( $job_id );

			if ( is_wp_error( $job ) ) {
				$error = sprintf( '%s -> Can not process a next batch of users from an external URL because a job with ID %s was not found', __METHOD__, $job_id );
				$can_custom_log && Log_Service::write_to_custom_log( $error, 'sync' );
				Log_Service::write_log( 'ERROR', $error );
				exit( wp_kses( $error, WordPress_Helpers::get_allowed_html() ) );
			}

			if ( empty( $job['last'] ) || ! empty( $job['last']['stopped'] ) ) {
				$warning = sprintf( '%s -> Can not process a next batch of users from an external URL because the job with ID %s has either not been started or has already stopped', __METHOD__, $job_id );
				$can_custom_log && Log_Service::write_to_custom_log( $warning, 'sync' );
				Log_Service::write_log( 'DEBUG', $warning );
				exit( wp_kses( $warning, WordPress_Helpers::get_allowed_html() ) );
			}

			if ( class_exists( '\Wpo\Sync\SyncV2_Service' ) && class_exists( '\Wpo\Sync\Sync_Helpers' ) ) {
				$fetch_result = \Wpo\Sync\SyncV2_Service::fetch_users( $job_id );
				$message      = is_wp_error( $fetch_result ) ? $fetch_result->get_error_message() : '';
				exit( wp_kses( $message, WordPress_Helpers::get_allowed_html() ) );
			}

			$error          = sprintf( '%s -> Can not start a user-synchronization job because required classes are not installed', __METHOD__ );
			$can_custom_log = version_compare( Version::$current, '36.2' ) > 0;
			$can_custom_log && Log_Service::write_to_custom_log( $error, 'sync' );
			Log_Service::write_log( 'ERROR', $error );
			exit( wp_kses( $error, WordPress_Helpers::get_allowed_html() ) );
		}
	}
}
