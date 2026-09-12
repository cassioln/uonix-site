<?php
/**
 * Script de pré-geração e teste de envio de e-mail de cotação em produção.
 */
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/../../public_html/wp-load.php';

if ( ! function_exists( 'uonix_get_email_product_image_url' ) ) {
	die( "ERRO: uonix_get_email_product_image_url não encontrada!\n" );
}

echo "=== 1. GERANDO MINIATURAS PARA E-MAIL EM PRODUÇÃO ===\n";
$args = array(
	'status' => 'publish',
	'limit'  => -1,
);
$products = wc_get_products( $args );
echo "Total de produtos encontrados: " . count( $products ) . "\n";

$generated = 0;
foreach ( $products as $product ) {
	$url = uonix_get_email_product_image_url( $product, 300 );
	if ( $url ) {
		$generated++;
		echo "  [OK] #" . $product->get_id() . " - " . $product->get_name() . " -> " . basename( $url ) . "\n";
	} else {
		echo "  [SEM IMAGEM] #" . $product->get_id() . " - " . $product->get_name() . "\n";
	}
}
echo "Miniaturas prontas: $generated\n\n";

echo "=== 2. VERIFICANDO URL PÚBLICA DE EXEMPLO ===\n";
$escova = wc_get_product( 11179 ); // Escova de Nylon
if ( $escova ) {
	$escova_url = uonix_get_email_product_image_url( $escova, 300 );
	echo "Escova Nylon URL: $escova_url\n";
}
$limpador = wc_get_product( 11182 ); // Limpador de Furos
if ( $limpador ) {
	$limpador_url = uonix_get_email_product_image_url( $limpador, 300 );
	echo "Limpador URL: $limpador_url\n";
}
