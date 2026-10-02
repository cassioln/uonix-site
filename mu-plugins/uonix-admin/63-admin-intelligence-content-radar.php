<?php
/**
 * Central de Inteligência — Módulo 8, Radar de Pautas.
 *
 * Uma vez por dia, busca 90 dias de consultas no Search Console e seleciona as que se
 * repetem, mas em que o site aparece mal (posição média acima de 15). Para cada candidata,
 * o Gemini propõe uma pauta: post novo ou reforço da página que já aparece, com título,
 * ângulo e intenção de busca. O painel (56) e o e-mail (57) só leem o que este cron gravou.
 *
 * Privacidade (#253): o texto de consulta só é gravado para as candidatas, e passa antes
 * pelo mesmo saneamento do snapshot (`uonix_analytics_metrics_sanitize_query()`, no 53).
 * Descartes e envios guardam só o hash da consulta normalizada.
 *
 * Nada é aplicado no WordPress: a pauta é para quem escreve os posts.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_radar_rules' ) ) {
	/**
	 * Regras do Radar. Medido em 2026-10-02: 163 consultas em 90 dias, e de 5 a 10 passam
	 * nesta regra. A forma de pergunta não é fonte: 12 consultas, 1 impressão cada.
	 *
	 * `min_position` é exclusivo: a posição média tem de ser MAIOR. A faixa do Módulo 3 vai
	 * até 12 (55), então de 12 a 15 nenhum dos dois lista: a consulta já está perto do topo.
	 * `ai_budget` conta do início da execução: depois dele, nenhuma chamada ao Gemini
	 * começa. O `crontab` de produção corta em 290 s (lição da #335). `head_max` limita os
	 * HEADs que seguem 301 (#343): 10 × 8 s no pior caso.
	 */
	function uonix_intelligence_radar_rules() {
		return array(
			'window_days'     => 90,
			'lag_days'        => 3,
			'min_position'    => 15.0,
			'min_impressions' => 5,
			'max_candidates'  => 10,
			'email_limit'     => 3,
			'emailed_ttl'     => 180 * DAY_IN_SECONDS,
			'ai_budget'       => 150,
			'rows'            => 1000,
			'head_max'        => 10,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_option' ) ) {
	function uonix_intelligence_radar_option() {
		return 'uonix_intelligence_content_radar';
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_dismissed_option' ) ) {
	function uonix_intelligence_radar_dismissed_option() {
		return 'uonix_intelligence_content_radar_dismissed';
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_emailed_option' ) ) {
	function uonix_intelligence_radar_emailed_option() {
		return 'uonix_intelligence_content_radar_emailed';
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_hook' ) ) {
	function uonix_intelligence_radar_hook() {
		return 'uonix_intelligence_content_radar_daily';
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_window' ) ) {
	/**
	 * Janela de 90 dias que termina 3 dias antes de `$hoje` (Y-m-d): a Search Console
	 * publica com uns 3 dias de atraso. Data inválida devolve array vazio.
	 *
	 * @return array{start?: string, end?: string}
	 */
	function uonix_intelligence_radar_window( $hoje ) {
		$dia = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $hoje, new DateTimeZone( 'UTC' ) );
		if ( false === $dia || $dia->format( 'Y-m-d' ) !== (string) $hoje ) {
			return array();
		}
		$regras = uonix_intelligence_radar_rules();
		$fim    = $dia->modify( '-' . (int) $regras['lag_days'] . ' days' );
		$inicio = $fim->modify( '-' . ( (int) $regras['window_days'] - 1 ) . ' days' );

		return array( 'start' => $inicio->format( 'Y-m-d' ), 'end' => $fim->format( 'Y-m-d' ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_window_label' ) ) {
	/**
	 * "02/07 a 29/09", para o painel e o e-mail.
	 */
	function uonix_intelligence_radar_window_label( $janela ) {
		if ( ! is_array( $janela ) || ! isset( $janela['start'], $janela['end'] ) ) {
			return '';
		}
		$fuso   = new DateTimeZone( 'UTC' );
		$inicio = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $janela['start'], $fuso );
		$fim    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $janela['end'], $fuso );

		return false === $inicio || false === $fim ? '' : $inicio->format( 'd/m' ) . ' a ' . $fim->format( 'd/m' );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_normalize_query' ) ) {
	/**
	 * Minúsculas, sem acento e com os espaços reduzidos: "Ancoragem  Prédial" e
	 * "ancoragem predial" são a mesma consulta para o Radar.
	 */
	function uonix_intelligence_radar_normalize_query( $consulta ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', uonix_intelligence_normalize_term( (string) $consulta ) ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_query_key' ) ) {
	/**
	 * Chave de uma consulta: sha256 da forma normalizada. É o que descartes e envios
	 * guardam, no lugar do texto (#253).
	 */
	function uonix_intelligence_radar_query_key( $consulta ) {
		return hash( 'sha256', uonix_intelligence_radar_normalize_query( $consulta ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_is_key' ) ) {
	function uonix_intelligence_radar_is_key( $valor ) {
		return is_string( $valor ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $valor );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_is_noise' ) ) {
	/**
	 * Consulta que não é lacuna de conteúdo.
	 *
	 * - Aspas ou operador de busca (`site:`, `inurl:`…): medido em 2026-10-02, 4 das 15
	 *   maiores acima da posição 15 eram buscas de spammer por blogs com comentário
	 *   aberto, sempre entre aspas.
	 * - A marca: quem busca "uônix" procura a empresa, não um assunto.
	 */
	function uonix_intelligence_radar_is_noise( $consulta ) {
		$texto = (string) $consulta;
		if ( false !== strpos( $texto, '"' ) || false !== strpos( $texto, '“' ) || false !== strpos( $texto, '”' ) ) {
			return true;
		}
		$normal = uonix_intelligence_radar_normalize_query( $texto );
		if ( 1 === preg_match( '/(^|\s)(site|inurl|intitle|intext|allinurl|allintitle):/', $normal ) ) {
			return true;
		}

		return false !== strpos( $normal, 'uonix' );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_select' ) ) {
	/**
	 * Candidatas do Radar, a partir das duas respostas do Search Console. Pura: não lê
	 * opção nem faz rede.
	 *
	 * - A página líder é a de MAIS impressões para a consulta. Somar linhas de página
	 *   contaria duas vezes a impressão em que duas páginas do site aparecem juntas.
	 * - O texto passa por `uonix_analytics_metrics_sanitize_query()` (53), o mesmo
	 *   saneamento do snapshot. Consulta recusada (e-mail, telefone, link) sai inteira.
	 * - Duas grafias com a mesma chave viram uma candidata: a de mais impressões.
	 * - O limite de 10 conta DEPOIS de tirar as descartadas, que voltam à parte (até 10),
	 *   para a área "Descartadas" do painel.
	 *
	 * @param array $consultas Linhas da dimensão `query`.
	 * @param array $paginas   Linhas das dimensões `query` e `page`.
	 * @param array $descartes Chave => data, de `uonix_intelligence_radar_dismissed()`.
	 * @return array{visible: array, dismissed: array}
	 */
	function uonix_intelligence_radar_select( array $consultas, array $paginas, array $descartes = array() ) {
		$regras = uonix_intelligence_radar_rules();

		$lider = array();
		foreach ( $paginas as $linha ) {
			if ( ! is_array( $linha ) || ! isset( $linha['keys'][0], $linha['keys'][1] ) || ! is_string( $linha['keys'][0] ) || ! is_string( $linha['keys'][1] ) ) {
				continue;
			}
			$consulta = $linha['keys'][0];
			$caminho  = uonix_analytics_metrics_normalize_path( $linha['keys'][1] );
			if ( '' === $consulta || '' === $caminho ) {
				continue;
			}
			$impressoes = (float) ( $linha['impressions'] ?? 0 );
			$atual      = isset( $lider[ $consulta ] ) ? $lider[ $consulta ] : null;
			if ( null === $atual || $impressoes > $atual['impressions'] || ( $impressoes === $atual['impressions'] && strcmp( $caminho, $atual['path'] ) < 0 ) ) {
				$lider[ $consulta ] = array( 'path' => $caminho, 'impressions' => $impressoes );
			}
		}

		$todas = array();
		foreach ( $consultas as $linha ) {
			if ( ! is_array( $linha ) || ! isset( $linha['keys'][0] ) || ! is_string( $linha['keys'][0] ) || '' === $linha['keys'][0] ) {
				continue;
			}
			$bruta = $linha['keys'][0];
			$texto = uonix_analytics_metrics_sanitize_query( $bruta );
			if ( '' === $texto ) {
				continue;
			}
			$impressoes = (float) ( $linha['impressions'] ?? 0 );
			$posicao    = (float) ( $linha['position'] ?? 0 );
			if ( ! ( $posicao > (float) $regras['min_position'] ) || $impressoes < (float) $regras['min_impressions'] || uonix_intelligence_radar_is_noise( $texto ) ) {
				continue;
			}
			$todas[] = array(
				'key'         => uonix_intelligence_radar_query_key( $texto ),
				'query'       => $texto,
				'impressions' => (int) round( $impressoes ),
				'clicks'      => (int) round( (float) ( $linha['clicks'] ?? 0 ) ),
				'position'    => round( $posicao, 1 ),
				'page_path'   => isset( $lider[ $bruta ] ) ? $lider[ $bruta ]['path'] : '',
			);
		}
		usort(
			$todas,
			static function ( $a, $b ) {
				return ( $b['impressions'] <=> $a['impressions'] ) ?: strcmp( uonix_intelligence_radar_normalize_query( $a['query'] ), uonix_intelligence_radar_normalize_query( $b['query'] ) );
			}
		);

		$limite      = (int) $regras['max_candidates'];
		$visiveis    = array();
		$descartadas = array();
		$vistas      = array();
		foreach ( $todas as $candidata ) {
			if ( isset( $vistas[ $candidata['key'] ] ) ) {
				continue;
			}
			$vistas[ $candidata['key'] ] = true;
			if ( isset( $descartes[ $candidata['key'] ] ) ) {
				if ( count( $descartadas ) < $limite ) {
					$descartadas[] = $candidata;
				}
				continue;
			}
			if ( count( $visiveis ) < $limite ) {
				$visiveis[] = $candidata;
			}
		}

		return array( 'visible' => $visiveis, 'dismissed' => $descartadas );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_fetch' ) ) {
	/**
	 * As duas chamadas ao Search Console: por consulta, que dá as métricas exatas, e por
	 * consulta e página, que dá a página líder. Mesmo padrão de
	 * `uonix_intelligence_executive_fetch_gsc_pages()` (59).
	 *
	 * O corte é contado nas linhas CRUAS: a resposta com o limite de linhas pode ter
	 * cortado a cauda, e o corte da API é por cliques, não por impressões.
	 *
	 * @param callable|null $query Para teste: `( $config, $periodo, $dimensao, $limite )`.
	 * @return array{queries: array, pages: array, truncated: bool}|WP_Error
	 */
	function uonix_intelligence_radar_fetch( $config, $janela, $query = null ) {
		if ( ! is_callable( $query ) ) {
			if ( ! function_exists( 'uonix_analytics_metrics_get_access_token' ) || ! function_exists( 'uonix_analytics_metrics_search_console_rows' ) ) {
				return uonix_analytics_metrics_error( 'analytics_layer_missing' );
			}
			$query = static function ( $config, $periodo, $dimensao, $limite ) {
				$token = uonix_analytics_metrics_get_access_token( $config );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				return uonix_analytics_metrics_search_console_rows(
					$token,
					isset( $config['search_console_site_url'] ) ? (string) $config['search_console_site_url'] : '',
					$periodo,
					$dimensao,
					$limite
				);
			};
		}
		$limite = (int) uonix_intelligence_radar_rules()['rows'];
		$saida  = array( 'queries' => array(), 'pages' => array(), 'truncated' => false );
		foreach ( array( 'queries' => 'query', 'pages' => array( 'query', 'page' ) ) as $nome => $dimensao ) {
			$bruto = call_user_func( $query, $config, $janela, $dimensao, $limite );
			if ( is_wp_error( $bruto ) ) {
				return $bruto;
			}
			$decodificado = uonix_analytics_metrics_decode_search_console_report( $bruto, true );
			if ( is_wp_error( $decodificado ) ) {
				return $decodificado;
			}
			$linhas             = isset( $decodificado['rows'] ) && is_array( $decodificado['rows'] ) ? $decodificado['rows'] : array();
			$saida[ $nome ]     = $linhas;
			$saida['truncated'] = $saida['truncated'] || count( $linhas ) >= $limite;
		}

		return $saida;
	}
}
