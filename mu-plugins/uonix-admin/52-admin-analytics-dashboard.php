<?php
/**
 * Uonix Insights - Painel Integrado de Analytics, Catálogo e Performance no WP-Admin.
 *
 * Oferece uma visão centralizada para administradores e editores:
 * 1. KPIs rápidos do catálogo (produtos, serviços e blog) e atalhos para validação externa de SEO.
 * 2. Estado da configuração local de GTM, GA4, LGPD AdOpt e Search Console.
 * 3. Tabelas detalhadas de Produtos, Artigos e Serviços com atalhos de SEO e links de busca direta.
 * 4. Hub de atalhos rápidos para Google Analytics 4, Search Console e Looker Studio.
 *
 * @package UonixAdmin
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registra o menu "Uônix Insights" no painel administrativo.
 */
add_action('admin_menu', 'uonix_register_analytics_dashboard_menu', 20);
function uonix_register_analytics_dashboard_menu()
{
	add_menu_page(
		__('Uônix Insights', 'uonix'),
		__('Uônix Insights', 'uonix'),
		'edit_posts',
		'uonix-analytics',
		'uonix_render_analytics_dashboard_page',
		'dashicons-chart-area',
		3
	);
}

/**
 * Resolve visualizações de uma URL no snapshot selecionado.
 *
 * Retorna zero apenas quando o relatório GA4 tem cobertura completa. Em um
 * relatório parcial, a ausência do caminho permanece desconhecida (`null`).
 */
function uonix_analytics_dashboard_page_view_value( $snapshot, $permalink )
{
	if ( ! is_array( $snapshot ) || ! is_string( $permalink ) || ! isset( $snapshot['ga4'] ) || ! is_array( $snapshot['ga4'] ) ) {
		return null;
	}
	$path = wp_parse_url( $permalink, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
		return null;
	}
	if ( '/' !== $path ) {
		$path = rtrim( $path, '/' ) . '/';
	}
	$page_views = isset( $snapshot['ga4']['page_views'] ) && is_array( $snapshot['ga4']['page_views'] ) ? $snapshot['ga4']['page_views'] : array();
	if ( array_key_exists( $path, $page_views ) ) {
		$value = is_numeric( $page_views[ $path ] ) ? (float) $page_views[ $path ] : null;
		return null !== $value && is_finite( $value ) && $value >= 0 ? $value : null;
	}
	return true === ( $snapshot['ga4']['page_views_complete'] ?? false ) ? 0.0 : null;
}

/**
 * Apresenta a rota inicial com um rótulo compreensível no ranking.
 */
function uonix_analytics_dashboard_page_label( $path )
{
	return '/' === $path ? 'Home' : $path;
}

/**
 * Prepara até dez linhas de ranking com escala percentual relativa.
 */
function uonix_analytics_dashboard_chart_rows( $rows, $label_key, $value_key )
{
	$chart_rows = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		if ( ! is_array( $row ) || ! isset( $row[ $label_key ], $row[ $value_key ] ) || ! is_scalar( $row[ $label_key ] ) || ! is_numeric( $row[ $value_key ] ) ) {
			continue;
		}
		$label = trim( (string) $row[ $label_key ] );
		$value = (float) $row[ $value_key ];
		if ( '' === $label || ! is_finite( $value ) || $value < 0 ) {
			continue;
		}
		$chart_rows[] = array( 'label' => $label, 'value' => $value );
		if ( 10 === count( $chart_rows ) ) break;
	}
	$maximum = empty( $chart_rows ) ? 0.0 : max( array_column( $chart_rows, 'value' ) );
	foreach ( $chart_rows as &$row ) {
		$row['percent'] = $maximum > 0 ? round( ( $row['value'] / $maximum ) * 100, 1 ) : 0.0;
	}
	unset( $row );
	return $chart_rows;
}

/**
 * Formata a comparação de um KPI sem classificar a variação como melhora ou piora.
 */
function uonix_analytics_dashboard_metric_comparison( $metric )
{
	$fallback = array(
		'label' => 'Comparação indisponível',
		'class' => 'uonix-trend-empty',
	);
	if ( ! is_array( $metric ) || ! isset( $metric['state'] ) || ! is_string( $metric['state'] ) ) {
		return $fallback;
	}

	if ( 'new' === $metric['state'] ) {
		return array( 'label' => 'Novo no período', 'class' => 'uonix-trend-new' );
	}
	if ( 'empty' === $metric['state'] ) {
		return array( 'label' => 'Sem dados nos dois períodos', 'class' => 'uonix-trend-empty' );
	}
	if ( 'comparable' !== $metric['state'] || ! isset( $metric['delta_percent'] ) || ! is_numeric( $metric['delta_percent'] ) ) {
		return $fallback;
	}

	$delta_percent = (float) $metric['delta_percent'];
	if ( ! is_finite( $delta_percent ) ) {
		return $fallback;
	}

	$prefix = $delta_percent > 0 ? '+' : '';
	$class = $delta_percent > 0 ? 'uonix-trend-up' : ( $delta_percent < 0 ? 'uonix-trend-down' : 'uonix-trend-flat' );
	return array(
		'label' => $prefix . number_format_i18n( $delta_percent, 1 ) . '% vs. período anterior',
		'class' => $class,
	);
}

/**
 * Renderiza a página do Dashboard de Analytics e Desempenho.
 */
function uonix_render_analytics_dashboard_page()
{
	if (!current_user_can('edit_posts')) {
		wp_die(esc_html__('Você não tem permissão para acessar esta página.', 'uonix'));
	}

	// Consulta de dados do catálogo
	$products_query = get_posts(array(
		'post_type' => 'product',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'orderby' => 'title',
		'order' => 'ASC',
	));

	$posts_query = get_posts(array(
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'orderby' => 'date',
		'order' => 'DESC',
	));

	$services_query = get_posts(array(
		'post_type' => 'servicos',
		'post_status' => 'publish',
		'posts_per_page' => -1,
		'orderby' => 'title',
		'order' => 'ASC',
	));

	$total_products = count($products_query);
	$total_posts = count($posts_query);
	$total_services = count($services_query);

	// Verificação das integrações
	$general_opts = get_option('rank-math-options-general', array());
	$has_gsc_meta = !empty($general_opts['google_verify']);
	$analytics_configuration = function_exists('uonix_analytics_configuration') ? uonix_analytics_configuration() : false;
	$analytics_is_configured = is_array($analytics_configuration)
		&& !empty($analytics_configuration['gtm_container_id'])
		&& !empty($analytics_configuration['adopt_website_id']);
	$gtm_id = $analytics_is_configured ? $analytics_configuration['gtm_container_id'] : '';
	$gtm_is_audited = $analytics_is_configured && 'GTM-P8TR5CCH' === $gtm_id;
	$analytics_dot_class = $analytics_is_configured ? 'uonix-dot-configured' : 'uonix-dot-inactive';
	$google_ads_dot_class = $gtm_is_audited ? 'uonix-dot-configured' : 'uonix-dot-inactive';
	$gsc_dot_class = $has_gsc_meta ? 'uonix-dot-configured' : 'uonix-dot-inactive';
	$gtm_status = $analytics_is_configured ? $gtm_id . ' [Configurado]' : 'Não configurado';
	$ga4_status = $analytics_is_configured ? 'Via GTM [Verificação externa]' : 'Não configurado';
	$meta_status = $analytics_is_configured ? 'Via GTM + LGPD [Verificação externa]' : 'Não configurado';
	$gsc_status = $has_gsc_meta ? 'Meta tag [Configurada]' : 'Meta tag [Não configurada]';
	$adopt_status = $analytics_is_configured ? 'Website ID [Configurado]' : 'Não configurado';
	$google_ads_id = $gtm_is_audited ? 'AW-6012006717' : '';
	$google_ads_status = $gtm_is_audited
		? $google_ads_id . ' via GTM [Verificação externa]'
		: ( $analytics_is_configured ? 'Requer validação do GTM' : 'Não configurado' );
	$gsc_domain_url = 'https://search.google.com/search-console?resource_id=sc-domain:uonix.com.br';
	$ga4_url = 'https://analytics.google.com/analytics/web/';
	$google_ads_url = 'https://ads.google.com/aw/overview';
	$gtm_url = 'https://tagmanager.google.com/#/container/accounts/6348960683/containers/248910884/workspaces';
	$looker_url = 'https://lookerstudio.google.com/';
	$looker_gallery_url = 'https://lookerstudio.google.com/gallery';
	$meta_events_url = 'https://business.facebook.com/events_manager2';
	$meta_diagnostics_url = 'https://business.facebook.com/events_manager2/diagnostics';
	$meta_test_events_url = 'https://business.facebook.com/events_manager2/test_events';
	$meta_suite_url = 'https://business.facebook.com/latest/home';
	$gsc_performance_url = 'https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain:uonix.com.br';
	$gsc_index_url = 'https://search.google.com/search-console/index?resource_id=sc-domain:uonix.com.br';
	$gsc_sitemaps_url = 'https://search.google.com/search-console/sitemaps?resource_id=sc-domain:uonix.com.br';
	$adopt_tags_url = 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/tags';
	$adopt_documents_url = 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/documents';
	$adopt_settings_url = 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix';
	$adopt_url = 'https://dash.goadopt.io/org/uonix/disclaimers';
	$requested_period = isset( $_GET['uonix_period'] ) ? wp_unslash( $_GET['uonix_period'] ) : 30;
	$metrics_period_days = function_exists( 'uonix_analytics_metrics_sanitize_period_days' ) ? uonix_analytics_metrics_sanitize_period_days( $requested_period ) : 30;
	$metrics_snapshot = function_exists( 'uonix_analytics_metrics_get_snapshot' ) ? uonix_analytics_metrics_get_snapshot( $metrics_period_days ) : false;
	$metrics_status = is_array( $metrics_snapshot ) ? ( $metrics_snapshot['status'] ?? 'updated' ) : 'unavailable';
	$metrics_is_fresh = function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' ) && uonix_analytics_metrics_snapshot_is_fresh( $metrics_snapshot );
	$metrics_refresh_attempted = isset( $_GET['uonix_metrics_refresh'] ) && '1' === (string) wp_unslash( $_GET['uonix_metrics_refresh'] );
	$metrics_auto_refresh = current_user_can( 'manage_options' ) && ! $metrics_is_fresh && ! $metrics_refresh_attempted;
	$metrics_updated_at = is_array( $metrics_snapshot ) && isset( $metrics_snapshot['updated_at'] ) && is_string( $metrics_snapshot['updated_at'] ) ? $metrics_snapshot['updated_at'] : '';
	$metrics_updated_timestamp = '' !== $metrics_updated_at ? strtotime( $metrics_updated_at ) : false;
	$metrics_updated_datetime = false !== $metrics_updated_timestamp ? gmdate( 'c', $metrics_updated_timestamp ) : '';
	$metrics_updated_label = false !== $metrics_updated_timestamp ? wp_date( 'd/m/Y H:i', $metrics_updated_timestamp ) : '';
	$metrics_cache_class = $metrics_is_fresh ? 'uonix-cache-fresh' : ( is_array( $metrics_snapshot ) ? 'uonix-cache-stale' : 'uonix-cache-empty' );
	$metrics_cache_label = $metrics_is_fresh ? 'Cache atualizado' : ( is_array( $metrics_snapshot ) ? 'Cache vencido' : 'Sem snapshot' );
	$dashboard_state = function_exists( 'uonix_analytics_metrics_requested_dashboard_state' )
		? uonix_analytics_metrics_requested_dashboard_state( $_GET )
		: array( 'tab' => 'metrics', 'subtab' => 'aggregate', 'catalog_tab' => 'products' );
	$active_dashboard_tab = $dashboard_state['tab'];
	$active_metrics_subtab = $dashboard_state['subtab'];
	$active_catalog_tab = $dashboard_state['catalog_tab'];
	$dashboard_tab_url = static function ( $tab, $subtab, $catalog_tab ) use ( $metrics_period_days ) {
		$state = array(
			'tab' => $tab,
			'subtab' => $subtab,
			'catalog_tab' => $catalog_tab,
		);
		if ( function_exists( 'uonix_analytics_metrics_dashboard_url' ) ) {
			return uonix_analytics_metrics_dashboard_url( $metrics_period_days, $state );
		}
		return admin_url( 'admin.php?page=uonix-analytics&uonix_period=' . $metrics_period_days );
	};
	?>
	<div class="wrap uonix-analytics-wrap">
		<!-- Header Principal -->
		<div class="uonix-analytics-header">
			<div class="uonix-header-content">
				<div class="uonix-header-badge">UÔNIX - ANCORAGEM PREDIAL</div>
				<h1>Central de Desempenho, Catálogo & Analytics</h1>
				<p>Consulte o catálogo e os artigos técnicos, e acesse as plataformas externas para verificar tráfego, tags
					e indexação.</p>
			</div>
		</div>

		<nav class="uonix-primary-tabs" role="tablist" aria-label="Seções do painel">
			<a id="uonix-tab-metrics" role="tab" aria-selected="<?php echo 'metrics' === $active_dashboard_tab ? 'true' : 'false'; ?>" aria-controls="uonix-panel-metrics" tabindex="<?php echo 'metrics' === $active_dashboard_tab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', $active_metrics_subtab, $active_catalog_tab ) ); ?>" data-uonix-panel="uonix-panel-metrics" data-uonix-query-key="tab" data-uonix-query-value="metrics">Métricas</a>
			<a id="uonix-tab-destinations" role="tab" aria-selected="<?php echo 'destinations' === $active_dashboard_tab ? 'true' : 'false'; ?>" aria-controls="uonix-panel-destinations" tabindex="<?php echo 'destinations' === $active_dashboard_tab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'destinations', $active_metrics_subtab, $active_catalog_tab ) ); ?>" data-uonix-panel="uonix-panel-destinations" data-uonix-query-key="tab" data-uonix-query-value="destinations">Destinos de marketing configurados</a>
		</nav>

		<section id="uonix-panel-metrics" role="tabpanel" aria-labelledby="uonix-tab-metrics"<?php echo 'metrics' === $active_dashboard_tab ? '' : ' hidden'; ?>>
			<div class="uonix-panel-header uonix-metrics-panel-header">
				<div class="uonix-metrics-copy">
					<h2 id="uonix-metrics-title">Métricas agregadas dos últimos <?php echo esc_html( $metrics_period_days ); ?> dias</h2>
					<p><?php echo esc_html( 'updated' === $metrics_status ? 'Fonte: GA4 Data API e Search Console API. Comparação com os ' . $metrics_period_days . ' dias anteriores.' : ( 'stale' === $metrics_status ? 'Último snapshot disponível; atualização pendente.' : 'Conexão não configurada ou sem snapshot. Nenhuma métrica é exibida.' ) ); ?></p>
					<div class="uonix-metrics-cache-meta">
						<span class="uonix-metrics-cache-status <?php echo esc_attr( $metrics_cache_class ); ?>"><?php echo esc_html( $metrics_cache_label ); ?></span>
						<?php if ( '' !== $metrics_updated_label ) : ?>
							<time datetime="<?php echo esc_attr( $metrics_updated_datetime ); ?>">Atualizado em <?php echo esc_html( $metrics_updated_label ); ?></time>
						<?php endif; ?>
					</div>
				</div>
				<div class="uonix-metrics-toolbar">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="uonix-metrics-period-form">
					<input type="hidden" name="page" value="uonix-analytics" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $active_dashboard_tab ); ?>" />
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_metrics_subtab ); ?>" />
					<input type="hidden" name="catalog_tab" value="<?php echo esc_attr( $active_catalog_tab ); ?>" />
					<label for="uonix-metrics-period">Período</label>
					<select id="uonix-metrics-period" name="uonix_period" onchange="this.form.requestSubmit()">
						<?php foreach ( array( 7, 30, 90, 365 ) as $period_option ) : ?>
							<option value="<?php echo esc_attr( $period_option ); ?>"<?php echo $period_option === $metrics_period_days ? ' selected="selected"' : ''; ?>>Últimos <?php echo esc_html( $period_option ); ?> dias</option>
						<?php endforeach; ?>
					</select>
					<noscript><button type="submit" class="button">Aplicar período</button></noscript>
				</form>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uonix-metrics-refresh-form"<?php echo $metrics_auto_refresh ? ' data-uonix-auto-refresh="1"' : ''; ?>>
						<input type="hidden" name="action" value="uonix_analytics_metrics_refresh" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( $active_dashboard_tab ); ?>" />
						<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_metrics_subtab ); ?>" />
						<input type="hidden" name="catalog_tab" value="<?php echo esc_attr( $active_catalog_tab ); ?>" />
						<input type="hidden" name="uonix_period" value="<?php echo esc_attr( $metrics_period_days ); ?>" />
						<?php wp_nonce_field( 'uonix_analytics_metrics_refresh' ); ?>
						<button type="submit" class="button button-secondary">Atualizar métricas agora</button>
						<span>Consulta somente leitura.</span>
					</form>
					<?php if ( $metrics_auto_refresh ) : ?>
						<script>
							document.addEventListener('DOMContentLoaded', function () {
								var form = document.querySelector('.uonix-metrics-refresh-form[data-uonix-auto-refresh="1"]');
								if (!form) return;
								var button = form.querySelector('button[type="submit"]');
								if (button) {
									button.disabled = true;
									button.textContent = 'Atualizando métricas…';
								}
								if (typeof form.requestSubmit === 'function') form.requestSubmit();
								else form.submit();
							});
						</script>
					<?php endif; ?>
				<?php endif; ?>
				</div>
			</div>

			<nav class="uonix-secondary-tabs" role="tablist" aria-label="Seções de métricas">
				<a id="uonix-tab-aggregate" role="tab" aria-selected="<?php echo 'aggregate' === $active_metrics_subtab ? 'true' : 'false'; ?>" aria-controls="uonix-panel-aggregate" tabindex="<?php echo 'aggregate' === $active_metrics_subtab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', 'aggregate', $active_catalog_tab ) ); ?>" data-uonix-panel="uonix-panel-aggregate" data-uonix-query-key="subtab" data-uonix-query-value="aggregate">Métricas agregadas</a>
				<a id="uonix-tab-catalog" role="tab" aria-selected="<?php echo 'catalog' === $active_metrics_subtab ? 'true' : 'false'; ?>" aria-controls="uonix-panel-catalog" tabindex="<?php echo 'catalog' === $active_metrics_subtab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', 'catalog', $active_catalog_tab ) ); ?>" data-uonix-panel="uonix-panel-catalog" data-uonix-query-key="subtab" data-uonix-query-value="catalog">Catálogo &amp; conteúdo</a>
			</nav>

			<div id="uonix-panel-aggregate" role="tabpanel" aria-labelledby="uonix-tab-aggregate"<?php echo 'aggregate' === $active_metrics_subtab ? '' : ' hidden'; ?>>
		<section class="uonix-marketing-section" aria-labelledby="uonix-metrics-title">
			<?php if ( is_array( $metrics_snapshot ) && isset( $metrics_snapshot['ga4'], $metrics_snapshot['search_console'] ) ) :
				$ga4_summary = $metrics_snapshot['ga4']['summary'];
				$gsc_summary = $metrics_snapshot['search_console']['summary'];
				$metric_value = static function ( $metric, $percent = false ) { return $percent ? number_format_i18n( (float) $metric['current'] * 100, 1 ) . '%' : number_format_i18n( (float) $metric['current'] ); };
				$active_users_comparison = uonix_analytics_dashboard_metric_comparison( $ga4_summary['active_users'] );
				$sessions_comparison = uonix_analytics_dashboard_metric_comparison( $ga4_summary['sessions'] );
				$clicks_comparison = uonix_analytics_dashboard_metric_comparison( $gsc_summary['clicks'] );
				$impressions_comparison = uonix_analytics_dashboard_metric_comparison( $gsc_summary['impressions'] );
				$landing_page_chart = uonix_analytics_dashboard_chart_rows( $metrics_snapshot['ga4']['landing_pages'] ?? array(), 'path', 'sessions' );
				foreach ( $landing_page_chart as &$row ) {
					$row['label'] = uonix_analytics_dashboard_page_label( $row['label'] );
				}
				unset( $row );
				$query_chart = uonix_analytics_dashboard_chart_rows( $metrics_snapshot['search_console']['queries'] ?? array(), 'query', 'clicks' );
			?>
			<div class="uonix-kpi-grid">
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $ga4_summary['active_users'] ) ); ?></span><span class="uonix-kpi-title">Usuários ativos</span><span class="uonix-kpi-comparison <?php echo esc_attr( $active_users_comparison['class'] ); ?>"><?php echo esc_html( $active_users_comparison['label'] ); ?></span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $ga4_summary['sessions'] ) ); ?></span><span class="uonix-kpi-title">Sessões</span><span class="uonix-kpi-comparison <?php echo esc_attr( $sessions_comparison['class'] ); ?>"><?php echo esc_html( $sessions_comparison['label'] ); ?></span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $gsc_summary['clicks'] ) ); ?></span><span class="uonix-kpi-title">Cliques orgânicos</span><span class="uonix-kpi-comparison <?php echo esc_attr( $clicks_comparison['class'] ); ?>"><?php echo esc_html( $clicks_comparison['label'] ); ?></span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $gsc_summary['impressions'] ) ); ?></span><span class="uonix-kpi-title">Impressões orgânicas</span><span class="uonix-kpi-comparison <?php echo esc_attr( $impressions_comparison['class'] ); ?>"><?php echo esc_html( $impressions_comparison['label'] ); ?></span></div></div>
			</div>
			<div class="uonix-ranking-grid">
				<section class="uonix-ranking-chart" aria-labelledby="uonix-landing-pages-chart-title">
					<h3 id="uonix-landing-pages-chart-title">Principais páginas de entrada</h3>
					<?php if ( ! empty( $landing_page_chart ) ) : ?>
						<ol class="uonix-ranking-list">
							<?php foreach ( $landing_page_chart as $row ) :
								$formatted_value = number_format_i18n( $row['value'] );
							?>
								<li aria-label="<?php echo esc_attr( $row['label'] . ': ' . $formatted_value . ' sessões' ); ?>">
									<div class="uonix-ranking-label"><code><?php echo esc_html( $row['label'] ); ?></code><strong><?php echo esc_html( $formatted_value ); ?> sessões</strong></div>
									<div class="uonix-ranking-bar-track" aria-hidden="true"><span class="uonix-ranking-bar-fill" style="width:<?php echo esc_attr( number_format( $row['percent'], 1, '.', '' ) ); ?>%"></span></div>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="uonix-ranking-empty">Nenhuma página de entrada no período.</p>
					<?php endif; ?>
				</section>
				<section class="uonix-ranking-chart" aria-labelledby="uonix-queries-chart-title">
					<h3 id="uonix-queries-chart-title">Principais consultas orgânicas</h3>
					<?php if ( ! empty( $query_chart ) ) : ?>
						<ol class="uonix-ranking-list">
							<?php foreach ( $query_chart as $row ) :
								$formatted_value = number_format_i18n( $row['value'] );
							?>
								<li aria-label="<?php echo esc_attr( $row['label'] . ': ' . $formatted_value . ' cliques' ); ?>">
									<div class="uonix-ranking-label"><span><?php echo esc_html( $row['label'] ); ?></span><strong><?php echo esc_html( $formatted_value ); ?> cliques</strong></div>
									<div class="uonix-ranking-bar-track" aria-hidden="true"><span class="uonix-ranking-bar-fill uonix-ranking-bar-fill-search" style="width:<?php echo esc_attr( number_format( $row['percent'], 1, '.', '' ) ); ?>%"></span></div>
								</li>
							<?php endforeach; ?>
						</ol>
						<p class="uonix-ranking-note">O Search Console pode omitir linhas de baixo volume.</p>
					<?php else : ?>
						<p class="uonix-ranking-empty">Nenhuma consulta orgânica disponível no período.</p>
					<?php endif; ?>
				</section>
			</div>
			<?php endif; ?>
		</section>
			</div>

			<div id="uonix-panel-catalog" role="tabpanel" aria-labelledby="uonix-tab-catalog"<?php echo 'catalog' === $active_metrics_subtab ? '' : ' hidden'; ?>>
		<!-- Cards de Métricas Rápidas -->
		<div class="uonix-kpi-grid">
			<div class="uonix-kpi-card">
				<div class="uonix-kpi-icon uonix-icon-blue"><span class="dashicons dashicons-products"></span></div>
				<div class="uonix-kpi-data">
					<span class="uonix-kpi-value"><?php echo esc_html($total_products); ?></span>
					<span class="uonix-kpi-title">Produtos Cadastrados</span>
					<span class="uonix-kpi-sub">Dispositivos & Fixações</span>
				</div>
			</div>
			<div class="uonix-kpi-card">
				<div class="uonix-kpi-icon uonix-icon-green"><span class="dashicons dashicons-welcome-write-blog"></span>
				</div>
				<div class="uonix-kpi-data">
					<span class="uonix-kpi-value"><?php echo esc_html($total_posts); ?></span>
					<span class="uonix-kpi-title">Artigos no Blog</span>
					<span class="uonix-kpi-sub">Guias NR-35 & NBR 16325</span>
				</div>
			</div>
			<div class="uonix-kpi-card">
				<div class="uonix-kpi-icon uonix-icon-purple"><span class="dashicons dashicons-hammer"></span></div>
				<div class="uonix-kpi-data">
					<span class="uonix-kpi-value"><?php echo esc_html($total_services); ?></span>
					<span class="uonix-kpi-title">Serviços Técnicos</span>
					<span class="uonix-kpi-sub">Instalações, Ensaios & ART</span>
				</div>
			</div>
			<div class="uonix-kpi-card">
				<div class="uonix-kpi-icon uonix-icon-orange"><span class="dashicons dashicons-awards"></span></div>
				<div class="uonix-kpi-data">
					<span class="uonix-kpi-value">Externa</span>
					<span class="uonix-kpi-title">Validação externa de SEO</span>
					<span class="uonix-kpi-sub">Search Console & testes de schema</span>
				</div>
			</div>
		</div>

		<!-- Abas de Navegação do catálogo -->
		<div class="uonix-tabs-nav" role="tablist" aria-label="Seções do catálogo">
			<a id="uonix-catalog-tab-products" class="uonix-tab-btn<?php echo 'products' === $active_catalog_tab ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo 'products' === $active_catalog_tab ? 'true' : 'false'; ?>" aria-controls="tab-products" tabindex="<?php echo 'products' === $active_catalog_tab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', 'catalog', 'products' ) ); ?>" data-uonix-panel="tab-products" data-uonix-query-key="catalog_tab" data-uonix-query-value="products">
				<span class="dashicons dashicons-products"></span> Produtos (<?php echo esc_html($total_products); ?>)
			</a>
			<a id="uonix-catalog-tab-blog" class="uonix-tab-btn<?php echo 'blog' === $active_catalog_tab ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo 'blog' === $active_catalog_tab ? 'true' : 'false'; ?>" aria-controls="tab-blog" tabindex="<?php echo 'blog' === $active_catalog_tab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', 'catalog', 'blog' ) ); ?>" data-uonix-panel="tab-blog" data-uonix-query-key="catalog_tab" data-uonix-query-value="blog">
				<span class="dashicons dashicons-welcome-write-blog"></span> Artigos de Blog
				(<?php echo esc_html($total_posts); ?>)
			</a>
			<a id="uonix-catalog-tab-services" class="uonix-tab-btn<?php echo 'services' === $active_catalog_tab ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo 'services' === $active_catalog_tab ? 'true' : 'false'; ?>" aria-controls="tab-services" tabindex="<?php echo 'services' === $active_catalog_tab ? '0' : '-1'; ?>" href="<?php echo esc_url( $dashboard_tab_url( 'metrics', 'catalog', 'services' ) ); ?>" data-uonix-panel="tab-services" data-uonix-query-key="catalog_tab" data-uonix-query-value="services">
				<span class="dashicons dashicons-hammer"></span> Serviços
				(<?php echo esc_html($total_services); ?>)
			</a>
		</div>

		<!-- Conteúdo das Abas -->
		<div class="uonix-tab-content-wrapper">

			<!-- ABA 1: PRODUTOS -->
			<div id="tab-products" role="tabpanel" aria-labelledby="uonix-catalog-tab-products"<?php echo 'products' === $active_catalog_tab ? '' : ' hidden'; ?> class="uonix-tab-panel<?php echo 'products' === $active_catalog_tab ? ' active' : ''; ?>">
				<div class="uonix-panel-header">
					<h2>Catálogo de Dispositivos e Fixações (<?php echo esc_html($total_products); ?> Produtos)</h2>
					<p>Monitore os títulos comerciais, palavras-chave de foco e consulte o desempenho de busca no Google
						para cada produto.</p>
				</div>
				<div class="uonix-table-responsive">
					<table class="uonix-table">
						<thead>
							<tr>
								<th>Produto</th>
								<th>Categoria</th>
								<th>Palavra-Chave Principal</th>
								<th>Título SEO (Google)</th>
								<th>Visualizações — <?php echo esc_html( $metrics_period_days ); ?> dias</th>
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($products_query as $product_post):
								$pid = $product_post->ID;
								$permalink = get_permalink($pid);
								$page_view_value = uonix_analytics_dashboard_page_view_value( $metrics_snapshot, $permalink );
								$edit_link = get_edit_post_link($pid);
								$kw = get_post_meta($pid, 'rank_math_focus_keyword', true);
								$seo_title = get_post_meta($pid, 'rank_math_title', true);
								$terms = get_the_terms($pid, 'product_cat');
								$cat_name = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->name : '—';
								$gsc_inspect = 'https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain:uonix.com.br&page=*' . rawurlencode($product_post->post_name);
								?>
								<tr>
									<td class="uonix-title-col">
										<strong><?php echo esc_html($product_post->post_title); ?></strong>
										<span
											class="uonix-slug-badge">/produtos/<?php echo esc_html($product_post->post_name); ?>/</span>
									</td>
									<td><span class="uonix-tag"><?php echo esc_html($cat_name); ?></span></td>
									<td><code><?php echo esc_html($kw ? $kw : '—'); ?></code></td>
									<td class="uonix-desc-col">
										<?php echo esc_html($seo_title ? $seo_title : $product_post->post_title); ?>
									</td>
									<td class="uonix-page-views-col"><?php echo esc_html( null === $page_view_value ? '—' : number_format_i18n( $page_view_value ) ); ?></td>
									<td style="text-align:right; white-space:nowrap;">
										<a href="<?php echo esc_url($permalink); ?>" target="_blank" class="button button-small"
											title="Ver no site">
											<span class="dashicons dashicons-visibility"></span> Ver
										</a>
										<a href="<?php echo esc_url($edit_link); ?>" class="button button-small"
											title="Editar produto">
											<span class="dashicons dashicons-edit"></span> Editar
										</a>
										<a href="<?php echo esc_url($gsc_inspect); ?>" target="_blank" rel="noopener"
											class="button button-small button-secondary"
											title="Ver buscas deste produto no Search Console">
											<span class="dashicons dashicons-search"></span> Google
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- ABA 2: BLOG -->
			<div id="tab-blog" role="tabpanel" aria-labelledby="uonix-catalog-tab-blog"<?php echo 'blog' === $active_catalog_tab ? '' : ' hidden'; ?> class="uonix-tab-panel<?php echo 'blog' === $active_catalog_tab ? ' active' : ''; ?>">
				<div class="uonix-panel-header">
					<h2>Artigos Técnicos e Guias Normativos (<?php echo esc_html($total_posts); ?> Artigos)</h2>
					<p>Artigos que atraem tráfego orgânico qualificado para palavras-chave de engenharia e trabalho em
						altura.</p>
				</div>
				<div class="uonix-table-responsive">
					<table class="uonix-table">
						<thead>
							<tr>
								<th>Artigo</th>
								<th>Data</th>
								<th>Palavra-Chave Principal</th>
								<th>Título SEO</th>
								<th>Visualizações — <?php echo esc_html( $metrics_period_days ); ?> dias</th>
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($posts_query as $blog_post):
								$bid = $blog_post->ID;
								$permalink = get_permalink($bid);
								$page_view_value = uonix_analytics_dashboard_page_view_value( $metrics_snapshot, $permalink );
								$edit_link = get_edit_post_link($bid);
								$kw = get_post_meta($bid, 'rank_math_focus_keyword', true);
								$seo_title = get_post_meta($bid, 'rank_math_title', true);
								$date = get_the_date('d/m/Y', $bid);
								$gsc_inspect = 'https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain:uonix.com.br&page=*' . rawurlencode($blog_post->post_name);
								?>
								<tr>
									<td class="uonix-title-col">
										<strong><?php echo esc_html($blog_post->post_title); ?></strong>
										<span class="uonix-slug-badge">/<?php echo esc_html($blog_post->post_name); ?>/</span>
									</td>
									<td><?php echo esc_html($date); ?></td>
									<td><code><?php echo esc_html($kw ? $kw : '—'); ?></code></td>
									<td class="uonix-desc-col">
										<?php echo esc_html($seo_title ? $seo_title : $blog_post->post_title); ?>
									</td>
									<td class="uonix-page-views-col"><?php echo esc_html( null === $page_view_value ? '—' : number_format_i18n( $page_view_value ) ); ?></td>
									<td style="text-align:right; white-space:nowrap;">
										<a href="<?php echo esc_url($permalink); ?>" target="_blank" class="button button-small"
											title="Ver no site">
											<span class="dashicons dashicons-visibility"></span> Ver
										</a>
										<a href="<?php echo esc_url($edit_link); ?>" class="button button-small"
											title="Editar artigo">
											<span class="dashicons dashicons-edit"></span> Editar
										</a>
										<a href="<?php echo esc_url($gsc_inspect); ?>" target="_blank" rel="noopener"
											class="button button-small button-secondary"
											title="Ver buscas deste post no Search Console">
											<span class="dashicons dashicons-search"></span> Google
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- ABA 3: SERVIÇOS -->
			<div id="tab-services" role="tabpanel" aria-labelledby="uonix-catalog-tab-services"<?php echo 'services' === $active_catalog_tab ? '' : ' hidden'; ?> class="uonix-tab-panel<?php echo 'services' === $active_catalog_tab ? ' active' : ''; ?>">
				<div class="uonix-panel-header">
					<h2>Serviços Técnicos e Consultoria de Engenharia (<?php echo esc_html($total_services); ?> Serviços)
					</h2>
					<p>Serviços com emissão de ART, ensaios de arrancamento estático e projetos de proteção contra quedas.
					</p>
				</div>
				<div class="uonix-table-responsive">
					<table class="uonix-table">
						<thead>
							<tr>
								<th>Serviço</th>
								<th>Palavra-Chave Principal</th>
								<th>Dados Estruturados</th>
								<th>Título SEO</th>
								<th>Visualizações — <?php echo esc_html( $metrics_period_days ); ?> dias</th>
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($services_query as $service_post):
								$sid = $service_post->ID;
								$permalink = get_permalink($sid);
								$page_view_value = uonix_analytics_dashboard_page_view_value( $metrics_snapshot, $permalink );
								$edit_link = get_edit_post_link($sid);
								$kw = get_post_meta($sid, 'rank_math_focus_keyword', true);
								$seo_title = get_post_meta($sid, 'rank_math_title', true);
								$gsc_inspect = 'https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain:uonix.com.br&page=*' . rawurlencode($service_post->post_name);
								?>
								<tr>
									<td class="uonix-title-col">
										<strong><?php echo esc_html($service_post->post_title); ?></strong>
										<span
											class="uonix-slug-badge">/servicos/<?php echo esc_html($service_post->post_name); ?>/</span>
									</td>
									<td><code><?php echo esc_html($kw ? $kw : '—'); ?></code></td>
									<td><span class="uonix-tag uonix-tag-schema">Verificação externa</span></td>
									<td class="uonix-desc-col">
										<?php echo esc_html($seo_title ? $seo_title : $service_post->post_title); ?>
									</td>
									<td class="uonix-page-views-col"><?php echo esc_html( null === $page_view_value ? '—' : number_format_i18n( $page_view_value ) ); ?></td>
									<td style="text-align:right; white-space:nowrap;">
										<a href="<?php echo esc_url($permalink); ?>" target="_blank" class="button button-small"
											title="Ver no site">
											<span class="dashicons dashicons-visibility"></span> Ver
										</a>
										<a href="<?php echo esc_url($edit_link); ?>" class="button button-small"
											title="Editar serviço">
											<span class="dashicons dashicons-edit"></span> Editar
										</a>
										<a href="<?php echo esc_url($gsc_inspect); ?>" target="_blank" rel="noopener"
											class="button button-small button-secondary"
											title="Ver buscas deste serviço no Search Console">
											<span class="dashicons dashicons-search"></span> Google
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>
			</div>
		</section>

		<!-- Destinos de marketing: fatos técnicos locais, sem métricas externas não consultadas. -->
		<section id="uonix-panel-destinations" class="uonix-marketing-section" role="tabpanel" aria-labelledby="uonix-tab-destinations"<?php echo 'destinations' === $active_dashboard_tab ? '' : ' hidden'; ?>>
			<div class="uonix-panel-header">
				<h2 id="uonix-marketing-title">Destinos de marketing configurados</h2>
				<p>Este painel confirma apenas a configuração local. Dados de audiência, campanhas e resultados devem ser conferidos na plataforma indicada.</p>
			</div>

			<!-- Status das Tags & Rastreamento -->
			<div class="uonix-status-strip">
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($analytics_dot_class); ?>"></span>
					<span class="uonix-status-label">Google Tag Manager:</span>
					<strong><?php echo esc_html($gtm_status); ?></strong>
				</div>
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($analytics_dot_class); ?>"></span>
					<span class="uonix-status-label">Google Analytics 4:</span>
					<strong><?php echo esc_html($ga4_status); ?></strong>
				</div>
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($google_ads_dot_class); ?>"></span>
					<span class="uonix-status-label">Google Ads:</span>
					<strong><?php echo esc_html($google_ads_status); ?></strong>
				</div>
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($analytics_dot_class); ?>"></span>
					<span class="uonix-status-label">Meta Pixel:</span>
					<strong><?php echo esc_html($meta_status); ?></strong>
				</div>
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($gsc_dot_class); ?>"></span>
					<span class="uonix-status-label">Search Console:</span>
					<strong><?php echo esc_html($gsc_status); ?></strong>
				</div>
				<div class="uonix-status-item">
					<span class="uonix-status-dot <?php echo esc_html($analytics_dot_class); ?>"></span>
					<span class="uonix-status-label">LGPD AdOpt:</span>
					<strong><?php echo esc_html($adopt_status); ?></strong>
				</div>
			</div>

			<div class="uonix-marketing-grid">
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-admin-generic uonix-sc-icon-gtm"></span><h3>Google Tag Manager</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($gtm_status); ?></dd><dt>Finalidade</dt><dd>Centralizar tags e consentimento.</dd><dt>Validar em</dt><dd>Container, versão e Preview no GTM.</dd></dl>
					<a href="<?php echo esc_url($gtm_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Google Tag Manager</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-chart-line uonix-sc-icon-ga"></span><h3>Google Analytics 4</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($analytics_is_configured ? 'G-RFY1BB1RM4 via GTM' : 'Não configurado'); ?></dd><dt>Finalidade</dt><dd>Mensuração estatística conforme consentimento.</dd><dt>Validar em</dt><dd>Relatórios, Eventos e DebugView no GA4.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">Visão geral e tempo real</a></li><li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">Páginas e telas</a></li><li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">Aquisição de tráfego</a></li></ul>
					<a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Google Analytics</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-megaphone uonix-sc-icon-ads"></span><h3>Google Ads via GTM</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($gtm_is_audited ? $google_ads_id . ' — Google Tag, vinculador de conversões e remarketing' : $google_ads_status); ?></dd><dt>Finalidade</dt><dd>Mensuração de mídia e remarketing conforme consentimento de marketing.</dd><dt>Validar em</dt><dd>Validar campanhas, públicos e resultados no Google Ads.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">Campanhas e grupos de anúncios</a></li><li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">Diagnóstico de mensuração</a></li><li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">Públicos de remarketing</a></li></ul>
					<a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline uonix-btn-outline-ads">Abrir Google Ads</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-shield uonix-sc-icon-adopt"></span><h3>AdOpt e Consent Mode</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($adopt_status); ?></dd><dt>Finalidade</dt><dd>Controlar categorias estatísticas e de marketing.</dd><dt>Validar em</dt><dd>Banner e preferências na produção.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url( $adopt_tags_url ); ?>" target="_blank" rel="noopener">Escanear tags</a></li><li><a href="<?php echo esc_url( $adopt_documents_url ); ?>" target="_blank" rel="noopener">Documentos</a></li><li><a href="<?php echo esc_url( $adopt_settings_url ); ?>" target="_blank" rel="noopener">Configurações</a></li></ul>
					<a href="<?php echo esc_url( $adopt_url ); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir AdOpt</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-facebook-alt uonix-sc-icon-meta"></span><h3>Meta Pixel</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($meta_status); ?></dd><dt>Finalidade</dt><dd>PageView sujeito ao consentimento de marketing.</dd><dt>Validar em</dt><dd>Abra a plataforma para verificar o recebimento, diagnósticos e qualidade do Pixel.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener">Gerenciador de Eventos</a></li><li><a href="<?php echo esc_url( $meta_diagnostics_url ); ?>" target="_blank" rel="noopener">Diagnóstico e qualidade</a></li><li><a href="<?php echo esc_url( $meta_test_events_url ); ?>" target="_blank" rel="noopener">Testar eventos</a></li><li><a href="<?php echo esc_url($meta_suite_url); ?>" target="_blank" rel="noopener">Meta Business Suite</a></li></ul>
					<a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline uonix-btn-outline-meta">Abrir Events Manager</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-search uonix-sc-icon-gsc"></span><h3>Google Search Console</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($gsc_status); ?></dd><dt>Finalidade</dt><dd>Pesquisar desempenho orgânico e indexação.</dd><dt>Validar em</dt><dd>Desempenho, páginas e sitemaps no Search Console.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url( $gsc_performance_url ); ?>" target="_blank" rel="noopener">Consultas de pesquisa</a></li><li><a href="<?php echo esc_url( $gsc_index_url ); ?>" target="_blank" rel="noopener">Cobertura e indexação</a></li><li><a href="<?php echo esc_url( $gsc_sitemaps_url ); ?>" target="_blank" rel="noopener">Sitemaps XML</a></li></ul>
					<a href="<?php echo esc_url($gsc_domain_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Search Console</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-analytics uonix-sc-icon-looker"></span><h3>Google Looker Studio</h3></div>
					<dl><dt>Configuração local</dt><dd>Plataforma externa</dd><dt>Finalidade</dt><dd>Criar painéis e relatórios personalizados.</dd><dt>Validar em</dt><dd>Fontes de dados e permissões no Looker Studio.</dd></dl>
					<ul class="uonix-card-links"><li><a href="<?php echo esc_url($looker_url); ?>" target="_blank" rel="noopener">Abrir painéis</a></li><li><a href="<?php echo esc_url( $looker_gallery_url ); ?>" target="_blank" rel="noopener">Galeria de modelos</a></li></ul>
					<a href="<?php echo esc_url($looker_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Looker Studio</a>
				</div>
			</div>
		</section>
	</div>

	<!-- Estilos CSS do Dashboard -->
	<style>
		.uonix-analytics-wrap {
			max-width: none;
			width: auto;
			box-sizing: border-box;
			margin: 20px 20px 40px 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			color: #1e293b;
		}

		.uonix-analytics-header {
			background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
			color: #ffffff;
			padding: 28px 32px;
			border-radius: 12px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
			margin-bottom: 16px;
		}

		.uonix-header-badge {
			display: inline-block;
			background: rgba(255, 255, 255, 0.15);
			color: #93c5fd;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.8px;
			padding: 4px 10px;
			border-radius: 20px;
			margin-bottom: 8px;
		}

		.uonix-analytics-header h1 {
			color: #ffffff;
			font-size: 24px;
			margin: 0 0 6px 0;
			font-weight: 700;
		}

		.uonix-analytics-header p {
			color: #cbd5e1;
			margin: 0;
			font-size: 14px;
			max-width: 680px;
		}

		.uonix-primary-tabs,
		.uonix-secondary-tabs {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
		}

		.uonix-primary-tabs {
			margin: 0 0 20px;
			padding-bottom: 10px;
			border-bottom: 2px solid #cbd5e1;
		}

		.uonix-secondary-tabs {
			margin: 0 0 18px;
		}

		.uonix-primary-tabs [role="tab"],
		.uonix-secondary-tabs [role="tab"] {
			display: inline-flex;
			align-items: center;
			border: 1px solid #cbd5e1;
			border-radius: 6px;
			background: #ffffff;
			color: #475569;
			cursor: pointer;
			font-weight: 700;
			line-height: 1.3;
			padding: 10px 14px;
			text-decoration: none;
		}

		.uonix-primary-tabs [role="tab"][aria-selected="true"] {
			border-color: #1d4ed8;
			background: #1d4ed8;
			color: #ffffff;
		}

		.uonix-secondary-tabs [role="tab"][aria-selected="true"] {
			border-color: #2563eb;
			background: #eff6ff;
			color: #1d4ed8;
		}

		.uonix-primary-tabs [role="tab"]:focus-visible,
		.uonix-secondary-tabs [role="tab"]:focus-visible,
		.uonix-tabs-nav [role="tab"]:focus-visible {
			outline: 3px solid #93c5fd;
			outline-offset: 2px;
		}

		[role="tabpanel"][hidden] {
			display: none !important;
		}

		.uonix-btn {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 9px 16px;
			font-size: 13px;
			font-weight: 600;
			border-radius: 6px;
			text-decoration: none;
			transition: all 0.15s ease;
		}

		.uonix-btn .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
		}

		.uonix-btn-outline {
			display: block;
			text-align: center;
			background: #f8fafc;
			color: #2563eb;
			border: 1px solid #cbd5e1;
			margin-top: 16px;
		}

		.uonix-btn-outline:hover {
			background: #2563eb;
			color: #ffffff;
			border-color: #2563eb;
		}

		.uonix-status-strip {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 8px;
			padding: 12px 20px;
			display: flex;
			flex-wrap: wrap;
			gap: 24px;
			font-size: 13px;
			margin-bottom: 20px;
		}

		.uonix-status-item {
			display: flex;
			align-items: center;
			gap: 8px;
		}

		.uonix-status-dot {
			width: 8px;
			height: 8px;
			border-radius: 50%;
			display: inline-block;
		}

		.uonix-dot-configured {
			background: #2563eb;
			box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
		}

		.uonix-dot-inactive {
			background: #94a3b8;
			box-shadow: 0 0 0 2px rgba(148, 163, 184, 0.2);
		}

		.uonix-status-label {
			color: #64748b;
		}

		.uonix-marketing-section {
			margin-bottom: 24px;
		}

		.uonix-marketing-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
			gap: 16px;
		}

		.uonix-marketing-card {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 20px;
			display: flex;
			flex-direction: column;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
		}

		.uonix-marketing-card-header {
			display: flex;
			align-items: center;
			gap: 10px;
			margin-bottom: 14px;
		}

		.uonix-marketing-card-header h3 {
			margin: 0;
			font-size: 15px;
			color: #0f172a;
		}

		.uonix-marketing-card dl {
			margin: 0;
		}

		.uonix-marketing-card dt {
			margin-top: 12px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.35px;
			text-transform: uppercase;
			color: #64748b;
		}

		.uonix-marketing-card dt:first-child {
			margin-top: 0;
		}

		.uonix-marketing-card dd {
			margin: 3px 0 0;
			font-size: 13px;
			line-height: 1.45;
			color: #334155;
		}

		.uonix-card-links {
			list-style: none;
			margin: 16px 0;
			padding: 14px 0 0;
			border-top: 1px solid #e2e8f0;
			display: grid;
			gap: 8px;
		}

		.uonix-card-links li {
			margin: 0;
		}

		.uonix-card-links a {
			color: #2563eb;
			font-size: 13px;
			font-weight: 600;
			text-decoration: none;
		}

		.uonix-card-links a:hover,
		.uonix-card-links a:focus {
			text-decoration: underline;
		}

		.uonix-marketing-card .uonix-btn-outline {
			margin-top: auto;
		}

		.uonix-kpi-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
			gap: 16px;
			margin-bottom: 24px;
		}

		.uonix-kpi-card {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 18px 20px;
			display: flex;
			align-items: center;
			gap: 16px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
		}

		.uonix-kpi-icon {
			width: 48px;
			height: 48px;
			border-radius: 10px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.uonix-kpi-icon .dashicons {
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-icon-blue {
			background: #eff6ff;
			color: #2563eb;
		}

		.uonix-icon-green {
			background: #f0fdf4;
			color: #16a34a;
		}

		.uonix-icon-purple {
			background: #faf5ff;
			color: #9333ea;
		}

		.uonix-icon-orange {
			background: #fff7ed;
			color: #ea580c;
		}

		.uonix-kpi-value {
			display: block;
			font-size: 24px;
			font-weight: 700;
			color: #0f172a;
			line-height: 1.1;
		}

		.uonix-kpi-title {
			display: block;
			font-size: 13px;
			font-weight: 600;
			color: #334155;
			margin-top: 2px;
		}

		.uonix-kpi-sub {
			display: block;
			font-size: 11px;
			color: #64748b;
			margin-top: 1px;
		}

		.uonix-kpi-comparison {
			display: block;
			font-size: 11px;
			font-weight: 700;
			margin-top: 5px;
		}

		.uonix-trend-up {
			color: #047857;
		}

		.uonix-trend-down {
			color: #b45309;
		}

		.uonix-trend-new,
		.uonix-trend-flat,
		.uonix-trend-empty {
			color: #64748b;
		}

		.uonix-tabs-nav {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
			border-bottom: 2px solid #e2e8f0;
			margin-bottom: 20px;
		}

		.uonix-tab-btn {
			background: none;
			border: none;
			padding: 10px 18px;
			font-size: 14px;
			font-weight: 600;
			color: #64748b;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 6px;
			border-bottom: 2px solid transparent;
			margin-bottom: -2px;
			text-decoration: none;
			transition: all 0.15s ease;
		}

		.uonix-tab-btn .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
		}

		.uonix-tab-btn:hover {
			color: #0f172a;
		}

		.uonix-tab-btn.active {
			color: #2563eb;
			border-bottom-color: #2563eb;
		}

		.uonix-tab-panel {
			display: none;
		}

		.uonix-tab-panel.active {
			display: block;
		}

		.uonix-panel-header {
			margin-bottom: 16px;
		}

		.uonix-panel-header h2 {
			font-size: 17px;
			margin: 0 0 4px 0;
			color: #0f172a;
			font-weight: 700;
		}

		.uonix-panel-header p {
			font-size: 13px;
			margin: 0;
			color: #64748b;
		}

		.uonix-metrics-panel-header {
			display: flex;
			align-items: flex-end;
			justify-content: space-between;
			gap: 24px;
		}

		.uonix-metrics-copy {
			min-width: 0;
			flex: 1;
		}

		.uonix-metrics-cache-meta {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px 12px;
			margin-top: 10px;
			font-size: 12px;
			color: #64748b;
		}

		.uonix-metrics-cache-status {
			display: inline-flex;
			align-items: center;
			padding: 3px 9px;
			border-radius: 999px;
			font-weight: 700;
		}

		.uonix-cache-fresh {
			background: #dcfce7;
			color: #166534;
		}

		.uonix-cache-stale {
			background: #fef3c7;
			color: #92400e;
		}

		.uonix-cache-empty {
			background: #e2e8f0;
			color: #475569;
		}

		.uonix-metrics-toolbar,
		.uonix-metrics-period-form,
		.uonix-metrics-refresh-form {
			display: flex;
			align-items: center;
		}

		.uonix-metrics-toolbar {
			flex-wrap: wrap;
			justify-content: flex-end;
			gap: 10px;
		}

		.uonix-metrics-period-form {
			gap: 7px;
		}

		.uonix-metrics-period-form label {
			font-size: 12px;
			font-weight: 700;
			color: #475569;
		}

		.uonix-metrics-period-form select {
			min-width: 150px;
		}

		.uonix-metrics-refresh-form {
			gap: 8px;
		}

		.uonix-metrics-refresh-form span {
			font-size: 11px;
			color: #64748b;
		}

		.uonix-table-responsive {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 8px;
			overflow-x: auto;
			overflow-y: hidden;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
		}

		.uonix-table {
			width: 100%;
			min-width: 900px;
			border-collapse: collapse;
			text-align: left;
			font-size: 13px;
		}

		.uonix-table th {
			background: #f8fafc;
			padding: 12px 16px;
			font-weight: 600;
			color: #475569;
			border-bottom: 1px solid #e2e8f0;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		.uonix-table td {
			padding: 12px 16px;
			border-bottom: 1px solid #f1f5f9;
			vertical-align: middle;
		}

		.uonix-table tr:last-child td {
			border-bottom: none;
		}

		.uonix-table tr:hover td {
			background: #f8fafc;
		}

		.uonix-title-col strong {
			display: block;
			font-size: 14px;
			color: #0f172a;
		}

		.uonix-slug-badge {
			display: inline-block;
			font-size: 11px;
			color: #64748b;
			font-family: monospace;
		}

		.uonix-tag {
			background: #f1f5f9;
			color: #475569;
			padding: 3px 8px;
			border-radius: 4px;
			font-size: 11px;
			font-weight: 600;
		}

		.uonix-tag-schema {
			background: #f3e8ff;
			color: #7e22ce;
		}

		.uonix-desc-col {
			font-size: 12px;
			color: #475569;
			max-width: 320px;
		}

		.uonix-page-views-col {
			text-align: right;
			font-variant-numeric: tabular-nums;
			white-space: nowrap;
		}

		.uonix-ranking-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 20px;
			margin-top: 20px;
		}

		.uonix-ranking-chart {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 22px;
		}

		.uonix-ranking-chart h3 {
			margin: 0 0 18px;
			font-size: 16px;
			color: #0f172a;
		}

		.uonix-ranking-list {
			list-style: none;
			margin: 0;
			padding: 0;
			display: grid;
			gap: 14px;
		}

		.uonix-ranking-list li {
			margin: 0;
		}

		.uonix-ranking-label {
			display: flex;
			align-items: baseline;
			justify-content: space-between;
			gap: 16px;
			margin-bottom: 6px;
			font-size: 13px;
		}

		.uonix-ranking-label code,
		.uonix-ranking-label span {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.uonix-ranking-label strong {
			color: #334155;
			font-size: 12px;
			white-space: nowrap;
		}

		.uonix-ranking-bar-track {
			height: 9px;
			background: #e2e8f0;
			border-radius: 999px;
			overflow: hidden;
		}

		.uonix-ranking-bar-fill {
			display: block;
			height: 100%;
			background: linear-gradient(90deg, #2563eb, #38bdf8);
			border-radius: inherit;
		}

		.uonix-ranking-bar-fill-search {
			background: linear-gradient(90deg, #047857, #34d399);
		}

		.uonix-ranking-note,
		.uonix-ranking-empty {
			margin: 16px 0 0;
			font-size: 12px;
			color: #64748b;
		}

		.uonix-sc-icon-ga {
			color: #ea580c;
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-sc-icon-gsc {
			color: #2563eb;
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-sc-icon-looker {
			color: #059669;
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-sc-icon-meta {
			color: #1877f2;
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-sc-icon-gtm,
		.uonix-sc-icon-ads,
		.uonix-sc-icon-adopt {
			font-size: 24px;
			width: 24px;
			height: 24px;
		}

		.uonix-sc-icon-gtm {
			color: #4285f4;
		}

		.uonix-sc-icon-ads {
			color: #d97706;
		}

		.uonix-sc-icon-adopt {
			color: #7c3aed;
		}

		.uonix-btn-outline-meta {
			color: #1877f2;
		}

		.uonix-btn-outline-meta:hover {
			background: #1877f2;
			color: #ffffff;
			border-color: #1877f2;
		}

		.uonix-btn-outline-ads {
			color: #b45309;
		}

		.uonix-btn-outline-ads:hover {
			background: #d97706;
			color: #ffffff;
			border-color: #d97706;
		}

		@media (max-width: 960px) {
			.uonix-primary-tabs [role="tab"],
			.uonix-secondary-tabs [role="tab"] {
				flex: 1 1 220px;
				text-align: left;
			}

			.uonix-tabs-nav .uonix-tab-btn {
				flex: 1 1 140px;
				min-width: 0;
				justify-content: center;
			}

			.uonix-metrics-panel-header {
				align-items: stretch;
				flex-direction: column;
			}

			.uonix-metrics-toolbar {
				justify-content: flex-start;
			}

			.uonix-ranking-grid {
				grid-template-columns: 1fr;
			}

			.uonix-analytics-header {
				padding: 22px;
			}

			.uonix-status-strip {
				gap: 12px 20px;
			}
		}
	</style>

	<!-- Script JS para navegação acessível das abas -->
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			function syncFormState() {
				var state = new URL(window.location.href).searchParams;
				document.querySelectorAll('.uonix-metrics-period-form, .uonix-metrics-refresh-form').forEach(function (form) {
					['tab', 'subtab', 'catalog_tab'].forEach(function (key) {
						var input = form.querySelector('input[name="' + key + '"]');
						if (input && state.has(key)) input.value = state.get(key);
					});
				});
			}

			function activateTab(tab, persist) {
				var tablist = tab.closest('[role="tablist"]');
				if (!tablist) return;
				var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
				tabs.forEach(function (candidate) {
					var selected = candidate === tab;
					candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
					candidate.tabIndex = selected ? 0 : -1;
					candidate.classList.toggle('active', selected);
					var panel = document.getElementById(candidate.getAttribute('aria-controls'));
					if (panel) {
						panel.hidden = !selected;
						panel.classList.toggle('active', selected);
					}
				});

				if (persist && tab.href) {
					history.replaceState({}, '', tab.href);
					syncFormState();
				}
			}

			document.querySelectorAll('[role="tablist"]').forEach(function (tablist) {
				var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
				tabs.forEach(function (tab, index) {
					tab.addEventListener('click', function (event) {
						if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
							return;
						}
						event.preventDefault();
						activateTab(tab, true);
					});
					tab.addEventListener('keydown', function (event) {
						var targetIndex = index;
						switch (event.key) {
							case 'ArrowRight':
								targetIndex = (index + 1) % tabs.length;
								break;
							case 'ArrowLeft':
								targetIndex = (index - 1 + tabs.length) % tabs.length;
								break;
							case 'Home':
								targetIndex = 0;
								break;
							case 'End':
								targetIndex = tabs.length - 1;
								break;
							default:
								return;
						}
						event.preventDefault();
						tabs[targetIndex].focus();
						activateTab(tabs[targetIndex], true);
					});
				});
			});
		});
	</script>
	<?php
}
