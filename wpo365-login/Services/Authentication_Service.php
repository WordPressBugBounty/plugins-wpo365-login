<?php

namespace Wpo\Services;

use Wpo\Core\Domain_Helpers;
use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Error_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;
use Wpo\Services\Saml2_Service;
use Wpo\Services\User_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Authentication_Service' ) ) {

	class Authentication_Service {


		const USR_META_WPO365_AUTH = 'WPO365_AUTH';

		/**
		 * @param bool $force  boolean If true the test whether authentication can be skipped will be skipped.
		 */
		public static function authenticate_request( $force = false ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			/**
			 * @since   23.0    If the audience check fails and the administrator has configured the plugin
			 *                  not to exit when the audience check fails.
			 */
			if ( $request->get_item( 'skip_authentication' ) === true ) {
				return;
			}

			$wp_usr_id = get_current_user_id();

			$wpo_auth_value = get_user_meta(
				$wp_usr_id,
				self::USR_META_WPO365_AUTH,
				true
			);

			$request->set_item( 'wpo_auth_value', $wpo_auth_value );
			$request->set_item( 'wp_usr_id', $wp_usr_id );

			if ( ! $force && self::skip_authentication() ) {
				return;
			}

			// Logged-on WP-only user
			if ( is_user_logged_in() && empty( $wpo_auth_value ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> User is a Wordpress-only user so no authentication is required' );
				return;
			}

			// User not logged on
			if ( empty( $wpo_auth_value ) ) {

				// If multiple IdPs have been configured then the user must first select one
				if ( ! empty( Wp_Config_Service::get_multiple_idps() ) ) {
					Log_Service::write_log( 'DEBUG', sprintf( '%s -> Multiple IdPs have been configured and the user has not selected one and therefore he / she is redirected to the login page instead', __METHOD__ ) );
					self::goodbye( Error_Service::NO_IDP_SELECTED );
					exit();
				}

				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> User is not logged in and therefore sending the user to Microsoft to sign in' );
				$login_hint = isset( $_REQUEST['login_hint'] ) // phpcs:ignore
					? \sanitize_text_field( $_REQUEST['login_hint'] ) // phpcs:ignore
					: null;
				self::redirect_to_microsoft( $login_hint );
			}

			// Check if user has expired
			$wpo_auth = json_decode( $wpo_auth_value );

			// If 0 then session expiration check is skipped
			if ( Options_Service::get_global_numeric_var( 'session_duration' ) > 0 ) {
				$auth_expired = ! isset( $wpo_auth->expiry ) || $wpo_auth->expiry < time();

				if ( $auth_expired ) {

					$upn = User_Service::try_get_user_principal_name( $wp_usr_id );

					$login_hint = ! empty( $upn ) ? $upn : null;

					do_action( 'destroy_wpo365_session' );

					// Don't call wp_logout because it may be extended
					wp_destroy_current_session();
					wp_clear_auth_cookie();
					wp_set_current_user( 0 );

					unset( $_COOKIE[ AUTH_COOKIE ] );
					unset( $_COOKIE[ SECURE_AUTH_COOKIE ] );
					unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> User logged out because current login not valid anymore (' . $auth_expired . ')' );

					self::redirect_to_microsoft( $login_hint );
				}
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Session expiration ignored because the administrator configured a duration of 0' );
			}

			Wpmu_Helpers::wpmu_add_user_to_blog( $wp_usr_id, null, null );
		}

		/**
		 * @since 11.0
		 */
		public static function authenticate_oidc_user() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			/**
			 * Switch the blog context if WPMU is detected and the user is trying to access
			 * a subsite but landed at the main site because of Microsoft redirecting the
			 * user there immediately after successful authentication.
			 */
			$state     = $request->get_item( 'state' );
			$id_token  = $request->get_item( 'id_token' );
			$auth_code = $request->get_item( 'code' );

			Wpmu_Helpers::switch_blog( $state );

			if ( empty( $id_token ) ) {
				$error_message = sprintf( '%s -> ID token could not be extracted from request storage.', __METHOD__ );
				Log_Service::write_log( 'ERROR', $error_message );
				self::goodbye( Error_Service::ID_TOKEN_ERROR );
				exit();
			}

			$wpo_usr = Options_Service::get_global_boolean_var( 'use_b2c' ) && \class_exists( '\Wpo\Services\Id_Token_Service_B2c' )
				? User_Service::user_from_b2c_id_token( $id_token )
				: User_Service::user_from_id_token( $id_token );

			self::user_in_group( $wpo_usr );

			do_action(
				'wpo365/oidc/authenticating',
				$wpo_usr->preferred_username,
				$wpo_usr->email,
				$wpo_usr->groups
			);

			/**
			 * Authenticate but don't sign in Azure AD users.
			 *
			 * @since   16.0
			 */

			if ( apply_filters( 'wpo365/cookie/set', $wpo_usr, $state ) === true ) {
				return $wpo_usr;
			}

			$wp_usr = User_Service::ensure_user( $wpo_usr );

			if ( empty( $wp_usr ) ) {
				$error_message = sprintf( '%s -> Multiple errors occurred: please check debug log for previous errors', __METHOD__ );
				Log_Service::write_log( 'ERROR', $error_message );
				self::goodbye( Error_Service::CHECK_LOG );
				exit();
			}

			// Now log on the user
			wp_set_auth_cookie( $wp_usr->ID, Options_Service::get_global_boolean_var( 'remember_user' ) );  // Both log user on
			wp_set_current_user( $wp_usr->ID );       // And set current user

			// Session valid until
			$session_duration = Options_Service::get_global_numeric_var( 'session_duration' );
			$session_duration = empty( $session_duration ) ? 3480 : $session_duration;
			$expiry           = time() + intval( $session_duration );

			// Obfuscated user's wp id
			$obfuscated_user_id  = $expiry + $wp_usr->ID;
			$wpo_auth            = new \stdClass();
			$wpo_auth->expiry    = $expiry;
			$wpo_auth->ouid      = $obfuscated_user_id;
			$wpo_auth->upn       = $wpo_usr->upn;
			$wpo_auth->url       = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
			$wpo_auth->auth_code = md5( $auth_code );

			update_user_meta(
				$wp_usr->ID,
				self::USR_META_WPO365_AUTH,
				wp_json_encode( $wpo_auth )
			);

			$request->set_item( 'wpo_auth_value', $wpo_auth );

			/**
			 * Fires after the user has successfully logged in.
			 *
			 * @since 7.1
			 *
			 * @param string  $user_login Username.
			 * @param WP_User $user       WP_User object of the logged-in user.
			 */
			if ( Options_Service::get_global_boolean_var( 'skip_wp_login_action' ) === false ) {
				do_action( 'wp_login', $wp_usr->user_login, $wp_usr );
			}

			/**
			 * @since 10.6
			 *
			 * The wpo365_openid_token_processed action hook signals to its subscribers
			 * that a user has just signed in successfully with Microsoft. As arguments
			 * it provides the WordPress user ID and the user's Azure AD group IDs
			 * as an one-dimensional array of GUIDs (as strings).
			 */

			do_action( 'wpo365_openid_token_processed', $wp_usr->ID, $wpo_usr->groups, $id_token );

			/**
			 * @since 15.0
			 */

			do_action( 'wpo365/oidc/authenticated', $wp_usr->ID );

			return $wpo_usr;
		}

		public static function authenticate_saml2_user() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			/**
			 * Switch the blog context if WPMU is detected and the user is trying to access
			 * a subsite but landed at the main site because of Microsoft redirecting the
			 * user there immediately after successful authentication.
			 */
			$state = $request->get_item( 'relay_state' );
			Wpmu_Helpers::switch_blog( $state );

			require_once $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/OneLogin/_toolkit_loader.php';

			$saml_settings = Saml2_Service::saml_settings();
			$auth          = new \OneLogin_Saml2_Auth( $saml_settings );
			$auth->processResponse();

			// Check for errors
			$errors = $auth->getErrors();

			if ( ! empty( $errors ) ) {
				$error_reason = $auth->getLastErrorReason();
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Could not process SAML 2.0 response (See log for errors [' . $error_reason . '])' );
				Log_Service::write_log( 'WARN', $errors );
				self::goodbye( Error_Service::SAML2_ERROR );
				exit();
			}

			// Check if authentication was successful
			if ( ! $auth->isAuthenticated() ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User is not authenticated' );
				self::goodbye( Error_Service::SAML2_ERROR );
				exit();
			}

			// Check against replay attack
			Saml2_Service::check_message_id( $auth->getLastMessageId() );

			// Abstraction to WPO365 User
			$saml_attributes = $auth->getAttributes();
			$saml_name_id    = $auth->getNameId();
			$wpo_usr         = User_Service::user_from_saml_response( $saml_name_id, $saml_attributes );

			self::user_in_group( $wpo_usr );

			do_action(
				'wpo365/saml2/authenticating',
				$wpo_usr->preferred_username,
				$wpo_usr->email,
				$wpo_usr->groups
			);

			/**
			 * Authenticate but don't sign in Azure AD users.
			 *
			 * @since   16.0
			 */

			if ( apply_filters( 'wpo365/cookie/set', $wpo_usr, $state ) === true ) {
				return $wpo_usr;
			}

			$wp_usr = User_Service::ensure_user( $wpo_usr );

			if ( empty( $wp_usr ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Multiple errors occurred: please check debug log for previous errors' );
				self::goodbye( Error_Service::CHECK_LOG );
				exit();
			}

			// Now log on the user
			wp_set_auth_cookie( $wp_usr->ID, Options_Service::get_global_boolean_var( 'remember_user' ) );  // Both log user on
			wp_set_current_user( $wp_usr->ID );       // And set current user

			// Session valid until
			$session_duration = Options_Service::get_global_numeric_var( 'session_duration' );
			$session_duration = empty( $session_duration ) ? 3480 : $session_duration;
			$expiry           = time() + intval( $session_duration );

			// Obfuscated user's wp id
			$obfuscated_user_id = $expiry + $wp_usr->ID;
			$wpo_auth           = new \stdClass();
			$wpo_auth->expiry   = $expiry;
			$wpo_auth->ouid     = $obfuscated_user_id;
			$wpo_auth->upn      = $wpo_usr->upn;
			$wpo_auth->url      = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

			update_user_meta(
				$wp_usr->ID,
				self::USR_META_WPO365_AUTH,
				wp_json_encode( $wpo_auth )
			);

			$request->set_item( 'wpo_auth_value', $wpo_auth );

			/**
			 * Fires after the user has successfully logged in.
			 *
			 * @since 7.1
			 *
			 * @param string  $user_login Username.
			 * @param WP_User $user       WP_User object of the logged-in user.
			 */
			if ( Options_Service::get_global_boolean_var( 'skip_wp_login_action' ) === false ) {
				do_action( 'wp_login', $wp_usr->user_login, $wp_usr );
			}

			/**
			 * @since 15.0
			 */

			do_action( 'wpo365/saml/authenticated', $wp_usr->ID );

			return $wpo_usr;
		}

		/**
		 * Redirects the user either back to site with an HTTP POST or when dual login
		 * is configured to the (custom) login form. The data POSTed tells the plugin
		 * to initiate the Sign in with Microsoft flow (both OpenID Connect + SAML).
		 *
		 * @since 8.0
		 *
		 * @param string $login_hint string Login hint that will be added to the Open ID Connect link if present.
		 *
		 * @return void
		 */
		public static function redirect_to_microsoft( $login_hint = null ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( ! Options_Service::is_wpo365_configured() ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Attempt to initiate SSO failed because WPO365 is not configured', __METHOD__ ) );
				return;
			}

			if ( class_exists( '\Wpo\Services\Dual_Login_Service' ) ) {
				\Wpo\Services\Dual_Login_Service::redirect();
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Forwarding the user to Microsoft to get fresh ID and access token(s)' );

			/**
			 * @since 33.0  The loading template has become optional and instead the redirect to Microsoft
			 *              is performed server-side.
			 */

			if ( Options_Service::get_global_boolean_var( 'use_teams' ) ) {
				ob_start();
				include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/openid-redirect.php';
				$content = ob_get_clean();
				echo wp_kses( $content, WordPress_Helpers::get_allowed_html() );
				exit();
			}

			if ( Options_Service::get_global_boolean_var( 'use_saml' ) ) {
				\Wpo\Services\Saml2_Service::initiate_request();
				exit();
			} else {
				if ( Options_Service::get_global_boolean_var( 'use_b2c' ) && \class_exists( '\Wpo\Services\Id_Token_Service_B2c' ) ) {
					$auth_url = \Wpo\Services\Id_Token_Service_B2c::get_openidconnect_url( $login_hint );
				} elseif ( Options_Service::get_global_boolean_var( 'use_ciam' ) ) {
					$auth_url = \Wpo\Services\Id_Token_Service_Ciam::get_openidconnect_url( $login_hint );
				} else {
					$auth_url = Id_Token_Service::get_openidconnect_url( $login_hint );
				}

				Url_Helpers::force_redirect( $auth_url );
			}
		}

		/**
		 * @since 11.0
		 */
		private static function user_in_group( $wpo_usr ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Check whether allowed (Office 365 or Security) Group Ids have been configured
			$allowed_groups_ids = Options_Service::get_global_list_var( 'groups_whitelist' );

			if ( count( $allowed_groups_ids ) > 0 ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Group policy has been defined' );

				if ( empty( $wpo_usr->groups ) || ! ( count(
					array_intersect_key(
						array_flip( $allowed_groups_ids ),
						$wpo_usr->groups
					)
				) ) >= 1 ) {
					$express_login = Options_Service::get_global_boolean_var( 'express_login' );

					if ( $express_login ) {
						Log_Service::write_log(
							'ERROR',
							__METHOD__ . ' -> Access denied error because the administrator has restricted
                        access to a limited number of Azure AD (security) groups but also enabled Express Login. As a result the plugin
                        can possibly not retrieve all Azure AD (security) groups that a user is a member of.'
						);
					} else {
						Log_Service::write_log(
							'WARN',
							__METHOD__ . ' -> Access denied error because the administrator has restricted
                        access to a limited number of Azure AD (security) groups and the user trying to log on 
                        is not in one of these groups.'
						);
					}

					self::goodbye( Error_Service::NOT_IN_GROUP );
					exit();
				}
			}
		}

		/**
		 * @since 11.0
		 */
		public static function user_from_domain( $preferred_username, $email ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Check whether the user's domain is white listed (if empty this check is skipped)
			$domains             = Options_Service::get_global_list_var( 'domain_whitelist' );
			$domain_blocked_list = Options_Service::get_global_boolean_var( 'domain_blocked_list' );

			if ( count( $domains ) > 0 ) {
				$login_domain = Domain_Helpers::get_smtp_domain_from_email_address( $preferred_username );
				$email_domain = Domain_Helpers::get_smtp_domain_from_email_address( $email );

				$match = function ( $test_domain ) use ( $domains ) {

					foreach ( $domains as $domain ) {

						if ( empty( $domain ) ) {
							continue;
						}

						if ( ! empty( $test_domain ) ) {

							// Wildcard at the end -> stripos must be 0
							if ( substr( $domain, -2 ) === '.*' ) {
								$test_with = str_replace( '.*', '', $domain );

								if ( WordPress_Helpers::stripos( $test_domain, $test_with ) === 0 ) {
									return true;
								}
							} elseif ( substr( $domain, 0, 2 ) === '*.' ) { // Wildcard at the beginning -> stripos must be greater than 0
								$test_with = str_replace( '*.', '', $domain );
								$strpos    = WordPress_Helpers::stripos( $test_domain, $test_with );

								if ( $strpos !== false && $strpos >= 0 ) {
									return true;
								}
							} elseif ( strcasecmp( $test_domain, $domain ) === 0 ) {
									return true;
							}
						}
					}

					return false;
				};

				$found = $match( $login_domain );

				if ( ! $found ) {
					$found = $match( $email_domain );
				}

				// Only users from specific domains are allowed to sign in
				if ( ! $domain_blocked_list ) {

					if ( ! $found ) {
						Log_Service::write_log(
							'WARN',
							sprintf(
								'%s -> Access denied error because the administrator has restricted access to a limited number of domains [login: %s, email: %s]',
								__METHOD__,
								$login_domain,
								$email_domain
							)
						);
						self::goodbye( Error_Service::NOT_FROM_DOMAIN );
						exit();
					}
					// Users from specific domains are NOT allowed to sign in
				} elseif ( $found ) {

						Log_Service::write_log(
							'WARN',
							sprintf(
								'%s -> Access denied error because the administrator has blocked access for domain [login: %s, email: %s]',
								__METHOD__,
								$login_domain,
								$email_domain
							)
						);
						self::goodbye( Error_Service::NOT_FROM_DOMAIN );
						exit();
				}
			}
		}

		/**
		 * Destroys any session and authenication artefacts and hooked up with wpo365_logout and should
		 * therefore never be called directly to avoid endless loops etc.
		 *
		 * @since   1.0
		 *
		 * @return  void
		 */
		public static function destroy_session() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$wp_usr_id = get_current_user_id();

			if ( empty( $wp_usr_id ) ) {
				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$wp_usr_id       = $request->get_item( 'wp_usr_id' );
			}

			Log_Service::write_log(
				'DEBUG',
				sprintf(
					'%s -> Destroying session for %s',
					__METHOD__,
					( isset( $_SERVER['PHP_SELF'] ) ? strtolower( basename( $_SERVER['PHP_SELF'] ) ) : '' ) // phpcs:ignore
				)
			);

			if ( ! empty( $wp_usr_id ) ) {
				delete_user_meta( $wp_usr_id, self::USR_META_WPO365_AUTH );
				delete_user_meta( $wp_usr_id, Access_Token_Service::USR_META_WPO365_AUTH_CODE );
			}
		}

		/**
		 * Same as destroy_session but with redirect to login page (but only if the
		 * login page isn't the current page).
		 *
		 * @since   1.0
		 *
		 * @param   string $login_error_code   Error code that is added to the logout url as query string parameter.
		 * @param   bool   $login_error        Whether an error occurred during login (if not, then most likely during user creation).
		 * @return  void
		 */
		public static function goodbye( $login_error_code, $login_error = true ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$error_page_url  = Options_Service::get_global_string_var( 'error_page_url' );
			$error_page_path = WordPress_Helpers::rtrim( wp_parse_url( $error_page_url, PHP_URL_PATH ), '/' );

			$redirect_to = ( empty( $error_page_url ) || $error_page_path === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] )
				? Url_Helpers::get_preferred_login_url()
				: $error_page_url;

			if ( empty( $_SERVER['PHP_SELF'] ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> $_SERVER[PHP_SELF] is empty. Please review your server configuration.' );
			}

			do_action( 'destroy_wpo365_session' );

			if ( $login_error ) {
				do_action( 'wpo365/user/loggedin/fail', $login_error_code );
			} else {
				do_action( 'wpo365/user/created/fail', $login_error_code );
			}

			wp_destroy_current_session();
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );

			unset( $_COOKIE[ AUTH_COOKIE ] );
			unset( $_COOKIE[ SECURE_AUTH_COOKIE ] );
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

			$redirect_to = add_query_arg( 'login_errors', $login_error_code, $redirect_to );

			/**
			 * @since 34.3 Adding the redirect_to query arg to enable the user to recover from the error
			 */

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$state_url       = $request->get_item( 'state' );

			if ( empty( $state_url ) ) {
				$state_url = $request->get_item( 'relay_state' );
			}

			if ( ! empty( $state_url ) ) {
				$redirect_to = add_query_arg( 'redirect_to', $state_url, $redirect_to );
			} elseif ( ! empty( wp_unslash( $_REQUEST['redirect_to'] ) ) ) { // phpcs:ignore
				$redirect_to = add_query_arg( 'redirect_to', esc_url_raw( $_REQUEST['redirect_to'] ), $redirect_to ); // phpcs:ignore
			}

			Url_Helpers::force_redirect( $redirect_to );
		}

		/**
		 * Helper hooked up to the wp_authenticate trigger to check if the user has been deactivated or not.
		 *
		 * @since   10.1
		 *
		 * @param   string $login The user's login name.
		 * @param   bool   $kill_session Whether or not to exit.
		 *
		 * @return  void
		 */
		public static function is_deactivated( $login = '', $kill_session = false ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$wp_usr = \get_user_by( 'login', $login );

			if ( ! empty( $wp_usr ) && \get_user_meta( $wp_usr->ID, 'wpo365_active', true ) === 'deactivated' ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Account ' . $wp_usr->login . ' is deactivated' );

				if ( $kill_session ) {
					self::goodbye( Error_Service::DEACTIVATED );
					exit();
				}

				$error_page_url  = Options_Service::get_global_string_var( 'error_page_url' );
				$error_page_path = WordPress_Helpers::rtrim( wp_parse_url( $error_page_url, PHP_URL_PATH ), '/' );

				$redirect_to = ( empty( $error_page_url ) || $error_page_path === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] )
					? Url_Helpers::get_preferred_login_url()
					: $error_page_url;

				$redirect_to = add_query_arg( 'login_errors', Error_Service::DEACTIVATED, $redirect_to );
				Url_Helpers::force_redirect( $redirect_to );
			}
		}

		/**
		 * Checks the configured scenario and the pages black list settings to
		 * decide whether or not authentication of the current page is needed.
		 *
		 * @since 5.0
		 *
		 * @return  boolean     True if validation should be skipped, otherwise false.
		 */
		private static function skip_authentication() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			/**
			 * @since   31.0    Skip authentication for specific IP addresses
			 */

			$ip_addresses = Options_Service::get_global_list_var( 'skip_ips' );

			if ( ! empty( $ip_addresses ) ) {
				$remote_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

				if ( filter_var( $remote_address, FILTER_VALIDATE_IP ) !== false ) {

					foreach ( $ip_addresses as $ip_address ) {

						if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) !== false && strcasecmp( $ip_address, $remote_address ) === 0 ) {
							Log_Service::write_log(
								'DEBUG',
								sprintf(
									'%s -> Skipping authentication because the user\'s IP address %s is in the list of IP addresses freed from authentication',
									__METHOD__,
									$remote_address
								)
							);
							return true;
						}
					}
				}
			}

			/**
			 * @since   21.9    Skip authentication when wp-cli is detected.
			 */

			if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) === true && Options_Service::get_global_boolean_var( 'use_wp_cli' ) ) {
				Log_Service::write_log( 'DEBUG', sprintf( '%s -> Skipping authentication [reason: wp-cli]', __METHOD__ ) );
				return true;
			}

			// Skip when a basic authentication header is detected
			if (
				Options_Service::get_global_boolean_var( 'skip_api_basic_auth_request' ) === true
				&& Url_Helpers::is_basic_auth_api_request()
			) {
				return true;
			}

			// Not logged on and not configured => log in as WP Admin first
			if ( ! is_user_logged_in() && ( Options_Service::is_wpo365_configured() === false ) ) {
				return true;
			}

			// Bail out if the administrator configured to disable SSO for WP Admin
			if ( ( is_admin() || is_network_admin() ) && Options_Service::get_global_boolean_var( 'skip_wp_admin' ) ) {
				return true;
			}

			/**
			 * @since   16.0    If this is login and an wpo365 auth cookie is found then try
			 *                  to trick any page caching mechanism.
			 */
			do_action( 'wpo365/cookie/redirect' );

			/**
			 * @since   12.x
			 *
			 * Administrator enabled SSO for the login page and dual login is not enabled.
			 */

			if ( Options_Service::get_global_boolean_var( 'redirect_on_login' ) === true && Url_Helpers::is_wp_login() ) {
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore

				// Bail out early when a user is attempting to logout.
				if ( $action === 'logout' ) {
					return true;
				}

				$dual_login_enabled = Options_Service::get_global_boolean_var( 'redirect_to_login_v2' );
				$skip_wp_admin      = Options_Service::get_global_boolean_var( 'skip_wp_admin' );
				$bypass_key         = Options_Service::get_aad_option( 'redirect_on_login_secret' );
				$error_page         = Options_Service::get_global_string_var( 'error_page_url' );
				$secure             = ( wp_parse_url( wp_login_url(), PHP_URL_SCHEME ) === 'https' );
				$cookie_name        = defined( 'WPO_SSO_BYPASS_COOKIE' ) ? constant( 'WPO_SSO_BYPASS_COOKIE' ) : 'wordpress_wpo365_sso_bypass';
				$rp_key             = $action === 'rp' && isset( $_REQUEST['login'] ) && isset( $_REQUEST['key'] ) ? sanitize_text_field( $_REQUEST['key'] ) : ''; // phpcs:ignore
				$rp_login           = $action === 'rp' && isset( $_REQUEST['login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['login'] ) ) : ''; // phpcs:ignore
				$rp_key_check_valid = empty( $rp_key ) || empty( $rp_login ) ? false : ! is_wp_error( check_password_reset_key( $rp_key, $rp_login ) );
				$is_post_password   = $action === 'postpass' && isset( $_POST['post_password'] ) && is_string( $_POST['post_password'] ); // phpcs:ignore

				// Bail out early when a request with valid key to reset the password is detected.
				if ( $rp_key_check_valid ) {
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> A request for the reset password page will be allowed pass-thru [cookie will be set]' );
					setcookie( $cookie_name, $bypass_key, 0, COOKIEPATH, COOKIE_DOMAIN, $secure );
					return true;
				}

				// Bail out early when user enters a password to view a password-protected post or attempts to reset their password.
				if ( $is_post_password ) {
					return true;
				} elseif ( isset( $_COOKIE[ $cookie_name ] ) ) { // Admin has configured to enable SSO for the login page but pre-requisites are not fulfulled.
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> A request for the login page will be allowed pass-thru [cookie will be deleted]' );
					$cookie = wp_unslash( $_COOKIE[ $cookie_name ] ); // phpcs:ignore

					// Remove sso-bypass-cookie when data is posted to the login page (unless user is resetting their password)
					if ( ! empty( $_REQUEST ) && $action !== 'lostpassword' && $action !== 'rp' && $action !== 'resetpass' ) { // phpcs:ignore
						setcookie( $cookie_name, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, $secure );
						unset( $_COOKIE[ $cookie_name ] );
					}

					if ( $cookie === $bypass_key ) {
						return true;
					}
				} elseif ( $dual_login_enabled ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Administrator has enabled SSO for the login page but has also enabled the contradicting Dual Login feature' );
				} elseif ( $skip_wp_admin ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Administrator enabled SSO for the login page but has also disabled SSO for WP Admin' );
				} elseif ( empty( $bypass_key ) ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Administrator has enabled SSO for the login page but has not configured a mandatory secret key to bypass SSO' );
				} elseif ( strlen( $bypass_key ) < 32 ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Administrator has enabled SSO for the login page but the length of the mandatory secret key to bypass SSO is less than 32 characters' );
				} elseif ( empty( $error_page ) ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Administrator has enabled SSO for the login page but has not configured a mandatory error page' );
				} elseif ( is_user_logged_in() ) { // Admin has configured to enable SSO for the login page but user is already logged-in.
					Url_Helpers::goto_after();
				} elseif ( isset( $_GET[ $bypass_key ] ) ) { // phpcs:ignore
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> A request for the login page will be allowed pass-thru [cookie will be set]' );
					setcookie( $cookie_name, $bypass_key, 0, COOKIEPATH, COOKIE_DOMAIN, $secure );
					return true;
				} else { // Admin has configured to enable SSO for the login page but no secret key has been detected.
					return false;
				}
			}

			// Check if current page is homepage and can be skipped
			$public_homepage = Options_Service::get_global_boolean_var( 'public_homepage' );

			if ( $public_homepage === true && ( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] === $GLOBALS['WPO_CONFIG']['url_info']['request_uri'] ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Cancelling session validation for home page because public homepage is selected' );
				return true;
			}

			// Check if current page is blacklisted and can be skipped
			$black_listed_pages = Options_Service::get_global_list_var( 'pages_blacklist' );

			// Always add Error Page URL (if configured)
			$error_page_url = Options_Service::get_global_string_var( 'error_page_url' );

			if ( ! empty( $error_page_url ) && WordPress_Helpers::stripos( $error_page_url, WordPress_Helpers::rtrim( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'], '/' ) ) === 0 ) {
				$error_page_url  = WordPress_Helpers::rtrim( strtolower( $error_page_url ), '/' );
				$error_page_path = WordPress_Helpers::rtrim( wp_parse_url( $error_page_url, PHP_URL_PATH ), '/' );

				if ( empty( $error_page_path ) || $error_page_path === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Error page URL must be a page and cannot be the root of the current website (' . $error_page_path . ')' );
				} else {
					$black_listed_pages[] = $error_page_path;
				}
			}

			// Always add Custom Login URL (if configured)
			$custom_login_url = Options_Service::get_global_string_var( 'custom_login_url' );

			if ( ! empty( $custom_login_url ) ) {
				$custom_login_url  = WordPress_Helpers::rtrim( strtolower( $custom_login_url ), '/' );
				$custom_login_path = WordPress_Helpers::rtrim( wp_parse_url( $custom_login_url, PHP_URL_PATH ), '/' );

				if ( empty( $custom_login_path ) || $custom_login_path === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Custom Login URL must be a page and cannot be the root of the current website (' . $custom_login_path . ')' );
				} else {
					$black_listed_pages[] = $custom_login_path;
				}
			}

			// Ensure default login path
			$default_login_url_path = wp_parse_url( wp_login_url(), PHP_URL_PATH );

			if ( array_search( $default_login_url_path, $black_listed_pages, true ) === false ) {
				$black_listed_pages[] = $default_login_url_path;
			}

			// Ensure admin-ajax.php
			$admin_ajax_path = 'admin-ajax.php';

			if ( array_search( $admin_ajax_path, $black_listed_pages, true ) === true ) {
				$black_listed_pages[] = $admin_ajax_path;
			}

			// Ensure wp-cron.php
			$wp_cron_path = 'wp-cron.php';

			if ( array_search( $wp_cron_path, $black_listed_pages, true ) === false ) {
				$black_listed_pages[] = $wp_cron_path;
			}

			// Ensure /favicon.ico

			$favicon_path = '/favicon.ico';

			if ( array_search( $favicon_path, $black_listed_pages, true ) === false ) {
				$black_listed_pages[] = $favicon_path;
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Pages Blacklist after error page / custom login has verified' );
			Log_Service::write_log( 'DEBUG', $black_listed_pages );

			// Check if current page is blacklisted and can be skipped
			foreach ( $black_listed_pages as $black_listed_page ) {

				$black_listed_page = WordPress_Helpers::rtrim( strtolower( $black_listed_page ), '/' );

				// Filter out empty or mis-configured black page entries
				if ( empty( $black_listed_page ) || $black_listed_page === '/' || $black_listed_page === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_path'] ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Black listed page page must be a page and cannot be the root of the current website (' . $black_listed_page . ')' );
					continue;
				}

				// Correction after the plugin switched from basename to path based comparison
				$starts_with       = substr( $black_listed_page, 0, 1 );
				$black_listed_page = $starts_with === '/' || $starts_with === '?' ? $black_listed_page : '/' . $black_listed_page;

				// Filter out any attempt to illegally bypass authentication
				$illegal_stripos = WordPress_Helpers::stripos( $GLOBALS['WPO_CONFIG']['url_info']['request_uri'], '?/' );
				if ( $illegal_stripos !== false && strlen( $GLOBALS['WPO_CONFIG']['url_info']['request_uri'] ) > ( $illegal_stripos + 2 ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> Serious attempt to try to bypass authentication using an illegal query string combination "?/" (path used: ' . $GLOBALS['WPO_CONFIG']['url_info']['request_uri'] . ')' );
					break;
				} elseif ( WordPress_Helpers::stripos( $GLOBALS['WPO_CONFIG']['url_info']['request_uri'], $black_listed_page ) !== false ) {
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found [' . $black_listed_page . '] thus cancelling session validation for path ' . $GLOBALS['WPO_CONFIG']['url_info']['request_uri'] );
					return true;
				}
			}

			$scenario = Options_Service::get_global_string_var( 'auth_scenario' );

			if ( ! is_admin() && ! is_network_admin() && ( $scenario === 'internet' || $scenario === 'internetAuthOnly' ) ) {
				$private_pages = Options_Service::get_global_list_var( 'private_pages' );
				$login_urls    = Url_Helpers::get_login_urls();

				// Check if current page is private and cannot be skipped
				foreach ( $private_pages as $private_page ) {
					$private_page = WordPress_Helpers::rtrim( strtolower( $private_page ), '/' );

					if ( empty( $private_page ) ) {
						continue;
					}

					if ( $private_page === $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] ) {
						Log_Service::write_log( 'ERROR', __METHOD__ . ' -> The following entry in the Private Pages list is illegal because it is the site url: ' . $private_page );
						continue;
					}

					/**
					 * @since 9.0
					 *
					 * Prevent users from hiding the login page.
					 */

					if ( ( ! empty( $login_urls['default_login_url'] ) && WordPress_Helpers::stripos( $private_page, $login_urls['default_login_url'] ) !== false ) || ( ! empty( $login_urls['custom_login_url'] ) && WordPress_Helpers::stripos( $private_page, $login_urls['custom_login_url'] ) !== false ) ) {
						Log_Service::write_log( 'ERROR', __METHOD__ . ' -> The following entry in the Private Pages list is illegal because it is a login url: ' . $private_page );
						continue;
					}

					if ( WordPress_Helpers::stripos( $GLOBALS['WPO_CONFIG']['url_info']['current_url'], $private_page ) === 0 ) {

						/**
						 * @since   17.0
						 *
						 * Authentication may still be skipped when custom rules apply.
						 */

						if ( apply_filters( 'wpo365_skip_authentication', false ) === true ) {
							return true;
						}

						return false;
					}
				}

				Log_Service::write_log(
					'DEBUG',
					sprintf(
						' %s -> Cancelling session validation for page %s because selected scenario is \'Internet\'',
						__METHOD__,
						isset( $_SERVER['PHP_SELF'] ) ? strtolower( basename( wp_unslash( $_SERVER['PHP_SELF'] ) ) ) : '' // phpcs:ignore
					)
				);
				return true;
			}

			/**
			 * @since   10.6
			 *
			 * The wpo365_skip_authentication filter hook signals allows its
			 * subscribers to dynamically add rules that would allow the plugin
			 * to skip authentication.
			 */

			if ( apply_filters( 'wpo365_skip_authentication', false ) === true ) {
				return true;
			}

			return false;
		}

		/**
		 * Instead of showing a 404 for private page the user is requested to sign in.
		 *
		 * @since 12.x
		 */
		public static function check_private_pages() {

			if ( ! Options_Service::get_global_boolean_var( 'redirect_on_private_page', false ) ) {
				return;
			}

			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Check is scenario is 'internet' and validation of current page can be skipped
			$scenario = Options_Service::get_global_string_var( 'auth_scenario' );

			if ( ! is_admin() && ( $scenario === 'internet' || $scenario === 'internetAuthOnly' ) && ! is_user_logged_in() ) {

				$query_result = \get_queried_object();

				if ( isset( $query_result->post_status ) && $query_result->post_status === 'private' ) {
					self::authenticate_request( true );
				}
			}
		}
	}
}
