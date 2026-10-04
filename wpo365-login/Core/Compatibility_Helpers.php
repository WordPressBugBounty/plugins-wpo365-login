<?php

namespace Wpo\Core;

use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Wp_Config_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Core\Compatibility_Helpers' ) ) {

	class Compatibility_Helpers {

		/**
		 * Writes the compatibility warning as an error to the log but only if it's not currently already in the list
		 * of WPO365 Health Messages.
		 *
		 * @since   20.0
		 *
		 * @param   string $warning
		 *
		 * @return  void
		 */
		public static function compat_warning( $warning ) {
			$wpo_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );

			if ( empty( $wpo_errors ) || ! is_array( $wpo_errors ) ) {
				Log_Service::write_log( 'ERROR', $warning );
			} else {
				// Log_Service stores health messages sanitized (e.g. "->" becomes "-&gt;"), so compare like with like.
				$health_warning = wp_kses( $warning, WordPress_Helpers::get_allowed_message_html() );

				$same_errors = array_filter(
					$wpo_errors,
					function ( $wpo_error ) use ( $health_warning ) {
						return isset( $wpo_error['body'] ) && WordPress_Helpers::stripos( $wpo_error['body'], $health_warning ) !== false;
					}
				);

				if ( count( $same_errors ) === 0 ) {
					Log_Service::write_log( 'ERROR', $warning );
				}
			}
		}

		/**
		 * Reduces the key of the extra_user_fields array by removing the name part for custom
		 * WordPress usermeta that was introduced with version 20.
		 *
		 * @since   20.0
		 *
		 * @param   array $extra_user_fields  The array of extra user fields that will be updated.
		 *
		 * @return  array
		 */
		public static function update_user_field_key( $extra_user_fields ) {
			if ( ! class_exists( '\Wpo\Services\User_Details_Service' ) || method_exists( '\Wpo\Services\User_Details_Service', 'parse_user_field_key' ) ) {
				return $extra_user_fields;
			}

			// Iterate over the configured graph fields and identify any supported expandable properties
			$extra_user_fields = array_map(
				function ( $kv_pair ) {
					$marker_pos = WordPress_Helpers::stripos( $kv_pair['key'], ';#' );

					if ( $marker_pos > 0 ) {
						$kv_pair['key'] = substr( $kv_pair['key'], 0, $marker_pos );
					}

					return $kv_pair;
				},
				$extra_user_fields
			);

			$compat_warning = sprintf(
				'%s -> The administrator configured <em>Entra ID user attributes to WordPress user meta mappings</em> on the plugin\'s <strong>User sync</strong> page. These mappings have been recently upgraded to allow administrators to specify their own name for the usermeta key. This new feature, however, breaks existing functionality. To remain compatible you should update your premium WPO365 extension and optionally update the existing mappings.',
				__METHOD__
			);

			self::compat_warning( $compat_warning );

			return $extra_user_fields;
		}

		/**
		 * Starting with version 31.0 mappings to save user details as WP user meta must be prefixed with their corresponding source or else WPO365 cannot decide whether
		 * or not the user meta should be removed. For example, "department" may be a SAML claim for a user set to "Communications". If that user property is emptied, the
		 * claim will be omitted from the SAML response (instead of being sent as a null value).
		 *
		 * @param mixed $claim
		 * @return bool True if $claim has the expected prefix otherwise false
		 */
		public static function check_user_claim_prefix( $claim ) {

			if (
				WordPress_Helpers::stripos( $claim, 'graph::' ) !== 0
				&& WordPress_Helpers::stripos( $claim, 'scim::' ) !== 0
				&& WordPress_Helpers::stripos( $claim, 'saml::' ) !== 0
				&& WordPress_Helpers::stripos( $claim, 'oidc::' ) !== 0
			) {
				$compat_warning = sprintf(
					'Starting with version 31.0 WPO365 requires that you add a prefix to each ID token claim (prefix: "oidc::"), SAML 2.0 claim (prefix: "saml::"), SCIM attribute (prefix: scim::) or Microsoft Graph property (prefix: "graph::") for which you have entered a mapping on the plugin\'s "User Sync" configuration page. See https://docs.wpo365.com/article/98-synchronize-microsoft-365-azure-ad-profile-fields for further details.',
					__METHOD__
				);

				self::compat_warning( $compat_warning );
				return false;
			}

			return true;
		}

		/**
		 * Warns the administrator - whilst an administrator is viewing a WP Admin page - that support for the
		 * option to allow apps to request any Microsoft Graph endpoint has been discontinued and that its value
		 * is being ignored.
		 *
		 * @since   45.0
		 *
		 * @return  void
		 */
		public static function check_deprecated_graph_options() {

			if ( ! Options_Service::get_global_boolean_var( 'graph_allow_all_endpoints' ) ) {
				return;
			}

			$compat_warning = sprintf(
				'%s -> Support for the option <em>Allow apps to request any Microsoft Graph endpoint</em> on the plugin\'s <a href="#integration">Integration</a> configuration page has been discontinued, because of security concerns. The option is now ignored and each destination must instead be added to the list of <em>Allowed endpoints</em>. Please review that list and then uncheck the option (once unchecked it cannot be re-enabled).',
				__METHOD__
			);

			self::compat_warning( $compat_warning );
		}

		/**
		 * Warns the administrator - whilst an administrator is viewing a WP Admin page - that "Require login for the Media
		 * Folder" is not (yet) supported on multisite networks, where it may not protect the files of every site.
		 *
		 * @since   45.0
		 *
		 * @return  void
		 */
		public static function check_unsupported_media_folder_protection() {

			if ( ! is_multisite() || ! Options_Service::get_global_boolean_var( 'block_direct_media_access' ) ) {
				return;
			}

			self::compat_warning(
				sprintf(
					'%s -> <em>Require login for the Media Folder</em> is not supported on WordPress multisite networks and may not protect your files. Please turn it off on the plugin\'s <a href="#rolesAccess">Roles + Access</a> configuration page; this also removes WPO365\'s rules from .htaccess.',
					__METHOD__
				)
			);
		}

		public static function upgrade_actions( $plugin_name = 'wpo365_login' ) {

			/**
			 * @since 45.0  upgrade_actions is read from the database only (see Options_Service::is_db_only_option).
			 * Runs first, so that the actions below don't run again when WPO_OVERRIDES_<blog id> records them as done.
			 */

			self::upgrade_actions_from_db();

			/**
			 * @since 45.0  License keys are read from the database only (see Options_Service::is_db_only_option).
			 */

			self::license_keys_from_db();

			if ( $plugin_name === 'wpo365_login' ) {
				$upgrade_actions = Options_Service::get_global_list_var( 'upgrade_actions' );

				if ( ! in_array( 'client_side_redirect', $upgrade_actions, true ) ) {

					if ( Options_Service::is_wpo365_configured() ) {
						Options_Service::add_update_option( 'use_teams', true );

						self::compat_warning(
							sprintf(
								'Starting with WPO365 | LOGIN version 33.0 you can configure WPO365 to redirect users to Microsoft faster (using a server-side redirect). This is generally recommended to avoid issues with server-side / external caching services. Uncheck the option "Use client-side redirect" on the plugin\'s "Login / Logout" configuration page, unless your WordPress site is integrated in Microsoft Teams, or you wish to briefly display a "loading" icon when the user is redirected. See https://docs.wpo365.com/article/223-use-client-side-redirect for details.',
								__METHOD__
							)
						);
					}

					$upgrade_actions[] = 'client_side_redirect';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}

				/**
				 * @since 43.x  "Use custom Logged Out Page" (use_custom_logged_out_page) is a
				 * new option that decides whether error_page_url is used at all. Existing
				 * installs that already configured an error_page_url before this option
				 * existed should keep using it, so this one-time check turns the new option on
				 * for them; new installs (and sites where error_page_url was never configured)
				 * get the new default of false, meaning the built-in /wpo/loggedout page is
				 * used instead. Deliberately a one-time migration rather than inferred on every
				 * wizard load, so that explicitly unchecking the option later - while a stale
				 * error_page_url value happens to still be present - is never silently
				 * overridden back to true.
				 */

				if ( ! in_array( 'use_custom_logged_out_page_default', $upgrade_actions, true ) ) {

					if ( ! empty( Options_Service::get_global_string_var( 'error_page_url' ) ) ) {
						Options_Service::add_update_option( 'use_custom_logged_out_page', true );
					}

					$upgrade_actions[] = 'use_custom_logged_out_page_default';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}

				/**
				 * @since 45.0  Support for "Allow apps to request any Microsoft Graph endpoint"
				 * (graph_allow_all_endpoints) has been discontinued. A website that relied on that
				 * option never needed to allow-list a single destination, so without this one-time
				 * migration every request from a (WPO365) app would suddenly be refused. Seed the
				 * list with the destinations that can be derived from the current configuration.
				 */

				if ( ! in_array( 'graph_allow_all_endpoints_retired', $upgrade_actions, true ) ) {

					if ( Options_Service::get_global_boolean_var( 'graph_allow_all_endpoints' ) ) {
						self::seed_graph_allowed_endpoints();
					}

					$upgrade_actions[] = 'graph_allow_all_endpoints_retired';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}

				/**
				 * @since 45.0  Between version 25.0 and WI-304, exporting the SAML 2.0 SP metadata saved the default
				 * Single Logout Service URL as ".../wp-login.php&action=loggedout". Repair it once in the database. Only
				 * the database value is read, so a value from wp-config.php is never copied in - that one is repaired
				 * whenever it is read (see Saml2_Service::repair_query_string).
				 */

				if ( ! in_array( 'saml_sp_sls_url_repaired', $upgrade_actions, true ) ) {
					$db_options = Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin()
						? get_option( 'wpo365_options', array() )
						: get_site_option( 'wpo365_options', array() );
					$db_sls_url = is_array( $db_options ) && isset( $db_options['saml_sp_sls_url'] ) && is_string( $db_options['saml_sp_sls_url'] )
						? $db_options['saml_sp_sls_url']
						: '';
					$repaired   = \Wpo\Services\Saml2_Service::repair_query_string( $db_sls_url );

					if ( $repaired !== $db_sls_url ) {
						Options_Service::add_update_option( 'saml_sp_sls_url', $repaired );
					}

					$upgrade_actions[] = 'saml_sp_sls_url_repaired';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}

				/**
				 * @since 45.0  WI-425: the option "Use Firebase\JWT instead of phpseclib" (use_id_token_parser_v2) and the
				 * deprecated ID token parser behind it have been removed. Tell an administrator who had it enabled - once -
				 * that ID tokens are now always validated with phpseclib. The stored value is simply ignored.
				 */

				if ( ! in_array( 'id_token_parser_v2_retired', $upgrade_actions, true ) ) {

					if ( Options_Service::get_global_boolean_var( 'use_id_token_parser_v2' ) ) {
						self::compat_warning(
							sprintf(
								'%s -> The option <em>Use Firebase\JWT instead of phpseclib</em> on the plugin\'s <a href="#miscellaneous">Miscellaneous</a> configuration page has been removed because of security concerns. WPO365 now always validates ID tokens with phpseclib. If users can no longer sign in with Microsoft, please run the plugin\'s self-test and contact support@wpo365.com.',
								__METHOD__
							)
						);
					}

					$upgrade_actions[] = 'id_token_parser_v2_retired';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}

				/**
				 * @since 45.0  WI-427: the option "Skip ID token verification" (skip_id_token_verification) has been
				 * removed. Tell an administrator who had it enabled - once - that ID tokens are now always checked.
				 */

				if ( ! in_array( 'skip_id_token_verification_retired', $upgrade_actions, true ) ) {

					if ( Options_Service::get_global_boolean_var( 'skip_id_token_verification' ) ) {
						self::compat_warning(
							sprintf(
								'%s -> The option <em>Skip ID token verification</em> on the plugin\'s <a href="#singleSignOn">Single Sign-on</a> configuration page has been removed because of security concerns. WPO365 now always checks that an ID token was issued for your application by the issuer that Microsoft publishes for your tenant. If users can no longer sign in with Microsoft, please run the plugin\'s self-test and contact support@wpo365.com.',
								__METHOD__
							)
						);
					}

					$upgrade_actions[] = 'skip_id_token_verification_retired';
					Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
				}
			}
		}

		/**
		 * Adds the destinations that can be derived from the current configuration to the list of allowed
		 * endpoints. Existing entries are never modified or removed and application-level permissions are
		 * only ever seeded for an app that has been configured for app-only access.
		 *
		 * @since   45.0
		 *
		 * @return  void
		 */
		private static function seed_graph_allowed_endpoints() {
			$allowed_endpoints = Options_Service::get_global_list_var( 'graph_allowed_endpoints' );
			$tld               = Options_Service::get_aad_option( 'tld' );
			$tld               = ! empty( $tld ) ? $tld : '.com';

			$seeds = array(
				array(
					'key'     => sprintf( 'https://graph.microsoft%s/', $tld ),
					'boolVal' => false,
				),
			);

			$app_instances     = class_exists( '\Wpo\Graph\Apps_Db' ) ? \Wpo\Graph\Apps_Db::get_app_instances() : array();
			$power_bi          = false;
			$power_bi_app_only = false;

			if ( ! is_wp_error( $app_instances ) && is_array( $app_instances ) ) {

				foreach ( $app_instances as $app_instance ) {
					// Continue with an associative array to avoid tripping the WordPress sniff for camelCase members.
					$instance = json_decode( wp_json_encode( $app_instance ), true );

					if ( empty( $instance ) || ! is_array( $instance ) ) {
						continue;
					}

					$app_only = isset( $instance['appliedRequirements']['userRequirements']['appOnlyAccess'] )
						&& $instance['appliedRequirements']['userRequirements']['appOnlyAccess'] === true;

					// The SharePoint host an app connects to is website-specific and therefore not part of an app's static requirements.
					if ( ! empty( $instance['config']['legacy']['hostname'] ) ) {
						$host    = preg_replace( '#^https?://#i', '', $instance['config']['legacy']['hostname'] );
						$seeds[] = array(
							'key'     => sprintf( 'https://%s', untrailingslashit( $host ) ),
							'boolVal' => false,
						);
					}

					if ( ! empty( $instance['appType'] ) && strcasecmp( $instance['appType'], 'pbi' ) === 0 ) {
						$power_bi          = true;
						$power_bi_app_only = $power_bi_app_only || $app_only;
					}
				}
			}

			// Seeded once for all Power BI apps, so that a second app cannot lower the permissions a first app needs.
			if ( $power_bi ) {
				$seeds[] = array(
					'key'     => 'https://api.powerbi.com/',
					'boolVal' => $power_bi_app_only,
				);
			}

			foreach ( $seeds as $seed ) {
				$exists = false;

				foreach ( $allowed_endpoints as $allowed_endpoint ) {

					if ( ! empty( $allowed_endpoint['key'] ) && strcasecmp( $allowed_endpoint['key'], $seed['key'] ) === 0 ) {
						$exists = true;
						break;
					}
				}

				if ( ! $exists ) {
					$allowed_endpoints[] = $seed;
				}
			}

			Options_Service::add_update_option( 'graph_allowed_endpoints', $allowed_endpoints );

			$compat_warning = sprintf(
				'%s -> Because support for the option <em>Allow apps to request any Microsoft Graph endpoint</em> has been discontinued, WPO365 has added the destinations it could derive from your configuration to the list of <em>Allowed endpoints</em> on the plugin\'s <a href="#integration">Integration</a> configuration page. Please review that list: a destination that WPO365 could not derive - for example the SharePoint Home URL used by the <em>Content by Search</em> app - must be added manually, and each entry that does not need application-level permissions should remain unchecked.',
				__METHOD__
			);

			self::compat_warning( $compat_warning );
		}

		/**
		 * Adds, once, the upgrade actions that WPO_OVERRIDES_<blog id> records as done to the list in the
		 * database, together with upgrade_actions_from_db itself.
		 *
		 * @since   45.0
		 *
		 * @return  void
		 */
		private static function upgrade_actions_from_db() {
			$upgrade_actions = Options_Service::get_global_list_var( 'upgrade_actions' );

			if ( in_array( 'upgrade_actions_from_db', $upgrade_actions, true ) ) {
				return;
			}

			$wpo_overrides = Wp_Config_Service::get_options_overrides();

			if ( is_array( $wpo_overrides ) && isset( $wpo_overrides['upgrade_actions'] ) && is_array( $wpo_overrides['upgrade_actions'] ) ) {

				foreach ( $wpo_overrides['upgrade_actions'] as $upgrade_action ) {

					if ( is_string( $upgrade_action ) && ! in_array( $upgrade_action, $upgrade_actions, true ) ) {
						$upgrade_actions[] = $upgrade_action;
					}
				}
			}

			$upgrade_actions[] = 'upgrade_actions_from_db';
			Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
		}

		/**
		 * Copies, once, the license keys that WPO_OVERRIDES_<blog id> holds to the database. When the two
		 * differ, the wp-config.php key wins. License keys are never written to the log.
		 *
		 * @since   45.0
		 *
		 * @return  void
		 */
		private static function license_keys_from_db() {
			$upgrade_actions = Options_Service::get_global_list_var( 'upgrade_actions' );

			if ( in_array( 'license_keys_from_db', $upgrade_actions, true ) ) {
				return;
			}

			// License keys are stored network-wide, so a dedicated subsite leaves the migration to a network admin page load.
			if ( Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin() ) {
				return;
			}

			$wpo_overrides     = Wp_Config_Service::get_options_overrides();
			$license_key_names = array();

			if ( is_array( $wpo_overrides ) ) {

				foreach ( $wpo_overrides as $key => $value ) {

					if ( preg_match( '/^license_\d+$/', (string) $key ) !== 1 || ! is_string( $value ) ) {
						continue;
					}

					// Only the key counts, not the URL that a successful license check appends after the "|".
					$config_key = WordPress_Helpers::trim( explode( '|', $value )[0] );

					if ( $config_key === '' ) {
						continue;
					}

					$license_key_names[] = $key;
					$db_key              = WordPress_Helpers::trim( explode( '|', Options_Service::get_global_string_var( $key ) )[0] );

					if ( $db_key === $config_key ) {
						continue;
					}

					if ( $db_key !== '' ) {
						Log_Service::write_log(
							'WARN',
							sprintf(
								'%s -> The license key saved as %s in the database has been replaced by the license key found in WPO_OVERRIDES_%s',
								__METHOD__,
								$key,
								Wpmu_Helpers::get_options_blog_id()
							)
						);
					}

					Options_Service::add_update_option( $key, $value );
				}
			}

			if ( ! empty( $license_key_names ) ) {
				self::compat_warning(
					sprintf(
						'%1$s -> WPO365 now reads license keys from the database only and ignores the license keys in your site\'s wp-config.php file. The license keys found in WPO_OVERRIDES_%2$s (%3$s) are now saved in the database. Please remove these entries from WPO_OVERRIDES_%2$s and from now on manage your license keys on the <strong>WPO365 > Licenses</strong> page.',
						__METHOD__,
						Wpmu_Helpers::get_options_blog_id(),
						implode( ', ', $license_key_names )
					)
				);
			}

			$upgrade_actions[] = 'license_keys_from_db';
			Options_Service::add_update_option( 'upgrade_actions', $upgrade_actions );
		}
	}
}
