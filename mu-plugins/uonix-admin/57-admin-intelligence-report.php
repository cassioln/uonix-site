<?php
/**
 * Central de Inteligência — relatório executivo por e-mail.
 *
 * Monta e envia o relatório a partir do que 55-admin-intelligence-metrics.php
 * devolve. Não consulta API própria.
 *
 * Mantém o invariante **existe evento agendado se, e somente se, existe
 * destinatário**: o handler é registrado no carregamento, e um callback de `init`
 * cria ou remove o evento semanal conforme a lista de destinatários. Carregar
 * este arquivo não escreve no agendador.
 *
 * A ativação segue sendo decisão humana — ela é expressa por cadastrar um
 * destinatário, não por rodar um comando. Lista vazia é o estado registrado e
 * inativo, válido e esperado — ver docs/uonix-insights-inteligencia.md.
 *
 * O envio usa wp_mail(), portanto passa pelo guard de ambiente de
 * mu-plugins/uonix-integrations/49-email-environment-label.php, que bloqueia
 * envio em QA e DEV sem UONIX_NONPROD_EMAIL_TO. Esse guard não é contornado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_report_period_label' ) ) {
	/**
	 * Rótulo do período coberto, lido do snapshot.
	 *
	 * Devolve string vazia quando o snapshot não declara o intervalo: inventar um
	 * período no cabeçalho de um relatório executivo é pior que omiti-lo.
	 */
	function uonix_intelligence_report_period_label( $snapshot ) {
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['periods']['current']['start'], $snapshot['periods']['current']['end'] ) ) {
			return '';
		}
		$inicio = strtotime( (string) $snapshot['periods']['current']['start'] );
		$fim    = strtotime( (string) $snapshot['periods']['current']['end'] );
		if ( false === $inicio || false === $fim ) {
			return '';
		}
		return gmdate( 'd/m/Y', $inicio ) . ' a ' . gmdate( 'd/m/Y', $fim );
	}
}

if ( ! function_exists( 'uonix_intelligence_report_context' ) ) {
	/**
	 * Reúne tudo que o template precisa, para o template não buscar nada.
	 */
	function uonix_intelligence_report_context() {
		$rules    = function_exists( 'uonix_intelligence_seo_rules' ) ? uonix_intelligence_seo_rules() : array( 'period_days' => 30 );
		$snapshot = function_exists( 'uonix_analytics_metrics_get_snapshot' ) ? uonix_analytics_metrics_get_snapshot( $rules['period_days'] ) : false;
		$analysis = function_exists( 'uonix_intelligence_seo_opportunities' ) ? uonix_intelligence_seo_opportunities( $snapshot, 3 ) : array(
			'available' => false,
			'reason'    => 'snapshot_missing',
			'source'    => 'search_console',
			'synced_at' => '',
			'stale'     => true,
			'universe'  => 0,
			'rows'      => array(),
		);

		return array(
			'analysis'     => $analysis,
			'period_label' => uonix_intelligence_report_period_label( $snapshot ),
			'environment'  => defined( 'UONIX_ENV' ) ? (string) UONIX_ENV : '',
			'panel_url'    => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=uonix-analytics&tab=intelligence' ) : '',
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_report_subject' ) ) {
	function uonix_intelligence_report_subject( $context ) {
		$assunto = 'Uônix — Relatório de Inteligência e Performance';
		$periodo = isset( $context['period_label'] ) ? (string) $context['period_label'] : '';
		return '' !== $periodo ? $assunto . ' (' . $periodo . ')' : $assunto;
	}
}

if ( ! function_exists( 'uonix_intelligence_report_html' ) ) {
	/**
	 * Corpo HTML do relatório.
	 *
	 * Tabelas e estilo inline por necessidade: Outlook não aplica CSS de folha nem
	 * respeita flexbox. Cada bloco carrega a própria procedência, como no painel.
	 */
	function uonix_intelligence_report_html( $context ) {
		$analysis = isset( $context['analysis'] ) && is_array( $context['analysis'] ) ? $context['analysis'] : array();
		$rows     = isset( $analysis['rows'] ) && is_array( $analysis['rows'] ) ? $analysis['rows'] : array();
		$periodo  = isset( $context['period_label'] ) ? (string) $context['period_label'] : '';
		$ambiente = isset( $context['environment'] ) ? (string) $context['environment'] : '';
		$painel   = isset( $context['panel_url'] ) ? (string) $context['panel_url'] : '';

		$stale     = ! empty( $analysis['stale'] );
		$synced_at = isset( $analysis['synced_at'] ) ? (string) $analysis['synced_at'] : '';
		$timestamp = '' !== $synced_at ? strtotime( $synced_at ) : false;
		$procedencia = 'Fonte: Search Console';
		$procedencia .= false !== $timestamp ? ' · sincronizado em ' . gmdate( 'd/m/Y H:i', $timestamp ) . ' (UTC)' : ' · sem horário de sincronização';
		$procedencia .= $stale ? ' · dado desatualizado' : '';

		$html  = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<title>' . esc_html( uonix_intelligence_report_subject( $context ) ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background-color:#f1f5f9;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">';
		$html .= '<tr><td align="center">';
		$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">';

		// Cabeçalho.
		$html .= '<tr><td style="background-color:#0b1c2c;padding:24px 28px;">';
		$html .= '<div style="color:#ffffff;font-size:20px;font-weight:bold;line-height:1.3;">Uônix</div>';
		$html .= '<div style="color:#94a3b8;font-size:13px;padding-top:4px;">Relatório de Inteligência &amp; Performance</div>';
		if ( '' !== $periodo ) {
			$html .= '<div style="display:inline-block;margin-top:12px;padding:4px 10px;background-color:#1e3a5f;color:#e2e8f0;font-size:12px;border-radius:4px;">' . esc_html( $periodo ) . '</div>';
		}
		$html .= '</td></tr>';

		// Bloco: Oportunidades SEO.
        $html .= '<tr><td style="padding:24px 28px 8px 28px;">';
		$html .= '<div style="color:#0e3780;font-size:16px;font-weight:bold;">Oportunidades de busca a um passo do topo</div>';
		$html .= '<div style="color:#64748b;font-size:11px;padding-top:6px;">' . esc_html( $procedencia ) . '</div>';
		$html .= '</td></tr>';

		$html .= '<tr><td style="padding:8px 28px 24px 28px;">';
		if ( empty( $analysis['available'] ) ) {
			$motivo = function_exists( 'uonix_intelligence_unavailable_message' )
				? uonix_intelligence_unavailable_message( isset( $analysis['reason'] ) ? (string) $analysis['reason'] : '' )
				: 'A análise não está disponível no momento.';
			$html .= '<div style="padding:14px 16px;background-color:#fffbeb;border-left:4px solid #f59e0b;color:#78350f;font-size:13px;line-height:1.5;">' . esc_html( $motivo ) . '</div>';
		} elseif ( array() === $rows ) {
			$html .= '<div style="padding:14px 16px;background-color:#f0f9ff;border-left:4px solid #0284c7;color:#075985;font-size:13px;line-height:1.5;">Nenhuma consulta atendeu aos critérios nesta janela. Isso é um resultado, não uma falha: não há ganho fácil disponível agora.</div>';
		} else {
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">';
			$html .= '<tr style="background-color:#f8fafc;">';
			$html .= '<th align="left" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Consulta</th>';
			$html .= '<th align="right" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Posição</th>';
			$html .= '<th align="right" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Impressões</th>';
			$html .= '</tr>';
			foreach ( $rows as $row ) {
				$sugestao = isset( $row['suggestion'] ) && is_array( $row['suggestion'] ) && array() !== $row['suggestion']
					? implode( ' · ', array_map( 'strval', $row['suggestion'] ) )
					: '';
				$html .= '<tr>';
				$html .= '<td style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">';
				$html .= '<strong>' . esc_html( (string) $row['query'] ) . '</strong>';
				if ( '' !== $sugestao ) {
					$html .= '<div style="color:#64748b;font-size:11px;padding-top:3px;">Acrescentar ao título: ' . esc_html( $sugestao ) . '</div>';
				}
				$html .= '</td>';
				$html .= '<td align="right" style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">' . esc_html( number_format( (float) $row['position'], 1, ',', '.' ) ) . '</td>';
				$html .= '<td align="right" style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">' . esc_html( number_format( (float) $row['impressions'], 0, ',', '.' ) ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</table>';
		}
		$html .= '</td></tr>';

		// Rodapé.
		$html .= '<tr><td style="background-color:#f8fafc;padding:18px 28px;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.6;">';
		if ( '' !== $painel ) {
			$html .= '<div><a href="' . esc_url( $painel ) . '" style="color:#0e3780;">Abrir a Central de Inteligência no painel</a></div>';
		}
		$html .= '<div style="padding-top:6px;">Envio semanal. O agendador do WordPress depende de tráfego no site, então o horário exato varia.</div>';
		if ( '' !== $ambiente && 'production' !== $ambiente ) {
			$html .= '<div style="padding-top:6px;color:#b45309;"><strong>Ambiente: ' . esc_html( strtoupper( $ambiente ) ) . '</strong> — mensagem não produtiva.</div>';
		}
		$html .= '</td></tr>';

		$html .= '</table></td></tr></table></body></html>';

		return $html;
	}
}

if ( ! function_exists( 'uonix_intelligence_send_report' ) ) {
	/**
	 * Envia o relatório para os destinatários cadastrados.
	 *
	 * Sem destinatário não envia e diz por quê: uma lista vazia é configuração
	 * pendente, não erro de transporte. O resultado é sempre um array, nunca um
	 * booleano solto, para o chamador poder registrar o motivo.
	 *
	 * @return array{sent: bool, reason: string, recipients: int}
	 */
	function uonix_intelligence_send_report( $recipients = null ) {
		$lista = null === $recipients && function_exists( 'uonix_intelligence_get_recipients' )
			? uonix_intelligence_get_recipients()
			: $recipients;
		$lista = is_array( $lista ) ? $lista : array();

		if ( array() === $lista ) {
			return array( 'sent' => false, 'reason' => 'no_recipients', 'recipients' => 0 );
		}

		$context = uonix_intelligence_report_context();
		$enviado = wp_mail(
			$lista,
			uonix_intelligence_report_subject( $context ),
			uonix_intelligence_report_html( $context ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		return array(
			'sent'       => (bool) $enviado,
			'reason'     => $enviado ? '' : 'mail_failed',
			'recipients' => count( $lista ),
		);
	}
}

// O handler do evento semanal é registrado no carregamento. O evento NÃO é
// agendado aqui: carregar um arquivo não deve escrever no agendador, e em
// mu-plugin isso roda antes de `init`, antes dos plugins. Quem agenda é o
// callback de `init` abaixo.
if ( function_exists( 'uonix_intelligence_report_hook' ) ) {
	add_action( uonix_intelligence_report_hook(), 'uonix_intelligence_send_report', 10, 0 );
}

if ( ! function_exists( 'uonix_intelligence_report_first_run' ) ) {
	/**
	 * Momento do primeiro disparo: próxima segunda-feira, 08:00 no fuso do site.
	 *
	 * O horário é arbitrário **de propósito**. Sem cronjob de servidor, WP-Cron
	 * dispara por tráfego, não por relógio: a deriva pode atravessar o dia. Por
	 * isso o contrato proíbe prometer horário na interface, e por isso não vale
	 * gastar decisão escolhendo um. Segunda-feira é só a âncora semanal.
	 *
	 * @return int Timestamp Unix.
	 */
	function uonix_intelligence_report_first_run() {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

		return ( new DateTimeImmutable( 'now', $timezone ) )
			->modify( 'next monday' )
			->setTime( 8, 0, 0 )
			->getTimestamp();
	}
}

if ( ! function_exists( 'uonix_intelligence_maybe_schedule_report' ) ) {
	/**
	 * Mantém o invariante: **existe evento agendado se, e somente se, existe
	 * destinatário.**
	 *
	 * A lista de destinatários é a chave de ativação, e não um campo a mais: o
	 * próprio `uonix_intelligence_send_report()` já recusa lista vazia com o
	 * motivo `no_recipients`. Amarrar o agendamento a ela dá três propriedades
	 * que um agendamento manual por WP-CLI não tem:
	 *
	 * - **Reprodutível.** Agendamento feito à mão vive só no banco. Um clone de
	 *   ambiente ou uma restauração o perde em silêncio, e ninguém lembra de
	 *   refazer. Aqui ele se restabelece sozinho na requisição seguinte.
	 * - **Contido por ambiente sem lógica de ambiente.** Um ambiente onde nunca
	 *   se configurou destinatário não agenda nada, porque a opção vive no banco
	 *   de cada ambiente. Não é preciso perguntar "sou produção?".
	 * - **Painel honesto.** Sem destinatário, o evento é removido, então o painel
	 *   exibe "não agendado" em vez de prometer um envio que não aconteceria.
	 *
	 * @return bool Verdadeiro apenas quando esta chamada criou o evento.
	 */
	function uonix_intelligence_maybe_schedule_report() {
		if ( ! function_exists( 'uonix_intelligence_report_hook' ) || ! function_exists( 'uonix_intelligence_get_recipients' ) ) {
			return false;
		}

		$hook      = uonix_intelligence_report_hook();
		$agendado  = wp_next_scheduled( $hook );
		$destinos  = uonix_intelligence_get_recipients();

		if ( array() === $destinos ) {
			if ( false !== $agendado ) {
				// `wp_clear_scheduled_hook` remove todas as ocorrências, e não só a
				// primeira: se um agendamento duplicado existir, some junto.
				wp_clear_scheduled_hook( $hook );
			}

			return false;
		}

		if ( false !== $agendado ) {
			// Já agendado: não duplicar nem mover a data. Reagendar a cada
			// requisição empurraria o disparo para sempre adiante e o relatório
			// nunca sairia.
			return false;
		}

		$recorrencias = wp_get_schedules();
		if ( ! isset( $recorrencias['weekly'] ) ) {
			// Falha fechada: sem a recorrência registrada, `wp_schedule_event`
			// criaria um disparo único disfarçado de semanal. Melhor não agendar e
			// deixar o painel dizer "não agendado".
			return false;
		}

		return (bool) wp_schedule_event( uonix_intelligence_report_first_run(), 'weekly', $hook );
	}
}
// `accepted_args = 0` como o irmão de 53: o callback não usa argumento nenhum, e
// declarar zero impede que um dia alguém injete dado pelo despacho do hook.
add_action( 'init', 'uonix_intelligence_maybe_schedule_report', 10, 0 );

if ( ! function_exists( 'uonix_intelligence_handle_test_send' ) ) {
	/**
	 * Envio de teste sob demanda, a partir da aba de configurações.
	 *
	 * Mesmas guardas da gravação de destinatários: `manage_options` e nonce. Disparar
	 * e-mail para a lista é ação de efeito externo, não leitura.
	 */
	function uonix_intelligence_handle_test_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão para enviar o relatório.', 'uonix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'uonix_intelligence_send_test' );

		$resultado = uonix_intelligence_send_report();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'uonix-analytics',
					'tab'                  => 'settings',
					'uonix_test_sent'      => $resultado['sent'] ? '1' : '0',
					'uonix_test_reason'    => $resultado['reason'],
					'uonix_test_recipients' => (string) $resultado['recipients'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
add_action( 'admin_post_uonix_intelligence_send_test', 'uonix_intelligence_handle_test_send' );
