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
$GLOBALS['uonix_test_analytics_configuration'] = array(
	'gtm_container_id' => 'GTM-P8TR5CCH',
	'adopt_website_id' => 'adopt-test-id',
);

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

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals, ',', '.' );
}

function admin_url( $path = '' ) {
	return 'https://uonix.com.br/wp-admin/' . $path;
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="fixture" />';
}

function current_user_can( $capability ) {
	if ( 'edit_posts' === $capability || 'manage_options' === $capability ) {
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

function uonix_analytics_configuration() {
	return $GLOBALS['uonix_test_analytics_configuration'];
}

function uonix_analytics_metrics_get_snapshot() {
	return array(
		'status'     => 'updated',
		'updated_at' => '2026-09-11T12:00:00+00:00',
		'periods'    => array(
			'current'  => array( 'start' => '2026-08-11', 'end' => '2026-09-09' ),
			'previous' => array( 'start' => '2026-07-12', 'end' => '2026-08-10' ),
		),
		'ga4'        => array(
			'summary' => array(
				'active_users' => array( 'current' => 42, 'previous' => 35, 'delta_percent' => 20, 'state' => 'comparable' ),
				'sessions'     => array( 'current' => 60, 'previous' => 0, 'delta_percent' => null, 'state' => 'new' ),
			),
			'landing_pages' => array( array( 'path' => '/servicos/', 'sessions' => 18 ) ),
		),
		'search_console' => array(
			'summary' => array(
				'clicks'      => array( 'current' => 20, 'previous' => 10, 'delta_percent' => 100, 'state' => 'comparable' ),
				'impressions' => array( 'current' => 100, 'previous' => 80, 'delta_percent' => 25, 'state' => 'comparable' ),
				'ctr'         => array( 'current' => .2, 'previous' => .125, 'delta_percent' => 60, 'state' => 'comparable' ),
				'position'    => array( 'current' => 8, 'previous' => 9, 'delta_percent' => -11.1, 'state' => 'comparable' ),
			),
			'queries' => array( array( 'query' => 'linha de vida', 'clicks' => 5, 'impressions' => 25, 'ctr' => .2, 'position' => 4 ) ),
			'pages'   => array( array( 'page' => '/servicos/', 'clicks' => 6, 'impressions' => 30, 'ctr' => .2, 'position' => 5 ) ),
		),
	);
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

$failures = 0;
function uonix_dashboard_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uonix_dashboard_visible_text( $html ) {
	$without_code = preg_replace( '#<(?:script|style)\b[^>]*>.*?</(?:script|style)>#is', ' ', $html );
	$without_tags = preg_replace( '/<[^>]+>/', ' ', $without_code );
	$plain_text   = html_entity_decode( $without_tags, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $plain_text ) );
}

function uonix_dashboard_span_texts( $html, $class_name ) {
	$pattern = '#<span class="' . preg_quote( $class_name, '#' ) . '">(.*?)</span>#is';
	preg_match_all( $pattern, $html, $matches );
	return array_map(
		static function( $text ) {
			return trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		},
		$matches[1]
	);
}

// Carrega o arquivo a ser testado
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/52-admin-analytics-dashboard.php';

// Asserção 1: Registra action admin_menu
uonix_dashboard_assert( ! empty( $GLOBALS['uonix_test_menu_actions'] ), 'Não registrou nenhuma action' );
$admin_menu_registered = false;
foreach ( $GLOBALS['uonix_test_menu_actions'] as $act ) {
	if ( 'admin_menu' === $act['hook'] ) {
		$admin_menu_registered = true;
		call_user_func( $act['callback'] );
	}
}
uonix_dashboard_assert( $admin_menu_registered, 'Action admin_menu não registrada' );
echo "ok   Action admin_menu registrada corretamente\n";

// Asserção 2: Verifica parâmetros do menu
uonix_dashboard_assert( ! empty( $GLOBALS['uonix_test_menus'] ), 'Menu não foi adicionado via add_menu_page' );
$menu = $GLOBALS['uonix_test_menus'][0];
uonix_dashboard_assert( 'Uônix Insights' === $menu['menu_title'], 'Título do menu incorreto' );
uonix_dashboard_assert( 'edit_posts' === $menu['capability'], 'Capability do menu incorreta' );
uonix_dashboard_assert( 'uonix-analytics' === $menu['menu_slug'], 'Slug do menu incorreto' );
uonix_dashboard_assert( 'dashicons-chart-area' === $menu['icon_url'], 'Ícone do menu incorreto' );
echo "ok   Menu Uônix Insights registrado com slug, permissões e ícone corretos\n";

// Asserção 3: Renderização do dashboard com permissão
ob_start();
uonix_render_analytics_dashboard_page();
$output = ob_get_clean();

uonix_dashboard_assert( strpos( $output, 'Central de Desempenho, Catálogo &amp; Analytics' ) !== false || strpos( $output, 'Central de Desempenho, Catálogo & Analytics' ) !== false, 'Header do dashboard não renderizou' );
uonix_dashboard_assert( strpos( $output, 'GTM-P8TR5CCH' ) !== false, 'GTM ID configurado não está presente no status' );
uonix_dashboard_assert( strpos( $output, 'GTM-5F4Q3ZJ' ) === false, 'Dashboard não pode exibir o GTM legado fixo' );
uonix_dashboard_assert( strpos( $output, 'Conformidade [Ativo]' ) === false, 'Dashboard não pode declarar conformidade LGPD sem evidência formal' );
uonix_dashboard_assert( strpos( $output, '<span class="uonix-kpi-value">100%</span>' ) === false, 'Dashboard não pode declarar 100% de SEO sem medição' );
uonix_dashboard_assert( strpos( $output, 'Conformidade de SEO' ) === false, 'Dashboard não pode declarar conformidade de SEO sem auditoria' );
uonix_dashboard_assert( strpos( $output, 'Acompanhe em tempo real o catálogo' ) === false, 'Dashboard local não pode alegar monitoramento em tempo real' );
uonix_dashboard_assert( strpos( $output, 'Service + Breadcrumb' ) === false, 'Dashboard não pode afirmar schemas por serviço sem inspecioná-los' );
uonix_dashboard_assert( strpos( $output, 'disparos via GTM (PageView, Contact, Lead)' ) === false, 'Dashboard não pode afirmar eventos Meta sem verificação externa' );
uonix_dashboard_assert( strpos( $output, 'Validação externa de SEO' ) !== false, 'Dashboard deve identificar SEO como validação externa' );
uonix_dashboard_assert( strpos( $output, 'Verificação externa' ) !== false, 'Dashboard deve distinguir configuração local de verificação externa' );
uonix_dashboard_assert( strpos( $output, 'Search Console' ) !== false, 'Search Console não está presente' );
uonix_dashboard_assert( strpos( $output, 'Olhal de Ancoragem Modelo 210 Inox 304' ) !== false, 'Produto de teste não foi listado na tabela' );
uonix_dashboard_assert( strpos( $output, 'Fator de queda' ) !== false, 'Post de blog de teste não foi listado na tabela' );
uonix_dashboard_assert( strpos( $output, 'Ensaios de Arrancamento' ) !== false, 'Serviço de teste não foi listado na tabela' );
uonix_dashboard_assert( strpos( $output, 'Meta Pixel' ) !== false, 'Meta Pixel não está presente no dashboard' );
uonix_dashboard_assert( strpos( $output, 'events_manager2' ) !== false, 'Link do Events Manager da Meta não está presente' );

// A central de marketing mostra somente fatos técnicos verificáveis localmente.
uonix_dashboard_assert( strpos( $output, 'Destinos de marketing configurados' ) !== false, 'Dashboard apresenta a central de destinos de marketing' );
uonix_dashboard_assert( strpos( $output, 'Google Ads via GTM' ) !== false, 'Dashboard apresenta card dedicado ao Google Ads via GTM' );
uonix_dashboard_assert( strpos( $output, 'AW-6012006717' ) !== false, 'Dashboard mostra a conta técnica Google Ads auditada' );
uonix_dashboard_assert( strpos( $output, 'Google Tag, vinculador de conversões e remarketing' ) !== false, 'Dashboard descreve somente a infraestrutura Ads ainda configurada' );
uonix_dashboard_assert( strpos( $output, 'Validar campanhas, públicos e resultados no Google Ads' ) !== false, 'Dashboard direciona resultados Ads para validação externa' );
uonix_dashboard_assert( strpos( $output, 'G-RFY1BB1RM4' ) !== false, 'Dashboard mostra Measurement ID GA4 auditado' );
uonix_dashboard_assert( strpos( $output, 'Configuração local' ) !== false, 'Cards distinguem configuração local' );
uonix_dashboard_assert( strpos( $output, 'Validar em' ) !== false, 'Cards distinguem plataforma de validação' );
uonix_dashboard_assert( strpos( $output, 'Métricas agregadas dos últimos 30 dias' ) !== false, 'Dashboard apresenta métricas agregadas cacheadas' );
uonix_dashboard_assert( strpos( $output, 'Usuários ativos' ) !== false && strpos( $output, 'Sessões' ) !== false, 'Dashboard apresenta resumo GA4' );
uonix_dashboard_assert( strpos( $output, 'Cliques orgânicos' ) !== false && strpos( $output, 'Impressões orgânicas' ) !== false, 'Dashboard apresenta resumo Search Console' );
uonix_dashboard_assert( strpos( $output, 'linha de vida' ) !== false && strpos( $output, '/servicos/' ) !== false, 'Dashboard apresenta rankings agregados sem query string' );

$visible_output = uonix_dashboard_visible_text( $output );
$marketing_claim_patterns = array(
	'/\b(?:convers(?:ão|oes)|lead|cliente)s?\s+(?:confirmad[oa]s?|realizad[oa]s?)\b/iu',
	'/\b(?:ROAS|CPA|CPC|custo|gasto)\b/iu',
	'/\beventos?\s+recebidos?\b/iu',
	'/\bpalavras?-chave\s+que\s+trazem\s+clientes\b/iu',
	'/\bvisitantes?\s+ativos?\b/iu',
);
foreach ( $marketing_claim_patterns as $pattern ) {
	uonix_dashboard_assert( 0 === preg_match( $pattern, $visible_output ), "Dashboard nao contem alegacao de marketing nao verificavel: {$pattern}" );
}

$kpi_values = uonix_dashboard_span_texts( $output, 'uonix-kpi-value' );
uonix_dashboard_assert( array( '42', '60', '20', '100', '2', '1', '1', 'Externa' ) === $kpi_values, 'KPIs exibem métricas agregadas cacheadas, contagens locais e o estado externo de SEO' );
$kpi_titles = uonix_dashboard_span_texts( $output, 'uonix-kpi-title' );
uonix_dashboard_assert(
	array( 'Usuários ativos', 'Sessões', 'Cliques orgânicos', 'Impressões orgânicas', 'Produtos Cadastrados', 'Artigos no Blog', 'Serviços Técnicos', 'Validação externa de SEO' ) === $kpi_titles,
	'Titulos dos KPIs incluem apenas métricas agregadas e estados sem alegação de conformidade'
);
$schema_statuses = uonix_dashboard_span_texts( $output, 'uonix-tag uonix-tag-schema' );
uonix_dashboard_assert( array( 'Verificação externa' ) === $schema_statuses, 'Dados estruturados de servicos sao apresentados exclusivamente como verificacao externa' );
uonix_dashboard_assert(
	1 === substr_count( $output, 'Abra a plataforma para verificar o recebimento, diagnósticos e qualidade do Pixel.' ),
	'Card Meta direciona a confirmação de recebimento para a plataforma externa'
);

$unsupported_claim_patterns = array(
	'/100\s*%.{0,80}(?:SEO|conformidade)|(?:SEO|conformidade).{0,80}100\s*%/iu',
	'/\bmonitoramento\s+em\s+tempo\s+real\b/iu',
	'/\b(?:service|schema)\b.{0,80}\bbreadcrumb\b.{0,80}\b(?:validado|validados|validada|validadas|comprovado|comprovada|garantido|garantida|preservado|preservada)\b/iu',
	'/\bGTM\b.{0,120}\b(?:entrega|dispara|envia|encaminha)\b.{0,120}\b(?:PageView|Contact|Lead|Pixel)\b/iu',
);
foreach ( $unsupported_claim_patterns as $pattern ) {
	uonix_dashboard_assert( 0 === preg_match( $pattern, $visible_output ), "Dashboard nao contem alegacao nao observada: {$pattern}" );
}
echo "ok   Dashboard renderiza cards de KPI, tabelas, atalhos Google e Meta Pixel\n";

// Asserção 4: configuração ausente deve ser exibida de forma fail-closed.
$GLOBALS['uonix_test_analytics_configuration'] = false;
ob_start();
uonix_render_analytics_dashboard_page();
$output_without_analytics = ob_get_clean();
uonix_dashboard_assert( strpos( $output_without_analytics, 'GTM-P8TR5CCH' ) === false, 'Dashboard não pode exibir ID GTM quando a configuração está incompleta' );
uonix_dashboard_assert( strpos( $output_without_analytics, 'AW-6012006717' ) === false, 'Dashboard não pode exibir ID Ads quando a configuração está incompleta' );
uonix_dashboard_assert( strpos( $output_without_analytics, 'Não configurado' ) !== false, 'Dashboard deve sinalizar configuração de analytics ausente' );
echo "ok   Dashboard falha fechado quando Analytics/AdOpt não estão configurados\n";

// A configuração genérica não é prova da conta Ads: o container precisa ser o auditado.
$GLOBALS['uonix_test_analytics_configuration'] = array(
	'gtm_container_id' => 'GTM-FOREIGN999',
	'adopt_website_id' => 'adopt-test-id',
);
ob_start();
uonix_render_analytics_dashboard_page();
$output_with_foreign_gtm = ob_get_clean();
uonix_dashboard_assert( strpos( $output_with_foreign_gtm, 'AW-6012006717' ) === false, 'Dashboard não atribui conta Ads auditada a container GTM diferente' );
uonix_dashboard_assert( strpos( $output_with_foreign_gtm, 'Requer validação do GTM' ) !== false, 'Dashboard falha fechado para Ads quando o container GTM não é o auditado' );
echo "ok   Google Ads falha fechado quando o container GTM diverge do auditado\n";

// Asserção 5: Usuário sem permissão é barrado com wp_die
$GLOBALS['uonix_test_can_edit'] = false;
$blocked = false;
try {
	ob_start();
	uonix_render_analytics_dashboard_page();
	ob_end_clean();
} catch ( RuntimeException $e ) {
	$blocked = ( strpos( $e->getMessage(), 'WP_DIE' ) !== false );
}
uonix_dashboard_assert( $blocked, 'Usuário sem permissão edit_posts deveria ser bloqueado' );
echo "ok   Acesso sem permissão é bloqueado com segurança via wp_die\n";

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "\nPASS: Módulo Uônix Insights (Painel Integrado de Analytics) aprovado em todos os testes!\n";
