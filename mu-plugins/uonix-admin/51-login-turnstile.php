<?php
/**
 * Turnstile na tela nativa de login do WordPress.
 *
 * O desafio reduz automação, mas não substitui limite de tentativas, bloqueio
 * por IP, MFA ou proteção contra credential stuffing. Esse limite é conhecido
 * e foi aceito por Cassio em 2026-08-03 até o fim da migração.
 *
 * A validação é fail-open quando as chaves não estão configuradas: impedir o
 * acesso administrativo por erro de configuração seria pior que perder
 * temporariamente o desafio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_login_turnstile_is_active' ) ) {
	/**
	 * Verifica o contrato completo do módulo compartilhado em tempo de execução.
	 *
	 * uonix-integrations é carregado depois de uonix-admin, portanto as funções
	 * ainda não existem quando este arquivo é incluído, mas já existem quando os
	 * hooks da tela de login são executados.
	 */
	function uonix_login_turnstile_is_active() {
		return function_exists( 'uonix_turnstile_is_enabled' )
			&& function_exists( 'uonix_turnstile_render_widget' )
			&& function_exists( 'uonix_turnstile_validate_request' )
			&& uonix_turnstile_is_enabled();
	}
}

if ( ! function_exists( 'uonix_login_turnstile_render' ) ) {
	/**
	 * Renderiza o mesmo widget usado pelos formulários customizados.
	 */
	function uonix_login_turnstile_render() {
		if ( ! uonix_login_turnstile_is_active() ) {
			return;
		}

		$widget = uonix_turnstile_render_widget(
			'wp_login',
			array(
				'theme' => 'light',
			)
		);

		if ( '' !== $widget ) {
			printf( '<div class="uonix-login-turnstile" style="margin:0 0 16px;">%s</div>', $widget );
		}
	}
}

if ( ! function_exists( 'uonix_login_turnstile_validate' ) ) {
	/**
	 * Valida o desafio antes de o core comparar usuário e senha.
	 *
	 * @param null|WP_User|WP_Error $user     Resultado de filtros anteriores.
	 * @param string                $username Usuário informado.
	 * @param string                $password Senha informada.
	 * @return null|WP_User|WP_Error
	 */
	function uonix_login_turnstile_validate( $user, $username, $password ) {
		global $pagenow;

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

		// Só o formulário nativo exibe o widget. Não bloquear REST, XML-RPC,
		// WP-CLI, WooCommerce nem outros consumidores de authenticate.
		if ( 'wp-login.php' !== $pagenow || 'POST' !== $request_method ) {
			return $user;
		}

		if ( ! uonix_login_turnstile_is_active() ) {
			return $user;
		}

		$validation = uonix_turnstile_validate_request( 'wp_login' );

		if ( is_wp_error( $validation ) ) {
			// Falha de transporte não é recusa do desafio. Cloudflare fora do ar,
			// egress bloqueado ou timeout trancariam todos os administradores fora
			// do wp-admin, sem via de recuperação pelo navegador. Mesma razão do
			// fail-open por chave ausente: perder o desafio é melhor que perder o
			// acesso. Recusas reais do desafio continuam bloqueando.
			if ( 'uonix_turnstile_request_failed' === $validation->get_error_code() ) {
				return $user;
			}

			return new WP_Error(
				'uonix_login_turnstile',
				__( '<strong>Erro:</strong> falha na verificação de segurança. Recarregue a página e tente novamente.', 'uonix' )
			);
		}

		return $user;
	}
}

add_action( 'login_form', 'uonix_login_turnstile_render' );

/*
 * Prioridade 30: DEPOIS de wp_authenticate_username_password (20).
 *
 * O core não faz curto-circuito com WP_Error — ele só devolve cedo quando recebe
 * um WP_User. Validando antes (prioridade 5), o nosso WP_Error era descartado e,
 * com credencial correta, o login entrava SEM desafio. Comprovado em DEV com
 * instrumentação: prio 6 tinha uonix_login_turnstile, prio 10002 já tinha
 * invalid_email.
 *
 * Rodando em 30 o usuário já foi resolvido, então o nosso erro é o último a
 * valer e o bloqueio é real. A senha é verificada primeiro, o que não é
 * problema: o Turnstile mitiga automação, não substitui limite de tentativas.
 */
add_filter( 'authenticate', 'uonix_login_turnstile_validate', 30, 3 );
