<?php
/**
 * Testes da política fail-closed de indexação.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_filters'] = array();
$GLOBALS['uonix_test_actions'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

$policy_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-security/06-environment-indexing.php';

if ( ! is_file( $policy_file ) ) {
	fwrite( STDERR, "FAIL: 06-environment-indexing.php ainda não existe.\n" );
	exit( 1 );
}

require_once $policy_file;

$failures = 0;

function uonix_indexing_assert_same( $expected, $actual, $message ) {
	global $failures;

	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

$required_functions = array(
	'uonix_environment_allows_indexing',
	'uonix_filter_environment_blog_public',
	'uonix_filter_environment_robots',
	'uonix_environment_robots_header_value',
);

foreach ( $required_functions as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, sprintf( "FAIL: %s() ainda não existe.\n", $required_function ) );
		exit( 1 );
	}
}

uonix_indexing_assert_same( false, uonix_environment_allows_indexing( 'production', null ), 'constante ausente bloqueia produção' );
uonix_indexing_assert_same( false, uonix_environment_allows_indexing( 'production', false ), 'flag false bloqueia produção' );
uonix_indexing_assert_same( true, uonix_environment_allows_indexing( 'production', true ), 'flag true permite somente produção' );
uonix_indexing_assert_same( false, uonix_environment_allows_indexing( 'staging', true ), 'staging permanece noindex' );
uonix_indexing_assert_same( false, uonix_environment_allows_indexing( 'development', true ), 'development permanece noindex' );
uonix_indexing_assert_same( false, uonix_environment_allows_indexing( 'local', true ), 'local permanece noindex' );

uonix_indexing_assert_same( 0, uonix_filter_environment_blog_public( false, 'staging', true ), 'blog_public é forçado para zero fora de produção' );
uonix_indexing_assert_same( false, uonix_filter_environment_blog_public( false, 'production', true ), 'produção liberada deixa a opção do banco decidir' );

$robots = uonix_filter_environment_robots( array( 'max-image-preview' => 'large' ), 'development', false );
uonix_indexing_assert_same( true, $robots['noindex'] ?? null, 'robots contém noindex' );
uonix_indexing_assert_same( true, $robots['nofollow'] ?? null, 'robots contém nofollow' );
uonix_indexing_assert_same( true, $robots['noarchive'] ?? null, 'robots contém noarchive' );
uonix_indexing_assert_same( 'large', $robots['max-image-preview'] ?? null, 'diretivas existentes são preservadas' );

uonix_indexing_assert_same( 'noindex, nofollow, noarchive', uonix_environment_robots_header_value( 'staging', true ), 'header bloqueia staging mesmo com flag true' );
uonix_indexing_assert_same( '', uonix_environment_robots_header_value( 'production', true ), 'header é omitido apenas em produção liberada' );

$registered_filters = array_column( $GLOBALS['uonix_test_filters'], 0 );
$registered_actions = array_column( $GLOBALS['uonix_test_actions'], 0 );
uonix_indexing_assert_same( true, in_array( 'pre_option_blog_public', $registered_filters, true ), 'registra pre_option_blog_public' );
uonix_indexing_assert_same( true, in_array( 'wp_robots', $registered_filters, true ), 'registra wp_robots' );
uonix_indexing_assert_same( true, in_array( 'send_headers', $registered_actions, true ), 'registra X-Robots-Tag no front-end' );

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: política fail-closed de indexação.\n" );
