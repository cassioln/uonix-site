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

		// Módulo 4. Opcional: sem o 59 carregado, o e-mail sai como antes, só com as
		// oportunidades de SEO. Ver 59-admin-intelligence-executive.php.
		$executive = function_exists( 'uonix_intelligence_executive_collect' )
			? uonix_intelligence_executive_collect( array( 'seo' => $analysis ) )
			: null;

		return array(
			'analysis'     => $analysis,
			'executive'    => $executive,
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

// ---------------------------------------------------------------------------
// Módulo 4: blocos executivos. A camada de dados é 59-admin-intelligence-executive.php;
// daqui para baixo só se formata o que ela devolve.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_report_window_label' ) ) {
	/**
	 * "21/09 a 27/09" a partir de `array( 'start' => 'Y-m-d', 'end' => 'Y-m-d' )`.
	 */
	function uonix_intelligence_report_window_label( $window ) {
		if ( ! is_array( $window ) || ! isset( $window['start'], $window['end'] ) ) {
			return '';
		}
		$fuso   = new DateTimeZone( 'UTC' );
		$inicio = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $window['start'], $fuso );
		$fim    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $window['end'], $fuso );
		if ( false === $inicio || false === $fim ) {
			return '';
		}

		return $inicio->format( 'd/m' ) . ' a ' . $fim->format( 'd/m' );
	}
}

if ( ! function_exists( 'uonix_intelligence_report_executive_reason' ) ) {
	/**
	 * Motivo de indisponibilidade de uma caixa, em linguagem de leitor.
	 *
	 * Os motivos da Search Console vêm do Módulo 5, mas a tradução deles fica AQUI, e
	 * não em `uonix_intelligence_anomaly_reason_message()`. Aquela fala com o operador
	 * da aba Anomalias — "o gatilho tenta de novo na próxima verificação" — e o leitor
	 * deste e-mail não tem gatilho nenhum. A versão anterior delegava, e o e-mail
	 * imprimia essa frase (achado BAIXO da revisão do PR #301).
	 *
	 * O teste confere que todo motivo produzido pelo 58 e pelo 59 tem entrada aqui.
	 * Motivo desconhecido cai num texto honestamente vago.
	 */
	function uonix_intelligence_report_executive_reason( $reason ) {
		$mapa = array(
			'submissions_table_missing' => 'a tabela de orçamentos não está disponível.',
			'ga4_fetch_failed'          => 'a consulta ao GA4 falhou nesta execução.',
			'ga4_missing'               => 'os dados do GA4 não estão disponíveis.',
			'config_missing'            => 'as credenciais de leitura do Google não estão configuradas.',
			'no_sessions'               => 'nenhuma visita registrada no período.',
			'organic_missing'           => 'os dados da Search Console não estão disponíveis.',
			'windows_invalid'           => 'o período do relatório é inválido.',
			// Motivos da Search Console, produzidos por `uonix_intelligence_anomaly_organic_drop()`.
			'series_fetch_failed'       => 'a consulta à Search Console falhou nesta execução.',
			'series_invalid'            => 'a Search Console respondeu num formato inesperado.',
			'series_dates_invalid'      => 'a Search Console devolveu datas inconsistentes.',
			'series_too_short'          => 'a Search Console ainda não tem as duas semanas necessárias para comparar.',
			'baseline_too_small'        => 'a semana anterior teve poucas impressões; nesse volume a variação é ruído, não sinal.',
			'comparison_failed'         => 'a comparação entre as duas semanas não produziu número válido.',
		);

		return isset( $mapa[ $reason ] ) ? $mapa[ $reason ] : 'o dado não está disponível nesta semana.';
	}
}

if ( ! function_exists( 'uonix_intelligence_report_delta_html' ) ) {
	/**
	 * "▲ +30,4% vs. semana anterior", com a seta e a cor do sentido.
	 */
	function uonix_intelligence_report_delta_html( $delta, $sufixo ) {
		$d     = (float) $delta;
		$seta  = $d > 0 ? '▲' : ( $d < 0 ? '▼' : '=' );
		$cor   = $d > 0 ? '#15803d' : ( $d < 0 ? '#b91c1c' : '#64748b' );
		$sinal = $d > 0 ? '+' : ( $d < 0 ? '−' : '' );

		return '<span style="color:' . $cor . ';font-weight:bold;">' . $seta . ' ' . $sinal . esc_html( number_format( abs( $d ), 1, ',', '.' ) ) . '%</span> ' . esc_html( (string) $sufixo );
	}
}

if ( ! function_exists( 'uonix_intelligence_report_detect_html' ) ) {
	/**
	 * Resultado do teste de diferença, sem porcentagem.
	 */
	function uonix_intelligence_report_detect_html( $direction ) {
		if ( 'up' === $direction ) {
			return '<span style="color:#15803d;font-weight:bold;">▲ aumento detectável</span>';
		}
		if ( 'down' === $direction ) {
			return '<span style="color:#b91c1c;font-weight:bold;">▼ queda detectável</span>';
		}

		return '<span style="color:#64748b;">sem mudança detectável</span>';
	}
}

if ( ! function_exists( 'uonix_intelligence_report_box_html' ) ) {
	/**
	 * Uma célula do scorecard. Toda string vinda de dado passa por `esc_html`.
	 */
	function uonix_intelligence_report_box_html( $box ) {
		$titulos = array(
			'leads'      => 'Orçamentos',
			'visits'     => 'Visitas',
			'organic'    => 'Impressões na busca',
			'conversion' => 'Conversão',
		);
		$chave  = is_array( $box ) && isset( $box['key'] ) ? (string) $box['key'] : '';
		$titulo = isset( $titulos[ $chave ] ) ? $titulos[ $chave ] : $chave;

		$celula  = '<td width="50%" valign="top" style="width:50%;padding:14px 16px;border:1px solid #e2e8f0;">';
		$celula .= '<div style="color:#475569;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;">' . esc_html( $titulo ) . '</div>';

		if ( ! is_array( $box ) || empty( $box['available'] ) ) {
			$motivo  = uonix_intelligence_report_executive_reason( is_array( $box ) && isset( $box['reason'] ) ? (string) $box['reason'] : '' );
			$celula .= '<div style="color:#92400e;font-size:12px;line-height:1.5;padding-top:8px;">Indisponível: ' . esc_html( $motivo ) . '</div>';
			return $celula . '</td>';
		}

		$valor  = '';
		$linhas = array();
		$fonte  = '';

		if ( 'leads' === $chave ) {
			$valor    = number_format( (float) $box['current'], 0, ',', '.' );
			$linhas[] = esc_html( 'últimos 7 dias (' . uonix_intelligence_report_window_label( $box['window'] ) . ')' );
			$linhas[] = esc_html( sprintf( '4 semanas: %d, contra %d nas 4 anteriores', (int) $box['month'], (int) $box['prev_month'] ) );
			$linhas[] = uonix_intelligence_report_detect_html( (string) $box['direction'] );
			$fonte    = 'Fonte: formulários do site';
		} elseif ( 'visits' === $chave ) {
			$valor    = number_format( (float) $box['current'], 0, ',', '.' );
			$linhas[] = esc_html( 'semana de ' . uonix_intelligence_report_window_label( $box['window'] ) );
			if ( ! empty( $box['comparable'] ) ) {
				$linhas[] = uonix_intelligence_report_delta_html( $box['delta_percent'], 'vs. semana anterior' );
			} elseif ( 'no_history' === ( $box['note'] ?? '' ) ) {
				$linhas[] = esc_html( 'sem histórico do GA4 para comparar' );
			} else {
				$linhas[] = esc_html( 'semana anterior sem visitas registradas' );
			}
			$fonte = 'Fonte: GA4 · só visitas com consentimento';
		} elseif ( 'organic' === $chave ) {
			$valor    = number_format( (float) $box['current'], 0, ',', '.' );
			$linhas[] = esc_html( 'semana de ' . uonix_intelligence_report_window_label( $box['window'] ) );
			if ( 'collapse' === ( $box['note'] ?? '' ) ) {
				$linhas[] = '<span style="color:#b91c1c;font-weight:bold;">▼ nenhuma impressão na semana</span> ' . esc_html( '— veja a aba Anomalias' );
			} elseif ( ! empty( $box['comparable'] ) ) {
				$linhas[] = uonix_intelligence_report_delta_html( $box['delta_percent'], 'vs. semana anterior' );
			} elseif ( 'previous_incomplete' === ( $box['note'] ?? '' ) ) {
				// Sem porcentagem: com dia ausente na semana anterior, a variação sairia
				// inflada — e é por isso que ela não é mostrada.
				$faltam   = (int) ( $box['missing_prev'] ?? 0 );
				$linhas[] = esc_html( sprintf( 'sem comparação: a semana anterior tem %d %s sem dado', $faltam, 1 === $faltam ? 'dia' : 'dias' ) );
			} else {
				$faltam   = (int) ( $box['imputed_days'] ?? 0 );
				$linhas[] = esc_html( sprintf( 'sem comparação: %d %s desta semana ainda sem dado', $faltam, 1 === $faltam ? 'dia' : 'dias' ) );
			}
			// A semana termina alguns dias antes de hoje porque a Search Console publica
			// com atraso. Dizer isso evita que a data pareça um erro.
			$fonte = 'Fonte: Search Console · a semana termina antes de hoje porque os dados chegam com ~3 dias de atraso';
		} elseif ( 'conversion' === $chave ) {
			$valor    = 'até ' . number_format( (float) $box['rate'] * 100, 1, ',', '.' ) . '%';
			$linhas[] = esc_html( sprintf(
				'%d %s em %s %s · 4 semanas (%s)',
				(int) $box['leads'],
				1 === (int) $box['leads'] ? 'orçamento' : 'orçamentos',
				number_format( (float) $box['sessions'], 0, ',', '.' ),
				1 === (int) $box['sessions'] ? 'visita' : 'visitas',
				uonix_intelligence_report_window_label( $box['window'] )
			) );
			if ( ! empty( $box['comparable'] ) ) {
				$linhas[] = esc_html( 'antes: até ' . number_format( (float) $box['prev_rate'] * 100, 1, ',', '.' ) . '% · ' ) . uonix_intelligence_report_detect_html( (string) $box['direction'] );
			} elseif ( 'no_history' === ( $box['note'] ?? '' ) ) {
				$linhas[] = esc_html( 'sem histórico do GA4 para comparar' );
			} else {
				$linhas[] = esc_html( 'período anterior sem visitas registradas' );
			}
			// O sentido do erro é conhecido e vai escrito: o numerador conta todos os
			// orçamentos, o denominador só visitas com consentimento.
			$fonte = 'Teto: o GA4 só conta visitas com consentimento, então a taxa real é menor ou igual';
		}

		$celula .= '<div style="color:#0b1c2c;font-size:26px;font-weight:bold;line-height:1.2;padding-top:6px;">' . esc_html( $valor ) . '</div>';
		foreach ( $linhas as $linha ) {
			$celula .= '<div style="color:#334155;font-size:12px;line-height:1.5;padding-top:3px;">' . $linha . '</div>';
		}
		if ( '' !== $fonte ) {
			$celula .= '<div style="color:#94a3b8;font-size:10px;line-height:1.4;padding-top:8px;">' . esc_html( $fonte ) . '</div>';
		}

		return $celula . '</td>';
	}
}

if ( ! function_exists( 'uonix_intelligence_report_executive_top_html' ) ) {
	/**
	 * Scorecard e destaques, que abrem o e-mail.
	 */
	function uonix_intelligence_report_executive_top_html( $executive ) {
		$caixas = isset( $executive['scorecard']['boxes'] ) && is_array( $executive['scorecard']['boxes'] ) ? $executive['scorecard']['boxes'] : array();
		$html   = '';

		$html .= '<tr><td style="padding:24px 28px 8px 28px;">';
		$html .= '<div style="color:#0e3780;font-size:16px;font-weight:bold;">Resumo da semana</div>';
		$html .= '<div style="color:#64748b;font-size:11px;padding-top:6px;">Cada caixa declara a própria fonte e o próprio período.</div>';
		$html .= '</td></tr>';

		$html .= '<tr><td style="padding:8px 28px 8px 28px;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
		foreach ( array( array( 'leads', 'visits' ), array( 'organic', 'conversion' ) ) as $par ) {
			$html .= '<tr>';
			foreach ( $par as $chave ) {
				$html .= uonix_intelligence_report_box_html( isset( $caixas[ $chave ] ) ? $caixas[ $chave ] : array( 'key' => $chave, 'available' => false, 'reason' => '' ) );
			}
			$html .= '</tr>';
		}
		$html .= '</table>';
		$html .= '</td></tr>';

		$destaques = isset( $executive['insights'] ) && is_array( $executive['insights'] ) ? $executive['insights'] : array();
		if ( array() !== $destaques ) {
			$html .= '<tr><td style="padding:16px 28px 8px 28px;">';
			$html .= '<div style="color:#0e3780;font-size:16px;font-weight:bold;">Destaques</div>';
			$html .= '<ul style="margin:8px 0 0 0;padding-left:18px;color:#1e293b;font-size:13px;line-height:1.6;">';
			foreach ( $destaques as $destaque ) {
				if ( is_array( $destaque ) && isset( $destaque['text'] ) && '' !== (string) $destaque['text'] ) {
					$html .= '<li style="padding-bottom:6px;">' . esc_html( (string) $destaque['text'] ) . '</li>';
				}
			}
			$html .= '</ul>';
			$html .= '</td></tr>';
		}

		return $html;
	}
}

if ( ! function_exists( 'uonix_intelligence_report_executive_pages_html' ) ) {
	/**
	 * Páginas mais encontradas na busca (pilar 4).
	 */
	function uonix_intelligence_report_executive_pages_html( $executive ) {
		$paginas = isset( $executive['top_pages'] ) && is_array( $executive['top_pages'] ) ? $executive['top_pages'] : array();

		$procedencia = 'Fonte: Search Console';
		$janela      = uonix_intelligence_report_window_label( isset( $paginas['window'] ) ? $paginas['window'] : null );
		if ( '' !== $janela ) {
			$procedencia .= ' · ' . $janela . ' (28 dias, sem os dias ainda não publicados)';
		}
		// Só declara a soma quando ela aconteceu. Afirmar sempre seria dizer ao leitor
		// que há endereço antigo somado numa semana em que não há nenhum.
		$houve_soma = false;
		foreach ( isset( $paginas['rows'] ) && is_array( $paginas['rows'] ) ? $paginas['rows'] : array() as $linha ) {
			if ( is_array( $linha ) && isset( $linha['merged'] ) && (int) $linha['merged'] > 0 ) {
				$houve_soma = true;
				break;
			}
		}
		if ( $houve_soma ) {
			$procedencia .= ' · endereços antigos que redirecionam foram somados ao destino';
		}
		// A API corta por cliques, então lista cheia pode ter perdido página de muita
		// impressão e pouco clique na cauda.
		if ( ! empty( $paginas['truncated'] ) ) {
			$procedencia .= ' · a Search Console devolveu o limite de páginas, e a lista pode estar incompleta';
		}

		$html  = '<tr><td style="padding:8px 28px 8px 28px;">';
		$html .= '<div style="color:#0e3780;font-size:16px;font-weight:bold;">Páginas mais encontradas na busca</div>';
		$html .= '<div style="color:#64748b;font-size:11px;padding-top:6px;">' . esc_html( $procedencia ) . '</div>';
		$html .= '</td></tr>';

		$html .= '<tr><td style="padding:8px 28px 24px 28px;">';
		if ( empty( $paginas['available'] ) ) {
			$html .= '<div style="padding:14px 16px;background-color:#fffbeb;border-left:4px solid #f59e0b;color:#78350f;font-size:13px;line-height:1.5;">As páginas mais encontradas não estão disponíveis nesta semana: a consulta à Search Console não retornou dados.</div>';
		} else {
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">';
			$html .= '<tr style="background-color:#f8fafc;">';
			$html .= '<th align="left" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Página</th>';
			$html .= '<th align="right" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Impressões</th>';
			$html .= '<th align="right" style="padding:8px 10px;color:#334155;font-size:12px;border-bottom:1px solid #e2e8f0;">Cliques</th>';
			$html .= '</tr>';
			foreach ( (array) $paginas['rows'] as $linha ) {
				if ( ! is_array( $linha ) ) {
					continue;
				}
				$estado = isset( $linha['state'] ) ? (string) $linha['state'] : 'unknown';
				$html  .= '<tr>';
				$html  .= '<td style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">' . esc_html( isset( $linha['label'] ) ? (string) $linha['label'] : '' );
				// Página que o Google mostra e não existe é o achado mais acionável do
				// bloco, então ela é marcada em vermelho em vez de escondida.
				if ( 'not_found' === $estado ) {
					$html .= '<div style="color:#b91c1c;font-size:11px;font-weight:bold;padding-top:3px;">Esta página não existe (404): o Google mostra o endereço e o visitante não encontra nada.</div>';
				} elseif ( 'ok' !== $estado ) {
					$html .= '<div style="color:#64748b;font-size:11px;padding-top:3px;">Status da página não verificado.</div>';
				}
				$somados = isset( $linha['merged'] ) ? (int) $linha['merged'] : 0;
				if ( $somados > 0 ) {
					$html .= '<div style="color:#64748b;font-size:11px;padding-top:3px;">' . esc_html( 1 === $somados ? 'Inclui 1 endereço antigo que redireciona para cá.' : sprintf( 'Inclui %d endereços antigos que redirecionam para cá.', $somados ) ) . '</div>';
				}
				$html .= '</td>';
				$html .= '<td align="right" style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">' . esc_html( number_format( (float) ( $linha['impressions'] ?? 0 ), 0, ',', '.' ) ) . '</td>';
				$html .= '<td align="right" style="padding:10px;border-bottom:1px solid #f1f5f9;color:#1e293b;">' . esc_html( number_format( (float) ( $linha['clicks'] ?? 0 ), 0, ',', '.' ) ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</table>';
		}
		$html .= '</td></tr>';

		return $html;
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

		// Módulo 4: scorecard e destaques abrem o e-mail, porque é o que o leitor
		// executivo lê primeiro. Ausente o contexto, o e-mail sai como antes.
		$executivo = isset( $context['executive'] ) && is_array( $context['executive'] ) ? $context['executive'] : null;
		if ( null !== $executivo ) {
			$html .= uonix_intelligence_report_executive_top_html( $executivo );
		}

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

		if ( null !== $executivo ) {
			$html .= uonix_intelligence_report_executive_pages_html( $executivo );
		}

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
	 * motivo `no_recipients`. Amarrar o agendamento a ela dá duas propriedades
	 * que um agendamento manual por WP-CLI não tem:
	 *
	 * - **Reprodutível.** Agendamento feito à mão vive só na opção `cron`. Uma
	 *   restauração de banco anterior a ele, ou uma limpeza de cron, o perde em
	 *   silêncio. Aqui ele se restabelece na requisição seguinte, porque a lista
	 *   de destinatários sobrevive.
	 * - **Painel honesto.** Sem destinatário, o evento é removido, então o painel
	 *   exibe "não agendado" em vez de prometer um envio que não aconteceria.
	 *
	 * **NÃO confunda isto com contenção por ambiente.** Não vale dizer que "a
	 * opção vive no banco de cada ambiente, então o ambiente clonado não agenda":
	 * `scripts/clone-environment.sh` copia `wp_options` da origem. A contenção
	 * existe porque `uonix_executive_report_recipients` está em
	 * `protected_options_where()` — e, mais precisamente, porque esse predicado
	 * governa o `DELETE` de `restore_options()`, que remove do destino a linha
	 * herdada. Sem essa proteção, o guard de e-mail de
	 * 49-email-environment-label.php ainda conteria o ENVIO, mas não a AFIRMAÇÃO
	 * do painel. Ver docs/uonix-insights-inteligencia.md.
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
