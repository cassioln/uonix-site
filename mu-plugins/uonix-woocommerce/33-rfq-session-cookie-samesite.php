<?php
/**
 * Acrescenta SameSite=Lax somente ao cookie de sessão RFQ do fornecedor.
 *
 * O cookie original permanece intacto. Uma segunda linha Set-Cookie, equivalente
 * e mais específica, é emitida por último; nenhum header de terceiros é removido
 * ou reconstruído.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_rfq_cookie_with_samesite' ) ) {
	function uonix_rfq_cookie_with_samesite( $header, $cookie_name ) {
		if ( ! is_string( $header ) || preg_match( '/[\r\n]/', $header ) ) {
			return null;
		}

		$pattern = '/^Set-Cookie:\s*' . preg_quote( $cookie_name, '/' ) . '=[^;]*(?:;.*)?$/i';
		if ( ! preg_match( $pattern, $header ) ) {
			return null;
		}
		if ( false !== stripos( $header, 'samesite=' ) ) {
			return null;
		}
		$attributes = array_map( 'trim', explode( ';', substr( $header, strpos( $header, ';' ) + 1 ) ) );
		$attributes = array_map( 'strtolower', $attributes );
		if ( ! in_array( 'secure', $attributes, true ) || ! in_array( 'httponly', $attributes, true ) ) {
			return null;
		}

		return $header . '; SameSite=Lax';
	}
}

if ( ! function_exists( 'uonix_rfq_latest_cookie_with_samesite' ) ) {
	function uonix_rfq_latest_cookie_with_samesite( $headers, $cookie_name ) {
		$replacement = null;
		foreach ( (array) $headers as $header ) {
			$candidate = uonix_rfq_cookie_with_samesite( $header, $cookie_name );
			if ( null !== $candidate ) {
				$replacement = $candidate;
			}
		}
		return $replacement;
	}
}

header_register_callback(
	function () {
		if ( ! defined( 'RFQTK_WP_SESSION_COOKIE' ) || ! apply_filters( 'uonix_rfq_samesite_enabled', true ) ) {
			return;
		}

		$replacement = uonix_rfq_latest_cookie_with_samesite( headers_list(), RFQTK_WP_SESSION_COOKIE );
		if ( null !== $replacement ) {
			header( $replacement, false );
		}
	}
);
