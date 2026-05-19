<?php

namespace Wpo\Services;

$plugins_directory = dirname( __DIR__, 2 );
$candidates        = array(
	'wpo365-essentials',
	'wpo365-integrate',
	'wpo365-intranet-5y',
	'wpo365-login-intranet',
	'wpo365-login-premium',
	'wpo365-login-professional',
	'wpo365-pro',
	'wpo365-sync-5y',
);

foreach ( $candidates as $candidate ) {
	$file        = "$plugins_directory/$candidate/Services/Secure_Download_Service.php";
	$file_exists = file_exists( $file );

	if ( $file_exists ) {
		require_once $file;

		try {
			// Read the rewrite-generated 'file' parameter from the raw QUERY_STRING.
			// Both Apache [QSA] and Nginx append the original query string AFTER the
			// rewrite params, so a user-supplied 'file=' would appear as a second
			// entry. PHP $_GET['file'] gives the last value (injection risk). We take
			// the first value instead.
			$raw_qs = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : ''; // phpcs:ignore
			$file   = '';

			foreach ( explode( '&', $raw_qs ) as $qs_pair ) {
				if ( strncmp( $qs_pair, 'file=', 5 ) === 0 ) {
					$file = urldecode( substr( $qs_pair, 5 ) );
					break;
				}
			}

			$sec_download = new \Wpo\Services\Secure_Download_Service();
			$sec_download->serve_file( $file );
		} catch ( \Exception $ex ) { // phpcs:ignore
			status_header( 500 );
			exit( sprintf( 'An error occurred. Please try again or contact support for assistance. [Error: %s]', $ex->getMessage() ) ); // phpcs:ignore
		}

		break;
	}
}
