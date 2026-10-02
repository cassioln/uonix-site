<?php
/**
 * Teste: menu "Políticas e LGPD" do perfil editor.
 *
 * MOTIVAÇÃO (pedido de 2026-10-02): o editor precisa de um atalho para editar
 * Política de Privacidade, Política de Cookies e Termos de Uso, e para abrir o
 * painel da AdOpt.
 *
 * O teste falha se o menu vazar para o administrador, se um item apontar para
 * a página errada, se página inexistente entrar no menu, se o destaque do menu
 * mudar em telas que não são essas páginas, ou se o módulo sair do loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_caps']  = array();
$GLOBALS['uox_test_menus'] = array();
$GLOBALS['uox_test_posts'] = array();
$GLOBALS['submenu']        = array();
$GLOBALS['pagenow']        = 'index.php';

class WP_Post {
	public $post_type;
	public $post_status;

	public function __construct( $post_type, $post_status ) {
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['uox_test_caps'], true );
}

function get_post( $id ) {
	return $GLOBALS['uox_test_posts'][ $id ] ?? null;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['uox_test_menus'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'icon_url', 'position' );
	return 'toplevel_page_politicas';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/65-admin-editor-politicas-lgpd.php';

$failures = 0;

function uox_pl_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_pl_reset() {
	$GLOBALS['uox_test_menus'] = array();
	$GLOBALS['submenu']        = array();
}

function uox_pl_todas_as_paginas() {
	$GLOBALS['uox_test_posts'] = array(
		3     => new WP_Post( 'page', 'publish' ),
		11343 => new WP_Post( 'page', 'publish' ),
		11344 => new WP_Post( 'page', 'publish' ),
	);
}

$editor = array( 'edit_pages' );
$admin  = array( 'edit_pages', 'manage_options' );
$adopt  = 'https://dash.goadopt.io/org/uonix/disclaimers';

// Loader.
$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_pl_assert( false !== strpos( $loader, "'65-admin-editor-politicas-lgpd.php'" ), 'module.php deve carregar 65-admin-editor-politicas-lgpd.php' );

// Ids conferidos pelo endereço público: 3 privacidade, 11343 cookies, 11344 termos.
uox_pl_assert(
	array(
		'Política de Privacidade' => 3,
		'Política de Cookies'     => 11343,
		'Termos de Uso'           => 11344,
	) === uonix_admin_editor_politicas_paginas(),
	'títulos, ordem e ids das páginas'
);

// Administrador não ganha o menu.
uox_pl_todas_as_paginas();
$GLOBALS['uox_test_caps'] = $admin;
uox_pl_reset();
uonix_admin_editor_politicas_menu();
uox_pl_assert( array() === $GLOBALS['uox_test_menus'] && array() === $GLOBALS['submenu'], 'administrador não ganha o menu' );

// Sem edit_pages também não.
$GLOBALS['uox_test_caps'] = array();
uox_pl_reset();
uonix_admin_editor_politicas_menu();
uox_pl_assert( array() === $GLOBALS['uox_test_menus'], 'quem não edita páginas não ganha o menu' );

// Editor: menu e quatro itens, na ordem pedida.
$GLOBALS['uox_test_caps'] = $editor;
uox_pl_reset();
uonix_admin_editor_politicas_menu();
$menu = $GLOBALS['uox_test_menus'][0] ?? array();
uox_pl_assert( 1 === count( $GLOBALS['uox_test_menus'] ), 'editor ganha um item de menu' );
uox_pl_assert( 'Políticas e LGPD' === ( $menu['menu_title'] ?? null ), 'menu se chama Políticas e LGPD' );
uox_pl_assert( 'edit_pages' === ( $menu['capability'] ?? null ), 'menu exige edit_pages' );
uox_pl_assert( 'post.php?post=3&action=edit' === ( $menu['menu_slug'] ?? null ), 'menu pai abre a Política de Privacidade' );
uox_pl_assert(
	array(
		array( 'Política de Privacidade', 'edit_pages', 'post.php?post=3&action=edit' ),
		array( 'Política de Cookies', 'edit_pages', 'post.php?post=11343&action=edit' ),
		array( 'Termos de Uso', 'edit_pages', 'post.php?post=11344&action=edit' ),
		array( 'Adopt', 'edit_pages', $adopt ),
	) === ( $GLOBALS['submenu']['post.php?post=3&action=edit'] ?? null ),
	'submenu com as três páginas e a AdOpt, cada uma no endereço certo'
);

// Página ausente, na lixeira ou que não é página fica fora do menu.
$GLOBALS['uox_test_posts'] = array(
	3     => new WP_Post( 'page', 'trash' ),
	11343 => new WP_Post( 'page', 'draft' ),
	11344 => new WP_Post( 'post', 'publish' ),
);
uox_pl_reset();
uonix_admin_editor_politicas_menu();
uox_pl_assert( 'post.php?post=11343&action=edit' === ( $GLOBALS['uox_test_menus'][0]['menu_slug'] ?? null ), 'menu pai passa para a primeira página que existe' );
uox_pl_assert(
	array(
		array( 'Política de Cookies', 'edit_pages', 'post.php?post=11343&action=edit' ),
		array( 'Adopt', 'edit_pages', $adopt ),
	) === ( $GLOBALS['submenu']['post.php?post=11343&action=edit'] ?? null ),
	'só a página válida e a AdOpt ficam no submenu'
);

// Nenhuma página no ambiente: sem menu (o endereço da AdOpt não serve de slug).
$GLOBALS['uox_test_posts'] = array();
uox_pl_reset();
uonix_admin_editor_politicas_menu();
uox_pl_assert( array() === $GLOBALS['uox_test_menus'] && array() === $GLOBALS['submenu'], 'sem páginas, sem menu' );

// Destaque do menu ao editar cada página.
uox_pl_todas_as_paginas();
$GLOBALS['pagenow'] = 'post.php';
foreach ( array( 3, 11343, 11344 ) as $id ) {
	$_GET = array( 'post' => (string) $id, 'action' => 'edit' );
	uox_pl_assert( 'post.php?post=3&action=edit' === uonix_admin_editor_politicas_parent_file( 'edit.php?post_type=page' ), "post {$id}: destaca Políticas e LGPD" );
	uox_pl_assert( "post.php?post={$id}&action=edit" === uonix_admin_editor_politicas_submenu_file( null ), "post {$id}: destaca o item da página" );
}

// Outras páginas e telas mantêm o destaque original.
$_GET = array( 'post' => '42', 'action' => 'edit' );
uox_pl_assert( 'edit.php?post_type=page' === uonix_admin_editor_politicas_parent_file( 'edit.php?post_type=page' ), 'outra página mantém Páginas' );
uox_pl_assert( 'x' === uonix_admin_editor_politicas_submenu_file( 'x' ), 'outra página mantém o submenu' );
$GLOBALS['pagenow'] = 'edit.php';
$_GET               = array( 'post' => '3' );
uox_pl_assert( 'edit.php' === uonix_admin_editor_politicas_parent_file( 'edit.php' ), 'outra tela mantém o destaque' );

$GLOBALS['pagenow']       = 'post.php';
$GLOBALS['uox_test_caps'] = $admin;
uox_pl_assert( 'edit.php?post_type=page' === uonix_admin_editor_politicas_parent_file( 'edit.php?post_type=page' ), 'administrador mantém o destaque original' );

// AdOpt em outra aba, só para o editor.
ob_start();
uonix_admin_editor_politicas_adopt_nova_aba();
uox_pl_assert( '' === ob_get_clean(), 'administrador não recebe o script' );

$GLOBALS['uox_test_caps'] = $editor;
ob_start();
uonix_admin_editor_politicas_adopt_nova_aba();
$script = (string) ob_get_clean();
uox_pl_assert( false !== strpos( $script, json_encode( $adopt ) ), 'script mira o link da AdOpt' );
uox_pl_assert( false !== strpos( $script, "a.target = '_blank'" ) && false !== strpos( $script, 'noopener' ), 'link da AdOpt abre em outra aba com noopener' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: editor tem o menu Políticas e LGPD com as páginas legais e a AdOpt.\n";
