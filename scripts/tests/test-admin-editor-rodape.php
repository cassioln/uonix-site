<?php
/**
 * Teste: perfil editor vê widgets.php como "Editar Rodapé", só com o rodapé.
 *
 * MOTIVAÇÃO (pedido de 2026-10-02): o editor precisa editar o rodapé sem ver
 * nem tocar Barra Lateral, Max Mega Menu Widgets e Widgets inativos. A área
 * mega-menu guarda o conteúdo do megamenu; um PUT numa área do rodapé que
 * receba um widget de lá o tira do megamenu.
 *
 * O teste falha se o menu, o título ou os filtros vazarem para o
 * administrador ou outras telas, se a listagem mostrar áreas fora do rodapé,
 * se a guarda deixar passar escrita fora do rodapé, ou se o módulo sair do
 * loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_caps']    = array();
$GLOBALS['uox_test_menus']   = array();
$GLOBALS['uox_test_inline']  = array();
$GLOBALS['uox_test_widgets'] = array();
$GLOBALS['pagenow']          = 'index.php';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['uox_test_caps'], true );
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['uox_test_menus'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'position' );
	return 'toplevel_page_widgets';
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['uox_test_inline'][] = compact( 'handle', 'data', 'position' );
	return true;
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function wp_find_widgets_sidebar( $widget_id ) {
	return $GLOBALS['uox_test_widgets'][ $widget_id ] ?? null;
}

class WP_Error {
	public $code;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}
}

class WP_REST_Response {
	private $data;

	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data( $data ) {
		$this->data = $data;
	}
}

/**
 * Imita a ordem do WP_REST_Request: JSON, POST e GET vêm antes do parâmetro
 * da URL, então um "id" no corpo ou na query passa por cima do id da rota.
 */
class Uox_Test_Request {
	private $route;
	private $method;
	private $params;
	private $url_params = array();

	public function __construct( $method, $route, array $params = array() ) {
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
		if ( 1 === preg_match( '#/(?:sidebars|widgets)/([\w-]+)/?$#i', $route, $m ) ) {
			$this->url_params['id'] = $m[1];
		}
	}

	public function get_route() {
		return $this->route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? $this->url_params[ $key ] ?? null;
	}
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/64-admin-editor-rodape.php';

$failures = 0;

function uox_er_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_er_como_editor() {
	$GLOBALS['uox_test_caps'] = array( 'edit_theme_options' );
}

function uox_er_como_admin() {
	$GLOBALS['uox_test_caps'] = array( 'edit_theme_options', 'manage_options' );
}

function uox_er_guarda( $method, $route, array $params = array() ) {
	return uonix_admin_editor_rodape_guarda( null, array(), new Uox_Test_Request( $method, $route, $params ) );
}

function uox_er_recusou( $resultado ) {
	return $resultado instanceof WP_Error && 'uonix_editor_rodape_fora_do_rodape' === $resultado->code && 403 === ( $resultado->data['status'] ?? null );
}

// Loader.
$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_er_assert( false !== strpos( $loader, "'64-admin-editor-rodape.php'" ), 'module.php deve carregar 64-admin-editor-rodape.php' );

// Áreas do rodapé.
uox_er_assert( uonix_admin_editor_rodape_area_do_rodape( 'footer1' ), 'footer1 é rodapé' );
uox_er_assert( uonix_admin_editor_rodape_area_do_rodape( 'footer6' ), 'footer6 é rodapé' );
foreach ( array( 'sidebar-primary', 'sidebar-secondary', 'mega-menu', 'wp_inactive_widgets', 'footer1-x', 'xfooter1', 'footer', 'footer7', 'footer0', 'FOOTER1', '' ) as $nao ) {
	uox_er_assert( ! uonix_admin_editor_rodape_area_do_rodape( $nao ), "{$nao} não é rodapé" );
}
uox_er_assert( ! uonix_admin_editor_rodape_area_do_rodape( null ), 'null não é rodapé' );

// Menu lateral.
uox_er_como_admin();
uonix_admin_editor_rodape_menu();
uox_er_assert( array() === $GLOBALS['uox_test_menus'], 'administrador não ganha o item Rodapé' );

uox_er_como_editor();
uonix_admin_editor_rodape_menu();
$menu = $GLOBALS['uox_test_menus'][0] ?? array();
uox_er_assert( 1 === count( $GLOBALS['uox_test_menus'] ), 'editor ganha um item de menu' );
uox_er_assert( 'Rodapé' === ( $menu['menu_title'] ?? null ), 'item do menu se chama Rodapé' );
uox_er_assert( 'widgets.php' === ( $menu['menu_slug'] ?? null ), 'item do menu aponta para widgets.php' );
uox_er_assert( 'edit_theme_options' === ( $menu['capability'] ?? null ), 'item do menu exige edit_theme_options' );

// parent_file e título PHP.
$GLOBALS['pagenow'] = 'widgets.php';
uox_er_assert( 'widgets.php' === uonix_admin_editor_rodape_parent_file( 'themes.php' ), 'editor em widgets.php destaca Rodapé' );
uox_er_assert( 'Editar Rodapé' === uonix_admin_editor_rodape_gettext( 'Widgets', 'Widgets', 'default' ), 'editor vê Editar Rodapé no título' );
uox_er_assert( 'Blocos' === uonix_admin_editor_rodape_gettext( 'Blocos', 'Blocks', 'default' ), 'outras strings não mudam' );
uox_er_assert( 'Widgets' === uonix_admin_editor_rodape_gettext( 'Widgets', 'Widgets', 'kadence' ), 'outro domínio não muda' );

$GLOBALS['pagenow'] = 'index.php';
uox_er_assert( 'themes.php' === uonix_admin_editor_rodape_parent_file( 'themes.php' ), 'fora de widgets.php o parent_file não muda' );
uox_er_assert( 'Widgets' === uonix_admin_editor_rodape_gettext( 'Widgets', 'Widgets', 'default' ), 'fora de widgets.php o título não muda' );

uox_er_como_admin();
$GLOBALS['pagenow'] = 'widgets.php';
uox_er_assert( 'themes.php' === uonix_admin_editor_rodape_parent_file( 'themes.php' ), 'administrador mantém o parent_file' );
uox_er_assert( 'Widgets' === uonix_admin_editor_rodape_gettext( 'Widgets', 'Widgets', 'default' ), 'administrador mantém o título Widgets' );

// Título JS.
uonix_admin_editor_rodape_titulo_js( 'widgets.php' );
uox_er_assert( array() === $GLOBALS['uox_test_inline'], 'administrador não recebe o script do título' );

uox_er_como_editor();
uonix_admin_editor_rodape_titulo_js( 'index.php' );
uox_er_assert( array() === $GLOBALS['uox_test_inline'], 'outras telas não recebem o script do título' );

uonix_admin_editor_rodape_titulo_js( 'widgets.php' );
$script = $GLOBALS['uox_test_inline'][0] ?? array();
uox_er_assert( 'wp-edit-widgets' === ( $script['handle'] ?? null ), 'script do título vai no wp-edit-widgets' );
uox_er_assert( 'before' === ( $script['position'] ?? null ), 'script do título roda antes do editor' );
// __( 'Widgets' ) do editor vem sem domínio: o i18n.gettext genérico recebe
// undefined e não casaria com 'default'. Só o gettext_default serve.
uox_er_assert( false !== strpos( (string) ( $script['data'] ?? '' ), "'i18n.gettext_default'" ), 'script usa o filtro i18n.gettext_default' );
uox_er_assert( false === strpos( (string) ( $script['data'] ?? '' ), 'dominio' ), 'script não depende do domínio recebido' );
uox_er_assert( false !== strpos( (string) ( $script['data'] ?? '' ), 'Editar Rodapé' ), 'script troca para Editar Rodapé' );

// Listagens.
$GLOBALS['uox_test_widgets'] = array(
	'block-10' => 'footer1',
	'block-11' => 'footer3',
	'block-85' => 'mega-menu',
	'block-20' => 'sidebar-primary',
	'block-30' => 'wp_inactive_widgets',
);

$areas    = new WP_REST_Response(
	array(
		array( 'id' => 'sidebar-primary' ),
		array( 'id' => 'footer1' ),
		array( 'id' => 'mega-menu' ),
		array( 'id' => 'wp_inactive_widgets' ),
		array( 'id' => 'footer2' ),
	)
);
$filtrado = uonix_admin_editor_rodape_filtra_listas( $areas, array(), new Uox_Test_Request( 'GET', '/wp/v2/sidebars' ) );
uox_er_assert( array( array( 'id' => 'footer1' ), array( 'id' => 'footer2' ) ) === $filtrado->get_data(), 'listagem de áreas mostra só o rodapé, reindexada' );

$widgets  = new WP_REST_Response(
	array(
		array( 'id' => 'block-10', 'sidebar' => 'footer1' ),
		array( 'id' => 'block-85', 'sidebar' => 'mega-menu' ),
		array( 'id' => 'block-30', 'sidebar' => 'wp_inactive_widgets' ),
		array( 'id' => 'block-11', 'sidebar' => 'footer3' ),
	)
);
$filtrado = uonix_admin_editor_rodape_filtra_listas( $widgets, array(), new Uox_Test_Request( 'GET', '/wp/v2/widgets/' ) );
uox_er_assert( array( 'block-10', 'block-11' ) === array_column( $filtrado->get_data(), 'id' ), 'listagem de widgets mostra só o rodapé' );

$post     = new WP_REST_Response( array( array( 'id' => 'mega-menu' ) ) );
$filtrado = uonix_admin_editor_rodape_filtra_listas( $post, array(), new Uox_Test_Request( 'POST', '/wp/v2/sidebars' ) );
uox_er_assert( array( array( 'id' => 'mega-menu' ) ) === $filtrado->get_data(), 'escrita não é filtrada na saída' );

$outra    = new WP_REST_Response( array( array( 'id' => 'mega-menu' ) ) );
$filtrado = uonix_admin_editor_rodape_filtra_listas( $outra, array(), new Uox_Test_Request( 'GET', '/wp/v2/posts' ) );
uox_er_assert( array( array( 'id' => 'mega-menu' ) ) === $filtrado->get_data(), 'outras rotas não são filtradas' );

$erro = new WP_Error( 'x' );
uox_er_assert( $erro === uonix_admin_editor_rodape_filtra_listas( $erro, array(), new Uox_Test_Request( 'GET', '/wp/v2/sidebars' ) ), 'erro passa intacto' );

// Guarda: áreas.
uox_er_assert( uox_er_recusou( uox_er_guarda( 'GET', '/wp/v2/sidebars/mega-menu' ) ), 'ler mega-menu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/sidebar-primary', array( 'widgets' => array() ) ) ), 'gravar Barra Lateral é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/wp_inactive_widgets', array( 'widgets' => array() ) ) ), 'gravar Widgets inativos é recusado' );
uox_er_assert( null === uox_er_guarda( 'GET', '/wp/v2/sidebars/footer1' ), 'ler footer1 passa' );
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/sidebars/footer1', array( 'widgets' => array( 'block-10', 'block-11', 'block-99' ) ) ), 'reordenar e mover entre áreas do rodapé passa' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/footer1', array( 'widgets' => array( 'block-10', 'block-85' ) ) ) ), 'puxar widget do megamenu para o rodapé é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/footer1', array( 'widgets' => array( 'block-30' ) ) ) ), 'puxar widget inativo para o rodapé é recusado' );

// Guarda: widgets.
uox_er_assert( uox_er_recusou( uox_er_guarda( 'GET', '/wp/v2/widgets/block-85' ) ), 'ler widget do megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'DELETE', '/wp/v2/widgets/block-85' ) ), 'apagar widget do megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/widgets/block-20', array( 'sidebar' => 'footer1' ) ) ), 'trazer widget da Barra Lateral é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'sidebar' => 'mega-menu' ) ) ), 'mandar widget do rodapé para o megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'sidebar' => 'wp_inactive_widgets' ) ) ), 'mandar widget do rodapé para inativos é recusado' );
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'sidebar' => 'footer2' ) ), 'mover widget entre áreas do rodapé passa' );
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'instance' => array() ) ), 'editar widget do rodapé passa' );
uox_er_assert( null === uox_er_guarda( 'DELETE', '/wp/v2/widgets/block-10' ), 'apagar widget do rodapé passa' );
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/widgets/block-99', array( 'sidebar' => 'footer1' ) ), 'widget sem área pode entrar no rodapé' );

// Guarda: criação.
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/widgets', array( 'sidebar' => 'mega-menu' ) ) ), 'criar widget no megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/widgets' ) ), 'criar widget sem área é recusado' );
uox_er_assert( null === uox_er_guarda( 'POST', '/wp/v2/widgets', array( 'sidebar' => 'footer4' ) ), 'criar widget no rodapé passa' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/widgets', array( 'sidebar' => 'footer1', 'id' => 'block-85', 'instance' => array() ) ) ), 'POST com id do megamenu (sobrescreve e move) é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/widgets', array( 'sidebar' => 'footer1', 'id' => 'block-30' ) ) ), 'POST com id de widget inativo é recusado' );
uox_er_assert( null === uox_er_guarda( 'POST', '/wp/v2/widgets', array( 'sidebar' => 'footer2', 'id' => 'block-10' ) ), 'POST com id de widget do rodapé passa' );
uox_er_assert( null === uox_er_guarda( 'GET', '/wp/v2/widgets' ), 'listar widgets passa (filtrado na saída)' );

// Rotas com maiúsculas: o núcleo casa com a flag i, então a guarda também.
// Vale para ?rest_route= e para cada item de batch/v1 e uonix/v1/lote.
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/SIDEBARS/mega-menu', array( 'widgets' => array() ) ) ), 'PUT em /wp/v2/SIDEBARS/mega-menu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/Sidebars/mega-menu/', array( 'widgets' => array() ) ) ), 'PUT em /wp/v2/Sidebars/mega-menu/ é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/WP/V2/sidebars/footer1', array( 'widgets' => array( 'block-85' ) ) ) ), 'PUT em /WP/V2/sidebars/footer1 puxando o megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'DELETE', '/WP/V2/widgets/block-85' ) ), 'DELETE em /WP/V2/widgets/block-85 é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/Widgets/block-10', array( 'sidebar' => 'wp_inactive_widgets' ) ) ), 'PUT em /wp/v2/Widgets mandando para inativos é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/WIDGETS', array( 'sidebar' => 'mega-menu' ) ) ), 'POST em /wp/v2/WIDGETS no megamenu é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'POST', '/wp/v2/WIDGETS', array( 'sidebar' => 'footer1', 'id' => 'block-85' ) ) ), 'POST em /wp/v2/WIDGETS com id do megamenu é recusado' );
// "id" no corpo ou na query passa por cima do id da URL no núcleo: o pedido
// ambíguo é recusado, mesmo com a URL apontando para o rodapé.
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/footer1', array( 'id' => 'mega-menu', 'widgets' => array() ) ) ), 'PUT em footer1 com id mega-menu no corpo é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'DELETE', '/wp/v2/widgets/block-10', array( 'id' => 'block-85', 'force' => true ) ) ), 'DELETE em block-10 com id block-85 no corpo é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'id' => 'block-85', 'sidebar' => 'footer1', 'instance' => array() ) ) ), 'PUT em block-10 com id block-85 no corpo é recusado' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'id' => 'block-11' ) ) ), 'id do corpo diferente da URL é recusado mesmo dentro do rodapé' );
uox_er_assert( uox_er_recusou( uox_er_guarda( 'PUT', '/wp/v2/sidebars/footer1', array( 'id' => 42 ) ) ), 'id que não é texto é recusado' );
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/widgets/block-10', array( 'id' => 'block-10', 'sidebar' => 'footer2' ) ), 'id do corpo igual ao da URL passa' );

uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/posts/1' ), 'outras rotas passam' );

// Resposta já definida por outro filtro segue intacta.
$anterior = new WP_Error( 'outro' );
uox_er_assert( $anterior === uonix_admin_editor_rodape_guarda( $anterior, array(), new Uox_Test_Request( 'GET', '/wp/v2/sidebars/footer1' ) ), 'resposta anterior é preservada' );

// Administrador não é afetado.
uox_er_como_admin();
uox_er_assert( null === uox_er_guarda( 'PUT', '/wp/v2/sidebars/mega-menu', array( 'widgets' => array( 'block-85' ) ) ), 'administrador grava o megamenu' );
uox_er_assert( null === uox_er_guarda( 'DELETE', '/wp/v2/widgets/block-85' ), 'administrador apaga widget do megamenu' );
$areas    = new WP_REST_Response( array( array( 'id' => 'mega-menu' ), array( 'id' => 'footer1' ) ) );
$filtrado = uonix_admin_editor_rodape_filtra_listas( $areas, array(), new Uox_Test_Request( 'GET', '/wp/v2/sidebars' ) );
uox_er_assert( 2 === count( $filtrado->get_data() ), 'administrador vê todas as áreas' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s)\n" );
	exit( 1 );
}

echo "OK: editor vê e altera só as áreas do rodapé em Editar Rodapé\n";
