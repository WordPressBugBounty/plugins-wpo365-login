<?php

namespace Wpo\Services;

use Wpo\Core\Compatibility_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Log_Service' ) ) {

	class Log_Service {


		const VERBOSE     = 0;
		const INFORMATION = 1;
		const WARNING     = 2;
		const ERROR       = 3;
		const CRITICAL    = 4;

		/**
		 * Writes a message to the WordPress debug.log file
		 *
		 * @since   1.0
		 *
		 * @param   string $level The level to log e.g. DEBUG or ERROR.
		 * @param   string $log Message to write to the log.
		 * @param   array  $props
		 */
		public static function write_log( $level, $log, $props = array() ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request_id      = $request->current_request_id();
			$request_log     = $request->get_item( 'request_log' );

			if ( ! $request_log ) {

				if ( $level === 'ERROR' ) {
					$body = is_array( $log ) || is_object( $log ) ? print_r( $log, true ) : $log; // phpcs:ignore
					error_log( '[WPO365] Attempting to write to log before it has been initialized: ' . $body ); // phpcs:ignore
				}

				return;
			}

			if ( $level === 'DEBUG' && ( ! $request_log || $request_log['debug_log'] === false ) ) {
				return;
			}

			$body = is_array( $log ) || is_object( $log ) ? print_r( $log, true ) : $log; // phpcs:ignore
			$now  = \DateTime::createFromFormat( 'U.u', number_format( microtime( true ), 6, '.', '' ) );

			if ( function_exists( 'wp_timezone_string' ) ) {
				$wp_time_zone_str = wp_timezone_string();
				$time_zone        = new \DateTimeZone( $wp_time_zone_str );
				$now->setTimezone( $time_zone );
			}

			$log_item = array(
				'body'        => $body,
				'now'         => $now->format( 'm-d-Y H:i:s.u' ),
				'time'        => time(),
				'level'       => $level,
				'request_id'  => $request_id,
				'php_version' => \phpversion(),
				'props'       => $props,
			);

			$request_log['log'][] = $log_item;
			$request->set_item( 'request_log', $request_log );

			if ( $level === 'ERROR' ) {
				$cached_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );
				$cached_errors = is_array( $cached_errors ) ? $cached_errors : array();
				\array_unshift( $cached_errors, $log_item );
				$cached_errors = array_slice( $cached_errors, 0, 10 );
				Wpmu_Helpers::mu_set_transient( 'wpo365_errors', $cached_errors, 259200 );
			}
		}

		/**
		 * Writes the log file to the defined output stream
		 *
		 * @since 7.11
		 *
		 * @return void
		 */
		public static function flush_log() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request_log     = $request->get_item( 'request_log' );

			// Nothing to flush
			if ( empty( $request_log['log'] ) ) {
				return;
			}

			// Flush to ApplicationInsights
			$log_location           = Options_Service::get_global_string_var( 'debug_log_location', false );
			$ai_instrumentation_key = Options_Service::get_global_string_var( 'ai_instrumentation_key', false );

			if ( ! empty( $log_location ) && ! empty( $ai_instrumentation_key ) ) {
				if ( $log_location === 'remotely' || $log_location === 'both' ) {
					self::flush_log_to_ai();

					if ( $log_location === 'remotely' ) {
						$request->remove_item( 'request_log' );
						return;
					}
				}
			}

			// Save the last 500 entries
			$wpo365_log = Wpmu_Helpers::mu_get_transient( 'wpo365_debug_log' );

			if ( empty( $wpo365_log ) ) {
				$wpo365_log = array();
			}

			$wpo365_log = array_merge( $wpo365_log, $request_log['log'] );
			$count      = count( $wpo365_log );

			if ( $count > 500 ) {
				$wpo365_log = array_slice( $wpo365_log, ( $count - 500 ) );
			}

			Wpmu_Helpers::mu_set_transient( 'wpo365_debug_log', $wpo365_log, 604800 );

			// Still also write it to default debug output
			if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) === true ) {

				foreach ( $request_log['log'] as $item ) {
					$log_message = '[' . $item['now'] . ' | ' . $item['request_id'] . '] ' . $item['level'] . ' ( ' . $item['php_version'] . ' ): ' . $item['body'];
					error_log( $log_message ); // phpcs:ignore
				}
			}

			$request->remove_item( 'request_log' );
		}

		/**
		 * Handler that can be hooked into http_api_curl action. The results will be
		 * streamed to the WPO365 debug output on request-shutdown (see Request_Service::shutdown).
		 *
		 * @since 28.x
		 *
		 * @param mixed $handle
		 * @param mixed $parsed_args
		 * @param mixed $url
		 * @return void
		 */
		public static function enable_curl_logging( $handle, $parsed_args, $url ) { // phpcs:ignore
			curl_setopt( $handle, CURLOPT_VERBOSE, true ); // phpcs:ignore
            $curl_log = fopen( 'php://temp', 'rw' ); // phpcs:ignore

			if ( $curl_log !== false ) {
				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$request->set_item( 'curl_log', $curl_log );
				curl_setopt( $handle, CURLOPT_STDERR, $curl_log ); // phpcs:ignore
			}
		}

		/**
		 * Logs a message to a custom log file in the plugin's root folder.
		 *
		 * @param mixed  $message Message to be logged.
		 * @param string $log Name of the log e.g. scim.log or sync.log.
		 * @return void
		 */
		public static function write_to_custom_log( $message, $log ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$custom_logs     = $request->get_item( 'custom_logs' );

			if ( $custom_logs === false ) {
				$custom_logs = array();

				if ( Options_Service::get_global_boolean_var( 'enable_custom_log_sync' ) ) {
					$custom_logs[] = 'sync';
				}

				if ( Options_Service::get_global_boolean_var( 'enable_custom_log_scim' ) ) {
					$custom_logs[] = 'scim';
				}

				$request->set_item( 'custom_logs', $custom_logs );
			}

			if ( ! in_array( $log, $custom_logs, true ) ) {
				return;
			}

			$destination = sprintf( '%s%s.log', plugin_dir_path( __DIR__ ), $log );
			$body 		 	 = is_array( $message ) || is_object( $message ) ? print_r( $message, true ) : $message; // phpcs:ignore
			$now         = \DateTime::createFromFormat( 'U.u', number_format( microtime( true ), 6, '.', '' ) );

			if ( function_exists( 'wp_timezone_string' ) ) {
				$wp_time_zone_str = wp_timezone_string();
				$time_zone        = new \DateTimeZone( $wp_time_zone_str );
				$now->setTimezone( $time_zone );
			}

			$message = sprintf( '%s | %s', $now->format( 'm-d-Y H:i:s.u' ), $body ) . PHP_EOL;

			if ( ! error_log( $message, 3, $destination ) ) { // phpcs:ignore
				Compatibility_Helpers::compat_warning( sprintf( '%s -> You have enabled custom logging for "%s". However, WPO365 failed to create the log and / or write to it [destination: %s]. Please fix this or disable the custom logging on the plugin\'s "Debug" configuration page.', __METHOD__, $log, $destination ) );
			}
		}

		/**
		 * Flushes the current request log buffer to ApplicationInsights as trace messages.
		 *
		 * @since   10.1
		 *
		 * @return  void
		 */
		private static function flush_log_to_ai() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request_log     = $request->get_item( 'request_log' );

			$ai_items = array_map( '\Wpo\Services\Log_Service::to_ai', $request_log['log'] );
			$body     = wp_json_encode( $ai_items, JSON_UNESCAPED_UNICODE );

			$headers_array = array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json; charset=utf-8',
				'Expect'       => '',
			);

			$response = wp_remote_post(
				'https://dc.services.visualstudio.com/v2/track',
				array(
					'timeout'   => 15,
					'blocking'  => false,
					'headers'   => $headers_array,
					'body'      => $body,
					'sslverify' => false,
				)
			);
		}

		/**
		 * Simple switch to be used from array_map
		 *
		 * @since   10.1
		 *
		 * @param   array $log_item Associative array with the information to be logged.
		 *
		 * @return  array Associative array formatted according to Microsoft.ApplicationInsights.Message / Microsoft.ApplicationInsights.Exception requirement
		 */
		private static function to_ai( $log_item ) {

			if ( $log_item['level'] === 'ERROR' ) {
				return self::to_ai_exception( $log_item );
			}

			return self::to_ai_message( $log_item );
		}

		/**
		 * Helper to convert a WPO365 log item into an AI tracking message (trace).
		 *
		 * @since   10.1
		 *
		 * @param   array $log_item Associative array with the information to be logged.
		 *
		 * @return  array Associative array formatted according to Microsoft.ApplicationInsights.Message requirement
		 */
		private static function to_ai_message( $log_item ) {
			$ai_instrumentation_key = Options_Service::get_global_string_var( 'ai_instrumentation_key', false );

			if ( $log_item['level'] === 'ERROR' ) {
				$level = self::ERROR;
			} elseif ( $log_item['level'] === 'WARN' ) {
				$level = self::WARNING;
			} else {
				$level = self::INFORMATION;
			}

			return array(
				'data' => array(
					'baseData' => array(
						'message'       => $log_item['body'],
						'ver'           => 2,
						'severityLevel' => $level,
						'properties'    => self::get_ai_props( $log_item ),
					),
					'baseType' => 'MessageData',
				),
				'ver'  => 1,
				'time' => gmdate( 'c', $log_item['time'] ) . 'Z',
				'name' => 'Microsoft.ApplicationInsights.Message',
				'iKey' => $ai_instrumentation_key,
			);
		}

		/**
		 * Helper to convert a WPO365 log item into an AI tracking message (trace).
		 *
		 * @since   10.1
		 *
		 * @param   array $log_item Associative array with the information to be logged.
		 *
		 * @return  array Associative array formatted according to Microsoft.ApplicationInsights.Exception requirement
		 */
		private static function to_ai_exception( $log_item ) {
			$ai_instrumentation_key = Options_Service::get_global_string_var( 'ai_instrumentation_key', false );

			return array(
				'data' => array(
					'baseData' => array(
						'ver'        => 2,
						'properties' => self::get_ai_props( $log_item ),
						'exceptions' => array(
							array(
								'typeName'     => 'Error',
								'message'      => $log_item['body'],
								'hasFullStack' => false,
							),
						),
					),
					'baseType' => 'ExceptionData',
				),
				'ver'  => 1,
				'time' => gmdate( 'c', $log_item['time'] ) . 'Z',
				'name' => 'Microsoft.ApplicationInsights.Exception',
				'iKey' => $ai_instrumentation_key,
			);
		}

		/**
		 * Adds the custom properties being logged as custom properties for
		 * ApplicationInsights.
		 *
		 * @since   23.0
		 *
		 * @param   mixed $log_item
		 * @return  array
		 */
		private static function get_ai_props( $log_item ) {
			$props = array(
				'phpVersion'   => $log_item['php_version'],
				'wpoVersion'   => $GLOBALS['WPO_CONFIG']['version'],
				'wpoEdition'   => implode( ',', $GLOBALS['WPO_CONFIG']['extensions'] ),
				'wpoRequestId' => $log_item['request_id'],
				'wpoHost'      => $GLOBALS['WPO_CONFIG']['url_info']['host'],
			);

			if ( ! empty( $log_item['props'] ) && is_array( $log_item['props'] ) ) {

				foreach ( $log_item['props'] as $key => $value ) {
					$props[ $key ] = $value;
				}
			}

			return $props;
		}
	}
}
