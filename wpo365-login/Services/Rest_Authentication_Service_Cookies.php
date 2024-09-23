<?php

namespace Wpo\Services;

// Prevent public access to this script
defined('ABSPATH') or die();

use \WP_Error;
use \Wpo\Core\WordPress_Helpers;
use \Wpo\Services\Options_Service;
use \Wpo\Services\Log_Service;

if (!class_exists('\Wpo\Services\Rest_Authentication_Service_Cookies')) {

    class Rest_Authentication_Service_Cookies
    {
        /**
         * Handles the WordPress rest_authentication_errors hook. It looks for the WP REST NONCE header and if found validates it.
         * 
         * @param mixed $errors 
         * @return WP_Error|null|true
         */
        public static function authenticate_request($errors)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            // Check if we have a rule that matches the current request URI
            $wp_rest_cookies_protected_endpoints = Options_Service::get_global_list_var('wp_rest_cookies_protected_endpoints');

            // Authenticated if no rules are found
            if (empty($wp_rest_cookies_protected_endpoints)) {
                Log_Service::write_log('DEBUG', __METHOD__ . ' -> No WordPress REST API cookies protected endpoints found');
                return null;
            }

            $headers = array_change_key_case(getallheaders());

            foreach ($wp_rest_cookies_protected_endpoints as $wp_rest_cookies_protected_endpoint) {

                if (
                    empty($wp_rest_cookies_protected_endpoint['key'])
                    || empty($wp_rest_cookies_protected_endpoint['value'])
                ) {
                    Log_Service::write_log('ERROR', __METHOD__ . '-> The following WordPress REST API cookies endpoint is invalid [' . print_r($wp_rest_cookies_protected_endpoint, true) . ']');
                    continue;
                }

                // 1. REQUEST TYPE
                if (empty($_SERVER['REQUEST_METHOD']) || false === WordPress_Helpers::stripos($wp_rest_cookies_protected_endpoint['value'], $_SERVER['REQUEST_METHOD'])) {
                    Log_Service::write_log('DEBUG', __METHOD__ . '-> The type of the current request (' . $_SERVER['REQUEST_METHOD'] . ') does not match with the request type of the current rule (' . $wp_rest_cookies_protected_endpoint['value'] . ')');
                    continue;
                }

                // 2. PATH
                if (WordPress_Helpers::stripos($GLOBALS['WPO_CONFIG']['url_info']['request_uri'], $wp_rest_cookies_protected_endpoint['key']) !== false) {
                    Log_Service::write_log('DEBUG', __METHOD__ . '-> The following WordPress REST API cookies endpoint configuration will be applied [' . print_r($wp_rest_cookies_protected_endpoint, true) . ']');

                    // Check if X-WP-Nonce header is present
                    if (empty($headers['x-wp-nonce'])) {
                        Log_Service::write_log('WARN', __METHOD__ . ' -> X-WP-NONCE header missing [apache or mod_security may have removed it]');

                        return new WP_Error(
                            'wpo365_rest_auth_error',
                            '403 FORBIDDEN: X-WP-NONCE header was not found',
                            array('status' => 403)
                        );
                    }

                    if (!wp_verify_nonce($headers['x-wp-nonce'], 'wp_rest')) {
                        Log_Service::write_log('WARN', __METHOD__ . ' Validation of the X-WP-NONCE header failed');

                        return new WP_Error(
                            'wpo365_rest_auth_error',
                            '401 UNAUTHORIZED: X-WP-NONCE header appears invalid',
                            array('status' => 401)
                        );
                    }

                    $wp_usr = \wp_get_current_user();
                    wp_set_current_user($wp_usr->ID);

                    Log_Service::write_log('DEBUG', sprintf('%s -> Impersonated WordPress user with ID %s ', __METHOD__, $wp_usr->ID));

                    // Exit loop as soon as the token can be validated
                    return true;
                }
            }

            // None of the rules apply -> Another authentication handler should handle this request

            if (Options_Service::get_global_boolean_var('wp_rest_block')) {
                Log_Service::write_log('WARN', sprintf('%s -> Access to this WordPress REST API is forbidden [%s]', __METHOD__, $GLOBALS['WPO_CONFIG']['url_info']['request_uri']));

                return new WP_Error(
                    'wpo365_rest_auth_error',
                    sprintf('403 FORBIDDEN: Access to this WordPress REST API is forbidden [%s]', $GLOBALS['WPO_CONFIG']['url_info']['request_uri']),
                    array('status' => 403)
                );
            }

            return null;
        }
    }
}
