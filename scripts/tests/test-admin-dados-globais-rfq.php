<?php
/**
 * Testes da tela "Dados da Uônix": gravação com capability, nonce e allowlist.
 *
 * Regressão coberta: antes, a gravação acontecia no render da página, disparada
 * apenas pela presença de um campo no POST, sem nonce e com o nome da option
 * vindo do próprio POST.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$failures = 0;
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_actions'] = array();
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_nonce_field_action'] = null;

function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

/** Interrompe o fluxo no lugar do exit(), preservando a URL de destino. */
class Uox_Redirect_Exception extends RuntimeException {
	public $url;
	public function __construct( $url ) { $this->url = $url; parent::__construct( 'redirect' ); }
}
class Uox_Die_Exception extends RuntimeException {}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['uox_actions'][ $hook ] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_shortcode( $tag, $callback ) { return true; }
function add_menu_page() { return ''; }

function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['uox_options'][ $key ] = $value; return true; }

function current_user_can( $capability ) { return (bool) $GLOBALS['uox_can']; }
function check_admin_referer( $action = -1 ) {
	// Registra a ação verificada para que o teste possa confrontá-la com a emitida
	// pelo formulário. Um stub que ignora o argumento deixaria passar uma
	// divergência que quebraria 100% das gravações legítimas em produção.
	$GLOBALS['uox_referer_action'] = $action;
	if ( ! $GLOBALS['uox_referer_ok'] ) {
		throw new Uox_Die_Exception( 'nonce invalido' );
	}
	return true;
}
function wp_die( $message = '', $title = '', $args = array() ) { throw new Uox_Die_Exception( (string) $message ); }
function wp_safe_redirect( $url ) { throw new Uox_Redirect_Exception( $url ); }

function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function admin_url( $path = '' ) { return 'https://uonix.com.br/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }
function wp_nonce_field( $action = -1 ) { $GLOBALS['uox_nonce_field_action'] = $action; echo '<input type="hidden" name="_wpnonce" value="stub">'; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php';

// ---------------------------------------------------------------------------
// Allowlist derivada do mapa de campos, sem segunda lista para divergir.
// ---------------------------------------------------------------------------
$permitidas = uox_dados_globais_chaves_permitidas();
uox_assert( in_array( 'telefone_1', $permitidas, true ), 'Allowlist inclui chave real de contato' );
uox_assert( in_array( 'rota_orcamento', $permitidas, true ), 'Allowlist inclui chave real de roteamento' );
uox_assert( ! in_array( 'inputs', $permitidas, true ) && ! in_array( 'label', $permitidas, true ), 'Allowlist não vaza as chaves estruturais do mapa' );
uox_assert( ! in_array( 'hackeado', $permitidas, true ), 'Allowlist não aceita chave arbitrária' );
uox_assert( $permitidas === array_unique( $permitidas ), 'Allowlist não tem chave duplicada' );
uox_assert( array() === array_filter( $permitidas, static function ( $chave ) { return ! is_string( $chave ); } ), 'Toda chave da allowlist é string' );

// ---------------------------------------------------------------------------
// O render não grava mais nada. Esta é a regressão que importa: antes, este POST
// bastava para gravar, sem nonce nenhum.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array( 'uox_telefone_1' => 'original' );
$_POST = array( 'uox_salvar_dados' => '1', 'uox_dados' => array( 'telefone_1' => 'injetado por CSRF' ) );
$_GET = array();
ob_start();
uox_render_dados_page();
$html = (string) ob_get_clean();
uox_assert( 'original' === get_option( 'uox_telefone_1' ), 'POST no render não grava: o caminho de CSRF está fechado' );

// ---------------------------------------------------------------------------
// O formulário precisa apontar para o handler e carregar o nonce. Sem isso, a
// gravação legítima passa a ser recusada e a tela fica silenciosamente quebrada.
// ---------------------------------------------------------------------------
uox_assert( false !== strpos( $html, 'admin-post.php' ), 'Formulário envia para admin-post.php' );
uox_assert( false !== strpos( $html, 'name="action" value="uox_salvar_dados_globais"' ), 'Formulário declara a ação do handler' );
uox_assert( false !== strpos( $html, 'name="_wpnonce"' ), 'Formulário carrega campo de nonce' );

// ---------------------------------------------------------------------------
// Handler: capability.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = false;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_options'] = array( 'uox_telefone_1' => 'original' );
$_POST = array( 'uox_dados' => array( 'telefone_1' => 'sem permissao' ) );
try {
	uox_salvar_dados_globais();
	uox_assert( false, 'Handler sem capability deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Handler sem capability interrompe' );
} catch ( Uox_Redirect_Exception $e ) {
	uox_assert( false, 'Handler sem capability não pode chegar ao redirect' );
}
uox_assert( 'original' === get_option( 'uox_telefone_1' ), 'Handler sem capability não grava' );

// ---------------------------------------------------------------------------
// Handler: nonce.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = false;
$GLOBALS['uox_options'] = array( 'uox_telefone_1' => 'original' );
$_POST = array( 'uox_dados' => array( 'telefone_1' => 'sem nonce' ) );
try {
	uox_salvar_dados_globais();
	uox_assert( false, 'Handler sem nonce válido deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Handler sem nonce válido interrompe' );
} catch ( Uox_Redirect_Exception $e ) {
	uox_assert( false, 'Handler sem nonce válido não pode chegar ao redirect' );
}
uox_assert( 'original' === get_option( 'uox_telefone_1' ), 'Handler sem nonce válido não grava' );

// ---------------------------------------------------------------------------
// Handler: allowlist de chave e tipo de valor.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_options'] = array();
$_POST = array(
	'uox_dados' => array(
		'telefone_1'     => '  11 4372 9366  ',
		'hackeado'       => 'valor arbitrario',
		'active_plugins' => 'tentativa de escalada',
		'rota_orcamento' => array( 'array' => 'nao escalar' ),
		// PHP converte chave numérica de array para int. Quem barra este vetor é a
		// comparação estrita do `in_array` — verificado por mutação: remover o
		// `is_string` do handler não abre a porta. O `is_string` fica como defesa
		// explícita e redundante, não como o guarda efetivo.
		'123'            => 'chave numerica',
		'telefone_1 '    => 'chave com espaco a direita',
		'TELEFONE_1'     => 'chave em caixa alta',
	),
);
$redirect = '';
try {
	uox_salvar_dados_globais();
	uox_assert( false, 'Handler válido deveria redirecionar' );
} catch ( Uox_Redirect_Exception $e ) {
	$redirect = (string) $e->url;
}
uox_assert( '11 4372 9366' === get_option( 'uox_telefone_1' ), 'Chave permitida é gravada com valor sanitizado' );
uox_assert( false === get_option( 'uox_hackeado' ), 'Chave fora da allowlist não é gravada' );
uox_assert( false === get_option( 'uox_active_plugins' ), 'Chave de escalada não é gravada nem com o prefixo' );
uox_assert( false === get_option( 'uox_rota_orcamento' ), 'Valor não escalar é recusado' );
uox_assert( false === get_option( 'uox_123' ), 'Chave numérica, que PHP converte para int, é recusada' );
uox_assert( false === get_option( 'uox_telefone_1 ' ), 'Chave permitida com espaço à direita é recusada' );
uox_assert( false === get_option( 'uox_TELEFONE_1' ), 'Chave permitida em caixa diferente é recusada' );
uox_assert( 1 === count( $GLOBALS['uox_options'] ), 'Apenas a chave permitida chegou ao banco' );
uox_assert( false !== strpos( $redirect, 'uox_salvos=1' ), 'Redirect informa quantos campos foram salvos' );
uox_assert( false !== strpos( $redirect, 'uox_recusados=6' ), 'Redirect informa quantos campos foram recusados' );

// ---------------------------------------------------------------------------
// As duas pontas do nonce têm que usar a mesma ação. Se divergirem, nenhuma
// gravação legítima passa — e a tela quebra em silêncio, sem erro visível.
// ---------------------------------------------------------------------------
uox_assert( null !== $GLOBALS['uox_nonce_field_action'], 'Formulário emitiu campo de nonce com uma ação' );
uox_assert( null !== $GLOBALS['uox_referer_action'], 'Handler verificou o nonce com uma ação' );
uox_assert( $GLOBALS['uox_nonce_field_action'] === $GLOBALS['uox_referer_action'], 'A ação do nonce emitida pelo formulário é a mesma verificada pelo handler' );

// ---------------------------------------------------------------------------
// Handler registrado no hook correto.
// ---------------------------------------------------------------------------
uox_assert( isset( $GLOBALS['uox_actions']['admin_post_uox_salvar_dados_globais'] ), 'Handler está registrado em admin_post_uox_salvar_dados_globais' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: dados globais gravados apenas com capability, nonce e chave em allowlist.\n";
