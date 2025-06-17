<?php

namespace Wpo\Core;

use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Error_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Wpmu_Helpers' ) ) {

	class Wpmu_Helpers {


		/**
		 * Helper to get the global or local transient based on the
		 * WPMU configuration.
		 *
		 * @since 9.2
		 *
		 * @return mixed Returns the value of transient or false if not found
		 */
		public static function mu_get_transient( $name ) {

			if ( ! is_multisite() || ( Options_Service::mu_use_subsite_options() && ! self::mu_is_network_admin() ) ) {
				return get_transient( $name );
			}

			return get_site_transient( $name );
		}

		/**
		 * Helper to set the global or local transient based on the
		 * WPMU configuration.
		 *
		 * @since 9.2
		 *
		 * @param string $name Name of transient.
		 * @param mixed  $value Value of transient.
		 * @param int    $duration Time transient should be cached in seconds.
		 *
		 * @return void
		 */
		public static function mu_set_transient( $name, $value, $duration = 0 ) {

			if ( ! is_multisite() || ( Options_Service::mu_use_subsite_options() && ! self::mu_is_network_admin() ) ) {
				set_transient( $name, $value, $duration );
			} else {
				set_site_transient( $name, $value, $duration );
			}
		}

		/**
		 * Helper to delete the global or local transient based on the
		 * WPMU configuration.
		 *
		 * @since 10.9
		 *
		 * @param string $name Name of transient.
		 *
		 * @return void
		 */
		public static function mu_delete_transient( $name ) {

			if ( ! is_multisite() || ( Options_Service::mu_use_subsite_options() && ! self::mu_is_network_admin() ) ) {
				delete_transient( $name );
			} else {
				delete_site_transient( $name );
			}
		}

		/**
		 * Helper to check if the current request is for a network admin page and it includes a simple
		 * check if the request is made from an AJAX call.
		 *
		 * @since   11.18
		 *
		 * @return  boolean  True if the request is for a network admin page other false.
		 */
		public static function mu_is_network_admin() {
			return ( is_network_admin() || $GLOBALS['WPO_CONFIG']['ina'] === true );
		}

		/**
		 * Helper to switch the current blog from the main site to a subsite in case
		 * of a multisite installation (shared scenario) when the user is redirected
		 * back to the main site whereas the state URL indicates that the target is
		 * a subsite.
		 *
		 * @since   11.0
		 *
		 * @param   string $state_url The (Relay) state URL.
		 *
		 * @return  void
		 */
		public static function switch_blog( $state_url ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( is_multisite() && ! empty( $state_url ) ) {
				$redirect_url = Options_Service::get_aad_option( 'redirect_url' );
				$redirect_url = Options_Service::get_global_boolean_var( 'use_saml' )
					? Options_Service::get_aad_option( 'saml_sp_acs_url' )
					: $redirect_url;
				$redirect_url = apply_filters( 'wpo365/aad/redirect_uri', $redirect_url );

				$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
				$state_host    = wp_parse_url( $state_url, PHP_URL_HOST );
				$state_path    = '/';
				$redirect_path = '/';

				if ( ! is_subdomain_install() ) {
					$redirect_path = wp_parse_url( $redirect_url, PHP_URL_PATH );
					$state_path    = wp_parse_url( $state_url, PHP_URL_PATH );
				}

				$state_blog_id    = self::get_blog_id_from_host_and_path( $state_host, $state_path );
				$redirect_blog_id = self::get_blog_id_from_host_and_path( $redirect_host, $redirect_path );

				Log_Service::write_log( 'DEBUG', __METHOD__ . " -> Detected WPMU with state context (path: $state_path - ID: $state_blog_id) and AAD redirect context (path: $redirect_path - ID: $redirect_blog_id)" );

				if ( $state_blog_id !== $redirect_blog_id ) {
					switch_to_blog( $state_blog_id );
					$GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] = get_option( 'home' );
				}
			}
		}

		/**
		 * Helper to try and search for a matching blog by itteratively removing the last segment from the path.
		 *
		 * @since   16.0
		 *
		 * @param   string $host   The domain e.g. www.your-site.com.
		 * @param   string $path   The path starting with a slash.
		 *
		 * @return  int     The blog ID or 0 if not found
		 */
		public static function get_blog_id_from_host_and_path( $host, $path ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$blog_id = get_blog_id_from_url( $host, $path );

			if ( ! empty( $blog_id ) ) {
				return $blog_id;
			}

			$path       = WordPress_Helpers::rtrim( $path, '/' );
			$path       = WordPress_Helpers::ltrim( $path, '/' );
			$segments   = explode( '/', $path );
			$segments[] = 'placeholder'; // Add empty string to start with full URL when popping elements from the end

			while ( ( $last_element = array_pop( $segments ) ) !== null ) { // phpcs:ignore
				$path = '/' . implode( '/', $segments );

				if ( strlen( $path ) > 1 ) {
					$path = $path . '/';
				}

				$blog_id = get_blog_id_from_url( $host, $path );

				if ( $blog_id > 0 ) {
					return $blog_id;
				}
			}

			return 0;
		}

		/**
		 * Helper to cache the blog ID where WPO365 should look for its options.
		 *
		 * @return mixed
		 */
		public static function get_options_blog_id() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$blog_id         = $request->get_item( 'blog_id' );

			if ( $blog_id !== false ) {
				return $blog_id;
			}

			return ( Options_Service::mu_use_subsite_options() && ! self::mu_is_network_admin() ) ? get_current_blog_id() : get_main_site_id();
		}

		/**
		 * @since 11.0
		 * @since 28.x  Moved to Wpmu_Helpers
		 */
		public static function wpmu_add_user_to_blog( $wp_usr_id, $blog_id = null, $site_id = null ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( ! is_multisite() || is_super_admin( $wp_usr_id ) ) {
				return;
			}

			if ( $blog_id === null ) {
				$blog_id = get_current_blog_id();
			}

			$is_main_site = is_main_site( $site_id );

			$usr_default_role = $is_main_site
				? Options_Service::get_global_string_var( 'new_usr_default_role' )
				: Options_Service::get_global_string_var( 'mu_new_usr_default_role' );

			if ( empty( $usr_default_role ) ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Could not add user with ID ' . $wp_usr_id . ' to current blog with ID ' . $blog_id . ' because the default role for the subsite is not valid' );
				return;
			}

			if ( ! is_user_member_of_blog( $wp_usr_id, $blog_id ) ) {
				$use_subsite_options     = Options_Service::mu_use_subsite_options();
				$add_member_to_main_site = Options_Service::get_global_boolean_var( 'create_and_add_users' );
				$add_member_to_subsite   = ! Options_Service::get_global_boolean_var( 'skip_add_user_to_subsite' );
				$add_member_to_all_sites = Options_Service::get_global_boolean_var( 'mu_add_user_to_all_sites' );
				$blog_name               = get_bloginfo( 'name' );

				// Shared mode
				if ( ! $use_subsite_options ) {

					// Main site / Sub site when settings prevented adding user > Send to dashboard URL
					if (
						( $is_main_site && ! $add_member_to_all_sites && ! $add_member_to_main_site )
						|| ( ! $is_main_site && ! $add_member_to_all_sites && ! $add_member_to_subsite )
					) {
						Log_Service::write_log( 'WARN', sprintf( '%s -> User %d does not have privileges for site "%s" and is therefore denied access.', __METHOD__, $wp_usr_id, $blog_name ) );
						do_action( 'wpo365/wpmu/access_denied', $wp_usr_id );
						wp_die( sprintf( __( 'You attempted to access "%s", but you do not currently have privileges on this site. If you believe you should be able to access the site, please contact your network administrator.' ), $blog_name ), 403 ); // phpcs:ignore
					}
				}

				// Settings don't allow adding member to dedicated site [wpmu dedicated mode]
				if ( $use_subsite_options && ! $add_member_to_main_site ) {
					Log_Service::write_log( 'WARN', sprintf( '%s -> User %d does not have privileges for site "%s" and is therefore denied access.', __METHOD__, $wp_usr_id, $blog_name ) );
					do_action( 'wpo365/wpmu/access_denied', $wp_usr_id );
					wp_die( sprintf( __( 'You attempted to access "%s", but you do not currently have privileges on this site. If you believe you should be able to access the site, please contact your network administrator.' ), $blog_name ), 403 ); // phpcs:ignore
				}

				// In dedicated mode restrictions may apply for domain and group membership or role assignment so the user must sign in again.
				if ( $use_subsite_options ) {
					$request_service  = Request_Service::get_instance();
					$request          = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
					$is_oidc_response = $request->get_item( 'is_oidc_response' );
					$is_saml_response = $request->get_item( 'is_saml_response' );

					// Only redirect the user to the login page when they are not currently signing in.
					if ( ! $is_oidc_response && ! $is_saml_response ) {
						Authentication_Service::goodbye( Error_Service::LOGGED_OUT );
						exit();
					}
				}

				add_user_to_blog( $blog_id, $wp_usr_id, $usr_default_role );

				// Refresh the user's role etc. to avoid "You are not allowed access to this page".
				$set_current_user_hooks = $GLOBALS['wp_filter']['set_current_user'];
				unset( $GLOBALS['wp_filter']['set_current_user'] );

				wp_set_current_user( 0 );
				wp_set_current_user( $wp_usr_id );

				$GLOBALS['wp_filter']['set_current_user'] = $set_current_user_hooks; // phpcs:ignore

				/**
				 * @since 15.0
				 */

				do_action( 'wpo365/wpmu/user_added', $blog_id, $wp_usr_id );

				Log_Service::write_log( 'DEBUG', __METHOD__ . " -> Added user with ID $wp_usr_id as a member to blog with ID $blog_id" );
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . " -> Skipped adding user with ID $wp_usr_id to blog with ID $blog_id because user already added" );
			}
		}

		/**
		 * Displays an access denied message when a user tries to view a site's dashboard they
		 * do not have access to.
		 *
		 * @since 35.1
		 *
		 * @param int $wp_usr_id
		 *
		 * @return void
		 */
		public static function wpmu_access_denied_splash( $wp_usr_id ) {

			if ( $wp_usr_id === 0 ) {
				return;
			}

			$blogs = get_blogs_of_user( $wp_usr_id );

			if ( wp_list_filter( $blogs, array( 'userblog_id' => get_current_blog_id() ) ) ) {
				return;
			}

			$blog_name = get_bloginfo( 'name' );
			$output    = sprintf(
					/* translators: 1: Site title. */
				__( 'You attempted to access "%1$s", but you do not currently have privileges on this site. If you believe you should be able to access "%1$s", please contact your network administrator.' ),
				$blog_name
			);

			if ( empty( $blogs ) ) {
				wp_die(
					wp_kses( $output, WordPress_Helpers::get_allowed_html() ),
					403
				);
			}

			$output = '<p>' . sprintf(
			/* translators: 1: Site title. */
				__( 'You attempted to access "%1$s", but you do not currently have privileges on this site. If you believe you should be able to access "%1$s", please contact your network administrator.' ),
				$blog_name
			) . '</p>';
			$output .= '<p>' . __( 'If you reached this screen by accident and meant to visit one of your own sites, here are some shortcuts to help you find your way.' ) . '</p>';

			$output .= '<h3>' . __( 'Your Sites' ) . '</h3>';
			$output .= '<table>';

			foreach ( $blogs as $blog ) {
				$output .= '<tr>';
				$output .= "<td>{$blog->blogname}</td>";
				$output .= '<td><a href="' . esc_url( get_admin_url( $blog->userblog_id ) ) . '">' . __( 'Visit Dashboard' ) . '</a> | ' .
				'<a href="' . esc_url( get_home_url( $blog->userblog_id ) ) . '">' . __( 'View Site' ) . '</a></td>';
				$output .= '</tr>';
			}

			$output .= '</table>';

			wp_die( wp_kses( $output, WordPress_Helpers::get_allowed_html() ), 403 );
		}

		/**
		 * Will set a user's primary blog when WPO365 creates a new user.
		 *
		 * @since 33.x
		 *
		 * @param mixed $wp_usr_id
		 * @return void
		 */
		public static function set_user_primary_blog( $wp_usr_id ) {

			if ( ! is_multisite() ) {
				return;
			}

			$blog_id = get_current_blog_id();

			if ( is_user_member_of_blog( $wp_usr_id, $blog_id ) ) {
				$primary_blog = get_user_meta( $wp_usr_id, 'primary_blog', true );

				if ( empty( $primary_blog ) ) {
					update_user_meta( $wp_usr_id, 'primary_blog', $blog_id );
				}
			}
		}

		/**
		 * Filters the name (and thus the domain) of a user's personal blog, setting it to the user's email username.
		 *
		 * @since 35.x
		 *
		 * @param string $wp_usr_id_str
		 *
		 * @return string
		 */
		public static function user_site_name( $wp_usr_id_str ) {

			if ( ! Options_Service::get_global_boolean_var( 'mu_new_user_site_use_username' ) ) {
				return $wp_usr_id_str;
			}

			$wp_usr_id = intval( $wp_usr_id_str );

			if ( $wp_usr_id === 0 ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Cannot update the user site name [Error: WP User ID is 0]' ) );
				return $wp_usr_id_str;
			}

			$wp_usr = get_user_by( 'ID', $wp_usr_id );

			if ( $wp_usr === false ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Cannot update the user site name [Error: WP User not found]' ) );
				return $wp_usr_id_str;
			}

			if ( filter_var( $wp_usr->user_email, FILTER_VALIDATE_EMAIL ) === false ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Cannot update the user site name [Error: Invalid email]', __METHOD__ ) );
				return $wp_usr_id_str;
			}

			$user_name = explode( '@', $wp_usr->user_email )[0];

			if ( empty( $user_name ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Cannot update the user site name [Error: Invalid email username]' ) );
				return $wp_usr_id_str;
			}

			return strtolower( $user_name );
		}
	}
}
