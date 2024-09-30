<?php

namespace Wpo\Core;

use \Wpo\Core\Extensions_Helpers;
use Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Core\Plugin_Helpers')) {

    class Plugin_Helpers
    {
        /**
         * Helper to check if a premium WPO365 plugin edition is active.
         */
        public static function is_premium_edition_active($slug = null)
        {
            Log_Service::write_log('WARN', sprintf('%s -> Method is deprecated - Please update all your WPO365 plugins to version 27.0 or later', __METHOD__));

            if (empty($slug)) {
                $extensions = Extensions_Helpers::get_extensions();

                foreach ($extensions as $slug => $extension) {

                    if (true === $extension['activated']) {
                        return true;
                    }
                }

                return false;
            }

            if (false === function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            return \is_plugin_active($slug);
        }

        /**
         * WPMU aware wp filter extension to show the action link on the plugins page. Will add 
         * the wpo365 configuration action link depending on the WPMU configuration
         * 
         * @since 7.3
         * 
         * @param Array $links The current action link collection
         * 
         * @return Array The new action link collection
         */
        public static function get_configuration_action_link($links)
        {
            // Don't show the configuration link for subsite admin if subsite options shouldn't be used
            if (is_multisite() && !is_network_admin() && false === Options_Service::mu_use_subsite_options())
                return $links;

            $wizard_link = '<a href="admin.php?page=wpo365-wizard">' . __('Configuration', 'wpo365-login') . '</a>';
            array_push($links, $wizard_link);

            return $links;
        }

        /**
         * Iterates all installed WPO365 premium extensions and checks for updates.
         * 
         * @since 32.0
         * 
         * @param object|null $check_for_updates_data 
         * @return object 
         */
        public static function check_for_updates($check_for_updates_data)
        {
            if (null === $check_for_updates_data) {
                return $check_for_updates_data;
            }

            $installed_plugins = Extensions_Helpers::get_extensions();

            if (empty($installed_plugins)) {
                return $check_for_updates_data;
            }

            $updates = self::get_plugin_update_info($installed_plugins);

            foreach ($updates as $filename => $plugin_update_info) {
                $current_version = $installed_plugins[$filename]['version'];

                if (
                    !empty($plugin_update_info->new_version)
                    && version_compare($plugin_update_info->new_version, $current_version, '>')
                ) {
                    $check_for_updates_data->response[$filename] = $plugin_update_info;
                } else {
                    $check_for_updates_data->no_update[$filename] = $plugin_update_info;
                }
            }

            return $check_for_updates_data;
        }

        public static function check_licenses()
        {
            Wpmu_Helpers::mu_delete_transient('wpo365_lic_notices');
            $extensions = Extensions_Helpers::get_active_extensions();
            wp_clean_plugins_cache(true);

            foreach ($extensions as $slug => $extension) {
                list($license_key, $url) = self::get_plugin_license_key($extension);

                if (true === $extension['activated']) {
                    self::check_license($extension, $license_key, $url);
                }
            }
        }

        public static function show_license_notices()
        {
            // Get all license related admin notices
            $lic_notices = Wpmu_Helpers::mu_get_transient('wpo365_lic_notices');

            if (\is_array($lic_notices)) {

                foreach ($lic_notices as $lic_notice) {
                    add_action('admin_notices', function () use ($lic_notice) {
                        printf('<div class="notice notice-error" style="margin-left: 2px;"><p>%s</p></div>', __($lic_notice));
                    }, 10, 0);
                    add_action('network_admin_notices', function () use ($lic_notice) {
                        printf('<div class="notice notice-error" style="margin-left: 2px;"><p>%s</p></div>', __($lic_notice));
                    }, 10, 0);
                }
            }
        }

        /**
         * Hooks in to the plugins API to view details of available plugin updates.
         * 
         * @since 32.0
         * 
         * @param object|false $res 
         * @param string $action 
         * @param object $args 
         * @return object|false 
         */
        public static function plugin_info($res, $action, $args)
        {
            // Nothing to do
            if ('plugin_information' !== $action) {
                return $res;
            }

            // Check the cache.
            $cache_key = 'wpo365_plugins_updated';
            $data = get_site_transient($cache_key);

            // No cached updates found
            if (!isset($data['plugins']) || !is_array($data['plugins'])) {
                return $res;
            }

            // Prepare the plugin info if found
            foreach ($data['plugins'] as $filename => $plugin_info) {
                $slug = sprintf('%s/', $args->slug);

                if (false !== WordPress_Helpers::stripos($filename, $slug)) {
                    return $plugin_info;
                }
            }

            return $res;
        }

        /**
         * Shows a compatibility warning for older versions of premium plugins.
         * 
         * @since 32.0
         * 
         * @param array $plugin_meta 
         * @param string $file_name 
         * @return array  
         */
        public static function show_old_version_warning($plugin_meta, $file_name)
        {
            if (false !== WordPress_Helpers::stripos($file_name, 'wpo365')) {
                $non_premium_plugin_folders = array(
                    'wpo365-login/',
                    'wpo365-msgraphmailer/',
                    'wpo365-samesite/',
                    'wpo365-developer/'
                );

                $plugin_folder = explode('/', $file_name);
                $plugin_folder = $plugin_folder[0] . '/';

                if (!in_array($plugin_folder, $non_premium_plugin_folders)) {
                    $update_plugins = get_site_transient('update_plugins');

                    if (is_object($update_plugins) && property_exists($update_plugins, 'response') && isset($update_plugins->response[$file_name])) {
                        $plugin_row = array('<div style="display: block; color: red; padding: 10px; margin-top: 20px; border: 1px solid; background-color: #ffffff; font-weight: 600;">');
                        $plugin_row[] = '<div style="display: block; padding-bottom: 5px;"><span class="dashicons dashicons-warning"></span>&nbsp;Compatibility alert</div>';
                        $plugin_row[] = sprintf('<div style="display: block;"><span style="font-weight: 400; color: #000000;">The currently installed version of this plugin has not been tested with the latest version of %s. To avoid issues, make sure you update your plugins regularly.</span></div></div>', class_exists('\Wpo\Login') ? 'WPO365 | LOGIN' : 'WPO365 | MICROSOFT GRAPH MAILER');
                        $plugin_meta[] = implode('', $plugin_row);
                    }
                }
            }

            return $plugin_meta;
        }

        private static function get_plugin_update_info($installed_plugins)
        {
            foreach ($installed_plugins as $slug => $plugin_data) {
                list($license_key) = self::get_plugin_license_key($plugin_data);
                $installed_plugins[$slug]['license_key'] = $license_key;
            }

            $hash = md5(wp_json_encode($installed_plugins));

            // Check the cache.
            $cache_key = 'wpo365_plugins_updated';
            $data = get_site_transient($cache_key);

            if (isset($data['hash'], $data['plugins']) && $hash === $data['hash']) {
                return $data['plugins'];
            }

            $data = array(
                'hash' => $hash,
                'plugins' => array(),
            );

            $plugin_version_infos = self::get_version_from_remote($installed_plugins);

            if (is_array($plugin_version_infos)) {

                foreach ($plugin_version_infos as $filename => $plugin_version_info) {
                    $data['plugins'][$filename] = (object) $plugin_version_info;
                }
            }

            set_site_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);

            return $data['plugins'];
        }

        /**
         * Prompt WordPress to clean its plugin-updates cache and refresh the cached data for premium WPO365 plugins. 
         * 
         * @since 32.0
         * 
         * @return void 
         */
        public static function force_check_for_plugin_updates()
        {
            if (
                !is_user_logged_in()
                || !current_user_can('administrator')
                || empty($_POST)
                || !check_admin_referer('wpo365_force_check_for_plugin_updates', 'wpo365_force_check_for_plugin_updates_nonce')
            ) {
                wp_die('Forbidden', '403 Forbidden', array('response' => 403));
            }

            delete_site_transient('wpo365_plugins_updated');
            wp_clean_plugins_cache(true);
            $goto_after = admin_url('plugins.php');
            wp_redirect($goto_after);
            die();
        }

        /**
         * Connects to the EDD SL service endpoint at wpo365.com to get the 
         * plugin update info for the plugin in question.
         * 
         * @param   array           $plugin_data
         * @return  false|object    The (EDD) plugin update info from the wpo365.com server; Otherwise false.
         */
        private static function get_version_from_remote($installed_plugins)
        {
            if (self::request_recently_failed()) {
                return false;
            }

            $api_params = array(
                'edd_action' => 'get_version',
                'products' => array(),
            );

            foreach ($installed_plugins as $slug => $installed_plugin) {
                $api_params['products'][$slug] = array(
                    'license'     => isset($installed_plugin['license_key']) ? $installed_plugin['license_key'] : '',
                    'item_id'     => isset($installed_plugin['store_item_id']) ? $installed_plugin['store_item_id'] : false,
                    'url'         => home_url(),
                );
            }

            $request = wp_remote_post(
                $GLOBALS['WPO_CONFIG']['store'],
                array(
                    'sslverify' => true,
                    'body'      => $api_params,
                    'headers'   => array('Expect' => ''),
                )
            );

            if (is_wp_error($request) || (200 !== wp_remote_retrieve_response_code($request))) {
                $slugs = array_keys($installed_plugins);
                Log_Service::write_log('WARN', sprintf('%s -> Could not retrieve version info from wpo365.com for the following plugins: %s', __METHOD__, implode(', ', $slugs)));
                self::log_failed_request();
                return false;
            }

            $plugin_version_infos = json_decode(wp_remote_retrieve_body($request));
            $result = array();

            foreach ($plugin_version_infos as $filename => $plugin_version_info) {

                if (isset($plugin_version_info->sections)) {
                    $plugin_version_info->sections = maybe_unserialize($plugin_version_info->sections);
                } else {
                    return false;
                }

                if (isset($plugin_version_info->banners)) {
                    $plugin_version_info->banners = maybe_unserialize($plugin_version_info->banners);
                }

                if (isset($plugin_version_info->icons)) {
                    $plugin_version_info->icons = maybe_unserialize($plugin_version_info->icons);
                }

                if (!empty($plugin_version_info->sections)) {

                    foreach ($plugin_version_info->sections as $key => $section) {
                        $plugin_version_info->$key = (array) $section;
                    }
                }

                // Correct the wrong slug that EDD is making up out of get_next_post_where
                $plugin_version_info->slug = substr($filename, 0, WordPress_Helpers::stripos($filename, '/'));

                // This is required for your plugin to support auto-updates in WordPress 5.5.
                $plugin_version_info->plugin = $filename;
                $plugin_version_info->id     = $filename;
                $plugin_version_info->tested = self::get_tested_version($plugin_version_info);

                $result[$filename] = $plugin_version_info;
            }

            return $result;
        }

        /**
         * Reads the license key for the plugin in question from the site's (network)
         * options.
         * 
         * @param   array   $plugin_data 
         * @return  array   The license key entered by the admin and the URL
         */
        private static function get_plugin_license_key($plugin_data)
        {
            $store_item_id = $plugin_data['store_item_id'];
            $license_key_name = \sprintf("license_%d", $store_item_id);
            $license_key = '';
            $url = '';
            $network_options = get_site_option('wpo365_options');

            if (!empty($network_options[$license_key_name])) {
                $license_key = $network_options[$license_key_name];

                if (WordPress_Helpers::stripos($license_key, '|') > -1) {
                    list($license_key, $url) = explode('|', $license_key);
                }
            }

            return array(
                $license_key,
                $url,
            );
        }

        private static function check_license($extension, $license_key, $url = '')
        {
            Log_Service::write_log('DEBUG', sprintf(
                '##### -> %s [%s]',
                __METHOD__,
                $extension['store_item']
            ));

            if (self::request_recently_failed()) {
                return false;
            }

            $lic_notices = Wpmu_Helpers::mu_get_transient('wpo365_lic_notices');

            if (empty($lic_notices)) {
                $lic_notices = array();
            }

            $lic_url = is_multisite()
                ? network_admin_url('admin.php?page=wpo365-manage-licenses')
                : admin_url('admin.php?page=wpo365-manage-licenses');

            $empty_url_arg = empty($url);

            if ($empty_url_arg) {
                $url = is_multisite() ? network_home_url() : home_url();
            } else {
                $_url = is_multisite() ? network_home_url() : home_url();

                // Check if the URL that was previously valid has changed
                if (strcasecmp(trailingslashit($url), trailingslashit($_url)) !== 0) {
                    $host = parse_url($_url, PHP_URL_HOST);

                    // Ignore the case where the hostname is an IP address
                    if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
                        return;
                    }
                }
            }

            $skip_license_check = false;

            if (false !== WordPress_Helpers::stripos($url, 'localhost')) {
                $skip_license_check = true;
            } else {
                $url_host = parse_url($url, PHP_URL_HOST);
                $url_path = parse_url($url, PHP_URL_PATH);

                $url_host_segments = explode('.', $url_host);

                for ($i = 0; $i < count($url_host_segments) && $i < 2; $i++) {
                    array_pop($url_host_segments);
                }

                $url_subdomain = implode('.', $url_host_segments);
                $skip_list = array('dev', 'test', 'staging', 'stage', 'preprod', 'pre-prod,', 'uat');

                foreach ($skip_list as $skip) {

                    if (false !== WordPress_Helpers::stripos($url_path, $skip)) {
                        $skip_license_check = true;
                        break;
                    }

                    if (false !== WordPress_Helpers::stripos($url_subdomain, $skip)) {
                        $skip_license_check = true;
                        break;
                    }
                }
            }

            if ($skip_license_check) {
                Log_Service::write_log('DEBUG', sprintf(
                    '%s -> Skipping license check for %s using URL %s',
                    __METHOD__,
                    $extension['store_item'],
                    $url
                ));
                return;
            }

            Log_Service::write_log('DEBUG', sprintf(
                '%s -> Checking license for %s using %s',
                __METHOD__,
                $extension['store_item'],
                $url
            ));

            // Generate warning if license was not found
            if (empty($license_key)) {
                $lic_notices[] = \sprintf(
                    'Could not find a license for <strong>%s</strong>. Please go to <a href="%s">WP Admin > WPO365 > Licenses</a> and activate your license or purchase a <a href="%s" target="_blank">new license online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details',
                    $extension['store_item'],
                    $lic_url,
                    $extension['store_url'],
                    'https://www.wpo365.com/end-user-license-agreement/'
                );
                Wpmu_Helpers::mu_set_transient('wpo365_lic_notices', $lic_notices);
                return;
            }

            // Call the custom API.
            $response = wp_remote_get(\sprintf("https://www.wpo365.com/?edd_action=check_license&license=%s&item_id=%s&url=%s", $license_key, $extension['store_item_id'], $url), array('timeout' => 15, 'sslverify' => false));

            // make sure the response came back okay
            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {

                if (is_wp_error($response)) {
                    $message = $response->get_error_message();
                } else {
                    $message = __('An error occurred, please try again.');
                }

                Log_Service::write_log('WARN', sprintf('%s -> %s', __METHOD__, $message));
                self::log_failed_request();

                Log_Service::write_log('ERROR', __METHOD__ . ' -> ' . $message);
            }

            $license_data = json_decode(wp_remote_retrieve_body($response));
            $message = '';

            switch ($license_data->license) {

                case 'valid':

                    Log_Service::write_log('DEBUG', sprintf(
                        '%s -> License key for %s is valid',
                        __METHOD__,
                        $extension['store_item']
                    ));

                    /**
                     * @since   23.0    Lets cache the URL for which the license check was successful
                     */

                    if ($empty_url_arg) {
                        $license_key_name = \sprintf("license_%d", $extension['store_item_id']);
                        $network_options = get_site_option('wpo365_options');

                        if (!empty($network_options[$license_key_name])) {
                            $network_options[$license_key_name] = sprintf('%s|%s', $license_key, $url);
                            update_site_option('wpo365_options', $network_options);
                            $GLOBALS['WPO_CONFIG']['options'] = array();
                        }
                    }

                    return;

                case 'expired':
                    $message = sprintf(
                        __('Your license key for <strong>%s</strong> expired on %s. Please go to <a href="%s" target="_blank">WP Admin > WPO365 > Licenses</a> and update your license or purchase a <a href="%s" target="_blank">new license online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details'),
                        $extension['store_item'],
                        date_i18n(get_option('date_format'), strtotime($license_data->expires, current_time('timestamp'))),
                        $lic_url,
                        $extension['store_url'],
                        'https://www.wpo365.com/end-user-license-agreement/'
                    );
                    break;

                case 'disabled':
                    $message =
                        sprintf(
                            __('Your license key for <strong>%s</strong> has been disabled. Please go to <a href="%s" target="_blank">WP Admin > WPO365 > Licenses</a> and update your license or purchase a <a href="%s" target="_blank">new license online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details'),
                            $extension['store_item'],
                            $lic_url,
                            $extension['store_url'],
                            'https://www.wpo365.com/end-user-license-agreement/'
                        );
                    break;

                case 'key_mismatch':
                case 'item_name_mismatch':
                    $message =
                        sprintf(
                            __('Your license key for <strong>%s</strong> is not valid for this product. Please go to <a href="%s" target="_blank">WP Admin > WPO365 > Licenses</a> and update your license key or purchase additional <a href="%s" target="_blank">licenses online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details'),
                            $extension['store_item'],
                            $lic_url,
                            $extension['store_url'],
                            'https://www.wpo365.com/end-user-license-agreement/'
                        );
                    break;

                case 'site_inactive':
                    $message =
                        sprintf(
                            __('Your license key for <strong>%s</strong> is not active for this site. Please go to <a href="%s" target="_blank">WP Admin > WPO365 > Licenses</a> and activate your license or purchase additional <a href="%s" target="_blank">licenses online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details'),
                            $extension['store_item'],
                            $lic_url,
                            $extension['store_url'],
                            'https://www.wpo365.com/end-user-license-agreement/'
                        );
                    break;

                case 'invalid_item_id':
                    $message =
                        sprintf(
                            __('The item ID <strong>%s</strong> for <strong>%s</strong> is not valid. Please go to <a href="%s" target="_blank">WP Admin > WPO365 > Licenses</a> and update your license key or purchase additional <a href="%s" target="_blank">licenses online</a>. See the <a href="%s" target="_blank">End User License Agreement</a> for details'),
                            $extension['store_item_id'],
                            $extension['store_item'],
                            $lic_url,
                            $extension['store_url'],
                            'https://www.wpo365.com/end-user-license-agreement/'
                        );
                    break;

                default:
                    $message =
                        sprintf(
                            __('An unknown error occurred whilst checking your license key for %s. Please check WP Admin > WPO365 > ... > Debug to view the raw request (and optionally send it to support@wpo365.com).'),
                            $extension['store_item']
                        );
                    Log_Service::write_log('WARN', sprintf(
                        '%s -> License key %s for %s is not valid for site with URL %s [raw request: %s]',
                        __METHOD__,
                        $license_key,
                        $extension['store_item_id'],
                        $url,
                        htmlentities(serialize($response))
                    ));
                    break;
            }

            if (!empty($message)) {
                $lic_notices[] = $message;
                Wpmu_Helpers::mu_set_transient('wpo365_lic_notices', $lic_notices);

                Log_Service::write_log('ERROR', sprintf(
                    '%s -> License key %s for %s is not valid for site with URL %s [error: %s]',
                    __METHOD__,
                    $license_key,
                    $extension['store_item_id'],
                    $url,
                    $message
                ));
            }
        }

        /**
         * Logs a failed HTTP request for this API URL.
         * We set a timestamp for 1 hour from now. This prevents future API requests from being
         * made to this domain for 1 hour. Once the timestamp is in the past, API requests
         * will be allowed again. This way if the site is down for some reason we don't bombard
         * it with failed API requests.
         * 
         * @since 
         *
         */
        private static function log_failed_request()
        {
            $failed_request_cache_key = 'wpo365_failed_http_' . md5($GLOBALS['WPO_CONFIG']['store']);
            update_option($failed_request_cache_key, strtotime('+1 hour'));
        }

        /**
         * Determines if a request has recently failed.
         *
         * @since 1.9.1
         *
         * @return bool
         */
        private static function request_recently_failed()
        {
            $failed_request_cache_key = 'wpo365_failed_http_' . md5($GLOBALS['WPO_CONFIG']['store']);
            $failed_request_details = get_option($failed_request_cache_key);

            // Request has never failed.
            if (empty($failed_request_details) || !is_numeric($failed_request_details)) {
                return false;
            }

            /*
            * Request previously failed, but the timeout has expired.
            * This means we're allowed to try again.
            */
            if (time() > $failed_request_details) {
                delete_option($failed_request_cache_key);
                return false;
            }

            return true;
        }

        /**
         * Gets the plugin's tested version.
         *
         * @param object $version_info
         * @return null|string
         */
        private static function get_tested_version($version_info)
        {
            // There is no tested version.
            if (empty($version_info->tested)) {
                return null;
            }

            // Strip off extra version data so the result is x.y or x.y.z.
            list($current_wp_version) = explode('-', get_bloginfo('version'));

            // The tested version is greater than or equal to the current WP version, no need to do anything.
            if (version_compare($version_info->tested, $current_wp_version, '>=')) {
                return $version_info->tested;
            }
            $current_version_parts = explode('.', $current_wp_version);
            $tested_parts          = explode('.', $version_info->tested);

            // The current WordPress version is x.y.z, so update the tested version to match it.
            if (isset($current_version_parts[2]) && $current_version_parts[0] === $tested_parts[0] && $current_version_parts[1] === $tested_parts[1]) {
                $tested_parts[2] = $current_version_parts[2];
            }

            return implode('.', $tested_parts);
        }
    }
}
