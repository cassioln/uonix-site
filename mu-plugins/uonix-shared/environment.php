<?php
/**
 * Resolução pura do ambiente Uonix.
 */

if ( ! function_exists( 'uonix_resolve_environment' ) ) {
	/**
	 * Resolve o ambiente, priorizando WP_ENVIRONMENT_TYPE quando explicitamente definido.
	 *
	 * @param string $wp_environment Ambiente reportado pelo WordPress.
	 * @param bool   $is_explicit    Se WP_ENVIRONMENT_TYPE foi definido explicitamente.
	 * @param string $host           Host da URL atual.
	 * @return string
	 */
	function uonix_resolve_environment( $wp_environment, $is_explicit, $host ) {
		$allowed        = array( 'production', 'staging', 'development', 'local' );
		$wp_environment = strtolower( (string) $wp_environment );
		$host           = strtolower( trim( (string) $host ) );

		if ( '::1' !== $host ) {
			$host = preg_replace( '/:\d+$/', '', $host );
		}

		$host = trim( $host, '[]' );

		if ( $is_explicit && in_array( $wp_environment, $allowed, true ) ) {
			return $wp_environment;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return 'local';
		}

		if ( 'test.uonix.ksio.dev' === $host ) {
			return 'development';
		}

		if ( 'uonix.ksio.dev' === $host ) {
			return 'staging';
		}

		if ( in_array( $host, array( 'site.uonix.com.br', 'uonix.com.br', 'www.uonix.com.br' ), true ) ) {
			return 'production';
		}

		return in_array( $wp_environment, $allowed, true ) ? $wp_environment : 'production';
	}
}
