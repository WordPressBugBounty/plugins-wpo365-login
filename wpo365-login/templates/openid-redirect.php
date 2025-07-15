<?php

use Wpo\Core\WordPress_Helpers;

defined( 'ABSPATH' ) || die();

$header_sent = headers_sent();

if ( class_exists( '\Wpo\Services\Options_Service' ) ) {

	$loading_template_url = \Wpo\Services\Options_Service::get_global_string_var( 'loading_template_url' );

	if ( ! empty( $loading_template_url ) && preg_match( '/.*\.html$/', $loading_template_url ) === 1 && file_exists( ABSPATH . $loading_template_url ) ) {
		ob_start();
		include ABSPATH . $loading_template_url;
		$loading_template = ob_get_clean();
	}
}

?>

<?php if ( ! $header_sent ) : ?>
	<?php if ( ! \Wpo\Services\Options_Service::get_global_boolean_var( 'use_no_teams_sso' ) ) : ?>
		<!DOCTYPE html>
	<?php endif ?>
	<html>

	<body>
	<?php endif ?>

	<style>
		.wpo365-flex {
			display: flex;
			display: -webkit-box;
			display: -moz-box;
			display: -webkit-flex;
			display: -ms-flexbox;
		}

		.wpo365-flex-column {
			-webkit-box-direction: normal;
			-webkit-box-orient: vertical;
			-moz-box-direction: normal;
			-moz-box-orient: vertical;
			-webkit-flex-direction: column;
			-ms-flex-direction: column;
			flex-direction: column;
		}

		.wpo365-flex-justify-content {
			-webkit-box-pack: center;
			-moz-box-pack: center;
			-ms-flex-pack: center;
			-webkit-justify-content: center;
			justify-content: center;
		}

		.wpo365-flex-align-items {
			-webkit-box-align: center;
			-moz-box-align: center;
			-ms-flex-align: center;
			-webkit-align-items: center;
			align-items: center;
		}

		#wpo365RedirectLoadingOther {
			text-align: center;
		}

		#wpo365OpenIdRedirect {
			text-align: center
		}

		@keyframes spinner {
			0% {
				transform: translate3d(-50%, -50%, 0) rotate(0deg);
			}

			100% {
				transform: translate3d(-50%, -50%, 0) rotate(360deg);
			}
		}

		.loading::before {
			animation: 1.5s linear infinite spinner;
			animation-play-state: inherit;
			border: solid 5px #cfd0d1;
			border-bottom-color: #1c87c9;
			border-radius: 50%;
			content: "";
			height: 40px;
			width: 40px;
			position: absolute;
			transform: translate3d(-50%, -50%, 0);
			will-change: transform;
		}

		.loading {
			padding: 50px;
		}
	</style>

	<?php if ( \Wpo\Services\Options_Service::get_global_boolean_var( 'use_loader_v1' ) ) : ?>
		<style>
			.loading:before {
				content: "";
				display: block;
				font-size: 10px;
				text-indent: -9999em
			}

			.loader-animation-1 .loading:before,
			.loading:before {
				width: 1.25em;
				height: 5em;
				margin: -2.5em 0 0 -.625em;
				border: none;
				-webkit-border-radius: 0;
				border-radius: 0;
				background: #000;
				-webkit-animation: loader-animation-1 1s infinite ease-in-out;
				animation: loader-animation-1 1s infinite ease-in-out;
				-webkit-transform: translateZ(0);
				transform: translateZ(0)
			}

			@-webkit-keyframes loader-animation-1 {

				0%,
				100% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				12.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				25% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -2.5em 0 0 #000, -1.875em 2.5em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -2.5em 0 0 #000, -1.875em 2.5em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				37.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				50% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -2.5em 0 0 #000, 0 2.5em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -2.5em 0 0 #000, 0 2.5em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				62.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000
				}

				75% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -2.5em 0 0 #000, 1.875em 2.5em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -2.5em 0 0 #000, 1.875em 2.5em 0 0 #000
				}

				87.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000
				}
			}

			@keyframes loader-animation-1 {

				0%,
				100% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				12.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				25% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -2.5em 0 0 #000, -1.875em 2.5em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -2.5em 0 0 #000, -1.875em 2.5em 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				37.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em -1.25em 0 0 #000, -1.875em 1.25em 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				50% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -2.5em 0 0 #000, 0 2.5em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -2.5em 0 0 #000, 0 2.5em 0 0 #000, 1.875em 0 0 0 #000, 1.875em 0 0 0 #000
				}

				62.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 -1.25em 0 0 #000, 0 1.25em 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000
				}

				75% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -2.5em 0 0 #000, 1.875em 2.5em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -2.5em 0 0 #000, 1.875em 2.5em 0 0 #000
				}

				87.5% {
					-webkit-box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000;
					box-shadow: -1.875em 0 0 0 #000, 1.875em 0 0 0 #000, -1.875em 0 0 0 #000, -1.875em 0 0 0 #000, 0 0 0 0 #000, 0 0 0 0 #000, 1.875em -1.25em 0 0 #000, 1.875em 1.25em 0 0 #000
				}
			}

			.loading {
				min-height: 5em;
				margin-top: 5em;
				padding: 0
			}
		</style>
	<?php endif ?>

	<div id="wpo365RedirectLoading" class="wpo365-flex wpo365-flex-column wpo365-flex-justify-content wpo365-flex-align-items" style="width: 100%; height: 95vh">
		<!-- error -->
		<div id="wpo365RedirectError"></div>
		<?php
		if ( ! empty( $loading_template ) ) :
			echo wp_kses( $loading_template, WordPress_Helpers::get_allowed_html() );
		else :
			?>
			<!-- loader -->
			<div class="loading"></div>
		<?php endif ?>
		<div id="wpo365RedirectLoadingOther"></div>
		<div id="wpo365OpenIdRedirect"></div>
	</div>

	<?php
	if ( \Wpo\Services\Options_Service::get_global_boolean_var( 'use_no_teams_sso' ) ) {
		wp_print_script_tag(
			array(
				'src' => sprintf( '%sapps/dist/pintra-redirect-wo-teams.js?v=%s', esc_url( trailingslashit( $GLOBALS['WPO_CONFIG']['plugin_url'] ) ), esc_html( $GLOBALS['WPO_CONFIG']['version'] ) ),
			)
		);
	} elseif ( \Wpo\Services\Options_Service::get_global_boolean_var( 'use_ms_teams_sso_v1' ) ) {
		wp_print_script_tag(
			array(
				'src' => sprintf( '%sapps/dist/pintra-redirect-v1.js?v=%s', esc_url( trailingslashit( $GLOBALS['WPO_CONFIG']['plugin_url'] ) ), esc_html( $GLOBALS['WPO_CONFIG']['version'] ) ),
			)
		);
	} else {
		wp_print_script_tag(
			array(
				'src' => sprintf( '%sapps/dist/pintra-redirect.js?v=%s', esc_url( trailingslashit( $GLOBALS['WPO_CONFIG']['plugin_url'] ) ), esc_html( $GLOBALS['WPO_CONFIG']['version'] ) ),
			)
		);
	}

	$javascript = "window.wpo365 = window.wpo365 || {};\n" .
								( class_exists( '\Wpo\Core\Url_Helpers' ) && \Wpo\Core\Url_Helpers::is_wp_login() ? sprintf( "window.wpo365.siteUrl = '%s';\n", esc_url_raw( $GLOBALS['WPO_CONFIG']['url_info']['wp_site_url'] ) ) : '' ) .
								( \Wpo\Services\Options_Service::get_global_boolean_var( 'bounce_to_admin' ) ? sprintf( "window.wpo365.bounceUrl = '%s';\n", esc_url_raw( admin_url() ) ) : '' ) .
								"try {\n" .
								sprintf( "window.wpo365.pintraRedirect.toMsOnline('%s');", ( ! empty( $login_hint ) ? esc_html( $login_hint ) : '' ) ) .
								"} catch (err) { console.log('Error occured whilst trying to redirect to MS online'); console.error(err); }\n";

	if ( ! current_theme_supports( 'html5', 'script' ) || ! function_exists( 'wp_print_inline_script_tag' ) ) {
		printf( "<script>%s</script>\n", $javascript ); // phpcs:ignore
	} else {
		wp_print_inline_script_tag( $javascript );
	}
	?>

	<?php if ( ! $header_sent ) : ?>
	</body>

	</html>
<?php endif ?>