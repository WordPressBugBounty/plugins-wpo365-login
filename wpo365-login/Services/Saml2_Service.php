<?php

namespace Wpo\Services;

use WP_Error;
use Wpo\Core\Url_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Error_Service;
use Wpo\Services\Log_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Saml2_Service' ) ) {

	class Saml2_Service {


		/**
		 * Iniates a SAML 2.0 request and redirects the user to the IdP.
		 *
		 * @since 11.0
		 *
		 * @return void
		 */
		public static function initiate_request( $params = array() ) {
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

			if ( ! empty( $_REQUEST['domain_hint'] ) ) { // phpcs:ignore
				$params['whr'] = sanitize_text_field( \strtolower( \trim( sanitize_text_field( wp_unslash( $_REQUEST['domain_hint'] ) ) ) ) ); // phpcs:ignore
			}

			$redirect_to = ! empty( $_REQUEST['redirect_to'] ) // phpcs:ignore
				? wp_sanitize_redirect( $_REQUEST['redirect_to'] ) // phpcs:ignore
				: null;

			require_once $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/OneLogin/_toolkit_loader.php';

			$state_url     = Url_Helpers::get_state_url( $redirect_to );
			$force_authn   = Options_Service::get_global_boolean_var( 'saml_force_authn' );
			$saml_settings = self::saml_settings();
			$auth          = new \OneLogin_Saml2_Auth( $saml_settings );
			$auth->login( $state_url, $params, $force_authn );
		}

		/**
		 * Gets an attribute / claim from the SAML 2.0 response.
		 *
		 * @since   11.0
		 *
		 * @param   string $claim  WPO365 User field name (looked up in the claim mappings setting).
		 * @param   array  $saml_attributes   Attributes received as part of the SAML response.
		 * @param   bool   $to_lower  True if the attribute value returned should be converted to lower case.
		 * @param   string $type  Enumeration: string, array.
		 *
		 * @param bool   $return_null
		 *
		 * @return  string  Attribute's value as string or null if the claim was not found
		 */
		public static function get_attribute( $claim, $saml_attributes, $to_lower = false, $type = 'string', $return_null = false ) {

			$claim_mappings = array(
				'preferred_username' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
				'email'              => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
				'first_name'         => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
				'last_name'          => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
				'full_name'          => 'http://schemas.microsoft.com/identity/claims/displayname',
				'tid'                => 'http://schemas.microsoft.com/identity/claims/tenantid',
				'objectidentifier'   => 'http://schemas.microsoft.com/identity/claims/objectidentifier',
			);

			$claim_value = '';

			if ( isset( $claim_mappings[ $claim ] ) && isset( $saml_attributes[ $claim_mappings[ $claim ] ] ) ) {
				$claim_value = $saml_attributes[ $claim_mappings[ $claim ] ];
			} elseif ( isset( $saml_attributes[ $claim ] ) ) {
				$claim_value = $saml_attributes[ $claim ];
			} elseif ( $return_null ) {
				return null;
			}

			if ( $type === 'string' ) {

				if ( is_array( $claim_value ) && count( $claim_value ) > 0 ) {
					$claim_value = $claim_value[0];
				}

				if ( is_string( $claim_value ) ) {
					return $to_lower ? \strtolower( $claim_value ) : $claim_value;
				}
			} elseif ( $type === 'array' ) {

				if ( is_array( $claim_value ) ) {
					return $claim_value;
				}
			}

			if ( $type === 'string' ) {
				return '';
			}

			if ( $type === 'array' ) {
				return array();
			}

			return null;
		}

		/**
		 * Creates a OneLogin settings object with the settings configured through the WPO365 wizard.
		 *
		 * @since   11.0
		 *
		 * @return  mixed(array|boolean)   Array with OneLogin (non-advanced) settings or true / false when validating.
		 */
		public static function saml_settings( $validate = false ) {
			$base_url      = Options_Service::get_aad_option( 'saml_base_url' );
			$sp_entity_id  = Options_Service::get_aad_option( 'saml_sp_entity_id' );
			$sp_sls_url    = Options_Service::get_aad_option( 'saml_sp_sls_url' );
			$idp_entity_id = Options_Service::get_aad_option( 'saml_idp_entity_id' );
			$idp_ssos_url  = Options_Service::get_aad_option( 'saml_idp_ssos_url' );
			$idp_sls_url   = Options_Service::get_aad_option( 'saml_idp_sls_url' );
			$x509cert      = Options_Service::get_aad_option( 'saml_x509_cert' );
			$sp_acs_url    = Options_Service::get_aad_option( 'saml_sp_acs_url' );

			/**
			 * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
			 */
			$sp_acs_url = apply_filters( 'wpo365/aad/redirect_uri', $sp_acs_url );

			$log_level  = $validate ? 'WARN' : 'ERROR';
			$has_errors = false;

			$exit_on_error = function () use ( $validate ) {

				if ( ! $validate ) {
					Authentication_Service::goodbye( Error_Service::SAML2_ERROR );
				}
			};

			if ( empty( $base_url ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Base URL cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $sp_entity_id ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Service Provider Entity ID cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $sp_acs_url ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Service Provider Assertion Consumer Service URL cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $sp_sls_url ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Service Provider Single Logout Service URL cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $idp_entity_id ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Identity Provider Entity ID cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $idp_ssos_url ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Identity Provider Single Sign-on Service URL cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $idp_sls_url ) ) {
				Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (Identity Provider Single Logout Service URL cannot be empty)' );
				$exit_on_error();
				$has_errors = true;
			}

			if ( empty( $x509cert ) ) {

				if ( ! empty( Options_Service::get_aad_option( 'saml_idp_meta_data_url' ) ) ) {
					$x509cert = 'X509 CERTIFICATE PLACEHOLDER';
				} else {
					Log_Service::write_log( $log_level, __METHOD__ . ' -> SAML 2.0 error (X509 Certificate cannot be empty)' );
					$exit_on_error();
					$has_errors = true;
				}
			}

			if ( $validate === true ) {
				return ! $has_errors;
			}

			$settings = array(
				'strict'  => true,
				'debug'   => false,
				'baseurl' => $base_url,
				'sp'      => array(
					'entityId'                 => $sp_entity_id,
					'assertionConsumerService' => array(
						'url'     => $sp_acs_url,
						'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
					),
					'singleLogoutService'      => array(
						'url'     => $sp_sls_url,
						'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					),
					'NameIDFormat'             => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
					'x509cert'                 => '',
					'privateKey'               => '',
				),
				'idp'     => array(
					'entityId'            => $idp_entity_id,
					'singleSignOnService' => array(
						'url'     => $idp_ssos_url,
						'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					),
					'singleLogoutService' => array(
						'url'         => $idp_sls_url,
						'responseUrl' => '',
						'binding'     => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					),
					'x509cert'            => $x509cert,
				),
			);

			/**
			 * @since 25.0 By default we disable the requested authentication context.
			 */
			if ( ! Options_Service::get_global_boolean_var( 'saml_enable_requested_authn_context' ) ) {
				$settings['security'] = array(
					'requestedAuthnContext' => false,
				);
			}

			/**
			 * @since 11.14
			 *
			 * Example:
			 *
			 * define( 'WPO_SAML2_ADVANCED_SETTINGS',
			 *  array(
			 *    'security' => array(
			 *      'requestedAuthnContext' => array (
			 *        'urn:federation:authentication:windows'
			 *      )
			 *    )
			 *  )
			 * );
			 */

			if ( defined( 'WPO_SAML2_ADVANCED_SETTINGS' ) && is_array( constant( 'WPO_SAML2_ADVANCED_SETTINGS' ) ) ) {
				return array_merge( $settings, constant( 'WPO_SAML2_ADVANCED_SETTINGS' ) );
			}

			return $settings;
		}

		public static function check_message_id( $message_id ) {
			$cache = Wpmu_Helpers::mu_get_transient( 'wpo365_saml_message_ids' );

			if ( empty( $cache ) || ! \is_array( $cache ) ) {
				$cache = array(
					'last_write_index' => 0,
					'slots'            => array(
						0 => array( $message_id ),
						1 => array(),
						2 => array(),
						3 => array(),
						4 => array(),
						5 => array(),
					),
				);
				return;
			}

			$minutes    = intval( gmdate( 'i' ) );
			$mod        = $minutes % 10;
			$write_slot = $minutes - $mod / 10;

			foreach ( $cache['slots'] as $slot ) {
				$index = array_search( $message_id, $slot, true );

				if ( $index !== false ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> SAML 2.0 error (replay attack detected: SAML message ID already used)' );
					Authentication_Service::goodbye( Error_Service::TAMPERED_WITH );
				}
			}

			$cache['slots'][ $write_slot ][]               = $message_id;
			$cache['slots'][ ( ( $write_slot + 1 ) % 6 ) ] = array();

			Wpmu_Helpers::mu_set_transient( 'wpo365_saml_message_ids', $cache );
		}

		/**
		 * Attempts to read the IdP metadata from the App Federation Metadata URL entered by the administrator and
		 * configure WPO365 accordingly.
		 *
		 * @since 25.0
		 *
		 * @return bool|WP_Error Returns true if the import was successful otherwise an error.
		 */
		public static function import_idp_meta() {
			$saml_idp_meta_data_url = Options_Service::get_aad_option( 'saml_idp_meta_data_url' );

			if ( empty( $saml_idp_meta_data_url ) ) {
				return new WP_Error( 'ConfigurationException', sprintf( '%s -> The App Federation Metadata URL is not configured', __METHOD__ ) );
			}

			$response = wp_remote_get(
				$saml_idp_meta_data_url,
				array(
					'method'    => 'GET',
					'headers'   => array(
						'Expect' => '',
					),
					'sslverify' => ! Options_Service::get_global_boolean_var( 'skip_host_verification' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$idp_meta = wp_remote_retrieve_body( $response );

			if ( empty( $idp_meta ) ) {
				return new WP_Error( 'BodyParseError', sprintf( '%s -> No body was detected in the response retrieved from %s', __METHOD__, $saml_idp_meta_data_url ) );
			}

			$idp_meta_xml = simplexml_load_string( $idp_meta );

			if ( $idp_meta_xml === false ) {
				return new WP_Error( 'XmlParseError', sprintf( '%s -> Body retrieved from %s cannot be loaded as an XML document', __METHOD__, $saml_idp_meta_data_url ) );
			}

			$imported_idp_values = array(
				'saml_idp_entity_id' => $idp_meta_xml->attributes()->entityID->__toString(),
				'saml_x509_cert'     => $idp_meta_xml->IDPSSODescriptor->KeyDescriptor->KeyInfo->X509Data->X509Certificate->__toString(), // phpcs:ignore
				'saml_idp_ssos_url'  => $idp_meta_xml->IDPSSODescriptor->SingleSignOnService->attributes()->Location->__toString(), // phpcs:ignore
				'saml_idp_sls_url'   => $idp_meta_xml->IDPSSODescriptor->SingleLogoutService->attributes()->Location->__toString(), // phpcs:ignore
			);

			foreach ( $imported_idp_values as $option_key => $value ) {

				if ( ! empty( $value ) ) {

					if ( strcasecmp( $option_key, 'saml_x509_cert' ) === 0 ) {
						$value = self::format_x509_certificate( $value );
					}

					if ( strcasecmp( $option_key, 'saml_idp_entity_id' ) === 0 ) {
						$tenant_id = self::get_tenant_id_from_entity_id( $value );

						if ( ! empty( $tenant_id ) ) {
							Options_Service::add_update_option( 'tenant_id', $tenant_id );
							Log_Service::write_log( 'DEBUG', sprintf( '%s -> Derived tenant ID with value "%s" from SAML IdP Entity ID "%s"', __METHOD__, $tenant_id, $value ) );
						}
					}

					Options_Service::add_update_option( $option_key, $value );
					Log_Service::write_log( 'DEBUG', sprintf( '%s -> Imported SAML option "%s" with value "%s" from %s', __METHOD__, $option_key, $value, $saml_idp_meta_data_url ) );
				} else {
					Log_Service::write_log( 'ERROR', sprintf( '%s -> Failed to import SAML option "%s" with value "%s" from %s', __METHOD__, $option_key, $value, $saml_idp_meta_data_url ) );
				}
			}

			return true;
		}


		/**
		 *
		 * @return WP_Error|string
		 */
		public static function export_sp_meta() {
			$base_url = Options_Service::get_aad_option( 'saml_base_url' );

			if ( empty( $base_url ) ) {
				$base_url = get_option( 'home' );

				if ( empty( $base_url ) ) {
					return new WP_Error( 'ConfigurationException', sprintf( '%s -> The SAML 2.0 Base URL is not configured', __METHOD__ ) );
				}

				Options_Service::add_update_option( 'saml_base_url', $base_url );
			}

			$base_url     = trailingslashit( $base_url );
			$sp_entity_id = Options_Service::get_aad_option( 'saml_sp_entity_id' );

			if ( empty( $sp_entity_id ) ) {
				$sp_entity_id = sprintf( '%s%s', $base_url, uniqid() );
				Options_Service::add_update_option( 'saml_sp_entity_id', $sp_entity_id );
			}

			$sp_sls_url = Options_Service::get_aad_option( 'saml_sp_sls_url' );

			if ( empty( $sp_sls_url ) ) {
				$sp_sls_url = sprintf( '%swp-login.php&action=loggedout', $base_url );
				Options_Service::add_update_option( 'saml_sp_sls_url', $sp_sls_url );
			}

			$sp_acs_url = Options_Service::get_aad_option( 'saml_sp_acs_url' );

			if ( empty( $sp_acs_url ) ) {
				$sp_acs_url = $base_url;
				Options_Service::add_update_option( 'saml_sp_acs_url', $sp_acs_url );
			}

			$sp_metadata_valid_until = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+48 hours', time() ) );

			$xml_chunks = array(
				'<?xml version="1.0"?>',
				'<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" validUntil="__##sp_metadata_valid_until##__" entityID="__##sp_entity_id##__">',
				'  <md:SPSSODescriptor AuthnRequestsSigned="true" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">',
				'    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="__##sp_sls_url##__" />',
				'    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="__##sp_acs_url##__" index="1" />',
				'  </md:SPSSODescriptor>',
				'</md:EntityDescriptor>',
			);

			$xml = implode( PHP_EOL, $xml_chunks );

			$xml = str_replace( '__##sp_metadata_valid_until##__', $sp_metadata_valid_until, $xml );
			$xml = str_replace( '__##sp_entity_id##__', $sp_entity_id, $xml );
			$xml = str_replace( '__##sp_sls_url##__', $sp_sls_url, $xml );
			$xml = str_replace( '__##sp_acs_url##__', $sp_acs_url, $xml );

			return $xml;
		}

		/**
		 * To support multi-tenancy.
		 *
		 * @param array $issuers
		 * @return array
		 */
		public static function merge_allowed_issuers( $issuers ) {
			$_issuers  = Options_Service::get_global_list_var( 'allowed_tenants' );
			$_issuers  = array_map(
				function ( $tenant_id ) {
					return sprintf( 'https://sts.windows.net/%s/', $tenant_id );
				},
				$_issuers
			);
			$__issuers = array_merge( $_issuers, $issuers );
			return array_unique( $__issuers );
		}

		/**
		 * Attempts to read the signing certificate from the IdP metadata / App Federation Metadata URL
		 *
		 * @since 26.1
		 *
		 * @return bool|WP_Error Returns true if the import was successful otherwise an error.
		 */
		public static function get_signing_certificate_for_tenant( $tenant_id, $force = false ) {
			if ( empty( $tenant_id ) ) {
				return new WP_Error( 'MissingArgumentException', sprintf( '%s -> Tenant ID cannot be empty', __METHOD__ ) );
			}

			/**
			 * 3 scenarios:
			 *   1. Single tenant > Take saml_idp_meta_data_url (ours)
			 *   2. Multi tenancy > Retrieve from default meta data URL (theirs) when issuer is not us
			 *   3. Multiple IdPs > Take saml_idp_meta_data_url (ours)
			 */

			if ( Options_Service::get_global_boolean_var( 'multi_tenanted' ) ) {
				$idp_entity_id    = Options_Service::get_aad_option( 'saml_idp_entity_id' );
				$idp_tentant_id   = self::get_tenant_id_from_entity_id( $idp_entity_id );
				$use_their_config = strcasecmp( $idp_tentant_id, $tenant_id ) !== 0;
			}

			if ( ! $force && empty( $use_their_config ) ) {
				$_x509_cert = Options_Service::get_aad_option( 'saml_x509_cert' );

				if ( ! empty( $_x509_cert ) ) {
					return $_x509_cert;
				}
			}

			$certificates = get_site_option( 'wpo365_x509_certificates', array() );

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$idp_id          = $request->get_item( 'idp_id' );

			if ( empty( $idp_id ) ) {
				$idp_id = 'default'; // Not to be confused with 'default' => true for an IdP Config
			}

			$cert_storage_key = sprintf( '%s_%s', $tenant_id, $idp_id );

			if ( ! $force ) {

				foreach ( $certificates as $key => $certificate ) {

					if ( strcasecmp( $key, $cert_storage_key ) === 0 ) {
						return self::format_x509_certificate( $certificate );
					}
				}
			}

			if ( empty( $use_their_config ) ) {
				$federation_metadata_url = Options_Service::get_aad_option( 'saml_idp_meta_data_url' );
			}

			if ( empty( $federation_metadata_url ) ) {
				$tld                     = Options_Service::get_aad_option( 'tld' );
				$tld                     = ! empty( $tld ) ? $tld : '.com';
				$federation_metadata_url = sprintf(
					'https://login.microsoftonline%s/%s/FederationMetadata/2007-06/FederationMetadata.xml',
					$tld,
					$tenant_id
				);
			}

			$response = wp_remote_get(
				$federation_metadata_url,
				array(
					'method'    => 'GET',
					'headers'   => array(
						'Expect' => '',
					),
					'sslverify' => ! Options_Service::get_global_boolean_var( 'skip_host_verification' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$idp_meta = wp_remote_retrieve_body( $response );

			if ( empty( $idp_meta ) ) {
				return new WP_Error( 'BodyParseError', sprintf( '%s -> No body was detected in the response retrieved from %s', __METHOD__, $federation_metadata_url ) );
			}

			$idp_meta_xml = simplexml_load_string( $idp_meta );

			if ( $idp_meta_xml === false ) {
				return new WP_Error( 'XmlParseError', sprintf( '%s -> Body retrieved from %s cannot be loaded as an XML document', __METHOD__, $federation_metadata_url ) );
			}

			$x509_cert = $idp_meta_xml->IDPSSODescriptor->KeyDescriptor->KeyInfo->X509Data->X509Certificate->__toString(); // phpcs:ignore

			$certificates[ $cert_storage_key ] = $x509_cert;
			update_site_option( 'wpo365_x509_certificates', $certificates );

			return self::format_x509_certificate( $x509_cert );
		}

		/**
		 * Gets the tenant / directory ID from the SAML entity ID
		 *
		 * @param mixed $entity_id
		 * @return string Tenant ID or empty string
		 */
		public static function get_tenant_id_from_entity_id( $entity_id ) {
			if ( empty( $entity_id ) ) {
				return '';
			}

			$segments = explode( '/', $entity_id );

			foreach ( $segments as $segment ) {

				if ( ! empty( $segment ) && strlen( $segment ) === 36 ) {
					return $segment;
				}
			}

			return '';
		}

		/**
		 * Rewrites the X509 string into the required format.
		 *
		 * @since 25.0
		 *
		 * @param mixed $certificate
		 * @return string
		 */
		private static function format_x509_certificate( $certificate ) {
			if ( empty( $certificate ) ) {
				return '';
			}

			$chunks = str_split( $certificate, 64 );
			array_unshift( $chunks, '-----BEGIN CERTIFICATE-----' );
			$chunks[] = '-----END CERTIFICATE-----' . PHP_EOL;

			return implode( PHP_EOL, $chunks );
		}
	}
}
