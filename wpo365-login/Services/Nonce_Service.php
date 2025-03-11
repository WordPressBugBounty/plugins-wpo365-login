<?php

namespace Wpo\Services;

use Wpo\Core\Wpmu_Helpers;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Nonce_Service' ) ) {

	class Nonce_Service {

		/**
		 * Creates a nonce to ensure the request for an Azure AD token
		 * originates from the current server.
		 *
		 * @since   21.6
		 *
		 * @return string
		 */
		public static function create_nonce() {

			if ( Wpmu_Helpers::mu_get_transient( 'wpo365_nonces' ) !== false ) {
				Wpmu_Helpers::mu_delete_transient( 'wpo365_nonces' );
			}

			$is_mu_shared = ! Options_Service::mu_use_subsite_options();
			$nonce_stack  = $is_mu_shared ? get_site_option( 'wpo365_nonces' ) : get_option( 'wpo365_nonces' );

			if ( empty( $nonce_stack ) ) {
				$nonce_stack = array();
			}

			$nonce         = uniqid();
			$nonce_stack[] = $nonce;

			// When the stack grows to 200 it's downsized to 150
			if ( count( $nonce_stack ) > 200 ) {
				array_splice( $nonce_stack, 0, 100 );
			}

			if ( $is_mu_shared ) {
				update_site_option( 'wpo365_nonces', $nonce_stack );
			} else {
				update_option( 'wpo365_nonces', $nonce_stack );
			}

			return $nonce;
		}

		/**
		 * Verifies the nonce that Microsoft returns together with the requested token.
		 *
		 * @param mixed $nonce
		 * @return bool
		 */
		public static function verify_nonce( $nonce ) {
			$is_mu_shared = ! Options_Service::mu_use_subsite_options();
			$nonce_stack  = $is_mu_shared ? get_site_option( 'wpo365_nonces' ) : get_option( 'wpo365_nonces' );

			if ( empty( $nonce_stack ) ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Empty nonce stack', __METHOD__ ) );
				return false;
			}

			$index = array_search( $nonce, $nonce_stack, true );

			if ( $index === false ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Nonce %s not found', __METHOD__, $nonce ) );
				return false;
			}

			array_splice( $nonce_stack, $index, 1 );

			if ( $is_mu_shared ) {
				update_site_option( 'wpo365_nonces', $nonce_stack );
			} else {
				update_option( 'wpo365_nonces', $nonce_stack );
			}

			return true;
		}
	}
}
