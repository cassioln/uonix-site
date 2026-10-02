<?php
/**
 * Teste: organização do menu lateral do perfil editor.
 *
 * MOTIVAÇÃO (pedido de 2026-10-02): "Posts" vira "Blog" com "Comentários"
 * dentro; um menu "Seções do Site" reúne Rodapé e os atalhos de edição das
 * seções; os menus que só agrupam atalhos abrem o submenu no clique, sem
 * navegar.
 *
 * O teste falha se algo vazar para o administrador, se um item mudar de
 * destino ou de ordem, se Comentários perder o contador ou o destaque, se a
 * marcação de grupo pegar outro menu, ou se o módulo sair do loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_caps']      = array();
$GLOBALS['uox_test_menus']     = array();
$GLOBALS['uox_test_politicas'] = 'post.php?post=3&action=edit';
$GLOBALS['pagenow']            = 'index.php';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['uox_test_caps'], true );
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['uox_test_menus'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'icon_url', 'position' );
	return 'toplevel_page_widgets';
}

// Slug do menu "Políticas e LGPD" (65-admin-editor-politicas-lgpd.php).
function uonix_admin_editor_politicas_slug_pai() {
	return $GLOBALS['uox_test_politicas'];
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/67-admin-editor-menus.php';

$failures = 0;

function uox_mn_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$editor = array( 'edit_theme_options', 'edit_posts', 'edit_pages' );
$admin  = array( 'edit_theme_options', 'edit_posts', 'edit_pages', 'manage_options' );

$comentarios_html = 'Comentários <span class="awaiting-mod count-0"><span class="pending-count" aria-hidden="true">0</span><span class="comments-in-moderation-text screen-reader-text">0 comentário esperando moderação</span></span>';

// Menu como o núcleo monta (posições e campos de wp-admin/menu.php).
function uox_mn_menu_do_nucleo() {
	global $comentarios_html;

	$GLOBALS['menu'] = array(
		2  => array( 'Painel', 'read', 'index.php', '', 'menu-top menu-top-first menu-icon-dashboard', 'menu-dashboard', 'dashicons-dashboard' ),
		5  => array( 'Posts', 'edit_posts', 'edit.php', '', 'menu-top menu-icon-post open-if-no-js', 'menu-posts', 'dashicons-admin-post' ),
		20 => array( 'Páginas', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top menu-icon-page', 'menu-pages', 'dashicons-admin-page' ),
		25 => array( $comentarios_html, 'edit_posts', 'edit-comments.php', '', 'menu-top menu-icon-comments', 'menu-comments', 'dashicons-admin-comments' ),
		56 => array( 'Seções do Site', 'edit_theme_options', 'widgets.php', 'Seções do Site', 'menu-top toplevel_page_widgets', 'toplevel_page_widgets', 'dashicons-layout' ),
		57 => array( 'Políticas e LGPD', 'edit_pages', 'post.php?post=3&action=edit', 'Políticas e LGPD', 'menu-top toplevel_page_politicas', 'toplevel_page_politicas', 'dashicons-shield' ),
	);
	$GLOBALS['submenu'] = array(
		'edit.php'          => array(
			5  => array( 'Todos os posts', 'edit_posts', 'edit.php' ),
			10 => array( 'Adicionar post', 'edit_posts', 'post-new.php' ),
			15 => array( 'Categorias', 'manage_categories', 'edit-tags.php?taxonomy=category' ),
		),
		'edit-comments.php' => array(
			0 => array( 'Todos os comentários', 'edit_posts', 'edit-comments.php' ),
		),
	);
}

// Loader.
$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_mn_assert( false !== strpos( $loader, "'67-admin-editor-menus.php'" ), 'module.php deve carregar 67-admin-editor-menus.php' );

// Itens de Seções do Site: mesmos destinos do Acesso Rápido, na ordem pedida.
uox_mn_assert(
	array(
		'Rodapé'           => 'widgets.php',
		'Banner Home'      => 'post.php?post=6130&action=edit',
		'Banner Produtos'  => 'site-editor.php?p=%2Fwp_block%2F7255&canvas=edit',
		'Selo Aniversário' => 'site-editor.php?p=%2Fwp_block%2F10973&canvas=edit',
		'Topo (Contatos)'  => 'site-editor.php?p=%2Fwp_block%2F3631&canvas=edit',
		'Dúvidas (FAQ)'    => 'site-editor.php?p=%2Fwp_block%2F2859&canvas=edit',
	) === uonix_admin_editor_menus_secoes(),
	'títulos, ordem e destinos de Seções do Site'
);
$painel = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php' );
foreach ( uonix_admin_editor_menus_secoes() as $titulo => $url ) {
	if ( 'widgets.php' !== $url ) {
		uox_mn_assert( false !== strpos( $painel, 'href="/wp-admin/' . $url . '"' ), "{$titulo}: destino diferente do Acesso Rápido" );
	}
}

// Administrador: nada muda.
$GLOBALS['uox_test_caps'] = $admin;
uox_mn_menu_do_nucleo();
$antes_menu    = $GLOBALS['menu'];
$antes_submenu = $GLOBALS['submenu'];
uonix_admin_editor_menus_secoes_do_site();
uonix_admin_editor_menus_blog();
uonix_admin_editor_menus_marca_grupos();
uox_mn_assert( array() === $GLOBALS['uox_test_menus'], 'administrador não ganha Seções do Site' );
uox_mn_assert( $antes_menu === $GLOBALS['menu'] && $antes_submenu === $GLOBALS['submenu'], 'administrador mantém o menu do núcleo' );

// Editor: Seções do Site.
$GLOBALS['uox_test_caps'] = $editor;
uox_mn_menu_do_nucleo();
uonix_admin_editor_menus_secoes_do_site();
$menu_novo = $GLOBALS['uox_test_menus'][0] ?? array();
uox_mn_assert( 'Seções do Site' === ( $menu_novo['menu_title'] ?? null ), 'menu se chama Seções do Site' );
uox_mn_assert( 'widgets.php' === ( $menu_novo['menu_slug'] ?? null ), 'slug do menu é widgets.php (destaque do 64)' );
uox_mn_assert( 'edit_theme_options' === ( $menu_novo['capability'] ?? null ), 'menu exige edit_theme_options' );
$esperado = array();
foreach ( uonix_admin_editor_menus_secoes() as $titulo => $url ) {
	$esperado[] = array( $titulo, 'edit_theme_options', $url );
}
uox_mn_assert( $esperado === ( $GLOBALS['submenu']['widgets.php'] ?? null ), 'submenu de Seções do Site com os seis itens' );

// Editor: Blog com Comentários.
uonix_admin_editor_menus_blog();
uox_mn_assert( 'Blog' === $GLOBALS['menu'][5][0], 'Posts vira Blog' );
uox_mn_assert( 'edit.php' === $GLOBALS['menu'][5][2], 'Blog continua em edit.php' );
uox_mn_assert( ! isset( $GLOBALS['menu'][25] ), 'Comentários sai do menu principal' );
uox_mn_assert( ! isset( $GLOBALS['submenu']['edit-comments.php'] ), 'submenu antigo de Comentários sai' );
uox_mn_assert( array( 5, 10, 11, 15 ) === array_keys( $GLOBALS['submenu']['edit.php'] ), 'Comentários logo depois de Adicionar post' );
uox_mn_assert( array( $comentarios_html, 'edit_posts', 'edit-comments.php' ) === $GLOBALS['submenu']['edit.php'][11], 'Comentários com o contador do núcleo' );
uox_mn_assert( 'Páginas' === $GLOBALS['menu'][20][0], 'outros menus não mudam' );

// Sem o menu de Comentários (usuário sem acesso), Blog só renomeia.
uox_mn_menu_do_nucleo();
unset( $GLOBALS['menu'][25] );
uonix_admin_editor_menus_blog();
uox_mn_assert( 'Blog' === $GLOBALS['menu'][5][0] && array( 5, 10, 15 ) === array_keys( $GLOBALS['submenu']['edit.php'] ), 'sem Comentários, nada é injetado' );

// Marcação dos menus de grupo.
uox_mn_menu_do_nucleo();
uonix_admin_editor_menus_marca_grupos();
uox_mn_assert( false !== strpos( $GLOBALS['menu'][56][4], 'uonix-menu-grupo' ), 'Seções do Site marcado como grupo' );
uox_mn_assert( false !== strpos( $GLOBALS['menu'][57][4], 'uonix-menu-grupo' ), 'Políticas e LGPD marcado como grupo' );
uox_mn_assert( 0 === strpos( $GLOBALS['menu'][56][4], 'menu-top toplevel_page_widgets' ), 'classes do núcleo preservadas' );
foreach ( array( 2, 5, 20, 25 ) as $posicao ) {
	uox_mn_assert( false === strpos( $GLOBALS['menu'][ $posicao ][4], 'uonix-menu-grupo' ), "menu {$posicao} não é grupo" );
}

$GLOBALS['uox_test_politicas'] = '';
uox_mn_assert( array( 'widgets.php' ) === uonix_admin_editor_menus_grupos(), 'sem o menu de políticas, só Seções do Site' );
$GLOBALS['uox_test_politicas'] = 'post.php?post=3&action=edit';

// Destaque: Comentários dentro de Blog.
$GLOBALS['pagenow'] = 'comment.php';
uox_mn_assert( 'edit.php' === uonix_admin_editor_menus_parent_file( 'edit-comments.php' ), 'comment.php destaca Blog' );
$GLOBALS['pagenow'] = 'edit.php';
uox_mn_assert( 'edit.php' === uonix_admin_editor_menus_parent_file( 'edit.php' ), 'Blog continua destacado em edit.php' );

// Destaque: Banner Home dentro de Seções do Site.
$GLOBALS['pagenow'] = 'post.php';
$_GET               = array( 'post' => '6130', 'action' => 'edit' );
uox_mn_assert( 'widgets.php' === uonix_admin_editor_menus_parent_file( 'edit.php?post_type=page' ), 'Banner Home destaca Seções do Site' );
uox_mn_assert( 'post.php?post=6130&action=edit' === uonix_admin_editor_menus_submenu_file( null ), 'Banner Home destaca o item' );
$_GET = array( 'post' => '42', 'action' => 'edit' );
uox_mn_assert( 'edit.php?post_type=page' === uonix_admin_editor_menus_parent_file( 'edit.php?post_type=page' ), 'outro post mantém o destaque' );
uox_mn_assert( 'x' === uonix_admin_editor_menus_submenu_file( 'x' ), 'outro post mantém o submenu' );

// Administrador mantém os destaques do núcleo.
$GLOBALS['uox_test_caps'] = $admin;
$_GET                     = array( 'post' => '6130', 'action' => 'edit' );
uox_mn_assert( 'edit.php?post_type=page' === uonix_admin_editor_menus_parent_file( 'edit.php?post_type=page' ), 'administrador: Banner Home mantém o destaque' );
uox_mn_assert( 'x' === uonix_admin_editor_menus_submenu_file( 'x' ), 'administrador: submenu mantido' );
$GLOBALS['pagenow'] = 'comment.php';
uox_mn_assert( 'edit-comments.php' === uonix_admin_editor_menus_parent_file( 'edit-comments.php' ), 'administrador: comment.php mantém Comentários' );

// Clique nos grupos.
ob_start();
uonix_admin_editor_menus_clique_grupo();
uox_mn_assert( '' === ob_get_clean(), 'administrador não recebe o script do clique' );

$GLOBALS['uox_test_caps'] = $editor;
ob_start();
uonix_admin_editor_menus_clique_grupo();
$script = (string) ob_get_clean();
uox_mn_assert( false !== strpos( $script, "'#adminmenu li.uonix-menu-grupo > a'" ), 'script mira só os menus de grupo' );
uox_mn_assert( false !== strpos( $script, 'evento.preventDefault()' ), 'clique no grupo não navega' );
uox_mn_assert( false !== strpos( $script, "'wp-menu-open'" ) && false !== strpos( $script, "'wp-has-current-submenu'" ), 'script abre o submenu como menu pai aberto' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: menu do editor com Blog, Seções do Site e grupos que abrem no clique.\n";
