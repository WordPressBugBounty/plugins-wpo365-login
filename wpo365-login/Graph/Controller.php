<?php

namespace Wpo\Graph;

// Prevent public access to this script
defined('ABSPATH') or die();

use \Wpo\Core\Permissions_Helpers;
use \Wpo\Graph\Request;
use \Wpo\Services\Access_Token_Service;
use \Wpo\Services\Authentication_Service;
use \Wpo\Services\Log_Service;
use \Wpo\Services\Options_Service;

if (!class_exists('\Wpo\Graph\Controller')) {

    class Controller extends \WP_REST_Controller
    {

        /**
         * Register the routes for the objects of the controller.
         */
        public function register_routes()
        {

            $version = '1';
            $namespace = 'wpo365/v' . $version . '/graph';

            register_rest_route(
                $namespace,
                '/user',
                array(
                    array(
                        'methods' => \WP_REST_Server::READABLE,
                        'callback' => '\Wpo\Graph\Current_User::get_current_user',
                        'permission_callback' => array($this, 'check_permissions_current_user'),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/users',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/users');
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/myorganization',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/myorganization');
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/me',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/me');
                        },
                        'permission_callback' => array($this, 'check_permissions'),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/groups',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/groups');
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/drives',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/drives');
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/sites',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::get($request, '/sites');
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/proxy',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::proxy($request);
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions($request, true, true);
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/token',
                array(
                    array(
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => function ($request) {
                            return Request::token($request);
                        },
                        'permission_callback' => function ($request) {
                            return $this->check_permissions_get_token($request);
                        }
                    ),
                )
            );
        }

        /**
         * Checks if the app is allowed to access the API.
         * 
         * @param string $scope Scope for which the token must be valid.
         * @return bool|WP_Error True if user can retrieve an access token for the requested scope otherwise a WP_Error is returned.
         */
        public function check_permissions($request, $allow_application = false, $check_proxy = false)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
                return new \WP_Error('UnauthorizedException', 'The request cannot be validated.', array('status' => 401));
            }

            $body = $request->get_json_params();

            if ($check_proxy && !Options_Service::get_global_boolean_var('enable_graph_proxy')) {
                $url = !empty($body['url']) ? $body['url'] : '';
                return new \WP_Error('ForbiddenException', sprintf('This type of request is not allowed [proxy request for %s]. Go to WP Admin > WPO365 > Integration and \'Allow Microsoft Graph proxy-type requests\'.', $url), array('status' => 403));
            }

            // User signed in with Microsoft but the request is malformed
            if (empty($body) || !\is_array($body) || empty($body['scope'])) {
                return new \WP_Error('InvalidArgumentException', 'No (Microsoft Graph permission) scope has been defined. Possibly the request body is malformed or the request header did not define the Content-type as application/json.', array('status' => 400));
            }

            $wp_usr_id = \get_current_user_id();
            $graph_permission_level = self::get_graph_permission_level($body['scope']);

            if ($graph_permission_level == 'signedInWithMicrosoft') {

                $wpo_auth_value = get_user_meta($wp_usr_id, Authentication_Service::USR_META_WPO365_AUTH, true);

                // To get a delegated oauth token the user must have signed in with Microsoft
                if ($wp_usr_id === 0 || empty($wpo_auth_value)) {
                    return new \WP_Error('UnauthorizedException', 'Please sign in with Microsoft first before using this API.', array('status' => 401));
                }

                // If administrator configured a session duration then check if wpo_auth is expired
                if (Options_Service::get_global_numeric_var('session_duration') > 0) {
                    $wpo_auth = json_decode($wpo_auth_value);

                    if (!isset($wpo_auth->expiry) || $wpo_auth->expiry < time()) {
                        return new \WP_Error('UnauthorizedException', 'Your session is expired. Please sign in with Microsoft again before using this API.', array('status' => 401));
                    }
                }

                // User signed in with Microsoft
                return true;
            } elseif ($graph_permission_level == 'signedIn') {

                // User is not logged in
                if ($wp_usr_id === 0) {
                    return new \WP_Error('UnauthorizedException', 'Please sign in first before using this API.', array('status' => 401));
                }

                // User is logged in
                return true;
            } elseif ($graph_permission_level == 'anonymous') {

                // The app does not require a logged-in user
                return true;
            }

            return new \WP_Error('UnauthorizedException', 'The server received an unauthenticated request.', array('status' => 401));
        }

        /**
         * Checks if the app may retrieve a (delegated) oauth access token.
         * 
         * @since   17.0
         * 
         * @return  bool|WP_Error   True if user can retrieve an access token for the requested scope otherwise a WP_Error is returned.
         */
        public function check_permissions_get_token($request)
        {
            Log_Service::write_log('DEBUG', '##### -> ' . __METHOD__);

            if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
                return new \WP_Error('UnauthorizedException', 'The request cannot be validated.', array('status' => 401));
            }

            $body = $request->get_json_params();
            $wp_usr_id = \get_current_user_id();

            if (
                false == Options_Service::get_global_boolean_var('graph_allow_token_retrieval')
                && false == Options_Service::get_global_boolean_var('graph_allow_get_token')
            ) {
                return new \WP_Error('ForbiddenException', 'This type of request is not allowed [token request]. Go to WP Admin > WPO365 > Integration and allow \'Apps may request (delegated) oauth access tokens\'.', array('status' => 403));
            }

            $wpo_auth_value = get_user_meta($wp_usr_id, Authentication_Service::USR_META_WPO365_AUTH, true);

            // To get a delegated oauth token the user must have signed in with Microsoft
            if ($wp_usr_id === 0 || empty($wpo_auth_value)) {
                return new \WP_Error('UnauthorizedException', 'Please sign in with Microsoft first before using this API.', array('status' => 401));
            }

            // If administrator configured a session duration then check if wpo_auth is expired
            if (Options_Service::get_global_numeric_var('session_duration') > 0) {
                $wpo_auth = json_decode($wpo_auth_value);

                if (!isset($wpo_auth->expiry) || $wpo_auth->expiry < time()) {
                    return new \WP_Error('UnauthorizedException', 'Your session is expired. Please sign in with Microsoft again before using this API.', array('status' => 401));
                }
            }

            return true;
        }

        /**
         * Checks if a user is logged in.
         */
        public function check_permissions_current_user()
        {
            $wp_usr_id = \get_current_user_id();

            if ($wp_usr_id === 0) {
                return new \WP_Error('UnauthorizedException', 'Please sign in first before using this API.', array('status' => 401));
            }

            return true;
        }

        /**
         * Get the Graph Permission Level defined by the administrator and verifies it against the requested scope.
         * 
         * @since   17.0
         * 
         * @param   string      $scope      The scope requested by the app.
         * @return  string      The verified Graph Permission Level.
         */
        private static function get_graph_permission_level($scope)
        {
            $graph_permission_level = Options_Service::get_global_string_var('graph_permission_level');

            if (empty($graph_permission_level) || $graph_permission_level == 'signedInWithMicrosoft') {
                return 'signedInWithMicrosoft';
            }

            // The following scopes require a user who signed in with Microsoft
            if (true === Permissions_Helpers::must_use_delegate_access_for_scope($scope)) {
                return 'signedInWithMicrosoft';
            }

            return $graph_permission_level;
        }
    }
}
