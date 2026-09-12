<?php
/**
 * Script de manutenção e pré-geração de miniaturas para e-mails de cotação (RFQ).
 *
 * Modo de segurança (Fail-Closed):
 * - Execução estritamente restrita à linha de comando (CLI) como script principal (bloqueia require/include).
 * - Padrão é --dry-run (somente leitura / inspeção diagnóstica sem mutações no disco).
 * - Flags desconhecidas são categoricamente rejeitadas com código de erro 1.
 * - wp_upload_dir chamado com create_dir=false (nenhuma criação implícita de diretórios em dry-run).
 * - Para gravar/atualizar miniaturas fisicamente no disco, passe explicitamente a flag --execute ou --apply.
 * - Validação física estrita via getimagesize() (arquivos de 1 byte ou truncados são rejeitados).
 */

// 1. Bloqueia execução secundária se incluído via require/include em outros scripts
if ( ! isset( $_SERVER['SCRIPT_FILENAME'] ) || realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) {
	return;
}

// 2. Bloqueia execução fora de CLI (suporta env UONIX_MOCK_SAPI para teste comportamental do guard)
$current_sapi = getenv( 'UONIX_MOCK_SAPI' ) ? getenv( 'UONIX_MOCK_SAPI' ) : php_sapi_name();
if ( 'cli' !== $current_sapi ) {
	if ( function_exists( 'http_response_code' ) ) {
		http_response_code( 403 );
	}
	fwrite( STDERR, "ERRO: Acesso restrito à linha de comando (CLI).\n" );
	exit( 1 );
}

// 3. Validação estrita de argumentos da linha de comando
$raw_args = isset( $argv ) ? array_slice( $argv, 1 ) : array();

$is_execute = false;
$is_dry_run = false;
$is_help    = false;

$allowed_flags = array( '--dry-run', '--execute', '--apply', '--help', '-h' );

foreach ( $raw_args as $arg ) {
	if ( ! in_array( $arg, $allowed_flags, true ) ) {
		fwrite( STDERR, "ERRO: Opção desconhecida '{$arg}'. Opções válidas: --dry-run, --execute, --apply, --help.\n" );
		exit( 1 );
	}
	if ( '--execute' === $arg || '--apply' === $arg ) {
		$is_execute = true;
	} elseif ( '--dry-run' === $arg ) {
		$is_dry_run = true;
	} elseif ( '--help' === $arg || '-h' === $arg ) {
		$is_help = true;
	}
}

if ( $is_help ) {
	echo "Uso: php scripts/maintenance/generate-prod-email-thumbs.php [opções]\n\n";
	echo "Opções:\n";
	echo "  --dry-run   (Padrão seguro) Inspeciona produtos e integridade do cache sem efetuar gravações.\n";
	echo "  --execute   Gera e grava as miniaturas físicas em wp-content/uploads/email-thumbs/.\n";
	echo "  --apply     Alias para --execute.\n";
	echo "  --help, -h  Exibe esta mensagem de ajuda.\n";
	exit( 0 );
}

// 4. Carregamento do ambiente WordPress/WooCommerce
$custom_wp_load     = getenv( 'UONIX_WP_LOAD' );
$wp_load_candidates = array();
if ( $custom_wp_load && file_exists( $custom_wp_load ) ) {
	$wp_load_candidates[] = $custom_wp_load;
}
$wp_load_candidates = array_merge( $wp_load_candidates, array(
	__DIR__ . '/../../public_html/wp-load.php',
	__DIR__ . '/../../wp-load.php',
	dirname( __DIR__, 2 ) . '/public_html/wp-load.php',
	dirname( __DIR__, 2 ) . '/wp-load.php',
) );

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

// 5. wp_upload_dir com create_dir = false para garantir zero mutações em dry-run
$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array( 'basedir' => '', 'baseurl' => '' );
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
$corrupt_count  = 0;

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

	$source_file       = get_attached_file( $thumb_id );
	$expected_filename = sprintf( 'email-thumb-%d-300x300.jpg', $thumb_id );
	$dest_file         = $cache_dir . '/' . $expected_filename;

	if ( ! $is_execute ) {
		// Modo DRY-RUN: apenas inspeciona se o arquivo existe e é uma imagem JPEG íntegra
		if ( file_exists( $dest_file ) ) {
			$info = ( filesize( $dest_file ) > 100 ) ? @getimagesize( $dest_file ) : false;
			$is_valid_jpeg = ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) && ( $info[2] === IMAGETYPE_JPEG || ( isset( $info['mime'] ) && $info['mime'] === 'image/jpeg' ) ) );

			if ( $is_valid_jpeg ) {
				$success_count++;
				echo "  [CACHE EXISTENTE] #{$product_id} - {$name} -> {$expected_filename}\n";
			} else {
				$corrupt_count++;
				echo "  [CACHE INVÁLIDO/CORROMPIDO] #{$product_id} - {$name} -> tamanho: " . filesize( $dest_file ) . " bytes (requer regeneração)\n";
			}
		} else {
			echo "  [PENDENTE DE GERAÇÃO] #{$product_id} - {$name} (origem: " . ( $source_file && file_exists( $source_file ) ? basename( $source_file ) : 'arquivo ausente' ) . ")\n";
		}
		continue;
	}

	// Modo EXECUTE: gera e valida fisicamente no disco com integridade JPEG estrita
	$url = uonix_get_email_product_image_url( $product, 300 );
	$is_generated_thumb = ( false !== strpos( $url, 'email-thumbs/' ) );

	$file_on_disk_valid = false;
	if ( file_exists( $dest_file ) && filesize( $dest_file ) > 100 ) {
		$info = @getimagesize( $dest_file );
		if ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) && ( $info[2] === IMAGETYPE_JPEG || ( isset( $info['mime'] ) && $info['mime'] === 'image/jpeg' ) ) ) {
			$file_on_disk_valid = true;
		}
	}

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
	if ( $corrupt_count > 0 ) {
		echo "  Arquivos em cache corrompidos/inválidos: {$corrupt_count}\n";
	}
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
