<?php
/**
 * Marca páginas de FLUXO como noindex.
 *
 * POR QUE ESTE ARQUIVO EXISTE
 *
 * O Search Console reportou 9 páginas em "Página com redirecionamento" não indexadas. Uma
 * delas é `/finalizar-orcamento/`: medido em produção, ela devolve 302 para `/cotacao/`
 * quando o carrinho está vazio — que é o caso do Googlebot, que nunca tem carrinho.
 *
 * Páginas de fluxo (carrinho, checkout, conta) não têm valor de busca:
 *
 *   - o conteúdo é dinâmico e vazio para quem chega sem sessão
 *   - ninguém pesquisa "finalizar orçamento uônix" no Google
 *   - consomem crawl budget que deveria ir para produtos e serviços
 *   - aparecem como erro no relatório, escondendo problemas reais
 *
 * COMO, E POR QUE ASSIM
 *
 * Uso o filtro `wp_robots` do core, mesmo padrão de 06-environment-indexing.php. Isso
 * garante que:
 *
 *   - as diretivas de ambiente (dev/QA em noindex) continuam funcionando: só ACRESCENTO,
 *     nunca substituo o array
 *   - vale para o Rank Math também, porque ele respeita wp_robots
 *   - fica VERSIONADO. A alternativa era marcar noindex no painel do Rank Math, que grava
 *     em post meta — invisível no repositório e perdido num restore de banco.
 *
 * As páginas são identificadas por FUNÇÃO (is_cart, is_checkout), não por ID. ID muda entre
 * ambientes e um clone quebraria a regra em silêncio.
 *
 * @package Uonix\Security
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'uonix_pagina_de_fluxo_atual' ) ) {
	/**
	 * A requisição atual é de uma página de fluxo (não indexável)?
	 *
	 * @param bool|null $forcar Valor forçado, só para teste.
	 * @return bool
	 */
	function uonix_pagina_de_fluxo_atual( $forcar = null ) {
		if ( null !== $forcar ) {
			return (bool) $forcar;
		}

		// Sem WooCommerce carregado não há página de fluxo a proteger.
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return false;
		}

		// A confirmação do pedido (order-received) precisa ficar acessível ao cliente, mas
		// também não deve ser indexada: contém dados do pedido.
		if ( is_cart() || is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'uonix_noindex_paginas_de_fluxo' ) ) {
	/**
	 * Acrescenta noindex nas páginas de fluxo, sem remover diretivas já registradas.
	 *
	 * Mesma disciplina de 06-environment-indexing.php: o array é ACRESCIDO, nunca
	 * substituído. Sobrescrever apagaria o noindex de ambiente e um deploy em QA passaria a
	 * ser indexado — bem pior que o problema que este arquivo resolve.
	 *
	 * @param array     $robots Diretivas atuais.
	 * @param bool|null $forcar Valor forçado, só para teste.
	 * @return array
	 */
	function uonix_noindex_paginas_de_fluxo( $robots, $forcar = null ) {
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}

		if ( ! uonix_pagina_de_fluxo_atual( $forcar ) ) {
			return $robots;
		}

		$robots['noindex'] = true;

		// `follow` deliberadamente NÃO é alterado: os links do carrinho para produtos
		// continuam passando autoridade. Só a página em si sai do índice.
		return $robots;
	}
}

add_filter( 'wp_robots', 'uonix_noindex_paginas_de_fluxo', 20 );
