<?php

namespace Wpo\Services;

use \Wpo\Core\Url_Helpers;
use \Wpo\Core\WordPress_Helpers;
use \Wpo\Services\Authentication_Service;
use \Wpo\Services\Error_Service;
use \Wpo\Services\Id_Token_Service;
use \Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;
use \Wpo\Services\Request_Service;
use \Wpo\Services\Wp_Config_Service;

use \Wpo\Tests\Self_Test;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Services\Router_Service')) {

    class Router_Service
    {

        public static function has_route()
        {
            // initiate openidconnect / saml flow
            if (!empty($_REQUEST['action']) && $_REQUEST['action'] == 'openidredirect') {
                add_action('init', '\Wpo\Services\Router_Service::route_initiate_user_authentication');
                return true;
            }

            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            $is_oidc_response = $request->get_item('is_oidc_response');
            $is_saml_response = $request->get_item('is_saml_response');
            $mode = $request->get_item('mode');

            if (!self::skip_authentication_response($is_oidc_response, $is_saml_response)) {

                if (isset($_REQUEST['error'])) {
                    $error_string = sanitize_text_field($_REQUEST['error']) . (isset($_REQUEST['error_description']) ? \sanitize_text_field($_REQUEST['error_description']) : '');
                    Log_Service::write_log('ERROR', __METHOD__ . ' -> ' . $error_string);
                    add_action('init', '\Wpo\Services\Router_Service::route_openidconnect_error');
                    return true;
                }

                if ($is_saml_response) {

                    if ($mode == 'selfTest') {
                        add_action('init', '\Wpo\Services\Router_Service::route_plugin_selftest');
                        return true;
                    }

                    add_action('init', '\Wpo\Services\Router_Service::route_saml2_response');
                    return true;
                }

                if (Options_Service::get_global_boolean_var('use_pkce') && method_exists('\Wpo\Services\Pkce_Service', 'process_state_with_verifier')) {
                    \Wpo\Services\Pkce_Service::process_state_with_verifier();
                }

                if (!empty($_REQUEST['id_token'])) {
                    $id_token = sanitize_text_field($_REQUEST['id_token']);

                    if (true === Id_Token_Service::check_audience($id_token)) {
                        $request->set_item('encoded_id_token', $id_token);
                        unset($_REQUEST['id_token']);

                        if (!empty($_REQUEST['code'])) {
                            $request->set_item('code', sanitize_text_field($_REQUEST['code']));
                            unset($_REQUEST['code']);
                        }

                        if ($mode == 'selfTest') {
                            add_action('init', '\Wpo\Services\Router_Service::route_plugin_selftest');
                            return true;
                        }

                        add_action('init', '\Wpo\Services\Router_Service::route_openidconnect_token');
                        return true;
                    }

                    return false;
                }

                if (!empty($_REQUEST['code'])) {
                    $request->set_item('code', sanitize_text_field($_REQUEST['code']));
                    unset($_REQUEST['code']);

                    if ($mode == 'selfTest') {
                        add_action('init', '\Wpo\Services\Router_Service::route_plugin_selftest');
                        return true;
                    }

                    if ($mode == 'mailAuthorize') {
                        add_action('init', '\Wpo\Services\Router_Service::route_mail_authorize');
                        return true;
                    }

                    add_action('init', '\Wpo\Services\Router_Service::route_openidconnect_code');
                    return true;
                }
            }

            // check for user sync start request via external link
            if (!empty($_REQUEST['wpo365_sync_run']) && $_REQUEST['wpo365_sync_run'] == 'start') {

                if (!empty($_REQUEST['job_id'])) {

                    if (isset($_REQUEST['type']) && $_REQUEST['type'] == 'wpToAad') {

                        if (class_exists('\Wpo\Sync\Sync_Wp_To_Aad_Service')) {
                            \Wpo\Sync\Sync_Wp_To_Aad_Service::sync_users(sanitize_text_field($_REQUEST['job_id']));
                            exit();
                        }

                        Log_Service::write_log('WARN', sprintf('%s -> Could not start a WP to AAD User synchronization job because the required classes are not installed', __METHOD__));
                        exit();
                    }

                    if (class_exists('\Wpo\Sync\SyncV2_Service')) {

                        if (class_exists('\Wpo\Sync\Sync_Helpers')) {
                            $job = \Wpo\Sync\Sync_Helpers::get_user_sync_job_by_id($_REQUEST['job_id']);

                            if (is_wp_error($job)) {
                                Log_Service::write_log('ERROR', sprintf('%s -> An attempt to start a user-synchronization job from an external URL was detected but no job was found for ID %s', __METHOD__, $_REQUEST['job_id']));
                                exit();
                            }
                        }

                        add_action('init', function () {
                            \Wpo\Sync\SyncV2_Service::sync_users(sanitize_text_field($_REQUEST['job_id']));
                            exit();
                        }, 10);

                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Route to initialize user authentication with the option to do
         * so with OpenID Connect or with SAML.
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_initiate_user_authentication()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            /**
             * In case of multiple IdPs the type of IdP is leading the way.
             */

            if (Options_Service::get_global_boolean_var('use_saml')) {
                self::route_saml2_initiate();
            } else {
                self::route_openidconnect_initiate();
            }
        }

        /**
         * Route to redirect user to login.microsoftonline.com
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_openidconnect_initiate()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            $login_hint = !empty($_REQUEST['login_hint'])
                ? $_REQUEST['login_hint']
                : null;

            $redirect_to = !empty($_POST['redirect_to'])
                ? esc_url_raw($_POST['redirect_to'])
                : null;

            $b2c_policy = !empty($_POST['b2c_policy'])
                ? sanitize_text_field($_POST['b2c_policy'])
                : null;

            if (Options_Service::is_wpo365_configured()) {

                if (Options_Service::get_global_boolean_var('use_b2c') &&  \class_exists('\Wpo\Services\Id_Token_Service_B2c')) {
                    $authUrl = \Wpo\Services\Id_Token_Service_B2c::get_openidconnect_url($login_hint, $redirect_to, $b2c_policy);
                } else if (Options_Service::get_global_boolean_var('use_ciam')) {
                    $authUrl = \Wpo\Services\Id_Token_Service_Ciam::get_openidconnect_url($login_hint, $redirect_to);
                } else {
                    $authUrl = Id_Token_Service::get_openidconnect_url($login_hint, $redirect_to);
                }

                Url_Helpers::force_redirect($authUrl);
                exit();
            }

            Log_Service::write_log('WARN', sprintf('%s -> Attempt to initiate SSO failed because WPO365 is not configured', __METHOD__));

            $redirect_to = remove_query_arg('cb', $redirect_to);
            Url_Helpers::force_redirect($redirect_to);
        }

        /**
         * Route to redirect user to the configured SAML 2.0 IdP
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_saml2_initiate()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            $redirect_to = !empty($_POST['redirect_to'])
                ? esc_url_raw($_POST['redirect_to'])
                : null;

            $params = array();

            if (!empty($_POST['domain_hint'])) {
                $params['whr'] = sanitize_text_field(\strtolower(\trim($_POST['domain_hint'])));
            }

            if (Options_Service::is_wpo365_configured()) {
                \Wpo\Services\Saml2_Service::initiate_request($redirect_to, $params);
                exit();
            }

            Log_Service::write_log('WARN', sprintf('%s -> Attempt to initiate SSO failed because WPO365 is not configured', __METHOD__));

            $redirect_to = remove_query_arg('cb', $redirect_to);
            Url_Helpers::force_redirect($redirect_to);
        }

        /**
         * Route to redirect user to the configured SAML 2.0 IdP
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_saml2_response()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (Options_Service::is_wpo365_configured()) {
                try {
                    $wpo_usr = Authentication_Service::authenticate_saml2_user();
                    Url_Helpers::goto_after($wpo_usr);
                } catch (\Exception $e) {
                    Log_Service::write_log('ERROR', __METHOD__ . ' -> Could not process SAML 2.0 response (' . $e->getMessage() . ')');
                    Authentication_Service::goodbye(Error_Service::SAML2_ERROR);
                    exit();
                }
            }
        }

        /**
         * Route to process an incoming id token
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_openidconnect_token()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (Options_Service::get_global_boolean_var('use_id_token_parser_v2') && \class_exists('\Wpo\Services\Id_Token_Service_Deprecated')) {
                \Wpo\Services\Id_Token_Service_Deprecated::process_openidconnect_token();
            } else {
                Id_Token_Service::process_openidconnect_token();
            }

            $wpo_usr = Authentication_Service::authenticate_oidc_user();
            Url_Helpers::goto_after($wpo_usr);
        }

        /**
         * Route to process an incoming authorization code
         * 
         * @since 18.0
         * 
         * @return void
         */
        public static function route_openidconnect_code()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (strcasecmp(Options_Service::get_aad_option('oidc_flow'), 'code') === 0) {
                if (Options_Service::get_global_boolean_var('use_b2c')) {
                    \Wpo\Services\Id_Token_Service_B2c::process_openidconnect_code();
                } else if (Options_Service::get_global_boolean_var('use_ciam')) {
                    \Wpo\Services\Id_Token_Service_Ciam::process_openidconnect_code();
                } else {
                    \Wpo\Services\Id_Token_Service::process_openidconnect_code();
                }
            } else {
                Log_Service::write_log('ERROR', __METHOD__ . ' -> An authorization code was received but support for the "authorization code flow" has not been configured.');
                Authentication_Service::goodbye(Error_Service::CHECK_LOG);
                exit();
            }

            $wpo_usr = Authentication_Service::authenticate_oidc_user();
            Url_Helpers::goto_after($wpo_usr);
        }

        /**
         * Route to sign user out of WordPress and redirect to login page
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_openidconnect_error()
        {
            Authentication_Service::goodbye(Error_Service::CHECK_LOG);
            exit();
        }

        /**
         * Route to execute plugin selftest and then redirect user back to results (or landing page)
         * 
         * @since 11.0
         * 
         * @return void
         */
        public static function route_plugin_selftest()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            // Perform a self test
            new Self_Test();

            // Get redirect target (default / wpo365 not configured)
            $redirect_to = !empty($_REQUEST['redirect_to'])
                ? esc_url_raw($_REQUEST['redirect_to'])
                : $GLOBALS['WPO_CONFIG']['url_info']['current_url'];

            $redirect_to = Url_Helpers::get_redirect_url($redirect_to);

            $redirect_to = remove_query_arg('flushPermaLinks', $redirect_to);
            $redirect_to = remove_query_arg('mode', $redirect_to);

            Url_Helpers::force_redirect($redirect_to);
        }

        /**
         * Route to execute mail authorization with delegated permissions.
         * 
         * @since 19.0
         * 
         * @return void
         */
        public static function route_mail_authorize()
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            // Get redirect target (default / wpo365 not configured)
            $redirect_to = !empty($_REQUEST['redirect_to'])
                ? esc_url_raw($_REQUEST['redirect_to'])
                : $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

            // Try update redirect target (wpo365 configured)
            $redirect_url = Url_Helpers::get_redirect_url($redirect_to);

            // Check if wp_mail has been plugged
            if (false === \Wpo\Mail\Mailer::check_wp_mail()) {
                Url_Helpers::force_redirect($redirect_url);
            }

            // Process the incoming authorization code and request an ID token, access and refresh token.
            $scope = Options_Service::get_global_boolean_var('mail_send_shared') ? 'https://graph.microsoft.com/Mail.Send.Shared' : 'https://graph.microsoft.com/Mail.Send';
            \Wpo\Services\Id_Token_Service::process_openidconnect_code($scope, false);

            // Uses the tokens received to create a mail user object
            $authorization_result = \Wpo\Mail\Mail_Authorization_Helpers::authorize_mail_user();

            if (is_wp_error($authorization_result)) {
                Log_Service::write_log('ERROR', sprintf(
                    '%s -> Mail authorization failed. [%s]',
                    __METHOD__,
                    $authorization_result->get_error_message()
                ));
            }

            Url_Helpers::force_redirect($redirect_url);
        }

        /**
         * Analyzes the state URL, memoizes any of the WPO365 internal parameters and removes those.
         * 
         * @since 19.0
         * 
         * @param mixed $url 
         * @param mixed $request 
         * @return string|bool Returns false if the URL is not valid and otherwise the state URL with any of the WPO365 internal parameters removed. 
         */
        public static function process_state_url($url, $request)
        {
            $url = urldecode($url);
            $url = wp_sanitize_redirect($url);
            $url = str_replace('#', '_____', $url); // Replace the hash because parse_url does not handle it well

            if (WordPress_Helpers::stripos($url, '/') === 0) {
                $url = sprintf(
                    '%s%s',
                    $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'],
                    ltrim($url, '/')
                );
            }

            if (false === filter_var($url, FILTER_VALIDATE_URL) || WordPress_Helpers::stripos($url, 'http') !== 0) {
                return false;
            }

            $query = parse_url($url, PHP_URL_QUERY);

            if (empty($query)) {
                $result = array();
            } else {
                parse_str($query, $result);
            }

            if (isset($result['mode'])) {
                $mode = $result['mode'];
                $request->set_item('mode', $mode);
                $url = remove_query_arg('mode', $url);
            }

            if (isset($result['tfp'])) {
                $tfp = $result['tfp'];
                $request->set_item('tfp', $tfp);
                $url = remove_query_arg('tfp', $url);
            }

            if (isset($result['idp_id'])) {
                $idp_id = $result['idp_id'];
                $request->set_item('idp_id', $idp_id);
                $url = remove_query_arg('idp_id', $url);
            }

            if (isset($result['pkce_code_challenge_id'])) {
                $pkce_code_challenge_id = $result['pkce_code_challenge_id'];
                $request->set_item('pkce_code_challenge_id', $pkce_code_challenge_id);
                $url = remove_query_arg('pkce_code_challenge_id', $url);
            }

            // If state is a relative URL then try to fix it.
            if (WordPress_Helpers::strpos($url, '/') === 0) {
                $url = sprintf(
                    '%s%s',
                    WordPress_Helpers::rtrim($GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'], '/'),
                    $url
                );
            }

            $url = str_replace('_____', '#', $url);
            return $url;
        }

        /**
         * Checks if WPO365 should process an authentication response that it has detected by comparing the current URL with the Redirect URI.
         * 
         * @since 25.0
         * 
         * @return bool True if WPO365 should continue processing the authentication response.
         */
        private static function skip_authentication_response($is_oidc_response, $is_saml_response)
        {
            $request_service = Request_Service::get_instance();
            $request = $request_service->get_request($GLOBALS['WPO_CONFIG']['request_id']);

            // Skip if there is no authentication response to process or the admin has disabled SSO and the Graph Mailer or the Graph Mailer is enabled but the reponse is for mail-authorization
            $no_auth_response = !$is_oidc_response && !$is_saml_response;
            $no_sso = Options_Service::get_global_boolean_var('no_sso', false);
            $use_mailer =  Options_Service::get_global_boolean_var('use_graph_mailer', false);

            if ($no_auth_response || ($no_sso && !$use_mailer) || ($no_sso && $use_mailer && $request->get_item('mode') != 'mailAuthorize')) {
                return true; // Nothing to do
            }

            // Don't skip if strict-mode has not been enabled
            if (!Options_Service::get_aad_option('redirect_url_strict', true)) {
                return false;
            }

            $home_url = get_option('home');

            if ($is_oidc_response) {
                $redirect_url = Options_Service::get_aad_option('redirect_url');
            }

            if ($is_saml_response) {
                $redirect_url = Options_Service::get_aad_option('saml_sp_acs_url');
            }

            $redirect_url = apply_filters('wpo365/aad/redirect_uri', $redirect_url);

            if (empty($home_url) || empty($redirect_url)) {
                Log_Service::write_log('WARN', sprintf(
                    '%s -> The administrator has configured Redirect URI "strict mode" but either the home address URL (%s) or the AAD redirect URI (%s) appears to be empty and "strict mode" can therefore not be enforced.',
                    __METHOD__,
                    $home_url,
                    $redirect_url
                ));
                return false;
            }

            $home_url = Url_Helpers::undress_url($home_url);
            $redirect_url = Url_Helpers::undress_url($redirect_url);

            if (0 === strcasecmp($home_url, $redirect_url)) {
                Log_Service::write_log('WARN', sprintf(
                    '%s -> The administrator has configured Redirect URI "strict mode" but the home address URL (%s) and the AAD redirect URI (%s) appear to be equal and therefore "strict mode" cannot be enforced. For "strict mode", the Redirect URI must end with a specific path e.g. %s',
                    __METHOD__,
                    $home_url,
                    $redirect_url,
                    sprintf('%s/sso_auth/', $home_url)
                ));
                return false;
            }

            $current_url = $GLOBALS['WPO_CONFIG']['url_info']['current_url'];

            if (empty($current_url)) {
                Log_Service::write_log('WARN', sprintf(
                    '%s -> The administrator has configured Redirect URI "strict mode" but WPO365 cannot determine the current URL and therefore "strict mode" cannot be enforced.',
                    __METHOD__
                ));
                return false;
            }

            $current_url = Url_Helpers::undress_url($current_url);

            if (0 === strcasecmp($current_url, $redirect_url)) {
                Log_Service::write_log('DEBUG', sprintf(
                    '%s -> The administrator has configured Redirect URI "strict mode" and the current URL (%s) is equal to the redirect URI (%s) and therefore WPO365 will process the OIDC / SAML 2.0 payload.',
                    __METHOD__,
                    $current_url,
                    $redirect_url
                ));
                return false;
            }

            Log_Service::write_log('DEBUG', sprintf(
                '%s -> The administrator has configured Redirect URI "strict mode" and the current URL (%s) is not equal to the redirect URI (%s) and therefore WPO365 will not process the OIDC / SAML 2.0 payload.',
                __METHOD__,
                $current_url,
                $redirect_url
            ));

            return true;
        }
    }
}
