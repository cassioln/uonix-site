<?php
/**
 * Bootstrap da ficha técnica estruturada por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__ . '/ficha-tecnica-variacao',
	array(
		'class-uonix-vts-schema.php',
		'class-uonix-vts-renderer.php',
		'class-uonix-vts-admin.php',
	),
	'uonix-woocommerce/ficha-tecnica-variacao'
);

if ( class_exists( 'Uonix_VTS_Renderer' ) ) {
	Uonix_VTS_Renderer::register_hooks();
}

if ( class_exists( 'Uonix_VTS_Admin' ) ) {
	Uonix_VTS_Admin::register_hooks();
}
