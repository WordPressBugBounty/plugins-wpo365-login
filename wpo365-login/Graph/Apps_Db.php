<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace Wpo\Graph;

use WP_Error;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Options_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Graph\Apps_Db' ) ) {

	class Apps_Db {

		/**
		 *
		 * @param object $app_instance
		 *
		 * @return string|WP_Error
		 */
		public static function add_app_instance( $app_instance ) {
			global $wpdb;

			if ( ! self::table_exists( 'wpo365_app_instances' ) ) {
				$create_result = self::create_table( 'wpo365_app_instances' );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}
			}

			$wp_usr         = \wp_get_current_user();
			$formatted_time = self::time_zone_corrected_formatted_date_string();

			$app_instance->authorDisplayName = $wp_usr->display_name;
			$app_instance->authorId          = $wp_usr->ID;
			$app_instance->created           = $formatted_time;
			$app_instance->modified          = $formatted_time;

			$table_name = self::get_table_name( 'wpo365_app_instances' );

			$rows_inserted = $wpdb->insert( // phpcs:ignore
				$table_name,
				array(
					'app_instance' => wp_json_encode( $app_instance ),
				)
			);

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when inserting a new app instance [%s]', $wpdb->last_error ) );
			}

			$last_inserted_id = $wpdb->insert_id;
			$app_instance->id = $last_inserted_id;
			return self::update_app_instance( $last_inserted_id, $app_instance );
		}

		/**
		 * Gets all app instances for the app type provided.
		 *
		 * @return array|null|WP_Error
		 */
		public static function get_app_instances() {
			global $wpdb;

			if ( ! self::table_exists( 'wpo365_app_instances' ) ) {
				$create_result = self::create_table( 'wpo365_app_instances' );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}
			}

			$table_name = self::get_table_name( 'wpo365_app_instances' );
			$query      = $wpdb->prepare( 'SELECT * FROM %i', $table_name );
			$result     = $wpdb->get_results( $query, ARRAY_A );// phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when retrieving app instances [%s]', $wpdb->last_error ) );
			}

			if ( $result === null ) {
				return array();
			}

			return array_map(
				function ( $row ) {
					return json_decode( $row['app_instance'], false );
				},
				$result
			);
		}

		/**
		 * Gets the app instance for the id provided.
		 *
		 * @param int $id
		 *
		 * @return string|null|WP_Error
		 */
		public static function get_app_instance( $id ) {
			global $wpdb;

			if ( ! self::table_exists( 'wpo365_app_instances' ) ) {
				$create_result = self::create_table( 'wpo365_app_instances' );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}
			}

			$table_name = self::get_table_name( 'wpo365_app_instances' );

			$result = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `id` = %d',
					$table_name,
					$id
				),
				ARRAY_A
			);

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when retrieving app instance with ID %d [%s]', $id, $wpdb->last_error ) );
			}

			if ( $result === null || count( $result ) !== 1 ) {
				return null;
			}

			return json_decode( $result[0]['app_instance'], false );
		}

		/**
		 * Updates the applied requirements of an app instance.
		 *
		 * @param int    $id
		 * @param object $app_instance
		 *
		 * @return WP_Error|void
		 */
		public static function update_app_instance( $id, $app_instance ) {
			global $wpdb;

			if ( ! self::table_exists( 'wpo365_app_instances' ) ) {
				$create_result = self::create_table( 'wpo365_app_instances' );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}
			}

			$formatted_time = self::time_zone_corrected_formatted_date_string();

			$app_instance->modified = $formatted_time;

			$table_name = self::get_table_name( 'wpo365_app_instances' );

			$wpdb->update( $table_name, array( 'app_instance' => json_encode( $app_instance, ) ),  array( 'id' => $id ) ); // phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when updating app instance with ID %d [%s]', $id, $wpdb->last_error ) );
			}

			return self::get_app_instance( $id );
		}

		/**
		 * Deletes an app instance from the database.
		 *
		 * @param mixed $id
		 * @return WP_Error|void
		 */
		public static function delete_app_instance( $id ) {
			global $wpdb;

			if ( ! self::table_exists( 'wpo365_app_instances' ) ) {
				$create_result = self::create_table( 'wpo365_app_instances' );

				if ( is_wp_error( $create_result ) ) {
					return $create_result;
				}
			}

			$table_name = self::get_table_name( 'wpo365_app_instances' );

			$result = $wpdb->delete( $table_name, array( 'id' => $id ) ); // phpcs:ignore

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when deleting app instance with ID %d [%s]', $id, $wpdb->last_error ) );
			}

			if ( $result !== 1 ) {
				return new WP_Error( 'DbException', sprintf( 'Error occurred when deleting app instance with ID %d [rows affected: %d]', $id, $result ) );
			}
		}

		/**
		 * Helper method to centrally provide the custom WordPress table name.
		 *
		 * @since 3.0
		 *
		 * @return string
		 */
		private static function get_table_name( $table_name ) {
			global $wpdb;

			if ( Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin() ) {
				return $wpdb->prefix . $table_name;
			}

			return $wpdb->base_prefix . $table_name;
		}

		/**
		 * Helper method to create / update the custom Mail DB table used for logging.
		 *
		 * @since   17.0
		 *
		 * @return  boolean|WP_Error
		 */
		private static function create_table( $table_name ) {
			global $wpdb;

			$_table_name = self::get_table_name( $table_name );

			$charset_collate = $wpdb->get_charset_collate();

			if ( $table_name === 'wpo365_app_instances' ) {
				$sql = "CREATE TABLE IF NOT EXISTS $_table_name (
					id BIGINT AUTO_INCREMENT PRIMARY KEY,
          app_instance JSON NOT NULL
          ) $charset_collate;";
			} else {
				return new WP_Error( 'DbException', sprintf( 'Missing SQL statement to create table with name "%s"', $table_name ) );
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'DbException', $wpdb->last_error );
			}

			return true;
		}

		/**
		 * Helper method to check whether the custom WordPress table exists.
		 *
		 * @since   3.0
		 *
		 * @return boolean
		 */
		private static function table_exists( $table_name ) {
			global $wpdb;

			$_table_name = self::get_table_name( $table_name );

			if ( $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$_table_name
				)
			) === $_table_name ) {
				return true;
			}

			return false;
		}

		/**
		 * ...
		 *
		 * @since ...
		 *
		 * @param mixed $time
		 * @return string
		 */
		private static function time_zone_corrected_formatted_date_string( $time = null ) {
			if ( \method_exists( '\Wpo\Core\WordPress_Helpers', 'time_zone_corrected_formatted_date' ) ) {
				return WordPress_Helpers::time_zone_corrected_formatted_date( $time );
			}

			if ( empty( $time ) ) {
				$time = time();
			}

			return gmdate( 'Y-m-d H:i:s', $time );
		}
	}
}
