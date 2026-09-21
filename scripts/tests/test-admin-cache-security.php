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
$GLOBALS['uox_test_transient_ttls']    = array();
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

/*
 * O dublê REGISTRA a expiração, e isso não é detalhe.
 *
 * Enquanto ele descartava o terceiro argumento, a propriedade "a janela expira" não
 * tinha cobertura alguma: trocar o TTL por 0 mantinha a suíte verde. E no WordPress
 * `set_transient( $k, $v, 0 )` significa transient SEM expiração — o botão viraria
 * trava permanente de uso único e nunca mais purgaria. Achado pela revisão
 * independente do PR #212, por mutação executada.
 */
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['uox_test_transients'][ $key ]      = $value;
	$GLOBALS['uox_test_transient_ttls'][ $key ]  = $expiration;
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
// Janela de tempo em que a purga acontece, para asserir o timestamp do lock por
// INTERVALO em vez de tolerância — exato, e imune a stall do processo.
$t_antes_da_purga = time();
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
		. 'editor recebe aviso de sucesso e continua vendo o HTML antigo'
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

/*
 * O TTL do lock tem de ser a janela do throttle, e NUNCA 0.
 *
 * `set_transient( $k, $v, 0 )` cria transient sem expiração: o throttle deixaria de
 * ser limite de frequência e viraria trava de uso único, com o botão nunca mais
 * purgando. Sem esta assertiva a regressão passa verde — foi medido.
 */
$ttl_lock = $GLOBALS['uox_test_transient_ttls']['uonix_cache_flush_lock'] ?? null;

uox_cache_security_assert(
	is_int( $ttl_lock ) && $ttl_lock > 0,
	'o lock de throttle precisa de TTL positivo; 0 significa transient sem expiração '
		. 'no WordPress e transformaria o botão em trava permanente de uso único'
);
uox_cache_security_assert(
	$ttl_lock === uox_cache_flush_throttle_seconds(),
	'o TTL do lock deve ser exatamente a janela de uox_cache_flush_throttle_seconds()'
);

/*
 * O valor guardado tem de ser o INSTANTE da purga, e a assertiva precisa provar isso.
 *
 * `is_int( $v ) && $v > 0` é satisfeito pelo literal 1 — o valor legado. Com 1, o
 * clamp de uox_cache_flush_remaining_seconds() absorve o resultado negativo e o aviso
 * imprime "Aguarde 1 segundo(s)" quando ainda faltam 60. Comparar com time() é o que
 * distingue timestamp de booleano. Achado pela revisão independente do PR #212, por
 * mutação executada.
 */
$valor_lock = $GLOBALS['uox_test_transients']['uonix_cache_flush_lock'];

uox_cache_security_assert(
	is_int( $valor_lock ) && $valor_lock >= $t_antes_da_purga && $valor_lock <= time(),
	'o lock deve guardar o TIMESTAMP da purga (≈ time()), não um booleano: com valor 1 o '
		. 'aviso diz "aguarde 1 segundo" enquanto a janela inteira ainda corre'
);

/*
 * E o tempo restante precisa ser exercitado numa janela PARCIALMENTE decorrida.
 *
 * O cenário acima grava time() e mede no mesmo segundo, então restante == janela por
 * construção — e um `return $janela;` no topo da função (o comportamento antigo, que
 * imprimia sempre a janela cheia) passaria pelo range check. Aqui a janela é envelhecida
 * à mão para que só a derivação real satisfaça a assertiva.
 */
$janela = uox_cache_flush_throttle_seconds();
$restante_esperado_parcial = 5;

/*
 * GUARDA DE VALIDADE dos dois cenários de tempo restante — este e o do aviso renderizado.
 *
 * Ambos envelhecem o lock para deixar ~5s e aceitam +/-2. Se a janela do throttle for
 * pequena, o esperado (~5) e a JANELA CHEIA caem os dois dentro da tolerância, e as duas
 * assertivas passariam por coincidência em vez de por derivação — inclusive contra a
 * mutação que devolve a janela inteira.
 *
 * Reescrever a faixa 3..7 como abs(x - 5) <= 2 NÃO desfez esse acoplamento: as duas
 * formas são matematicamente idênticas. É esta guarda, e só ela, que impede o
 * falso-verde. Ela vem ANTES do primeiro cenário de propósito: se falhar depois, a
 * primeira falha impressa aponta o cenário errado.
 */
uox_cache_security_assert(
	$janela > 7,
	sprintf(
		'a janela do throttle (%ds) ficou pequena demais para os cenários de tempo '
			. 'restante distinguirem ~%ds da janela cheia; ajuste os cenários junto com o default',
		$janela,
		$restante_esperado_parcial
	)
);
$decorridos = $janela - $restante_esperado_parcial;
$GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] = time() - $decorridos;
$restante_parcial = uox_cache_flush_remaining_seconds();

uox_cache_security_assert(
	abs( $restante_parcial - $restante_esperado_parcial ) <= 2,
	sprintf(
		'com %ds de %ds já decorridos o restante deve ser ~5s, não a janela cheia; obtido: %ds',
		$decorridos,
		$janela,
		$restante_parcial
	)
);

// Janela vencida: o clamp evita 0 e negativo, porque o aviso só aparece quando a purga
// FOI recusada e "aguarde 0 segundos" contradiria a recusa.
$GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] = time() - ( $janela * 2 );
uox_cache_security_assert(
	1 === uox_cache_flush_remaining_seconds(),
	'com a janela já vencida o restante deve ser clampado em 1, nunca 0 nem negativo'
);

// Transient ausente ou com valor não numérico: devolve a janela inteira, sem warning.
unset( $GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] );
uox_cache_security_assert(
	$janela === uox_cache_flush_remaining_seconds(),
	'sem lock registrado o restante deve ser a janela inteira'
);
$GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] = 'lixo';
uox_cache_security_assert(
	$janela === uox_cache_flush_remaining_seconds(),
	'com valor não numérico no lock o restante deve cair na janela inteira'
);

// Restaura o valor original por HIGIENE, não por dependência: o 'lixo' acima também é
// truthy para get_transient(), então o ramo do throttle dispararia igual. Registrado
// para que ninguém remova esta linha achando que os cenários seguintes a exigem — nem
// a mantenha achando que ela é o que os faz passar.
$GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] = $valor_lock;

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
	false !== strpos( $render_output, 'Cache do servidor limpo' ),
	'retorno com uonix_cache_flushed=1 deve renderizar o aviso de sucesso'
);
/*
 * O aviso não pode voltar a prometer mais do que o botão entrega. Ele limpa duas
 * camadas locais (objeto e página em disco) e NÃO toca a borda da Cloudflare, que
 * segue servindo HTML antigo por até ~1h. A versão anterior dizia "totalmente
 * limpa": o editor lia sucesso, continuava vendo conteúdo velho e concluía que a
 * ferramenta não funciona. Esta asserção existe para que a promessa só possa
 * voltar junto com uma purga de borda de verdade.
 */
uox_cache_security_assert(
	false === stripos( $render_output, 'totalmente' ),
	'o aviso de sucesso não pode alegar limpeza total enquanto a borda da Cloudflare não for purgada'
);
uox_cache_security_assert(
	false !== stripos( $render_output, 'Cloudflare' ),
	'o aviso de sucesso deve declarar que a borda não é limpa por aqui'
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
 * O AVISO RENDERIZADO no estado "aguarde" — a metade visível do throttle.
 *
 * Até aqui o teste conferia apenas a URL do redirect. O ramo do renderer que produz o
 * aviso não tinha cobertura alguma, e duas regressões passavam verdes:
 *
 *   1. apagar o `elseif ( 'aguarde' === ... )` inteiro — o editor é redirecionado e não
 *      recebe explicação nenhuma de por que nada aconteceu, contra o invariante que o
 *      próprio comentário do código declara ("aviso explícito, não silêncio");
 *   2. trocar uox_cache_flush_remaining_seconds() por uox_cache_flush_throttle_seconds()
 *      no printf — o aviso volta a imprimir a janela cheia, que é exatamente o que o
 *      docblock proíbe ("quem esperou 55s não pode ler aguarde 60 segundos").
 *
 * A lacuna anterior era de CALL SITE: a função estava coberta por chamada direta, o
 * consumidor dela não. Renderizar o estado mata as duas de uma vez. Achado pela
 * terceira revisão independente do PR #212, por mutação executada.
 */
$janela_aviso = uox_cache_flush_throttle_seconds();

$restante_esperado = 5;
$GLOBALS['uox_test_transients']['uonix_cache_flush_lock'] = time() - ( $janela_aviso - $restante_esperado );
$_GET  = array( 'uonix_cache_flushed' => 'aguarde' );
$_POST = array();
ob_start();
uox_render_manutencao_cache();
$aviso_aguarde = ob_get_clean();

$casou_aviso     = preg_match( '/Aguarde (\d+) segundo\(s\)/', $aviso_aguarde, $captura );
$segundos_no_aviso = 1 === $casou_aviso ? (int) $captura[1] : -1;

uox_cache_security_assert(
	1 === $casou_aviso,
	'o estado "aguarde" precisa RENDERIZAR o aviso: sem ele o editor é redirecionado e '
		. 'não recebe explicação nenhuma de por que a limpeza não aconteceu'
);
uox_cache_security_assert(
	abs( $segundos_no_aviso - $restante_esperado ) <= 2,
	sprintf(
		'com %ds de %ds decorridos o aviso deve exibir ~%ds, não a janela cheia; exibiu: %d',
		$janela_aviso - $restante_esperado,
		$janela_aviso,
		$restante_esperado,
		$segundos_no_aviso
	)
);
uox_cache_security_assert(
	false === strpos( $aviso_aguarde, 'Cache do servidor limpo' ),
	'o estado "aguarde" nunca pode renderizar o aviso de SUCESSO — dizer "limpou" sem '
		. 'ter limpado é o defeito que este handler existe para não cometer'
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
