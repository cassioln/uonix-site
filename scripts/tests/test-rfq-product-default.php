<?php
/**
 * Prova o default de RFQ sem depender de uma instalação WordPress no CI.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['rfq_assertions'] = 0;
$GLOBALS['rfq_filters']    = array();
$GLOBALS['rfq_post_types'] = array(
	101 => 'product',
	102 => 'product_variation',
	103 => 'post',
);
$GLOBALS['rfq_meta']       = array();

function rfq_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function rfq_assert_same( $expected, $actual, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( $expected !== $actual ) {
		rfq_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function rfq_assert_contains( $needle, $haystack, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( false === strpos( $haystack, $needle ) ) {
		rfq_fail( $message . '; trecho ausente=' . var_export( $needle, true ) );
	}
}

function rfq_assert_not_contains( $needle, $haystack, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( false !== strpos( $haystack, $needle ) ) {
		rfq_fail( $message . '; trecho inesperado=' . var_export( $needle, true ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['rfq_filters'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['rfq_filters'][ $hook ] ?? array() as $registration ) {
		$callback_args = array_slice(
			array_merge( array( $value ), $args ),
			0,
			$registration['accepted_args']
		);
		$value = call_user_func_array( $registration['callback'], $callback_args );
	}

	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_post_type( $post_id ) {
	return $GLOBALS['rfq_post_types'][ absint( $post_id ) ] ?? false;
}

function get_post_meta( $post_id, $meta_key, $single = false ) {
	$post_id = absint( $post_id );

	if ( isset( $GLOBALS['rfq_meta'][ $post_id ] ) && array_key_exists( $meta_key, $GLOBALS['rfq_meta'][ $post_id ] ) ) {
		$value = $GLOBALS['rfq_meta'][ $post_id ][ $meta_key ];
		return $single ? $value : array( $value );
	}

	$default = $single ? '' : array();

	return apply_filters( 'default_post_metadata', $default, $post_id, $meta_key, $single, 'post' );
}

function checked( $checked, $current = true, $display = true ) {
	$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

$repo_root   = dirname( __DIR__, 2 );
$module_file = getenv( 'UONIX_TEST_RFQ_MODULE_FILE' );
$module_file = $module_file ?: $repo_root . '/mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php';

if ( ! is_readable( $module_file ) ) {
	rfq_fail( 'módulo RFQ padrão ausente' );
}

require $module_file;

$registrations = $GLOBALS['rfq_filters']['default_post_metadata'] ?? array();
rfq_assert_same( 1, count( $registrations ), 'hook default_post_metadata registrado exatamente uma vez' );
rfq_assert_same( 10, $registrations[0]['priority'], 'prioridade do hook' );
rfq_assert_same( 5, $registrations[0]['accepted_args'], 'quantidade de argumentos do hook' );

$key = '_gpls_woo_rfq_rfq_enable';

rfq_assert_same( 'yes', get_post_meta( 101, $key, true ), 'produto sem meta recebe yes' );
rfq_assert_same( 'yes', get_post_meta( 102, $key, true ), 'variação sem meta recebe yes' );
rfq_assert_same( array( 'yes' ), get_post_meta( 101, $key, false ), 'leitura múltipla mantém lista' );
rfq_assert_same( '', get_post_meta( 103, $key, true ), 'outro post type mantém default original' );
rfq_assert_same( '', get_post_meta( 101, '_outra_chave', true ), 'outra chave mantém default original' );
rfq_assert_same( '', get_post_meta( 0, $key, true ), 'ID inválido mantém default original' );

$GLOBALS['rfq_meta'][101][ $key ] = 'no';
$GLOBALS['rfq_meta'][102][ $key ] = 'yes';

rfq_assert_same( 'no', get_post_meta( 101, $key, true ), 'no físico permanece no' );
rfq_assert_same( 'yes', get_post_meta( 102, $key, true ), 'yes físico permanece yes' );

$product_html = sprintf(
	'<input type="checkbox" name="%1$s" id="%1$s" value="yes" %2$s>',
	$key,
	checked( get_post_meta( 102, $key, true ), 'yes', false )
);
$disabled_html = sprintf(
	'<input type="checkbox" name="%1$s" id="%1$s" value="yes" %2$s>',
	$key,
	checked( get_post_meta( 101, $key, true ), 'yes', false )
);

rfq_assert_contains( 'checked=', $product_html, 'checkbox fica marcado para yes' );
rfq_assert_not_contains( 'checked=', $disabled_html, 'checkbox respeita no explícito' );

$loader = file_get_contents( $repo_root . '/mu-plugins/uonix-woocommerce/module.php' );
rfq_assert_contains( "'23-rfq-produto-padrao.php'", $loader, 'loader inclui o módulo RFQ' );

printf(
	"PASS: RFQ usa yes somente como default ausente e respeita valor explícito (%d asserções).\n",
	$GLOBALS['rfq_assertions']
);
