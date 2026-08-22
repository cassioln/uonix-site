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

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: analytics e consentimento ficam restritos à produção configurada.\n" );
