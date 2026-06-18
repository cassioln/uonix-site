<?php
/**
 * WooCommerce: orçamento, checkout, carrinho e confirmação do pedido.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'03-woocommerce-checkout-cpf-cnpj.php',
		'12-carrinho-menu-scroll.php',
		'13-carrinho-badge-autoopen.php',
		'14-checkout-newsletter-mirror.php',
		'15-carrinho-mini-cart-sidebar.php',
		'16-woocommerce-thank-you.php',
		'17-woocommerce-checkout-design.php',
		'18-produto-alertas-variacao.php',
		'20-catalogo-titulos-produtos.php',
	),
	'uonix-woocommerce'
);
