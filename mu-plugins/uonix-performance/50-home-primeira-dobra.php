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

		return $html;
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
