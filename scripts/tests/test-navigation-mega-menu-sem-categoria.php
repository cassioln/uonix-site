<?php
/**
 * O megamenu de produtos (uonix_menu_categorias) não pode derrubar o site
 * quando a categoria não existe: sem o WooCommerce (taxonomia ausente) ou com
 * um slug renomeado no painel, get_term_by() devolve false e get_term_link()
 * um WP_Error, que concatenado como string era erro fatal no site inteiro (#416).
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		unset( $args );
	}
	public function have_posts() {
		return false;
	}
}

$GLOBALS['termos'] = array();

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
	return $GLOBALS['termos'][ $valor ] ?? false;
}
function get_term_link( $termo ) {
	if ( ! is_object( $termo ) ) {
		return new WP_Error( 'invalid_term', 'Empty Term.' );
	}
	return 'https://uonix.test/categoria/' . $termo->slug . '/';
}
function get_term_meta( ...$args ) {
	unset( $args );
	return '';
}
function wp_strip_all_tags( $texto ) {
	return strip_tags( $texto );
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

// 1. Nenhuma categoria existe (WooCommerce desativado): renderiza, sem fatal,
//    com o link do catálogo como reserva.
$html = uonix_gerar_mega_menu_v14();
if ( false === strpos( $html, 'href="/produtos/#catalogo-produtos"' ) ) {
	$falhas[] = 'sem categoria, o link de reserva do catálogo não foi usado';
}
if ( false !== strpos( $html, 'Object of class' ) ) {
	$falhas[] = 'WP_Error vazou para o HTML';
}

// 2. Uma categoria existe e as outras foram renomeadas: a existente mantém o link
//    real, e as demais caem no catálogo.
$GLOBALS['termos']['fixacao-quimica'] = (object) array(
	'term_id'     => 7,
	'slug'        => 'fixacao-quimica',
	'description' => 'Químicos.',
);
$html = uonix_gerar_mega_menu_v14();
if ( false === strpos( $html, 'https://uonix.test/categoria/fixacao-quimica/#catalogo-produtos' ) ) {
	$falhas[] = 'categoria existente perdeu o link real';
}
if ( false === strpos( $html, 'href="/produtos/#catalogo-produtos"' ) ) {
	$falhas[] = 'categorias ausentes não usaram o link de reserva';
}

if ( $falhas ) {
	fwrite( STDERR, "FAIL:\n  - " . implode( "\n  - ", $falhas ) . "\n" );
	exit( 1 );
}
echo "OK: megamenu de produtos renderiza sem categoria e sem WooCommerce\n";
