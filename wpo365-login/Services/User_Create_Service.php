<?php

namespace Wpo\Services;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use Wpo\Core\Permissions_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;

if ( ! class_exists( '\Wpo\Services\User_Create_Service' ) ) {

	class User_Create_Service {


		/**
		 * @since 11.0
		 */
		public static function create_user( &$wpo_usr, $is_deamon = false, $exit_on_error = true ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$user_login = ! empty( $wpo_usr->preferred_username )
				? $wpo_usr->preferred_username
				: $wpo_usr->upn;

			if ( ! $is_deamon ) { // Do not apply when synchronizing users

				if ( is_multisite() ) {
					$blog_name = get_bloginfo( 'name' );

					if ( ! Options_Service::mu_use_subsite_options() && ! Options_Service::get_global_boolean_var( 'mu_add_user_to_all_sites' ) ) {

						if ( is_main_site() ) {

							if ( ! Options_Service::get_global_boolean_var( 'create_and_add_users' ) ) {
								Log_Service::write_log( 'WARN', sprintf( '%s -> User %s does not have privileges for site "%s" and is therefore denied access.', __METHOD__, $user_login, $blog_name ) );
								wp_die( sprintf( __( 'You attempted to access "%s", but you do not currently have privileges on this site. If you believe you should be able to access the site, please contact your network administrator.' ), $blog_name ), 403 ); // phpcs:ignore
							}
						} elseif ( Options_Service::get_global_boolean_var( 'skip_add_user_to_subsite' ) ) { // Sub Site
							Log_Service::write_log( 'WARN', sprintf( '%s -> User %s does not have privileges for site "%s" and is therefore denied access.', __METHOD__, $user_login, $blog_name ) );
							wp_die( sprintf( __( 'You attempted to access "%s", but you do not currently have privileges on this site. If you believe you should be able to access the site, please contact your network administrator.' ), $blog_name ), 403 ); // phpcs:ignore
						}
					}

					// WPMU Dedicated Mode
					if ( Options_Service::mu_use_subsite_options() && ! Options_Service::get_global_boolean_var( 'create_and_add_users' ) ) {
						Log_Service::write_log( 'WARN', sprintf( '%s -> User %s does not have privileges for site "%s" and is therefore denied access.', __METHOD__, $user_login, $blog_name ) );
						wp_die( sprintf( __( 'You attempted to access "%s", but you do not currently have privileges on this site. If you believe you should be able to access the site, please contact your network administrator.' ), $blog_name ), 403 ); // phpcs:ignore
					}
				} elseif ( ! Options_Service::get_global_boolean_var( 'create_and_add_users' ) ) {
					Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User not found and settings prevented creating a new user on-demand for user ' . $user_login );
					Authentication_Service::goodbye( Error_Service::USER_NOT_FOUND, false );
				}
			}

			/**
			 * @since   23.0    Added possibility to hook up (custom) actions to pre-defined events for various WPO365 workloads.
			 */

			do_action(
				'wpo365/user/creating',
				$wpo_usr->preferred_username,
				$wpo_usr->email,
				$wpo_usr->groups
			);

			$usr_default_role = is_main_site()
				? Options_Service::get_global_string_var( 'new_usr_default_role' )
				: Options_Service::get_global_string_var( 'mu_new_usr_default_role' );

			$password_length = Options_Service::get_global_numeric_var( 'password_length' );

			if ( empty( $password_length ) || $password_length < 16 ) {
				$password_length = 16;
			}

			$password = Permissions_Helpers::generate_password( $password_length );

			/**
			 * @since 33.2  Allow developers to filter the user_login.
			 */

			$user_login = apply_filters( 'wpo365/user/user_login', $user_login );

			$userdata = array(
				'user_login'   => $user_login,
				'user_pass'    => $password,
				'display_name' => $wpo_usr->full_name,
				'user_email'   => $wpo_usr->email,
				'first_name'   => $wpo_usr->first_name,
				'last_name'    => $wpo_usr->last_name,
				'role'         => $usr_default_role,
			);

			/**
			 * @since 9.4
			 *
			 * Optionally removing any user_register hooks as these more often than
			 * not interfer and cause unexpected behavior.
			 */

			$user_regiser_hooks = null;

			if ( Options_Service::get_global_boolean_var( 'skip_user_register_action' ) && isset( $GLOBALS['wp_filter'] ) && isset( $GLOBALS['wp_filter']['user_register'] ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Temporarily removing all filters for the user_register action to avoid interference' );
				$user_regiser_hooks = $GLOBALS['wp_filter']['user_register'];
				unset( $GLOBALS['wp_filter']['user_register'] );
			}

			$existing_registering = remove_filter( 'wp_pre_insert_user_data', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registering', PHP_INT_MAX );
			$existing_registered  = remove_action( 'user_register', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registered', PHP_INT_MAX );
			$wp_usr_id            = wp_insert_user( $userdata );

			if ( $existing_registering ) {
				add_filter( 'wp_pre_insert_user_data', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registering', PHP_INT_MAX, 4 );
			}

			if ( $existing_registered ) {
				add_action( 'user_register', '\Wpo\Services\Wp_To_Aad_Create_Update_Service::handle_user_registered', PHP_INT_MAX, 1 );
			}

			if ( ! empty( $GLOBALS['wp_filter'] ) && ! empty( $user_regiser_hooks ) ) {
				$GLOBALS['wp_filter']['user_register'] = $user_regiser_hooks; // phpcs:ignore
			}

			if ( is_wp_error( $wp_usr_id ) ) {
				$log_message = sprintf( '%s -> Could not create wp user. [error: %s]', __METHOD__, $wp_usr_id->get_error_message() );
				Log_Service::write_log( 'ERROR', $log_message );

				if ( $exit_on_error ) {
					Authentication_Service::goodbye( Error_Service::CHECK_LOG, false );
				} else {
					do_action( 'wpo365/user/created/fail', $log_message );
				}

				return 0;
			}

			if ( ! empty( $wpo_usr ) ) {
				User_Service::save_user_principal_name( $wpo_usr->upn, $wp_usr_id );
				User_Service::save_user_tenant_id( $wpo_usr->tid, $wp_usr_id );
				User_Service::save_user_object_id( $wpo_usr->oid, $wp_usr_id );
			}

			$wpo_usr->created = true;
			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Created new user with ID ' . $wp_usr_id );

			Wpmu_Helpers::wpmu_add_user_to_blog( $wp_usr_id );

			/**
			 * @since 15.0
			 */

			do_action( 'wpo365/user/created', $wp_usr_id );

			add_filter( 'allow_password_reset', '\Wpo\Services\User_Create_Service::temporarily_allow_password_reset', PHP_INT_MAX, 1 );
			wp_new_user_notification( $wp_usr_id, null, 'both' );
			remove_filter( 'allow_password_reset', '\Wpo\Services\User_Create_Service::temporarily_allow_password_reset', PHP_INT_MAX );

			Wpmu_Helpers::mu_delete_transient( 'wpo365_upgrade_dismissed' );
			Wpmu_Helpers::mu_set_transient( 'wpo365_user_created', gmdate( 'd' ), 1209600 );

			return $wp_usr_id;
		}

		/**
		 * @since 11.0
		 *
		 * @deprecated
		 */
		public static function wpmu_add_user_to_blog( $wp_usr_id, $preferred_user_name ) { // phpcs:ignore
			Wpmu_Helpers::wpmu_add_user_to_blog( $wp_usr_id );
		}

		/**
		 * Helper used to temporarily add as a filter for 'allow_password_reset' when sending a new user email.
		 *
		 * @since   24.0
		 *
		 * @return  true
		 */
		public static function temporarily_allow_password_reset() {
			return true;
		}
	}
}
