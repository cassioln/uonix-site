<?php
/**
 * Testes do painel administrativo de clone sem inicializar WordPress.
 */

define( 'ABSPATH', __DIR__ );
define( 'UONIX_GITHUB_TOKEN', 'fixture-token-never-render' );
define( 'UONIX_GITHUB_WORKFLOW_REF', 'qa' );

$GLOBALS['uonix_admin_actions']  = array();
$GLOBALS['uonix_remote_request'] = null;

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['uonix_admin_actions'][] = array( $hook, $callback, $priority );
}

function add_submenu_page() {}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function check_admin_referer() { return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_post( $url, $args ) {
	$GLOBALS['uonix_remote_request'] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 204 ), 'body' => '' );
}
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/48-admin-clone-ambientes.php';

$failures = 0;

function assert_same( $expected, $actual, $message ) {
	global $failures;
	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

function assert_contains( $needle, $haystack, $message ) {
	global $failures;
	if ( false === strpos( (string) $haystack, (string) $needle ) ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message} (ausente: {$needle})\n" );
	}
}

function assert_not_contains( $needle, $haystack, $message ) {
	global $failures;
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message} (encontrado: {$needle})\n" );
	}
}

assert_same( array( 'prod', 'qa', 'dev', 'local' ), array_keys( uox_clone_env_labels() ), 'painel lista quatro ambientes' );
assert_same( 'master', uox_clone_get_workflow_ref(), 'workflow ref canônico' );
assert_same( 'github-runner', uox_clone_execution_mode( 'prod', 'dev' ), 'par remoto usa runner' );
assert_same( 'mac', uox_clone_execution_mode( 'local', 'qa' ), 'par com local usa Mac' );
assert_same( 'CLONAR QA PARA PROD', uox_clone_required_confirmation( 'qa', 'prod' ), 'frase de produção' );
assert_same( true, uox_clone_pair_requires_ssh_window( 'prod', 'qa' ), 'produção exige janela SSH' );
assert_same( false, uox_clone_pair_requires_ssh_window( 'qa', 'dev' ), 'QA para DEV não exige janela Locaweb' );

$dry_command = uox_clone_build_local_command( 'qa', 'local', 'dry-run', false, '' );
assert_contains( '--source=', $dry_command, 'comando local inclui origem' );
assert_contains( '--target=', $dry_command, 'comando local inclui destino' );
assert_contains( '--dry-run', $dry_command, 'comando local usa dry-run' );
assert_not_contains( '--execute', $dry_command, 'dry-run não usa execute' );
assert_not_contains( 'fixture-token-never-render', $dry_command, 'token não aparece no comando' );
assert_not_contains( '--yes', $dry_command, 'interface legada removida' );

$execute_command = uox_clone_build_local_command( 'qa', 'prod', 'execute', true, 'CLONAR QA PARA PROD' );
assert_contains( '--execute', $execute_command, 'comando local usa execute' );
assert_contains( '--replace-users', $execute_command, 'replace-users explícito' );
assert_contains( '--confirmation=', $execute_command, 'confirmação encaminhada' );

$dispatch = uox_clone_dispatch_workflow( 'qa', 'dev', 'dry-run', false, '' );
assert_same( true, $dispatch, 'dispatch remoto aceito' );
$payload = json_decode( $GLOBALS['uonix_remote_request']['args']['body'], true );
assert_same( 'master', $payload['ref'], 'dispatch usa master' );
assert_same( 'dry-run', $payload['inputs']['mode'], 'dispatch encaminha modo' );
assert_same( 'false', $payload['inputs']['replace_users'], 'replace_users inicia false' );
assert_not_contains( 'fixture-token-never-render', $GLOBALS['uonix_remote_request']['url'], 'token não aparece na URL' );

$_POST = array(
	'uox_clone_action'        => 'clone',
	'uox_clone_source'        => 'qa',
	'uox_clone_target'        => 'qa',
	'uox_clone_mode'          => 'dry-run',
	'uox_clone_replace_users' => '',
);
$result = uox_clone_get_result_from_post();
assert_same( 'uox_clone_same_env', $result->get_error_code(), 'source==target bloqueado' );

// Clone PARA produção em modo execute é recusado pelo painel, independentemente da
// frase digitada: o workflow exige o SHA do commit na confirmação
// (clone-environment.yml) e o painel não o conhece. Sem esta recusa, o painel
// dispararia um clone destinado a falhar em validate-request.
$_POST = array(
	'uox_clone_action'       => 'clone',
	'uox_clone_source'       => 'qa',
	'uox_clone_target'       => 'prod',
	'uox_clone_mode'         => 'execute',
	'uox_clone_confirmation' => 'ERRADO',
);
$result = uox_clone_get_result_from_post();
assert_same( 'uox_clone_production_requires_manual_dispatch', $result->get_error_code(), 'produção com frase errada recusada' );

// Nem mesmo a frase que o painel considerava válida libera a operação: a frase sem
// SHA seria rejeitada pelo workflow, então o painel não deve prosseguir.
$_POST = array(
	'uox_clone_action'       => 'clone',
	'uox_clone_source'       => 'qa',
	'uox_clone_target'       => 'prod',
	'uox_clone_mode'         => 'execute',
	'uox_clone_confirmation' => uox_clone_required_confirmation( 'qa', 'prod' ),
);
$result = uox_clone_get_result_from_post();
assert_same( 'uox_clone_production_requires_manual_dispatch', $result->get_error_code(), 'produção com frase sem SHA também recusada' );

// Dry-run para produção continua permitido: não escreve nada e o workflow aceita
// confirmação vazia nesse ramo.
$_POST = array(
	'uox_clone_action' => 'clone',
	'uox_clone_source' => 'qa',
	'uox_clone_target' => 'prod',
	'uox_clone_mode'   => 'dry-run',
);
$result = uox_clone_get_result_from_post();
assert_same(
	false,
	is_wp_error( $result ) && 'uox_clone_production_requires_manual_dispatch' === $result->get_error_code(),
	'dry-run para produção não é recusado pela guarda de dispatch manual'
);

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: painel cobre quatro ambientes, runner/Mac, produção e token não exposto.\n" );
