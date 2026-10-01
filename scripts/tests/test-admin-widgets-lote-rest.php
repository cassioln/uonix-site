<?php
/**
 * Teste: rota alternativa ao batch/v1 para o editor de widgets salvar.
 *
 * MOTIVAÇÃO (medido em produção em 2026-10-01): o nginx da Locaweb devolve 403
 * em HTML a todo POST para batch/v1, e o editor de widgets salva por ali. A rota
 * uonix/v1/lote atende o mesmo lote do núcleo por outro caminho.
 *
 * O teste falha se a rota não for registrada com os argumentos do lote do
 * núcleo, se não delegar a serve_batch_request_v1, se o caminho contiver
 * batch/v1, se o script carregar fora de widgets.php, ou se o módulo não for
 * carregado pelo loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$raiz = dirname( __DIR__, 2 );
define( 'UONIX_MU_PATH', $raiz . '/mu-plugins/' );
define( 'UONIX_MU_URL', 'https://exemplo.test/wp-content/mu-plugins/' );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

class WP_REST_Request {}

class Uox_Servidor_Falso {
	public $rotas = array();
	public $recebido = null;

	public function get_routes() {
		return $this->rotas;
	}

	public function serve_batch_request_v1( WP_REST_Request $request ) {
		$this->recebido = $request;
		return 'resposta-do-lote';
	}
}

$GLOBALS['uox_test_actions']  = array();
$GLOBALS['uox_test_rotas']    = array();
$GLOBALS['uox_test_scripts']  = array();
$GLOBALS['uox_test_carregou'] = array();
$GLOBALS['uox_test_servidor'] = new Uox_Servidor_Falso();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	$GLOBALS['uox_test_rotas'][] = array( $namespace, $route, $args );
	return true;
}

function __return_true() {
	return true;
}

function rest_get_server() {
	return $GLOBALS['uox_test_servidor'];
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	$GLOBALS['uox_test_scripts'][] = array( $handle, $src, $deps, $ver );
}

function uonix_mu_require_files( $dir, $files, $module ) {
	$GLOBALS['uox_test_carregou'][ $module ] = $files;
}

require $raiz . '/mu-plugins/uonix-admin/module.php';
require_once $raiz . '/mu-plugins/uonix-admin/62-admin-widgets-lote-rest.php';

$failures = 0;

function uox_wl_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

// Loader executado, não lido como texto: comentar a linha também reprova.
uox_wl_assert( in_array( '62-admin-widgets-lote-rest.php', $GLOBALS['uox_test_carregou']['uonix-admin'] ?? array(), true ), 'módulo não é carregado por uonix-admin/module.php' );

$acao_rest  = $GLOBALS['uox_test_actions']['rest_api_init'][0] ?? null;
$acao_admin = $GLOBALS['uox_test_actions']['admin_enqueue_scripts'][0] ?? null;
uox_wl_assert( null !== $acao_rest && 'uonix_admin_widgets_lote_registrar_rota' === $acao_rest[0], 'rest_api_init não registra a rota' );
uox_wl_assert( null !== $acao_admin && 'uonix_admin_widgets_lote_enqueue' === $acao_admin[0], 'admin_enqueue_scripts não carrega o script' );

// Sem o lote do núcleo, nada é registrado.
uonix_admin_widgets_lote_registrar_rota();
uox_wl_assert( array() === $GLOBALS['uox_test_rotas'], 'registrou rota sem o batch/v1 do núcleo' );

// Com o lote do núcleo: mesma rota, mesmos argumentos, só POST, delegando ao núcleo.
$args_nucleo = array(
	'validation' => array( 'type' => 'string', 'enum' => array( 'require-all-validate', 'normal' ), 'default' => 'normal' ),
	'requests'   => array( 'required' => true, 'type' => 'array', 'maxItems' => 25 ),
);
$GLOBALS['uox_test_servidor']->rotas = array(
	'/batch/v1' => array(
		array(
			'methods' => array( 'POST' => true ),
			'args'    => $args_nucleo,
		),
	),
);
uonix_admin_widgets_lote_registrar_rota();
uox_wl_assert( 1 === count( $GLOBALS['uox_test_rotas'] ), 'deveria registrar exatamente uma rota' );
list( $ns, $rota, $def ) = $GLOBALS['uox_test_rotas'][0] ?? array( '', '', array() );
$caminho = '/' . $ns . $rota;
uox_wl_assert( '/uonix/v1/lote' === $caminho, "caminho inesperado: {$caminho}" );
uox_wl_assert( false === strpos( $caminho, 'batch/v1' ), 'o caminho não pode conter batch/v1' );
uox_wl_assert( 'POST' === ( $def['methods'] ?? null ), 'rota deveria aceitar só POST' );
uox_wl_assert( $args_nucleo === ( $def['args'] ?? null ), 'argumentos diferentes dos do lote do núcleo' );
uox_wl_assert( isset( $def['permission_callback'] ) && is_callable( $def['permission_callback'] ), 'permission_callback ausente' );
uox_wl_assert( ! isset( $def['allow_batch'] ), 'a rota de lote não pode aceitar lote aninhado' );

$req = new WP_REST_Request();
uox_wl_assert( isset( $def['callback'] ) && 'resposta-do-lote' === call_user_func( $def['callback'], $req ), 'callback não devolve a resposta do núcleo' );
uox_wl_assert( $req === $GLOBALS['uox_test_servidor']->recebido, 'callback não repassa a mesma requisição ao núcleo' );

// Script: só em Aparência > Widgets.
foreach ( array( 'index.php', 'customize.php', 'post.php', 'nav-menus.php', 'appearance_page_widgets-extra', 'widgets.php.bak', '' ) as $tela ) {
	$GLOBALS['uox_test_scripts'] = array();
	uonix_admin_widgets_lote_enqueue( $tela );
	uox_wl_assert( array() === $GLOBALS['uox_test_scripts'], "tela {$tela}: não deveria carregar o script" );
}

$GLOBALS['uox_test_scripts'] = array();
uonix_admin_widgets_lote_enqueue( 'widgets.php' );
$rel = 'uonix-admin/assets/js/admin-widgets-lote.js';
uox_wl_assert( 1 === count( $GLOBALS['uox_test_scripts'] ), 'widgets.php deveria carregar um script' );
$s = $GLOBALS['uox_test_scripts'][0] ?? array( '', '', array(), '' );
uox_wl_assert( UONIX_MU_URL . $rel === $s[1], 'URL do script inesperada' );
uox_wl_assert( in_array( 'wp-api-fetch', (array) $s[2], true ), 'script precisa depender de wp-api-fetch' );
uox_wl_assert( is_file( UONIX_MU_PATH . $rel ) && (string) filemtime( UONIX_MU_PATH . $rel ) === $s[3], 'versão do script deveria ser o filemtime do arquivo' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: rota uonix/v1/lote delega ao lote do núcleo e o script só carrega em widgets.php.\n";
