<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use WP_REST_Response;

if ( ! class_exists( '\Wpo\Graph\Apps_Helpers' ) ) {

	class Apps_Helpers {

		public static function create_rest_response( $result ) {

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result === null ) {
				return new WP_REST_Response( null, 204 );
			}

			return new WP_REST_Response( $result, 200 );
		}
	}
}
