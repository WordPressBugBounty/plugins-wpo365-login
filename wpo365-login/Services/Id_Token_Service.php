<?php

namespace Wpo\Services;

use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Error_Service;
use Wpo\Services\Jwt_Token_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Nonce_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Id_Token_Service' ) ) {

	class Id_Token_Service {


		/**
		 * Constructs the oauth authorize URL that is the end point where the user will be sent for authorization.
		 *
		 * @since 4.0
		 *
		 * @since 11.0 Dropped support for the v1 endpoint.
		 *
		 * @param string $login_hint Login hint that will be added to Open Connect ID link.
		 *
		 * @return string if everthing is configured OK a valid authorization URL
		 */
		public static function get_openidconnect_url( $login_hint = null ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			// do not continue if the user didn't select an IdP and multiple IdPs have been configured
			if ( empty( $request->get_item( 'wpo_aad' ) ) ) {

				if ( is_array( Wp_Config_Service::get_multiple_idps() ) ) {
					Log_Service::write_log(
						'ERROR',
						sprintf(
							'%s ->  Multiple IdPs have been configured and the user has not selected one and therefore he / she is redirected to the login page instead',
							__METHOD__
						)
					);
					Authentication_Service::goodbye( 'NO_IDP_SELECTED' );
				}

				Log_Service::write_log(
					'ERROR',
					sprintf(
						'%s ->  Cannot continue sending the user to Microsoft to authenticate [Error: Entra ID / AAD options not cached]',
						__METHOD__
					)
				);
				Authentication_Service::goodbye( 'CHECK_LOG' );
			}

			$application_id = Options_Service::get_aad_option( 'application_id' );
			$directory_id   = Options_Service::get_aad_option( 'tenant_id' );
			$oidc_flow      = Options_Service::get_aad_option( 'oidc_flow' );
			$multi_tenanted = Options_Service::get_global_boolean_var( 'multi_tenanted' ) && ! Options_Service::get_global_boolean_var( 'use_b2c' );

			/**
			 * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
			 */

			$redirect_uri = Options_Service::get_aad_option( 'redirect_url' );
			$redirect_uri = apply_filters( 'wpo365/aad/redirect_uri', $redirect_uri );
			$query_args   = array();

			if ( ! empty( $_REQUEST['wpo_embedded'] ) ) { // phpcs:ignore
				$query_args['mode'] = sanitize_key( $_REQUEST['wpo_embedded'] ) === 'teams' ? 'wpoEmbeddedTeams' : 'wpoEmbeddedIframe'; // phpcs:ignore 
			}

			$state_url = Url_Helpers::get_state_url( '', $query_args );

			/**
			 * @since   21.9    Premium extensions of the WPO365 plugin require User.Read to update core WP user fields.
			 */

			if ( class_exists( '\Wpo\Services\User_Create_Update_Service' ) ) {
				$tld   = Options_Service::get_aad_option( 'tld' );
				$tld   = ! empty( $tld ) ? $tld : '.com';
				$scope = "https://graph.microsoft$tld/user.read openid email profile";
			} else {
				$scope = 'openid email profile';
			}

			$response_mode = Options_Service::get_aad_option( 'oidc_response_mode' );

			if ( empty( $response_mode ) || $oidc_flow !== 'code' ) {
				$response_mode = 'form_post';
			}

			$params = array(
				'client_id'     => $application_id,
				'redirect_uri'  => $redirect_uri,
				'response_mode' => $response_mode,
				'scope'         => $scope,
				'state'         => $state_url,
				'nonce'         => Nonce_Service::create_nonce(),
			);

			$params['response_type'] = $oidc_flow === 'code' ? 'code' : 'id_token code';

			// Add Proof Key for Code Exchange challenge if required
			if ( Options_Service::get_global_boolean_var( 'use_pkce' ) && class_exists( '\Wpo\Services\Pkce_Service' ) ) {
				\Wpo\Services\Pkce_Service::add_and_memoize_verifier( $params );
			}

			/**
			 * @since 9.4
			 *
			 * Add ability to configure a domain hint to prevent Microsoft from
			 * signing in users that are already logged in to a different O365 tenant.
			 */
			$domain_hint = Options_Service::get_global_string_var( 'domain_hint' );

			if ( ! empty( $domain_hint ) ) {
				$params['domain_hint'] = $domain_hint;
			}

			if ( empty( $login_hint ) && ! empty( $_REQUEST['login_hint'] ) ) { // phpcs:ignore
				$login_hint = sanitize_email( wp_unslash( $_REQUEST['login_hint'] ) ); // phpcs:ignore
			}

			if ( ! empty( $login_hint ) ) {
				$params['login_hint'] = $login_hint;
			}

			if ( Options_Service::get_global_boolean_var( 'add_select_account_prompt' ) === true ) {
				$params['prompt'] = 'select_account';
			} elseif ( Options_Service::get_global_boolean_var( 'add_create_account_prompt' ) === true ) {
				$params['prompt'] = 'create';
			}

			if ( $multi_tenanted === true ) {
				$directory_id                   = 'common';
				$multi_tenanted_api_permissions = Options_Service::get_global_list_var( 'multi_tenanted_api_permissions' );

				foreach ( $multi_tenanted_api_permissions as $key => $permission ) {

					$permission = strtolower( trim( $permission ) );

					if ( ! empty( $permission ) ) {
						$params['scope'] = $params['scope'] . " $permission";
					}
				}
			}

			/**
			 * @since 34.x  Filters the authorization params.
			 */
			$params   = apply_filters( 'wpo365/oidc/params', $params );
			$tld      = Options_Service::get_aad_option( 'tld' );
			$tld      = ! empty( $tld ) ? $tld : '.com';
			$auth_url = sprintf(
				'https://login.microsoftonline%s/%s/oauth2/v2.0/authorize?%s',
				$tld,
				$directory_id,
				http_build_query( $params, '', '&' )
			);

			Log_Service::write_log( 'DEBUG', __METHOD__ . " -> Open ID Connect URL: $auth_url" );

			return $auth_url;
		}

		/**
		 * Processes the ID token and caches it for the current request. If an authorization code is sent
		 * along, it will be saved so the it can be used when requesting access tokens for the current
		 * user (if integration is configured).
		 *
		 * @return  void
		 */
		public static function process_openidconnect_token( $force_goodbye = true ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Decode the id_token
			$id_token = self::decode_id_token();

			// Handle if token could not be processed
			if ( $id_token === false ) {

				if ( $force_goodbye === true ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> ID token could not be processed and user will be redirected to default WordPress login.' );
					Authentication_Service::goodbye( Error_Service::ID_TOKEN_ERROR );
				}

				return;
			}

			// Until 45.0 only the Hybrid Flow's pre-check (check_audience) verified issuer and audience.
			$validation = self::validate_issuer_and_audience( $id_token );

			if ( $validation !== true ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> %s', __METHOD__, $validation ) );

				if ( $force_goodbye === true ) {
					Authentication_Service::goodbye( Error_Service::ID_TOKEN_AUD );
				}

				return;
			}

			// Verify the nonce; verify_nonce() calls Authentication_Service::goodbye() and exits on failure.
			Nonce_Service::verify_nonce( $id_token->nonce );

			// Log id token if configured
			if ( Options_Service::get_global_boolean_var( 'debug_log_id_token' ) === true ) {
				Log_Service::write_log( 'DEBUG', $id_token );
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'id_token', $id_token );

			if ( property_exists( $id_token, 'tfp' ) ) {
				$request->set_item( 'tfp', $id_token->tfp );
			} elseif ( property_exists( $id_token, 'acr' ) ) {
				$request->set_item( 'tfp', $id_token->acr );
			}
		}

		/**
		 * Helper to process the authorization code which is then used to request an ID and access token.
		 *
		 * @since   18.0
		 *
		 * @return void
		 */
		public static function process_openidconnect_code( $scope = '', $force_goodbye = true ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$code            = Access_Token_Service::get_authorization_code();
			$mode            = $request->get_item( 'mode' );
			$use_mail_config = $mode === 'mailAuthorize';

			if ( empty( $code ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Authorization code not found', __METHOD__ ) );
				return;
			}

			$multi_tenanted      = Options_Service::get_global_boolean_var( 'multi_tenanted' ) && ! $use_mail_config && ! Options_Service::get_global_boolean_var( 'use_b2c' );
			$mail_multi_tenanted = Options_Service::get_global_boolean_var( 'mail_multi_tenanted' );
			$tld                 = $use_mail_config ? Options_Service::get_mail_option( 'mail_tld' ) : Options_Service::get_aad_option( 'tld' );
			$tld                 = ! empty( $tld ) ? $tld : '.com';
			$scope               = str_replace( '.com', $tld, $scope );
			$scope               = $mode === 'mailAuthorize' && $mail_multi_tenanted
				? sprintf( 'offline_access%s', empty( $scope ) ? '' : ' ' . $scope )
				: sprintf( 'openid email profile offline_access%s', empty( $scope ) ? '' : ' ' . $scope );
			$directory_id        = $use_mail_config ? Options_Service::get_mail_option( 'mail_tenant_id' ) : Options_Service::get_aad_option( 'tenant_id' );
			$directory_id        = $multi_tenanted || ( $mode === 'mailAuthorize' && $mail_multi_tenanted ) ? 'common' : $directory_id;
			$application_id      = $use_mail_config ? Options_Service::get_mail_option( 'mail_application_id' ) : Options_Service::get_aad_option( 'application_id' );
			$application_secret  = $use_mail_config ? Options_Service::get_mail_option( 'mail_application_secret' ) : Options_Service::get_aad_option( 'application_secret' );

			/**
			 * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
			 */

			if ( $use_mail_config ) {
				$redirect_uri = Options_Service::get_mail_option( 'mail_redirect_url' );
			} else {
				$redirect_uri = Options_Service::get_aad_option( 'redirect_url' );
				$redirect_uri = apply_filters( 'wpo365/aad/redirect_uri', $redirect_uri );
			}

			$params = array(
				'client_id'     => $application_id,
				'response_type' => 'token',
				'redirect_uri'  => $redirect_uri,
				'response_mode' => 'form_post',
				'grant_type'    => 'authorization_code',
				'scope'         => $scope,
				'code'          => $code,
				'client_secret' => $application_secret,
			);

			if ( Options_Service::get_global_boolean_var( 'use_pkce' ) && class_exists( '\Wpo\Services\Pkce_Service' ) ) {
				$pkce_code_verifier = \Wpo\Services\Pkce_Service::get_personal_pkce_code_verifier();

				if ( ! empty( $pkce_code_verifier ) ) {
					$params['code_verifier'] = $pkce_code_verifier;
				} else {
					$warning = 'Cannot retrieve an (ID) token because the Administrator 
                        has configured the use of a Proof Key for Code Exchange but a code verifier for the current
                        user cannot be found. See the <a href="https://docs.wpo365.com/article/149-require-proof-key-for-code-exchange-pkce" target="_blank">online documentation</a> 
                        for detailed step-by-step instructions on how to configure the WPO365 | LOGIN plugin to use a Proof Key for Code Exchange.';
					Log_Service::write_log( 'ERROR', __METHOD__ . " -> $warning" );

					$access_token_errors   = $request->get_item( 'access_token_errors' );
					$access_token_errors   = ! empty( $access_token_errors ) ? $access_token_errors : array();
					$access_token_errors[] = $warning;
					$request->set_item( 'access_token_errors', $access_token_errors );

					return;
				}
			}

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );
			$token_url       = sprintf(
				'https://login.microsoftonline%s/%s/oauth2/v2.0/token',
				$tld,
				$directory_id
			);

			/**
			 * @since 33.x  Filters the params e.g. to support SNI based authentication.
			 */
			$params = apply_filters( 'wpo365/aad/params', $params, $application_id, $token_url );

			$response = wp_remote_post(
				$token_url,
				array(
					'sslverify' => $skip_ssl_verify,
					'body'      => $params,
					'headers'   => array( 'Expect' => '' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Error occured whilst fetching from %s: %s', __METHOD__, $token_url, $response->get_error_message() ) );
				return;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Error occured whilst fetching from %s: See next line for details.', __METHOD__, $token_url ) );
				Log_Service::write_log( 'ERROR', $response );
				return;
			}

			if ( property_exists( $body, 'error' ) ) {
				$message = property_exists( $body, 'error_description' ) ? $body->error_description : $body->error;
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Error occured whilst fetching from %s: %s', __METHOD__, $token_url, $message ) );
				return;
			}

			if ( property_exists( $body, 'access_token' ) ) {
				$access_token               = new \stdClass();
				$access_token->access_token = $body->access_token;

				if ( property_exists( $body, 'expires_in' ) ) {
					$access_token->expiry = time() + intval( $body->expires_in );
				}

				if ( property_exists( $body, 'scope' ) ) {
					$access_token->scope = $body->scope;
				}

				$access_tokens = $request->get_item( 'access_tokens' );

				if ( empty( $access_tokens ) ) {
					$access_tokens = array();
				}

				// Save access token as request variable -> will be saved on shutdown
				$access_tokens[] = $access_token;
				$request->set_item( 'access_tokens', $access_tokens );
			}

			if ( property_exists( $body, 'refresh_token' ) ) {
				$refresh_token                = new \stdClass();
				$refresh_token->refresh_token = $body->refresh_token;

				if ( property_exists( $body, 'scope' ) ) {
					$refresh_token->scope = $body->scope;
				}

				$request->set_item( 'refresh_token', $refresh_token );
			}

			if ( $mode === 'mailAuthorize' && Options_Service::get_global_boolean_var( 'mail_skip_all_checks' ) ) {
				return;
			}

			if ( property_exists( $body, 'id_token' ) ) {
				$request->set_item( 'encoded_id_token', $body->id_token );
				self::process_openidconnect_token( $force_goodbye );
				return;
			}

			Log_Service::write_log( 'ERROR', sprintf( '%s -> ID token not found in data retrieved from token endpoint [see next line for response body]', __METHOD__ ) );
			Log_Service::write_log( 'DEBUG', $body );
		}

		/**
		 * Unraffles the incoming JWT id_token with the help of Jwt_Token_Service (phpseclib) and the tenant specific public keys available from Microsoft.
		 *
		 * @since   1.0
		 *
		 * @return  object|boolean
		 */
		public static function decode_id_token() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$id_token        = $request->get_item( 'encoded_id_token' );

			// Get the token and get it's header for a first analysis
			if ( empty( $id_token ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> ID token not found in posted data.' );
				return false;
			}

			$claims = Jwt_Token_Service::validate_signature( $id_token );

			if ( is_wp_error( $claims ) ) {
				Log_Service::write_log( 'ERROR', $claims->get_error_message() );
				return false;
			}

			return $claims;
		}

		/**
		 * Pre-checks a posted ID token - before its signature has been verified - for the right issuer and audience,
		 * so that an ID token that is meant for another application (e.g. another plugin) is recognized early.
		 *
		 * @since   21.x    Initially as part of decode_id_token
		 * @since   23.0    Moved into its own function
		 * @since   45.0    Uses the same checks as process_openidconnect_token (validate_issuer_and_audience). The
		 *                  option "Skip ID token verification" has been removed.
		 *
		 * @param   mixed $id_token
		 *
		 * @return  bool
		 */
		public static function check_audience( $id_token ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			/**
			 * @since 23.0  Either let request bypass WPO365 logic (= exit) if the audience check
			 *              fails or send user with error to login / error page.
			 */
			$on_error = function () use ( $request ) {

				if ( ! Options_Service::get_global_boolean_var( 'exit_on_audience_error' ) ) {
					Authentication_Service::goodbye( Error_Service::ID_TOKEN_AUD );
				}

				$request->set_item( 'skip_authentication', true );
				return false;
			};

			$token_arr = explode( '.', $id_token );

			// Token should explored in three segments header, body, signature
			if ( count( $token_arr ) !== 3 ) {
				return $on_error();
			}

			// Payload (claims)
			$claims = \json_decode( WordPress_Helpers::base64_url_decode( $token_arr[1] ) );

			if ( ! is_object( $claims ) ) {
				return $on_error();
			}

			$validation = self::validate_issuer_and_audience( $claims );

			if ( $validation !== true ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> %s', __METHOD__, $validation ) );
				return $on_error();
			}

			return true;
		}

		/**
		 * Whether an ID token was issued for this website's application by the identity provider in use - and, for a
		 * website that allows users from other tenants, by its own or an allowed tenant. The issuer is compared with the
		 * one that the identity provider publishes in its Open ID configuration, so that a tenant ID entered as a domain
		 * name and a B2C or CIAM custom domain work as well.
		 *
		 * @since   45.0
		 *
		 * @param   object $claims
		 *
		 * @return  true|string True, or a message that explains why the ID token is refused.
		 */
		private static function validate_issuer_and_audience( $claims ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$use_mail_config = $request->get_item( 'mode' ) === 'mailAuthorize';
			$use_b2c         = ! $use_mail_config && Options_Service::get_global_boolean_var( 'use_b2c' );
			$use_ciam        = ! $use_mail_config && Options_Service::get_global_boolean_var( 'use_ciam' );
			$multi_tenanted  = ! $use_mail_config && ! $use_b2c && ! $use_ciam && Options_Service::get_global_boolean_var( 'multi_tenanted' );
			$application_id  = $use_mail_config ? Options_Service::get_mail_option( 'mail_application_id' ) : Options_Service::get_aad_option( 'application_id' );

			/**
			 * Escaped because the messages repeat claims - possibly of a token that has not been verified yet - and
			 * are rendered for an administrator as a WPO365 health message.
			 */
			$user_name = ! empty( $claims->unique_name ) && is_string( $claims->unique_name )
				? esc_html( $claims->unique_name )
				: ( ! empty( $claims->preferred_username ) && is_string( $claims->preferred_username )
					? esc_html( $claims->preferred_username )
					: '???'
				);

			$audiences = isset( $claims->aud ) ? array_map( 'strtolower', array_filter( (array) $claims->aud, 'is_string' ) ) : array();

			if ( empty( $application_id ) || ! in_array( strtolower( $application_id ), $audiences, true ) ) {
				return sprintf(
					'The ID token that has been received for [%s] is intended for another audience [%s] (vs. your registered application [%s]).',
					$user_name,
					esc_html( implode( ', ', $audiences ) ),
					esc_html( $application_id )
				);
			}

			// A B2C token names the user flow (policy) that issued it, and its issuer may depend on that user flow.
			$b2c_policy = null;

			if ( $use_b2c ) {
				$token_policy = ! empty( $claims->tfp ) ? $claims->tfp : ( ! empty( $claims->acr ) ? $claims->acr : '' );

				foreach ( array( Options_Service::get_aad_option( 'b2c_policy_name' ), Options_Service::get_aad_option( 'b2c_signup_policy' ) ) as $configured_policy ) {

					if ( ! empty( $configured_policy ) && is_string( $token_policy ) && strcasecmp( $token_policy, $configured_policy ) === 0 ) {
						$b2c_policy = $configured_policy;
					}
				}
			}

			$open_id_config = Jwt_Token_Service::get_openid_configuration( $b2c_policy, $multi_tenanted );

			if ( empty( $open_id_config->issuer ) || ! is_string( $open_id_config->issuer ) ) {
				return sprintf(
					'The issuer of the ID token that has been received for [%s] cannot be verified, because the Open ID configuration of the identity provider could not be retrieved. Please check the log for details.',
					$user_name
				);
			}

			$expected_issuer = $open_id_config->issuer;

			if ( $multi_tenanted ) {
				$tenant_id = ! empty( $claims->tid ) && is_string( $claims->tid ) ? strtolower( $claims->tid ) : '';

				// Entra ID's multi-tenant configuration publishes the issuer as a template: https://login.microsoftonline.com/{tenantid}/v2.0.
				$expected_issuer = str_ireplace( '{tenantid}', $tenant_id, $expected_issuer );
				$allowed_tenants = array();

				foreach ( Options_Service::get_global_list_var( 'allowed_tenants' ) as $allowed_tenant ) {

					if ( is_string( $allowed_tenant ) && WordPress_Helpers::trim( $allowed_tenant ) !== '' ) {
						$allowed_tenants[] = strtolower( WordPress_Helpers::trim( $allowed_tenant ) );
					}
				}

				// An empty list allows users from any tenant.
				if ( ! empty( $allowed_tenants ) ) {
					$allowed_tenants[] = self::get_own_tenant_guid();

					if ( $tenant_id === '' || ! in_array( $tenant_id, $allowed_tenants, true ) ) {
						return sprintf(
							'The ID token that has been received for [%s] has been issued by a tenant [%s] that has not been allow-listed on the plugin\'s "User Registration" configuration page.',
							$user_name,
							esc_html( $tenant_id )
						);
					}
				}
			}

			$issuer = ! empty( $claims->iss ) && is_string( $claims->iss ) ? $claims->iss : '';

			if ( strcasecmp( $issuer, $expected_issuer ) !== 0 ) {
				return sprintf(
					'The ID token that has been received for [%s] has been issued by [%s] (vs. the expected issuer [%s]).',
					$user_name,
					esc_html( $issuer ),
					esc_html( $expected_issuer )
				);
			}

			return true;
		}

		/**
		 * The website's own tenant as a GUID - also when its tenant ID has been entered as a domain name, in which case
		 * the GUID is taken from the issuer in the tenant's Open ID configuration.
		 *
		 * @since   45.0
		 *
		 * @return  string  The GUID in lower case, or an empty string.
		 */
		private static function get_own_tenant_guid() {
			$guid_pattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
			$tenant_id    = strtolower( WordPress_Helpers::trim( Options_Service::get_aad_option( 'tenant_id' ) ) );

			if ( preg_match( '/^' . $guid_pattern . '$/', $tenant_id ) === 1 ) {
				return $tenant_id;
			}

			$open_id_config = Jwt_Token_Service::get_openid_configuration();

			if ( ! empty( $open_id_config->issuer ) && is_string( $open_id_config->issuer ) && preg_match( '/' . $guid_pattern . '/i', $open_id_config->issuer, $matches ) === 1 ) {
				return strtolower( $matches[0] );
			}

			return '';
		}
	}
}
