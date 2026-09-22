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

		// O ambiente remoto de desenvolvimento está sendo retirado da topologia, mas
		// este mapeamento NÃO pode sair antes de o host deixar de ser alvo de clone.
		// Enquanto clone-environment.yml ainda aceita esse destino, o clone copia
		// mu-plugins para lá — então o código novo chega ao host sem passar por deploy.
		//
		// Sem este bloco, um host que não defina WP_ENVIRONMENT_TYPE cai no fallback de
		// produção no fim da função, e três travas caem juntas: indexação, analytics/AdOpt
		// e o redirecionamento de e-mail — 49-email-environment-label.php só protege
		// staging|development, então um orçamento de teste sairia para o cliente real,
		// sem prefixo de ambiente e com Cc/Bcc preservados.
		//
		// Remover somente junto com a limpeza do subsistema de clone.
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
