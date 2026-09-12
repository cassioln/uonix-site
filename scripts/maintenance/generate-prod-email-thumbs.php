<?php
/**
 * Script de manutenção e pré-geração de miniaturas para e-mails de cotação (RFQ).
 *
 * Modo de segurança (Fail-Closed):
 * - Execução estritamente restrita à linha de comando (CLI).
 * - Padrão é --dry-run (somente leitura / inspeção diagnóstica).
 * - Para gravar/atualizar miniaturas fisicamente no disco, passe explicitamente a flag --execute ou --apply.
 * - Validação estrita de integridade física em disco (não aceita fallback silencioso como sucesso).
 */

if ( php_sapi_name() !== 'cli' ) {
	if ( function_exists( 'http_response_code' ) ) {
		http_response_code( 403 );
	}
	fwrite( STDERR, "ERRO: Acesso restrito à linha de comando (CLI).\n" );
	exit( 1 );
}

$args_cli   = isset( $argv ) ? $argv : array();
$is_execute = in_array( '--execute', $args_cli, true ) || in_array( '--apply', $args_cli, true );
$is_help    = in_array( '--help', $args_cli, true ) || in_array( '-h', $args_cli, true );

if ( $is_help ) {
	echo "Uso: php scripts/maintenance/generate-prod-email-thumbs.php [opções]\n\n";
	echo "Opções:\n";
	echo "  --dry-run   (Padrão) Inspeciona produtos e estado do cache sem efetuar gravações.\n";
	echo "  --execute   Gera e grava as miniaturas físicas em wp-content/uploads/email-thumbs/.\n";
	echo "  --apply     Alias para --execute.\n";
	echo "  --help, -h  Exibe esta mensagem de ajuda.\n";
	exit( 0 );
}

$wp_load_candidates = array(
	__DIR__ . '/../../public_html/wp-load.php',
	__DIR__ . '/../../wp-load.php',
	dirname( __DIR__, 2 ) . '/public_html/wp-load.php',
	dirname( __DIR__, 2 ) . '/wp-load.php',
);

$wp_loaded = false;
foreach ( $wp_load_candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		define( 'WP_USE_THEMES', false );
		require_once $candidate;
		$wp_loaded = true;
		break;
	}
}

if ( ! $wp_loaded || ! function_exists( 'wc_get_products' ) ) {
	fwrite( STDERR, "ERRO: Ambiente WordPress/WooCommerce não localizado.\n" );
	exit( 1 );
}

if ( ! function_exists( 'uonix_get_email_product_image_url' ) ) {
	fwrite( STDERR, "ERRO: Função uonix_get_email_product_image_url não carregada!\n" );
	exit( 1 );
}

$upload_dir = wp_upload_dir();
$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'email-thumbs';

echo "========================================================================\n";
echo "🛠️  PRÉ-GERADOR DE MINIATURAS RFQ — UÔNIX\n";
echo "========================================================================\n";
echo "Modo: " . ( $is_execute ? "EXECUÇÃO ATIVA (--execute)" : "DRY-RUN / INSPEÇÃO (padrão seguro)" ) . "\n";
echo "Diretório de cache: " . $cache_dir . "\n\n";

$products = wc_get_products( array(
	'status' => 'publish',
	'limit'  => -1,
) );

$total_products = count( $products );
echo "Total de produtos encontrados: {$total_products}\n\n";

$success_count  = 0;
$no_image_count = 0;
$failure_count  = 0;

foreach ( $products as $product ) {
	$product_id = $product->get_id();
	$name       = $product->get_name();
	$thumb_id   = $product->get_image_id();

	if ( ! $thumb_id && $product->is_type( 'variation' ) ) {
		$parent_id = method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : 0;
		if ( $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent && is_a( $parent, 'WC_Product' ) ) {
				$thumb_id = $parent->get_image_id();
			}
		}
	}

	if ( ! $thumb_id ) {
		$no_image_count++;
		echo "  [SEM IMAGEM] #{$product_id} - {$name}\n";
		continue;
	}

	$source_file = get_attached_file( $thumb_id );
	$expected_filename = sprintf( 'email-thumb-%d-300x300.jpg', $thumb_id );
	$dest_file         = $cache_dir . '/' . $expected_filename;

	if ( ! $is_execute ) {
		// Modo DRY-RUN: apenas inspeciona se o arquivo já existe e é válido
		if ( file_exists( $dest_file ) && filesize( $dest_file ) > 0 ) {
			$success_count++;
			echo "  [CACHE EXISTENTE] #{$product_id} - {$name} -> {$expected_filename}\n";
		} else {
			echo "  [PENDENTE DE GERAÇÃO] #{$product_id} - {$name} (origem: " . ( $source_file && file_exists( $source_file ) ? basename( $source_file ) : 'arquivo ausente' ) . ")\n";
		}
		continue;
	}

	// Modo EXECUTE: gera e valida fisicamente no disco
	$url = uonix_get_email_product_image_url( $product, 300 );
	$is_generated_thumb = ( false !== strpos( $url, 'email-thumbs/' ) );
	$file_on_disk_valid = ( file_exists( $dest_file ) && filesize( $dest_file ) > 0 );

	if ( $is_generated_thumb && $file_on_disk_valid ) {
		$success_count++;
		echo "  [OK] #{$product_id} - {$name} -> {$expected_filename} (" . round( filesize( $dest_file ) / 1024, 1 ) . " KB)\n";
	} else {
		$failure_count++;
		echo "  [FALHA/FALLBACK] #{$product_id} - {$name} -> URL retornada: {$url}\n";
	}
}

echo "\n------------------------------------------------------------------------\n";
echo "Resumo:\n";
echo "  Produtos analisados: {$total_products}\n";
if ( $is_execute ) {
	echo "  Miniaturas geradas e validadas: {$success_count}\n";
	echo "  Produtos sem imagem anexada: {$no_image_count}\n";
	echo "  Falhas / Fallbacks: {$failure_count}\n";
} else {
	echo "  Miniaturas em cache válido: {$success_count}\n";
	echo "  Produtos sem imagem anexada: {$no_image_count}\n";
	echo "\nℹ️  Modo DRY-RUN concluído. Nenhuma mutação foi efetuada no disco.\n";
	echo "   Para gerar ou atualizar as miniaturas fisicamente, execute com:\n";
	echo "   php scripts/maintenance/generate-prod-email-thumbs.php --execute\n";
}
echo "------------------------------------------------------------------------\n";

if ( $is_execute && $failure_count > 0 ) {
	fwrite( STDERR, "❌ Concluído com {$failure_count} falhas na geração de miniaturas.\n" );
	exit( 1 );
}

exit( 0 );
