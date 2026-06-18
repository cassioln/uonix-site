<?php
/**
 * Ajustes exclusivos do ambiente local.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! in_array( UONIX_ENV, array( 'local', 'development' ), true ) ) {
	return;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'mailpit.php',
	),
	'uonix-local'
);
