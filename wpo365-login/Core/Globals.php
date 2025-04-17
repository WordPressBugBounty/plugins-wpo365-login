<?php

namespace Wpo\Core;

use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Globals' ) ) {

	class Globals {


		public static function set_global_vars(
			$plugin_file,
			$plugin_dir
		) {

			if ( function_exists( 'get_plugin_data' ) === false ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data = \get_plugin_data( $plugin_file, false, false );

			$base_name = plugin_basename( $plugin_file );

			$GLOBALS['WPO_CONFIG'] = array(
				'options'        => array(),
				'plugin'         => $base_name,
				'plugin_dir'     => $plugin_dir,
				'plugin_url'     => plugin_dir_url( $plugin_file ),
				'plugin_file'    => $plugin_file,
				'extension_file' => '',
				'extensions'     => array(),
				'slug'           => substr( $base_name, 0, WordPress_Helpers::stripos( $base_name, '/' ) ),
				'store'          => 'https://www.wpo365.com',
				'store_item'     => '',
				'store_item_id'  => '',
				'request_id'     => uniqid( '', true ),
				'url_info'       => self::get_url_info(),
				'version'        => $plugin_data['Version'],
				'ina'            => ( array_key_exists( 'ina', $_POST ) && filter_var( $_POST['ina'], FILTER_VALIDATE_BOOLEAN ) === true ), // phpcs:ignore
			);
		}

		/**
		 * Sets a number of URL related globals (all normalized and not ending with a trailing space).
		 * Whether or not to force SSL is determined by the user override (option) use_ssl. If this
		 * option hast been configured, the plugin will assume the same protocol as used for the
		 * redirect url. If the redirect utl hasn't been configured yet, the plugin will assume the
		 * same protocol as used for the home url.
		 *
		 * @since 1.0
		 *
		 * @return array
		 */
		public static function get_url_info() {
			$home   = get_option( 'home' );
			$scheme = ( isset( $_SERVER['HTTPS'] ) && ( $_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1 ) ) ||
				( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' )
				? 'https'
				: 'http';

			/**
			 * @since   12.10   Deal with reverse proxies
			 */
			if ( WordPress_Helpers::stripos( $home, 'http://' ) === 0 && $scheme === 'https' ) {
				$home = preg_replace( '/^http:/i', 'https:', $home );
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore

			if ( ! empty( $request_uri ) ) {
				$request_uri_segments = explode( '/', $request_uri );
				$request_uri_segments = array_filter( // Filtering is applied to sanitize invalid URLs e.g. https://site/subsite//wp-login.php.
					$request_uri_segments,
					function ( $segment ) {
						return ! empty( $segment );
					}
				);
				$request_uri          = sprintf( // Restore any trailing slashes.
					'/%s%s',
					implode( '/', $request_uri_segments ),
					substr( $request_uri, -1 ) === '/' && strlen( $request_uri ) > 1 ? '/' : ''
				);
			}

			$home_path   = Url_Helpers::ensure_trailing_slash_path( wp_parse_url( $home, PHP_URL_PATH ) );
			$host        = wp_parse_url( $home, PHP_URL_HOST );
			$current_url = $scheme . '://' . $host . $request_uri;

			return array(
				'request_uri'  => $request_uri,
				'wp_site_url'  => Url_Helpers::ensure_trailing_slash_url( $home ),
				'wp_site_path' => $home_path,
				'current_url'  => $current_url,
				'host'         => $host,
			);
		}
	}
}
