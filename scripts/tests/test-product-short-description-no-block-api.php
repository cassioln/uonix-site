<?php
/**
 * Prova o comportamento fail-closed quando a API oficial de seleção do editor não existe.
 */

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );

$GLOBALS['no_block_api_actions']  = array();
$GLOBALS['no_block_api_removed']  = array();
$GLOBALS['no_block_api_callback'] = 0;
$GLOBALS['no_block_api_asserts']  = 0;

function no_block_api_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function no_block_api_assert_same( $expected, $actual, $message ) {
	$GLOBALS['no_block_api_asserts']++;

	if ( $expected !== $actual ) {
		no_block_api_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['no_block_api_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function remove_meta_box( $id, $screen, $context ) {
	$GLOBALS['no_block_api_removed'][] = compact( 'id', 'screen', 'context' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function no_block_api_excerpt_callback( $post, $box ) {
	unset( $post, $box );
	$GLOBALS['no_block_api_callback']++;
}

$module_file = $repo_root . '/mu-plugins/uonix-woocommerce/25-admin-resumo-fixo.php';
if ( ! is_readable( $module_file ) ) {
	no_block_api_fail( 'módulo do editor fixo ausente' );
}

require $module_file;

$post = (object) array(
	'post_type'    => 'product',
	'post_excerpt' => 'Resumo preservado',
);
$box = array(
	'id'       => 'postexcerpt',
	'title'    => 'Breve descrição sobre o produto',
	'callback' => 'no_block_api_excerpt_callback',
);
$wp_meta_boxes = array(
	'product' => array(
		'normal' => array(
			'default' => array( 'postexcerpt' => $box ),
		),
	),
);

no_block_api_assert_same( false, function_exists( 'use_block_editor_for_post' ), 'fixture não declara a API oficial de seleção do editor' );

uonix_admin_resumo_fixo_capturar();

no_block_api_assert_same( $box, $wp_meta_boxes['product']['normal']['default']['postexcerpt'], 'ausência da API preserva a metabox original' );
no_block_api_assert_same( array(), $GLOBALS['no_block_api_removed'], 'ausência da API não remove a metabox original' );
no_block_api_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'ausência da API não captura callback sem provar o editor clássico' );

ob_start();
uonix_admin_resumo_fixo_renderizar( $post );
$html = ob_get_clean();

no_block_api_assert_same( '', $html, 'ausência da API não cria renderização paralela' );
no_block_api_assert_same( 0, $GLOBALS['no_block_api_callback'], 'ausência da API não executa o callback capturado' );

printf(
	"PASS: ausência da API do editor preserva a metabox nativa (%d asserções).\n",
	$GLOBALS['no_block_api_asserts']
);
