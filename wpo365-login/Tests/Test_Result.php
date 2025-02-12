<?php

namespace Wpo\Tests;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Test\Test_Result' ) ) {

	class Test_Result {


		const SEVERITY_LOW      = 'low';
		const SEVERITY_CRITICAL = 'critical';
		const SEVERITY_BLOCKING = 'blocking';

		const CAPABILITY_EXTENSIONS         = 'INSTALLED EXTENSIONS';
		const HEALTH_MESSAGES               = 'HEALTH MESSAGES';
		const CAPABILITY_CONFIG             = 'CONFIGURATION';
		const CAPABILITY_OIC_SSO            = 'OPENID CONNECT BASED SSO';
		const CAPABILITY_B2C_SSO            = 'AZURE AD B2C SSO';
		const CAPABILITY_SAML_SSO           = 'SAML 2.0 BASED SSO';
		const CAPABILITY_ACCESS_TOKENS      = 'ACCESS TOKENS';
		const CAPABILITY_PROFILE_PLUS       = 'PROFILE+';
		const CAPABILITY_MAIL               = 'MAIL';
		const CAPABILITY_AVATAR             = 'AVATAR';
		const CAPABILITY_CUSTOM_USER_FIELDS = 'CUSTOM USER FIELDS';
		const CAPABILITY_REST               = 'AAD BASED REST API PROTECTION';
		const CAPABILITY_ROLES_ACCESS       = 'ROLES + ACCESS / AUDIENCES';
		const CAPABILITY_SCIM               = 'SCIM';
		const CAPABILITY_SYNC               = 'SYNC';
		const CAPABILITY_APPS               = 'M365 APPS';

		public $timestamp = null;

		public $category = null;

		public $severity = null;

		public $title = null;

		public $sequence = 0;

		public $passed = false;

		public $fix = array();

		public $message = null;

		public $more_info = null;

		public $data = null;

		public function __construct( $title, $category, $severity = self::SEVERITY_LOW ) {
			$this->timestamp = gmdate( 'j F Y H:i:s', time() );
			$this->title     = $title;
			$this->category  = $category;
			$this->severity  = $severity;
		}
	}
}
