<?php
/**
 * Formulários customizados, leads, newsletter e Trabalhe Conosco.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'28-modal-generico.php',
		'29-form-captura-lead.php',
		'30-sticky-lead.php',
		'31-sticky-whatsapp-servicos.php',
		'32-form-newsletter.php',
		'33-form-trabalhe-conosco.php',
		'48-form-trabalhe-redirect-autofill.php',
	),
	'uonix-forms'
);
