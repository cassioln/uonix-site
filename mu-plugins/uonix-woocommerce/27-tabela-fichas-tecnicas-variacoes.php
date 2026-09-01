<?php
/**
 * Bootstrap da tabela consolidada de fichas técnicas por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__ . '/tabela-fichas-tecnicas-variacoes',
	array(
		'class-uonix-vtst-table.php',
		'class-uonix-vtst-diagram-admin.php',
	),
	'uonix-woocommerce/tabela-fichas-tecnicas-variacoes'
);

if ( class_exists( 'Uonix_VTS_Schema' ) && class_exists( 'Uonix_VTST_Table' ) ) {
	Uonix_VTST_Table::register_hooks();
}

if ( class_exists( 'Uonix_VTST_Diagram_Admin' ) ) {
	Uonix_VTST_Diagram_Admin::register_hooks();
}
