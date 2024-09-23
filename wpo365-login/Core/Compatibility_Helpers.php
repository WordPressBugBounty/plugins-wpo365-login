<?php

namespace Wpo\Core;

use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Log_Service;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Core\Compatibility_Helpers')) {

    class Compatibility_Helpers
    {
        /**
         * Writes the compatibility warning as an error to the log but only if it's not currently already in the list
         * of WPO365 Health Messages.
         * 
         * @since   20.0
         * 
         * @param   string   $warning 
         * 
         * @return  void 
         */
        public static function compat_warning($warning)
        {
            $wpo_errors = Wpmu_Helpers::mu_get_transient('wpo365_errors');

            if (empty($wpo_errors) || !is_array($wpo_errors)) {
                Log_Service::write_log('ERROR', $warning);
            } else {
                $same_errors = array_filter($wpo_errors, function ($wpo_error) use ($warning) {
                    return isset($wpo_error['body']) && false !== WordPress_Helpers::stripos($wpo_error['body'], $warning);
                });

                if (sizeof($same_errors) === 0) {
                    Log_Service::write_log('ERROR', $warning);
                }
            }
        }

        /**
         * Reduces the key of the extra_user_fields array by removing the name part for custom 
         * WordPress usermeta that was introduced with version 20.
         *  
         * @since   20.0
         * 
         * @param   array   $extra_user_fields  The array of extra user fields that will be updated
         *
         * @return  void 
         */
        public static function update_user_field_key($extra_user_fields)
        {
            if (!class_exists('\Wpo\Services\User_Details_Service') || method_exists('\Wpo\Services\User_Details_Service', 'parse_user_field_key')) {
                return $extra_user_fields;
            }

            // Iterate over the configured graph fields and identify any supported expandable properties
            $extra_user_fields = array_map(function ($kv_pair) {
                $marker_pos = WordPress_Helpers::stripos($kv_pair['key'], ';#');

                if ($marker_pos > 0) {
                    $kv_pair['key'] = substr($kv_pair['key'], 0, $marker_pos);
                }

                return $kv_pair;
            }, $extra_user_fields);

            $compat_warning = sprintf(
                '%s -> The administrator configured <em>Azure AD user attributes to WordPress user meta mappings</em> on the plugin\'s <strong>User sync</strong> page. These mappings have been recently upgraded to allow administrators to specify their own name for the usermeta key. This new feature, however, breaks existing functionality. To remain compatible you should update your premium WPO365 extension and optionally update the existing mappings.',
                __METHOD__
            );

            self::compat_warning($compat_warning);

            return $extra_user_fields;
        }

        /**
         * Starting with version 31.0 mappings to save user details as WP user meta must be prefixed with their corresponding source or else WPO365 cannot decide whether
         * or not the user meta should be removed. For example, "department" may be a SAML claim for a user set to "Communications". If that user property is emptied, the
         * claim will be omitted from the SAML response (instead of being sent as a null value).
         * 
         * @param mixed $claim 
         * @return bool True if $claim has the expected prefix otherwise false 
         */
        public static function check_user_claim_prefix($claim)
        {

            if (
                WordPress_Helpers::stripos($claim, 'graph::') !== 0
                && WordPress_Helpers::stripos($claim, 'scim::') !== 0
                && WordPress_Helpers::stripos($claim, 'saml::') !== 0
                && WordPress_Helpers::stripos($claim, 'oidc::') !== 0
            ) {
                $compat_warning = sprintf(
                    'Starting with version 31.0 WPO365 requires that you add a prefix to each ID token claim (prefix: "oidc::"), SAML 2.0 claim (prefix: "saml::"), SCIM attribute (prefix: scim::) or Microsoft Graph property (prefix: "graph::") for which you have entered a mapping on the plugin\'s "User Sync" configuration page. See https://docs.wpo365.com/article/98-synchronize-microsoft-365-azure-ad-profile-fields for further details.',
                    __METHOD__
                );

                self::compat_warning($compat_warning);
                return false;
            }

            return true;
        }
    }
}
