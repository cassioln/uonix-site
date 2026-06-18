<?php
/**
 * Ajustes exclusivos do ambiente local.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'local' !== UONIX_ENV ) {
	return;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'mailpit.php',
	),
	'uonix-local'
);
