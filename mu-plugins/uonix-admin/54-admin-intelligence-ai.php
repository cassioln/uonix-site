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
		return array( 'title' => 60, 'description' => 155, 'per_run' => 5, 'timeout' => 15, 'max_output_tokens' => 1024 );
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

if ( ! function_exists( 'uonix_intelligence_ai_meta_text' ) ) {
	/**
	 * Texto de uma meta do Rank Math pronto para o pedido. Variáveis (`%title%`,
	 * `%sep%`…) são resolvidas pelo Rank Math quando ele expõe o resolvedor. O que
	 * sobrar sem resolver vira '', e quem chama cai no título do post: o template
	 * cru nunca vai ao Gemini.
	 */
	function uonix_intelligence_ai_meta_text( $raw, $post_id ) {
		$texto = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' !== $texto && false !== strpos( $texto, '%' ) && is_callable( array( 'RankMath\\Helper', 'replace_vars' ) ) && function_exists( 'get_post' ) ) {
			$texto = (string) call_user_func( array( 'RankMath\\Helper', 'replace_vars' ), $texto, get_post( $post_id ) );
		}
		$texto = trim( html_entity_decode( wp_strip_all_tags( $texto ), ENT_QUOTES, 'UTF-8' ) );

		return 1 === preg_match( '/%[a-z_]+%/i', $texto ) ? '' : $texto;
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_input' ) ) {
	/**
	 * Entrada do pedido para uma oportunidade, ou null sem consulta ou sem post publicado.
	 *
	 * Fronteira de dados: cada chave é montada uma a uma a partir da linha. Nada mais
	 * da linha passa, mesmo que um dia ela carregue outros campos.
	 */
	function uonix_intelligence_ai_input( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['query'] ) || ! is_string( $row['query'] ) || '' === $row['query'] ) {
			return null;
		}
		$path    = isset( $row['target_page'] ) && is_string( $row['target_page'] ) ? $row['target_page'] : '';
		$post_id = uonix_intelligence_ai_page_post_id( $path );
		if ( 0 === $post_id ) {
			return null;
		}
		$titulo = uonix_intelligence_ai_meta_text( get_post_meta( $post_id, 'rank_math_title', true ), $post_id );
		if ( '' === $titulo ) {
			$titulo = trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ) );
		}

		return array(
			'query'           => $row['query'],
			'impressions'     => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
			'position'        => round( (float) ( $row['position'] ?? 0 ), 1 ),
			'ctr'             => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
			'page_url'        => home_url( $path ),
			'title'           => $titulo,
			'description'     => uonix_intelligence_ai_meta_text( get_post_meta( $post_id, 'rank_math_description', true ), $post_id ),
			'differentiators' => uonix_intelligence_seo_differentiators(),
			'model'           => uonix_intelligence_ai_model(),
			'post_id'         => $post_id,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_ai_input_hash' ) ) {
	function uonix_intelligence_ai_input_hash( array $input ) {
		return hash( 'sha256', (string) wp_json_encode( $input ) );
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
	 * Corpo do `generateContent`. Só os campos abaixo vão ao Gemini: `post_id` e `model`
	 * da entrada ficam de fora.
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
