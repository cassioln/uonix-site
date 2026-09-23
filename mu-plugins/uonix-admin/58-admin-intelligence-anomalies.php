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
