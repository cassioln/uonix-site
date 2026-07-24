<?php
/**
 * Identifica e protege e-mails enviados fora de produção.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_email_environment_label' ) ) {
	/**
	 * Retorna o rótulo visível usado em assuntos e corpo de e-mails não produtivos.
	 *
	 * @param string|null $environment Ambiente explícito ou UONIX_ENV.
	 * @return string
	 */
	function uonix_email_environment_label( $environment = null ) {
		$environment = null === $environment && defined( 'UONIX_ENV' ) ? UONIX_ENV : $environment;
		$labels      = array(
			'staging'     => 'QA',
			'development' => 'DEV',
			'local'       => 'LOCAL',
		);

		return $labels[ $environment ] ?? '';
	}
}

if ( ! function_exists( 'uonix_nonprod_email_recipient' ) ) {
	/**
	 * Obtém a caixa segura sem usar fallback para destinatários reais.
	 *
	 * @param string|null $safe_recipient Valor explícito ou constante do ambiente.
	 * @return string
	 */
	function uonix_nonprod_email_recipient( $safe_recipient = null ) {
		if ( null === $safe_recipient && defined( 'UONIX_NONPROD_EMAIL_TO' ) ) {
			$safe_recipient = UONIX_NONPROD_EMAIL_TO;
		}

		$safe_recipient = trim( (string) $safe_recipient );
		$is_valid       = function_exists( 'is_email' )
			? is_email( $safe_recipient )
			: false !== filter_var( $safe_recipient, FILTER_VALIDATE_EMAIL );

		return $is_valid ? $safe_recipient : '';
	}
}

if ( ! function_exists( 'uonix_should_block_email_environment' ) ) {
	/**
	 * Indica quando um ambiente remoto deve falhar fechado por falta da caixa segura.
	 *
	 * @param string|null $environment    Ambiente explícito ou UONIX_ENV.
	 * @param string|null $safe_recipient Caixa segura explícita ou constante.
	 * @return bool
	 */
	function uonix_should_block_email_environment( $environment = null, $safe_recipient = null ) {
		$environment = null === $environment && defined( 'UONIX_ENV' ) ? UONIX_ENV : $environment;

		if ( ! in_array( $environment, array( 'staging', 'development' ), true ) ) {
			return false;
		}

		return '' === uonix_nonprod_email_recipient( $safe_recipient );
	}
}

if ( ! function_exists( 'uonix_email_remove_copy_headers' ) ) {
	/**
	 * Remove Cc/Bcc em QA e DEV e preserva os demais cabeçalhos, incluindo Reply-To.
	 *
	 * @param array|string $headers Cabeçalhos aceitos por wp_mail().
	 * @return array|string
	 */
	function uonix_email_remove_copy_headers( $headers ) {
		$is_array = is_array( $headers );
		$lines    = $is_array ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		$filtered = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*(?:cc|bcc)\s*:/i', (string) $line ) ) {
				continue;
			}

			$filtered[] = $line;
		}

		return $is_array ? array_values( $filtered ) : implode( "\r\n", $filtered );
	}
}

if ( ! function_exists( 'uonix_email_add_environment_banner' ) ) {
	/**
	 * Adiciona um aviso visível ao corpo sem duplicá-lo.
	 *
	 * @param string $message Corpo original.
	 * @param string $label   Rótulo do ambiente.
	 * @return string
	 */
	function uonix_email_add_environment_banner( $message, $label ) {
		$message = (string) $message;
		$banner  = 'Este e-mail foi enviado pelo ambiente ' . $label . ' da Uonix.';

		if ( false !== strpos( $message, $banner ) ) {
			return $message;
		}

		if ( preg_match( '/<\/?[a-z][\s\S]*>/i', $message ) ) {
			$html_banner = '<div style="background:#f97316;color:#111827;font-family:Arial,sans-serif;font-size:14px;font-weight:700;line-height:1.4;padding:10px 16px;text-align:center;text-transform:uppercase;">'
				. esc_html( $banner )
				. '</div>';

			if ( false !== stripos( $message, '<body' ) ) {
				return preg_replace( '/(<body[^>]*>)/i', '$1' . $html_banner, $message, 1 );
			}

			return $html_banner . $message;
		}

		return '[' . $label . '] ' . $banner . "\n\n" . $message;
	}
}

if ( ! function_exists( 'uonix_apply_email_environment_policy' ) ) {
	/**
	 * Aplica a política pura aos argumentos de wp_mail().
	 *
	 * @param array       $args           Argumentos de wp_mail().
	 * @param string|null $environment    Ambiente explícito ou UONIX_ENV.
	 * @param string|null $safe_recipient Caixa segura explícita ou constante.
	 * @return array
	 */
	function uonix_apply_email_environment_policy( array $args, $environment = null, $safe_recipient = null ) {
		$environment = null === $environment && defined( 'UONIX_ENV' ) ? UONIX_ENV : $environment;
		$label       = uonix_email_environment_label( $environment );

		if ( '' === $label ) {
			return $args;
		}

		if ( in_array( $environment, array( 'staging', 'development' ), true ) ) {
			$safe_recipient = uonix_nonprod_email_recipient( $safe_recipient );
			$args['to']     = '' === $safe_recipient ? array() : $safe_recipient;

			if ( isset( $args['headers'] ) ) {
				$args['headers'] = uonix_email_remove_copy_headers( $args['headers'] );
			}
		}

		$subject_prefix = '[' . $label . '] ';
		$subject        = isset( $args['subject'] ) ? (string) $args['subject'] : '';

		if ( ! str_starts_with( $subject, $subject_prefix ) ) {
			$args['subject'] = $subject_prefix . $subject;
		}

		$args['message'] = uonix_email_add_environment_banner( $args['message'] ?? '', $label );

		return $args;
	}
}

if ( ! function_exists( 'uonix_filter_email_environment_policy' ) ) {
	function uonix_filter_email_environment_policy( $args ) {
		return uonix_apply_email_environment_policy( (array) $args );
	}
}

if ( ! function_exists( 'uonix_prevent_unsafe_nonprod_email' ) ) {
	/**
	 * Interrompe wp_mail() antes do transporte se QA/DEV não tiverem caixa segura válida.
	 *
	 * @param mixed $short_circuit Valor de short-circuit anterior.
	 * @param array $args          Argumentos já filtrados de wp_mail().
	 * @return mixed
	 */
	function uonix_prevent_unsafe_nonprod_email( $short_circuit, $args ) {
		if ( ! uonix_should_block_email_environment() ) {
			return $short_circuit;
		}

		$message = 'UONIX: envio bloqueado porque UONIX_NONPROD_EMAIL_TO está ausente ou inválido em QA/DEV.';
		error_log( $message );

		if ( class_exists( 'WP_Error' ) && function_exists( 'do_action' ) ) {
			do_action( 'wp_mail_failed', new WP_Error( 'uonix_nonprod_email_not_configured', $message, $args ) );
		}

		return false;
	}
}

if ( ! function_exists( 'uonix_nonprod_email_admin_notice' ) ) {
	function uonix_nonprod_email_admin_notice() {
		if ( ! uonix_should_block_email_environment() ) {
			return;
		}

		echo '<div class="notice notice-error"><p>'
			. esc_html( 'Envio de e-mail bloqueado: configure UONIX_NONPROD_EMAIL_TO com uma caixa segura para QA/DEV.' )
			. '</p></div>';
	}
}

add_filter( 'wp_mail', 'uonix_filter_email_environment_policy', PHP_INT_MAX );
add_filter( 'pre_wp_mail', 'uonix_prevent_unsafe_nonprod_email', PHP_INT_MAX, 2 );
add_action( 'admin_notices', 'uonix_nonprod_email_admin_notice' );