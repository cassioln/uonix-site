<?php
/**
 * WooCommerce (admin): preço normal padrão 0 em novas variações.
 *
 * Toda variação criada sem preço nasce com `_regular_price = 0`, para que o
 * campo "Preço normal (R$)" já venha preenchido em vez de vazio e o WooCommerce
 * pare de emitir o aviso "N variações sem preço".
 *
 * Ponto de entrada escolhido: `woocommerce_new_product_variation`, disparado
 * pelo data store logo após a inserção. Isso cobre os três caminhos de criação
 * (botão "Adicionar variação", "Gerar variações" e importação/CSV), enquanto um
 * filtro de renderização cobriria apenas a tela. O campo do formulário é
 * montado por `woocommerce_wp_text_input()`, que não expõe filtro de valor,
 * portanto não existe hook de view equivalente.
 *
 * Só grava quando o preço está realmente ausente: variação criada já com preço
 * (duplicação, importação com valor) permanece intacta. Não altera variações
 * existentes nem toca no preço promocional.
 *
 * Sem guarda de contexto (`is_admin()`) de propósito: o hook só dispara na
 * criação de uma variação, e `is_admin()` é falso em WP-CLI, importação CSV e
 * REST — contextos legítimos que também devem receber o preço padrão.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preço padrão aplicado a novas variações sem preço definido.
 *
 * @return string Preço padrão já normalizado.
 */
function uonix_variacao_preco_padrao() {
	/**
	 * Permite ajustar o preço padrão das novas variações.
	 *
	 * @param string $preco Preço padrão em formato aceito pelo WooCommerce.
	 */
	$preco = apply_filters( 'uonix_variacao_preco_padrao', '0' );

	return is_scalar( $preco ) ? (string) $preco : '0';
}

add_action(
	'woocommerce_new_product_variation',
	function ( $variation_id, $variation = null ) {
		$variation_id = absint( $variation_id );

		if ( ! $variation_id ) {
			return;
		}

		if ( ! $variation instanceof WC_Product_Variation ) {
			$variation = wc_get_product( $variation_id );
		}

		if ( ! $variation instanceof WC_Product_Variation ) {
			return;
		}

		// Preço já definido na criação (duplicação, importação): não sobrescrever.
		if ( '' !== (string) $variation->get_regular_price( 'edit' ) ) {
			return;
		}

		$preco = uonix_variacao_preco_padrao();

		$variation->set_regular_price( $preco );

		// Sem preço promocional, o preço ativo acompanha o preço normal.
		if ( '' === (string) $variation->get_sale_price( 'edit' ) ) {
			$variation->set_price( $preco );
		}

		$variation->save();

		// Mantém o produto pai coerente (faixa de preço e aviso de variação sem preço).
		$parent_id = $variation->get_parent_id();

		if ( $parent_id ) {
			wc_delete_product_transients( $parent_id );

			if ( function_exists( 'wc_deferred_product_sync' ) ) {
				wc_deferred_product_sync( $parent_id );
			}
		}
	},
	10,
	2
);
