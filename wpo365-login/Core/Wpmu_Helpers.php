<?php

namespace Wpo\Core;

use \Wpo\Core\WordPress_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Error_Service;
use \Wpo\Services\Options_Service;
use \Wpo\Services\Log_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Core\Wpmu_Helpers')) {

    class Wpmu_Helpers
    {

        /**
         * Helper to get the global or local transient based on the
         * WPMU configuration.
         * 
         * @since 9.2
         * 
         * @return mixed Returns the value of transient or false if not found
         */
        public static function mu_get_transient($name)
        {

            if (!is_multisite() || (Options_Service::mu_use_subsite_options() && !self::mu_is_network_admin())) {
                return get_transient($name);
            }

            return get_site_transient($name);
        }

        /**
         * Helper to set the global or local transient based on the
         * WPMU configuration.
         * 
         * @since 9.2
         * 
         * @param $name string Name of transient
         * @param $value mixed Value of transient
         * @param $duration int Time transient should be cached in seconds
         * 
         * @return void
         */
        public static function mu_set_transient($name, $value, $duration = 0)
        {

            if (!is_multisite() || (Options_Service::mu_use_subsite_options() && !self::mu_is_network_admin())) {
                set_transient($name, $value, $duration);
            } else {
                set_site_transient($name, $value, $duration);
            }
        }

        /**
         * Helper to delete the global or local transient based on the
         * WPMU configuration.
         * 
         * @since 10.9
         * 
         * @param $name string Name of transient
         * 
         * @return void
         */
        public static function mu_delete_transient($name)
        {

            if (!is_multisite() || (Options_Service::mu_use_subsite_options() && !self::mu_is_network_admin())) {
                delete_transient($name);
            } else {
                delete_site_transient($name);
            }
        }

        /**
         * Helper to check if the current request is for a network admin page and it includes a simple 
         * check if the request is made from an AJAX call.
         * 
         * @since   11.18
         * 
         * @return  boolean  True if the request is for a network admin page other false.
         */
        public static function mu_is_network_admin()
        {
            return (is_network_admin() || true === $GLOBALS['WPO_CONFIG']['ina']);
        }

        /**
         * Helper to switch the current blog from the main site to a subsite in case
         * of a multisite installation (shared scenario) when the user is redirected 
         * back to the main site whereas the state URL indicates that the target is
         * a subsite.
         * 
         * @since   11.0
         * 
         * @param   $state_url  string  The (Relay) state URL
         * 
         * @return  void
         */
        public static function switch_blog($state_url)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (is_multisite() && !empty($state_url)) {
                $redirect_url = Options_Service::get_aad_option('redirect_url');
                $redirect_url = Options_Service::get_global_boolean_var('use_saml')
                    ? Options_Service::get_aad_option('saml_sp_acs_url')
                    : $redirect_url;
                $redirect_url = apply_filters('wpo365/aad/redirect_uri', $redirect_url);

                $redirect_host = parse_url($redirect_url, PHP_URL_HOST);
                $state_host = parse_url($state_url, PHP_URL_HOST);
                $state_path = '/';
                $redirect_path = '/';

                if (!is_subdomain_install()) {
                    $redirect_path = parse_url($redirect_url, PHP_URL_PATH);
                    $state_path = parse_url($state_url, PHP_URL_PATH);
                }

                $state_blog_id = self::get_blog_id_from_host_and_path($state_host, $state_path);
                $redirect_blog_id = self::get_blog_id_from_host_and_path($redirect_host, $redirect_path);

                Log_Service::write_log('DEBUG', __METHOD__ . " -> Detected WPMU with state context (path: $state_path - ID: $state_blog_id) and AAD redirect context (path: $redirect_path - ID: $redirect_blog_id)");

                if ($state_blog_id !== $redirect_blog_id) {
                    switch_to_blog($state_blog_id);
                    $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] = get_option('home');
                }
            }
        }

        /**
         * Helper to try and search for a matching blog by itteratively removing the last segment from the path.
         * 
         * @since   16.0
         * 
         * @param   string  $host   The domain e.g. www.your-site.com
         * @param   string  $path   The path starting with a slash
         * 
         * @return  int     The blog ID or 0 if not found
         */
        public static function get_blog_id_from_host_and_path($host, $path)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            $blog_id = get_blog_id_from_url($host, $path);

            if (!empty($blog_id)) {
                return $blog_id;
            }

            $path = WordPress_Helpers::rtrim($path, '/');
            $path = WordPress_Helpers::ltrim($path, '/');
            $segments = explode('/', $path);
            $segments[] = 'placeholder'; // Add empty string to start with full URL when popping elements from the end

            while (null !== ($last_element = array_pop($segments))) {
                $path = '/' . implode('/', $segments);

                if (strlen($path) > 1) {
                    $path = $path . '/';
                }

                $blog_id = get_blog_id_from_url($host, $path);

                if ($blog_id > 0) {
                    return $blog_id;
                }
            }

            return 0;
        }

        /**
         * Helper to cache the blog ID where WPO365 should look for its options.
         * 
         * @return mixed 
         */
        public static function get_options_blog_id()
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);
            $blog_id = $request->get_item('blog_id');

            if (false !== $blog_id) {
                return $blog_id;
            }

            return (Options_Service::mu_use_subsite_options() && !Wpmu_Helpers::mu_is_network_admin()) ? get_current_blog_id() : get_main_site_id();
        }

        /**
         * @since 11.0
         * @since 28.x  Moved to Wpmu_Helpers
         */
        public static function wpmu_add_user_to_blog($wp_usr_id, $blog_id = null, $site_id = null)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (!is_multisite()) {
                return;
            }

            if (null === $blog_id) {
                $blog_id = get_current_blog_id();
            }

            $is_main_site = is_main_site($site_id);

            $usr_default_role = $is_main_site
                ? Options_Service::get_global_string_var('new_usr_default_role')
                : Options_Service::get_global_string_var('mu_new_usr_default_role');

            if (!empty($usr_default_role)) {

                if (!is_user_member_of_blog($wp_usr_id, $blog_id)) {
                    $use_subsite_options = Options_Service::mu_use_subsite_options();
                    $add_member_to_main_site = Options_Service::get_global_boolean_var('create_and_add_users');
                    $add_member_to_subsite = !Options_Service::get_global_boolean_var('skip_add_user_to_subsite');

                    // Shared mode
                    if (!$use_subsite_options) {

                        // Main site / Sub site when settings prevented adding user > Send to dashboard URL
                        if (
                            ($is_main_site && !$add_member_to_main_site)
                            || (!$is_main_site && !$add_member_to_subsite)
                        ) {
                            $goto_after = get_dashboard_url($wp_usr_id);
                        }

                        // Settings prevented user from being added to site
                        if (!empty($goto_after)) {
                            Log_Service::write_log('DEBUG', sprintf(
                                '%s -> Settings prevented the user from being added to blog with id %s and therefore sending user to dashboard-URL %s instead',
                                __METHOD__,
                                $blog_id,
                                $goto_after
                            ));

                            add_filter('login_redirect', function ($redirect_to, $requested_redirect_to, $wp_usr) use ($goto_after) {
                                return $goto_after;
                            }, 99, 3);

                            return;
                        }
                    }

                    // Settings don't allow adding member to dedicated site [wpmu dedicated mode]
                    if ($use_subsite_options && !$add_member_to_main_site) {
                        Log_Service::write_log('ERROR', __METHOD__ . ' -> [WPMU dedicated] User not a member of blog with id ' . $blog_id . ' and settings prevented adding user ' . $wp_usr_id);
                        Authentication_Service::goodbye(Error_Service::USER_NOT_FOUND, false);
                        exit();
                    }

                    add_user_to_blog($blog_id, $wp_usr_id, $usr_default_role);

                    /**
                     * @since 15.0
                     */

                    do_action('wpo365/wpmu/user_added', $blog_id, $wp_usr_id);

                    Log_Service::write_log('DEBUG', __METHOD__ . " -> Added user with ID $wp_usr_id as a member to blog with ID $blog_id");
                } else {
                    Log_Service::write_log('DEBUG', __METHOD__ . " -> Skipped adding user with ID $wp_usr_id to blog with ID $blog_id because user already added");
                }
            } else {
                Log_Service::write_log('WARN', __METHOD__ . ' -> Could not add user with ID ' . $wp_usr_id . ' to current blog with ID ' . $blog_id . ' because the default role for the subsite is not valid');
            }
        }
    }
}
