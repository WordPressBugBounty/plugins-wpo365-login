<?php

namespace Wpo\Tests;

use Wpo\Services\Log_Service;
use Wpo\Services\Saml2_Service;
use Wpo\Services\User_Service;
use Wpo\Services\Request_Service;


// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Tests\Test_Saml2' ) ) {

	class Test_Saml2 {

		private $settings_ok = false;
		private $auth        = null;
		private $wpo_usr     = null;
		private $request     = null;

		public function test_saml2_settings() {

			$test_result         = new Test_Result( 'All mandatory SAML settings are configured', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_BLOCKING );
			$test_result->passed = true;

			$saml_settings = Saml2_Service::saml_settings( true );

			if ( empty( $saml_settings ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'One or more mandatory SAML settings are not configured. Please check the <a href="#debug">debug log</a> for warnings.';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			} else {
				$this->settings_ok = true;
			}

			return $test_result;
		}

		public function test_process_saml2_response() {

			$test_result         = new Test_Result( 'SAML response has been processed and no errors occurred', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_BLOCKING );
			$test_result->passed = true;

			if ( empty( $this->settings_ok ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'Mandatory SAML settings are not configured -> Test skipped';
				$test_result->more_info = '';
				return $test_result;
			}

			require_once $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/OneLogin/_toolkit_loader.php';

			try {
				$saml_settings = Saml2_Service::saml_settings();
				$this->auth    = new \OneLogin_Saml2_Auth( $saml_settings );
				$this->auth->processResponse();
			} catch ( \Exception $e ) {
				$this->auth             = null;
				$test_result->passed    = false;
				$test_result->message   = 'Could not process SAML response (' . $e->getMessage() . ')';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
				return $test_result;
			}

			// Check for errors
			$errors = $this->auth->getErrors();

			if ( ! empty( $errors ) ) {
				$test_result->passed  = false;
				$test_result->message = 'Could not process SAML response (See log for errors)';
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not process SAML 2.0 response (See log for errors)' );
				Log_Service::write_log( 'WARN', $errors );
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
				return $test_result;
			}

			$test_result->data = $this->auth->getAttributes();
			return $test_result;
		}

		public function test_saml_response_is_authenticated() {
			$test_result         = new Test_Result( 'User is authenticated', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_BLOCKING );
			$test_result->passed = true;

			if ( empty( $this->auth ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->auth->isAuthenticated() ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'User not successfully authenticated';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			}

			return $test_result;
		}

		public function test_saml_response_contains_upn() {
			$test_result         = new Test_Result( 'SAML response contains user principal name', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			if ( empty( $this->auth ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->auth->isAuthenticated() ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'User not successfully authenticated -> test skipped';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			} else {
				$saml_attributes = $this->auth->getAttributes();
				$saml_name_id    = $this->auth->getNameId();
				$this->wpo_usr   = User_Service::user_from_saml_response( $saml_name_id, $saml_attributes );

				if ( empty( $this->wpo_usr->preferred_username ) || empty( $this->wpo_usr->upn ) ) {
					$test_result->passed    = false;
					$test_result->message   = 'SAML response does not contain user principal name (upn)';
					$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
				}
			}

			return $test_result;
		}

		public function test_saml_response_contains_first_name() {
			$test_result         = new Test_Result( 'SAML response contains first name', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( $this->wpo_usr ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->wpo_usr->first_name ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response does not contain first name';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			}

			return $test_result;
		}

		public function test_saml_response_contains_last_name() {
			$test_result         = new Test_Result( 'SAML response contains last name', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( $this->wpo_usr ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->wpo_usr->last_name ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response does not contain last name';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			}

			return $test_result;
		}

		public function test_saml_response_contains_full_name() {
			$test_result         = new Test_Result( 'SAML response contains full name', Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( $this->wpo_usr ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->wpo_usr->full_name ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response does not contain full name';
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			}

			return $test_result;
		}

		public function test_saml_response_contains_groups() {
			$test_result         = new Test_Result( "SAML response contains 'groups' claim", Test_Result::CAPABILITY_SAML_SSO, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( empty( $this->wpo_usr ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'SAML response could not be processed -> test skipped';
				$test_result->more_info = '';
			} elseif ( empty( $this->wpo_usr->groups ) ) {
				$test_result->passed    = false;
				$test_result->message   = "SAML response does not contain a 'groups' claim";
				$test_result->more_info = 'https://docs.wpo365.com/article/100-configure-single-sign-on-with-saml-2-0';
			}

			return $test_result;
		}

		public function test_end() {
			$request_service = Request_Service::get_instance();
			$this->request   = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$this->request->set_item( 'wpo_usr', $this->wpo_usr );
		}
	}
}
