<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use WP_Error;
use Wpo\Core\Compatibility_Helpers;
use Wpo\Core\Permissions_Helpers;
use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Access_Token_Service;
use Wpo\Services\Graph_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

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
		 * @param \WP_REST_Request $rest_request The request object.
		 * @param string           $endpoint
		 *
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

			$app_instance = self::resolve_app_instance( isset( $body['appId'] ) ? $body['appId'] : null );

			if ( is_wp_error( $app_instance ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $app_instance->get_error_message() ) );
				return $app_instance;
			}

			// An app that identified itself has its token mode decided by its configuration, not by its request.
			$application = $app_instance !== null
				? self::app_instance_uses_app_only( $app_instance )
				: ( ! empty( $body['application'] ) && filter_var( $body['application'], FILTER_VALIDATE_BOOLEAN ) );

			$scope   = sanitize_text_field( urldecode( $body['scope'] ) );
			$binary  = ! empty( $body['binary'] ) ? true : false;
			$data    = ! empty( $body['data'] ) ? $body['data'] : '';
			$headers = ! empty( $body['headers'] ) ? $body['headers'] : array();
			$method  = ! empty( $body['method'] ) ? \strtoupper( sanitize_key( $body['method'] ) ) : 'GET';
			$query   = $endpoint . Url_Helpers::leadingslashit( sanitize_text_field( urldecode( $body['query'] ) ) );

			/**
			 * Decide once whether this request must be sent with the permissions of the (signed-in) user.
			 *
			 * Reasons to do so, in the order they are evaluated:
			 *
			 * a) the endpoint cannot be served with application-level permissions at all - /me needs a user,
			 *    Copilot is delegated-only and Microsoft Graph's search API does not accept an app-only token;
			 * b) the endpoint could be, but the app did not ask for it;
			 * c) the scope itself requires delegated access e.g. Yammer or SharePoint;
			 * d) the administrator did not allow application-level permissions for the endpoint; or
			 * e) the application permission (or .default resource) requested is not allow-listed.
			 *
			 * Cause (b) applies to every endpoint once the app identified itself, because then the mode comes
			 * from the app's own configuration. For an app that did not - a deprecated [pintra] shortcode app,
			 * or a bundle that predates WI-416-6 - it applies only to the endpoints whose apps set the
			 * "application" body parameter from their configuration, because elsewhere it cannot be trusted.
			 */
			$refused_reason = '';
			$use_delegated  = in_array( $endpoint, array( '/copilot', '/me', '/search' ), true )
				|| ( ! $application && ( $app_instance !== null || in_array( $endpoint, array( '/drives', '/sites' ), true ) ) )
				|| Permissions_Helpers::must_use_delegate_access_for_scope( $scope );

			if ( ! $use_delegated && $endpoint_config === false ) {
				$use_delegated  = true;
				$refused_reason = self::app_only_refused_warning( $endpoint );
			}

			if ( ! $use_delegated ) {
				$resolved = self::resolve_app_only_request( $scope );

				if ( is_wp_error( $resolved ) ) {

					if ( $resolved->get_error_code() !== 'not_authorized' ) {
						Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $resolved->get_error_message() ) );
						return $resolved;
					}

					$use_delegated  = true;
					$refused_reason = $resolved->get_error_message();
				}
			}

			if ( ! empty( $refused_reason ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $refused_reason ) );
			}

			// Nothing can be requested on behalf of a user who never signed in with Microsoft.
			if ( $use_delegated && ! Access_Token_Service::user_has_delegated_access( get_current_user_id() ) ) {
				$warning = sprintf(
					'%s Please sign in with Microsoft first, or - if this app should be available to visitors who do not sign in - configure the app for application-level permissions and allow those for the endpoint "%s" on the plugin\'s Integration configuration page.',
					! empty( $refused_reason ) ? $refused_reason : sprintf( 'The endpoint "%s" can only be requested with the permissions of a user.', $endpoint ),
					$endpoint
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'not_authorized', self::client_error_message( $warning, 'This content is only available to a user who signed in with Microsoft.' ), array( 'status' => 403 ) );
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			if ( ! empty( $data['http_request_args'] ) ) {
				$request->set_item( 'http_request_args', $data['http_request_args'] );
				add_filter( 'http_request_args', '\Wpo\Graph\Request::add_http_request_args', 10, 2 );
				unset( $data['http_request_args'] );
			}

			// The client may define a timeout e.g. when waiting for Copilot response.
			if ( ! empty( $body['timeout'] ) ) {
				$timeout = filter_var( $body['timeout'], FILTER_VALIDATE_INT );

				if ( $timeout !== false ) {
					$request->set_item( 'timeout', $timeout );
				}
			}

			// No delegated fallback when the app explicitly asked for application-level permissions.
			$result = Graph_Service::fetch( $query, $method, $binary, $headers, $use_delegated, false, $data, $scope, 'WARN', ! $application );

			if ( ! empty( $request->get_item( 'http_request_args' ) ) ) {
				$request->remove_item( 'http_request_args' );
				remove_filter( 'http_request_args', '\Wpo\Graph\Request::add_http_request_args', 10 );
			}

			if ( \is_wp_error( $result ) ) {
				$error_message = ! empty( $refused_reason )
					? sprintf( '%s [Error: %s]', $refused_reason, $result->get_error_message() )
					: $result->get_error_message();
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Failed to fetch from Microsoft Graph. [Error: %s]', __METHOD__, $error_message ) );
				return new \WP_Error( 'fetch_error', self::client_error_message( $error_message, 'The request to Microsoft 365 could not be completed.' ), array( 'status' => 500 ) );
			}

			// The payload is expected to return the 302 Location header e.g. a pre-authenticated downloadUrl.
			if ( $result['response_code'] === 302 && ! empty( $result['payload'] ) ) {
				return $result['payload'];
			}

			if ( $result['response_code'] < 200 || $result['response_code'] > 299 ) {
				$json_encoded_result = wp_json_encode( $result );
				Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to fetch from Microsoft Graph. [Raw: %s]', __METHOD__, $json_encoded_result ) );
				return new \WP_Error(
					'fetch_error',
					sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $result['response_code'] ),
					self::client_error_data( $result['response_code'], $json_encoded_result )
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
		 * @param \WP_REST_Request $rest_request
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
			// An app may send "data" as a JSON string or as an object e.g. Power BI does both.
			$data = array_key_exists( 'data', $body ) && ! empty( $body['data'] )
				? ( is_array( $body['data'] ) ? $body['data'] : json_decode( $body['data'], true ) )
				: '';

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

						if ( ! empty( $data['identities'][ $i ]['roles'] ) && is_string( $data['identities'][ $i ]['roles'] ) ) {

							if ( WordPress_Helpers::stripos( $data['identities'][ $i ]['roles'], 'meta_' ) === 0 ) {
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
							} else {
								// Data is formatted as a string, which is incorrect.
								Log_Service::write_log( 'WARN', sprintf( '%s -> Token request JSON formatting error: Found string "%s" for identities.[%d].roles. Array expected.', __METHOD__, $data['identities'][ $i ]['roles'], $i ) );
								$data['identities'][ $i ]['roles'] = array( $data['identities'][ $i ]['roles'] );
							}
						}

						if ( ! empty( $data['identities'][ $i ]['datasets'] ) && is_string( $data['identities'][ $i ]['datasets'] ) ) {
							// Data is formatted as a string, which is incorrect.
							Log_Service::write_log( 'WARN', sprintf( '%s -> Token request JSON formatting error: Found string "%s" for identities.[%d].datasets. Array expected.', __METHOD__, $data['identities'][ $i ]['datasets'], $i ) );
							$data['identities'][ $i ]['datasets'] = array( $data['identities'][ $i ]['datasets'] );
						}
					}
				}
			}

			$app_instance = self::resolve_app_instance( isset( $body['appId'] ) ? $body['appId'] : null );

			if ( is_wp_error( $app_instance ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $app_instance->get_error_message() ) );
				return $app_instance;
			}

			$binary  = ! empty( $body['binary'] ) ? filter_var( $body['binary'], FILTER_VALIDATE_BOOLEAN ) : false;
			$headers = ! empty( $body['headers'] ) && \is_array( $body['headers'] ) ? $body['headers'] : array();
			$method  = ! empty( $body['method'] ) ? \strtoupper( $body['method'] ) : 'GET';
			$data    = is_array( $data ) ? wp_json_encode( $data ) : '';

			// An app that identified itself has its token mode decided by its configuration, not by its request.
			$application = $app_instance !== null
				? self::app_instance_uses_app_only( $app_instance )
				: ( ! empty( $body['application'] ) && filter_var( $body['application'], FILTER_VALIDATE_BOOLEAN ) );

			$app_only = self::resolve_app_only_context( $application, $scope, $endpoint_config !== false, $url );

			if ( is_wp_error( $app_only ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $app_only->get_error_message() ) );
				return $app_only;
			}

			$application    = $app_only['application'];
			$refused_reason = $app_only['refused_reason'];

			if ( ! empty( $refused_reason ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $refused_reason ) );
			}

			// Fix possible wrong headers.
			foreach ( $headers as $key => $value ) {

				if ( WordPress_Helpers::stripos( $key, 'contenttype' ) !== false ) {
					$headers['Content-Type'] = $value;
					unset( $headers[ $key ] );
				}
			}

			$access_token = $application
				? Access_Token_Service::get_app_only_access_token( $app_only['scope'], $app_only['permission'] )
				: Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				$error_message = ! empty( $refused_reason )
					? sprintf( '%s [Error: %s]', $refused_reason, $access_token->get_error_message() )
					: $access_token->get_error_message();
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $error_message ) );
				return new \WP_Error( 'not_authorized', self::client_error_message( $error_message, 'This request to Microsoft 365 is not allowed.' ), array( 'status' => 403 ) );
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
						'headers'     => $headers,
						'sslverify'   => $skip_ssl_verify,
						'redirection' => 0,
					)
				);
			} elseif ( WordPress_Helpers::stripos( $method, 'POST' ) === 0 ) {
				$response = wp_remote_post(
					$url,
					array(
						'body'        => $data,
						'headers'     => $headers,
						'sslverify'   => $skip_ssl_verify,
						'redirection' => 0,
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
				return new \WP_Error( 'fetch_error', self::client_error_message( $warning, 'The request to Microsoft 365 could not be completed.' ) );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				$warning = sprintf( 'Failed to fetch from %s. [Status: %d]', wp_parse_url( $url, PHP_URL_HOST ), $status );
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> %s', __METHOD__, $warning )
				);
				return new \WP_Error( 'fetch_error', $warning, self::client_error_data( $status, $body ) );
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

			return new \WP_Error( 'json_error', sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ), self::client_error_data( 500, $body ) );
		}

		/**
		 * Used execute a Microsoft Graph batch-request.
		 *
		 * @since 40.0
		 *
		 * @param \WP_REST_Request $rest_request
		 *
		 * @return array|WP_Error
		 */
		public static function batch( $rest_request ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$body = $rest_request->get_json_params();

			if ( empty( $body ) || ! \is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data']['requests'] ) || empty( $body['scope'] ) ) {
				return new \WP_Error( 'missing_argument', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
			}

			$data                = array( 'requests' => array() );
			$force_delegated     = false;
			$force_delegated_url = '';

			foreach ( $body['data']['requests'] as $batch_request ) {
				$url             = sanitize_text_field( urldecode( $batch_request['url'] ) );
				$endpoint_config = self::validate_endpoint( $url );

				if ( is_wp_error( $endpoint_config ) ) {
					Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $endpoint_config->get_error_message() ) );
					return $endpoint_config;
				}

				if ( $endpoint_config === false ) {
					$force_delegated     = true;
					$force_delegated_url = $url;
				}

				if ( ! isset( $batch_request['id'] ) || ! isset( $batch_request['method'] ) ) {
					$message = 'Required batch-request-body properties [id, method] not found.';
					Log_Service::write_log( 'ERROR', sprintf( '%s -> %s', __METHOD__, $message ) );
					return new WP_Error( 'missing_argument', $message, array( 'status' => 400 ) );
				}

				$data['requests'][] = (object) $batch_request;
			}

			$app_instance = self::resolve_app_instance( isset( $body['appId'] ) ? $body['appId'] : null );

			if ( is_wp_error( $app_instance ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $app_instance->get_error_message() ) );
				return $app_instance;
			}

			$binary  = ! empty( $body['binary'] ) ? filter_var( $body['binary'], FILTER_VALIDATE_BOOLEAN ) : false;
			$headers = ! empty( $body['headers'] ) && \is_array( $body['headers'] ) ? $body['headers'] : array();
			$scope   = sanitize_text_field( urldecode( $body['scope'] ) );

			// An app that identified itself has its token mode decided by its configuration, not by its request.
			$application = $app_instance !== null
				? self::app_instance_uses_app_only( $app_instance )
				: ( ! empty( $body['application'] ) && filter_var( $body['application'], FILTER_VALIDATE_BOOLEAN ) );

			$app_only = self::resolve_app_only_context( $application, $scope, ! $force_delegated, $force_delegated_url );

			if ( is_wp_error( $app_only ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $app_only->get_error_message() ) );
				return $app_only;
			}

			$application    = $app_only['application'];
			$refused_reason = $app_only['refused_reason'];

			$graph_version = Options_Service::get_global_string_var( 'graph_version' );
			$graph_version = empty( $graph_version ) || $graph_version === 'current'
				? 'v1.0'
				: 'beta';
			$url           = sprintf( 'https://graph.microsoft.com/%s/$batch', $graph_version );

			if ( ! empty( $refused_reason ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $refused_reason ) );
			}

			$access_token = $application
				? Access_Token_Service::get_app_only_access_token( $app_only['scope'], $app_only['permission'] )
				: Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				$error_message = ! empty( $refused_reason )
					? sprintf( '%s [Error: %s]', $refused_reason, $access_token->get_error_message() )
					: $access_token->get_error_message();
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $error_message ) );
				return new \WP_Error( 'not_authorized', self::client_error_message( $error_message, 'This request to Microsoft 365 is not allowed.' ), array( 'status' => 403 ) );
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
					'body'        => wp_json_encode( $data ),
					'headers'     => $headers,
					'sslverify'   => $skip_ssl_verify,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				$warning = sprintf(
					'Failed to fetch from %s. [Error: %s]',
					$url,
					$response->get_error_message()
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'fetch_error', self::client_error_message( $warning, 'The request to Microsoft 365 could not be completed.' ) );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 200 || $status > 299 ) {
				$warning = sprintf( 'Failed to fetch from Microsoft Graph. [Status: %d]', $status );
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> %s', __METHOD__, $warning )
				);
				return new \WP_Error( 'fetch_error', $warning, self::client_error_data( $status, $body ) );
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

			return new \WP_Error( 'json_error', sprintf( 'Error occurred whilst converting to JSON: %d', $json_error ), self::client_error_data( 500, $body ) );
		}

		/**
		 * Request an (bearer) access token for the scope provided.
		 *
		 * @since 17.0
		 *
		 * @param \WP_REST_Request $rest_request
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
				return new \WP_Error( 'not_authorized', self::client_error_message( $access_token->get_error_message(), 'An access token for Microsoft 365 could not be obtained.' ), array( 'status' => 403 ) );
			}

			// This route hands the token to the browser, so it must be the user's and never the website's.
			if ( self::token_is_app_only( $access_token->access_token ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> Refused to return an access token with application-level permissions.', __METHOD__ ) );
				return new \WP_Error( 'not_authorized', 'An access token for Microsoft 365 could not be obtained.', array( 'status' => 403 ) );
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
		 * @since 45.0  Uploading with application-level permissions is no longer supported: the upload is always
		 *              sent with the permissions of the (signed-in) user who requested it. The response of the
		 *              destination is no longer returned either - only whether the upload succeeded.
		 *
		 * @return array|WP_Error   Array with a single "ok" member when the upload succeeded.
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
				Compatibility_Helpers::compat_warning(
					sprintf(
						'%s -> An app tried to upload a file to Microsoft 365 using application-level permissions. Support for this has been discontinued because of security concerns and the file is uploaded using the permissions of the (signed-in) user instead. Uploading therefore now requires a user who signed in with Microsoft and who has been granted the delegated permission Sites.ReadWrite.All. An app that has been configured for anonymous or application-only access can no longer upload files, and the option to enable file upload is no longer available for such an app.',
						__METHOD__
					)
				);
			}

			if ( empty( $file_name ) || empty( $file_path ) || empty( $file_type ) ) {
				return new \WP_Error( 'missing_argument', 'Cannot upload file. [Error: Mandatory file attributes not found]', array( 'status' => 400 ) );
			}

			if ( empty( $url ) || empty( $scope ) ) {
				return new \WP_Error( 'missing_argument', 'Cannot upload file. [Error: Mandatory MS Graph parameters are missing]', array( 'status' => 400 ) );
			}

			$endpoint_config = self::validate_endpoint( $url );

			if ( is_wp_error( $endpoint_config ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $endpoint_config->get_error_message() ) );
				return $endpoint_config;
			}

			// Never an app-only token: this route attaches the credentials of the caller, not those of the website.
			$access_token = Access_Token_Service::get_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $access_token->get_error_message() ) );
				return new \WP_Error( 'not_authorized', self::client_error_message( $access_token->get_error_message(), 'An access token for Microsoft 365 could not be obtained.' ), array( 'status' => 403 ) );
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
				return new \WP_Error( 'file_not_found', self::client_error_message( $warning, 'The uploaded file could not be read.' ), array( 'status' => 500 ) );
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Fetching from ' . $url );

			$response = wp_remote_request(
				$url,
				array(
					'method'      => 'PUT',
					'body'        => $upload_file_contents,
					'headers'     => $headers,
					'sslverify'   => $skip_ssl_verify,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				$warning = sprintf(
					'Failed to fetch from %s. [Error: %s]',
					$url,
					$response->get_error_message()
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> %s', __METHOD__, $warning ) );
				return new \WP_Error( 'fetch_error', self::client_error_message( $warning, 'The request to Microsoft 365 could not be completed.' ) );
			}

			$body   = wp_remote_retrieve_body( $response );
			$status = wp_remote_retrieve_response_code( $response );

			/**
			 * The upload either succeeded or it did not - the response of the destination is not returned,
			 * nor is its status, except to an administrator who needs it to find out what went wrong.
			 */
			if ( $status < 200 || $status > 299 ) {
				Log_Service::write_log(
					'WARN',
					sprintf( '%s -> Failed to upload to %s. [Status: %d] [Raw: %s]', __METHOD__, wp_parse_url( $url, PHP_URL_HOST ), $status, $body )
				);
				return new \WP_Error(
					'fetch_error',
					self::client_error_message(
						sprintf( 'Failed to upload to %s. [Status: %d]', wp_parse_url( $url, PHP_URL_HOST ), $status ),
						'The file could not be uploaded.'
					),
					self::client_error_data( 502, $body )
				);
			}

			return array( 'ok' => true );
		}

		/**
		 * Adds the redirection argument to the http request arguments.
		 *
		 * @param array  $args
		 * @param string $url
		 * @return array
		 */
		public static function add_http_request_args( $args, $url ) {
			$request_service   = Request_Service::get_instance();
			$request           = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$http_request_args = $request->get_item( 'http_request_args' );

			if ( ! is_array( $http_request_args ) ) {
				return $args;
			}

			foreach ( $http_request_args as $http_request_arg ) {

				if ( ! is_array( $http_request_arg ) || empty( $http_request_arg['key'] ) ) {
					continue;
				}

				/**
				 * Only the number of redirects may be overridden, and an empty url is not a wildcard.
				 */
				if ( strcasecmp( $http_request_arg['key'], 'redirection' ) !== 0 ) {
					Log_Service::write_log(
						'WARN',
						sprintf( '%s -> An app tried to override the outgoing request argument "%s", which is not allowed.', __METHOD__, $http_request_arg['key'] )
					);
					continue;
				}

				if ( empty( $http_request_arg['url'] ) ) {
					Log_Service::write_log(
						'WARN',
						sprintf( '%s -> An app tried to override an outgoing request argument for every destination, which is not allowed.', __METHOD__ )
					);
					continue;
				}

				if ( WordPress_Helpers::stripos( $url, $http_request_arg['url'] ) === false ) {
					continue;
				}

				$redirection = filter_var( $http_request_arg['value'], FILTER_VALIDATE_INT );

				if ( $redirection === false || $redirection < 0 ) {
					$redirection = 0;
				}

				$args['redirection'] = min( $redirection, 5 );
			}

			return $args;
		}

		/**
		 * Whether the access token provided was issued to the website itself instead of to a user.
		 *
		 * An access token with application-level permissions carries the roles granted to the app registration
		 * and no scopes, where a token issued for a user always carries the scopes consented for that user.
		 * Testing for both matters: a user can be assigned to an app role too, so roles alone do not identify
		 * an app-only token.
		 *
		 * @since   45.0
		 *
		 * @param   string $access_token
		 *
		 * @return  bool
		 */
		private static function token_is_app_only( $access_token ) {

			if ( empty( Access_Token_Service::get_application_roles( $access_token ) ) ) {
				return false;
			}

			$segments = explode( '.', strval( $access_token ) );

			if ( count( $segments ) !== 3 ) {
				return false;
			}

			$claims = json_decode( WordPress_Helpers::base64_url_decode( $segments[1] ) );

			return json_last_error() === JSON_ERROR_NONE && empty( $claims->scp );
		}

		/**
		 * Returns the detailed message when an administrator is asking and a generic one for anybody else.
		 *
		 * WPO365's diagnostics name the endpoint, the application permission or the app instance a request
		 * refers to, and reveal whether the app registration was granted a permission. That is exactly what an
		 * administrator needs to fix a configuration, and exactly what nobody else should be able to probe for
		 * - one scope at a time - to map what the website's credentials can do. The detailed message is always
		 * written to the WPO365 log, which only an administrator can read.
		 *
		 * @since   45.0
		 *
		 * @param   string $detail    The message for an administrator.
		 * @param   string $generic   The message for everybody else.
		 *
		 * @return  string
		 */
		private static function client_error_message( $detail, $generic ) {
			return Permissions_Helpers::user_is_admin( wp_get_current_user() ) ? $detail : $generic;
		}

		/**
		 * Returns the data of an error response, with the response of the destination included for an
		 * administrator only.
		 *
		 * That response is often the only thing that explains what Microsoft 365 objected to - a missing
		 * Power BI workspace access, or row-level security that requires an effective identity - so an
		 * administrator should see it. For anybody else it makes the outbound request readable, which is
		 * what PatchStack objected to.
		 *
		 * @since   45.0
		 *
		 * @param   int    $status  The HTTP status to report.
		 * @param   string $raw     The response of the destination, if any.
		 *
		 * @return  array
		 */
		private static function client_error_data( $status, $raw = null ) {
			$data = array( 'status' => $status );

			if ( ! empty( $raw ) && Permissions_Helpers::user_is_admin( wp_get_current_user() ) ) {
				$data['raw'] = $raw;
			}

			return $data;
		}

		/**
		 * Resolves the WPO365 app that sent the request, so that the app's configuration - and not the request
		 * itself - can decide whether application-level permissions may be used.
		 *
		 * @since   45.0
		 *
		 * @param   mixed $app_id   The app instance id as sent by the app, if any.
		 *
		 * @return  array|WP_Error|null  The app instance as an associative array, a WP_Error when the id does
		 *                               not match an app on this website, or null when no usable id was sent.
		 */
		private static function resolve_app_instance( $app_id ) {
			static $resolved = array();

			$app_id = is_numeric( $app_id ) ? intval( $app_id ) : 0;

			// An app makes several requests per page and they all refer to the same app instance.
			if ( $app_id > 0 && array_key_exists( $app_id, $resolved ) ) {
				return $resolved[ $app_id ];
			}

			if ( $app_id <= 0 ) {

				// -1 identifies a WPO365 app without a stored configuration: a deprecated [pintra] shortcode app,
				// the block editor's rewrite tool or a preview in the wizard.
				if ( $app_id !== -1 ) {
					Compatibility_Helpers::compat_warning(
						sprintf(
							'%s -> An app requested data from Microsoft 365 without identifying itself, so WPO365 cannot decide - based on how that app was configured - whether it may use application-level permissions, and falls back to the list of allowed endpoints instead. Please update all your premium WPO365 plugins to keep your site secure and compatible with future updates. If the request was sent by your own code rather than a WPO365 app, you can ignore this message.',
							__METHOD__
						)
					);
				}

				return null;
			}

			if ( ! class_exists( '\Wpo\Graph\Apps_Db' ) ) {
				return null;
			}

			$app_instance = Apps_Db::get_app_instance( $app_id );

			if ( is_wp_error( $app_instance ) || empty( $app_instance ) ) {
				$resolved[ $app_id ] = new \WP_Error(
					'not_found',
					self::client_error_message(
						sprintf( 'This website does not have an app with ID %d.', $app_id ),
						'The app that sent this request could not be found.'
					),
					array( 'status' => 404 )
				);

				return $resolved[ $app_id ];
			}

			// Continue with an associative array to avoid tripping the WordPress sniff for camelCase members.
			$app_instance        = json_decode( wp_json_encode( $app_instance ), true );
			$resolved[ $app_id ] = is_array( $app_instance ) ? $app_instance : null;

			return $resolved[ $app_id ];
		}

		/**
		 * Whether the administrator configured the app instance provided for application-level permissions.
		 *
		 * @since   45.0
		 *
		 * @param   array $app_instance
		 *
		 * @return  bool
		 */
		private static function app_instance_uses_app_only( $app_instance ) {
			return ! empty( $app_instance['appliedRequirements']['userRequirements']['appOnlyAccess'] );
		}

		/**
		 * Decides whether a request may use application-level permissions and, if so, resolves what is needed
		 * to obtain such an access token. Shared by proxy() and batch(), which - unlike get() - address a
		 * caller-supplied destination and therefore only know the administrator's allow-lists.
		 *
		 * @since   45.0
		 *
		 * @param   bool   $application        Whether the app asked for application-level permissions.
		 * @param   string $scope              The scope requested by the app.
		 * @param   bool   $app_only_allowed   Whether the allow-listed endpoint permits application-level permissions.
		 * @param   string $endpoint           The endpoint, used to explain a refusal to the administrator.
		 *
		 * @return  array|WP_Error  Array with "application", "scope", "permission" and "refused_reason", or a
		 *                          WP_Error when the scope is malformed.
		 */
		private static function resolve_app_only_context( $application, $scope, $app_only_allowed, $endpoint ) {
			$context = array(
				'application'    => false,
				'scope'          => '',
				'permission'     => null,
				'refused_reason' => '',
			);

			if ( ! $application ) {
				return $context;
			}

			if ( ! Options_Service::get_aad_option( 'use_app_only_token', true ) ) {
				$context['refused_reason'] = 'The app requested application-level permissions but WPO365 has not been configured to use an access token with application-level permissions. Go to WP Admin > WPO365 > Integration and review the settings in the section \'Application Access\'.';
				return $context;
			}

			if ( Permissions_Helpers::must_use_delegate_access_for_scope( $scope ) || ! $app_only_allowed ) {
				// Only report the administrator-controlled cause, not a scope that inherently requires delegated access.
				$context['refused_reason'] = $app_only_allowed ? '' : self::app_only_refused_warning( $endpoint );
				return $context;
			}

			$resolved = self::resolve_app_only_request( $scope );

			if ( is_wp_error( $resolved ) ) {

				if ( $resolved->get_error_code() !== 'not_authorized' ) {
					return $resolved;
				}

				$context['refused_reason'] = $resolved->get_error_message();
				return $context;
			}

			$context['application'] = true;
			$context['scope']       = $resolved[0];
			$context['permission']  = $resolved[1];

			return $context;
		}

		/**
		 * Resolves the scope requested by an app into the scope and the application permission needed to
		 * request an access token with application-level permissions, and verifies both against the
		 * administrator-defined allow-lists.
		 *
		 * @since   45.0
		 *
		 * @param   string $scope  The scope requested by the app e.g. https://graph.microsoft.com/Sites.Selected.
		 *
		 * @return  array|WP_Error  Array with the (app-only) scope and the application permission to assert -
		 *                          null for a .default scope - or a WP_Error with code "missing_argument" when
		 *                          the scope is malformed or "not_authorized" when it is not allow-listed.
		 */
		private static function resolve_app_only_request( $scope ) {
			$scope      = WordPress_Helpers::rtrim( WordPress_Helpers::trim( strval( $scope ) ), '/' );
			$scope_host = WordPress_Helpers::stripos( $scope, 'https://' ) !== false ? wp_parse_url( $scope, PHP_URL_HOST ) : 'graph.microsoft.com';
			$tld        = Options_Service::get_aad_option( 'tld' );
			$tld        = ! empty( $tld ) ? $tld : '.com';
			$scope_host = ! empty( $scope_host ) ? str_replace( '.com', $tld, $scope_host ) : '';

			if ( empty( $scope_host ) ) {
				return new \WP_Error( 'missing_argument', 'Scope does not resolve to a Microsoft 365 resource.', array( 'status' => 400 ) );
			}

			$scope_segments = explode( '/', $scope );
			$permission     = array_pop( $scope_segments );

			// A malformed (e.g. trailing-slash) scope must not silently skip the checks below.
			if ( empty( $permission ) ) {
				return new \WP_Error( 'missing_argument', 'Scope does not resolve to an application permission.', array( 'status' => 400 ) );
			}

			/**
			 * The resource is everything before the permission, not just the host: Power BI is identified by
			 * https://analysis.windows.net/powerbi/api, and asking Microsoft for https://analysis.windows.net
			 * instead fails with AADSTS500011 (resource principal not found).
			 */
			$resource       = implode( '/', $scope_segments );
			$resource       = WordPress_Helpers::stripos( $resource, 'https://' ) === 0 ? $resource : sprintf( 'https://%s', $scope_host );
			$app_only_scope = sprintf( '%s/.default', $resource );

			/**
			 * A .default scope yields an access token that carries every application permission granted to the
			 * app registration, so no individual permission can be asserted for it. Power BI depends on this.
			 * The resource itself is therefore allow-listed instead, on top of the destination check that
			 * validate_endpoint() already performed.
			 */
			if ( strcasecmp( $permission, '.default' ) === 0 ) {

				foreach ( self::allowed_app_only_audiences() as $allowed_audience ) {

					if ( strcasecmp( $scope_host, $allowed_audience ) === 0 ) {
						// Already a resource-qualified .default scope, so pass it on unchanged.
						return array( $scope, null );
					}
				}

				return new \WP_Error(
					'not_authorized',
					self::client_error_message(
						sprintf( 'The app requested an access token with application-level permissions for all permissions granted for "%s", which is not a resource WPO365 supports this for.', $scope_host ),
						'This request to Microsoft 365 is not allowed.'
					),
					array( 'status' => 403 )
				);
			}

			// Graph_Service::fetch() requests User.Read.All when an app asks for User.Read, so check what is actually requested.
			if ( strcasecmp( $permission, 'User.Read' ) === 0 ) {
				$permission = 'User.Read.All';
			}

			foreach ( self::allowed_app_only_permissions() as $allowed_permission ) {

				if ( strcasecmp( $permission, $allowed_permission ) === 0 ) {
					return array( $app_only_scope, $permission );
				}
			}

			return new \WP_Error(
				'not_authorized',
				self::client_error_message(
					sprintf( 'The app requested an access token with the application-level permission "%s", which is not allowed. Go to WP Admin > WPO365 > Integration and add that permission to the list of \'Allowed application permissions\' in the section \'Microsoft 365 Apps\', but only if apps on this website should be able to use it.', $permission ),
					'This request to Microsoft 365 is not allowed.'
				),
				array( 'status' => 403 )
			);
		}

		/**
		 * The application permissions that an app may request an access token for. The defaults are the
		 * permissions needed by the apps that WPO365 ships with and the administrator can add more.
		 *
		 * @since   45.0
		 *
		 * @return  array
		 */
		private static function allowed_app_only_permissions() {
			$permissions = array(
				'Sites.Selected',
				'Sites.Read.All',
				'User.Read.All',
				'Group.Read.All',
				'GroupMember.Read.All',
				'Files.Read.All',
				'Calendars.Read',
			);

			$configured = Options_Service::get_global_list_var( 'graph_allowed_app_only_permissions' );

			if ( is_array( $configured ) ) {

				foreach ( $configured as $permission ) {
					$permission = WordPress_Helpers::trim( strval( $permission ) );

					if ( ! empty( $permission ) ) {
						$permissions[] = $permission;
					}
				}
			}

			return $permissions;
		}

		/**
		 * The resources for which an app may request an access token that carries every granted application
		 * permission (a .default scope), because for those no individual permission can be asserted.
		 *
		 * @since   45.0
		 *
		 * @return  array
		 */
		private static function allowed_app_only_audiences() {
			$tld = Options_Service::get_aad_option( 'tld' );
			$tld = ! empty( $tld ) ? $tld : '.com';

			return array(
				sprintf( 'graph.microsoft%s', $tld ),
				'analysis.windows.net',
			);
		}

		/**
		 * Returns the warning that explains why an app-only request was downgraded to delegated (user) access.
		 *
		 * Without it the administrator only sees the error that Microsoft returns for the delegated request that
		 * follows (typically AADSTS65001, about a missing consent), which says nothing about the actual cause.
		 *
		 * @since   45.0
		 *
		 * @param   string $endpoint   The endpoint for which application-level permissions are not allowed.
		 *
		 * @return  string
		 */
		private static function app_only_refused_warning( $endpoint ) {
			return sprintf(
				'Application-level permissions are not allowed for the endpoint "%s", so the request was sent with the permissions of the (signed-in) user instead. Go to WP Admin > WPO365 > Integration and check the box next to that endpoint in the list of \'Allowed endpoints\', or re-apply the requirements of the app that sent the request.',
				$endpoint
			);
		}

		/**
		 * Validates the endpoint provided against the list of allowed endpoints and returns a WP_Error if the
		 * endpoint is not allow-listed or else a boolean value indicating whether application-level permissions
		 * are allowed.
		 *
		 * @since   17.0
		 * @since   45.0    Support for the option to allow apps to request any endpoint (graph_allow_all_endpoints)
		 *                  has been discontinued: that option also disabled this check for routes that attach the
		 *                  website's own Microsoft 365 credentials to an outgoing request.
		 *
		 * @param   string $endpoint   The endpoint to validate.
		 *
		 * @return  WP_Error|bool       Returns a WP_Error if the endpoint is not allowed or else a boolean value indicating whether application-level permissions are allowed.
		 */
		private static function validate_endpoint( $endpoint ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( WordPress_Helpers::stripos( $endpoint, '/' ) === 0 ) {
				$tld      = Options_Service::get_aad_option( 'tld' );
				$tld      = ! empty( $tld ) ? $tld : '.com';
				$endpoint = sprintf( 'https://graph.microsoft%s/_%s', $tld, $endpoint );
			}

			$endpoint = str_replace( '/v1.0/', '/_/', $endpoint );
			$endpoint = str_replace( '/beta/', '/_/', $endpoint );

			$allowed_endpoints_and_permissions = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );

			/**
			 * The most specific entry decides, not the first one that happens to match: an entry for the whole
			 * of Microsoft Graph must not shadow an entry for e.g. /sites that the administrator did allow
			 * application-level permissions for. Specificity is the length of the allow-listed path, so an
			 * entry without a path - which matches every path on its host - always loses. Entries that are
			 * equally specific (a duplicated row) may not take application-level permissions away from
			 * each other.
			 */
			$best_match_length = -1;
			$app_only_allowed  = false;

			foreach ( $allowed_endpoints_and_permissions as $allowed_endpoint_config ) {

				$allowed_endpoint = ! empty( $allowed_endpoint_config['key'] ) ? $allowed_endpoint_config['key'] : '';
				$allowed_endpoint = str_replace( '/v1.0/', '/_/', $allowed_endpoint );
				$allowed_endpoint = str_replace( '/beta/', '/_/', $allowed_endpoint );

				if ( ! Url_Helpers::endpoint_matches( $endpoint, $allowed_endpoint ) ) {
					continue;
				}

				$allowed_path = WordPress_Helpers::rtrim( strval( wp_parse_url( $allowed_endpoint, PHP_URL_PATH ) ), '/' );
				$match_length = strlen( $allowed_path );
				$allows       = isset( $allowed_endpoint_config['boolVal'] ) && $allowed_endpoint_config['boolVal'] === true;

				if ( $match_length > $best_match_length ) {
					$best_match_length = $match_length;
					$app_only_allowed  = $allows;
				} elseif ( $match_length === $best_match_length ) {
					$app_only_allowed = $app_only_allowed || $allows;
				}
			}

			if ( $best_match_length < 0 ) {
				return new \WP_Error(
					'not_authorized',
					self::client_error_message(
						sprintf( 'The endpoint "%s" is not allow-listed. Go to WP Admin > WPO365 > Integration and add the endpoint to the list of \'Allowed endpoints\' in the section \'Microsoft 365 Apps\'.', $endpoint ),
						'This request to Microsoft 365 is not allowed.'
					),
					array( 'status' => 403 )
				);
			}

			return $app_only_allowed;
		}
	}
}
