<?php
/**
 * Turnstile para formulários customizados Uonix.
 *
 * Reutiliza as chaves salvas no Fluent Forms e desativa automaticamente em localhost.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_turnstile_is_local' ) ) {
	/**
	 * Identifica ambientes locais mesmo quando WP_ENVIRONMENT_TYPE não foi definido.
	 */
	function uonix_turnstile_is_local() {
		if ( defined( 'UONIX_ENV' ) && 'local' === UONIX_ENV ) {
			return true;
		}

		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}
}

if ( ! function_exists( 'uonix_turnstile_get_settings' ) ) {
	/**
	 * Busca as chaves do Turnstile em constantes ou na configuração global do Fluent Forms.
	 */
	function uonix_turnstile_get_settings() {
		$fluent_settings = get_option( '_fluentform_turnstile_details', array() );

		if ( ! is_array( $fluent_settings ) ) {
			$fluent_settings = array();
		}

		$settings = array(
			'siteKey'    => defined( 'UONIX_TURNSTILE_SITE_KEY' ) ? UONIX_TURNSTILE_SITE_KEY : ( $fluent_settings['siteKey'] ?? '' ),
			'secretKey'  => defined( 'UONIX_TURNSTILE_SECRET_KEY' ) ? UONIX_TURNSTILE_SECRET_KEY : ( $fluent_settings['secretKey'] ?? '' ),
			'theme'      => $fluent_settings['theme'] ?? 'auto',
			'appearance' => $fluent_settings['appearance'] ?? 'always',
			'size'       => 'flexible',
		);

		return apply_filters( 'uonix_turnstile_settings', $settings );
	}
}

if ( ! function_exists( 'uonix_turnstile_is_enabled' ) ) {
	/**
	 * Ativa Turnstile apenas fora do local e quando existem chaves configuradas.
	 */
	function uonix_turnstile_is_enabled() {
		if ( uonix_turnstile_is_local() ) {
			return false;
		}

		$settings = uonix_turnstile_get_settings();

		return ! empty( $settings['siteKey'] ) && ! empty( $settings['secretKey'] );
	}
}

if ( ! function_exists( 'uonix_turnstile_enqueue_assets' ) ) {
	/**
	 * Carrega a API oficial do Turnstile e inicializa todos os widgets Uonix da página.
	 */
	function uonix_turnstile_enqueue_assets() {
		if ( ! uonix_turnstile_is_enabled() ) {
			return;
		}

		static $inline_added = false;

		$handle = 'uonix-turnstile-api';
		$src    = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=uonixTurnstileOnload';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, $src, array(), null, true );
		}

		if ( ! $inline_added ) {
			wp_add_inline_script(
				$handle,
				"window.uonixTurnstile = window.uonixTurnstile || {
	widgets: {},
	renderAll: function(root) {
		if (!window.turnstile) return;
		(root || document).querySelectorAll('.uonix-turnstile-widget:not([data-uonix-rendered=\"1\"])').forEach(function(container) {
			var form = container.closest('form');
			var widgetId = window.turnstile.render(container, {
				sitekey: container.dataset.sitekey,
				action: container.dataset.action || 'uonix_form',
				theme: container.dataset.theme || 'auto',
				size: container.dataset.size || 'flexible',
				appearance: container.dataset.appearance || 'always',
				'response-field': false,
				callback: function(token) {
					if (!form) return;
					var input = form.querySelector('input[name=\"cf-turnstile-response\"]');
					if (!input) {
						input = document.createElement('input');
						input.type = 'hidden';
						input.name = 'cf-turnstile-response';
						form.appendChild(input);
					}
					input.value = token;
				},
				'expired-callback': function() {
					if (!form) return;
					var input = form.querySelector('input[name=\"cf-turnstile-response\"]');
					if (input) input.value = '';
				},
				'error-callback': function() {
					if (!form) return;
					var input = form.querySelector('input[name=\"cf-turnstile-response\"]');
					if (input) input.value = '';
				}
			});
			container.dataset.uonixRendered = '1';
			container.dataset.uonixWidgetId = widgetId;
		});
	},
	reset: function(form) {
		if (!window.turnstile || !form) return;
		var container = form.querySelector('.uonix-turnstile-widget[data-uonix-widget-id]');
		var input = form.querySelector('input[name=\"cf-turnstile-response\"]');
		if (input) input.value = '';
		if (container) window.turnstile.reset(container.dataset.uonixWidgetId);
	}
};
window.uonixTurnstileOnload = function() {
	window.uonixTurnstile.renderAll(document);
};",
				'before'
			);

			$inline_added = true;
		}

		wp_enqueue_script( $handle );
	}
}

if ( ! function_exists( 'uonix_turnstile_render_widget' ) ) {
	/**
	 * Imprime o container do widget. Em local retorna vazio.
	 *
	 * @param string $action  Nome da ação usada na validação.
	 * @param array  $options Overrides visuais por formulário.
	 */
	function uonix_turnstile_render_widget( $action, $options = array() ) {
		if ( ! uonix_turnstile_is_enabled() ) {
			return '';
		}

		uonix_turnstile_enqueue_assets();

		$settings = uonix_turnstile_get_settings();
		$options  = is_array( $options ) ? $options : array();
		$settings = array_merge( $settings, array_intersect_key( $options, array_flip( array( 'theme', 'appearance', 'size' ) ) ) );
		$action   = preg_replace( '/[^a-zA-Z0-9_-]/', '_', (string) $action );
		$action   = substr( $action, 0, 32 );

		return sprintf(
			'<div class="uonix-turnstile-widget" data-sitekey="%s" data-action="%s" data-theme="%s" data-size="%s" data-appearance="%s"></div>',
			esc_attr( $settings['siteKey'] ),
			esc_attr( $action ),
			esc_attr( $settings['theme'] ),
			esc_attr( $settings['size'] ),
			esc_attr( $settings['appearance'] )
		);
	}
}

if ( ! function_exists( 'uonix_turnstile_validate_request' ) ) {
	/**
	 * Valida o token enviado pelo formulário customizado antes de gravar dados.
	 */
	function uonix_turnstile_validate_request( $expected_action = '' ) {
		if ( ! uonix_turnstile_is_enabled() ) {
			return true;
		}

		$settings = uonix_turnstile_get_settings();
		$token    = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) );

		if ( empty( $token ) ) {
			return new WP_Error( 'uonix_turnstile_empty', 'Falha na verificação de segurança. Recarregue a página e tente novamente.' );
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $settings['secretKey'],
					'response' => $token,
					'remoteip' => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'UONIX Turnstile: erro ao validar token: ' . $response->get_error_message() );
			return new WP_Error( 'uonix_turnstile_request_failed', 'Não foi possível validar a verificação de segurança. Tente novamente.' );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $result['success'] ) ) {
			error_log( 'UONIX Turnstile: validação recusada: ' . wp_json_encode( $result ) );
			return new WP_Error( 'uonix_turnstile_failed', 'Falha na verificação de segurança. Tente novamente.' );
		}

		if ( $expected_action && ! empty( $result['action'] ) && $expected_action !== $result['action'] ) {
			error_log( 'UONIX Turnstile: action divergente: ' . wp_json_encode( $result ) );
			return new WP_Error( 'uonix_turnstile_action_mismatch', 'Falha na verificação de segurança. Tente novamente.' );
		}

		return true;
	}
}

if ( ! function_exists( 'uonix_turnstile_send_json_error_if_invalid' ) ) {
	/**
	 * Encapsula a resposta AJAX padrão dos formulários Uonix.
	 */
	function uonix_turnstile_send_json_error_if_invalid( $action ) {
		$validation = uonix_turnstile_validate_request( $action );

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
		}
	}
}
