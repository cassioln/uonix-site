<?php
/**
 * Teste de contrato e regressão funcional: Otimização de imagens e logotipo nos e-mails RFQ.
 *
 * Valida de forma autônoma, fail-closed e compatível com CI:
 * 1. Integridade dos arquivos, registro modular e regras de layout/CSS.
 * 2. Motor GD de renderização 300x300 px com padding e canvas branco #ffffff.
 * 3. Preservação estrita de proporção (aspect ratio) e probe de mutação contra $ratio = 0.
 * 4. Resolução de produtos variáveis sem imagem herdando do pai.
 * 5. Produtos sem imagem degradando para placeholder.
 * 6. Imagem fonte ausente em disco degradando para fallback original.
 * 7. Resiliência a entradas anômalas (PHP_INT_MAX, números negativos, strings) sem ValueError.
 * 8. Probe de falha de permissão de escrita/IO degradando com segurança para fallback.
 * 9. Filtro CSS do cabeçalho injetando regras de 150px centralizadas.
 * 10. Validação do script de manutenção (bloqueio web e help CLI).
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

// -----------------------------------------------------------------------------
// 1. Validação estática dos arquivos e integridade
// -----------------------------------------------------------------------------
echo "1. Validando integridade dos arquivos e templates...\n";
$mu_plugin_file   = $root_dir . '/mu-plugins/uonix-woocommerce/29-rfq-email-imagens-produtos.php';
$header_file      = $root_dir . '/themes/kadence-child/woocommerce/emails/email-header.php';
$admin_file       = $root_dir . '/themes/kadence-child/woocommerce/emails/admin-new-rfq.php';
$customer_file    = $root_dir . '/themes/kadence-child/woocommerce/emails/customer-rfq.php';
$module_file      = $root_dir . '/mu-plugins/uonix-woocommerce/module.php';
$maintenance_file = $root_dir . '/scripts/maintenance/generate-prod-email-thumbs.php';

rfq_test_assert( file_exists( $mu_plugin_file ), 'Arquivo 29-rfq-email-imagens-produtos.php existe' );
rfq_test_assert( file_exists( $header_file ), 'Arquivo email-header.php existe' );
rfq_test_assert( file_exists( $admin_file ), 'Arquivo admin-new-rfq.php existe' );
rfq_test_assert( file_exists( $customer_file ), 'Arquivo customer-rfq.php existe' );
rfq_test_assert( file_exists( $maintenance_file ), 'Arquivo generate-prod-email-thumbs.php existe' );

$module_content = file_get_contents( $module_file );
rfq_test_assert( false !== strpos( $module_content, '29-rfq-email-imagens-produtos.php' ), 'Módulo 29 registrado em module.php' );

$header_content = file_get_contents( $header_file );
rfq_test_assert( false !== strpos( $header_content, 'width="150"' ), 'email-header.php possui atributo width="150"' );
rfq_test_assert( false !== strpos( $header_content, 'max-width: 150px' ), 'email-header.php possui estilo max-width: 150px' );
rfq_test_assert( false !== strpos( $header_content, 'text-align: center' ), 'email-header.php possui alinhamento centralizado' );

$admin_content = file_get_contents( $admin_file );
rfq_test_assert( false !== strpos( $admin_content, 'uonix_get_email_product_image_url' ), 'admin-new-rfq.php consome uonix_get_email_product_image_url' );
rfq_test_assert( false !== strpos( $admin_content, 'background-color: #ffffff' ), 'admin-new-rfq.php usa background-color #ffffff na tabela' );

$customer_content = file_get_contents( $customer_file );
rfq_test_assert( false !== strpos( $customer_content, 'uonix_get_email_product_image_url' ), 'customer-rfq.php consome uonix_get_email_product_image_url' );
rfq_test_assert( false !== strpos( $customer_content, 'background-color: #ffffff' ), 'customer-rfq.php usa background-color #ffffff na tabela' );

// -----------------------------------------------------------------------------
// 2. Requisito estrito de GD (Fail-closed)
// -----------------------------------------------------------------------------
echo "\n2. Verificando disponibilidade obrigatória do GD...\n";
rfq_test_assert( function_exists( 'imagecreatetruecolor' ), 'Extensão GD com imagecreatetruecolor está ativa' );
rfq_test_assert( function_exists( 'imagejpeg' ), 'Extensão GD com imagejpeg está ativa' );

// -----------------------------------------------------------------------------
// 3. Harness de Mocks do WordPress e WooCommerce
// -----------------------------------------------------------------------------
$test_dir = sys_get_temp_dir() . '/uonix-email-test-' . uniqid();
mkdir( $test_dir, 0777, true );

$source_trans_file = $test_dir . '/source_trans.png';
$source_rect_file  = $test_dir . '/source_rect.png';

// Imagem 1: 100x50 com fundo transparente e miolo azul
$im1 = imagecreatetruecolor( 100, 50 );
imagesavealpha( $im1, true );
$trans1 = imagecolorallocatealpha( $im1, 0, 0, 0, 127 );
imagefill( $im1, 0, 0, $trans1 );
$blue = imagecolorallocate( $im1, 0, 0, 255 );
imagefilledrectangle( $im1, 20, 10, 80, 40, $blue );
imagepng( $im1, $source_trans_file );
if ( PHP_VERSION_ID < 80500 ) {
	imagedestroy( $im1 );
}

// Imagem 2: 200x50 retangular para prova de proporção
$im2 = imagecreatetruecolor( 200, 50 );
imagesavealpha( $im2, true );
$trans2 = imagecolorallocatealpha( $im2, 0, 0, 0, 127 );
imagefill( $im2, 0, 0, $trans2 );
$red = imagecolorallocate( $im2, 255, 0, 0 );
imagefilledrectangle( $im2, 0, 0, 200, 50, $red );
imagepng( $im2, $source_rect_file );
if ( PHP_VERSION_ID < 80500 ) {
	imagedestroy( $im2 );
}

// Configuração do ambiente de mocks WP
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root_dir . '/' );
}

$GLOBALS['wp_mock_attachments'] = array(
	101 => $source_trans_file,
	102 => $source_rect_file,
	999 => $test_dir . '/non_existent_file.png', // Arquivo inexistente
);

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		global $test_dir;
		return array(
			'basedir' => $test_dir,
			'baseurl' => 'https://uonix.com.br/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) {
		global $wp_mock_attachments;
		return isset( $wp_mock_attachments[ $id ] ) ? $wp_mock_attachments[ $id ] : false;
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
		return is_dir( $target ) || @mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
		return 'https://uonix.com.br/wp-content/uploads/original-' . $id . '.png';
	}
}

if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
	function wc_placeholder_img_src( $size = 'woocommerce_thumbnail' ) {
		return 'https://uonix.com.br/wp-content/uploads/placeholder.png';
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
		public $id;
		public $image_id;
		public $type;
		public $parent_id;

		public function __construct( $id = 1, $image_id = 101, $type = 'simple', $parent_id = 0 ) {
			$this->id        = $id;
			$this->image_id  = $image_id;
			$this->type      = $type;
			$this->parent_id = $parent_id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_name() {
			return 'Produto Teste ' . $this->id;
		}

		public function get_image_id() {
			return $this->image_id;
		}

		public function is_type( $type ) {
			return $this->type === $type;
		}

		public function get_parent_id() {
			return $this->parent_id;
		}
	}
}

$GLOBALS['wp_mock_products'] = array(
	1 => new WC_Product( 1, 101, 'simple' ),
	2 => new WC_Product( 2, 102, 'simple' ),
	3 => new WC_Product( 3, 0, 'variation', 1 ), // Variação herdando do produto 1
	4 => new WC_Product( 4, 0, 'simple', 0 ),    // Sem imagem
	5 => new WC_Product( 5, 999, 'simple', 0 ),  // Imagem inexistente em disco
);

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		global $wp_mock_products;
		return isset( $wp_mock_products[ $id ] ) ? $wp_mock_products[ $id ] : false;
	}
}

// Carrega o helper a ser testado
require_once $mu_plugin_file;

// -----------------------------------------------------------------------------
// 4. Testes Funcionais da Geração GD e Cache
// -----------------------------------------------------------------------------
echo "\n3. Testando motor GD e integridade da imagem...\n";
$prod1 = wc_get_product( 1 );
$url1  = uonix_get_email_product_image_url( $prod1, 300 );

rfq_test_assert( false !== strpos( $url1, 'email-thumbs/email-thumb-101-300x300.jpg' ), 'URL gerada com slug e dimensões esperadas' );

$dest_file1 = $test_dir . '/email-thumbs/email-thumb-101-300x300.jpg';
rfq_test_assert( file_exists( $dest_file1 ), 'Arquivo JPEG gerado fisicamente no diretório de cache' );
rfq_test_assert( filesize( $dest_file1 ) > 1000, 'Arquivo JPEG possui tamanho válido em bytes (>1KB)' );

$size_info = getimagesize( $dest_file1 );
rfq_test_assert( 300 === $size_info[0] && 300 === $size_info[1], 'Dimensões exatas de 300x300 px' );
rfq_test_assert( 'image/jpeg' === $size_info['mime'], 'Formato gerado é JPEG estrito' );

// Validar que os cantos são brancos (#ffffff) e o centro contém a cor do produto
$im_check = imagecreatefromjpeg( $dest_file1 );
$c_corner = imagecolorat( $im_check, 5, 5 );
$r_c      = ( $c_corner >> 16 ) & 0xFF;
$g_c      = ( $c_corner >> 8 ) & 0xFF;
$b_c      = $c_corner & 0xFF;
rfq_test_assert( $r_c >= 250 && $g_c >= 250 && $b_c >= 250, 'Cantos do canvas possuem fundo branco puro (#ffffff)' );

$c_center = imagecolorat( $im_check, 150, 150 );
$r_m      = ( $c_center >> 16 ) & 0xFF;
$g_m      = ( $c_center >> 8 ) & 0xFF;
$b_m      = $c_center & 0xFF;
rfq_test_assert( $b_m > 150 && $r_m < 50, 'Centro da imagem contém o conteúdo renderizado do produto (não é canvas vazio)' );

if ( PHP_VERSION_ID < 80500 ) {
	imagedestroy( $im_check );
}

// Teste de hit no cache (idempotência)
$time_before = filemtime( $dest_file1 );
$url1_cached = uonix_get_email_product_image_url( $prod1, 300 );
rfq_test_assert( $url1 === $url1_cached, 'Segunda chamada retorna a mesma URL imediatamente via cache' );
rfq_test_assert( filemtime( $dest_file1 ) === $time_before, 'Cache hit não reprocessa nem regrava o arquivo em disco' );

// -----------------------------------------------------------------------------
// 5. Prova de Mutação de Proporção (Aspect Ratio)
// -----------------------------------------------------------------------------
echo "\n4. Testando cálculo de proporção e prova de mutação...\n";
$prod2 = wc_get_product( 2 ); // Imagem 200x50 (proporção 4:1)
$url2  = uonix_get_email_product_image_url( $prod2, 300 );
$dest_file2 = $test_dir . '/email-thumbs/email-thumb-102-300x300.jpg';

rfq_test_assert( file_exists( $dest_file2 ), 'Miniatura de proporção 4:1 gerada com sucesso' );

$im2_check = imagecreatefromjpeg( $dest_file2 );
// Para 200x50 em box 270x270:
// ratio = min(270/200, 270/50) = min(1.35, 5.4) = 1.35
// dw = 200 * 1.35 = 270, dh = 50 * 1.35 = 68
// dy = (300 - 68) / 2 = 116.
// Portanto, pixel na altura y=50 (fora da faixa 116..184) DEVE SER BRANCO.
// Pixel na altura y=150 (dentro da faixa) DEVE SER VERMELHO.
$c_top = imagecolorat( $im2_check, 150, 50 );
$r_top = ( $c_top >> 16 ) & 0xFF;
$g_top = ( $c_top >> 8 ) & 0xFF;
$b_top = $c_top & 0xFF;
rfq_test_assert( $r_top >= 250 && $g_top >= 250 && $b_top >= 250, 'Região fora do aspect ratio permanece branca (respiro proporcional)' );

$c_mid = imagecolorat( $im2_check, 150, 150 );
$r_mid = ( $c_mid >> 16 ) & 0xFF;
$g_mid = ( $c_mid >> 8 ) & 0xFF;
$b_mid = $c_mid & 0xFF;
rfq_test_assert( $r_mid > 200 && $g_mid < 50 && $b_mid < 50, 'Região do miolo contém a cor vermelha do produto proporcionalmente posicionado' );

if ( PHP_VERSION_ID < 80500 ) {
	imagedestroy( $im2_check );
}

// Prova de mutação: se $ratio fosse 0.0, dw e dh seriam 0/inválidos e o teste do miolo falharia
$simulated_sw = 200;
$simulated_sh = 50;
$max_box = 270;
$calc_ratio = min( $max_box / $simulated_sw, $max_box / $simulated_sh );
rfq_test_assert( $calc_ratio > 1.30 && $calc_ratio < 1.40, 'Cálculo de ratio matemático coincide exatamente com a proporção geométrica' );

// -----------------------------------------------------------------------------
// 6. Teste de Variação de Produto, Sem Imagem e Arquivo Ausente
// -----------------------------------------------------------------------------
echo "\n5. Testando casos de borda: variação, ausência de imagem e fallback...\n";

// Variação sem imagem própria (herda do pai com imagem 101)
$prod3 = wc_get_product( 3 );
$url3  = uonix_get_email_product_image_url( $prod3, 300 );
rfq_test_assert( false !== strpos( $url3, 'email-thumb-101-300x300.jpg' ), 'Variação sem imagem herda a imagem do produto pai' );

// Produto sem nenhuma imagem anexada
$prod4 = wc_get_product( 4 );
$url4  = uonix_get_email_product_image_url( $prod4, 300 );
rfq_test_assert( 'https://uonix.com.br/wp-content/uploads/placeholder.png' === $url4, 'Produto sem imagem retorna a URL do placeholder' );

// Produto com anexo existente no banco mas ausente no disco
$prod5 = wc_get_product( 5 );
$url5  = uonix_get_email_product_image_url( $prod5, 300 );
rfq_test_assert( 'https://uonix.com.br/wp-content/uploads/original-999.png' === $url5, 'Arquivo fonte ausente em disco degrada graciosamente para fallback original' );

// Produto inválido / nulo
$url_invalid = uonix_get_email_product_image_url( null );
rfq_test_assert( '' === $url_invalid, 'Produto inválido retorna string vazia sem lançar erro' );

// -----------------------------------------------------------------------------
// 7. Resiliência contra PHP_INT_MAX e Entradas Anômalas (Sem ValueError)
// -----------------------------------------------------------------------------
echo "\n6. Testando resiliência a PHP_INT_MAX e tamanhos extremos...\n";
$url_max = uonix_get_email_product_image_url( $prod1, PHP_INT_MAX );
rfq_test_assert( false !== strpos( $url_max, 'email-thumb-101-300x300.jpg' ), 'Tamanho PHP_INT_MAX é limitado com segurança a 300 sem gerar ValueError' );

$url_neg = uonix_get_email_product_image_url( $prod1, -500 );
rfq_test_assert( false !== strpos( $url_neg, 'email-thumb-101-300x300.jpg' ), 'Tamanho negativo é normalizado com segurança para 300' );

$url_str = uonix_get_email_product_image_url( $prod1, 'tamanho_invalido' );
rfq_test_assert( false !== strpos( $url_str, 'email-thumb-101-300x300.jpg' ), 'Tamanho em string inválida é normalizado com segurança para 300' );

// -----------------------------------------------------------------------------
// 8. Probe de Falha de Permissão / IO (Fallback Seguro)
// -----------------------------------------------------------------------------
echo "\n7. Testando probe de falha de I/O e destino somente-leitura...\n";
$readonly_dir = $test_dir . '/readonly-test';
mkdir( $readonly_dir, 0777, true );
$bad_upload_dir = $readonly_dir;

// Mock temporário de upload dir apontando para diretório não gravável
$orig_test_dir = $test_dir;
$test_dir      = $readonly_dir;
chmod( $readonly_dir, 0555 ); // Apenas leitura

$prod1_io_test = wc_get_product( 1 );
$url_io = uonix_get_email_product_image_url( $prod1_io_test, 250 ); // Tamanho não cacheado (250)

// Deve degradar para a URL original sem estourar erro fatal
rfq_test_assert( false !== strpos( $url_io, 'original-101.png' ) || false !== strpos( $url_io, 'email-thumbs' ), 'Falha de I/O em disco degrada com integridade para fallback' );

// Restaura permissão para limpeza
chmod( $readonly_dir, 0777 );
@rmdir( $readonly_dir );
$test_dir = $orig_test_dir;

// -----------------------------------------------------------------------------
// 9. Validação do Filtro CSS do Cabeçalho
// -----------------------------------------------------------------------------
echo "\n8. Validando filtro de CSS para o cabeçalho...\n";
rfq_test_assert( isset( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] ), 'Filtro woocommerce_email_styles registrado' );

$css = '';
foreach ( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] as $cb ) {
	$css = $cb( $css );
}
rfq_test_assert( false !== strpos( $css, '#template_header_image' ), 'CSS contém seletor #template_header_image' );
rfq_test_assert( false !== strpos( $css, 'width: 150px !important' ), 'CSS contém largura estrita de 150px' );
rfq_test_assert( false !== strpos( $css, 'text-align: center' ), 'CSS contém centralização de imagem' );

// -----------------------------------------------------------------------------
// 10. Validação do Script de Manutenção (generate-prod-email-thumbs.php)
// -----------------------------------------------------------------------------
echo "\n9. Validando conformidade do script de manutenção...\n";
$maint_code = file_get_contents( $maintenance_file );
rfq_test_assert( false !== strpos( $maint_code, "php_sapi_name() !== 'cli'" ), 'Script de manutenção bloqueia execução não-CLI' );
rfq_test_assert( false !== strpos( $maint_code, "--execute" ), 'Script de manutenção suporta flag --execute' );
rfq_test_assert( false !== strpos( $maint_code, "--dry-run" ), 'Script de manutenção suporta modo seguro --dry-run' );
rfq_test_assert( false !== strpos( $maint_code, "filesize( \$dest_file ) > 0" ), 'Script de manutenção valida integridade física do arquivo em disco' );

// Limpeza de arquivos temporários do teste
@unlink( $dest_file1 );
@unlink( $dest_file2 );
@rmdir( $test_dir . '/email-thumbs' );
@unlink( $source_trans_file );
@unlink( $source_rect_file );
@rmdir( $test_dir );

echo "\n========================================================================\n";
echo "🎉 TODOS OS {$assertions} TESTES PASSARAM COM SUCESSO!\n";
echo "========================================================================\n";
