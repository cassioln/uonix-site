<?php
/**
 * Remove páginas de conversão do sitemap do Rank Math.
 *
 * Essas páginas continuam publicamente acessíveis e com noindex; o filtro
 * apenas evita que URLs não indexáveis sejam publicadas no XML do sitemap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'rank_math/sitemap/entry',
	function ( $url, $type ) {
		if ( ! is_array( $url ) || empty( $url['loc'] ) ) {
			return $url;
		}

		$path = wp_parse_url( $url['loc'], PHP_URL_PATH );
		$path = trailingslashit( (string) $path );

		if ( in_array( $path, array( '/cotacao/', '/finalizar-orcamento/' ), true ) ) {
			return false;
		}

		return $url;
	},
	10,
	2
);
