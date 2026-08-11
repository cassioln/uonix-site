<?php
/**
 * Bootstrap da ficha técnica estruturada por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__ . '/ficha-tecnica-variacao',
	array( 'class-uonix-vts-schema.php' ),
	'uonix-woocommerce/ficha-tecnica-variacao'
);
