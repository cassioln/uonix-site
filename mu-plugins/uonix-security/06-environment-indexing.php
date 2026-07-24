<?php
/**
 * Política de indexação por ambiente, bloqueada por padrão.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_environment_allows_indexing' ) ) {
	/**
	 * Permite indexação somente em produção com liberação booleana explícita.
	 *
	 * @param string|null $environment    Ambiente explícito ou UONIX_ENV.
	 * @param bool|null   $allow_indexing Flag explícita ou UONIX_ALLOW_INDEXING.
	 * @return bool
	 */
	function uonix_environment_allows_indexing( $environment = null, $allow_indexing = null ) {
		$environment = null === $environment && defined( 'UONIX_ENV' ) ? UONIX_ENV : $environment;

		if ( null === $allow_indexing ) {
			$allow_indexing = defined( 'UONIX_ALLOW_INDEXING' ) ? UONIX_ALLOW_INDEXING : false;
		}

		return true === $allow_indexing && 'production' === $environment;
	}
}

if ( ! function_exists( 'uonix_filter_environment_blog_public' ) ) {
	/**
	 * Força blog_public=0 quando a liberação explícita não existe.
	 *
	 * @param mixed       $pre_option     Valor short-circuit da opção.
	 * @param string|null $environment    Ambiente opcional para testes.
	 * @param bool|null   $allow_indexing Flag opcional para testes.
	 * @return mixed
	 */
	function uonix_filter_environment_blog_public( $pre_option, $environment = null, $allow_indexing = null ) {
		return uonix_environment_allows_indexing( $environment, $allow_indexing ) ? $pre_option : 0;
	}
}

if ( ! function_exists( 'uonix_filter_environment_robots' ) ) {
	/**
	 * Acrescenta diretivas restritivas sem remover diretivas já registradas.
	 *
	 * @param array       $robots         Diretivas atuais.
	 * @param string|null $environment    Ambiente opcional para testes.
	 * @param bool|null   $allow_indexing Flag opcional para testes.
	 * @return array
	 */
	function uonix_filter_environment_robots( $robots, $environment = null, $allow_indexing = null ) {
		if ( uonix_environment_allows_indexing( $environment, $allow_indexing ) ) {
			return $robots;
		}

		$robots['noindex']   = true;
		$robots['nofollow']  = true;
		$robots['noarchive'] = true;

		return $robots;
	}
}

if ( ! function_exists( 'uonix_environment_robots_header_value' ) ) {
	/**
	 * Retorna o valor seguro do X-Robots-Tag ou vazio quando liberado.
	 *
	 * @param string|null $environment    Ambiente opcional para testes.
	 * @param bool|null   $allow_indexing Flag opcional para testes.
	 * @return string
	 */
	function uonix_environment_robots_header_value( $environment = null, $allow_indexing = null ) {
		return uonix_environment_allows_indexing( $environment, $allow_indexing )
			? ''
			: 'noindex, nofollow, noarchive';
	}
}

if ( ! function_exists( 'uonix_send_environment_robots_header' ) ) {
	/**
	 * Envia o header somente no front-end e sem sobrescrever após os headers saírem.
	 */
	function uonix_send_environment_robots_header() {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || headers_sent() ) {
			return;
		}

		$value = uonix_environment_robots_header_value();

		if ( '' !== $value ) {
			header( 'X-Robots-Tag: ' . $value, true );
		}
	}
}

add_filter( 'pre_option_blog_public', 'uonix_filter_environment_blog_public' );
add_filter( 'wp_robots', 'uonix_filter_environment_robots' );
add_action( 'send_headers', 'uonix_send_environment_robots_header' );
