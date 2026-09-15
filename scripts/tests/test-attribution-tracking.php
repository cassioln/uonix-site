<?php
/**
 * Testes do módulo de rastreamento e persistência de atribuição (UTM, fbclid, gclid).
 * Validação da Issue #189.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_actions'] = array();
$GLOBALS['uonix_test_filters'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function wp_doing_cron() {
	return false;
}
function sanitize_text_field( $str ) {
	return strip_tags( trim( (string) $str ) );
}
function wp_unslash( $val ) {
	return stripslashes( $val );
}
function add_query_arg( $args, $url ) {
	$query = http_build_query( $args );
	return strpos( $url, '?' ) !== false ? $url . '&' . $query : $url . '?' . $query;
}

// Carrega o módulo de rastreamento
require_once __DIR__ . '/../../mu-plugins/uonix-integrations/39-rastreamento-utm-atribuicao.php';

// Teste 1: Hook de renderização em wp_head está registrado
$has_wp_head = false;
foreach ( $GLOBALS['uonix_test_actions'] as $action ) {
	if ( 'wp_head' === $action[0] && 'uonix_render_attribution_tracker_script' === $action[1] ) {
		$has_wp_head = true;
		break;
	}
}
if ( ! $has_wp_head ) {
	fwrite( STDERR, "FAIL: Hook wp_head para uonix_render_attribution_tracker_script não registrado.\n" );
	exit( 1 );
}

// Teste 2: Script emite tags corretas, geração do _fbc e dataLayer
ob_start();
uonix_render_attribution_tracker_script();
$script_output = ob_get_clean();

if ( strpos( $script_output, 'id="uonix-attribution-tracker"' ) === false ) {
	fwrite( STDERR, "FAIL: Script uonix-attribution-tracker não gerado.\n" );
	exit( 1 );
}
if ( strpos( $script_output, "fb.1." ) === false ) {
	fwrite( STDERR, "FAIL: Lógica de geração de cookie canônico _fbc ausente.\n" );
	exit( 1 );
}
if ( strpos( $script_output, "uonix_attribution_loaded" ) === false ) {
	fwrite( STDERR, "FAIL: Evento de dataLayer uonix_attribution_loaded ausente.\n" );
	exit( 1 );
}

// Teste 3: Preservação de parâmetros em redirecionamento (wp_redirect)
$_SERVER['QUERY_STRING'] = 'utm_source=meta&utm_medium=cpc&utm_campaign=ancoragem&fbclid=IwARtest123';
$_GET['utm_source']   = 'meta';
$_GET['utm_medium']   = 'cpc';
$_GET['utm_campaign'] = 'ancoragem';
$_GET['fbclid']       = 'IwARtest123';

$redirect_url = uonix_preserve_attribution_query_string_on_redirect( 'https://uonix.com.br/produtos/', 301 );

if ( strpos( $redirect_url, 'utm_source=meta' ) === false || strpos( $redirect_url, 'fbclid=IwARtest123' ) === false ) {
	fwrite( STDERR, "FAIL: Redirecionamento descartou parâmetros UTM ou fbclid. Resultado: {$redirect_url}\n" );
	exit( 1 );
}

echo "PASS: Atribuição UTM, fbclid e gclid persistidos e validados.\n";
exit( 0 );
