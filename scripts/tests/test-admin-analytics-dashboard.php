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
$GLOBALS['uonix_test_can_manage']   = true;
$GLOBALS['uonix_test_snapshot_fresh'] = true;
$GLOBALS['uonix_test_snapshot_periods'] = array();
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

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals, ',', '.' );
}

function wp_date( $format, $timestamp ) {
	return gmdate( $format, $timestamp );
}

function admin_url( $path = '' ) {
	return 'https://uonix.com.br/wp-admin/' . $path;
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="fixture" />';
}

function current_user_can( $capability ) {
	if ( 'edit_posts' === $capability ) return $GLOBALS['uonix_test_can_edit'];
	if ( 'manage_options' === $capability ) return $GLOBALS['uonix_test_can_manage'];
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

function uonix_analytics_metrics_sanitize_period_days( $value ) {
	if ( is_int( $value ) ) {
		$days = $value;
	} elseif ( is_string( $value ) && preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
		$days = (int) $value;
	} else {
		return 30;
	}
	return in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
}

function uonix_analytics_metrics_snapshot_is_fresh( $snapshot ) {
	return $GLOBALS['uonix_test_snapshot_fresh'] && is_array( $snapshot ) && 'updated' === ( $snapshot['status'] ?? '' );
}

function uonix_analytics_metrics_get_snapshot( $days = 30 ) {
	$days = uonix_analytics_metrics_sanitize_period_days( $days );
	$GLOBALS['uonix_test_snapshot_periods'][] = $days;
	return array(
		'period_days' => $days,
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
			'landing_pages' => array(
				array( 'path' => '/', 'sessions' => 18 ),
				array( 'path' => '/servicos/', 'sessions' => 6 ),
			),
			'page_views' => array(
				'/produtos/ancoragem-uonix-modelo-210-inox/' => 17,
				'/fator-de-queda-o-risco-comeca-no-projeto-nao-na-queda/' => 11,
				'/servicos/ensaios-de-arrancamento/' => 5,
			),
			'page_views_complete' => true,
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
	$paths = array(
		2420 => '/produtos/ancoragem-uonix-modelo-210-inox/',
		5614 => '/produtos/arruela-funileiroinox-304/',
		10849 => '/fator-de-queda-o-risco-comeca-no-projeto-nao-na-queda/',
		2636 => '/servicos/ensaios-de-arrancamento/',
	);
	return 'https://uonix.com.br' . ( $paths[ $id ] ?? '/?p=' . $id );
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

function uonix_dashboard_div_end_offset( $html, $opening_offset ) {
	if ( ! is_int( $opening_offset ) || $opening_offset < 0 ) {
		return null;
	}
	$fragment = substr( $html, $opening_offset );
	if ( ! is_string( $fragment ) || preg_match_all( '#</?div\b[^>]*>#i', $fragment, $matches, PREG_OFFSET_CAPTURE ) < 1 ) {
		return null;
	}
	$depth = 0;
	foreach ( $matches[0] as $match ) {
		$tag = $match[0];
		if ( 0 === stripos( $tag, '</div' ) ) {
			--$depth;
			if ( 0 === $depth ) {
				return $opening_offset + $match[1] + strlen( $tag );
			}
			continue;
		}
		++$depth;
	}
	return null;
}

// Carrega os módulos na mesma ordem do loader administrativo.
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/52-admin-analytics-dashboard.php';

uonix_dashboard_assert( function_exists( 'uonix_analytics_dashboard_page_view_value' ), 'Resolvedor de visualizações por permalink existe' );
uonix_dashboard_assert( function_exists( 'uonix_analytics_dashboard_chart_rows' ), 'Normalizador de barras dos rankings existe' );
uonix_dashboard_assert( function_exists( 'uonix_analytics_dashboard_page_label' ), 'Formatador de rótulos de páginas existe' );
if ( function_exists( 'uonix_analytics_dashboard_page_view_value' ) ) {
	$page_view_snapshot = uonix_analytics_metrics_get_snapshot( 30 );
	uonix_dashboard_assert( 17.0 === uonix_analytics_dashboard_page_view_value( $page_view_snapshot, 'https://uonix.com.br/produtos/ancoragem-uonix-modelo-210-inox/?utm_source=teste' ), 'Resolvedor encontra visualizações pelo caminho sem query string' );
	uonix_dashboard_assert( 0.0 === uonix_analytics_dashboard_page_view_value( $page_view_snapshot, 'https://uonix.com.br/pagina-sem-visitas/' ), 'Cobertura completa distingue zero visita' );
	$page_view_snapshot['ga4']['page_views_complete'] = false;
	uonix_dashboard_assert( null === uonix_analytics_dashboard_page_view_value( $page_view_snapshot, 'https://uonix.com.br/pagina-desconhecida/' ), 'Cobertura incompleta mantém valor desconhecido' );
}
if ( function_exists( 'uonix_analytics_dashboard_page_label' ) ) {
	uonix_dashboard_assert( 'Home' === uonix_analytics_dashboard_page_label( '/' ), 'Raiz do site é exibida como Home' );
	uonix_dashboard_assert( '/servicos/' === uonix_analytics_dashboard_page_label( '/servicos/' ), 'Demais caminhos preservam o rótulo técnico' );
}
if ( function_exists( 'uonix_analytics_dashboard_chart_rows' ) ) {
	$chart_rows = uonix_analytics_dashboard_chart_rows(
		array(
			array( 'label' => 'Maior', 'value' => 20 ),
			array( 'label' => 'Menor', 'value' => 5 ),
			array( 'label' => 'Zero', 'value' => 0 ),
		),
		'label',
		'value'
	);
	uonix_dashboard_assert( array( 100.0, 25.0, 0.0 ) === array_column( $chart_rows, 'percent' ), 'Barras usam escala relativa segura' );
	$zero_chart_rows = uonix_analytics_dashboard_chart_rows( array( array( 'label' => 'Sem dados', 'value' => 0 ) ), 'label', 'value' );
	uonix_dashboard_assert( 0.0 === $zero_chart_rows[0]['percent'], 'Ranking zerado não divide por zero' );
}

uonix_dashboard_assert( function_exists( 'uonix_analytics_dashboard_metric_comparison' ), 'Formatador de comparação dos KPIs existe' );
if ( function_exists( 'uonix_analytics_dashboard_metric_comparison' ) ) {
	$comparison_up = uonix_analytics_dashboard_metric_comparison( array( 'delta_percent' => 20, 'state' => 'comparable' ) );
	$comparison_down = uonix_analytics_dashboard_metric_comparison( array( 'delta_percent' => -12.5, 'state' => 'comparable' ) );
	$comparison_new = uonix_analytics_dashboard_metric_comparison( array( 'delta_percent' => null, 'state' => 'new' ) );
	$comparison_empty = uonix_analytics_dashboard_metric_comparison( array( 'delta_percent' => null, 'state' => 'empty' ) );
	$comparison_invalid = uonix_analytics_dashboard_metric_comparison( array( 'delta_percent' => INF, 'state' => 'comparable' ) );
	uonix_dashboard_assert( array( 'label' => '+20,0% vs. período anterior', 'class' => 'uonix-trend-up' ) === $comparison_up, 'Variação positiva é formatada sem alegar melhoria' );
	uonix_dashboard_assert( array( 'label' => '-12,5% vs. período anterior', 'class' => 'uonix-trend-down' ) === $comparison_down, 'Variação negativa é formatada sem alegar piora' );
	uonix_dashboard_assert( array( 'label' => 'Novo no período', 'class' => 'uonix-trend-new' ) === $comparison_new, 'Base anterior zero usa estado novo' );
	uonix_dashboard_assert( array( 'label' => 'Sem dados nos dois períodos', 'class' => 'uonix-trend-empty' ) === $comparison_empty, 'Dois períodos zerados usam estado vazio' );
	uonix_dashboard_assert( array( 'label' => 'Comparação indisponível', 'class' => 'uonix-trend-empty' ) === $comparison_invalid, 'Variação inválida falha fechado' );
}

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
uonix_dashboard_assert( strpos( $output, 'class="uonix-header-actions"' ) === false && strpos( $output, '.uonix-header-actions {' ) === false, 'Cabeçalho remove atalhos duplicados' );
uonix_dashboard_assert( strpos( $output, 'tab-google-hub' ) === false && strpos( $output, 'Ferramentas &amp; Tráfego (Google + Meta)' ) === false && strpos( $output, 'Ferramentas & Tráfego (Google + Meta)' ) === false, 'Aba separada de ferramentas foi removida' );
uonix_dashboard_assert( strpos( $output, 'max-width: 1350px' ) === false && strpos( $output, 'max-width: none;' ) !== false, 'Painel ocupa toda a largura útil do wpbody' );
uonix_dashboard_assert( 7 === substr_count( $output, 'class="uonix-marketing-card"' ), 'Hub foi consolidado em sete cards de marketing' );
uonix_dashboard_assert( strpos( $output, 'class="uonix-metrics-toolbar"' ) !== false, 'Seletor, cache e atualização compartilham uma barra de ferramentas' );
uonix_dashboard_assert( strpos( $output, 'class="uonix-metrics-cache-status uonix-cache-fresh">Cache atualizado</span>' ) !== false, 'Cache fresco é identificado sem esconder o timestamp' );
uonix_dashboard_assert( strpos( $output, '<time datetime="2026-09-11T12:00:00+00:00">Atualizado em 11/09/2026 12:00</time>' ) !== false, 'Timestamp válido aparece semanticamente no período selecionado' );
uonix_dashboard_assert( strpos( $output, '.uonix-metrics-toolbar {' ) !== false, 'Barra de período e cache possui layout próprio' );
uonix_dashboard_assert( strpos( $output, '.uonix-metrics-period-form {' ) !== false, 'Seletor de período possui layout próprio' );
uonix_dashboard_assert( strpos( $output, '.uonix-page-views-col {' ) !== false, 'Coluna de visualizações recebe alinhamento numérico' );
uonix_dashboard_assert( strpos( $output, 'overflow-x: auto;' ) !== false, 'Tabelas continuam acessíveis por rolagem horizontal em telas estreitas' );
uonix_dashboard_assert( strpos( $output, 'Visão geral e tempo real' ) !== false && strpos( $output, 'Páginas e telas' ) !== false && strpos( $output, 'Aquisição de tráfego' ) !== false, 'Card GA4 incorpora seus atalhos' );
uonix_dashboard_assert( strpos( $output, 'Consultas de pesquisa' ) !== false && strpos( $output, 'Cobertura e indexação' ) !== false && strpos( $output, 'Sitemaps XML' ) !== false, 'Card Search Console incorpora seus atalhos' );
uonix_dashboard_assert( strpos( $output, 'Campanhas e grupos de anúncios' ) !== false && strpos( $output, 'Públicos de remarketing' ) !== false, 'Card Google Ads incorpora seus atalhos' );
uonix_dashboard_assert( strpos( $output, 'Diagnóstico e qualidade' ) !== false && strpos( $output, 'Testar eventos' ) !== false && strpos( $output, 'Meta Business Suite' ) !== false, 'Card Meta incorpora seus atalhos' );
uonix_dashboard_assert( strpos( $output, 'Google Looker Studio' ) !== false && strpos( $output, 'Galeria de modelos' ) !== false, 'Looker Studio passa a integrar a grade principal' );

$adopt_links = array(
	'Escanear tags' => 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/tags',
	'Documentos' => 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/documents',
	'Configurações' => 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix',
);
foreach ( $adopt_links as $label => $url ) {
	uonix_dashboard_assert( strpos( $output, 'href="' . $url . '"' ) !== false && strpos( $output, '>' . $label . '</a>' ) !== false, 'Card AdOpt contém o link exato: ' . $label );
}
uonix_dashboard_assert( strpos( $output, '<a href="https://dash.goadopt.io/org/uonix/disclaimers" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir AdOpt</a>' ) !== false, 'Card AdOpt oferece Abrir AdOpt como botão' );
uonix_dashboard_assert( 1 === substr_count( $output, '>Abrir AdOpt</a>' ), 'Card AdOpt exibe Abrir AdOpt somente como botão' );
uonix_dashboard_assert( strpos( $output, 'uonix-shortcuts-grid' ) === false, 'Grade antiga de atalhos não permanece no painel' );

// A central de marketing mostra somente fatos técnicos verificáveis localmente.
uonix_dashboard_assert( strpos( $output, 'Destinos de marketing configurados' ) !== false, 'Dashboard apresenta a central de destinos de marketing' );
$catalog_content_start = strpos( $output, '<div class="uonix-tab-content-wrapper">' );
$catalog_content_end = uonix_dashboard_div_end_offset( $output, $catalog_content_start );
$marketing_section_start = strpos( $output, '<section id="uonix-panel-destinations"' );
uonix_dashboard_assert( is_int( $catalog_content_end ) && is_int( $marketing_section_start ) && $marketing_section_start > $catalog_content_end, 'Central de destinos de marketing é a última seção de conteúdo do dashboard' );
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
uonix_dashboard_assert( 4 === substr_count( $output, 'class="uonix-kpi-comparison ' ), 'Os quatro KPIs mostram comparação com o período anterior' );
uonix_dashboard_assert( strpos( $output, '>+20,0% vs. período anterior</span>' ) !== false, 'Usuários ativos mostram variação comparável' );
uonix_dashboard_assert( strpos( $output, '>Novo no período</span>' ) !== false, 'Sessões mostram o estado novo quando a base anterior é zero' );
uonix_dashboard_assert( strpos( $output, '>+100,0% vs. período anterior</span>' ) !== false && strpos( $output, '>+25,0% vs. período anterior</span>' ) !== false, 'KPIs orgânicos mostram suas comparações' );
uonix_dashboard_assert( strpos( $output, 'linha de vida' ) !== false && strpos( $output, '/servicos/' ) !== false, 'Dashboard apresenta rankings agregados sem query string' );
uonix_dashboard_assert( 2 === substr_count( $output, 'class="uonix-ranking-chart"' ), 'Dashboard renderiza dois gráficos de ranking' );
uonix_dashboard_assert( strpos( $output, 'class="uonix-ranking-bar-track"' ) !== false && strpos( $output, 'class="uonix-ranking-bar-fill"' ) !== false, 'Gráficos possuem trilha e barra proporcional' );
uonix_dashboard_assert( strpos( $output, '.uonix-ranking-grid {' ) !== false && strpos( $output, '.uonix-ranking-bar-fill {' ) !== false, 'Gráficos possuem layout visual nativo no próprio módulo' );
uonix_dashboard_assert( strpos( $output, 'aria-label="Home: 18 sessões"' ) !== false, 'Gráfico de páginas exibe a raiz como Home com rótulo numérico acessível' );
uonix_dashboard_assert( 1 === substr_count( $output, '<code>Home</code>' ), 'Gráfico não repete a página Home no snapshot normalizado' );
uonix_dashboard_assert( strpos( $output, 'aria-label="linha de vida: 5 cliques"' ) !== false, 'Gráfico de consultas mantém rótulo numérico acessível' );
uonix_dashboard_assert( 3 === substr_count( $output, '<th>Visualizações — 30 dias</th>' ), 'As três tabelas identificam o período da coluna de visualizações' );
uonix_dashboard_assert( strpos( $output, '<td class="uonix-page-views-col">17</td>' ) !== false, 'Produto mostra visualizações da sua URL' );
uonix_dashboard_assert( strpos( $output, '<td class="uonix-page-views-col">11</td>' ) !== false, 'Artigo mostra visualizações da sua URL' );
uonix_dashboard_assert( strpos( $output, '<td class="uonix-page-views-col">5</td>' ) !== false, 'Serviço mostra visualizações da sua URL' );
uonix_dashboard_assert( strpos( $output, '<td class="uonix-page-views-col">0</td>' ) !== false, 'Conteúdo ausente de relatório completo mostra zero' );
uonix_dashboard_assert( 1 === preg_match( '#<a(?=[^>]*id="uonix-catalog-tab-services")(?=[^>]*role="tab")(?=[^>]*aria-controls="tab-services")[^>]*>\s*<span class="dashicons dashicons-hammer"></span>\s*Serviços\s*\([0-9]+\)\s*</a>#u', $output ), 'Aba de serviços usa exatamente o rótulo curto solicitado' );

// O estado de navegação é URL-driven e as abas usam a semântica ARIA completa.
uonix_dashboard_assert( function_exists( 'uonix_analytics_metrics_requested_dashboard_state' ), 'Resolvedor compartilhado do estado de abas existe' );
uonix_dashboard_assert( strpos( $output, 'role="tablist" aria-label="Seções do painel"' ) !== false, 'Abas principais expõem tablist com rótulo acessível' );
uonix_dashboard_assert( 1 === preg_match( '#<a(?=[^>]*id="uonix-tab-metrics")(?=[^>]*role="tab")(?=[^>]*aria-selected="true")(?=[^>]*aria-controls="uonix-panel-metrics")(?=[^>]*href="[^"]*tab=metrics[^"]*")[^>]*>#', $output ), 'Métricas é a aba principal padrão, selecionada e navegável sem JavaScript' );
uonix_dashboard_assert( 1 === preg_match( '#<a(?=[^>]*id="uonix-tab-destinations")(?=[^>]*role="tab")(?=[^>]*aria-selected="false")(?=[^>]*aria-controls="uonix-panel-destinations")(?=[^>]*href="[^"]*tab=destinations[^"]*")[^>]*>#', $output ), 'Destinos é a segunda aba principal disponível e navegável sem JavaScript' );
uonix_dashboard_assert( strpos( $output, 'id="uonix-panel-metrics" role="tabpanel" aria-labelledby="uonix-tab-metrics"' ) !== false, 'Painel de métricas é relacionado semanticamente à sua aba' );
uonix_dashboard_assert( 1 === preg_match( '#<section(?=[^>]*id="uonix-panel-destinations")(?=[^>]*role="tabpanel")(?=[^>]*aria-labelledby="uonix-tab-destinations")[^>]*\bhidden\b[^>]*>#', $output ), 'Painel de destinos começa oculto fora da aba ativa' );
uonix_dashboard_assert( strpos( $output, 'role="tablist" aria-label="Seções de métricas"' ) !== false, 'Métricas contém subabas acessíveis' );
uonix_dashboard_assert( strpos( $output, 'data-uonix-query-key="tab" data-uonix-query-value="metrics"' ) !== false, 'Aba principal declara o estado de URL que representa' );
uonix_dashboard_assert( strpos( $output, 'data-uonix-query-key="subtab" data-uonix-query-value="catalog"' ) !== false, 'Subaba de catálogo declara o estado de URL que representa' );
uonix_dashboard_assert( strpos( $output, 'data-uonix-query-key="catalog_tab" data-uonix-query-value="blog"' ) !== false, 'Subaba interna de blog declara o estado de URL que representa' );
uonix_dashboard_assert( 0 === preg_match( '#<a[^>]+href="[^"]*uonix_metrics_refresh=1#', $output ), 'Links de abas não propagam o marcador transitório de sincronização' );
uonix_dashboard_assert( strpos( $output, "case 'ArrowRight':" ) !== false && strpos( $output, "case 'Home':" ) !== false && strpos( $output, 'history.replaceState' ) !== false, 'Tabs suportam teclado e atualizam URL sem recarregar' );
uonix_dashboard_assert(
	1 === preg_match( '/\.uonix-tabs-nav\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $output )
	&& 1 === preg_match( '/@media\s*\(max-width:\s*960px\)\s*\{[\s\S]*?\.uonix-tabs-nav\s+\.uonix-tab-btn\s*\{[^}]*flex:\s*1\s+1\s+140px;[^}]*min-width:\s*0;/s', $output ),
	'Subabas do catálogo quebram de forma controlada em telas estreitas, sem overflow horizontal'
);

// Asserções da Issue #209: remoção de emojis e hierarquia do painel
uonix_dashboard_assert( 1 === preg_match( '#<a[^>]*id="uonix-tab-metrics"[^>]*>\s*Métricas\s*</a>#u', $output ), 'Aba principal Métricas não possui emoji' );
uonix_dashboard_assert( 1 === preg_match( '#<a[^>]*id="uonix-tab-destinations"[^>]*>\s*Destinos de marketing configurados\s*</a>#u', $output ), 'Aba principal Destinos não possui emoji' );
uonix_dashboard_assert( 1 === preg_match( '#<a[^>]*id="uonix-tab-aggregate"[^>]*>\s*Métricas agregadas\s*</a>#u', $output ), 'Subaba Métricas agregadas não possui emoji' );
uonix_dashboard_assert( 1 === preg_match( '#<a[^>]*id="uonix-tab-catalog"[^>]*>\s*Catálogo &amp; conteúdo\s*</a>#u', $output ) || 1 === preg_match( '#<a[^>]*id="uonix-tab-catalog"[^>]*>\s*Catálogo & conteúdo\s*</a>#u', $output ), 'Subaba Catálogo & conteúdo não possui emoji' );

$metrics_panel_pos = strpos( $output, 'id="uonix-panel-metrics"' );
$metrics_header_pos = strpos( $output, 'class="uonix-panel-header uonix-metrics-panel-header"' );
$secondary_tabs_pos = strpos( $output, 'class="uonix-secondary-tabs"' );
uonix_dashboard_assert( $metrics_panel_pos !== false && $metrics_header_pos !== false && $secondary_tabs_pos !== false && $metrics_panel_pos < $metrics_header_pos && $metrics_header_pos < $secondary_tabs_pos, 'Cabeçalho e barra de ferramentas de métricas ficam posicionados acima das subabas' );

$destinations_panel_pos = strpos( $output, 'id="uonix-panel-destinations"' );
$status_strip_pos = strpos( $output, 'class="uonix-status-strip"' );
$marketing_grid_pos = strpos( $output, 'class="uonix-marketing-grid"' );
$aggregate_subpanel_pos = strpos( $output, 'id="uonix-subpanel-aggregate"' );
$aggregate_subpanel_end = uonix_dashboard_div_end_offset( $output, $aggregate_subpanel_pos );
uonix_dashboard_assert( $destinations_panel_pos !== false && $status_strip_pos !== false && $marketing_grid_pos !== false && $destinations_panel_pos < $status_strip_pos && $status_strip_pos < $marketing_grid_pos, 'Faixa de status de integrações está dentro do painel de destinos e posicionada acima dos cards' );
uonix_dashboard_assert( $status_strip_pos > $aggregate_subpanel_end, 'Faixa de status não pertence mais ao subpainel de métricas agregadas' );

$_GET = array(
	'tab' => 'metrics',
	'subtab' => 'catalog',
	'catalog_tab' => 'blog',
	'uonix_period' => '90',
);
ob_start();
uonix_render_analytics_dashboard_page();
$output_catalog_blog = ob_get_clean();
uonix_dashboard_assert( 1 === preg_match( '#<a(?=[^>]*id="uonix-tab-catalog")(?=[^>]*role="tab")(?=[^>]*aria-selected="true")(?=[^>]*aria-controls="uonix-panel-catalog")[^>]*>#', $output_catalog_blog ), 'Subaba catálogo é restaurada pela URL e navegável sem JavaScript' );
uonix_dashboard_assert( 1 === preg_match( '#<div(?=[^>]*id="tab-blog")(?=[^>]*role="tabpanel")(?=[^>]*aria-labelledby="uonix-catalog-tab-blog")[^>]*>#', $output_catalog_blog ) && 1 === preg_match( '#<a(?=[^>]*id="uonix-catalog-tab-blog")(?=[^>]*role="tab")(?=[^>]*aria-selected="true")(?=[^>]*aria-controls="tab-blog")[^>]*>#', $output_catalog_blog ), 'Subaba interna blog é restaurada pela URL e navegável sem JavaScript' );
uonix_dashboard_assert( strpos( $output_catalog_blog, 'name="tab" value="metrics"' ) !== false && strpos( $output_catalog_blog, 'name="subtab" value="catalog"' ) !== false && strpos( $output_catalog_blog, 'name="catalog_tab" value="blog"' ) !== false, 'Formulários preservam as abas durante mudança de período e sincronização' );

$_GET = array( 'tab' => 'invalida', 'subtab' => 'invalida', 'catalog_tab' => 'invalida' );
ob_start();
uonix_render_analytics_dashboard_page();
$output_invalid_tabs = ob_get_clean();
uonix_dashboard_assert( 1 === preg_match( '#<a(?=[^>]*id="uonix-tab-metrics")(?=[^>]*aria-selected="true")[^>]*>#', $output_invalid_tabs ) && 1 === preg_match( '#<a(?=[^>]*id="uonix-tab-aggregate")(?=[^>]*aria-selected="true")[^>]*>#', $output_invalid_tabs ) && 1 === preg_match( '#<a(?=[^>]*id="uonix-catalog-tab-products")(?=[^>]*aria-selected="true")[^>]*>#', $output_invalid_tabs ), 'Valores de abas fora da allowlist voltam ao estado padrão seguro' );
$_GET = array();

$_GET['uonix_period'] = '90';
$GLOBALS['uonix_test_snapshot_fresh'] = false;
ob_start();
uonix_render_analytics_dashboard_page();
$output_90_stale = ob_get_clean();
uonix_dashboard_assert( 90 === end( $GLOBALS['uonix_test_snapshot_periods'] ), 'Dashboard solicita o snapshot do período selecionado' );
uonix_dashboard_assert( strpos( $output_90_stale, 'Métricas agregadas dos últimos 90 dias' ) !== false, 'Título acompanha o período de 90 dias' );
uonix_dashboard_assert( preg_match( '/<option value="90" selected(?:="selected")?>Últimos 90 dias<\/option>/', $output_90_stale ) === 1, 'Seletor marca 90 dias como opção ativa' );
uonix_dashboard_assert( strpos( $output_90_stale, 'name="uonix_period" value="90"' ) !== false, 'Formulário de refresh preserva o período selecionado' );
uonix_dashboard_assert( strpos( $output_90_stale, 'data-uonix-auto-refresh="1"' ) !== false && strpos( $output_90_stale, 'requestSubmit' ) !== false, 'Cache vencido inicia atualização administrativa separada' );

$GLOBALS['uonix_test_snapshot_fresh'] = true;
ob_start();
uonix_render_analytics_dashboard_page();
$output_90_fresh = ob_get_clean();
uonix_dashboard_assert( strpos( $output_90_fresh, 'data-uonix-auto-refresh="1"' ) === false, 'Cache fresco não inicia nova consulta' );

$_GET['uonix_period'] = '13';
ob_start();
uonix_render_analytics_dashboard_page();
$output_invalid_period = ob_get_clean();
uonix_dashboard_assert( 30 === end( $GLOBALS['uonix_test_snapshot_periods'] ), 'Período inválido volta para 30 dias' );
uonix_dashboard_assert( strpos( $output_invalid_period, 'Métricas agregadas dos últimos 30 dias' ) !== false, 'Título não reflete período inválido' );
$_GET = array();

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
