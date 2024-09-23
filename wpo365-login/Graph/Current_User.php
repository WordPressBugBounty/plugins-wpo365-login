<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined('ABSPATH') or die();

use \Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;

if (!class_exists('\Wpo\Graph\Current_User')) {

    class Current_User
    {

        /**
         * Returns basic information for the current user incl. the user's (Azure AD) UPN and Object ID.
         * 
         * @since 13.0
         * 
         * @return array The user's login, email, display name, ID, UPN and AAD Object ID.
         */
        public static function get_current_user()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            $wp_usr_id = \get_current_user_id();

            $user_info = \wp_get_current_user();
            $user_login = !empty($user_info->user_login) ? $user_info->user_login  : '';
            $user_email = !empty($user_info->user_email) ? $user_info->user_email : '';
            $display_name = !empty($user_info->display_name) ? $user_info->display_name  : '';
            $id = $user_info->ID;

            $upn = \get_user_meta($wp_usr_id, 'userPrincipalName', true);
            $aad_object_id = \get_user_meta($wp_usr_id, 'aadObjectId', true);

            return array(
                "user_login" => $user_login,
                "user_email" => $user_email,
                "display_name" => $display_name,
                "id" => $id,
                "upn" => !empty($upn) ? $upn : '',
                "aad_object_id" => !empty($aad_object_id) ? $aad_object_id : '',
            );
        }
    }
}
