<?php

namespace Wpo\Tests;

use Wpo\Core\Extensions_Helpers;
use Wpo\Core\User;
use Wpo\Core\WordPress_Helpers;

use Wpo\Services\Access_Token_Service;
use Wpo\Services\Graph_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;
use Wpo\Services\User_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Tests\Test_Access_Tokens' ) ) {

	class Test_Access_Tokens {


		private $delegated_access_token             = null;
		private $delegated_access_token_test_result = null;
		private $delegated_static_permissions       = array();

		private $application_access_token             = null;
		private $application_access_token_test_result = null;
		private $application_static_permissions       = array();

		private $no_sso                  = false;
		private $use_saml                = false;
		private $use_b2c                 = false;
		private $use_ciam                = false;
		private $graph_version_beta      = false;
		private $extensions              = array();
		private $request                 = null;
		private $wpo_usr                 = null;
		private $hostname                = null;
		private $mail_auth_configuration = null;
		private $tld                     = '.com';

		public function __construct() {
			$tld                      = Options_Service::get_aad_option( 'tld' );
			$this->tld                = empty( $tld ) ? '.com' : $tld;
			$this->no_sso             = Options_Service::get_global_boolean_var( 'no_sso' );
			$this->use_saml           = Options_Service::get_global_boolean_var( 'use_saml' );
			$this->use_b2c            = Options_Service::get_global_boolean_var( 'use_b2c' );
			$this->use_ciam           = Options_Service::get_global_boolean_var( 'use_ciam' );
			$this->graph_version_beta = Options_Service::get_global_string_var( 'graph_version' ) === 'beta';
			$this->extensions         = Extensions_Helpers::get_active_extensions();
		}

		/**
		 * INIT
		 */
		public function test_initialize() {
			$request_service = Request_Service::get_instance();
			$this->request   = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			if ( $this->no_sso ) {
				$queries = array();

				if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { //phpcs:ignore
					parse_str( $_SERVER['QUERY_STRING'], $queries ); //phpcs:ignore
				}

				if ( isset( $queries['upn'] ) ) {
					$upn                = filter_var( $queries['upn'], FILTER_SANITIZE_EMAIL );
					$this->wpo_usr      = new User();
					$this->wpo_usr->upn = $upn;
					$this->wpo_usr->oid = $upn;
				}
			} else {
				$this->wpo_usr = $this->request->get_item( 'wpo_usr' );
			}

			$this->get_delegated_access_token();
			$this->get_application_access_token();
		}

		/**
		 * ACCESS TOKENS
		 */
		public function test_access_token_delegated() {

			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			if ( ! $this->delegated_access_token_test_result->passed ) {
				return $this->delegated_access_token_test_result;
			}
		}

		/**
		 * ACCESS TOKENS -> PERMISSIONS
		 */
		public function test_access_token_static_permissions_openid() {

			if ( $this->no_sso || $this->use_saml || $this->use_b2c || ! $this->delegated_access_token_test_result->passed ) {
				return;
			}

			return $this->check_static_permission(
				$this->delegated_access_token,
				'openid',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_BLOCKING,
				Test_Result::CAPABILITY_OIC_SSO,
				'and as a result the plugin may not be able to request (OpenID Connect related) ID tokens. Please add the following API Permission (for the (Azure AD) <em>registered application</em> in Azure Portal): Microsoft Graph > Delegated > openid.',
				'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow'
			);
		}

		/**
		 * ACCESS TOKENS -> PERMISSIONS
		 */
		public function test_access_token_static_permissions_email() {

			if ( $this->no_sso || $this->use_saml || $this->use_b2c || ! $this->delegated_access_token_test_result->passed ) {
				return;
			}

			return $this->check_static_permission(
				$this->delegated_access_token,
				'email',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_CRITICAL,
				Test_Result::CAPABILITY_OIC_SSO,
				'and as a result the plugin may fail to match an Entra ID / AAD user by his / her email address. Please add the following API Permission (for the (Azure AD) <em>registered application</em> in Azure Portal): Microsoft Graph > Delegated > offline_access.',
				'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow'
			);
		}

		/**
		 * ACCESS TOKENS -> PERMISSIONS
		 */
		public function test_access_token_static_permissions_user_read() {

			if ( $this->no_sso || $this->use_saml || $this->use_b2c || ! $this->delegated_access_token_test_result->passed ) {
				return;
			}

			return $this->check_static_permission(
				$this->delegated_access_token,
				'user.read',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_BLOCKING,
				Test_Result::CAPABILITY_OIC_SSO,
				'and as a result the plugin will not be able to sign in as a user. Please add the following API Permission (for the (Azure AD) <em>registered application</em> in Azure Portal): Microsoft Graph > Delegated > User.Read.',
				'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow'
			);
		}

		/**
		 * ACCESS TOKENS -> REFRESH TOKEN
		 */
		public function test_refresh_token_delegated() {

			if ( $this->no_sso || $this->use_saml || $this->use_b2c || ! $this->delegated_access_token_test_result->passed ) {
				return;
			}

			$test_result         = new Test_Result( 'Access token for <em>delegated</em> permissions includes a refresh token.', Test_Result::CAPABILITY_ACCESS_TOKENS, Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			if ( ! property_exists( $this->delegated_access_token, 'refresh_token' ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'Access token for <em>delegated</em> permissions does not include a refresh token. Please add the following API Permission (for the (Azure AD) <em>registered application</em> in Azure Portal): Microsoft Graph > Delegated > offline_access.';
				$test_result->more_info = '';
				return $test_result;
			}

			return $test_result;
		}

		/**
		 * ACCESS TOKENS -> GRAPH VERION
		 */
		public function test_graph_version() {
			$test_result         = new Test_Result( '<em>Beta</em> version of Microsoft Graph selected.', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( ! $this->graph_version_beta ) {
				$test_result->passed    = false;
				$test_result->message   = 'Update the Microsoft Graph version to <em>beta</em> on the plugin\'s <a href=\"#integration\">integration</a> page.';
				$test_result->more_info = 'https://docs.wpo365.com/article/67-microsoft-graph-version';
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'graphVersion' => 'beta',
						),
					),
				);
				return $test_result;
			}

			return $test_result;
		}

		/**
		 * PROFILE+ | DELEGATED
		 */
		public function test_profile_plus_delegated() {

			// Skip test if using SAML
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Get a user\'s basic profile (name and email address) with <em>delegated</em> permissions from Microsoft Graph.', Test_Result::CAPABILITY_PROFILE_PLUS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			// Check if suitable extensions can be found
			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-login-plus/wpo365-login.php',
					'wpo365-mail/wpo365-mail.php',
					'wpo365-login-professional/wpo365-login.php',
					'wpo365-avatar/wpo365-avatar.php',
					'wpo365-custom-fields/wpo365-custom-fields.php',
					'wpo365-groups/wpo365-groups.php',
					'wpo365-roles-access/wpo365-roles-access.php',
					'wpo365-scim/wpo365-scim.php',
					'wpo365-customers/wpo365-customers.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-essentials/wpo365-essentials.php',
				)
			);

			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the PROFILE+ feature. Please note that the WPO365 | LOGIN will nevertheless populate a new WordPress user\'s name and email profile attributes when those attributes were found in the ID token or SAML 2.0 response, upon login.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if access token with delegated permissions can be found
			if ( ! $this->delegated_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->delegated_access_token_test_result->message;
				$test_result->more_info = $this->delegated_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_profile = $this->check_static_permission(
				$this->delegated_access_token,
				'profile',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_PROFILE_PLUS,
				'and as a result the ID token will not contain basic profile information.',
				'https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow'
			);

			if ( ! $test_result_profile->passed ) {
				$test_result->passed    = $test_result_profile->passed;
				$test_result->message   = $test_result_profile->message;
				$test_result->more_info = $test_result_profile->more_info;
				return $test_result;
			}

			// Check if a user can be retrieved from Microsoft Graph
			if ( \class_exists( '\Wpo\Services\User_Details_Service' ) ) {

				if ( ! empty( $this->wpo_usr ) && ! empty( $this->wpo_usr->oid ) ) {
					$graph_user        = \Wpo\Services\User_Details_Service::get_graph_user( $this->wpo_usr->oid, false, true, true );
					$test_result->data = $graph_user;
				}

				if ( is_wp_error( $test_result->data ) || empty( $test_result->data ) || ! isset( $test_result->data['@odata.context'] ) ) {
					$test_result->passed  = false;
					$test_result->message = 'Could not retrieve a user resource from Microsoft Graph for current user. Click "View" for details.';
				} else {
					// Save wpo_usr for later use
					$this->wpo_usr = User_Service::user_from_graph_user( $test_result->data );
				}
			}

			return $test_result;
		}

		/**
		 * PROFILE+ | APPLICATION
		 */
		public function test_profile_plus_application() {

			// Skip test if using B2C and instead recommend to use ID token as source for profile fields
			if ( $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Get a user\'s basic profile (name and email address) with <em>application</em> permissions from Microsoft Graph.', Test_Result::CAPABILITY_PROFILE_PLUS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			// Check if suitable extension can be found
			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-login-plus/wpo365-login.php',
					'wpo365-mail/wpo365-mail.php',
					'wpo365-login-professional/wpo365-login.php',
					'wpo365-avatar/wpo365-avatar.php',
					'wpo365-custom-fields/wpo365-custom-fields.php',
					'wpo365-groups/wpo365-groups.php',
					'wpo365-roles-access/wpo365-roles-access.php',
					'wpo365-scim/wpo365-scim.php',
					'wpo365-customers/wpo365-customers.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-essentials/wpo365-essentials.php',
				)
			);

			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the PROFILE+ feature. Please note that the WPO365 | LOGIN will nevertheless populate a new WordPress user\'s name and email profile attributes when those attributes were found in the ID token or SAML 2.0 response, upon login.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if access token with application permissions can be found
			if ( ! $this->application_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->application_access_token_test_result->message;
				$test_result->more_info = $this->application_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->application_access_token,
				'user.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_PROFILE_PLUS,
				'and as a result the plugin cannot independently from the logged-in user retrieve user profile information from Azure AD using Microsoft Graph.',
				'https://docs.wpo365.com/article/23-integration'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$test_result->passed    = $test_result_user_read_all->passed;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = $test_result_user_read_all->more_info;
				return $test_result;
			}

			// Check if a user can be retrieved from Microsoft Graph
			if ( \class_exists( '\Wpo\Services\User_Details_Service' ) ) {

				if ( ! empty( $this->wpo_usr ) && ! empty( $this->wpo_usr->oid ) ) {
					$graph_user        = \Wpo\Services\User_Details_Service::get_graph_user( $this->wpo_usr->oid, false, false, true );
					$test_result->data = $graph_user;
				}

				if ( is_wp_error( $test_result->data ) || empty( $test_result->data ) || ! isset( $test_result->data['@odata.context'] ) ) {
					$test_result->passed  = false;
					$test_result->message = 'Could not retrieve a user resource from Microsoft Graph for current user. Click "View" for details.';
				} else {
					// Save wpo_usr for later use
					$this->wpo_usr = User_Service::user_from_graph_user( $test_result->data );
				}
			}

			return $test_result;
		}

		/**
		 * MAIL
		 */
		public function test_mail() {
			$test_result         = new Test_Result( 'Send WordPress emails using Microsoft Graph.', Test_Result::CAPABILITY_MAIL, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( Options_Service::get_global_boolean_var( 'use_graph_mailer' ) ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Enable sending WordPress emails using Microsoft Graph on the plugin's <a href=\"#mail\">Mail</a> configuration page.";
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'useGraphMailer' => true,
						),
					),
				);
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			$this->mail_auth_configuration = \Wpo\Mail\Mail_Authorization_Helpers::get_mail_auth_configuration( false, false );

			if ( $this->mail_auth_configuration->delegated_authorized && $this->mail_auth_configuration->app_only_authorized ) {
				$test_result->passed    = false;
				$test_result->message   = 'It appears that you have configured both <strong>application</strong> and <strong>delegated</strong> permissions for <em>Mail.Send</em>.  
                    If you do not need the plugin\'s ability to send emails from multiple different accounts then it is strongly recommended that you remove 
                    <strong>application</strong> permissions for <em>Mail.Send</em>. The reason behind this recommendation is the general trend to configure as few and as low permissions as 
                    possible. <strong>Application</strong> <em>Mail.Send</em> permissions allow the plugin to send emails as any user in the organization whereas <strong>
                    delegated</strong> <em>Mail.Send</em> permissions reduce this to a single account.<br/><div style="color: #0078D4;">If you just changed <strong>delegated</strong> API Permissions in Azure AD 
                    then please re-authorize the mail user on the plugin\'s <strong>Mail</strong> configuration page and before runing the Plugin self-test again.</div>';
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			if ( $this->mail_auth_configuration->delegated_authorized && $this->mail_auth_configuration->has_refresh_token ) {
				return $test_result;
			}

			if ( ! $this->mail_auth_configuration->delegated_authorized ) {
				$test_result->passed    = false;
				$test_result->message   = sprintf(
					'You have configured the WPO365 plugin to send WordPress emails using Microsoft Graph but you have not configured <strong>delegated</strong> <em>Mail.Send</em> 
                    permissions and authorized an account to send mail from (using the <strong>Authorize</strong> function on the plugin\'s <a href="#mail">Mail</a> configuration page). %s',
					! $this->mail_auth_configuration->app_only_authorized ? '' : 'However, it appears that you have configured <strong>application</strong> <em>Mail.Send</em> permissions.  
                    If you do not need the plugin\'s ability to send emails from multiple different accounts then it is strongly recommended that you remove 
                    <strong>application</strong> permissions for <em>Mail.Send</em>. The reason behind this recommendation is the general trend to configure as few and as low permissions as 
                    possible. <strong>Application</strong> <em>Mail.Send</em> permissions allow the plugin to send emails as any user in the organization whereas <strong>
                    delegated</strong><em>Mail.Send</em> permissions reduce this to a single account.'
				);
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			if ( ! $this->mail_auth_configuration->has_refresh_token ) {
				$test_result->passed    = false;
				$test_result->message   = 'You have configured the WPO365 plugin to send WordPress emails using Microsoft Graph but you have not configured <strong>delegated</strong> 
                    <em>offline_access</em> permissions. As a result, the WPO365 plugin cannot refresh the access token silently and thus will fail to send emails as soon as the access token 
                    is expired (by default after 60 minutes).';
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}
		}

		public function test_mail_shared() {
			$test_result         = new Test_Result( 'Send WordPress emails from a Shared Mailbox using Microsoft Graph.', Test_Result::CAPABILITY_MAIL, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( Options_Service::get_global_boolean_var( 'use_graph_mailer' ) ) ) {
				return;
			}

			// Check if suitable extension can be found
			$suitable_extensions = \array_flip(
				array(
					'wpo365-mail/wpo365-mail.php',
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				return;
			}

			if ( $this->mail_auth_configuration === null ) {
				$this->mail_auth_configuration = \Wpo\Mail\Mail_Authorization_Helpers::get_mail_auth_configuration( false, false );
			}

			$mail_application_id = Options_Service::get_mail_option( 'mail_application_id' );

			if ( empty( $mail_application_id ) ) {
				$mail_application_id = Options_Service::get_aad_option( 'application_id' );
			}

			if ( empty( $this->mail_auth_configuration->delegated_shared_authorized ) && empty( $this->mail_auth_configuration->app_only_authorized ) ) {
				$test_result->passed    = false;
				$test_result->message   = "You have not granted delegated <em>Mail.Send.Shared</em> permissions in Azure AD for App registration with ID 
                                         <strong>$mail_application_id</strong> (or alternatively granted application-level <em>Mail.Send</em> permissions) 
                                         and as a result you cannot send WordPress emails from a Microsoft 365 Shared Mailbox. You can safely ignore this 
                                         if you do not wish to send WordPress emails from such a Shared Mailbox.<br/><div style=\"color: #0078D4;\">If 
                                         you just changed <strong>delegated</strong> API Permissions in Azure AD then please re-authorize the mail user 
                                         on the plugin\'s <strong>Mail</strong> configuration page before running the Plugin self-test again.</div>";
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			return $test_result;
		}

		public function test_mail_large_attachments() {
			$test_result         = new Test_Result( 'Send WordPress emails with attachments larger than 3 MB using Microsoft Graph.', Test_Result::CAPABILITY_MAIL, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( Options_Service::get_global_boolean_var( 'use_graph_mailer' ) ) ) {
				return;
			}

			// Check if suitable extension can be found
			$suitable_extensions = \array_flip(
				array(
					'wpo365-mail/wpo365-mail.php',
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				return;
			}

			if ( ! class_exists( '\Wpo\Mail\Mail_Attachments' ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'There is a newer version available for your premium WPO365 plugin(s) that supports sending WordPress emails with attachments larger than 3 MB. Please update now.';
				$test_result->more_info = 'https://docs.wpo365.com/article/13-update-the-wpo365-plugin-to-the-latest-version';
				return $test_result;
			}

			if ( $this->mail_auth_configuration === null ) {
				$this->mail_auth_configuration = \Wpo\Mail\Mail_Authorization_Helpers::get_mail_auth_configuration( false, false );
			}

			$mail_application_id = Options_Service::get_mail_option( 'mail_application_id' );

			if ( empty( $mail_application_id ) ) {
				$mail_application_id = Options_Service::get_aad_option( 'application_id' );
			}

			if ( empty( $this->mail_auth_configuration->delegated_readwrite_authorized ) && empty( $this->mail_auth_configuration->app_only_readwrite_authorized ) ) {
				$test_result->passed    = false;
				$test_result->message   = "You have not granted delegated <em>Mail.ReadWrite</em> permissions in Azure AD for App registration with ID 
                                         <strong>$mail_application_id</strong> (or alternatively granted application-level <em>Mail.ReadWrite</em> 
                                         permissions) and as a result you cannot send attachments larger than 3 MB from WordPress using Microsoft Graph.
                                         <br/><div style=\"color: #0078D4;\">If you just changed <strong>delegated</strong> API Permissions in Azure AD then 
                                         please re-authorize the mail user on the plugin\'s <strong>Mail</strong> configuration page before running 
                                         the Plugin self-test again.</div>";
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			return $test_result;
		}

		public function test_mail_large_attachments_shared() {
			$test_result         = new Test_Result( 'Send WordPress emails with attachments larger than 3 MB using Microsoft Graph from a Shared Mailbox.', Test_Result::CAPABILITY_MAIL, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( Options_Service::get_global_boolean_var( 'use_graph_mailer' ) ) ) {
				return;
			}

			// Check if suitable extension can be found
			$suitable_extensions = \array_flip(
				array(
					'wpo365-mail/wpo365-mail.php',
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				return;
			}

			if ( ! class_exists( '\Wpo\Mail\Mail_Attachments' ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'There is a newer version available for your premium WPO365 plugin(s) that supports sending WordPress emails with attachments larger than 3 MB. Please update now.';
				$test_result->more_info = 'https://docs.wpo365.com/article/13-update-the-wpo365-plugin-to-the-latest-version';
				return $test_result;
			}

			if ( $this->mail_auth_configuration === null ) {
				$this->mail_auth_configuration = \Wpo\Mail\Mail_Authorization_Helpers::get_mail_auth_configuration( false, false );
			}

			$mail_application_id = Options_Service::get_mail_option( 'mail_application_id' );

			if ( empty( $mail_application_id ) ) {
				$mail_application_id = Options_Service::get_aad_option( 'application_id' );
			}

			if ( empty( $this->mail_auth_configuration->delegated_readwrite_shared_authorized ) && empty( $this->mail_auth_configuration->app_only_readwrite_authorized ) ) {
				$test_result->passed    = false;
				$test_result->message   = "You have not granted delegated <em>Mail.ReadWrite.Shared</em> permissions in Azure AD for App registration with ID 
                                         <strong>$mail_application_id</strong> (or alternatively granted application-level <em>Mail.ReadWrite</em> permissions) 
                                         and as a result you cannot send attachments larger than 3 MB from WordPress using Microsoft Graph from a Microsoft 365 Shared 
                                         Mailbox. You can safely ignore this if you do not wish to send WordPress emails from such a Shared Mailbox.
                                         <br/><div style=\"color: #0078D4;\">If you just changed <strong>delegated</strong> API Permissions in Azure AD then 
                                         please re-authorize the mail user on the plugin\'s <strong>Mail</strong> configuration page before running
                                         the Plugin self-test again.</div>";
				$test_result->more_info = 'https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph';
				return $test_result;
			}

			return $test_result;
		}

		/**
		 * ROLES + ACCESS | DELEGATED
		 */
		public function test_roles_access_delegated() {

			// Skip if using SAML
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Get a user\'s Azure AD group memberships with <em>delegated</em> permissions.', Test_Result::CAPABILITY_ROLES_ACCESS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-roles-access/wpo365-roles-access.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			// Check if suitable extensions can be found
			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the ROLES + ACCESS / AUDIENCES feature.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if suitable access token with delegated permissions can be found
			if ( ! $this->delegated_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->delegated_access_token_test_result->message;
				$test_result->more_info = $this->delegated_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_groupmember_read_all = $this->check_static_permission(
				$this->delegated_access_token,
				'groupmember.read.all',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			// Check if access token has appropriate permissions
			$test_result_group_read_all = $this->check_static_permission(
				$this->delegated_access_token,
				'group.read.all',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->delegated_access_token,
				'user.read.all',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$application_id         = Options_Service::get_aad_option( 'application_id' );
				$test_result->passed    = false;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = 'https://docs.wpo365.com/article/23-integration';
				return $test_result;
			}

			if ( ! $test_result_groupmember_read_all->passed && $test_result_group_read_all->passed ) {
				$application_id         = Options_Service::get_aad_option( 'application_id' );
				$test_result->passed    = false;
				$test_result->message   = "Static permission <strong>Group.Read.All</strong> has been configured for the (Azure AD) <em>registered application</em> with ID <strong>$application_id</strong> but starting with v18.0 it is strongly recommended to instead configure <strong>GroupMember.Read.All</strong> API Permission (and to remove <em>Group.Read.All</em>).";
				$test_result->more_info = 'https://docs.wpo365.com/article/23-integration';
				return $test_result;
			}

			if ( ! $test_result_groupmember_read_all->passed ) {
				$test_result->passed    = $test_result_groupmember_read_all->passed;
				$test_result->message   = $test_result_groupmember_read_all->message;
				$test_result->more_info = $test_result_groupmember_read_all->more_info;
				return $test_result;
			}

			// Check if a user's member groups can be retrieved from Microsoft Graph
			if ( ! empty( $this->wpo_usr ) && \class_exists( '\Wpo\Services\User_Aad_Groups_Service' ) ) {

				$test_result->data = \Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $this->wpo_usr, true, true );

				if ( ! isset( $test_result->data['response_code'] ) || $test_result->data['response_code'] !== 200 ) {
					$test_result->passed  = false;
					$test_result->message = 'Failed to retrieve the Azure AD groups for current user from Microsoft Graph. Inspect the data that was received below for a possible reason.';
				}
			} else {
				$test_result->passed  = false;
				$test_result->message = 'Failed to retrieve the Azure AD groups for current user from Microsoft Graph due to earliers errors.';
			}

			return $test_result;
		}

		/**
		 * ROLES + ACCESS | APPLICATION
		 */
		public function test_roles_access_application() {

			$test_result         = new Test_Result( 'Get a user\'s Azure AD group memberships with <em>application</em> permissions.', Test_Result::CAPABILITY_ROLES_ACCESS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-roles-access/wpo365-roles-access.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			// Check if suitable extensions can be found
			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the ROLES + ACCESS / AUDIENCES feature.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if access token with application permissions can be found
			if ( ! $this->application_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->application_access_token_test_result->message;
				$test_result->more_info = $this->application_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_groupmember_read_all = $this->check_static_permission(
				$this->application_access_token,
				'groupmember.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			// Check if access token has appropriate permissions
			$test_result_group_read_all = $this->check_static_permission(
				$this->application_access_token,
				'group.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->application_access_token,
				'user.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_ROLES_ACCESS,
				'and as a result the plugin cannot retrieve a user\'s Azure AD group memberships.',
				'https://docs.wpo365.com/article/23-integration'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$test_result->passed    = $test_result_user_read_all->passed;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = $test_result_user_read_all->more_info;
				return $test_result;
			}

			if ( ! $test_result_groupmember_read_all->passed && $test_result_group_read_all->passed ) {
				$application_id         = Options_Service::get_aad_option( 'app_only_application_id' );
				$test_result->passed    = false;
				$test_result->message   = "Static permission <strong>Group.Read.All</strong> has been configured for the (Azure AD) <em>registered application</em> with ID <strong>$application_id</strong> but starting with v18.0 it is strongly recommended to instead configure <strong>GroupMember.Read.All</strong> API Permission (and to remove <em>Group.Read.All</em>).";
				$test_result->more_info = 'https://docs.wpo365.com/article/23-integration';
				return $test_result;
			}

			if ( ! $test_result_groupmember_read_all->passed ) {
				$test_result->passed    = $test_result_groupmember_read_all->passed;
				$test_result->message   = $test_result_groupmember_read_all->message;
				$test_result->more_info = $test_result_groupmember_read_all->more_info;
				return $test_result;
			}

			// Check if a user's member groups can be retrieved from Microsoft Graph
			if ( ! empty( $this->wpo_usr ) && \class_exists( '\Wpo\Services\User_Aad_Groups_Service' ) ) {
				$test_result->data = \Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $this->wpo_usr, true, true );

				if ( is_wp_error( $test_result->data ) || ! isset( $test_result->data['response_code'] ) || $test_result->data['response_code'] !== 200 ) {
					$test_result->passed  = false;
					$test_result->message = 'Failed to retrieve the Azure AD groups for current user from Microsoft Graph.';
				}
			} else {
				$test_result->passed  = false;
				$test_result->message = 'Failed to retrieve the Azure AD groups for current user from Microsoft Graph due to earliers errors.';
			}

			return $test_result;
		}

		/**
		 * AVATAR | DELEGATED
		 */
		public function test_avatar_delegated() {

			// Skip if using SAML
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Fetch a user\'s Microsoft 365 profile photo with <em>delegated</em> permissions.', Test_Result::CAPABILITY_AVATAR, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-avatar/wpo365-avatar.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
				)
			);

			// Check if suitable extensions can be found
			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the AVATAR feature.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if avatar is on
			if ( ! Options_Service::get_global_boolean_var( 'use_avatar' ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Enable the retrieval of a user's profile image as WordPress avatar on the plugin's <a href=\"#userSync\">User sync</a> configuration page.";
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'useAvatar' => true,
						),
					),
				);
				$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
				return $test_result;
			}

			// Check if suitable access token with delegated permissions can be found
			if ( ! $this->delegated_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->delegated_access_token_test_result->message;
				$test_result->more_info = $this->delegated_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->delegated_access_token,
				'user.read',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_AVATAR,
				'and as a result the plugin cannot retrieve a user\'s Azure AD profile photo.',
				'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$test_result->passed    = $test_result_user_read_all->passed;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = $test_result_user_read_all->more_info;
				return $test_result;
			}

			// Check if a user's profile photo can be retrieved from Microsoft Graph
			if ( ! empty( $this->wpo_usr ) && ! empty( $this->wpo_usr->oid ) && \class_exists( '\Wpo\Services\Avatar_Service' ) ) {

				$data = Graph_Service::fetch( '/users/' . $this->wpo_usr->oid . '/photo/$value', 'GET', true, array( 'Accept: application/json;odata.metadata=minimal' ), true, false, '', 'https://graph.microsoft.com/User.Read' );

				if ( ! \is_wp_error( $data ) && isset( $data['response_code'] ) ) {
					$test_result->data = array( 'imageUri' => 'data:image/jpeg;base64,' . base64_encode( $data['payload'] ) ); //phpcs:ignore
				} else {
					$test_result->data      = $data;
					$test_result->passed    = false;
					$test_result->message   = 'Failed to retrieve a profile photo for the current user. Inspect the data that was received below for a possible reason.';
					$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
				}
			} else {
				$test_result->passed    = false;
				$test_result->message   = 'Failed to retrieve a profile photo for current user from Microsoft Graph due to earliers errors.';
				$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
			}

			return $test_result;
		}

		/**
		 * AVATAR | APPLICATION
		 */
		public function test_avatar_application() {

			$test_result         = new Test_Result( 'Fetch a user\'s Microsoft 365 profile photo with <em>application</em> permissions.', Test_Result::CAPABILITY_AVATAR, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$suitable_extensions = \array_flip(
				array(
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-avatar/wpo365-avatar.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-pro/wpo365-pro.php',
				)
			);

			// Check if suitable extensions can be found
			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the AVATAR feature.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-extensions/';
				return $test_result;
			}

			// Check if avatar is on
			if ( ! Options_Service::get_global_boolean_var( 'use_avatar' ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Enable the retrieval of a user's profile image as WordPress avatar on the plugin's <a href=\"#userSync\">User sync</a> configuration page.";
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'useAvatar' => true,
						),
					),
				);
				$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
				return $test_result;
			}

			// Check if access token with application permissions can be found
			if ( ! $this->application_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->application_access_token_test_result->message;
				$test_result->more_info = $this->application_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->application_access_token,
				'user.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_AVATAR,
				'and as a result the plugin cannot independently from the currently logged-in user retrieve a user\'s Azure AD profile photo.',
				'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$test_result->passed    = $test_result_user_read_all->passed;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = $test_result_user_read_all->more_info;
				return $test_result;
			}

			// Check if a user's profile photo can be retrieved from Microsoft Graph
			if ( ! empty( $this->wpo_usr ) && ! empty( $this->wpo_usr->oid ) && \class_exists( '\Wpo\Services\Avatar_Service' ) ) {

				$data = Graph_Service::fetch( '/users/' . $this->wpo_usr->oid . '/photo/$value', 'GET', true, array( 'Accept: application/json;odata.metadata=minimal' ), false, false, '', 'https://graph.microsoft.com/User.Read.All' );

				if ( ! \is_wp_error( $data ) && isset( $data['response_code'] ) && $data['response_code'] === 200 ) {
					$test_result->data = array( 'imageUri' => 'data:image/jpeg;base64,' . base64_encode( $data['payload'] ) ); //phpcs:ignore
				} else {
					$test_result->data      = $data;
					$test_result->passed    = false;
					$test_result->message   = 'Failed to retrieve a profile photo for the current user. Inspect the data that was received below for a possible reason.';
					$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
				}
			} else {
				$test_result->passed    = false;
				$test_result->message   = 'Failed to retrieve a profile photo for current user from Microsoft Graph due to earliers errors.';
				$test_result->more_info = 'https://docs.wpo365.com/article/96-microsoft-365-profile-picture-as-wp-avatar';
			}

			return $test_result;
		}

		/**
		 * SYNC | CRON DISABLED
		 */
		public function test_sync_cron() {

			// Check if sync is en
			$sync_enabled = Options_Service::get_global_boolean_var( 'enable_user_sync' );

			if ( empty( $sync_enabled ) ) {
				return;
			}

			$test_result         = new Test_Result( 'WP-Cron is disabled.', Test_Result::CAPABILITY_SYNC, Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			// Check if cron has been disabled
			if ( ! defined( 'DISABLE_WP_CRON' ) || empty( \constant( 'DISABLE_WP_CRON' ) ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'Since WP-Cron does not run continiously, it is strongly recommended that you <em>disable WordPress CRON</em> by adding the line <em>define( \'DISABLE_WP_CRON\', true );</em> to your wp-config.php file and configure an external task scheduling service to trigger WP-Cron at regular intervals (e.g. every minute).';
				$test_result->more_info = 'https://docs.wpo365.com/article/135-hooking-wp-cron-into-a-task-scheduling-service';
				return $test_result;
			}

			// Check if wp-cron.php has been added to the list of pages freed from authentication

			$allowed_urls = Options_Service::get_global_list_var( 'pages_blacklist' );
			$found        = false;

			foreach ( $allowed_urls as $url ) {

				if ( WordPress_Helpers::stripos( $url, 'wp-cron.php' ) !== false ) {
					$found = true;
					break;
				}
			}

			if ( empty( $found ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'Since you disabled WP-Cron you must add "wp-cron.php" to the list of pages freed from authentication on the plugin\'s <a href="#singleSignOn">Single Sign-on</a> configuration page to be able to trigger WP-Cron at regular intervals from an extenal task scheduling service.';
				$test_result->more_info = 'https://docs.wpo365.com/article/135-hooking-wp-cron-into-a-task-scheduling-service';
				$test_result->fix       = array(
					array(
						'op'    => 'add',
						'value' => array(
							'pagesBlacklist' => 'wp-cron.php',
						),
					),
				);
				return $test_result;
			}

			return $test_result;
		}

		/**
		 * SYNC | APPLICATION
		 */
		public function test_sync_application() {

			$test_result         = new Test_Result( 'Synchronize users with <em>application</em> permissions (required when you want to schedule user synchronization).', Test_Result::CAPABILITY_SYNC, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$suitable_extensions = \array_flip(
				array(
					'wpo365-customers/wpo365-customers.php',
					'wpo365-login-premium/wpo365-login.php',
					'wpo365-login-intranet/wpo365-login.php',
					'wpo365-intranet-5y/wpo365-intranet-5y.php',
					'wpo365-sync-5y/wpo365-sync-5y.php',
					'wpo365-integrate/wpo365-integrate.php',
					'wpo365-customers/wpo365-customers.php',
				)
			);

			// Check if suitable extensions can be found
			if ( count( $this->extensions ) === 0 || count( \array_intersect_key( $suitable_extensions, $this->extensions ) ) === 0 ) {
				$test_result->passed    = false;
				$test_result->message   = 'No WPO365 extension was found that would enable the SYNC feature.';
				$test_result->more_info = 'https://www.wpo365.com/compare-all-wpo365-bundles/';
				return $test_result;
			}

			// Check if sync is en
			$sync_enabled = Options_Service::get_global_boolean_var( 'enable_user_sync' );

			if ( empty( $sync_enabled ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Enable User synchronization on the plugin's <a href=\"#userSync\">User sync</a> configuration page.";
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableUserSync' => true,
						),
					),
				);
				$test_result->more_info = 'https://docs.wpo365.com/article/57-synchronize-users-from-azure-ad-to-wordpress';
				return $test_result;
			}

			// Check if cron has been disabled
			$disabled = Options_Service::get_global_boolean_var( 'prevent_send_email_change_email' );

			if ( ! $disabled ) {
				$test_result->passed    = false;
				$test_result->message   = 'It is recommended to disable the automatic email response to users when their password or email is changed during User synchronization on the plugin\'s <a href="#miscellaneous">Miscellaneous</a> configuration page.';
				$test_result->more_info = 'https://docs.wpo365.com/article/115-prevent-wordpress-to-send-email-changed-email';
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'preventSendEmailChangeEmail' => true,
						),
					),
				);
				return $test_result;
			}

			$allowed_urls = Options_Service::get_global_list_var( 'pages_blacklist' );
			$found        = false;

			foreach ( $allowed_urls as $url ) {

				if ( WordPress_Helpers::stripos( $url, '/wp-json/wpo365/v1/' ) !== false ) {
					$found = true;
					break;
				}
			}

			if ( empty( $found ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'You must add "/wp-json/wpo365/v1/" to the list of pages freed from authentication on the plugin\'s <a href="#singleSignOn">Single Sign-on</a> configuration page to be able to synchronize users.';
				$test_result->more_info = 'https://docs.wpo365.com/article/37-pages-blacklist';
				$test_result->fix       = array(
					array(
						'op'    => 'add',
						'value' => array(
							'pagesBlacklist' => '/wp-json/wpo365/v1/',
						),
					),
				);
				return $test_result;
			}

			// Check if suitable access token with application permissions can be found
			if ( ! $this->application_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->application_access_token_test_result->message;
				$test_result->more_info = $this->application_access_token_test_result->more_info;
				return $test_result;
			}

			// Check if access token has appropriate permissions
			$test_result_user_read_all = $this->check_static_permission(
				$this->application_access_token,
				'user.read.all',
				'application',
				$this->application_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_SYNC,
				'and as a result the plugin cannot retrieve users from your organization from Microsoft Graph to synchronize with WordPress.',
				'https://docs.wpo365.com/article/57-synchronize-users-from-azure-ad-to-wordpress'
			);

			if ( ! $test_result_user_read_all->passed ) {
				$test_result->passed    = $test_result_user_read_all->passed;
				$test_result->message   = $test_result_user_read_all->message;
				$test_result->more_info = $test_result_user_read_all->more_info;
				return $test_result;
			}

			// Check if the default sync query can be executed against Microsoft Graph
			if ( \class_exists( '\Wpo\Sync\SyncV2_Service' ) ) {

				$test_result->data = Graph_Service::fetch( '/myorganization/users?$filter=accountEnabled+eq+true+and+userType+eq+%27member%27&$top=1', 'GET', false, array( 'Accept: application/json;odata.metadata=minimal' ), false, false, '', 'https://graph.microsoft.com/User.Read.All' );

				if ( \is_wp_error( $test_result->data ) || ! isset( $test_result->data['response_code'] ) || $test_result->data['response_code'] !== 200 ) {
					$test_result->passed    = false;
					$test_result->message   = 'Failed to retrieve users from your organization from Microsoft to synchronize with WordPress. Inspect the data that was received below for a possible reason.';
					$test_result->more_info = 'https://docs.wpo365.com/article/57-synchronize-users-from-azure-ad-to-wordpress';
				}
			} else {
				$test_result->passed    = false;
				$test_result->message   = 'Unknow error occurred [class could not be loaded].';
				$test_result->more_info = 'https://docs.wpo365.com/article/57-synchronize-users-from-azure-ad-to-wordpress';
			}

			return $test_result;
		}

		/**
		 * CONTENT BY SEARCH | DELEGATED
		 */
		public function test_sharepoint_search_delegated() {
			// Skip if using SAML
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Search files in SharePoint Online (as a user who signed in with Microsoft) using the WPO365 (shortcode / Gutenberg based) client-side apps.', Test_Result::CAPABILITY_APPS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			// Check if suitable access token with delegated permissions can be found
			if ( ! $this->delegated_access_token_test_result->passed ) {
				$test_result->passed    = false;
				$test_result->message   = $this->delegated_access_token_test_result->message;
				$test_result->more_info = $this->delegated_access_token_test_result->more_info;
				return $test_result;
			}

			$test_result_sites_search_all = $this->check_static_permission(
				$this->delegated_access_token,
				'sites.search.all',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_APPS,
				'and as a result a user cannot search for items in SharePoint Online.',
				'https://docs.wpo365.com/article/125-sharepoint-search-for-wordpress'
			);

			if ( ! $test_result_sites_search_all->passed ) {
				$test_result->passed    = $test_result_sites_search_all->passed;
				$test_result->message   = $test_result_sites_search_all->message;
				$test_result->more_info = $test_result_sites_search_all->more_info;
				return $test_result;
			}

			if ( ! Options_Service::get_global_boolean_var( 'enable_graph_api' ) ) {
				$test_result->passed  = false;
				$test_result->message = "To enable (WPO365) client-side apps to request data from your SharePoint Online instance you must check the option to <em>Enable WPO365 REST API for Microsoft Graph</a> on the plugin's <a href=\"#integration\">Integration</a> configuration page.";
				$test_result->fix     = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableGraphApi' => true,
						),
					),
				);
				return $test_result;
			}

			if ( ! Options_Service::get_global_boolean_var( 'enable_graph_proxy' ) ) {
				$test_result->passed  = false;
				$test_result->message = "To enable (WPO365) client-side apps to request data from your SharePoint Online instance you must check the option to <em>Allow Microsoft Graph proxy-type requests</a> on the plugin's <a href=\"#integration\">Integration</a> configuration page.";
				$test_result->fix     = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableGraphProxy' => true,
						),
					),
				);
				return $test_result;
			}

			$allowed_endpoints_and_permissions = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );
			$sharepoint_endpoint_ok            = false;

			foreach ( $allowed_endpoints_and_permissions as $allowed_endpoint_config ) {

				if ( WordPress_Helpers::stripos( $allowed_endpoint_config['key'], 'sharepoint.com' ) > 0 && $allowed_endpoint_config['boolVal'] === false ) {
					$sharepoint_endpoint_ok = true;
					continue;
				}
			}

			if ( ! $sharepoint_endpoint_ok ) {
				$test_result->passed  = false;
				$test_result->message = 'You must add the SharePoint Online Home URL e.g. https://your-tenant.sharepoint.com to the list of <em>Allowed endpoints</em> on the plugin\'s <a href="#integration">Integration</a> configuration page.';

				// Check if access token has appropriate permissions
				$test_result_sites_read_all = $this->check_static_permission(
					$this->delegated_access_token,
					'sites.read.all',
					'delegated',
					$this->delegated_static_permissions,
					Test_Result::SEVERITY_LOW,
					Test_Result::CAPABILITY_APPS,
					'and as a result a user cannot get files from SharePoint / OneDrive.',
					'https://tutorials.wpo365.com/courses/embed-a-sharepoint-library-in-wordpress/'
				);

				if ( $test_result_sites_read_all->passed ) {
					// Check if the SharePoint home site can be retrieved
					$response = Graph_Service::fetch( '/sites/root', 'GET', false, array( 'Accept: application/json;odata.metadata=minimal' ), true, false, '', 'https://graph.microsoft.com/Sites.Read.All' );

					if ( ! \is_wp_error( $response ) && isset( $response['response_code'] ) && $response['response_code'] === 200 ) {

						try {
							$this->hostname = $response['payload']['siteCollection']['hostname'];
						} catch ( \Exception $e ) { //phpcs:ignore
						}
					}
				}

				if ( ! empty( $this->hostname ) ) {
					$test_result->fix = array(
						array(
							'op'    => 'add',
							'value' => array(
								'graphAllowedEndpoints' => array(
									'key'     => sprintf( 'https://%s/', $this->hostname ),
									'boolVal' => false,
								),
							),
						),
					);
				}
			}

			return $test_result;
		}

		/**
		 * DOCUMENTS | DELEGATED
		 */
		public function test_sharepoint_documents_delegated() {
			// Skip if using SAML
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'View files in a SharePoint Online / OneDrive Library (as a user who signed in with Microsoft) using the WPO365 (shortcode / Gutenberg based) client-side apps.', Test_Result::CAPABILITY_APPS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$test_result_sites_read_all = $this->check_static_permission(
				$this->delegated_access_token,
				'sites.read.all',
				'delegated',
				$this->delegated_static_permissions,
				Test_Result::SEVERITY_LOW,
				Test_Result::CAPABILITY_APPS,
				'and as a result a user cannot view in a SharePoint Online / OneDrive document library.',
				'https://tutorials.wpo365.com/courses/embed-a-sharepoint-library-in-wordpress/'
			);

			if ( ! $test_result_sites_read_all->passed ) {
				$test_result->passed    = $test_result_sites_read_all->passed;
				$test_result->message   = $test_result_sites_read_all->message;
				$test_result->more_info = $test_result_sites_read_all->more_info;
				return $test_result;
			}

			if ( ! Options_Service::get_global_boolean_var( 'enable_graph_api' ) ) {
				$test_result->passed  = false;
				$test_result->message = "To enable (WPO365) client-side apps to request data from your SharePoint Online instance you must check the option to <em>Enable WPO365 REST API for Microsoft Graph</a> on the plugin's <a href=\"#integration\">Integration</a> configuration page.";
				$test_result->fix     = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableGraphApi' => true,
						),
					),
				);
				return $test_result;
			}

			if ( ! Options_Service::get_global_boolean_var( 'enable_graph_proxy' ) ) {
				$test_result->passed  = false;
				$test_result->message = "To enable (WPO365) client-side apps to request data from your SharePoint Online instance you must check the option to <em>Allow Microsoft Graph proxy-type requests</a> on the plugin's <a href=\"#integration\">Integration</a> configuration page.";
				$test_result->fix     = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableGraphProxy' => true,
						),
					),
				);
				return $test_result;
			}

			$allowed_endpoints_and_permissions = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );
			$drives_endpoint_ok                = false;
			$sites_endpoint_ok                 = false;

			foreach ( $allowed_endpoints_and_permissions as $allowed_endpoint_config ) {

				if ( WordPress_Helpers::stripos( $allowed_endpoint_config['key'], '/sites' ) > 0 ) {
					$sites_endpoint_ok = true;
					continue;
				}

				if ( WordPress_Helpers::stripos( $allowed_endpoint_config['key'], '/drives' ) > 0 ) {
					$drives_endpoint_ok = true;
					continue;
				}
			}

			if ( ! $sites_endpoint_ok ) {
				$test_result->passed  = false;
				$test_result->message = 'You must add the following entry to the list of <em>Allowed endpoints</em> on the plugin\'s <a href="#integration">Integration</a> configuration page: https://graph.microsoft.com/_/sites.';
				$test_result->fix     = array(
					array(
						'op'    => 'add',
						'value' => array(
							'graphAllowedEndpoints' => array(
								'key'     => sprintf( 'https://graph.microsoft%s/_/sites', $this->tld ),
								'boolVal' => false,
							),
						),
					),
				);
				return $test_result;
			}

			if ( ! $drives_endpoint_ok ) {
				$test_result->passed  = false;
				$test_result->message = 'You must add the following entry to the list of <em>Allowed endpoints</em> on the plugin\'s <a href="#integration">Integration</a> configuration page: https://graph.microsoft.com/_/drives.';
				$test_result->fix     = array(
					array(
						'op'    => 'add',
						'value' => array(
							'graphAllowedEndpoints' => array(
								'key'     => sprintf( 'https://graph.microsoft%s/_/drives', $this->tld ),
								'boolVal' => false,
							),
						),
					),
				);
				return $test_result;
			}

			return $test_result;
		}

		/**
		 * DELEGATED PERMISSIONS
		 */
		private function get_delegated_access_token() {
			if ( $this->no_sso || $this->use_saml || $this->use_b2c ) {
				return;
			}

			$test_result         = new Test_Result( 'Retrieve an access token with <em>delegated</em> access', Test_Result::CAPABILITY_ACCESS_TOKENS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			$application_secret = Options_Service::get_aad_option( 'application_secret' );

			if ( empty( $application_secret ) ) {
				$test_result->passed                      = false;
				$test_result->message                     = 'An <em>Application (Client) Secret</em> for <em>Delegated access</em> has not been configured (on the <a href="#integration">Integration</a> tab). Please consult the online documentation using the link below and configure the <em>Integration</em> portion of the plugin.';
				$test_result->more_info                   = 'https://docs.wpo365.com/article/23-integration';
				$this->delegated_access_token_test_result = $test_result;
				return;
			}

			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $application_secret ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'Application (client) secret for <em>Delegated access</em> appears to be invalid. Possibly the secret\'s ID instead of its value has been copied from the corresonding page in Azure Portal.';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			$this->delegated_access_token = Access_Token_Service::get_access_token( 'openid profile offline_access user.read' );

			if ( is_wp_error( $this->delegated_access_token ) || ! property_exists( $this->delegated_access_token, 'access_token' ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'Could not fetch access token. The following error occurred: ' . $this->delegated_access_token->get_error_message();
				$test_result->more_info = '';
			} elseif ( property_exists( $this->delegated_access_token, 'scope' ) ) {
				$this->delegated_static_permissions = explode( ' ', $this->delegated_access_token->scope );
				$this->delegated_static_permissions = array_map(
					function ( $item ) {
						$lowered  = strtolower( $item );
						$endpoint = sprintf( 'https://graph.microsoft%s/', $this->tld );
						return str_replace( $endpoint, '', $lowered );
					},
					$this->delegated_static_permissions
				);
			}

			$this->delegated_access_token_test_result = $test_result;
		}

		public function test_end() {

			if ( empty( $this->wpo_usr ) ) {
				return;
			}

			$request_service = Request_Service::get_instance();
			$this->request   = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$this->request->set_item( 'wpo_usr', $this->wpo_usr );
		}

		/**
		 * APPLICATION PERMISSIONS
		 */
		private function get_application_access_token( $scope = null, $role = null ) {
			$scope               = empty( $scope ) ? sprintf( 'https://graph.microsoft%s/.default', $this->tld ) : str_replace( '.com', $this->tld, $scope );
			$test_result         = new Test_Result( 'Can fetch app-only access tokens', Test_Result::CAPABILITY_ACCESS_TOKENS, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( ! Options_Service::get_aad_option( 'use_app_only_token', true ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'It is recommended to configure application-level access so the plugin can connect to Microsoft Services such as Microsoft Graph without a logged-in user to enable more advanced scenarios e.g. User Synchronization.';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			$application_id = Options_Service::get_aad_option( 'app_only_application_id' );

			if ( empty( $application_id ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'It is recommended to configure application-level access so the plugin can connect to Microsoft Services such as Microsoft Graph without a logged-in user to enable more advanced scenarios e.g. User Synchronization.';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-5][0-9a-f]{3}-[089ab][0-9a-f]{3}-[0-9a-f]{12}$/', $application_id ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'Support for application-level (app-only) access to Microsoft Services has been enabled but the app-only Application (client) ID on the plugin\'s <a href="#integration">Integration</a> page is not a valid GUID';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			$application_secret = Options_Service::get_aad_option( 'app_only_application_secret' );

			if ( empty( $application_secret ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = "The use of application permissions is enabled. However, the plugin failed to retrieve the necessary application (client) secret for the corresponding (Azure AD) <em>registered application</em> with ID <strong>$application_id</strong>. Please create a Client secret for the (Azure AD) <em>registered application</em> in Azure AD and enter it on the plugin's <a href=\"#integration\">Integration</a>.";
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $application_secret ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'App-only Application (client) secret for (Azure AD) <em>registered application</em> with ID <strong>' . $application_id . '</strong> appears to be invalid. Possibly the secret\'s ID instead of its value has been copied from the corresonding page for the (Azure AD) <em>registered application</em> in Azure Portal.';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			$this->application_access_token = Access_Token_Service::get_app_only_access_token( $scope, $role );

			if ( is_wp_error( $this->application_access_token ) || ! property_exists( $this->application_access_token, 'access_token' ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'Could not fetch app-only access token from the (Azure AD) <em>registered application</em> with ID <strong>' . $application_id . '</strong>. The following error occurred: ' . $this->application_access_token->get_error_message();
				$test_result->more_info                     = '';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			if ( empty( $this->application_access_token->roles ) ) {
				$test_result->passed                        = false;
				$test_result->message                       = 'Support for application-level (app-only) access to Microsoft Services has been configured for (Azure AD) <em>registered application</em> with ID <strong>' . $application_id . '</strong>. However, the access token that was retrieved does not have any roles (= application-level API permissions) assigned / granted.';
				$test_result->more_info                     = 'https://docs.wpo365.com/article/23-integration';
				$this->application_access_token_test_result = $test_result;
				return;
			}

			$this->application_static_permissions       = $this->application_access_token->roles;
			$this->application_access_token_test_result = $test_result;
		}

		private function check_static_permission( $access_token, $permission, $permission_type, $static_permissions, $severity, $category = Test_Result::CAPABILITY_CONFIG, $additional_message = '', $more_info = 'https://docs.wpo365.com/article/23-integration' ) {
			$endpoint   = sprintf( 'https://graph.microsoft%s/', $this->tld );
			$permission = str_replace( $endpoint, '', $permission );

			if ( strcasecmp( $permission, 'mail.send' ) === 0 ) {
				$application_id = Options_Service::get_mail_option( 'mail_application_id' );
			} elseif ( strcasecmp( $permission_type, 'delegated' ) === 0 ) {
				$application_id = Options_Service::get_aad_option( 'application_id' );
			} else {
				$application_id = Options_Service::get_aad_option( 'app_only_application_id' );
			}

			$test_result         = new Test_Result( "Static <strong>$permission_type</strong> permission <strong>$permission</strong> has been configured for the (Azure AD) <em>registered application</em> with ID <strong>$application_id</strong>", $category, $severity );
			$test_result->passed = true;

			if ( empty( $access_token ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Could not fetch $permission_type access token -> test skipped";
				$test_result->more_info = '';
				return $test_result;
			}

			if ( empty( $static_permissions ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Could not determine static permissions of the current $permission_type access token -> test skipped";
				$test_result->more_info = '';
				return $test_result;
			}

			foreach ( $static_permissions as $key => $static_permission ) {

				if ( WordPress_Helpers::stripos( $static_permission, $permission ) !== false ) {
					return $test_result;
				}
			}

			$test_result->passed    = false;
			$test_result->message   = "Static <strong>$permission_type</strong> permission <strong>$permission</strong> is not configured for the (Azure AD) <em>registered application</em> with ID <strong>$application_id</strong> $additional_message";
			$test_result->more_info = $more_info;
			return $test_result;
		}
	}
}
