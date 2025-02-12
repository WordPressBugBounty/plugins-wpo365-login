<?php

namespace Wpo\Services;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use Wpo\Services\Log_Service;

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! class_exists( '\Wpo\Services\Files_Service' ) ) {

	class Files_Service {


		private static $instance = null;

		private $wpo365_profile_images_dir = null;

		private $wpo365_profile_images_url = null;

		public $wpo365_profile_images_configured = false;

		protected function __construct() {
		}

		public static function get_instance() {

			if ( empty( self::$instance ) ) {
				self::$instance = new Files_Service();
			}

			return self::$instance;
		}

		public function configure_wpo365_profile_images() {

			if ( $this->wpo365_profile_images_configured ) {
				return true;
			}

			global $wp_filesystem;

			$credentials = request_filesystem_credentials( '' );

			if ( $credentials === false || ! WP_Filesystem( $credentials ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Missing / incorrect credentials to write to the file system' );
				$this->wpo365_profile_images_configured = false;
				return false;
			}

			$blog_id    = get_current_blog_id();
			$upload_dir = wp_upload_dir();

			$upload_base_dir = $upload_dir['basedir'];
			$upload_base_dir = str_replace( "sites/$blog_id", '', $upload_base_dir );
			$upload_base_dir = str_replace( "sites\\$blog_id", '', $upload_base_dir );
			$upload_base_dir = trailingslashit( $upload_base_dir );

			$this->wpo365_profile_images_dir = $upload_base_dir . 'wpo365/profile-images/'; // C:\path\to\wordpress\wp-content\uploads\wpo365\profile-images\

			if ( ! file_exists( $this->wpo365_profile_images_dir ) ) {

				if ( ! $wp_filesystem->mkdir( $this->wpo365_profile_images_dir, FS_CHMOD_DIR ) && ! wp_mkdir_p( $this->wpo365_profile_images_dir ) ) {
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Files service could not create requested target directory ' . $this->wpo365_profile_images_dir );
					$this->wpo365_profile_images_configured = false;
					return false;
				}
			}

			$upload_base_url = $upload_dir['baseurl'];
			$upload_base_url = str_replace( "sites/$blog_id", '', $upload_base_url );
			$upload_base_url = str_replace( "sites\\$blog_id", '', $upload_base_url );
			$upload_base_url = trailingslashit( $upload_base_url );

			$this->wpo365_profile_images_url = $upload_base_url . 'wpo365/profile-images/'; // http://example.com/wp-content/uploads/wpo365/profile-images/

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Files service has been configured successfully' );
			$this->wpo365_profile_images_configured = true;
			return true;
		}

		public function get_wpo365_profile_images_dir() {
			return $this->wpo365_profile_images_dir;
		}

		public function get_wpo365_profile_images_url() {
			return $this->wpo365_profile_images_url;
		}

		public function save_wpo365_profile_image( $path, $file_content ) {

			global $wp_filesystem;

			if ( $this->wpo365_profile_images_configured ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Trying to write a file to the file system' );
				return $wp_filesystem->put_contents( $path, $file_content, FS_CHMOD_FILE );
			}

			return false;
		}
	}
}
