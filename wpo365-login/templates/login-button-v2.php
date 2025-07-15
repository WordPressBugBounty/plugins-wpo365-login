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
			display: -webkit-box;
			display: -moz-box;
			display: -ms-flexbox;
			display: -webkit-flex;
			display: flex;
			-webkit-box-orient: vertical;
			-moz-box-orient: vertical;
			-webkit-box-direction: normal;
			-moz-box-direction: normal;
			-webkit-flex-direction: column;
			-ms-flex-direction: column;
			flex-direction: column;
			width: 100%;
			padding: 12px 12px 24px 12px;
			text-align: center;
			transition: transform 0.2s;
			align-items: center;
		}

		.wpo365-mssignin-wrapper:hover {
			<?php
			if ( ! $button_dont_zoom ) {
				echo 'transform: scale(1.05);';
			}
			?>
		}

		.wpo365-mssignin-spacearound {
			display: block;
			width: 100%;
			max-width: 400px;
			margin-bottom: 10px;
		}

		.wpo365-mssignin-select {
			width: 100%;
			height: 41px;
		}

		.wpo365-mssignin-wrapper form {
			display: none;
		}

		.wpo365-mssignin-button {
			background: <?php echo esc_html( $button_background_color ); ?>;
			border: <?php printf( '%s %s', esc_html( $button_border_width ), esc_html( $button_border_color ) ); ?>;
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
			width: 100%;
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
			color: <?php echo esc_html( $button_foreground_color ); ?>;
			font-family: "Segoe UI", Frutiger, "Frutiger Linotype", "Dejavu Sans", "Helvetica Neue", Arial, sans-serif;
			font-size: 15px;
			-webkit-flex-shrink: 1;
			-moz-flex-shrink: 1;
			flex-shrink: 1;
			height: 21px;
			line-height: 21px;
			overflow: hidden;
		}
	</style>
	<div id="wpo365OpenIdRedirect" class="wpo365-mssignin-wrapper">
		<?php if ( ! $hide_login_button ) : ?>
			<?php if ( ! empty( $wpo_idps ) ) : ?>
				<div class="wpo365-mssignin-spacearound">
					<select name="selectedTenant" id="selectedTenant" class="wpo365-mssignin-select">
						<option value="" disabled selected><?php echo esc_html( $sign_in_multi_placeholder ); ?></option>
						<?php foreach ( $wpo_idps as $idp ) : ?>
							<option value="<?php echo esc_html( $idp['id'] ); ?>"><?php echo esc_html( $idp['title'] ); ?></option>
						<?php endforeach ?>
					</select>
				</div>
			<?php endif ?>
			<div class="wpo365-mssignin-spacearound">
				<button class="wpo365-mssignin-button" type="button" onclick="window.wpo365.pintraRedirect.toMsOnline('', location.href, '', '', false, document.getElementById('selectedTenant') ? document.getElementById('selectedTenant').value : null)" aria-label="<?php echo esc_html( $sign_in_with_microsoft ); ?>">
					<?php if ( empty( $button_hide_logo ) ) : ?>
						<div class="wpo365-mssignin-logo">
							<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 21 21">
								<title>MS-SymbolLockup</title>
								<rect x="1" y="1" width="9" height="9" fill="#f25022" />
								<rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
								<rect x="11" y="1" width="9" height="9" fill="#7fba00" />
								<rect x="11" y="11" width="9" height="9" fill="#ffb900" />
							</svg>
						</div>
					<?php endif ?>
					<div class="wpo365-mssignin-label"><?php echo esc_html( $sign_in_with_microsoft ); ?></div>
				</button>
			</div>
		<?php endif ?>
	</div>
</div>