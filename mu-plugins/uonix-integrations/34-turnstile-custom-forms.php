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
			'appearance' => 'interaction-only',
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
	 * Registra um loader local; a API oficial so e baixada quando o formulario entra em uso.
	 */
	function uonix_turnstile_enqueue_assets() {
		if ( ! uonix_turnstile_is_enabled() ) {
			return;
		}

		static $inline_added = false;

		$handle = 'uonix-turnstile-lazy';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false, array(), null, true );
		}

		if ( ! $inline_added ) {
			wp_add_inline_script(
				$handle,
				"(function() {
	if (window.uonixTurnstile && window.uonixTurnstile.lazyReady) {
		return;
	}

	var apiSrc = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=uonixTurnstileOnload';
	var apiPromise = null;

	function getTokenInput(form) {
		var input;

		if (!form) {
			return null;
		}

		input = form.querySelector('input[name=\"cf-turnstile-response\"]');

		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'cf-turnstile-response';
			form.appendChild(input);
		}

		return input;
	}

	function setToken(form, token) {
		var input = getTokenInput(form);

		if (input) {
			input.value = token || '';
		}
	}

	function isVisibleTree(node) {
		var current = node;
		var style;

		while (current && current !== document.documentElement) {
			style = window.getComputedStyle(current);

			if (style.display === 'none' || style.visibility === 'hidden') {
				return false;
			}

			current = current.parentElement;
		}

		return true;
	}

	function renderContainer(container) {
		var form;
		var widgetId;

		if (!window.turnstile || !container || container.dataset.uonixRendered === '1' || !isVisibleTree(container)) {
			return;
		}

		form = container.closest('form');
		widgetId = window.turnstile.render(container, {
			sitekey: container.dataset.sitekey,
			action: container.dataset.action || 'uonix_form',
			theme: container.dataset.theme || 'auto',
			size: container.dataset.size || 'flexible',
			appearance: container.dataset.appearance || 'interaction-only',
			'response-field': false,
			callback: function(token) {
				setToken(form, token);

				if (form && form.dataset.uonixTurnstilePendingSubmit === '1') {
					form.dataset.uonixTurnstilePendingSubmit = '';

					if (typeof form.requestSubmit === 'function') {
						form.requestSubmit();
					} else {
						form.submit();
					}
				}
			},
			'expired-callback': function() {
				setToken(form, '');
			},
			'error-callback': function() {
				setToken(form, '');
			}
		});

		container.dataset.uonixRendered = '1';
		container.dataset.uonixWidgetId = widgetId;
	}

	function renderAll(root) {
		if (!window.turnstile) {
			return;
		}

		(root || document).querySelectorAll('.uonix-turnstile-widget').forEach(renderContainer);
	}

	function loadApi() {
		var existing;
		var script;

		if (window.turnstile) {
			return Promise.resolve();
		}

		if (apiPromise) {
			return apiPromise;
		}

		existing = document.querySelector('script[src*=\"challenges.cloudflare.com/turnstile/v0/api.js\"]');

		apiPromise = new Promise(function(resolve, reject) {
			if (existing) {
				existing.addEventListener('load', resolve, { once: true });
				existing.addEventListener('error', reject, { once: true });

				if (window.turnstile) {
					resolve();
				}

				return;
			}

			script = document.createElement('script');
			script.src = apiSrc;
			script.async = true;
			script.defer = true;
			script.onload = resolve;
			script.onerror = reject;
			document.head.appendChild(script);
		});

		return apiPromise;
	}

	function releasePendingSubmit(root, reason) {
		var form = root && root.tagName === 'FORM' ? root : (root && root.closest ? root.closest('form') : null);

		if (!form || form.dataset.uonixTurnstilePendingSubmit !== '1') {
			return;
		}

		form.dataset.uonixTurnstilePendingSubmit = '';
		form.dataset.uonixTurnstileUnavailable = '1';

		if (window.console && window.console.warn) {
			window.console.warn('UONIX Turnstile: desafio indisponivel (' + reason + '); envio liberado sem token.');
		}

		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	function prepare(root) {
		return loadApi()
			.then(function() {
				renderAll(root || document);
			})
			.catch(function(error) {
				// Antes havia um catch VAZIO aqui. Quando o api.js da Cloudflare
				// nao carrega -- adblock, firewall corporativo, DNS filtrado --
				// o preventDefault do listener de submit ja impediu o envio e o
				// erro era engolido em silencio: o botao parecia morto, sem
				// nenhuma mensagem. Perda de lead nos formularios do site.
				//
				// Liberar o envio e a escolha correta para formulario publico: a
				// validacao server-side continua obrigatoria e recusa o request
				// sem token, entao nao se abre brecha. O que se evita e o
				// usuario legitimo travado sem explicacao.
				releasePendingSubmit(root, error && error.type ? error.type : 'api indisponivel');
			});
	}

	function formHasToken(form) {
		var input = form ? form.querySelector('input[name=\"cf-turnstile-response\"]') : null;

		return !!(input && input.value);
	}

	function prepareFromEventTarget(target) {
		var form = target && target.closest ? target.closest('form') : null;

		if (form && form.querySelector('.uonix-turnstile-widget') && isVisibleTree(form)) {
			prepare(form);
		}
	}

	function observeWidgets() {
		var widgets = document.querySelectorAll('.uonix-turnstile-widget');

		if (!widgets.length) {
			return;
		}

		if (!('IntersectionObserver' in window)) {
			return;
		}

		var observer = new IntersectionObserver(function(entries) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting && isVisibleTree(entry.target)) {
					prepare(entry.target.closest('form') || entry.target);
					observer.unobserve(entry.target);
				}
			});
		}, { rootMargin: '240px 0px' });

		widgets.forEach(function(widget) {
			observer.observe(widget);
		});
	}

	document.addEventListener('focusin', function(event) {
		prepareFromEventTarget(event.target);
	}, true);

	document.addEventListener('pointerdown', function(event) {
		prepareFromEventTarget(event.target);
	}, true);

	document.addEventListener('submit', function(event) {
		var form = event.target;

		if (!form || !form.querySelector || !form.querySelector('.uonix-turnstile-widget') || formHasToken(form)) {
			return;
		}

		event.preventDefault();
		form.dataset.uonixTurnstilePendingSubmit = '1';
		prepare(form);
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', observeWidgets);
	} else {
		observeWidgets();
	}

	window.uonixTurnstile = {
		lazyReady: true,
		widgets: {},
		load: loadApi,
		renderAll: renderAll,
		prepare: prepare,
		reset: function(form) {
			var container;
			var input;

			if (!form) {
				return;
			}

			input = form.querySelector('input[name=\"cf-turnstile-response\"]');

			if (input) {
				input.value = '';
			}

			container = form.querySelector('.uonix-turnstile-widget[data-uonix-widget-id]');

			if (window.turnstile && container) {
				window.turnstile.reset(container.dataset.uonixWidgetId);
			} else {
				prepare(form);
			}
		}
	};

	window.uonixTurnstileOnload = function() {
		window.uonixTurnstile.renderAll(document);
	};
})();",
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
		$settings['appearance'] = 'interaction-only';
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
	 *
	 * @param string      $expected_action Action esperada no resultado da Cloudflare.
	 * @param string|null $token           Token explícito. Quando null, lê de $_POST.
	 *                                    Necessário para superfícies que NÃO populam
	 *                                    $_POST — a Store API do WooCommerce recebe
	 *                                    JSON, então $_POST fica vazio e ler de lá
	 *                                    daria "token ausente" mesmo com token válido.
	 */
	function uonix_turnstile_validate_request( $expected_action = '', $token = null ) {
		if ( ! uonix_turnstile_is_enabled() ) {
			return true;
		}

		$settings = uonix_turnstile_get_settings();

		if ( null === $token ) {
			$token = wp_unslash( $_POST['cf-turnstile-response'] ?? '' );
		}

		$token = sanitize_text_field( (string) $token );

		if ( empty( $token ) ) {
			// Caso mais comum: o usuário não completou o widget. É o cenário mais
			// brando dos três e não exige recarregar — o widget é resetado pelo JS
			// (scheduleTurnstileReset no checkout_error), então pedir reload era
			// burocracia desnecessária.
			return new WP_Error( 'uonix_turnstile_empty', 'Confirme a verificação de segurança para continuar.' );
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				/*
				 * 5s em vez de 10s. O siteverify da Cloudflare responde tipicamente em
				 * menos de 500ms; 10s significava que, em degradação, a pessoa ficava
				 * 10 segundos olhando um spinner antes de ver o erro — e no checkout
				 * isso passava do watchdog de UX.
				 *
				 * Cortar em 5s ainda dá margem de 10x sobre o tempo normal. O filtro
				 * permite ajustar sem editar o mu-plugin.
				 */
				'timeout' => (int) apply_filters( 'uonix_turnstile_verify_timeout', 5 ),
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
	 *
	 * Devolve também o `code` do WP_Error. Antes só `message` era enviado, e o JS não
	 * conseguia distinguir "faltou completar o desafio" de "desafio inválido" — o que
	 * impedia UX específica no cliente (ex.: destacar o widget apenas no primeiro caso,
	 * como o checkout passou a fazer).
	 *
	 * Códigos possíveis:
	 *   uonix_turnstile_empty           token ausente — a pessoa não completou
	 *   uonix_turnstile_failed          a Cloudflare recusou o token
	 *   uonix_turnstile_action_mismatch action divergente do esperado
	 *   uonix_turnstile_request_failed  falha de transporte (rede/timeout)
	 */
	function uonix_turnstile_send_json_error_if_invalid( $action ) {
		$validation = uonix_turnstile_validate_request( $action );

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error(
				array(
					'message' => $validation->get_error_message(),
					'code'    => $validation->get_error_code(),
				)
			);
		}
	}
}
