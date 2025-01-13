<?php

namespace Wpo\Services;

use \Wpo\Core\Compatibility_Helpers;
use \Wpo\Core\WordPress_Helpers;
use \Wpo\Core\Wpmu_Helpers;
use \Wpo\Services\Log_Service;
use \Wpo\Services\Request_Service;
use \Wpo\Services\Saml2_Service;
use \Wpo\Services\Wp_Config_Service;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Services\Options_Service')) {

    class Options_Service
    {

        /**
         * Same as get_global_var but will try and interpret the value of the
         * global variable as if it is a boolean. 
         * 
         * @since 4.6
         * 
         * @param   string  $name   Name of the global variable to get
         * @return  boolean         True in case value found equals 1, "1", "true" or true, otherwise false.
         */
        public static function get_global_boolean_var($name, $log = true)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $wpo_aad = $request->get_item('wpo_aad');

            if (isset($wpo_aad[$name])) {
                return $wpo_aad[$name];
            }

            $var = self::get_global_var($name, $log);

            return ($var === true
                || $var === "1"
                || $var === 1
                || (is_string($var) && strtolower($var) == 'true')) ? true : false;
        }

        /**
         * Same as get_global_var but will try and cast the value as a 1 dimensional array.
         * 
         * @since 7.0
         * 
         * @param   string $name    name of the global variable to get.
         * @return  array           Value of the global variable as an array or empty if not found.
         */
        public static function get_global_list_var($name, $log = true)
        {
            $var = self::get_global_var($name, $log);

            if (is_array($var)) {

                /**
                 * @since   20.0    Check for compatibility of the extra_user_fields.
                 */

                if ($name == 'extra_user_fields') {
                    $var = Compatibility_Helpers::update_user_field_key($var);
                }

                return $var;
            }

            return array();
        }

        /**
         * Same as get_global_var but will try and cast the value as an integer.
         * 
         * @since 7.0
         * 
         * @param   string $name    name of the global variable to get.
         * @return  int             Value of the global variable as an integer or else -1.
         */
        public static function get_global_numeric_var($name, $log = true)
        {
            $var = self::get_global_var($name, $log);
            return is_numeric($var) ? $var : 0;
        }

        /**
         * Same as get_global_var but will try and cast the value as a string.
         * 
         * @since 7.0
         * 
         * @param   string $name    name of the global variable to get.
         * @return  int             Value of the global variable as a string or else an empty string.
         */
        public static function get_global_string_var($name, $log = true)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $wpo_aad = $request->get_item('wpo_aad');

            if (isset($wpo_aad[$name])) {
                return $wpo_aad[$name];
            }

            $var = self::get_global_var($name, $log);
            return is_string($var) ? WordPress_Helpers::trim($var) : '';
        }

        /**
         * Helper to check if this WordPress instance is multisite and 
         * a global boolean constant WPO_MU_USE_SUBSITE_OPTIONS has been 
         * configured
         * 
         * @since 7.3
         * 
         * @return boolean True if subsite options should be used 
         */
        public static function mu_use_subsite_options()
        {
            if (is_multisite() && defined('WPO_MU_USE_SUBSITE_OPTIONS') && true === constant('WPO_MU_USE_SUBSITE_OPTIONS')) {
                return true;
            }

            return false;
        }

        /**
         * Convert keys in an associative array from php style with underscore to camel case.
         * 
         * @since 5.4
         * 
         * @param $assoc_array string Associative options array with keys following PHP camel case naming convention.
         * 
         * @return string Updated (associative) options array with keys following JSON naming convention.
         */
        public static function to_camel_case($assoc_array)
        {
            $result = array();

            if (!is_array($assoc_array))
                return $result;

            foreach ($assoc_array as $key => $value) {
                $key = str_replace('-', '', $key);
                $key = strtolower($key);
                $cc_key = preg_replace_callback('/_([a-z])/', function ($match) {
                    return strtoupper($match[1]);
                }, $key);
                $result[$cc_key] = $value;
            }

            return $result;
        }

        /**
         * Convert keys in an associative array from camel case to php style with underscore.
         * 
         * @since 5.4
         * 
         * @param $assoc_array string Associative options array with keys following JSON camel case naming convention.
         * 
         * @return string Updated (associative) options array with keys following PHP naming convention.
         */
        public static function from_camel_case($assoc_array)
        {
            $result = array();

            foreach ($assoc_array as $key => $value) {
                $php_key = preg_replace_callback('/([A-Z])/', function ($match) {
                    return "_" . strtolower($match[1]);
                }, $key);
                $result[$php_key] = $value;
            }

            return $result;
        }

        /**
         * Helper to get an initial options array.
         * 
         * @since   7.0
         * 
         * @return  array   Array with snake cased options
         */
        public static function get_default_options()
        {
            $default_login_url_path = parse_url(wp_login_url(), PHP_URL_PATH);

            $pages_blacklist = array(
                '/login/',
                'admin-ajax.php',
                'wp-cron.php',
                'xmlrpc.php',
                '/wp-json/wpo365/v1/',
                $default_login_url_path,
            );

            $default_options = array(
                'auth_scenario' => 'internet',
                'mu_new_usr_default_role' => 'subscriber',
                'new_usr_default_role' => 'subscriber',
                'oidc_flow' => 'code',
                'oidc_response_mode' => 'form_post',
                'pages_blacklist' => $pages_blacklist,
                'session_duration' => 0,
                'version' => '2019',
            );

            return $default_options;
        }

        /**
         * Helper to get the cached WPO365 options to the Wizard.
         * 
         * @since   7.0
         * 
         * @return  array   Array with camel cased options or an empty one if an error occurred.
         */
        public static function get_options($to_camel_case = true)
        {
            $options = $GLOBALS['WPO_CONFIG']['options'];

            try {
                return $to_camel_case
                    ? self::to_camel_case($options)
                    : $options;
            } catch (\Exception $e) {
                return array();
            }
        }

        /**
         * Helper to update the cached WPO365 options with options sent
         * from the Wizard.
         * 
         * @since   7.0
         * 
         * @param   array|string    $updated_options    camelcased options sent by Wizard
         * @param   boolean         $is_assoc           argument is a PHP assoc array
         * @return  bool            True if successfully updated otherwise false
         */
        public static function update_options($updated_options, $is_assoc = false, $reset = false)
        {

            /**
             * @since   16.0
             */
            if ($reset) {
                $GLOBALS['WPO_CONFIG']['options'] = self::get_default_options();
            }

            try {
                if (!$is_assoc) {
                    $camel_case_options = json_decode(base64_decode($updated_options), true);
                    $snake_case_options = self::from_camel_case($camel_case_options);
                } else {
                    $snake_case_options = $updated_options;
                }

                $options = $GLOBALS['WPO_CONFIG']['options'];

                if (!empty($options)) {
                    foreach ($snake_case_options as $key => $value)       // add to existing options
                        $options[$key] = $value;
                } else {
                    $options = $snake_case_options;                         // or replace all options
                }

                ksort($options);

                if (self::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) {
                    update_option('wpo365_options', $options);
                } else {
                    // For non-multisite installs, it uses get_option.
                    update_site_option('wpo365_options', $options);
                }

                $GLOBALS['WPO_CONFIG']['options'] = array();
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }

        /**
         * Deletes the WPO365 configuration
         * 
         * @since 11.18
         */
        public static function delete_options()
        {

            if (self::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) {
                return delete_option('wpo365_options');
            } else {
                // For non-multisite installs, it uses delete_option.
                return delete_site_option('wpo365_options');
            }
        }

        /**
         * Simple helper to add or update an option.
         * 
         * @since 10.0
         * 
         * @param $name     string  Name of the option to add
         * @param $value    mixed   Value of the option to add
         * 
         * @return void
         */
        public static function add_update_option($name, $value)
        {
            $options = $GLOBALS['WPO_CONFIG']['options'];
            $options[$name] = $value;
            ksort($options);
            $GLOBALS['WPO_CONFIG']['options'] = $options;

            if (self::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) {
                update_option('wpo365_options', $options);
            } else {
                // For non-multisite installs, it uses get_option.
                update_site_option('wpo365_options', $options);
            }
        }

        /**
         * Converts a string based key and value pair to an object style key value pair
         * e.g. from "c93f6d7c-1a8b-4421-b87b-bbc67ed396a3,author;" to 
         * { key: "c93f6d7c-1a8b-4421-b87b-bbc67ed396a3", value="author" }
         * 
         * @since 5.4
         * 
         * @param $option string Key value pairs as string e.g. key1,value1;key2,value2;...
         * 
         * @return Array associative array in the form of array( 'key1' => 'value2', 'key2' => 'value2' )...
         */
        private static function to_keyvalues($option)
        {
            if (false === is_string($option))
                return array();

            $kv_str_arr = array_filter(explode(';', WordPress_Helpers::trim($option)));
            $kv_arr = array_map(function ($kv_str) {
                $kv = array_filter(explode(',', $kv_str));
                return array('key' => $kv[0], 'value' => $kv[1]);
            }, $kv_str_arr);
            $kv_arr = is_array($kv_arr) ? $kv_arr : array();

            return $kv_arr;
        }

        /**
         * Inspects the array provided whether tenant, application and redirect url have been
         * specified.
         * 
         * @since 7.3
         * 
         * @return boolean True if wpo365 is configured otherwise false
         */
        public static function is_wpo365_configured()
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $is_wpo365_configured = $request->get_item('is_wpo365_configured');

            // Use the cached outcome if any
            if (false !== $is_wpo365_configured) {
                return ($is_wpo365_configured === 1);
            }

            if (Options_Service::get_global_boolean_var('no_sso')) {
                return $is_wpo365_configured;
            }

            // Assume WPO365 is configured
            $is_wpo365_configured = 1;

            $tentant_id_ok = !empty(self::get_aad_option('tenant_id'));

            if (!$tentant_id_ok) {
                $is_wpo365_configured = 0;
                Log_Service::write_log('WARN', __METHOD__ . ' -> WPO365 is not configured -> Tenant ID is missing.');
            }

            if (true === self::get_global_boolean_var('use_saml')) {

                if (!Saml2_Service::saml_settings(true)) {
                    $is_wpo365_configured = 0;
                    Log_Service::write_log('WARN', __METHOD__ . ' -> WPO365 is not configured -> SAML settings are invalid.');
                }
            } else {
                $application_id_ok = !empty(self::get_aad_option('application_id'));

                if (!$application_id_ok) {
                    $is_wpo365_configured = 0;
                    Log_Service::write_log('WARN', __METHOD__ . ' -> WPO365 is not configured -> Application ID is missing.');
                }

                /**
                 * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
                 */

                $redirect_uri = self::get_aad_option('redirect_url');
                $redirect_uri = apply_filters('wpo365/aad/redirect_uri', $redirect_uri);

                $redirect_url_ok = !empty($redirect_uri);

                if (!$redirect_url_ok) {
                    $is_wpo365_configured = 0;
                    Log_Service::write_log('WARN', __METHOD__ . ' -> WPO365 is not configured -> Redirect URL is missing.');
                }
            }

            $cached_errors = Wpmu_Helpers::mu_get_transient('wpo365_errors');

            if (is_array($cached_errors)) {

                foreach ($cached_errors as $cached_error) {

                    if ((false !== WordPress_Helpers::stripos($cached_error['body'], 'WPOOSGAO20') || false !== WordPress_Helpers::stripos($cached_error['body'], 'WPOOSGMO20')) && $cached_error['request_id'] == $GLOBALS['WPO_CONFIG']['request_id']) {
                        $is_wpo365_configured = 0;
                        break;
                    }
                }
            }

            // Cache for this request
            $request->set_item('is_wpo365_configured', $is_wpo365_configured);
            return $is_wpo365_configured === 1;
        }

        /**
         * Gets an AAD related option that (since v16) may be
         * saved in the site's wp-config.php instead.
         * 
         * @since   16.0
         * @since   27.0   Changed to always get from wp-config if config object present
         * 
         * @param   string  $option_name    The option's name.
         * 
         * @return  string
         */
        public static function get_aad_option($option_name, $return_bool = false)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $wpo_aad = $request->get_item('wpo_aad');

            $default_value = $return_bool ? false : '';

            if (false === $wpo_aad) {
                Log_Service::write_log('ERROR', '[WPOOSGAO10] -> The AAD options have not been cached');
                return $default_value;
            }

            if (!isset($wpo_aad[$option_name])) {
                $value = $return_bool ? self::get_global_boolean_var($option_name) : self::get_global_string_var($option_name);
            } else {
                $value = $wpo_aad[$option_name];
            }

            if (false !== WordPress_Helpers::stripos($value, 'See wp-config.php') || false !== WordPress_Helpers::stripos($value, '00000000-0000-0000-0000-000000000000')) {
                Log_Service::write_log('ERROR', sprintf(
                    '[WPOOSGAO20] -> SSO and / or email related WPO365 configuration options have been moved to your site\'s wp-config.php file but some values are missing and cannot be recovered from the database. Please refer to the following article https://www.wpo365.com/news/breaking-change-affecting-wp-config-php-based-identity-provider-idp-configurations/ and update your wp-config.php file accordingly. [Error: %s is missing]',
                    $option_name
                ));
                return $default_value;
            }

            return $value;
        }

        /**
         * Gets an AAD related option that for the mail feature that may be
         * saved in the site's wp-config.php instead.
         * 
         * @since   16.0
         * @since   27.0   Changed to always get from wp-config if config object present
         * 
         * @param   string  $option_name    The option's name.
         * 
         * @return  string
         */
        public static function get_mail_option($option_name)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $wpo_mail = $request->get_item('wpo_mail');

            if (false === $wpo_mail && !empty($GLOBALS['WPO_CONFIG']['options'])) {
                Log_Service::write_log('ERROR', '[WPOOSGMO10] -> The Graph Mailer options have not been cached');
                return '';
            }

            if (!isset($wpo_mail[$option_name])) {
                $value = self::get_global_string_var($option_name);
            } else {
                $value = $wpo_mail[$option_name];
            }

            if (false !== WordPress_Helpers::stripos($value, 'See wp-config.php') || false !== WordPress_Helpers::stripos($value, '00000000-0000-0000-0000-000000000000')) {
                Log_Service::write_log('ERROR', sprintf(
                    '[WPOOSGMO20] -> SSO and / or email related WPO365 configuration options have been moved to your site\'s wp-config.php file but some values are missing and cannot be recovered from the database. Please refer to the following article https://www.wpo365.com/news/breaking-change-affecting-wp-config-php-based-identity-provider-idp-configurations/ and update your wp-config.php file accordingly. [Error: %s is missing]',
                    $option_name
                ));
                return '';
            }

            return $value;
        }

        /**
         * Helper function to read the options into a global variable.
         * 
         * @since 7.3
         * 
         * @return void
         */
        public static function ensure_options_cache()
        {
            if (empty($GLOBALS['WPO_CONFIG']['options'])) {
                $request_service = Request_Service::get_instance();
                $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

                if (self::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) {
                    $options = get_option('wpo365_options', array());
                } else {
                    // For non-multisite installs, it uses get_option.
                    $options = get_site_option('wpo365_options', array());
                }

                if (empty($options)) {
                    $options = self::get_default_options();
                }

                Wp_Config_Service::apply_overrides($options);

                Wp_Config_Service::ensure_aad_options($options);

                if (!empty($wpo_aad = $request->get_item('wpo_aad'))) {

                    $default_urls = array('redirect_url', 'mail_redirect_url', 'saml_base_url');

                    foreach ($wpo_aad as $key => $value) {

                        if (in_array($key, $default_urls) && empty($value)) {
                            $value = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];
                        }

                        $options[$key] = $value;
                    }
                }

                Wp_Config_Service::ensure_mail_options($options);

                if (!empty($wpo_mail = $request->get_item('wpo_mail'))) {

                    foreach ($wpo_mail as $key => $value) {
                        $options[$key] = $value;
                    }
                }

                if (isset($options['use_wp_config']) && true === filter_var($options['use_wp_config'], FILTER_VALIDATE_BOOLEAN)) {
                    self::remove_aad_options($options);
                }

                $GLOBALS['WPO_CONFIG']['options'] = $options;
            }
        }

        /**
         * Array with options that should be partially obscured when logged
         * 
         * @since 7.11
         * 
         * @return array with option names that should be obscured when logged
         */
        public static function get_secret_options()
        {
            return array(
                'app_only_application_id',
                'app_only_application_secret',
                'application_id',
                'application_secret',
                'mail_application_id',
                'mail_application_secret',
                'nonce_secret',
                'redirect_on_login_secret',
                'saml_x509_cert',
                'scim_secret_token',
                'tenant_id',
            );
        }

        /**
         * Simple helper to ensure that the AAD options were removed.
         * 
         * @since   21.9
         * 
         * @param   mixed     $options
         * 
         * @return  void 
         */
        private static function remove_aad_options(&$options)
        {

            if (!is_array($options)) {
                return;
            }

            $options['app_only_application_id'] = '00000000-0000-0000-0000-000000000000';
            $options['app_only_application_secret'] = 'See wp-config.php';
            $options['application_id'] = '00000000-0000-0000-0000-000000000000';
            $options['application_secret'] = 'See wp-config.php';
            $options['mail_application_id'] = '00000000-0000-0000-0000-000000000000';
            $options['mail_application_secret'] = 'See wp-config.php';
            $options['mail_redirect_url'] = 'See wp-config.php';
            $options['mail_tenant_id'] = '00000000-0000-0000-0000-000000000000';
            $options['redirect_url'] = 'See wp-config.php';
            $options['saml_base_url'] = 'See wp-config.php';
            $options['saml_idp_entity_id'] = 'See wp-config.php';
            $options['saml_idp_meta_data_url'] = 'See wp-config.php';
            $options['saml_idp_sls_binding'] = 'See wp-config.php';
            $options['saml_idp_sls_url'] = 'See wp-config.php';
            $options['saml_idp_ssos_binding'] = 'See wp-config.php';
            $options['saml_idp_ssos_url'] = 'See wp-config.php';
            $options['saml_sp_acs_binding'] = 'See wp-config.php';
            $options['saml_sp_acs_url'] = 'See wp-config.php';
            $options['saml_sp_entity_id'] = 'See wp-config.php';
            $options['saml_sp_sls_binding'] = 'See wp-config.php';
            $options['saml_sp_sls_url'] = 'See wp-config.php';
            $options['saml_x509_cert'] = 'See wp-config.php';
            $options['tenant_id'] = '00000000-0000-0000-0000-000000000000';
            $options['wp_rest_aad_application_id_uri'] = 'See wp-config.php';
            $options['wp_rest_aad_application_id'] = '00000000-0000-0000-0000-000000000000';
        }

        /**
         * Gets a global variable by its name.
         * 
         * @param   string  $name   Variable name as string
         * 
         * @return  object|null The global variable or WP_Error if not found
         */
        private static function get_global_var($name, $log = true)
        {
            // Try return the requested option
            if (
                isset($GLOBALS['WPO_CONFIG']['options'][$name])
                && !empty($GLOBALS['WPO_CONFIG']['options'][$name])
            ) {
                $value = $GLOBALS['WPO_CONFIG']['options'][$name];
            }

            return empty($value)
                ? null
                : $value;
        }
    }
}
