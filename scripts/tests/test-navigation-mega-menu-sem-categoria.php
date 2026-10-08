<?php
/**
 * O megamenu de produtos (uonix_menu_categorias) não pode derrubar o site nem
 * emitir aviso quando a categoria não existe:
 * - sem o WooCommerce (taxonomia ausente) ou com um slug renomeado no painel,
 *   get_term_by() devolve false e get_term_link() um WP_Error, que concatenado
 *   como string era erro fatal no site inteiro (#416);
 * - um filtro de terceiros pode fazer get_term_by() devolver WP_Error, e ler
 *   ->description e ->term_id dele gerava Warning (#423).
 *
 * Qualquer Warning, Notice ou Deprecated reprova o teste.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['avisos'] = array();
set_error_handler(
	function ( $nivel, $mensagem, $arquivo, $linha ) {
		$GLOBALS['avisos'][] = basename( $arquivo ) . ":{$linha}: {$mensagem}";
		return true;
	}
);

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

class WP_Query {
	public $posts = array();
	private $restantes;
	public function __construct( $args ) {
		unset( $args );
		$this->posts     = $GLOBALS['produtos'];
		$this->restantes = $GLOBALS['produtos'];
	}
	public function have_posts() {
		return ! empty( $this->restantes );
	}
	public function the_post() {
		$GLOBALS['post_atual'] = array_shift( $this->restantes );
	}
}

$GLOBALS['termos']   = array();
$GLOBALS['produtos'] = array();

function add_action( ...$args ) {
	unset( $args );
}
function add_shortcode( ...$args ) {
	unset( $args );
}
function is_wp_error( $valor ) {
	return $valor instanceof WP_Error;
}
function get_term_by( $campo, $valor, $taxonomia ) {
	unset( $campo, $taxonomia );
	if ( ! empty( $GLOBALS['term_by_erro'] ) ) {
		return new WP_Error( 'filtro_terceiro', 'Filtro de terceiros.' );
	}
	return $GLOBALS['termos'][ $valor ] ?? false;
}
function get_term_link( $termo ) {
	if ( ! is_object( $termo ) || $termo instanceof WP_Error ) {
		return new WP_Error( 'invalid_term', 'Empty Term.' );
	}
	return 'https://uonix.test/categoria/' . $termo->slug . '/';
}
function get_term_meta( ...$args ) {
	unset( $args );
	return '';
}
function wp_get_attachment_url( $id ) {
	unset( $id );
	return '';
}
function get_the_post_thumbnail_url( ...$args ) {
	unset( $args );
	return '';
}
function get_the_ID() {
	return $GLOBALS['post_atual']->ID;
}
function get_the_title() {
	return $GLOBALS['post_atual']->post_title;
}
function get_the_permalink() {
	return 'https://uonix.test/produtos/' . $GLOBALS['post_atual']->ID . '/';
}
function get_the_excerpt( $id ) {
	unset( $id );
	return '';
}
function get_post_field( $campo, $id ) {
	unset( $campo, $id );
	return $GLOBALS['post_atual']->post_content;
}
function strip_shortcodes( $texto ) {
	return $texto;
}
function wp_get_post_terms( ...$args ) {
	unset( $args );
	// Sem o WooCommerce, product_brand e pa_marca não existem.
	return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
}
function wp_strip_all_tags( $texto ) {
	return strip_tags( (string) $texto );
}
function wp_reset_postdata() {}
function esc_url( $url ) {
	return $url;
}
function esc_html( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES );
}
function esc_attr( $texto ) {
	return htmlspecialchars( (string) $texto, ENT_QUOTES );
}

require dirname( __DIR__, 2 ) . '/mu-plugins/uonix-navigation/27-mega-menu-produtos-marcas.php';

$falhas = array();
function verificar( $condicao, $mensagem ) {
	if ( ! $condicao ) {
		$GLOBALS['falhas'][] = $mensagem;
	}
}

// 1. Nenhuma categoria existe (WooCommerce desativado): renderiza, sem fatal,
//    com o link do catálogo como reserva.
$html = uonix_gerar_mega_menu_v14();
verificar( false !== strpos( $html, 'href="/produtos/#catalogo-produtos"' ), 'sem categoria, o link de reserva do catálogo não foi usado' );
verificar( false === strpos( $html, 'Object of class' ), 'WP_Error vazou para o HTML' );

// 2. Uma categoria existe e as outras não: a existente mantém o link real.
$GLOBALS['termos']['fixacao-quimica'] = (object) array(
	'term_id'     => 7,
	'slug'        => 'fixacao-quimica',
	'description' => 'Químicos.',
);
$html = uonix_gerar_mega_menu_v14();
verificar( false !== strpos( $html, 'https://uonix.test/categoria/fixacao-quimica/#catalogo-produtos' ), 'categoria existente perdeu o link real' );
verificar( false !== strpos( $html, 'href="/produtos/#catalogo-produtos"' ), 'categorias ausentes não usaram o link de reserva' );

// 3. Filtro de terceiros faz get_term_by() devolver WP_Error: sem aviso (#423).
$GLOBALS['term_by_erro'] = true;
$html                    = uonix_gerar_mega_menu_v14();
$GLOBALS['term_by_erro'] = false;
verificar( false !== strpos( $html, 'href="/produtos/#catalogo-produtos"' ), 'WP_Error de get_term_by não caiu no link de reserva' );

// 4. O laço de produtos roda sem o WooCommerce (sem wc_placeholder_img_src e
//    com wp_get_post_terms devolvendo WP_Error): marca padrão, sem aviso.
$GLOBALS['produtos'] = array(
	(object) array(
		'ID'           => 42,
		'post_title'   => 'Chumbador<br>Químico',
		'post_content' => '<p>Ancoragem química.</p>',
	),
);
$html = uonix_gerar_mega_menu_v14();
verificar( false !== strpos( $html, 'https://uonix.test/produtos/42/#catalogo-produtos' ), 'o laço de produtos não renderizou o link do produto' );
verificar( false !== strpos( $html, 'UÔNIX' ), 'sem marca, o produto deveria cair na marca padrão' );

verificar( empty( $GLOBALS['avisos'] ), "avisos do PHP:\n    " . implode( "\n    ", array_slice( $GLOBALS['avisos'], 0, 5 ) ) );

if ( $falhas ) {
	fwrite( STDERR, "FAIL:\n  - " . implode( "\n  - ", $falhas ) . "\n" );
	exit( 1 );
}
echo "OK: megamenu de produtos renderiza sem categoria, sem WooCommerce e sem avisos\n";
