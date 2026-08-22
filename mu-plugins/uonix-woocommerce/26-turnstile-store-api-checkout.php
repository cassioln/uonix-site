<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UONIX - Turnstile na Store API de checkout do WooCommerce.
 *
 * ============================================================================
 * POR QUE ESTE ARQUIVO EXISTE (C41)
 * ============================================================================
 *
 * O checkout visível do site é o CLÁSSICO (shortcode) e já está protegido por
 * `woocommerce_after_checkout_validation` em 17-woocommerce-checkout-design.php.
 * Isso foi medido em produção: POST em /?wc-ajax=checkout com todos os campos
 * válidos e SEM token devolve `{"result":"failure"}` com a mensagem de falha na
 * verificação de segurança. Não há bypass ali.
 *
 * O bypass real estava em OUTRA superfície, que ninguém havia enumerado: a
 * Store API. `POST /wp-json/wc/store/v1/checkout` cria pedido por um ciclo
 * próprio (Automattic\WooCommerce\StoreApi\Routes\V1\Checkout::process_order)
 * que NÃO dispara `woocommerce_after_checkout_validation`. O endpoint é
 * ANÔNIMO por construção: `requires_nonce()` devolve false quando a requisição
 * traz um Cart-Token, e o Cart-Token é entregue de graça por
 * `GET /wp-json/wc/store/v1/cart`.
 *
 * PROVA EMPÍRICA (DEV, test.uonix.ksio.dev, 2026-08-17): a sequência
 *   GET  /wp-json/wc/store/v1/cart            -> devolve Cart-Token
 *   POST /wp-json/wc/store/v1/cart/add-item   -> 201
 *   POST /wp-json/wc/store/v1/checkout        -> 200
 * criou o pedido 11027 (status gplsquote-req) SEM nenhum token de Turnstile.
 * Em produção a mesma rota responde 400 por validação de CAMPO — ou seja, está
 * ativa e alcançável anonimamente; só não foi levada até o fim para não gerar
 * pedido real.
 *
 * ENUMERAÇÃO (não memória): varredura de todos os namespaces REST publicados
 * em produção, filtrando rotas de escrita ligadas a pedido/checkout e medindo a
 * reachability anônima de cada uma. Resultado: todas as rotas `wc/v1|v2|v3` e
 * `wc-analytics` de orders respondem 401 (exigem credencial). As ÚNICAS rotas
 * anônimas capazes de criar pedido são as da Store API:
 *
 *   /wc/store/v1/checkout          (e o alias legado /wc/store/checkout)
 *   /wc/store/v1/checkout/<id>     (e /wc/store/checkout/<id>)
 *
 * São exatamente as cobertas aqui.
 *
 * ============================================================================
 * ONDE VALIDAR
 * ============================================================================
 *
 * A validação roda em `rest_request_before_callbacks`, que é o último ponto
 * ANTES do callback da rota. Isso importa: o callback é quem cria o pedido, e
 * qualquer hook interno do ciclo da Store API já rodaria com pedido gravado.
 *
 *   - `woocommerce_store_api_checkout_order_processed` dispara DEPOIS do pedido
 *     existir e depois do e-mail — bloquear ali deixaria lixo no banco.
 *   - `woocommerce_store_api_checkout_update_order_from_request` dispara depois
 *     do rascunho já ter sido criado (create_or_update_draft_order).
 *
 * Devolver WP_Error em `rest_request_before_callbacks` faz o WordPress abortar
 * o despacho e responder o erro, sem nunca executar o callback. Nenhum pedido,
 * nem rascunho, é criado.
 *
 * ============================================================================
 * DE ONDE VEM O TOKEN
 * ============================================================================
 *
 * A Store API recebe JSON, então `$_POST` fica VAZIO. Ler o token de $_POST
 * daria "token ausente" mesmo com token válido, quebrando o checkout em blocos
 * se ele passar a ser usado. Por isso o token é lido do corpo JSON e o
 * validador compartilhado recebe o valor explicitamente (2º parâmetro de
 * `uonix_turnstile_validate_request`).
 *
 * Chaves aceitas no corpo: `cf-turnstile-response` (nome canônico do widget) e
 * `extensions.uonix_turnstile.token` (via `extensionCartUpdate`/`ExtendSchema`,
 * que é como um bloco customizado enviaria o dado).
 *
 * ============================================================================
 * FAIL-CLOSED, COM UMA EXCEÇÃO DELIBERADA
 * ============================================================================
 *
 * Token ausente ou recusado bloqueia. Só a INDISPONIBILIDADE da Cloudflare
 * (`uonix_turnstile_request_failed`: timeout, egress bloqueado, terceiro fora)
 * é tratada como fail-open, pela mesma razão já documentada no login: uma queda
 * de terceiro não pode derrubar a captação de orçamentos do site inteiro. As
 * recusas reais do desafio continuam barrando.
 */

if ( ! function_exists( 'uonix_turnstile_store_api_protected_routes' ) ) {
	/**
	 * Rotas da Store API que criam ou finalizam pedido.
	 *
	 * Casadas por prefixo de caminho, não por igualdade: `/checkout/<id>`
	 * também finaliza pedido, e o namespace legado sem versão (`wc/store`)
	 * continua registrado pelo WooCommerce e resolve para a mesma rota.
	 */
	function uonix_turnstile_store_api_protected_routes() {
		return apply_filters(
			'uonix_turnstile_store_api_protected_routes',
			array(
				'/wc/store/v1/checkout',
				'/wc/store/checkout',
			)
		);
	}
}

if ( ! function_exists( 'uonix_turnstile_store_api_route_is_protected' ) ) {
	/**
	 * Decide se a rota pedida está sob proteção.
	 *
	 * @param string $route Caminho da rota REST (ex.: /wc/store/v1/checkout).
	 */
	function uonix_turnstile_store_api_route_is_protected( $route ) {
		$route = untrailingslashit( (string) $route );

		if ( '' === $route ) {
			return false;
		}

		foreach ( uonix_turnstile_store_api_protected_routes() as $protected ) {
			$protected = untrailingslashit( (string) $protected );

			if ( $route === $protected ) {
				return true;
			}

			// `/wc/store/v1/checkout/123` finaliza pedido e precisa da mesma
			// proteção. O separador evita casar uma rota vizinha cujo nome
			// apenas comece igual (ex.: /checkout-draft).
			if ( 0 === strpos( $route, $protected . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'uonix_turnstile_store_api_extract_token' ) ) {
	/**
	 * Lê o token do corpo JSON da requisição REST.
	 *
	 * A Store API não popula $_POST, então o token precisa vir daqui.
	 *
	 * @param WP_REST_Request $request Requisição REST.
	 * @return string Token encontrado, ou string vazia.
	 */
	function uonix_turnstile_store_api_extract_token( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return '';
		}

		$token = $request->get_param( 'cf-turnstile-response' );

		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		$extensions = $request->get_param( 'extensions' );

		if ( is_array( $extensions ) ) {
			$namespaced = $extensions['uonix_turnstile'] ?? array();

			if ( is_array( $namespaced ) && isset( $namespaced['token'] ) && is_string( $namespaced['token'] ) ) {
				return $namespaced['token'];
			}
		}

		return '';
	}
}

if ( ! function_exists( 'uonix_turnstile_store_api_guard' ) ) {
	/**
	 * Barra o despacho do callback que cria pedido quando o desafio não passa.
	 *
	 * Assinatura de `rest_request_before_callbacks`: ( $response, $handler, $request ).
	 * Devolver WP_Error aborta o despacho — o callback não roda e nenhum pedido
	 * é criado.
	 *
	 * @param mixed           $response Resposta acumulada (WP_Error curto-circuita).
	 * @param array           $handler  Handler da rota.
	 * @param WP_REST_Request $request  Requisição REST.
	 * @return mixed
	 */
	function uonix_turnstile_store_api_guard( $response, $handler, $request ) {
		// Erro anterior já curto-circuitou o despacho: não sobrescrever.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		// Somente métodos de escrita. GET do checkout apenas lê estado.
		if ( ! in_array( strtoupper( (string) $request->get_method() ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		if ( ! uonix_turnstile_store_api_route_is_protected( $request->get_route() ) ) {
			return $response;
		}

		if ( ! apply_filters( 'uonix_turnstile_protect_woocommerce_checkout', true ) ) {
			return $response;
		}

		if ( ! function_exists( 'uonix_turnstile_validate_request' ) || ! function_exists( 'uonix_turnstile_is_enabled' ) ) {
			return $response;
		}

		if ( ! uonix_turnstile_is_enabled() ) {
			return $response;
		}

		$token      = uonix_turnstile_store_api_extract_token( $request );
		$validation = uonix_turnstile_validate_request( 'woocommerce_checkout', $token );

		if ( ! is_wp_error( $validation ) ) {
			return $response;
		}

		// Indisponibilidade da Cloudflare não pode derrubar a captação de
		// orçamentos. Recusa real do desafio continua bloqueando.
		if ( 'uonix_turnstile_request_failed' === $validation->get_error_code() ) {
			return $response;
		}

		return new WP_Error(
			'uonix_turnstile_store_api_failed',
			$validation->get_error_message(),
			array( 'status' => 403 )
		);
	}
}

add_filter( 'rest_request_before_callbacks', 'uonix_turnstile_store_api_guard', 10, 3 );
