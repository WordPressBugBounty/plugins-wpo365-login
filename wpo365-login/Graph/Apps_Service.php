<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace Wpo\Graph;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use stdClass;
use WP_Error;
use WP_REST_Request;
use Wpo\Services\Access_Token_Service;
use Wpo\Services\Graph_Service;
use Wpo\Services\Options_Service;

if ( ! class_exists( '\Wpo\Graph\Apps_Service' ) ) {

	class Apps_Service {

		/**
		 *
		 * @param WP_REST_Request $request
		 * @return object|WP_Error
		 */
		public static function add_app_instance( $request ) {
			$params   = json_decode( $request->get_body(), false );
			$is_valid = ! property_exists( $params, 'appInstance' )
			? new WP_Error( 'ArgumentException', 'Mandatory argument "appInstance" not found.' )
			: self::is_valid_app_instance( $params->appInstance );

			if ( is_wp_error( $is_valid ) ) {
				return $is_valid;
			}

			$app_instance = Apps_Db::add_app_instance( $params->appInstance );

			if ( is_wp_error( $app_instance ) ) {
				return $app_instance;
			}

			$apply_result = self::apply_requirements( $params->appInstance->appliedRequirements );

			if ( is_wp_error( $apply_result ) ) {
				return $apply_result;
			}

			return $app_instance;
		}

		/**
		 *
		 * @param WP_REST_Request $request
		 * @return object|WP_Error
		 */
		public static function update_app_instance( $request ) {
			$id = absint( sanitize_key( $request['id'] ) );

			if ( ! is_numeric( $id ) || $id === 0 ) {
				return new WP_Error( 'ArgumentException', sprintf( 'The method %s expected an integer value as ID. [%d]', __METHOD__, $id ) );
			}

			$params = json_decode( $request->get_body(), false );

			$is_valid = ! property_exists( $params, 'appInstance' )
			? new WP_Error( 'ArgumentException', 'Mandatory argument "appInstance" not found.' )
			: self::is_valid_app_instance( $params->appInstance );

			if ( is_wp_error( $is_valid ) ) {
				return $is_valid;
			}

			$apply_result = self::apply_requirements(
				$params->appInstance->appliedRequirements,
				$params->appInstance->config
			);

			if ( is_wp_error( $apply_result ) ) {
				return $apply_result;
			}

			Apps_Db::update_app_instance( $id, $params->appInstance );

			return Apps_Db::get_app_instance( $id );
		}

		/**
		 *
		 * @param WP_REST_Request $request
		 * @return object|WP_Error
		 */
		public static function delete_app_instance( $request ) {
			$id = absint( sanitize_key( $request['id'] ) );

			if ( ! is_numeric( $id ) || $id === 0 ) {
				return new WP_Error( 'ArgumentException', sprintf( 'The method %s expected an integer value as ID. [%s]', __METHOD__, $id ) );
			}

			return Apps_Db::delete_app_instance( $id );
		}

		/**
		 *
		 * @param stdClass $requirements
		 * @param stdClass $config
		 *
		 * @return true|WP_Error
		 */
		public static function apply_requirements( $requirements, $config = null ) {

			// Ensure the WPO365 REST API for Microsoft Graph has been enabled.
			if ( ! Options_Service::get_global_boolean_var( 'enable_graph_api' ) ) {
				Options_Service::add_update_option( 'enable_graph_api', true );
			}

			$graph_version = Options_Service::get_global_string_var( 'graph_version' );

			// Ensure we are using the beta version of Microsoft Graph for richer data.
			if ( strcasecmp( $graph_version, 'current' ) === 0 ) {
				Options_Service::add_update_option( 'graph_version', 'beta' );
			}

			// Check permissions.
			$app_only_access = filter_var( $requirements->userRequirements->appOnlyAccess, FILTER_VALIDATE_BOOLEAN );

			// Add / Update WPO365 endpoints.
			if ( $app_only_access ) {
				$endpoints = ! empty( $requirements->appOnly->endpoints ) ? $requirements->appOnly->endpoints : array();
			} else {
				$endpoints = ! empty( $requirements->delegated->endpoints ) ? $requirements->delegated->endpoints : array();
			}

			if ( ! empty( $endpoints ) ) {
				$allowed_endpoints = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );

				foreach ( $endpoints as $endpoint ) {
					$updated = false;

					foreach ( $allowed_endpoints as $index => $allowed_endpoint ) {

						if ( ! $updated && strcasecmp( $allowed_endpoint['key'], $endpoint ) === 0 ) {
							$allowed_endpoints[ $index ] = array(
								'key'     => $endpoint,
								'boolVal' => $app_only_access,
							);

							$updated = true;
							break;
						}
					}

					if ( ! $updated ) {
						$allowed_endpoints[] = array(
							'key'     => $endpoint,
							'boolVal' => $app_only_access,
						);
					}
				}

				Options_Service::add_update_option( 'graph_allowed_endpoints', $allowed_endpoints );
			}

			if ( $app_only_access ) {
				// Ensure use App Only token is enabled.
				$app_only_application_id     = Options_Service::get_aad_option( 'app_only_application_id' );
				$app_only_application_secret = Options_Service::get_aad_option( 'app_only_application_secret' );
				$app_only_enabled            = ! empty( $app_only_application_id ) && ! empty( $app_only_application_secret );

				if ( ! $app_only_enabled && ! Options_Service::get_global_boolean_var( 'no_sso' ) ) {
					$delegated_application_id     = Options_Service::get_aad_option( 'application_id' );
					$delegated_application_secret = Options_Service::get_aad_option( 'application_secret' );

					if ( ! empty( $delegated_application_id ) && ! empty( $delegated_application_secret ) ) {
						Options_Service::add_update_option( 'use_app_only_token', true );
						Options_Service::add_update_option( 'use_single_app_registration', true );
						Options_Service::add_update_option( 'app_only_application_id', $delegated_application_id );
						Options_Service::add_update_option( 'app_only_application_secret', $delegated_application_secret );
						$app_only_application_id     = Options_Service::get_aad_option( 'app_only_application_id' );
						$app_only_application_secret = Options_Service::get_aad_option( 'app_only_application_secret' );
						$app_only_enabled            = true;
					}
				}

				$app_only_access_token = Access_Token_Service::get_app_only_access_token();

				if ( is_wp_error( $app_only_access_token ) ) {

					$error_message = $app_only_enabled
						? sprintf( 'Failed to obtain application-level access using "App Principal" (= App Registration) with ID %s. [Error: %s]', $app_only_application_id, $app_only_access_token->get_error_message() )
						: 'Cannot obtain application-level access. Please refer to the following guide https://tutorials.wpo365.com/courses/integration-application-permissions/ to register your WordPress website in Entra ID and allow it to access Microsoft 365 services and APIs as itself / without user-context.';

					return new WP_Error( 'PermissionException', $error_message );
				}

				foreach ( $requirements->appOnly->permissions as $role ) {

					if ( ! Access_Token_Service::token_has_role( $app_only_access_token, $role ) ) {
						return new WP_Error( 'PermissionException', sprintf( 'Application Permission for "%s" has not been granted to the "App Principal" (= App Registration) with ID %s', $role, $app_only_application_id ) );
					}
				}
			} else {
				$application_id = Options_Service::get_aad_option( 'application_id' );

				foreach ( $requirements->delegated->permissions as $scope ) {
					$access_token = Access_Token_Service::get_access_token( $scope );

					if ( is_wp_error( $access_token ) ) {
						return new WP_Error( 'PermissionException', sprintf( 'Delegated Permission for "%s" has not been granted to the "App Principal" (= App Registration) with ID %s. [Error: %s]', $scope, $application_id, $access_token->get_error_message() ) );
					}
				}
			}

			// Allow the use of the old AJAX API.
			if ( ! empty( $requirements->appOnly->ajax ) || ! empty( $requirements->delegated->ajax ) ) {
				Options_Service::add_update_option( 'enable_token_service', true );
			}

			// Allow proxy-type requests to the API.
			if ( ! empty( $requirements->appOnly->proxy ) || ! empty( $requirements->delegated->proxy ) ) {
				Options_Service::add_update_option( 'enable_graph_proxy', true );
			}

			// Allow apps to request OAuth tokens.
			if ( ! empty( $requirements->appOnly->token ) || ! empty( $requirements->delegated->token ) ) {
				Options_Service::add_update_option( 'graph_allow_token_retrieval', true );
			}

			// Allow apps to request OAuth when upload is enabled.
			if ( $config !== null && property_exists( $config, 'enableUpload' ) && $config->enableUpload ) {
				Options_Service::add_update_option( 'graph_allow_token_retrieval', true );
			}

			return true;
		}

		/**
		 *
		 * @param WP_REST_Request $request
		 * @return array|WP_Error
		 */
		public static function apply_requirements_app_only( $request ) {
			$id = absint( sanitize_key( $request['id'] ) );

			if ( ! is_numeric( $id ) || $id === 0 ) {
				return new WP_Error( 'ArgumentException', sprintf( 'The method %s expected an integer value as ID. [%s]', __METHOD__, $id ) );
			}

			$current_app_instance = Apps_Db::get_app_instance( $id );

			if ( is_wp_error( $current_app_instance ) ) {
				return $current_app_instance;
			}

			if ( $current_app_instance === null ) {
				return new WP_Error( 'NotFoundException', sprintf( 'The requested app instance with ID %d was not found', $id ) );
			}

			$site_id         = ! empty( $current_app_instance->config->siteInfo->id ) ? $current_app_instance->config->siteInfo->id : null;
			$app_only_access = ! empty( $current_app_instance->appliedRequirements->userRequirements->appOnlyAccess );
			$application_id  = Options_Service::get_aad_option( 'app_only_application_id' );

			if ( ! $app_only_access ) {
				return new WP_Error( 'ConfigurationException', sprintf( 'An attempt to allow an "App Principal" to access a SharePoint Site Collection failed due to a misconfiguration' ) );
			}

			// Ensure we remove cached tokens before we check.
			delete_option( Access_Token_Service::SITE_META_ACCESS_TOKEN );
			$app_only_access_token = Access_Token_Service::get_app_only_access_token();

			if ( is_wp_error( $app_only_access_token ) ) {
				return $app_only_access_token;
			}

			if ( ! Access_Token_Service::token_has_role( $app_only_access_token, 'Sites.Selected' ) ) {
				return new WP_Error( 'PermissionException', sprintf( 'Application Permission for "Sites.Selected" has not been granted to the "App Principal" (= App Registration) with ID %s', $application_id ) );
			}

			if ( ! Access_Token_Service::token_has_role( $app_only_access_token, 'Sites.FullControl.All' ) ) {
				return new WP_Error( 'PermissionException', sprintf( 'Please add the "Microsoft Graph > Application permissions > Sites.FullControl.All" API permission to the App Registration with ID %s. It can be removed once application-level access is enabled.', $application_id ) );
			}

			if ( empty( $site_id ) && ! empty( $current_app_instance->config->legacy ) ) {
				$hostname             = $current_app_instance->config->legacy->hostname ? $current_app_instance->config->legacy->hostname : null;
				$server_relative_path = $current_app_instance->config->legacy->serverRelativePath ? $current_app_instance->config->legacy->serverRelativePath : null;

				if ( empty( $hostname ) || empty( $server_relative_path ) ) {
					return new WP_Error( 'ConfigurationException', sprintf( 'An attempt to allow an "App Principal" to access a SharePoint Site Collection failed due to a missing legacy site identifiers' ) );
				}

				$result = Graph_Service::fetch( sprintf( '/sites/%s:/%s?select=id', $hostname, $server_relative_path ), 'GET', false, array( 'Content-Type' => 'application/json' ), false, false, '', 'https://graph.microsoft.com/Sites.FullControl.All' );

				if ( ! empty( $result['payload']['id'] ) ) {
					$site_id = $result['payload']['id'];
				}
			}

			if ( empty( $site_id ) ) {
				return new WP_Error( 'ConfigurationException', sprintf( 'An attempt to allow an "App Principal" to access a SharePoint Site Collection failed due to a missing site identifier' ) );
			}

			$data = array(
				'roles'               => array( 'Read' ),
				'grantedToIdentities' => array(
					array(
						'application' => array(
							'id'          => $application_id,
							'displayName' => 'Not available',
						),
					),
				),
			);

			$json   = wp_json_encode( $data );
			$result = Graph_Service::fetch( sprintf( '/sites/%s/permissions', $site_id ), 'POST', false, array( 'Content-Type' => 'application/json' ), false, false, $json, 'https://graph.microsoft.com/Sites.FullControl.All' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( isset( $result['response_code'] ) && $result['response_code'] === 201 ) {
				return $result['payload'];
			}

			$error_message = isset( $result['payload']['error']['message'] ) ? $result['payload']['error']['message'] : 'Unknown error occurred';
			return new WP_Error( 'GraphFetchException', sprintf( 'An attempt to allow an "App Principal" to access a SharePoint Site Collection failed [Reason: %s].', $error_message ) );
		}

		/**
		 *
		 * @param object $app_instance
		 * @return WP_Error|true
		 */
		public static function is_valid_app_instance( $app_instance ) {

			if ( ! ( $app_instance instanceof stdClass ) ) {
				return new WP_Error( 'ValidationException', 'App instance is not valid. [Error: not an array]' );
			}

			if ( ! property_exists( $app_instance, 'appType' ) ) {
				return new WP_Error( 'ValidationException', 'App instance is not valid. [Error: property "appType" missing]' );
			}

			if ( ! property_exists( $app_instance, 'title' ) ) {
				return new WP_Error( 'ValidationException', 'App instance is not valid. [Error: property "title" missing]' );
			}

			if ( ! property_exists( $app_instance, 'appliedRequirements' ) ) {
				return new WP_Error( 'ValidationException', 'App instance is not valid. [Error: property "appliedRequirements" missing]' );
			}

			if ( ! property_exists( $app_instance, 'config' ) ) {
				return new WP_Error( 'ValidationException', 'App instance is not valid. [Error: property "config" missing]' );
			}

			return true;
		}
	}
}
