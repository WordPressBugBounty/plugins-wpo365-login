<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use Wpo\Core\Permissions_Helpers;
use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Access_Token_Service;
use Wpo\Services\Graph_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;

if ( ! class_exists( '\Wpo\Graph\Request' ) ) {

	class Request {


		/**
		 * A transparant proxy for https://graph.microsoft.com/.
		 *
		 * Supported body parameters are:
		 * - application (boolean)  -> when an access token emitted by the Azure AD app with static application permissions should be used.
		 * - binary (boolean)       -> e.g. when retrieving a user's profile picture. The binary result will be an JSON structure with a "binary" member with a base64 encoded value.
		 * - data (string)          -> Stringified JSON object (will only be sent if method equals post)
		 * - headers (array)        -> e.g. {"ConsistencyLevel": "eventual"}
		 * - method (string)        -> any of get, post
		 * - query (string)         -> e.g. demo@wpo365/photo/$value
		 * - scope (string)         -> the permission scope required for the query e.g. https://graph.microsoft.com/User.Read.All.
		 *
		 * @param WP_REST_Request $rest_request The request object.
		 * @return array|WP_Error
		 */
		public static function get( $rest_request, $endpoint ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$body = $rest_request->get_json_params();

			if ( empty( $endpoint ) || empty( $body ) || ! \is_array( $body ) || empty( $body['query'] ) ) {
				return new \WP_Error( 'InvalidArgumentException', 'Body is malformed JSON or the request header did not define the Content-type as application/json.' );
			}

			$endpoint_config = self::validate_endpoint( $endpoint );

			if ( is_wp_error( $endpoint_config ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Attempt to access %s has been blocked', __METHOD__, $endpoint ) );
				return $endpoint_config;
			}

			$use_delegated = Permissions_Helpers::must_use_delegate_access_for_scope( $body['scope'] ) || $endpoint_config === false;
			$binary        = ! empty( $body['binary'] ) ? true : false;
			$data          = ! empty( $body['data'] ) ? $body['data'] : '';
			$headers       = ! empty( $body['headers'] ) ? $body['headers'] : array();
			$method        = ! empty( $body['method'] ) ? \strtoupper( $body['method'] ) : 'GET';
			$query         = $endpoint . Url_Helpers::leadingslashit( $body['query'] );
			$scope         = $body['scope'];

			$result = Graph_Service::fetch( $query, $method, $binary, $headers, $use_delegated, false, $data, $scope );

			if ( \is_wp_error( $result ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Could not fetch data from Microsoft Graph [' . $result->get_error_message() . '].' );
				return new \WP_Error( 'GraphFetchError', $result->get_error_message(), array( 'status' => 500 ) );
			}

			if ( empty( $result ) ) {
				return new \WP_Error( 'GraphNoContent', 'Your request to Microsoft Graph returned an empty result.', array( 'status' => 204 ) );
			}

			if ( $result['response_code'] < 200 || $result['response_code'] > 299 ) {
				$json_encoded_result = wp_json_encode( $result );
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not fetch data from Microsoft Graph [' . $json_encoded_result . '].' );
				return new \WP_Error( 'GraphFetchError', 'Your request to Microsoft Graph returned an invalid HTTP response code [' . $json_encoded_result . '].', array( 'status' => $result['response_code'] ) );
			}

			if ( $binary ) {
				return array( 'binary' => \base64_encode( $result['payload'] ) ); // phpcs:ignore
			}

			return $result['payload'];
		}

		/**
		 * Used to proxy a request from the client-side to another O365 service e.g. yammer
		 * to circumvent CORS issues.
		 *
		 * @since 17.0
		 *
		 * @return array|WP_Error
		 */
		public static function proxy( $rest_request ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$body = $rest_request->get_json_params();

			if ( empty( $body ) || ! \is_array( $body ) || empty( $body['url'] ) || empty( $body['scope'] ) ) {
				return new \WP_Error( 'InvalidArgumentException', 'Body is malformed JSON or the request header did not define the Content-type as application/json.' );
			}

			$url             = ! empty( $body['url'] ) ? $body['url'] : '';
			$endpoint_config = self::validate_endpoint( $url );

			if ( is_wp_error( $endpoint_config ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Attempt to access %s has been blocked', __METHOD__, $url ) );
				return $endpoint_config;
			}

			$scope = $body['scope'];
			$data  = array_key_exists( 'data', $body ) && ! empty( $body['data'] ) ? $body['data'] : '';

			if ( WordPress_Helpers::stripos( $scope, 'https://analysis.windows.net/powerbi/api/.default' ) === 0 ) {

				if ( ! empty( $data ) && is_array( $data ) && array_key_exists( 'identities', $data ) ) {
					$wp_usr           = wp_get_current_user();
					$identities_count = count( $data['identities'] );

					for ( $i = 0; $i < $identities_count; $i++ ) {

						if ( ! empty( $data['identities'][ $i ]['username'] ) && WordPress_Helpers::stripos( $data['identities'][ $i ]['username'], 'wp_' ) === 0 ) {
							$key                                  = str_replace( 'wp_', '', $data['identities'][ $i ]['username'] );
							$data['identities'][ $i ]['username'] = $wp_usr->{$key};
						}

						if ( ! empty( $data['identities'][ $i ]['username'] ) && WordPress_Helpers::stripos( $data['identities'][ $i ]['username'], 'meta_' ) === 0 ) {
							$key                                  = str_replace( 'meta_', '', $data['identities'][ $i ]['username'] );
							$username                             = get_user_meta( $wp_usr->ID, $key, true );
							$data['identities'][ $i ]['username'] = ! empty( $username ) ? $username : '';
						}

						if ( ! empty( $data['identities'][ $i ]['roles'] ) && is_string( $data['identities'][ $i ]['roles'] ) && WordPress_Helpers::stripos( $data['identities'][ $i ]['roles'], 'meta_' ) === 0 ) {
							$key                               = str_replace( 'meta_', '', $data['identities'][ $i ]['roles'] );
							$roles                             = get_user_meta( $wp_usr->ID, $key );
							$roles                             = ! empty( $roles ) && ! is_array( $roles )
								? $roles                       = array( $roles )
								: (
									( ! empty( $roles )
										? $roles
										: array() )
								);
							$data['identities'][ $i ]['roles'] = $roles;
						}
					}
				}
			}

			$binary      = ! empty( $body['binary'] ) ? filter_var( $body['binary'], FILTER_VALIDATE_BOOLEAN ) : false;
			$application = ! empty( $body['application'] ) ? filter_var( $body['application'], FILTER_VALIDATE_BOOLEAN ) : false;
			$headers     = ! empty( $body['headers'] ) && \is_array( $body['headers'] ) ? $body['headers'] : array();
			$method      = ! empty( $body['method'] ) ? \strtoupper( $body['method'] ) : 'GET';

			$access_token = $application
				? Access_Token_Service::get_app_only_access_token( $scope )
				: Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				$warning = 'Could not retrieve an access token for (scope|url) ' . $scope . '|' . $url . '.  Error details: ' . $access_token->get_error_message();
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> ' . $warning );
				return new \WP_Error( 'ProxyFetchError', $warning );
			}

			$headers['Authorization'] = sprintf( 'Bearer %s', $access_token->access_token );
			$headers['Expect']        = '';

			if ( WordPress_Helpers::stripos( $url, '$count=true' ) !== false ) {
				$headers['ConsistencyLevel'] = 'eventual';
			}

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Fetching from ' . $url );

			if ( WordPress_Helpers::stripos( $method, 'GET' ) === 0 ) {
				$response = wp_remote_get(
					$url,
					array(
						'headers'   => $headers,
						'sslverify' => $skip_ssl_verify,
					)
				);
			} elseif ( WordPress_Helpers::stripos( $method, 'POST' ) === 0 ) {
				$response = wp_remote_post(
					$url,
					array(
						'body'      => $data,
						'headers'   => $headers,
						'sslverify' => $skip_ssl_verify,
					)
				);
			} else {
				return new \WP_Error( 'NotImplementedException', 'Error occured whilst fetching from ' . $url . ':  Method ' . $method . ' not implemented' );
			}

			if ( is_wp_error( $response ) ) {
				$warning = 'Error occured whilst fetching from ' . $url . ': ' . $response->get_error_message();
				Log_Service::write_log( 'WARN', __METHOD__ . " -> $warning" );
				return new \WP_Error( 'ProxyFetchError', $warning );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Error occured whilst fetching from Microsoft Graph: HTTP STATUS %d', __METHOD__, intval( $status ) ) );
				return new \WP_Error( 'ProxyFetchError', sprintf( 'Error occurred whilst fetching from Microsoft Graph: HTTP STATUS %d', intval( $status ) ) );
			}

			if ( $binary ) {
				return array( 'binary' => \base64_encode( $body ) ); // phpcs:ignore
			}

			json_decode( $body );
			$json_error = json_last_error();

			if ( $json_error === JSON_ERROR_NONE ) {
				return $body;
			}

			Log_Service::write_log( 'WARN', sprintf( '%s -> Error occured whilst converting to JSON: %d [See next line for raw response]', __METHOD__, $json_error ) );
			Log_Service::write_log( 'DEBUG', $body );

			return new \WP_Error( 'ProxyFetchError', sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ) );
		}

		/**
		 * Request an (bearer) access token for the scope provided.
		 *
		 * @since 17.0
		 *
		 * @return array|WP_Error
		 */
		public static function token( $rest_request ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$body = $rest_request->get_json_params();

			if ( empty( $body ) || ! \is_array( $body ) || empty( $body['scope'] ) ) {
				return new \WP_Error( 'InvalidArgumentException', 'Body is malformed JSON or the request header did not define the Content-type as application/json.' );
			}

			$scope = $body['scope'];

			// Currently application level permissions are not supported for proxy requests
			$access_token = Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				$warning = 'Could not retrieve an access token for (scope) ' . $scope . '; Error details: ' . $access_token->get_error_message();
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> ' . $warning );
				return new \WP_Error( 'TokenFetchError', $warning );
			}

			return array(
				'access_token' => $access_token->access_token,
				'scope'        => $scope,
			);
		}

		/**
		 * Given an endpoint, checks if all endpoints are allowed and if not validates the endpoint provided
		 * and returns a WP_Error if not or else a boolean value indicating whether application-level permissions
		 * are allowed.
		 *
		 * @since   17.0
		 *
		 * @param   string $endpoint   The endpoint to validate.
		 *
		 * @return  WP_Error|bool       Returns a WP_Error if the endpoint is not allowed or else a boolean value indicating whether application-level permissions are allowed.
		 */
		private static function validate_endpoint( $endpoint ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( Options_Service::get_global_boolean_var( 'graph_allow_all_endpoints' ) ) {
				return true;
			} else {
				if ( WordPress_Helpers::stripos( $endpoint, '/' ) === 0 ) {
					$tld      = Options_Service::get_aad_option( 'tld' );
					$tld      = ! empty( $tld ) ? $tld : '.com';
					$endpoint = sprintf( 'https://graph.microsoft%s/_%s', $tld, $endpoint );
				}

				$endpoint = str_replace( '/v1.0/', '/_/', $endpoint );
				$endpoint = str_replace( '/beta/', '/_/', $endpoint );

				$allowed_endpoints_and_permissions = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );

				foreach ( $allowed_endpoints_and_permissions as $allowed_endpoint_config ) {

					$allowed_endpoint = $allowed_endpoint_config['key'];
					$allowed_endpoint = str_replace( '/v1.0/', '/_/', $allowed_endpoint );
					$allowed_endpoint = str_replace( '/beta/', '/_/', $allowed_endpoint );

					if ( WordPress_Helpers::stripos( $endpoint, $allowed_endpoint ) === 0 ) {
						return $allowed_endpoint_config['boolVal'] === true;
					}
				}

				return new \WP_Error( 'ForbiddenException', sprintf( 'This type of request is not allowed [endpoint %s]. Go to WP Admin > WPO365 > Integration and add the endpoint to the list of \'Allowed endpoints\' in the section \'Microsoft 365 Apps\'.', $endpoint ), array( 'status' => 403 ) );
			}
		}
	}
}
