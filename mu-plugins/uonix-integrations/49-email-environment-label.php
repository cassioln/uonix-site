<?php
/**
 * Identifica e-mails enviados fora de produção.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_email_environment_label' ) ) {
	/**
	 * Retorna o rótulo visível usado em assuntos e corpo de e-mails não produtivos.
	 */
	function uonix_email_environment_label() {
		if ( ! defined( 'UONIX_ENV' ) || 'production' === UONIX_ENV ) {
			return '';
		}

		if ( 'staging' === UONIX_ENV ) {
			return 'QA';
		}

		return 'DEV';
	}
}

add_filter(
	'wp_mail',
	function ( $args ) {
		$label = uonix_email_environment_label();

		if ( '' === $label ) {
			return $args;
		}

		$subject_prefix = '[' . $label . '] ';
		$subject        = isset( $args['subject'] ) ? (string) $args['subject'] : '';

		if ( ! str_starts_with( $subject, $subject_prefix ) ) {
			$args['subject'] = $subject_prefix . $subject;
		}

		$message = isset( $args['message'] ) ? (string) $args['message'] : '';
		$banner  = 'Este e-mail foi enviado pelo ambiente ' . $label . ' da Uonix.';

		if ( preg_match( '/<\/?[a-z][\s\S]*>/i', $message ) ) {
			$html_banner = '<div style="background:#f97316;color:#111827;font-family:Arial,sans-serif;font-size:14px;font-weight:700;line-height:1.4;padding:10px 16px;text-align:center;text-transform:uppercase;">'
				. esc_html( $banner )
				. '</div>';

			if ( false !== stripos( $message, '<body' ) ) {
				$args['message'] = preg_replace( '/(<body[^>]*>)/i', '$1' . $html_banner, $message, 1 );
			} else {
				$args['message'] = $html_banner . $message;
			}
		} else {
			$args['message'] = '[' . $label . '] ' . $banner . "\n\n" . $message;
		}

		return $args;
	}
);
