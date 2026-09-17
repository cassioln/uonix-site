<?php
/**
 * Mantém a sessão oficial do RFQ em cookie.
 *
 * O plugin usa uma opção global para selecionar o backend de estado. Aplicar
 * php_session em AJAX e rfq_cookie na página produziria dois backends para o
 * mesmo orçamento. A política usa, portanto, rfq_cookie de forma uniforme e
 * elimina PHPSESSID global das páginas públicas cacheáveis.
 *
 * Compatibilidade de transição: um visitante que já tenha PHPSESSID continua
 * no backend legado até aquele cookie expirar. Isso preserva cotações abertas;
 * o cache de página deve sempre ignorar PHPSESSID durante essa janela.
 *
 * Rollback operacional: defina a opção `uonix_rfq_cookie_session_enabled` como
 * `0` para voltar imediatamente ao valor persistido do plugin. Uma constante
 * UONIX_RFQ_COOKIE_SESSION_ENABLED=false no wp-config.php também prevalece.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_rfq_public_session_mode' ) ) {
	function uonix_rfq_public_session_mode( $pre_option ) {
		if ( defined( 'UONIX_RFQ_COOKIE_SESSION_ENABLED' ) && ! UONIX_RFQ_COOKIE_SESSION_ENABLED ) {
			return $pre_option;
		}

		if ( isset( $_COOKIE['PHPSESSID'] ) && is_string( $_COOKIE['PHPSESSID'] ) && '' !== $_COOKIE['PHPSESSID'] ) {
			return $pre_option;
		}

		// A flag fica fora do filtro da opção RFQ; portanto não há recursão.
		// false preserva a ativação default para instalações existentes.
		$enabled = get_option( 'uonix_rfq_cookie_session_enabled', true );
		if ( false === $enabled || 0 === $enabled || '0' === $enabled ) {
			return $pre_option;
		}

		return 'rfq_cookie';
	}
}

add_filter( 'pre_option_settings_gpls_woo_rfq_cookie_or_phpsession', 'uonix_rfq_public_session_mode' );
