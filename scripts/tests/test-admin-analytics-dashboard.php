<?php
/**
 * Teste do Módulo Uônix Insights (Painel Integrado de Analytics & Performance).
 *
 * Valida:
 *  - Registro do menu admin 'Uônix Insights' na action 'admin_menu';
 *  - Slug correto 'uonix-analytics' e capability 'edit_posts';
 *  - Bloqueio de acesso para usuários sem permissão (current_user_can fail);
 *  - Renderização dos cards de KPIs, tabelas de produtos e atalhos do Google.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_menu_actions'] = array();
$GLOBALS['uonix_test_menus']        = array();
$GLOBALS['uonix_test_can_edit']     = true;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_menu_actions'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
		'priority' => $priority,
	);
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['uonix_test_menus'][] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
		'menu_slug'  => $menu_slug,
		'callback'   => $callback,
		'icon_url'   => $icon_url,
		'position'   => $position,
	);
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function current_user_can( $capability ) {
	if ( 'edit_posts' === $capability ) {
		return $GLOBALS['uonix_test_can_edit'];
	}
	return false;
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'WP_DIE: ' . $message );
}

function get_option( $name, $default = false ) {
	if ( 'rank-math-options-general' === $name ) {
		return array( 'google_verify' => 'gwb3yPi79I8knt2zh_ctf3tZuEyasOFwLCNoeE2TO1w' );
	}
	return $default;
}

function get_posts( $args = array() ) {
	$pt = $args['post_type'] ?? 'post';
	if ( 'product' === $pt ) {
		return array(
			(object) array( 'ID' => 2420, 'post_title' => 'Olhal de Ancoragem Modelo 210 Inox 304', 'post_name' => 'ancoragem-uonix-modelo-210-inox' ),
			(object) array( 'ID' => 5614, 'post_title' => 'Arruela Funileiro Inox 304', 'post_name' => 'arruela-funileiroinox-304' ),
		);
	}
	if ( 'post' === $pt ) {
		return array(
			(object) array( 'ID' => 10849, 'post_title' => 'Fator de queda: o risco começa no projeto, não na queda', 'post_name' => 'fator-de-queda-o-risco-comeca-no-projeto-nao-na-queda' ),
		);
	}
	if ( 'servicos' === $pt ) {
		return array(
			(object) array( 'ID' => 2636, 'post_title' => 'Ensaios de Arrancamento', 'post_name' => 'ensaios-de-arrancamento' ),
		);
	}
	return array();
}

function get_permalink( $id ) {
	return 'https://uonix.com.br/?p=' . $id;
}

function get_edit_post_link( $id ) {
	return 'https://uonix.com.br/wp-admin/post.php?post=' . $id . '&action=edit';
}

function get_post_meta( $id, $key, $single = false ) {
	if ( 'rank_math_focus_keyword' === $key ) {
		return 'ancoragem teste';
	}
	if ( 'rank_math_title' === $key ) {
		return 'Título SEO Teste';
	}
	return '';
}

function get_the_terms( $id, $taxonomy ) {
	return array( (object) array( 'name' => 'Olhal de Ancoragem' ) );
}

function is_wp_error( $thing ) {
	return false;
}

function get_the_date( $format, $id ) {
	return '26/08/2026';
}

// Carrega o arquivo a ser testado
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/52-admin-analytics-dashboard.php';

// Asserção 1: Registra action admin_menu
assert( ! empty( $GLOBALS['uonix_test_menu_actions'] ), 'Não registrou nenhuma action' );
$admin_menu_registered = false;
foreach ( $GLOBALS['uonix_test_menu_actions'] as $act ) {
	if ( 'admin_menu' === $act['hook'] ) {
		$admin_menu_registered = true;
		call_user_func( $act['callback'] );
	}
}
assert( $admin_menu_registered, 'Action admin_menu não registrada' );
echo "ok   Action admin_menu registrada corretamente\n";

// Asserção 2: Verifica parâmetros do menu
assert( ! empty( $GLOBALS['uonix_test_menus'] ), 'Menu não foi adicionado via add_menu_page' );
$menu = $GLOBALS['uonix_test_menus'][0];
assert( 'Uônix Insights' === $menu['menu_title'], 'Título do menu incorreto' );
assert( 'edit_posts' === $menu['capability'], 'Capability do menu incorreta' );
assert( 'uonix-analytics' === $menu['menu_slug'], 'Slug do menu incorreto' );
assert( 'dashicons-chart-area' === $menu['icon_url'], 'Ícone do menu incorreto' );
echo "ok   Menu Uônix Insights registrado com slug, permissões e ícone corretos\n";

// Asserção 3: Renderização do dashboard com permissão
ob_start();
uonix_render_analytics_dashboard_page();
$output = ob_get_clean();

assert( strpos( $output, 'Central de Desempenho, Catálogo &amp; Analytics' ) !== false || strpos( $output, 'Central de Desempenho, Catálogo & Analytics' ) !== false, 'Header do dashboard não renderizou' );
assert( strpos( $output, 'GTM-5F4Q3ZJ' ) !== false, 'GTM ID não está presente no status' );
assert( strpos( $output, 'Search Console' ) !== false, 'Search Console não está presente' );
assert( strpos( $output, 'Olhal de Ancoragem Modelo 210 Inox 304' ) !== false, 'Produto de teste não foi listado na tabela' );
assert( strpos( $output, 'Fator de queda' ) !== false, 'Post de blog de teste não foi listado na tabela' );
assert( strpos( $output, 'Ensaios de Arrancamento' ) !== false, 'Serviço de teste não foi listado na tabela' );
echo "ok   Dashboard renderiza cards de KPI, tabelas de produtos, posts, serviços e atalhos\n";

// Asserção 4: Usuário sem permissão é barrado com wp_die
$GLOBALS['uonix_test_can_edit'] = false;
$blocked = false;
try {
	ob_start();
	uonix_render_analytics_dashboard_page();
	ob_end_clean();
} catch ( RuntimeException $e ) {
	$blocked = ( strpos( $e->getMessage(), 'WP_DIE' ) !== false );
}
assert( $blocked, 'Usuário sem permissão edit_posts deveria ser bloqueado' );
echo "ok   Acesso sem permissão é bloqueado com segurança via wp_die\n";

echo "\nPASS: Módulo Uônix Insights (Painel Integrado de Analytics) aprovado em todos os testes!\n";
