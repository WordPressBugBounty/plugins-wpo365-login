<?php
// phpcs:ignoreFile

use Wpo\Core\Script_Helpers;

defined( 'ABSPATH' ) || die();

$react_urls = Script_Helpers::get_react_urls();

wp_print_script_tag( array( 
	'crossorigin' => 'anonymous',
	'src' 				=> $react_urls['react_url'],
) );

wp_print_script_tag( array( 
	'crossorigin' => 'anonymous',
	'src' 				=> $react_urls['react_dom_url'],
) );

$javascript = "window.wpo365 = window.wpo365 || {};\n" .
							sprintf( "window.wpo365.blocks = %s\n", wp_json_encode( 
								array(
								'nonce'  => \wp_create_nonce( 'wp_rest' ),
								'apiUrl' => esc_url_raw( \trailingslashit( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] ) ) . 'wp-json/wpo365/v1/graph',
							)));

if ( ! current_theme_supports( 'html5', 'script' ) || ! function_exists( 'wp_print_inline_script_tag' ) ) {
		printf( "<script>%s</script>\n", $javascript ); // phpcs:ignore
	} else {
		wp_print_inline_script_tag( $javascript );
	}
?>

<!-- Main -->
<div>
	<?php 
		wp_print_script_tag( array( 
			'src' 								=> esc_url( $script_url ),
			'data-nonce' 					=> wp_create_nonce( 'wpo365_fx_nonce' ),
			'data-wpajaxadminurl' => admin_url( 'admin-ajax.php' ),
			'data-props' 					=> htmlspecialchars( $props ),
		) );
	?>
	<!-- react root element will be added here -->
</div>