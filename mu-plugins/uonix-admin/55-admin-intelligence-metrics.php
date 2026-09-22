<?php
/**
 * Central de Inteligência — camada de dados.
 *
 * Detecta oportunidades acionáveis a partir do snapshot já sincronizado pelo
 * 53-admin-analytics-metrics.php. Não faz chamada de rede, não agenda cron e não
 * renderiza nada: consome o snapshot existente e devolve estrutura pronta para o
 * painel e para o e-mail.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_seo_rules' ) ) {
	/**
	 * Limiares da regra "Vitórias Fáceis" (striking distance).
	 *
	 * A issue #193 apresenta três faixas divergentes para a posição (4-15, 4-12 e
	 * 4-10). O contrato fixa 4 a 12. `max_ctr` está em fração, não em porcentagem,
	 * porque é assim que a Search Console API devolve o CTR.
	 *
	 * `min_impressions` é PISO DE RUÍDO, não critério de relevância. A priorização
	 * por volume é feita por ordenação: a função ordena os candidatos por impressões
	 * decrescentes e devolve os primeiros. Quem separa oportunidade boa de ruim é o
	 * ranking, não o piso.
	 *
	 * O valor anterior era 100 e vinha da especificação de produto, não de medição.
	 * Medido em produção em 2026-09-22: a consulta de maior volume do site inteiro
	 * tem 106 impressões em 30 dias, então o critério eliminava 110 das 111 consultas
	 * e o módulo devolvia zero por construção. O piso atual existe só para não
	 * reportar consulta cujo CTR é estatisticamente sem sentido — abaixo de seis
	 * impressões, um único clique já produz mais de 15% de taxa.
	 */
	function uonix_intelligence_seo_rules() {
		return array(
			'min_position'    => 4.0,
			'max_position'    => 12.0,
			'min_impressions' => 5,
			'max_ctr'         => 0.03,
			'period_days'     => 30,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_seo_differentiators' ) ) {
	/**
	 * Diferenciais técnicos sugeridos para Title/Description, e os termos cuja
	 * presença na consulta indica que o diferencial já está coberto.
	 *
	 * Determinístico por desenho: nenhuma chamada a LLM nesta camada. Integração
	 * com Gemini é escopo separado, conforme o contrato.
	 */
	function uonix_intelligence_seo_differentiators() {
		return array(
			array( 'label' => 'Aço Inox 304/316', 'covered_by' => array( 'inox', 'aco inox', 'aço inox', '304', '316' ) ),
			array( 'label' => 'Laudo com ART', 'covered_by' => array( 'laudo', 'art', 'engenheiro' ) ),
			array( 'label' => 'Conforme NBR 16325', 'covered_by' => array( 'nbr', 'norma', '16325', 'nr 35', 'nr35' ) ),
			array( 'label' => 'Ensaio de Arrancamento', 'covered_by' => array( 'ensaio', 'arrancamento', 'teste de carga' ) ),
			array( 'label' => 'Pronta Entrega', 'covered_by' => array( 'entrega', 'prazo', 'estoque' ) ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_normalize_term' ) ) {
	/**
	 * Normaliza para comparação: minúsculas e acentos reduzidos, sem depender de
	 * intl/iconv, que não estão garantidos no host.
	 */
	function uonix_intelligence_normalize_term( $value ) {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
		$map = array(
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
			'é' => 'e', 'ê' => 'e', 'è' => 'e',
			'í' => 'i', 'î' => 'i',
			'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
			'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ç' => 'c',
		);
		return strtr( $value, $map );
	}
}

if ( ! function_exists( 'uonix_intelligence_title_suggestion' ) ) {
	/**
	 * Sugere até dois diferenciais ausentes da consulta, para entrar no Title.
	 *
	 * Determinístico: mesma consulta produz sempre a mesma sugestão, na ordem de
	 * `uonix_intelligence_seo_differentiators()`. Devolve lista vazia quando a
	 * consulta já cobre todos os diferenciais — nesse caso não há sugestão honesta
	 * a dar, e a ausência é informação, não falha.
	 */
	function uonix_intelligence_title_suggestion( $query, $max = 2 ) {
		$haystack = uonix_intelligence_normalize_term( $query );
		if ( '' === $haystack ) {
			return array();
		}
		$max = is_int( $max ) && $max > 0 ? $max : 2;
		$suggestions = array();
		foreach ( uonix_intelligence_seo_differentiators() as $differentiator ) {
			$covered = false;
			foreach ( $differentiator['covered_by'] as $token ) {
				if ( false !== strpos( $haystack, uonix_intelligence_normalize_term( $token ) ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$suggestions[] = $differentiator['label'];
			}
			if ( count( $suggestions ) >= $max ) {
				break;
			}
		}
		return $suggestions;
	}
}

if ( ! function_exists( 'uonix_intelligence_unavailable' ) ) {
	/**
	 * Resposta padronizada quando não há base para afirmar nada.
	 *
	 * Fail-soft e explícito: o consumidor recebe o motivo, não um array vazio que
	 * se confunde com "nenhuma oportunidade encontrada". São estados diferentes.
	 */
	function uonix_intelligence_unavailable( $reason, $extra = array() ) {
		return array_merge(
			array(
				'available'   => false,
				'reason'      => (string) $reason,
				'source'      => 'search_console',
				'synced_at'   => '',
				'stale'       => true,
				'period_days' => uonix_intelligence_seo_rules()['period_days'],
				'universe'    => 0,
				'rows'        => array(),
			),
			is_array( $extra ) ? $extra : array()
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_seo_opportunities' ) ) {
	/**
	 * Oportunidades de SEO em distância de salto, a partir do snapshot de 30 dias.
	 *
	 * Cada resposta carrega `source`, `synced_at` e `stale` para que o bloco que a
	 * renderize possa declarar sua própria procedência, inclusive declarar-se
	 * desatualizado sozinho — exigência do contrato.
	 *
	 * @param array|false|null $snapshot Snapshot já carregado, ou null para ler.
	 * @param int              $limit    Máximo de linhas devolvidas.
	 */
	function uonix_intelligence_seo_opportunities( $snapshot = null, $limit = 5 ) {
		$rules = uonix_intelligence_seo_rules();
		$limit = is_int( $limit ) && $limit > 0 ? $limit : 5;

		if ( null === $snapshot ) {
			$snapshot = function_exists( 'uonix_analytics_metrics_get_snapshot' )
				? uonix_analytics_metrics_get_snapshot( $rules['period_days'] )
				: false;
		}
		if ( ! is_array( $snapshot ) ) {
			return uonix_intelligence_unavailable( 'snapshot_missing' );
		}

		$synced_at = isset( $snapshot['updated_at'] ) ? (string) $snapshot['updated_at'] : '';
		$stale     = function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' )
			? ! uonix_analytics_metrics_snapshot_is_fresh( $snapshot )
			: true;
		$context   = array( 'synced_at' => $synced_at, 'stale' => $stale );

		// Snapshot legado (v1) não tem `period_days`, e `mark_stale()` pode promover um
		// payload legado para a chave corrente. Sem esta distinção, a ausência do campo
		// seria reportada como "período divergente" — diagnóstico falso que manda quem
		// depura olhar para o seletor de período em vez de para a migração.
		if ( ! isset( $snapshot['period_days'] ) ) {
			return uonix_intelligence_unavailable( 'snapshot_legacy', $context );
		}

		// Os limiares são expressos em 30 dias. Aplicá-los a outro período produziria
		// número sem significado, então é recusa explícita, não adaptação silenciosa.
		if ( (int) $snapshot['period_days'] !== $rules['period_days'] ) {
			return uonix_intelligence_unavailable( 'period_mismatch', $context );
		}

		// Snapshot v2 não tem `queries_extended`. Ausência é dado insuficiente, jamais
		// zero oportunidades: com 10 consultas a regra não teria o que peneirar.
		if ( ! isset( $snapshot['search_console']['queries_extended'] ) || ! is_array( $snapshot['search_console']['queries_extended'] ) ) {
			return uonix_intelligence_unavailable( 'queries_extended_missing', $context );
		}
		$universe = $snapshot['search_console']['queries_extended'];

		$matches = array();
		foreach ( $universe as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['query'], $row['impressions'], $row['ctr'], $row['position'] ) ) {
				continue;
			}
			$query       = (string) $row['query'];
			$impressions = (float) $row['impressions'];
			$ctr         = (float) $row['ctr'];
			$position    = (float) $row['position'];
			if ( '' === $query ) {
				continue;
			}
			if ( $position < $rules['min_position'] || $position > $rules['max_position'] ) {
				continue;
			}
			if ( $impressions <= $rules['min_impressions'] ) {
				continue;
			}
			if ( $ctr >= $rules['max_ctr'] ) {
				continue;
			}
			$matches[] = array(
				'query'       => $query,
				'position'    => $position,
				'impressions' => $impressions,
				'ctr'         => $ctr,
				'clicks'      => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0,
				'suggestion'  => uonix_intelligence_title_suggestion( $query ),
			);
		}

		// Maior volume primeiro: entre duas consultas igualmente próximas do topo, a
		// de mais impressões devolve mais clique pelo mesmo esforço. Empate resolvido
		// pela consulta, para a ordem ser estável entre execuções.
		usort(
			$matches,
			function ( $a, $b ) {
				if ( $a['impressions'] === $b['impressions'] ) {
					return strcmp( $a['query'], $b['query'] );
				}
				return ( $a['impressions'] < $b['impressions'] ) ? 1 : -1;
			}
		);

		return array(
			'available'   => true,
			'reason'      => '',
			'source'      => 'search_console',
			'synced_at'   => $synced_at,
			'stale'       => $stale,
			'period_days' => $rules['period_days'],
			'universe'    => count( $universe ),
			'rows'        => array_slice( $matches, 0, $limit ),
		);
	}
}

// ---------------------------------------------------------------------------
// Destinatários do relatório executivo.
//
// E-mail de destinatário não é segredo, então `wp_options` é legítimo aqui — ao
// contrário de token de API, que fica em constante no wp-config conforme o
// contrato. Ver docs/uonix-insights-inteligencia.md.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_recipients_option' ) ) {
	function uonix_intelligence_recipients_option() {
		return 'uonix_executive_report_recipients';
	}
}

if ( ! function_exists( 'uonix_intelligence_report_hook' ) ) {
	/**
	 * Nome do evento de cron do relatório executivo.
	 *
	 * Existe como acessor, e não como string solta, porque quem exibe o próximo
	 * disparo e quem agenda o envio são arquivos diferentes. Se cada um escrevesse
	 * o nome à mão, uma divergência faria o painel afirmar "não agendado" para
	 * sempre, sem nada reprovar.
	 */
	function uonix_intelligence_report_hook() {
		return 'uonix_intelligence_weekly_report';
	}
}

if ( ! function_exists( 'uonix_intelligence_recipients_limit' ) ) {
	/**
	 * Teto de destinatários.
	 *
	 * Não é limitação técnica: é contenção de dano. Uma lista que cresce sem limite
	 * transforma um relatório interno em lista de distribuição, e cada endereço a
	 * mais é uma cópia de dado de desempenho comercial fora do controle.
	 */
	function uonix_intelligence_recipients_limit() {
		return 10;
	}
}

if ( ! function_exists( 'uonix_intelligence_sanitize_recipients' ) ) {
	/**
	 * Normaliza uma lista de destinatários.
	 *
	 * Aceita array ou texto com um endereço por linha (também tolera vírgula e
	 * ponto-e-vírgula como separadores). Descarta o que não for e-mail válido,
	 * deduplica sem diferenciar caixa e respeita o teto.
	 *
	 * @return array{recipients: array<int, string>, rejected: int}
	 */
	function uonix_intelligence_sanitize_recipients( $raw ) {
		if ( is_string( $raw ) ) {
			$partes = preg_split( '/[\r\n,;]+/', $raw );
			$raw    = is_array( $partes ) ? $partes : array();
		}
		if ( ! is_array( $raw ) ) {
			return array( 'recipients' => array(), 'rejected' => 0 );
		}

		$aceitos  = array();
		$vistos   = array();
		$recusados = 0;
		$limite   = uonix_intelligence_recipients_limit();

		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				++$recusados;
				continue;
			}
			$email = trim( (string) $item );
			if ( '' === $email ) {
				continue;
			}
			$email = function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : $email;
			if ( '' === $email || ( function_exists( 'is_email' ) && ! is_email( $email ) ) ) {
				++$recusados;
				continue;
			}
			$chave = strtolower( $email );
			if ( isset( $vistos[ $chave ] ) ) {
				continue;
			}
			if ( count( $aceitos ) >= $limite ) {
				++$recusados;
				continue;
			}
			$vistos[ $chave ] = true;
			$aceitos[]        = $email;
		}

		return array( 'recipients' => $aceitos, 'rejected' => $recusados );
	}
}

if ( ! function_exists( 'uonix_intelligence_get_recipients' ) ) {
	function uonix_intelligence_get_recipients() {
		$saved = function_exists( 'get_option' ) ? get_option( uonix_intelligence_recipients_option(), array() ) : array();
		$normalizado = uonix_intelligence_sanitize_recipients( is_array( $saved ) ? $saved : array() );
		return $normalizado['recipients'];
	}
}

if ( ! function_exists( 'uonix_intelligence_save_recipients' ) ) {
	/**
	 * Persiste a lista de destinatários.
	 *
	 * Escrita exige `manage_options` e nonce, espelhando o refresh manual das
	 * métricas em 53. A visualização do painel segue `edit_posts`, como o resto do
	 * Insights: ler quem recebe é diferente de mudar quem recebe.
	 */
	function uonix_intelligence_save_recipients() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão para alterar os destinatários do relatório.', 'uonix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'uonix_intelligence_save_recipients' );

		// Aceita texto (um por linha) e também array, que é o formato de um campo
		// repetido. Tratar array como entrada inválida faria um POST legítimo
		// `uonix_recipients[]=` apagar a lista inteira e reportar sucesso.
		$raw = isset( $_POST['uonix_recipients'] ) ? wp_unslash( $_POST['uonix_recipients'] ) : '';
		if ( is_array( $raw ) ) {
			$entrada = $raw;
		} elseif ( is_scalar( $raw ) ) {
			$entrada = (string) $raw;
		} else {
			$entrada = '';
		}
		$normalizado = uonix_intelligence_sanitize_recipients( $entrada );

		if ( function_exists( 'update_option' ) ) {
			update_option( uonix_intelligence_recipients_option(), $normalizado['recipients'], false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'uonix-analytics',
					'tab'  => 'settings',
					'uonix_recipients_saved'    => count( $normalizado['recipients'] ),
					'uonix_recipients_rejected' => $normalizado['rejected'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
add_action( 'admin_post_uonix_intelligence_save_recipients', 'uonix_intelligence_save_recipients' );
