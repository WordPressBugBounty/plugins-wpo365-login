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
	    $file         = $_GET['file'] ?? ''; // phpcs:ignore
			$sec_download = new \Wpo\Services\Secure_Download_Service();
			$sec_download->serve_file( $file );
		} catch ( \Exception $ex ) { // phpcs:ignore
			status_header( 500 );
			exit( sprintf( 'An error occurred. Please try again or contact support for assistance. [Error: %s]', $ex->getMessage() ) ); // phpcs:ignore
		}

		break;
	}
}
