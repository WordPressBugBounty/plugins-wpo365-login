<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use WP_Error;
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
		 * - application (boolean)  -> when an access token emitted by the Entra ID app with static application permissions should be used.
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
				return new \WP_Error( 'missing_argument', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
			}

			$endpoint_config = self::validate_endpoint( $endpoint );

			if ( is_wp_error( $endpoint_config ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $endpoint_config->get_error_message() ) );
				return $endpoint_config;
			}

			$scope         = sanitize_text_field( urldecode( $body['scope'] ) );
			$use_delegated = Permissions_Helpers::must_use_delegate_access_for_scope( $scope ) || $endpoint_config === false;
			$binary        = ! empty( $body['binary'] ) ? true : false;
			$data          = ! empty( $body['data'] ) ? $body['data'] : '';
			$headers       = ! empty( $body['headers'] ) ? $body['headers'] : array();
			$method        = ! empty( $body['method'] ) ? \strtoupper( sanitize_key( $body['method'] ) ) : 'GET';
			$query         = $endpoint . Url_Helpers::leadingslashit( sanitize_text_field( urldecode( $body['query'] ) ) );

			$result = Graph_Service::fetch( $query, $method, $binary, $headers, $use_delegated, false, $data, $scope );

			if ( \is_wp_error( $result ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Failed to fetch from Microsoft Graph. [Error: %s]', __METHOD__, $result->get_error_message() ) );
				return new \WP_Error( 'fetch_error', $result->get_error_message(), array( 'status' => 500 ) );
			}

			if ( $result['response_code'] < 200 || $result['response_code'] > 299 ) {
				$json_encoded_result = wp_json_encode( $result );
				Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to fetch from Microsoft Graph. [Raw: %s]', __METHOD__, $json_encoded_result ) );
				return new \WP_Error(
					'fetch_error',
					sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $result['response_code'] ),
					array(
						'status' => $result['response_code'],
						'raw'    => $json_encoded_result,
					)
				);
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
				return new \WP_Error( 'missing_argument', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
			}

			$url             = ! empty( $body['url'] ) ? sanitize_text_field( urldecode( $body['url'] ) ) : '';
			$endpoint_config = self::validate_endpoint( $url );

			if ( is_wp_error( $endpoint_config ) ) {
				Log_Service::write_log( 'WARN', $endpoint_config->get_error_message() );
				return $endpoint_config;
			}

			$scope = sanitize_text_field( urldecode( $body['scope'] ) );
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

						if ( ! empty( $data['identities'][ $i ]['customData'] ) && WordPress_Helpers::stripos( $data['identities'][ $i ]['customData'], 'wp_' ) === 0 ) {
							$key                                    = str_replace( 'wp_', '', $data['identities'][ $i ]['customData'] );
							$data['identities'][ $i ]['customData'] = $wp_usr->{$key};
						}

						if ( ! empty( $data['identities'][ $i ]['customData'] ) && WordPress_Helpers::stripos( $data['identities'][ $i ]['customData'], 'meta_' ) === 0 ) {
							$key                                    = str_replace( 'meta_', '', $data['identities'][ $i ]['customData'] );
							$custom_data                            = get_user_meta( $wp_usr->ID, $key, true );
							$data['identities'][ $i ]['customData'] = ! empty( $custom_data ) ? $custom_data : '';
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
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $access_token->get_error_message() ) );
				return new \WP_Error( 'not_authorized', $access_token->get_error_message(), array( 'status' => 403 ) );
			}

			$headers['Authorization'] = sprintf( 'Bearer %s', $access_token->access_token );
			$headers['Expect']        = '';

			if ( WordPress_Helpers::stripos( $url, '$count=true' ) !== false ) {
				$headers['ConsistencyLevel'] = 'eventual';
			}

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

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
				return new \WP_Error(
					'not_implemented',
					sprintf(
						'Failed to fetch from %s. [Error: Method %s not implemented]',
						$url,
						$method
					),
					array( 'status' => 500 )
				);
			}

			if ( is_wp_error( $response ) ) {
				$warning = sprintf(
					'Failed to fetch from %s. [Error: %s]',
					$url,
					$response->get_error_message()
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'fetch_error', $warning );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				$warning = sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $status );
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> %s', __METHOD__, $warning )
				);
				return new \WP_Error(
					'fetch_error',
					$warning,
					array(
						'status' => $status,
						'raw'    => $body,
					)
				);
			}

			if ( $binary ) {
				return array( 'binary' => \base64_encode( $body ) ); // phpcs:ignore
			}

			$json       = json_decode( $body );
			$json_error = json_last_error();

			if ( $json_error === JSON_ERROR_NONE ) {
				return $json;
			}

			Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to convert to JSON: %d', __METHOD__, $json_error ) );

			return new \WP_Error(
				'json_error',
				sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ),
				array(
					'status' => 500,
					'raw'    => $body,
				)
			);
		}

		/**
		 * Used execute a Microsoft Graph batch-request.
		 *
		 * @since 40.0
		 *
		 * @return array|WP_Error
		 */
		public static function batch( $rest_request ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$body = $rest_request->get_json_params();

			if ( empty( $body ) || ! \is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data']['requests'] ) || empty( $body['scope'] ) ) {
				return new \WP_Error( 'missing_argument', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
			}

			$data = array( 'requests' => array() );

			foreach ( $body['data']['requests'] as $batch_request ) {
				$url             = sanitize_text_field( urldecode( $batch_request['url'] ) );
				$endpoint_config = self::validate_endpoint( $url );

				if ( is_wp_error( $endpoint_config ) ) {
					Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $endpoint_config->get_error_message() ) );
					return $endpoint_config;
				}

				if ( ! isset( $batch_request['id'] ) || ! isset( $batch_request['method'] ) ) {
					$message = 'Required batch-request-body properties [id, method] not found.';
					Log_Service::write_log( 'ERROR', sprintf( '%s -> %s', __METHOD__, $message ) );
					return new WP_Error( 'missing_argument', $message, array( 'status' => 400 ) );
				}

				$data['requests'][] = (object) $batch_request;
			}

			$binary        = ! empty( $body['binary'] ) ? filter_var( $body['binary'], FILTER_VALIDATE_BOOLEAN ) : false;
			$application   = ! empty( $body['application'] ) ? filter_var( $body['application'], FILTER_VALIDATE_BOOLEAN ) : false;
			$headers       = ! empty( $body['headers'] ) && \is_array( $body['headers'] ) ? $body['headers'] : array();
			$scope         = sanitize_text_field( urldecode( $body['scope'] ) );
			$graph_version = Options_Service::get_global_string_var( 'graph_version' );
			$graph_version = empty( $graph_version ) || $graph_version === 'current'
				? 'v1.0'
				: 'beta';
			$url           = sprintf( 'https://graph.microsoft.com/%s/$batch', $graph_version );

			if ( $application ) {
				$scope_host     = WordPress_Helpers::stripos( $scope, 'https://' ) !== false ? wp_parse_url( $scope, PHP_URL_HOST ) : 'graph.microsoft.com';
				$tld            = Options_Service::get_aad_option( 'tld' );
				$tld            = ! empty( $tld ) ? $tld : '.com';
				$scope_host     = str_replace( '.com', $tld, $scope_host );
				$app_only_scope = "https://$scope_host/.default";
				$scope_segments = explode( '/', $scope );
				$role           = array_pop( $scope_segments );
			}

			$access_token = $application
				? Access_Token_Service::get_app_only_access_token( $app_only_scope, $role )
				: Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $access_token->get_error_message() ) );
				return new \WP_Error( 'not_authorized', $access_token->get_access_token(), array( 'status' => 403 ) );
			}

			$headers['Authorization'] = sprintf( 'Bearer %s', $access_token->access_token );
			$headers['Expect']        = '';
			$headers['Accept']        = 'application/json';
			$headers['Content-Type']  = 'application/json';

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

			Log_Service::write_log( 'DEBUG', sprintf( '%s -> Fetching from %s', __METHOD__, $url ) );

			$response = wp_remote_post(
				$url,
				array(
					'body'      => wp_json_encode( $data ),
					'headers'   => $headers,
					'sslverify' => $skip_ssl_verify,
				)
			);

			if ( is_wp_error( $response ) ) {
				$warning = sprintf(
					'Failed to fetch from %s. [Error: %s]',
					$url,
					$response->get_error_message()
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'fetch_error', $warning );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				$warning = sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $status );
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> %s', __METHOD__, $warning )
				);
				return new \WP_Error(
					'fetch_error',
					$warning,
					array(
						'status' => $status,
						'raw'    => $body,
					)
				);
			}

			if ( $binary ) {
				return array( 'binary' => \base64_encode( $body ) ); // phpcs:ignore
			}

			$json       = json_decode( $body, true );
			$json_error = json_last_error();

			if ( $json_error === JSON_ERROR_NONE && isset( $json['responses'] ) ) {
				return $json;
			}

			Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to convert to JSON: %d', __METHOD__, $json_error ) );

			return new \WP_Error(
				'json_error',
				sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ),
				array(
					'status' => 500,
					'raw'    => $body,
				)
			);
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
				return new \WP_Error( 'missing_argument', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
			}

			$scope = sanitize_text_field( urldecode( $body['scope'] ) );

			// Currently application level permissions are not supported for proxy requests
			$access_token = Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $access_token->get_error_message() ) );
				return new \WP_Error( 'not_authorized', $access_token->get_error_message(), array( 'status' => 403 ) );
			}

			return array(
				'access_token' => $access_token->access_token,
				'scope'        => $scope,
			);
		}

		/**
		 * Upload a file (to SharePoint using Microsoft Graph).
		 *
		 * @since 39.0
		 *
		 * @return array|WP_Error
		 */
		public static function file() {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$file = isset( $_FILES['data'] ) ? $_FILES['data'] : null; // phpcs:ignore

			if ( empty( $file ) || $file['error'] !== UPLOAD_ERR_OK ) {
				return new WP_Error( 'no_file', sprintf( '%s -> No file was uploaded or upload failed.' ), array( 'status' => 400 ) );
			}

			$max_file_size = 1024 * 1024 * 3; // 3MB

			if ( $file['size'] > $max_file_size ) {
				return new WP_Error( 'file_size_error', sprintf( '%s -> File exceeds maximum size of 3 MB.' ), array( 'status' => 413 ) );
			}

			$file_name   = sanitize_file_name( $file['name'] );
			$file_path   = $file['tmp_name'];
			$file_type   = isset( $file['type'] ) ? $file['type'] : 'application/octet-stream';
			$url         = isset( $_POST['url'] ) ? sanitize_text_field( urldecode( $_POST['url'] ) ) : ''; // phpcs:ignore
			$application = isset( $_POST['application'] ) ? filter_var( wp_unslash( $_POST['application'] ), FILTER_VALIDATE_BOOLEAN ) : false; // phpcs:ignore
			$scope       = isset( $_POST['scope'] ) ? sanitize_text_field( urldecode( $_POST['scope'] ) ) : ''; // phpcs:ignore

			if ( $application ) {
				$scope_host     = WordPress_Helpers::stripos( $scope, 'https://' ) !== false ? wp_parse_url( $scope, PHP_URL_HOST ) : 'graph.microsoft.com';
				$tld            = Options_Service::get_aad_option( 'tld' );
				$tld            = ! empty( $tld ) ? $tld : '.com';
				$scope_host     = str_replace( '.com', $tld, $scope_host );
				$app_only_scope = "https://$scope_host/.default";
				$scope_segments = explode( '/', $scope );
				$role           = array_pop( $scope_segments );
			}

			if ( empty( $file_name ) || empty( $file_path ) || empty( $file_type ) ) {
				return new \WP_Error( 'missing_argument', 'Cannot upload file. [Error: Mandatory file attributes not found]', array( 'status' => 400 ) );
			}

			if ( empty( $url ) || empty( $scope ) ) {
				return new \WP_Error( 'missing_argument', 'Cannot upload file. [Error: Mandatory MS Graph parameters are missing]', array( 'status' => 400 ) );
			}

			$access_token = $application
				? Access_Token_Service::get_app_only_access_token( $app_only_scope, $role )
				: Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $access_token->get_error_message() ) );
				return new \WP_Error( 'not_authorized', $access_token->get_error_message(), array( 'status' => 403 ) );
			}

			$headers['Authorization'] = sprintf( 'Bearer %s', $access_token->access_token );
			$headers['Expect']        = '';
			$headers['Content-Type']  = $file_type;
			$skip_ssl_verify          = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
			global $wp_filesystem;

			$upload_file_contents = $wp_filesystem->get_contents( $file_path );

			if ( ! $upload_file_contents ) {
				$warning = sprintf( '%s -> Uploaded file not found or failed to read. [Path: %s]', __METHOD__, $file_path );
				Log_Service::write_log( 'ERROR', $warning );
				return new \WP_Error( 'file_not_found', $warning, array( 'status' => 500 ) );
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Fetching from ' . $url );

			$response = wp_remote_request(
				$url,
				array(
					'method'    => 'PUT',
					'body'      => $upload_file_contents,
					'headers'   => $headers,
					'sslverify' => $skip_ssl_verify,
				)
			);

			if ( is_wp_error( $response ) ) {
				$warning = sprintf(
					'Failed to fetch from %s. [Error: %s]',
					$url,
					$response->get_error_message()
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'fetch_error', $warning );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				$warning = sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $status );
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> %s', __METHOD__, $warning )
				);
				return new \WP_Error(
					'fetch_error',
					$warning,
					array(
						'status' => $status,
						'raw'    => $body,
					)
				);
			}

			$json       = json_decode( $body, true );
			$json_error = json_last_error();

			if ( $json_error === JSON_ERROR_NONE ) {
				return $json;
			}

			Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to convert to JSON: %d', __METHOD__, $json_error ) );

			return new \WP_Error(
				'json_error',
				sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ),
				array(
					'status' => 500,
					'raw'    => $body,
				)
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

				return new \WP_Error( 'not_authorized', sprintf( 'The endpoint "%s" is not allow-listed. Go to WP Admin > WPO365 > Integration and add the endpoint to the list of \'Allowed endpoints\' in the section \'Microsoft 365 Apps\'.', $endpoint ), array( 'status' => 403 ) );
			}
		}
	}
}
