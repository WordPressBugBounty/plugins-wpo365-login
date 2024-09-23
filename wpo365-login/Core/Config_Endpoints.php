<?php

    namespace Wpo\Core;
        
    // Prevent public access to this script
    defined( 'ABSPATH' ) or die();

    use \Wpo\Services\Log_Service;
    use \Wpo\Services\Options_Service;

    if( !class_exists( '\Wpo\Core\Config_Endpoints' ) ) {

        class Config_Endpoints { 

            /**
             * Register the routes for the objects of the controller.
             */
            public static function users_search_unique( $rest_request ) {
                $body = $rest_request->get_json_params();

                if ( empty( $body ) || !\is_array( $body ) || empty( $body[ 'keyword' ] ) ) {
                    return new \WP_Error( 'InvalidArgumentException', 'Body is malformed JSON or the request header did not define the Content-type as application/json.', array( 'status' => 400 ) );
                }

                $users = new \WP_User_Query( array(
                    'count_total'    => true,
                    'search'         => '*' . esc_attr( $body[ 'keyword' ] ) . '*',
                    'search_columns' => array(
                        'user_login',
                        'user_nicename',
                        'user_email',
                        'user_url',
                    ),
                ) );

                if ( $users->get_total() != 1 ) {
                    return new \WP_Error( 'AmbigiousResultException', 'The query did not return exactly one unique user. Please update your input and try again.', array( 'status' => 404 ) );
                }

                $users_found = $users->get_results();

                return array( 
                    'ID' => $users_found[0]->ID,
                    'user_login' => $users_found[0]->user_login,
                );
            }
        }
    }