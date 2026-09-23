<?php
/**
 * Central de Inteligência — Alerta de Anomalias (Módulo 5).
 *
 * Detecta dois gatilhos: silêncio de conversões e queda de tráfego orgânico
 * semana-a-semana. Devolve estrutura pronta para o painel e para o e-mail, no
 * mesmo contrato de 55-admin-intelligence-metrics.php: resposta sempre
 * estruturada, e "não há base para afirmar" é estado DIFERENTE de "nenhuma
 * anomalia".
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_anomaly_rules' ) ) {
	/**
	 * Limiares dos gatilhos.
	 *
	 * `lead_silence_days` NÃO vem da especificação. A #193 fala em "0 leads em 48
	 * horas úteis (em comparação com a média histórica de 3-5 leads/dia)", e esse
	 * "3-5 leads/dia" nunca foi medido. É a mesma armadilha já documentada em
	 * 55-admin-intelligence-metrics.php:30, onde `min_impressions` valia 100 por
	 * especificação e a medição mostrou que a maior consulta do site tinha 106
	 * impressões — a regra eliminava 110 das 111 consultas e o módulo devolvia zero
	 * por construção.
	 *
	 * Aqui o efeito de errar é pior: um limiar abaixo do intervalo normal descreve o
	 * funcionamento habitual do site, dispara toda semana, e o alerta passa a ser
	 * ignorado — o que destrói o módulo inteiro sem nada reprovar.
	 *
	 * Por isso o valor é auditável em produção, e não escolhido uma vez:
	 * `uonix_intelligence_anomaly_lead_baseline()` mede o maior intervalo sem lead
	 * observado, e o painel imprime a medição ao lado do limiar vigente. Se a taxa
	 * de leads do site mudar, a tela mostra que o limiar ficou defasado.
	 *
	 * `organic_drop_percent` é percentual, logo livre de escala: 35% de queda
	 * significa a mesma coisa com 100 ou com 100.000 impressões. Esse número pode
	 * vir da especificação sem medição prévia, ao contrário do anterior.
	 */
	function uonix_intelligence_anomaly_rules() {
		return array(
			'lead_silence_days'       => 10,
			'organic_drop_percent'    => 35.0,
			'organic_window_days'     => 7,
			'organic_min_impressions' => 50,
			'organic_max_lag_days'    => 7,
			'baseline_days'           => 90,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_unavailable' ) ) {
	/**
	 * Resposta padronizada quando não há base para afirmar nada sobre um gatilho.
	 *
	 * `anomalous` é FALSO aqui, mas `available` também: quem consome precisa
	 * distinguir "verifiquei e está normal" de "não consegui verificar". Tratar as
	 * duas como iguais faria uma fonte quebrada parecer sistema saudável, que é
	 * exatamente o silêncio que este módulo existe para eliminar.
	 */
	function uonix_intelligence_anomaly_unavailable( $trigger, $reason, $extra = array() ) {
		return array_merge(
			array(
				'trigger'      => (string) $trigger,
				'available'    => false,
				'reason'       => (string) $reason,
				'anomalous'    => false,
				'source'       => '',
				'synced_at'    => '',
				'stale'        => true,
				'measured'     => array(),
				'started_at'   => '',
				'headline'     => '',
				'likely_cause' => '',
				'action'       => '',
				'observed_on'  => '',
			),
			is_array( $extra ) ? $extra : array()
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_lead_form_ids' ) ) {
	/**
	 * Formulários que contam como lead.
	 *
	 * 4 é a Captura de Leads e 3 o Formulário de Contato, conforme
	 * 39-admin-editor-dashboard.php. Existe como acessor porque os IDs já estão
	 * escritos à mão naquele arquivo: se este também os escrevesse, uma troca de ID
	 * no Fluent Forms faria o painel de leads e o detector de anomalia discordarem
	 * em silêncio, e o detector diria "nenhum lead" para sempre.
	 *
	 * NÃO inclui o formulário de currículos: candidatura não é oportunidade
	 * comercial, e contá-la mascararia um colapso de vendas.
	 */
	function uonix_intelligence_anomaly_lead_form_ids() {
		return array( 3, 4 );
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_submissions_table' ) ) {
	/**
	 * Nome da tabela de submissões, ou string vazia quando ela não existe.
	 *
	 * Ausência é informação, não zero: um site sem a tabela não tem "nenhum lead",
	 * tem uma fonte indisponível. Confundir as duas coisas transformaria a falta do
	 * Fluent Forms num alerta crítico permanente.
	 */
	function uonix_intelligence_anomaly_submissions_table() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}
		$tabela = $wpdb->prefix . 'fluentform_submissions';
		$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabela ) );

		return $existe === $tabela ? $tabela : '';
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_lead_status_clause' ) ) {
	/**
	 * Recorte de status que exclui lixeira e spam.
	 *
	 * Tolera `status` nulo e vazio porque submissões antigas do Fluent Forms não
	 * preenchem a coluna — filtrar por igualdade a um status válido descartaria o
	 * histórico inteiro e o detector veria silêncio onde houve lead. Mesmo recorte
	 * de 39-admin-editor-dashboard.php.
	 *
	 * Sem esta exclusão, uma rajada de spam contaria como conversão e ESCONDERIA um
	 * colapso real de vendas — o alerta ficaria calado justamente quando importa.
	 */
	function uonix_intelligence_anomaly_lead_status_clause() {
		return "( status IS NULL OR status = '' OR status NOT IN ( 'trashed', 'trash', 'spam' ) )";
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_lead_daily_counts' ) ) {
	/**
	 * Leads por dia nos últimos `$days` dias, indexados por data local.
	 *
	 * Só devolve dias COM lead: dia ausente é dia sem lead. Materializar zeros aqui
	 * inflaria a resposta sem acrescentar informação, e quem calcula intervalo já
	 * precisa iterar o calendário de qualquer forma.
	 *
	 * @return array<string, int>|null Null quando a tabela não existe.
	 */
	function uonix_intelligence_anomaly_lead_daily_counts( $days = 90 ) {
		global $wpdb;
		$tabela = uonix_intelligence_anomaly_submissions_table();
		if ( '' === $tabela ) {
			return null;
		}

		$days   = is_int( $days ) && $days > 0 ? $days : 90;
		$ids    = uonix_intelligence_anomaly_lead_form_ids();
		$desde  = uonix_intelligence_anomaly_now()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
		$marcas = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// `$tabela` vem de `$wpdb->prefix`, não de entrada; `$marcas` é gerado a
		// partir da contagem de IDs internos. Todo valor passa por prepare.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT DATE( created_at ) AS dia, COUNT( id ) AS total
			 FROM {$tabela}
			 WHERE form_id IN ( {$marcas} )
			   AND " . uonix_intelligence_anomaly_lead_status_clause() . "
			   AND created_at >= %s
			 GROUP BY DATE( created_at )",
			array_merge( $ids, array( $desde ) )
		);

		$linhas = $wpdb->get_results( $sql );
		if ( ! is_array( $linhas ) ) {
			return array();
		}

		$contagem = array();
		foreach ( $linhas as $linha ) {
			if ( ! is_object( $linha ) || ! isset( $linha->dia, $linha->total ) ) {
				continue;
			}
			$contagem[ (string) $linha->dia ] = (int) $linha->total;
		}

		return $contagem;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_now' ) ) {
	/**
	 * Agora, no fuso do site.
	 *
	 * Existe como acessor para o teste poder fixar o instante sem tocar no relógio
	 * do sistema, e para as duas pontas da comparação usarem o mesmo fuso. Um
	 * detector que compara `created_at` local com `now` em UTC erraria por três
	 * horas — imaterial num limiar de dias, mas a coerência é de graça.
	 */
	function uonix_intelligence_anomaly_now( $override = null ) {
		$fuso = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'America/Sao_Paulo' );

		return new DateTimeImmutable( is_string( $override ) && '' !== $override ? $override : 'now', $fuso );
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_longest_gap' ) ) {
	/**
	 * Maior sequência de dias consecutivos sem lead dentro da janela observada.
	 *
	 * Função pura, para o limiar poder ser medido e testado sem banco. Conta o
	 * intervalo ENTRE leads e também o que vai do último lead até hoje, porque um
	 * silêncio em curso é exatamente o que o gatilho precisa enxergar.
	 *
	 * @param array<string, int> $por_dia Contagem por data 'Y-m-d'.
	 * @param string             $inicio  Primeiro dia observado 'Y-m-d'.
	 * @param string             $fim     Último dia observado 'Y-m-d'.
	 */
	function uonix_intelligence_anomaly_longest_gap( $por_dia, $inicio, $fim ) {
		$fuso    = new DateTimeZone( 'UTC' );
		$cursor  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $inicio, $fuso );
		$termino = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $fim, $fuso );
		if ( false === $cursor || false === $termino || $cursor > $termino ) {
			return null;
		}

		$maior = 0;
		$atual = 0;
		while ( $cursor <= $termino ) {
			$dia = $cursor->format( 'Y-m-d' );
			if ( isset( $por_dia[ $dia ] ) && (int) $por_dia[ $dia ] > 0 ) {
				$atual = 0;
			} else {
				++$atual;
				if ( $atual > $maior ) {
					$maior = $atual;
				}
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		return $maior;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_lead_baseline' ) ) {
	/**
	 * Mede o comportamento normal de leads, para o limiar ser auditável.
	 *
	 * É a resposta ao risco descrito em `uonix_intelligence_anomaly_rules()`: em vez
	 * de fixar um número uma vez e esperar que continue verdadeiro, o painel mostra
	 * a medição ao lado do limiar. Um limiar menor ou igual ao maior intervalo
	 * normal está descrevendo o funcionamento do site, não uma anomalia.
	 *
	 * @return array{available: bool, reason: string, days: int, leads: int, per_day: float, longest_gap: int|null, threshold_days: int, threshold_is_safe: bool}
	 */
	function uonix_intelligence_anomaly_lead_baseline( $days = null ) {
		$regras = uonix_intelligence_anomaly_rules();
		$days   = is_int( $days ) && $days > 0 ? $days : (int) $regras['baseline_days'];
		$limiar = (int) $regras['lead_silence_days'];

		$base = array(
			'available'         => false,
			'reason'            => '',
			'days'              => $days,
			'leads'             => 0,
			'per_day'           => 0.0,
			'longest_gap'       => null,
			'threshold_days'    => $limiar,
			'threshold_is_safe' => false,
		);

		$por_dia = uonix_intelligence_anomaly_lead_daily_counts( $days );
		if ( null === $por_dia ) {
			$base['reason'] = 'submissions_table_missing';
			return $base;
		}

		$agora  = uonix_intelligence_anomaly_now();
		$maior  = uonix_intelligence_anomaly_longest_gap(
			$por_dia,
			$agora->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ),
			$agora->format( 'Y-m-d' )
		);
		$total  = array_sum( array_map( 'intval', $por_dia ) );

		$base['available']   = true;
		$base['leads']       = $total;
		$base['per_day']     = round( $total / $days, 2 );
		$base['longest_gap'] = $maior;
		// Limiar seguro é limiar ESTRITAMENTE maior que o maior silêncio normal. Igual
		// não serve: o intervalo que já aconteceu sem nada de errado voltaria a
		// acontecer, e o alerta dispararia descrevendo normalidade.
		$base['threshold_is_safe'] = null !== $maior && $limiar > $maior;

		return $base;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_lead_silence' ) ) {
	/**
	 * Gatilho 1: silêncio de conversões.
	 *
	 * Dias CORRIDOS, e não "horas úteis" como a especificação propõe. Decisão de
	 * 2026-09-23: contar dia útil exigiria calendário de feriados, e uma lista de
	 * feriados vencida quebra o alerta em silêncio — o pior modo de falha possível
	 * para um vigia. Com dias corridos, fim de semana e feriado já entram embutidos
	 * no limiar, porque a medição que produz o limiar os inclui.
	 */
	function uonix_intelligence_anomaly_lead_silence() {
		$regras = uonix_intelligence_anomaly_rules();
		$limiar = (int) $regras['lead_silence_days'];

		$por_dia = uonix_intelligence_anomaly_lead_daily_counts( max( $limiar * 2, 30 ) );
		if ( null === $por_dia ) {
			return uonix_intelligence_anomaly_unavailable( 'lead_silence', 'submissions_table_missing' );
		}

		$agora = uonix_intelligence_anomaly_now();
		$hoje  = $agora->format( 'Y-m-d' );

		// Dias sem lead contados de hoje para trás. Para o gatilho, o que importa é o
		// silêncio EM CURSO, não o maior silêncio histórico.
		$silencio = 0;
		$cursor   = $agora;
		while ( $silencio <= $limiar ) {
			$dia = $cursor->format( 'Y-m-d' );
			if ( isset( $por_dia[ $dia ] ) && (int) $por_dia[ $dia ] > 0 ) {
				break;
			}
			++$silencio;
			$cursor = $cursor->modify( '-1 day' );
		}

		$anomalo = $silencio >= $limiar;

		return array(
			'trigger'     => 'lead_silence',
			'available'   => true,
			'reason'      => '',
			'anomalous'   => $anomalo,
			'source'      => 'fluentform_submissions',
			'synced_at'   => $agora->format( 'c' ),
			'stale'       => false,
			'measured'    => array( 'silent_days' => $silencio, 'threshold_days' => $limiar ),
			'started_at'  => $anomalo ? $agora->modify( '-' . $silencio . ' days' )->format( 'Y-m-d' ) : '',
			'headline'    => $anomalo
				? sprintf( 'Nenhum orçamento recebido há %d dias.', $silencio )
				: sprintf( 'Último orçamento há %d dia(s), dentro do normal.', $silencio ),
			'likely_cause' => $anomalo
				? 'Falha no envio do formulário (Turnstile, Tag Manager ou entrega de e-mail), ou queda real de demanda.'
				: '',
			'action'      => $anomalo
				? 'Enviar um orçamento de teste pelo site. Se chegar, o formulário está funcionando e a queda é de demanda; se não chegar, o problema é técnico e está no caminho de envio.'
				: '',
			'observed_on' => $hoje,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_organic_windows' ) ) {
	/**
	 * Monta as duas janelas de comparação a partir das datas que a API DEVOLVEU.
	 *
	 * Função pura, e o coração do gatilho 2. Existe separada porque o caminho óbvio
	 * — reusar `uonix_analytics_metrics_periods( null, 7 )` — produz falso positivo
	 * estrutural, e isso foi MEDIDO em 2026-09-23.
	 *
	 * Aquela função define a janela atual como `[hoje−7, hoje−1]`, mas o Search
	 * Console não publica os últimos ~3 dias: naquela data a série terminava em
	 * 20/09 e os dias 21, 22 e 23 não voltavam nem como zero. Resultado: a janela
	 * atual perde sempre 2 de 7 dias enquanto a anterior está completa. Com o
	 * tráfego real do site a projeção para 24/09 dava 496 contra 1.030 impressões,
	 * ou seja **−51,8% sem nenhuma mudança real** — "queda crítica" todo dia. E a
	 * sazonalidade agrava: fim de semana rende ~65 impressões contra 140–244 em dia
	 * útil, então quando os dias faltantes são úteis o erro é maior.
	 *
	 * A correção tem três partes, e cada uma responde a um modo de falha:
	 *
	 * 1. **Ancorar as duas janelas na última data COM dado**, não em ontem. Isso
	 *    exclui a cauda não consolidada por construção, e não por subtração de uma
	 *    constante.
	 * 2. **Derivar a defasagem do próprio dado.** Fixar "3 dias" em constante
	 *    transformaria uma mudança do lado do Google em falso positivo silencioso.
	 * 3. **Deslocar a janela anterior em exatamente `$window_days`**, o que preserva
	 *    a composição de dias da semana entre as duas — sem isso, comparar 7 dias
	 *    que contêm dois fins de semana com 7 que contêm um já acusaria queda.
	 *
	 * Ausência de uma data NO INTERIOR de uma janela é zero legítimo: a API omite
	 * linha para dia sem impressão. Só a cauda é "ainda não publicado", e ancorar em
	 * `$last` já a exclui — por isso não há, e não deve haver, exigência de que
	 * todas as datas estejam presentes.
	 *
	 * @param array<int, string> $dates_present   Datas 'Y-m-d' devolvidas pela API.
	 * @param int                $window_days     Tamanho de cada janela.
	 * @param string             $requested_start Data inicial que foi PEDIDA.
	 * @param string             $today           Hoje, no fuso do site.
	 * @return array Janelas e defasagem, ou `array( 'reason' => ... )`.
	 */
	function uonix_intelligence_anomaly_organic_windows( $dates_present, $window_days, $requested_start, $today ) {
		$regras  = uonix_intelligence_anomaly_rules();
		$janela  = is_int( $window_days ) && $window_days > 0 ? $window_days : (int) $regras['organic_window_days'];
		$max_lag = (int) $regras['organic_max_lag_days'];
		$fuso    = new DateTimeZone( 'UTC' );

		$validas = array();
		foreach ( is_array( $dates_present ) ? $dates_present : array() as $data ) {
			if ( is_string( $data ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $data ) ) {
				$validas[] = $data;
			}
		}
		if ( array() === $validas ) {
			return array( 'reason' => 'series_empty' );
		}

		// Comparação lexicográfica serve para 'Y-m-d': a ordem de string coincide com
		// a cronológica nesse formato, e evita construir um objeto por linha.
		$ultima  = max( $validas );
		$fim     = DateTimeImmutable::createFromFormat( '!Y-m-d', $ultima, $fuso );
		$agora   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, $fuso );
		$pedido  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $requested_start, $fuso );
		if ( false === $fim || false === $agora || false === $pedido ) {
			return array( 'reason' => 'series_dates_invalid' );
		}

		$defasagem = (int) $agora->diff( $fim )->days;
		if ( $fim > $agora ) {
			// Data futura na resposta não é defasagem pequena: é dado inconsistente, e
			// tratá-la como atual ancoraria as janelas num dia que não terminou.
			return array( 'reason' => 'series_dates_invalid' );
		}
		if ( $defasagem > $max_lag ) {
			// Série velha demais para responder "o que mudou nesta semana". Pode ser
			// integração quebrada ou site desindexado — nos dois casos a comparação
			// semana-a-semana afirmaria algo que o dado não sustenta.
			return array( 'reason' => 'series_stale' );
		}

		$atual_inicio    = $fim->modify( '-' . ( $janela - 1 ) . ' days' );
		$anterior_fim    = $atual_inicio->modify( '-1 day' );
		$anterior_inicio = $anterior_fim->modify( '-' . ( $janela - 1 ) . ' days' );

		if ( $anterior_inicio < $pedido ) {
			// Não pedimos histórico suficiente. Comparar contra janela anterior truncada
			// seria o mesmo defeito que esta função existe para evitar, só do outro lado.
			return array( 'reason' => 'series_too_short' );
		}

		return array(
			'current'      => array( 'start' => $atual_inicio->format( 'Y-m-d' ), 'end' => $fim->format( 'Y-m-d' ) ),
			'previous'     => array( 'start' => $anterior_inicio->format( 'Y-m-d' ), 'end' => $anterior_fim->format( 'Y-m-d' ) ),
			'lag_days'     => $defasagem,
			'last_settled' => $ultima,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_sum_impressions' ) ) {
	/**
	 * Soma impressões das linhas cuja data cai dentro da janela, inclusive.
	 *
	 * @param array<int, array> $rows   Linhas decodificadas, com `keys[0]` = data.
	 * @param array             $window `array( 'start' => 'Y-m-d', 'end' => 'Y-m-d' )`.
	 */
	function uonix_intelligence_anomaly_sum_impressions( $rows, $window ) {
		if ( ! is_array( $rows ) || ! isset( $window['start'], $window['end'] ) ) {
			return 0.0;
		}
		$total = 0.0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['keys'][0], $row['impressions'] ) || ! is_string( $row['keys'][0] ) ) {
				continue;
			}
			$data = $row['keys'][0];
			if ( $data >= (string) $window['start'] && $data <= (string) $window['end'] ) {
				$total += (float) $row['impressions'];
			}
		}

		return $total;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_fetch_organic_series' ) ) {
	/**
	 * Busca a série diária de impressões no Search Console.
	 *
	 * Reusa `uonix_analytics_metrics_search_console_rows()` com a dimensão `date`, e
	 * `uonix_analytics_metrics_decode_search_console_report()` para desempacotar.
	 * Nenhum buscador novo: aquela função já aceita período e dimensão arbitrários.
	 *
	 * @return array<int, array>|WP_Error Linhas decodificadas.
	 */
	function uonix_intelligence_anomaly_fetch_organic_series( $config, $period ) {
		if ( ! function_exists( 'uonix_analytics_metrics_get_access_token' ) || ! function_exists( 'uonix_analytics_metrics_search_console_rows' ) ) {
			return uonix_analytics_metrics_error( 'analytics_layer_missing' );
		}

		$token = uonix_analytics_metrics_get_access_token( $config );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$bruto = uonix_analytics_metrics_search_console_rows(
			$token,
			isset( $config['search_console_site_url'] ) ? (string) $config['search_console_site_url'] : '',
			$period,
			'date',
			100
		);
		if ( is_wp_error( $bruto ) ) {
			return $bruto;
		}

		$decodificado = uonix_analytics_metrics_decode_search_console_report( $bruto, true );
		if ( is_wp_error( $decodificado ) ) {
			return $decodificado;
		}

		return isset( $decodificado['rows'] ) && is_array( $decodificado['rows'] ) ? $decodificado['rows'] : array();
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_organic_drop' ) ) {
	/**
	 * Gatilho 2: queda de tráfego orgânico semana-a-semana.
	 *
	 * O período pedido é derivado das regras, não escrito à mão: no pior caso a
	 * última data publicada está `organic_max_lag_days` atrás, e a janela anterior
	 * começa `2 * organic_window_days − 1` dias antes dela. Pedir menos que isso
	 * produziria `series_too_short` justamente nos dias em que o Google atrasa mais.
	 */
	function uonix_intelligence_anomaly_organic_drop( $series_fetcher = null, $config = null, $today = null ) {
		$regras = uonix_intelligence_anomaly_rules();
		$janela = (int) $regras['organic_window_days'];
		$agora  = uonix_intelligence_anomaly_now( $today );
		$hoje   = $agora->format( 'Y-m-d' );

		$config = is_array( $config ) ? $config : ( function_exists( 'uonix_analytics_metrics_get_config' ) ? uonix_analytics_metrics_get_config() : null );
		if ( ! is_array( $config ) ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'config_missing' );
		}

		$recuo  = (int) $regras['organic_max_lag_days'] + ( 2 * $janela );
		$inicio = $agora->modify( '-' . $recuo . ' days' )->format( 'Y-m-d' );
		$period = array( 'start' => $inicio, 'end' => $hoje );

		$fetcher = is_callable( $series_fetcher ) ? $series_fetcher : 'uonix_intelligence_anomaly_fetch_organic_series';
		$rows    = call_user_func( $fetcher, $config, $period );
		if ( is_wp_error( $rows ) ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'series_fetch_failed' );
		}
		if ( ! is_array( $rows ) ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'series_invalid' );
		}

		$datas = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['keys'][0] ) && is_string( $row['keys'][0] ) ) {
				$datas[] = $row['keys'][0];
			}
		}

		$janelas = uonix_intelligence_anomaly_organic_windows( $datas, $janela, $inicio, $hoje );
		if ( isset( $janelas['reason'] ) ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', (string) $janelas['reason'] );
		}

		$atual    = uonix_intelligence_anomaly_sum_impressions( $rows, $janelas['current'] );
		$anterior = uonix_intelligence_anomaly_sum_impressions( $rows, $janelas['previous'] );

		// Piso de ruído: com pouquíssima impressão, variação percentual é aleatória. Um
		// site que saiu de 4 para 2 impressões caiu 50% sem que isso signifique nada.
		if ( $anterior < (float) $regras['organic_min_impressions'] ) {
			return uonix_intelligence_anomaly_unavailable(
				'organic_drop',
				'baseline_too_small',
				array( 'measured' => array( 'current' => $atual, 'previous' => $anterior, 'windows' => $janelas ) )
			);
		}

		$comparacao = function_exists( 'uonix_analytics_metrics_compare' )
			? uonix_analytics_metrics_compare( $atual, $anterior )
			: null;
		if ( ! is_array( $comparacao ) || ! isset( $comparacao['delta_percent'] ) ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'comparison_failed' );
		}

		$variacao = (float) $comparacao['delta_percent'];
		$limiar   = (float) $regras['organic_drop_percent'];
		$anomalo  = $variacao <= -$limiar;

		return array(
			'trigger'      => 'organic_drop',
			'available'    => true,
			'reason'       => '',
			'anomalous'    => $anomalo,
			'source'       => 'search_console',
			'synced_at'    => $agora->format( 'c' ),
			'stale'        => false,
			'measured'     => array(
				'current'       => $atual,
				'previous'      => $anterior,
				'delta_percent' => $variacao,
				'threshold'     => $limiar,
				'windows'       => $janelas,
			),
			'started_at'   => $anomalo ? (string) $janelas['current']['start'] : '',
			'headline'     => $anomalo
				? sprintf( 'Impressões orgânicas caíram %s%% em relação à semana anterior.', number_format( abs( $variacao ), 1, ',', '.' ) )
				: sprintf( 'Impressões orgânicas em %s%% na comparação semanal, dentro do normal.', number_format( $variacao, 1, ',', '.' ) ),
			'likely_cause' => $anomalo
				? 'Possível penalização manual, erro de indexação, bloqueio no robots.txt ou perda de posição em consultas de volume.'
				: '',
			'action'       => $anomalo
				? 'Conferir Ações Manuais e Cobertura no Search Console, e comparar as páginas que perderam impressão entre as duas janelas.'
				: '',
			'observed_on'  => (string) $janelas['last_settled'],
		);
	}
}

// ---------------------------------------------------------------------------
// Estado, deduplicação e agregação.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_anomaly_state_option' ) ) {
	/**
	 * Opção que guarda, por gatilho, se ele estava anômalo na última verificação.
	 *
	 * Acessor, e não string solta, pelo mesmo motivo de
	 * `uonix_intelligence_report_hook()`: quem grava e quem lê estão em arquivos
	 * diferentes, e uma divergência de nome faria todo alerta parecer novo — o
	 * módulo reenviaria e-mail em cada verificação, sem nada reprovar.
	 *
	 * Precisa entrar na lista protegida de `scripts/clone-environment.sh`. Sem isso
	 * o ambiente clonado herda o estado da produção e o badge do painel passa a
	 * afirmar, em QA, uma anomalia que é da produção.
	 */
	function uonix_intelligence_anomaly_state_option() {
		return 'uonix_intelligence_anomaly_state';
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_get_state' ) ) {
	/**
	 * Estado da última verificação: `array<string, bool>` por gatilho.
	 */
	function uonix_intelligence_anomaly_get_state() {
		$salvo = function_exists( 'get_option' ) ? get_option( uonix_intelligence_anomaly_state_option(), array() ) : array();
		if ( ! is_array( $salvo ) ) {
			return array();
		}
		$estado = array();
		foreach ( $salvo as $gatilho => $ativo ) {
			if ( is_string( $gatilho ) && '' !== $gatilho ) {
				$estado[ $gatilho ] = (bool) $ativo;
			}
		}

		return $estado;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_state_from_findings' ) ) {
	/**
	 * Novo estado a persistir, a partir dos achados desta verificação.
	 *
	 * Função pura. A regra que importa: gatilho **indisponível preserva o estado
	 * anterior**, em vez de virar "normal".
	 *
	 * Sem isso, uma falha de um dia na Search Console limparia o estado, e no dia
	 * seguinte a mesma anomalia — que nunca acabou — voltaria a ser classificada
	 * como nova e geraria um segundo e-mail. A deduplicação existe justamente para
	 * isso não acontecer, e seria derrotada pelo caminho mais silencioso possível.
	 *
	 * @param array<int, array>    $findings Achados da verificação.
	 * @param array<string, bool>  $previous Estado anterior.
	 * @return array<string, bool>
	 */
	function uonix_intelligence_anomaly_state_from_findings( $findings, $previous = array() ) {
		$previous = is_array( $previous ) ? $previous : array();
		$estado   = array();

		foreach ( is_array( $findings ) ? $findings : array() as $achado ) {
			if ( ! is_array( $achado ) || ! isset( $achado['trigger'] ) || ! is_string( $achado['trigger'] ) || '' === $achado['trigger'] ) {
				continue;
			}
			$gatilho = $achado['trigger'];
			if ( empty( $achado['available'] ) ) {
				$estado[ $gatilho ] = isset( $previous[ $gatilho ] ) ? (bool) $previous[ $gatilho ] : false;
				continue;
			}
			$estado[ $gatilho ] = ! empty( $achado['anomalous'] );
		}

		return $estado;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_transitions' ) ) {
	/**
	 * Gatilhos que passaram de normal para anômalo nesta verificação.
	 *
	 * Função pura, e é ela que garante um e-mail por episódio. Anomalia que persiste
	 * não reaparece aqui; se ela limpar e voltar, reaparece — porque é outro
	 * episódio, e merece aviso novo.
	 *
	 * Exige `available`: achado que não pôde ser verificado nunca dispara e-mail,
	 * porque não há o que afirmar.
	 *
	 * @return array<int, array> Os próprios achados em transição.
	 */
	function uonix_intelligence_anomaly_transitions( $findings, $previous = array() ) {
		$previous   = is_array( $previous ) ? $previous : array();
		$transicoes = array();

		foreach ( is_array( $findings ) ? $findings : array() as $achado ) {
			if ( ! is_array( $achado ) || empty( $achado['available'] ) || empty( $achado['anomalous'] ) ) {
				continue;
			}
			if ( ! isset( $achado['trigger'] ) || ! is_string( $achado['trigger'] ) || '' === $achado['trigger'] ) {
				continue;
			}
			$estava = isset( $previous[ $achado['trigger'] ] ) ? (bool) $previous[ $achado['trigger'] ] : false;
			if ( ! $estava ) {
				$transicoes[] = $achado;
			}
		}

		return $transicoes;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_detect' ) ) {
	/**
	 * Roda os gatilhos e resume o resultado.
	 *
	 * Aceita achados prontos para o teste poder exercitar agregação e badge sem
	 * banco nem rede, do mesmo jeito que `uonix_analytics_metrics_sync()` aceita um
	 * fetcher.
	 *
	 * @return array{findings: array, anomalous: int, unavailable: int, checked_at: string}
	 */
	function uonix_intelligence_anomaly_detect( $findings = null ) {
		if ( ! is_array( $findings ) ) {
			$findings = array(
				uonix_intelligence_anomaly_lead_silence(),
				uonix_intelligence_anomaly_organic_drop(),
			);
		}

		$anomalos      = 0;
		$indisponiveis = 0;
		foreach ( $findings as $achado ) {
			if ( ! is_array( $achado ) ) {
				continue;
			}
			if ( empty( $achado['available'] ) ) {
				++$indisponiveis;
				continue;
			}
			if ( ! empty( $achado['anomalous'] ) ) {
				++$anomalos;
			}
		}

		return array(
			'findings'    => $findings,
			'anomalous'   => $anomalos,
			'unavailable' => $indisponiveis,
			'checked_at'  => uonix_intelligence_anomaly_now()->format( 'c' ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_badge' ) ) {
	/**
	 * Rótulo de status, conforme a especificação do Módulo 5.
	 *
	 * Três estados, não dois. A especificação prevê "Sistema Normal" e "N Anomalia
	 * Crítica Detectada", mas calar sobre gatilho indisponível faria uma fonte
	 * quebrada aparecer como sistema saudável — exatamente o silêncio que este
	 * módulo existe para eliminar. Então há um terceiro estado para "não consegui
	 * verificar".
	 *
	 * @return array{state: string, label: string}
	 */
	function uonix_intelligence_anomaly_badge( $summary ) {
		$anomalos      = isset( $summary['anomalous'] ) ? (int) $summary['anomalous'] : 0;
		$indisponiveis = isset( $summary['unavailable'] ) ? (int) $summary['unavailable'] : 0;

		if ( $anomalos > 0 ) {
			return array(
				'state' => 'critical',
				'label' => sprintf(
					1 === $anomalos ? '%d anomalia crítica detectada' : '%d anomalias críticas detectadas',
					$anomalos
				),
			);
		}
		if ( $indisponiveis > 0 ) {
			return array(
				'state' => 'unknown',
				'label' => sprintf(
					1 === $indisponiveis ? '%d verificação indisponível' : '%d verificações indisponíveis',
					$indisponiveis
				),
			);
		}

		return array( 'state' => 'normal', 'label' => 'Sistema normal' );
	}
}

// ---------------------------------------------------------------------------
// Alerta por e-mail.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_anomaly_alert_subject' ) ) {
	function uonix_intelligence_anomaly_alert_subject( $transitions ) {
		$total = is_array( $transitions ) ? count( $transitions ) : 0;
		$nome  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Uônix';

		return sprintf(
			1 === $total ? '[%s] Anomalia detectada: %s' : '[%s] %d anomalias detectadas',
			'' !== $nome ? $nome : 'Uônix',
			1 === $total && isset( $transitions[0]['headline'] ) ? (string) $transitions[0]['headline'] : $total
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_alert_html' ) ) {
	/**
	 * Corpo do alerta, no formato de detalhe de incidente que a especificação pede:
	 * o que aconteceu, quando começou, causa provável, ação recomendada.
	 *
	 * Reusa a estrutura de tabelas de 600px de `uonix_intelligence_report_html()`, e
	 * o mesmo aviso de ambiente não produtivo — cuja AUSÊNCIA no e-mail recebido em
	 * 23/09/2026 foi a prova de que a detecção de ambiente funciona em produção.
	 */
	function uonix_intelligence_anomaly_alert_html( $transitions ) {
		$transitions = is_array( $transitions ) ? $transitions : array();
		// Constante, como em 57: não existe função de ambiente neste código. Ler por
		// `function_exists()` de uma função inexistente deixaria `$ambiente` vazio e o
		// aviso de ambiente não produtivo NUNCA apareceria — um alerta de QA passaria
		// por alerta de produção, que é o inverso da proteção pretendida.
		$ambiente    = defined( 'UONIX_ENV' ) ? (string) UONIX_ENV : '';
		$painel      = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=uonix-analytics&tab=anomalies' ) : '';

		$html  = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<title>' . esc_html( uonix_intelligence_anomaly_alert_subject( $transitions ) ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background-color:#f1f5f9;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">';
		$html .= '<tr><td align="center">';
		$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">';

		$html .= '<tr><td style="background-color:#7f1d1d;padding:24px 28px;">';
		$html .= '<div style="color:#ffffff;font-size:20px;font-weight:bold;line-height:1.3;">Uônix</div>';
		$html .= '<div style="color:#fecaca;font-size:13px;padding-top:4px;">Alerta de anomalia operacional</div>';
		$html .= '</td></tr>';

		foreach ( $transitions as $achado ) {
			if ( ! is_array( $achado ) ) {
				continue;
			}
			$html .= '<tr><td style="padding:22px 28px 6px 28px;border-top:1px solid #f1f5f9;">';
			$html .= '<div style="color:#7f1d1d;font-size:16px;font-weight:bold;line-height:1.4;">' . esc_html( isset( $achado['headline'] ) ? (string) $achado['headline'] : '' ) . '</div>';
			$html .= '</td></tr>';
			$html .= '<tr><td style="padding:0 28px 20px 28px;font-size:13px;color:#1e293b;line-height:1.6;">';
			foreach ( array(
				'Quando começou'    => isset( $achado['started_at'] ) ? (string) $achado['started_at'] : '',
				'Causa provável'    => isset( $achado['likely_cause'] ) ? (string) $achado['likely_cause'] : '',
				'Ação recomendada'  => isset( $achado['action'] ) ? (string) $achado['action'] : '',
				'Verificado em'     => isset( $achado['observed_on'] ) ? (string) $achado['observed_on'] : '',
			) as $rotulo => $valor ) {
				if ( '' === $valor ) {
					continue;
				}
				$html .= '<div style="padding-top:8px;"><strong style="color:#475569;">' . esc_html( $rotulo ) . ':</strong> ' . esc_html( $valor ) . '</div>';
			}
			$html .= '</td></tr>';
		}

		$html .= '<tr><td style="background-color:#f8fafc;padding:18px 28px;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.6;">';
		if ( '' !== $painel ) {
			$html .= '<div><a href="' . esc_url( $painel ) . '" style="color:#0e3780;">Abrir a aba de Anomalias no painel</a></div>';
		}
		$html .= '<div style="padding-top:6px;">Este aviso sai uma vez por episódio. Enquanto a anomalia persistir, não há reenvio; se ela se resolver e voltar, um novo aviso é emitido.</div>';
		if ( '' !== $ambiente && 'production' !== $ambiente ) {
			$html .= '<div style="padding-top:6px;color:#b45309;"><strong>Ambiente: ' . esc_html( strtoupper( $ambiente ) ) . '</strong> — mensagem não produtiva.</div>';
		}
		$html .= '</td></tr>';

		$html .= '</table></td></tr></table></body></html>';

		return $html;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_send_alert' ) ) {
	/**
	 * Envia o alerta para a lista do relatório executivo.
	 *
	 * Reusa `uonix_intelligence_get_recipients()` de propósito: uma segunda lista
	 * seria um segundo invariante a manter, e o portão de ativação do módulo já
	 * exige que o destinatário seja o operador.
	 *
	 * @return array{sent: bool, reason: string, recipients: int}
	 */
	function uonix_intelligence_anomaly_send_alert( $transitions, $recipients = null ) {
		$transitions = is_array( $transitions ) ? $transitions : array();
		if ( array() === $transitions ) {
			return array( 'sent' => false, 'reason' => 'no_transition', 'recipients' => 0 );
		}

		$lista = null === $recipients && function_exists( 'uonix_intelligence_get_recipients' )
			? uonix_intelligence_get_recipients()
			: $recipients;
		$lista = is_array( $lista ) ? $lista : array();

		if ( array() === $lista ) {
			return array( 'sent' => false, 'reason' => 'no_recipients', 'recipients' => 0 );
		}

		$enviado = wp_mail(
			$lista,
			uonix_intelligence_anomaly_alert_subject( $transitions ),
			uonix_intelligence_anomaly_alert_html( $transitions ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		return array(
			'sent'       => (bool) $enviado,
			'reason'     => $enviado ? '' : 'mail_failed',
			'recipients' => count( $lista ),
		);
	}
}

// ---------------------------------------------------------------------------
// Verificação periódica.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_anomaly_run_check' ) ) {
	/**
	 * Verifica, alerta na transição, e persiste o estado.
	 *
	 * A ordem importa, e a regra de persistência é a parte não óbvia: **o estado só
	 * avança quando o aviso saiu, ou quando não havia aviso a dar.**
	 *
	 * Se o envio falhar — transporte quebrado, ou lista de destinatários ainda
	 * vazia — a transição NÃO é persistida, e a verificação seguinte tenta de novo.
	 * Persistir ali silenciaria o episódio para sempre: o estado diria "já avisei"
	 * sobre um aviso que nunca chegou. E deixar de persistir não gera enxurrada,
	 * porque nos dois casos nada é entregue.
	 *
	 * Consequência desejada: se o operador cadastrar o próprio endereço com uma
	 * anomalia em curso, ele recebe o aviso na verificação seguinte, em vez de
	 * descobrir que perdeu o episódio.
	 */
	function uonix_intelligence_anomaly_run_check() {
		$resumo     = uonix_intelligence_anomaly_detect();
		$anterior   = uonix_intelligence_anomaly_get_state();
		$transicoes = uonix_intelligence_anomaly_transitions( $resumo['findings'], $anterior );
		$novo       = uonix_intelligence_anomaly_state_from_findings( $resumo['findings'], $anterior );

		$envio = array( 'sent' => false, 'reason' => 'no_transition', 'recipients' => 0 );
		if ( array() !== $transicoes ) {
			$envio = uonix_intelligence_anomaly_send_alert( $transicoes );
			if ( empty( $envio['sent'] ) ) {
				foreach ( $transicoes as $achado ) {
					$gatilho = (string) $achado['trigger'];
					$novo[ $gatilho ] = isset( $anterior[ $gatilho ] ) ? (bool) $anterior[ $gatilho ] : false;
				}
			}
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( uonix_intelligence_anomaly_state_option(), $novo, false );
		}

		return array( 'summary' => $resumo, 'transitions' => count( $transicoes ), 'send' => $envio );
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_hook' ) ) {
	function uonix_intelligence_anomaly_hook() {
		return 'uonix_intelligence_anomaly_check';
	}
}

// O handler é registrado no carregamento; o evento NÃO é agendado aqui. Mesmo
// motivo de 57: carregar arquivo não deve escrever no agendador, e em mu-plugin
// isso roda antes de `init` e antes dos plugins.
if ( function_exists( 'uonix_intelligence_anomaly_hook' ) ) {
	add_action( uonix_intelligence_anomaly_hook(), 'uonix_intelligence_anomaly_run_check', 10, 0 );
}

if ( ! function_exists( 'uonix_intelligence_anomaly_maybe_schedule' ) ) {
	/**
	 * Agenda a verificação diária.
	 *
	 * **Diferença deliberada em relação ao relatório executivo, que NÃO deve ser
	 * "corrigida" para ficar igual:** `uonix_intelligence_maybe_schedule_report()`
	 * mantém o invariante "existe evento agendado se, e somente se, existe
	 * destinatário", porque um relatório sem destinatário não tem o que fazer.
	 *
	 * Aqui o evento é agendado SEMPRE. A verificação alimenta o badge do painel, que
	 * é útil sem e-mail nenhum: quem abre a tela quer saber se há anomalia, tenha ou
	 * não cadastrado endereço. Só o ENVIO depende de destinatário, e essa condição
	 * vive em `uonix_intelligence_anomaly_send_alert()`.
	 *
	 * @return bool Verdadeiro apenas quando esta chamada criou o evento.
	 */
	function uonix_intelligence_anomaly_maybe_schedule() {
		if ( ! function_exists( 'uonix_intelligence_anomaly_hook' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		$hook = uonix_intelligence_anomaly_hook();
		if ( false !== wp_next_scheduled( $hook ) ) {
			// Já agendado: não duplicar nem mover a data. Reagendar a cada requisição
			// empurraria o disparo para sempre adiante e a verificação nunca rodaria.
			return false;
		}

		$recorrencias = wp_get_schedules();
		if ( ! isset( $recorrencias['daily'] ) ) {
			// Falha fechada, como em 57: sem a recorrência registrada,
			// `wp_schedule_event` criaria um disparo único disfarçado de diário.
			return false;
		}

		return (bool) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
	}
}
// `accepted_args = 0` como os irmãos de 53 e 57: o callback não usa argumento, e
// declarar zero impede que alguém injete dado pelo despacho do hook.
add_action( 'init', 'uonix_intelligence_anomaly_maybe_schedule', 10, 0 );
