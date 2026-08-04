<?php
/**
 * Testa o contrato do Turnstile na tela nativa de login do WordPress.
 *
 * O módulo compartilhado 34-turnstile-custom-forms.php já sabe renderizar e
 * validar o desafio. Este teste garante que o login:
 *
 * - realmente carrega o módulo dedicado;
 * - renderiza o widget com a action `wp_login`;
 * - valida antes da senha (prioridade 5 no filtro authenticate);
 * - bloqueia POST de wp-login.php quando o desafio falha;
 * - libera o fluxo quando o desafio passa;
 * - não intercepta GET, REST/CLI nem formulários de autenticação externos;
 * - mantém fail-open quando o Turnstile está desabilitado no ambiente.
 */

define( 'ABSPATH', __DIR__ );

$module_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/51-login-turnstile.php';
$admin_module_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/module.php';

if ( ! is_file( $module_file ) ) {
	fwrite( STDERR, "FAIL: módulo 51-login-turnstile.php ainda não existe.\n" );
	exit( 1 );
}

$GLOBALS['uonix_login_hooks'] = array();
$GLOBALS['uonix_turnstile_enabled'] = true;
$GLOBALS['uonix_turnstile_validation'] = true;
$GLOBALS['uonix_turnstile_render_calls'] = array();
$GLOBALS['uonix_turnstile_validate_calls'] = array();

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WP_User {}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_login_hooks'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => (int) $priority,
		'accepted_args' => (int) $accepted_args,
	);
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $hook, $callback, $priority, $accepted_args );
}

function uonix_turnstile_is_enabled() {
	return (bool) $GLOBALS['uonix_turnstile_enabled'];
}

function uonix_turnstile_render_widget( $action, $options = array() ) {
	$GLOBALS['uonix_turnstile_render_calls'][] = array( $action, $options );

	return sprintf(
		'<div class="uonix-turnstile-widget" data-action="%s"></div>',
		htmlspecialchars( $action, ENT_QUOTES, 'UTF-8' )
	);
}

function uonix_turnstile_validate_request( $expected_action = '' ) {
	$GLOBALS['uonix_turnstile_validate_calls'][] = $expected_action;

	return $GLOBALS['uonix_turnstile_validation'];
}

require_once $module_file;

$failures = 0;

function uonix_login_assert( $condition, $message ) {
	global $failures;

	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\n", $message ) );
		return;
	}

	printf( "ok   %s\n", $message );
}

function uonix_login_hook( $name ) {
	return $GLOBALS['uonix_login_hooks'][ $name ][0] ?? null;
}

function uonix_login_reset_runtime() {
	$GLOBALS['uonix_turnstile_enabled'] = true;
	$GLOBALS['uonix_turnstile_validation'] = true;
	$GLOBALS['uonix_turnstile_render_calls'] = array();
	$GLOBALS['uonix_turnstile_validate_calls'] = array();
	$GLOBALS['pagenow'] = 'wp-login.php';
	$_SERVER['REQUEST_METHOD'] = 'POST';
}

/*
 * Réplica da cadeia REAL de `authenticate` do WordPress.
 *
 * O restante deste arquivo chama o callback isolado, o que prova a lógica dele
 * mas NÃO prova o efeito no login. A diferença é decisiva: em
 * wp-includes/pluggable.php o core faz
 *
 *     $user = apply_filters( 'authenticate', null, $username, $password );
 *
 * e `wp_authenticate_username_password` (prioridade 20) só faz curto-circuito
 * quando recebe um WP_User. Recebendo um WP_Error, ele CONTINUA e devolve o
 * próprio resultado — sobrescrevendo o erro anterior.
 *
 * Medido em DEV com instrumentação, contra o site real:
 *     prio 6      code=uonix_login_turnstile   (nosso bloqueio existia)
 *     prio 10002  code=invalid_email           (foi descartado)
 *
 * Consequência: com credencial CORRETA o callback do core devolve um WP_User e
 * o desafio é ignorado — o login passa sem Turnstile. Validar antes da senha é
 * inútil; a validação precisa rodar DEPOIS da resolução do usuário.
 */
function uonix_login_apply_authenticate_chain( $username, $password, $resolved_user ) {
	$hooks = $GLOBALS['uonix_login_hooks']['authenticate'] ?? array();

	$chain = array();
	foreach ( $hooks as $hook ) {
		$chain[] = array( 'priority' => $hook['priority'], 'callback' => $hook['callback'] );
	}

	// Os callbacks nativos que importam para este contrato.
	$chain[] = array(
		'priority' => 20,
		'callback' => function ( $user, $u, $p ) use ( $resolved_user ) {
			// wp_authenticate_username_password: só devolve cedo com WP_User.
			if ( $user instanceof WP_User ) {
				return $user;
			}

			return $resolved_user;
		},
	);

	/*
	 * Elos POSTERIORES à nossa prioridade 30, para provar que o WP_Error do
	 * Turnstile sobrevive até o fim da cadeia — e não apenas até o elo seguinte.
	 *
	 * A revisão independente do PR #48 apontou que modelar só a prioridade 20
	 * prova fidelidade parcial: o teste não demonstrava sobrevivência até o
	 * último filtro. Ambos foram conferidos no core e no site real de DEV, onde a
	 * instrumentação mostrou loginizer em 10001/10002.
	 */
	$chain[] = array(
		'priority' => 99,
		'callback' => function ( $user ) {
			// wp_authenticate_spam_check: só age sobre WP_User; preserva WP_Error.
			return $user;
		},
	);

	$chain[] = array(
		'priority' => 10001,
		'callback' => function ( $user, $u, $p ) {
			// loginizer_wp_authenticate: repassa o resultado anterior.
			return $user;
		},
	);

	usort(
		$chain,
		static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		}
	);

	$user = null;
	foreach ( $chain as $link ) {
		$user = call_user_func( $link['callback'], $user, $username, $password );
	}

	return $user;
}

/* O loader precisa incluir o módulo, ou todo o resto do teste seria órfão. */
$admin_module_source = file_get_contents( $admin_module_file );
uonix_login_assert(
	false !== strpos( $admin_module_source, "'51-login-turnstile.php'" ),
	'uonix-admin/module.php carrega o módulo de Turnstile do login'
);

/*
 * Registro dos hooks.
 *
 * A prioridade tem de ser MAIOR que 20, onde o core registra
 * wp_authenticate_username_password. Abaixo disso o WP_Error do Turnstile é
 * sobrescrito pelo resultado do core e o desafio deixa de ter efeito.
 */
$render_hook = uonix_login_hook( 'login_form' );
$auth_hook = uonix_login_hook( 'authenticate' );

uonix_login_assert( null !== $render_hook, 'login_form registra o renderizador do Turnstile' );
uonix_login_assert( null !== $auth_hook, 'authenticate registra o validador do Turnstile' );
uonix_login_assert(
	( $auth_hook['priority'] ?? 0 ) > 20,
	'Turnstile valida depois da resolução do usuário, para o bloqueio não ser sobrescrito'
);
uonix_login_assert( 3 === ( $auth_hook['accepted_args'] ?? null ), 'authenticate aceita user, username e password' );
uonix_login_assert( ! isset( $GLOBALS['uonix_login_hooks']['register_form'] ), 'registro de usuário não exibe desafio sem validação correspondente' );
uonix_login_assert( ! isset( $GLOBALS['uonix_login_hooks']['lostpassword_form'] ), 'recuperação de senha não exibe desafio sem validação correspondente' );

/* Renderização ativa: widget compartilhado e action correta. */
uonix_login_reset_runtime();
ob_start();
call_user_func( $render_hook['callback'] );
$login_html = ob_get_clean();

uonix_login_assert( false !== strpos( $login_html, 'uonix-login-turnstile' ), 'login imprime wrapper próprio do desafio' );
uonix_login_assert( false !== strpos( $login_html, 'data-action="wp_login"' ), 'login usa a action wp_login' );
uonix_login_assert( 'wp_login' === ( $GLOBALS['uonix_turnstile_render_calls'][0][0] ?? null ), 'renderizador compartilhado recebe action wp_login' );

/* Fail-open quando o Turnstile está desabilitado no ambiente. */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_enabled'] = false;
ob_start();
call_user_func( $render_hook['callback'] );
$disabled_html = ob_get_clean();
uonix_login_assert( '' === $disabled_html, 'ambiente sem Turnstile configurado não imprime widget quebrado' );

/* POST do wp-login: falha do desafio bloqueia antes da autenticação. */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_empty', 'sem token' );
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha-incorreta' );
uonix_login_assert( is_wp_error( $result ), 'POST sem desafio válido é bloqueado' );
uonix_login_assert( 'uonix_login_turnstile' === $result->get_error_code(), 'erro de login não expõe detalhes internos da Cloudflare' );
uonix_login_assert( array( 'wp_login' ) === $GLOBALS['uonix_turnstile_validate_calls'], 'validação exige a action wp_login' );

/* Desafio válido libera o core para verificar usuário e senha. */
uonix_login_reset_runtime();
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
uonix_login_assert( null === $result, 'desafio válido preserva o fluxo normal de autenticação' );
uonix_login_assert( array( 'wp_login' ) === $GLOBALS['uonix_turnstile_validate_calls'], 'desafio válido também é verificado server-side' );

/* Não bloquear fluxos que não exibem o widget. */
uonix_login_reset_runtime();
$GLOBALS['pagenow'] = 'index.php';
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
uonix_login_assert( null === $result, 'formulário externo ao wp-login não é interceptado' );
uonix_login_assert( array() === $GLOBALS['uonix_turnstile_validate_calls'], 'formulário externo não chama a Cloudflare' );

uonix_login_reset_runtime();
$_SERVER['REQUEST_METHOD'] = 'GET';
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
uonix_login_assert( null === $result, 'GET do wp-login não é bloqueado' );
uonix_login_assert( array() === $GLOBALS['uonix_turnstile_validate_calls'], 'GET não chama a Cloudflare' );

uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_enabled'] = false;
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
uonix_login_assert( null === $result, 'Turnstile desabilitado mantém fail-open deliberado' );
uonix_login_assert( array() === $GLOBALS['uonix_turnstile_validate_calls'], 'fail-open não chama validador incompleto' );

/*
 * Indisponibilidade da Cloudflare NÃO pode trancar o administrador.
 * `uonix_turnstile_validate_request` devolve WP_Error tanto para token recusado
 * quanto para falha de transporte (timeout, egress bloqueado, Cloudflare fora).
 * Tratar os dois igual transformaria uma queda de terceiro em perda total de
 * acesso ao wp-admin, sem via de recuperação pelo navegador.
 */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_request_failed', 'sem rede' );
$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
uonix_login_assert( null === $result, 'falha de transporte até a Cloudflare mantém o login acessível' );

/* Recusas reais do desafio continuam bloqueando. */
foreach ( array( 'uonix_turnstile_empty', 'uonix_turnstile_failed', 'uonix_turnstile_action_mismatch' ) as $blocking_code ) {
	uonix_login_reset_runtime();
	$GLOBALS['uonix_turnstile_validation'] = new WP_Error( $blocking_code, 'recusado' );
	$result = call_user_func( $auth_hook['callback'], null, 'cassio', 'senha' );
	uonix_login_assert( is_wp_error( $result ), sprintf( 'recusa real do desafio (%s) bloqueia o login', $blocking_code ) );
}

/*
 * O TESTE QUE FALTAVA: efeito na cadeia real, não na função isolada.
 *
 * Este é o cenário que o site sofria de verdade — credencial CORRETA e nenhum
 * token. Se o desafio for validado antes da senha, o callback do core devolve um
 * WP_User e o login entra sem Turnstile.
 */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_empty', 'sem token' );
$valid_user = new WP_User();
$chain_result = uonix_login_apply_authenticate_chain( 'cassio', 'senha-correta', $valid_user );

uonix_login_assert(
	is_wp_error( $chain_result ),
	'credencial VÁLIDA sem desafio é recusada na cadeia real de authenticate'
);
uonix_login_assert(
	is_wp_error( $chain_result ) && 'uonix_login_turnstile' === $chain_result->get_error_code(),
	'o erro que sobrevive à cadeia é o do Turnstile, não o do core'
);

/* Credencial válida COM desafio resolvido continua autenticando. */
uonix_login_reset_runtime();
$chain_ok = uonix_login_apply_authenticate_chain( 'cassio', 'senha-correta', $valid_user );
uonix_login_assert(
	$chain_ok instanceof WP_User,
	'credencial válida com desafio resolvido autentica normalmente'
);

/* Falha de transporte não pode trancar o administrador nem na cadeia real. */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_request_failed', 'sem rede' );
$chain_transport = uonix_login_apply_authenticate_chain( 'cassio', 'senha-correta', $valid_user );
uonix_login_assert(
	$chain_transport instanceof WP_User,
	'Cloudflare inacessível não impede login legítimo na cadeia real'
);

/*
 * SENHA ERRADA sem token também tem de ser barrada pelo desafio.
 *
 * Este é o caso de força bruta: o atacante erra a senha por definição. Um
 * early-return em is_wp_error( $user ) fazia o desafio ser pulado justamente
 * nessas requisições, cobrando Turnstile só de quem já acertou a credencial —
 * o inverso do propósito. Achado da revisão independente do PR #48.
 */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_empty', 'sem token' );
$wrong_password_error = new WP_Error( 'incorrect_password', 'senha errada' );
$brute = uonix_login_apply_authenticate_chain( 'cassio', 'senha-errada', $wrong_password_error );

uonix_login_assert(
	is_wp_error( $brute ) && 'uonix_login_turnstile' === $brute->get_error_code(),
	'senha errada sem desafio é barrada pelo Turnstile, não pelo erro do core'
);
uonix_login_assert(
	array( 'wp_login' ) === $GLOBALS['uonix_turnstile_validate_calls'],
	'tentativa de força bruta é submetida ao desafio'
);

/*
 * Erro anterior de outro filtro NÃO isenta do desafio no formulário nativo.
 *
 * Antes o módulo devolvia o erro anterior sem validar. Isso é uma isenção
 * controlada por terceiro: bastaria qualquer plugin registrado antes da
 * prioridade 30 devolver WP_Error para desligar o Turnstile — e é o que o core
 * já faz naturalmente com senha errada.
 *
 * O desafio continua sendo exigido; o que se perde é apenas a mensagem original,
 * substituída pela do desafio. Aceitável: sem token válido a tentativa é
 * inválida de qualquer modo, e revelar menos sobre a causa reduz enumeração.
 */
uonix_login_reset_runtime();
$GLOBALS['uonix_turnstile_validation'] = new WP_Error( 'uonix_turnstile_empty', 'sem token' );
$previous_error = new WP_Error( 'other_plugin', 'erro anterior' );
$result = call_user_func( $auth_hook['callback'], $previous_error, 'cassio', 'senha' );
uonix_login_assert(
	is_wp_error( $result ) && 'uonix_login_turnstile' === $result->get_error_code(),
	'erro de terceiro não serve como isenção do desafio'
);

/* Com o desafio resolvido, o erro do outro filtro é preservado. */
uonix_login_reset_runtime();
$result = call_user_func( $auth_hook['callback'], $previous_error, 'cassio', 'senha' );
uonix_login_assert( $previous_error === $result, 'desafio válido preserva o erro anterior da cadeia' );

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: Turnstile protege o formulário nativo de login antes da senha.\n" );
