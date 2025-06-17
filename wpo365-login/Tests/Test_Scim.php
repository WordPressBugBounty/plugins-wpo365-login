<?php

namespace Wpo\Tests;

use Wpo\Core\WordPress_Helpers;
use Wpo\SCIM\SCIM_Users;
use Wpo\Services\Options_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Tests\Test_Scim' ) ) {

	class Test_Scim {


		public function __construct() {}

		/* phpcs:ignore
		public function test_update_user() {

			$test_result         = new Test_Result( 'SCIM update accepts multiple operations with and without path', 'SCIM', Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			$scim_user = SCIM_Users::update_user(
				4,
				array(
					array(
						'op'    => 'add',
						'value' => array(
							'displayName' => 'Lewis, Matt',
							'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeType' => 'Employee',
							'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:companyName' => 'People/Emplyees',
						),
					),
					array(
						'op'    => 'replace',
						'path'  => 'emails[type eq "work"].value',
						'value' => 'demoos@wpo365.com',
					),
					array(
						'op'    => 'add',
						'path'  => 'name.familyName',
						'value' => 'Demoos',
					),
				)
			);

				return $test_result;
		} */

		/**
		 * SCIM | APPLICATION
		 */
		public function test_scim_application() {
			$test_result         = new Test_Result( 'Provision Entra ID users using the built-in integration with Entra ID Application Provisioning service.', Test_Result::CAPABILITY_SCIM, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			// Check if scim is enabled
			$scim_enabled = Options_Service::get_global_boolean_var( 'enable_scim' );

			if ( empty( $scim_enabled ) ) {
				$test_result->passed    = false;
				$test_result->message   = "Enable SCIM based integration with Entra ID Application / User provisioning on the plugin's <a href=\"#userSync\">User sync</a> configuration page.";
				$test_result->fix       = array(
					array(
						'op'    => 'replace',
						'value' => array(
							'enableScim' => true,
						),
					),
				);
				$test_result->more_info = 'https://tutorials.wpo365.com/courses/sync-entra-user-provisioning-scim/';
				return $test_result;
			}

			// Check if SCIM secret has been defined
			if ( empty( Options_Service::get_global_string_var( 'scim_secret_token' ) ) && ( ! defined( 'WPO_SCIM_TOKEN' ) || empty( constant( 'WPO_SCIM_TOKEN' ) ) ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'As part of the "Admin Credentials" (for Entra ID Application / User Provisioning) you must create a secret token on the plugin\'s <a href=\"#userSync\">User sync</a> configuration page.';
				$test_result->more_info = 'https://tutorials.wpo365.com/courses/sync-entra-user-provisioning-scim/';
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
				$test_result->message   = 'You must add "/wp-json/wpo365/v1/" to the list of pages freed from authentication on the plugin\'s <a href="#singleSignOn">Single Sign-on</a> configuration page or else the Entra ID Application / User Provisioning service cannot connect with your website.';
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

			// Check if sending email changed emails has been disabled
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

			return $test_result;
		}
	}
}
