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
		'49-admin-ksio-governanca.php',
		'50-admin-intelligence-license.php',
		'51-login-turnstile.php',
		'53-admin-analytics-metrics.php',
		'54-admin-intelligence-ai.php',
		'55-admin-intelligence-metrics.php',
		'56-admin-intelligence-dashboard.php',
		'57-admin-intelligence-report.php',
		'58-admin-intelligence-anomalies.php',
		'59-admin-intelligence-executive.php',
		'63-admin-intelligence-content-radar.php',
		'64-admin-editor-rodape.php',
		'52-admin-analytics-dashboard.php',
		'61-admin-widgets-rodape-fundo-escuro.php',
		'62-admin-widgets-lote-rest.php',
		'65-admin-editor-politicas-lgpd.php',
		'66-admin-editor-sem-personalizador.php',
		'67-admin-editor-menus.php',
		'68-admin-editor-marketing.php',
	),
	'uonix-admin'
);
