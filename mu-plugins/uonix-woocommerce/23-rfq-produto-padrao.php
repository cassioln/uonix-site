<?php
/**
 * WooCommerce: RFQ ativo por padrão sem configuração explícita.
 *
 * A ausência de `_gpls_woo_rfq_rfq_enable` equivale a `yes` para produtos e
 * variações. Valores físicos `yes` e `no` continuam sob controle do RFQ Toolkit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define o valor de RFQ somente quando a metadata física está ausente.
 *
 * @param mixed  $value     Valor padrão atual.
 * @param int    $object_id ID do post.
 * @param string $meta_key  Chave consultada.
 * @param bool   $single    Se a leitura espera um valor único.
 * @param string $meta_type Tipo da metadata.
 * @return mixed
 */
function uonix_rfq_produto_valor_padrao( $value, $object_id, $meta_key, $single, $meta_type ) {
	if ( 'post' !== $meta_type || '_gpls_woo_rfq_rfq_enable' !== $meta_key ) {
		return $value;
	}

	$object_id = absint( $object_id );

	if ( ! $object_id ) {
		return $value;
	}

	$post_type = get_post_type( $object_id );

	if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
		return $value;
	}

	return $single ? 'yes' : array( 'yes' );
}

add_filter( 'default_post_metadata', 'uonix_rfq_produto_valor_padrao', 10, 5 );
