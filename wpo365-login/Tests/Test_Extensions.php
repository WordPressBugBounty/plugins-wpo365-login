<?php

namespace Wpo\Tests;

use Wpo\Core\Extensions_Helpers;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Tests\Test_Extensions' ) ) {

	class Test_Extensions {


		private $extensions = array();

		public function __construct() {
			$this->extensions = Extensions_Helpers::get_active_extensions();
		}

		public function test_wpo365_premium() {
			return $this->get_test_result_for_extensions( 'wpo365-login-premium/wpo365-login.php', 'WPO365 | SYNC', 38.0 );
		}

		public function test_wpo365_sync_5y() {
			return $this->get_test_result_for_extensions( 'wpo365-sync-5y/wpo365-sync-5y.php', 'WPO365 | SYNC | 5Y', 38.0 );
		}

		public function test_wpo365_intranet_5y() {
			return $this->get_test_result_for_extensions( 'wpo365-intranet-5y/wpo365-intranet-5y.php', 'WPO365 | INTRANET | 5Y', 38.0 );
		}

		public function test_wpo365_integrate() {
			return $this->get_test_result_for_extensions( 'wpo365-integrate/wpo365-integrate.php', 'WPO365 | INTEGRATE', 38.0 );
		}

		public function test_wpo365_pro() {
			return $this->get_test_result_for_extensions( 'wpo365-pro/wpo365-pro.php', 'WPO365 | PROFESSIONAL', 38.0 );
		}

		public function test_wpo365_essentials() {
			return $this->get_test_result_for_extensions( 'wpo365-essentials/wpo365-essentials.php', 'WPO365 | ESSENTIALS', 38.0 );
		}

		public function test_wpo365_customers() {
			return $this->get_test_result_for_extensions( 'wpo365-customers/wpo365-customers.php', 'WPO365 | CUSTOMERS', 38.0 );
		}

		public function test_wpo365_intranet() {
			return $this->get_test_result_for_extensions( 'wpo365-login-intranet/wpo365-login.php', 'WPO365 | INTRANET', 38.0 );
		}

		public function test_wpo365_profile_plus() {
			return $this->get_test_result_for_extensions( 'wpo365-login-plus/wpo365-login.php', 'WPO365 | PROFILE+', 38.0 );
		}

		public function test_wpo365_mail() {
			return $this->get_test_result_for_extensions( 'wpo365-mail/wpo365-mail.php', 'WPO365 | MAIL', 38.0 );
		}

		public function test_wpo365_login_plus() {
			return $this->get_test_result_for_extensions( 'wpo365-login-professional/wpo365-login.php', 'WPO365 | LOGIN+', 38.0 );
		}

		public function test_wpo365_avatar() {
			return $this->get_test_result_for_extensions( 'wpo365-avatar/wpo365-avatar.php', 'WPO365 | AVATAR', 38.0 );
		}

		public function test_wpo365_custom_user_fields() {
			return $this->get_test_result_for_extensions( 'wpo365-custom-fields/wpo365-custom-fields.php', 'WPO365 | CUSTOM USER FIELDS', 38.0 );
		}

		public function test_wpo365_groups() {
			return $this->get_test_result_for_extensions( 'wpo365-groups/wpo365-groups.php', 'WPO365 | GROUPS', 38.0 );
		}

		public function test_wpo365_apps() {
			return $this->get_test_result_for_extensions( 'wpo365-apps/wpo365-apps.php', 'WPO365 | APPS', 38.0 );
		}

		public function test_wpo365_documents() {
			return $this->get_test_result_for_extensions( 'wpo365-documents/wpo365-documents.php', 'WPO365 | DOCUMENTS', 3.4 );
		}

		public function test_wpo365_roles_access() {
			return $this->get_test_result_for_extensions( 'wpo365-roles-access/wpo365-roles-access.php', 'WPO365 | ROLES + ACCESS', 38.0 );
		}

		public function test_wpo365_scim() {
			return $this->get_test_result_for_extensions( 'wpo365-scim/wpo365-scim.php', 'WPO365 | SCIM', 38.0 );
		}

		public function test_basic_plugins() {
			$update_plugins = get_site_transient( 'update_plugins' );

			if ( is_object( $update_plugins ) && property_exists( $update_plugins, 'response' ) ) {

				if ( class_exists( '\Wpo\Login' ) ) {
					$test_result         = new Test_Result( 'Latest version of WPO365 | LOGIN is installed', Test_Result::CAPABILITY_EXTENSIONS, Test_Result::SEVERITY_CRITICAL );
					$test_result->passed = true;

					if ( isset( $update_plugins->response['wpo365-login/wpo365-login.php'] ) ) {
						$test_result->passed  = false;
						$test_result->message = sprintf( 'Version %s is available for the <strong>WPO365 | LOGIN</strong> plugin (installed version: %s). Please update now.', $update_plugins->response['wpo365-login/wpo365-login.php']->new_version, \Wpo\Core\Version::$current );
					}

					return $test_result;
				}
			}
		}

		private function get_test_result_for_extensions( $slug, $title, $version ) {
			$test_result_title   = sprintf( 'Latest version of %s is installed', $title );
			$test_result         = new Test_Result( $test_result_title, Test_Result::CAPABILITY_EXTENSIONS, Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			if ( ! array_key_exists( $slug, $this->extensions ) ) {
				return;
			}

			if ( $this->extensions[ $slug ]['version'] < $version ) {
				$test_result->passed    = false;
				$test_result->message   = sprintf( "Version %s is available for the <strong>$title</strong> plugin (current version: %s). Please update now.", $version, $this->extensions[ $slug ]['version'] );
				$test_result->more_info = 'https://docs.wpo365.com/article/13-update-the-wpo365-plugin-to-the-latest-version';
			}

			return $test_result;
		}
	}
}
