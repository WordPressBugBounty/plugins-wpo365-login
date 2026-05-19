<?php

namespace Wpo\Services;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Nonce_Service' ) ) {

	class Nonce_Service {

		/**
		 * Set to true once the nonce table has been confirmed to exist in the current request.
		 *
		 * @var bool
		 */
		private static $table_ready = false;

		/**
		 * Returns the nonce table name. Note that we are using base_prefix instead of prefix and
		 * thus expect one table per installation and not per subsite in a WPMU network.
		 *
		 * @return string
		 */
		private static function table_name() {
			global $wpdb;
			return $wpdb->base_prefix . 'wpo365_nonces';
		}

		/**
		 * Creates the nonce table if it does not already exist. Runs at most once per PHP process,
		 * guarded by the static $table_ready flag.
		 *
		 * @return void
		 */
		private static function maybe_create_table() {

			if ( self::$table_ready ) {
				return;
			}

			global $wpdb;
			$table_name = self::table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				$charset_collate = $wpdb->get_charset_collate();
				$sql             = "CREATE TABLE {$table_name} (
					nonce VARCHAR(64) NOT NULL,
					created_at DATETIME NOT NULL,
					PRIMARY KEY (nonce)
				) {$charset_collate};";

				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $sql );
			}

			self::$table_ready = true;
		}

		/**
		 * Creates a nonce to ensure the request for an Entra ID token originates from the current server.
		 * Uses cryptographically secure randomness and stores the value directly in the database,
		 * bypassing any persistent object cache.
		 *
		 * @since   21.6
		 *
		 * @return string
		 */
		public static function create_nonce() {
			global $wpdb;

			self::maybe_create_table();

			$nonce      = bin2hex( random_bytes( 32 ) );
			$table_name = self::table_name();

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table_name,
				array(
					'nonce'      => $nonce,
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s' )
			);

			// Delete any nonces older than 24 hours.
			if ( wp_rand( 1, 100 ) === 1 ) {
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"DELETE FROM `{$table_name}` WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$cutoff
					)
				);
			}

			return $nonce;
		}

		/**
		 * Verifies the nonce that Microsoft returns together with the requested token.
		 * Reads directly from the database to bypass any persistent object cache.
		 *
		 * Calls Authentication_Service::goodbye() and exits on failure:
		 *   - Nonce not found        → Error_Service::NONCE_NOT_FOUND
		 *   - Nonce older than 5 min → Error_Service::NONCE_EXPIRED
		 *
		 * On success the nonce row is deleted (delete-on-use). Approximately once per
		 * 100 calls, nonces older than 24 hours are purged from the table.
		 *
		 * @param mixed $nonce
		 * @return bool True when the nonce is valid and has been consumed.
		 */
		public static function verify_nonce( $nonce ) {
			global $wpdb;

			self::maybe_create_table();

			$table_name = self::table_name();

			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT nonce, created_at FROM `{$table_name}` WHERE nonce = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$nonce
				)
			);

			$skip = Options_Service::get_global_boolean_var( 'skip_nonce_verification' );

			if ( $row === null ) {
				Log_Service::write_log( 'WARN', sprintf( '%s -> Nonce not found', __METHOD__ ) );

				if ( ! $skip ) {
					Authentication_Service::goodbye( Error_Service::NONCE_NOT_FOUND );
				}

				return;
			}

			$created_at_utc = strtotime( $row->created_at . ' UTC' );
			$age_seconds    = time() - $created_at_utc;

			if ( $age_seconds > 300 ) {
				$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array( 'nonce' => $nonce ),
					array( '%s' )
				);
				Log_Service::write_log( 'WARN', sprintf( '%s -> Nonce has expired (age: %ds)', __METHOD__, $age_seconds ) );

				if ( ! $skip ) {
					Authentication_Service::goodbye( Error_Service::NONCE_NOT_FOUND );
				}

				return;
			}

			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array( 'nonce' => $nonce ),
				array( '%s' )
			);
		}
	}
}
