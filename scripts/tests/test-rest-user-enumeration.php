<?php
/**
 * Testes do bloqueio de enumeração de usuários pela REST API.
 *
 * O gate do Turnstile cobre o formulário nativo de login e o XML-RPC está
 * desligado, mas /wp-json/wp/v2/users respondia 200 para qualquer anônimo e
 * entregava os slugs reais de login. Isso dá metade do trabalho de um ataque de
 * força bruta de graça: o atacante já sabe QUEM atacar.
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

foreach ( array( 'uonix_security_rest_hides_users', 'uonix_security_filter_rest_user_endpoints' ) as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, sprintf( "FAIL: %s() ainda não existe.\n", $required_function ) );
		exit( 1 );
	}
}

/*
 * Um anônimo não pode listar usuários. É este o caso que estava aberto em
 * DEV, QA e produção, entregando slug, nome e id de três contas reais.
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
 * Quem tem list_users precisa manter o endpoint: é o que o próprio wp-admin
 * consome para montar telas de autor, e bloquear quebraria o editor.
 */
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array( 'list_users' => true );
uonix_rest_assert_same( false, uonix_security_rest_hides_users(), 'quem tem list_users mantém o endpoint' );

// O filtro remove as rotas de usuário, e apenas elas.
$GLOBALS['uonix_test_logged_in'] = false;
$GLOBALS['uonix_test_caps']      = array();

$routes = array(
	'/wp/v2/users'                 => array( 'stub' ),
	'/wp/v2/users/(?P<id>[\d]+)'   => array( 'stub' ),
	'/wp/v2/posts'                 => array( 'stub' ),
	'/wp/v2/media'                 => array( 'stub' ),
	'/'                            => array( 'stub' ),
);

$filtered = uonix_security_filter_rest_user_endpoints( $routes );

uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users', $filtered ), 'rota de coleção de usuários é removida' );
uonix_rest_assert_same( false, array_key_exists( '/wp/v2/users/(?P<id>[\d]+)', $filtered ), 'rota de usuário único é removida' );
uonix_rest_assert_same( true, array_key_exists( '/wp/v2/posts', $filtered ), 'rota de posts é preservada' );
uonix_rest_assert_same( true, array_key_exists( '/wp/v2/media', $filtered ), 'rota de mídia é preservada' );
uonix_rest_assert_same( true, array_key_exists( '/', $filtered ), 'índice da REST é preservado' );

// Com capacidade, nada é removido.
$GLOBALS['uonix_test_logged_in'] = true;
$GLOBALS['uonix_test_caps']      = array( 'list_users' => true );

$allowed = uonix_security_filter_rest_user_endpoints( $routes );
uonix_rest_assert_same( true, array_key_exists( '/wp/v2/users', $allowed ), 'administrador mantém a rota de usuários' );
uonix_rest_assert_same( count( $routes ), count( $allowed ), 'nenhuma rota é removida de quem pode listar' );

// O módulo precisa estar realmente registrado, não apenas definido.
$registered = array_column( $GLOBALS['uonix_test_filters'], 0 );
uonix_rest_assert_same( true, in_array( 'rest_endpoints', $registered, true ), 'registra o filtro rest_endpoints' );

// E o carregador do módulo precisa incluir o arquivo, senão nada disso roda.
$module = file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-security/module.php' );
uonix_rest_assert_same( true, false !== strpos( $module, '07-rest-user-enumeration.php' ), 'module.php carrega o arquivo novo' );

if ( 0 !== $failures ) {
	fwrite( STDERR, sprintf( "%d falha(s).\n", $failures ) );
	exit( 1 );
}

printf( "PASS: REST não entrega enumeração de usuários a quem não pode listar.\n" );
