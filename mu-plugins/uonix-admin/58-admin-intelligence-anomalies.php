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
	 *
	 * `organic_settle_lag_days` = 4 vem de MEDIÇÃO com margem de um dia. Em
	 * 2026-09-23, `scripts/tools/gsc-client.js analytics --dim date --days 12`
	 * devolveu série terminando em 20/09: defasagem de 3 dias. O quarto dia é margem
	 * para variação do lado do Google.
	 *
	 * Fixar essa defasagem só é seguro porque a completude é conferida à parte: se a
	 * Search Console atrasar mais que isso, a janela fica incompleta e o gatilho se
	 * declara INDISPONÍVEL, em vez de comparar janela furada. Sem a conferência de
	 * completude, uma constante aqui viraria falso positivo silencioso — e foi por
	 * isso que a primeira versão deste arquivo derivava a defasagem do dado, solução
	 * que a revisão do PR #293 mostrou ser pior (ver
	 * `uonix_intelligence_anomaly_organic_windows()`).
	 *
	 * Não existe mais `organic_max_lag_days`. Ele valia 7 sem nenhuma medição por
	 * trás, num arquivo que abre com três parágrafos sobre não fixar limiar sem
	 * medir, e tinha efeito que o número escondia: com `max_lag == window_days`, numa
	 * defasagem de 7 dias a "comparação semanal" comparava a semana −2 com a −3, e a
	 * manchete continuava dizendo "em relação à semana anterior". A conferência de
	 * completude substitui a função dele sem precisar de um número arbitrário.
	 */
	function uonix_intelligence_anomaly_rules() {
		return array(
			'lead_silence_days'       => 10,
			'organic_drop_percent'    => 35.0,
			'organic_window_days'     => 7,
			'organic_min_impressions' => 50,
			'organic_settle_lag_days' => 4,
			'baseline_days'           => 90,
			'alert_max_attempts'      => 3,
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
	function uonix_intelligence_anomaly_lead_daily_counts( $days = 90, $today = null ) {
		global $wpdb;
		$tabela = uonix_intelligence_anomaly_submissions_table();
		if ( '' === $tabela ) {
			return null;
		}

		$days   = is_int( $days ) && $days > 0 ? $days : 90;
		$ids    = uonix_intelligence_anomaly_lead_form_ids();
		$desde  = uonix_intelligence_anomaly_now( $today )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
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
	 * ## A cauda em curso é EXCLUÍDA da medição, e isso é o ponto
	 *
	 * A primeira versão media o maior silêncio até HOJE, incluindo o silêncio em
	 * curso. A revisão do PR #293 provou que isso invertia o mecanismo, por
	 * identidade e não por acidente de fixture:
	 *
	 *   `longest_gap >= silêncio_em_curso`, e disparar exige
	 *   `silêncio_em_curso >= limiar`; logo `longest_gap >= limiar`; logo
	 *   `limiar > longest_gap` — que é `threshold_is_safe` — é **necessariamente
	 *   falso em 100% dos verdadeiros positivos.**
	 *
	 * Na tela, isso punha o aviso "o limiar descreve o comportamento habitual do
	 * site" logo abaixo do badge "1 anomalia crítica detectada": o painel instruía o
	 * operador a desconsiderar justamente o alerta correto. Um aviso que só aparece
	 * junto de acertos treina a pessoa a ignorar a tela, que é o modo de falha que
	 * `rules()` declara querer evitar.
	 *
	 * Agora a medição vai de `$inicio` até o ÚLTIMO DIA COM LEAD: só intervalos
	 * FECHADOS, que de fato terminaram e portanto pertencem à normalidade observada.
	 * O silêncio em curso sai em `current_silence`, como número separado.
	 *
	 * Sem nenhum lead na janela não há normalidade a medir, e a resposta é
	 * indisponível — não um `longest_gap` igual à janela inteira, que produziria o
	 * mesmo aviso enganoso.
	 *
	 * @return array{available: bool, reason: string, days: int, leads: int, per_day: float, longest_gap: int|null, current_silence: int|null, threshold_days: int, threshold_is_safe: bool}
	 */
	function uonix_intelligence_anomaly_lead_baseline( $days = null, $today = null ) {
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
			'current_silence'   => null,
			'threshold_days'    => $limiar,
			'threshold_is_safe' => false,
		);

		$por_dia = uonix_intelligence_anomaly_lead_daily_counts( $days, $today );
		if ( null === $por_dia ) {
			$base['reason'] = 'submissions_table_missing';
			return $base;
		}

		$agora  = uonix_intelligence_anomaly_now( $today );
		$inicio = $agora->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$total  = array_sum( array_map( 'intval', $por_dia ) );

		$comLead = array();
		foreach ( $por_dia as $dia => $qtd ) {
			if ( (int) $qtd > 0 && is_string( $dia ) ) {
				$comLead[] = $dia;
			}
		}
		if ( array() === $comLead ) {
			$base['reason'] = 'no_leads_in_window';
			return $base;
		}

		// Ordem lexicográfica coincide com a cronológica em 'Y-m-d'.
		$ultimoLead   = max( $comLead );
		$primeiroLead = min( $comLead );
		$fuso         = new DateTimeZone( 'UTC' );
		$silencio     = (int) DateTimeImmutable::createFromFormat( '!Y-m-d', $ultimoLead, $fuso )
			->diff( DateTimeImmutable::createFromFormat( '!Y-m-d', $agora->format( 'Y-m-d' ), $fuso ) )->days;

		$base['available']       = true;
		$base['leads']           = $total;
		$base['per_day']         = round( $total / $days, 2 );
		$base['current_silence'] = $silencio;
		// A medição vai do PRIMEIRO ao ÚLTIMO lead, e não da borda da janela até hoje.
		//
		// Os dois recortes importam. Terminar no último lead exclui o silêncio em curso,
		// que é a correção do ALTO 4. Começar no primeiro lead exclui o vazio inicial,
		// que não é intervalo entre leads — é a janela de observação sendo maior que a
		// história disponível. Num site novo, ou depois de uma limpeza de submissões,
		// contá-lo produziria um "maior silêncio" do tamanho do vazio e o painel
		// declararia o limiar inseguro sem nenhuma evidência real.
		//
		// Com os dois recortes, `longest_gap` significa exatamente o que a tela afirma:
		// o maior intervalo entre dois orçamentos consecutivos.
		$base['longest_gap']     = uonix_intelligence_anomaly_longest_gap( $por_dia, $primeiroLead, $ultimoLead );
		// Limiar seguro é limiar ESTRITAMENTE maior que o maior silêncio normal. Igual
		// não serve: o intervalo que já aconteceu sem nada de errado voltaria a
		// acontecer, e o alerta dispararia descrevendo normalidade.
		$base['threshold_is_safe'] = null !== $base['longest_gap'] && $limiar > (int) $base['longest_gap'];

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
	function uonix_intelligence_anomaly_lead_silence( $today = null ) {
		$regras = uonix_intelligence_anomaly_rules();
		$limiar = (int) $regras['lead_silence_days'];
		$janela = max( $limiar * 2, 30 );

		$por_dia = uonix_intelligence_anomaly_lead_daily_counts( $janela, $today );
		if ( null === $por_dia ) {
			return uonix_intelligence_anomaly_unavailable( 'lead_silence', 'submissions_table_missing' );
		}

		$agora = uonix_intelligence_anomaly_now( $today );

		// Dias sem lead contados de hoje para trás. Para o gatilho, o que importa é o
		// silêncio EM CURSO, não o maior silêncio histórico.
		//
		// O teto é a JANELA CONSULTADA, não o limiar. A versão anterior parava em
		// `limiar + 1` e o painel exibia "há 11 dias" no 40º dia de colapso, com
		// `started_at` andando um dia por dia — fazendo um incidente único parecer uma
		// sucessão de episódios novos a cada visita à tela.
		$silencio = 0;
		$cursor   = $agora;
		while ( $silencio < $janela ) {
			$dia = $cursor->format( 'Y-m-d' );
			if ( isset( $por_dia[ $dia ] ) && (int) $por_dia[ $dia ] > 0 ) {
				break;
			}
			++$silencio;
			$cursor = $cursor->modify( '-1 day' );
		}

		$anomalo = $silencio >= $limiar;
		// `-( $silencio - 1 )` e não `-$silencio`: com 10 dias de silêncio, o dia
		// `hoje − 10` é aquele em que um orçamento CHEGOU. O primeiro dia silencioso é o
		// seguinte, e é ele que "Quando começou" deve nomear.
		$comecou = $anomalo ? $agora->modify( '-' . max( 0, $silencio - 1 ) . ' days' )->format( 'Y-m-d' ) : '';

		return array(
			'trigger'     => 'lead_silence',
			'available'   => true,
			'reason'      => '',
			'anomalous'   => $anomalo,
			'source'      => 'fluentform_submissions',
			'synced_at'   => $agora->format( 'c' ),
			'stale'       => false,
			'measured'    => array( 'silent_days' => $silencio, 'threshold_days' => $limiar, 'window_days' => $janela ),
			'started_at'  => $comecou,
			'headline'    => $anomalo
				? sprintf( 'Nenhum orçamento recebido há %d dias.', $silencio )
				: sprintf( 'Último orçamento há %d dia(s), dentro do normal.', $silencio ),
			'likely_cause' => $anomalo
				? 'Falha no envio do formulário (Turnstile, Tag Manager ou entrega de e-mail), ou queda real de demanda.'
				: '',
			'action'      => $anomalo
				? 'Enviar um orçamento de teste pelo site. Se chegar, o formulário está funcionando e a queda é de demanda; se não chegar, o problema é técnico e está no caminho de envio.'
				: '',
			'observed_on' => $agora->format( 'Y-m-d' ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_organic_windows' ) ) {
	/**
	 * Monta as duas janelas de comparação e afere a COMPLETUDE de cada uma.
	 *
	 * Função pura, e o coração do gatilho 2. Não usa
	 * `uonix_analytics_metrics_periods( null, 7 )`, e a razão foi medida em
	 * 2026-09-23: aquela função termina a janela atual em ontem (`53:378`), mas o
	 * Search Console não publica os últimos ~3 dias — a série terminava em 20/09 e
	 * 21, 22 e 23 não voltavam nem como zero. A janela atual perderia sempre 2 de 7
	 * dias enquanto a anterior está completa, o que dava **−51,8% sem mudança real**
	 * na projeção com o tráfego do site.
	 *
	 * ## O que a revisão do PR #293 corrigiu, e é a lição desta função
	 *
	 * A primeira versão ancorava as duas janelas na última data COM dado e derivava a
	 * defasagem do próprio dado. Isso resolvia o caso medido e **abria dois pontos
	 * cegos**, porque a API omite linha tanto para "ainda não publicado" quanto para
	 * "zero impressões" — os dois são o mesmo byte na resposta:
	 *
	 * - **Colapso a zero ficava invisível.** Site desindexado: a API para de devolver
	 *   linha, a ancoragem recua para o último dia bom, as duas janelas caem em
	 *   período saudável, e o módulo afirmava `0,0%` e "dentro do normal" durante um
	 *   apagão total de tráfego orgânico.
	 * - **Buraco no INTERIOR reproduzia o falso positivo original.** Três dias
	 *   ausentes no meio da janela atual, tratados como zero legítimo, davam −42,9%
	 *   com o tráfego real do site e disparavam e-mail de queda crítica. A ancoragem
	 *   não protegia, porque a última data estava presente.
	 *
	 * O sinal que separa os dois casos não é a última data: é **quantos dias estão
	 * presentes** em cada janela. Daí o desenho atual:
	 *
	 * 1. As janelas são de CALENDÁRIO FIXO, descontando `organic_settle_lag_days`
	 *    (4, medido 3 mais um dia de margem) do dia de hoje.
	 * 2. Cada janela declara quantos dos `$window_days` dias tem dado.
	 * 3. Quem consome decide: janela anterior incompleta não estabelece linha de
	 *    base; janela atual VAZIA com anterior cheia é colapso, e é anomalia;
	 *    janela atual parcialmente incompleta é INDISPONÍVEL, nunca "normal".
	 *
	 * Recusar é a única resposta honesta na incompletude parcial, porque o dado não
	 * distingue zero de ausente. Fixar a defasagem em constante só é seguro por causa
	 * do passo 2: sem a conferência de completude, um atraso do Google viraria falso
	 * positivo — que era exatamente a objeção que me fez derivá-la do dado, e que a
	 * completude responde melhor.
	 *
	 * A janela anterior é deslocada em exatamente `$window_days`, o que preserva a
	 * composição de dias da semana — sem isso, comparar 7 dias com dois fins de
	 * semana contra 7 com um já acusaria queda.
	 *
	 * @param array<int, string> $dates_present   Datas 'Y-m-d' devolvidas pela API.
	 * @param int                $window_days     Tamanho de cada janela.
	 * @param string             $requested_start Data inicial que foi PEDIDA.
	 * @param string             $today           Hoje, no fuso do site.
	 * @return array Janelas com contagem de dias presentes, ou `array( 'reason' => ... )`.
	 */
	function uonix_intelligence_anomaly_organic_windows( $dates_present, $window_days, $requested_start, $today ) {
		$regras = uonix_intelligence_anomaly_rules();
		$janela = is_int( $window_days ) && $window_days > 0 ? $window_days : (int) $regras['organic_window_days'];
		$atraso = (int) $regras['organic_settle_lag_days'];
		$fuso   = new DateTimeZone( 'UTC' );

		$presentes = array();
		foreach ( is_array( $dates_present ) ? $dates_present : array() as $data ) {
			if ( is_string( $data ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $data ) ) {
				$presentes[ $data ] = true;
			}
		}

		$agora  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, $fuso );
		$pedido = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $requested_start, $fuso );
		if ( false === $agora || false === $pedido ) {
			return array( 'reason' => 'series_dates_invalid' );
		}

		$atual_fim       = $agora->modify( '-' . $atraso . ' days' );
		$atual_inicio    = $atual_fim->modify( '-' . ( $janela - 1 ) . ' days' );
		$anterior_fim    = $atual_inicio->modify( '-1 day' );
		$anterior_inicio = $anterior_fim->modify( '-' . ( $janela - 1 ) . ' days' );

		if ( $anterior_inicio < $pedido ) {
			// Não pedimos histórico suficiente. Comparar contra janela anterior truncada
			// seria o mesmo defeito que esta função existe para evitar, do outro lado.
			return array( 'reason' => 'series_too_short' );
		}

		$contar = static function ( DateTimeImmutable $inicio, DateTimeImmutable $fim ) use ( $presentes ) {
			$n      = 0;
			$cursor = $inicio;
			while ( $cursor <= $fim ) {
				if ( isset( $presentes[ $cursor->format( 'Y-m-d' ) ] ) ) {
					++$n;
				}
				$cursor = $cursor->modify( '+1 day' );
			}
			return $n;
		};

		return array(
			'current'       => array( 'start' => $atual_inicio->format( 'Y-m-d' ), 'end' => $atual_fim->format( 'Y-m-d' ) ),
			'previous'      => array( 'start' => $anterior_inicio->format( 'Y-m-d' ), 'end' => $anterior_fim->format( 'Y-m-d' ) ),
			'window_days'   => $janela,
			'settle_lag'    => $atraso,
			'current_days'  => $contar( $atual_inicio, $atual_fim ),
			'previous_days' => $contar( $anterior_inicio, $anterior_fim ),
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
	 * O período pedido é derivado das regras, não escrito à mão: a janela anterior
	 * começa `organic_settle_lag_days + 2 * organic_window_days − 1` dias atrás.
	 * Pedir menos produziria `series_too_short`.
	 *
	 * Três desfechos possíveis, e a distinção entre eles é o que a revisão do PR #293
	 * obrigou a construir:
	 *
	 * - **Colapso.** Janela atual com ZERO dias de dado e anterior completa. É
	 *   anomalia, e é o caso que a versão anterior declarava "dentro do normal" com
	 *   variação `0,0%` durante um apagão total de tráfego orgânico.
	 * - **Incompleta.** Qualquer janela sem todos os dias. Indisponível, nunca
	 *   "normal": a API não distingue zero de ausente, então comparar janela furada
	 *   é justamente o falso positivo de −42,9% que a revisão mediu.
	 * - **Comparável.** As duas completas. Só aqui a variação percentual significa
	 *   algo.
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

		$recuo  = (int) $regras['organic_settle_lag_days'] + ( 2 * $janela );
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
		$medido   = array( 'current' => $atual, 'previous' => $anterior, 'windows' => $janelas );

		// Sem linha de base não há o que comparar, e "sem dado" nunca é "sem anomalia".
		if ( (int) $janelas['previous_days'] < $janela ) {
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'baseline_incomplete', array( 'measured' => $medido ) );
		}
		if ( $anterior < (float) $regras['organic_min_impressions'] ) {
			// Piso de ruído: com pouquíssima impressão a variação percentual é aleatória.
			// Um site que saiu de 4 para 2 impressões caiu 50% sem significar nada.
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'baseline_too_small', array( 'measured' => $medido ) );
		}

		// COLAPSO: a semana inteira sem uma única linha, contra uma semana anterior
		// cheia. A API omite dia sem impressão, então zero e ausente são o mesmo byte —
		// e é por isso que este caso precisa de tratamento próprio em vez de virar uma
		// comparação de 0,0%.
		if ( 0 === (int) $janelas['current_days'] ) {
			return array(
				'trigger'      => 'organic_drop',
				'available'    => true,
				'reason'       => '',
				'anomalous'    => true,
				'source'       => 'search_console',
				'synced_at'    => $agora->format( 'c' ),
				'stale'        => false,
				'measured'     => $medido,
				'started_at'   => (string) $janelas['current']['start'],
				'headline'     => 'O site parou de aparecer na busca: nenhuma impressão registrada na última semana.',
				'likely_cause' => 'Desindexação por penalização manual, `noindex` acidental, bloqueio no robots.txt, ou indisponibilidade prolongada do Search Console.',
				'action'       => 'Conferir Ações Manuais e Cobertura no Search Console com urgência, e verificar robots.txt e as meta tags de indexação do site.',
				'observed_on'  => (string) $janelas['current']['end'],
			);
		}

		if ( (int) $janelas['current_days'] < $janela ) {
			// Incompleta sem estar vazia: pode ser atraso do Google ou dia de zero real, e
			// o dado não diz qual. Recusar é a única resposta honesta — comparar produziria
			// a queda artificial de −42,9% que a revisão do PR #293 mediu.
			return uonix_intelligence_anomaly_unavailable( 'organic_drop', 'series_incomplete', array( 'measured' => $medido ) );
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
		$medido['delta_percent'] = $variacao;
		$medido['threshold']     = $limiar;

		return array(
			'trigger'      => 'organic_drop',
			'available'    => true,
			'reason'       => '',
			'anomalous'    => $anomalo,
			'source'       => 'search_console',
			'synced_at'    => $agora->format( 'c' ),
			'stale'        => false,
			'measured'     => $medido,
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
			'observed_on'  => (string) $janelas['current']['end'],
		);
	}
}

// ---------------------------------------------------------------------------
// Estado, deduplicação e agregação.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_anomaly_state_option' ) ) {
	/**
	 * Opção única com o resultado da última verificação.
	 *
	 * Guarda duas coisas sob o mesmo nome: `triggers`, o estado por gatilho que
	 * alimenta a deduplicação, e `summary`, o resultado completo que o painel
	 * renderiza.
	 *
	 * **Uma opção e não duas**, porque as duas são escritas juntas pela mesma
	 * verificação e precisam da mesma proteção de clone. Separá-las criaria a
	 * possibilidade de uma ser protegida e a outra não, e de divergirem.
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

if ( ! function_exists( 'uonix_intelligence_anomaly_read_option' ) ) {
	/**
	 * Conteúdo bruto da opção, sempre como array.
	 */
	function uonix_intelligence_anomaly_read_option() {
		$salvo = function_exists( 'get_option' ) ? get_option( uonix_intelligence_anomaly_state_option(), array() ) : array();

		return is_array( $salvo ) ? $salvo : array();
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_get_state' ) ) {
	/**
	 * Estado da última verificação: `array<string, bool>` por gatilho.
	 */
	function uonix_intelligence_anomaly_get_state() {
		$salvo    = uonix_intelligence_anomaly_read_option();
		$gatilhos = isset( $salvo['triggers'] ) && is_array( $salvo['triggers'] ) ? $salvo['triggers'] : array();

		$estado = array();
		foreach ( $gatilhos as $gatilho => $ativo ) {
			if ( is_string( $gatilho ) && '' !== $gatilho ) {
				$estado[ $gatilho ] = (bool) $ativo;
			}
		}

		return $estado;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_get_alert_meta' ) ) {
	/**
	 * Contabilidade de aviso por gatilho: desde quando, quantas tentativas, e se o
	 * aviso ficou sem entrega.
	 *
	 * Vive ao lado de `triggers` em vez de dentro, para `state_from_findings()` e
	 * `transitions()` continuarem puras e operando sobre `array<string, bool>` — são
	 * as duas funções mais testadas do módulo, e mudar a forma delas para acomodar
	 * contabilidade seria trocar cobertura por conveniência.
	 *
	 * **Existe porque "já avisei" e "já tentei" são coisas diferentes**, e a versão
	 * anterior tratava as duas como uma só. Ver `uonix_intelligence_anomaly_run_check()`.
	 *
	 * @return array<string, array{since: string, attempts: int, undelivered: bool}>
	 */
	function uonix_intelligence_anomaly_get_alert_meta() {
		$salvo = uonix_intelligence_anomaly_read_option();
		$bruto = isset( $salvo['meta'] ) && is_array( $salvo['meta'] ) ? $salvo['meta'] : array();

		$meta = array();
		foreach ( $bruto as $gatilho => $dados ) {
			if ( ! is_string( $gatilho ) || '' === $gatilho || ! is_array( $dados ) ) {
				continue;
			}
			$meta[ $gatilho ] = array(
				'since'       => isset( $dados['since'] ) ? (string) $dados['since'] : '',
				'attempts'    => isset( $dados['attempts'] ) ? (int) $dados['attempts'] : 0,
				'undelivered' => ! empty( $dados['undelivered'] ),
			);
		}

		return $meta;
	}
}

if ( ! function_exists( 'uonix_intelligence_anomaly_get_summary' ) ) {
	/**
	 * Resultado da última verificação, para o painel renderizar SEM recomputar.
	 *
	 * O painel não pode chamar `uonix_intelligence_anomaly_detect()`: ele faz uma
	 * consulta ao banco e uma chamada à API da Search Console, e o badge aparece no
	 * rótulo da aba, que é renderizado em TODA carga da tela. Recomputar ali
	 * significaria uma chamada de API por pageview, e um painel que fica lento
	 * exatamente quando o Google está lento.
	 *
	 * É o mesmo princípio que o painel de métricas já segue: renderizar do snapshot,
	 * nunca da API. Quem computa é o evento diário.
	 *
	 * `checked_at` vazio significa "nunca verificado", que é estado diferente de
	 * "verificado e normal" — e o painel precisa dizer qual dos dois é.
	 */
	function uonix_intelligence_anomaly_get_summary() {
		$salvo   = uonix_intelligence_anomaly_read_option();
		$resumo  = isset( $salvo['summary'] ) && is_array( $salvo['summary'] ) ? $salvo['summary'] : array();

		return array(
			'findings'    => isset( $resumo['findings'] ) && is_array( $resumo['findings'] ) ? $resumo['findings'] : array(),
			'anomalous'   => isset( $resumo['anomalous'] ) ? (int) $resumo['anomalous'] : 0,
			'unavailable' => isset( $resumo['unavailable'] ) ? (int) $resumo['unavailable'] : 0,
			'checked_at'  => isset( $resumo['checked_at'] ) ? (string) $resumo['checked_at'] : '',
		);
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
	function uonix_intelligence_anomaly_detect( $findings = null, $today = null, $previous_state = null ) {
		if ( ! is_array( $findings ) ) {
			$findings = array(
				uonix_intelligence_anomaly_lead_silence( $today ),
				uonix_intelligence_anomaly_organic_drop( null, null, $today ),
			);
		}
		$anterior = is_array( $previous_state ) ? $previous_state : uonix_intelligence_anomaly_get_state();
		$meta     = uonix_intelligence_anomaly_get_alert_meta();

		$anomalos      = 0;
		$indisponiveis = 0;
		$saida         = array();

		foreach ( $findings as $achado ) {
			if ( ! is_array( $achado ) ) {
				continue;
			}
			$gatilho = isset( $achado['trigger'] ) ? (string) $achado['trigger'] : '';

			if ( empty( $achado['available'] ) ) {
				// **Anomalia ativa não pode desaparecer da tela porque a fonte falhou hoje.**
				// `state_from_findings()` já preserva o sinalizador, mas o badge lê o resumo:
				// sem isto, uma queda de tráfego em curso e já notificada era rebaixada de
				// "1 anomalia crítica" para "1 verificação indisponível", e o detalhe do
				// incidente sumia — por todos os dias que a falha de fonte durasse.
				//
				// `stale_anomaly` diz ao painel que a anomalia foi detectada antes e não foi
				// reverificada hoje, o que é diferente tanto de "está acontecendo agora"
				// quanto de "não sei nada".
				if ( '' !== $gatilho && ! empty( $anterior[ $gatilho ] ) ) {
					$achado['stale_anomaly'] = true;
					$achado['stale']         = true;
					$achado['started_at']    = isset( $meta[ $gatilho ]['since'] ) ? (string) $meta[ $gatilho ]['since'] : '';
					++$anomalos;
					$saida[] = $achado;
					continue;
				}
				++$indisponiveis;
				$saida[] = $achado;
				continue;
			}

			if ( ! empty( $achado['anomalous'] ) ) {
				++$anomalos;
			}
			$saida[] = $achado;
		}

		return array(
			'findings'    => $saida,
			'anomalous'   => $anomalos,
			'unavailable' => $indisponiveis,
			'checked_at'  => uonix_intelligence_anomaly_now( $today )->format( 'c' ),
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
	 * A ordem importa, e a regra de persistência é a parte não óbvia: **"já avisei" e
	 * "já tentei" são coisas diferentes.**
	 *
	 * A versão anterior tratava as duas como uma só, e a revisão do PR #293 mostrou o
	 * dano com o fonte do PHPMailer na mão. Eu havia escrito que não persistir a
	 * transição num envio falho "não gera enxurrada, porque nos dois casos nada é
	 * entregue". **Isso é falso.** `smtpSend()` transmite o `DATA` se houver ao menos
	 * um destinatário aceito, e só DEPOIS lança `recipients_failed`; `send()` devolve
	 * `false`. Logo um endereço com typo na lista faz os bons receberem a mensagem
	 * com `wp_mail()` devolvendo falso — e a retentativa ilimitada entregava **14
	 * e-mails idênticos** num episódio de 14 dias, que é exatamente o resultado que a
	 * deduplicação existe para impedir.
	 *
	 * As três situações agora são distintas:
	 *
	 * - **Sucesso.** Persiste o sinalizador e zera as tentativas.
	 * - **Lista vazia** (`no_recipients`). Nada foi tentado, então não conta
	 *   tentativa e o sinalizador não avança. É o caso em que a retentativa é
	 *   provadamente inofensiva, e o desejado: quem cadastrar o endereço com uma
	 *   anomalia em curso recebe o aviso na verificação seguinte.
	 * - **Falha de envio** (`mail_failed`). Pode ter entregado em parte, então a
	 *   retentativa tem TETO (`alert_max_attempts`). Esgotado o teto, o sinalizador
	 *   avança e o episódio fica marcado `undelivered`, para o painel poder dizer
	 *   "anomalia detectada, aviso não entregue". Assim o episódio não é silenciado
	 *   *nem* vira enxurrada — antes o código escolhia um dos dois males sem poder
	 *   saber qual.
	 */
	function uonix_intelligence_anomaly_run_check( $findings = null, $today = null ) {
		$anterior   = uonix_intelligence_anomaly_get_state();
		$meta       = uonix_intelligence_anomaly_get_alert_meta();
		$resumo     = uonix_intelligence_anomaly_detect( $findings, $today, $anterior );
		$transicoes = uonix_intelligence_anomaly_transitions( $resumo['findings'], $anterior );
		$novo       = uonix_intelligence_anomaly_state_from_findings( $resumo['findings'], $anterior );
		$teto       = (int) uonix_intelligence_anomaly_rules()['alert_max_attempts'];
		$hoje       = uonix_intelligence_anomaly_now( $today )->format( 'Y-m-d' );

		$envio = array( 'sent' => false, 'reason' => 'no_transition', 'recipients' => 0 );
		if ( array() !== $transicoes ) {
			$envio = uonix_intelligence_anomaly_send_alert( $transicoes );

			foreach ( $transicoes as $achado ) {
				$gatilho = (string) $achado['trigger'];
				$desde   = isset( $achado['started_at'] ) && '' !== $achado['started_at'] ? (string) $achado['started_at'] : $hoje;

				if ( ! empty( $envio['sent'] ) ) {
					$meta[ $gatilho ] = array( 'since' => $desde, 'attempts' => 0, 'undelivered' => false );
					continue;
				}

				if ( 'no_recipients' === $envio['reason'] ) {
					// Nada foi tentado: não conta tentativa, e o sinalizador não avança.
					$novo[ $gatilho ] = isset( $anterior[ $gatilho ] ) ? (bool) $anterior[ $gatilho ] : false;
					continue;
				}

				$tentativas = ( isset( $meta[ $gatilho ]['attempts'] ) ? (int) $meta[ $gatilho ]['attempts'] : 0 ) + 1;
				$esgotou    = $tentativas >= $teto;
				$meta[ $gatilho ] = array(
					'since'       => $desde,
					'attempts'    => $tentativas,
					'undelivered' => $esgotou,
				);
				if ( ! $esgotou ) {
					$novo[ $gatilho ] = isset( $anterior[ $gatilho ] ) ? (bool) $anterior[ $gatilho ] : false;
				}
			}
		}

		// Gatilho que voltou ao normal perde a contabilidade: ela descreve um episódio,
		// e o episódio acabou. Sem isso, um episódio novo herdaria as tentativas do
		// anterior e poderia nascer com o teto já esgotado, nunca avisando.
		//
		// O critério é o ACHADO desta rodada, não `$novo`. Durante uma retentativa o
		// sinalizador é mantido em falso de propósito, e limpar por ele apagaria a
		// contagem de tentativas a cada rodada — o teto nunca seria alcançado e a
		// enxurrada que este mecanismo existe para impedir voltaria inteira.
		foreach ( $resumo['findings'] as $achado ) {
			if ( ! is_array( $achado ) || ! isset( $achado['trigger'] ) ) {
				continue;
			}
			$gatilho = (string) $achado['trigger'];
			if ( ! empty( $achado['available'] ) && empty( $achado['anomalous'] ) ) {
				unset( $meta[ $gatilho ] );
			}
		}

		if ( function_exists( 'update_option' ) ) {
			// `summary` é gravado sempre, inclusive quando o envio falhou: o painel deve
			// mostrar o que foi medido agora, independentemente de o e-mail ter saído.
			// Só `triggers` carrega a semântica de "já avisei".
			update_option(
				uonix_intelligence_anomaly_state_option(),
				array( 'triggers' => $novo, 'meta' => $meta, 'summary' => $resumo ),
				false
			);
		}

		return array( 'summary' => $resumo, 'transitions' => count( $transicoes ), 'send' => $envio, 'meta' => $meta );
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
//
// `accepted_args = 0` é CARGA ESTRUTURAL aqui, não formalidade. `run_check()`
// aceita achados prontos, porque é assim que o teste exercita a deduplicação sem
// banco nem rede. Se o hook fosse registrado com argumentos, um evento de cron
// forjado poderia passar achados arbitrários e fazer o módulo enviar e-mail de
// anomalia inventada. O zero é o que fecha essa porta.
add_action( uonix_intelligence_anomaly_hook(), 'uonix_intelligence_anomaly_run_check', 10, 0 );

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
