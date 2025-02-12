<?php

namespace Wpo\Core;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Cron_Helpers' ) ) {

	class Cron_Helpers {


		/**
		 * Adds custom named cron schedules
		 *
		 * @since 10.0
		 *
		 * @param array $schedules Array of already defined.
		 */
		public static function add_cron_schedules( $schedules ) {
			add_filter( 'doing_it_wrong_trigger_error', '\Wpo\Core\Cron_Helpers::suppress_doing_it_wrong_trigger_error' );

			$schedules['wpo_every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute', 'wpo365-login' ),
			);

			$schedules['wpo_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 minutes', 'wpo365-login' ),
			);

			$schedules['wpo_daily'] = array(
				'interval' => 86400,
				'display'  => __( 'WPO365 Daily', 'wpo365-login' ),
			);

			$schedules['wpo_weekly'] = array(
				'interval' => 604800,
				'display'  => __( 'WPO365 Weekly', 'wpo365-login' ),
			);

			remove_filter( 'doing_it_wrong_trigger_error', '\Wpo\Core\Cron_Helpers::suppress_doing_it_wrong_trigger_error' );

			return $schedules;
		}

		/**
		 * Suppresses the warning doing_it_wrong_trigger_error which is triggered when hooking into cron_schedules early.
		 *
		 * @since 34.3
		 *
		 * @return false
		 */
		public static function suppress_doing_it_wrong_trigger_error() {
			return false;
		}
	}
}
