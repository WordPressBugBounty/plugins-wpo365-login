<?php

namespace Wpo\Core;

// prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Event_User' ) ) {

	class Event_User {


		/**
		 * WP User ID e.g. 2
		 *
		 * @var int
		 */
		public $wp_user_id = null;

		/**
		 * WP User username e.g. admin
		 *
		 * @var string
		 */
		public $wp_user_login = null;

		/**
		 * Microsoft Entra ID user Object ID e.g. 00000000-0000-0000-0000-000000000000
		 *
		 * @var string
		 */
		public $oid = null;

		/**
		 * User displayname e.g. Max Demo or Demo, Max
		 *
		 * @var string
		 */
		public $display_name = null;

		/**
		 * Email address of user e.g. max@domain
		 *
		 * @var string
		 */
		public $mail = null;
	}
}
