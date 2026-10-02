<?php
/**
 * Central de Inteligência — sugestão de Title/Description por IA (Gemini).
 *
 * Para cada oportunidade do Módulo 3 (55), reescreve o título e a descrição atuais
 * da página líder da consulta, afirmando só os diferenciais de
 * `uonix_intelligence_seo_differentiators()`. Roda num cron diário próprio, com
 * cache por entrada. O painel (56) e o e-mail (57, 59) só leem o cache e nunca
 * chamam o Gemini. Sem `UONIX_GEMINI_API_KEY` no wp-config.php, nada é chamado.
 *
 * Nada é aplicado no Rank Math: a sugestão é exibida para revisão humana.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_ai_option' ) ) {
	function uonix_intelligence_ai_option() {
		return 'uonix_intelligence_ai_suggestions';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_hook' ) ) {
	function uonix_intelligence_ai_hook() {
		return 'uonix_intelligence_ai_daily';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_limits' ) ) {
	/**
	 * Limites de saída e de custo. `per_run` é o `limit` das oportunidades (55).
	 * `max_output_tokens` com folga: medido em 2026-09-30, com 20 o modelo gastou
	 * 16 pensando e voltou sem texto.
	 */
	function uonix_intelligence_ai_limits() {
		// `max_hops`: saltos de redirecionamento seguidos no cron (#343), o mesmo teto do 59.
		return array( 'title' => 60, 'description' => 155, 'per_run' => 5, 'timeout' => 15, 'max_output_tokens' => 1024, 'max_hops' => 3 );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_api_key' ) ) {
	function uonix_intelligence_ai_api_key() {
		return defined( 'UONIX_GEMINI_API_KEY' ) && is_string( UONIX_GEMINI_API_KEY ) ? trim( UONIX_GEMINI_API_KEY ) : '';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_model' ) ) {
	/**
	 * Modelo fixo, e não apelido `-latest`. Medido em 2026-09-30: `gemini-flash-latest`
	 * já respondia como `gemini-3.8-flash`, e `gemini-2.5-flash` dava 404 para chave
	 * nova. Valor fora do formato cai no padrão.
	 */
	function uonix_intelligence_ai_model() {
		$modelo = defined( 'UONIX_GEMINI_MODEL' ) && is_string( UONIX_GEMINI_MODEL ) ? trim( UONIX_GEMINI_MODEL ) : '';
		return 1 === preg_match( '/^[a-z0-9][a-z0-9.\-]{0,63}$/', $modelo ) ? $modelo : 'gemini-3.8-flash';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_page_post_id' ) ) {
	/**
	 * Post PUBLICADO da página líder, ou 0.
	 *
	 * A Search Console também reporta endereços que hoje dão 404 ou 301 (medido na
	 * revisão do PR #301; ver 59). Nesses, nada é achado, e o estado vira `no_page`.
	 * O produto em `/produtos/<slug>/` que `url_to_postid()` não resolver é
	 * procurado pelo slug.
	 */
	function uonix_intelligence_ai_page_post_id( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path || '/' !== $path[0] ) {
			return 0;
		}
		$id = 0;
		if ( '/' === $path ) {
			$id = (int) get_option( 'page_on_front' );
		} elseif ( function_exists( 'url_to_postid' ) && function_exists( 'home_url' ) ) {
			$id = (int) url_to_postid( home_url( $path ) );
		}
		if ( 0 === $id && 1 === preg_match( '#^/produtos/([a-z0-9\-]+)/?$#', $path, $m ) && function_exists( 'get_page_by_path' ) ) {
			$produto = get_page_by_path( $m[1], OBJECT, 'product' );
			$id      = is_object( $produto ) && isset( $produto->ID ) ? (int) $produto->ID : 0;
		}

		return $id > 0 && function_exists( 'get_post_status' ) && 'publish' === get_post_status( $id ) ? $id : 0;
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_page_object' ) ) {
	/**
	 * O que a página líder é: um post publicado, um termo de taxonomia pública, ou nada.
	 *
	 * Post primeiro, pelo caminho de sempre. Sem post, o termo que a regra de reescrita abre
	 * (`uonix_intelligence_resolve_term_path()`, no 55): categoria de produto, categoria e tag
	 * do blog (#344). A URL antiga do termo que o WordPress canonicaliza por 301 resolve para
	 * o próprio termo; os demais endereços que redirecionam continuam sem página (#343).
	 *
	 * @return array{type: string, id: int, taxonomy?: string}|null
	 */
	function uonix_intelligence_ai_page_object( $path ) {
		$post_id = uonix_intelligence_ai_page_post_id( $path );
		if ( $post_id > 0 ) {
			return array( 'type' => 'post', 'id' => $post_id );
		}
		$termo = is_string( $path ) && '' !== $path && '/' === $path[0] && function_exists( 'uonix_intelligence_resolve_term_path' )
			? uonix_intelligence_resolve_term_path( $path )
			: null;

		return is_array( $termo ) ? array( 'type' => 'term', 'taxonomy' => $termo['taxonomy'], 'id' => $termo['id'] ) : null;
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_follow_redirect' ) ) {
	/**
	 * Destino final de um endereço antigo que redireciona, ou '' (#343).
	 *
	 * A Search Console ainda credita impressões a endereços que hoje dão 301, como
	 * `/teste-de-arrancamento` → `/servico/ensaios-de-arrancamento/` (medido em produção em
	 * 2026-10-01). Sem seguir, a oportunidade ficava em `no_page`.
	 *
	 * **Faz HTTP**, um HEAD por salto, por `uonix_intelligence_executive_page_status()` (59).
	 * Por isso só o cron chama esta função; o leitor usa o destino que o cron gravou.
	 *
	 * Só leva a página quando TODAS as condições valem:
	 *   - cada salto é 301/302/307/308 para o próprio domínio
	 *     (`uonix_analytics_metrics_normalize_path()`, do 53);
	 *   - no máximo `max_hops` saltos, sem ciclo;
	 *   - o destino responde 2xx;
	 *   - o destino é um post publicado ou um termo (`uonix_intelligence_ai_page_object()`).
	 * Qualquer outra coisa (fora do domínio, cadeia longa, 404, sem resposta) devolve ''.
	 */
	function uonix_intelligence_ai_follow_redirect( $path ) {
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0]
			|| ! function_exists( 'uonix_intelligence_executive_page_status' ) || ! function_exists( 'uonix_analytics_metrics_normalize_path' ) ) {
			return '';
		}
		$maximo = (int) uonix_intelligence_ai_limits()['max_hops'];
		$atual  = $path;
		$vistos = array( $path );
		for ( $saltos = 0; $saltos <= $maximo; $saltos++ ) {
			$status = uonix_intelligence_executive_page_status( $atual );
			$estado = is_array( $status ) && isset( $status['state'] ) ? (string) $status['state'] : 'unknown';
			if ( 'ok' === $estado ) {
				// Página que responde sem redirecionar não é endereço antigo: nada a seguir.
				return $saltos > 0 && null !== uonix_intelligence_ai_page_object( $atual ) ? $atual : '';
			}
			// O próprio laço limita a `max_hops` saltos: depois do último, ele termina sem destino.
			if ( 'redirect' !== $estado ) {
				return '';
			}
			$location = isset( $status['location'] ) ? (string) $status['location'] : '';
			$destino  = uonix_analytics_metrics_normalize_path( $location );
			// `Location` para a raiz, sem caminho, normaliza para vazio (o mesmo cuidado do 59).
			if ( '' === $destino && 1 === preg_match( '#^https?://(www\.)?uonix\.com\.br/?$#iD', $location ) ) {
				$destino = '/';
			}
			if ( '' === $destino || in_array( $destino, $vistos, true ) ) {
				return '';
			}
			$vistos[] = $destino;
			$atual    = $destino;
		}

		return '';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_term_kind' ) ) {
	/**
	 * Como o pedido e o painel chamam cada taxonomia. O Gemini precisa saber que a página
	 * lista produtos, e não é um artigo nem um produto só.
	 */
	function uonix_intelligence_ai_term_kind( $taxonomy ) {
		$mapa = array(
			'product_cat' => 'categoria de produtos',
			'product_tag' => 'tag de produtos',
			'category'    => 'categoria do blog',
			'post_tag'    => 'tag do blog',
		);

		return isset( $mapa[ $taxonomy ] ) ? $mapa[ $taxonomy ] : 'arquivo de taxonomia';
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_meta_text' ) ) {
	/**
	 * Texto de uma meta do Rank Math pronto para o pedido. Variáveis (`%title%`,
	 * `%sep%`…) são resolvidas pelo Rank Math quando ele expõe o resolvedor. O que
	 * sobrar sem resolver vira '', e quem chama cai no título do post ou no nome do
	 * termo: o template cru nunca vai ao Gemini.
	 *
	 * @param mixed      $raw   Valor da meta.
	 * @param int|object $alvo  Id do post, ou o objeto (post ou termo) que o Rank Math recebe.
	 */
	function uonix_intelligence_ai_meta_text( $raw, $alvo ) {
		$texto = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' !== $texto && false !== strpos( $texto, '%' ) && is_callable( array( 'RankMath\\Helper', 'replace_vars' ) ) ) {
			$objeto = is_object( $alvo ) ? $alvo : ( function_exists( 'get_post' ) ? get_post( (int) $alvo ) : null );
			$texto  = (string) call_user_func( array( 'RankMath\\Helper', 'replace_vars' ), $texto, $objeto );
		}
		$texto = trim( html_entity_decode( wp_strip_all_tags( $texto ), ENT_QUOTES, 'UTF-8' ) );

		return 1 === preg_match( '/%[a-z_]+%/i', $texto ) ? '' : $texto;
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_input' ) ) {
	/**
	 * Entrada do pedido para uma oportunidade, ou null sem consulta ou sem página (post
	 * publicado, termo ou o destino de um 301 do próprio domínio).
	 *
	 * Fronteira de dados: cada chave é montada uma a uma a partir da linha. Nada mais
	 * da linha passa, mesmo que um dia ela carregue outros campos.
	 *
	 * @param string $redirected_to Destino do 301 que o cron achou (#343). Só vale quando a
	 *                              página líder não é post nem termo; aí a entrada descreve
	 *                              o destino, que é a página que se edita.
	 */
	function uonix_intelligence_ai_input( $row, $redirected_to = '' ) {
		if ( ! is_array( $row ) || ! isset( $row['query'] ) || ! is_string( $row['query'] ) || '' === $row['query'] ) {
			return null;
		}
		$path    = isset( $row['target_page'] ) && is_string( $row['target_page'] ) ? $row['target_page'] : '';
		$objeto  = uonix_intelligence_ai_page_object( $path );
		$destino = '';
		if ( null === $objeto && is_string( $redirected_to ) && '' !== $redirected_to && '/' === $redirected_to[0] ) {
			$objeto  = uonix_intelligence_ai_page_object( $redirected_to );
			$destino = null !== $objeto ? $redirected_to : '';
			$path    = null !== $objeto ? $redirected_to : $path;
		}
		if ( null === $objeto ) {
			return null;
		}
		$limpo = static function ( $texto ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $texto ), ENT_QUOTES, 'UTF-8' ) );
		};
		$extra = array();
		if ( 'term' === $objeto['type'] ) {
			// Termo (#344): a meta do Rank Math do termo; sem ela, o nome e a descrição dele.
			$termo     = uonix_intelligence_resolve_term_path( $path );
			// O Rank Math resolve as variáveis da meta sobre o termo real.
			$alvo_meta = function_exists( 'get_term' ) ? get_term( $objeto['id'], $objeto['taxonomy'] ) : null;
			$alvo_meta = is_object( $alvo_meta ) && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $alvo_meta ) ) ? $alvo_meta : null;
			$titulo    = uonix_intelligence_ai_meta_text( get_term_meta( $objeto['id'], 'rank_math_title', true ), $alvo_meta );
			$titulo    = '' !== $titulo ? $titulo : $limpo( is_array( $termo ) ? $termo['name'] : '' );
			$descricao = uonix_intelligence_ai_meta_text( get_term_meta( $objeto['id'], 'rank_math_description', true ), $alvo_meta );
			$descricao = '' !== $descricao ? $descricao : $limpo( is_array( $termo ) ? $termo['description'] : '' );
			$post_id   = 0;
			$extra     = array( 'page_kind' => uonix_intelligence_ai_term_kind( $objeto['taxonomy'] ) );
		} else {
			$post_id   = $objeto['id'];
			$titulo    = uonix_intelligence_ai_meta_text( get_post_meta( $post_id, 'rank_math_title', true ), $post_id );
			$titulo    = '' !== $titulo ? $titulo : $limpo( get_the_title( $post_id ) );
			$descricao = uonix_intelligence_ai_meta_text( get_post_meta( $post_id, 'rank_math_description', true ), $post_id );
		}

		return $extra + array(
			'query'           => $row['query'],
			'impressions'     => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
			'position'        => round( (float) ( $row['position'] ?? 0 ), 1 ),
			'ctr'             => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
			'page_url'        => home_url( $path ),
			'title'           => $titulo,
			'description'     => $descricao,
			'differentiators' => uonix_intelligence_seo_differentiators(),
			'model'           => uonix_intelligence_ai_model(),
			'post_id'         => $post_id,
			'object'          => $objeto,
			'redirected_to'   => $destino,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_input_hash' ) ) {
	/**
	 * Hash do que define a sugestão: consulta, página, texto atual, lista e modelo.
	 *
	 * As métricas vão no pedido, mas ficam fora do hash: a janela de 30 dias do 53
	 * termina ontem e muda as métricas a cada sincronização diária. Com elas no hash,
	 * toda sincronização invalidava o cache (revisão do PR #329, A1).
	 */
	function uonix_intelligence_ai_input_hash( array $input ) {
		$base = array();
		foreach ( array( 'query', 'page_url', 'title', 'description', 'differentiators', 'model' ) as $chave ) {
			$base[ $chave ] = isset( $input[ $chave ] ) ? $input[ $chave ] : null;
		}
		// O tipo de página vai ao Gemini, então entra no hash. Só quando existe (termos, #344):
		// o hash dos posts fica o de antes, e a sugestão pronta não é refeita à toa.
		if ( isset( $input['page_kind'] ) && '' !== $input['page_kind'] ) {
			$base['page_kind'] = $input['page_kind'];
		}

		return hash( 'sha256', (string) wp_json_encode( $base ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_entry_key' ) ) {
	/**
	 * Chave da entrada no cache. A consulta entra só como hash: o cache vai para backup
	 * e clone, e o texto da consulta não precisa ir junto (#253).
	 */
	function uonix_intelligence_ai_entry_key( $query, $path ) {
		return hash( 'sha256', (string) $query . "\n" . (string) $path );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_request_body' ) ) {
	/**
	 * Corpo do `generateContent`. Só os campos abaixo vão ao Gemini: `post_id`, `object` e
	 * `model` da entrada ficam de fora.
	 */
	function uonix_intelligence_ai_request_body( array $input ) {
		$limites = uonix_intelligence_ai_limits();
		$dados   = array(
			'consulta'                => $input['query'],
			'impressoes_30_dias'      => $input['impressions'],
			'posicao_media'           => $input['position'],
			'taxa_de_clique'          => $input['ctr'],
			'pagina'                  => $input['page_url'],
			'titulo_atual'            => $input['title'],
			'descricao_atual'         => $input['description'],
			'diferenciais_permitidos' => array_values( $input['differentiators'] ),
		);
		if ( isset( $input['page_kind'] ) && '' !== $input['page_kind'] ) {
			$dados['tipo_de_pagina'] = $input['page_kind'];
		}
		$instrucao = implode(
			"\n",
			array(
				'Você reescreve o título (Title) e a meta description de uma página do site da Uônix, fabricante de ancoragens e acessórios para trabalho em altura, para ganhar cliques na busca do Google.',
				'Regras:',
				'- Escreva em português do Brasil.',
				sprintf( '- Título com no máximo %d caracteres. Descrição com no máximo %d caracteres.', $limites['title'], $limites['description'] ),
				'- Use o vocabulário da consulta quando fizer sentido.',
				'- Não afirme nada que não esteja no título atual, na descrição atual ou na lista de diferenciais permitidos. Não invente garantia, prazo, preço, certificação, número nem superlativo como "máxima" ou "melhor".',
				'- Escolha os diferenciais pelo assunto da página: serviço não recebe característica de produto.',
				'- Ao usar um diferencial, escreva-o no texto exatamente como na lista, e liste em differentiators_used exatamente os rótulos usados.',
				'- Sem HTML.',
				'Dados (JSON):',
				// Sem escapar `/` nem acento: "Aço Inox 304/316" tem de chegar escrito como na lista.
				(string) wp_json_encode( $dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		return array(
			'contents'         => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $instrucao ) ) ) ),
			'generationConfig' => array(
				'temperature'      => 0.2,
				'maxOutputTokens'  => $limites['max_output_tokens'],
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'title'                => array( 'type' => 'STRING' ),
						'description'          => array( 'type' => 'STRING' ),
						'differentiators_used' => array( 'type' => 'ARRAY', 'items' => array( 'type' => 'STRING' ) ),
					),
					'required'   => array( 'title', 'description', 'differentiators_used' ),
				),
				// Medido em 2026-09-30 no gemini-3.8-flash: aceito, STOP, nenhum token de raciocínio.
				'thinkingConfig'   => array( 'thinkingBudget' => 0 ),
			),
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_validate' ) ) {
	/**
	 * Aceita a resposta só se TODAS as regras valerem; senão devolve null.
	 *
	 * Afirmação inventada fora da lista, como "garantia de 10 anos", não é detectável
	 * aqui. A proteção é a instrução do pedido e a revisão humana: nada é aplicado sozinho.
	 *
	 * @param mixed    $text      Texto devolvido pelo modelo.
	 * @param string[] $allowed   Rótulos permitidos.
	 */
	function uonix_intelligence_ai_validate( $text, array $allowed ) {
		$dados = is_string( $text ) ? json_decode( $text, true ) : null;
		if ( ! is_array( $dados ) ) {
			return null;
		}
		$chaves = array_keys( $dados );
		sort( $chaves );
		if ( array( 'description', 'differentiators_used', 'title' ) !== $chaves ) {
			return null;
		}
		if ( ! is_string( $dados['title'] ) || ! is_string( $dados['description'] ) || ! is_array( $dados['differentiators_used'] ) ) {
			return null;
		}
		$titulo    = trim( $dados['title'] );
		$descricao = trim( $dados['description'] );
		$limites   = uonix_intelligence_ai_limits();
		foreach ( array( array( $titulo, $limites['title'] ), array( $descricao, $limites['description'] ) ) as $par ) {
			$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( $par[0], 'UTF-8' ) : strlen( $par[0] );
			if ( $tamanho < 1 || $tamanho > $par[1] || 1 === preg_match( '/<[^>]*>/', $par[0] ) ) {
				return null;
			}
		}
		$usados = array();
		foreach ( $dados['differentiators_used'] as $rotulo ) {
			if ( ! is_string( $rotulo ) || ! in_array( $rotulo, $allowed, true ) ) {
				return null;
			}
			$usados[ $rotulo ] = true;
		}
		// Declarado tem de estar no texto, e o que está no texto tem de estar declarado.
		$no_texto = uonix_intelligence_normalize_term( $titulo . ' ' . $descricao );
		foreach ( $allowed as $rotulo ) {
			if ( ( false !== strpos( $no_texto, uonix_intelligence_normalize_term( $rotulo ) ) ) !== isset( $usados[ $rotulo ] ) ) {
				return null;
			}
		}

		return array( 'title' => $titulo, 'description' => $descricao, 'differentiators_used' => array_keys( $usados ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_call' ) ) {
	/**
	 * Uma chamada ao Gemini para uma entrada.
	 *
	 * 429 e 503 ganham UMA nova tentativa após `$pause` segundos. Medido em
	 * 2026-09-30: o gemini-3.8-flash respondeu 503 ("high demand") em 3 de 4
	 * chamadas seguidas. Os demais erros não são repetidos: o próximo cron diário
	 * tenta de novo.
	 *
	 * @return array{status: string, suggestion?: array}
	 */
	function uonix_intelligence_ai_call( array $input, $pause = 2 ) {
		$chave = uonix_intelligence_ai_api_key();
		if ( '' === $chave ) {
			return array( 'status' => 'not_configured' );
		}
		$limites = uonix_intelligence_ai_limits();
		$url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( (string) $input['model'] ) . ':generateContent';
		$args    = array(
			'timeout' => $limites['timeout'],
			'headers' => array( 'x-goog-api-key' => $chave, 'Content-Type' => 'application/json' ),
			'body'    => (string) wp_json_encode( uonix_intelligence_ai_request_body( $input ) ),
		);

		$codigo   = 0;
		$resposta = null;
		for ( $tentativa = 1; $tentativa <= 2; ++$tentativa ) {
			$resposta = wp_remote_post( $url, $args );
			$codigo   = is_wp_error( $resposta ) ? 0 : (int) wp_remote_retrieve_response_code( $resposta );
			if ( 1 === $tentativa && in_array( $codigo, array( 429, 503 ), true ) ) {
				if ( (int) $pause > 0 ) {
					sleep( (int) $pause );
				}
				continue;
			}
			break;
		}
		if ( 404 === $codigo ) {
			return array( 'status' => 'model_missing' );
		}
		if ( 200 !== $codigo ) {
			return array( 'status' => 'unavailable' );
		}

		$corpo     = json_decode( (string) wp_remote_retrieve_body( $resposta ), true );
		$candidato = is_array( $corpo ) && isset( $corpo['candidates'][0] ) && is_array( $corpo['candidates'][0] ) ? $corpo['candidates'][0] : array();
		$fim       = isset( $candidato['finishReason'] ) && is_string( $candidato['finishReason'] ) ? $candidato['finishReason'] : '';
		if ( 'STOP' !== $fim ) {
			// Sem candidato, ou cortado por tamanho: transitório. Bloqueio por segurança e afins: recusa.
			return array( 'status' => ( '' === $fim || 'MAX_TOKENS' === $fim ) ? 'unavailable' : 'rejected' );
		}
		$texto  = '';
		$partes = isset( $candidato['content']['parts'] ) && is_array( $candidato['content']['parts'] ) ? $candidato['content']['parts'] : array();
		foreach ( $partes as $parte ) {
			if ( is_array( $parte ) && empty( $parte['thought'] ) && isset( $parte['text'] ) && is_string( $parte['text'] ) ) {
				$texto .= $parte['text'];
			}
		}
		$sugestao = uonix_intelligence_ai_validate( $texto, (array) $input['differentiators'] );

		return null === $sugestao ? array( 'status' => 'rejected' ) : array( 'status' => 'ok', 'suggestion' => $sugestao );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_run' ) ) {
	/**
	 * Cron diário: gera ou mantém a sugestão de cada oportunidade.
	 *
	 * Entrada igual à da última sugestão aceita: nenhuma chamada. Entrada nova: uma
	 * chamada, e o resultado substitui o anterior, inclusive quando falha. Assim, a
	 * sugestão de um título que já não existe nunca sobrevive. Oportunidade que saiu
	 * da lista sai do cache. Não toca o snapshot nem o e-mail.
	 *
	 * @param array|null $analysis Resultado de uonix_intelligence_seo_opportunities(), ou null para ler.
	 * @return array{called: int, skipped: string}
	 */
	function uonix_intelligence_ai_run( $analysis = null, $pause = 2 ) {
		if ( '' === uonix_intelligence_ai_api_key() ) {
			return array( 'called' => 0, 'skipped' => 'not_configured' );
		}
		$limites = uonix_intelligence_ai_limits();
		if ( null === $analysis ) {
			$analysis = function_exists( 'uonix_intelligence_seo_opportunities' ) ? uonix_intelligence_seo_opportunities( null, $limites['per_run'] ) : array();
		}
		if ( ! is_array( $analysis ) || empty( $analysis['available'] ) || ! isset( $analysis['rows'] ) || ! is_array( $analysis['rows'] ) ) {
			return array( 'called' => 0, 'skipped' => 'no_opportunities' );
		}

		$cache    = get_option( uonix_intelligence_ai_option(), array() );
		$cache    = is_array( $cache ) ? $cache : array();
		$novo     = array();
		$chamadas = 0;
		$agora    = gmdate( 'c' );
		foreach ( array_slice( $analysis['rows'], 0, $limites['per_run'] ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['query'] ) || ! is_string( $row['query'] ) ) {
				continue;
			}
			$path  = isset( $row['target_page'] ) && is_string( $row['target_page'] ) ? $row['target_page'] : '';
			$chave = uonix_intelligence_ai_entry_key( $row['query'], $path );
			$input = uonix_intelligence_ai_input( $row );
			if ( null === $input ) {
				// Endereço antigo: segue o 301 até a página real (#343). Só aqui, no cron.
				$destino = uonix_intelligence_ai_follow_redirect( $path );
				$input   = '' !== $destino ? uonix_intelligence_ai_input( $row, $destino ) : null;
			}
			if ( null === $input ) {
				$novo[ $chave ] = array( 'status' => 'no_page', 'attempted_at' => $agora );
				continue;
			}
			$hash     = uonix_intelligence_ai_input_hash( $input );
			$anterior = isset( $cache[ $chave ] ) && is_array( $cache[ $chave ] ) ? $cache[ $chave ] : array();
			if ( 'ok' === ( $anterior['status'] ?? '' ) && $hash === ( $anterior['input_hash'] ?? '' ) ) {
				$novo[ $chave ] = $anterior;
				continue;
			}
			$resultado = uonix_intelligence_ai_call( $input, $pause );
			++$chamadas;
			$entrada = array( 'status' => $resultado['status'], 'input_hash' => $hash, 'attempted_at' => $agora, 'model' => $input['model'] );
			if ( '' !== $input['redirected_to'] ) {
				$entrada['redirected_to'] = $input['redirected_to'];
			}
			if ( 'ok' === $resultado['status'] ) {
				$entrada['suggestion']   = $resultado['suggestion'];
				$entrada['generated_at'] = $agora;
			}
			$novo[ $chave ] = $entrada;
		}
		update_option( uonix_intelligence_ai_option(), $novo, false );

		return array( 'called' => $chamadas, 'skipped' => '' );
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_suggestion_for' ) ) {
	/**
	 * Sugestão de uma oportunidade para o painel e o e-mail. Só lê o cache.
	 *
	 * A entrada é recalculada aqui. Se o título ou a descrição mudaram depois da
	 * geração, o estado é `pending`, e a sugestão antiga não é exibida.
	 */
	function uonix_intelligence_ai_suggestion_for( $row ) {
		if ( '' === uonix_intelligence_ai_api_key() ) {
			return array( 'status' => 'not_configured' );
		}
		if ( ! is_array( $row ) || ! isset( $row['query'] ) || ! is_string( $row['query'] ) || ! array_key_exists( 'target_page', $row ) || null === $row['target_page'] ) {
			return array( 'status' => 'pending' );
		}
		$cache   = get_option( uonix_intelligence_ai_option(), array() );
		$chave   = uonix_intelligence_ai_entry_key( $row['query'], (string) $row['target_page'] );
		$entrada = is_array( $cache ) && isset( $cache[ $chave ] ) && is_array( $cache[ $chave ] ) ? $cache[ $chave ] : array();
		// Endereço antigo (#343): o destino que o cron achou. O leitor não faz HTTP.
		$destino = isset( $entrada['redirected_to'] ) && is_string( $entrada['redirected_to'] ) ? $entrada['redirected_to'] : '';
		$input   = uonix_intelligence_ai_input( $row, $destino );
		if ( null === $input ) {
			return array( 'status' => 'no_page' );
		}
		if ( ( $entrada['input_hash'] ?? '' ) !== uonix_intelligence_ai_input_hash( $input ) ) {
			return array( 'status' => 'pending' );
		}
		$status = isset( $entrada['status'] ) && is_string( $entrada['status'] ) ? $entrada['status'] : 'pending';
		if ( 'ok' !== $status ) {
			return array( 'status' => in_array( $status, array( 'unavailable', 'rejected', 'model_missing' ), true ) ? $status : 'pending' );
		}
		$sugestao = $entrada['suggestion'] ?? null;
		if ( ! is_array( $sugestao ) || ! isset( $sugestao['title'], $sugestao['description'] ) || ! is_string( $sugestao['title'] ) || ! is_string( $sugestao['description'] ) ) {
			return array( 'status' => 'pending' );
		}

		return array(
			'status'              => 'ok',
			'title'               => $sugestao['title'],
			'description'         => $sugestao['description'],
			'current_title'       => $input['title'],
			'current_description' => $input['description'],
			'generated_at'        => isset( $entrada['generated_at'] ) && is_string( $entrada['generated_at'] ) ? $entrada['generated_at'] : '',
			'post_id'             => $input['post_id'],
			'object'              => $input['object'],
			'redirected_to'       => $input['redirected_to'],
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_state_message' ) ) {
	/**
	 * Texto para o operador de cada estado sem sugestão. Estado desconhecido cai em
	 * "aguardando", que é honesto: nada foi gerado para esta entrada.
	 */
	function uonix_intelligence_ai_state_message( $status ) {
		$mapa = array(
			'not_configured' => 'IA não configurada: defina UONIX_GEMINI_API_KEY no wp-config.php.',
			'pending'        => 'Aguardando a próxima geração diária.',
			'unavailable'    => 'O Gemini não respondeu. Nova tentativa na próxima geração diária.',
			'rejected'       => 'Sugestão recusada pela validação: tamanho, formato ou diferencial fora da lista.',
			'no_page'        => 'Sem página publicada para esta consulta: removida ou redirecionada.',
			'model_missing'  => 'Modelo indisponível: confira UONIX_GEMINI_MODEL no wp-config.php.',
		);

		return isset( $mapa[ $status ] ) ? $mapa[ $status ] : $mapa['pending'];
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_schedule' ) ) {
	function uonix_intelligence_ai_schedule() {
		if ( ! wp_next_scheduled( uonix_intelligence_ai_hook() ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', uonix_intelligence_ai_hook() );
		}
	}
}
add_action( 'init', 'uonix_intelligence_ai_schedule', 10, 0 );
// `accepted_args = 0`, como os irmãos de 53 e 57: o callback não usa argumento.
add_action( uonix_intelligence_ai_hook(), 'uonix_intelligence_ai_run', 10, 0 );
