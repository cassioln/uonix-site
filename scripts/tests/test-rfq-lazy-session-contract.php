<?php
/**
 * Contrato da inicialização preguiçosa de sessão RFQ.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );
$module    = $repo_root . '/mu-plugins/uonix-woocommerce/31-rfq-lazy-cookie-session.php';

function fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fail( $message );
	}
}

assert_true( is_file( $module ), 'Módulo de sessão RFQ preguiçosa ausente.' );

$GLOBALS['uonix_lazy_hooks'] = array();
$GLOBALS['uonix_lazy_cookie_name'] = 'rfqtk_wp_session_test';
$GLOBALS['uonix_lazy_options'] = array(
	'settings_gpls_woo_rfq_cookie_or_phpsession' => 'rfq_cookie',
);
$_COOKIE = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_lazy_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['uonix_lazy_hooks'][ $hook ][ $priority ] ?? array() as $index => $registered ) {
		if ( $registered[0] === $callback ) {
			unset( $GLOBALS['uonix_lazy_hooks'][ $hook ][ $priority ][ $index ] );
		}
	}
}

function has_action( $hook, $callback = false ) {
	foreach ( $GLOBALS['uonix_lazy_hooks'][ $hook ] ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( false === $callback || $registered[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['uonix_lazy_options'][ $name ] ?? $default;
}

function defined_cookie_name() {
	return $GLOBALS['uonix_lazy_cookie_name'];
}

class RFQTK_WP_Session {
	public static $instances = 0;
	public static function get_instance() {
		self::$instances++;
		return new self();
	}
	public function session_started() { return true; }
	public function write_data() { return true; }
}

function RFQTK_wp_session_start() {
	return RFQTK_WP_Session::get_instance()->session_started();
}
function RFQTK_wp_session_write_close() {
	return RFQTK_WP_Session::get_instance()->write_data();
}

define( 'RFQTK_WP_SESSION_COOKIE', $GLOBALS['uonix_lazy_cookie_name'] );

// O terceiro registra os hooks incondicionais antes da callback MU tardia.
add_action( 'plugins_loaded', 'RFQTK_wp_session_start', 10 );
add_action( 'shutdown', 'RFQTK_wp_session_write_close', 10 );

require $module;

$lifecycle = $GLOBALS['uonix_lazy_hooks']['plugins_loaded'][0] ?? array();
assert_true( 1 === count( $lifecycle ), 'Política deve rodar antes do autostart RFQ.' );
$lifecycle[0][0]();
assert_true( false === has_action( 'plugins_loaded', 'RFQTK_wp_session_start' ), 'Autostart RFQ deve ser removido.' );
assert_true( false === has_action( 'shutdown', 'RFQTK_wp_session_write_close' ), 'Autocommit RFQ deve ser removido.' );
assert_true( 0 === RFQTK_WP_Session::$instances, 'GET público sem cookie não pode instanciar sessão.' );

// Retomar só ocorre quando o visitante já tem o cookie RFQ existente.
$_COOKIE[ $GLOBALS['uonix_lazy_cookie_name'] ] = 'opaque-existing-session';
RFQTK_WP_Session::$instances = 0;
$lifecycle[0][0]();
assert_true( 1 === RFQTK_WP_Session::$instances, 'Cookie RFQ existente deve ser retomado.' );

// O visitante com PHPSESSID é atendido pelo backend legado da política 30.
// Seus hooks não podem ser removidos pelo módulo lazy de cookie.
$GLOBALS['uonix_lazy_options']['settings_gpls_woo_rfq_cookie_or_phpsession'] = 'php_session';
add_action( 'plugins_loaded', 'RFQTK_wp_session_start', 10 );
add_action( 'shutdown', 'RFQTK_wp_session_write_close', 10 );
$lifecycle[0][0]();
assert_true( 10 === has_action( 'plugins_loaded', 'RFQTK_wp_session_start' ), 'Backend legado deve preservar autostart.' );
assert_true( 10 === has_action( 'shutdown', 'RFQTK_wp_session_write_close' ), 'Backend legado deve preservar autocommit.' );

echo "PASS: sessão RFQ só inicia para cookie existente ou chamada explícita do fluxo.\n";
