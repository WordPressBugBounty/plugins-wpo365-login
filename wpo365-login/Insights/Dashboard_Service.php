<?php

namespace Wpo\Insights;

use Wpo\Core\Wpmu_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Insights\Event_Service;
use Wpo\Services\Options_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Insights\Dashboard_Service' ) ) {

	class Dashboard_Service {

		/**
		 * Callback that hooks into 'wp_dashboard_setup'.
		 *
		 * @since 3.7
		 *
		 * @return void
		 */
		public static function insights_widget() {

			// Bail out if the admin does not want to show the widget.
			if ( Options_Service::get_global_boolean_var( 'insights_widget_hidden' ) ) {
				return;
			}

			wp_add_dashboard_widget(
				'wpo365-insights-widget',                                       // Slug.
				'Daily WPO365 Insights',                                        // Title.
				'\Wpo\Insights\Dashboard_Service::insights_widget_display'      // Display function.
			);

			// TODO -> Make it configurable to move the widget to the top of the list.
			// Move the widget to the top.
			global $wp_meta_boxes;

			// Get the widget array.
			$widget = $wp_meta_boxes['dashboard']['normal']['core']['wpo365-insights-widget'];

			// Remove it from its current position.
			unset( $wp_meta_boxes['dashboard']['normal']['core']['wpo365-insights-widget'] );

			// Re-add it at the beginning.
			// phpcs:ignore
			$wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
				array( 'wpo365-insights-widget' => $widget ),
				$wp_meta_boxes['dashboard']['normal']['core']
			);
		}

		/**
		 * Callback to display the WPO365 Insights Widget.
		 *
		 * @return void
		 */
		public static function insights_widget_display() {
			$insights_enabled = Options_Service::get_global_boolean_var( 'insights_enabled', false );

			echo '<div style="display: grid; grid-template-columns: 1fr 4fr; grid-auto-rows: minmax(64px, auto); column-gap: 16px; align-items: center;">';
			echo '<div style="margin-bottom: 0; text-align: center;"><img src="https://www.wpo365.com/wp-content/uploads/2023/05/website-icon-512.png" style="width: 50px; height: auto;"></div>';
			echo '<div><div><span style="font-weight: 600;">See what matters, when it happens</span> Track key WPO365 events like logins, sent emails and user creation and updates - and get alerted when critical errors occur.</div>';
			echo ! $insights_enabled ? '<div style="margin-top: 8px;"><a href="' . esc_url_raw( admin_url( '/admin.php?page=wpo365-wizard#insights' ) ) . '">Click here to get started</a></div>' : '';
			echo '</div>';

			if ( ! $insights_enabled ) {
				echo '</div>';
				return;
			}

			$cached_insights_summary = self::get_cached_insights_summary();

			echo '</div>';

			$javascript = 'function wpo365WidgetInsightsShowMore() { document.querySelectorAll(\'.wpo365WidgetInsightsList\').forEach(w => w.style.display = \'inline-block\'); document.querySelector(\'.wpo365WidgetInsightsMoreToggle\').style.display = \'none\'; }';

			if ( ! current_theme_supports( 'html5', 'script' ) || ! function_exists( 'wp_print_inline_script_tag' ) ) {
				printf( "<script>%s</script>\n", $javascript ); // phpcs:ignore
			} else {
				wp_print_inline_script_tag( $javascript );
			}

			echo '<div>';

			$summaries_counter = 0;

			foreach ( $cached_insights_summary['events'] as $key => $summaries ) {
				$hidden = $key === 'users';

				if ( ! is_array( $summaries ) || count( $summaries ) === 0 ) {
					continue;
				}

				echo '<ul class="wpo365WidgetInsightsList" style="margin: 0; display: ' . ( $hidden ? 'none' : 'inline-block' ) . '; width: 100%; list-style: none; padding: 0; margin-top: ' . ( $summaries_counter === 0 ? '16' : '8' ) . 'px;">';

				foreach ( $summaries as $summary ) {
					$result = sprintf( '%s (%d)', self::get_insights_event_title( $summary->event_action, $summary->event_category, $summary->event_status ), $summary->event_count );
					$warn   = $summary->event_status === 'NOK';
					echo '<li style="width: 50%; float: left; margin-bottom: 10px;"><span class="dashicons dashicons-arrow-right-alt2" style="margin-right: 8px;"></span><a href="' . esc_url_raw( admin_url( '/admin.php?page=wpo365-wizard#insights' ) ) . '" ' . ( $warn ? ' style="color: red;" ' : '' ) . '>' . wp_kses( $result, WordPress_Helpers::get_allowed_html() ) . '</a></li>';
				}

				echo '</ul>';
				++$summaries_counter;
			}

			echo '</div>';

			if ( isset( $cached_insights_summary['events']['users'] ) ) {
				echo '<div class="wpo365WidgetInsightsMoreToggle" style="text-align: center; font-size: 11px; margin-bottom: 16px;"><a href="javascript: void(0)" onClick="(wpo365WidgetInsightsShowMore())">Show more</a></span></div>';
			}

			echo '<div style="text-align: center; font-size: 11px;"><span style="font-weight: 600;">Last updated</span>&nbsp;' . wp_kses( WordPress_Helpers::time_zone_corrected_formatted_date( $cached_insights_summary['timestamp'] ), WordPress_Helpers::get_allowed_html() ) . '&nbsp;|&nbsp;<a href="' . esc_url_raw( self::get_refresh_url() ) . '">Refresh</a>&nbsp;|&nbsp;<a href="' . esc_url_raw( admin_url( '/admin.php?page=wpo365-wizard#insights' ) ) . '">View all</a>&nbsp;|&nbsp;<a href="' . esc_url_raw( admin_url( '/admin.php?page=wpo365-wizard#insights' ) ) . '">Configure</a></div>';
		}

		/**
		 * Returns a summary of all key events measured by WPO365 from cache, or refreshes it if the cache is older
		 * than 1 hour or the user has requested a refresh.
		 *
		 * @since 38.0
		 *
		 * @return array
		 */
		private static function get_cached_insights_summary() {

			// Check cache first.
			$mu_use_site_options     = Options_Service::mu_use_subsite_options();
			$cached_insights_summary = $mu_use_site_options
			? get_option( 'wpo365_insights_summary' )
			: get_site_option( 'wpo365_insights_summary' );

			if ( ! $cached_insights_summary || $cached_insights_summary['timestamp'] < ( time() - HOUR_IN_SECONDS ) || ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( wp_unslash( sanitize_key( $_REQUEST['_wpnonce'] ) ), 'wpo365-insights-refresh' ) ) ) {
				$is_wpo_login = is_plugin_active( 'wpo365-login/wpo365-login.php' );

				$wpo365_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );

				$cached_insights_summary = array(
					'timestamp' => time(),
					'events'    => array(
						'alerts' => Event_Service::get_insights_summary( 'TODAY', 'alerts' ),
						'mail'   => Event_Service::get_insights_summary( 'TODAY', 'mail' ),
					),
				);

				if ( $is_wpo_login ) {
					$cached_insights_summary['events']['login'] = Event_Service::get_insights_summary( 'TODAY', 'login' );
					$cached_insights_summary['events']['users'] = Event_Service::get_insights_summary( 'TODAY', 'users' );
				}

				if ( $mu_use_site_options ) {
					update_option( 'wpo365_insights_summary', $cached_insights_summary );
				} else {
					update_site_option( 'wpo365_insights_summary', $cached_insights_summary );
				}

				// Redirect to the cleaned URL.
				$url = remove_query_arg( '_wpnonce' );

				if ( ! headers_sent() ) {
					wp_safe_redirect( $url );
					exit;
				} else {
					echo '<script>window.location.href="' . esc_url( $url ) . '";</script>';
					exit;
				}
			}

			return $cached_insights_summary;
		}

		/**
		 * Returns a human-readable string for the event provided as an argument.
		 *
		 * @since 38.0
		 *
		 * @param string $action
		 * @param string $category
		 * @param string $status
		 *
		 * @return string
		 */
		private static function get_insights_event_title( $action, $category, $status ) {
			switch ( $action ) {
				case 'wpo365/alert/submitted':
					return sprintf( 'Alerts sent | %s', $status );
				case 'wpo365/user/loggedin':
					return sprintf( '(%s) Users loggedin | %s', $category, $status );
				case 'wpo365/user/created':
					return sprintf( '(%s) Users created | %s', $category, $status );
				case 'wpo365/user/updated':
					return sprintf( '(%s) Users updated | %s', $category, $status );
				case 'wpo365/mail/sent':
					return sprintf( 'Emails sent | %s', $status );
				default:
					return '';
			}
		}

		/**
		 * Adds a _wpnonce parameter to the current URL that - if valid - will trigger the refresh of cached insights summary.
		 *
		 * @since 38.0
		 *
		 * @return string
		 */
		private static function get_refresh_url() {
			$current_url = $GLOBALS['WPO_CONFIG']['url_info']['current_url'];
			$parsed_url  = wp_parse_url( $current_url );

			parse_str( isset( $parsed_url['query'] ) ? $parsed_url['query'] : '', $query_params );

			$query_params['_wpnonce'] = wp_create_nonce( 'wpo365-insights-refresh' );
			$new_query                = http_build_query( $query_params );

			return $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'] . '?' . $new_query;
		}
	}
}
