<?php

namespace Wpo\Core;

// prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\User' ) ) {

	class User {


		/**
		 * Email address of user
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $email = '';

		/**
		 * Unique user's principal name
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $upn = '';

		/**
		 * User's preferred name
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $preferred_username = '';

		/**
		 * Custom claim used as Username
		 *
		 * @since 24.0
		 *
		 * @var string
		 */
		public $custom_username = '';

		/**
		 * Name of user
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $name = '';

		/**
		 * User's first name
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $first_name = '';

		/**
		 * User's last name incl. middle name etc.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $last_name = '';

		/**
		 * User's full ( or display ) name
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $full_name = '';

		/**
		 * Office 365 and/or Azure AD group ids
		 *
		 * @var array
		 */
		public $groups = array();

		/**
		 * User's tenant ID
		 *
		 * @var string
		 */
		public $tid = '';

		/**
		 * User's Azure AD object ID
		 *
		 * @var string
		 */
		public $oid = '';

		/**
		 * True is the user was created during the current script execution
		 *
		 * @var bool
		 */
		public $created = false;

		/**
		 * True is the user was created from an ID Token / SAML response
		 *
		 * @var bool
		 */
		public $from_idp_token = false;

		/**
		 * The Graph Resource for this user
		 *
		 * @var array
		 */
		public $graph_resource = null;

		/**
		 * The SAML attributes for this user
		 *
		 * @var array
		 */
		public $saml_attributes = array();

		/**
		 * The SCIM attributes for this user
		 *
		 * @var array
		 */
		public $scim_attributes = array();

		/**
		 * The ID token for this user
		 *
		 * @var object
		 */
		public $id_token = null;

		/**
		 * The App Roles for this users
		 *
		 * @var array
		 */
		public $app_roles = array();

		/**
		 * Flag to indicate whether the user signed in with a MSA account e.g. outlook.com
		 *
		 * @var bool
		 */
		public $is_msa = false;
	}
}
