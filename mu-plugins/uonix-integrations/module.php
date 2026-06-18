<?php
/**
 * Integrações externas, LGPD, analytics e avaliações.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'34-turnstile-custom-forms.php',
		'38-integracoes-analytics-lgpd.php',
		'43-avaliacoes-google-trustindex.php',
	),
	'uonix-integrations'
);
