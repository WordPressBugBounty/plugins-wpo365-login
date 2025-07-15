<?php

defined( 'ABSPATH' ) || die();

if ( class_exists( '\Wpo\Core\Url_Helpers' ) && \Wpo\Core\Url_Helpers::is_wp_login() ) {
		$_site_url = $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'];

	if ( defined( 'WPO_AUTH_SCENARIO' ) && constant( 'WPO_AUTH_SCENARIO' ) === 'internet' ) {
		$_site_url = \Wpo\Services\Options_Service::get_aad_option( 'redirect_url' );
		$_site_url = \Wpo\Services\Options_Service::get_global_boolean_var( 'use_saml' )
			? \Wpo\Services\Options_Service::get_aad_option( 'saml_sp_acs_url' )
			: $_site_url;
		$_site_url = apply_filters( 'wpo365/aad/redirect_uri', $_site_url );
	}
}

$javascript = "window.wpo365 = window.wpo365 || {};\n" .
							( ! empty( $_site_url ) ? sprintf( "window.wpo365.siteUrl = '%s';\n", $_site_url ) : '' );

if ( ! current_theme_supports( 'html5', 'script' ) || ! function_exists( 'wp_print_inline_script_tag' ) ) {
		printf( "<script>%s</script>\n", $javascript ); // phpcs:ignore
} else {
	wp_print_inline_script_tag( $javascript );
}

?>

<div>
	<style>
		.wpo365-mssignin-wrapper {
			box-sizing: border-box;
			display: block;
			width: 100%;
			padding: 12px 12px 24px 12px;
			text-align: center;
		}

		.wpo365-mssignin-spacearound {
			display: inline-block;
		}

		.wpo365-mssignin-wrapper form {
			display: none;
		}

		.wpo365-mssignin-button {
			border: 1px solid #8c8c8c;
			background: #ffffff;
			display: flex;
			display: -webkit-box;
			display: -moz-box;
			display: -webkit-flex;
			display: -ms-flexbox;
			-webkit-box-align: center;
			-moz-box-align: center;
			-ms-flex-align: center;
			-webkit-align-items: center;
			align-items: center;
			-webkit-box-pack: center;
			-moz-box-pack: center;
			-ms-flex-pack: center;
			-webkit-justify-content: center;
			justify-content: center;
			cursor: pointer;
			max-height: 41px;
			min-height: 41px;
			height: 41px;
		}

		.wpo365-mssignin-logo {
			padding-left: 12px;
			padding-right: 6px;
			-webkit-flex-shrink: 1;
			-moz-flex-shrink: 1;
			flex-shrink: 1;
			width: 21px;
			height: 21px;
			box-sizing: content-box;
			display: flex;
			display: -webkit-box;
			display: -moz-box;
			display: -webkit-flex;
			display: -ms-flexbox;
			-webkit-box-pack: center;
			-moz-box-pack: center;
			-ms-flex-pack: center;
			-webkit-justify-content: center;
			justify-content: center;
		}

		.wpo365-mssignin-label {
			white-space: nowrap;
			padding-left: 6px;
			padding-right: 12px;
			font-weight: 600;
			color: #5e5e5e;
			font-family: "Segoe UI", Frutiger, "Frutiger Linotype", "Dejavu Sans", "Helvetica Neue", Arial, sans-serif;
			font-size: 15px;
			-webkit-flex-shrink: 1;
			-moz-flex-shrink: 1;
			flex-shrink: 1;
			height: 21px;
			line-height: 21px;
		}
	</style>
	<div id="wpo365OpenIdRedirect" class="wpo365-mssignin-wrapper">
		<?php if ( ! $hide_login_button ) : ?>
			<div class="wpo365-mssignin-spacearound">
				<button class="wpo365-mssignin-button" type="button" onclick="window.wpo365.pintraRedirect.toMsOnline()" aria-label="<?php echo esc_html( $sign_in_with_microsoft ); ?>">
					<div class="wpo365-mssignin-logo">
						<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 21 21">
							<title>MS-SymbolLockup</title>
							<rect x="1" y="1" width="9" height="9" fill="#f25022" />
							<rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
							<rect x="11" y="1" width="9" height="9" fill="#7fba00" />
							<rect x="11" y="11" width="9" height="9" fill="#ffb900" />
						</svg>
					</div>
					<div class="wpo365-mssignin-label"><?php echo esc_html( $sign_in_with_microsoft ); ?></div>
				</button>
			</div>
		<?php endif ?>
	</div>
</div>