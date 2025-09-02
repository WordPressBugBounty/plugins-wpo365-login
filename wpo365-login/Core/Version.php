<?php

namespace Wpo\Core;

// prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Version' ) ) {

	class Version {

		public static $current = '38.0';
	}
}
