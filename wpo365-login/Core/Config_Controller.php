<?php

    namespace Wpo\Core;
        
    // Prevent public access to this script
    defined( 'ABSPATH' ) or die();

    use \Wpo\Core\Permissions_Helpers;
    use \Wpo\Core\Config_Endpoints;
    use \Wpo\Services\Log_Service;
    use \Wpo\Services\Options_Service;

    if( !class_exists( '\Wpo\Core\Config_Controller' ) ) {

        class Config_Controller extends \WP_REST_Controller { 

            /**
             * Register the routes for the objects of the controller.
             */
            public function register_routes() {

                $version = '1';
                $namespace = 'wpo365/v' . $version;

                register_rest_route( $namespace, '/users/search/unique', 
                    array(
                        array(
                            'methods' => \WP_REST_Server::CREATABLE,
                            'callback' => function ( $request ) { 
                                return Config_Endpoints::users_search_unique( $request ); 
                            },
                            'permission_callback' => array( $this, 'check_permissions' ),
                        ),
                    )
                );
            }

            /**
             * Checks if the user can retrieve an access token for the requested scope.
             * 
             * @param string $scope Scope for which the token must be valid.
             * @return bool|WP_Error True if user can retrieve an access token for the requested scope otherwise a WP_Error is returned.
             */
            public function check_permissions( $request, $allow_application = false ) {
                
                if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
                    return new \WP_Error( 'UnauthorizedException', 'The request cannot be validated.', array( 'status' => 401 ) );
                }

                $wp_usr = \wp_get_current_user();

                if ( empty( $wp_usr ) ) {
                    return new \WP_Error( 'UnauthorizedException', 'Please sign in first before using this API.', array( 'status' => 401 ) );
                }

                if ( ! Permissions_Helpers::user_is_admin( $wp_usr ) ) {
                    return new \WP_Error( 'UnauthorizedException', 'Please sign in with administrative credentials before using this API.', array( 'status' => 403 ) );
                }

                return true;
            }
        }
    }