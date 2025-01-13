=== WordPress + Microsoft Office 365 / Azure AD | LOGIN ===
Contributors: wpo365
Tags: Microsoft, SSO, PowerBI, Sharepoint, Email
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 34.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Swiss Army Knife to integrate WordPress + Microsoft | Entra | Azure | B2C | 365. For SSO, Mail, Roles, Access, Profiles, SharePoint, PowerBI and more

== Description ==

Enable users to sign in with their corporate or school account (Azure AD (B2C) / Entra (Ext.) ID) to access your WordPress website: No username or password required. But there is more!

= SINGLE SIGN-ON (SSO) =

- Enable Microsoft based Single Sign-on [more](https://www.wpo365.com/feature/single-sign-on/)
- Supported Identity Providers (IdPs): **Azure Active Directory**, **Azure AD B2C**, **Entra External ID (Azure AD for Customers)** [more](https://docs.wpo365.com/article/158-select-identity-provider-idp)
- Supported SSO protocols: **OpenID Connect** and **SAML 2.0** [more](https://docs.wpo365.com/article/159-select-sso-protocol)
- Supported OpenID Connect User Flows: Authorization Code User Flow (recommended) and Hybrid User Flow [more](https://docs.wpo365.com/article/156-why-the-authorization-code-user-flow-is-now-recommended)

= NEW USERS =

- New users that sign in with Microsoft automatically become WordPress users [more](https://www.wpo365.com/feature/single-sign-on/)

= INTRANET =

- Configure the **intranet** authentication mode to restrict access to all front-end posts and pages [more](https://www.wpo365.com/article/building-a-wordpress-based-intranet-restrict-access/)
- Hide the  **WordPress Admin Bar** for specific roles [more](https://docs.wpo365.com/article/150-hide-wp-admin-bar-for-roles)

= MICROSOFT TEAMS =

- Support for (seamless) integration of your WordPress website into a **Microsoft Teams** Tabs and Apps [more](https://www.wpo365.com/feature/microsoft-teams/)

= MAIL =

- **Send emails using Microsoft Graph** instead of SMTP from your WordPress website [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Choose between a Microsoft 365 account or a personal Microsoft account, like Hotmail.com or Outlook.com, to send WordPress emails
- Send as **HTML**
- Save to the **Sent Items** folder
- Support for **file attachments**

= WORDPRESS MULTISITE =

- Support for **WordPress Multisite** [more](https://www.wpo365.com/feature/wordpress-multisite/)

= POWER BI =

- Embed Microsoft **Power BI** content (user owns data) [more](https://www.wpo365.com/feature/power-bi-embed/)

= SHAREPOINT =

- Embed a **SharePoint Online** library [more](https://www.wpo365.com/feature/sharepoint-onedrive-library/)
- Embed a **SharePoint Online** list [more](https://www.wpo365.com/feature/sharepoint-list/)
- Embed an **Outlook / Exchange** calendar [more](https://www.wpo365.com/feature/outlook-exchange-calendar/)
- Embed a **SharePoint Online** search [more](https://www.wpo365.com/feature/sharepoint-search/)

= EMPLOYEE DIRECTORY =

- Embed an intuitve Azure AD / Microsoft Graph based **Employee Directory** into a front-end post or page [more](https://www.wpo365.com/feature/employee-directory/)

= REST API ENDPOINT PROTECTION =

- Protect your **WordPress REST API** endpoints with a combination of a WordPress cookie and a nonce for delegated access [more](https://docs.wpo365.com/article/151-wordpress-cookies-based-protection-for-the-wordpress-rest-api)

= DEVELOPERS =

- Developers can now connect to a RESTful API for Microsoft Graph in their favorite programming language and without the hassle of authentication and authorization [more](https://docs.wpo365.com/article/129-a-restful-proxy-to-microsoft-graph-inside-wordpress)
- *PHP hooks* for developers to build custom Microsoft Graph / Office 365 integrations [more](https://docs.wpo365.com/article/82-developer-hooks)

https://youtu.be/S9tiASl1nH0

**ADD FUNCTIONALITY WITH PREMIUM EXTENSIONS**

The features below can be unlocked with [premium addOns and bundles](https://www.wpo365.com/pricing/).

= SYNC =

- Full **User Sync** using MS Graph from Entra to WordPress [more](https://www.wpo365.com/feature/user-synchronization/)
- **Create** new WP Users
- **Update** existing WP Users
- (Soft) **Delete** existing WP Users

*WP User Roles, Profiles and Avatars will be updated and other rules e.g. LearnDash Enrollments will be applied*

= SCIM =

- Entra **User Provisioning** (SCIM) [more](https://www.wpo365.com/feature/azure-ad-user-provisioning-scim/)
- **Create** new WP Users
- **Update** existing WP Users
- (Soft) **Delete** existing WP Users

*WP User Roles, Profiles and Avatars will be updated and other rules e.g. LearnDash Enrollments will be applied*

= ROLES + ACCESS =

- Assign WordPress roles by Entra Groups, Entra User Attributes, Domains and / or App Roles [more](https://www.wpo365.com/feature/roles-access/)
- Restrict access to site / pages by Entra Groups, Domains and / or WPO365 Audiences [more](https://www.wpo365.com/feature/roles-access/)
- Redirect after login by Entra Groups and / or Domains [more](https://www.wpo365.com/feature/roles-access/)

= LEARNDASH =

- Auto-Enroll WP Users in LearnDash Courses and Groups by Entra Groups, Domains and / or Defaults [more](https://www.wpo365.com/feature/learndash/)

= CUSTOM USER FIELDS =
- Enhance WordPress / BuddyPress User Profiles with **Entra User Attributes** [more](https://www.wpo365.com/feature/custom-user-fields/)

= AVATAR =

- M365 Profile Picture as WordPress / BuddyPress Avatar [more](https://www.wpo365.com/feature/avatar/)

= MAIL =

- Auto-retry to deliver emails that failed to send [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Send attachments larger than 3MB  [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Send as / On behalf [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Send from a Shared Mailbox [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Enable Staging Mode [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Mail Throttle [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Send as BCC [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)
- Default Reply-To [more](https://www.wpo365.com/feature/send-mail-using-ms-graph/)

= MICROSOFT 365 APPS =

- Power BI [more](https://www.wpo365.com/feature/power-bi-embed/)
- SharePoint Library [more](https://www.wpo365.com/feature/sharepoint-onedrive-library/)
- SharePoint List [more](https://www.wpo365.com/feature/sharepoint-list/)
- SharePoint Search [more](https://www.wpo365.com/feature/sharepoint-search/)
- Exchange Calendar [more](https://www.wpo365.com/feature/outlook-exchange-calendar/)
- Viva Engage [more](https://www.wpo365.com/feature/yammer-for-wordpress/)
- Employee Directory [more](https://www.wpo365.com/feature/employee-directory/)

= ADVANCED LOGIN OPTIONS =

- Support for Multitenancy [more](https://www.wpo365.com/feature/advanced-login-options/)
- Support for multiple IdPs [more](https://www.wpo365.com/feature/multiple-identity-providers/)
- Force SSO [more](https://www.wpo365.com/feature/advanced-login-options/)
- Dual Login [more](https://www.wpo365.com/feature/advanced-login-options/)
- Intercept manual login [more](https://www.wpo365.com/feature/advanced-login-options/)
- Prevent pwd. / email change [more](https://www.wpo365.com/feature/advanced-login-options/)
- Single Sign-out [more](https://www.wpo365.com/feature/advanced-login-options/)
- Sign out of M365 [more](https://www.wpo365.com/feature/advanced-login-options/)
- Custom login URL [more](https://www.wpo365.com/feature/advanced-login-options/)
- Custom loading template [more](https://www.wpo365.com/feature/advanced-login-options/)
- B2C custom domain [more](https://www.wpo365.com/feature/advanced-login-options/)
- Embedded B2C login [more](https://www.wpo365.com/feature/advanced-login-options/)
- Custom new User email [more](https://www.wpo365.com/feature/advanced-login-options/)

= PROFILE+ =

- Update a WordPress user's first, last and full name, their mail address and their role, whenever they sign in with Microsoft

= REST API ENDPOINT PROTECTION =

- Enable **Azure AD** based protection for your **WordPress REST API** endpoints [more](https://docs.wpo365.com/article/147-azure-ad-based-protection-for-the-wordpress-rest-api)

= CONFIGURATION =

- Save multiple configurations
- Directly edit (the JSON representation of) a configuration

== Prerequisites ==

- Make sure that you have disabled caching for your Website, especially when you configure a WordPress based intranet and access to WP Admin and all pubished pages and posts requires authentication. With caching enabled, the plugin may not work as expected
- We have tested our plugin with Wordpress >= 5 and PHP >= 7.4
- You need to Entra ID Tenant Administrator to configure both Azure Active Directory and the plugin
- When configuring a WordPress based intranet, you should consider restricting access to the otherwise publicly available wp-content directory [more](https://docs.wpo365.com/article/36-authentication-scenario)

== Support ==

We will go to great length trying to support you if the plugin doesn't work as expected. Go to our [Support Page](https://www.wpo365.com/how-to-get-support/) to get in touch with us. We haven't been able to test our plugin in all endless possible Wordpress configurations and versions so we are keen to hear from you and happy to learn!

== Feedback ==

We are keen to hear from you so share your feedback with us and contact us using the [contact form](https://www.wpo365.com/contact/) on our website!

== Open Source ==

When you’re a developer and interested in the code you should have a look at our repo over at [WordPress](http://plugins.svn.wordpress.org/wpo365-login/).

== Installation ==

Please check out [our Getting Started page](https://docs.wpo365.com/category/21-getting-started) for detailed installation and configuration instructions.

== Frequently Asked Questions ==

Please check out [our online FAQs](https://docs.wpo365.com/category/26-support) for answers to commonly asked questions.

== Screenshots ==
1. Microsoft / Azure AD based Single Sign-on
2. Embedded Power BI for WordPress
3. Embedded SharePoint Online Documents for WordPress
4. Embedded SharePoint Online Search for WordPress
5. Employee Directory
6. Support for Azure AD B2B and Azure AD B2C
7. Sending WordPress email using Microsoft Graph
8. Synchronizing users from Azure AD to WordPress
9. Embed WordPress in a Teams Tab or App
10. Assign WordPress roles / Deny access based on Azure AD groups

== Upgrade Notice ==

Please check the [online change log](https://www.wpo365.com/change-log/) for upgrade notices.

== Changelog ==

Also available [online](https://www.wpo365.com/change-log/).

= v34.1 =
* Improvement: You can now choose between a Microsoft 365 account or a personal Microsoft account, like Hotmail.com or Outlook.com, to send WordPress emails. See the [updated tutorial](https://tutorials.wpo365.com/courses/email-configure-microsoft-graph-mailer/lessons/send-email-from-a-personal-microsoft-account-e-g-hotmail-com/) for details. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: Plugin no longer (falsely) reports an error when enabling the auto-retry mail-send functionality fails. Instead, it generates a warning with a more verbose description. [MAIL, PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: Allowlisting domains does now correctly denies access to domains not in the list. [ROLES + ACCESS, PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: Detection of the redirect_to parameter - for example added to the (default) login URL by other solutions - has been improved. [LOGIN]
* Fix: The plugin now properly URL encodes a user's UPN when checking for existing users in AAD B2C / Entra Ext. ID. [CUSTOMERS]
* Fix: The plugin now properly URL encodes a user's UPN when trying to submit email messages to Microsoft Graph. [LOGIN, MICROSOFT GRAPH MAILER]

= v34.0 =
* Breaking Change: Excluding a WP role from "WPO365 Audiences" (e.g. to ensure that the role in question sees all content without "WPO365 Audiences"-based restrictions) now takes the post type as an extra configuration parameter (so that - for example - a user with custom WP role "Wiki-Editor" can see all posts of custom post type "Wiki" but for all other post types configured restrictions will apply). The setting can only be changed if the version of the premium plugin providing the "WPO365 Audiences" logic is equal or higher than 34.0. Without updating the configuration, WPO365 will assume that the role-exclusion applies to all post types. Refer to the [new tutorial](https://tutorials.wpo365.com/courses/ra-use-wpo365-audiences-to-restrict-gate-access-to-content/) for further details. [ROLES + ACCESS, PRO, INTEGRATE (SYNC, INTRANET)]
* Change: The PHPSECLIB v3 library has been updated to the latest version 3.0.43 and any customizations have been abandonned. [LOGIN, MICROSOFT GRAPH MAILER]
* Change: The "WPO365 Audiences" Block Editor has been withdrawn and the only option to configure "WPO365 Audiences" at page-level is the Metabox, which is now always enabled. See [updated documentation](https://tutorials.wpo365.com/courses/ra-use-wpo365-audiences-to-restrict-gate-access-to-content/lessons/removed-wpo365-audiences-gutenberg-block/) for guidance. [ROLES + ACCESS, PRO, INTEGRATE (SYNC, INTRANET)]
* Improvement: You can now define a splash screen image URL as part of your Power BI Embed configuration and if defined, the app will show the image when Power BI is loading, effectively providing a white label experience. See the [updated documentation](https://docs.wpo365.com/article/84-microsoft-power-bi-for-wordpress) for details. [M365 APPS, INTEGRATE (INTRANET)]
* Improvement: A user's primary blog is set (to the contextual subsite) when WPO365 creates a new user when WordPress Multisite is enabled. [LOGIN]
* Improvement: Developers can now use the hook 'wpo365/oidc/params' to filter the parameters used to build the authorization URL. See the [updated documentation](https://docs.wpo365.com/article/82-developer-hooks) for details. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: The auto-retry functionality has been reworked to prevent emails from being sent twice. [MAIL, PRO, INTEGRATE (SYNC, INTEGRATE)]
* Fix: WPO365 will no longer send an out-of-the-box "new user email notification" if a new user is created by WPO365 during WPO365 User Synchronization. [INTEGRATE (SYNC, INTRANET)]
* Fix: Sending a test email from the "Mail" configuration page will no longer delete all cached (user) access tokens. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: The "Default role" setting is now also unlocked when the WPO365 | MAIL premium plugin is detected. [MAIL]
* Fix: Various issues when using SharePoint Online Search to search for employees have been fixed. [M365 APPS, INTEGRATE (INTRANET)]
* Fix: WPO365 now requires Microsoft Graph > Delegated Permissions > Calendar.Read permissions to test the configuration to embed an Exchange / Outlook calendar in WordPress (instead of Users.Read.All). [LOGIN, M365 APPS, INTEGRATE (INTRANET)]
* Fix: When using a complex query to retrieve - for example - a user's manager's Display Name from Microsoft Graph as follows "::graph:manager.displayName", WPO365 no longer incorrectly populates it with the current user's display name if the current user does not have a manager defined. [INTEGRATE (SYNC, INTRANET)]

= v33.3 =
* Fix: After updating to WordPress 6.7 an error "Notice: Function _load_textdomain_just_in_time was called incorrectly" would be thrown. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: A CSS "button" selector affected the global styling of button elements. [LOGIN, APPS, INTEGRATE (INTRANET)]
* Improvement: Developers can now use the hook 'wpo365/aad/params' to filter the parameters used to build the token request URL. See the [updated documentation](https://docs.wpo365.com/article/82-developer-hooks) for details. [LOGIN, MICROSOFT GRAPH MAILER]

= v33.2 =
* Improvement: A new filter "wpo365/user/user_login" to customize a user's WP username has been added to allow developers to apply their custom logic. Consult the updated [online documentation](https://docs.wpo365.com/article/82-developer-hooks). [LOGIN]
* Fix: Token expiration in Power BI has been improved and - among other things - in the instance of an "TokenExpired" client error, WPO365 will reload the window. [LOGIN]
* Fix: WPO365 is now better able to handle a situation where multiple apps to embed various Microsoft 365 services - e.g. a SharePoint Library, a Viva Engage Feed and an Exchnage Calendar - have been placed on the same page. [APPS, INTEGRATE (INTRANET)]
* Fix: When the calendar does not detect the start date column it will render an error message. [APPS, INTEGRATE (INTRANET)]
* Fix: For new installations, WPO365 will audiences will automatically enable the use of metaboxes (as opposed to using a Gutenberg Block). [ROLES + ACCESS, PROFESSIONAL, INTEGRATE, CUSTOMERS (SYNC, INTRANET)]
* Fix: The version number of the SCIM addon (plugin) has been fixed. [SCIM]
* Fix: When a new user is created by any other solution than WPO365, the default WordPress email(s) to the user and the administrator will not be blocked. [LOGIN]
* Fix: When a WPO365 User Synchronization Job has fisnished, it will show a green "stopped" badge to indicate success. [INTEGRATE (SYNC)]
* Fix: Copying a shortcode to embed "Power BI" content in the free version WPO365 | LOGIN has been fixed. [LOGIN]
* Fix: The WPO365 | DOCUMENTS Gutenberg block is now backward compatible with older configurations that do not specify the "Name" column as sortable. [DOCUMENTS, APPS, INTEGRATE (INTRANET)]

= v33.1 =
* Fix: WPO365 will now consistently redirect users (again) to their final destination URL, preventing them from being sent back to the login page. [LOGIN]
* Fix: WPO365 now checks (again) whether the user's final destination URL matches the scheme of the registered application's Redirect URI in Entra and if needed corrects this. [LOGIN]

= v33.0 =
* Breaking Change: Previously users (of a WordPress Multisite / WPO365 "Shared" WPMU Mode) attempting to access a (sub) site that they are not a member of, would be denied access. Starting with this version, those users will either be sent to their "primary" site instead, or - if a primary site cannot be determined - to their global user dashboard URL. [LOGIN]
* Breaking Change: Starting with version 33.0, WPO365 | LOGIN can redirect users to Microsoft faster (using a server-side redirect). This is generally recommended to avoid issues with server-side / external caching services. The JavaScript file "pintra-redirect.js" will therefore no longer be automatically enqueued on every page request. To mitigate the impact of this change on existing configurations, administators must manually update the WPO365 configuration and uncheck the option "Use client-side redirect" on the plugin's "Login / Logout" configuration page, unless the WordPress site is integrated in Microsoft Teams, uses a custom "Sign in with Microsoft" login button or the administrator wishes to briefly display a "loading" icon when the user is redirected. See the [online documentation](https://docs.wpo365.com/article/223-use-client-side-redirect) for details. [LOGIN]
* New Feature: The "Sign in with Microsoft" button that is displayed on the (default) WordPress login page can now be customized on the plugin's "Login / Logout" configuration page. That same button can also be placed on any WordPress post or page using the new shortcode "wpo365-sso-button". See the [online documentation](https://docs.wpo365.com/article/224-add-single-sign-on-button-anywhere-shortcode) for instructions. [LOGIN, ESSENTIALS, PROFESSIONAL, INTEGRATE, CUSTOMERS (LOGIN+, SYNC, INTRANET)]
* Improvement: WPO365 now uses built-in WordPress logic to help ensure that the URL where users are being redirected to - after they successfully signed in with Microsoft - is safe. [LOGIN]
* Fix: Fixed an issue whereby WPO365 would require a user to sign in with Microsoft when that user attempted to access a password-protected page when the administrator enforced SSO for the default / custom login page. [ESSENTIALS, PROFESSIONAL, INTEGRATE, CUSTOMERS (LOGIN+, SYNC, INTRANET)]

= v32.0 =
* Breaking Change: This version of WPO365 adds support for WordPress' built-in "login_redirect" filter. This means that the URL where users are redirected after they successfully sign in to your WordPress website, can be set by a third-party plugin e.g. "LoginWP". Please note that rules defined in WPO365 to redirect a user (e.g. "Welcome page for first-time users", "Always send user to default / custom landing page" and "Azure AD group-based redirect after successful login") will be applied after the "login_redirect" has been applied and therefore overrule the filtered result. [LOGIN]
* Breaking Change: If an administrator activated the option to "Force SSO for the default / custom login page", WPO365 will now redirect all requests to Microsoft for authentication, unless a unique cookie is presented. This cookie will be set by WPO365 when a user requests the default / custom landing page with the correct "Secret key to bypass SSO" added to the URL. Brute-force password-guessing bots should now be blocked from submitting login attempts to your website’s login endpoint. [ESSENTIALS, PROFESSIONAL, INTEGRATE (LOGIN+, SYNC, INTRANET)]
* Improvement: The built-in license and update manager has been greatly simplified and algined with WordPress' plugin managent. [ALL PREMIUM]
* Fix: WPO365's shutdown routine will now run at the very last possible moment to ensure that the built-in Microsoft Graph Mailer can still access its configuration and send emails, e.g. third-party plugins such as "WP Job Manager" sending out alerts. [MAIL, PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: Administrators can now still change a user's local WordPress password, even if the option "User cannot change password" (on the plugin's "User Registration" configuration page) has been activated. [ESSENTIALS, PROFESSIONAL, INTEGRATE (LOGIN+, SYNC INTRANET)]
* Fix: The "Plugin self-test" no longer fails when an administrator has configured multiple SAML 2.0 Identity Providers. [ALL PREMIUM]
* Fix: WPO365 has restored the ability to save user attributes from a user's manager e.g. the manager's email address as WordPress metadata for the user in question. [PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: The ability to define a default sorting of a column (ascending or descending) of an embedded SharePoint Library or List has been restored. [DOCUMENTS, APPS, INTEGRATE (INTRANET)]
* Fix: Direct reports of users listed in the Employee Directory app are filtered to ensure that disabled users are not selected. [APPS, INTEGRATE (INTRANET)]

= v31.1 =

* Improvement: The SCIM messages sent by Entra's User Provisioning Service are now logged and can be viewed via WP Admin > WPO365 > Dashboard > Insights > Users. See the [new tutorial step](https://tutorials.wpo365.com/courses/sync-entra-user-provisioning-scim/lessons/view-scim-messages-received-from-entra/) for details. [SCIM, INTEGRATE]
* Fix: Undefined variable $custom_field_not_found [ALL PREMIUM]

= v31.0 =

* Breaking Change: WPO365 is now able to save user attributes from any source (claims in an ID token and SAML 2.0 response, properties received from Microsoft Graph and Entra Provisioning (SCIM)) but the administrator needs to update the corresponding mappings with a prefix, or else WPO365 will not update the WP user meta record when the attribute is updated with an empty value. Refer to the [updated online documentation](https://docs.wpo365.com/article/98-synchronize-microsoft-365-azure-ad-profile-fields) for details. [CUSTOM USER FIELDS, PROFESSIONAL, INTEGRATE (LOGIN+, SYNC, INTRANET)]
* Improvement: WPO365 is now able to process the SAML 2.0 groups claim and apply all ROLES + ACCESS functionality e.g. WPO365 Audiences, restrict access, dynamically assign WordPress roles based on Entra Group Memberships. [ROLES + ACCESS, PROFESSIONAL, INTEGRATE, CUSTOMERS (SYNC, INTRANET)]
* Improvement: WPO365 will now also check if it needs to update a user's WP role(s) based on user attributes it receives from Entra's SCIM based User Provisioning Service. [ROLES + ACCESS, PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Improvement: An administrator can now configure WPO365 to redirect the user the website's backend when initiating the "Sign in with Microsoft" flow. See [online documentation](https://docs.wpo365.com/article/219-use-admin-url-to-initiate-authentication) for details. [LOGIN]
* Improvement: Once Entra (User) Provisioning via SCIM is enabled, administrators can specify a SCIM attribute for WPO365 to use as the WordPress username for new users. [SCIM, INTEGRATE (INTRANET)]
* Improvement: An administrator can specify one or more IP addresses that WPO365 should bypass for authentication. [ROLES + ACCESS, SCIM, ESSENTIALS, PROFESSIONAL, CUSTOMERS, INTEGRATE (LOGIN+, SYNC, INTRANET)]
* Fix: WPO365 will not try to retrieve a user's Entra Group Memberships if this information has already been included in the ID token or SAML response. [ROLES + ACCESS, PROFESSIONAL, CUSTOMERS, INTEGRATE (SYNC, INTRANET)]
* Fix: Added a "Close" button to the config-test apps when embedding a SharePoint list / library or Outlook / Exchange calendar in WordPress. [LOGIN]
* Fix: The "Recent documents" view stopped working after column-sorting had been implemented for the apps that embed a SharePoint Library and List. [LOGIN, APPS, INTEGRATE (INTRANET)]
* Fix: "Logout from Microsoft" is able to deal with multiple Identity Providers of different tenant types. [ALL PREMIUM]
* Fix: Column definition for apps that embed a SharePoint Library and List now require "isSortable" (instead of "sortable") set as true (following the documentation). [APPS, INTEGRATE (INTRANET)]
* Fix: Features unlocked by the CUSTOMERS bundle now include ROLES + ACCESS and AVATAR. [CUSTOMERS]
* Fix: Features unlocked by the PROFESSIONAL bundle now include AVATAR. [PROFESSIONAL]
* Fix: Special characters - for example ö and é - in SAML claim values are no longer encoded as HTML entities (e.g. &ouml;). [LOGIN]
* Fix: The option to skip authentication for REST API requests when a BASIC auth header is present has been removed. Instead an administrator should add REST API's endpoint to the "List of pages freed from authentication" on the plugin's "Single Sign-on" page. [ESSENTIALS, LOGIN+, PROFESSIONAL, CUSTOMERS, INTEGRATE, (SYNC, INTRANET)]
* Fix: A warning is shown when the secret key to bypass SSO contains non-alphnumeric characters. [ESSENTIALS, PROFESSIONAL, CUSTOMERS, INTEGRATE (LOGIN+, SYNC, INTRANET)]
* Fix: WPO365 is now be able to handle a site relative URL for the redirect_to parameter upon login. [LOGIN]

= v30.2 =

* Fix: When the SAML 2.0 certificate is invalid or expired, the plugin will now attempt to read the X.509 certificate from the "IDPSSODescriptor" XML node (provided that the administrator has entered a valid "App Metadata Federation" URL). [LOGIN]

= v30.1 =

* Fix: The login_hint parameter for the URL created by WPO365 to send a user to Microsoft to authenticate in case of (Entra) External ID and Azure AD B2C will now be correctly set. [LOGIN]
* Fix: A regression that caused WPO365 to send a user always to the custom error page (instead of the default one) - even if that option was not configuration - has now been fixed. [ESSENTIALS, ALL BUNDLES]

= v30.0 =

* BREAKING CHANGE: It is now possible to configure support for multiple Identity Providers for different tenant types (regular Entra ID, Azure AD B2C and Entra External ID). (read more)[https://www.wpo365.com/news/breaking-change-affecting-wp-config-php-based-identity-provider-idp-configurations/] and (more)[https://docs.wpo365.com/article/137-use-wp-config-for-aad-secrets] [ALL PREMIUM]
* Improvement: An SharePoint Library or List that is embedded in WordPress can now be sorted beforehand or when the user clicks on the column header. [DOCUMENTS, M365 APPS, INTEGRATE (INTRANET)]
* Improvement: The HelpScout beacon on the plugin's configuration pages would be blocked from loading - for example when using Microsoft Edge - and has therefore been replaced with a new help button that opens the WPO365 Contact Form instead. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: The new bundles PROFESSIONAL and INTEGRATE no longer cause a critical error if WPO365 | LOGIN has not been installed / activated prior to activation. [PROFESSIONAL, INTEGRATE]
* Fix: The default value for the redirect URL now again corresponds to the site's home URL. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: Some WP Cron Jobs that rely on a custom cron schedule "wpo-every-minute" e.g. Auto-Retry for sending emails and User Sync Monitor to ensure user synchronization keeps running, should no longer be removed when the custom schedule is not found. [MAIL, PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: Mail Log Viewer will show no results if a filter e.g. Errors returns no results. [MAIL, PROFESSIONAL, CUSTOMERS, INTEGRATE (SYNC, INTRANET)]
* Fix: Changed the log level of a number of avatar related issues e.g. when a profile picture for a user was not found from warning to debug. [PROFESSIONAL, INTEGRATE (SYNC, INTRANET)]
* Fix: The login-message shortcode and the login-button shortcode are now correctly initialized for the new PROFESSIONAL and INTEGRATE bundles. [PROFESSIONAL, INTEGRATE]
* Fix: If the administrator has configured a custom error / logged-out page then WPO365 will also ensure user is redirected to that page when they sign out of WordPress using the default sign-out option(s). [PROFESSIONAL, INTEGRATE (LOGIN+, SYNC, INTRANET)]

= v29.0 =
* Support for new [WPO365 feature bundles](https://www.wpo365.com/news/its-not-just-a-change-its-a-leap-forward).

= v28.2 =
* Fix: WPO365 will now correctly "ignore" a SAML response when the Relay State is not a properly formatted URL. [LOGIN]

= v28.1 =
* Improvement: The Mail Audit Log Viewer has been updated to show nr. of attempts and time of last attempt for a better general understanding of the send-status of the email in question. [MAIL, CUSTOMERS, SYNC, INTRANET]
* Improvement: The Debug Log entries now display timestamps in the WordPress timezone (see WP Admin > Settings > General > Timezone). [LOGIN, MICROSOFT GRAPH MAILER]
* Improvement: The Mail Audit Log entries now display timestamps in the WordPress timezone (see WP Admin > Settings > General > Timezone). [LOGIN, MICROSOFT GRAPH MAILER]
* Improvement: The WPO365 Insights entries now display timestamps in the WordPress timezone (see WP Admin > Settings > General > Timezone). [LOGIN, MICROSOFT GRAPH MAILER]
* Improvement: A small icon on the plugin's Mail configuration page will show the status of the "Resending failed emails automatically" feature. [MAIL, CUSTOMERS, SYNC, INTRANET]
* Improvement: The default WP role update scenario has been updated from "Add" to "Skip" and the plugin's "User Registration" configuration has been update accordingly. [LOGIN]
* Improvement: The Microsoft Graph Mailer for WordPress will not be instantiated if no authorization information can be found. [LOGIN, MICROSOFT GRAPH MAILER]
* Improvement: The Mail Authorization Status Popup will now appear only after 4 seconds and will no longer show if authorization is under way. [LOGIN, MICROSOFT GRAPH MAILER]
* Improvement: Administrators can now also auto-enroll users into LearnDash courses and auto-assign users to LearnDash Groups based on (login) domains. [ROLES + ACCESS, SYNC, INTRANET]
* Improvement: Administrators can now disable SSO for WP Admin. A warning will show if this new option conflicts with other options such as "Dual Login" and "Force SSO for the login page". [LOGIN+, CUSTOMERS, SYNC, INTRANET]
* Fix: WordPress no longer shows that an update for a premium addon or bundle is available when the latest version is already installed. [ALL PREMIUM]
* Fix: WPO365 now correctly replaces the WP Avatar with the user's Entra / Microsoft 365 Profile Picture when BuddyBoss has been installed / enabled. [AVATAR, SYNC, INTRANET]
* Fix: The self-test would fail if the administrator had enabled the Proof Key for Code Exchange. [LOGIN+, CUSTOMERS, SYNC, INTRANET]
* Fix: The recently added Mail Audit Log Retention Policy (to clean up entries older than 90 days) no longer fails if an older version of WPO365 | MICROSOFT GRAPH MAILER or WPO365 | LOGIN would be installed in combination with the latest version of the WPO365 | MAIL addon. [MAIL, CUSTOMERS, SYNC, INTRANET]
* Fix: WPO365 no longer tries to process an OpenID Connect response if SAML 2.0 based SSO is configured. [LOGIN]
* Fix: The shortcode configurator to embed a SharePoint List or Library now warns if the wrong Microsoft Graph version is selected on the plugin's "Integration" configuration page. [LOGIN, M365 APPS]
* Fix: WPO365 User Sync will now include the low-level DB error message if an error occurs when logging the results to the database. [CUSTOMERS, SYNC, INTRANET]
* Fix: The WPO365 configuration pages will now show the correct values for Entra ID / AAD related options retrieved from wp-config.php (instead of from the database). [ALL PREMIUM]
* Fix: The Mail Audit Log will now create a new table at the correct "level" in case WordPress Multisite would be activated and WPO365's default support mode for WPMU (= Shared) is configured. [MAIL, CUSTOMERS, SYNC, INTRANET]
* Fix: WPO365 will now only attempt to retrieve a User Resource from Microsoft Graph when the administrator explicitly configured "Microsoft Graph" as the desired "Source for custom user fields" on the plugin's "User Sync" configuration page. [LOGIN+, CUSTOMERS, SYNC, INTRANET]
* Fix: The Redirect URI for the WPO365 Microsoft Graph Mailer no longer indicates an error for the Redirect URI migrated from "Mail Integration for Office 365 / Outlook" plugin. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: The WP Avatar no longer shows a broken picture link when the Avatar feature is enabled but WPO365 fails to retrieve the user's profile photo from Microsoft Graph. [AVATAR, SYNC, INTRANET]

= v28.0 =
* Patched vulnerability (CVE-2024-4706): Validation of the script URL - used to embed Microsoft 365 services in WordPress - is now validated to ensure it points to a resource on the local WordPress server. [ALL]
* Breaking Change (Microsoft Graph Mailer): WPO365 [retains **mail log** entries](https://docs.wpo365.com/article/217-mail-log-retention) that are less than approximately 90 days old and deletes entries that exceed the configured number of days. [MAIL]
* Breaking Change (WordPress Multisite): Profile pictures for *WordPress Avatars* and downloaded from Microsoft Graph will always be saved in /wp-content/uploads/wpo365/profile-images instead of /wp-content/uploads/sites/[blog_id]/wpo365/profile-images. [AVATAR, SYNC, INTRANET]
* Improvement: In an attempt to better understand errors that involve *cURL*, administrators can now enable [verbose logging for cURL](https://docs.wpo365.com/article/214-enable-curl-verbose). [ALL]
* Improvement: The *Allowed (login) domains* list can now be changed into a [list of domains that are not allowed to sign in](https://docs.wpo365.com/article/43-domain-whitelist). This is especially useful for administrators that allow users from any Microsoft Entra ID / AAD tenant to sign in to their WordPress website. [LOGIN+, SYNC, INTRANET]
* Improvement: Administrators can now configure WPO365 to [add new or existing users to all subsites in a WordPress Multisite Network](https://docs.wpo365.com/article/215-always-add-all-users-to-all-wpmu-subsites) when they sign in with Microsoft or when their data is synchronized. Additionally, all existing users can be added a new subsite, when it is first initialized. [LOGIN+, SYNC, INTRANET]
* Improvement: A monitor (in the form of a WP Cron Job) for [WPO365 User Synchronization](https://www.wpo365.com/feature/user-synchronization/) will be started automatically (each time a new user synchronization starts) and will check every 5 minutes for unfinished synchronization jobs for which no WP Cron Job (to process the next batch of users) exists and re-create this job if needed. [SYNC, INTRANET]
* Improvement: If WPO365 is used to [integrate WordPress with Azure AD B2C](https://www.wpo365.com/feature/azure-ad-b2c/) and the administrator has configured WPO365 to create users in Azure AD B2C from WordPress, the status of this upstream-synchronization will now also show on a user's profile page. [CUSTOMERS, SYNC, INTRANET]
* Improvement: If enabled, [WPO365 Audiences](https://www.wpo365.com/feature/audiences/) will now be shown for each post and / or page on WordPress pages, listing all posts and pages. [ROLES + ACCESS, SYNC, INTRANET]
* Improvement: The response - when a non-logged-in user requests a post or a page that is restricted by a WPO365 Audience - is now streamlined with the option [Response for visitors requesting a page that requires a logged-in user](https://docs.wpo365.com/article/197-post-types-that-require-a-logged-in-user). [ROLES + ACCESS, SYNC, INTRANET]
* Improvement: The *Admin Credential > Secret Token* that is used for [Entra ID (AAD) User provisioning (SCIM) for WordPress](https://docs.wpo365.com/article/59-wordpress-user-provisioning-with-azure-ad-scim) can now be administered on the plugin's *User Sync* configuration page. [SCIM, INTRANET]
* Improvement: WPO365 now supports [Custom URL Domains for Microsoft Entra (Ext.) ID](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-custom-url-domain). [LOGIN+, SYNC, INTRANET]
* Improvement: If activated, WPO365 will terminate the loading of WordPress, whenever it identifies a login attempt (with local WordPress credentials) by a user whose username is not included in the [WPO_ADMINS list](https://docs.wpo365.com/article/174-restrict-access-to-the-wpo365-configuration-pages). See the [online documentation](https://docs.wpo365.com/article/216-only-wpoadmins-can-sign-in-locally) for details. [ALL]
* Improvement: The title for the *Office 365 Profile Information* section on a user's profile (only visible if the administrator enabled the option to [Show Azure AD user attributes in a WordPress user profile](https://docs.wpo365.com/article/98-synchronize-microsoft-365-azure-ad-profile-fields)) can now be translated (go to WP Admin > WPO365 > ... > Translations). [CUSTOM USER FIELDS, LOGIN+, SYNC, PREMIUM]
* Improvement: Administrators of a WordPress Multisite installation with dedicated mode enabled (so that subsites can be configured independently of each other) can now go to the plugins *Import / Export* configuration for a subsite to replace the (empty) configuration of the subsite with a copy of the central WPO365 configuration template. See the [updated documentation](https://docs.wpo365.com/article/29-support-for-wordpress-multisite-wpmu) for details. [ALL]
* Preview: Administrators of GCCH tenants can now select this type of tenant from the list of Identity Providers, in order to change the TLD for all relevant Microsoft endpoints to ".us" (instead of ".com"). [ALL]
* Fix: Translations for the Employee Directory app now correctly handle special characters (however, it may be necessary to recreate the shortcode). [ALL]
* Fix: The premium WPO365 | MAIL option to resend failed emails automatically can now be started when the premium addon is used in combination with WPO365 | MICROSOFT GRAPH MAILER. [MICROSOFT GRAPH MAILER]

= v27.2 =
* Improvement: The lis of "Optional SCIM attribute mappings" on the plugin's "User Sync" configuration page has been deprecated. Administrators that have support for SCIM based Azure AD User provisioning enabled, are urged to migrate these mappings to the list "SCIM attribute to WordPress user meta mappings" in the section "Custom User Fields" using the corresponding "Migrate optional SCIM attribute mappings" button. [SCIM, INTRANET]
* Fix: Some "SCIM attribute to WordPress user meta mappings" e.g. "emails[type eq "work"].value" were only processed by WPO365 internally e.g. to update a user's WordPress profile. With this change, these attributes can now also be mapped to WordPress user meta. [SCIM, INTRANET]
* Fix: An administrator now can (and should) - besides the ID token claim - also specify the corresponding AAD user property (and SCIM claim, if support for SCIM based Azure AD User provisioning has been enabled) that WPO365 should use for a new WordPress user's username. This only concerns those administrators, who configured a custom claim as the username of a new WordPress user (on the plugin's "User registraton" configuration page). [(LOGIN+), CUSTOMERS, SCIM, SYNC, SCIM]
* Fix: By fixing a caching issue, WPO365 should - after this update - no longer show a notification that "There is a new version of [...] available [...]" for WPO365 premium addons and bundles, after those were updated to the lastest version. [ALL PREMIUM ADDONS / BUNDLES]

= v27.1 =
* Fix: "Strict Mode" for the Redirect URI can now also be enabled for the WPO365 | MICROSOFT GRAPH MAILER plugin (so it will only try process an Oauth response / payload detected at the exact URL which must be a path below the site's home address e.g. /oidc-auth/). [MICROSOFT GRAPH MAILER]
* Fix: The plugin will not try and process an Oauth response / payload if both features SSO and MICROSOFT GRAPH MAILER are disabled or if SSO is disabled but MICROSOFT GRAPH MAILER is enabled and but the administrator did not start an attempt to authorize an account to send emails from. [LOGIN, MICROSOFT GRAPH MAILER]
* Fix: WPO365 Health Messages are now correctly displayed on the corresponding panel for the MICROSOFT GRAPH MAILER plugin.
* Fix: A cached Authorization Code will now be correctly removed from cache after it has been redeemed. [LOGIN]
* Fix: A user's UPN is now correctly escaped before inserting it into the WPO365 User Synchronization database table (to support UPNs with single quotes). [SYNC, INTRANET]

= v27.0 =
* Breaking Change: HTML and CSS for the default **login-button** has changed slightly and the wrapper is now a flex-box, to allow for an additional drop-down list in case the administrator configured [multiple Identity Providers](https://tutorials.wpo365.com/courses/wp-config-php-multiple-identity-providers-idps/). An administrator, however, can revert this change and configure WPO365 to use the old login-button template (see the corresponding option on the plugin's *Miscellaneous* configuration page). [LOGIN]
* Breaking Change: To support devOps workflows and site replication scenarios, WPO365 now automatically detects named constants in your website's wp-config.php file that either configure an [single Identity Provider (IdP)](https://tutorials.wpo365.com/courses/wp-config-php-single-identity-provider-idp/) or any of the [WPO365 settings](https://tutorials.wpo365.com/courses/wp-config-php-configuration-w-o-idps/) that are not directly related to an IdP. As a result, the option **Use WP-Config.php for AAD secrets** has been renamed to [Obfuscate AAD options](https://docs.wpo365.com/article/137-use-wp-config-for-aad-secrets) and the option **Use WP-Config.php to override (some) config options** has been removed. [ANY PREMIUM ADDON / BUNDLE]
* Breaking Change: LearnDash enrollment rules are now also applied to existing users (when they sign in or when users are synchronized). [ROLES + ACCESS, SYNC, INTRANET]
* Feature (preview): Administrators can now configure WPO365 to support multiple Identity Providers (IdP). If multiple IdPs have been configured, WPO365 will - by default - render a dropdown list enumerating IdPs by their "friendly name". A user simply picks an IdP from the list before clicking "Sign in with Microsoft". Refer to the new [tutorial](https://tutorials.wpo365.com/courses/wp-config-php-multiple-identity-providers-idps/) for further details. [ANY PREMIUM ADDON / BUNDLE]
* Feature (preview): Now administrators can enable **WPO365 Insights** and aggregate various events into straightforward management dashboards. These dashboards are designed to offer valuable insights, such as tracking the count of users who have authenticated successfully or unsuccessfully, monitoring emails that have been sent successfully or unsuccessfully, and overseeing the synchronization status of users, whether through SCIM, WPO365 User synchronization, or during their initial sign-in. See the new [online guide](https://www.wpo365.com/feature/wpo365-insights/) for further details. [ALL]
* Feature (preview): Administrators can now add **app roles** to an *App registration* in *Microsoft Entra Admin Center* and use them to dynamically assign WordPress roles to users. See the [online documentation](https://www.wpo365.com/feature/roles-access/) for further details. [ROLES + ACCESS, SYNC, INTRANET]
* Feature (preview): WPO365 now also supports the SAML 2.0 protocol for use with Azure AD's **multi-tenancy feature**. See the [online documentation](https://docs.wpo365.com/article/211-support-for-multitenant-apps-saml-2-0) for further details. [LOGIN+, SYNC, INTRANET]
* Improvement: WPO365 can now be configured to skip saving the default WP **avatar** for a user without a profile picture. See the [online documentation](https://docs.wpo365.com/article/212-do-not-save-default-wp-avatar) for further details. [AVATAR, SYNC, INTRANET]
* Improvement: An administrator can now choose between the WordPress site URL or the WP Admin URL as the **default landing page** after a user successfully signed in with Microsoft. Alternatively, a custom URL can be defined when the LOGIN+ addon, or the SYNC or INTRANET is detected. [LOGIN, LOGIN+, SYNC, INTRANET]
* Improvement: When a SAML 2.0 **X509 certificate** is missing from the configuration, is expired or has been withdrawn, WPO365 will try and read the tenant's federation metadata to obtain (and cache) a new signing key. [LOGIN]
* Improvement: **WPO365 Health Messages** will no longer be displayed on a default WordPress notification banner, but instead a dismissable panel will slide over the configuration app. [LOGIN]
* Improvement: After running the **Plugin self-test** for SAML 2.0 based SSO, the raw SAML response can now be viewed by clicking the corresponding link for the "SAML response has been processed and no errors occurred" test case. [LOGIN]
* Improvement: Generated **passwords** are checked to ensure that the generated password has characters from all four possible categories (lower and upper case, numbers and symbols). [LOGIN]
* Improvement: When deleting a WPO365 configuration, several caches e.g. for access tokens and certificates, are cleaned as well. [LOGIN]
* Improvement: WPO365 will now update BuddyPress profile fields (provided that this option is enabled) whenever Azure AD Provisioning (SCIM) sends new / updated user attributes. [SCIM, INTRANET]
* Fix: **Audiences** now work correctly if a user is a member of one Audience but not of all when more than one Audience has been added to a page. [ROLES + ACCESS, SYNC, INTRANET]
* Fix: **User synchronization** of users with an apostrophe in their username now no longer generates an error when being logged into the database table. [SYNC, INTRANET]
* Fix: **Auth.-Only** scenarios are now compatible with the **Audiences** feature to make a page private (restricting access exclusively to users who are authenticated). [ROLES + ACCESS, SYNC, INTRANET]
* Fix: WPO365 will not send the user into an infinite loop anymore, if the administrator has enabled "strict mode" for the Redirect URI plus checked the option to use wp-config.php for AAD secrets. [ALL PREMIUM]
* Fix: WPO365 now checks for before "Trying to create a duplicate log entry" during user synchronization and will update the existing log record instead. [SYNC, INTRANET]

= v26.0 =
* Feature: Embed an Outlook / Exchange Calendar in WordPress. See [online documentation](https://www.wpo365.com/feature/outlook-exchange-calendar/) for details. [LOGIN, APPS, INTRANET]
* Feature: Embed a SharePoint Online List in WordPress. See [online documentation](https://www.wpo365.com/feature/sharepoint-list/) for details. [LOGIN, APPS, INTRANET]
* Fix: The plugin attempted to process any POST request with parameter "error", mistakenly assuming that it would be an authentication-error sent by Microsoft. [LOGIN, MICROSOFT GRAPH MAILER]
* Version bumped. [ALL]

= Older versions =

Please check the [online change log](https://www.wpo365.com/change-log/) for previous changelogs.
