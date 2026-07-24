<?php
/**
 * Garante que os estilos do arquivo do blog não dependam do WooCommerce.
 */

define( 'ABSPATH', __DIR__ );

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $hook, $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $hook, $callback, $priority, $accepted_args );
}

function is_home() {
	return true;
}

function is_category( $category = null ) {
	unset( $category );
	return false;
}

function is_tag() {
	return false;
}

function is_archive() {
	return false;
}

function is_search() {
	return false;
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/37-blog-arquivo-editor.php';

ob_start();
uonix_estilos_premium_blog_archive();
$output = ob_get_clean();

if ( false === strpos( $output, '.post-archive' ) ) {
	fwrite( STDERR, "FAIL: estilos do arquivo do blog não foram renderizados sem WooCommerce.\n" );
	exit( 1 );
}

printf( "PASS: estilos do arquivo do blog não dependem do WooCommerce.\n" );
