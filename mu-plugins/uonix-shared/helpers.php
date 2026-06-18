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

if ( ! function_exists( 'uonix_shared_normalize_text' ) ) {
	function uonix_shared_normalize_text( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}
}

if ( ! function_exists( 'uonix_shared_format_postcode' ) ) {
	function uonix_shared_format_postcode( $value ) {
		$value  = uonix_shared_normalize_text( $value );
		$digits = uonix_shared_only_digits( $value );

		if ( 8 === strlen( $digits ) ) {
			return substr( $digits, 0, 5 ) . '-' . substr( $digits, 5 );
		}

		return $value;
	}
}

if ( ! function_exists( 'uonix_shared_address_has_number' ) ) {
	function uonix_shared_address_has_number( $address, $number ) {
		$address = uonix_shared_normalize_text( $address );
		$number  = uonix_shared_normalize_text( $number );

		if ( '' === $address || '' === $number ) {
			return false;
		}

		return (bool) preg_match( '/(^|[\s,.-])' . preg_quote( $number, '/' ) . '($|[\s,.-])/i', $address );
	}
}

if ( ! function_exists( 'uonix_shared_join_address_number' ) ) {
	function uonix_shared_join_address_number( $address, $number ) {
		$address = uonix_shared_normalize_text( $address );
		$number  = uonix_shared_normalize_text( $number );

		if ( '' === $address || '' === $number ) {
			return $address;
		}

		if ( uonix_shared_address_has_number( $address, $number ) ) {
			$address = preg_replace( '/,\s*(' . preg_quote( $number, '/' ) . ')(?=$|[\s,.-])/i', ' $1', $address );
			return uonix_shared_normalize_text( $address );
		}

		return $address . ' ' . $number;
	}
}

if ( ! function_exists( 'uonix_shared_build_address_lines' ) ) {
	function uonix_shared_build_address_lines( array $address ) {
		$street     = uonix_shared_normalize_text( $address['street'] ?? '' );
		$number     = uonix_shared_normalize_text( $address['number'] ?? '' );
		$complement = uonix_shared_normalize_text( $address['complement'] ?? '' );
		$city       = uonix_shared_normalize_text( $address['city'] ?? '' );
		$state      = uonix_shared_normalize_text( $address['state'] ?? '' );
		$postcode   = uonix_shared_format_postcode( $address['postcode'] ?? '' );

		if ( '' !== $complement && '' !== $city && 0 === strcasecmp( $complement, $city ) ) {
			$complement = '';
		}

		$lines = array();

		if ( '' !== $street ) {
			$lines[] = uonix_shared_join_address_number( $street, $number );
		} elseif ( '' !== $number ) {
			$lines[] = 'Número: ' . $number;
		}

		if ( '' !== $complement ) {
			$lines[] = $complement;
		}

		if ( '' !== $city && '' !== $state ) {
			$lines[] = $city . ' / ' . $state;
		} elseif ( '' !== $city ) {
			$lines[] = 'Cidade: ' . $city;
		} elseif ( '' !== $state ) {
			$lines[] = 'Estado: ' . $state;
		}

		if ( '' !== $postcode ) {
			$lines[] = 'CEP: ' . $postcode;
		}

		return ! empty( $lines ) ? $lines : array( 'Não informado' );
	}
}

if ( ! function_exists( 'uonix_shared_get_order_billing_address_lines' ) ) {
	function uonix_shared_get_order_billing_address_lines( $order ) {
		if ( ! is_object( $order ) ) {
			return array( 'Não informado' );
		}

		return uonix_shared_build_address_lines(
			array(
				'street'     => method_exists( $order, 'get_billing_address_1' ) ? $order->get_billing_address_1() : '',
				'number'     => method_exists( $order, 'get_meta' ) ? $order->get_meta( 'billing_address_3' ) : '',
				'complement' => method_exists( $order, 'get_billing_address_2' ) ? $order->get_billing_address_2() : '',
				'city'       => method_exists( $order, 'get_billing_city' ) ? $order->get_billing_city() : '',
				'state'      => method_exists( $order, 'get_billing_state' ) ? $order->get_billing_state() : '',
				'postcode'   => method_exists( $order, 'get_billing_postcode' ) ? $order->get_billing_postcode() : '',
			)
		);
	}
}
