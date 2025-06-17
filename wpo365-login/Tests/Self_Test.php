<?php

namespace Wpo\Tests;

use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;
use Wpo\Tests\Test_Access_Tokens;
use Wpo\Tests\Test_Configuration;
use Wpo\Tests\Test_OpenId_Connect;
use Wpo\Tests\Test_Extensions;
use Wpo\Tests\Test_Result;
use Wpo\Tests\Test_Saml2;
use Wpo\Tests\Test_B2c;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Tests\Self_Test' ) ) {

	class Self_Test {


		private $test_results = array();

		public function __construct() {
			$this->run_tests();
		}

		public function run_tests() {
			if ( isset( $_REQUEST['flushPermaLinks'] ) ) { //phpcs:ignore
				$flush = filter_var( $_REQUEST['flushPermaLinks'], FILTER_VALIDATE_BOOLEAN ); //phpcs:ignore

				if ( $flush ) {
					flush_rewrite_rules();
				}
			} else {
				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$state           = $request->get_item( 'state' );

				if ( ! empty( $state ) && WordPress_Helpers::stripos( $state, 'flushPermaLinks=true' ) > 0 ) {
					flush_rewrite_rules();
				}
			}

			$test_sets = array( new Test_Extensions(), $this );
			$no_sso    = Options_Service::get_global_boolean_var( 'no_sso' );
			$use_saml  = Options_Service::get_global_boolean_var( 'use_saml' );
			$use_b2c   = Options_Service::get_global_boolean_var( 'use_b2c' );
			$use_ciam  = Options_Service::get_global_boolean_var( 'use_ciam' );
			$oidc_flow = Options_Service::get_aad_option( 'oidc_flow' );

			if ( ! $no_sso ) {
				if ( $use_saml ) {
					$test_sets[] = new Test_Saml2();
				} elseif ( $use_b2c ) {
					$test_sets[] = new Test_B2c();
				} elseif ( $use_ciam ) {
					$test_sets[] = new Test_B2c();
				} else {
					$test_sets[] = new Test_OpenId_Connect();
				}
			}

			// In case of the Authorization Code Flow we need to exchange the code for an ID token
			if ( ! $no_sso && ! $use_saml && $oidc_flow === 'code' ) {

				if ( $use_b2c && class_exists( '\Wpo\Services\Id_Token_Service_B2c' ) ) {
					\Wpo\Services\Id_Token_Service_B2c::process_openidconnect_code();
				} elseif ( $use_ciam && class_exists( '\Wpo\Services\Id_Token_Service_Ciam' ) ) {
					\Wpo\Services\Id_Token_Service_Ciam::process_openidconnect_code();
				} else {
					\Wpo\Services\Id_Token_Service::process_openidconnect_code();
				}
			}

			if ( Options_Service::get_global_boolean_var( 'test_configuration' ) ) {
				$test_sets[] = new Test_Configuration();
			}

			if ( Options_Service::get_global_boolean_var( 'test_access_token' ) ) {
				$test_sets[] = new Test_Access_Tokens();
			}

			if ( Options_Service::get_global_boolean_var( 'use_wp_rest_aad' ) ) {
				$test_sets[] = new Test_Rest_Protection();
			}

			if ( Options_Service::get_global_boolean_var( 'enable_scim' ) ) {
				$test_sets[] = new Test_Scim();
			}

			foreach ( $test_sets as $test_set ) {
				$tests = preg_grep( '/^test_/', get_class_methods( $test_set ) );

				foreach ( $tests as $test ) {
					$result = $test_set->$test();

					// A test may return void when skipped
					if ( ! empty( $result ) ) {
						$this->test_results[] = $result;
					}
				}
			}

			Wpmu_Helpers::mu_set_transient( 'wpo365_self_test_results', $this->test_results, 21600 );
		}

		public function test_no_health_messages() {
			$test_result         = new Test_Result( 'There are no WPO365 Health Messages', Test_Result::HEALTH_MESSAGES, Test_Result::SEVERITY_CRITICAL );
			$test_result->passed = true;

			$cached_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );
			$cached_errors = is_array( $cached_errors ) ? $cached_errors : array();

			if ( ! empty( $cached_errors ) ) {
				$test_result->passed    = false;
				$test_result->message   = 'After addressing each of the reported WPO365 Health Messages, click "Dismiss all".';
				$test_result->data      = $cached_errors;
				$test_result->more_info = '';
			}

			return $test_result;
		}

		public function test_configuration_without_secrets() {
			$test_result         = new Test_Result( 'WPO365 configuration as JSON without secrets', Test_Result::CAPABILITY_CONFIG, Test_Result::SEVERITY_LOW );
			$test_result->passed = true;

			if ( ! isset( $GLOBALS['WPO_CONFIG']['options'] ) ) {
				$test_result->message   = 'Global option cache did not properly initialize.';
				$test_result->more_info = '';
				return $test_result;
			}

			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			$wpo_aad = $request->get_item( 'wpo_aad' );

			if ( empty( $wpo_aad ) ) {
				$wpo_aad = array();
			}

			$wpo_mail = $request->get_item( 'wpo_mail' );

			if ( empty( $wpo_mail ) ) {
				$wpo_mail = array();
			}

			$secret_keys    = Options_Service::get_secret_options();
			$keys_to_remove = array( 'configurations', 'name' );
			$options        = array_filter( $GLOBALS['WPO_CONFIG']['options'], '__return_true' );
			$options        = array_replace( $options, $wpo_aad, $wpo_mail );

			foreach ( $options as $key => $value ) {

				if ( in_array( $key, $keys_to_remove, true ) ) {
					unset( $options[ $key ] );
					continue;
				}

				if ( in_array( $key, $secret_keys, true ) && is_string( $value ) ) {
					$options[ $key ] = substr( $value, 0, intval( strlen( $value ) / 3 ) ) . '[...]';
				}
			}

			$test_result->data      = $options;
			$test_result->more_info = '';
			return $test_result;
		}
	}
}
