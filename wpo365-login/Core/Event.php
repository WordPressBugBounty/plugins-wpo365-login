<?php

namespace Wpo\Core;

// prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Event' ) ) {

	class Event {

		const STATUS_NOK = 'NOK';
		const STATUS_OK  = 'OK';

		const LEVEL_DEBUG = 'DEBUG';
		const LEVEL_INFO  = 'INFO';
		const LEVEL_WARN  = 'WARN';
		const LEVEL_ERROR = 'ERROR';

		/**
		 * Action for this event e.g. wpo365/user/created
		 *
		 * @var string
		 */
		public $action = null;

		/**
		 * Category for this event e.g. SSO
		 *
		 * @var string
		 */
		public $category = null;

		/**
		 * Any data for this event as object or array (will be serialized as JSON)
		 *
		 * @var string
		 */
		public $data = null;

		/**
		 * Error message for this event e.g. "cURL timeout"
		 *
		 * @var string
		 */
		public $error = null;

		/**
		 * Level for this event: DEBUG, INFO, WARN, DEBUG
		 *
		 * @var array
		 */
		public $level = null;

		/**
		 * Request ID for the event e.g. 65bbb23d20d8a4.56444086
		 *
		 * @var array
		 */
		public $request_id = null;

		/**
		 * Status for this event: OK or NOK
		 *
		 * @var string
		 */
		public $status = null;

		/**
		 * System info e.g. array ('php_version' => 8.2.10)
		 *
		 * @var array
		 */
		public $system_info = null;

		/**
		 * UNIX time stamp for this event e.g. 1706803645
		 *
		 * @var int
		 */
		public $timestamp = 0;

		/**
		 * User information
		 *
		 * @var Event_User
		 */
		public $user = null;

		/**
		 *
		 * @return void
		 */
		public function __construct(
			$action,
			$category,
			$user,
			$data = null,
			$error = null,
			$level = 'INFO',
			$status = 'OK'
		) {
			$this->action      = $action;
			$this->category    = $category;
			$this->user        = $user;
			$this->data        = $data;
			$this->error       = $error;
			$this->level       = $level;
			$this->status      = $status;
			$this->request_id  = $GLOBALS['WPO_CONFIG']['request_id'];
			$this->system_info = null;
			$this->timestamp   = time();
		}
	}
}
