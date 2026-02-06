<?php

namespace Wpo\Services;

use Error;
use WP_Error;
use Wpo\Core\Domain_Helpers;
use Wpo\Core\Extensions_Helpers;
use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Wp_Config_Service' ) ) {

	class Wp_Config_Service {

		/**
		 * Simple helper to override some options with values found in wp-config.php. See
		 * https://docs.wpo365.com/article/172-use-wp-config-php-to-override-some-config-options.
		 *
		 * @since   27.0 (previously in Options_Service)
		 *
		 * @param   mixed $options
		 *
		 * @return  void
		 */
		public static function apply_overrides( &$options ) {
			if ( ! is_array( $options ) ) {
				return;
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$wpo_overrides   = $request->get_item( 'wpo_overrides' );

			if ( $wpo_overrides === false ) {
				$wpo_overrides = self::get_options_overrides();

				if ( $wpo_overrides === false ) {
					$wpo_overrides = array();
				}

				$request->set_item( 'wpo_overrides', $wpo_overrides );
			}

			if ( empty( $wpo_overrides ) ) {
				return;
			}

			/**
			 * @since 28.x  A sync job's "last" info is still stored in the wp_options table and therefore needs
			 *              to be preserved (see \Wpo\Sync\Sync_Helpers::restore_jobs_last below).
			 */

			if ( ! empty( $options['enable_user_sync'] ) ) {
				$jobs = is_array( $options['user_sync_jobs'] ) ? array_map(
					function ( $job ) {
						return $job;
					},
					$options['user_sync_jobs']
				) : array();
			}

			foreach ( $wpo_overrides as $key => $value ) {
				$options[ $key ] = $value;
			}

			if ( ! empty( $jobs ) && method_exists( '\Wpo\Sync\Sync_Helpers', 'restore_jobs_last' ) ) {
				\Wpo\Sync\Sync_Helpers::restore_jobs_last( $options, $jobs );
			}
		}

		/**
		 * Will set up wpo_aad in request-cache.
		 *
		 * @param array $options
		 *
		 * @return void
		 */
		public static function ensure_aad_options( &$options ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			if ( $request->get_item( 'wpo_aad' ) !== false ) {
				return;
			}

			// Do not continue if the global options have not yet been cached

			if ( empty( $options ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> An attempt to initialize AAD options was made before the global options have been loaded', __METHOD__ ) );
				Authentication_Service::goodbye( Error_Service::CHECK_LOG );
			}

			// 1. Check if multiple IdPs have been configured

			$wpo_idps = self::get_multiple_idps();

			if ( $wpo_idps !== false ) {

				// Closure to filter the default IdP

				$get_default_idp = function () use ( $wpo_idps ) {
					$filtered_idps = array_filter(
						$wpo_idps,
						function ( $idp ) {
							return ! empty( $idp['default'] ) && $idp['default'] === true;
						}
					);

					$filtered_idps = array_values( $filtered_idps ); // re-index from 0

					if ( count( $filtered_idps ) === 1 ) {
						return $filtered_idps[0];
					} else {
						Log_Service::write_log( 'ERROR', sprintf( '%s -> Could not find a default IdP', __METHOD__ ) );
						return array();
					}
				};

				// 1.a Scenario -> mailAuthorize

				if ( $request->get_item( 'mode' ) === 'mailAuthorize' ) {
					$wpo_aad = $get_default_idp();
				} else { // 1.b Scenario -> Start authentication (get by id posted)
					$idp_id = $request->get_item( 'idp_id' );

					if ( ! empty( $idp_id ) ) {
						$filtered_idps = array_filter(
							$wpo_idps,
							function ( $idp ) use ( $idp_id ) {
								return ! empty( $idp['id'] ) && strcasecmp( $idp['id'], $idp_id ) === 0;
							}
						);

						$filtered_idps = array_values( $filtered_idps ); // re-index from 0

						if ( count( $filtered_idps ) === 1 ) {
							$wpo_aad = $filtered_idps[0];
						} else {
							$wpo_aad = array();
							Log_Service::write_log( 'ERROR', sprintf( '%s -> Could not find IdP by IdP ID', __METHOD__ ) );
						}
					}
				}

				// 1.c Disabled since v30.0 [Scenario -> Check user meta for IdP identifier (get by Id)]

				if ( ! isset( $wpo_aad ) ) {
					$wpo_aad = $get_default_idp();
				}

				if ( isset( $wpo_aad['type'] ) ) {
					$options['use_saml'] = $wpo_aad['type'] === 'saml';
					ksort( $options );
				}

				if ( isset( $wpo_aad['tenant_type'] ) ) {
					$options['use_b2c']  = $wpo_aad['tenant_type'] === 'b2c';
					$options['use_ciam'] = $wpo_aad['tenant_type'] === 'ciam';
					ksort( $options );
				}

				if ( isset( $wpo_aad['tld'] ) && strcasecmp( $wpo_aad['tld'], '.us' ) === 0 ) {
					$options['use_gcc'] = true;
					ksort( $options );
				}

				$request->set_item( 'wpo_aad', $wpo_aad );
				return;
			}

			// 2. Check if single IdP has been configured

			if ( ! isset( $wpo_aad ) ) {
				$wpo_aad = self::get_single_idp();

				// -> Backward compatibility for options that are were previously not defined in wp-config.php

				if ( ! empty( $wpo_aad ) ) {
					$aad_option_keys = self::get_aad_option_keys();

					foreach ( $aad_option_keys['strings'] as $key ) {

						if ( ! isset( $wpo_aad[ $key ] ) ) {
							$wpo_aad[ $key ] = self::get_string_option( $key, $options );
						}
					}

					foreach ( $aad_option_keys['bools'] as $key ) {

						if ( ! isset( $wpo_aad[ $key ] ) ) {
							$wpo_aad[ $key ] = self::get_boolean_option( $key, $options );
						}
					}

					if ( isset( $wpo_aad['type'] ) ) {
						$options['use_saml'] = $wpo_aad['type'] === 'saml';
						ksort( $options );
					}

					if ( isset( $wpo_aad['tenant_type'] ) ) {
						$options['use_b2c']  = $wpo_aad['tenant_type'] === 'b2c';
						$options['use_ciam'] = $wpo_aad['tenant_type'] === 'ciam';
						ksort( $options );
					}

					if ( isset( $wpo_aad['tld'] ) && strcasecmp( $wpo_aad['tld'], '.us' ) === 0 ) {
						$options['use_gcc'] = true;
						ksort( $options );
					}

					$request->set_item( 'wpo_aad', $wpo_aad );
					return;
				}
			}

			// 3. From default options

			if ( empty( $wpo_aad ) ) {
				$aad_option_keys = self::get_aad_option_keys();
				$wpo_aad         = array();

				array_map(
					function ( $key ) use ( $options, &$wpo_aad ) {
						$wpo_aad[ $key ] = Wp_Config_Service::get_string_option( $key, $options );
					},
					$aad_option_keys['strings']
				);

				array_map(
					function ( $key ) use ( $options, &$wpo_aad ) {
						$wpo_aad[ $key ] = Wp_Config_Service::get_boolean_option( $key, $options );
					},
					$aad_option_keys['bools']
				);
			}

			$request->set_item( 'wpo_aad', $wpo_aad );
		}

		/**
		 * Will set up wpo_mail in request-cache.
		 *
		 * @param array $options
		 *
		 * @return void
		 */
		public static function ensure_mail_options( $options ) {
			if ( ! self::get_boolean_option( 'use_graph_mailer', $options ) ) {
				return;
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			if ( $request->get_item( 'wpo_mail' ) !== false ) {
				return;
			}

			// Do not continue if the global options have not yet been cached

			if ( empty( $options ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> An attempt to initialize MAIL options was made before the global options have been loaded', __METHOD__ ) );
				Authentication_Service::goodbye( Error_Service::CHECK_LOG );
			}

			// 1. Mail Options from WPO_IDPS_x (default)

			$wpo_idps = self::get_multiple_idps();

			if ( ! empty( $wpo_idps ) ) {
				$filtered_idps = array_filter(
					$wpo_idps,
					function ( $idp ) {
						return ! empty( $idp['default'] ) && $idp['default'] === true;
					}
				);

				$filtered_idps = array_values( $filtered_idps ); // re-index from 0

				if ( count( $filtered_idps ) === 1 ) {
					$mail_option_keys = self::get_mail_option_keys();
					$wpo_mail         = array();

					foreach ( $mail_option_keys as $key ) {
						$wpo_mail[ $key ] = isset( $filtered_idps[0][ $key ] ) ? $filtered_idps[0][ $key ] : self::get_string_option( $key, $options );
					}

					$request->set_item( 'wpo_mail', $wpo_mail );
					return;
				}
			}

			// 2. Options from WPO_AAD_x

			$wpo_aad = self::get_single_idp();

			if ( ! empty( $wpo_aad ) ) {
				$mail_option_keys = self::get_mail_option_keys();
				$wpo_mail         = array();

				foreach ( $mail_option_keys as $key ) {
					$wpo_mail[ $key ] = isset( $wpo_aad[ $key ] ) ? $wpo_aad[ $key ] : self::get_string_option( $key, $options );
				}

				$request->set_item( 'wpo_mail', $wpo_mail );
				return;
			}

			// 3. From default options because WPO_AAD_x is not configured

			$mail_option_keys = self::get_mail_option_keys();
			$wpo_mail         = array();

			array_map(
				function ( $key ) use ( $options, &$wpo_mail ) {
					$wpo_mail[ $key ] = Wp_Config_Service::get_string_option( $key, $options );
				},
				$mail_option_keys
			);

			$request->set_item( 'wpo_mail', $wpo_mail );
		}

		/**
		 * An array of IdPs from WP-Config.php or false if not found.
		 *
		 * @return array|bool
		 */
		public static function get_multiple_idps() {
			if ( empty( Extensions_Helpers::get_active_extensions() ) ) {
				return false;
			}

			$blog_id                        = Wpmu_Helpers::get_options_blog_id();
			$wpo_config_multi_constant_name = 'WPO_IDPS_' . $blog_id;
			return defined( $wpo_config_multi_constant_name ) && is_array( constant( $wpo_config_multi_constant_name ) )
				? constant( $wpo_config_multi_constant_name )
				: false;
		}

		/**
		 * An IdP from WP-Config.php or false if not found.
		 *
		 * @return array|bool
		 */
		public static function get_single_idp() {
			if ( empty( Extensions_Helpers::get_active_extensions() ) ) {
				return false;
			}

			$blog_id                  = Wpmu_Helpers::get_options_blog_id();
			$wpo_config_constant_name = 'WPO_AAD_' . $blog_id;
			return defined( $wpo_config_constant_name ) && is_array( constant( $wpo_config_constant_name ) )
				? constant( $wpo_config_constant_name )
				: false;
		}

		/**
		 * Options overrides from WP-Config.php or false if not found.
		 *
		 * @return array|bool
		 */
		public static function get_options_overrides() {
			if ( empty( Extensions_Helpers::get_active_extensions() ) ) {
				return false;
			}

			$blog_id                  = Wpmu_Helpers::get_options_blog_id();
			$wpo_config_constant_name = 'WPO_OVERRIDES_' . $blog_id;
			return defined( $wpo_config_constant_name ) && is_array( constant( $wpo_config_constant_name ) )
				? constant( $wpo_config_constant_name )
				: false;
		}

		/**
		 * Export the options as a parseable string that can be used in wp-config.php.
		 *
		 * @return string|WP_Error
		 */
		public static function get_parseable_options( $aad_options_only ) {
			if ( isset( $GLOBALS['WPO_CONFIG']['options'] ) ) {
				$aad_option_keys  = self::get_aad_option_keys();
				$mail_option_keys = self::get_mail_option_keys();

				$keys_to_remove = array(
					'configurations',
					'name',
				);

				if ( $aad_options_only ) {
					$parseable_options = array_filter(
						$GLOBALS['WPO_CONFIG']['options'],
						function ( $value, $key ) use ( $aad_option_keys, $mail_option_keys ) {
							return in_array( $key, $aad_option_keys['strings'], true ) || in_array( $key, $aad_option_keys['bools'], true ) || in_array( $key, $mail_option_keys, true );
						},
						ARRAY_FILTER_USE_BOTH
					);

					if ( Options_Service::get_global_boolean_var( 'use_saml' ) ) {
						$parseable_options['type'] = 'saml';
					} else {
						$parseable_options['type'] = 'oidc';
					}

					if ( Options_Service::get_global_boolean_var( 'use_b2c' ) ) {
						$parseable_options['tenant_type'] = 'b2c';
					} elseif ( Options_Service::get_global_boolean_var( 'use_ciam' ) ) {
						$parseable_options['tenant_type'] = 'ciam';
					} else {
						$parseable_options['tenant_type'] = 'workforce';
					}

					// Add some placeholders

					if ( ! empty( $parseable_options['redirect_url'] ) || ! empty( $parseable_options['saml_sp_acs_url'] ) ) {
						$wpo_idps = self::get_multiple_idps();

						if ( ! empty( $wpo_idps ) ) {
							array_map(
								function ( $idp ) use ( &$parseable_options ) {

									if ( ! empty( $idp['tenant_id'] ) && ! empty( $parseable_options['tenant_id'] ) && $idp['tenant_id'] === $parseable_options['tenant_id'] ) {

										if ( ( ! empty( $idp['redirect_url'] ) && ! empty( $parseable_options['redirect_url'] ) && $idp['redirect_url'] === $parseable_options['redirect_url'] )
										|| ( ! empty( $idp['saml_sp_acs_url'] ) && ! empty( $parseable_options['saml_sp_acs_url'] ) && $idp['saml_sp_acs_url'] === $parseable_options['saml_sp_acs_url'] )
										) {

											if ( ! empty( $idp['id'] ) ) {
												$parseable_options['id'] = $idp['id'];
											}

											if ( ! empty( $idp['title'] ) ) {
												$parseable_options['title'] = $idp['title'];
											}

											if ( ! empty( $idp['default'] ) ) {
												$parseable_options['default'] = $idp['default'];
											}
										}
									}
								},
								$wpo_idps
							);
						}
					}

					if ( empty( $parseable_options['id'] ) ) {
						$parseable_options['id'] = uniqid();
					}

					if ( empty( $parseable_options['title'] ) ) {
						$parseable_options['title'] = sprintf( 'Title for IdP %s', $parseable_options['id'] );
					}

					if ( ! isset( $parseable_options['default'] ) ) {
						$parseable_options['default'] = false;
					}
				} else {
					$parseable_options = array_filter(
						$GLOBALS['WPO_CONFIG']['options'],
						function ( $value, $key ) use ( $keys_to_remove, $aad_option_keys, $mail_option_keys ) {
							return ! in_array( $key, $keys_to_remove, true ) && ! in_array( $key, $aad_option_keys['strings'], true ) && ! in_array( $key, $aad_option_keys['bools'], true ) && ! in_array( $key, $mail_option_keys, true );
						},
						ARRAY_FILTER_USE_BOTH
					);

					if ( is_array( $parseable_options['user_sync_jobs'] ) ) {
						$parseable_options_count = count( $parseable_options['user_sync_jobs'] );

						for ( $i = 0; $i < $parseable_options_count; $i++ ) {

							if ( isset( $parseable_options['user_sync_jobs'][ $i ]['last'] ) ) {
								unset( $parseable_options['user_sync_jobs'][ $i ]['last'] );
							}
						}
					}
				}

				ksort( $parseable_options );

				if ( ! empty( $parseable_options ) ) {
					return base64_encode( var_export( $parseable_options, true ) ); // phpcs:ignore
				}
			}

			$error = sprintf( '%s -> Failed to export parseable options', __METHOD__ );
			Log_Service::write_log( 'WARN', $error );
			return new WP_Error( 'VarExportException', $error );
		}

		/**
		 * Helper to return all options that can be retrieved using get_aad_option()
		 *
		 * @since 27.0
		 *
		 * @return array
		 */
		public static function get_aad_option_keys() {
			return array(
				'strings' => array(
					'app_only_application_id',
					'app_only_application_secret',
					'application_id',
					'application_secret',
					'b2c_custom_domain',
					'b2c_domain_name',
					'b2c_policy_name',
					'b2c_signup_policy',
					'oidc_flow',
					'oidc_response_mode',
					'redirect_on_login_secret',
					'redirect_url',
					'saml_base_url',
					'saml_idp_entity_id',
					'saml_idp_meta_data_url',
					'saml_idp_sls_url',
					'saml_idp_ssos_url',
					'saml_sp_acs_url',
					'saml_sp_entity_id',
					'saml_sp_sls_url',
					'saml_x509_cert',
					'tenant_id',
					'tld',
					'wp_rest_aad_application_id_uri',
				),
				'bools'   => array(
					'b2c_allow_multiple_policies',
					'b2c_enable_signup',
					'redirect_url_strict',
					'use_app_only_token',
				),
			);
		}

		/**
		 * Helper to return all options that can be retrieved using get_aad_option()
		 *
		 * @since 27.0
		 *
		 * @return string[]
		 */
		public static function get_mail_option_keys() {
			return array(
				'mail_tenant_id',
				'mail_application_id',
				'mail_application_secret',
				'mail_redirect_url',
				'mail_tld',
			);
		}

		/**
		 * Hooks into the wp_authenticate hook and dynamically redirects the user that
		 * is attempting to sign in, to an IdP that is configured to handle the domain
		 * portion of user_login as entered by the user.
		 *
		 * @param mixed $user_login
		 * @param mixed $user_password
		 * @return void
		 */
		public static function dynamically_redirect_to_idp( $user_login, $user_password ) { // phpcs:ignore
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			// Bail out early if WPO365 is not configured

			if ( ! Options_Service::is_wpo365_configured() ) {
				return;
			}

			// Bail out early if the administrator has not configured multiple IdPs

			$wpo_idps = self::get_multiple_idps();

			if ( empty( $wpo_idps ) ) {
				return;
			}

			$user_login_domain = Domain_Helpers::get_smtp_domain_from_email_address( $user_login );

			// Bail out early if the user didn't enter an email formatted user_login

			if ( empty( $user_login_domain ) ) {
				return;
			}

			$filtered_idps = array_filter(
				$wpo_idps,
				function ( $idp ) use ( $user_login_domain ) {
					return ! empty( $idp['id'] ) && ! empty( $idp['domains'] ) && in_array( $user_login_domain, $idp['domains'], true );
				}
			);

			$filtered_idps = array_values( $filtered_idps ); // re-index from 0

			if ( count( $filtered_idps ) !== 1 ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Attempt to dynamically redirect user with user_login %s to IdP failed because no IdP has been configured for the domain', __METHOD__, $user_login ) );
				return;
			}

			// Re-ensure AAD options with the selected IDP instead

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'idp_id', $filtered_idps[0]['id'] );
			$request->remove_item( 'wpo_aad' );
			self::ensure_aad_options( $GLOBALS['WPO_CONFIG']['options'] );

			if ( Options_Service::get_global_boolean_var( 'use_saml' ) ) {
				$params = array( 'login_hint' => $user_login );
				\Wpo\Services\Saml2_Service::initiate_request( $params );
				exit();
			} elseif ( Options_Service::get_global_boolean_var( 'use_b2c' ) && \class_exists( '\Wpo\Services\Id_Token_Service_B2c' ) ) {
					$auth_url = \Wpo\Services\Id_Token_Service_B2c::get_openidconnect_url( $user_login );
			} elseif ( Options_Service::get_global_boolean_var( 'use_ciam' ) ) {
				$auth_url = \Wpo\Services\Id_Token_Service_Ciam::get_openidconnect_url( $user_login );
			} else {
				$auth_url = Id_Token_Service::get_openidconnect_url( $user_login );
			}

			Url_Helpers::force_redirect( $auth_url );
		}

		/**
		 * Get a global string option from the global options cache.
		 *
		 * @param string $option_name
		 * @return string
		 */
		private static function get_string_option( $option_name, $options ) {
			if ( isset( $options[ $option_name ] ) ) {
				return is_string( $options[ $option_name ] ) ? WordPress_Helpers::trim( $options[ $option_name ] ) : '';
			}

			return '';
		}

		/**
		 * Get a global boolean option from the global options cache.
		 *
		 * @param string $option_name
		 * @return boolean
		 */
		private static function get_boolean_option( $option_name, $options ) {
			if ( isset( $options[ $option_name ] ) ) {
				return filter_var( $options[ $option_name ], FILTER_VALIDATE_BOOLEAN );
			}

			return false;
		}
	}
}
