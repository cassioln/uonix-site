<?php
/**
 * Acrescenta SameSite=Lax somente ao cookie de sessão RFQ do fornecedor.
 * Secure e HttpOnly já são emitidos pelo plugin e permanecem preservados.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'send_headers',
	function () {
		if ( ! defined( 'RFQTK_WP_SESSION_COOKIE' ) ) {
			return;
		}

		$headers = headers_list();
		foreach ( $headers as $header ) {
			if ( 0 !== stripos( $header, 'Set-Cookie: ' . RFQTK_WP_SESSION_COOKIE . '=' ) || false !== stripos( $header, 'samesite=' ) ) {
				continue;
			}

			header_remove( 'Set-Cookie' );
			foreach ( $headers as $candidate ) {
				if ( 0 !== stripos( $candidate, 'Set-Cookie:' ) ) {
					continue;
				}
				if ( $candidate === $header ) {
					$candidate .= '; SameSite=Lax';
				}
				header( $candidate, false );
			}
			break;
		}
	},
	PHP_INT_MAX
);
