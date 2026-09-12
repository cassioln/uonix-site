<?php
/**
 * Teste de contrato: Otimização de imagens de produtos e logotipo nos e-mails de orçamento (RFQ).
 *
 * Valida de forma autônoma (compatível com CI) e dinâmica:
 * 1. Estrutura e integridade dos templates de e-mail e mu-plugin.
 * 2. Comportamento de uonix_get_email_product_image_url() com padding proporcional e fundo branco.
 * 3. Regras CSS e atributos do logotipo no cabeçalho (150px).
 */

set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
	if ( E_NOTICE === $errno || E_USER_NOTICE === $errno ) {
		return true;
	}
	return false;
} );

$assertions = 0;

function rfq_test_assert( $condition, $message ) {
	global $assertions;
	$assertions++;
	if ( ! $condition ) {
		fwrite( STDERR, "❌ FALHA: {$message}\n" );
		exit( 1 );
	}
	echo "  [OK] {$message}\n";
}

echo "========================================================================\n";
echo "🧪 TESTE: OTIMIZAÇÃO DE IMAGENS E LOGOTIPO NOS E-MAILS RFQ\n";
echo "========================================================================\n\n";

$root_dir = dirname( __DIR__, 2 );

// 1. Validação estática dos arquivos
echo "1. Validando integridade dos arquivos...\n";
$mu_plugin_file = $root_dir . '/mu-plugins/uonix-woocommerce/29-rfq-email-imagens-produtos.php';
$header_file    = $root_dir . '/themes/kadence-child/woocommerce/emails/email-header.php';
$admin_file     = $root_dir . '/themes/kadence-child/woocommerce/emails/admin-new-rfq.php';
$customer_file  = $root_dir . '/themes/kadence-child/woocommerce/emails/customer-rfq.php';
$module_file    = $root_dir . '/mu-plugins/uonix-woocommerce/module.php';

rfq_test_assert( file_exists( $mu_plugin_file ), 'Arquivo 29-rfq-email-imagens-produtos.php existe' );
rfq_test_assert( file_exists( $header_file ), 'Arquivo email-header.php existe' );
rfq_test_assert( file_exists( $admin_file ), 'Arquivo admin-new-rfq.php existe' );
rfq_test_assert( file_exists( $customer_file ), 'Arquivo customer-rfq.php existe' );

// 2. Registro no module.php
$module_content = file_get_contents( $module_file );
rfq_test_assert( false !== strpos( $module_content, '29-rfq-email-imagens-produtos.php' ), 'Módulo 29 registrado em module.php' );

// 3. Validação do template de cabeçalho (email-header.php)
echo "\n2. Validando regras do cabeçalho e logotipo...\n";
$header_content = file_get_contents( $header_file );
rfq_test_assert( false !== strpos( $header_content, 'width="150"' ), 'email-header.php possui atributo width="150"' );
rfq_test_assert( false !== strpos( $header_content, 'max-width: 150px' ), 'email-header.php possui estilo max-width: 150px' );
rfq_test_assert( false !== strpos( $header_content, 'text-align: center' ), 'email-header.php possui alinhamento centralizado' );

// 4. Validação dos templates de e-mail (admin-new-rfq.php e customer-rfq.php)
echo "\n3. Validando integração nos templates RFQ...\n";
$admin_content = file_get_contents( $admin_file );
rfq_test_assert( false !== strpos( $admin_content, 'uonix_get_email_product_image_url' ), 'admin-new-rfq.php consome uonix_get_email_product_image_url' );
rfq_test_assert( false !== strpos( $admin_content, 'background-color: #ffffff' ), 'admin-new-rfq.php usa background-color #ffffff na tabela' );

$customer_content = file_get_contents( $customer_file );
rfq_test_assert( false !== strpos( $customer_content, 'uonix_get_email_product_image_url' ), 'customer-rfq.php consome uonix_get_email_product_image_url' );
rfq_test_assert( false !== strpos( $customer_content, 'background-color: #ffffff' ), 'customer-rfq.php usa background-color #ffffff na tabela' );

// 5. Teste funcional da geração de imagem com GD
echo "\n4. Testando motor GD de composição sobre branco...\n";
if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	echo "  ⚠️ GD não disponível no ambiente de teste; pulando validação de renderização binária.\n";
} else {
	$temp_dir = sys_get_temp_dir() . '/uonix-test-email-' . uniqid();
	mkdir( $temp_dir, 0777, true );
	$temp_source = $temp_dir . '/source.png';

	// Cria uma imagem transparente de teste (100x50)
	$src_im = imagecreatetruecolor( 100, 50 );
	imagesavealpha( $src_im, true );
	$trans = imagecolorallocatealpha( $src_im, 0, 0, 0, 127 );
	imagefill( $src_im, 0, 0, $trans );
	$black = imagecolorallocate( $src_im, 0, 0, 0 );
	imagefilledrectangle( $src_im, 20, 10, 80, 40, $black );
	imagepng( $src_im, $temp_source );
	if ( PHP_VERSION_ID < 80500 ) {
		imagedestroy( $src_im );
	}

	// Mocks mínimos do WordPress para testar a função isoladamente
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root_dir . '/' );
	}
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		function wp_upload_dir() {
			global $temp_dir;
			return array(
				'basedir' => $temp_dir,
				'baseurl' => 'http://example.com/uploads',
			);
		}
	}
	if ( ! function_exists( 'get_attached_file' ) ) {
		function get_attached_file( $id ) {
			global $temp_source;
			return $temp_source;
		}
	}
	if ( ! function_exists( 'trailingslashit' ) ) {
		function trailingslashit( $string ) {
			return rtrim( $string, '/\\' ) . '/';
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ) {
			return abs( intval( $maybeint ) );
		}
	}
	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( $target ) {
			return is_dir( $target ) || mkdir( $target, 0777, true );
		}
	}
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url() {
			return '';
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		$GLOBALS['uonix_test_filters'] = array();
		function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['uonix_test_filters'][ $tag ][] = $callback;
		}
	}
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_image_id() { return 999; }
			public function is_type( $type ) { return false; }
		}
	}

	require_once $mu_plugin_file;

	$mock_product = new WC_Product();
	$generated_url = uonix_get_email_product_image_url( $mock_product, 300 );

	rfq_test_assert( false !== strpos( $generated_url, 'email-thumb-999-300x300.jpg' ), 'URL gerada contém nome esperado da miniatura' );

	$generated_file = $temp_dir . '/email-thumbs/email-thumb-999-300x300.jpg';
	rfq_test_assert( file_exists( $generated_file ), 'Arquivo JPEG foi gerado fisicamente no disco' );

	$info = getimagesize( $generated_file );
	rfq_test_assert( 300 === $info[0] && 300 === $info[1], 'Dimensões exatas de 300x300 px' );
	rfq_test_assert( 'image/jpeg' === $info['mime'], 'Formato gerado é JPEG' );

	// Validar que os cantos do canvas são brancos puros (255, 255, 255)
	$im = imagecreatefromjpeg( $generated_file );
	$c1 = imagecolorat( $im, 0, 0 );
	$r  = ( $c1 >> 16 ) & 0xFF;
	$g  = ( $c1 >> 8 ) & 0xFF;
	$b  = $c1 & 0xFF;
	if ( PHP_VERSION_ID < 80500 ) {
		imagedestroy( $im );
	}
	rfq_test_assert( $r >= 250 && $g >= 250 && $b >= 250, 'Cantos do canvas são brancos (#ffffff)' );

	// Limpeza dos temporários
	@unlink( $generated_file );
	@rmdir( $temp_dir . '/email-thumbs' );
	@unlink( $temp_source );
	@rmdir( $temp_dir );
}

// 6. Teste do filtro CSS
echo "\n5. Validando filtro de CSS para o cabeçalho...\n";
if ( isset( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] ) ) {
	$css = '';
	foreach ( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] as $cb ) {
		$css = $cb( $css );
	}
	rfq_test_assert( false !== strpos( $css, '#template_header_image' ), 'CSS contém seletor #template_header_image' );
	rfq_test_assert( false !== strpos( $css, '150px' ), 'CSS contém largura de 150px' );
}

echo "\n========================================================================\n";
echo "🎉 TODOS OS {$assertions} TESTES PASSARAM COM SUCESSO!\n";
echo "========================================================================\n";
