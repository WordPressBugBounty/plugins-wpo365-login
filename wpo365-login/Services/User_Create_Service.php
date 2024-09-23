<?php

namespace Wpo\Services;

// Prevent public access to this script
defined('ABSPATH') or die();

use \Wpo\Core\Permissions_Helpers;
use \Wpo\Core\Wpmu_Helpers;
use \Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;

if (!class_exists('\Wpo\Services\User_Create_Service')) {

    class User_Create_Service
    {

        /**
         * @since 11.0
         */
        public static function create_user(&$wpo_usr)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            $user_login = !empty($wpo_usr->preferred_username)
                ? $wpo_usr->preferred_username
                : $wpo_usr->upn;

            /**
             * @since 12.5 
             * 
             * Don't create a user when that user should not be added to a subsite in case of wpmu shared mode.
             */
            if (is_multisite() && !Options_Service::mu_use_subsite_options() && !is_main_site() && Options_Service::get_global_boolean_var('skip_add_user_to_subsite')) {
                // Not using subsite options and administrator has disabled automatic adding of users to subsites
                $blog_id = get_current_blog_id();
                Log_Service::write_log('WARN', __METHOD__ . " -> Skipped creating a user with login $user_login for blog with ID $blog_id because administrator has disabled adding a user to a subsite");
                Authentication_Service::goodbye(Error_Service::USER_NOT_FOUND, false);

                exit();
            }

            if (!Options_Service::get_global_boolean_var('create_and_add_users')) {
                Log_Service::write_log('ERROR', __METHOD__ . ' -> User not found and settings prevented creating a new user on-demand for user ' . $user_login);
                Authentication_Service::goodbye(Error_Service::USER_NOT_FOUND, false);

                exit();
            }

            /**
             * @since   23.0    Added possibility to hook up (custom) actions to pre-defined events for various WPO365 workloads.
             */

            do_action(
                'wpo365/user/creating',
                $wpo_usr->preferred_username,
                $wpo_usr->email,
                $wpo_usr->groups
            );

            $usr_default_role = is_main_site()
                ? Options_Service::get_global_string_var('new_usr_default_role')
                : Options_Service::get_global_string_var('mu_new_usr_default_role');

            $password_length = Options_Service::get_global_numeric_var('password_length');

            if (empty($password_length) || $password_length < 16) {
                $password_length = 16;
            }

            $password = Permissions_Helpers::generate_password($password_length);

            $userdata = array(
                'user_login'    => $user_login,
                'user_pass'     => $password,
                'role'          => $usr_default_role,
            );

            /**
             * @since 9.4 
             * 
             * Optionally removing any user_register hooks as these more often than
             * not interfer and cause unexpected behavior.
             */

            $user_regiser_hooks = null;

            if (Options_Service::get_global_boolean_var('skip_user_register_action') && isset($GLOBALS['wp_filter']) && isset($GLOBALS['wp_filter']['user_register'])) {
                Log_Service::write_log('DEBUG', __METHOD__ . ' -> Temporarily removing all filters for the user_register action to avoid interference');
                $user_regiser_hooks = $GLOBALS['wp_filter']['user_register'];
                unset($GLOBALS['wp_filter']['user_register']);
            }

            $existing_registering = remove_filter('wp_pre_insert_user_data', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registering', PHP_INT_MAX);
            $existing_registered = remove_action('user_register', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registered', PHP_INT_MAX);
            $wp_usr_id = wp_insert_user($userdata);

            if ($existing_registering) {
                add_filter('wp_pre_insert_user_data', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registering', PHP_INT_MAX, 4);
            }

            if ($existing_registered) {
                add_action('user_register', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registered', PHP_INT_MAX, 1);
            }

            if (!empty($GLOBALS['wp_filter']) && !empty($user_regiser_hooks)) {
                $GLOBALS['wp_filter']['user_register'] = $user_regiser_hooks;
            }

            if (is_wp_error($wp_usr_id)) {
                Log_Service::write_log('ERROR', __METHOD__ . ' -> Could not create wp user. See next line for error information.');
                Log_Service::write_log('ERROR', $wp_usr_id);
                Authentication_Service::goodbye(Error_Service::CHECK_LOG, false);
                exit();
            }

            if (!empty($wpo_usr)) {
                User_Service::save_user_principal_name($wpo_usr->upn, $wp_usr_id);
                User_Service::save_user_tenant_id($wpo_usr->tid, $wp_usr_id);
                User_Service::save_user_object_id($wpo_usr->oid, $wp_usr_id);
            }

            /**
             * @since 15.0
             */

            do_action('wpo365/user/created', $wp_usr_id);

            $wpo_usr->created = true;
            Log_Service::write_log('DEBUG', __METHOD__ . ' -> Created new user with ID ' . $wp_usr_id);

            Wpmu_Helpers::wpmu_add_user_to_blog($wp_usr_id);

            add_filter('allow_password_reset', '\Wpo\Services\User_Create_Service::temporarily_allow_password_reset', PHP_INT_MAX, 1);
            wp_new_user_notification($wp_usr_id, null, 'both');
            remove_filter('allow_password_reset', '\Wpo\Services\User_Create_Service::temporarily_allow_password_reset', PHP_INT_MAX);

            Wpmu_Helpers::mu_delete_transient('wpo365_upgrade_dismissed');
            Wpmu_Helpers::mu_set_transient('wpo365_user_created', date('d'), 1209600);

            return $wp_usr_id;
        }

        /**
         * @since 11.0
         * 
         * @deprecated
         */
        public static function wpmu_add_user_to_blog($wp_usr_id, $preferred_user_name)
        {
            Wpmu_Helpers::wpmu_add_user_to_blog($wp_usr_id);
        }

        /**
         * Helper used to temporarily add as a filter for 'allow_password_reset' when sending a new user email.
         * 
         * @since   24.0
         * 
         * @return  true 
         */
        public static function temporarily_allow_password_reset()
        {
            return true;
        }
    }
}