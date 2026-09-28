<?php
/**
 * Central de Inteligência — Relatório Executivo (Módulo 4), camada de dados.
 *
 * Monta o scorecard, os destaques determinísticos e as páginas mais encontradas na
 * busca, que o e-mail semanal de 57-admin-intelligence-report.php renderiza. Não
 * renderiza nada e não grava nada.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_executive_rules' ) ) {
	/**
	 * Parâmetros do relatório executivo.
	 *
	 * `detect_alpha` = 0,05 é o nível convencional de significância, e é o único
	 * número deste módulo que não vem de medição do site. Ele decide quando uma
	 * diferença de orçamentos é "detectável". A alternativa seria um limiar fixo de
	 * diferença, escolhido por mim — e com ~1 orçamento por semana qualquer limiar
	 * fixo ou calaria sempre ou dispararia sempre. Ver
	 * `uonix_intelligence_executive_binomial_p()`.
	 *
	 * `top_pages` = 5 é tamanho de digest, não medição: o bloco é informativo e não
	 * decide nada.
	 */
	function uonix_intelligence_executive_rules() {
		return array(
			'detect_alpha'   => 0.05,
			'lead_days_read' => 60,
			'ga4_buffer'     => 7,
			'top_pages'      => 5,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_windows' ) ) {
	/**
	 * Janelas do relatório, todas terminando ONTEM.
	 *
	 * Ontem, e não hoje, porque o relatório sai segunda às 08:00: "hoje" teria oito
	 * horas e puxaria a semana para baixo. Terminar ontem dá sete dias inteiros.
	 *
	 * Ontem é seguro para o GA4 e para os orçamentos, e isso foi MEDIDO em
	 * 2026-09-28: às 13:00 o GA4 já devolvia dado parcial do próprio dia 28/09, então
	 * a defasagem dele é de horas, não de dias. Os orçamentos vêm do banco local, sem
	 * defasagem nenhuma.
	 *
	 * A Search Console NÃO usa estas janelas. Ela publica com ~3 dias de atraso
	 * (medido de novo em 2026-09-28: série terminando em 25/09), e a janela dela vem
	 * de `uonix_intelligence_anomaly_organic_windows()`, que já resolve isso.
	 *
	 * Duas granularidades, e a escolha entre elas não é estética:
	 *
	 * - **7 dias** para visitas e impressões, cujo volume aguenta comparação semanal.
	 * - **28 dias** para orçamentos e conversão. Com 14 orçamentos em 90 dias (~1 por
	 *   semana, medido em 2026-09-23), um "▲ +100%" semanal seria ruído vestido de
	 *   sinal: basta um orçamento a mais.
	 *
	 * As janelas anteriores têm o mesmo tamanho e vêm imediatamente antes, o que
	 * preserva a composição de dias da semana.
	 *
	 * @param string $today Hoje, 'Y-m-d', no fuso do site.
	 * @return array<string, array{start: string, end: string, days: int}>
	 */
	function uonix_intelligence_executive_windows( $today ) {
		$fuso = new DateTimeZone( 'UTC' );
		$hoje = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, $fuso );
		if ( false === $hoje ) {
			return array();
		}
		$ontem  = $hoje->modify( '-1 day' );
		$janela = static function ( DateTimeImmutable $fim, $dias ) {
			return array(
				'start' => $fim->modify( '-' . ( $dias - 1 ) . ' days' )->format( 'Y-m-d' ),
				'end'   => $fim->format( 'Y-m-d' ),
				'days'  => $dias,
			);
		};

		return array(
			'week'       => $janela( $ontem, 7 ),
			'prev_week'  => $janela( $ontem->modify( '-7 days' ), 7 ),
			'month'      => $janela( $ontem, 28 ),
			'prev_month' => $janela( $ontem->modify( '-28 days' ), 28 ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_sum' ) ) {
	/**
	 * Soma os valores por dia que caem dentro da janela, inclusive.
	 *
	 * Dia ausente vale zero. Isso é correto para orçamentos (banco local) e para o
	 * GA4 (defasagem de horas, janela terminando ontem), e é justamente o que NÃO vale
	 * para a Search Console — por isso ela não passa por aqui.
	 *
	 * @param array<string, int|float> $per_day Valores por 'Y-m-d'.
	 */
	function uonix_intelligence_executive_sum( $per_day, $window ) {
		if ( ! is_array( $per_day ) || ! isset( $window['start'], $window['end'] ) ) {
			return 0;
		}
		$total = 0;
		foreach ( $per_day as $dia => $valor ) {
			$dia = (string) $dia;
			if ( $dia >= (string) $window['start'] && $dia <= (string) $window['end'] ) {
				$total += (int) $valor;
			}
		}

		return $total;
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_binomial_p' ) ) {
	/**
	 * Valor-p bilateral EXATO de X = k sob X ~ Binomial(n, p0).
	 *
	 * É o teste padrão para comparar duas contagens de Poisson: condicionado ao total
	 * `n = a + b`, a primeira contagem segue Binomial(n, p0), com `p0` = fração da
	 * exposição que pertence à primeira. Para duas janelas de mesmo tamanho, p0 = 0,5.
	 * Para comparar taxas (orçamentos por visita), p0 = visitas₁ / (visitas₁ + visitas₂).
	 *
	 * Exato, e não a aproximação normal `|a − b| > 2√(a+b)`, porque o volume deste
	 * site é pequeno e a aproximação erra justamente ali. Exemplo: 0 contra 5 passa na
	 * aproximação (5 > 4,47), mas o valor-p exato é 0,0625 — não detectável a 5%.
	 *
	 * Bilateral por duplicação da cauda menor, limitado a 1. Calculado em espaço de
	 * logaritmo, para 0,5ⁿ não virar zero em n grande.
	 */
	function uonix_intelligence_executive_binomial_p( $k, $n, $p0 = 0.5 ) {
		$k  = (int) $k;
		$n  = (int) $n;
		$p0 = (float) $p0;
		if ( $n <= 0 || $k < 0 || $k > $n ) {
			return 1.0;
		}
		if ( $p0 <= 0.0 ) {
			return 0 === $k ? 1.0 : 0.0;
		}
		if ( $p0 >= 1.0 ) {
			return $n === $k ? 1.0 : 0.0;
		}

		$log_p   = log( $p0 );
		$log_q   = log( 1.0 - $p0 );
		$log_pmf = $n * $log_q;
		$inferior = 0.0;
		$superior = 0.0;
		for ( $i = 0; $i <= $n; $i++ ) {
			$pmf = exp( $log_pmf );
			if ( $i <= $k ) {
				$inferior += $pmf;
			}
			if ( $i >= $k ) {
				$superior += $pmf;
			}
			if ( $i < $n ) {
				$log_pmf += log( ( $n - $i ) / ( $i + 1 ) ) + $log_p - $log_q;
			}
		}

		return min( 1.0, 2.0 * min( $inferior, $superior ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_unavailable_box' ) ) {
	/**
	 * Caixa sem base para afirmar. Estado distinto de "zero" e de "sem mudança".
	 */
	function uonix_intelligence_executive_unavailable_box( $key, $reason ) {
		return array(
			'key'       => (string) $key,
			'available' => false,
			'reason'    => (string) $reason,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_leads_box' ) ) {
	/**
	 * Orçamentos: número absoluto, nunca variação percentual.
	 *
	 * Com ~1 orçamento por semana, "▲ +100%" é um orçamento a mais. A caixa mostra
	 * a semana em número absoluto e compara 28 dias contra os 28 anteriores pelo
	 * teste binomial exato, que responde a pergunta que interessa: a diferença é
	 * maior do que o acaso explica neste volume?
	 *
	 * @param array<string, int>|null $por_dia Orçamentos por dia, ou null sem tabela.
	 */
	function uonix_intelligence_executive_leads_box( $por_dia, $w, $alpha ) {
		if ( null === $por_dia ) {
			return uonix_intelligence_executive_unavailable_box( 'leads', 'submissions_table_missing' );
		}
		if ( ! isset( $w['week'], $w['month'], $w['prev_month'] ) ) {
			return uonix_intelligence_executive_unavailable_box( 'leads', 'windows_invalid' );
		}

		$semana   = uonix_intelligence_executive_sum( $por_dia, $w['week'] );
		$mes      = uonix_intelligence_executive_sum( $por_dia, $w['month'] );
		$anterior = uonix_intelligence_executive_sum( $por_dia, $w['prev_month'] );
		$p        = uonix_intelligence_executive_binomial_p( $mes, $mes + $anterior, 0.5 );
		$detecta  = ( $mes + $anterior ) > 0 && $p < (float) $alpha;

		return array(
			'key'            => 'leads',
			'available'      => true,
			'reason'         => '',
			'source'         => 'fluentform_submissions',
			'window'         => $w['week'],
			'current'        => $semana,
			'month'          => $mes,
			'prev_month'     => $anterior,
			'month_window'   => $w['month'],
			'p_value'        => $p,
			'detectable'     => $detecta,
			'direction'      => $detecta ? ( $mes > $anterior ? 'up' : 'down' ) : 'flat',
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_ga4_per_day' ) ) {
	/**
	 * Normaliza as linhas diárias do GA4 para 'Y-m-d' => sessões.
	 *
	 * O GA4 devolve a dimensão `date` como 'YYYYMMDD' e OMITE dia sem sessão. Omitir
	 * aqui é zero de verdade, porque a defasagem é de horas. `first_day` é o primeiro
	 * dia com dado e decide se existe histórico para comparar.
	 *
	 * @param array<int, array> $rows Saída de `uonix_analytics_metrics_ga4_rows( $r, 'date' )`.
	 * @return array{per_day: array<string, int>, first_day: string}
	 */
	function uonix_intelligence_executive_ga4_per_day( $rows ) {
		$por_dia  = array();
		$primeiro = '';
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['date'], $row['sessions'] ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^(\d{4})(\d{2})(\d{2})$/D', (string) $row['date'], $m ) || ! is_numeric( $row['sessions'] ) ) {
				continue;
			}
			$dia             = $m[1] . '-' . $m[2] . '-' . $m[3];
			$por_dia[ $dia ] = ( $por_dia[ $dia ] ?? 0 ) + (int) $row['sessions'];
			if ( '' === $primeiro || $dia < $primeiro ) {
				$primeiro = $dia;
			}
		}

		return array( 'per_day' => $por_dia, 'first_day' => $primeiro );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_history_covers' ) ) {
	/**
	 * Existe dado do GA4 ANTES do início desta janela?
	 *
	 * Sem isto, uma janela anterior que começou antes de o GA4 existir soma menos
	 * dias do que tem, e a comparação acusa um crescimento que é só a instalação.
	 * Medido em 2026-09-28: o snapshot de 30 dias gravava `sessions.previous = 0`,
	 * `state = new` — o GA4 deste site não tem dado antes de ~29/08.
	 *
	 * Exige dado ESTRITAMENTE antes do início. É conservador: se o GA4 tiver começado
	 * exatamente no primeiro dia da janela, a comparação é recusada. Recusar é o erro
	 * seguro; comparar contra janela truncada é o erro que mente.
	 */
	function uonix_intelligence_executive_history_covers( $ga4, $window ) {
		if ( ! is_array( $ga4 ) || ! isset( $ga4['first_day'], $window['start'] ) || '' === (string) $ga4['first_day'] ) {
			return false;
		}

		return (string) $ga4['first_day'] < (string) $window['start'];
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_visits_box' ) ) {
	/**
	 * Visitas da semana contra a semana anterior (GA4).
	 *
	 * São visitas COM consentimento. O site usa Consent Mode v2 via AdOpt, e sem
	 * consentimento de estatística o GA4 não registra a sessão nos relatórios. A
	 * variação semanal não é afetada se a taxa de consentimento for estável; o número
	 * absoluto, sim, e por isso a caixa declara.
	 *
	 * @param array|WP_Error|null $ga4 Saída de `uonix_intelligence_executive_ga4_per_day()`.
	 */
	function uonix_intelligence_executive_visits_box( $ga4, $w ) {
		if ( is_wp_error( $ga4 ) ) {
			return uonix_intelligence_executive_unavailable_box( 'visits', 'ga4_fetch_failed' );
		}
		if ( ! is_array( $ga4 ) || ! isset( $ga4['per_day'] ) || ! isset( $w['week'], $w['prev_week'] ) ) {
			return uonix_intelligence_executive_unavailable_box( 'visits', 'ga4_missing' );
		}

		$semana   = uonix_intelligence_executive_sum( $ga4['per_day'], $w['week'] );
		$anterior = uonix_intelligence_executive_sum( $ga4['per_day'], $w['prev_week'] );
		$coberto  = uonix_intelligence_executive_history_covers( $ga4, $w['prev_week'] );

		$delta = null;
		if ( $coberto && function_exists( 'uonix_analytics_metrics_compare' ) ) {
			$comparacao = uonix_analytics_metrics_compare( $semana, $anterior );
			if ( is_array( $comparacao ) && 'comparable' === ( $comparacao['state'] ?? '' ) ) {
				$delta = (float) $comparacao['delta_percent'];
			}
		}

		return array(
			'key'            => 'visits',
			'available'      => true,
			'reason'         => '',
			'source'         => 'ga4',
			'window'         => $w['week'],
			'compare_window' => $w['prev_week'],
			'current'        => $semana,
			'previous'       => $coberto ? $anterior : null,
			'delta_percent'  => $delta,
			'comparable'     => null !== $delta,
			'note'           => $coberto ? ( null === $delta ? 'previous_empty' : '' ) : 'no_history',
			'consented_only' => true,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_organic_box' ) ) {
	/**
	 * Impressões na busca, semana contra semana, a partir do Módulo 5.
	 *
	 * Reusa o achado de `uonix_intelligence_anomaly_organic_drop()` em vez de somar a
	 * série aqui: aquela função já tem as janelas assentadas e a conferência de
	 * completude que duas revisões independentes do PR #293 obrigaram a construir.
	 * Somar de novo neste arquivo seria reabrir os pontos cegos que lá foram fechados.
	 *
	 * Quando houve imputação de dias ausentes, a variação daquela função é um LIMITE
	 * construído para o alerta, não uma medição. Aqui a caixa mostra os números
	 * observados e recusa a porcentagem.
	 */
	function uonix_intelligence_executive_organic_box( $achado ) {
		if ( ! is_array( $achado ) ) {
			return uonix_intelligence_executive_unavailable_box( 'organic', 'organic_missing' );
		}
		if ( empty( $achado['available'] ) ) {
			return uonix_intelligence_executive_unavailable_box( 'organic', isset( $achado['reason'] ) ? (string) $achado['reason'] : 'organic_missing' );
		}

		$m = isset( $achado['measured'] ) && is_array( $achado['measured'] ) ? $achado['measured'] : array();
		$j = isset( $m['windows'] ) && is_array( $m['windows'] ) ? $m['windows'] : array();
		if ( ! isset( $j['current'], $j['previous'], $m['current'], $m['previous'] ) ) {
			return uonix_intelligence_executive_unavailable_box( 'organic', 'organic_missing' );
		}

		$colapso  = isset( $j['current_days'] ) && 0 === (int) $j['current_days'];
		$imputado = isset( $m['imputed_days'] ) ? (int) $m['imputed_days'] : 0;
		if ( $colapso ) {
			$delta = -100.0;
		} elseif ( 0 === $imputado && isset( $m['delta_percent'] ) ) {
			$delta = (float) $m['delta_percent'];
		} else {
			$delta = null;
		}

		return array(
			'key'            => 'organic',
			'available'      => true,
			'reason'         => '',
			'source'         => 'search_console',
			'window'         => $j['current'],
			'compare_window' => $j['previous'],
			'current'        => (float) $m['current'],
			'previous'       => (float) $m['previous'],
			'delta_percent'  => $delta,
			'comparable'     => null !== $delta,
			'note'           => $colapso ? 'collapse' : ( $imputado > 0 ? 'imputed' : '' ),
			'imputed_days'   => $imputado,
			'anomalous'      => ! empty( $achado['anomalous'] ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_conversion_box' ) ) {
	/**
	 * Orçamentos por visita, em 28 dias, como TETO.
	 *
	 * **A taxa exibida é maior que a real, e o sentido do erro é conhecido.** O
	 * numerador conta todos os orçamentos; o denominador só as visitas com
	 * consentimento de estatística (Consent Mode v2). Sem consentimento de todos, o
	 * denominador fica menor e a divisão, maior. O Google só estima as visitas não
	 * consentidas acima de um volume diário que este site não atinge.
	 *
	 * A comparação com os 28 dias anteriores usa o teste binomial exato com a
	 * exposição proporcional às visitas, e só acontece se o GA4 cobrir a janela
	 * anterior inteira. Em 2026-09-28 não cobre, e a caixa diz isso.
	 */
	function uonix_intelligence_executive_conversion_box( $por_dia_leads, $ga4, $w, $alpha ) {
		if ( null === $por_dia_leads ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', 'submissions_table_missing' );
		}
		if ( is_wp_error( $ga4 ) ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', 'ga4_fetch_failed' );
		}
		if ( ! is_array( $ga4 ) || ! isset( $ga4['per_day'] ) || ! isset( $w['month'], $w['prev_month'] ) ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', 'ga4_missing' );
		}

		$sessoes = uonix_intelligence_executive_sum( $ga4['per_day'], $w['month'] );
		$leads   = uonix_intelligence_executive_sum( $por_dia_leads, $w['month'] );
		if ( $sessoes <= 0 ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', 'no_sessions' );
		}

		$box = array(
			'key'            => 'conversion',
			'available'      => true,
			'reason'         => '',
			'source'         => 'ga4+fluentform_submissions',
			'window'         => $w['month'],
			'compare_window' => $w['prev_month'],
			'leads'          => $leads,
			'sessions'       => $sessoes,
			'rate'           => $leads / $sessoes,
			'upper_bound'    => true,
			'comparable'     => false,
			'note'           => 'no_history',
			'prev_rate'      => null,
			'p_value'        => null,
			'detectable'     => false,
			'direction'      => 'flat',
		);

		if ( ! uonix_intelligence_executive_history_covers( $ga4, $w['prev_month'] ) ) {
			return $box;
		}
		$sessoes_ant = uonix_intelligence_executive_sum( $ga4['per_day'], $w['prev_month'] );
		$leads_ant   = uonix_intelligence_executive_sum( $por_dia_leads, $w['prev_month'] );
		if ( $sessoes_ant <= 0 ) {
			$box['note'] = 'previous_empty';
			return $box;
		}

		$p       = uonix_intelligence_executive_binomial_p( $leads, $leads + $leads_ant, $sessoes / ( $sessoes + $sessoes_ant ) );
		$detecta = ( $leads + $leads_ant ) > 0 && $p < (float) $alpha;
		$taxa_ant = $leads_ant / $sessoes_ant;

		$box['comparable'] = true;
		$box['note']       = '';
		$box['prev_rate']  = $taxa_ant;
		$box['p_value']    = $p;
		$box['detectable'] = $detecta;
		$box['direction']  = $detecta ? ( $box['rate'] > $taxa_ant ? 'up' : 'down' ) : 'flat';

		return $box;
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_scorecard' ) ) {
	/**
	 * Junta as quatro caixas. Função pura: tudo entra por parâmetro.
	 *
	 * @param array $in `today`, `lead_counts`, `ga4`, `organic`.
	 */
	function uonix_intelligence_executive_scorecard( array $in ) {
		$regras = uonix_intelligence_executive_rules();
		$w      = uonix_intelligence_executive_windows( isset( $in['today'] ) ? (string) $in['today'] : '' );
		$leads  = array_key_exists( 'lead_counts', $in ) ? $in['lead_counts'] : null;
		$ga4    = array_key_exists( 'ga4', $in ) ? $in['ga4'] : null;
		$alpha  = (float) $regras['detect_alpha'];

		return array(
			'windows' => $w,
			'boxes'   => array(
				'leads'      => uonix_intelligence_executive_leads_box( $leads, $w, $alpha ),
				'visits'     => uonix_intelligence_executive_visits_box( $ga4, $w ),
				'organic'    => uonix_intelligence_executive_organic_box( isset( $in['organic'] ) ? $in['organic'] : null ),
				'conversion' => uonix_intelligence_executive_conversion_box( $leads, $ga4, $w, $alpha ),
			),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_num' ) ) {
	function uonix_intelligence_executive_num( $value, $decimals = 0 ) {
		return number_format( (float) $value, (int) $decimals, ',', '.' );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_insights' ) ) {
	/**
	 * Até três destaques, por regra determinística. Nunca enchimento.
	 *
	 * Determinístico por decisão do Cassio em 2026-09-28: mesmo dado, mesmo texto, e
	 * portanto testável. A integração com LLM é fatia separada na #193.
	 *
	 * Cada destaque só existe se o dado que o sustenta está disponível. Com dado de
	 * menos, saem menos de três — e isso é o correto: um destaque inventado para
	 * completar a lista é exatamente o texto que o contrato proíbe.
	 *
	 * Os destaques descrevem, não explicam. "A visibilidade cresceu mais que a
	 * demanda" é uma constatação sobre dois números; "porque o título está ruim" seria
	 * causa, e nada aqui a mede.
	 *
	 * O limiar de impressões reusa `organic_drop_percent` do Módulo 5, para as duas
	 * superfícies nunca discordarem sobre o que é uma variação relevante.
	 *
	 * @return array<int, array{kind: string, text: string}>
	 */
	function uonix_intelligence_executive_insights( $scorecard, $seo = null ) {
		$caixas = isset( $scorecard['boxes'] ) && is_array( $scorecard['boxes'] ) ? $scorecard['boxes'] : array();
		$saida  = array();

		$leads = isset( $caixas['leads'] ) ? $caixas['leads'] : array();
		if ( ! empty( $leads['available'] ) ) {
			$a = (int) $leads['month'];
			$b = (int) $leads['prev_month'];
			if ( 'up' === $leads['direction'] ) {
				$texto = sprintf( 'Orçamentos subiram de forma detectável: %d nas últimas 4 semanas, contra %d nas 4 anteriores.', $a, $b );
			} elseif ( 'down' === $leads['direction'] ) {
				$texto = sprintf( 'Orçamentos caíram de forma detectável: %d nas últimas 4 semanas, contra %d nas 4 anteriores.', $a, $b );
			} else {
				$texto = sprintf( '%d orçamento(s) nas últimas 4 semanas, contra %d nas 4 anteriores: sem mudança detectável, porque com este volume a diferença cabe no acaso.', $a, $b );
			}
			$saida[] = array( 'kind' => 'leads', 'text' => $texto );
		}

		$org = isset( $caixas['organic'] ) ? $caixas['organic'] : array();
		if ( ! empty( $org['available'] ) ) {
			$limiar = function_exists( 'uonix_intelligence_anomaly_rules' )
				? (float) uonix_intelligence_anomaly_rules()['organic_drop_percent']
				: 35.0;
			$texto = '';
			if ( 'collapse' === ( $org['note'] ?? '' ) ) {
				$texto = 'Nenhuma impressão na busca na última semana com dado assentado. Veja a aba Anomalias.';
			} elseif ( ! empty( $org['comparable'] ) ) {
				$d = (float) $org['delta_percent'];
				$p = uonix_intelligence_executive_num( abs( $d ), 1 );
				if ( $d <= -$limiar ) {
					$texto = sprintf( 'Impressões na busca caíram %s%% na semana. Veja a aba Anomalias.', $p );
				} elseif ( $d >= $limiar ) {
					$sem_demanda = ! empty( $leads['available'] ) && 'up' !== $leads['direction'];
					$texto = $sem_demanda
						? sprintf( 'Impressões na busca subiram %s%% na semana, sem aumento detectável de orçamentos nas últimas 4 semanas: a visibilidade cresceu mais que a demanda.', $p )
						: sprintf( 'Impressões na busca subiram %s%% na semana.', $p );
				} else {
					$texto = sprintf( 'Impressões na busca variaram %s%s%% na semana, abaixo do limiar de %s%% que o Alerta de Anomalias trata como relevante.', $d < 0 ? '−' : '+', $p, uonix_intelligence_executive_num( $limiar, 0 ) );
				}
			}
			if ( '' !== $texto ) {
				$saida[] = array( 'kind' => 'organic', 'text' => $texto );
			}
		}

		$linhas = is_array( $seo ) && ! empty( $seo['available'] ) && isset( $seo['rows'] ) && is_array( $seo['rows'] ) ? $seo['rows'] : array();
		if ( isset( $linhas[0] ) && is_array( $linhas[0] ) && isset( $linhas[0]['query'], $linhas[0]['impressions'], $linhas[0]['position'] ) ) {
			$r      = $linhas[0];
			$cliques = isset( $r['clicks'] ) ? (float) $r['clicks'] : 0.0;
			$texto  = sprintf(
				'Maior ganho fácil na busca: “%s”, com %s impressões na posição %s e %s.',
				(string) $r['query'],
				uonix_intelligence_executive_num( $r['impressions'], 0 ),
				uonix_intelligence_executive_num( $r['position'], 1 ),
				$cliques > 0 ? 'taxa de clique de ' . uonix_intelligence_executive_num( (float) ( $r['ctr'] ?? 0 ) * 100, 1 ) . '%' : 'nenhum clique'
			);
			if ( isset( $r['suggestion'] ) && is_array( $r['suggestion'] ) && array() !== $r['suggestion'] ) {
				$texto .= ' Acrescentar ao título: ' . implode( ' · ', array_map( 'strval', $r['suggestion'] ) ) . '.';
			}
			$saida[] = array( 'kind' => 'seo', 'text' => $texto );
		}

		return array_slice( $saida, 0, 3 );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_page_label' ) ) {
	/**
	 * Rótulo legível de uma página: o título do post, ou o próprio caminho.
	 *
	 * Devolve texto puro. Quem imprime escapa.
	 */
	function uonix_intelligence_executive_page_label( $path ) {
		$path = (string) $path;
		if ( '/' === $path || '' === $path ) {
			return 'Página inicial';
		}
		if ( function_exists( 'url_to_postid' ) && function_exists( 'home_url' ) && function_exists( 'get_the_title' ) ) {
			$id = (int) url_to_postid( home_url( $path ) );
			if ( $id > 0 ) {
				$titulo = trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $titulo ) {
					return $titulo;
				}
			}
		}

		return $path;
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_top_pages' ) ) {
	/**
	 * Páginas mais encontradas na busca (pilar 4), do snapshot de 30 dias.
	 *
	 * Ordena por impressões, não por cliques: o pilar pergunta o que é mais
	 * procurado, e impressão é o que a Search Console mede de procura.
	 *
	 * Páginas, e não consultas. As consultas de volume alto fora da faixa de
	 * oportunidade têm o texto minimizado no snapshot (#278), então uma lista das mais
	 * buscadas por consulta ficaria com buracos justamente nas maiores.
	 *
	 * Não há comparação aqui, só ranking, e é por isso que o snapshot serve. A
	 * defasagem da Search Console tira os últimos ~3 dias da janela: isso muda os
	 * totais e pode trocar de lugar páginas com volume próximo. O bloco declara a
	 * janela e não afirma variação nenhuma, então nenhum número dele depende dos dias
	 * ausentes serem zero.
	 */
	function uonix_intelligence_executive_top_pages( $snapshot, $limit = null, $labeler = null ) {
		$limit   = is_int( $limit ) && $limit > 0 ? $limit : (int) uonix_intelligence_executive_rules()['top_pages'];
		$labeler = is_callable( $labeler ) ? $labeler : 'uonix_intelligence_executive_page_label';

		if ( ! is_array( $snapshot ) || ! isset( $snapshot['search_console']['pages'] ) || ! is_array( $snapshot['search_console']['pages'] ) ) {
			return array( 'available' => false, 'reason' => 'snapshot_missing', 'rows' => array() );
		}

		$linhas = array();
		foreach ( $snapshot['search_console']['pages'] as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['page'], $p['impressions'] ) || '' === (string) $p['page'] ) {
				continue;
			}
			$linhas[] = array(
				'path'        => (string) $p['page'],
				'impressions' => (float) $p['impressions'],
				'clicks'      => isset( $p['clicks'] ) ? (float) $p['clicks'] : 0.0,
			);
		}
		usort(
			$linhas,
			static function ( $a, $b ) {
				if ( $a['impressions'] === $b['impressions'] ) {
					return strcmp( $a['path'], $b['path'] );
				}
				return $a['impressions'] < $b['impressions'] ? 1 : -1;
			}
		);
		$linhas = array_slice( $linhas, 0, $limit );
		foreach ( $linhas as $i => $l ) {
			$linhas[ $i ]['label'] = (string) call_user_func( $labeler, $l['path'] );
		}

		$periodo = isset( $snapshot['periods']['current'] ) && is_array( $snapshot['periods']['current'] ) ? $snapshot['periods']['current'] : array();

		return array(
			'available' => array() !== $linhas,
			'reason'    => array() === $linhas ? 'pages_empty' : '',
			'rows'      => $linhas,
			'window'    => $periodo,
			'synced_at' => isset( $snapshot['updated_at'] ) ? (string) $snapshot['updated_at'] : '',
			'stale'     => function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' ) ? ! uonix_analytics_metrics_snapshot_is_fresh( $snapshot ) : true,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_fetch_ga4_daily' ) ) {
	/**
	 * Série diária de sessões do GA4. Reusa o buscador e o decodificador de 53.
	 *
	 * @return array<int, array>|WP_Error
	 */
	function uonix_intelligence_executive_fetch_ga4_daily( $config, $period ) {
		if ( ! function_exists( 'uonix_analytics_metrics_get_access_token' ) || ! function_exists( 'uonix_analytics_metrics_ga4_report' ) ) {
			return uonix_analytics_metrics_error( 'analytics_layer_missing' );
		}
		$token = uonix_analytics_metrics_get_access_token( $config );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$bruto = uonix_analytics_metrics_ga4_report(
			isset( $config['ga4_property_id'] ) ? (string) $config['ga4_property_id'] : '',
			$token,
			$period,
			array( array( 'name' => 'date' ) ),
			400
		);
		if ( is_wp_error( $bruto ) ) {
			return $bruto;
		}
		$decodificado = uonix_analytics_metrics_decode_ga4_report( $bruto, true );
		if ( is_wp_error( $decodificado ) ) {
			return $decodificado;
		}

		return uonix_analytics_metrics_ga4_rows( $decodificado, 'date' );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_collect' ) ) {
	/**
	 * Reúne as entradas reais e devolve o que o e-mail renderiza.
	 *
	 * Duas chamadas de rede: a série diária do GA4 e a série diária da Search
	 * Console (esta via `uonix_intelligence_anomaly_organic_drop()`). O relatório sai
	 * uma vez por semana, então o custo é irrelevante; o que importa é que falha de
	 * rede degrada a caixa para "indisponível" e o e-mail sai mesmo assim.
	 *
	 * Tudo é injetável por `$args`, do mesmo jeito que `uonix_analytics_metrics_sync()`
	 * aceita um fetcher, para o teste exercitar o caminho inteiro sem rede.
	 */
	function uonix_intelligence_executive_collect( $args = array() ) {
		$args   = is_array( $args ) ? $args : array();
		$regras = uonix_intelligence_executive_rules();

		$hoje = isset( $args['today'] ) && is_string( $args['today'] ) && '' !== $args['today']
			? $args['today']
			: ( function_exists( 'uonix_intelligence_anomaly_now' ) ? uonix_intelligence_anomaly_now()->format( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		$w = uonix_intelligence_executive_windows( $hoje );

		if ( array_key_exists( 'lead_counts', $args ) ) {
			$leads = $args['lead_counts'];
		} else {
			$leads = function_exists( 'uonix_intelligence_anomaly_lead_daily_counts' )
				? uonix_intelligence_anomaly_lead_daily_counts( (int) $regras['lead_days_read'], $hoje )
				: null;
		}

		$config = array_key_exists( 'config', $args )
			? $args['config']
			: ( function_exists( 'uonix_analytics_metrics_get_config' ) ? uonix_analytics_metrics_get_config() : null );
		$config = is_array( $config ) ? $config : null;

		if ( null === $config || ! isset( $w['prev_month'], $w['week'] ) ) {
			$ga4 = uonix_analytics_metrics_error( 'config_missing' );
		} else {
			// O início fica `ga4_buffer` dias antes da janela mais antiga, para
			// `history_covers()` enxergar se já havia dado antes dela.
			$inicio = DateTimeImmutable::createFromFormat( '!Y-m-d', $w['prev_month']['start'], new DateTimeZone( 'UTC' ) )
				->modify( '-' . (int) $regras['ga4_buffer'] . ' days' )
				->format( 'Y-m-d' );
			$fetcher = isset( $args['ga4_fetcher'] ) && is_callable( $args['ga4_fetcher'] ) ? $args['ga4_fetcher'] : 'uonix_intelligence_executive_fetch_ga4_daily';
			$linhas  = call_user_func( $fetcher, $config, array( 'start' => $inicio, 'end' => $w['week']['end'] ) );
			$ga4     = is_wp_error( $linhas ) ? $linhas : uonix_intelligence_executive_ga4_per_day( $linhas );
		}

		$organico = function_exists( 'uonix_intelligence_anomaly_organic_drop' )
			? uonix_intelligence_anomaly_organic_drop( isset( $args['gsc_fetcher'] ) ? $args['gsc_fetcher'] : null, $config, $hoje )
			: null;

		$placar = uonix_intelligence_executive_scorecard(
			array(
				'today'       => $hoje,
				'lead_counts' => $leads,
				'ga4'         => $ga4,
				'organic'     => $organico,
			)
		);

		$snapshot = array_key_exists( 'snapshot', $args )
			? $args['snapshot']
			: ( function_exists( 'uonix_analytics_metrics_get_snapshot' ) ? uonix_analytics_metrics_get_snapshot( 30 ) : false );

		return array(
			'scorecard'    => $placar,
			'insights'     => uonix_intelligence_executive_insights( $placar, isset( $args['seo'] ) ? $args['seo'] : null ),
			'top_pages'    => uonix_intelligence_executive_top_pages( $snapshot, null, isset( $args['labeler'] ) ? $args['labeler'] : null ),
			'generated_on' => $hoje,
		);
	}
}
