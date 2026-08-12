<?php
/**
 * Prova o carregamento restrito da correção do editor de resumo do produto.
 */

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );
define( 'UONIX_MU_PATH', $repo_root . '/mu-plugins/' );
define( 'UONIX_MU_URL', 'https://example.test/wp-content/mu-plugins/' );

$GLOBALS['excerpt_actions']  = array();
$GLOBALS['excerpt_enqueued'] = array();
$GLOBALS['excerpt_screen']   = (object) array( 'post_type' => 'post' );
$GLOBALS['excerpt_asserts']  = 0;

function excerpt_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function excerpt_assert_same( $expected, $actual, $message ) {
	$GLOBALS['excerpt_asserts']++;

	if ( $expected !== $actual ) {
		excerpt_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function excerpt_assert_contains( $needle, $haystack, $message ) {
	$GLOBALS['excerpt_asserts']++;

	if ( false === strpos( $haystack, $needle ) ) {
		excerpt_fail( $message . '; trecho ausente=' . var_export( $needle, true ) );
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['excerpt_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function get_current_screen() {
	return $GLOBALS['excerpt_screen'];
}

function wp_enqueue_script( $handle, $src, $dependencies, $version, $in_footer ) {
	$GLOBALS['excerpt_enqueued'][] = array(
		'handle'       => $handle,
		'src'          => $src,
		'dependencies' => $dependencies,
		'version'      => $version,
		'in_footer'    => $in_footer,
	);
}

$module_file = $repo_root . '/mu-plugins/uonix-woocommerce/24-admin-resumo-editor-estavel.php';
if ( ! is_readable( $module_file ) ) {
	excerpt_fail( 'módulo de estabilidade do editor ausente' );
}

require $module_file;

$registrations = $GLOBALS['excerpt_actions']['admin_enqueue_scripts'] ?? array();
excerpt_assert_same( 1, count( $registrations ), 'admin_enqueue_scripts registrado uma vez' );
excerpt_assert_same( 10, $registrations[0]['priority'], 'prioridade do enqueue' );
excerpt_assert_same( 1, $registrations[0]['accepted_args'], 'accepted_args do enqueue' );

$enqueue = $registrations[0]['callback'];

$enqueue( 'post.php' );
excerpt_assert_same( array(), $GLOBALS['excerpt_enqueued'], 'post comum não recebe o asset' );

$GLOBALS['excerpt_screen'] = (object) array( 'post_type' => 'product' );
$enqueue( 'upload.php' );
excerpt_assert_same( array(), $GLOBALS['excerpt_enqueued'], 'outra tela de produto não recebe o asset' );

$enqueue( 'post.php' );
excerpt_assert_same( 1, count( $GLOBALS['excerpt_enqueued'] ), 'edição de produto recebe o asset' );

$script = $GLOBALS['excerpt_enqueued'][0];
excerpt_assert_same( 'uonix-product-excerpt-editor', $script['handle'], 'handle do asset' );
excerpt_assert_same(
	array( 'jquery', 'jquery-ui-sortable', 'postbox', 'editor' ),
	$script['dependencies'],
	'dependências do lifecycle das metaboxes e editor'
);
excerpt_assert_same( true, $script['in_footer'], 'asset carregado no rodapé' );
excerpt_assert_contains(
	'uonix-woocommerce/assets/js/admin-product-excerpt-editor.js',
	$script['src'],
	'URL do asset gerenciado'
);
excerpt_assert_same(
	(string) filemtime( UONIX_MU_PATH . 'uonix-woocommerce/assets/js/admin-product-excerpt-editor.js' ),
	$script['version'],
	'versão vinculada ao arquivo'
);

$loader = file_get_contents( $repo_root . '/mu-plugins/uonix-woocommerce/module.php' );
excerpt_assert_contains( "'24-admin-resumo-editor-estavel.php'", $loader, 'loader inclui o módulo' );

printf(
	"PASS: editor de resumo carrega somente na edição de produto (%d asserções).\n",
	$GLOBALS['excerpt_asserts']
);
