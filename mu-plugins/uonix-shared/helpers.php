<?php
/**
 * Funções utilitárias reutilizáveis pelos módulos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_shared_only_digits' ) ) {
	function uonix_shared_only_digits( $value ) {
		return preg_replace( '/\D+/', '', (string) $value );
	}
}

if ( ! function_exists( 'uonix_shared_upper_text' ) ) {
	function uonix_shared_upper_text( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}
}

if ( ! function_exists( 'uonix_shared_lower_email' ) ) {
	function uonix_shared_lower_email( $value ) {
		return strtolower( sanitize_email( wp_unslash( (string) $value ) ) );
	}
}

if ( ! function_exists( 'uonix_shared_format_phone' ) ) {
	function uonix_shared_format_phone( $value ) {
		$digits = uonix_shared_only_digits( $value );

		if ( 10 === strlen( $digits ) ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 4 ), substr( $digits, 6 ) );
		}

		if ( 11 === strlen( $digits ) ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 5 ), substr( $digits, 7 ) );
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}
}

if ( ! function_exists( 'uonix_shared_post_value' ) ) {
	function uonix_shared_post_value( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
}
