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
	 * `ai_budget` conta do início da execução: depois dele, nenhuma página é resolvida e
	 * nenhuma chamada ao Gemini começa. O `crontab` de produção corta em 290 s (lição da #335). `head_max` limita os
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
	 * - Um domínio, como `fulano.com.br`: é busca de navegação, e pode ser o site de alguém
	 *   (MÉDIO 1 da revisão do PR #378). Norma e número com ponto ("16325.1", "1.500") não são.
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
		if ( 1 === preg_match( '/\b[a-z0-9-]+\.(com|net|org|br|io|info|biz)\b/', $normal ) ) {
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
	 * @param array $excluir   Chave => true: consultas que o Módulo 3 já lista. Saem inteiras,
	 *                         nem visíveis nem descartadas: cada consulta fica com uma
	 *                         recomendação só (decisão do Cassio em 2026-10-02).
	 * @return array{visible: array, dismissed: array}
	 */
	function uonix_intelligence_radar_select( array $consultas, array $paginas, array $descartes = array(), array $excluir = array() ) {
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
			// O saneamento do 53 tira as tags ANTES de decodificar entidades e `%XX`: um
			// `&amp;lt;b&amp;gt;` sai dele como `<b>` ou `&lt;b&gt;`. Sobrou sinal de HTML ou
			// entidade, a consulta sai inteira (MÉDIO 1 da revisão do PR #378).
			if ( '' === $texto || 1 === preg_match( '/[<>]|&(#[0-9]+|#x[0-9a-f]+|[a-z]+);/i', $texto ) ) {
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
			if ( isset( $excluir[ $candidata['key'] ] ) ) {
				continue;
			}
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

if ( ! function_exists( 'uonix_intelligence_radar_input_hash' ) ) {
	/**
	 * Hash do que define a pauta: a consulta, a página que aparece hoje e o modelo.
	 *
	 * As métricas vão no pedido, mas ficam fora do hash: a janela de 90 dias anda todo
	 * dia, e com elas no hash toda execução refazia a pauta (a mesma lição do #329, A1).
	 */
	function uonix_intelligence_radar_input_hash( array $entrada ) {
		$pagina = isset( $entrada['page'] ) && is_array( $entrada['page'] ) ? $entrada['page'] : array();

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					isset( $entrada['query'] ) ? (string) $entrada['query'] : '',
					isset( $pagina['path'] ) ? (string) $pagina['path'] : '',
					isset( $pagina['redirected_to'] ) ? (string) $pagina['redirected_to'] : '',
					isset( $pagina['kind'] ) ? (string) $pagina['kind'] : '',
					isset( $pagina['title'] ) ? (string) $pagina['title'] : '',
					isset( $entrada['model'] ) ? (string) $entrada['model'] : '',
				)
			)
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_request_body' ) ) {
	/**
	 * Corpo do `generateContent` para uma candidata. Só os quatro campos abaixo vão ao
	 * Gemini; com 301, o caminho enviado é o do destino, que é a página que se edita.
	 *
	 * Sem `enum` no esquema: não foi medido no gemini-3.8-flash. A instrução pede os
	 * valores, e `uonix_intelligence_radar_validate()` garante.
	 */
	function uonix_intelligence_radar_request_body( array $entrada ) {
		$pagina = isset( $entrada['page'] ) && is_array( $entrada['page'] ) ? $entrada['page'] : array();
		$titulo = isset( $pagina['title'] ) ? (string) $pagina['title'] : '';
		$dados  = array(
			'consulta'           => isset( $entrada['query'] ) ? (string) $entrada['query'] : '',
			'impressoes_90_dias' => isset( $entrada['impressions'] ) ? (int) $entrada['impressions'] : 0,
			'posicao_media'      => isset( $entrada['position'] ) ? (float) $entrada['position'] : 0.0,
			'pagina_atual'       => '' === $titulo ? null : array(
				'caminho' => isset( $pagina['redirected_to'] ) && '' !== (string) $pagina['redirected_to'] ? (string) $pagina['redirected_to'] : (string) ( $pagina['path'] ?? '' ),
				'tipo'    => isset( $pagina['kind'] ) ? (string) $pagina['kind'] : '',
				'titulo'  => $titulo,
			),
		);
		$instrucao = implode(
			"\n",
			array(
				'Você propõe uma pauta de conteúdo para o blog do site da Uônix, fabricante de ancoragens e de acessórios para trabalho em altura.',
				'A consulta abaixo aparece na busca do Google, mas o site aparece mal para ela: posição média acima de 15.',
				'Regras:',
				'- Escreva em português do Brasil.',
				'- Em "caminho", responda "reforcar" quando a página atual já trata do assunto da consulta e falta profundidade, e "nova" quando ela trata de outra coisa. Sem página atual, responda "nova".',
				'- "titulo": o título do post, com no máximo 80 caracteres.',
				'- "angulo": o que o texto deve cobrir e em que se diferencia do que já existe, com no máximo 300 caracteres.',
				'- "intencao": "informacional", "comercial", "transacional" ou "navegacional".',
				'- Não invente norma, número, prazo, preço, certificação nem superlativo como "máxima" ou "melhor".',
				'- Só cite NR ou NBR que já esteja na consulta ou no título da página atual.',
				'- Sem HTML e sem link.',
				'Dados (JSON):',
				(string) wp_json_encode( $dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		$limites = function_exists( 'uonix_intelligence_ai_limits' ) ? uonix_intelligence_ai_limits() : array( 'max_output_tokens' => 1024 );

		return array(
			'contents'         => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $instrucao ) ) ) ),
			'generationConfig' => array(
				'temperature'      => 0.2,
				'maxOutputTokens'  => (int) $limites['max_output_tokens'],
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'caminho'  => array( 'type' => 'STRING' ),
						'titulo'   => array( 'type' => 'STRING' ),
						'angulo'   => array( 'type' => 'STRING' ),
						'intencao' => array( 'type' => 'STRING' ),
					),
					'required'   => array( 'caminho', 'titulo', 'angulo', 'intencao' ),
				),
				// Medido em 2026-09-30 no gemini-3.8-flash: aceito, STOP, nenhum token de raciocínio.
				'thinkingConfig'   => array( 'thinkingBudget' => 0 ),
			),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_validate' ) ) {
	/**
	 * Aceita a pauta só se TODAS as regras valerem; senão devolve null.
	 *
	 * Norma inventada não é detectável aqui. A proteção é a instrução do pedido e a
	 * revisão humana: a pauta só é exibida, e quem escreve o post confere.
	 *
	 * @param mixed $texto      Texto devolvido pelo modelo.
	 * @param bool  $com_pagina Se a página líder foi resolvida. Sem ela, "reforcar" não tem o que reforçar.
	 * @return array{caminho: string, titulo: string, angulo: string, intencao: string}|null
	 */
	function uonix_intelligence_radar_validate( $texto, $com_pagina ) {
		$dados = is_string( $texto ) ? json_decode( $texto, true ) : null;
		if ( ! is_array( $dados ) ) {
			return null;
		}
		$chaves = array_keys( $dados );
		sort( $chaves );
		if ( array( 'angulo', 'caminho', 'intencao', 'titulo' ) !== $chaves ) {
			return null;
		}
		foreach ( $dados as $valor ) {
			if ( ! is_string( $valor ) ) {
				return null;
			}
		}
		$pauta = array(
			'caminho'  => trim( $dados['caminho'] ),
			'titulo'   => trim( $dados['titulo'] ),
			'angulo'   => trim( $dados['angulo'] ),
			'intencao' => trim( $dados['intencao'] ),
		);
		if ( ! in_array( $pauta['caminho'], array( 'nova', 'reforcar' ), true ) || ( 'reforcar' === $pauta['caminho'] && ! $com_pagina ) ) {
			return null;
		}
		if ( ! in_array( $pauta['intencao'], array( 'informacional', 'comercial', 'transacional', 'navegacional' ), true ) ) {
			return null;
		}
		foreach ( array( array( $pauta['titulo'], 80 ), array( $pauta['angulo'], 300 ) ) as $par ) {
			$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( $par[0], 'UTF-8' ) : strlen( $par[0] );
			if ( $tamanho < 1 || $tamanho > $par[1] || 1 === preg_match( '/<[^>]*>/', $par[0] ) || 1 === preg_match( '#(https?://|www\.|\.com\.br\b)#i', $par[0] ) ) {
				return null;
			}
		}

		return $pauta;
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_post_kind' ) ) {
	/**
	 * Como o pedido e o painel chamam cada tipo de post. Termos vêm prontos do 54
	 * (`uonix_intelligence_ai_term_kind()`).
	 */
	function uonix_intelligence_radar_post_kind( $post_id ) {
		$tipo = function_exists( 'get_post_type' ) ? (string) get_post_type( (int) $post_id ) : '';
		$mapa = array(
			'post'     => 'post do blog',
			'page'     => 'página',
			'product'  => 'produto',
			'servicos' => 'página de serviço',
		);

		return isset( $mapa[ $tipo ] ) ? $mapa[ $tipo ] : 'página';
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_page' ) ) {
	/**
	 * O que é a página líder de uma consulta: título e tipo, para o pedido e o painel.
	 *
	 * Reaproveita a resolução do 54: post publicado, termo (#344) ou o destino de um 301
	 * do próprio domínio (#343). **Seguir o 301 faz HTTP**, um HEAD por salto, e cada HEAD
	 * desconta de `$orcamento`. Por isso só o cron chama esta função.
	 *
	 * Sem página com certeza (removida, 404, rascunho), ela volta com `title` vazio, e a pauta
	 * só pode ser "nova". **HEAD sem resposta, ou orçamento esgotado, devolve null:** é "não
	 * sei", e não "não há página". O cron então mantém a página de ontem, como o 54 faz com a
	 * sugestão de título (MÉDIO 2 da revisão do PR #378, a lição do MÉDIO 1 do #373).
	 *
	 * @param int|null $orcamento HEADs que ainda podem ser gastos; null é sem limite (só para teste).
	 * @return array{path: string, kind: string, title: string, redirected_to: string}|null
	 */
	function uonix_intelligence_radar_page( $consulta, $path, &$orcamento = null ) {
		$path  = is_string( $path ) ? $path : '';
		$vazia = array( 'path' => $path, 'kind' => '', 'title' => '', 'redirected_to' => '' );
		if ( '' === $path || ! function_exists( 'uonix_intelligence_ai_input' ) ) {
			return $vazia;
		}
		$linha   = array( 'query' => (string) $consulta, 'target_page' => $path );
		$entrada = uonix_intelligence_ai_input( $linha );
		if ( null === $entrada && function_exists( 'uonix_intelligence_ai_follow_redirect' ) ) {
			$destino = uonix_intelligence_ai_follow_redirect( $path, $orcamento );
			if ( null === $destino ) {
				return null;
			}
			$entrada = '' !== $destino ? uonix_intelligence_ai_input( $linha, $destino ) : null;
		}
		if ( ! is_array( $entrada ) || ! isset( $entrada['title'] ) || '' === (string) $entrada['title'] ) {
			return $vazia;
		}

		return array(
			'path'          => $path,
			'kind'          => isset( $entrada['page_kind'] ) && is_string( $entrada['page_kind'] ) ? $entrada['page_kind'] : uonix_intelligence_radar_post_kind( (int) ( $entrada['post_id'] ?? 0 ) ),
			'title'         => (string) $entrada['title'],
			'redirected_to' => isset( $entrada['redirected_to'] ) ? (string) $entrada['redirected_to'] : '',
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_dismissed' ) ) {
	/**
	 * Descartes válidos: chave => data. Entrada malformada é ignorada.
	 *
	 * @return array<string, string>
	 */
	function uonix_intelligence_radar_dismissed() {
		$gravado = get_option( uonix_intelligence_radar_dismissed_option(), array() );
		$saida   = array();
		foreach ( is_array( $gravado ) ? $gravado : array() as $chave => $data ) {
			if ( uonix_intelligence_radar_is_key( $chave ) && is_string( $data ) ) {
				$saida[ $chave ] = $data;
			}
		}

		return $saida;
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_module3_queries' ) ) {
	/**
	 * Consultas que o painel do Módulo 3 MOSTRA como oportunidade (55): posição de 4 a 12 nos
	 * 30 dias do snapshot, até `panel_limit`. Medido em 2026-10-02: "ensaio de arrancamento"
	 * estava nos dois blocos, na posição 10,1 em 30 dias e 15,1 em 90. Sem snapshot, nada é
	 * excluído.
	 *
	 * Só o que o painel mostra: excluir as que o Módulo 3 calcula e não mostra fazia uma
	 * consulta em 6.º lugar sumir dos dois blocos (MÉDIO da revisão do PR #385). O Radar decide
	 * no horário do cron, e o painel lê o snapshot na hora: uma sincronização no meio pode
	 * deixar a consulta fora dos dois por até um dia, até o próximo cron.
	 *
	 * @return string[]
	 */
	function uonix_intelligence_radar_module3_queries() {
		if ( ! function_exists( 'uonix_intelligence_seo_opportunities' ) ) {
			return array();
		}
		$regras  = function_exists( 'uonix_intelligence_seo_rules' ) ? uonix_intelligence_seo_rules() : array();
		$limite  = isset( $regras['panel_limit'] ) && (int) $regras['panel_limit'] > 0 ? (int) $regras['panel_limit'] : 5;
		$analise = uonix_intelligence_seo_opportunities( null, $limite );
		if ( ! is_array( $analise ) || empty( $analise['available'] ) || ! isset( $analise['rows'] ) || ! is_array( $analise['rows'] ) ) {
			return array();
		}
		$saida = array();
		foreach ( $analise['rows'] as $linha ) {
			if ( is_array( $linha ) && isset( $linha['query'] ) && is_string( $linha['query'] ) && '' !== $linha['query'] ) {
				$saida[] = $linha['query'];
			}
		}

		return $saida;
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_run' ) ) {
	/**
	 * Cron diário do Radar: busca, seleciona, resolve a página, pede a pauta e grava.
	 *
	 * - A opção é escrita UMA vez, no fim. Um corte do PHP no meio deixa a lista anterior.
	 * - Falha do Search Console, ou falta de credencial, grava só `status` e `updated_at`:
	 *   a lista anterior fica, com a hora dela em `list_updated_at`.
	 * - Pauta `ok` com o mesmo hash é reaproveitada sem chamada. Qualquer outro status é
	 *   tentado de novo no dia seguinte.
	 * - Descartadas não resolvem página (nenhum HEAD) nem chamam o Gemini.
	 * - Depois de `ai_budget` segundos do início, nenhuma página é resolvida (HEAD) e nenhuma
	 *   chamada ao Gemini começa: as restantes ficam `deferred` (lição da #335).
	 * - Página que o resolvedor não sabe dizer (null) reaproveita a de ontem; sem ela, a pauta
	 *   fica `deferred`, sem chamada.
	 * - Depois de um `quota_exhausted`, ou de `failure_streak` falhas seguidas (`unavailable` ou
	 *   `model_missing`), nenhuma chamada começa: as restantes ficam `deferred`, com o motivo em
	 *   `reason`. Medido em 2026-10-03, às 00:08 UTC: 1 pauta ok e 8 falhas, e a cota do dia
	 *   acabou na mesma rodada (#384). Cada chamada grava o `http` e os `attempts`.
	 *
	 * Tudo é injetável por `$args`, como em `uonix_intelligence_executive_collect()`:
	 * `today` (Y-m-d), `now` (ISO 8601), `config`, `query` (buscador do Search Console),
	 * `page_resolver`, `generate`, `has_key`, `clock` (segundos, float), `pause` e `module3`
	 * (lista de consultas do Módulo 3, que saem do Radar). O hook do
	 * cron é registrado com `accepted_args = 0`, então só um teste injeta.
	 *
	 * @return array{status: string, called: int}
	 */
	function uonix_intelligence_radar_run( $args = array() ) {
		$args    = is_array( $args ) ? $args : array();
		$regras  = uonix_intelligence_radar_rules();
		$agora   = isset( $args['now'] ) && is_string( $args['now'] ) && '' !== $args['now'] ? $args['now'] : gmdate( 'c' );
		$hoje    = isset( $args['today'] ) && is_string( $args['today'] ) && '' !== $args['today']
			? $args['today']
			: ( function_exists( 'uonix_intelligence_anomaly_now' ) ? uonix_intelligence_anomaly_now()->format( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		$relogio = isset( $args['clock'] ) && is_callable( $args['clock'] ) ? $args['clock'] : static function () {
			return microtime( true );
		};
		$inicio   = (float) call_user_func( $relogio );
		$anterior = get_option( uonix_intelligence_radar_option(), array() );
		$anterior = is_array( $anterior ) ? $anterior : array();
		$falha    = static function ( $status ) use ( $anterior, $agora ) {
			$novo               = $anterior;
			$novo['status']     = $status;
			$novo['updated_at'] = $agora;
			update_option( uonix_intelligence_radar_option(), $novo, false );
			return array( 'status' => $status, 'called' => 0 );
		};

		$config = array_key_exists( 'config', $args )
			? $args['config']
			: ( function_exists( 'uonix_analytics_metrics_get_config' ) ? uonix_analytics_metrics_get_config() : null );
		$janela = uonix_intelligence_radar_window( $hoje );
		if ( ! is_array( $config ) || array() === $janela ) {
			return $falha( 'config_missing' );
		}
		$dados = uonix_intelligence_radar_fetch( $config, $janela, isset( $args['query'] ) ? $args['query'] : null );
		if ( is_wp_error( $dados ) ) {
			return $falha( 'gsc_failed' );
		}

		$modulo3 = array_key_exists( 'module3', $args ) && is_array( $args['module3'] ) ? $args['module3'] : uonix_intelligence_radar_module3_queries();
		$excluir = array();
		foreach ( $modulo3 as $consulta ) {
			if ( is_string( $consulta ) && '' !== $consulta ) {
				$excluir[ uonix_intelligence_radar_query_key( $consulta ) ] = true;
			}
		}
		$selecao      = uonix_intelligence_radar_select( $dados['queries'], $dados['pages'], uonix_intelligence_radar_dismissed(), $excluir );
		$ia_antes     = array();
		$pagina_antes = array();
		foreach ( isset( $anterior['candidates'] ) && is_array( $anterior['candidates'] ) ? $anterior['candidates'] : array() as $c ) {
			if ( is_array( $c ) && uonix_intelligence_radar_is_key( $c['key'] ?? null ) ) {
				$ia_antes[ $c['key'] ] = isset( $c['ai'] ) && is_array( $c['ai'] ) ? $c['ai'] : array();
				// Página que ontem já era "não sei" não é a página de ontem: com ela, o segundo
				// "não sei" seguido chamava o Gemini sem página (segunda passada da revisão do #378).
				$pagina_antes[ $c['key'] ] = isset( $c['page'] ) && is_array( $c['page'] ) && empty( $ia_antes[ $c['key'] ]['page_unknown'] ) ? $c['page'] : null;
			}
		}
		$resolver  = isset( $args['page_resolver'] ) && is_callable( $args['page_resolver'] ) ? $args['page_resolver'] : 'uonix_intelligence_radar_page';
		$gerar     = isset( $args['generate'] ) && is_callable( $args['generate'] ) ? $args['generate'] : 'uonix_intelligence_ai_generate';
		$tem_chave = array_key_exists( 'has_key', $args )
			? (bool) $args['has_key']
			: ( function_exists( 'uonix_intelligence_ai_api_key' ) && '' !== uonix_intelligence_ai_api_key() );
		$modelo    = function_exists( 'uonix_intelligence_ai_model' ) ? uonix_intelligence_ai_model() : '';
		$pausa     = isset( $args['pause'] ) ? max( 0, (int) $args['pause'] ) : 2;
		$heads     = (int) $regras['head_max'];
		$chamadas  = 0;
		$gravadas  = array();
		$limites   = function_exists( 'uonix_intelligence_ai_limits' ) ? uonix_intelligence_ai_limits() : array();
		$teto      = isset( $limites['failure_streak'] ) ? max( 1, (int) $limites['failure_streak'] ) : 2;
		$parada    = array();
		$seguidas  = 0;
		// Um endereço é resolvido uma vez por execução, e o "não sei" também é lembrado. Medido
		// em 2026-10-02: duas candidatas com a mesma página líder gastavam o orçamento de HEAD
		// em dobro, e as últimas da lista nunca eram conferidas.
		$por_caminho = array();

		foreach ( $selecao['visible'] as $c ) {
			$sem_pagina = array( 'path' => $c['page_path'], 'kind' => '', 'title' => '', 'redirected_to' => '' );
			// Sem tempo, nenhuma página é resolvida: seguir o 301 faz HEAD, e o HEAD depois do
			// orçamento empurrava o cron para perto dos 290 s (BAIXO 4 da revisão do PR #378).
			if ( (float) call_user_func( $relogio ) - $inicio > (float) $regras['ai_budget'] ) {
				$pagina = null;
			} elseif ( array_key_exists( $c['page_path'], $por_caminho ) ) {
				$pagina = $por_caminho[ $c['page_path'] ];
			} else {
				$pagina                        = call_user_func_array( $resolver, array( $c['query'], $c['page_path'], &$heads ) );
				$por_caminho[ $c['page_path'] ] = $pagina;
			}
			$adiar  = false;
			if ( null === $pagina ) {
				// "Não sei": a página de ontem vale, e sem ela a pauta espera. Tratar como "não há
				// página" refazia uma pauta "reforcar" boa como "nova" (MÉDIO 2 da revisão do PR #378).
				// E só para o MESMO caminho líder: com outro, ela é de outra página.
				$ontem  = isset( $pagina_antes[ $c['key'] ] ) ? $pagina_antes[ $c['key'] ] : null;
				$pagina = is_array( $ontem ) && isset( $ontem['path'] ) && (string) $ontem['path'] === (string) $c['page_path'] ? $ontem : null;
				$adiar  = null === $pagina;
			}
			$c['page'] = is_array( $pagina ) ? array_merge( $sem_pagina, array_map( 'strval', array_intersect_key( $pagina, $sem_pagina ) ) ) : $sem_pagina;
			unset( $c['page_path'] );
			$entrada = array( 'query' => $c['query'], 'impressions' => $c['impressions'], 'position' => $c['position'], 'page' => $c['page'], 'model' => $modelo );
			$hash    = uonix_intelligence_radar_input_hash( $entrada );
			$antes   = isset( $ia_antes[ $c['key'] ] ) ? $ia_antes[ $c['key'] ] : array();
			if ( 'ok' === ( $antes['status'] ?? '' ) && $hash === ( $antes['input_hash'] ?? '' ) ) {
				$c['ai'] = $antes;
			} elseif ( ! $tem_chave ) {
				$c['ai'] = array( 'status' => 'not_configured' );
			} elseif ( $adiar || (float) call_user_func( $relogio ) - $inicio > (float) $regras['ai_budget'] ) {
				$c['ai'] = array( 'status' => 'deferred', 'input_hash' => $hash, 'attempted_at' => $agora );
			} elseif ( array() !== $parada ) {
				$c['ai'] = array( 'status' => 'deferred' ) + $parada + array( 'input_hash' => $hash, 'attempted_at' => $agora );
			} else {
				if ( $chamadas > 0 && $pausa > 0 ) {
					sleep( $pausa );
				}
				$resposta = call_user_func( $gerar, uonix_intelligence_radar_request_body( $entrada ), $modelo, $pausa );
				++$chamadas;
				$ai = array(
					'status'       => is_array( $resposta ) && isset( $resposta['status'] ) && is_string( $resposta['status'] ) ? $resposta['status'] : 'unavailable',
					'input_hash'   => $hash,
					'attempted_at' => $agora,
					'model'        => $modelo,
				);
				foreach ( array( 'http', 'attempts' ) as $campo ) {
					if ( is_array( $resposta ) && isset( $resposta[ $campo ] ) && is_int( $resposta[ $campo ] ) ) {
						$ai[ $campo ] = $resposta[ $campo ];
					}
				}
				if ( 'quota_exhausted' === $ai['status'] ) {
					$parada = array( 'reason' => 'quota_exhausted' );
					$espera = is_array( $resposta ) && isset( $resposta['retry_after'] ) && is_int( $resposta['retry_after'] ) ? $resposta['retry_after'] : 0;
					if ( $espera > 0 && false !== strtotime( $agora ) ) {
						$ai['retry_at']     = gmdate( 'c', strtotime( $agora ) + $espera );
						$parada['retry_at'] = $ai['retry_at'];
					}
				} elseif ( in_array( $ai['status'], array( 'unavailable', 'model_missing' ), true ) ) {
					++$seguidas;
					if ( $seguidas >= $teto ) {
						$parada = array( 'reason' => 'failures' );
					}
				} else {
					$seguidas = 0;
				}
				if ( 'ok' === $ai['status'] ) {
					$pauta = uonix_intelligence_radar_validate( $resposta['text'] ?? null, '' !== $c['page']['title'] );
					if ( null === $pauta ) {
						$ai['status'] = 'rejected';
					} else {
						$ai['suggestion']   = $pauta;
						$ai['generated_at'] = $agora;
					}
				}
				$c['ai'] = $ai;
			}
			// A página ficou sem saber: o próximo cron não pode tomá-la como a de ontem.
			if ( $adiar ) {
				$c['ai']['page_unknown'] = true;
			} else {
				unset( $c['ai']['page_unknown'] );
			}
			$gravadas[] = $c;
		}
		foreach ( $selecao['dismissed'] as $c ) {
			$c['page'] = isset( $pagina_antes[ $c['key'] ] ) ? $pagina_antes[ $c['key'] ] : array( 'path' => $c['page_path'], 'kind' => '', 'title' => '', 'redirected_to' => '' );
			$c['ai']   = isset( $ia_antes[ $c['key'] ] ) ? $ia_antes[ $c['key'] ] : array();
			// Descartada sem página conhecida: restaurada, o primeiro "não sei" não pode tomar a
			// página vazia como a de ontem (terceira passada da revisão do PR #378).
			if ( ! isset( $pagina_antes[ $c['key'] ] ) ) {
				$c['ai']['page_unknown'] = true;
			}
			unset( $c['page_path'] );
			$gravadas[] = $c;
		}

		update_option(
			uonix_intelligence_radar_option(),
			array(
				'updated_at'      => $agora,
				'list_updated_at' => $agora,
				'status'          => 'ok',
				'window'          => $janela,
				'truncated'       => ! empty( $dados['truncated'] ),
				'candidates'      => $gravadas,
			),
			false
		);

		return array( 'status' => 'ok', 'called' => $chamadas );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_schedule' ) ) {
	function uonix_intelligence_radar_schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( uonix_intelligence_radar_hook() ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', uonix_intelligence_radar_hook() );
		}
	}
}
add_action( 'init', 'uonix_intelligence_radar_schedule', 10, 0 );
// `accepted_args = 0`, como os irmãos de 53, 54, 57 e 59: um evento agendado à mão não
// injeta `$args` (buscador, gerador, resolvedor) pelo despacho do hook.
add_action( uonix_intelligence_radar_hook(), 'uonix_intelligence_radar_run', 10, 0 );

if ( ! function_exists( 'uonix_intelligence_radar_state' ) ) {
	/**
	 * O que o painel e o e-mail leem: a última execução do cron, sem rede.
	 *
	 * A separação entre visíveis e descartadas usa os descartes AO VIVO: um descarte vale
	 * na hora, sem esperar o próximo cron. Candidata malformada é ignorada, e campo
	 * malformado vira vazio do tipo certo (foco de revisão 4).
	 */
	function uonix_intelligence_radar_state() {
		$gravado   = get_option( uonix_intelligence_radar_option(), array() );
		$gravado   = is_array( $gravado ) ? $gravado : array();
		$descartes = uonix_intelligence_radar_dismissed();
		$texto     = static function ( $valor ) {
			return is_string( $valor ) ? $valor : '';
		};

		$visiveis    = array();
		$descartadas = array();
		foreach ( isset( $gravado['candidates'] ) && is_array( $gravado['candidates'] ) ? $gravado['candidates'] : array() as $c ) {
			if ( ! is_array( $c ) || ! uonix_intelligence_radar_is_key( $c['key'] ?? null ) || ! isset( $c['query'] ) || ! is_string( $c['query'] ) || '' === $c['query'] ) {
				continue;
			}
			$pagina = isset( $c['page'] ) && is_array( $c['page'] ) ? $c['page'] : array();
			$normal = array(
				'key'         => $c['key'],
				'query'       => $c['query'],
				'impressions' => is_numeric( $c['impressions'] ?? null ) ? (int) $c['impressions'] : 0,
				'clicks'      => is_numeric( $c['clicks'] ?? null ) ? (int) $c['clicks'] : 0,
				'position'    => is_numeric( $c['position'] ?? null ) ? (float) $c['position'] : 0.0,
				'page'        => array(
					'path'          => $texto( $pagina['path'] ?? null ),
					'kind'          => $texto( $pagina['kind'] ?? null ),
					'title'         => $texto( $pagina['title'] ?? null ),
					'redirected_to' => $texto( $pagina['redirected_to'] ?? null ),
				),
				'ai'          => isset( $c['ai'] ) && is_array( $c['ai'] ) ? $c['ai'] : array(),
			);
			if ( isset( $descartes[ $c['key'] ] ) ) {
				$descartadas[] = $normal;
			} else {
				$visiveis[] = $normal;
			}
		}
		$janela = isset( $gravado['window']['start'], $gravado['window']['end'] ) && is_string( $gravado['window']['start'] ) && is_string( $gravado['window']['end'] ) ? $gravado['window'] : array();

		return array(
			'ran'             => isset( $gravado['updated_at'] ) && is_string( $gravado['updated_at'] ),
			'status'          => $texto( $gravado['status'] ?? null ),
			'updated_at'      => $texto( $gravado['updated_at'] ?? null ),
			'list_updated_at' => $texto( $gravado['list_updated_at'] ?? null ),
			'window'          => $janela,
			'truncated'       => ! empty( $gravado['truncated'] ),
			'visible'         => array_slice( $visiveis, 0, (int) uonix_intelligence_radar_rules()['max_candidates'] ),
			'dismissed'       => $descartadas,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_suggestion' ) ) {
	/**
	 * A pauta de uma candidata, só quando `ok` e com os quatro campos válidos. Painel e
	 * e-mail leem por aqui, e não direto da opção.
	 *
	 * @return array{caminho: string, titulo: string, angulo: string, intencao: string}|null
	 */
	function uonix_intelligence_radar_suggestion( $candidata ) {
		if ( ! is_array( $candidata ) || ! isset( $candidata['ai']['status'], $candidata['ai']['suggestion'] ) || 'ok' !== $candidata['ai']['status'] || ! is_array( $candidata['ai']['suggestion'] ) ) {
			return null;
		}
		$p = $candidata['ai']['suggestion'];
		foreach ( array( 'caminho', 'titulo', 'angulo', 'intencao' ) as $campo ) {
			if ( ! isset( $p[ $campo ] ) || ! is_string( $p[ $campo ] ) || '' === $p[ $campo ] ) {
				return null;
			}
		}
		if ( ! in_array( $p['caminho'], array( 'nova', 'reforcar' ), true ) || ! in_array( $p['intencao'], array( 'informacional', 'comercial', 'transacional', 'navegacional' ), true ) ) {
			return null;
		}

		return array( 'caminho' => $p['caminho'], 'titulo' => $p['titulo'], 'angulo' => $p['angulo'], 'intencao' => $p['intencao'] );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_emailed' ) ) {
	/**
	 * Registros de envio que ainda valem: chave válida, data legível, não no futuro e com
	 * até 180 dias. Uma data no futuro, de relógio adiantado, não conta (foco de revisão 5).
	 *
	 * @param int|null $now Para teste; o padrão é `time()`.
	 * @return array<string, string>
	 */
	function uonix_intelligence_radar_emailed( $now = null ) {
		$agora   = is_int( $now ) ? $now : time();
		$validade = (int) uonix_intelligence_radar_rules()['emailed_ttl'];
		$gravado = get_option( uonix_intelligence_radar_emailed_option(), array() );
		$saida   = array();
		foreach ( is_array( $gravado ) ? $gravado : array() as $chave => $data ) {
			$momento = is_string( $data ) ? strtotime( $data ) : false;
			if ( uonix_intelligence_radar_is_key( $chave ) && false !== $momento && $momento <= $agora && ( $agora - $momento ) <= $validade ) {
				$saida[ $chave ] = $data;
			}
		}

		return $saida;
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_email_items' ) ) {
	/**
	 * Pautas novas para o próximo e-mail: visíveis, não enviadas nos últimos 180 dias, e
	 * com pauta `ok`. Sem a chave do Gemini, vão as candidatas sem pauta. Com chave, uma
	 * pauta que falhou espera, sem gastar a novidade, e continua no painel.
	 *
	 * Os 180 dias impedem que uma consulta oscilando em torno do limite gere e-mail toda
	 * semana (o problema da #297 no Módulo 5).
	 *
	 * @param int|null  $now     Para teste; o padrão é `time()`.
	 * @param bool|null $has_key Para teste; o padrão é a chave do wp-config.php.
	 * @return array{items: array, panel_count: int}
	 */
	function uonix_intelligence_radar_email_items( $now = null, $has_key = null ) {
		$estado    = uonix_intelligence_radar_state();
		$enviados  = uonix_intelligence_radar_emailed( $now );
		$tem_chave = null === $has_key
			? ( function_exists( 'uonix_intelligence_ai_api_key' ) && '' !== uonix_intelligence_ai_api_key() )
			: (bool) $has_key;
		$itens     = array();
		foreach ( $estado['visible'] as $c ) {
			if ( isset( $enviados[ $c['key'] ] ) ) {
				continue;
			}
			$status = isset( $c['ai']['status'] ) && is_string( $c['ai']['status'] ) ? $c['ai']['status'] : '';
			if ( null !== uonix_intelligence_radar_suggestion( $c ) || ( ! $tem_chave && 'not_configured' === $status ) ) {
				$itens[] = $c;
			}
		}

		return array(
			'items'       => array_slice( $itens, 0, (int) uonix_intelligence_radar_rules()['email_limit'] ),
			'panel_count' => count( $estado['visible'] ),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_mark_emailed' ) ) {
	/**
	 * Registra que estas pautas foram no e-mail. Só o envio semanal REAL e bem-sucedido
	 * chama isto (`uonix_intelligence_send_scheduled_report()`, no 57); o envio de teste
	 * não. Poda os registros vencidos ou com data no futuro.
	 *
	 * @return int Registros que ficaram.
	 */
	function uonix_intelligence_radar_mark_emailed( $chaves, $now = null ) {
		$agora = is_int( $now ) ? $now : time();
		$atual = uonix_intelligence_radar_emailed( $agora );
		foreach ( is_array( $chaves ) ? $chaves : array() as $chave ) {
			if ( uonix_intelligence_radar_is_key( $chave ) ) {
				$atual[ $chave ] = gmdate( 'c', $agora );
			}
		}
		update_option( uonix_intelligence_radar_emailed_option(), $atual, false );

		return count( $atual );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_can_curate' ) ) {
	/**
	 * Quem pode descartar e restaurar: quem vê a Central, pela mesma regra da página
	 * (`uonix_render_analytics_dashboard_page()`, no 52). Descartar só esconde uma linha e
	 * pode ser desfeito; o envio de teste, que tem efeito externo, continua só do dono.
	 */
	function uonix_intelligence_radar_can_curate() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		return ! function_exists( 'uonix_ksio_can_access_tool' ) || uonix_ksio_can_access_tool( 'analytics' );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_handle_curation' ) ) {
	/**
	 * Descarta ou restaura uma ou várias candidatas (lote pedido pelo Cassio em 2026-10-02).
	 *
	 * - A permissão vem antes do nonce, e o nonce é por ação: um formulário serve várias pautas.
	 * - O botão de uma linha (`uonix_radar_key`) vale só para ela, mesmo com outras caixas
	 *   marcadas. O botão do lote usa as caixas marcadas (`uonix_radar_keys[]`).
	 * - No máximo 20 chaves por pedido, que é o que cabe nas duas tabelas. Cada chave tem de
	 *   ser válida e estar entre as candidatas gravadas; as outras são ignoradas. Sem nenhuma
	 *   válida, nada é gravado, e o painel diz por quê.
	 *
	 * @param string $acao `dismiss` ou `restore`.
	 */
	function uonix_intelligence_radar_handle_curation( $acao ) {
		if ( ! uonix_intelligence_radar_can_curate() ) {
			wp_die( esc_html__( 'Sem permissão para alterar o Radar de Pautas.', 'uonix' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'uonix_intelligence_radar_' . $acao );

		$linha   = isset( $_POST['uonix_radar_key'] ) && is_string( $_POST['uonix_radar_key'] ) ? (string) wp_unslash( $_POST['uonix_radar_key'] ) : '';
		$pedidas = array();
		if ( '' !== $linha ) {
			$pedidas = array( $linha );
		} elseif ( isset( $_POST['uonix_radar_keys'] ) && is_array( $_POST['uonix_radar_keys'] ) ) {
			foreach ( wp_unslash( $_POST['uonix_radar_keys'] ) as $chave ) {
				if ( is_string( $chave ) ) {
					$pedidas[] = $chave;
				}
			}
		}
		$pedidas = array_slice( array_values( array_unique( $pedidas ) ), 0, 20 );

		$gravado = get_option( uonix_intelligence_radar_option(), array() );
		$chaves  = array();
		foreach ( is_array( $gravado ) && isset( $gravado['candidates'] ) && is_array( $gravado['candidates'] ) ? $gravado['candidates'] : array() as $c ) {
			if ( is_array( $c ) && isset( $c['key'] ) && is_string( $c['key'] ) ) {
				$chaves[] = $c['key'];
			}
		}
		$validas = array();
		foreach ( $pedidas as $chave ) {
			if ( uonix_intelligence_radar_is_key( $chave ) && in_array( $chave, $chaves, true ) ) {
				$validas[] = $chave;
			}
		}
		if ( array() !== $validas ) {
			$descartes = uonix_intelligence_radar_dismissed();
			foreach ( $validas as $chave ) {
				if ( 'dismiss' === $acao ) {
					$descartes[ $chave ] = gmdate( 'c' );
				} else {
					unset( $descartes[ $chave ] );
				}
			}
			update_option( uonix_intelligence_radar_dismissed_option(), $descartes, false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'uonix-analytics',
					'tab'           => 'intelligence',
					'uonix_radar'   => array() !== $validas ? $acao : 'invalid',
					'uonix_radar_n' => count( $validas ),
				),
				admin_url( 'admin.php' )
			) . '#uonix-radar'
		);
		exit;
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_handle_dismiss' ) ) {
	function uonix_intelligence_radar_handle_dismiss() {
		uonix_intelligence_radar_handle_curation( 'dismiss' );
	}
}

if ( ! function_exists( 'uonix_intelligence_radar_handle_restore' ) ) {
	function uonix_intelligence_radar_handle_restore() {
		uonix_intelligence_radar_handle_curation( 'restore' );
	}
}
add_action( 'admin_post_uonix_intelligence_radar_dismiss', 'uonix_intelligence_radar_handle_dismiss', 10, 0 );
add_action( 'admin_post_uonix_intelligence_radar_restore', 'uonix_intelligence_radar_handle_restore', 10, 0 );
