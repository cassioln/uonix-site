<?php
/**
 * Teste: menu "Marketing" do perfil editor.
 *
 * MOTIVAÇÃO (pedido de 2026-10-03): o editor ganha um menu Marketing com a
 * Visão Geral (a tela "Destinos de marketing configurados" do Insights, sem o
 * AdOpt) e atalhos para cada plataforma.
 *
 * O teste falha se o menu vazar para o administrador, se um atalho mudar de
 * ordem ou de endereço, se a Visão Geral voltar a mostrar o AdOpt ou deixar de
 * usar a tela do Insights, se o menu não abrir no clique como os outros
 * grupos, ou se o módulo sair do loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_caps']     = array();
$GLOBALS['uox_test_menus']    = array();
$GLOBALS['uox_test_submenus'] = array();
$GLOBALS['uox_test_render']   = array();
$GLOBALS['submenu']           = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['uox_test_caps'], true );
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['uox_test_menus'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position' );
	return 'toplevel_page_uonix-marketing';
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	$GLOBALS['uox_test_submenus'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	// Como o núcleo: o item entra no $submenu do pai.
	$GLOBALS['submenu'][ $parent_slug ][] = array( $menu_title, $capability, $menu_slug, $page_title );
	return 'marketing_page_uonix-marketing';
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'wp_die: ' . $message );
}

// Funções do Insights (52-admin-analytics-dashboard.php), cobertas pelo teste dele.
function uonix_analytics_destinations_context() {
	return array(
		'gtm_url'         => 'https://tagmanager.google.com/#/container/x',
		'ga4_url'         => 'https://analytics.google.com/analytics/web/',
		'google_ads_url'  => 'https://ads.google.com/aw/overview',
		'meta_events_url' => 'https://business.facebook.com/events_manager2',
		'gsc_domain_url'  => 'https://search.google.com/search-console?resource_id=sc-domain:uonix.com.br',
		'looker_url'      => 'https://lookerstudio.google.com/',
		'adopt_url'       => 'https://dash.goadopt.io/org/uonix/disclaimers',
	);
}

function uonix_analytics_render_destinations( $destinos, $com_adopt = true ) {
	$GLOBALS['uox_test_render'][] = array( $destinos, $com_adopt );
	echo '<p>destinos</p>';
}

function uonix_analytics_print_dashboard_css() {
	echo '<style>/* css do insights */</style>';
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/68-admin-editor-marketing.php';
require_once $raiz . '/mu-plugins/uonix-admin/67-admin-editor-menus.php';

$failures = 0;

function uox_mk_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$editor = array( 'edit_posts', 'edit_pages', 'edit_theme_options' );
$admin  = array( 'edit_posts', 'edit_pages', 'edit_theme_options', 'manage_options' );

// Loader.
$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_mk_assert( false !== strpos( $loader, "'68-admin-editor-marketing.php'" ), 'module.php deve carregar 68-admin-editor-marketing.php' );

// Administrador não ganha o menu.
$GLOBALS['uox_test_caps'] = $admin;
uonix_admin_editor_marketing_menu();
uox_mk_assert( array() === $GLOBALS['uox_test_menus'] && array() === $GLOBALS['submenu'], 'administrador não ganha o menu Marketing' );

// Editor: menu, Visão Geral e os seis atalhos, na ordem dos cards, sem AdOpt.
$GLOBALS['uox_test_caps'] = $editor;
uonix_admin_editor_marketing_menu();
$menu = $GLOBALS['uox_test_menus'][0] ?? array();
uox_mk_assert( 'Marketing' === ( $menu['menu_title'] ?? null ), 'menu se chama Marketing' );
uox_mk_assert( 'uonix-marketing' === ( $menu['menu_slug'] ?? null ), 'slug do menu é uonix-marketing' );
uox_mk_assert( 'edit_posts' === ( $menu['capability'] ?? null ), 'menu exige edit_posts' );
uox_mk_assert( 'uonix_admin_editor_marketing_render' === ( $menu['callback'] ?? null ), 'menu desenha a Visão Geral' );
uox_mk_assert(
	array(
		array( 'Visão Geral', 'edit_posts', 'uonix-marketing', 'Marketing — Visão Geral' ),
		array( 'Google Tag Manager', 'edit_posts', 'https://tagmanager.google.com/#/container/x' ),
		array( 'Google Analytics 4', 'edit_posts', 'https://analytics.google.com/analytics/web/' ),
		array( 'Google Ads', 'edit_posts', 'https://ads.google.com/aw/overview' ),
		array( 'Meta Pixel', 'edit_posts', 'https://business.facebook.com/events_manager2' ),
		array( 'Search Console', 'edit_posts', 'https://search.google.com/search-console?resource_id=sc-domain:uonix.com.br' ),
		array( 'Looker Studio', 'edit_posts', 'https://lookerstudio.google.com/' ),
	) === ( $GLOBALS['submenu']['uonix-marketing'] ?? null ),
	'submenu: Visão Geral e os atalhos com os endereços dos cards'
);
uox_mk_assert( false === strpos( (string) json_encode( $GLOBALS['submenu'] ), 'goadopt' ), 'AdOpt fora do menu Marketing' );

// Visão Geral: tela do Insights sem AdOpt, com o CSS dele.
ob_start();
uonix_admin_editor_marketing_render();
$tela = (string) ob_get_clean();
uox_mk_assert( 1 === count( $GLOBALS['uox_test_render'] ), 'Visão Geral desenha os destinos uma vez' );
uox_mk_assert( false === ( $GLOBALS['uox_test_render'][0][1] ?? null ), 'Visão Geral sem o AdOpt' );
uox_mk_assert( uonix_analytics_destinations_context() === ( $GLOBALS['uox_test_render'][0][0] ?? null ), 'Visão Geral usa os dados do Insights' );
uox_mk_assert( false !== strpos( $tela, '<h1>Marketing</h1>' ) && false !== strpos( $tela, 'uonix-analytics-wrap' ), 'Visão Geral com o cabeçalho do Insights' );
uox_mk_assert( false !== strpos( $tela, 'css do insights' ), 'Visão Geral imprime o CSS do Insights' );

// Sem permissão: a tela recusa.
$GLOBALS['uox_test_caps'] = array();
$recusou                  = false;
try {
	uonix_admin_editor_marketing_render();
} catch ( RuntimeException $e ) {
	$recusou = 0 === strpos( $e->getMessage(), 'wp_die' );
}
uox_mk_assert( $recusou, 'sem edit_posts, a Visão Geral recusa' );

// Atalhos em outra aba, só para o editor.
$GLOBALS['uox_test_caps'] = $admin;
ob_start();
uonix_admin_editor_marketing_nova_aba();
uox_mk_assert( '' === ob_get_clean(), 'administrador não recebe o script' );

$GLOBALS['uox_test_caps'] = $editor;
ob_start();
uonix_admin_editor_marketing_nova_aba();
$script = (string) ob_get_clean();
uox_mk_assert( false !== strpos( $script, '#toplevel_page_uonix-marketing .wp-submenu a[href^="https://"]' ), 'script mira só os atalhos externos do Marketing' );
uox_mk_assert( false !== strpos( $script, "a.target = '_blank'" ) && false !== strpos( $script, 'noopener' ), 'atalhos abrem em outra aba com noopener' );

// Marketing abre no clique como os outros grupos (67).
uox_mk_assert( in_array( 'uonix-marketing', uonix_admin_editor_menus_grupos(), true ), 'Marketing é menu de grupo' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: editor tem o menu Marketing com a Visão Geral do Insights sem AdOpt.\n";
