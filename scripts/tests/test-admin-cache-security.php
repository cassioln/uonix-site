<?php
/**
 * Teste comportamental da limpeza de cache no dashboard administrativo.
 *
 * O endpoint não pode produzir efeito colateral em uma requisição GET, mesmo
 * que alguém acrescente uox_flush_action=run à URL.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_cache_flushes']     = 0;
$GLOBALS['uox_test_page_cache_clears'] = 0;
$GLOBALS['uox_test_transients']        = array();
$GLOBALS['uox_test_can_edit']      = true;
$GLOBALS['uox_test_nonce_checks']  = 0;
$GLOBALS['uox_test_nonce_valid']   = true;
$GLOBALS['uox_test_actions']       = array();
$GLOBALS['uox_test_redirect']      = '';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_actions'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
	);
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function wp_cache_flush() {
	++$GLOBALS['uox_test_cache_flushes'];
	return true;
}

/**
 * Dublê do WP Super Cache.
 *
 * wp_cache_flush() cobre apenas o cache de OBJETO. O HTML que o editor quer
 * atualizar vive no cache de PÁGINA, que o perfil Simple deste site grava em
 * disco com wp_cache_object_cache = 0. Sem este dublê, o teste não distinguiria
 * "limpou tudo" de "limpou só metade" — foi assim que a chamada órfã do WP Rocket
 * sobreviveu meses guardada por function_exists, sempre verde e sem efeito.
 */
function wp_cache_clear_cache( $blog_id = 0 ) {
	++$GLOBALS['uox_test_page_cache_clears'];
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_transient( $key ) {
	return $GLOBALS['uox_test_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['uox_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['uox_test_transients'][ $key ] );
	return true;
}

function current_user_can( $capability ) {
	return 'edit_posts' === $capability && $GLOBALS['uox_test_can_edit'];
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'WP_DIE: ' . $message );
}

function wp_safe_redirect( $url ) {
	$GLOBALS['uox_test_redirect'] = $url;
	throw new RuntimeException( 'REDIRECT' );
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="fixture" />';
}

function check_admin_referer( $action ) {
	++$GLOBALS['uox_test_nonce_checks'];
	if ( ! $GLOBALS['uox_test_nonce_valid'] ) {
		throw new RuntimeException( 'WP_DIE: nonce inválido' );
	}
	return true;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function admin_url( $path = '' ) {
	return 'https://uonix.com.br/wp-admin/' . $path;
}

function add_query_arg( $key, $value = '', $url = '' ) {
	return (string) $url . '?' . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php';

$failures = 0;

function uox_cache_security_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$flush_handler = null;
foreach ( $GLOBALS['uox_test_actions'] as $registered_action ) {
	if ( 'admin_post_uonix_flush_cache' === $registered_action['hook'] ) {
		$flush_handler = $registered_action['callback'];
		break;
	}
}

uox_cache_security_assert(
	is_callable( $flush_handler ),
	'limpeza de cache deve registrar o handler admin_post_uonix_flush_cache'
);

$_GET  = array( 'uox_flush_action' => 'run' );
$_POST = array();
ob_start();
uox_render_manutencao_cache();
$get_output = ob_get_clean();

uox_cache_security_assert(
	0 === $GLOBALS['uox_test_cache_flushes'],
	'GET com uox_flush_action=run não pode limpar o cache'
);
uox_cache_security_assert(
	0 === $GLOBALS['uox_test_page_cache_clears'],
	'GET com uox_flush_action=run não pode limpar o cache de página'
);
uox_cache_security_assert(
	1 !== preg_match( '#href="[^"]*[?&]uox_flush_action=run#', $get_output ),
	'GET não deve renderizar um link mutante de limpeza'
);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array(
	'action'   => 'uonix_flush_cache',
	'_wpnonce' => 'fixture',
);
$_POST = array();
$get_handler_blocked = false;
try {
	if ( is_callable( $flush_handler ) ) {
		call_user_func( $flush_handler );
	}
} catch ( RuntimeException $exception ) {
	$get_handler_blocked = false !== strpos( $exception->getMessage(), 'WP_DIE' );
}

uox_cache_security_assert( $get_handler_blocked, 'handler deve rejeitar GET mesmo com action e nonce válidos' );
uox_cache_security_assert(
	0 === $GLOBALS['uox_test_cache_flushes'],
	'GET no admin-post.php não pode limpar o cache'
);
uox_cache_security_assert(
	0 === $GLOBALS['uox_test_page_cache_clears'],
	'GET no admin-post.php não pode limpar o cache de página'
);
uox_cache_security_assert(
	0 === $GLOBALS['uox_test_nonce_checks'],
	'GET deve ser rejeitado antes da validação do nonce'
);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = array();
$_POST = array(
	'action'  => 'uonix_flush_cache',
	'_wpnonce' => 'fixture',
);
$redirected = false;
if ( is_callable( $flush_handler ) ) {
	try {
		call_user_func( $flush_handler );
	} catch ( RuntimeException $exception ) {
		$redirected = 'REDIRECT' === $exception->getMessage();
	}
}

uox_cache_security_assert( $redirected, 'POST autorizado deve redirecionar ao dashboard' );
uox_cache_security_assert(
	'https://uonix.com.br/wp-admin/index.php?uonix_cache_flushed=1' === $GLOBALS['uox_test_redirect'],
	'POST autorizado deve redirecionar com uonix_cache_flushed=1'
);
uox_cache_security_assert(
	1 === $GLOBALS['uox_test_cache_flushes'],
	'POST autorizado deve limpar o cache exatamente uma vez'
);
uox_cache_security_assert(
	1 === $GLOBALS['uox_test_page_cache_clears'],
	'POST autorizado deve limpar o cache de PÁGINA exatamente uma vez — sem isso o '
		. 'editor recebe "cache totalmente limpa" e continua vendo o HTML antigo'
);
uox_cache_security_assert(
	1 === $GLOBALS['uox_test_nonce_checks'],
	'POST autorizado deve validar o nonce antes da limpeza'
);

/*
 * Throttle: a purga de página esfria o site inteiro, então dois cliques seguidos
 * não podem purgar duas vezes. E o segundo clique não pode dizer "limpou" — seria
 * o mesmo defeito que este handler acabou de corrigir, em nova forma.
 */
uox_cache_security_assert(
	isset( $GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] ),
	'purga bem-sucedida deve registrar o lock de throttle'
);

$nonce_checks_antes_do_throttle = $GLOBALS['uox_test_nonce_checks'];
$GLOBALS['uox_test_redirect'] = '';
try {
	call_user_func( $flush_handler );
} catch ( RuntimeException $exception ) {
	// redirect esperado
}

uox_cache_security_assert(
	'https://uonix.com.br/wp-admin/index.php?uonix_cache_flushed=aguarde' === $GLOBALS['uox_test_redirect'],
	'segundo POST na janela do throttle deve redirecionar com estado "aguarde", nunca como sucesso'
);
uox_cache_security_assert(
	1 === $GLOBALS['uox_test_cache_flushes'] && 1 === $GLOBALS['uox_test_page_cache_clears'],
	'segundo POST na janela do throttle não pode purgar nenhuma das duas camadas'
);
uox_cache_security_assert(
	$GLOBALS['uox_test_nonce_checks'] === $nonce_checks_antes_do_throttle + 1,
	'o throttle deve agir DEPOIS do nonce: gravar transient é efeito colateral e não '
		. 'pode acontecer antes da autorização, nem a janela ser sondável sem nonce'
);

// A janela expira: o throttle é limite de frequência, não trava de uso único.
delete_transient( 'uonix_cache_flush_lock' );
$GLOBALS['uox_test_redirect'] = '';
try {
	call_user_func( $flush_handler );
} catch ( RuntimeException $exception ) {
	// redirect esperado
}

uox_cache_security_assert(
	2 === $GLOBALS['uox_test_cache_flushes'] && 2 === $GLOBALS['uox_test_page_cache_clears'],
	'expirada a janela, um novo POST autorizado deve purgar as duas camadas de novo'
);
uox_cache_security_assert(
	'https://uonix.com.br/wp-admin/index.php?uonix_cache_flushed=1' === $GLOBALS['uox_test_redirect'],
	'purga após a janela deve voltar a redirecionar como sucesso'
);

$GLOBALS['uox_test_nonce_valid'] = false;
$invalid_nonce_blocked = false;
try {
	if ( is_callable( $flush_handler ) ) {
		call_user_func( $flush_handler );
	}
} catch ( RuntimeException $exception ) {
	$invalid_nonce_blocked = false !== strpos( $exception->getMessage(), 'WP_DIE' );
}

uox_cache_security_assert( $invalid_nonce_blocked, 'POST com nonce inválido deve ser bloqueado' );
uox_cache_security_assert(
	2 === $GLOBALS['uox_test_cache_flushes'],
	'POST com nonce inválido não pode limpar o cache'
);
uox_cache_security_assert(
	2 === $GLOBALS['uox_test_page_cache_clears'],
	'POST com nonce inválido não pode limpar o cache de página'
);
uox_cache_security_assert(
	4 === $GLOBALS['uox_test_nonce_checks'],
	'POST com nonce inválido deve ser rejeitado pelo verificador'
);

$GLOBALS['uox_test_nonce_valid'] = true;
$GLOBALS['uox_test_can_edit'] = false;
$blocked = false;
try {
	if ( is_callable( $flush_handler ) ) {
		call_user_func( $flush_handler );
	}
} catch ( RuntimeException $exception ) {
	$blocked = false !== strpos( $exception->getMessage(), 'WP_DIE' );
}

uox_cache_security_assert( $blocked, 'POST sem edit_posts deve ser bloqueado' );
uox_cache_security_assert(
	2 === $GLOBALS['uox_test_cache_flushes'],
	'POST sem edit_posts não pode limpar o cache'
);
uox_cache_security_assert(
	2 === $GLOBALS['uox_test_page_cache_clears'],
	'POST sem edit_posts não pode limpar o cache de página'
);
uox_cache_security_assert(
	4 === $GLOBALS['uox_test_nonce_checks'],
	'POST sem edit_posts deve ser rejeitado antes da validação do nonce'
);

$GLOBALS['uox_test_can_edit'] = true;
$GLOBALS['uox_test_nonce_valid'] = true;
$_GET  = array( 'uonix_cache_flushed' => '1' );
$_POST = array();
ob_start();
uox_render_manutencao_cache();
$render_output = ob_get_clean();

uox_cache_security_assert(
	false !== strpos( $render_output, 'A memória cache do site foi totalmente limpa' ),
	'retorno com uonix_cache_flushed=1 deve renderizar o aviso de sucesso'
);
uox_cache_security_assert(
	false !== strpos( $render_output, '<form method="post" action="https://uonix.com.br/wp-admin/admin-post.php">' ),
	'controle de limpeza deve apontar para admin-post.php via POST'
);
uox_cache_security_assert(
	false !== strpos( $render_output, 'name="action" value="uonix_flush_cache"' ),
	'formulário deve conter a action oculta do handler'
);

/*
 * Asserção ESTRUTURAL: a purga de página tem de ser guardada por function_exists.
 *
 * Os dublês acima definem wp_cache_clear_cache(), então o teste comportamental
 * passaria mesmo se a chamada fosse nua. Mas o WP Super Cache é instalado somente
 * pelo deploy de produção: em QA, DEV e local a função não existe, e uma chamada
 * nua daria fatal no admin-post.php — justamente onde um editor clica.
 */
$dashboard_source = file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php' );

uox_cache_security_assert(
	is_string( $dashboard_source ) && '' !== $dashboard_source,
	'não consegui ler 39-admin-editor-dashboard.php para a asserção estrutural'
);
uox_cache_security_assert(
	is_string( $dashboard_source )
		&& 1 === preg_match(
			'/function_exists\(\s*[\'"]wp_cache_clear_cache[\'"]\s*\)/',
			$dashboard_source
		),
	'a purga de cache de página precisa estar guardada por function_exists( "wp_cache_clear_cache" ): '
		. 'sem o guard, o botão dá fatal em QA, DEV e local, onde o WP Super Cache não é instalado'
);

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: limpeza de cache protegida por POST, nonce e capability\n";
