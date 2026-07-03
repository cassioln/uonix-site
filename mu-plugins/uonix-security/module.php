<?php
/**
 * Hardening de seguranca independente de plugins pagos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'05-xmlrpc-pingback.php',
	),
	'uonix-security'
);
