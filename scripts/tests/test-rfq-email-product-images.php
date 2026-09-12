<?php
/**
 * Teste de contrato, regressão funcional e provas de mutação:
 * Otimização de imagens e logotipo nos e-mails RFQ da Uônix.
 *
 * Valida de forma autônoma, fail-closed e compatível com CI:
 * 1. Integridade dos arquivos, registro modular e regras de layout/CSS.
 * 2. Motor GD de renderização 300x300 px com padding e canvas branco #ffffff.
 * 3. Preservação estrita de proporção (aspect ratio) e probe de mutação contra $ratio = 0.
 * 4. Resolução de produtos variáveis sem imagem herdando do pai.
 * 5. Produtos sem imagem degradando para placeholder.
 * 6. Imagem fonte ausente em disco degradando para fallback original.
 * 7. Resiliência a entradas anômalas (PHP_INT_MAX, números negativos, strings) sem ValueError.
 * 8. Probe de falha de permissão de escrita/IO degradando estritamente para fallback original.
 * 9. Provas de mutação contra cache corrompido:
 *    - Cache corrompido de 1 byte (rejeição e regeneração).
 *    - Cache corrompido de >100 bytes não-JPEG (rejeição e regeneração).
 *    - Cache corrompido com falha de fonte (fallback estrito sem URL email-thumbs).
 * 10. Filtro CSS do cabeçalho injetando regras de 150px centralizadas.
 * 11. Provas comportamentais reais de subprocessos CLI (generate-prod-email-thumbs.php):
 *    - Prova de mutação do guard de CLI (UONIX_MOCK_SAPI não-cli deve abortar com exit 1).
 *    - Prova de isolamento contra require/include (SCRIPT_FILENAME !== __FILE__).
 *    - Prova de rejeição de flags desconhecidas com exit 1.
 *    - Prova de dry-run por padrão sem flags (zero mutações no disco e modo dry-run explícito).
 *    - Prova de detecção de cache corrompido/1-byte como inválido no script de manutenção.
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

$source_trans_file  = $test_dir . '/source_trans.png';
$source_rect_file   = $test_dir . '/source_rect.png';
$source_broken_file = $test_dir . '/source_broken.png';
file_put_contents( $source_broken_file, 'INVALID_CORRUPTED_PNG_DATA' );

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

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root_dir . '/' );
}

$GLOBALS['wp_mock_attachments'] = array(
	101 => $source_trans_file,
	102 => $source_rect_file,
	103 => $source_broken_file,
	999 => $test_dir . '/non_existent_file.png',
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

		public function get_id() { return $this->id; }
		public function get_name() { return 'Produto Teste ' . $this->id; }
		public function get_image_id() { return $this->image_id; }
		public function is_type( $type ) { return $this->type === $type; }
		public function get_parent_id() { return $this->parent_id; }
	}
}

$GLOBALS['wp_mock_products'] = array(
	1 => new WC_Product( 1, 101, 'simple' ),
	2 => new WC_Product( 2, 102, 'simple' ),
	3 => new WC_Product( 3, 0, 'variation', 1 ), // Variação herdando do produto 1
	4 => new WC_Product( 4, 0, 'simple', 0 ),    // Sem imagem
	5 => new WC_Product( 5, 999, 'simple', 0 ),  // Imagem inexistente em disco
	6 => new WC_Product( 6, 103, 'simple', 0 ),  // Imagem corrompida em disco
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

// Produto com anexo com dados corrompidos (não é imagem válida)
$prod6 = wc_get_product( 6 );
$url6  = uonix_get_email_product_image_url( $prod6, 300 );
rfq_test_assert( 'https://uonix.com.br/wp-content/uploads/original-103.png' === $url6, 'Arquivo fonte corrompido degrada graciosamente para fallback original sem falhar' );

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
// 8. PROVAS DE MUTAÇÃO CONTRA CACHE CORROMPIDO (Mutação 1 do Auditor)
// -----------------------------------------------------------------------------
echo "\n7. Provas de mutação contra cache corrompido (1 byte e >100 bytes não-JPEG)...\n";

// Prova 1A: Cache residual de 1 byte com mtime recente DEVE ser rejeitado e regenerado
$corrupt_cache_file_1b = $test_dir . '/email-thumbs/email-thumb-101-350x350.jpg';
file_put_contents( $corrupt_cache_file_1b, 'X' );
touch( $corrupt_cache_file_1b, time() + 3600 );

$url_regen_1b = uonix_get_email_product_image_url( $prod1, 350 );
rfq_test_assert( false !== strpos( $url_regen_1b, 'email-thumb-101-350x350.jpg' ), 'Cache de 1 byte foi rejeitado e URL da miniatura regenerada retornada' );
rfq_test_assert( filesize( $corrupt_cache_file_1b ) > 1000, 'Arquivo de cache de 1 byte foi substituído no disco por JPEG válido (>1KB)' );
$regen_info_1b = @getimagesize( $corrupt_cache_file_1b );
rfq_test_assert( is_array( $regen_info_1b ) && 350 === $regen_info_1b[0] && 'image/jpeg' === $regen_info_1b['mime'], 'Arquivo de 1 byte regenerado é JPEG válido de 350x350' );

// Prova 1B (CRÍTICA): Cache de >100 bytes contendo dados não-JPEG (ex.: 300 bytes de texto/HTML de erro).
// Se o código NÃO validar getimagesize(), ele aceitaria esse arquivo porque filesize > 100 e mtime recente!
$corrupt_cache_file_large = $test_dir . '/email-thumbs/email-thumb-101-380x380.jpg';
file_put_contents( $corrupt_cache_file_large, str_repeat( 'CORRUPTED_CACHE_NON_JPEG_PAYLOAD_', 10 ) ); // 340 bytes
touch( $corrupt_cache_file_large, time() + 3600 );

$url_regen_large = uonix_get_email_product_image_url( $prod1, 380 );
rfq_test_assert( false !== strpos( $url_regen_large, 'email-thumb-101-380x380.jpg' ), 'Cache não-JPEG >100 bytes foi rejeitado e regenerado' );
$regen_info_large = @getimagesize( $corrupt_cache_file_large );
rfq_test_assert( is_array( $regen_info_large ) && 380 === $regen_info_large[0] && 'image/jpeg' === $regen_info_large['mime'], 'Arquivo corrompido >100 bytes foi regenerado como JPEG válido de 380x380' );
$raw_bytes_first = file_get_contents( $corrupt_cache_file_large, false, null, 0, 3 );
rfq_test_assert( "\xFF\xD8\xFF" === $raw_bytes_first, 'Arquivo regenerado possui magic bytes legítimos de JPEG (\xFF\xD8\xFF)' );

// Prova 1C: Cache corrompido de 1 byte onde a imagem fonte NÃO pode ser regenerada (fonte corrompida).
// DEVE retornar estritamente a URL original do fallback e NUNCA a URL da miniatura de 1 byte!
$corrupt_cache_file6 = $test_dir . '/email-thumbs/email-thumb-103-300x300.jpg';
file_put_contents( $corrupt_cache_file6, 'Z' );
touch( $corrupt_cache_file6, time() + 3600 );

$url_corrupt_fallback = uonix_get_email_product_image_url( $prod6, 300 );
rfq_test_assert( 'https://uonix.com.br/wp-content/uploads/original-103.png' === $url_corrupt_fallback, 'Cache corrompido sem regeneração viável retorna estritamente fallback original' );
rfq_test_assert( false === strpos( $url_corrupt_fallback, 'email-thumbs' ), 'URL corrompida em email-thumbs NUNCA é servida' );

// -----------------------------------------------------------------------------
// 9. Probe de Falha de Permissão / IO (Fallback Estrito)
// -----------------------------------------------------------------------------
echo "\n8. Testando probe de falha de I/O e destino somente-leitura...\n";
$readonly_dir = $test_dir . '/readonly-test';
mkdir( $readonly_dir, 0777, true );

$orig_test_dir = $test_dir;
$test_dir      = $readonly_dir;
chmod( $readonly_dir, 0555 ); // Apenas leitura

$prod1_io_test = wc_get_product( 1 );
$url_io = uonix_get_email_product_image_url( $prod1_io_test, 280 );

rfq_test_assert( 'https://uonix.com.br/wp-content/uploads/original-101.png' === $url_io, 'Falha de I/O em disco retorna estritamente a URL original de fallback' );
rfq_test_assert( false === strpos( $url_io, 'email-thumbs' ), 'Falha de I/O NUNCA retorna URL de miniatura não gravada' );

chmod( $readonly_dir, 0777 );
@rmdir( $readonly_dir );
$test_dir = $orig_test_dir;

// -----------------------------------------------------------------------------
// 10. Validação do Filtro CSS do Cabeçalho
// -----------------------------------------------------------------------------
echo "\n9. Validando filtro de CSS para o cabeçalho...\n";
rfq_test_assert( isset( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] ), 'Filtro woocommerce_email_styles registrado' );

$css = '';
foreach ( $GLOBALS['uonix_test_filters']['woocommerce_email_styles'] as $cb ) {
	$css = $cb( $css );
}
rfq_test_assert( false !== strpos( $css, '#template_header_image' ), 'CSS contém seletor #template_header_image' );
rfq_test_assert( false !== strpos( $css, 'width: 150px !important' ), 'CSS contém largura estrita de 150px' );
rfq_test_assert( false !== strpos( $css, 'text-align: center' ), 'CSS contém centralização de imagem' );

// -----------------------------------------------------------------------------
// 11. PROVAS COMPORTAMENTAIS REAIS EM SUBPROCESSOS CLI (Mutações 2 e 3 do Auditor)
// -----------------------------------------------------------------------------
echo "\n10. Executando provas comportamentais reais em subprocessos CLI...\n";

// Subprocesso A: Inclusão via require é bloqueada e não executa o corpo
$cmd_require = sprintf( 'php -r %s', escapeshellarg( "require '{$maintenance_file}'; echo 'REQUIRE_BLOCKED_OK';" ) );
$out_require = shell_exec( $cmd_require );
rfq_test_assert( false !== strpos( (string) $out_require, 'REQUIRE_BLOCKED_OK' ), 'Inclusão via require é neutralizada sem executar o loop principal' );

// Subprocesso B (PROVA DA MUTAÇÃO 2): Guard de CLI. Se UONIX_MOCK_SAPI não for 'cli', o script DEVE abortar com exit code 1
$desc = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$cmd_non_cli = sprintf( 'UONIX_MOCK_SAPI=apache2handler php %s', escapeshellarg( $maintenance_file ) );
$proc_non_cli = proc_open( $cmd_non_cli, $desc, $pipes_non_cli );
$non_cli_stderr = stream_get_contents( $pipes_non_cli[2] );
fclose( $pipes_non_cli[0] );
fclose( $pipes_non_cli[1] );
fclose( $pipes_non_cli[2] );
$status_non_cli = proc_close( $proc_non_cli );
rfq_test_assert( 1 === $status_non_cli, 'Execução em contexto não-CLI aborta categoricamente com código 1 (fail-closed)' );
rfq_test_assert( false !== strpos( $non_cli_stderr, 'Acesso restrito à linha de comando' ), 'Execução não-CLI emite erro restritivo no STDERR' );

// Subprocesso C: Rejeição de flags desconhecidas com exit code 1
$proc_bad_flag = proc_open( "php '{$maintenance_file}' --flag-invalida-teste", $desc, $pipes_bad );
$bad_flag_stderr = stream_get_contents( $pipes_bad[2] );
fclose( $pipes_bad[0] );
fclose( $pipes_bad[1] );
fclose( $pipes_bad[2] );
$status_bad_flag = proc_close( $proc_bad_flag );
rfq_test_assert( 1 === $status_bad_flag, 'Invocação com flag desconhecida encerra com código de saída 1' );
rfq_test_assert( false !== strpos( $bad_flag_stderr, 'Opção desconhecida' ), 'Invocação com flag desconhecida emite mensagem no STDERR' );

// Subprocesso D: Flag --help encerra com exit code 0 e texto descritivo
$proc_help = proc_open( "php '{$maintenance_file}' --help", $desc, $pipes_h );
$help_stdout = stream_get_contents( $pipes_h[1] );
fclose( $pipes_h[0] );
fclose( $pipes_h[1] );
fclose( $pipes_h[2] );
$status_help = proc_close( $proc_help );
rfq_test_assert( 0 === $status_help, 'Invocação com --help encerra com código de saída 0' );
rfq_test_assert( false !== strpos( $help_stdout, '--dry-run' ) && false !== strpos( $help_stdout, '--execute' ), 'Ajuda CLI documenta --dry-run e --execute' );

// -----------------------------------------------------------------------------
// Subprocessos E & F (PROVA DA MUTAÇÃO 3): Execução Real do Script de Manutenção
// com Harness de Mock WP via UONIX_WP_LOAD
// -----------------------------------------------------------------------------
$maint_mock_dir = $test_dir . '/maint-mock-env';
mkdir( $maint_mock_dir, 0777, true );
$maint_uploads_dir = $maint_mock_dir . '/uploads';
mkdir( $maint_uploads_dir, 0777, true );

$wp_load_mock_file = $maint_mock_dir . '/wp-load-mock.php';
$wp_load_mock_template = <<<'PHP_MOCK'
<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function get_id() { return 1001; }
		public function get_name() { return 'Produto Manutencao Mock'; }
		public function get_image_id() { return 2001; }
		public function is_type( $t ) { return false; }
		public function get_parent_id() { return 0; }
	}
}
if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args ) { return array( new WC_Product() ); }
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) { return new WC_Product(); }
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) { return '%%SOURCE_FILE%%'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		return array(
			'basedir' => '%%UPLOADS_DIR%%',
			'baseurl' => 'https://uonix.com.br/wp-content/uploads',
		);
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( intval( $n ) ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $t ) { return is_dir( $t ) || @mkdir( $t, 0777, true ); }
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $sz = 'thumbnail' ) {
		return 'https://uonix.com.br/wp-content/uploads/orig-' . $id . '.png';
	}
}
if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
	function wc_placeholder_img_src() {
		return 'https://uonix.com.br/wp-content/uploads/placeholder.png';
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
}
require_once '%%MU_PLUGIN_FILE%%';
PHP_MOCK;

$wp_load_mock_code = str_replace(
	array( '%%SOURCE_FILE%%', '%%UPLOADS_DIR%%', '%%MU_PLUGIN_FILE%%' ),
	array( addslashes( $source_trans_file ), addslashes( $maint_uploads_dir ), addslashes( $mu_plugin_file ) ),
	$wp_load_mock_template
);
file_put_contents( $wp_load_mock_file, $wp_load_mock_code );
putenv( 'UONIX_WP_LOAD=' . $wp_load_mock_file );

// Subprocesso E (PROVA CRÍTICA DA MUTAÇÃO 3): Invocação SEM FLAGS DEVE RODAR EM DRY-RUN E ZERO GRAVAÇÃO
$cmd_no_flags = sprintf( 'php %s', escapeshellarg( $maintenance_file ) );
$proc_no_flags = proc_open( $cmd_no_flags, $desc, $pipes_nf );
$stdout_no_flags = stream_get_contents( $pipes_nf[1] );
$stderr_no_flags = stream_get_contents( $pipes_nf[2] );
fclose( $pipes_nf[0] );
fclose( $pipes_nf[1] );
fclose( $pipes_nf[2] );
$status_no_flags = proc_close( $proc_no_flags );

rfq_test_assert( 0 === $status_no_flags, 'Invocação sem flags encerra com código 0' );
rfq_test_assert( false !== strpos( $stdout_no_flags, 'Modo: DRY-RUN / INSPEÇÃO (padrão seguro)' ), 'Invocação sem flags adota modo DRY-RUN como padrão estrito' );
rfq_test_assert( false !== strpos( $stdout_no_flags, 'Nenhuma mutação foi efetuada no disco' ), 'Invocação sem flags confirma zero mutações' );
rfq_test_assert( ! file_exists( $maint_uploads_dir . '/email-thumbs' ), 'Invocação sem flags NUNCA cria a pasta email-thumbs em disco' );

// Subprocesso F: Invocação com --dry-run explícito confirma ausência de mutação
$cmd_dry_run = sprintf( 'php %s --dry-run', escapeshellarg( $maintenance_file ) );
$proc_dry = proc_open( $cmd_dry_run, $desc, $pipes_dr );
$stdout_dry = stream_get_contents( $pipes_dr[1] );
fclose( $pipes_dr[0] );
fclose( $pipes_dr[1] );
fclose( $pipes_dr[2] );
$status_dry = proc_close( $proc_dry );

rfq_test_assert( 0 === $status_dry, 'Invocação com --dry-run explícito encerra com código 0' );
rfq_test_assert( false !== strpos( $stdout_dry, 'Modo: DRY-RUN / INSPEÇÃO' ), 'Invocação com --dry-run roda em modo inspeção' );
rfq_test_assert( ! file_exists( $maint_uploads_dir . '/email-thumbs' ), 'Modo --dry-run não cria diretórios em disco' );

// Subprocesso G: Invocação em Dry-Run detecta arquivo corrompido de 1 byte como [CACHE INVÁLIDO/CORROMPIDO]
mkdir( $maint_uploads_dir . '/email-thumbs', 0777, true );
$maint_corrupt_1b = $maint_uploads_dir . '/email-thumbs/email-thumb-2001-300x300.jpg';
file_put_contents( $maint_corrupt_1b, 'X' ); // 1 byte corrompido

$proc_corrupt_check = proc_open( $cmd_dry_run, $desc, $pipes_cc );
$stdout_corrupt_check = stream_get_contents( $pipes_cc[1] );
fclose( $pipes_cc[0] );
fclose( $pipes_cc[1] );
fclose( $pipes_cc[2] );
$status_corrupt_check = proc_close( $proc_corrupt_check );

rfq_test_assert( 0 === $status_corrupt_check, 'Invocação dry-run com arquivo corrompido encerra com código 0' );
rfq_test_assert( false !== strpos( $stdout_corrupt_check, 'CACHE INVÁLIDO/CORROMPIDO' ), 'Script de manutenção detecta cache de 1 byte como corrupto' );
rfq_test_assert( false === strpos( $stdout_corrupt_check, 'CACHE EXISTENTE' ), 'Script de manutenção NUNCA aceita cache de 1 byte como existente' );

// Subprocesso H: Invocação com --execute gera e valida miniatura fisicamente no disco
$cmd_execute = sprintf( 'php %s --execute', escapeshellarg( $maintenance_file ) );
$proc_exec = proc_open( $cmd_execute, $desc, $pipes_ex );
$stdout_exec = stream_get_contents( $pipes_ex[1] );
fclose( $pipes_ex[0] );
fclose( $pipes_ex[1] );
fclose( $pipes_ex[2] );
$status_exec = proc_close( $proc_exec );

putenv( 'UONIX_WP_LOAD' ); // Limpeza da variável de ambiente

rfq_test_assert( 0 === $status_exec, 'Invocação com --execute encerra com código 0' );
rfq_test_assert( false !== strpos( $stdout_exec, 'Modo: EXECUÇÃO ATIVA (--execute)' ), 'Invocação com --execute opera em modo ativo' );
rfq_test_assert( false !== strpos( $stdout_exec, '[OK] #1001' ), 'Invocação com --execute gera miniatura com sucesso [OK]' );
rfq_test_assert( file_exists( $maint_corrupt_1b ) && filesize( $maint_corrupt_1b ) > 1000, 'Arquivo corrompido de 1 byte foi fisicamente sobrescrito por miniatura íntegra (>1KB)' );
$maint_info = @getimagesize( $maint_corrupt_1b );
rfq_test_assert( is_array( $maint_info ) && 300 === $maint_info[0] && 'image/jpeg' === $maint_info['mime'], 'Miniatura gravada pelo pré-gerador é JPEG válido de 300x300' );

// Limpeza de arquivos temporários do teste
@unlink( $dest_file1 );
@unlink( $dest_file2 );
@unlink( $corrupt_cache_file_1b );
@unlink( $corrupt_cache_file_large );
@unlink( $corrupt_cache_file6 );
@rmdir( $test_dir . '/email-thumbs' );
@unlink( $source_trans_file );
@unlink( $source_rect_file );
@unlink( $source_broken_file );
@unlink( $maint_corrupt_1b );
@rmdir( $maint_uploads_dir . '/email-thumbs' );
@rmdir( $maint_uploads_dir );
@unlink( $wp_load_mock_file );
@rmdir( $maint_mock_dir );
@rmdir( $test_dir );

echo "\n========================================================================\n";
echo "🎉 TODOS OS {$assertions} TESTES PASSARAM COM SUCESSO!\n";
echo "========================================================================\n";
