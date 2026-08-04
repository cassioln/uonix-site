<?php
/**
 * Impede enumeração de usuários pela REST API.
 *
 * O gate do Turnstile cobre o formulário nativo de login e o XML-RPC está
 * desligado em 05-xmlrpc-pingback.php, mas /wp-json/wp/v2/users respondia 200
 * para qualquer anônimo e entregava os slugs reais de login. Isso adianta
 * metade do trabalho de um ataque de força bruta: o atacante deixa de precisar
 * adivinhar QUEM atacar.
 *
 * O corte é por capacidade, não por rota fixa: quem tem list_users continua com
 * o endpoint, porque é dele que o próprio wp-admin depende para montar telas de
 * autor. Bloquear para todos quebraria o editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_security_rest_hides_users' ) ) {
	/**
	 * Decide se as rotas de usuário devem desaparecer para o requisitante.
	 *
	 * @return bool
	 */
	function uonix_security_rest_hides_users() {
		return ! is_user_logged_in() || ! current_user_can( 'list_users' );
	}
}

if ( ! function_exists( 'uonix_security_filter_rest_user_endpoints' ) ) {
	/**
	 * Remove as rotas de usuário — e somente elas — de quem não pode listar.
	 *
	 * @param array $endpoints Rotas registradas na REST API.
	 * @return array
	 */
	function uonix_security_filter_rest_user_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) || ! uonix_security_rest_hides_users() ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			// Casa a coleção e o item, sem tocar em rotas de outros namespaces
			// que apenas contenham "users" no meio do caminho.
			if ( preg_match( '#^/wp/v2/users(?:/|$)#', (string) $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}
}
add_filter( 'rest_endpoints', 'uonix_security_filter_rest_user_endpoints', 1000 );
