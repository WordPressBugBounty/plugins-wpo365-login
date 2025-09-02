<?php

namespace Wpo\Services;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

use Wpo\Core\Domain_Helpers;
use Wpo\Core\User;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Authentication_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Request_Service;
use Wpo\Services\Saml2_Service;
use Wpo\Services\User_Create_Service;
use Wpo\Services\Wp_Config_Service;

if ( ! class_exists( '\Wpo\Services\User_Service' ) ) {

	class User_Service {


		const USER_NOT_LOGGED_IN = 0;
		const IS_NOT_O365_USER   = 1;
		const IS_O365_USER       = 2;

		/**
		 * Transform ID token in to internally used User represenation.
		 *
		 * @since 7.17
		 *
		 * @param object $id_token The open ID connect token received.
		 *
		 * @return mixed(User|WP_Error) A new User object created from the id_token or WP_Error if the ID token could not be parsed
		 */
		public static function user_from_id_token( $id_token ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$preferred_username = '';

			if ( property_exists( $id_token, 'preferred_username' ) && ! empty( $id_token->preferred_username ) ) {
				$preferred_username = trim( strtolower( $id_token->preferred_username ) );
			} elseif ( property_exists( $id_token, 'unique_name' ) && ! empty( $id_token->unique_name ) ) {
				$preferred_username = trim( strtolower( $id_token->unique_name ) );
			}

			$custom_username = '';

			if ( Options_Service::get_global_string_var( 'user_name_preference' ) === 'custom' ) {
				$username_claim = Options_Service::get_global_string_var( 'user_name_claim' );

				if ( ! empty( $username_claim ) && property_exists( $id_token, $username_claim ) ) {
					$custom_username = $id_token->$username_claim;
				}
			}

			$upn = isset( $id_token->upn )
			? WordPress_Helpers::trim( strtolower( $id_token->upn ) )
			: '';

			$email = isset( $id_token->email )
			? WordPress_Helpers::trim( strtolower( $id_token->email ) )
			: '';

			$first_name = isset( $id_token->given_name )
			? WordPress_Helpers::trim( $id_token->given_name )
			: '';

			$last_name = isset( $id_token->family_name )
			? WordPress_Helpers::trim( $id_token->family_name )
			: '';

			$full_name = isset( $id_token->name )
			? WordPress_Helpers::trim( $id_token->name )
			: '';

			$tid = isset( $id_token->tid )
			? WordPress_Helpers::trim( $id_token->tid )
			: '';

			$oid = isset( $id_token->oid )
			? WordPress_Helpers::trim( $id_token->oid )
			: '';

			$groups = property_exists( $id_token, 'groups' ) && is_array( $id_token->groups )
			? array_flip( $id_token->groups )
			: array();

			$app_roles = property_exists( $id_token, 'roles' ) && is_array( $id_token->roles )
			? array_flip( $id_token->roles )
			: array();

			$wpo_usr                     = new User();
			$wpo_usr->from_idp_token     = true;
			$wpo_usr->first_name         = $first_name;
			$wpo_usr->last_name          = $last_name;
			$wpo_usr->full_name          = $full_name;
			$wpo_usr->email              = $email;
			$wpo_usr->preferred_username = $preferred_username;
			$wpo_usr->custom_username    = $custom_username;
			$wpo_usr->upn                = $upn;
			$wpo_usr->name               = $full_name;
			$wpo_usr->tid                = $tid;
			$wpo_usr->oid                = $oid;
			$wpo_usr->groups             = $groups;
			$wpo_usr->app_roles          = $app_roles;
			$wpo_usr->is_msa             = ! empty( $wpo_usr->tid ) && WordPress_Helpers::stripos( $wpo_usr->tid, '9188040d-6c67-4c5b-b112-36a304b66dad' ) === 0;

			// Store for later e.g. custom (BuddyPress) fields
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'wpo_usr', $wpo_usr );

			if ( Options_Service::get_global_boolean_var( 'express_login' ) ) {
				return $wpo_usr;
			}

			if ( ! $wpo_usr->is_msa && Options_Service::get_global_string_var( 'extra_user_fields_source' ) === 'graph' && method_exists( '\Wpo\Services\User_Details_Service', 'get_graph_user' ) ) {

				$resource_identifier = ! empty( $wpo_usr->oid )
				? $wpo_usr->oid
				: (
				( ! empty( $wpo_usr->upn )
					? $wpo_usr->upn
					: null )
					);

				$graph_resource = \Wpo\Services\User_Details_Service::get_graph_user( $resource_identifier, false, false, true );

				if ( is_wp_error( $graph_resource ) ) {
					Log_Service::write_log(
						'ERROR',
						sprintf(
							'%s -> WPO365 tried to update the WordPress user with identifier %s but failed to connect to Microsoft Graph. 
                             First of all, please check whether another "WPO365 Health Message" has been generated to inform you of a 
                             connection or permissions issue. If you do not want WPO365 to attempt to get a user\'s attributes from 
                             Microsoft Graph, then please uncheck the option "Retrieve user attributes from Microsoft Graph" on the plugin\'s 
                             "User Sync" configuration page. Alternatively, check the option "Express Login" on the plugin\'s "Login / Logout" 
                             configuration page.',
							__METHOD__,
							$resource_identifier
						)
					);
				} else {

					if ( empty( $wpo_usr->upn ) && is_array( $graph_resource ) && array_key_exists( 'userPrincipalName', $graph_resource ) ) {
						$wpo_usr->upn = $graph_resource['userPrincipalName'];
					}

					$wpo_usr->graph_resource = $graph_resource;
				}
			}

			if ( ! $wpo_usr->is_msa && empty( $wpo_usr->groups ) && method_exists( '\Wpo\Services\User_Aad_Groups_Service', 'get_aad_groups' ) ) {
				\Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $wpo_usr );
			}

			if ( method_exists( '\Wpo\Services\User_Details_Service', 'update_wpo_usr_from_id_token' ) ) {
				\Wpo\Services\User_Details_Service::update_wpo_usr_from_id_token( $wpo_usr, $id_token );
			}

			if ( ! empty( $wpo_usr->graph_resource ) && method_exists( '\Wpo\Services\User_Details_Service', 'try_improve_core_fields' ) ) {
				\Wpo\Services\User_Details_Service::try_improve_core_fields( $wpo_usr );
			}

			/**
			 * @since   31.2    Filters the internal WPO365 representation of a user (see Wpo\Core\User)
			 */
			$wpo_usr = apply_filters( 'wpo365/user', $wpo_usr, 'oidc' );

			$request->set_item( 'wpo_usr', $wpo_usr );
			return $wpo_usr;
		}

		/**
		 * Transform ID token in to internally used User represenation.
		 *
		 * @since 14.0
		 *
		 * @param   object $id_token The open ID connect token received.
		 * @return  mixed(User|WP_Error)    A new User object created from the id_token or WP_Error if the ID token could not be parsed
		 */
		public static function user_from_b2c_id_token( $id_token ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$email = isset( $id_token->emails ) && \is_array( $id_token->emails ) && \count( $id_token->emails ) > 0
			? WordPress_Helpers::trim( strtolower( $id_token->emails[0] ) )
			: '';

			if ( empty( $email ) && ! empty( $id_token->email ) ) {
				$email = $id_token->email;
			}

			$preferred_username = $email;

			$custom_username = '';

			if ( Options_Service::get_global_string_var( 'user_name_preference' ) === 'custom' ) {
				$username_claim = Options_Service::get_global_string_var( 'user_name_claim' );

				if ( ! empty( $username_claim ) && property_exists( $id_token, $username_claim ) ) {
					$custom_username = $id_token->$username_claim;
				}
			}

			$upn = isset( $id_token->upn )
			? WordPress_Helpers::trim( strtolower( $id_token->upn ) )
			: '';

			$first_name = isset( $id_token->given_name )
			? WordPress_Helpers::trim( $id_token->given_name )
			: '';

			$last_name = isset( $id_token->family_name )
			? WordPress_Helpers::trim( $id_token->family_name )
			: '';

			$full_name = isset( $id_token->name )
			? WordPress_Helpers::trim( $id_token->name )
			: '';

			$tid = isset( $id_token->tid )
			? WordPress_Helpers::trim( $id_token->tid )
			: '';

			$oid = isset( $id_token->oid )
			? WordPress_Helpers::trim( $id_token->oid )
			: '';

			$groups = property_exists( $id_token, 'groups' ) && is_array( $id_token->groups )
			? array_flip( $id_token->groups )
			: array();

			$wpo_usr                     = new User();
			$wpo_usr->from_idp_token     = true;
			$wpo_usr->first_name         = $first_name;
			$wpo_usr->last_name          = $last_name;
			$wpo_usr->full_name          = $full_name;
			$wpo_usr->email              = $email;
			$wpo_usr->preferred_username = $preferred_username;
			$wpo_usr->custom_username    = $custom_username;
			$wpo_usr->upn                = $upn;
			$wpo_usr->name               = $full_name;
			$wpo_usr->tid                = $tid;
			$wpo_usr->oid                = $oid;
			$wpo_usr->groups             = $groups;
			$wpo_usr->is_msa             = ! empty( $wpo_usr->tid ) && WordPress_Helpers::stripos( $wpo_usr->tid, '9188040d-6c67-4c5b-b112-36a304b66dad' ) === 0;

			// Store for later e.g. custom (BuddyPress) fields
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'wpo_usr', $wpo_usr );

			if ( Options_Service::get_global_boolean_var( 'express_login' ) ) {
				return $wpo_usr;
			}

			if ( method_exists( '\Wpo\Services\User_Details_Service', 'update_wpo_usr_from_id_token' ) ) {
				\Wpo\Services\User_Details_Service::update_wpo_usr_from_id_token( $wpo_usr, $id_token );
			}

			if ( ! $wpo_usr->is_msa && Options_Service::get_global_string_var( 'extra_user_fields_source' ) === 'graph' && method_exists( '\Wpo\Services\User_Details_Service', 'get_graph_user' ) ) {

				$resource_identifier = ! empty( $wpo_usr->oid )
				? $wpo_usr->oid
				: (
				( ! empty( $wpo_usr->upn )
					? $wpo_usr->upn
					: null )
					);

				$graph_resource = \Wpo\Services\User_Details_Service::get_graph_user( $resource_identifier, false, false, true );

				if ( is_wp_error( $graph_resource ) ) {
					Log_Service::write_log(
						'ERROR',
						sprintf(
							'%s -> WPO365 tried to update the WordPress user with identifier %s but failed to connect to Microsoft Graph. 
                             First of all, please check whether another "WPO365 Health Message" has been generated to inform you of a 
                             connection or permissions issue. If you do not want WPO365 to attempt to get a user\'s attributes from 
                             Microsoft Graph, then please uncheck the option "Retrieve user attributes from Microsoft Graph" on the plugin\'s 
                             "User Sync" configuration page. Alternatively, check the option "Express Login" on the plugin\'s "Login / Logout" 
                             configuration page.',
							__METHOD__,
							$resource_identifier
						)
					);
				} else {

					if ( empty( $wpo_usr->upn ) && is_array( $graph_resource ) && array_key_exists( 'userPrincipalName', $graph_resource ) ) {
						$wpo_usr->upn = $graph_resource['userPrincipalName'];
					}

					$wpo_usr->graph_resource = $graph_resource;
				}
			}

			if ( ! $wpo_usr->is_msa && empty( $wpo_usr->groups ) && method_exists( '\Wpo\Services\User_Aad_Groups_Service', 'get_aad_groups' ) ) {
				\Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $wpo_usr );
			}

			if ( ! empty( $wpo_usr->graph_resource ) && method_exists( '\Wpo\Services\User_Details_Service', 'try_improve_core_fields' ) ) {
				\Wpo\Services\User_Details_Service::try_improve_core_fields( $wpo_usr );
			}

			/**
			 * @since   31.2    Filters the internal WPO365 representation of a user (see Wpo\Core\User)
			 */
			$wpo_usr = apply_filters( 'wpo365/user', $wpo_usr, 'oidc' );

			$request->set_item( 'wpo_usr', $wpo_usr );
			return $wpo_usr;
		}

		/**
		 * Parse graph user response received and return User object. This method may return a user
		 * without an email address.
		 *
		 * @since 2.2
		 *
		 * @param array $graph_resource  received from Microsoft Graph.
		 *
		 * @return User     A new User Object created from the graph response
		 */
		public static function user_from_graph_user( $graph_resource ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$wpo_usr = new User();

			if ( empty( $graph_resource ) ) {
				return $wpo_usr;
			}

			$wpo_usr->email = isset( $graph_resource['mail'] ) ? $graph_resource['mail'] : '';

			if ( empty( $wpo_usr->email ) ) {

				if ( ! empty( $graph_resource['otherMails'] ) ) {
					$wpo_usr->email = $graph_resource['otherMails'][0];
				} elseif ( ! empty( $graph_resource['identities'] ) ) {

					foreach ( $graph_resource['identities'] as $identity ) {

						if ( ! empty( $identity['signInType'] ) && $identity['signInType'] === 'emailAddress' && ! empty( $identity['issuerAssignedId'] ) ) {
							$wpo_usr->email = $identity['issuerAssignedId'];
							break;
						}
					}
				}
			}

			if ( ! empty( $wpo_usr->email ) ) {
				$wpo_usr->preferred_username = $wpo_usr->email;
			}

			if ( empty( $wpo_usr->preferred_username ) && ! empty( $graph_resource['userPrincipalName'] ) && WordPress_Helpers::stripos( $graph_resource['userPrincipalName'], '#ext#' ) === false && WordPress_Helpers::stripos( $graph_resource['userPrincipalName'], 'onmicrosoft.com' ) === false ) {
				$wpo_usr->preferred_username = $graph_resource['userPrincipalName'];
			}

			if ( Options_Service::get_global_string_var( 'user_name_preference' ) === 'custom' ) {
				$username_property = Options_Service::get_global_string_var( 'user_name_aad_property' );

				if ( ! empty( $username_property ) && ! empty( $graph_resource[ $username_property ] ) ) {
					$wpo_usr->custom_username = $graph_resource[ $username_property ];
				}
			}

			$wpo_usr->first_name     = isset( $graph_resource['givenName'] ) ? $graph_resource['givenName'] : '';
			$wpo_usr->last_name      = isset( $graph_resource['surname'] ) ? $graph_resource['surname'] : '';
			$wpo_usr->full_name      = isset( $graph_resource['displayName'] ) ? $graph_resource['displayName'] : '';
			$wpo_usr->upn            = isset( $graph_resource['userPrincipalName'] ) ? $graph_resource['userPrincipalName'] : '';
			$wpo_usr->oid            = isset( $graph_resource['id'] ) ? $graph_resource['id'] : '';
			$wpo_usr->name           = ! empty( $wpo_usr->full_name )
			? $wpo_usr->full_name
			: $wpo_usr->preferred_username;
			$wpo_usr->graph_resource = $graph_resource;

			// Enrich -> Azure AD groups
			if ( \class_exists( '\Wpo\Services\User_Aad_Groups_Service' ) && \method_exists( '\Wpo\Services\User_Aad_Groups_Service', 'get_aad_groups' ) ) {
				\Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $wpo_usr );
			}

			/**
			 * @since   31.2    Filters the internal WPO365 representation of a user (see Wpo\Core\User)
			 */
			$wpo_usr = apply_filters( 'wpo365/user', $wpo_usr, 'graph' );

			// Store for later e.g. custom (BuddyPress) fields
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'wpo_usr', $wpo_usr );

			return $wpo_usr;
		}

		/**
		 * Transform ID token in to internally used User represenation.
		 *
		 * @since 7.17
		 *
		 * @param string $name_id
		 * @param array  $saml_attributes The open ID connect token received.
		 *
		 * @return mixed(User|WP_Error) A new User object created from the id_token or WP_Error if the ID token could not be parsed
		 */
		public static function user_from_saml_response( $name_id, $saml_attributes ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$preferred_username = Saml2_Service::get_attribute( 'preferred_username', $saml_attributes, true ) ?? '';
			$upn                = ! empty( $name_id ) ? $name_id : $preferred_username;
			$email              = Saml2_Service::get_attribute( 'email', $saml_attributes, true );
			$first_name         = Saml2_Service::get_attribute( 'first_name', $saml_attributes );
			$last_name          = Saml2_Service::get_attribute( 'last_name', $saml_attributes );
			$full_name          = Saml2_Service::get_attribute( 'full_name', $saml_attributes );
			$tid                = Saml2_Service::get_attribute( 'tid', $saml_attributes );
			$oid                = Saml2_Service::get_attribute( 'objectidentifier', $saml_attributes );
			$groups             = Saml2_Service::get_attribute( 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups', $saml_attributes, false, 'array' );
			$app_roles          = Saml2_Service::get_attribute( 'http://schemas.microsoft.com/ws/2008/06/identity/claims/role', $saml_attributes, false, 'array' );

			$custom_username = '';

			if ( Options_Service::get_global_string_var( 'user_name_preference' ) === 'custom' ) {
				$username_claim = Options_Service::get_global_string_var( 'user_name_claim' );

				if ( ! empty( $username_claim ) ) {
					$custom_username = Saml2_Service::get_attribute( $username_claim, $saml_attributes );
				}
			}

			$wpo_usr                     = new User();
			$wpo_usr->from_idp_token     = true;
			$wpo_usr->first_name         = $first_name;
			$wpo_usr->last_name          = $last_name;
			$wpo_usr->full_name          = $full_name;
			$wpo_usr->email              = $email;
			$wpo_usr->preferred_username = $preferred_username;
			$wpo_usr->custom_username    = $custom_username;
			$wpo_usr->upn                = $upn;
			$wpo_usr->name               = $full_name;
			$wpo_usr->tid                = $tid;
			$wpo_usr->oid                = $oid;
			$wpo_usr->groups             = is_array( $groups ) ? array_flip( $groups ) : array();
			$wpo_usr->app_roles          = is_array( $app_roles ) ? array_flip( $app_roles ) : array();

			// Store for later e.g. custom (BuddyPress) fields
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
			$request->set_item( 'wpo_usr', $wpo_usr );

			if ( Options_Service::get_global_boolean_var( 'express_login' ) ) {
				return $wpo_usr;
			}

			if ( method_exists( '\Wpo\Services\User_Details_Service', 'update_wpo_usr_from_saml_attributes' ) ) {
				\Wpo\Services\User_Details_Service::update_wpo_usr_from_saml_attributes( $wpo_usr, $saml_attributes );
			}

			if ( Options_Service::get_global_string_var( 'extra_user_fields_source' ) === 'graph' && method_exists( '\Wpo\Services\User_Details_Service', 'get_graph_user' ) ) {

				$resource_identifier = ! empty( $wpo_usr->oid )
				? $wpo_usr->oid
				: (
				( ! empty( $wpo_usr->upn )
					? $wpo_usr->upn
					: null )
					);

				$graph_resource = \Wpo\Services\User_Details_Service::get_graph_user( $resource_identifier, false, false, true );

				if ( is_wp_error( $graph_resource ) ) {
					Log_Service::write_log(
						'ERROR',
						sprintf(
							'%s -> WPO365 tried to update the WordPress user with identifier %s but failed to connect to Microsoft Graph. 
                             First of all, please check whether another "WPO365 Health Message" has been generated to inform you of a 
                             connection or permissions issue. If you do not want WPO365 to attempt to get a user\'s attributes from 
                             Microsoft Graph, then please uncheck the option "Retrieve user attributes from Microsoft Graph" on the plugin\'s 
                             "User Sync" configuration page. Alternatively, check the option "Express Login" on the plugin\'s "Login / Logout" 
                             configuration page.',
							__METHOD__,
							$resource_identifier
						)
					);
				} else {

					if ( empty( $wpo_usr->upn ) && is_array( $graph_resource ) && array_key_exists( 'userPrincipalName', $graph_resource ) ) {
						$wpo_usr->upn = $graph_resource['userPrincipalName'];
					}

					$wpo_usr->graph_resource = $graph_resource;
				}
			}

			if ( ! $wpo_usr->is_msa && empty( $wpo_usr->groups ) && method_exists( '\Wpo\Services\User_Aad_Groups_Service', 'get_aad_groups' ) ) {
				\Wpo\Services\User_Aad_Groups_Service::get_aad_groups( $wpo_usr );
			}

			if ( ! empty( $wpo_usr->graph_resource ) && method_exists( '\Wpo\Services\User_Details_Service', 'try_improve_core_fields' ) ) {
				\Wpo\Services\User_Details_Service::try_improve_core_fields( $wpo_usr );
			}

			/**
			 * @since   31.2    Filters the internal WPO365 representation of a user (see Wpo\Core\User)
			 */
			$wpo_usr = apply_filters( 'wpo365/user', $wpo_usr, 'saml' );

			$request->set_item( 'wpo_usr', $wpo_usr );
			return $wpo_usr;
		}

		/**
		 * @since 11.0
		 */
		public static function ensure_user( $wpo_usr ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$wp_usr = self::try_get_user_by( $wpo_usr );

			if ( ! empty( $wp_usr ) ) {

				/**
				 * @since 15.0  Administrators may allow users to sign in and "re-activate" themselves if
				 *              they can sign in with Microsoft successfully.
				 */
				if ( ! Options_Service::get_global_boolean_var( 'allow_reactivation' ) ) {
					Authentication_Service::is_deactivated( $wp_usr->user_login, true );
				}

				\delete_user_meta( $wp_usr->ID, 'wpo365_active' );

				$wp_usr_id = $wp_usr->ID;
			}

			if ( empty( $wp_usr ) ) {
				if ( \class_exists( '\Wpo\Services\User_Create_Update_Service' ) && \method_exists( '\Wpo\Services\User_Create_Update_Service', 'create_user' ) ) {
					$wp_usr_id = \Wpo\Services\User_Create_Update_Service::create_user( $wpo_usr );
				} else {
					$wp_usr_id = User_Create_Service::create_user( $wpo_usr );
				}
			}

			if (
			! Options_Service::get_global_boolean_var( 'express_login' )
			&& class_exists( '\Wpo\Services\User_Create_Update_Service' ) && \method_exists( '\Wpo\Services\User_Create_Update_Service', 'update_user' )
			) {
				\Wpo\Services\User_Create_Update_Service::update_user( $wp_usr_id, $wpo_usr );
			} else {
				// At the very least add user to current blog
				Wpmu_Helpers::wpmu_add_user_to_blog( $wp_usr_id );

				// And memoize some user Entra handles (if not created or updated)
				if ( ! $wpo_usr->created ) {
					self::save_user_principal_name( $wpo_usr->upn, $wp_usr_id );
					self::save_user_tenant_id( $wpo_usr->tid, $wp_usr_id );
					self::save_user_object_id( $wpo_usr->oid, $wp_usr_id );
				}
			}

			$wp_usr = \get_user_by( 'ID', $wp_usr_id );

			return $wp_usr;
		}

		/**
		 * Tries to find the user by upn, accountname or email.
		 *
		 * @since 9.4
		 *
		 * @param User $wpo_usr
		 *
		 * @return WP_User or null
		 */
		public static function try_get_user_by( $wpo_usr ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			$user_match_order = Options_Service::get_global_list_var( 'user_match_order' );

			if ( empty( $user_match_order ) ) {
				$user_match_order = array( 'oid', 'upn', 'preferred_username', 'email' );
			}

			foreach ( $user_match_order as $field ) {

				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Matching user by field ' . $field );

				if ( $field === 'oid' ) {
					$wp_user = self::get_user_by_oid( $wpo_usr );

					if ( ! empty( $wp_user ) ) {
						return $wp_user;
					}
				} elseif ( $field === 'upn' ) {
					$wp_user = self::get_user_by_upn( $wpo_usr );

					if ( ! empty( $wp_user ) ) {
						return $wp_user;
					}
				} elseif ( $field === 'preferred_username' ) {

					if ( ! empty( $wpo_usr->preferred_username ) ) {
						$wp_usr = \get_user_by( 'login', sanitize_user( $wpo_usr->preferred_username, true ) );

						if ( ! empty( $wp_usr ) ) {
							return $wp_usr;
						}
					}
				} elseif ( $field === 'email' ) {

					if ( ! empty( $wpo_usr->email ) ) {
						$wp_usr = \get_user_by( 'email', $wpo_usr->email );

						if ( ! empty( $wp_usr ) ) {
							return $wp_usr;
						}
					}
				} elseif ( $field === 'custom' ) {

					if ( ! empty( $wpo_usr->custom_username ) ) {
						$wp_usr = \get_user_by( 'login', $wpo_usr->custom_username );

						if ( ! empty( $wp_usr ) ) {
							return $wp_usr;
						}
					}
				} elseif ( $field === 'login' ) {

					if ( ! empty( $wpo_usr->preferred_username ) ) {

						$atpos = WordPress_Helpers::strpos( $wpo_usr->preferred_username, '@' );

						if ( $atpos !== false ) {
							$accountname = substr( $wpo_usr->preferred_username, 0, $atpos );
							$wp_usr      = \get_user_by( 'login', $accountname );

							if ( ! empty( $wp_usr ) ) {
								return $wp_usr;
							}
						}
					}
				}
			}

			return null;
		}

		/**
		 * @since 11.0
		 */
		public static function try_get_user_principal_name( $wp_usr_id ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( empty( $wp_usr_id ) ) {
				return null;
			}

			$upn = get_user_meta( $wp_usr_id, 'userPrincipalName', true );

			if ( empty( $upn ) ) {
				$wp_usr      = \get_user_by( 'ID', $wp_usr_id );
				$upn         = $wp_usr->user_login;
				$smtp_domain = Domain_Helpers::get_smtp_domain_from_email_address( $upn );

				// User's login cannot be used to identify the user resource
				if ( empty( $smtp_domain ) || ! Domain_Helpers::is_tenant_domain( $smtp_domain ) ) {
					$upn         = $wp_usr->user_email;
					$smtp_domain = Domain_Helpers::get_smtp_domain_from_email_address( $upn );

					if ( empty( $smtp_domain ) || ! Domain_Helpers::is_tenant_domain( $smtp_domain ) ) {
						return null;
					}
				}
			}

			return $upn;
		}

		/**
		 * @since 11.0
		 */
		public static function save_user_principal_name( $upn, $wp_usr_id = 0 ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( $wp_usr_id > 0 && ! empty( $upn ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Successfully saved upn ' . $upn );
				update_user_meta( $wp_usr_id, 'userPrincipalName', $upn );
			}
		}

		/**
		 * @since 11.0
		 */
		public static function save_user_tenant_id( $tid, $wp_usr_id = 0 ) {
			if ( $wp_usr_id > 0 && ! empty( $tid ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Successfully saved user tenant id ' . $tid );
				update_user_meta( $wp_usr_id, 'aadTenantId', $tid );
			}
		}

		/**
		 * @since 11.0
		 */
		public static function save_user_idp_id( $idp_id ) {

			if ( empty( $idp_id ) ) {
				return;
			}

			$wp_usr_id = get_current_user_id();

			if ( $wp_usr_id > 0 && ! empty( $idp_id ) ) {
				$wpo_idps = Wp_Config_Service::get_multiple_idps();

				if ( ! is_array( $wpo_idps ) ) {
					return;
				}

				$filtered_idps = array_filter(
					$wpo_idps,
					function ( $idp ) use ( $idp_id ) {
						return ! empty( $idp['id'] ) && strcasecmp( $idp['id'], $idp_id ) === 0;
					}
				);

				$filtered_idps = array_values( $filtered_idps ); // re-index from 0

				if ( count( $filtered_idps ) === 1 ) {
					Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Successfully saved user IdP id ' . $idp_id );
					update_user_meta( $wp_usr_id, 'wpo365_idp_id', $idp_id );
				}
			}
		}

		/**
		 * Tries to retrieve a user's Azure AD object ID stored as user meta when the user last logged in.
		 *
		 * @since 12.10
		 *
		 * @param   int $wp_usr_id     The user's WP_User ID.
		 *
		 * @return  mixed(string|null)  GUID as string or null if not found
		 */
		public static function try_get_user_object_id( $wp_usr_id ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( empty( $wp_usr_id ) ) {
				return null;
			}

					$oid = get_user_meta( $wp_usr_id, 'aadObjectId', true );

			if ( empty( $oid ) ) {
				$oid = null;
			}

					return $oid;
		}

				/**
				 * @since 11.0
				 */
		public static function save_user_object_id( $oid, $wp_usr_id = 0 ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( $wp_usr_id > 0 && ! empty( $oid ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Successfully saved user object id ' . $oid );
				update_user_meta( $wp_usr_id, 'aadObjectId', $oid );
			}
		}

				/**
				 * Checks whether current user is O365 user
				 *
				 * @since   1.0
				 * @return  int One of the following User Service class constants
				 *              USER_NOT_LOGGED_IN, IS_O365_USER or IS_NOT_O365_USER
				 */
		public static function user_is_o365_user( $wp_usr_id, $email = '' ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			/**
			 * @since 32.0  Bail out early if user is tagged with aadObjectId
			 */

			if ( self::try_get_user_object_id( $wp_usr_id ) !== null ) {
				return self::IS_O365_USER;
			}

			$wp_usr = get_user_by( 'ID', intval( $wp_usr_id ) );

			if ( ! empty( $email ) && $wp_usr === false ) {
				$wp_usr = get_user_by( 'email', $email );
			}

			if ( $wp_usr === false ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Checking whether user is O365 user -> Not logged on' );
				return self::USER_NOT_LOGGED_IN;
			}

			$email_domain = Domain_Helpers::get_smtp_domain_from_email_address( $wp_usr->user_email );

			if ( Domain_Helpers::is_tenant_domain( $email_domain ) ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Checking whether user is O365 user -> YES' );
				return self::IS_O365_USER;
			}

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Checking whether user is O365 user -> NO' );
			return self::IS_NOT_O365_USER;
		}

				/**
				 *
				 * @param User $wpo_usr
				 * @return mixed(User|null)
				 */
		public static function get_user_by_oid( $wpo_usr ) {

			if ( empty( $wpo_usr ) || empty( $wpo_usr->oid ) ) {
				return null;
			}

			$args = array(
				'meta_key'   => 'aadObjectId', // phpcs:ignore
				'meta_value' => $wpo_usr->oid, // phpcs:ignore
			);

			$users = get_users( $args );

			if ( count( $users ) !== 1 ) {
				return null;
			}

			return $users[0];
		}

				/**
				 *
				 * @param User $wpo_usr
				 *
				 * @return WP_User|null
				 */
		public static function get_user_by_upn( $wpo_usr ) {
			if ( empty( $wpo_usr ) || empty( $wpo_usr->upn ) ) {
				return null;
			}

			$args = array(
				'meta_key'   => 'userPrincipalName', // phpcs:ignore
				'meta_value' => $wpo_usr->upn, // phpcs:ignore
			);

			$users = get_users( $args );

			if ( count( $users ) !== 1 ) {
				return null;
			}

			return $users[0];
		}

				/**
				 * Simple helper to the User from current request context.
				 *
				 * @since 33.2
				 *
				 * @return User|false
				 */
		public static function get_wpo_user_from_context() {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			return $request->get_item( 'wpo_usr' );
		}
	}
}
