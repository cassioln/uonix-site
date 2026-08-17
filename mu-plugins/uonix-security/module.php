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
		'06-environment-indexing.php',
		'07-rest-user-enumeration.php',
		'08-noindex-paginas-de-fluxo.php',
	),
	'uonix-security'
);
