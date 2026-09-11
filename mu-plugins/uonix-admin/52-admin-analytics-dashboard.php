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
	$meta_events_url = 'https://business.facebook.com/events_manager2';
	$meta_suite_url = 'https://business.facebook.com/latest/home';
	$metrics_snapshot = function_exists( 'uonix_analytics_metrics_get_snapshot' ) ? uonix_analytics_metrics_get_snapshot() : false;
	$metrics_status = is_array( $metrics_snapshot ) ? ( $metrics_snapshot['status'] ?? 'updated' ) : 'unavailable';
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
			<div class="uonix-header-actions">
				<a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener"
					class="uonix-btn uonix-btn-primary">
					<span class="dashicons dashicons-chart-line"></span> Abrir Google Analytics
				</a>
				<a href="<?php echo esc_url($gsc_domain_url); ?>" target="_blank" rel="noopener"
					class="uonix-btn uonix-btn-secondary">
					<span class="dashicons dashicons-search"></span> Abrir Search Console
				</a>
				<a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener"
					class="uonix-btn uonix-btn-ads">
					<span class="dashicons dashicons-megaphone"></span> Abrir Google Ads
				</a>
				<a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener"
					class="uonix-btn uonix-btn-meta">
					<span class="dashicons dashicons-facebook-alt"></span> Meta Events Manager
				</a>
			</div>
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

		<!-- Destinos de marketing: fatos técnicos locais, sem métricas externas não consultadas. -->
		<section class="uonix-marketing-section" aria-labelledby="uonix-marketing-title">
			<div class="uonix-panel-header">
				<h2 id="uonix-marketing-title">Destinos de marketing configurados</h2>
				<p>Este painel confirma apenas a configuração local. Dados de audiência, campanhas e resultados devem ser conferidos na plataforma indicada.</p>
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
					<a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Google Analytics</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-megaphone uonix-sc-icon-ads"></span><h3>Google Ads via GTM</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($gtm_is_audited ? $google_ads_id . ' — Google Tag, vinculador de conversões e remarketing' : $google_ads_status); ?></dd><dt>Finalidade</dt><dd>Mensuração de mídia e remarketing conforme consentimento de marketing.</dd><dt>Validar em</dt><dd>Validar campanhas, públicos e resultados no Google Ads.</dd></dl>
					<a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline uonix-btn-outline-ads">Abrir Google Ads</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-shield uonix-sc-icon-adopt"></span><h3>AdOpt e Consent Mode</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($adopt_status); ?></dd><dt>Finalidade</dt><dd>Controlar categorias estatísticas e de marketing.</dd><dt>Validar em</dt><dd>Banner e preferências na produção.</dd></dl>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-facebook-alt uonix-sc-icon-meta"></span><h3>Meta Pixel</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($meta_status); ?></dd><dt>Finalidade</dt><dd>PageView sujeito ao consentimento de marketing.</dd><dt>Validar em</dt><dd>Events Manager e diagnóstico da Meta.</dd></dl>
					<a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline uonix-btn-outline-meta">Abrir Events Manager</a>
				</div>
				<div class="uonix-marketing-card">
					<div class="uonix-marketing-card-header"><span class="dashicons dashicons-search uonix-sc-icon-gsc"></span><h3>Google Search Console</h3></div>
					<dl><dt>Configuração local</dt><dd><?php echo esc_html($gsc_status); ?></dd><dt>Finalidade</dt><dd>Pesquisar desempenho orgânico e indexação.</dd><dt>Validar em</dt><dd>Desempenho, páginas e sitemaps no Search Console.</dd></dl>
					<a href="<?php echo esc_url($gsc_domain_url); ?>" target="_blank" rel="noopener" class="uonix-btn uonix-btn-outline">Abrir Search Console</a>
				</div>
			</div>
		</section>

		<section class="uonix-marketing-section" aria-labelledby="uonix-metrics-title">
			<div class="uonix-panel-header">
				<h2 id="uonix-metrics-title">Métricas agregadas dos últimos 30 dias</h2>
				<p><?php echo esc_html( 'updated' === $metrics_status ? 'Fonte: GA4 Data API e Search Console API. Comparação com os 30 dias anteriores.' : ( 'stale' === $metrics_status ? 'Último snapshot disponível; atualização pendente.' : 'Conexão não configurada ou sem snapshot. Nenhuma métrica é exibida.' ) ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uonix-metrics-refresh-form">
						<input type="hidden" name="action" value="uonix_analytics_metrics_refresh" />
						<?php wp_nonce_field( 'uonix_analytics_metrics_refresh' ); ?>
						<button type="submit" class="button button-secondary">Atualizar métricas agora</button>
						<span>Consulta somente leitura.</span>
					</form>
				<?php endif; ?>
			</div>
			<?php if ( is_array( $metrics_snapshot ) && isset( $metrics_snapshot['ga4'], $metrics_snapshot['search_console'] ) ) :
				$ga4_summary = $metrics_snapshot['ga4']['summary'];
				$gsc_summary = $metrics_snapshot['search_console']['summary'];
				$metric_value = static function ( $metric, $percent = false ) { return $percent ? number_format_i18n( (float) $metric['current'] * 100, 1 ) . '%' : number_format_i18n( (float) $metric['current'] ); };
			?>
			<div class="uonix-kpi-grid">
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $ga4_summary['active_users'] ) ); ?></span><span class="uonix-kpi-title">Usuários ativos</span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $ga4_summary['sessions'] ) ); ?></span><span class="uonix-kpi-title">Sessões</span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $gsc_summary['clicks'] ) ); ?></span><span class="uonix-kpi-title">Cliques orgânicos</span></div></div>
				<div class="uonix-kpi-card"><div class="uonix-kpi-data"><span class="uonix-kpi-value"><?php echo esc_html( $metric_value( $gsc_summary['impressions'] ) ); ?></span><span class="uonix-kpi-title">Impressões orgânicas</span></div></div>
			</div>
			<div class="uonix-shortcuts-grid">
				<div class="uonix-shortcut-card"><h3>Principais páginas de entrada</h3><ul class="uonix-shortcut-links"><?php foreach ( array_slice( $metrics_snapshot['ga4']['landing_pages'], 0, 10 ) as $row ) : ?><li><code><?php echo esc_html( $row['path'] ); ?></code> — <?php echo esc_html( number_format_i18n( (float) $row['sessions'] ) ); ?> sessões</li><?php endforeach; ?></ul></div>
				<div class="uonix-shortcut-card"><h3>Principais consultas orgânicas</h3><ul class="uonix-shortcut-links"><?php foreach ( array_slice( $metrics_snapshot['search_console']['queries'], 0, 10 ) as $row ) : ?><li><?php echo esc_html( $row['query'] ); ?> — <?php echo esc_html( number_format_i18n( (float) $row['clicks'] ) ); ?> cliques</li><?php endforeach; ?></ul><p>O Search Console pode omitir linhas de baixo volume.</p></div>
			</div>
			<?php endif; ?>
		</section>

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

		<!-- Abas de Navegação -->
		<div class="uonix-tabs-nav">
			<button class="uonix-tab-btn active" data-tab="tab-products">
				<span class="dashicons dashicons-products"></span> Produtos (<?php echo esc_html($total_products); ?>)
			</button>
			<button class="uonix-tab-btn" data-tab="tab-blog">
				<span class="dashicons dashicons-welcome-write-blog"></span> Artigos de Blog
				(<?php echo esc_html($total_posts); ?>)
			</button>
			<button class="uonix-tab-btn" data-tab="tab-services">
				<span class="dashicons dashicons-hammer"></span> Serviços de Engenharia
				(<?php echo esc_html($total_services); ?>)
			</button>
			<button class="uonix-tab-btn" data-tab="tab-google-hub">
				<span class="dashicons dashicons-dashboard"></span> Ferramentas & Tráfego (Google + Meta)
			</button>
		</div>

		<!-- Conteúdo das Abas -->
		<div class="uonix-tab-content-wrapper">

			<!-- ABA 1: PRODUTOS -->
			<div id="tab-products" class="uonix-tab-panel active">
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
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($products_query as $product_post):
								$pid = $product_post->ID;
								$permalink = get_permalink($pid);
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
			<div id="tab-blog" class="uonix-tab-panel">
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
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($posts_query as $blog_post):
								$bid = $blog_post->ID;
								$permalink = get_permalink($bid);
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
			<div id="tab-services" class="uonix-tab-panel">
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
								<th style="text-align:right;">Ações Rápidas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($services_query as $service_post):
								$sid = $service_post->ID;
								$permalink = get_permalink($sid);
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

			<!-- ABA 4: ATALHOS GOOGLE & META -->
			<div id="tab-google-hub" class="uonix-tab-panel">
				<div class="uonix-panel-header">
					<h2>Hub de Acesso Direto às Ferramentas de Tráfego & Monitoramento</h2>
					<p>Clique nos cards abaixo para abrir os relatórios específicos diretamente nas plataformas Google e
						Meta.</p>
				</div>
				<div class="uonix-shortcuts-grid">

					<div class="uonix-shortcut-card">
						<div class="uonix-shortcut-header">
							<span class="dashicons dashicons-chart-line uonix-sc-icon-ga"></span>
							<h3>Google Analytics 4 (GA4)</h3>
						</div>
						<p>Consulte relatórios de audiência, páginas, aquisição e eventos na propriedade configurada.</p>
						<ul class="uonix-shortcut-links">
							<li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">➔ Visão Geral do
									Tráfego em Tempo Real</a></li>
							<li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">➔ Relatório de
									Páginas e Telas Mais Acessadas</a></li>
							<li><a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener">➔ Origem e
									Canais de Aquisição de Visitantes</a></li>
						</ul>
						<a href="<?php echo esc_url($ga4_url); ?>" target="_blank" rel="noopener"
							class="uonix-btn uonix-btn-outline">Abrir GA4 Dashboard</a>
					</div>

					<div class="uonix-shortcut-card">
						<div class="uonix-shortcut-header">
							<span class="dashicons dashicons-search uonix-sc-icon-gsc"></span>
							<h3>Google Search Console</h3>
						</div>
						<p>Consulte consultas, páginas, impressões, cliques, posição média e indexação na propriedade do domínio.</p>
						<ul class="uonix-shortcut-links">
							<li><a href="<?php echo esc_url('https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain:uonix.com.br'); ?>"
									target="_blank" rel="noopener">➔ Consultas de Pesquisa & Palavras-Chave</a></li>
							<li><a href="<?php echo esc_url('https://search.google.com/search-console/index?resource_id=sc-domain:uonix.com.br'); ?>"
									target="_blank" rel="noopener">➔ Status de Cobertura e Indexação de Páginas</a></li>
							<li><a href="<?php echo esc_url('https://search.google.com/search-console/sitemaps?resource_id=sc-domain:uonix.com.br'); ?>"
									target="_blank" rel="noopener">➔ Sitemaps XML Enviados</a></li>
						</ul>
						<a href="<?php echo esc_url($gsc_domain_url); ?>" target="_blank" rel="noopener"
							class="uonix-btn uonix-btn-outline">Abrir Search Console</a>
					</div>

					<div class="uonix-shortcut-card">
						<div class="uonix-shortcut-header">
							<span class="dashicons dashicons-megaphone uonix-sc-icon-ads"></span>
							<h3>Google Ads</h3>
						</div>
						<p><?php echo esc_html($gtm_is_audited ? 'A conta técnica ' . $google_ads_id . ' usa Google Tag, vinculador e remarketing pelo GTM.' : 'A configuração do Google Ads requer validação do container GTM auditado.'); ?> Resultados devem ser confirmados no Google Ads.</p>
						<ul class="uonix-shortcut-links">
							<li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">➔ Campanhas e grupos de anúncios</a></li>
							<li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">➔ Objetivos e diagnóstico de mensuração</a></li>
							<li><a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener">➔ Públicos de remarketing</a></li>
						</ul>
						<a href="<?php echo esc_url($google_ads_url); ?>" target="_blank" rel="noopener"
							class="uonix-btn uonix-btn-outline uonix-btn-outline-ads">Abrir Google Ads</a>
					</div>

					<div class="uonix-shortcut-card">
						<div class="uonix-shortcut-header">
							<span class="dashicons dashicons-facebook-alt uonix-sc-icon-meta"></span>
							<h3>Meta Pixel (Facebook & Instagram)</h3>
						</div>
						<p>Abra a plataforma para verificar o recebimento, diagnósticos e qualidade do Pixel.</p>
						<ul class="uonix-shortcut-links">
							<li><a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener">➔
									Gerenciador de Eventos (Events Manager)</a></li>
							<li><a href="<?php echo esc_url('https://business.facebook.com/events_manager2/diagnostics'); ?>"
									target="_blank" rel="noopener">➔ Diagnóstico & Qualidade dos Eventos</a></li>
							<li><a href="<?php echo esc_url('https://business.facebook.com/events_manager2/test_events'); ?>"
									target="_blank" rel="noopener">➔ Testar Eventos do Pixel em Tempo Real</a></li>
							<li><a href="<?php echo esc_url($meta_suite_url); ?>" target="_blank" rel="noopener">➔ Meta
									Business Suite Principal</a></li>
						</ul>
						<a href="<?php echo esc_url($meta_events_url); ?>" target="_blank" rel="noopener"
							class="uonix-btn uonix-btn-outline uonix-btn-outline-meta">Abrir Meta Events Manager</a>
					</div>

					<div class="uonix-shortcut-card">
						<div class="uonix-shortcut-header">
							<span class="dashicons dashicons-analytics uonix-sc-icon-looker"></span>
							<h3>Google Looker Studio</h3>
						</div>
						<p>Crie e visualize painéis executivos visuais e relatórios automatizados por e-mail com gráficos
							customizados.</p>
						<ul class="uonix-shortcut-links">
							<li><a href="<?php echo esc_url($looker_url); ?>" target="_blank" rel="noopener">➔ Acessar
									Looker Studio</a></li>
							<li><a href="https://lookerstudio.google.com/gallery" target="_blank" rel="noopener">➔ Galeria
									de modelos de relatórios</a></li>
						</ul>
						<a href="<?php echo esc_url($looker_url); ?>" target="_blank" rel="noopener"
							class="uonix-btn uonix-btn-outline">Abrir Looker Studio</a>
					</div>

				</div>
			</div>

		</div>
	</div>

	<!-- Estilos CSS do Dashboard -->
	<style>
		.uonix-analytics-wrap {
			max-width: 1350px;
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

		.uonix-header-actions {
			display: flex;
			gap: 10px;
			flex-shrink: 0;
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

		.uonix-btn-primary {
			background: #2563eb;
			color: #ffffff;
		}

		.uonix-btn-primary:hover {
			background: #1d4ed8;
			color: #ffffff;
		}

		.uonix-btn-secondary {
			background: rgba(255, 255, 255, 0.15);
			color: #ffffff;
			border: 1px solid rgba(255, 255, 255, 0.2);
		}

		.uonix-btn-secondary:hover {
			background: rgba(255, 255, 255, 0.25);
			color: #ffffff;
		}

		.uonix-btn-meta {
			background: #1877f2;
			color: #ffffff;
			border: 1px solid #1877f2;
		}

		.uonix-btn-meta:hover {
			background: #166fe5;
			color: #ffffff;
		}

		.uonix-btn-ads {
			background: #f59e0b;
			color: #1f2937;
			border: 1px solid #f59e0b;
		}

		.uonix-btn-ads:hover {
			background: #d97706;
			border-color: #d97706;
			color: #ffffff;
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

		.uonix-tabs-nav {
			display: flex;
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

		.uonix-table-responsive {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
		}

		.uonix-table {
			width: 100%;
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

		.uonix-shortcuts-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
			gap: 20px;
		}

		.uonix-shortcut-card {
			background: #ffffff;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 22px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
			display: flex;
			flex-direction: column;
			justify-content: space-between;
		}

		.uonix-shortcut-header {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 10px;
		}

		.uonix-shortcut-header h3 {
			margin: 0;
			font-size: 16px;
			font-weight: 700;
			color: #0f172a;
		}

		.uonix-shortcut-card p {
			font-size: 13px;
			color: #64748b;
			margin: 0 0 14px 0;
		}

		.uonix-shortcut-links {
			list-style: none;
			padding: 0;
			margin: 0 0 16px 0;
			font-size: 13px;
		}

		.uonix-shortcut-links li {
			margin-bottom: 8px;
		}

		.uonix-shortcut-links a {
			color: #2563eb;
			text-decoration: none;
			font-weight: 500;
		}

		.uonix-shortcut-links a:hover {
			text-decoration: underline;
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
	</style>

	<!-- Script JS para Troca de Abas -->
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var tabButtons = document.querySelectorAll('.uonix-tab-btn');
			var tabPanels = document.querySelectorAll('.uonix-tab-panel');

			tabButtons.forEach(function (btn) {
				btn.addEventListener('click', function () {
					var targetTab = this.getAttribute('data-tab');

					tabButtons.forEach(function (b) { b.classList.remove('active'); });
					tabPanels.forEach(function (p) { p.classList.remove('active'); });

					this.classList.add('active');
					var activePanel = document.getElementById(targetTab);
					if (activePanel) {
						activePanel.classList.add('active');
					}
				});
			});
		});
	</script>
	<?php
}
