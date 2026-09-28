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
	 * decide nada. `page_candidates` = 10 é quantas páginas têm o status HTTP
	 * conferido de saída — o dobro do que se exibe, porque cada redirecionamento somado
	 * libera uma posição.
	 *
	 * `head_max` = 20 é o teto RÍGIDO de requisições HEAD por execução, contando cada
	 * salto de cadeia e cada endereço que chega ao topo depois da soma. Não é "~10": a
	 * primeira versão do contrato dizia isso, e a revisão do PR #301 mediu que o teto
	 * real era 15. Com o teto explícito, o número é o que está escrito aqui.
	 *
	 * `head_budget` = 20 s é o orçamento de tempo, conferido ANTES de cada requisição.
	 * Por isso o tempo total pode passar dele por até um `head_timeout` (8 s): ~28 s no
	 * pior caso. O motivo NÃO é o `max_execution_time` do PHP — no Linux ele conta
	 * tempo de CPU e não espera de rede. O limite de relógio real vem do servidor web e
	 * do PHP-FPM, e não foi medido. O orçamento existe para o botão "Enviar Teste Agora"
	 * não depender de um limite que ninguém mediu. As três chamadas ao Google, com os
	 * seus três pedidos de token, têm timeout de 20 s cada em `53` e somam até 120 s no
	 * pior caso, fora deste orçamento.
	 *
	 * `max_hops` = 3: medido pela revisão do PR #301, nenhum dos 12 endereços de
	 * produção conferidos passa de um salto. Três é margem; acima disso, o endereço fica
	 * como "não verificado" em vez de ser somado a um destino incerto.
	 *
	 * `pages_rows` = 1000. A API ordena por CLIQUES, então se o universo de páginas
	 * passasse do limite o corte voltaria a ser por cliques e o defeito que a busca
	 * própria corrigiu reapareceria na cauda. A revisão mediu 78 páginas em 30 dias; o
	 * bloco sinaliza se o limite for atingido.
	 */
	function uonix_intelligence_executive_rules() {
		return array(
			'detect_alpha'    => 0.05,
			'lead_days_read'  => 60,
			'ga4_buffer'      => 7,
			'top_pages'       => 5,
			'page_candidates' => 10,
			'pages_days'      => 28,
			'pages_rows'      => 1000,
			'head_timeout'    => 8,
			'head_max'        => 20,
			'head_budget'     => 20.0,
			'max_hops'        => 3,
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
	 * Para os orçamentos, ontem é exato: vêm do banco local, sem defasagem nenhuma.
	 *
	 * Para o GA4, o que foi MEDIDO em 2026-09-28 é que às 13:00 já havia dado parcial
	 * do próprio dia 28/09 — o dado intradiário chega em horas. **Não foi medido que o
	 * dia anterior esteja fechado às 08:00 de segunda**, quando o relatório sai: o
	 * processamento diário do GA4 pode seguir refinando o dia por mais algumas horas. O
	 * dia afetado é o domingo, e o tamanho do erro possível foi medido no mesmo dia: o
	 * domingo 27/09 teve 2 das 65 visitas da semana (~3%). Então o viés possível na
	 * variação semanal de visitas é dessa ordem, para baixo. Aceitável para um digest,
	 * e declarado aqui para ninguém ler a caixa como exata.
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
	 * Bilateral por **duplicação da cauda menor**, limitado a 1. Para p0 ≠ 0,5 existe
	 * outra convenção — somar as probabilidades menores ou iguais à observada, que é o
	 * padrão do `binom.test` do R — e as duas divergem: para 3 em 3 com p0 = 0,2, esta
	 * dá 0,016 e aquela dá 0,008. A duplicação é mais conservadora (cala onde a outra
	 * dispararia), e a revisão do PR #301 mediu que o tamanho real deste teste nunca
	 * passa de 5% na grade n ≤ 80, p0 ∈ [0,02; 0,98]. Perde poder, não inventa sinal —
	 * a direção certa para um relatório que não pode afirmar mudança que não houve.
	 *
	 * Calculado em espaço de logaritmo, para 0,5ⁿ não virar zero em n grande.
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

if ( ! function_exists( 'uonix_intelligence_executive_ga4_error_reason' ) ) {
	/**
	 * Motivo de um erro do GA4. Sem credencial não é "a consulta falhou": nenhuma
	 * consulta foi feita, e o e-mail de um ambiente sem credencial não pode dizer que
	 * foi.
	 */
	function uonix_intelligence_executive_ga4_error_reason( $erro ) {
		return is_wp_error( $erro ) && 'config_missing' === $erro->get_error_code() ? 'config_missing' : 'ga4_fetch_failed';
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
			return uonix_intelligence_executive_unavailable_box( 'visits', uonix_intelligence_executive_ga4_error_reason( $ga4 ) );
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
	 * **A porcentagem só é exibida com as DUAS semanas completas.** A versão anterior
	 * olhava apenas `imputed_days`, e a revisão do PR #301 mediu o dano: esse campo
	 * conta só os dias ausentes da semana ATUAL. Na semana anterior, o Módulo 5 trata
	 * dia ausente como zero — conservador para detectar QUEDA, que é o trabalho dele,
	 * e errado para exibir variação nos dois sentidos. Com tráfego constante e dois dias
	 * ausentes na semana anterior, o e-mail diria "▲ +40%"; com queda real de −35% e
	 * três dias ausentes, diria "+13,8%, abaixo do limiar".
	 *
	 * Então: dia ausente em qualquer das duas semanas recusa a porcentagem, e a caixa
	 * mostra os números observados e diz qual semana está incompleta. O colapso é a
	 * exceção deliberada: semana atual sem nenhuma impressão é −100% contra qualquer
	 * semana anterior com volume, esteja ela completa ou não.
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

		$janela       = isset( $j['window_days'] ) ? (int) $j['window_days'] : 7;
		$colapso      = isset( $j['current_days'] ) && 0 === (int) $j['current_days'];
		$faltam_atual = isset( $j['current_days'] ) ? max( 0, $janela - (int) $j['current_days'] ) : $janela;
		$faltam_ant   = isset( $j['previous_days'] ) ? max( 0, $janela - (int) $j['previous_days'] ) : $janela;
		if ( $colapso ) {
			$delta = -100.0;
		} elseif ( 0 === $faltam_atual && 0 === $faltam_ant && isset( $m['delta_percent'] ) ) {
			$delta = (float) $m['delta_percent'];
		} else {
			$delta = null;
		}
		if ( $colapso ) {
			$nota = 'collapse';
		} elseif ( $faltam_atual > 0 ) {
			$nota = 'imputed';
		} elseif ( $faltam_ant > 0 ) {
			$nota = 'previous_incomplete';
		} else {
			$nota = '';
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
			'note'           => $nota,
			'imputed_days'   => $faltam_atual,
			'missing_prev'   => $faltam_ant,
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
	 *
	 * **Limitação que o teste não resolve:** a exposição também são visitas
	 * CONSENTIDAS. Se a taxa de aceite do banner da AdOpt mudar entre os dois
	 * períodos, o denominador muda sem que a conversão real mude, e o teste lê isso
	 * como mudança de conversão. As duas coisas são indistinguíveis com este dado.
	 */
	function uonix_intelligence_executive_conversion_box( $por_dia_leads, $ga4, $w, $alpha ) {
		if ( null === $por_dia_leads ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', 'submissions_table_missing' );
		}
		if ( is_wp_error( $ga4 ) ) {
			return uonix_intelligence_executive_unavailable_box( 'conversion', uonix_intelligence_executive_ga4_error_reason( $ga4 ) );
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

if ( ! function_exists( 'uonix_intelligence_executive_plural' ) ) {
	/**
	 * "1 orçamento", "0 orçamentos", "4 orçamentos". Texto para diretoria não leva "(s)".
	 */
	function uonix_intelligence_executive_plural( $n, $singular, $plural ) {
		return (int) $n . ' ' . ( 1 === (int) $n ? $singular : $plural );
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
				$texto = sprintf( '%s nas últimas 4 semanas, contra %d nas 4 anteriores: sem mudança detectável, porque com este volume a diferença cabe no acaso.', uonix_intelligence_executive_plural( $a, 'orçamento', 'orçamentos' ), $b );
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
					// Três ramos, e não dois. Com queda detectável de orçamentos, dizer "sem
					// aumento detectável" é verdade e soa como contradição logo depois do
					// destaque que diz que eles caíram (achado da revisão do PR #301).
					$dir_leads = ! empty( $leads['available'] ) ? (string) $leads['direction'] : '';
					if ( 'down' === $dir_leads ) {
						$texto = sprintf( 'Impressões na busca subiram %s%% na semana, enquanto os orçamentos caíram de forma detectável nas últimas 4 semanas: mais visibilidade não virou demanda.', $p );
					} elseif ( 'flat' === $dir_leads ) {
						$texto = sprintf( 'Impressões na busca subiram %s%% na semana, sem aumento detectável de orçamentos nas últimas 4 semanas: a visibilidade cresceu mais que a demanda.', $p );
					} else {
						$texto = sprintf( 'Impressões na busca subiram %s%% na semana.', $p );
					}
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

if ( ! function_exists( 'uonix_intelligence_executive_page_status' ) ) {
	/**
	 * Status HTTP de uma página do próprio site, sem seguir redirecionamento.
	 *
	 * Existe por um defeito que a revisão do PR #301 mediu: das cinco páginas que o
	 * bloco exibia, uma dava **404** (`/olhal-de-ancoragem/`, 327 impressões) e duas
	 * eram **301** para `/servico/...`. O bloco as apresentava como páginas do site,
	 * e os 301 dividiam as impressões entre o endereço antigo e o novo.
	 *
	 * Consulta HTTP, e não o banco, porque redirecionamento pode vir do Rank Math, do
	 * `.htaccess` ou do próprio WordPress, e só a resposta HTTP conhece os três.
	 *
	 * Falha de rede devolve `unknown`, nunca `ok`: não saber o status não é saber que
	 * a página existe.
	 *
	 * **O limite de 8 s por requisição**, e o argumento que o sustenta. Medido em
	 * produção em 2026-09-28: o loopback funciona na Locaweb, e os tempos variam muito
	 * — raiz em 133 ms, páginas de serviço em ~80 ms (cache), 301 em 2,2 s, 404 em
	 * 4,2 s; e a revisão do PR #301 mediu um 301 frio em 6,81 s. Com esses números, 8 s
	 * não é margem folgada, e nenhum número fixo seria. O que o torna defensável é o
	 * modo de falha: estourar o limite dá `unknown` — "status não verificado" —, nunca
	 * `ok`. O limite troca completude por tempo; não troca verdade por tempo.
	 *
	 * O orçamento de tempo e o teto de consultas moram em
	 * `uonix_intelligence_executive_top_pages()`, que é quem chama esta função em laço.
	 * Aqui não há estado: a versão anterior guardava o tempo gasto numa variável
	 * `static`, e uma segunda chamada no mesmo processo devolvia "não verificado" sem
	 * fazer requisição nenhuma.
	 *
	 * O User-Agent identificável existe porque a requisição passa pelo Rank Math: ela
	 * conta como acesso no contador do redirecionamento e, com o monitor de 404 ligado,
	 * registra um 404 por semana. Quem olhar esses contadores precisa conseguir separar
	 * este acesso dos visitantes (achado BAIXO da revisão do PR #301).
	 *
	 * @return array{state: string, code: int, location: string}
	 */
	function uonix_intelligence_executive_page_status( $path ) {
		if ( ! function_exists( 'wp_remote_head' ) || ! function_exists( 'home_url' ) ) {
			return array( 'state' => 'unknown', 'code' => 0, 'location' => '' );
		}
		$resposta = wp_remote_head(
			home_url( (string) $path ),
			array(
				'redirection' => 0,
				'timeout'     => (int) uonix_intelligence_executive_rules()['head_timeout'],
				'user-agent'  => 'Uonix-Relatorio-Executivo/1.0 (conferencia semanal de status de pagina)',
			)
		);
		if ( is_wp_error( $resposta ) ) {
			return array( 'state' => 'unknown', 'code' => 0, 'location' => '' );
		}
		$codigo = (int) wp_remote_retrieve_response_code( $resposta );
		$destino = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $resposta, 'location' ) : '';

		if ( $codigo >= 200 && $codigo < 300 ) {
			return array( 'state' => 'ok', 'code' => $codigo, 'location' => '' );
		}
		if ( in_array( $codigo, array( 301, 302, 307, 308 ), true ) && '' !== $destino ) {
			return array( 'state' => 'redirect', 'code' => $codigo, 'location' => $destino );
		}
		if ( 404 === $codigo || 410 === $codigo ) {
			return array( 'state' => 'not_found', 'code' => $codigo, 'location' => '' );
		}

		return array( 'state' => 'unknown', 'code' => $codigo, 'location' => '' );
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_top_pages' ) ) {
	/**
	 * Páginas mais encontradas na busca (pilar 4), com o status de cada uma conferido.
	 *
	 * Ordena por impressões, que é o que a Search Console mede de procura. Páginas, e
	 * não consultas, porque o pilar 4 pergunta pelos produtos e serviços mais
	 * procurados — e página é a unidade de produto e serviço no site. As consultas já
	 * aparecem no bloco de oportunidades de SEO.
	 *
	 * **A lista vem de uma busca própria, não do snapshot.** O snapshot guarda só as 10
	 * páginas de mais CLIQUES, porque a API ordena por cliques; reordenar essas 10 por
	 * impressões perdia justamente as páginas de muita impressão e pouco clique. A
	 * revisão do PR #301 mediu que a quinta posição daquele dia dependia disso por 9
	 * impressões.
	 *
	 * As `$candidates` páginas de mais impressão têm o status conferido, e cada
	 * redirecionamento é seguido até o fim da cadeia (até `max_hops` saltos):
	 *
	 * - **ok** — entra com o título do post;
	 * - **redirect** — as impressões e cliques são SOMADOS ao destino final, porque o
	 *   Google ainda contabiliza o endereço antigo e o visitante chega no novo;
	 * - **not_found** — entra marcada. Página que o Google mostra e não existe é
	 *   informação que o executivo precisa ver, não esconder;
	 * - **unknown** — entra marcada como "status não verificado". Inclui ciclo,
	 *   redirecionamento para fora do site, saltos demais e teto de consultas atingido.
	 *
	 * Função pura: linhas, conferência de status e rótulo entram por parâmetro. Devolve
	 * também `head_count`, o número de conferências feitas, para o teto ser verificável.
	 *
	 * @param array<int, array{page: string, impressions: float, clicks: float}>|WP_Error|null $rows
	 */
	function uonix_intelligence_executive_top_pages( $rows, $window, $status_fetcher = null, $labeler = null, $limit = null, $candidates = null ) {
		$regras     = uonix_intelligence_executive_rules();
		$limit      = is_int( $limit ) && $limit > 0 ? $limit : (int) $regras['top_pages'];
		$candidates = is_int( $candidates ) && $candidates > 0 ? $candidates : (int) $regras['page_candidates'];
		$status_fn  = is_callable( $status_fetcher ) ? $status_fetcher : 'uonix_intelligence_executive_page_status';
		$labeler    = is_callable( $labeler ) ? $labeler : 'uonix_intelligence_executive_page_label';

		$base = array( 'available' => false, 'reason' => '', 'rows' => array(), 'window' => is_array( $window ) ? $window : array(), 'truncated' => false, 'head_count' => 0 );
		if ( is_wp_error( $rows ) ) {
			$base['reason'] = 'pages_fetch_failed';
			return $base;
		}
		// O formato de `uonix_intelligence_executive_fetch_gsc_pages()`, que sabe se a
		// resposta veio cortada; ou uma lista simples de linhas.
		if ( is_array( $rows ) && isset( $rows['rows'] ) && is_array( $rows['rows'] ) ) {
			$base['truncated'] = ! empty( $rows['truncated'] );
			$rows              = $rows['rows'];
		}
		if ( ! is_array( $rows ) ) {
			$base['reason'] = 'pages_missing';
			return $base;
		}

		// Todas as páginas devolvidas, indexadas por caminho. É daqui que sai o volume
		// próprio do destino de um redirecionamento, mesmo que ele não esteja entre as
		// candidatas.
		$por_caminho = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['page'], $r['impressions'] ) ) {
				continue;
			}
			$caminho = (string) $r['page'];
			if ( '' === $caminho ) {
				continue;
			}
			if ( ! isset( $por_caminho[ $caminho ] ) ) {
				$por_caminho[ $caminho ] = array( 'path' => $caminho, 'impressions' => 0.0, 'clicks' => 0.0, 'state' => 'unchecked', 'merged' => 0 );
			}
			$por_caminho[ $caminho ]['impressions'] += (float) $r['impressions'];
			$por_caminho[ $caminho ]['clicks']      += isset( $r['clicks'] ) ? (float) $r['clicks'] : 0.0;
		}

		$ordenar = static function ( array $lista ) {
			usort(
				$lista,
				static function ( $a, $b ) {
					if ( $a['impressions'] === $b['impressions'] ) {
						return strcmp( $a['path'], $b['path'] );
					}
					return $a['impressions'] < $b['impressions'] ? 1 : -1;
				}
			);
			return $lista;
		};

		// ---- Consulta de status: memória, teto de consultas e orçamento de tempo. ----
		//
		// Estado LOCAL, e não `static`: cada chamada desta função começa do zero.
		$memo_status = array();
		$consultas   = 0;
		$gasto       = 0.0;
		$consultar   = static function ( $caminho ) use ( &$memo_status, &$consultas, &$gasto, $status_fn, $regras ) {
			if ( isset( $memo_status[ $caminho ] ) ) {
				return $memo_status[ $caminho ];
			}
			if ( $consultas >= (int) $regras['head_max'] || $gasto >= (float) $regras['head_budget'] ) {
				return array( 'state' => 'unknown', 'code' => 0, 'location' => '' );
			}
			++$consultas;
			$inicio = microtime( true );
			$status = call_user_func( $status_fn, $caminho );
			$gasto += microtime( true ) - $inicio;
			$status = is_array( $status ) ? $status : array( 'state' => 'unknown', 'code' => 0, 'location' => '' );
			$memo_status[ $caminho ] = $status;
			return $status;
		};

		$destino_de = static function ( $location ) {
			$location = (string) $location;
			$destino  = function_exists( 'uonix_analytics_metrics_normalize_path' ) ? uonix_analytics_metrics_normalize_path( $location ) : '';
			// `Location` apontando para a raiz sem caminho normaliza para vazio; sem isto,
			// o redirecionamento para a página inicial não seria somado a ela.
			if ( '' === $destino && 1 === preg_match( '#^https?://(www\.)?uonix\.com\.br/?$#iD', $location ) ) {
				$destino = '/';
			}
			return $destino;
		};

		// ---- Fase 1: seguir cada cadeia até o FIM, antes de somar qualquer coisa. ----
		//
		// A versão anterior somava durante o laço, e o resultado dependia da ordem. A
		// revisão do PR #301 rodou `/b` (300) → `/c` e `/a` (100) → `/b`: o e-mail
		// mostrava `/c` com 350 e, embaixo, `/b` com 100 — a mesma cadeia partida em
		// duas linhas, e o comentário da época afirmava que a soma "seguia junto até o
		// destino final". Resolvendo primeiro e somando depois, a ordem deixa de existir.
		//
		// `terminal` separa quem chegou a um fim conhecido de quem não chegou (ciclo,
		// destino fora do site, saltos demais). Quem não chegou não é somado a nada e
		// fica como "não verificado": somar a um destino incerto seria inventar.
		$resolvido = array();
		$resolver  = static function ( $caminho ) use ( &$resolvido, $consultar, $destino_de, $regras ) {
			if ( isset( $resolvido[ $caminho ] ) ) {
				return $resolvido[ $caminho ];
			}
			$cadeia    = array( $caminho );
			$atual     = $caminho;
			$resultado = null;
			for ( $salto = 0; $salto <= (int) $regras['max_hops']; $salto++ ) {
				if ( $atual !== $caminho && isset( $resolvido[ $atual ] ) ) {
					// Trecho já resolvido por outra cadeia: reusa, se ele chegou a um fim.
					$resultado = $resolvido[ $atual ]['terminal'] ? $resolvido[ $atual ] : null;
					break;
				}
				$status = $consultar( $atual );
				$estado = isset( $status['state'] ) ? (string) $status['state'] : 'unknown';
				if ( 'redirect' === $estado ) {
					$destino = $destino_de( isset( $status['location'] ) ? $status['location'] : '' );
					if ( '' === $destino || in_array( $destino, $cadeia, true ) ) {
						break; // fora do site, ou ciclo
					}
					$cadeia[] = $destino;
					$atual    = $destino;
					continue;
				}
				// Parou num nó com status próprio: ok, 404, ou desconhecido. Em todos, o
				// endereço de partida chega ali, e é ali que a soma cai.
				$resultado = array(
					'final'    => $atual,
					'state'    => in_array( $estado, array( 'ok', 'not_found' ), true ) ? $estado : 'unknown',
					'terminal' => true,
				);
				break;
			}
			// Saltos demais: só o endereço de partida fica sem fim. O último nó nem foi
			// consultado, e os intermediários, partindo deles mesmos, cabem no limite — marcá-
			// los aqui seria dar "não verificado" a quem tem resposta.
			if ( null === $resultado && $salto > (int) $regras['max_hops'] ) {
				$cadeia = array( $caminho );
			}
			// Com fim conhecido, todo nó da cadeia aponta para ele. Sem fim — ciclo ou
			// destino fora do site —, nenhum nó da cadeia chega a lugar nenhum: cada um fica
			// por conta própria, como "não verificado", e nada é somado.
			foreach ( $cadeia as $no ) {
				if ( null !== $resultado ) {
					$resolvido[ $no ] = $resultado;
				} elseif ( ! isset( $resolvido[ $no ] ) ) {
					$resolvido[ $no ] = array( 'final' => $no, 'state' => 'unknown', 'terminal' => false );
				}
			}
			return $resolvido[ $caminho ];
		};

		foreach ( array_slice( $ordenar( array_values( $por_caminho ) ), 0, $candidates ) as $c ) {
			$resolver( $c['path'] );
		}

		// ---- Fase 2: somar cada endereço no destino final da própria cadeia. ----
		//
		// A partir dos valores ORIGINAIS de `$por_caminho`, que não mudam: por isso a
		// ordem de processamento não altera o resultado. `merged` conta endereços
		// originais somados, então um ciclo — que não é somado — não conta a si mesmo.
		$agregar = static function () use ( &$por_caminho, &$resolvido ) {
			$agregado = array();
			foreach ( $por_caminho as $caminho => $entrada ) {
				$r    = isset( $resolvido[ $caminho ] ) ? $resolvido[ $caminho ] : null;
				$alvo = ( null !== $r && ! empty( $r['terminal'] ) ) ? (string) $r['final'] : $caminho;
				if ( ! isset( $agregado[ $alvo ] ) ) {
					$agregado[ $alvo ] = array(
						'path'        => $alvo,
						'impressions' => 0.0,
						'clicks'      => 0.0,
						'merged'      => 0,
						'state'       => isset( $resolvido[ $alvo ] ) ? (string) $resolvido[ $alvo ]['state'] : 'unchecked',
					);
				}
				$agregado[ $alvo ]['impressions'] += $entrada['impressions'];
				$agregado[ $alvo ]['clicks']      += $entrada['clicks'];
				if ( $alvo !== $caminho ) {
					++$agregado[ $alvo ]['merged'];
				}
			}
			return $agregado;
		};

		// Endereço que chegou ao topo sem ter sido candidato — por exemplo porque um
		// redirecionamento liberou posição — ainda não foi conferido. Confere e soma de
		// novo, até o topo ficar todo conferido. Termina: cada volta resolve ao menos um
		// caminho novo, e o teto de consultas corta o resto como "não verificado".
		do {
			$topo      = array_slice( $ordenar( array_values( $agregar() ) ), 0, $limit );
			$pendentes = array();
			foreach ( $topo as $linha ) {
				if ( 'unchecked' === $linha['state'] ) {
					$pendentes[] = $linha['path'];
				}
			}
			foreach ( $pendentes as $pendente ) {
				$resolver( $pendente );
			}
		} while ( array() !== $pendentes );

		$saida = array();
		foreach ( $topo as $linha ) {
			$linha['label'] = 'ok' === $linha['state'] ? (string) call_user_func( $labeler, $linha['path'] ) : $linha['path'];
			$saida[]        = $linha;
		}

		$base['available']  = array() !== $saida;
		$base['reason']     = array() === $saida ? 'pages_empty' : '';
		$base['rows']       = $saida;
		$base['head_count'] = $consultas;

		return $base;
	}
}

if ( ! function_exists( 'uonix_intelligence_executive_fetch_gsc_pages' ) ) {
	/**
	 * Até `pages_rows` páginas da Search Console na janela pedida, com o caminho
	 * normalizado.
	 *
	 * `$query` é injetável para o teste exercitar esta função de verdade: a revisão do
	 * PR #301 mostrou que ela nunca executava na suíte, e que trocar o limite de linhas
	 * de volta para 10 — desfazendo a correção — ou tirar a normalização passavam com
	 * tudo verde.
	 *
	 * @param callable|null $query `( $config, $period, $dimension, $row_limit )` que
	 *                             devolve o corpo cru da resposta, ou WP_Error.
	 * @return array{rows: array<int, array{page: string, impressions: float, clicks: float}>, truncated: bool}|WP_Error
	 */
	function uonix_intelligence_executive_fetch_gsc_pages( $config, $period, $query = null ) {
		if ( ! is_callable( $query ) ) {
			if ( ! function_exists( 'uonix_analytics_metrics_get_access_token' ) || ! function_exists( 'uonix_analytics_metrics_search_console_rows' ) ) {
				return uonix_analytics_metrics_error( 'analytics_layer_missing' );
			}
			$query = static function ( $config, $period, $dimension, $row_limit ) {
				$token = uonix_analytics_metrics_get_access_token( $config );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				return uonix_analytics_metrics_search_console_rows(
					$token,
					isset( $config['search_console_site_url'] ) ? (string) $config['search_console_site_url'] : '',
					$period,
					$dimension,
					$row_limit
				);
			};
		}
		$limite = (int) uonix_intelligence_executive_rules()['pages_rows'];
		$bruto  = call_user_func( $query, $config, $period, 'page', $limite );
		if ( is_wp_error( $bruto ) ) {
			return $bruto;
		}
		$decodificado = uonix_analytics_metrics_decode_search_console_report( $bruto, true );
		if ( is_wp_error( $decodificado ) ) {
			return $decodificado;
		}

		$saida = array();
		$crus  = isset( $decodificado['rows'] ) && is_array( $decodificado['rows'] ) ? $decodificado['rows'] : array();
		foreach ( $crus as $r ) {
			$caminho = uonix_analytics_metrics_normalize_path( isset( $r['keys'][0] ) ? (string) $r['keys'][0] : '' );
			if ( '' === $caminho ) {
				continue;
			}
			$saida[] = array( 'page' => $caminho, 'impressions' => (float) $r['impressions'], 'clicks' => (float) $r['clicks'] );
		}

		// Resposta com o limite de linhas pode ter cortado a cauda, e o corte da API é
		// por cliques, não por impressões. Conta as linhas CRUAS: a propriedade é de
		// domínio (`sc-domain:`), então a resposta pode trazer subdomínio que a
		// normalização descarta, e contar depois dela esconderia o corte.
		return array(
			'rows'      => $saida,
			'truncated' => count( $crus ) >= $limite,
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
	 * Três chamadas às APIs do Google — série diária do GA4, série diária da Search
	 * Console (via `uonix_intelligence_anomaly_organic_drop()`) e a lista de páginas —
	 * mais até `head_max` requisições HEAD ao próprio site para conferir o status das
	 * páginas, dentro do orçamento `head_budget`. O relatório sai uma vez por semana,
	 * então o custo é irrelevante; o que importa é que falha de rede degrada a caixa ou
	 * o bloco para "indisponível" e o e-mail sai mesmo assim.
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

		// Páginas: janela ASSENTADA de 28 dias, terminando no mesmo dia que a semana das
		// impressões. A Search Console publica com ~3 dias de atraso, e o bloco é ranking
		// — mas somar dias ainda não publicados como zero mudaria a ordem entre páginas de
		// volume próximo, então a janela nem os inclui.
		$atraso  = function_exists( 'uonix_intelligence_anomaly_rules' ) ? (int) uonix_intelligence_anomaly_rules()['organic_settle_lag_days'] : 4;
		$fim_pag = DateTimeImmutable::createFromFormat( '!Y-m-d', $hoje, new DateTimeZone( 'UTC' ) );
		$janela_paginas = false === $fim_pag ? array() : array(
			'start' => $fim_pag->modify( '-' . ( $atraso + (int) $regras['pages_days'] - 1 ) . ' days' )->format( 'Y-m-d' ),
			'end'   => $fim_pag->modify( '-' . $atraso . ' days' )->format( 'Y-m-d' ),
		);
		if ( null === $config || array() === $janela_paginas ) {
			$paginas = uonix_analytics_metrics_error( 'config_missing' );
		} else {
			$fetcher_pag = isset( $args['pages_fetcher'] ) && is_callable( $args['pages_fetcher'] ) ? $args['pages_fetcher'] : 'uonix_intelligence_executive_fetch_gsc_pages';
			$paginas     = call_user_func( $fetcher_pag, $config, $janela_paginas );
		}

		return array(
			'scorecard'    => $placar,
			'insights'     => uonix_intelligence_executive_insights( $placar, isset( $args['seo'] ) ? $args['seo'] : null ),
			'top_pages'    => uonix_intelligence_executive_top_pages(
				$paginas,
				$janela_paginas,
				isset( $args['status_fetcher'] ) ? $args['status_fetcher'] : null,
				isset( $args['labeler'] ) ? $args['labeler'] : null
			),
			'generated_on' => $hoje,
		);
	}
}
