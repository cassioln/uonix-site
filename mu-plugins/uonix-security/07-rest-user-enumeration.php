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
 * O corte é por capacidade. Quem tem list_users (o administrador) mantém todas
 * as rotas de usuário. Quem não tem (o anônimo e, entre os papéis nativos,
 * assinante, colaborador, autor e editor) perde a coleção, o item por ID e as
 * senhas de aplicativo.
 *
 * Uma exceção, e só uma: /wp/v2/users/me fica para qualquer usuário LOGADO. Ela
 * devolve apenas o próprio usuário, então não enumera ninguém, e o editor de
 * blocos depende dela para ler e gravar as preferências de quem edita. O papel
 * editor NÃO tem list_users: com o corte total, ele recebia 404 nessa rota e as
 * preferências não eram gravadas (#346). O anônimo não tem "próprio usuário",
 * e para ele a rota também some.
 *
 * Efeito que fica de propósito: quem não tem list_users segue sem a lista de
 * autores (`users?who=authors`) e sem o nome do autor de um post
 * (`users/<id>`), porque as duas são enumeração. No editor de blocos, o
 * seletor de autor fica vazio e a linha "Autor" do painel mostra "(sem
 * autor)" para o papel editor, antes e depois da exceção de /users/me.
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

if ( ! function_exists( 'uonix_security_rest_is_own_user_route' ) ) {
	/**
	 * Diz se a chave de rota é a do PRÓPRIO usuário, e só ela.
	 *
	 * A comparação é exata, de propósito: `/wp/v2/users/me/`, `/wp/v2/users/me-x`
	 * e as rotas de senha de aplicativo, que também aceitam `me` no meio da
	 * expressão, não são a rota do próprio usuário e continuam cortadas.
	 *
	 * @param mixed $route Chave de rota registrada na REST API.
	 * @return bool
	 */
	function uonix_security_rest_is_own_user_route( $route ) {
		return '/wp/v2/users/me' === $route;
	}
}

if ( ! function_exists( 'uonix_security_filter_rest_user_endpoints' ) ) {
	/**
	 * Remove as rotas de usuário — e somente elas — de quem não pode listar,
	 * exceto a do próprio usuário quando há alguém logado.
	 *
	 * @param array $endpoints Rotas registradas na REST API.
	 * @return array
	 */
	function uonix_security_filter_rest_user_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) || ! uonix_security_rest_hides_users() ) {
			return $endpoints;
		}

		$logged_in = is_user_logged_in();

		foreach ( array_keys( $endpoints ) as $route ) {
			// Casa a coleção e o item, sem tocar em rotas de outros namespaces
			// que apenas contenham "users" no meio do caminho.
			if ( ! preg_match( '#^/wp/v2/users(?:/|$)#', (string) $route ) ) {
				continue;
			}

			if ( $logged_in && uonix_security_rest_is_own_user_route( $route ) ) {
				continue;
			}

			unset( $endpoints[ $route ] );
		}

		return $endpoints;
	}
}
add_filter( 'rest_endpoints', 'uonix_security_filter_rest_user_endpoints', 1000 );
