<?php
/**
 * Testes da política fail-closed de GTM/GA4 via GTM e AdOpt.
 */

define( 'ABSPATH', __DIR__ );
define( 'UONIX_ENV', 'staging' );
define( 'UONIX_ANALYTICS_ENABLED', true );
define( 'UONIX_GTM_CONTAINER_ID', 'GTM-COPIED123' );
define( 'UONIX_ADOPT_WEBSITE_ID', 'copied-adopt-id' );

$GLOBALS['uonix_test_actions'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

/*
 * Options do WordPress, injetáveis pelo teste.
 *
 * Necessário para exercitar a guarda contra o Google Site Kit: sem este stub,
 * `function_exists('get_option')` era false, a guarda nunca rodava de verdade e uma
 * mutação que REMOVIA a chamada passava sem ser detectada.
 */
$GLOBALS['uonix_test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['uonix_test_options'] )
		? $GLOBALS['uonix_test_options'][ $name ]
		: $default;
}

function is_admin() {
	return false;
}

function is_feed() {
	return false;
}

function is_embed() {
	return false;
}

function is_front_page() {
	return true;
}

function home_url( $path = '' ) {
	return 'https://uonix.ksio.dev' . $path;
}

function esc_url( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-performance/50-home-primeira-dobra.php';

$failures = 0;

function uonix_analytics_assert_same( $expected, $actual, $message ) {
	global $failures;

	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

function uonix_analytics_assert_contains( $needle, $haystack, $message ) {
	global $failures;

	if ( false === strpos( $haystack, $needle ) ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s (ausente: %s)\n", $message, $needle ) );
	}
}

function uonix_analytics_assert_not_contains( $needle, $haystack, $message ) {
	global $failures;

	if ( false !== strpos( $haystack, $needle ) ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s (encontrado: %s)\n", $message, $needle ) );
	}
}

function uonix_analytics_assert_css_declaration( $selector, $declaration, $css, $message ) {
	global $failures;

	$pattern = '/' . preg_quote( $selector, '/' ) . '\\s*\\{(?<body>.*?)\\}/s';

	if ( ! preg_match( $pattern, $css, $matches ) || false === strpos( $matches['body'], $declaration ) ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s (seletor/declaracao ausente: %s { %s })\n", $message, $selector, $declaration ) );
	}
}

$required_functions = array(
	'uonix_analytics_configuration',
	'uonix_render_analytics_head',
	'uonix_render_analytics_body',
);

foreach ( $required_functions as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, sprintf( "FAIL: %s() ainda não existe.\n", $required_function ) );
		exit( 1 );
	}
}

$valid = array(
	'gtm_container_id' => 'GTM-REAL123',
	'adopt_website_id' => 'real-adopt-id',
);

uonix_analytics_assert_same( $valid, uonix_analytics_configuration( 'production', true, 'GTM-REAL123', 'real-adopt-id' ), 'produção habilitada com IDs completos é válida' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'production', false, 'GTM-REAL123', 'real-adopt-id' ), 'produção desabilitada não injeta' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'production', true, '', 'real-adopt-id' ), 'produção com GTM ausente não injeta parcialmente' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'production', true, 'GTM-REAL123', '' ), 'produção com AdOpt ausente não injeta parcialmente' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'staging', true, 'GTM-COPIED123', 'copied-adopt-id' ), 'QA ignora IDs copiados' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'development', true, 'GTM-COPIED123', 'copied-adopt-id' ), 'DEV ignora IDs copiados' );
uonix_analytics_assert_same( false, uonix_analytics_configuration( 'local', true, 'GTM-COPIED123', 'copied-adopt-id' ), 'local ignora IDs copiados' );

ob_start();
uonix_render_analytics_head( $valid );
$head_html = ob_get_clean();
uonix_analytics_assert_contains( 'GTM-REAL123', $head_html, 'head injeta GTM que entrega GA4' );
uonix_analytics_assert_contains( 'real-adopt-id', $head_html, 'head injeta AdOpt' );

$do_not_sell_selector = '#uonix-cookie-root #cookie-banner div:has(> #adopt-accept-all-button) > button:not(#adopt-preferences-button):not(#adopt-accept-all-button)';
uonix_analytics_assert_css_declaration(
	$do_not_sell_selector,
	'border-radius: 8px !important',
	$head_html,
	'botão Não venda usa o mesmo arredondamento discreto do Aceitar sem alterar Minhas opções'
);

ob_start();
uonix_render_analytics_body( $valid );
$body_html = ob_get_clean();
uonix_analytics_assert_contains( 'GTM-REAL123', $body_html, 'body injeta noscript do GTM' );

ob_start();
uonix_render_analytics_head( false );
uonix_render_analytics_body( false );
$disabled_html = ob_get_clean();
uonix_analytics_assert_same( '', $disabled_html, 'configuração inválida não injeta integração parcial' );

ob_start();
uonix_render_analytics_head( '' );
uonix_render_analytics_body( '' );
$empty_hook_argument_html = ob_get_clean();
uonix_analytics_assert_same( '', $empty_hook_argument_html, 'argumento vazio entregue por action sem parâmetros permanece fail-closed' );

ob_start();
uonix_home_performance_resource_hints();
$staging_hints = ob_get_clean();
uonix_analytics_assert_not_contains( 'tag.goadopt.io', $staging_hints, 'QA não emite preconnect/dns-prefetch do AdOpt' );

$source = file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php' );
uonix_analytics_assert_not_contains( 'GTM-P8TR5CCH', $source, 'ID GTM fixo foi removido do código' );
uonix_analytics_assert_not_contains( '4e6a8df6-4bee-43c7-86d9-7b91ccc9df56', $source, 'ID AdOpt fixo foi removido do código' );

/*
 * ---------------------------------------------------------------------------
 * Guarda contra contagem dupla com o Google Site Kit
 * ---------------------------------------------------------------------------
 *
 * O Site Kit, quando o módulo Analytics está conectado, injeta a própria tag GA4. Como
 * este arquivo emite o container GTM, os dois juntos duplicam pageviews e conversões —
 * silenciosamente.
 *
 * Estado medido em produção (2026-08-16): useSnippet = true nas três options, mas TODOS
 * os IDs vazios. Não há conflito hoje, mas basta conectar o módulo no painel para
 * começar. Estes casos provam que a guarda decide certo.
 *
 * As options são INJETADAS (não lidas do banco) para o teste ser determinístico.
 */

// Caso real de hoje: useSnippet ligado mas sem nenhum ID -> NÃO injeta, logo sem conflito.
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => '', 'webDataStreamID' => '' ),
		'googlesitekit_tagmanager_settings'  => array( 'useSnippet' => true, 'containerID' => '' ),
	) ),
	'useSnippet=true com IDs vazios não caracteriza injeção (estado atual de produção)'
);

// Módulo conectado de verdade -> conflito detectado.
uonix_analytics_assert_same(
	'analytics-4',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => 'G-ABC123XYZ' ),
	) ),
	'measurementID preenchido com useSnippet=true caracteriza injeção do GA4'
);

// useSnippet desligado: o Site Kit só lê dados, não coloca tag. Sem conflito.
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_analytics-4_settings' => array( 'useSnippet' => false, 'measurementID' => 'G-ABC123XYZ' ),
	) ),
	'useSnippet=false não injeta, mesmo com ID preenchido'
);

// O módulo Tag Manager também conta.
uonix_analytics_assert_same(
	'tagmanager',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_tagmanager_settings' => array( 'useSnippet' => true, 'containerID' => 'GTM-OUTRO99' ),
	) ),
	'containerID do Tag Manager preenchido caracteriza injeção'
);

// Espaço em branco não é ID válido.
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => '   ' ),
	) ),
	'ID só com espaços não caracteriza injeção'
);

// Site Kit ausente/não configurado.
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array() ),
	'sem options do Site Kit não há injeção'
);

// AdSense NÃO deve entrar na conta: serve anúncio, não emite medição de pageview, e
// bloqueá-lo suspenderia o container GTM sem motivo.
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_adsense_settings' => array( 'useSnippet' => true, 'accountID' => 'ca-pub-123' ),
	) ),
	'AdSense não caracteriza conflito de medição'
);

/*
 * UM CASO POR CAMPO SECUNDÁRIO.
 *
 * O revisor do PR #110 provou que sem isso a remoção de um campo secundário da lista
 * passava impune (exit=0): nenhuma asserção exercitava o campo com o primário VAZIO.
 * Cada caso abaixo preenche SÓ o campo secundário, então remover aquele campo da lista
 * quebra o teste.
 *
 * Campos conferidos no Tag_Guard real do pacote Site Kit 1.186.0:
 *   Analytics_4/Tag_Guard.php:33   useSnippet && measurementID
 *   Tag_Manager/Tag_Guard.php:55   is_amp ? ampContainerID : containerID
 */
uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => '', 'googleTagID' => 'GT-ABC123' ),
	) ),
	'googleTagID sem measurementID não caracteriza injeção do GA4'
);

uonix_analytics_assert_same(
	'tagmanager',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_tagmanager_settings' => array( 'useSnippet' => true, 'containerID' => '', 'ampContainerID' => 'GTM-AMP999' ),
	) ),
	'ampContainerID sozinho caracteriza injeção (é o campo do Tag_Guard quando is_amp)'
);

/*
 * O módulo Ads NÃO tem gate de useSnippet: Ads.php:337 register_tag() injeta assim que
 * conversionID existe. Emite gtag.js (AW-*), que compartilha gtag/dataLayer com o GA4.
 */
uonix_analytics_assert_same(
	'ads',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_ads_settings' => array( 'conversionID' => 'AW-987654321' ),
	) ),
	'Ads com conversionID injeta gtag mesmo SEM useSnippet — precisa ser detectado'
);

uonix_analytics_assert_same(
	'ads',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_ads_settings' => array( 'conversionID' => '', 'paxConversionID' => 'AW-111222333' ),
	) ),
	'paxConversionID sozinho também caracteriza injeção do Ads'
);

uonix_analytics_assert_same(
	'',
	uonix_site_kit_injeta_medicao( array(
		'googlesitekit_ads_settings' => array( 'conversionID' => '' ),
	) ),
	'Ads sem conversionID não injeta'
);

// A guarda precisa ESTAR LIGADA ao fluxo, não só definida.
//
// Buscar o nome da função no código-fonte é asserção fraca: o nome sobrevive no
// comentário e na própria definição, então trocar a chamada por `if ( false )` passaria
// — uma mutação provou exatamente isso. Aqui exercitamos o COMPORTAMENTO real, com as
// options do Site Kit injetadas via stub de get_option().

/*
 * Migração para Site Kit: quando o módulo Tag Manager passa a emitir o container,
 * o snippet legado não pode duplicar GTM/noscript. A camada AdOpt, porém, continua
 * sendo responsabilidade deste MU-plugin e precisa permanecer presente para manter
 * o consentimento que governa GA4 e Meta Pixel dentro do mesmo container.
 */
$GLOBALS['uonix_test_options'] = array(
	'googlesitekit_tagmanager_settings' => array( 'useSnippet' => true, 'containerID' => 'GTM-REAL123' ),
);

uonix_analytics_assert_same(
	$valid,
	uonix_analytics_configuration( 'production', true, 'GTM-REAL123', 'real-adopt-id' ),
	'Site Kit assumindo o GTM não invalida a configuração necessária ao AdOpt'
);

ob_start();
uonix_render_analytics_head( $valid );
$site_kit_head_html = ob_get_clean();
uonix_analytics_assert_contains( 'real-adopt-id', $site_kit_head_html, 'Site Kit no GTM preserva o carregamento do AdOpt' );
uonix_analytics_assert_not_contains( 'googletagmanager.com/gtm.js?id=', $site_kit_head_html, 'Site Kit no GTM suprime o snippet GTM legado no head' );

ob_start();
uonix_render_analytics_body( $valid );
$site_kit_body_html = ob_get_clean();
uonix_analytics_assert_contains( 'uonix-cookie-root', $site_kit_body_html, 'Site Kit no GTM preserva o root do banner AdOpt' );
uonix_analytics_assert_not_contains( 'googletagmanager.com/ns.html?id=', $site_kit_body_html, 'Site Kit no GTM suprime o noscript GTM legado' );

$GLOBALS['uonix_test_options'] = array(
	'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => 'G-CONFLITO1' ),
);

uonix_analytics_assert_same(
	false,
	uonix_analytics_configuration( 'production', true, 'GTM-REAL123', 'adopt-real-id' ),
	'com o Site Kit injetando GA4, a configuração precisa recuar (senão há contagem dupla)'
);

// Estado atual de produção: Site Kit ativo, useSnippet ligado, mas IDs vazios.
// O container GTM deve continuar sendo emitido normalmente.
$GLOBALS['uonix_test_options'] = array(
	'googlesitekit_analytics-4_settings' => array( 'useSnippet' => true, 'measurementID' => '' ),
	'googlesitekit_tagmanager_settings'  => array( 'useSnippet' => true, 'containerID' => '' ),
);

uonix_analytics_assert_same(
	true,
	is_array( uonix_analytics_configuration( 'production', true, 'GTM-REAL123', 'adopt-real-id' ) ),
	'Site Kit sem IDs configurados não deve suspender o container GTM'
);

// Site Kit removido/ausente: nada muda para o snippet.
$GLOBALS['uonix_test_options'] = array();

uonix_analytics_assert_same(
	true,
	is_array( uonix_analytics_configuration( 'production', true, 'GTM-REAL123', 'adopt-real-id' ) ),
	'sem o Site Kit instalado, a configuração válida continua sendo aceita'
);

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: analytics e consentimento ficam restritos à produção configurada.\n" );
