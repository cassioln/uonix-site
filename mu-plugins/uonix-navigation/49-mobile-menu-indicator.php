<?php
/**
 * Normaliza o indicador de submenus do menu mobile do Max Mega Menu.
 *
 * O posicionamento absoluto evita que o chevron herde o line-height do link,
 * mantendo a geometria estável na home e nas páginas internas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_mobile_menu_indicator_styles' ) ) {
	function uonix_mobile_menu_indicator_styles() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		?>
		<style id="uonix-mobile-menu-indicator-css">
			#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children > a.mega-menu-link {
				position: relative !important;
				padding-right: 60px !important;
			}

			#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children > a.mega-menu-link > span.mega-indicator {
				display: inline-flex !important;
				position: absolute !important;
				top: 50% !important;
				right: 10px !important;
				align-items: center !important;
				justify-content: center !important;
				float: none !important;
				width: 40px !important;
				height: 40px !important;
				padding: 0 !important;
				margin: 0 !important;
				border-radius: 4px !important;
				box-sizing: border-box !important;
				line-height: 1 !important;
				font-size: 0 !important;
				background-color: #f1f5f9 !important;
				transform: translateY(-50%) !important;
			}

			#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children > a.mega-menu-link > span.mega-indicator::after {
				content: "" !important;
				display: block !important;
				width: 8px !important;
				height: 8px !important;
				box-sizing: border-box !important;
				border-style: solid !important;
				border-width: 0 2px 2px 0 !important;
				border-color: #334155 !important;
				font-family: inherit !important;
				font-size: 0 !important;
				line-height: 1 !important;
				margin: 0 !important;
				vertical-align: baseline !important;
				transform: rotate(45deg) !important;
				transition: transform 0.25s ease !important;
			}

			#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children.mega-toggle-on > a.mega-menu-link > span.mega-indicator::after {
				transform: rotate(-135deg) !important;
			}
		</style>
		<?php
	}
}
add_action( 'wp_head', 'uonix_mobile_menu_indicator_styles', 100 );
