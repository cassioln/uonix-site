<?php
/**
 * Testes do resolvedor puro de ambientes Uonix.
 */

$environment_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-shared/environment.php';

if ( ! is_file( $environment_file ) ) {
	fwrite( STDERR, "FAIL: environment.php ainda não existe.\n" );
	exit( 1 );
}

require_once $environment_file;

if ( ! function_exists( 'uonix_resolve_environment' ) ) {
	fwrite( STDERR, "FAIL: uonix_resolve_environment() ainda não existe.\n" );
	exit( 1 );
}

$cases = array(
	array( 'production', true, 'uonix.com.br', 'production' ),
	array( 'production', true, 'site.uonix.com.br', 'production' ),
	array( 'production', true, 'www.uonix.com.br', 'production' ),
	array( 'production', true, 'uonix.ksio.dev', 'production' ),
	array( 'staging', true, 'site.uonix.com.br', 'staging' ),
	array( 'development', true, 'uonix.ksio.dev', 'development' ),
	array( 'local', true, 'uonix.ksio.dev', 'local' ),
	array( 'production', false, 'uonix.com.br', 'production' ),
	array( 'production', false, 'www.uonix.com.br', 'production' ),
	array( 'production', false, 'site.uonix.com.br', 'production' ),
	array( 'production', false, 'uonix.ksio.dev', 'staging' ),
	array( 'production', false, 'test.uonix.ksio.dev', 'development' ),
	array( 'production', false, 'localhost', 'local' ),
);

$failures = 0;

foreach ( $cases as $index => $case ) {
	list( $wp_environment, $is_explicit, $host, $expected ) = $case;
	$actual = uonix_resolve_environment( $wp_environment, $is_explicit, $host );

	if ( $expected !== $actual ) {
		++$failures;
		fwrite(
			STDERR,
			sprintf(
				"FAIL case %d: env=%s explicit=%s host=%s expected=%s actual=%s\n",
				$index + 1,
				$wp_environment,
				$is_explicit ? 'true' : 'false',
				$host,
				$expected,
				$actual
			)
		);
	}
}

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: %d casos do mapa de ambientes.\n", count( $cases ) );
