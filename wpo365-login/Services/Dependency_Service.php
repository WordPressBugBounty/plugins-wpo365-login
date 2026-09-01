<?php

namespace Wpo\Services;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Dependency_Service' ) ) {

	class Dependency_Service {

		/**
		 *
		 * @var Dependency_Service
		 */
		private static $instance = null;

		private $dependencies = array();

		private function __construct() {
		}

		public static function get_instance() {

			if ( empty( self::$instance ) ) {
				self::$instance = new Dependency_Service();
			}

			return self::$instance;
		}

		/**
		 *
		 * @param string $name
		 * @param mixed  $dependency
		 * @return void
		 */
		public function add( $name, $dependency ) {
			$this->dependencies[ $name ] = $dependency;
		}

		/**
		 *
		 * @param string $request_id
		 * @param string $name
		 * @return mixed|false
		 */
		public function get( $request_id, $name ) {

			if ( array_key_exists( $name, $this->dependencies ) ) {
				return $this->dependencies[ $name ];
			}

			return false;
		}

		/**
		 *
		 * @param string $request_id
		 * @param string $name
		 * @return void
		 */
		public function remove( $request_id, $name ) {

			if ( array_key_exists( $name, $this->dependencies ) ) {
				unset( $this->dependencies[ $name ] );
			}
		}
	}
}
