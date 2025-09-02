<?php
/**
 *  Plugin Name: WPO365 | LOGIN
 *  Plugin URI: https://wordpress.org/plugins/wpo365-login
 *  Description: With WPO365 | LOGIN users can sign in with their corporate or school (Azure AD / Microsoft Office 365) account to access your WordPress website: No username or password required (OIDC or SAML 2.0 based SSO). Plus you can send email using Microsoft Graph instead of SMTP from your WordPress website.
 *  Version: 38.0
 *  Author: marco@wpo365.com
 *  Author URI: https://www.wpo365.com
 *  License: GPL2+
 */

namespace Wpo;

require __DIR__ . '/vendor/autoload.php';

use Wpo\Core\Compatibility_Helpers;
use Wpo\Core\Globals;
use Wpo\Core\Wp_Hooks;

use Wpo\Services\Dependency_Service;
use Wpo\Services\Files_Service;
use Wpo\Services\Request_Service;
use Wpo\Services\Router_Service;
use Wpo\Services\Options_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Login' ) ) {

	class Login {


		private $dependencies;

		public function __construct() {
			$this->deactivation_hooks();
			add_action( 'plugins_loaded', array( $this, 'init' ), 1 );
			add_filter( 'cron_schedules', '\Wpo\Core\Cron_Helpers::add_cron_schedules', 10, 1 ); // phpcs:ignore
		}

		public function init() {
			$skip_init = defined( 'WPO_AUTH_SCENARIO' ) && constant( 'WPO_AUTH_SCENARIO' ) === 'internet' && ! \is_admin();

			if ( $skip_init ) {
				add_action( 'login_init', array( $this, 'load' ), 1 );
				return;
			}

			$this->load();
		}

		public function load() {
			Globals::set_global_vars( __FILE__, __DIR__ );
			load_plugin_textdomain( 'wpo365-login', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
			$this->cache_dependencies();
			Options_Service::ensure_options_cache();
			Compatibility_Helpers::upgrade_actions();
			$this->update_request_log();
			Wp_Hooks::add_wp_hooks();
			Wp_Hooks::add_wp_cli_commands();
			$this->load_gutenberg_blocks();
			$has_route = Router_Service::has_route();

			if ( ! Options_Service::get_global_boolean_var( 'no_sso', false ) && ! $has_route ) {
				add_action( 'init', '\Wpo\Services\Authentication_Service::authenticate_request', 1 );
			}
		}

		/**
		 * @since 13.0
		 *
		 * To load the Gutenberg M365 blocks.
		 */
		private function load_gutenberg_blocks() {
			$apps = array(
				'docs' => array(
					'edition'               => 'basic',
					'load_front_end_assets' => true,
				),
			);

			$plugins_dir = __DIR__;
			$plugins_url = \plugins_url() . '/' . basename( __DIR__ );

			foreach ( $apps as $app => $settings ) {
				new \Wpo\Blocks\Loader( $app, $settings['edition'], $plugins_dir, $plugins_url, $settings['load_front_end_assets'] );
			}
		}

		private function cache_dependencies() {
			$this->dependencies = Dependency_Service::get_instance();
			$this->dependencies->add( 'Request_Service', Request_Service::get_instance( true ) );
			$this->dependencies->add( 'Files_Service', Files_Service::get_instance() );
		}

		private function update_request_log() {
			$request_service          = Request_Service::get_instance();
			$request                  = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request_log              = $request->get_item( 'request_log' );
			$request_log['debug_log'] = Options_Service::get_global_boolean_var( 'debug_log', false );
			$request->set_item( 'request_log', $request_log );
		}

		private function deactivation_hooks() {

			if ( \class_exists( '\Wpo\Sync\Sync_Manager' ) ) {
				// Delete possible cron jobs
				register_deactivation_hook(
					__FILE__,
					function () {
						\Wpo\Sync\Sync_Manager::get_scheduled_events( true );
					}
				);
			}

			if ( \class_exists( '\Wpo\Sync\Sync_Helpers' ) ) {
				// Delete possible cron jobs
				register_deactivation_hook(
					__FILE__,
					function () {
						\Wpo\Sync\Sync_Helpers::get_scheduled_events( null, true );
					}
				);
			}

			if ( \class_exists( '\Wpo\Mail\Mail_Db' ) ) {
				// Delete possible cron jobs
				register_deactivation_hook(
					__FILE__,
					function () {
						wp_clear_scheduled_hook( 'wpo_process_unsent_messages' );
						Options_Service::add_update_option( 'mail_auto_retry', false );
					}
				);
			}

			register_deactivation_hook(
				__FILE__,
				function () {
					global $wpdb;

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare(
							'DELETE FROM %i WHERE `meta_key` LIKE %s OR `meta_key` LIKE %s OR `meta_key` LIKE %s OR `meta_key` LIKE %s',
							$wpdb->usermeta,
							'wpo_access%',
							'wpo_refresh%',
							'WPO365_AUTH%',
							'wpo_sync_users_%'
						)
					);

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare(
							'DELETE FROM %i WHERE `option_name` LIKE %s AND `option_name` != %s AND `option_name` != %s',
							$wpdb->options,
							'%wpo365%',
							'wpo365_options',
							'wpo365_mail_authorization'
						)
					);

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare(
							'DELETE FROM %i WHERE `option_name` = %s OR `option_name` = %s OR `option_name` = %s',
							$wpdb->options,
							'wpo_app_only_access_tokens',
							'wpo365_x509_certificates',
							'wpo_sync_v2_unscheduled'
						)
					);
				}
			);
		}
	}
}

$wpo365_login = new Login();
