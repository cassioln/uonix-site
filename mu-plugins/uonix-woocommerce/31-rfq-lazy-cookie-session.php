<?php
/**
 * Inicializa a sessão cookie do RFQ somente quando já houver estado RFQ.
 *
 * O plugin terceiro agenda instância e commit incondicionais em plugins_loaded
 * e shutdown. Em visitas públicas novas isso emite rfqtk_wp_session_* e impede
 * cache de página. Esta política remove os dois hooks depois que o terceiro os
 * registra; uma sessão existente continua sendo retomada para não perder uma
 * cotação já iniciada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_rfq_lazy_session_cookie_name' ) ) {
	function uonix_rfq_lazy_session_cookie_name() {
		return defined( 'RFQTK_WP_SESSION_COOKIE' ) ? RFQTK_WP_SESSION_COOKIE : '';
	}
}

if ( ! function_exists( 'uonix_rfq_lazy_session_uses_cookie_backend' ) ) {
	function uonix_rfq_lazy_session_uses_cookie_backend() {
		// A política anterior preserva php_session para visitante legado. Não
		// remova os hooks desse backend: ele precisa concluir a cotação aberta.
		return 'rfq_cookie' === get_option( 'settings_gpls_woo_rfq_cookie_or_phpsession', 'rfq_cookie' );
	}
}

if ( ! function_exists( 'uonix_rfq_lazy_session_has_existing_cookie' ) ) {
	function uonix_rfq_lazy_session_has_existing_cookie() {
		$cookie_name = uonix_rfq_lazy_session_cookie_name();

		return '' !== $cookie_name
			&& isset( $_COOKIE[ $cookie_name ] )
			&& is_string( $_COOKIE[ $cookie_name ] )
			&& '' !== $_COOKIE[ $cookie_name ];
	}
}

if ( ! function_exists( 'uonix_rfq_lazy_session_start_existing' ) ) {
	function uonix_rfq_lazy_session_start_existing() {
		if ( ! uonix_rfq_lazy_session_has_existing_cookie() || ! function_exists( 'RFQTK_wp_session_start' ) ) {
			return false;
		}

		return RFQTK_wp_session_start();
	}
}

if ( ! function_exists( 'uonix_rfq_lazy_session_disable_eager_hooks' ) ) {
	function uonix_rfq_lazy_session_disable_eager_hooks() {
		if ( ! uonix_rfq_lazy_session_uses_cookie_backend() ) {
			return;
		}

		remove_action( 'plugins_loaded', 'RFQTK_wp_session_start', 10 );
		remove_action( 'shutdown', 'RFQTK_wp_session_write_close', 10 );
		uonix_rfq_lazy_session_start_existing();
	}
}

// MU-plugins carregam antes dos plugins convencionais. No disparo de
// plugins_loaded, os callbacks do terceiro já estão registrados, portanto a
// prioridade 0 os remove antes do autostart original na prioridade 10.
add_action( 'plugins_loaded', 'uonix_rfq_lazy_session_disable_eager_hooks', 0 );
