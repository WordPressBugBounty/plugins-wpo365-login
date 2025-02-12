<?php

namespace Wpo\Services;

use Wpo\Services\Log_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Wp_Cli_Service' ) ) {

	/**
	 * Implements WPO365 WP_CLI commands that can be executed using "wp wpo365 [...]"
	 * where [...] is a public function's name (or subcommand) e.g. "wp wpo365 sync-schedule".
	 */
	class Wp_Cli_Service {

		/**
		 * WP_CLI command to schedule a new WP Cron job for a WPO365 User Synchronization Job identified by its Job ID that must be supplied as the first positional parameter.
		 *
		 * ## OPTIONS
		 *
		 * <job_id>
		 * : The ID of the job e.g. 4F4lSpZC0nzHhkOHeKC1jw5Y1NRbzdqH
		 *
		 * ---
		 * default: success
		 * options:
		 *   - success
		 *   - error
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp wpo365 sync-schedule 4F4lSpZC0nzHhkOHeKC1jw5Y1NRbzdqH
		 *
		 * @subcommand sync-schedule
		 */
		public function sync_schedule( $args ) {
			if ( ! is_array( $args ) || count( $args ) !== 1 ) {
				\WP_CLI::error( 'Mandatory positional argument 0 (Job ID) is missing' );
				return;
			}

			\WP_CLI::log( sprintf( 'Trying to schedule WPO365 User Synchronization Job with ID %s...', $args[0] ) );

			if ( method_exists( '\Wpo\Sync\SyncV2_Service', 'schedule' ) ) {

				try {
					$scheduled = \Wpo\Sync\SyncV2_Service::schedule( $args[0] );

					if ( is_wp_error( $scheduled ) ) {
						\WP_CLI::error( sprintf( 'Failed to schedule WPO365 User Synchronization Job with ID %s | Reason %s', $args[0], $scheduled->get_error_message() ) );
						return;
					}

					if ( ! $scheduled ) {
						\WP_CLI::error( sprintf( 'Failed to schedule WPO365 User Synchronization Job with ID %s | Reason unknown', $args[0] ) );
						return;
					}
				} catch ( \Exception $e ) {
					\WP_CLI::error( $e->getMessage() );
					return;
				}
			} else {
				\WP_CLI::error( 'Dependencies for this command are missing' );
				return;
			}

			\WP_CLI::success( sprintf( 'Successfully schedule WPO365 User Synchronization Job with ID %s...', $args[0] ) );
		}
	}
}
