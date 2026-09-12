<?php
/**
 * Teste e pré-geração de imagens para e-mails de orçamento (RFQ).
 *
 * Valida que:
 * 1. A função uonix_get_email_product_image_url() existe e funciona.
 * 2. Gera arquivos JPEG com resolução 300x300 px e fundo branco puro (255,255,255).
 * 3. Todas as imagens de produtos do catálogo são processadas com sucesso.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 2 ) . '/local/wordpress/wp-load.php';
}

echo "========================================================================\n";
echo "🧪 TESTE: OTIMIZAÇÃO DE IMAGENS DE PRODUTOS PARA E-MAILS DE ORÇAMENTO\n";
echo "========================================================================\n\n";

if ( ! function_exists( 'uonix_get_email_product_image_url' ) ) {
	// Carrega manualmente se não estiver ativo no contexto CLI direto
	$helper = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-woocommerce/29-rfq-email-imagens-produtos.php';
	if ( file_exists( $helper ) ) {
		require_once $helper;
	}
}

if ( ! function_exists( 'uonix_get_email_product_image_url' ) ) {
	exit( "❌ ERRO: Função uonix_get_email_product_image_url() não encontrada!\n" );
}
echo "✅ Função uonix_get_email_product_image_url() carregada com sucesso.\n\n";

$products = wc_get_products( array(
	'limit'  => -1,
	'status' => 'publish',
) );

echo "🔍 Encontrados " . count( $products ) . " produtos no catálogo.\n";
echo "Processando miniaturas de e-mail (300x300 JPEG sobre branco)...\n\n";

$success_count = 0;
$total_count   = 0;
$upload_dir    = wp_upload_dir();

foreach ( $products as $product ) {
	$total_count++;
	$p_id   = $product->get_id();
	$p_name = $product->get_name();
	
	$img_url = uonix_get_email_product_image_url( $product, 300 );
	
	if ( empty( $img_url ) ) {
		echo "⚠️ [SEM IMAGEM] #{$p_id} - {$p_name}\n";
		continue;
	}
	
	// Determina o caminho local do arquivo gerado
	$rel_path = str_replace( $upload_dir['baseurl'], '', $img_url );
	$abs_path = $upload_dir['basedir'] . $rel_path;
	
	if ( ! file_exists( $abs_path ) ) {
		echo "❌ [FALHA ARQUIVO] #{$p_id} - {$p_name}: {$abs_path}\n";
		continue;
	}
	
	$info = @getimagesize( $abs_path );
	if ( ! $info ) {
		echo "❌ [IMAGEM INVÁLIDA] #{$p_id} - {$p_name}\n";
		continue;
	}
	
	$width  = $info[0];
	$height = $info[1];
	$mime   = $info['mime'];
	$size_kb = round( filesize( $abs_path ) / 1024, 1 );
	
	// Validação dos cantos brancos usando GD
	$im = imagecreatefromjpeg( $abs_path );
	$corners_white = false;
	if ( $im ) {
		$c1 = imagecolorat( $im, 0, 0 );
		$c2 = imagecolorat( $im, $width - 1, 0 );
		$c3 = imagecolorat( $im, 0, $height - 1 );
		$c4 = imagecolorat( $im, $width - 1, $height - 1 );
		
		$r1 = ( $c1 >> 16 ) & 0xFF; $g1 = ( $c1 >> 8 ) & 0xFF; $b1 = $c1 & 0xFF;
		$r2 = ( $c2 >> 16 ) & 0xFF; $g2 = ( $c2 >> 8 ) & 0xFF; $b2 = $c2 & 0xFF;
		$r3 = ( $c3 >> 16 ) & 0xFF; $g3 = ( $c3 >> 8 ) & 0xFF; $b3 = $c3 & 0xFF;
		$r4 = ( $c4 >> 16 ) & 0xFF; $g4 = ( $c4 >> 8 ) & 0xFF; $b4 = $c4 & 0xFF;
		
		// Espera branco puro (255,255,255) ou quase puro (>250 por conta de compressão jpeg)
		if ( $r1 >= 250 && $g1 >= 250 && $b1 >= 250 &&
		     $r2 >= 250 && $g2 >= 250 && $b2 >= 250 &&
		     $r3 >= 250 && $g3 >= 250 && $b3 >= 250 &&
		     $r4 >= 250 && $g4 >= 250 && $b4 >= 250 ) {
			$corners_white = true;
		}
		imagedestroy( $im );
	}
	
	if ( 'image/jpeg' === $mime && 300 === $width && 300 === $height && $corners_white ) {
		$success_count++;
		echo "✅ #{$p_id} {$p_name}: {$width}x{$height} {$mime} ({$size_kb} KB) - Fundo Branco OK\n";
	} else {
		echo "⚠️ #{$p_id} {$p_name}: {$width}x{$height} {$mime} ({$size_kb} KB) - Fundo branco: " . ( $corners_white ? 'OK' : 'FALHA' ) . "\n";
	}
}

echo "\n------------------------------------------------------------------------\n";
echo "📊 RESUMO: {$success_count} de {$total_count} produtos com miniaturas de e-mail validadas.\n";
echo "========================================================================\n";
