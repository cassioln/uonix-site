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
		if ( false === stripos( $header, '; secure' ) || false === stripos( $header, '; httponly' ) ) {
			return null;
		}

		return $header . '; SameSite=Lax';
	}
}

header_register_callback(
	function () {
		if ( ! defined( 'RFQTK_WP_SESSION_COOKIE' ) || ! apply_filters( 'uonix_rfq_samesite_enabled', true ) ) {
			return;
		}

		$replacement = null;
		foreach ( headers_list() as $header ) {
			$candidate = uonix_rfq_cookie_with_samesite( $header, RFQTK_WP_SESSION_COOKIE );
			if ( null !== $candidate ) {
				$replacement = $candidate;
			}
		}
		if ( null !== $replacement ) {
			header( $replacement, false );
		}
	}
);
