<?php
/**
 * Testa que os callbacks de hook do WordPress sobrevivem ao despacho REAL do core.
 *
 * MOTIVAÇÃO (T62A): a versão nova do 38-integracoes-analytics-lgpd.php parou de
 * emitir GTM e AdOpt em produção, sem erro em log e com php -l limpo. A causa não
 * era plugin nem cache: o WordPress, em do_action( 'wp_head' ) SEM argumentos,
 * executa `if ( empty( $arg ) ) { $arg[] = ''; }` e depois, como accepted_args
 * vale 1 por padrão, chama o callback com UM argumento — a STRING VAZIA ''.
 *
 * O callback era `uonix_render_analytics_head( $configuration = null )` e decidia
 * auto-resolver a config com `null === $configuration`. Recebendo '' em vez de
 * null, a guarda não disparava, $configuration seguia '' e o
 * `! is_array( $configuration ) return;` abortava a função em silêncio.
 *
 * POR QUE O test-analytics-policy.php NÃO PEGOU: aquele arquivo substitui
 * add_action() por um stub que apenas REGISTRA o callback num array e nunca o
 * despacha. Ele exercita as funções por chamada direta, onde o default null
 * realmente vale. O bug só existe no caminho de despacho do core.
 *
 * Este teste fecha essa lacuna: implementa a MESMA semântica de argumentos do
 * WP_Hook::apply_filters() e dispara os hooks de verdade.
 */

define( 'UONIX_ENV',               'production' );
define( 'UONIX_ANALYTICS_ENABLED', true );
define( 'UONIX_GTM_CONTAINER_ID',  'GTM-TESTE123' );
define( 'UONIX_ADOPT_WEBSITE_ID',  'adopt-teste-id' );
define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_hooks'] = array();

/**
 * Réplica fiel da semântica de registro do WordPress.
 *
 * Assinatura idêntica à do core, incluindo o default accepted_args = 1 — que é
 * exatamente o detalhe que originou o bug.
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_hooks'][ $hook ][ $priority ][] = array(
		'function'      => $callback,
		'accepted_args' => (int) $accepted_args,
	);
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $hook, $callback, $priority, $accepted_args );
}

/**
 * Réplica da semântica de DESPACHO do core: wp-includes/plugin.php do_action()
 * combinado com WP_Hook::apply_filters().
 *
 * Reproduz os dois pontos que importam:
 *   1. do_action() sem argumentos monta $args = array( '' )  — string vazia;
 *   2. accepted_args >= num_args  -> call_user_func_array( $cb, $args );
 *      accepted_args === 0        -> call_user_func( $cb )  sem argumento algum.
 */
function uonix_do_action( $hook, ...$arg ) {
	if ( ! isset( $GLOBALS['uonix_hooks'][ $hook ] ) ) {
		return;
	}

	// plugin.php: if ( empty( $arg ) ) { $arg[] = ''; }
	if ( empty( $arg ) ) {
		$arg[] = '';
	}

	$num_args   = count( $arg );
	$priorities = $GLOBALS['uonix_hooks'][ $hook ];
	ksort( $priorities, SORT_NUMERIC );

	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $the_ ) {
			// class-wp-hook.php linhas ~338-343
			if ( 0 === $the_['accepted_args'] ) {
				call_user_func( $the_['function'] );
			} elseif ( $the_['accepted_args'] >= $num_args ) {
				call_user_func_array( $the_['function'], $arg );
			} else {
				call_user_func_array( $the_['function'], array_slice( $arg, 0, $the_['accepted_args'] ) );
			}
		}
	}
}

function is_admin()      { return false; }
function is_feed()       { return false; }
function is_embed()      { return false; }
function is_front_page() { return true; }
function home_url( $path = '' ) { return 'https://uonix.com.br' . $path; }
function esc_url( $value )  { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php';

$failures = 0;

function uonix_hook_assert( $condition, $message ) {
	global $failures;

	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\n", $message ) );
		return;
	}

	printf( "ok   %s\n", $message );
}

/*
 * ------------------------------------------------------------------
 * 1. O DESPACHO REAL PRECISA EMITIR — este é o caso que regrediu.
 * ------------------------------------------------------------------
 */
ob_start();
uonix_do_action( 'wp_head' );
$head = ob_get_clean();

uonix_hook_assert(
	false !== strpos( $head, 'GTM-TESTE123' ),
	'do_action( wp_head ) sem argumentos emite o container GTM'
);
uonix_hook_assert(
	false !== strpos( $head, 'adopt-website-id' ),
	'do_action( wp_head ) sem argumentos emite o meta da AdOpt'
);
uonix_hook_assert(
	false !== strpos( $head, 'adopt-teste-id' ),
	'do_action( wp_head ) usa o website id vindo da constante, não hardcoded'
);

ob_start();
uonix_do_action( 'wp_body_open' );
$body = ob_get_clean();

uonix_hook_assert(
	false !== strpos( $body, 'GTM-TESTE123' ),
	'do_action( wp_body_open ) sem argumentos emite o noscript do GTM'
);

/*
 * ------------------------------------------------------------------
 * 2. O FAIL-CLOSED PRECISA CONTINUAR VALENDO.
 *    Uma "correção" que só afrouxa a guarda passaria no bloco 1 e falharia aqui.
 * ------------------------------------------------------------------
 */
foreach ( array( 'false' => false, 'string vazia' => '', 'array vazio' => array() ) as $rotulo => $invalida ) {
	ob_start();
	uonix_render_analytics_head( $invalida );
	uonix_render_analytics_body( $invalida );
	$saida = ob_get_clean();

	uonix_hook_assert(
		'' === $saida,
		sprintf( 'config explícita inválida (%s) não injeta nada, mesmo em produção', $rotulo )
	);
}

// Config parcial não habilita injeção pela metade.
foreach ( array(
	'só GTM'   => array( 'gtm_container_id' => 'GTM-X' ),
	'só AdOpt' => array( 'adopt_website_id' => 'y' ),
) as $rotulo => $parcial ) {
	ob_start();
	uonix_render_analytics_head( $parcial );
	$saida = ob_get_clean();

	uonix_hook_assert(
		'' === $saida,
		sprintf( 'config parcial (%s) não injeta integração incompleta', $rotulo )
	);
}

/*
 * ------------------------------------------------------------------
 * 3. GUARDA ESTRUTURAL: todo callback registrado num hook que o WP dispara
 *    SEM argumentos precisa ser imune ao '' do core.
 *
 *    Aceitamos duas formas seguras:
 *      a) accepted_args === 0  (o core chama sem argumento algum);
 *      b) o callback não declara nenhum parâmetro.
 *
 *    Assim o mesmo defeito não volta em outro arquivo.
 * ------------------------------------------------------------------
 */
$hooks_sem_argumentos = array(
	'wp_head',
	'wp_footer',
	'wp_body_open',
	'init',
	'admin_init',
	'admin_notices',
	'wp_enqueue_scripts',
	'admin_enqueue_scripts',
	'template_redirect',
	'wp_loaded',
	'after_setup_theme',
	'widgets_init',
	'admin_menu',
	'shutdown',
);

foreach ( $hooks_sem_argumentos as $hook ) {
	if ( ! isset( $GLOBALS['uonix_hooks'][ $hook ] ) ) {
		continue;
	}

	foreach ( $GLOBALS['uonix_hooks'][ $hook ] as $priority => $callbacks ) {
		foreach ( $callbacks as $the_ ) {
			if ( 0 === $the_['accepted_args'] ) {
				continue; // forma (a): seguro por registro
			}

			$reflection = is_string( $the_['function'] ) && function_exists( $the_['function'] )
				? new ReflectionFunction( $the_['function'] )
				: ( $the_['function'] instanceof Closure ? new ReflectionFunction( $the_['function'] ) : null );

			if ( null === $reflection ) {
				continue;
			}

			if ( 0 === $reflection->getNumberOfParameters() ) {
				continue; // forma (b): seguro por assinatura
			}

			$nome = is_string( $the_['function'] ) ? $the_['function'] . '()' : 'closure';

			uonix_hook_assert(
				false,
				sprintf(
					'%s registrado em %s (prioridade %s) declara parâmetro opcional e accepted_args=%d: '
					. 'o core vai entregar a string vazia. Registre com accepted_args=0.',
					$nome,
					$hook,
					$priority,
					$the_['accepted_args']
				)
			);
		}
	}
}

// Varredura estrutural do projeto inteiro: nenhum callback registrado em
// add_action() pode declarar parâmetro opcional sem accepted_args=0.
//
// MOTIVAÇÃO (auditoria de 167 hooks, 2026-08-01): os callbacks de
// uonix-security/06-environment-indexing.php usam o default null como sinal de
// controle — o MESMO antipadrão do 38 — e hoje são imunes apenas porque estão
// registrados em add_filter(), e apply_filters() nunca injeta string vazia. Se
// algum dia forem reaproveitados num add_action(), o defeito reaparece
// silenciosamente. Esta varredura transforma essa ressalva em falha de CI.
$acoes_com_default = array();

foreach ( glob( __DIR__ . '/../../mu-plugins/uonix-*/*.php' ) as $arquivo ) {
	$fonte = file_get_contents( $arquivo );

	// Captura add_action( 'hook', 'callback_nomeado' ) sem accepted_args=0.
	// Aceita aspas simples OU duplas nos dois primeiros argumentos: o WordPress
	// não impõe estilo e um arquivo com aspas duplas escaparia da varredura.
	if ( ! preg_match_all(
		'/add_action\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([a-z0-9_]+)[\'"]\s*(?:,\s*([0-9]+)\s*)?(?:,\s*([0-9]+)\s*)?\)/i',
		$fonte,
		$registros,
		PREG_SET_ORDER
	) ) {
		continue;
	}

	foreach ( $registros as $registro ) {
		$callback      = $registro[2];
		$accepted_args = isset( $registro[4] ) && '' !== $registro[4] ? (int) $registro[4] : 1;

		if ( 0 === $accepted_args ) {
			continue; // já protegido
		}

		// A função declara parâmetro com default?
		if ( ! preg_match(
			'/function\s+' . preg_quote( $callback, '/' ) . '\s*\(([^)]*)\)/i',
			$fonte,
			$assinatura
		) ) {
			continue; // definida em outro arquivo; coberto pelo despacho acima
		}

		if ( false === strpos( $assinatura[1], '=' ) ) {
			continue; // sem parâmetro opcional: seguro
		}

		$acoes_com_default[] = sprintf(
			'%s: %s() em add_action(%s) com accepted_args=%d e parâmetro opcional',
			basename( $arquivo ),
			$callback,
			$registro[1],
			$accepted_args
		);
	}
}

uonix_hook_assert(
	array() === $acoes_com_default,
	"nenhum add_action() registra callback com parâmetro opcional sem accepted_args=0\n         "
		. implode( "\n         ", $acoes_com_default )
);

if ( 0 !== $failures ) {
	fwrite( STDERR, sprintf( "\n%d assercao(oes) falharam.\n", $failures ) );
	exit( 1 );
}

printf( "\nPASS: callbacks de hook sobrevivem ao despacho real do core e seguem fail-closed.\n" );
