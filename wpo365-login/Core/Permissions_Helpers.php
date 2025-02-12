<?php

namespace Wpo\Core;

use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\User_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Permissions_Helpers' ) ) {

	class Permissions_Helpers {


		/**
		 * @since 7.12
		 */
		public static function user_is_admin( $user ) {
			return user_can( $user->ID, 'remove_users' );
		}

		/**
		 * Returns true when a user is allowed to change the password
		 *
		 * @since   1.0
		 *
		 * @return boolean true when a user is allowed to change the password otherwise false
		 */
		public static function show_password_fields( $show, $user ) {

			return ! self::block_password_update( $user->ID );
		}

		/**
		 * Returns true when a user is allowed to change the password
		 *
		 * @since 1.5
		 *
		 * @param boolean $allow whether allowed or not.
		 * @param int     $user_id id of the user for which the action is triggered.
		 *
		 * @return boolean true when a user is allowed to change the password otherwise false
		 */
		public static function allow_password_reset( $allow, $user_id ) {
			return ! self::block_password_update( $user_id );
		}

		/**
		 * Helper method to determin whether a user is allowed to change the password
		 *
		 * @since 1.5
		 *
		 * @param int $user_id id of the user for which the action is triggered.
		 *
		 * @return boolean true when a user is not allowed to change the password otherwise false
		 */
		private static function block_password_update( $user_id ) {
			$block_password_change = Options_Service::get_global_boolean_var( 'block_password_change' );

			if ( ! $block_password_change ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Not blocking password update' );
				return false;
			}

			if ( current_user_can( 'remove_users' ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Not hiding Account Management section for admins on user-edit form' );
				return false;
			}

			$use_customers_tenants = Options_Service::get_global_boolean_var( 'use_b2c' ) || Options_Service::get_global_boolean_var( 'use_ciam' );

			// Limit the blocking of password update only for O365 users
			return ( $use_customers_tenants || User_Service::user_is_o365_user( $user_id ) === User_Service::IS_O365_USER ) ? true : false;
		}

		/**
		 * Prevents users who cannot create new users to change their email address
		 *
		 * @since   1.0
		 *
		 * @param   int $user_id
		 *
		 * @return  void
		 */
		public static function prevent_email_change( $user_id ) {

			// Don't block as per global settings configuration
			if ( Options_Service::get_global_boolean_var( 'block_email_change' ) === false ) {
				return;
			}

			$use_customers_tenants = Options_Service::get_global_boolean_var( 'use_b2c' ) || Options_Service::get_global_boolean_var( 'use_ciam' );

			if ( ! $use_customers_tenants && User_Service::user_is_o365_user( $user_id ) !== User_Service::IS_O365_USER ) {
				return;
			}

			if ( current_user_can( 'remove_users' ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Not preventing an administrator from updating an email address' );
				return;
			}

			$usr_old = get_user_by( 'ID', intval( $user_id ) );

			if ( $usr_old === false ) {
				return;
			}

			// At this point the user is an O365 user and email change should be blocked as per config
			if ( isset( $_POST['email'] ) && $_POST['email'] !== $usr_old->user_email ) { // phpcs:ignore

				// Prevent update
				$_POST['email'] = $usr_old->user_email;

				add_action(
					'user_profile_update_errors',
					function ( $errors ) {
						$errors->add( 'email_update_error', __( 'Updating your email address is currently not allowed', 'wpo365-login' ) );
					}
				);
			}
		}

		/**
		 * Quick check whether the requested scope e.g. api.yammer.com requires delegated access.
		 *
		 * @since   17.0
		 *
		 * @param   string $scope  The scope the requested access must be valid for.
		 * @return  boolean             True if delegated access is required for the scope provide.
		 */
		public static function must_use_delegate_access_for_scope( $scope ) {
			return ( WordPress_Helpers::stripos( $scope, 'api.yammer.com' ) !== false ||
				WordPress_Helpers::stripos( $scope, '.sharepoint.com' ) !== false ||
				( WordPress_Helpers::stripos( $scope, 'user.read.all' ) === false && WordPress_Helpers::stripos( $scope, 'user.read' ) !== false )
			);
		}

		/**
		 * Improved version of the wp_generate_password method that ensures that the generated
		 * password has characters from all 4 possible groups of characters (lowercase, uppercase,
		 * numbers and symbols).
		 *
		 * @param int $length
		 * @return string
		 */
		public static function generate_password( $length = 16 ) {
			$chars = array(
				'abcdefghijklmnopqrstuvwxyz',
				'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
				'0123456789',
				'!@#$%^&*()',
			);

			$chars_str = implode( '', $chars );

			$make_password = function () use ( $chars_str, $length ) {
				$password = '';

				for ( $i = 0; $i < $length; $i++ ) {
					$password .= substr( $chars_str, wp_rand( 0, strlen( $chars_str ) - 1 ), 1 );
				}

				return $password;
			};

			$check_password = function ( $password = '' ) use ( $chars ) {
				$score      = array( 'false', 'false', 'false', 'false' );
				$pwd_length = strlen( $password );

				for ( $i = 0; $i < $pwd_length; $i++ ) {
					$char = substr( $password, $i, 1 );

					for ( $j = 0; $j < 4; $j++ ) {

						if ( strpos( $chars[ $j ], $char ) !== false ) {
							$score[ $j ] = 'true';
							break;
						}
					}

					if ( ! in_array( 'false', $score, true ) ) {
						return true;
					}
				}

				return false;
			};

			$attempts = 0;

			do {
				$password = $make_password();
				++$attempts;
			} while ( ! $check_password( $password ) && $attempts < 20 );

			return $password;
		}

		/**
		 * Authentication handler for the 'authenticate' hook that will exit
		 * if the username is not in the list of WPO_ADMINS.
		 *
		 * @param WP_User $user
		 * @param string  $username
		 * @param string  $password
		 *
		 * @return WP_User
		 */
		public static function is_wpo_admin( $user, $username, $password ) { // phpcs:ignore

			if ( empty( $username ) ) {
				return $user;
			}

			$admins = constant( 'WPO_ADMINS' );

			if ( ! is_array( $admins ) ) {
				return $user;
			}

			$admins   = array_flip( $admins );
			$admins   = array_change_key_case( $admins );
			$username = strtolower( $username );

			if ( ! array_key_exists( $username, $admins ) ) {
				exit( 'Invalid username, email address or incorrect password.' );
			}

			return $user;
		}
	}
}
