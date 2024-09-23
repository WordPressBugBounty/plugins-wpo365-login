<?php

namespace Wpo\Tests;

use \Wpo\Core\WordPress_Helpers;
use \Wpo\Services\Options_Service;

// Prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Tests\Test_Configuration')) {

    class Test_Configuration
    {

        private $use_saml = false;
        private $use_b2c = false;
        private $use_ciam = false;
        private $no_sso = false;

        public function __construct()
        {
            $this->use_saml = Options_Service::get_global_boolean_var('use_saml');
            $this->use_b2c = Options_Service::get_global_boolean_var('use_b2c');
            $this->use_ciam = Options_Service::get_global_boolean_var('use_ciam');
            $this->no_sso = Options_Service::get_global_boolean_var('no_sso');
        }

        public function test_tenant_id()
        {
            $test_result = new Test_Result('Tenant ID has been configured', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_BLOCKING);
            $test_result->passed = true;

            $tenant_id = Options_Service::get_aad_option('tenant_id');

            if (empty($tenant_id)) {
                $test_result->passed = false;
                $test_result->message = "Tenant ID is not configured. If you only plan to enable the <strong>Mail</strong> feature then you can safely ignore this eror. Otherwise, please copy the 'Directory (tenant) ID' from your Azure AD App registration's 'Overview' page and paste it into the corresponding field on the plugin's <a href=\"#singleSignOn\">Single Sign-on</a> page.";
                $test_result->more_info = 'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow';
            } elseif (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-5][0-9a-f]{3}-[089ab][0-9a-f]{3}-[0-9a-f]{12}$/', $tenant_id)) {
                $test_result->passed = false;
                $test_result->message = "Tenant ID is not a valid GUID but required for all supported features. Please copy the 'Directory (tenant) ID' from your Azure AD App registration's 'Overview' page and paste it into the corresponding field on the plugin's <a href=\"#singleSignOn\">Single Sign-on</a> page.";
                $test_result->more_info = 'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow';
            }

            return $test_result;
        }

        public function test_auth_scenario_internet_optimization()
        {
            $is_optimized = defined('WPO_AUTH_SCENARIO') && constant('WPO_AUTH_SCENARIO') == 'internet';

            if (!$is_optimized) {
                return;
            }

            $test_result = new Test_Result('"Internet" mode optimization', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_CRITICAL);
            $test_result->passed = true;

            /**
             * @since 24.0 Filters the AAD Redirect URI e.g. to set it dynamically to the current host.
             */

            $redirect_url = Options_Service::get_aad_option('redirect_url');
            $redirect_url = apply_filters('wpo365/aad/redirect_uri', $redirect_url);


            if ($is_optimized && (empty($redirect_url) || false === WordPress_Helpers::stripos($redirect_url, '/wp-admin'))) {
                $test_result->passed = false;
                $test_result->message = "Since you configured <i>define( 'WPO_AUTH_SCENARIO', 'internet' );</i> you must ensure that the Redirect URI ends with '/wp-admin/'. Please update the Redirect URI first in <strong>Azure AD</strong> for your <i>App registration</i> and then subsequently on the plugin's <a href=\"#singleSignOn\">Single Sign-on</a> page.";
                $test_result->more_info = 'https://docs.wpo365.com/article/36-authentication-scenario';
            }

            return $test_result;
        }

        public function test_debug_mode_enabled()
        {
            $test_result = new Test_Result('Debug log disabled', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_CRITICAL);
            $test_result->passed = true;

            $debug_log = Options_Service::get_global_boolean_var('debug_log');

            if (true === $debug_log) {
                $test_result->passed = false;
                $test_result->message = 'Please disable debug log to improve overall performance of your website. Navigate to <a href="#debug">Debug</a> to disable the debug log.';
                $test_result->more_info = 'https://docs.wpo365.com/article/19-enable-debug-log';
            }

            return $test_result;
        }

        public function test_using_https()
        {
            $test_result = new Test_Result('Correct use of HTTPS', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_CRITICAL);
            $test_result->passed = true;

            $aad_redirect_url = Options_Service::get_aad_option('redirect_url'); // At the moment we don't apply the wpo365/aad/redirect_uri filter
            $wp_home = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

            if (WordPress_Helpers::stripos($aad_redirect_url, 'http://') === 0  && WordPress_Helpers::stripos($aad_redirect_url, 'localhost') === false) {
                $test_result->passed = false;
                $test_result->message = '(Azure AD) Redirect URL must start with https://. Navigate to <a href="#singleSignOn">Single Sign-on</a> and update the Redirect URL and make sure that the Redirect URI that you entered for your Azure AD App registration also starts with https://. If your website does not support SSL then please purchase an SSL certificate and configure this for your website. You can only use an insecure website address for development purposes that use "localhost".';
                $test_result->more_info = '';
            }

            return $test_result;
        }

        public function test_domain_hint()
        {

            if ($this->use_saml || $this->use_b2c || $this->use_ciam || $this->no_sso) {
                return;
            }

            $domain_hint = Options_Service::get_global_string_var('domain_hint');

            if (empty($domain_hint)) {
                return;
            }

            $test_result = new Test_Result('Domain hint configured', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_LOW);
            $test_result->passed = true;


            $custom_domain = Options_Service::get_global_list_var('custom_domain');

            $domain_hint_custom_domain = array_filter($custom_domain, function ($key) use ($domain_hint) {
                return WordPress_Helpers::stripos($key, $domain_hint) !== false;
            });

            if (empty($domain_hint_custom_domain)) {
                $test_result->passed = false;
                $test_result->message = 'Domain hint must be present in the list of <em>Custom domains</em> on the plugin\'s <a href="#userRegistration">User registration</a> configuration page (and thus present in the list of <a href="https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Domains" target="_blank">custom domains added to Azure AD</a>).';
                $test_result->more_info = 'https://docs.wpo365.com/article/35-domain-hint';
            }

            return $test_result;
        }

        public function test_custom_domain()
        {

            if ($this->use_b2c || $this->use_ciam || $this->no_sso) {
                return;
            }

            $test_result = new Test_Result('Custom domain names configured', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_CRITICAL);
            $test_result->passed = true;

            $custom_domain = Options_Service::get_global_list_var('custom_domain');

            if (empty($custom_domain)) {
                $test_result->passed = false;
                $test_result->message = "You have not configured at least one custom domain. Please check your <a href=\"https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Domains\" target=\"_blank\">Custom domain names</a> in Azure Portal and add the domain names on the plugin's <a href=\"#userRegistration\">User registration</a> page accordingly. Please press '+' after each entry to add the custom domain name to the list.";
                $test_result->more_info = 'https://docs.wpo365.com/article/48-custom-domains';
                return $test_result;
            }

            return $test_result;
        }

        public function test_wpo365_rest_api()
        {
            $test_result = new Test_Result('Custom REST API endpoint has been added', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_CRITICAL);
            $test_result->passed = true;

            $allowed_urls = Options_Service::get_global_list_var('pages_blacklist');
            $found = false;

            foreach ($allowed_urls as $url) {

                if (false !== WordPress_Helpers::stripos($url, '/wp-json/wpo365/v1/')) {
                    $found = true;
                    break;
                }
            }

            if (empty($found)) {
                $test_result->passed = false;
                $test_result->message = 'Add "/wp-json/wpo365/v1/" to the list of pages freed from authentication on the plugin\'s <a href="#singleSignOn">Single Sign-on</a> configuration page if you intend to synchronize users and / or add one of the Microsoft 365 Apps for WordPress to a page or post.';
                $test_result->more_info = 'https://docs.wpo365.com/article/37-pages-blacklist';
                $test_result->fix = array(
                    array(
                        'op' => 'add',
                        'value' => array(
                            'pagesBlacklist' => '/wp-json/wpo365/v1/',
                        ),
                    ),
                );
                return $test_result;
            }

            return $test_result;
        }

        public function test_rewrite_rules()
        {
            $test_result = new Test_Result('WordPress permalink structure has been updated', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_BLOCKING);
            $test_result->passed = true;

            global $wp_rewrite;

            if (empty($wp_rewrite->permalink_structure)) {
                $test_result->passed = false;
                $test_result->message = sprintf('You must <a href="%s">update your permalink structure</a> to something other than the default (= Plain or Ugly Permalinks) for the custom WPO365 REST APIs to work.', admin_url('options-permalink.php'));
                $test_result->more_info = 'https://wordpress.org/support/article/using-permalinks/';
                return $test_result;
            }
        }
    }
}
