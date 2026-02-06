<?php

namespace Wpo\Insights;

use WP_Error;
use Wpo\Core\Event_User;
use Wpo\Core\Event;
use Wpo\Core\User;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Error_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

if ( ! class_exists( '\Wpo\Insights\Event_Service' ) ) {

	class Event_Service {

		/**
		 * Add a new event to the memoized collection.
		 *
		 * @since 27.0
		 *
		 * @param Event $event
		 * @return void
		 */
		public static function add_event( $event ) {
			if ( ! Options_Service::get_global_boolean_var( 'insights_enabled' ) ) {
				return;
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$events          = $request->get_item( 'events' );

			if ( ! is_array( $events ) ) {
				$events = array( $event );
			} else {
				$events[] = $event;
			}

			$request->set_item( 'events', $events );
		}

		/**
		 * Creates a new wpo365/user/created event (success).
		 *
		 * @param int $wp_user_id
		 *
		 * @return void
		 */
		public static function user_created__handler( $wp_user_id = 0 ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_usr      = $request->get_item( 'wpo_usr' );
			$is_user_sync = $request->get_item( 'user_sync' );
			$is_scim      = $request->get_item( 'scim' );
			$data         = '';

			if ( $is_user_sync ) {
				$category = 'SYNC';
			} elseif ( $is_scim ) {
				$category = 'SCIM';
				$data     = self::get_scim_attributes();
			} elseif ( ! empty( $wpo_usr ) ) {
				$category = 'SSO';
			} else {
				$category = 'WP';
			}

			$user  = self::get_event_user( $wp_user_id, $wpo_usr );
			$event = new Event( 'wpo365/user/created', $category, $user, $data );
			self::add_event( $event );
		}

		/**
		 * Creates a new wpo365/user/created event (fail).
		 *
		 * @param WP_Error $error
		 *
		 * @return void
		 */
		public static function user_created_fail__handler( $error ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_usr      = $request->get_item( 'wpo_usr' );
			$is_user_sync = $request->get_item( 'user_sync' );
			$is_scim      = $request->get_item( 'scim' );
			$data         = '';

			if ( $is_user_sync ) {
				$category = 'SYNC';
			} elseif ( $is_scim ) {
				$category = 'SCIM';
				$data     = self::get_scim_attributes();
			} else {
				$category = 'SSO';
			}

			if ( is_wp_error( $error ) ) {
				$error_message = $error->get_error_message();
			} elseif ( is_string( $error ) ) {
				$error_message = $error;
			} else {
				$error_message = 'Unknown error occurred';
			}

			$user  = self::get_event_user( 0, $wpo_usr );
			$event = new Event( 'wpo365/user/created', $category, $user, $data, $error_message, 'ERROR', 'NOK' );
			self::add_event( $event );

			Options_Service::get_global_boolean_var( 'insights_alerts_user_creation' ) && do_action( 'wpo365/insights/notify', $error, $category );
		}

		/**
		 * Creates a new wpo365/user/loggedin event (success).
		 *
		 * @param string $logged_in_cookie
		 * @param int    $expire
		 * @param int    $expiration
		 * @param int    $wp_user_id
		 * @param string $scheme
		 * @param string $token
		 *
		 * @return void
		 */
		public static function user_loggedin__handler( $logged_in_cookie, $expire, $expiration, $wp_user_id, $scheme, $token ) { // phpcs:ignore
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_usr = $request->get_item( 'wpo_usr' );

			if ( ! empty( $wpo_usr ) ) {
				$category = 'SSO';
			} else {
				$category = 'WP';
			}

			$user  = self::get_event_user( $wp_user_id, $wpo_usr );
			$event = new Event( 'wpo365/user/loggedin', $category, $user );
			self::add_event( $event );
		}

		/**
		 * Creates a new wpo365/user/loggedin event (fail).
		 *
		 * @param string $error
		 *
		 * @return void
		 */
		public static function user_loggedin_fail__handler( $error ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$wpo_user        = $request->get_item( 'wpo_usr' );

			$sso_loggedin_error_codes = array(
				Error_Service::AADAPPREG_ERROR,
				Error_Service::CHECK_LOG,
				Error_Service::DEACTIVATED,
				Error_Service::ID_TOKEN_ERROR,
				Error_Service::ID_TOKEN_AUD,
				Error_Service::NOT_CONFIGURED,
				Error_Service::NOT_FROM_DOMAIN,
				Error_Service::NOT_IN_GROUP,
				Error_Service::SAML2_ERROR,
				Error_Service::USER_NOT_FOUND,
				Error_Service::TAMPERED_WITH,
			);

			if ( in_array( $error, $sso_loggedin_error_codes, true ) ) {
				$category = 'SSO';
			} else {
				$category = 'WP';
			}

			$user  = self::get_event_user( 0, $wpo_user );
			$event = new Event( 'wpo365/user/loggedin', $category, $user, null, $error, 'ERROR', 'NOK' );
			self::add_event( $event );

			Options_Service::get_global_boolean_var( 'insights_alerts_login' ) && do_action( 'wpo365/insights/notify', $error, $category );
		}

		/**
		 * Helper for the authenticate filter.
		 *
		 * @param null|WP_user|WP_error $user
		 * @return mixed
		 */
		public static function authenticate__handler( $user, $user_name, $password ) { // phpcs:ignore

			if ( is_wp_error( $user ) ) {
				self::user_loggedin_fail__handler( $user->get_error_message() );
			}

			return $user;
		}

		/**
		 * Helper for the wp_login_failer action.
		 *
		 * @param string   $user_name
		 * @param WP_Error $wp_error
		 * @return void
		 */
		public static function wp_login_failed__handler( $user_name, $wp_error ) {
			$error = is_wp_error( $wp_error ) && is_string( $wp_error->get_error_message() ) ? wp_strip_all_tags( $wp_error->get_error_message() ) : 'Unknown login error';

			if ( ! empty( $user_name ) ) {
				$error = sprintf( '%s [username: %s]', $error, sanitize_text_field( $user_name ) );
			}

			self::user_loggedin_fail__handler( $error );
		}

		public static function user_updated__handler( $wp_user_id ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_usr      = $request->get_item( 'wpo_usr' );
			$is_user_sync = $request->get_item( 'user_sync' );
			$is_scim      = $request->get_item( 'scim' );
			$data         = '';

			if ( $is_user_sync ) {
				$category = 'SYNC';
			} elseif ( $is_scim ) {
				$category = 'SCIM';
				$data     = self::get_scim_attributes();
			} elseif ( ! empty( $wpo_usr ) ) {
				$category = 'SSO';
			} else {
				$category = 'WP';
			}

			$user  = self::get_event_user( $wp_user_id, $wpo_usr );
			$event = new Event( 'wpo365/user/updated', $category, $user, $data );
			self::add_event( $event );
		}

		public static function user_updated_fail__handler( $wp_user_id, $error ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_usr      = $request->get_item( 'wpo_usr' );
			$is_user_sync = $request->get_item( 'user_sync' );
			$is_scim      = $request->get_item( 'scim' );
			$data         = null;

			if ( $is_user_sync ) {
				$category = 'SYNC';
			} elseif ( $is_scim ) {
				$category = 'SCIM';
				$data     = self::get_scim_attributes();
			} elseif ( ! empty( $wpo_usr ) ) {
				$category = 'SSO';
			} else {
				$category = 'WP';
			}

			$user  = self::get_event_user( $wp_user_id, $wpo_usr );
			$event = new Event( 'wpo365/user/updated', $category, $user, $data, $error, 'ERROR', 'NOK' );
			self::add_event( $event );
		}

		public static function mail_sent__handler( $log_message ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$mail_log_id     = $request->get_item( 'mail_log_id' );
			$data            = ! empty( $mail_log_id ) ? array( 'id' => '|' . $mail_log_id . '|' ) : null;
			$user            = self::get_event_user();
			$event           = new Event( 'wpo365/mail/sent', 'MAIL', $user, $data, $log_message );
			self::add_event( $event );
		}

		public static function mail_sent_fail__handler( $log_message ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$mail_log_id     = $request->get_item( 'mail_log_id' );
			$data            = ! empty( $mail_log_id ) ? array( 'id' => '|' . $mail_log_id . '|' ) : null;
			$user            = self::get_event_user();
			$event           = new Event( 'wpo365/mail/sent', 'MAIL', $user, $data, $log_message, 'ERROR', 'NOK' );
			self::add_event( $event );

			Options_Service::get_global_boolean_var( 'insights_alerts_mail' ) && do_action( 'wpo365/insights/notify', $log_message, 'MAIL' );
		}

		public static function notification_sent__handler( $log_message, $category = 'ALERT', $data = array() ) {
			$user  = self::get_event_user();
			$event = new Event( 'wpo365/alert/submitted', $category, $user, $data, $log_message );
			self::add_event( $event );
		}

		public static function notification_sent_fail__handler( $log_message, $category = 'ALERT', $data = array() ) {
			$user  = self::get_event_user();
			$event = new Event( 'wpo365/alert/submitted', $category, $user, $data, $log_message, 'ERROR', 'NOK' );
			self::add_event( $event );
		}

		/**
		 * Saves the memoized events to the events database table.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function flush_events() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$events          = $request->get_item( 'events' );

			if ( empty( $events ) ) {
				return; // Nothing to flush
			}

			self::ensure_events_table();

			global $wpdb;

			$table_name = self::get_events_table_name();

			foreach ( $events as $event ) {

				if ( is_array( $event->data ) && ! empty( $event->data['id'] ) ) {
					$like = '%' . $wpdb->esc_like( $event->data['id'] ) . '%';

					$record_id_var = $wpdb->get_var( // phpcs:ignore
						"SELECT `id` FROM `$table_name` WHERE `event_data` LIKE '$like' LIMIT 1" // phpcs:ignore
					);

					if ( is_numeric( $record_id_var ) ) {
						$record_id = (int) $record_id_var;
					}

					if ( ! empty( $wpdb->last_error ) ) {
						Log_Service::write_log( 'ERROR', sprintf( '%s -> An error occurred when trying add a new event [error: %s]', __METHOD__, $wpdb->last_error ) );
					}
				}

				$data = array(
					'event_action'    => $event->action,
					'event_category'  => $event->category,
					'event_data'      => ! empty( $event->data ) && ! is_string( $event->data ) ? wp_json_encode( $event->data ) : $event->data,
					'event_error'     => $event->error,
					'event_level'     => $event->level,
					'event_status'    => $event->status,
					'event_timestamp' => WordPress_Helpers::time_zone_corrected_formatted_date( $event->timestamp ),
					'event_user'      => wp_json_encode( $event->user ),
				);

				if ( isset( $record_id ) ) { // Update an existing record.
					$wpdb->update( // phpcs:ignore
						$table_name,
						$data,
						array( 'id' => $record_id )
					);
				} else { // Insert a new record.
					$wpdb->insert( // phpcs:ignore
						$table_name,
						$data
					);
				}

				if ( ! empty( $wpdb->last_error ) ) {
					Log_Service::write_log( 'ERROR', sprintf( '%s -> An error occurred when trying add a new event [error: %s]', __METHOD__, $wpdb->last_error ) );
				}

				// After every 100th event, check if any older entries needs deleting.
				if ( $wpdb->insert_id % 100 === 0 ) {
					self::insights_retention();
				}
			}

			$events = $request->remove_item( 'events' );
		}

		/**
		 *
		 *
		 * @param string $period
		 * @return WP_Error|void
		 */
		public static function get_insights_summary( $period, $group ) {
			$periods = array( 'TODAY', 'WTD', 'MTD', 'YTD' );

			if ( empty( $period ) || ! in_array( $period, $periods, true ) ) {
				$error = sprintf( '%s -> Value %s for argument "period" is not supported', __METHOD__, $period );
				Log_Service::write_log( 'ERROR', $error );
				return new WP_Error( 'ArgumentException', $error );
			}

			self::ensure_events_table();

			global $wpdb;

			$table_name = self::get_events_table_name();

			if ( $period === 'WTD' ) {
				$date_clause = 'WEEK(`event_timestamp`) = WEEK(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} elseif ( $period === 'MTD' ) {
				$date_clause = 'MONTH(`event_timestamp`) = MONTH(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} elseif ( $period === 'YTD' ) {
				$date_clause = 'YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} else {
				$date_clause = 'DATE(`event_timestamp`) = CURDATE()';
			}

			$actions = array();

			if ( $group === 'mail' ) {
				$actions[] = "`event_action` = 'wpo365/mail/sent'";
			}

			if ( $group === 'login' ) {
				$actions[] = "`event_action` = 'wpo365/user/loggedin'";
			}

			if ( $group === 'users' ) {
				$actions[] = "`event_action` = 'wpo365/user/created' OR `event_action` = 'wpo365/user/updated'";
			}

			if ( $group === 'alerts' ) {
				$actions[] = "`event_action` = 'wpo365/alert/submitted'";
			}

			$action_clause = implode( ' OR ', $actions );

			$query = sprintf(
				'SELECT `event_action`, `event_status`, `event_category`, COUNT(*) AS `event_count` FROM `%s` WHERE %s AND ( %s ) GROUP BY `event_action`, `event_category`, `event_status`',
				$table_name,
				$date_clause,
				$action_clause
			);

			$rows = $wpdb->get_results( $query ); // phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				$error = sprintf( '%s -> An error occurred when trying retrieve insights summary [error: %s]', __METHOD__, $wpdb->last_error );
				Log_Service::write_log( 'ERROR', $error );
				return false;
			}

			return $rows;
		}

		public static function get_insights( $action, $category, $status, $period, $start_row ) {
			$periods = array( 'TODAY', 'WTD', 'MTD', 'YTD' );

			if ( empty( $period ) || ! in_array( $period, $periods, true ) ) {
				$error = sprintf( '%s -> Value %s for argument "period" is not supported', __METHOD__, $period );
				Log_Service::write_log( 'ERROR', $error );
				return new WP_Error( 'ArgumentException', $error );
			}

			self::ensure_events_table();

			global $wpdb;

			$table_name = self::get_events_table_name();

			if ( $period === 'WTD' ) {
				$where_clause = 'WEEK(`event_timestamp`) = WEEK(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} elseif ( $period === 'MTD' ) {
				$where_clause = 'MONTH(`event_timestamp`) = MONTH(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} elseif ( $period === 'YTD' ) {
				$where_clause = 'YEAR(`event_timestamp`) = YEAR(CURDATE())';
			} else {
				$where_clause = 'DATE(`event_timestamp`) = CURDATE()';
			}

			$where_clause = sprintf(
				"%s AND `event_action` = '%s' AND `event_category` = '%s' AND `event_status` = '%s' ",
				$where_clause,
				$action,
				$category,
				$status
			);

			$rows = $wpdb->get_results( "SELECT * FROM `$table_name` WHERE $where_clause ORDER BY `event_timestamp` DESC LIMIT 25 OFFSET $start_row" ); // phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				$error = sprintf( '%s -> An error occurred when trying retrieve insights [error: %s]', __METHOD__, $wpdb->last_error );
				Log_Service::write_log( 'ERROR', $error );
				return new WP_Error( 'DbException', $error );
			}

			return $rows;
		}

		/**
		 * Truncates the insights data table.
		 *
		 * @return WP_Error|true
		 */
		public static function truncate_insights_data() {
			global $wpdb;

			$table_name = self::get_events_table_name();

			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) { // phpcs:ignore
				$error = sprintf( '%s -> Could not truncate table %s because the table was not found', __METHOD__, $table_name );
				Log_Service::write_log( 'WARN', $error );
				return new WP_Error( 'DbTableNotFoundException', $error );
			}

			$wpdb->query( "TRUNCATE TABLE $table_name" ); // phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				$error = sprintf( '%s -> An error occurred when trying to truncate the insights table [error: %s]', __METHOD__, $wpdb->last_error );
				Log_Service::write_log( 'ERROR', $error );
				return new WP_Error( 'DbTruncateException', $error );
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Truncated the insights table successfully' );
			return true;
		}

		/**
		 * Applies the retention rule (delete entries where the timestamp's year is less than or equal to last year).
		 *
		 * @since 38.0
		 *
		 * @return void
		 */
		private static function insights_retention() {
			$timezone = wp_timezone();
			$datetime = new \DateTime( 'now', $timezone );
			$year     = (int) $datetime->format( 'Y' );

			$current_insights_year = get_site_option( 'wpo365_current_insights_year' );

			// Bail if entries from before the current year have already been deleted.
			if ( $current_insights_year === $year ) {
				return;
			}

			global $wpdb;

			$table_name = self::get_events_table_name();

			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) { // phpcs:ignore
				Log_Service::write_log( 'WARN', sprintf( '%s -> Failed to apply rentention rules to table %s because the table was not found', __METHOD__, $table_name ) );
				return;
			}

			$wpdb->query( // phpcs:ignore
				$wpdb->prepare(
					'DELETE FROM %i WHERE YEAR( `event_timestamp` ) <= YEAR(CURDATE()) - 1',
					$table_name
				)
			);

			$db_last_error = $wpdb->last_error;

			if ( ! empty( $db_last_error ) ) {
				Log_Service::write_log(
					'ERROR',
					sprintf(
						'%s -> An error occurred when trying to apply the rentention rules to table %s [error: %s]',
						__METHOD__,
						$table_name,
						$db_last_error
					)
				);
				return;
			}

			update_site_option( 'wpo365_current_insights_year', $year );
		}

		/**
		 * Ensures the Event table has been created.
		 *
		 * @return bool
		 */
		private static function ensure_events_table() {
			global $wpdb;

			$table_name = self::get_events_table_name();

			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) { // phpcs:ignore
				return true;
			}

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE if NOT EXISTS $table_name(
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	event_action TEXT NOT null,
	event_category TEXT NOT null,
	event_data LONGTEXT,
	event_error TEXT,
	event_level TEXT,
	event_status TEXT NOT null,
	event_timestamp DATETIME default null,
	event_user LONGTEXT
) $charset_collate;";

				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $sql );

			if ( ! empty( $wpdb->last_error ) ) {
				Log_Service::write_log( 'ERROR', sprintf( '%s -> An error occurred when trying to create the wpo365_events table [error: %s]', __METHOD__, $wpdb->last_error ) );
				return false;
			}

				return true;
		}

				/**
				 * Helper method to centrally provide the custom WordPress table name.
				 *
				 * @since 3.0
				 *
				 * @return string
				 */
		private static function get_events_table_name() {
			global $wpdb;

			if ( Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin() ) {
				return $wpdb->prefix . 'wpo365_events';
			}

					return $wpdb->base_prefix . 'wpo365_events';
		}

				/**
				 * Helper to create a small info object with user details from both WP and Entra.
				 *
				 * @param int  $wp_user_id
				 * @param User $wpo_user
				 *
				 * @return Event_User
				 */
		private static function get_event_user( $wp_user_id = 0, $wpo_user = null ) {
			$user = new Event_User();

			if ( Options_Service::get_global_boolean_var( 'insights_anonymized' ) ) {
				return $user;
			}

			if ( $wp_user_id > 0 ) {
				$user->wp_user_id = $wp_user_id;

				$wp_user = get_user_by( 'ID', $wp_user_id );

				if ( ! empty( $wp_user ) ) {

					if ( ! empty( $wp_user->user_login ) ) {
						$user->wp_user_login = $wp_user->user_login;
					}

					if ( ! empty( $wp_user->display_name ) ) {
						$user->display_name = $wp_user->display_name;
					}

					if ( ! empty( $wp_user->user_email ) ) {
						$user->mail = $wp_user->user_email;
					}
				}
			}

			if ( ! empty( $wpo_user ) ) {

				if ( empty( $user->mail ) && ! empty( $wpo_user->email ) ) {
						$user->mail = $wpo_user->email;
				}

				if ( ! empty( $wpo_user->oid ) ) {
					$user->oid = $wpo_user->oid;
				}

				if ( empty( $user->display_name ) && ! empty( $wpo_user->full_name ) ) {
					$user->display_name = $wpo_user->full_name;
				}
			}

			return $user;
		}

				/**
				 * Tries to get SCIM attributes from current context and return those as JSON.
				 *
				 * @since 31.1
				 *
				 * @return string
				 */
		private static function get_scim_attributes() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$scim_attributes = $request->get_item( 'scim_attributes' );

			if ( is_array( $scim_attributes ) ) {
				$as_json = wp_json_encode( $scim_attributes );

				if ( ! empty( $as_json ) ) {
					return $as_json;
				}

				$err_message = json_last_error_msg();
				Log_Service::write_log( 'WARN', sprintf( '%s -> Could not encode SCIM attributes as JSON [%s]', __METHOD__, $err_message ) );
				Log_Service::write_log( 'DEBUG', $scim_attributes );
			}

					return null;
		}
	}
}
