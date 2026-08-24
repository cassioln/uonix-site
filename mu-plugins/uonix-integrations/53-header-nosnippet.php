<?php
/**
 * Marca o cabeçalho do site com data-nosnippet.
 *
 * O Google monta o snippet da SERP a partir do primeiro conteúdo textual que
 * encontra no HTML. No tema Kadence, o cabeçalho (menu, atendimento, telefone,
 * e-mail) é renderizado antes do conteúdo principal, então o snippet acaba
 * exibindo "MENUMENU / Central de Atendimento ..." em vez da meta description.
 *
 * O atributo data-nosnippet é o mecanismo oficial do Google para excluir um
 * bloco do snippet sem removê-lo da página nem impedir a indexação. Aqui ele é
 * aplicado somente ao bloco de cabeçalho do Kadence (kadence/header), via filtro
 * render_block, sem editar template do tema. Os <header> de cards de post
 * (entry-header) e demais blocos não são afetados.
 *
 * Referência: https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag#data-nosnippet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_header_add_nosnippet' ) ) {
	/**
	 * Injeta data-nosnippet na tag <header> do bloco de cabeçalho do Kadence.
	 *
	 * @param string $block_content HTML já renderizado do bloco.
	 * @param array  $block         Dados do bloco (inclui blockName).
	 * @return string
	 */
	function uonix_header_add_nosnippet( $block_content, $block ) {
		if ( is_admin() ) {
			return $block_content;
		}

		$block_name = is_array( $block ) && isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( 'kadence/header' !== $block_name ) {
			return $block_content;
		}

		if ( ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		// Já marcado (evita duplicar em re-render).
		if ( false !== stripos( $block_content, 'data-nosnippet' ) ) {
			return $block_content;
		}

		// Marca apenas a primeira tag <header ...> do bloco, preservando os
		// demais atributos existentes.
		return preg_replace(
			'/<header\b(?![^>]*\bdata-nosnippet\b)([^>]*)>/i',
			'<header$1 data-nosnippet>',
			$block_content,
			1
		);
	}
}

add_filter( 'render_block', 'uonix_header_add_nosnippet', 10, 2 );
