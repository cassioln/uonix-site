<?php
/**
 * Central de Inteligência — render.
 *
 * Renderiza os painéis das abas `intelligence` e `settings` do menu Uônix
 * Insights. Consome apenas o que 55-admin-intelligence-metrics.php devolve; não
 * consulta API, não grava nada e não agenda nada.
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
			'no_recipients' => 'Nenhum destinatário cadastrado, então nada foi enviado. Salve ao menos um endereço acima.',
			'mail_failed'   => 'O envio falhou. Em QA e DEV isso é esperado quando UONIX_NONPROD_EMAIL_TO não está configurado: o guard de ambiente bloqueia envio sem caixa segura.',
		);
		return isset( $mapa[ $reason ] ) ? $mapa[ $reason ] : 'O envio não foi concluído.';
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

if ( ! function_exists( 'uonix_intelligence_mask_email' ) ) {
	/**
	 * Mascara um endereço preservando a inicial e o domínio.
	 *
	 * A lista de destinatários é impressa em toda carga do painel, porque os painéis
	 * são renderizados sempre e apenas escondidos. Quem não pode alterar a lista não
	 * precisa ver os endereços completos da diretoria no código-fonte da página.
	 */
	function uonix_intelligence_mask_email( $email ) {
		$partes = explode( '@', (string) $email, 2 );
		if ( 2 !== count( $partes ) || '' === $partes[0] ) {
			return '***';
		}
		$oculto = str_repeat( '*', max( 1, strlen( $partes[0] ) - 1 ) );
		return substr( $partes[0], 0, 1 ) . $oculto . '@' . $partes[1];
	}
}

if ( ! function_exists( 'uonix_intelligence_render_panel' ) ) {
	/**
	 * Painel da aba "Oportunidades SEO".
	 */
	function uonix_intelligence_render_panel( $active_tab ) {
		$is_active = 'intelligence' === $active_tab;
		$analysis  = function_exists( 'uonix_intelligence_seo_opportunities' )
			? uonix_intelligence_seo_opportunities( null, 5 )
			: array( 'available' => false, 'reason' => 'snapshot_missing', 'source' => 'search_console', 'synced_at' => '', 'stale' => true, 'universe' => 0, 'rows' => array() );
		$rules = function_exists( 'uonix_intelligence_seo_rules' ) ? uonix_intelligence_seo_rules() : array( 'min_position' => 4, 'max_position' => 12, 'min_impressions' => 5, 'max_ctr' => 0.03 );
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
								<th scope="col">Diferenciais a acrescentar no título</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $row['query'] ); ?></strong></td>
									<td><?php echo esc_html( uonix_intelligence_number( $row['position'], 1 ) ); ?></td>
									<td><?php echo esc_html( uonix_intelligence_number( $row['impressions'], 0 ) ); ?></td>
									<td><?php echo esc_html( uonix_intelligence_format_ctr( $row['ctr'] ) ); ?></td>
									<td>
										<?php if ( empty( $row['suggestion'] ) ) : ?>
											<em>A consulta já cobre os diferenciais mapeados.</em>
										<?php else : ?>
											<?php echo esc_html( implode( ' · ', array_map( 'strval', (array) $row['suggestion'] ) ) ); ?>
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

if ( ! function_exists( 'uonix_intelligence_render_settings_panel' ) ) {
	/**
	 * Painel da aba "Configurações": destinatários e agendamento.
	 */
	function uonix_intelligence_render_settings_panel( $active_tab ) {
		$is_active   = 'settings' === $active_tab;
		$recipients  = function_exists( 'uonix_intelligence_get_recipients' ) ? uonix_intelligence_get_recipients() : array();
		$pode_editar = current_user_can( 'manage_options' );
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

			<?php if ( $salvos >= 0 ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( '%d destinatário(s) salvo(s).', $salvos ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $recusados > 0 ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( '%d entrada(s) recusada(s) por não serem e-mail válido ou por exceder o limite.', $recusados ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['uonix_test_sent'] ) ) : ?>
				<?php if ( '1' === (string) $_GET['uonix_test_sent'] ) : ?>
					<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( 'Relatório de teste enviado para %d destinatário(s).', isset( $_GET['uonix_test_recipients'] ) ? (int) $_GET['uonix_test_recipients'] : 0 ) ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( uonix_intelligence_test_send_message( isset( $_GET['uonix_test_reason'] ) ? (string) $_GET['uonix_test_reason'] : '' ) ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h3>Destinatários atuais</h3>
			<?php if ( array() === $recipients ) : ?>
				<p><em>Nenhum destinatário cadastrado. Sem destinatário, nenhum relatório é enviado.</em></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $recipients as $email ) : ?>
						<li><code><?php echo esc_html( $pode_editar ? $email : uonix_intelligence_mask_email( $email ) ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $pode_editar ) : ?>
					<p class="description">Endereços parcialmente ocultos: só quem pode alterar a lista vê os endereços completos.</p>
				<?php endif; ?>
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
			<?php else : ?>
				<p class="description">Alterar a lista de destinatários exige permissão de administrador.</p>
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
				O envio é disparado pelo agendador do WordPress, que depende de tráfego no site e não de relógio.
				Por isso esta tela informa a frequência e o próximo disparo realmente agendado, e não promete um horário fixo.
			</p>
		</section>
		<?php
	}
}
