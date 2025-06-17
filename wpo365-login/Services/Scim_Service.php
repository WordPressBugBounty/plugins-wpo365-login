<?php

namespace Wpo\Services;

use Wpo\Core\Permissions_Helpers;
use Wpo\Core\User;
use Wpo\Core\Version;
use Wpo\Core\WordPress_Helpers;
use Wpo\Services\Log_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Scim_Service' ) ) {

	class Scim_Service {

		const WPO_REST_NAMESPACE = 'wpo365/v1';
		const WPO_REST_BASE      = 'Users';

		// region Controller.

		/**
		 * Registers the necessary WP REST routes for the integration with Entra Application Provisioning (SCIM).
		 *
		 * @return void
		 */
		public static function register_routes() {

			if ( class_exists( '\Wpo\SCIM\SCIM_Controller' ) ) {
				$scim_controller = new \Wpo\SCIM\SCIM_Controller();
				$scim_controller->register_routes();
				return;
			}

			// Don't register the routes if the user didn't configure SCIM
			if ( ! Options_Service::get_global_boolean_var( 'enable_scim' ) ) {
				return;
			}

			add_filter( 'rest_post_dispatch', '\Wpo\Services\Scim_Service::set_response_headers', 10, 3 );

			register_rest_route(
				self::WPO_REST_NAMESPACE,
				'/' . self::WPO_REST_BASE,
				array(
					array( // QUERY USERS
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => '\Wpo\Services\Scim_Service::get_items',
						'permission_callback' => '\Wpo\Services\Scim_Service::check_permissions',
						'args'                => array(
							'filter' => array(
								'type'        => 'string',
								'description' => esc_html__( 'e.g /Users?filter=userName eq "Test_User_dfeef4c5-5681-4387-b016-bdf221e82081"', 'wpo365-login' ),
							),
						),
					),
					array( // CREATE USER
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => '\Wpo\Services\Scim_Service::create_item',
						'permission_callback' => '\Wpo\Services\Scim_Service::check_permissions',
						'args'                => self::get_post_args(),
					),
				)
			);

			register_rest_route(
				self::WPO_REST_NAMESPACE,
				'/' . self::WPO_REST_BASE . '/(?P<id>[^/]+)',
				array(
					array( // RETRIEVE USER
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => '\Wpo\Services\Scim_Service::get_items',
						'permission_callback' => '\Wpo\Services\Scim_Service::check_permissions',
						'args'                => array(
							'id' => array(
								'type' => 'string',
							),
						),
					),
				)
			);
		}

		// endregion

		// region REST helpers to do the necessary WP plumbing.

		/**
		 * Get a collection of items.
		 *
		 * @since   37.0
		 *
		 * @param   WP_REST_Request $request Full data about the request.
		 *
		 * @return  WP_REST_Response
		 */
		public static function get_items( $request ) {
			$scim_users = array();

			if ( isset( $request['filter'] ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Querying for users where ' . $request['filter'] );
				self::query_users( $request['filter'], $scim_users );
				$list_response = self::as_list_response( $scim_users );

				if ( is_wp_error( $list_response ) ) {
					Log_Service::write_log( 'WARN', __METHOD__ . ' -> ' . $list_response->get_error_message() );
					return new \WP_REST_Response( self::error( 400, $list_response->get_error_message() ), 400 );
				}

				return new \WP_REST_Response( $list_response, 200 );
			} elseif ( isset( $request['id'] ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Retrieving a user by id ' . $request['id'] );
				self::get_user_by_id( $request['id'], $scim_users );

				if ( count( $scim_users ) === 1 ) {
					return new \WP_REST_Response( $scim_users[0], 200 );
				}
			}

			return new \WP_REST_Response( self::error( 404 ), 404 );
		}

		/**
		 * Creates a new user from the SCIM resource present in the body of the $request.
		 *
		 * @since   37.0
		 *
		 * @param   WP_REST_Request $request
		 *
		 * @return  WP_REST_Response
		 */
		public static function create_item( $request ) {
			version_compare( Version::$current, '36.2' ) > 0 && Log_Service::write_to_custom_log( $request, 'scim' );

			if ( ! self::is_json( $request ) ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Request content-type is not set to json' );
				return new \WP_REST_Response( self::error( 400, 'Request content-type is not set to json' ) );
			}

			try {
				$body          = $request->get_body();
				$scim_resource = \json_decode( $body, true );
				$scim_usr      = self::create_user( $scim_resource );
			} catch ( \Exception $e ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> ' . $e->getMessage() );
				return new \WP_REST_Response( self::error( 400, $e->getMessage() ), 400 );
			}

			if ( is_wp_error( $scim_usr ) ) {
				$status = $scim_usr->get_error_code() === 'USREXISTS' ? 409 : 400;
				return new \WP_REST_Response( self::error( $status, $scim_usr->get_error_message() ), $status );
			}

			return new \WP_REST_Response( $scim_usr, 201 );
		}

		// endregion

		// region Query and Create users.

		/**
		 * @since   37.0
		 *
		 * @param   string $query Filter expression e.g. userName eq "john@doe.com" and userName eq "max@doe.com".
		 * @param   array  &$scim_users Array of SCIM users that the result will be added to.
		 *
		 * @return  void
		 */
		public static function query_users( $query, &$scim_users ) {

			if ( ! \is_array( $scim_users ) ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Argument exception ($scim_users is not an array)' );
				return;
			}

			$expressions = explode( '|', \str_replace( ' and ', '|', $query ) ); // $query => userName eq "a" and userName eq "b"

			foreach ( $expressions as $expression ) { // $expression => userName eq "a"
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found the following filter expression ' . $expression );
				$words = explode( ' ', $expression ); // phpcs:ignore $words => [ 'userName', 'eq', '"a"' ]

				if ( count( $words ) === 3 ) {
					$words[2] = WordPress_Helpers::trim( $words[2], '"' );

					if ( WordPress_Helpers::stripos( $words[0], 'userName' ) !== false ) {
						self::get_user_by_user_name( $words[2], $scim_users );
					} elseif ( WordPress_Helpers::stripos( $words[0], 'externalId' ) !== false ) {
						self::get_user_by_external_id( $words[2], $scim_users );
					}
				}
			}
		}

		/**
		 * @since   37.0
		 *
		 * @param   string $user_name User (login) name.
		 * @param   array  &$scim_users Array of SCIM users that the result will be added to.
		 *
		 * @return  void
		 */
		public static function get_user_by_user_name( $user_name, &$scim_users ) {

			$wp_usr = \get_user_by( 'login', $user_name );

			if ( $wp_usr !== false ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found WP user ' . $user_name );
				$scim_usr = self::as_scim_user( $wp_usr );

				if ( ! is_wp_error( $scim_usr ) ) {
					$scim_users[] = $scim_usr;
				}
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find a user for ' . $user_name );
			}
		}

		/**
		 * @since   37.0
		 *
		 * @param   string $external_id External ID of the user.
		 * @param   array  &$scim_users Array of SCIM users that the result will be added to.
		 *
		 * @return  void
		 */
		public static function get_user_by_external_id( $external_id, &$scim_users ) {
			$args = array(
				'meta_key'   => 'wpo365_scim_external_id', // phpcs:ignore
				'meta_value' => $external_id, // phpcs:ignore
			);

			$wp_usrs = \get_users( $args );

			if ( count( $wp_usrs ) === 1 ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found WP user ' . $wp_usrs[0]->user_login . ' for external id ' . $external_id );
				$scim_usr = self::as_scim_user( $wp_usrs[0] );

				if ( ! is_wp_error( $scim_usr ) ) {
					$scim_users[] = $scim_usr;
				}
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find a user for external id ' . $external_id );
			}
		}

		/**
		 * @since   37.0
		 *
		 * @param   string $email Email address of the user.
		 * @param   array  &$scim_users Array of SCIM users that the result will be added to.
		 *
		 * @return  void
		 */
		public static function get_user_by_email( $email, &$scim_users ) {
			$wp_usr = \get_user_by( 'email', $email );

			if ( $wp_usr !== false ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found WP user ' . $email );

				$scim_usr = self::as_scim_user( $wp_usr );

				if ( ! is_wp_error( $scim_usr ) ) {
					$scim_users[] = $scim_usr;
				}
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find a user for ' . $email );
			}
		}

		/**
		 * @since   37.0
		 *
		 * @param   string $id (WordPress) ID of the user.
		 * @param   array  &$scim_users Array of SCIM users that the result will be added to.
		 *
		 * @return  void
		 */
		public static function get_user_by_id( $id, &$scim_users ) {
			$wp_usr = \get_user_by( 'ID', $id );

			if ( $wp_usr !== false ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found WP user ' . $wp_usr->user_login . ' for id ' . $id );
				$scim_usr = self::as_scim_user( $wp_usr );

				if ( ! is_wp_error( $scim_usr ) ) {
					$scim_users[] = $scim_usr;
				}
			} else {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find a user for id ' . $id );
			}
		}

		/**
		 * @since   37.0
		 *
		 * @param   array $scim_usr SCIM resource as associative array.
		 *
		 * @return  array|WP_Error  The WP user as SCIM resource or a WP_Error if an error occurred
		 */
		public static function create_user( $scim_usr ) {
			Log_Service::write_log( 'DEBUG', sprintf( '##### -> %s', __METHOD__ ) );

			if ( ! \is_array( $scim_usr ) ) {
				return new \WP_Error( 'ARRCHECKFAILED', 'SCIM User resource is not an associative array' );
			}

			if ( ! isset( $scim_usr['externalId'] ) ) {
				return new \WP_Error( 'CREATEUSRFAILED', 'Mandatory property externalId not found.' );
			}

			$args = array(
				'meta_key'   => 'wpo365_scim_external_id', // phpcs:ignore
				'meta_value' => $scim_usr['externalId'], // phpcs:ignore
			);

			$wp_usrs = \get_users( $args );

			if ( count( $wp_usrs ) > 0 ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Cannot create user with external ID ' . $scim_usr['externalId'] . ' because this ID is already in use' );
				return new \WP_Error( 'USREXISTS', 'Cannot create user with external ID ' . $scim_usr['externalId'] . ' because this ID is already in use' );
			}

			$wpo_usr          = new User();
			$wpo_usr->created = true;

			$user_mappings = self::get_user_mappings();

			// Process the mandatory properties
			foreach ( $user_mappings[0] as $scim_attribute => $wpo_usr_key ) {
				$value = self::try_get_property( $scim_usr, $scim_attribute );

				if ( ! empty( $value ) && property_exists( $wpo_usr, $wpo_usr_key ) ) {
					$wpo_usr->$wpo_usr_key = $value;
				}
			}

			if ( empty( $wpo_usr->upn ) ) {
				return new \WP_Error( 'CREATEUSRFAILED', 'Mandatory property userName not found.' );
			}

			// upn will be used as login_name
			$wpo_usr->preferred_username = $wpo_usr->upn;

			// unless user is an external user in which case email will be used
			if ( WordPress_Helpers::stripos( $wpo_usr->upn, '#ext#' ) !== false ) {

				if ( empty( $wpo_usr->email ) ) {
					return new \WP_Error( 'CREATEUSRFAILED', 'Cannot create WP user for guest user without email address.' );
				}

				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Found guest user ' . $wpo_usr->upn . ' and will email address ' . $wpo_usr->email . ' as user login instead' );
				$wpo_usr->preferred_username = $wpo_usr->email;
			}

			// Object ID
			$oid_property = 'urn:ietf:params:scim:schemas:extension:wpo365:2.0:User:objectId';
			$oid          = self::try_get_property( $scim_usr, $oid_property );

			if ( ! empty( $oid ) ) {
				$wpo_usr->oid = $oid;
			}

			// process the optional properties
			foreach ( $user_mappings[1] as $scim_attribute => $wpo_usr_key ) {
				$value = self::try_get_property( $scim_usr, $scim_attribute );

				if ( ! empty( $value ) && property_exists( $wpo_usr, $wpo_usr_key ) ) {
					$wpo_usr->$wpo_usr_key = $value;
				}
			}

			/**
			 * @since   37.0    Filters the internal WPO365 representation of a user (see Wpo\Core\User)
			 */
			$wpo_usr = apply_filters( 'wpo365/user', $wpo_usr, 'scim' );

			// Check if the user already is in the system.
			$wp_usr = User_Service::try_get_user_by( $wpo_usr );

			// Create a new user.
			if ( empty( $wp_usr ) ) {
				$wp_usr_id = User_Create_Service::create_user( $wpo_usr, true, false );
				$wp_usr    = \get_user_by( 'ID', $wp_usr_id );
			} else {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> User with preferred username ' . $wpo_usr->preferred_username . ' already exists. Please activate WPO365 | SCIM or WPO365 | INTEGRATE to enable functionality to update users via SCIM.' );
			}

			if ( ! empty( $wp_usr ) ) {

				$user_meta_mappings = self::get_user_meta_mappings();

				// Process the mandatory user meta mappings
				foreach ( $user_meta_mappings[0] as $scim_attribute => $wp_meta_key ) {
					self::process_user_meta_mapping( $scim_usr, $scim_attribute, $wp_usr_id, $wp_meta_key );
				}

				return self::as_scim_user( $wp_usr );
			}

			return new \WP_Error( 'CREATEUSRFAILED', 'Could not create user from SCIM resource. Check the log for details.' );
		}

		// endregion

		// region Helpers e.g. check permissions.

		/**
		 * @since   37.0
		 *
		 * @param   WP_User $wp_usr     The WordPress user that will be transformed into a SCIM User resource.
		 *
		 * @return  array|null  Associative array representing a SCIM User resource
		 */
		public static function as_scim_user( $wp_usr ) {

			if ( $wp_usr instanceof \WP_User === false ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Argument is not a WP user' );
				return new \WP_Error( 'ARGCHECKFAILED', 'Argument is not a WP user' );
			}

			$usr_meta = get_user_meta( $wp_usr->ID );

			if ( ! isset( $usr_meta['wpo365_scim_external_id'] ) ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Cannot create a SCIM User resource for a WP user without an external ID' );
				return new \WP_Error( 'EXIDCHECKFAILED', 'External ID for user not found' );
			}

			$wpo365_active_value = isset( $usr_meta['wpo365_active'] ) && is_array( $usr_meta['wpo365_active'] ) && count( $usr_meta['wpo365_active'] ) === 1
				? $usr_meta['wpo365_active'][0]
				: '';

			$scim_usr = array(
				'schemas'  => array( 'urn:ietf:params:scim:schemas:core:2.0:User' ),
				'id'       => $wp_usr->ID,
				'meta'     => array(
					'resourceType' => 'User',
				),
				'userName' => $wp_usr->user_login,
				'name'     => array(
					'familyName' => $wp_usr->last_name,
					'givenName'  => $wp_usr->first_name,
					'formatted'  => $wp_usr->display_name,
				),
				'emails'   => array(
					array(
						'value'   => $wp_usr->user_email,
						'type'    => 'work',
						'primary' => true,
					),
				),
				'active'   => ( $wpo365_active_value !== 'deactivated' ),
			);

			$all_user_meta_mappings = self::get_user_meta_mappings();

			// Processing the mandatory and optional user meta mappings
			foreach ( $all_user_meta_mappings as $user_meta_mappings ) {

				foreach ( $user_meta_mappings as $scim_attribute => $wp_meta_key ) {

					if ( ! isset( $usr_meta[ $wp_meta_key ] ) ) {
						Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> User meta key for ' . $wp_meta_key . ' for user ' . $wp_usr->user_login . ' not found' );
						continue;
					}

					$usr_meta_value = is_array( $usr_meta[ $wp_meta_key ] ) && count( $usr_meta[ $wp_meta_key ] ) === 1
						? $usr_meta[ $wp_meta_key ][0]
						: false;

					if ( ! empty( $usr_meta_value ) ) {
						self::try_add_property( $scim_usr, $scim_attribute, $usr_meta_value );
					} else {
						Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> User meta value for ' . $wp_meta_key . ' for user ' . $wp_usr->user_login . ' is empty' );
					}
				}
			}

			Log_Service::write_log( 'DEBUG', sprintf( '%s -> Returning the following SCIM formatted users: %s', __METHOD__, wp_json_encode( $scim_usr ) ) );
			return $scim_usr;
		}

		/**
		 * SCIM wrapper for a collection of resources to be send as a response to a query.
		 *
		 * @since 37.0
		 *
		 * @param  array $scim_users Array with SCIM user resources.
		 *
		 * @return  array|WP_Error  Associative array representing a SCIM ListResponse message
		 */
		public static function as_list_response( $scim_users ) {

			if ( ! \is_array( $scim_users ) ) {
				return new \WP_Error( 'ARRCHECKFAILED', 'Cannot create a list response because the argument is not an array' );
			}

			$scim_usr = array(
				'schemas'      => array( 'urn:ietf:params:scim:api:messages:2.0:ListResponse' ),
				'totalResults' => count( $scim_users ),
				'Resources'    => $scim_users,
				'startIndex'   => 1,
				'itemsPerPage' => 20,
			);

			return $scim_usr;
		}

		/**
		 * Helper to validate a SCIM user resource that is posted to the API.
		 *
		 * @since   37.0
		 *
		 * @return  array   Associative array representing the minimal viable schema of a SCIM user resource
		 */
		public static function get_post_args() {
			return array(
				'schemas'    => array(
					'type'  => 'array',
					'items' => array(
						'type'              => 'string',
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return is_string( $param );
						},
					),
				),
				'externalId' => array(
					'type'              => 'string',
					'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
						return is_string( $param );
					},
				),
				'userName'   => array(
					'type'              => 'string',
					'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
						return is_string( $param );
					},
				),
				'emails'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'primary' => array(
								'type'              => 'boolean',
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_bool( $param );
								},
							),
							'type'    => array(
								'type'              => 'string',
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_string( $param );
								},
							),
							'value'   => array(
								'type'              => 'string',
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_string( $param );
								},
							),
						),

					),
				),
				'meta'       => array(
					'type'       => 'object',
					'properties' => array(
						'resourceType' => array(
							'type'              => 'string',
							'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
								return is_string( $param );
							},
						),
					),
				),
				'name'       => array(
					'type'       => 'object',
					'properties' => array(
						'familyName' => array(
							'type'              => 'string',
							'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
								return is_string( $param );
							},
						),
						'givenName'  => array(
							'type'              => 'string',
							'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
								return is_string( $param );
							},
						),
					),
				),
			);
		}

		/**
		 * Helper to validate a SCIM user resource that is patched.
		 *
		 * @since   37.0
		 *
		 * @return  array   Associative array representing the minimal viable schema for to PATCH a SCIM user resource
		 */
		public static function get_patch_args() {
			return array(
				'id'         => array(
					'type' => 'string',
				),
				'schemas'    => array(
					'type'  => 'array',
					'items' => array(
						'type'              => 'string',
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return is_string( $param );
						},
					),
				),
				'Operations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'op'   => array(
								'type'              => 'string',
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_string( $param );
								},
							),
							'path' => array(
								'type'              => 'string',
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_string( $param );
								},
							),
						),
					),
				),
			);
		}

		private static function get_user_mappings() {
			$mandatory_user_mappings = array(
				'emails[type eq "work"].value' => 'email',
				'userName'                     => 'upn',
			);

			$optional_user_mappings = array(
				'name.givenName'  => 'first_name',
				'name.familyName' => 'last_name',
				'name.formatted'  => 'full_name',
			);

			return array( $mandatory_user_mappings, $optional_user_mappings );
		}

		private static function get_user_meta_mappings() {
			$mandatory_user_meta_mappings = array(
				'externalId' => 'wpo365_scim_external_id',
				'urn:ietf:params:scim:schemas:extension:wpo365:2.0:User:objectId' => 'aadObjectId',
			);

			return array( $mandatory_user_meta_mappings, array() );
		}

		/**
		 * Processes an additional SCIM attribute given the SCIM resource, the SCIM attribute name and the WordPress User ID.
		 *
		 * @since   37.0
		 *
		 * @param   array  $scim_usr Associative array representing the SCIM User resource.
		 * @param   string $scim_attribute Name of the attribute (may be formatted with a dot to depict e.g. phoneNumbers[type = mobile]).
		 * @param   int    $wp_usr_id WP User ID.
		 * @param   string $wp_meta_key WP user meta key.
		 * @param   bool   $delete Whether to delete the WP meta.
		 *
		 * @return  boolean True if the user meta was updated successfully otherwise false
		 */
		private static function process_user_meta_mapping( $scim_usr, $scim_attribute, $wp_usr_id, $wp_meta_key, $delete = false ) { // phpcs:ignore
			$value = self::try_get_property( $scim_usr, $scim_attribute );

			// SCIM attribute was expected but not sent which indicates that the existing meta data should be emptied
			if ( $value !== false ) {

				\update_user_meta( $wp_usr_id, $wp_meta_key, $value );
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Updated ' . $scim_attribute . ' for user with ID ' . strval( $wp_usr_id ) );

				return true;
			}

			// Set user meta as empty
			\update_user_meta( $wp_usr_id, $wp_meta_key, '' );

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not process ' . $scim_attribute . ' for user with ID ' . $wp_usr_id );
			return false;
		}

		/**
		 * Simple helper to get a property from an associative array or otherwise return something "empty".
		 *
		 * @since   37.0
		 *
		 * @param   array  $user_resource Associative array that may or may not contain the property.
		 * @param   string $property Name of the property.
		 */
		private static function try_get_property( $user_resource, $property ) {

			/**
			 * If $property is an attribute path e.g. phoneNumbers[type eq "mobile"].value
			 * then the preg_match will populate $matches as follows:
			 *
			 * Array(
			 *      [0] => Array ( [0] => [type eq "mobile"], [1] => 12 ),
			 *      [1] => Array ( [0] => type eq "mobile" [1] => 13 ) scim_usr
			 * )
			 */

			\preg_match( '/\[(.*?)\]/', $property, $matches, PREG_OFFSET_CAPTURE );

			// $property is not formatted as a query
			if ( ! is_array( $matches ) || count( $matches ) !== 2 ) {

				if ( WordPress_Helpers::stripos( $property, 'urn:ietf:params:scim:schemas:extension' ) !== false ) {
					$splitted  = explode( ':', $property );
					$prop_name = array_pop( $splitted );
					$namespace = implode( ':', $splitted );

					if ( isset( $user_resource[ $namespace ] ) && isset( $user_resource[ $namespace ][ $prop_name ] ) ) {
						return $user_resource[ $namespace ][ $prop_name ];
					}
				} elseif ( WordPress_Helpers::stripos( $property, '.' ) !== false ) {
					$complex_property = explode( '.', $property );

					/**
					 * If $property is an attribute path e.g. "name.givenName"
					 */

					if ( count( $complex_property ) > 0 ) {
						$property_name  = \end( $complex_property ); // e.g. objectId
						$attribute_name = \str_replace( ".$property_name", '', $property ); // e.g. urn:ietf:params:scim:schemas:extension:wpo365:2.0:User

						if (
							isset( $user_resource[ $attribute_name ] )
							&& is_array( $user_resource[ $attribute_name ] )
							&& isset( $user_resource[ $attribute_name ][ $property_name ] )
						) {
							return $user_resource[ $attribute_name ][ $property_name ];
						}
					}
				}

				/**
				 * If $property is simple property e.g. "externalId"
				 */
				if ( isset( $user_resource[ $property ] ) ) {
					return $user_resource[ $property ];
				}

				return false;
			}

			// $property is formatted as a query
			$query = explode( ' ', $matches[1][0] );

			if ( ! is_array( $query ) || count( $query ) !== 3 ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not parse SCIM attribute path ' . $property );
				return false;
			}

			$prop_name  = substr( $property, 0, $matches[0][1] );
			$value_name = substr( $property, ( WordPress_Helpers::strpos( $property, '.' ) + 1 ) );

			$lookup_name  = $query[0];
			$lookup_value = \str_replace( '"', '', $query[2] );

			if ( ! isset( $user_resource[ $prop_name ] ) || ! is_array( $user_resource[ $prop_name ] ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find SCIM attribute ' . $prop_name );
				return false;
			}

			foreach ( $user_resource[ $prop_name ] as $item ) {

				if (
					isset( $item[ $lookup_name ] )
					&& strcasecmp( $item[ $lookup_name ], $lookup_value ) === 0
					&& isset( $item[ $value_name ] )
				) {
					return $item[ $value_name ];
				}
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not find SCIM attribute ' . $prop_name . ' where ' . $lookup_name . ' equals ' . $lookup_value );
			return false;
		}

		private static function try_add_property( &$user_resource, $property, $value ) {
			/**
			 * See SCIM_Users::try_get_property for explanation
			 */

			\preg_match( '/\[(.*?)\]/', $property, $matches, PREG_OFFSET_CAPTURE );

			// $property is not formatted as a query
			if ( ! is_array( $matches ) || count( $matches ) !== 2 ) {

				// Custom properties
				if ( WordPress_Helpers::stripos( $property, 'urn:ietf:params:scim:schemas:extension' ) !== false ) {
					$splitted  = explode( ':', $property );
					$prop_name = array_pop( $splitted );
					$namespace = implode( ':', $splitted );

					if ( empty( $user_resource[ $namespace ] ) ) {
						$user_resource[ $namespace ] = array(
							$prop_name => $value,
						);

						if ( empty( $user_resource['schemas'][ $namespace ] ) ) {
							$user_resource['schemas'][] = $namespace;
						}
					} else {
						$user_resource[ $namespace ][ $prop_name ] = $value;
					}

					return;
				}

				$complex_property = explode( '.', $property );

				/**
				 * If $property is an attribute path e.g. "name.givenName"
				 */

				if ( count( $complex_property ) === 2 ) {

					if ( ! isset( $user_resource[ $complex_property[0] ] ) ) {
						$user_resource[ $complex_property[0] ] = array();
					}

					$user_resource[ $complex_property[0] ][ $complex_property[1] ] = $value;

					return;
				}

				$user_resource[ $property ] = $value;
				return;
			}

			// $property is formatted as a query
			$query = explode( ' ', $matches[1][0] );

			if ( ! is_array( $query ) || count( $query ) !== 3 ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Could not parse SCIM attribute path ' . $property );
				return;
			}

			$prop_name  = substr( $property, 0, $matches[0][1] );
			$value_name = substr( $property, ( WordPress_Helpers::strpos( $property, '.' ) + 1 ) );

			$lookup_name  = $query[0];
			$lookup_value = \str_replace( '"', '', $query[2] );

			if ( ! isset( $user_resource[ $prop_name ] ) ) {
				$user_resource[ $prop_name ] = array();
			}

			$user_resource[ $prop_name ][] = array(
				$lookup_name => $lookup_value,
				$value_name  => $value,
			);
		}

		public static function generate_scim_secret_token() {
			// Verify AJAX request
			$current_user = Ajax_Service::verify_ajax_request( 'to generate a secret token to connect to Entra ID (SCIM)' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to generate a secret token to connect to Entra ID (SCIM) from AJAX service' );
				wp_die();
			}

			$secret_token = Permissions_Helpers::generate_password( 32 );

			if ( ! empty( $secret_token ) ) {
				Options_Service::add_update_option( 'scim_secret_token', $secret_token );
				Ajax_Service::ajax_response( 'OK', 200, '', $secret_token );
			}

			Ajax_Service::ajax_response( 'NOK', 500, 'Failed to create a secret token to connect to Entra ID (SCIM)', null );
		}

		/**
		 * Creates a flattened array from an user resource formatted according to SCIM spec.
		 *
		 * @since 37.0
		 *
		 * @param array  $arr
		 * @param string $prefix
		 * @return array
		 */
		public static function flatten_scim_attributes( $arr, $prefix = '' ) {
			$result = array();

			foreach ( $arr as $key => $value ) {
				$glue    = WordPress_Helpers::stripos( $prefix, 'urn:' ) === 0 ? ':' : '.';
				$new_key = $prefix === '' ? $key : sprintf( '%s%s%s', $prefix, $glue, $key );

				if ( is_numeric( $key ) && is_array( $value ) ) {

					if ( in_array( 'type', $value, true ) ) {

						foreach ( $value as $value_key => $value_value ) {

							if ( strcasecmp( $value_key, 'type' ) === 0 ) {
								continue;
							}

							$new_key            = sprintf( '%s[type eq "%s"].%s', $prefix, $value['type'], $value_key );
							$result[ $new_key ] = $value_value;
						}
					}
				} elseif ( is_array( $value ) ) {
					$result = array_merge( $result, self::flatten_scim_attributes( $value, $new_key ) );
				} else {
					$result[ $new_key ] = $value;
				}
			}

			return $result;
		}

		/**
		 * Sets the response header to comply with SCIM validation.
		 *
		 * @since 37.0
		 *
		 * @param mixed $response
		 * @param mixed $server
		 * @param mixed $request
		 *
		 * @return mixed
		 */
		public static function set_response_headers( $response, $server, $request ) {
			// Check if route belongs to wpo365
			$route = $request->get_route();

			if ( WordPress_Helpers::stripos( $route, '/' . self::WPO_REST_NAMESPACE ) === 0 ) {
				// To comply with SCIM validation
				$response->header( 'Content-Type', 'application/scim+json' );
			}

			return $response;
		}

		/**
		 * Check if a given request has access to get items
		 *
		 * @return WP_Error|bool
		 */
		public static function check_permissions() {

			$headers = \getallheaders();

			if ( ! isset( $headers['Authorization'] ) ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> No "Authorization" header was found for the incoming SCIM request. Please consult with your hosting provider whether they remove the "Authorization" header by default for security reasons.' );
				return false;
			}

			$token        = \str_replace( 'Bearer ', '', $headers['Authorization'] );
			$secret_token = Options_Service::get_global_string_var( 'scim_secret_token' );

			if ( ! empty( $secret_token ) ) {
				return $secret_token === $token;
			}

			return defined( 'WPO_SCIM_TOKEN' ) && constant( 'WPO_SCIM_TOKEN' ) === $token;
		}

		/**
		 * Helper returning a SCIM error message.
		 *
		 * @since   37.0
		 *
		 * @param   int    $status HTTP status code e.g. 404 for not-found or 400 for failed-operation.
		 * @param   string $message
		 *
		 * @return  array   Associative array representing a SCIM error message.
		 */
		private static function error( $status, $message = '' ) {
			$response = array(
				'schemas' => array( 'urn:ietf:params:scim:api:messages:2.0:Error' ),
				'status'  => strval( $status ),
			);

			if ( ! empty( $message ) ) {
				$response['detail'] = $message;
			}

			return $response;
		}

		private static function is_json( $request ) {
			$content_type = $request->get_content_type();

			if ( empty( $content_type ) || WordPress_Helpers::stripos( $content_type['value'], 'json' ) === false ) {
				return false;
			}

			return true;
		}

		// endregion
	}
}
