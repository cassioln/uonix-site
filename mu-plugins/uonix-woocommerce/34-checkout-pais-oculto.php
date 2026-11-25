<?php
/**
 * Mantém o país de cobrança no checkout da cotação.
 *
 * O WooCommerce 11.3.1 passou a recusar o pedido sem país de cobrança
 * ("This store cannot accept your order without a billing country."). A loja só
 * vende para o Brasil, então o WooCommerce oculta o campo, e o Woo Checkout
 * Field Editor Pro o mantém desabilitado: nenhum país seria enviado e todo
 * pedido de orçamento falharia.
 *
 * O filtro abaixo é o mecanismo do próprio plugin: ele devolve o país base
 * (BR) como campo oculto, semeia o cliente e carimba o pedido. Não muda o que
 * o visitante vê. Se uma versão futura do plugin remover o filtro, o contrato
 * em ops/plugins/contratos.json reprova o ensaio antes de chegar a produção.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'thwcfd_preserve_hidden_country_field', '__return_true' );
