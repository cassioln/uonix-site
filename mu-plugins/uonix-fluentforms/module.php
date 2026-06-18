<?php
/**
 * Sincronizações, normalização e roteamento do Fluent Forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'07-fluentforms-roteamento-email.php',
		'08-fluentforms-sync-woocommerce.php',
		'09-fluentforms-sync-contato.php',
	),
	'uonix-fluentforms'
);
