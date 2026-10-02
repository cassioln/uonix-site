<?php
/**
 * Central de Inteligência — render.
 *
 * Renderiza os painéis das abas `intelligence`, `anomalies` e `settings` do menu
 * Uônix Insights. Consome apenas o que 55-admin-intelligence-metrics.php,
 * 58-admin-intelligence-anomalies.php e 50-admin-intelligence-license.php devolvem;
 * não consulta API, não grava nada e não agenda nada. Os formulários da aba
 * Configurações postam para os handlers de 55, 57 e 50, que fazem a própria guarda.
 *
 * Reaproveita as classes de CSS já declaradas em 52-admin-analytics-dashboard.php
 * e as do core (`wp-list-table`, `form-table`), para não crescer o bloco de estilo
 * inline daquele arquivo.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_query_flag' ) ) {
	/**
	 * Lê da URL um marcador de aviso (`uonix_test_sent`, `uonix_license_saved`...)
	 * como string. Valor que não é string (`?x[]=1`) vira '', em vez do Warning
	 * "Array to string conversion" que o cast direto produzia. Quem chama só usa o
	 * resultado para escolher a chave de um mapa fixo.
	 */
	function uonix_intelligence_query_flag( $key ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
	}
}

if ( ! function_exists( 'uonix_intelligence_unavailable_message' ) ) {
	/**
	 * Traduz o motivo técnico de indisponibilidade para linguagem de operador.
	 *
	 * Vazar o código interno na tela obriga quem lê a consultar o código-fonte para
	 * entender o que fazer. Motivo desconhecido cai numa mensagem honestamente vaga
	 * em vez de inventar uma explicação.
	 */
	function uonix_intelligence_unavailable_message( $reason ) {
		$mapa = array(
			'snapshot_missing'         => 'Nenhum snapshot de 30 dias foi sincronizado ainda. As oportunidades aparecem após a primeira sincronização.',
			'snapshot_legacy'          => 'O snapshot armazenado vem de uma versão anterior e não contém o universo de consultas necessário para a análise.',
			'period_mismatch'          => 'O snapshot disponível é de outro período. Esta análise usa uma janela fixa de 30 dias.',
			'queries_extended_missing' => 'O snapshot ainda não contém o universo ampliado de consultas. Ele será preenchido na próxima sincronização.',
		);
		return isset( $mapa[ $reason ] ) ? $mapa[ $reason ] : 'A análise não está disponível no momento.';
	}
}

if ( ! function_exists( 'uonix_intelligence_test_send_message' ) ) {
	/**
	 * Explica por que o envio de teste não saiu.
	 *
	 * O caso `mail_failed` em QA e DEV é quase sempre o guard de ambiente fazendo o
	 * trabalho dele. Dizer isso na tela poupa o operador de caçar no error log um
	 * bloqueio que é intencional.
	 */
	function uonix_intelligence_test_send_message( $reason ) {
		$mapa = array(
			'no_recipients'    => 'Nenhum destinatário cadastrado, então nada foi enviado. Salve ao menos um endereço acima.',
			'mail_failed'      => 'O envio falhou. Em QA e DEV isso é esperado quando UONIX_NONPROD_EMAIL_TO não está configurado: o guard de ambiente bloqueia envio sem caixa segura.',
			'license_inactive' => 'Nada foi enviado: a licença da Central de Inteligência está inativa, e o envio de teste segue a mesma regra do automático. Veja o aviso acima.',
		);
		return isset( $mapa[ $reason ] ) ? $mapa[ $reason ] : 'O envio não foi concluído.';
	}
}

if ( ! function_exists( 'uonix_intelligence_render_license_notice' ) ) {
	/**
	 * Aviso de contato quando a licença pausa o envio (50-admin-intelligence-license.php).
	 * Com o envio ativo, não imprime nada.
	 */
	function uonix_intelligence_render_license_notice() {
		if ( function_exists( 'uonix_intelligence_license_state' ) && function_exists( 'uonix_intelligence_license_message' ) ) {
			$texto = uonix_intelligence_license_message( uonix_intelligence_license_state() );
		} else {
			// 57 e 58 não enviam sem o 50, e o aviso diz isso.
			$texto = 'O controle de licença da Central de Inteligência não carregou, e o envio automático do relatório semanal e dos alertas de anomalia está pausado. Fale com a ksio.dev.';
		}

		if ( '' === $texto ) {
			return;
		}
		?>
		<div class="notice notice-warning inline uonix-license-notice"><p><?php echo esc_html( $texto ); ?></p></div>
		<?php
	}
}

if ( ! function_exists( 'uonix_intelligence_render_provenance' ) ) {
	/**
	 * Selo de procedência do bloco: fonte, horário de sincronização e frescor.
	 *
	 * Exigência do contrato: cada bloco declara de onde vem o número e é capaz de
	 * se declarar desatualizado por conta própria, sem depender de uma nota global
	 * no rodapé — o relatório mistura fontes com frescores diferentes.
	 */
	function uonix_intelligence_render_provenance( $analysis ) {
		$fonte = isset( $analysis['source'] ) && 'search_console' === $analysis['source'] ? 'Search Console' : 'Fonte não declarada';
		$stale = ! empty( $analysis['stale'] );
		$classe = $stale ? 'uonix-cache-stale' : 'uonix-cache-fresh';
		$rotulo = $stale ? 'Dado desatualizado' : 'Dado atualizado';

		$synced_at = isset( $analysis['synced_at'] ) ? (string) $analysis['synced_at'] : '';
		$timestamp = '' !== $synced_at ? strtotime( $synced_at ) : false;
		?>
		<div class="uonix-metrics-cache-meta">
			<span class="uonix-metrics-cache-status <?php echo esc_attr( $classe ); ?>"><?php echo esc_html( $rotulo ); ?></span>
			<span><?php echo esc_html( 'Fonte: ' . $fonte ); ?></span>
			<?php if ( false !== $timestamp ) : ?>
				<time datetime="<?php echo esc_attr( gmdate( 'c', $timestamp ) ); ?>">
					<?php echo esc_html( 'Sincronizado em ' . ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i', $timestamp ) : gmdate( 'd/m/Y H:i', $timestamp ) ) ); ?>
				</time>
			<?php else : ?>
				<span>Sem horário de sincronização</span>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'uonix_intelligence_number' ) ) {
	/**
	 * Formata número usando o separador do locale, como o painel vizinho já faz.
	 *
	 * Fixar os separadores à mão faria os dois painéis divergirem por construção no
	 * dia em que o locale mudasse.
	 */
	function uonix_intelligence_number( $value, $decimals = 0 ) {
		return function_exists( 'number_format_i18n' )
			? number_format_i18n( (float) $value, $decimals )
			: number_format( (float) $value, $decimals, ',', '.' );
	}
}

if ( ! function_exists( 'uonix_intelligence_format_ctr' ) ) {
	function uonix_intelligence_format_ctr( $ctr ) {
		return uonix_intelligence_number( (float) $ctr * 100, 2 ) . '%';
	}
}

if ( ! function_exists( 'uonix_intelligence_render_panel' ) ) {
	/**
	 * Painel da aba "Oportunidades SEO".
	 */
	function uonix_intelligence_render_panel( $active_tab ) {
		$is_active = 'intelligence' === $active_tab;
		$rules     = function_exists( 'uonix_intelligence_seo_rules' ) ? uonix_intelligence_seo_rules() : array( 'min_position' => 4, 'max_position' => 12, 'min_impressions' => 5, 'max_ctr' => 0.03 );
		// O mesmo limite que o e-mail usa para dizer "mais N no painel" (#291).
		$analysis  = function_exists( 'uonix_intelligence_seo_opportunities' )
			? uonix_intelligence_seo_opportunities( null, isset( $rules['panel_limit'] ) ? (int) $rules['panel_limit'] : 5 )
			: array( 'available' => false, 'reason' => 'snapshot_missing', 'source' => 'search_console', 'synced_at' => '', 'stale' => true, 'universe' => 0, 'rows' => array() );
		$rows  = isset( $analysis['rows'] ) && is_array( $analysis['rows'] ) ? $analysis['rows'] : array();
		?>
		<section id="uonix-panel-intelligence" role="tabpanel" aria-labelledby="uonix-tab-intelligence"<?php echo $is_active ? '' : ' hidden'; ?>>
			<div class="uonix-panel-header">
				<div class="uonix-metrics-copy">
					<h2 id="uonix-intelligence-title">Oportunidades de busca a um passo do topo</h2>
					<p><?php echo esc_html(
						sprintf(
							'As consultas de maior volume entre as que aparecem da %d.ª à %d.ª posição com taxa de clique abaixo de %s, nos últimos 30 dias. Consultas com %d impressões ou menos ficam de fora: nesse volume a taxa de clique é ruído, não sinal.',
							(int) $rules['min_position'],
							(int) $rules['max_position'],
							uonix_intelligence_format_ctr( $rules['max_ctr'] ),
							(int) $rules['min_impressions']
						)
					); ?></p>
					<?php uonix_intelligence_render_provenance( $analysis ); ?>
				</div>
			</div>

			<?php if ( empty( $analysis['available'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( uonix_intelligence_unavailable_message( isset( $analysis['reason'] ) ? (string) $analysis['reason'] : '' ) ); ?></p>
				</div>
			<?php else : ?>
				<div class="uonix-kpi-grid">
					<div class="uonix-kpi-card">
						<div class="uonix-kpi-title">Oportunidades encontradas</div>
						<div class="uonix-kpi-value"><?php echo esc_html( (string) count( $rows ) ); ?></div>
						<div class="uonix-kpi-sub"><?php echo esc_html( sprintf( 'de %d consultas analisadas', isset( $analysis['universe'] ) ? (int) $analysis['universe'] : 0 ) ); ?></div>
					</div>
				</div>

				<?php if ( array() === $rows ) : ?>
					<div class="notice notice-info inline">
						<p>Nenhuma consulta atendeu aos critérios nesta janela. Isso é um resultado, não uma falha: significa que não há ganho fácil disponível agora.</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<caption class="screen-reader-text">Consultas próximas do topo, ordenadas por volume de impressões</caption>
						<thead>
							<tr>
								<th scope="col">Consulta</th>
								<th scope="col">Posição</th>
								<th scope="col">Impressões</th>
								<th scope="col">Taxa de clique</th>
								<th scope="col">Página Alvo</th>
								<th scope="col">Sugestão (IA)</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $row['query'] ); ?></strong></td>
									<td><?php echo esc_html( uonix_intelligence_number( $row['position'], 1 ) ); ?></td>
									<td><?php echo esc_html( uonix_intelligence_number( $row['impressions'], 0 ) ); ?></td>
									<td><?php echo esc_html( uonix_intelligence_format_ctr( $row['ctr'] ) ); ?></td>
									<?php
									$alvo     = array_key_exists( 'target_page', $row ) ? $row['target_page'] : null;
									// Post ou termo (#344): o link leva ao editor de cada um.
									if ( is_string( $alvo ) && '' !== $alvo && function_exists( 'uonix_intelligence_ai_page_object' ) ) {
										$objeto = uonix_intelligence_ai_page_object( $alvo );
									} else {
										$post_id = is_string( $alvo ) && '' !== $alvo && function_exists( 'uonix_intelligence_ai_page_post_id' ) ? uonix_intelligence_ai_page_post_id( $alvo ) : 0;
										$objeto  = $post_id > 0 ? array( 'type' => 'post', 'id' => $post_id ) : null;
									}
									$ia       = function_exists( 'uonix_intelligence_ai_suggestion_for' ) ? uonix_intelligence_ai_suggestion_for( $row ) : array( 'status' => 'not_configured' );
									$ia_texto = function_exists( 'uonix_intelligence_ai_state_message' ) ? uonix_intelligence_ai_state_message( isset( $ia['status'] ) ? (string) $ia['status'] : '' ) : 'IA não configurada.';
									?>
									<td>
										<?php if ( null === $alvo ) : ?>
											<em>Aguardando a próxima sincronização.</em>
										<?php elseif ( '' === $alvo ) : ?>
											<em>Não identificada.</em>
										<?php else : ?>
											<a href="<?php echo esc_url( home_url( $alvo ) ); ?>"><?php echo esc_html( $alvo ); ?></a>
											<?php if ( is_array( $objeto ) && 'post' === $objeto['type'] && current_user_can( 'edit_post', $objeto['id'] ) ) : ?>
												<br><a href="<?php echo esc_url( (string) get_edit_post_link( $objeto['id'] ) ); ?>">Editar página</a>
											<?php elseif ( is_array( $objeto ) && 'term' === $objeto['type'] && function_exists( 'get_edit_term_link' ) && current_user_can( 'edit_term', $objeto['id'] ) ) : ?>
												<br><a href="<?php echo esc_url( (string) get_edit_term_link( $objeto['id'], $objeto['taxonomy'] ) ); ?>"><?php echo esc_html( 'Editar ' . ( function_exists( 'uonix_intelligence_ai_term_kind' ) ? uonix_intelligence_ai_term_kind( $objeto['taxonomy'] ) : 'termo' ) ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( isset( $ia['status'] ) && 'ok' === $ia['status'] ) : ?>
											<p><strong>Título</strong><br>Atual: <?php echo esc_html( (string) $ia['current_title'] ); ?><br>Sugerido: <?php echo esc_html( (string) $ia['title'] ); ?></p>
											<p><strong>Descrição</strong><br>Atual: <?php echo esc_html( '' !== (string) $ia['current_description'] ? (string) $ia['current_description'] : '(vazia)' ); ?><br>Sugerida: <?php echo esc_html( (string) $ia['description'] ); ?></p>
											<p class="description"><?php echo esc_html( 'Gerada por IA' . ( '' !== (string) $ia['generated_at'] && false !== strtotime( (string) $ia['generated_at'] ) ? ' em ' . wp_date( 'd/m', strtotime( (string) $ia['generated_at'] ) ) : '' ) . ' — revise antes de publicar.' ); ?></p>
										<?php else : ?>
											<em><?php echo esc_html( $ia_texto ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_reason_message' ) ) {
	/**
	 * Traduz o motivo de indisponibilidade de um gatilho para linguagem de operador.
	 *
	 * Separado de `uonix_intelligence_unavailable_message()` porque os conjuntos de
	 * motivo são disjuntos: aquele fala de snapshot, este de série diária e de
	 * tabela de submissões. Um mapa único aceitaria motivo do domínio errado sem
	 * reclamar, e cairia no texto genérico exatamente quando houvesse explicação.
	 */
	function uonix_intelligence_anomaly_reason_message( $reason ) {
		$mapa = array(
			'submissions_table_missing' => 'A tabela de submissões do Fluent Forms não existe neste ambiente, então não há como contar orçamentos.',
			'no_leads_in_window'        => 'Nenhum orçamento no período medido, então não há comportamento normal a medir — e portanto não há como conferir o limiar.',
			'config_missing'            => 'As credenciais de leitura do Search Console não estão configuradas neste ambiente.',
			'series_fetch_failed'       => 'A consulta à Search Console falhou. O gatilho tenta de novo na próxima verificação.',
			'series_invalid'            => 'A Search Console respondeu num formato inesperado.',
			'series_too_short'          => 'A série devolvida não cobre as duas semanas necessárias para comparar.',
			'series_dates_invalid'      => 'A Search Console devolveu datas inconsistentes.',
			// Não existem mais `series_incomplete` nem `baseline_incomplete`: janela com dias
			// faltando deixou de ser recusada e passou a ser resolvida por imputação
			// otimista, em `uonix_intelligence_anomaly_organic_drop()`. Recusar silenciava
			// colapso severo — a mesma queda de 94% alertava com 1 impressão/dia e ficava
			// calada com zero.
			'baseline_too_small'        => 'A semana anterior teve impressões insuficientes: nesse volume a variação percentual é ruído, não sinal.',
			'comparison_failed'         => 'A comparação entre as duas semanas não produziu número finito.',
		);

		return isset( $mapa[ $reason ] ) ? $mapa[ $reason ] : 'Este gatilho não pôde ser verificado nesta rodada.';
	}
}

if ( ! function_exists( 'uonix_intelligence_render_anomalies_panel' ) ) {
	/**
	 * Painel da aba "Anomalias" (Módulo 5).
	 *
	 * Renderiza a partir do resultado PERSISTIDO pela verificação diária, nunca
	 * recomputando: `uonix_intelligence_anomaly_detect()` consulta o banco e chama a
	 * API da Search Console, e este painel — como todos os outros — é renderizado em
	 * toda carga da tela e apenas escondido. Recomputar aqui custaria uma chamada de
	 * API por pageview.
	 *
	 * A exceção é a linha de base de leads, que é só um `GROUP BY` local sobre a
	 * tabela de submissões, sem rede. Ela fica fora do cache de propósito: é a
	 * medição que torna o limiar auditável, e um limiar auditado contra número
	 * guardado de ontem não está auditado.
	 */
	function uonix_intelligence_render_anomalies_panel( $active_tab ) {
		$is_active = 'anomalies' === $active_tab;
		$resumo    = function_exists( 'uonix_intelligence_anomaly_get_summary' )
			? uonix_intelligence_anomaly_get_summary()
			: array( 'findings' => array(), 'anomalous' => 0, 'unavailable' => 0, 'checked_at' => '' );
		$badge     = function_exists( 'uonix_intelligence_anomaly_badge' )
			? uonix_intelligence_anomaly_badge( $resumo )
			: array( 'state' => 'normal', 'label' => 'Sistema normal' );
		$baseline  = function_exists( 'uonix_intelligence_anomaly_lead_baseline' )
			? uonix_intelligence_anomaly_lead_baseline()
			: array( 'available' => false, 'reason' => '', 'days' => 0, 'leads' => 0, 'per_day' => 0.0, 'longest_gap' => null, 'threshold_days' => 0, 'threshold_is_safe' => false );
		$verificado = isset( $resumo['checked_at'] ) ? (string) $resumo['checked_at'] : '';
		$momento    = '' !== $verificado ? strtotime( $verificado ) : false;
		$classe     = 'critical' === $badge['state'] ? 'uonix-cache-stale' : ( 'normal' === $badge['state'] ? 'uonix-cache-fresh' : 'uonix-cache-empty' );
		?>
		<section id="uonix-panel-anomalies" role="tabpanel" aria-labelledby="uonix-tab-anomalies"<?php echo $is_active ? '' : ' hidden'; ?>>
			<div class="uonix-panel-header">
				<div class="uonix-metrics-copy">
					<h2 id="uonix-anomalies-title">Vigilância de anomalias</h2>
					<p>Dois gatilhos verificados uma vez por dia: silêncio de orçamentos e queda de tráfego orgânico na comparação entre semanas. O aviso por e-mail sai uma vez por episódio, no momento em que a anomalia começa.</p>
					<div class="uonix-metrics-cache-meta">
						<span class="uonix-metrics-cache-status <?php echo esc_attr( $classe ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
						<?php if ( false !== $momento ) : ?>
							<time datetime="<?php echo esc_attr( gmdate( 'c', $momento ) ); ?>">
								<?php echo esc_html( 'Verificado em ' . ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i', $momento ) : gmdate( 'd/m/Y H:i', $momento ) ) ); ?>
							</time>
						<?php else : ?>
							<span>Nenhuma verificação registrada ainda</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php uonix_intelligence_render_license_notice(); ?>

			<?php if ( false === $momento ) : ?>
				<div class="notice notice-info inline">
					<?php if ( ! function_exists( 'uonix_intelligence_cron_by_visit' ) || uonix_intelligence_cron_by_visit() ) : ?>
						<p>A primeira verificação ainda não rodou. Ela é disparada pelo agendador do WordPress, que depende de tráfego no site — então acontece na primeira visita depois do horário agendado, e não em horário fixo.</p>
					<?php else : ?>
						<p>A primeira verificação ainda não rodou. Neste ambiente o WP-Cron por visita está desligado (<code>DISABLE_WP_CRON</code>): ela depende do agendador do servidor, e não de visitas ao site.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			foreach ( ( isset( $resumo['findings'] ) && is_array( $resumo['findings'] ) ? $resumo['findings'] : array() ) as $achado ) :
				if ( ! is_array( $achado ) ) {
					continue;
				}
				$rotulos = array(
					'lead_silence' => 'Colapso de conversões',
					'organic_drop' => 'Queda de tráfego orgânico',
				);
				$gatilho = isset( $achado['trigger'] ) ? (string) $achado['trigger'] : '';
				$titulo  = isset( $rotulos[ $gatilho ] ) ? $rotulos[ $gatilho ] : $gatilho;
				?>
				<h3><?php echo esc_html( $titulo ); ?></h3>
				<?php if ( ! empty( $achado['stale_expired'] ) ) : ?>
					<?php // Anomalia antiga demais para ser afirmada no presente. Manter o badge
					// crítico com dado velho treinaria o operador a ignorar a tela. ?>
					<div class="notice notice-warning inline">
						<p><strong><?php echo esc_html( sprintf(
							'Última observação anômala em %s, não reverificada há %d dias.',
							isset( $achado['last_observed'] ) ? (string) $achado['last_observed'] : '(sem data)',
							isset( $achado['stale_days'] ) ? (int) $achado['stale_days'] : 0
						) ); ?></strong></p>
						<p><?php echo esc_html( uonix_intelligence_anomaly_reason_message( isset( $achado['reason'] ) ? (string) $achado['reason'] : '' ) ); ?></p>
					</div>
				<?php elseif ( ! empty( $achado['stale_anomaly'] ) ) : ?>
					<?php // Anomalia detectada antes e não reverificada hoje. Nem "acontecendo
					// agora", nem "não sei nada": a fonte falhou, mas o que já foi medido continua
					// valendo, e apagar da tela esconderia um incidente ativo. ?>
					<div class="notice notice-error inline">
						<p><strong><?php echo esc_html(
							'' !== ( isset( $achado['started_at'] ) ? (string) $achado['started_at'] : '' )
								? sprintf( 'Anomalia detectada em %s e ainda não resolvida.', (string) $achado['started_at'] )
								: 'Anomalia detectada anteriormente e ainda não resolvida.'
						); ?></strong></p>
						<p><?php echo esc_html( 'Não foi possível reverificar nesta rodada: ' . uonix_intelligence_anomaly_reason_message( isset( $achado['reason'] ) ? (string) $achado['reason'] : '' ) ); ?></p>
					</div>
				<?php elseif ( empty( $achado['available'] ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html( uonix_intelligence_anomaly_reason_message( isset( $achado['reason'] ) ? (string) $achado['reason'] : '' ) ); ?></p>
					</div>
				<?php elseif ( empty( $achado['anomalous'] ) ) : ?>
					<div class="notice notice-success inline">
						<p><?php echo esc_html( isset( $achado['headline'] ) ? (string) $achado['headline'] : '' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error inline">
						<p><strong><?php echo esc_html( isset( $achado['headline'] ) ? (string) $achado['headline'] : '' ); ?></strong></p>
					</div>
					<table class="form-table" role="presentation">
						<tbody>
							<?php foreach ( array(
								'Quando começou'   => isset( $achado['started_at'] ) ? (string) $achado['started_at'] : '',
								'Causa provável'   => isset( $achado['likely_cause'] ) ? (string) $achado['likely_cause'] : '',
								'Ação recomendada' => isset( $achado['action'] ) ? (string) $achado['action'] : '',
							) as $rotulo => $valor ) : ?>
								<?php if ( '' === $valor ) { continue; } ?>
								<tr>
									<th scope="row"><?php echo esc_html( $rotulo ); ?></th>
									<td><?php echo esc_html( $valor ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php
			// Aviso que não saiu: o episódio esgotou o teto de retentativas de e-mail. O
			// operador precisa saber que existe anomalia detectada cujo aviso não chegou,
			// senão a ausência de e-mail seria lida como ausência de problema.
			$meta_alerta = function_exists( 'uonix_intelligence_anomaly_get_alert_meta' ) ? uonix_intelligence_anomaly_get_alert_meta() : array();
			$sem_entrega = array();
			foreach ( $meta_alerta as $gatilho_meta => $dados_meta ) {
				if ( ! empty( $dados_meta['undelivered'] ) ) {
					$sem_entrega[] = (string) $gatilho_meta;
				}
			}
			?>
			<?php if ( array() !== $sem_entrega ) : ?>
				<div class="notice notice-error inline">
					<p><strong><?php echo esc_html( sprintf(
						1 === count( $sem_entrega ) ? 'Uma anomalia foi detectada e o aviso por e-mail NÃO foi entregue.' : '%d anomalias foram detectadas e os avisos por e-mail NÃO foram entregues.',
						count( $sem_entrega )
					) ); ?></strong></p>
					<p>O envio foi tentado até o limite e falhou. Confira a lista de destinatários na aba Configurações: um único endereço inválido faz o servidor recusar a mensagem inteira.</p>
				</div>
			<?php endif; ?>

			<h3>Limiar de silêncio de orçamentos, conferido contra o histórico</h3>
			<?php if ( empty( $baseline['available'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( uonix_intelligence_anomaly_reason_message( isset( $baseline['reason'] ) ? (string) $baseline['reason'] : '' ) ); ?></p>
				</div>
			<?php else : ?>
				<p>O limiar precisa ser maior que o maior silêncio já observado em operação normal. Um limiar menor ou igual descreve o funcionamento do site, dispara toda semana, e o alerta passa a ser ignorado.</p>
				<p>A medição considera apenas intervalos <strong>encerrados</strong> — do primeiro dia do período até o último orçamento recebido. O silêncio em curso fica de fora de propósito: incluí-lo faria a anomalia atual entrar na definição de normalidade, e o aviso abaixo apareceria sempre que o alerta estivesse certo.</p>
				<div class="uonix-kpi-grid">
					<div class="uonix-kpi-card">
						<div class="uonix-kpi-title">Limiar em uso</div>
						<div class="uonix-kpi-value"><?php echo esc_html( uonix_intelligence_number( $baseline['threshold_days'], 0 ) ); ?></div>
						<div class="uonix-kpi-sub">dias corridos sem orçamento</div>
					</div>
					<div class="uonix-kpi-card">
						<div class="uonix-kpi-title">Maior silêncio encerrado</div>
						<div class="uonix-kpi-value"><?php echo esc_html( null === $baseline['longest_gap'] ? '—' : uonix_intelligence_number( $baseline['longest_gap'], 0 ) ); ?></div>
						<div class="uonix-kpi-sub"><?php echo esc_html(
							! empty( $baseline['gap_from_edge'] )
								? sprintf( 'dias ou MAIS: o intervalo começou antes dos %d dias medidos', (int) $baseline['days'] )
								: sprintf( 'dias, nos últimos %d', (int) $baseline['days'] )
						); ?></div>
					</div>
					<div class="uonix-kpi-card">
						<div class="uonix-kpi-title">Silêncio em curso</div>
						<div class="uonix-kpi-value"><?php echo esc_html( null === $baseline['current_silence'] ? '—' : uonix_intelligence_number( $baseline['current_silence'], 0 ) ); ?></div>
						<div class="uonix-kpi-sub">dias desde o último orçamento</div>
					</div>
					<div class="uonix-kpi-card">
						<div class="uonix-kpi-title">Orçamentos por dia</div>
						<div class="uonix-kpi-value"><?php echo esc_html( uonix_intelligence_number( $baseline['per_day'], 2 ) ); ?></div>
						<div class="uonix-kpi-sub"><?php echo esc_html( sprintf( '%s no período', uonix_intelligence_number( $baseline['leads'], 0 ) ) ); ?></div>
					</div>
				</div>
				<?php if ( empty( $baseline['threshold_is_safe'] ) ) : ?>
					<div class="notice notice-warning inline">
						<p>O limiar em uso não é maior que o maior silêncio observado no período. Enquanto isso valer, o gatilho vai disparar descrevendo o comportamento habitual do site em vez de uma anomalia.</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}
}

if ( ! function_exists( 'uonix_intelligence_render_settings_panel' ) ) {
	/**
	 * Painel da aba "Configurações": destinatários, agendamento e licença.
	 *
	 * Só o dono do ksio.dev vê os formulários (49). Os demais, administradores ou
	 * editores, leem a mesma aba sem poder alterá-la.
	 */
	function uonix_intelligence_render_settings_panel( $active_tab ) {
		$is_active   = 'settings' === $active_tab;
		$recipients  = function_exists( 'uonix_intelligence_get_recipients' ) ? uonix_intelligence_get_recipients() : array();
		$pode_editar = function_exists( 'uonix_ksio_can_configure_insights' ) && uonix_ksio_can_configure_insights();
		$salvos      = isset( $_GET['uonix_recipients_saved'] ) ? (int) $_GET['uonix_recipients_saved'] : -1;
		$recusados   = isset( $_GET['uonix_recipients_rejected'] ) ? (int) $_GET['uonix_recipients_rejected'] : 0;
		$hook        = function_exists( 'uonix_intelligence_report_hook' ) ? uonix_intelligence_report_hook() : '';
		$proximo     = ( '' !== $hook && function_exists( 'wp_next_scheduled' ) ) ? wp_next_scheduled( $hook ) : false;
		?>
		<section id="uonix-panel-settings" role="tabpanel" aria-labelledby="uonix-tab-settings"<?php echo $is_active ? '' : ' hidden'; ?>>
			<div class="uonix-panel-header">
				<div class="uonix-metrics-copy">
					<h2 id="uonix-settings-title">Destinatários e agendamento do relatório</h2>
					<p>Quem recebe o relatório executivo por e-mail, e quando ele é enviado.</p>
				</div>
			</div>

			<?php uonix_intelligence_render_license_notice(); ?>

			<?php if ( ! $pode_editar ) : ?>
				<p class="description uonix-settings-readonly">Somente leitura: estas configurações são alteradas pela ksio.dev.</p>
			<?php endif; ?>

			<?php if ( $salvos >= 0 ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( '%d destinatário(s) salvo(s).', $salvos ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $recusados > 0 ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( '%d entrada(s) recusada(s) por não serem e-mail válido ou por exceder o limite.', $recusados ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['uonix_test_sent'] ) ) : ?>
				<?php if ( '1' === uonix_intelligence_query_flag( 'uonix_test_sent' ) ) : ?>
					<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( 'Relatório de teste enviado para %d destinatário(s).', isset( $_GET['uonix_test_recipients'] ) ? (int) $_GET['uonix_test_recipients'] : 0 ) ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( uonix_intelligence_test_send_message( uonix_intelligence_query_flag( 'uonix_test_reason' ) ) ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h3>Destinatários atuais</h3>
			<?php if ( array() === $recipients ) : ?>
				<p><em>Nenhum destinatário cadastrado. Sem destinatário, nenhum relatório é enviado.</em></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $recipients as $email ) : ?>
						<li><code><?php echo esc_html( $email ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $pode_editar ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="uonix_intelligence_save_recipients" />
					<?php wp_nonce_field( 'uonix_intelligence_save_recipients' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="uonix-recipients">Lista de destinatários</label></th>
							<td>
								<textarea id="uonix-recipients" name="uonix_recipients" rows="6" class="large-text code" aria-describedby="uonix-recipients-help"><?php echo esc_textarea( implode( "\n", $recipients ) ); ?></textarea>
								<p class="description" id="uonix-recipients-help">
									<?php echo esc_html( sprintf( 'Um endereço por linha, no máximo %d. Endereço inválido é descartado e contabilizado no aviso.', function_exists( 'uonix_intelligence_recipients_limit' ) ? uonix_intelligence_recipients_limit() : 10 ) ); ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit"><button type="submit" class="button button-primary">Salvar destinatários</button></p>
				</form>
			<?php endif; ?>

			<?php if ( $pode_editar ) : ?>
				<h3>Envio de teste</h3>
				<p class="description">Gera o relatório com os dados atuais e envia para a lista acima, imediatamente.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="uonix_intelligence_send_test" />
					<?php wp_nonce_field( 'uonix_intelligence_send_test' ); ?>
					<p class="submit"><button type="submit" class="button">Enviar Teste Agora</button></p>
				</form>
			<?php endif; ?>

			<h3>Agendamento</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Frequência</th>
					<td>Semanal.</td>
				</tr>
				<tr>
					<th scope="row">Próximo disparo</th>
					<td>
						<?php if ( is_int( $proximo ) && $proximo > 0 ) : ?>
							<time datetime="<?php echo esc_attr( gmdate( 'c', $proximo ) ); ?>"><?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i', $proximo ) : gmdate( 'd/m/Y H:i', $proximo ) ); ?></time>
						<?php else : ?>
							<em>Não agendado.</em>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="description">
				<?php if ( ! function_exists( 'uonix_intelligence_cron_by_visit' ) || uonix_intelligence_cron_by_visit() ) : ?>
					O envio é disparado pelo agendador do WordPress, que depende de tráfego no site e não de relógio.
				<?php else : ?>
					Neste ambiente o WP-Cron por visita está desligado (<code>DISABLE_WP_CRON</code>): o envio depende do agendador do servidor, e não de tráfego no site. Sem esse agendador, nenhum envio acontece.
				<?php endif; ?>
				Por isso esta tela informa a frequência e o próximo disparo realmente agendado, e não promete um horário fixo.
			</p>

			<?php
			if ( $pode_editar ) {
				uonix_intelligence_render_license_settings();
			}
			?>
		</section>
		<?php
	}
}

if ( ! function_exists( 'uonix_intelligence_render_license_settings' ) ) {
	/**
	 * Bloco da licença na aba Configurações. Quem chama decide se o usuário é o dono.
	 *
	 * O painel é uma segunda camada, que só restringe: a constante do wp-config.php
	 * continua valendo, e o que ela pausa o painel não religa (50). Sem o 50 não há
	 * regra para mostrar nem handler para receber o formulário, e o bloco some.
	 */
	function uonix_intelligence_render_license_settings() {
		if ( ! function_exists( 'uonix_intelligence_license_state' ) || ! function_exists( 'uonix_intelligence_license_option' ) ) {
			return;
		}

		$estado  = uonix_intelligence_license_state();
		$gravado = get_option( uonix_intelligence_license_option(), null );
		$status  = is_array( $gravado ) && isset( $gravado['status'] ) && is_string( $gravado['status'] ) ? $gravado['status'] : '';
		$data    = is_array( $gravado ) && isset( $gravado['valid_until'] ) && is_string( $gravado['valid_until'] ) ? $gravado['valid_until'] : '';

		// Opção presente que o 50 lê como inválida pausa o envio. O seletor não vem
		// pré-marcado nesse caso: mostrar o status que parece gravado (`active` sem a
		// chave da data, por exemplo) e salvar tiraria a pausa sem o dono pedir (#322).
		$painel     = isset( $estado['panel'] ) && is_array( $estado['panel'] ) ? $estado['panel'] : array();
		$malformada = null !== $gravado && isset( $painel['reason'] ) && 'invalid' === $painel['reason'];
		if ( null === $gravado ) {
			$status = 'none';
		} elseif ( $malformada ) {
			$status = '';
		}
		$salvo   = uonix_intelligence_query_flag( 'uonix_license_saved' );
		$erro    = uonix_intelligence_query_flag( 'uonix_license_error' );

		// Textos fixos: o que vem da URL só escolhe a chave.
		$avisos_salvo = array(
			'1'       => 'Licença do painel salva.',
			'cleared' => 'Licença do painel removida: o painel não restringe mais o envio.',
		);
		$avisos_erro  = array(
			'status' => 'Nada foi salvo: status desconhecido.',
			'date'   => 'Nada foi salvo: a data-limite precisa ser um dia que exista, no formato AAAA-MM-DD.',
			'trial'   => 'Nada foi salvo: cortesia (trial) exige data-limite.',
			'missing' => 'Nada foi salvo: escolha o status do painel.',
		);
		$opcoes = array(
			'none'      => 'Sem controle pelo painel',
			'active'    => 'Contratada (active)',
			'trial'     => 'Cortesia (trial)',
			'suspended' => 'Suspensa (suspended)',
		);
		?>
		<h3 id="uonix-license-settings">Licença da Central</h3>

		<?php if ( isset( $avisos_salvo[ $salvo ] ) ) : ?>
			<div class="notice notice-success inline"><p><?php echo esc_html( $avisos_salvo[ $salvo ] ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $avisos_erro[ $erro ] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $avisos_erro[ $erro ] ); ?></p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Estado que vale</th>
				<td><?php echo esc_html( uonix_intelligence_license_summary( $estado ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">wp-config.php</th>
				<td><?php echo esc_html( uonix_intelligence_license_layer_summary( isset( $estado['constant'] ) ? $estado['constant'] : null ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">Painel</th>
				<td><?php echo esc_html( uonix_intelligence_license_layer_summary( isset( $estado['panel'] ) ? $estado['panel'] : null ) ); ?></td>
			</tr>
		</table>

		<?php if ( $malformada ) : ?>
			<div class="notice notice-warning inline"><p>A licença gravada no painel está malformada e pausa o envio. Salvar substitui o valor gravado: escolha o status de novo.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="uonix_intelligence_save_license" />
			<?php wp_nonce_field( 'uonix_intelligence_save_license' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="uonix-license-status">Status no painel</label></th>
					<td>
						<select id="uonix-license-status" name="uonix_license_status"<?php echo '' === $status ? ' required' : ''; ?>>
							<?php if ( '' === $status ) : ?>
								<option value="" disabled selected>Escolha o status</option>
							<?php endif; ?>
							<?php foreach ( $opcoes as $valor => $rotulo ) : ?>
								<option value="<?php echo esc_attr( $valor ); ?>"<?php echo $valor === $status ? ' selected' : ''; ?>><?php echo esc_html( $rotulo ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="uonix-license-valid-until">Último dia com envio</label></th>
					<td>
						<input type="date" id="uonix-license-valid-until" name="uonix_license_valid_until" value="<?php echo esc_attr( $data ); ?>" aria-describedby="uonix-license-valid-until-help" />
						<p class="description" id="uonix-license-valid-until-help">Inclusive, no fuso do site. Vazio é sem data-limite; a cortesia exige data.</p>
					</td>
				</tr>
			</table>
			<p class="description">
				O painel só restringe. A constante do wp-config.php continua valendo, e o que ela pausa o painel não religa.
				Vale a mais restritiva das duas e, se as duas permitem o envio, o prazo mais curto.
			</p>
			<p class="submit"><button type="submit" class="button">Salvar licença</button></p>
		</form>
		<?php
	}
}
