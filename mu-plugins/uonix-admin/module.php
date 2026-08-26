<?php
/**
 * Administração, dashboard, dados globais, login e utilitários internos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'04-blog-admin-feedback.php',
		'19-admin-taxonomias-produtos.php',
		'39-admin-editor-dashboard.php',
		'40-admin-dados-globais-rfq.php',
		'41-admin-fluentforms-ux.php',
		'45-login-personalizado.php',
		'46-admin-limpeza-conteudo.php',
		'47-admin-curriculos-recebidos.php',
		'48-admin-clone-ambientes.php',
		'51-login-turnstile.php',
		'52-admin-analytics-dashboard.php',
	),
	'uonix-admin'
);
