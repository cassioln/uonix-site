<?php
/**
 * Garante que o referer do newsletter seja sempre caminho relativo interno.
 * O Fluent Forms aplica site_url() a _wp_http_referer ao gravar source_url.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['newsletter_test_referer'] = false;

function add_shortcode( $tag, $callback ) {
	unset( $tag, $callback );
}

function add_action( $hook, $callback ) {
	unset( $hook, $callback );
}

function wp_get_referer() {
	return $GLOBALS['newsletter_test_referer'];
}

function home_url( $path = '' ) {
	return 'https://uonix.com.br' . $path;
}

function site_url( $path = '' ) {
	return 'https://uonix.com.br/' . ltrim( (string) $path, '/' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function newsletter_source_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function newsletter_source_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		newsletter_source_fail(
			$message . '; esperado ' . var_export( $expected, true ) .
			', recebido ' . var_export( $actual, true )
		);
	}
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-forms/32-form-newsletter.php';

$cases = array(
	'https://uonix.com.br/' => '/',
	'https://uonix.com.br/produtos/?origem=rodape' => '/produtos/?origem=rodape',
	'/contato/?campanha=teste' => '/contato/?campanha=teste',
	'https://externo.example/landing' => '/',
	'//externo.example/landing' => '/',
	false => '/',
);

foreach ( $cases as $referer => $expected ) {
	$GLOBALS['newsletter_test_referer'] = $referer;
	$path = uonix_newsletter_referer_path();
	newsletter_source_assert_same(
		$expected,
		$path,
		'referer normalizado sem URL duplicada: ' . var_export( $referer, true )
	);
}

$GLOBALS['newsletter_test_referer'] = 'https://uonix.com.br/';
newsletter_source_assert_same(
	'https://uonix.com.br/',
	site_url( uonix_newsletter_referer_path() ),
	'Fluent Forms recebe caminho relativo e não duplica a URL de origem'
);

printf( "PASS: URL de origem do newsletter é caminho interno relativo.\n" );
