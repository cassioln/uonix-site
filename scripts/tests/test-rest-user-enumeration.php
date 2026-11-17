<?php
/**
 * Testes do bloqueio de enumeração de usuários pela REST API.
 *
 * O gate do Turnstile cobre o formulário nativo de login e o XML-RPC está
 * desligado, mas /wp-json/wp/v2/users respondia 200 para qualquer anônimo e
 * entregava os slugs reais de login. Isso dá metade do trabalho de um ataque de
 * força bruta de graça: o atacante já sabe QUEM atacar.
 *
 * O corte não pode levar junto a rota do PRÓPRIO usuário: o editor de blocos lê
 * e grava as preferências de quem edita por /wp/v2/users/me, e o papel editor
 * não tem list_users (#346).
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function is_user_logged_in() {
	return ! empty( $GLOBALS['uonix_test_logged_in'] );
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['uonix_test_caps'][ $capability ] );
}

$policy_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-security/07-rest-user-enumeration.php';

if ( ! is_file( $policy_file ) ) {
	fwrite( STDERR, "FAIL: 07-rest-user-enumeration.php ainda não existe.\n" );
	exit( 1 );
}

require_once $policy_file;

$failures = 0;

function uonix_rest_assert_same( $expected, $actual, $message ) {
	global $failures;

	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

/**
 * As chaves de rota, em ordem alfabética, para comparar conjuntos sem depender
 * da ordem em que o filtro as devolve.
 */
function uonix_rest_sorted_keys( array $routes ) {
	$keys = array_keys( $routes );
	sort( $keys );

	return $keys;
}

foreach ( array( 'uonix_security_rest_hides_users', 'uonix_security_rest_is_own_user_route', 'uonix_security_filter_rest_user_endpoints' ) as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, sprintf( "FAIL: %s() ainda não existe.\n", $required_function ) );
		exit( 1 );
	}
}

/*
 * Um anônimo não pode listar usuários. É este o caso que estava aberto em
 * QA e produção, entregando slug, nome e id de três contas reais.
 */
$GLOBALS['uonix_test_logged_in'] = false;
$GLOBALS['uonix_test_caps']      = array();
uonix_rest_assert_same( true, uonix_security_rest_hides_users(), 'anônimo é impedido de listar usuários' );

/*
 * Um usuário logado sem list_users também não: subscriber autenticado não tem
 * motivo para enumerar a equipe.
 */
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array();
uonix_rest_assert_same( true, uonix_security_rest_hides_users(), 'logado sem list_users continua impedido' );

/*
 * Quem tem list_users precisa manter o endpoint inteiro: é o administrador, e
 * o wp-admin dele monta telas de autor e de usuários por essas rotas.
 */
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array( 'list_users' => true );
uonix_rest_assert_same( false, uonix_security_rest_hides_users(), 'quem tem list_users mantém o endpoint' );

/*
 * A rota do próprio usuário é reconhecida por comparação EXATA. Qualquer outra
 * forma continua cortada: na dúvida a rota some.
 */
uonix_rest_assert_same( true, uonix_security_rest_is_own_user_route( '/wp/v2/users/me' ), '/wp/v2/users/me é a rota do próprio usuário' );

$not_own = array(
	'/wp/v2/users',
	'/wp/v2/users/',
	'/wp/v2/users/(?P<id>[\d]+)',
	'/wp/v2/users/me/',
	'/wp/v2/users/me-outro',
	'/wp/v2/users/mee',
	'/wp/v2/users/ME',
	'/wp/v2/USERS/me',
	'/wp/v2/users/me/application-passwords',
	'/wp/v2/users/x/users/me',
	'/extra/wp/v2/users/me',
	'/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords',
	'/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/introspect',
	'/wp/v2/posts',
	'/',
	'',
);
foreach ( $not_own as $route ) {
	uonix_rest_assert_same( false, uonix_security_rest_is_own_user_route( $route ), sprintf( '%s não é a rota do próprio usuário', var_export( $route, true ) ) );
}
uonix_rest_assert_same( false, uonix_security_rest_is_own_user_route( null ), 'null não é a rota do próprio usuário' );
uonix_rest_assert_same( false, uonix_security_rest_is_own_user_route( 123 ), 'um inteiro não é a rota do próprio usuário' );
// Com == um true casaria com qualquer string não vazia: a comparação tem de ser estrita.
uonix_rest_assert_same( false, uonix_security_rest_is_own_user_route( true ), 'um booleano verdadeiro não é a rota do próprio usuário' );

/*
 * Chaves reais, lidas em 2026-11-17 com rest_get_server()->get_routes() no
 * WordPress 7.1.4 (as seis de /wp/v2/users, posts, media, o índice e a rota
 * /fluentform/v1/managers/users, de outro namespace), mais três sintéticas que
 * um prefixo ou uma comparação frouxa confundiria com rota de usuário:
 * /wp/v2/users/me-outro, /wp/v2/users-extra e /extra/wp/v2/users.
 */
$routes = array(
	'/wp/v2/users'                                                                   => array( 'stub-colecao' ),
	'/wp/v2/users/me'                                                                => array( 'stub-me' ),
	'/wp/v2/users/(?P<id>[\d]+)'                                                     => array( 'stub-item' ),
	'/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords'                   => array( 'stub-senhas' ),
	'/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/(?P<uuid>[\w\-]+)' => array( 'stub-senha' ),
	'/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/introspect'        => array( 'stub-introspect' ),
	'/wp/v2/users/me-outro'                                                          => array( 'stub-sintetica' ),
	'/wp/v2/users-extra'                                                             => array( 'stub-prefixo-parecido' ),
	'/extra/wp/v2/users'                                                             => array( 'stub-outro-namespace' ),
	'/wp/v2/posts'                                                                   => array( 'stub' ),
	'/wp/v2/media'                                                                   => array( 'stub' ),
	'/fluentform/v1/managers/users'                                                  => array( 'stub' ),
	'/'                                                                              => array( 'stub' ),
);

$outside_users = array( '/', '/extra/wp/v2/users', '/fluentform/v1/managers/users', '/wp/v2/media', '/wp/v2/posts', '/wp/v2/users-extra' );

// A ordem das rotas não pode mudar o resultado (um `continue` virado em `break`
// só aparece quando a rota mantida vem antes de rotas que devem cair).
$orders = array(
	'ordem natural'  => $routes,
	'ordem invertida' => array_reverse( $routes, true ),
);

/*
 * Anônimo: nenhuma rota de usuário, nem a do próprio. Quem não está logado não
 * tem "próprio usuário", e a rota só serviria de sonda.
 */
$GLOBALS['uonix_test_logged_in'] = false;
$GLOBALS['uonix_test_caps']      = array();

foreach ( $orders as $label => $input ) {
	$filtered = uonix_security_filter_rest_user_endpoints( $input );

	uonix_rest_assert_same( $outside_users, uonix_rest_sorted_keys( $filtered ), "anônimo ({$label}): só restam as rotas que não são de usuário" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users', $filtered ), "anônimo ({$label}): a coleção é removida" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/me', $filtered ), "anônimo ({$label}): /users/me é removida" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/(?P<id>[\d]+)', $filtered ), "anônimo ({$label}): o item por ID é removido" );
}

/*
 * Logado SEM list_users (editor, autor, assinante): só a rota do próprio
 * usuário sobrevive. A coleção, o item por ID, as senhas de aplicativo e a
 * sintética parecida com `me` continuam cortadas.
 */
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array();

$expected_editor = array_merge( $outside_users, array( '/wp/v2/users/me' ) );
sort( $expected_editor );

foreach ( $orders as $label => $input ) {
	$filtered = uonix_security_filter_rest_user_endpoints( $input );

	uonix_rest_assert_same( $expected_editor, uonix_rest_sorted_keys( $filtered ), "logado sem list_users ({$label}): resta /users/me e o que não é de usuário" );
	uonix_rest_assert_same( true, array_key_exists( '/wp/v2/users/me', $filtered ), "logado sem list_users ({$label}): /users/me é preservada" );
	uonix_rest_assert_same( array( 'stub-me' ), $filtered['/wp/v2/users/me'] ?? null, "logado sem list_users ({$label}): o tratador de /users/me chega intacto" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users', $filtered ), "logado sem list_users ({$label}): a coleção segue removida" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/(?P<id>[\d]+)', $filtered ), "logado sem list_users ({$label}): o item por ID segue removido" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords', $filtered ), "logado sem list_users ({$label}): senhas de aplicativo seguem removidas" );
	uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/me-outro', $filtered ), "logado sem list_users ({$label}): rota parecida com /users/me é removida" );
}

// O despachante do núcleo casa as chaves de rota com a flag `i` (class-wp-rest-server.php): uma chave
// registrada como '/WP/v2/Users' é alcançada por /wp/v2/users. O filtro tem de tratá-la como rota de
// usuário, e a exceção continua sendo só a chave exata em minúsculas.
$caixa = array(
	'/WP/v2/Users'    => array( 'stub' ),
	'/wp/v2/USERS/me' => array( 'stub' ),
	'/wp/v2/users/ME' => array( 'stub' ),
	'/wp/v2/posts'    => array( 'stub' ),
);
$GLOBALS['uonix_test_logged_in'] = false;
$GLOBALS['uonix_test_caps']      = array();
uonix_rest_assert_same( array( '/wp/v2/posts' ), array_keys( uonix_security_filter_rest_user_endpoints( $caixa ) ), 'anônimo: chaves com caixa diferente também são removidas' );
$GLOBALS['uonix_test_logged_in'] = true;
uonix_rest_assert_same( array( '/wp/v2/posts' ), array_keys( uonix_security_filter_rest_user_endpoints( $caixa ) ), 'logado sem list_users: só a chave exata /wp/v2/users/me é preservada, e as de caixa diferente caem' );

// Anônimo DEPOIS de um logado, no mesmo processo: nenhuma chamada pode guardar a decisão da anterior.
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array();
$antes = uonix_security_filter_rest_user_endpoints( $routes );
uonix_rest_assert_same( true, array_key_exists( '/wp/v2/users/me', $antes ), 'sequência: logado mantém /users/me' );
$GLOBALS['uonix_test_logged_in'] = false;
$depois = uonix_security_filter_rest_user_endpoints( $routes );
uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/me', $depois ), 'sequência: o anônimo que vem depois do logado perde /users/me' );
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array( 'list_users' => true );
$cheio = uonix_security_filter_rest_user_endpoints( $routes );
uonix_rest_assert_same( true, array_key_exists( '/wp/v2/users', $cheio ), 'sequência: quem tem list_users depois do anônimo mantém a coleção' );

// Quem tem list_users (administrador): nada é removido, nem a coleção.
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array( 'list_users' => true );

foreach ( $orders as $label => $input ) {
	$allowed = uonix_security_filter_rest_user_endpoints( $input );

	uonix_rest_assert_same( uonix_rest_sorted_keys( $input ), uonix_rest_sorted_keys( $allowed ), "com list_users ({$label}): nenhuma rota é removida" );
	uonix_rest_assert_same( true, array_key_exists( '/wp/v2/users', $allowed ), "com list_users ({$label}): mantém a rota de usuários" );
}

// Entrada que não é lista de rotas volta como veio, sem erro.
foreach ( array( null, false, 'texto' ) as $not_a_list ) {
	$GLOBALS['uonix_test_logged_in'] = false;
	$GLOBALS['uonix_test_caps']      = array();
	uonix_rest_assert_same( $not_a_list, uonix_security_filter_rest_user_endpoints( $not_a_list ), sprintf( 'entrada %s volta como veio', var_export( $not_a_list, true ) ) );
}

// O módulo precisa estar realmente registrado, não apenas definido: no gancho certo, com o callback certo
// e na prioridade 1000 (depois de qualquer plugin que registre rotas de usuário com prioridade comum).
$registrados = array_values( array_filter( $GLOBALS['uonix_test_filters'], static function ( $f ) {
	return 'rest_endpoints' === $f[0];
} ) );
uonix_rest_assert_same( 1, count( $registrados ), 'registra o filtro rest_endpoints uma vez' );
uonix_rest_assert_same( 'uonix_security_filter_rest_user_endpoints', $registrados[0][1] ?? null, 'o callback registrado é o filtro, e não o ajudante da decisão' );
uonix_rest_assert_same( 1000, $registrados[0][2] ?? null, 'prioridade 1000, depois dos plugins que registram rotas de usuário' );

// E o carregador do módulo precisa incluir o arquivo, senão nada disso roda.
// Executado, e não lido como texto: uma linha comentada no module.php também casa numa busca de texto (#435).
require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
$carregados = uox_loader_arquivos_do_modulo( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-security/module.php' );
uonix_rest_assert_same( true, in_array( '07-rest-user-enumeration.php', $carregados, true ), 'module.php carrega o arquivo novo' );

if ( 0 !== $failures ) {
	fwrite( STDERR, sprintf( "%d falha(s).\n", $failures ) );
	exit( 1 );
}

printf( "PASS: REST não entrega enumeração de usuários a quem não pode listar, e mantém só o próprio usuário a quem está logado.\n" );
