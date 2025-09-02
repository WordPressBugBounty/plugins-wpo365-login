<?php

namespace Wpo\Core;

use Wpo\Core\Permissions_Helpers;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Wp_Hooks' ) ) {

	class Wp_Hooks {

		public static function add_wp_hooks() {
			add_filter( 'pre_set_site_transient_update_plugins', '\Wpo\Core\Plugin_Helpers::check_for_updates', 10, 1 );

			// Do super admin stuff
			if ( ( is_admin() || is_network_admin() ) && Permissions_Helpers::user_is_admin( \wp_get_current_user() ) ) {
				// Add and hide wizard (page)
				add_action( 'admin_menu', '\Wpo\Pages\Wizard_Page::add_management_page' );
				add_action( 'network_admin_menu', '\Wpo\Pages\Wizard_Page::add_management_page' );

				new \Wpo\Pages\License_Page();

				// Show admin notification when WPO365 not properly configured
				add_action( 'admin_notices', '\Wpo\Services\Notifications_Service::show_admin_notices', 10, 0 );
				add_action( 'network_admin_notices', '\Wpo\Services\Notifications_Service::show_admin_notices', 10, 0 );
				add_action( 'admin_init', '\Wpo\Services\Notifications_Service::dismiss_admin_notices', 10, 0 );

				// Add license related messages to WP Admin
				\Wpo\Core\Plugin_Helpers::show_license_notices();

				// Show settings link
				add_filter( ( is_network_admin() ? 'network_admin_' : '' ) . 'plugin_action_links_' . $GLOBALS['WPO_CONFIG']['plugin'], '\Wpo\Core\Plugin_Helpers::get_configuration_action_link', 10, 1 );

				// Wire up AJAX backend services
				add_action( 'wp_ajax_wpo365_delete_settings', '\Wpo\Services\Ajax_Service::delete_settings' );
				add_action( 'wp_ajax_wpo365_delete_tokens', '\Wpo\Services\Ajax_Service::delete_tokens' );
				add_action( 'wp_ajax_wpo365_get_settings', '\Wpo\Services\Ajax_Service::get_settings' );
				add_action( 'wp_ajax_wpo365_update_settings', '\Wpo\Services\Ajax_Service::update_settings' );
				add_action( 'wp_ajax_wpo365_get_log', '\Wpo\Services\Ajax_Service::get_log' );
				add_action( 'wp_ajax_wpo365_get_self_test_results', '\Wpo\Services\Ajax_Service::get_self_test_results' );
				add_action( 'wp_ajax_wpo365_import_idp_meta', '\Wpo\Services\Ajax_Service::import_idp_meta' );
				add_action( 'wp_ajax_wpo365_export_sp_meta', '\Wpo\Services\Ajax_Service::export_sp_meta' );
				add_action( 'wp_ajax_wpo365_get_wpo_health_messages', '\Wpo\Services\Ajax_Service::get_wpo_health_messages' );
				add_action( 'wp_ajax_wpo365_dismiss_wpo_health_messages', '\Wpo\Services\Ajax_Service::dismiss_wpo_health_messages' );
				add_action( 'wp_ajax_wpo365_get_insights_summary', '\Wpo\Services\Ajax_Service::get_insights_summary' );
				add_action( 'wp_ajax_wpo365_get_insights', '\Wpo\Services\Ajax_Service::get_insights' );
				add_action( 'wp_ajax_wpo365_get_parseable_options', '\Wpo\Services\Ajax_Service::get_parseable_options' );
				add_action( 'wp_ajax_wpo365_truncate_insights_data', '\Wpo\Services\Ajax_Service::truncate_insights_data' );
				add_action( 'wp_ajax_wpo365_is_wpo365_configured', '\Wpo\Services\Ajax_Service::is_wpo365_configured' );
				add_action( 'wp_ajax_wpo365_get_multiple_idps', '\Wpo\Services\Ajax_Service::get_multiple_idps' );
				add_action( 'wp_ajax_wpo365_copy_main_site_options', '\Wpo\Services\Ajax_Service::copy_main_site_options' );
				add_action( 'wp_ajax_wpo365_switch_wpmu_mode', '\Wpo\Services\Ajax_Service::switch_wpmu_mode' );
				add_action( 'wp_ajax_wpo365_send_test_alert', '\Wpo\Services\Ajax_Service::send_test_alert' );

				// Graph mailer

				if ( Options_Service::get_global_boolean_var( 'use_graph_mailer', false ) ) {
					add_action( 'wp_ajax_wpo365_send_test_mail', '\Wpo\Services\Ajax_Service::send_test_mail' );
					add_action( 'wp_ajax_wpo365_get_mail_authorization_url', '\Wpo\Services\Ajax_Service::get_mail_authorization_url' );
					add_action( 'wp_ajax_wpo365_get_mail_auth_configuration', '\Wpo\Services\Ajax_Service::get_mail_auth_configuration' );
					add_action( 'wp_ajax_wpo365_try_migrate_mail_app_principal_info', '\Wpo\Services\Ajax_Service::try_migrate_mail_app_principal_info' );

					// Graph mailer auditing
					if ( class_exists( '\Wpo\Mail\Mail_Db' ) ) {
						add_action( 'wp_ajax_wpo365_get_mail_log', '\Wpo\Mail\Mail_Ajax_Service::get_mail_log' );
						add_action( 'wp_ajax_wpo365_send_mail_again', '\Wpo\Mail\Mail_Ajax_Service::send_mail_again' );
						add_action( 'wp_ajax_wpo365_truncate_mail_log', '\Wpo\Mail\Mail_Ajax_Service::truncate_mail_log' );

						if ( method_exists( '\Wpo\Mail\Mail_Ajax_Service', 'mail_auto_retry' ) ) {
							add_action( 'wp_ajax_wpo365_mail_auto_retry', '\Wpo\Mail\Mail_Ajax_Service::mail_auto_retry' );
						}
					}
				}

				// User sync

				if ( Options_Service::get_global_boolean_var( 'enable_user_sync', false ) ) {

					if ( class_exists( '\Wpo\Sync\Sync_Admin_Page' ) ) {
						add_action( 'admin_menu', '\Wpo\Sync\Sync_Admin_Page::add_plugin_page', 10 );
						add_action( 'init', '\Wpo\Sync\Sync_Admin_Page::init', 10, 0 );
					}

					if ( class_exists( '\Wpo\Sync\SyncV2_Service' ) ) {

						if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'reactivate_user' ) ) {
							add_action( 'admin_init', '\Wpo\Sync\SyncV2_Service::reactivate_user', 10, 0 );
						}

						if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'register_users_sync_columns' ) ) {
							add_filter( 'manage_users_columns', '\Wpo\Sync\SyncV2_Service::register_users_sync_columns', 10 );
						}

						if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'render_users_sync_columns' ) ) {
							add_filter( 'manage_users_custom_column', '\Wpo\Sync\SyncV2_Service::render_users_sync_columns', 10, 3 );
						}

						if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'test_sync_query' ) ) {
							add_action( 'wp_ajax_wpo365_test_sync_query', '\Wpo\Sync\SyncV2_Service::test_sync_query' );
						}

						if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'users_sync_bulk_actions' ) ) {
							add_filter( 'bulk_actions-users', '\Wpo\Sync\SyncV2_Service::users_sync_bulk_actions', 10, 1 );
							add_filter( 'handle_bulk_actions-users', '\Wpo\Sync\SyncV2_Service::users_sync_bulk_actions_handler', 10, 3 );
						}
					}
				}

				// WP to AAD

				if ( ( Options_Service::get_global_boolean_var( 'use_b2c', false ) || Options_Service::get_global_boolean_var( 'use_ciam', false ) ) && class_exists( '\Wpo\Services\Wp_To_Aad_Create_Update_Service' ) ) {
					add_action( 'admin_init', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::send_to_azure', 10, 0 );
					add_filter( 'manage_users_columns', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::register_users_sync_wp_to_aad_columns', 10 );
					add_filter( 'manage_users_custom_column', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::render_users_sync_wp_to_aad_columns', 10, 3 );
					add_filter( 'bulk_actions-users', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::users_sync_wp_to_aad_bulk_actions', 10, 1 );
					add_filter( 'handle_bulk_actions-users', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::users_sync_wp_to_aad_bulk_actions_handler', 10, 3 );
					add_filter( 'pre_get_users', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::get_users', 10, 1 );
					add_action( 'restrict_manage_users', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::render_button_nok_users', 10, 1 );

					if ( method_exists( '\Wpo\Services\Wp_To_Aad_Create_Update_Service', 'render_user_profile_wp_to_aad_info' ) ) {
						add_action( 'personal_options', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::render_user_profile_wp_to_aad_info', 10, 1 );
						add_action( 'profile_personal_options', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::render_user_profile_wp_to_aad_info', 10, 1 );
					}
				}

				// Ensure WP Cron job to check for each registered application whether its secret will epxire soon is added.

				if ( class_exists( '\Wpo\Services\Password_Credentials_Service' ) ) {
					\Wpo\Services\Password_Credentials_Service::ensure_check_password_credentials_expiration();
				}

				// SCIM specific WP AJAX endpoints
				if ( Options_Service::get_global_boolean_var( 'enable_scim' ) && method_exists( '\Wpo\Services\Scim_Service', 'generate_scim_secret_token' ) ) {
					add_action( 'wp_ajax_wpo365_generate_scim_secret_token', '\Wpo\Services\Scim_Service::generate_scim_secret_token' );
				}

				// To force WordPress to check for plugin updates if requested by an administrator
				add_action( 'admin_post_wpo365_force_check_for_plugin_updates', '\Wpo\Core\Plugin_Helpers::force_check_for_plugin_updates' );
				add_filter( 'plugin_row_meta', '\Wpo\Core\Plugin_Helpers::show_old_version_warning', 10, 2 );
				add_filter( 'plugins_api', '\Wpo\Core\Plugin_Helpers::plugin_info', 20, 3 );

				// Set up the dashboard widget.
				add_action( 'wp_dashboard_setup', '\Wpo\Insights\Dashboard_Service::insights_widget' );
			} // End admin stuff

			if ( Options_Service::get_global_boolean_var( 'insights_alerts_enabled' ) && class_exists( '\Wpo\Insights\Event_Notify_Service' ) ) {
				add_action(
					'wpo365/insights/notify',
					function ( $message, $category = 'N/A', $recipient = '' ) {
						$nofications = new \Wpo\Insights\Event_Notify_Service();
						$nofications->notify( $message, $category, $recipient );
					},
					10,
					3
				);

				add_action(
					'wpo365_insights_check_failed_notifications',
					function () {
						$nofications = new \Wpo\Insights\Event_Notify_Service();
						$nofications->check_failed_notifications();
					}
				);
			}

			// WP Cron job triggered action to check for each registered application whether its secret will epxire soon.
			add_action( 'wpo_check_password_credentials_expiration', '\Wpo\Services\Password_Credentials_Service::check_password_credentials_expiration' );

			// Auth.-only
			if ( class_exists( '\Wpo\Services\Auth_Only_Service' ) ) {
				$scenario = Options_Service::get_global_string_var( 'auth_scenario', false );

				if ( $scenario === 'internetAuthOnly' || $scenario === 'intranetAuthOnly' ) {
					add_filter( 'wpo365/cookie/redirect', '\Wpo\Services\Auth_Only_Service::cookie_redirect', 10 );
					add_filter( 'wpo365/cookie/set', '\Wpo\Services\Auth_Only_Service::set_wpo_cookie', 10, 2 );
					add_filter( 'wpo365_skip_authentication', '\Wpo\Services\Auth_Only_Service::validate_auth_cookie', 10 );
					add_filter( 'wpo365/cookie/remove/url', '\Wpo\Services\Auth_Only_Service::remove_cookie_from_url', 10, 1 );
				}
			}

			// Hooks used by cron jobs to schedule user synchronization events
			if ( class_exists( '\Wpo\Sync\Sync_Manager' ) ) {
				add_action( 'wpo_sync_users', '\Wpo\Sync\Sync_Manager::fetch_users', 10, 3 );
				add_action( 'wpo_sync_users_start', '\Wpo\Sync\Sync_Manager::fetch_users', 10, 2 );
			}

			// Hooks used by cron jobs to schedule user synchronization events
			if ( class_exists( '\Wpo\Sync\SyncV2_Service' ) ) {
				add_action( 'wpo_sync_v2_users_start', '\Wpo\Sync\SyncV2_Service::sync_users', 10, 1 );
				add_action( 'wpo_sync_v2_users_next', '\Wpo\Sync\SyncV2_Service::fetch_users', 10, 2 );
			}

			// Hooks to monitor WPO365 User Synchronization
			if ( method_exists( '\Wpo\Sync\Sync_Helpers', 'user_sync_monitor' ) ) {
				add_action( 'wpo_sync_v2_monitor', '\Wpo\Sync\Sync_Helpers::user_sync_monitor', 10, 1 );
				add_action( 'wpo365/sync/before', '\Wpo\Sync\Sync_Helpers::schedule_user_sync_monitor', 10, 1 );
			}

			if ( ( Options_Service::get_global_boolean_var( 'use_b2c', false ) || Options_Service::get_global_boolean_var( 'use_ciam', false ) ) ) {

				if ( class_exists( '\Wpo\Sync\Sync_Wp_To_Aad_Service' ) ) {
					add_action( 'wpo_sync_wp_to_aad_start', '\Wpo\Sync\Sync_Wp_To_Aad_Service::sync_users', 10, 1 );
					add_action( 'wpo_sync_wp_to_aad_next', '\Wpo\Sync\Sync_Wp_To_Aad_Service::fetch_users', 10, 2 );
				}

				if ( class_exists( '\Wpo\Services\Wp_To_Aad_Create_Update_Service' ) ) {
					add_action( 'user_register', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registered', PHP_INT_MAX, 1 );
					add_filter( 'wp_pre_insert_user_data', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registering', PHP_INT_MAX, 4 );
					add_action( 'wpo365/aad_user/created', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::send_new_customer_notification', 10, 2 );
				}

				add_filter( 'register_url', '\Wpo\Services\Id_Token_Service_B2c::get_registration_url', 10, 1 );
			}

			if ( Options_Service::get_global_boolean_var( 'use_b2c', false ) && class_exists( '\Wpo\Services\B2c_Embedded_Service' ) ) {
				add_action( 'init', 'Wpo\Services\B2c_Embedded_Service::ensure_b2c_embedded_short_code' );
			}

			// Ensure session is valid and remains valid
			add_action( 'destroy_wpo365_session', '\Wpo\Services\Authentication_Service::destroy_session' );

			// Prevent email address update
			add_action( 'personal_options_update', '\Wpo\Core\Permissions_Helpers::prevent_email_change', 10, 1 );

			// Redirect when user is not logged in and tries to navigate to a private page
			add_action( 'posts_selection', '\Wpo\Services\Authentication_Service::check_private_pages' );

			// Add short code(s)
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_pintra_short_code' );
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_display_error_message_short_code' );
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_login_button_short_code' );
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_login_button_short_code_V2' );
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_wpo365_redirect_script_sc' );
			add_action( 'init', 'Wpo\Core\Shortcode_Helpers::ensure_sso_button_sc' );

			// Wire up AJAX backend services
			add_action( 'wp_ajax_get_tokencache', '\Wpo\Services\Ajax_Service::get_tokencache' );
			add_action( 'wp_ajax_cors_proxy', '\Wpo\Services\Ajax_Service::cors_proxy' );

			// Register custom post meta for Audiences
			if ( Options_Service::get_global_boolean_var( 'enable_audiences', false ) && class_exists( '\Wpo\Services\Audiences_Service' ) ) {

				// Filters
				if ( method_exists( '\Wpo\Services\Audiences_Service', 'register_posts_audiences_column' ) ) {
					add_filter( 'manage_pages_columns', '\Wpo\Services\Audiences_Service::register_posts_audiences_column', 10, 1 );
					add_filter( 'manage_posts_columns', '\Wpo\Services\Audiences_Service::register_posts_audiences_column', 10, 2 );
					add_action( 'manage_pages_custom_column', '\Wpo\Services\Audiences_Service::render_posts_audiences_column', 10, 2 );
					add_action( 'manage_posts_custom_column', '\Wpo\Services\Audiences_Service::render_posts_audiences_column', 10, 2 );
				}

				add_filter( 'manage_users_columns', '\Wpo\Services\Audiences_Service::register_users_audiences_column', 10 );
				add_filter( 'manage_users_custom_column', '\Wpo\Services\Audiences_Service::render_users_audiences_column', 10, 3 );
				add_filter( 'posts_where', '\Wpo\Services\Audiences_Service::posts_where', 10, 2 );
				add_filter( 'get_pages', '\Wpo\Services\Audiences_Service::get_pages', 10, 2 );
				add_filter( 'wp_count_posts', '\Wpo\Services\Audiences_Service::wp_count_posts', 10, 3 );
				add_filter( 'get_previous_post_where', '\Wpo\Services\Audiences_Service::get_previous_post_where', 10, 5 );
				add_filter( 'get_next_post_where', '\Wpo\Services\Audiences_Service::get_next_post_where', 10, 5 );

				if ( \method_exists( '\Wpo\Services\Audiences_Service', 'map_meta_cap' ) ) {
					add_filter( 'map_meta_cap', '\Wpo\Services\Audiences_Service::map_meta_cap', 10, 4 );
				}

				// Actions
				add_action( 'init', '\Wpo\Services\Audiences_Service::aud_register_post_meta', 10 );

				if ( \method_exists( '\Wpo\Services\Audiences_Service', 'audiences_add_meta_box' ) ) {
					add_action( 'add_meta_boxes', '\Wpo\Services\Audiences_Service::audiences_add_meta_box', 10, 2 );
				}

				if ( \method_exists( '\Wpo\Services\Audiences_Service', 'audiences_save_post' ) ) {
					add_action( 'save_post', '\Wpo\Services\Audiences_Service::audiences_save_post', 10, 3 );
				}

				if ( \method_exists( '\Wpo\Services\Audiences_Service', 'handle_404' ) ) {
					add_filter( 'status_header', '\Wpo\Services\Audiences_Service::handle_404', 10, 4 );
				}

				// WP-REST

				add_action(
					'rest_api_init',
					function () {
						define( 'WPO365_REST_REQUEST', true );
					}
				);

				if ( Options_Service::get_global_boolean_var( 'enable_audiences_rest', false ) ) {

					$post_types = get_post_types();

					foreach ( $post_types as $post_type ) {

						add_filter( 'rest_prepare_{' . $post_type . '}', '\Wpo\Services\Audiences_Service::rest_prepare_post', 10, 3 );
					}
				}
			}

			// Clean up on shutdown
			add_action( 'shutdown', '\Wpo\Services\Request_Service::shutdown', PHP_INT_MAX );

			// Add pintraredirectjs if use for Teams (or "loading" template / client-side redirect) has been enabled
			if ( Options_Service::get_global_boolean_var( 'use_teams', false ) ) {
				add_action( 'wp_enqueue_scripts', '\Wpo\Core\Script_Helpers::enqueue_pintra_redirect', 10, 0 );
			}

			add_action( 'login_enqueue_scripts', '\Wpo\Core\Script_Helpers::enqueue_pintra_redirect', 10, 0 );
			add_action( 'admin_enqueue_scripts', '\Wpo\Core\Script_Helpers::enqueue_wizard', 10, 0 );
			add_filter( 'script_loader_tag', '\Wpo\Core\Script_Helpers::enqueue_script_asynchronously', 10, 3 );

			// Add safe style css
			add_filter( 'safe_style_css', '\Wpo\Core\WordPress_Helpers::safe_css', 10, 1 );

			// Adds the login button
			add_action( 'login_form', '\Wpo\Core\Shortcode_Helpers::login_button', 10 );

			// Init the custom REST API for config
			if ( class_exists( '\Wpo\Core\Config_Controller' ) ) {
				add_action(
					'rest_api_init',
					function () {
						$config_controller = new \Wpo\Core\Config_Controller();
						$config_controller->register_routes();
					}
				);
			}

			// Init the custom REST API for user sync
			if ( class_exists( '\Wpo\Sync\SyncV2_Controller' ) ) {
				add_action(
					'rest_api_init',
					function () {
						$sync_controller = new \Wpo\Sync\SyncV2_Controller();
						$sync_controller->register_routes();
					}
				);
			}

			// Init the custom REST API for PINTRA
			if ( class_exists( '\Wpo\Graph\Controller' ) && Options_Service::get_global_boolean_var( 'enable_graph_api', false ) ) {
				add_action(
					'rest_api_init',
					function () {
						$graph_controller = new \Wpo\Graph\Controller();
						$graph_controller->register_routes();
					}
				);
			}

			// Enable X-WP-NONCE (cookies) protection for WordPress REST API
			if ( Options_Service::get_global_boolean_var( 'use_wp_rest_cookies', false ) ) {
				add_filter( 'rest_authentication_errors', '\Wpo\Services\Rest_Authentication_Service_Cookies::authenticate_request', 10, 1 );
			}

			// Enable Azure AD protection for WordPress REST API
			if ( class_exists( '\Wpo\Services\Rest_Authentication_Service_Aad' ) && Options_Service::get_global_boolean_var( 'use_wp_rest_aad', false ) ) {
				add_filter( 'rest_authentication_errors', '\Wpo\Services\Rest_Authentication_Service_Aad::authenticate_request', 10, 1 );
			}

			if ( class_exists( '\Wpo\Services\User_Custom_Fields_Service' ) ) {
				// Add extra user profile fields
				add_action( 'show_user_profile', '\Wpo\Services\User_Custom_Fields_Service::show_extra_user_fields', 10, 1 );
				add_action( 'edit_user_profile', '\Wpo\Services\User_Custom_Fields_Service::show_extra_user_fields', 10, 1 );
				add_action( 'personal_options_update', '\Wpo\Services\User_Custom_Fields_Service::save_user_details', 10, 1 );
				add_action( 'edit_user_profile_update', '\Wpo\Services\User_Custom_Fields_Service::save_user_details', 10, 1 );
			}

			if ( class_exists( '\Wpo\Services\Login_Service' ) ) {
				// Prevent WP default login for O365 accounts
				add_action( 'wp_authenticate', '\Wpo\Services\Login_Service::prevent_default_login_for_o365_users', 11, 1 );
			}

			/**
			 * @since 27.0
			 */

			add_action( 'wp_authenticate', '\Wpo\Services\Wp_Config_Service::dynamically_redirect_to_idp', 2, 3 );

			// Hide the admin Bar
			add_action( 'after_setup_theme', '\Wpo\Core\WordPress_Helpers::hide_admin_bar', 10 );

			if ( class_exists( '\Wpo\Services\Authentication_Service' ) ) {
				// Prevent WP login for deactivated users
				add_action( 'wp_authenticate', '\Wpo\Services\Authentication_Service::is_deactivated', 10, 1 );
			}

			if ( Options_Service::get_global_boolean_var( 'enable_scim', false ) && method_exists( '\Wpo\Services\Scim_Service', 'register_routes' ) ) {
				add_action( 'rest_api_init', '\Wpo\Services\Scim_Service::register_routes' );
			}

			if ( Options_Service::get_global_boolean_var( 'use_graph_mailer', false ) ) {
				add_action( 'phpmailer_init', '\Wpo\Mail\Mailer::init', PHP_INT_MAX );
				add_filter( 'wp_mail_from', '\Wpo\Mail\Mailer::mail_from', 10, 1 );

				if ( Options_Service::get_global_boolean_var( 'mail_log', false ) && method_exists( '\Wpo\Mail\Mail_Db', 'add_mail_log' ) ) {
					add_filter( 'wp_mail', '\Wpo\Mail\Mail_Db::add_mail_log', 10, 1 );
				}

				if ( Options_Service::get_global_boolean_var( 'mail_throttling_enabled' ) && method_exists( '\Wpo\Mail\Mail_Db', 'check_message_rate_limit' ) ) {
					add_filter( 'wpo365/mail/before', '\Wpo\Mail\Mail_Db::check_message_rate_limit' );
				}

				if ( Options_Service::get_global_boolean_var( 'mail_auto_retry' ) && method_exists( '\Wpo\Mail\Mail_Db', 'process_unsent_messages' ) ) {
					add_action( 'wpo_process_unsent_messages', '\Wpo\Mail\Mail_Db::process_unsent_messages' );
					add_action(
						'admin_init',
						function () {
							\Wpo\Mail\Mail_Db::ensure_unsent_messages( false );
						}
					);
				}

				// Admin menu bar notifications
				if ( Options_Service::get_global_boolean_var( 'mail_staging_mode', false ) && class_exists( '\Wpo\Mail\Mail_Notifications' ) ) {
					add_action( 'admin_bar_menu', '\Wpo\Mail\Mail_Notifications::staging_mode_active', 100 );
					add_action( 'wp_enqueue_scripts', '\Wpo\Core\Script_Helpers::add_admin_bar_styles' );
					add_action( 'admin_enqueue_scripts', '\Wpo\Core\Script_Helpers::add_admin_bar_styles' );
				}
			}

			// BUDDY PRESS
			if ( class_exists( '\Wpo\Services\BuddyPress_Service' ) ) {
				// Add extra user profile fields to Buddy Press
				add_action( 'bp_after_profile_loop_content', '\Wpo\Services\BuddyPress_Service::bp_show_extra_user_fields', 10, 1 );
				// Replace avatar with O365 avatar (if available)
				add_filter( 'bp_core_fetch_avatar', '\Wpo\Services\BuddyPress_Service::fetch_buddy_press_avatar', 99, 2 );

				if ( method_exists( '\Wpo\Services\BuddyPress_Service', 'fetch_buddy_press_avatar_url' ) ) {
					add_filter( 'bp_core_fetch_avatar_url', '\Wpo\Services\BuddyPress_Service::fetch_buddy_press_avatar_url', 99, 2 );
				}
			}

			// Only allow password changes for non-O365 users and only when already logged on to the system
			add_filter( 'show_password_fields', '\Wpo\Core\Permissions_Helpers::show_password_fields', 10, 2 );
			add_filter( 'allow_password_reset', '\Wpo\Core\Permissions_Helpers::allow_password_reset', 10, 2 );

			// Enable login message output
			add_filter( 'login_message', '\Wpo\Services\Error_Service::check_for_login_messages', 10, 1 );

			// Add custom wp query vars
			add_filter( 'query_vars', '\Wpo\Core\Url_Helpers::add_query_vars_filter' );

			if ( class_exists( '\Wpo\Services\User_Details_Service' ) ) {
				add_filter( 'send_email_change_email', '\Wpo\Services\User_Details_Service::prevent_send_email_change_email' );
			}

			if ( Options_Service::get_global_boolean_var( 'prevent_send_password_change_email', false ) ) {
				remove_all_actions( 'after_password_reset' );
			}

			if ( Options_Service::get_global_boolean_var( 'use_avatar', false ) && class_exists( '\Wpo\Services\Avatar_Service' ) ) {
				// Replace avatar with O365 avatar (if available)
				$avatar_hook_priority = Options_Service::get_global_numeric_var( 'avatar_hook_priority', false );
				$avatar_hook_priority = $avatar_hook_priority > 0 ? $avatar_hook_priority : 1;

				add_filter( 'pre_get_avatar', '__return_null', PHP_INT_MAX, 3 );

				if ( \method_exists( '\Wpo\Services\Avatar_Service', 'pre_get_avatar_data' ) ) {
					add_filter( 'pre_get_avatar_data', '\Wpo\Services\Avatar_Service::pre_get_avatar_data', $avatar_hook_priority, 2 );
				} else {
					add_filter( 'get_avatar', '\Wpo\Services\Avatar_Service::get_O365_avatar', $avatar_hook_priority, 6 );
				}
			}

			if ( Options_Service::get_global_boolean_var( 'new_usr_send_mail_custom', false ) && class_exists( '\Wpo\Services\Mail_Notifications_Service' ) ) {
				// Filter to change the new user email notification
				add_filter( 'wp_new_user_notification_email', '\Wpo\Services\Mail_Notifications_Service::new_user_notification_email', 99, 3 );
			}

			// Suppress new user notifications when the user is created through WPO365 and the notification is not explicitely enabled
			if ( ! Options_Service::get_global_boolean_var( 'new_usr_send_mail', false ) ) {
				add_filter(
					'wp_send_new_user_notification_to_user',
					function () {
						$request_service = Request_Service::get_instance();
						$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
						$is_scim         = $request->get_item( 'scim' );

						return $is_scim || ! empty( \Wpo\Services\User_Service::get_wpo_user_from_context() ) ? false : true;
					}
				);

				add_filter(
					'wp_send_new_user_notification_to_admin',
					function () {
						$request_service = Request_Service::get_instance();
						$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
						$is_scim         = $request->get_item( 'scim' );

						return $is_scim || ! empty( \Wpo\Services\User_Service::get_wpo_user_from_context() ) ? false : true;
					}
				);
			}

			if ( Options_Service::get_global_boolean_var( 'new_usr_send_mail_admin_only', false ) ) {
				add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
			}

			// Must be added before the next wp_logout hook
			add_action( 'wp_logout', '\Wpo\Services\Authentication_Service::destroy_session', 1, 0 );

			if ( class_exists( '\Wpo\Services\Logout_Service' ) ) {
				add_action( 'wp_logout', '\Wpo\Services\Logout_Service::logout_O365', 1, 1 );
				add_action( 'wp_logout', '\Wpo\Services\Logout_Service::send_to_custom_logout_page', 2, 1 );
			}

			// To support single sign out without user confirmation
			if ( class_exists( '\Wpo\Services\Redirect_Service' ) ) {
				add_action( 'check_admin_referer', '\Wpo\Services\Redirect_Service::logout_without_confirmation', 10, 2 );
			}

			// New default actions
			add_action( 'wpo365/oidc/authenticating', '\Wpo\Services\Authentication_Service::user_from_domain', 10, 3 );
			add_action( 'wpo365/saml2/authenticating', '\Wpo\Services\Authentication_Service::user_from_domain', 10, 3 );

			// Update WPO365 extensions cache whenever a plugin is added or deleted
			add_action( 'activated_plugin', '\Wpo\Core\Extensions_Helpers::plugin_activated', 10, 2 );
			add_action( 'deactivated_plugin', '\Wpo\Core\Extensions_Helpers::plugin_deactivated', 10, 2 );

			// Check again for updates after upgrader process completes to fix "Update available notice"
			add_action( 'upgrader_process_complete', '\Wpo\Core\Extensions_Helpers::plugin_updated', 10, 2 );

			// To collect Insights
			if ( Options_Service::get_global_boolean_var( 'insights_enabled', false ) ) {
				add_action( 'user_register', '\Wpo\Insights\Event_Service::user_created__handler', 10, 1 );
				add_action( 'wpo365/user/created/fail', '\Wpo\Insights\Event_Service::user_created_fail__handler', 10, 1 );
				add_action( 'set_logged_in_cookie', '\Wpo\Insights\Event_Service::user_loggedin__handler', 10, 6 );
				add_action( 'wpo365/user/loggedin/fail', '\Wpo\Insights\Event_Service::user_loggedin_fail__handler', 10, 1 );
				add_filter( 'authenticate', '\Wpo\Insights\Event_Service::authenticate__handler', 10, 3 );
				add_action( 'wp_login_failed', '\Wpo\Insights\Event_Service::wp_login_failed__handler', 10, 2 );
				add_action( 'wpo365/user/updated', '\Wpo\Insights\Event_Service::user_updated__handler', 10, 1 );
				add_action( 'wpo365/user/updated/fail', '\Wpo\Insights\Event_Service::user_updated_fail__handler', 10, 2 );
				add_action( 'wpo365/mail/sent', '\Wpo\Insights\Event_Service::mail_sent__handler', 10, 1 );
				add_action( 'wpo365/mail/sent/fail', '\Wpo\Insights\Event_Service::mail_sent_fail__handler', 10, 1 );
				add_action( 'wpo365/alert/submitted', '\Wpo\Insights\Event_Service::notification_sent__handler', 10, 3 );
				add_action( 'wpo365/alert/submitted/fail', '\Wpo\Insights\Event_Service::notification_sent_fail__handler', 10, 3 );
			}

			// To collect cURL logging
			if ( Options_Service::get_global_boolean_var( 'curl_logging_enabled', false ) ) {
				add_action( 'http_api_curl', '\Wpo\Services\Log_Service::enable_curl_logging', 10, 3 );
			}

			// To exit whenever the username entered is not in the WPO_ADMINS list
			if ( Options_Service::get_global_boolean_var( 'prevent_non_admin_login', false ) && defined( 'WPO_ADMINS' ) ) {
				add_filter( 'authenticate', '\Wpo\Core\Permissions_Helpers::is_wpo_admin', 1, 3 );
			}

			// To add users to a new subsite (WPMU)
			if ( is_multisite() && Options_Service::get_global_boolean_var( 'mu_add_user_to_all_sites', false ) && method_exists( '\Wpo\Services\User_Create_Update_Service', 'wpmu_add_users_to_blog' ) ) {
				add_action( 'wp_initialize_site', '\Wpo\Services\User_Create_Update_Service::wpmu_add_users_to_blog', 10, 2 );
			}

			// Set up new subsite for new user (WPMU)
			if ( method_exists( '\Wpo\Services\User_Create_Update_Service', 'wpmu_add_new_user_site' ) ) {
				add_action( 'wpo365/user/created', '\Wpo\Services\User_Create_Update_Service::wpmu_add_new_user_site', 10, 1 );
				add_filter( 'wpo365/wpmu/user_site/name', '\Wpo\Core\Wpmu_Helpers::user_site_name', 10, 1 );
			}

			// To show a custom access denied splash (WPMU).
			add_action( 'wpo365/wpmu/access_denied', '\Wpo\Core\Wpmu_Helpers::wpmu_access_denied_splash', 10, 1 );

			// Apply updates to the internal WPO365 representation of a User (see Wpo\Core\User)
			if ( method_exists( '\Wpo\Services\User_Details_Service', 'set_user_display_name' ) ) {
				add_filter( 'wpo365/user', '\Wpo\Services\User_Details_Service::set_user_display_name', 10, 2 );
			}

			// Set a user's primary blog if a new user is added to a blog
			add_action( 'wpo365/user/created', '\Wpo\Core\Wpmu_Helpers::set_user_primary_blog', 10, 1 );
		}

		public static function add_wp_cli_commands() {
			if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) === true ) {
				\WP_CLI::add_command( 'wpo365', '\Wpo\Services\Wp_Cli_Service' );
			}
		}
	}
}
