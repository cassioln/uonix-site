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
	 */
	function uonix_intelligence_seo_rules() {
		return array(
			'min_position'    => 4.0,
			'max_position'    => 12.0,
			'min_impressions' => 100,
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
