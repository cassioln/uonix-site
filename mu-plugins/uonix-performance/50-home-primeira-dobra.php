<?php
/**
 * Prioriza recursos reais da primeira dobra da home.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_is_public_front_page' ) ) {
	function uonix_is_public_front_page() {
		return ! is_admin() && ! is_feed() && ! is_embed() && is_front_page();
	}
}

if ( ! function_exists( 'uonix_home_performance_resource_hints' ) ) {
	function uonix_home_performance_resource_hints() {
		if ( ! uonix_is_public_front_page() || 'local' === UONIX_ENV ) {
			return;
		}

		$lcp_image = home_url( '/wp-content/uploads/2026/01/alfa_servicos-e-treinamentos.webp' );

		printf(
			'<link rel="preload" as="image" href="%s" type="image/webp" fetchpriority="high">' . "\n",
			esc_url( $lcp_image )
		);

		echo '<link rel="preconnect" href="https://tag.goadopt.io" crossorigin>' . "\n";
		echo '<link rel="dns-prefetch" href="//tag.goadopt.io">' . "\n";
	}
}
add_action( 'wp_head', 'uonix_home_performance_resource_hints', 1 );

if ( ! function_exists( 'uonix_home_performance_critical_css' ) ) {
	function uonix_home_performance_critical_css() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}
		?>
		<style id="uonix-home-critical-pruning-css">
			#mega-menu-wrap-primary .mega-menu,
			#mega-menu-wrap-menu-extra-2 .mega-menu,
			#mega-menu-wrap-menu-extra-3 .mega-menu,
			#mega-menu-wrap-footer .mega-menu {
				display: flex;
				align-items: center;
				gap: 0;
				margin: 0;
				padding: 0;
				list-style: none;
			}
			#mega-menu-wrap-primary .mega-menu > li,
			#mega-menu-wrap-menu-extra-2 .mega-menu > li,
			#mega-menu-wrap-menu-extra-3 .mega-menu > li,
			#mega-menu-wrap-footer .mega-menu > li {
				position: relative;
				list-style: none;
			}
			#mega-menu-wrap-primary .mega-menu-link,
			#mega-menu-wrap-menu-extra-2 .mega-menu-link,
			#mega-menu-wrap-menu-extra-3 .mega-menu-link,
			#mega-menu-wrap-footer .mega-menu-link {
				display: flex;
				align-items: center;
				text-decoration: none;
				white-space: nowrap;
			}
			#mega-menu-wrap-primary .mega-menu-toggle,
			#mega-menu-wrap-primary .mega-close,
			#mega-menu-wrap-menu-extra-2 .mega-menu-toggle,
			#mega-menu-wrap-menu-extra-2 .mega-close,
			#mega-menu-wrap-menu-extra-3 .mega-menu-toggle,
			#mega-menu-wrap-menu-extra-3 .mega-close,
			#mega-menu-wrap-footer .mega-menu-toggle,
			#mega-menu-wrap-footer .mega-close {
				display: none;
			}
			#mega-menu-wrap-primary #mega-menu-primary > li.mega-menu-item-has-children > a.mega-menu-link > span.mega-indicator {
				display: inline-flex !important;
				align-items: center;
				justify-content: center;
				width: 0.85em;
				height: 0.85em;
				margin-left: 8px;
				color: inherit;
				opacity: 1 !important;
			}
			#mega-menu-wrap-primary #mega-menu-primary > li.mega-menu-item-has-children > a.mega-menu-link > span.mega-indicator::after {
				content: "" !important;
				display: block !important;
				width: 0.42em !important;
				height: 0.42em !important;
				border-right: 2px solid currentColor !important;
				border-bottom: 2px solid currentColor !important;
				background: transparent !important;
				-webkit-mask-image: none !important;
				mask-image: none !important;
				transform: rotate(45deg) translate(-1px, -1px);
				opacity: 1 !important;
			}
			#mega-menu-wrap-primary .mega-sub-menu,
			#mega-menu-wrap-menu-extra-2 .mega-sub-menu,
			#mega-menu-wrap-menu-extra-3 .mega-sub-menu,
			#mega-menu-wrap-footer .mega-sub-menu {
				display: none;
			}
			#mega-menu-wrap-primary .mega-menu-item:hover > .mega-sub-menu,
			#mega-menu-wrap-primary .mega-menu-item:focus-within > .mega-sub-menu,
			#mega-menu-wrap-menu-extra-2 .mega-menu-item:hover > .mega-sub-menu,
			#mega-menu-wrap-menu-extra-2 .mega-menu-item:focus-within > .mega-sub-menu,
			#mega-menu-wrap-menu-extra-3 .mega-menu-item:hover > .mega-sub-menu,
			#mega-menu-wrap-menu-extra-3 .mega-menu-item:focus-within > .mega-sub-menu {
				display: block;
			}
			#mega-menu-wrap-primary .mega-menu {
				justify-content: center;
				text-align: center;
			}
			#mega-menu-wrap-primary .mega-menu > li {
				display: inline-flex;
				align-items: center;
				height: 40px;
				margin: 0 10px 0 0;
				vertical-align: middle;
			}
			#mega-menu-wrap-primary .mega-menu > li > .mega-menu-link {
				height: 40px;
				padding: 0 10px;
				color: #f1f1f1;
				font-size: 18px;
				font-weight: 300;
				line-height: 40px;
				text-align: center;
				text-transform: uppercase;
			}
			#mega-menu-wrap-primary .mega-menu > li > .mega-menu-link:hover,
			#mega-menu-wrap-primary .mega-menu > li > .mega-menu-link:focus {
				color: #ffffff;
			}
			#mega-menu-wrap-primary .mega-indicator::after {
				content: "";
				display: inline-block;
				width: 0.75em;
				height: 0.75em;
				margin-left: 6px;
				background: currentColor;
				clip-path: polygon(20% 35%, 50% 65%, 80% 35%, 90% 45%, 50% 85%, 10% 45%);
			}
			#mega-menu-wrap-menu-extra-2 .mega-menu,
			#mega-menu-wrap-menu-extra-3 .mega-menu {
				justify-content: center;
			}
			#mega-menu-wrap-menu-extra-2 .mega-menu > li,
			#mega-menu-wrap-menu-extra-3 .mega-menu > li {
				display: inline-flex;
				align-items: center;
				margin: 0;
			}
			#mega-menu-wrap-menu-extra-2 #mega-menu-item-4811 > .mega-menu-link,
			#mega-menu-wrap-menu-extra-3 #mega-menu-item-4819 > .mega-menu-link {
				position: relative;
				gap: 0;
				padding: 5px 15px;
				color: #003399;
				font-size: 0;
				font-weight: 600;
				line-height: 1.1;
			}
			#mega-menu-wrap-menu-extra-2 #mega-menu-item-4811 > .mega-menu-link::before,
			#mega-menu-wrap-menu-extra-3 #mega-menu-item-4819 > .mega-menu-link::before {
				content: "";
				display: block;
				flex: 0 0 auto;
				background: #003399;
				-webkit-mask-position: center;
				mask-position: center;
				-webkit-mask-repeat: no-repeat;
				mask-repeat: no-repeat;
				-webkit-mask-size: contain;
				mask-size: contain;
			}
			#mega-menu-wrap-menu-extra-2 #mega-menu-item-4811 > .mega-menu-link::before {
				width: 33px;
				height: 33px;
				margin: 4px 12px 0 0;
				-webkit-mask-image: url("/wp-content/uploads/2026/03/ico-contato.svg");
				mask-image: url("/wp-content/uploads/2026/03/ico-contato.svg");
			}
			#mega-menu-wrap-menu-extra-3 #mega-menu-item-4819 > .mega-menu-link::before {
				width: 50px;
				height: 55px;
				margin: 6px 6px 0 0;
				-webkit-mask-image: url("/wp-content/uploads/2026/03/ico-carrinho.svg");
				mask-image: url("/wp-content/uploads/2026/03/ico-carrinho.svg");
			}
			#mega-menu-wrap-menu-extra-2 #mega-menu-item-4811 > .mega-menu-link::after,
			#mega-menu-wrap-menu-extra-3 #mega-menu-item-4819 > .mega-menu-link::after {
				display: block;
				color: #003399;
				font-size: 17px;
				font-weight: 600;
				line-height: 1.1;
				text-align: left;
				text-transform: capitalize;
				white-space: pre-line;
			}
			#mega-menu-wrap-menu-extra-2 #mega-menu-item-4811 > .mega-menu-link::after {
				content: "Central de\a Atendimento";
			}
			#mega-menu-wrap-menu-extra-3 #mega-menu-item-4819 > .mega-menu-link::after {
				content: "Itens do\a Or\00e7 amento";
			}
			#mega-menu-wrap-menu-extra-2 .mega-indicator,
			#mega-menu-wrap-menu-extra-3 .mega-indicator {
				display: none;
			}
			#mega-menu-wrap-mobile .mega-menu {
				display: none;
				margin: 0;
				padding: 0;
				list-style: none;
			}
			#mega-menu-wrap-mobile .mega-menu-toggle {
				display: flex;
				align-items: center;
				justify-content: flex-end;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 44px;
				height: 44px;
				padding: 0;
				border: 0;
				background: transparent;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-box,
			#mega-menu-wrap-mobile .mega-toggle-animated-inner,
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::before,
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::after {
				display: block;
				width: 28px;
				height: 3px;
				border-radius: 2px;
				background: currentColor;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-box {
				position: relative;
				background: transparent;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-inner {
				position: absolute;
				top: 50%;
				left: 0;
				transform: translateY(-50%);
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::before,
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::after {
				content: "";
				position: absolute;
				left: 0;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::before {
				top: -8px;
			}
			#mega-menu-wrap-mobile .mega-toggle-animated-inner::after {
				top: 8px;
			}
			@media (max-width: 1024px) {
				#mega-menu-wrap-primary .mega-menu {
					display: none;
				}
			}
			@media (min-width: 1025px) {
				#mega-menu-wrap-mobile {
					display: none;
				}
			}
			.wcps-container-1546 .splide__arrow i,
			.wcps-container-8643 .splide__arrow i {
				display: none !important;
			}
			.wcps-container-1546 .splide__arrow .icon::before,
			.wcps-container-8643 .splide__arrow .icon::before {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 1em;
				height: 1em;
				color: currentColor;
				font-size: 28px;
				font-weight: 800;
				line-height: 1;
			}
			.wcps-container-1546 .splide__arrow.prev .icon::before,
			.wcps-container-8643 .splide__arrow.prev .icon::before {
				content: "\2039";
			}
			.wcps-container-1546 .splide__arrow.next .icon::before,
			.wcps-container-8643 .splide__arrow.next .icon::before {
				content: "\203A";
			}
		</style>
		<?php
	}
}
add_action( 'wp_head', 'uonix_home_performance_critical_css', 2 );

if ( ! function_exists( 'uonix_home_dequeue_jarallax' ) ) {
	function uonix_home_dequeue_jarallax() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}

		wp_dequeue_script( 'jarallax' );
		wp_deregister_script( 'jarallax' );
	}
}
add_action( 'wp_enqueue_scripts', 'uonix_home_dequeue_jarallax', 100 );

if ( ! function_exists( 'uonix_home_dequeue_unused_home_assets' ) ) {
	function uonix_home_dequeue_unused_home_assets() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}

		wp_dequeue_style( 'font-awesome-5' );
		wp_deregister_style( 'font-awesome-5' );
	}
}
add_action( 'wp_enqueue_scripts', 'uonix_home_dequeue_unused_home_assets', 120 );
add_action( 'wp_print_styles', 'uonix_home_dequeue_unused_home_assets', 120 );

if ( ! function_exists( 'uonix_home_dequeue_filter_assets' ) ) {
	function uonix_home_dequeue_filter_assets() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}

		$styles = array(
			'woof',
			'woof_sections_style',
			'woof_tooltip-css',
			'woof_tooltip-css-noir',
			'icheck-jquery-color-flat',
			'icheck-jquery-color-square',
			'icheck-jquery-color-minimal',
			'ion.range-slider',
			'woof-front-builder-css',
			'woof-slideout-tab-css',
			'woof-slideout-css',
			'woof_by_author_html_items',
			'woof_by_instock_html_items',
			'woof_by_onsales_html_items',
			'woof_by_text_html_items',
			'woof_label_html_items',
			'woof_quick_search_html_items',
			'woof_select_radio_check_html_items',
			'woof_sd_html_items_checkbox',
			'woof_sd_html_items_radio',
			'woof_sd_html_items_switcher',
			'woof_sd_html_items_color',
			'woof_sd_html_items_tooltip',
			'woof_sd_html_items_front',
			'woof-switcher23',
			'select2',
		);

		$scripts = array(
			'woof-husky',
			'woof_url_parser',
			'woof_tooltip-js',
			'icheck-jquery',
			'woof_front',
			'woof_radio_html_items',
			'woof_checkbox_html_items',
			'woof_select_html_items',
			'woof_mselect_html_items',
			'woof_by_author_html_items',
			'woof_by_instock_html_items',
			'woof_by_onsales_html_items',
			'woof_by_text_html_items',
			'woof_label_html_items',
			'woof_sections_html_items',
			'woof_select_radio_check_html_items',
			'woof_sd_html_items',
			'woof_stat_html_items',
			'selectWoo',
			'wc-select2',
			'ion.range-slider',
			'woof-slideout-js',
			'woof-slideout-init',
		);

		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		foreach ( $scripts as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'uonix_home_dequeue_filter_assets', 100 );
add_action( 'wp_print_styles', 'uonix_home_dequeue_filter_assets', 100 );
add_action( 'wp_print_scripts', 'uonix_home_dequeue_filter_assets', 100 );

if ( ! function_exists( 'uonix_home_defer_styles_by_id' ) ) {
	function uonix_home_defer_styles_by_id( $html, $style_ids ) {
		$id_pattern = implode( '|', array_map( 'preg_quote', $style_ids ) );

		return preg_replace_callback(
			'/<link\b(?=[^>]*\bid=[\\\'"](' . $id_pattern . ')[\\\'"])(?=[^>]*\brel=[\\\'"]stylesheet[\\\'"])[^>]*>\s*/i',
			function ( $matches ) {
				if ( ! preg_match( '/\bhref=[\\\'"]([^\\\'"]+)[\\\'"]/i', $matches[0], $href_match ) ) {
					return '';
				}

				$id   = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
				$href = esc_url_raw( html_entity_decode( $href_match[1], ENT_QUOTES, 'UTF-8' ) );

				return sprintf(
					'<script>window.uonixDeferredStyles=window.uonixDeferredStyles||[];window.uonixDeferredStyles.push({id:%s,href:%s});</script>' . "\n",
					wp_json_encode( $id ),
					wp_json_encode( $href )
				);
			},
			$html
		);
	}
}

if ( ! function_exists( 'uonix_home_remove_assets_by_id' ) ) {
	function uonix_home_remove_assets_by_id( $html, $asset_ids ) {
		$id_pattern = implode( '|', array_map( 'preg_quote', $asset_ids ) );

		$html = preg_replace(
			'/<link\b(?=[^>]*\bid=[\\\'"](?:' . $id_pattern . ')[\\\'"])[^>]*>\s*/i',
			'',
			$html
		);

		$html = preg_replace(
			'/<style\b(?=[^>]*\bid=[\\\'"](?:' . $id_pattern . ')[\\\'"])[\s\S]*?<\/style>\s*/i',
			'',
			$html
		);

		return preg_replace(
			'/<script\b(?=[^>]*\bid=[\\\'"](?:' . $id_pattern . ')[\\\'"])[\s\S]*?<\/script>\s*/i',
			'',
			$html
		);
	}
}

if ( ! function_exists( 'uonix_home_img_attr' ) ) {
	function uonix_home_img_attr( $tag, $attr, $value ) {
		$escaped_value = esc_attr( $value );

		if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '=[\\\'"][^\\\'"]*[\\\'"]/i', $tag ) ) {
			return preg_replace(
				'/\s' . preg_quote( $attr, '/' ) . '=[\\\'"][^\\\'"]*[\\\'"]/i',
				' ' . $attr . '="' . $escaped_value . '"',
				$tag,
				1
			);
		}

		return preg_replace( '/<img\b/i', '<img ' . $attr . '="' . $escaped_value . '"', $tag, 1 );
	}
}

if ( ! function_exists( 'uonix_home_image_dimensions_from_url' ) ) {
	function uonix_home_image_dimensions_from_url( $url ) {
		static $cache = array();

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$cache[ $url ] = false;
		$parts         = wp_parse_url( $url );
		$home_parts    = wp_parse_url( home_url() );

		if ( empty( $parts['path'] ) || empty( $home_parts['host'] ) ) {
			return false;
		}

		if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return false;
		}

		if ( 0 !== strpos( $parts['path'], '/wp-content/uploads/' ) ) {
			return false;
		}

		$file = ABSPATH . ltrim( rawurldecode( $parts['path'] ), '/' );

		if ( ! is_readable( $file ) ) {
			return false;
		}

		$size = getimagesize( $file );

		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		$cache[ $url ] = array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);

		return $cache[ $url ];
	}
}

if ( ! function_exists( 'uonix_home_normalize_image_attributes' ) ) {
	function uonix_home_normalize_image_attributes( $html ) {
		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) {
				$tag = $matches[0];
				$url = '';

				if ( preg_match( '/\sdata-splide-lazy=[\\\'"]([^\\\'"]+)[\\\'"]/i', $tag, $lazy_match ) ) {
					$url = html_entity_decode( $lazy_match[1], ENT_QUOTES, 'UTF-8' );
				} elseif ( preg_match( '/\ssrc=[\\\'"]([^\\\'"]+)[\\\'"]/i', $tag, $src_match ) ) {
					$url = html_entity_decode( $src_match[1], ENT_QUOTES, 'UTF-8' );
				}

				$is_lcp = false !== strpos( $url, '/wp-content/uploads/2026/01/alfa_servicos-e-treinamentos.webp' );
				$is_header_asset = preg_match( '#/(logo-uonix|cropped-cropped-logo_uonix|selo-corporativo)#i', $url );

				$tag = uonix_home_img_attr( $tag, 'decoding', 'async' );

				if ( $is_lcp ) {
					$tag = uonix_home_img_attr( $tag, 'fetchpriority', 'high' );
					$tag = uonix_home_img_attr( $tag, 'loading', 'eager' );
				} elseif ( ! $is_header_asset && ! preg_match( '/\sloading=[\\\'"][^\\\'"]+[\\\'"]/i', $tag ) ) {
					$tag = uonix_home_img_attr( $tag, 'loading', 'lazy' );
				}

				if ( $url && ( ! preg_match( '/\swidth=[\\\'"][^\\\'"]+[\\\'"]/i', $tag ) || ! preg_match( '/\sheight=[\\\'"][^\\\'"]+[\\\'"]/i', $tag ) ) ) {
					$dimensions = uonix_home_image_dimensions_from_url( $url );

					if ( $dimensions ) {
						$tag = uonix_home_img_attr( $tag, 'width', (string) $dimensions['width'] );
						$tag = uonix_home_img_attr( $tag, 'height', (string) $dimensions['height'] );
					}
				}

				return $tag;
			},
			$html
		);
	}
}

if ( ! function_exists( 'uonix_home_strip_filter_asset_tags' ) ) {
	function uonix_home_strip_filter_asset_tags( $html ) {
		$asset_id_pattern = '(?:woof|icheck|ion\\.range-slider|select2|selectWoo|wc-select2)[^\\\'"]*';

		$html = preg_replace(
			'/<link\\b(?=[^>]*\\bid=[\\\'"]' . $asset_id_pattern . '[\\\'"])[^>]*>\\s*/i',
			'',
			$html
		);

		$html = preg_replace(
			'/<style\\b(?=[^>]*\\bid=[\\\'"]' . $asset_id_pattern . '[\\\'"])[\\s\\S]*?<\\/style>\\s*/i',
			'',
			$html
		);

		$html = preg_replace(
			'/<script\\b(?=[^>]*\\bid=[\\\'"]' . $asset_id_pattern . '[\\\'"])[\\s\\S]*?<\\/script>\\s*/i',
			'',
			$html
		);

		$html = uonix_home_remove_assets_by_id(
			$html,
			array(
				'font-awesome-5-css',
			)
		);

		$html = uonix_home_defer_styles_by_id(
			$html,
			array(
				'megamenu-css',
				'fluent-form-styles-css',
				'fluentform-public-default-css',
				'kadence-woocommerce-css',
			)
		);

		return uonix_home_normalize_image_attributes( $html );
	}
}

if ( ! function_exists( 'uonix_home_start_filter_asset_buffer' ) ) {
	function uonix_home_start_filter_asset_buffer() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}

		ob_start( 'uonix_home_strip_filter_asset_tags' );
	}
}
add_action( 'template_redirect', 'uonix_home_start_filter_asset_buffer', 0 );

if ( ! function_exists( 'uonix_home_deferred_styles_loader' ) ) {
	function uonix_home_deferred_styles_loader() {
		if ( ! uonix_is_public_front_page() ) {
			return;
		}
		?>
		<script>
			(function() {
				var loaded = {};
				var groupById = {
					'megamenu-css': 'menu',
					'fluent-form-styles-css': 'form',
					'fluentform-public-default-css': 'form',
					'kadence-woocommerce-css': 'woo'
				};

				function items() {
					return window.uonixDeferredStyles || [];
				}

				function loadStyle(item) {
					var link;

					if (!item || !item.href || loaded[item.id] || document.getElementById(item.id)) {
						loaded[item.id] = true;
						return;
					}

					link = document.createElement('link');
					link.rel = 'stylesheet';
					link.href = item.href;
					link.id = item.id;
					link.dataset.uonixDeferred = '1';
					document.head.appendChild(link);
					loaded[item.id] = true;
				}

				function loadGroup(group) {
					items().forEach(function(item) {
						if (groupById[item.id] === group) {
							loadStyle(item);
						}
					});
				}

				function bindLoad(selector, group) {
					document.querySelectorAll(selector).forEach(function(node) {
						['pointerenter', 'pointerdown', 'touchstart', 'focusin'].forEach(function(eventName) {
							node.addEventListener(eventName, function() {
								loadGroup(group);
							}, { once: true, passive: true });
						});
					});
				}

				function observeLoad(selector, group) {
					var nodes = document.querySelectorAll(selector);

					if (!nodes.length || !('IntersectionObserver' in window)) {
						return;
					}

					var observer = new IntersectionObserver(function(entries) {
						entries.forEach(function(entry) {
							if (entry.isIntersecting) {
								loadGroup(group);
								observer.disconnect();
							}
						});
					}, { rootMargin: '360px 0px' });

					nodes.forEach(function(node) {
						observer.observe(node);
					});
				}

				function init() {
					bindLoad('.mega-menu-wrap', 'menu');
					bindLoad('.fluentform, .fluent_form_3, form[id^="fluentform_"]', 'form');
					bindLoad('.wc-block-mini-cart, .uonix-menu-cart, .add_to_cart_button, .wcps-container', 'woo');
					observeLoad('.fluentform, .fluent_form_3, form[id^="fluentform_"]', 'form');
					window.setTimeout(function() { loadGroup('menu'); }, 12000);
					window.setTimeout(function() { loadGroup('woo'); }, 14000);
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', init);
				} else {
					init();
				}
			})();
		</script>
		<?php
	}
}
add_action( 'wp_footer', 'uonix_home_deferred_styles_loader', 1 );
