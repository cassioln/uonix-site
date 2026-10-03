<?php
/**
 * Stubs de WordPress para as "Atualizações Sistêmicas" do card de manutenção.
 *
 * Compartilhado por scripts/tests/test-admin-atualizacoes-pendentes.php (asserções
 * sobre o HTML) e por atualizacoes-sistemicas-cenario.php (gera o HTML que o teste
 * de navegador executa). Os dados vêm de $GLOBALS: uox_test_site_transients,
 * uox_test_plugins e uox_test_themes.
 */

define( 'ABSPATH', dirname( __DIR__ ) );

$GLOBALS['uox_test_site_transients'] = array();
$GLOBALS['uox_test_plugins']         = array();
$GLOBALS['uox_test_themes']          = array();
$GLOBALS['wp_version']               = '6.8.1';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_site_transient( $key ) {
	return $GLOBALS['uox_test_site_transients'][ $key ] ?? false;
}

function get_plugins() {
	return $GLOBALS['uox_test_plugins'];
}

class Uox_Test_Theme {
	private $headers;

	public function __construct( $headers ) {
		$this->headers = $headers;
	}

	public function exists() {
		return null !== $this->headers;
	}

	public function get( $header ) {
		return $this->headers[ $header ] ?? false;
	}
}

function wp_get_theme( $stylesheet ) {
	return new Uox_Test_Theme( $GLOBALS['uox_test_themes'][ $stylesheet ] ?? null );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__, 3 ) . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php';
