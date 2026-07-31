<?php
/**
 * Garante que callbacks globais de WooCommerce sejam seguros sem o plugin.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_callbacks'] = array(
	'the_title' => array(),
	'wp_head'   => array(),
	'wp_footer' => array(),
);

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );

	if ( isset( $GLOBALS['uonix_test_callbacks'][ $hook ] ) ) {
		$GLOBALS['uonix_test_callbacks'][ $hook ][] = $callback;
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );

	if ( isset( $GLOBALS['uonix_test_callbacks'][ $hook ] ) ) {
		$GLOBALS['uonix_test_callbacks'][ $hook ][] = $callback;
	}
}

function remove_action( $hook, $callback, $priority = 10 ) {
	unset( $hook, $callback, $priority );
}

function get_post_type( $post_id ) {
	unset( $post_id );

	return 'product';
}

function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}

$woocommerce_files = array(
	'14-checkout-newsletter-mirror.php',
	'16-woocommerce-thank-you.php',
	'17-woocommerce-checkout-design.php',
);

$woocommerce_native_hook_files = array(
	dirname( __DIR__, 2 ) . '/mu-plugins/uonix-woocommerce/20-catalogo-titulos-produtos.php',
	dirname( __DIR__, 2 ) . '/themes/kadence-child/snippets/01-woocommerce-loop-especificacoes.php',
	dirname( __DIR__, 2 ) . '/themes/kadence-child/snippets/34-produtos-carrosseis-relacionados.php',
	dirname( __DIR__, 2 ) . '/themes/kadence-child/snippets/35-produto-single-premium.php',
);

foreach ( $woocommerce_files as $woocommerce_file ) {
	require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-woocommerce/' . $woocommerce_file;
}

foreach ( $woocommerce_native_hook_files as $woocommerce_native_hook_file ) {
	require_once $woocommerce_native_hook_file;
}

ob_start();
foreach ( $GLOBALS['uonix_test_callbacks']['the_title'] as $title_callback ) {
	$title_callback( 'Produto<br>de teste', 123 );
}
foreach ( $GLOBALS['uonix_test_callbacks']['wp_head'] as $head_callback ) {
	$head_callback();
}
foreach ( $GLOBALS['uonix_test_callbacks']['wp_footer'] as $footer_callback ) {
	$footer_callback();
}
$output = ob_get_clean();

if ( '' !== $output ) {
	fwrite( STDERR, "FAIL: callbacks globais emitiram conteúdo sem WooCommerce: " . var_export( $output, true ) . "\n" );
	exit( 1 );
}

printf( "PASS: callbacks globais são seguros sem WooCommerce.\n" );
