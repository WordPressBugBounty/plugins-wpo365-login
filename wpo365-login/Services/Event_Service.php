<?php

namespace Wpo\Services;

use Error;
use WP_Error;
use \Wpo\Core\Event;
use Wpo\Core\Event_User;
use Wpo\Core\User;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use \Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;
use \Wpo\Services\Request_Service;

if (!class_exists('\Wpo\Services\Event_Service')) {

    class Event_Service
    {
        /**
         * Add a new event to the memoized collection.
         * 
         * @since 27.0
         * 
         * @param Event $event 
         * @return void 
         */
        public static function add_event($event)
        {
            if (!Options_Service::get_global_boolean_var('insights_enabled')) {
                return;
            }

            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $events = $request->get_item('events');

            if (!is_array($events)) {
                $events = array($event);
            } else {
                $events[] = $event;
            }

            $request->set_item('events', $events);
        }

        /**
         * Creates a new wpo365/user/created event (success).
         * 
         * @param int $wp_user_id 
         * 
         * @return void 
         */
        public static function user_created__handler($wp_user_id = 0)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $wpo_usr = $request->get_item('wpo_usr');
            $is_user_sync = $request->get_item('user_sync');
            $is_scim = $request->get_item('scim');
            $data = '';

            if ($is_user_sync) {
                $category = 'SYNC';
            } else if ($is_scim) {
                $category = 'SCIM';
                $data = self::get_scim_attributes();
            } else if (!empty($wpo_usr)) {
                $category = 'SSO';
            } else {
                $category = 'WP';
            }

            $user = self::get_event_user($wp_user_id, $wpo_usr);
            $event = new Event('wpo365/user/created', $category, $user, $data);
            self::add_event($event);
        }

        /**
         * Creates a new wpo365/user/created event (fail).
         * 
         * @param WP_Error $error
         * 
         * @return void 
         */
        public static function user_created_fail__handler($error)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $wpo_usr = $request->get_item('wpo_usr');
            $is_user_sync = $request->get_item('user_sync');
            $is_scim = $request->get_item('scim');
            $data = '';

            if ($is_user_sync) {
                $category = 'SYNC';
            } else if ($is_scim) {
                $category = 'SCIM';
                $data = self::get_scim_attributes();
            } else {
                $category = 'SSO';
            }

            if (is_wp_error($error)) {
                $error_message = $error->get_error_message();
            } else if (is_string($error)) {
                $error_message = $error;
            } else {
                $error_message = 'Unknown error occurred';
            }

            $user = self::get_event_user(0, $wpo_usr);
            $event = new Event('wpo365/user/created', $category, $user, $data, $error_message, 'ERROR', 'NOK');
            self::add_event($event);
        }

        /**
         * Creates a new wpo365/user/loggedin event (success).
         * 
         * @param string $logged_in_cookie 
         * @param int $expire 
         * @param int $expiration 
         * @param int $wp_user_id 
         * @param string $scheme 
         * @param string $token 
         * 
         * @return void 
         */
        public static function user_loggedin__handler($logged_in_cookie, $expire, $expiration, $wp_user_id, $scheme, $token)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $wpo_usr = $request->get_item('wpo_usr');

            if (!empty($wpo_usr)) {
                $category = 'SSO';
            } else {
                $category = 'WP';
            }

            $user = self::get_event_user($wp_user_id, $wpo_usr);
            $event = new Event('wpo365/user/loggedin', $category, $user);
            self::add_event($event);
        }

        /**
         * Creates a new wpo365/user/loggedin event (fail).
         * 
         * @param string $category 
         * @param int $wp_user_id 
         * @param User $wpo_user 
         * @param array|null $data 
         * @param string|WP_User|WP_Error $error 
         * @param string $level 
         * @param string $status 
         * 
         * @return void 
         */
        public static function user_loggedin_fail__handler($error)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

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

            if (in_array($error, $sso_loggedin_error_codes)) {
                $category = 'SSO';
            } else {
                $category = 'WP';
            }

            $user = self::get_event_user();
            $event = new Event('wpo365/user/loggedin', $category, $user, null, $error, 'ERROR', 'NOK');
            self::add_event($event);
        }

        /**
         * Helper for the authenticate filter.
         * 
         * @param null|WP_user|WP_error $user 
         * @return mixed 
         */
        public static function authenticate__handler($user, $user_name, $password)
        {

            if (is_wp_error($user)) {
                self::user_loggedin_fail__handler($user->get_error_message());
            }

            return $user;
        }

        /**
         * Helper for the wp_login_failer action.
         * 
         * @param string $user_name
         * @param string $error
         * @return void 
         */
        public static function wp_login_failed__handler($user_name, $error)
        {
            self::user_loggedin_fail__handler($error);
        }

        public static function user_updated__handler($wp_user_id)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $wpo_usr = $request->get_item('wpo_usr');
            $is_user_sync = $request->get_item('user_sync');
            $is_scim = $request->get_item('scim');
            $data = '';

            if ($is_user_sync) {
                $category = 'SYNC';
            } else if ($is_scim) {
                $category = 'SCIM';
                $data = self::get_scim_attributes();
            } else if (!empty($wpo_usr)) {
                $category = 'SSO';
            } else {
                $category = 'WP';
            }

            $user = self::get_event_user($wp_user_id, $wpo_usr);
            $event = new Event('wpo365/user/updated', $category, $user, $data);
            self::add_event($event);
        }

        public static function user_updated_fail__handler($wp_user_id, $error)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $wpo_usr = $request->get_item('wpo_usr');
            $is_user_sync = $request->get_item('user_sync');
            $is_scim = $request->get_item('scim');
            $data = null;

            if ($is_user_sync) {
                $category = 'SYNC';
            } else if ($is_scim) {
                $category = 'SCIM';
                $data = self::get_scim_attributes();
            } else if (!empty($wpo_usr)) {
                $category = 'SSO';
            } else {
                $category = 'WP';
            }

            $user = self::get_event_user($wp_user_id, $wpo_usr);
            $event = new Event('wpo365/user/updated', $category, $user, $data, $error, 'ERROR', 'NOK');
            self::add_event($event);
        }

        public static function mail_sent__handler($log_message)
        {
            $user = self::get_event_user();
            $event = new Event('wpo365/mail/sent', 'MAIL', $user, null, $log_message);
            self::add_event($event);
        }

        public static function mail_sent_fail__handler($log_message)
        {
            $user = self::get_event_user();
            $event = new Event('wpo365/mail/sent', 'MAIL', $user, null, $log_message, 'ERROR', 'NOK');
            self::add_event($event);
        }

        /**
         * Saves the memoized events to the events database table.
         * 
         * @since 27.0
         * 
         * @return void 
         */
        public static function flush_events()
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $events = $request->get_item('events');

            if (empty($events)) {
                return; // Nothing to flush
            }

            self::ensure_events_table();

            global $wpdb;

            $table_name = self::get_events_table_name();

            foreach ($events as $event) {

                $data = array(
                    'event_action' => $event->action,
                    'event_category' => $event->category,
                    'event_data' => $event->data,
                    'event_error' => $event->error,
                    'event_level' => $event->level,
                    'event_status' => $event->status,
                    'event_timestamp' => WordPress_Helpers::time_zone_corrected_formatted_date($event->timestamp),
                    'event_user' => json_encode($event->user),
                );

                $wpdb->insert(
                    $table_name,
                    $data
                );

                if (!empty($wpdb->last_error)) {
                    Log_Service::write_log('ERROR', sprintf('%s -> An error occurred when trying add a new event [error: %s]', __METHOD__, $wpdb->last_error));
                }
            }

            $events = $request->remove_item('events');
        }

        /**
         * 
         * 
         * @param string $period 
         * @return WP_Error|void 
         */
        public static function get_insights_summary($period, $group)
        {
            $periods = array('TODAY', 'WTD', 'MTD', 'YTD');

            if (empty($period) || !in_array($period, $periods)) {
                $error = sprintf('%s -> Value %s for argument "period" is not supported', __METHOD__, $period);
                Log_Service::write_log('ERROR', $error);
                return new WP_Error('ArgumentException', $error);
            }

            self::ensure_events_table();

            global $wpdb;

            $table_name = self::get_events_table_name();

            if ($period == 'WTD') {
                $where_clause = "WEEK(`event_timestamp`) = WEEK(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } elseif ($period == 'MTD') {
                $where_clause = "MONTH(`event_timestamp`) = MONTH(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } elseif ($period == 'YTD') {
                $where_clause = "YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } else {
                $where_clause = "DATE(`event_timestamp`) = CURDATE()";
            }

            if ($group == 'mail') {
                $keys = array('MAIL_SENT_OK', 'MAIL_SENT_NOK');
            }

            if ($group == 'login') {
                $keys = array('WP_LOGGEDIN_OK', 'WP_LOGGEDIN_NOK', 'SSO_LOGGEDIN_OK', 'SSO_LOGGEDIN_NOK');
            }

            if ($group == 'users') {
                $keys = array('SSO_CREATED_OK', 'SSO_CREATED_NOK', 'SYNC_CREATED_OK', 'SYNC_CREATED_NOK', 'SCIM_CREATED_OK', 'SCIM_CREATED_NOK', 'SYNC_UPDATED_OK', 'SYNC_UPDATED_NOK', 'SCIM_UPDATED_OK', 'SCIM_UPDATED_NOK');
            }

            if (!empty($keys)) {
                $query = 'SELECT';

                foreach ($keys as $key) {
                    $key_segments = self::key2params($key);

                    if (sizeof($key_segments) != 3) {
                        continue;
                    }

                    $query = $query . ' COALESCE(SUM(';
                    $query_glue = '';

                    foreach ($key_segments as $column_name => $value) {
                        $query = sprintf("%s%s`%s` = '%s'", $query, $query_glue, $column_name, $value);
                        $query_glue = 'AND';
                    }

                    $query = sprintf("%s), 0) AS %s,", $query, $key);
                }

                $query = rtrim($query, ',');
                $query = sprintf("%s FROM `%s` WHERE %s", $query, $table_name, $where_clause);
            }

            $rows = $wpdb->get_results($query);

            if (!empty($wpdb->last_error)) {
                $error = sprintf('%s -> An error occurred when trying retrieve insights summary [error: %s]', __METHOD__, $wpdb->last_error);
                Log_Service::write_log('ERROR', $error);
                return false;
            }

            return $rows;
        }

        public static function get_insights($key, $period, $start_row)
        {
            $periods = array('TODAY', 'WTD', 'MTD', 'YTD');

            if (empty($period) || !in_array($period, $periods)) {
                $error = sprintf('%s -> Value %s for argument "period" is not supported', __METHOD__, $period);
                Log_Service::write_log('ERROR', $error);
                return new WP_Error('ArgumentException', $error);
            }

            self::ensure_events_table();

            global $wpdb;

            $table_name = self::get_events_table_name();

            if ($period == 'WTD') {
                $where_clause = "WEEK(`event_timestamp`) = WEEK(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } elseif ($period == 'MTD') {
                $where_clause = "MONTH(`event_timestamp`) = MONTH(CURDATE()) AND YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } elseif ($period == 'YTD') {
                $where_clause = "YEAR(`event_timestamp`) = YEAR(CURDATE())";
            } else {
                $where_clause = "DATE(`event_timestamp`) = CURDATE()";
            }

            $where_params = self::key2params($key);

            if (sizeof($where_params) != 3) {
                return new WP_Error('ArgumentException', sprintf('%s => Attempt to split the key [%s] in 3 segments failed', __METHOD__, $key));
            }

            foreach ($where_params as $column_name => $value) {
                $where_clause = sprintf(
                    "%s %s `%s` = '%s'",
                    $where_clause,
                    empty($where_clause) ? '' : ' AND ',
                    $column_name,
                    $value
                );
            }

            $rows = $wpdb->get_results("SELECT 'SYNC_CREATED_OK' AS `key`, `event_user` AS `user`, `event_data` AS `data`, `event_error` AS `error`, `event_timestamp` AS `time` FROM $table_name WHERE $where_clause LIMIT 25 OFFSET $start_row");

            if (!empty($wpdb->last_error)) {
                $error = sprintf('%s -> An error occurred when trying retrieve insights [error: %s]', __METHOD__, $wpdb->last_error);
                Log_Service::write_log('ERROR', $error);
                return new WP_Error('DbException', $error);
            }

            return $rows;
        }

        /**
         * Truncates the insights data table.
         * 
         * @return WP_Error|true 
         */
        public static function truncate_insights_data()
        {
            global $wpdb;

            $table_name = self::get_events_table_name();

            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $error = sprintf('%s -> Could not truncate table %s because the table was not found', __METHOD__, $table_name);
                Log_Service::write_log('WARN', $error);
                return new WP_Error('DbTableNotFoundException', $error);
            }

            $wpdb->query("TRUNCATE TABLE $table_name");

            if (!empty($wpdb->last_error)) {
                $error = sprintf('%s -> An error occurred when trying to truncate the insights table [error: %s]', __METHOD__, $wpdb->last_error);
                Log_Service::write_log('ERROR', $error);
                return new WP_Error('DbTruncateException', $error);
            }

            Log_Service::write_log('DEBUG', __METHOD__ . " -> Truncated the insights table successfully");
            return true;
        }

        /**
         * Splits the internal insights key (e.g. SSO_CREATED_NOK) into an assoc array that matches with table column names and values.
         * 
         * @param mixed $key 
         * @return (string|void)[] 
         */
        private static function key2params($key)
        {
            $key_segments = explode('_', $key);

            if (sizeof($key_segments) != 3) {
                return array();
            }

            $get_action = function ($action_key) {

                if ($action_key == 'CREATED') {
                    return 'wpo365/user/created';
                }

                if ($action_key == 'UPDATED') {
                    return 'wpo365/user/updated';
                }

                if ($action_key == 'SENT') {
                    return 'wpo365/mail/sent';
                }

                if ($action_key == 'LOGGEDIN') {
                    return 'wpo365/user/loggedin';
                }
            };

            return array(
                'event_action' => $get_action($key_segments[1]),
                'event_status' => $key_segments[2],
                'event_category' => $key_segments[0],
            );
        }

        /**
         * Ensures the Event table has been created.
         * 
         * @return bool 
         */
        private static function ensure_events_table()
        {
            global $wpdb;

            $table_name = self::get_events_table_name();

            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                return true;
            }

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY, 
                    event_action TEXT NOT NULL,
                    event_category TEXT NOT NULL,
                    event_data LONGTEXT,
                    event_error TEXT,
                    event_level TEXT,
                    event_status TEXT NOT NULL,
                    event_timestamp DATETIME DEFAULT NULL,
                    event_user LONGTEXT
                    ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            if (!empty($wpdb->last_error)) {
                Log_Service::write_log('ERROR', sprintf('%s -> An error occurred when trying to create the wpo365_events table [error: %s]', __METHOD__, $wpdb->last_error));
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
        private static function get_events_table_name()
        {
            global $wpdb;

            if (Options_Service::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) {
                return $wpdb->prefix . "wpo365_events";
            }

            return $wpdb->base_prefix . "wpo365_events";
        }

        /**
         * Helper to create a small info object with user details from both WP and Entra.
         * 
         * @param int $wp_user_id 
         * @param User $wpo_user 
         * 
         * @return void 
         */
        private static function get_event_user($wp_user_id = 0, $wpo_user = null)
        {
            $user = new Event_User();

            if (Options_Service::get_global_boolean_var('insights_anonymized')) {
                return $user;
            }

            if ($wp_user_id > 0) {
                $user->wp_user_id = $wp_user_id;

                $wp_user = get_user_by('ID', $wp_user_id);

                if (!empty($wp_user)) {

                    if (!empty($wp_user->user_login)) {
                        $user->wp_user_login = $wp_user->user_login;
                    }

                    if (!empty($wp_user->display_name)) {
                        $user->display_name = $wp_user->display_name;
                    }

                    if (!empty($wp_user->user_email)) {
                        $user->mail = $wp_user->user_email;
                    }
                }
            }

            if (!empty($wpo_user)) {

                if (empty($user->mail) && !empty($wpo_user->email)) {
                    $user->mail = $wpo_user->email;
                }

                if (!empty($wpo_user->oid)) {
                    $user->oid = $wpo_user->oid;
                }

                if (empty($user->display_name) && !empty($wpo_user->full_name)) {
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
        private static function get_scim_attributes()
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $scim_attributes = $request->get_item('scim_attributes');

            if (is_array($scim_attributes)) {
                $as_json = json_encode($scim_attributes);

                if (!empty($as_json)) {
                    return $as_json;
                }

                $err_message = json_last_error_msg();
                Log_Service::write_log('WARN', sprintf('%s -> Could not encode SCIM attributes as JSON [%s]', __METHOD__, $err_message));
                Log_Service::write_log('DEBUG', $scim_attributes);
            }

            return null;
        }
    }
}
