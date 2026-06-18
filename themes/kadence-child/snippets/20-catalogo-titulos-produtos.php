<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Catalogo - normalizacao de titulos de produtos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 5309-5394 do export original.
// -----------------------------------------------------------------------------
/**
 *  Substitui <br> por " - " no nome dos produtos
 */
/**
 * Substitui <br> por " - " no nome dos produtos
 * EM TODO O SITE
 * EXCETO:
 * - Página de produto individual
 * - Página /produtos/
 * - Qualquer URL abaixo de /produtos/
 */

add_filter( 'the_title', 'uonix_replace_br_in_product_titles', 20, 2 );
add_filter( 'woocommerce_product_get_name', 'uonix_replace_br_in_product_titles_wc', 20, 2 );

/**
 * Filtro para títulos gerais
 */
function uonix_replace_br_in_product_titles( $title, $post_id ) {

	// Apenas produtos
	if ( get_post_type( $post_id ) !== 'product' ) {
		return $title;
	}

	// Exceções
	if ( uonix_should_keep_br() ) {
		return $title;
	}

	return uonix_replace_br( $title );
}

/**
 * Filtro específico do WooCommerce
 */
function uonix_replace_br_in_product_titles_wc( $title, $product ) {

	if ( ! $product instanceof WC_Product ) {
		return $title;
	}

	// Exceções
	if ( uonix_should_keep_br() ) {
		return $title;
	}

	return uonix_replace_br( $title );
}

/**
 * Verifica se estamos em páginas onde o <br> DEVE ser mantido
 */
function uonix_should_keep_br() {

	// Produto individual
	if ( is_product() ) {
		return true;
	}

	// Página /produtos/ ou qualquer subpágina
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$uri = trailingslashit( $_SERVER['REQUEST_URI'] );

		if ( strpos( $uri, '/produtos/' ) === 0 || strpos( $uri, '/produtos/' ) !== false ) {
			return true;
		}
	}

	return false;
}

/**
 * Função de replace segura
 */
function uonix_replace_br( $title ) {

	$replacements = array(
		'<br>'   => ' - ',
		'<br/>'  => ' - ',
		'<br />' => ' - ',
	);

	return str_replace( array_keys( $replacements ), array_values( $replacements ), $title );
}


