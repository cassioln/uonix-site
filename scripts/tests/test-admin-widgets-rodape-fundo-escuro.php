<?php
/**
 * Teste: áreas do rodapé no editor de widgets com o fundo escuro do rodapé.
 *
 * MOTIVAÇÃO (relato de 2026-10-01): em Aparência > Widgets as áreas do rodapé
 * têm fundo branco e o conteúdo do rodapé é branco, então ele fica invisível.
 *
 * O teste falha se o CSS não carregar em widgets.php, se carregar em qualquer
 * outra tela (incluindo nomes que contenham "widgets"), se mirar algo além das
 * áreas footer1 a footer6, ou se o módulo sair da lista que o `module.php` do
 * admin executa (linha comentada ou em código morto não conta: #362).
 *
 * A forma do CSS é conferida regra por regra: o fundo, o texto (`color:#fff` em
 * h1 a h6 e links) e o botão têm cada um as seis áreas, e a regra de texto traz as
 * três exclusões (formulário do widget legado, caixa "sem prévia" dele e
 * placeholders) como `X *`, dentro de `:where()` para não subir a especificidade.
 * O que o teste NÃO mede é a cor renderizada: isso foi medido no Chrome com o CSS
 * do núcleo, fora da suíte (corpo do PR #465).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_actions']  = array();
$GLOBALS['uox_test_styles']   = array();
$GLOBALS['uox_test_enqueued'] = array();
$GLOBALS['uox_test_inline']   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function wp_register_style( $handle, $src, $deps = array(), $ver = false ) {
	$GLOBALS['uox_test_styles'][ $handle ] = $src;
	return true;
}

function wp_enqueue_style( $handle ) {
	$GLOBALS['uox_test_enqueued'][] = $handle;
}

function wp_add_inline_style( $handle, $data ) {
	$GLOBALS['uox_test_inline'][ $handle ][] = $data;
	return true;
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/61-admin-widgets-rodape-fundo-escuro.php';

$failures = 0;

function uox_wr_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_wr_reset() {
	$GLOBALS['uox_test_styles']   = array();
	$GLOBALS['uox_test_enqueued'] = array();
	$GLOBALS['uox_test_inline']   = array();
}

require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
$carregados = uox_loader_arquivos_do_modulo( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_wr_assert( in_array( '61-admin-widgets-rodape-fundo-escuro.php', $carregados, true ), 'módulo 61 não está na lista que o module.php de uonix-admin executa (comentado ou removido)' );

$registrado = $GLOBALS['uox_test_actions']['admin_enqueue_scripts'][0] ?? null;
uox_wr_assert( null !== $registrado, 'ação admin_enqueue_scripts não registrada' );
uox_wr_assert( null !== $registrado && 'uonix_admin_widgets_rodape_fundo_escuro' === $registrado[0], 'callback registrado é outro' );

// Outras telas do admin, inclusive o Personalizador e o editor de posts: nada carrega.
foreach ( array( 'index.php', 'customize.php', 'post.php', 'nav-menus.php', '', 'appearance_page_widgets-extra', 'widgets', 'widgets.php?x=1', 'WIDGETS.PHP', 'settings_page_widgets.php', 'tools_page_my-widgets' ) as $tela ) {
	uox_wr_reset();
	uonix_admin_widgets_rodape_fundo_escuro( $tela );
	uox_wr_assert( array() === $GLOBALS['uox_test_enqueued'] && array() === $GLOBALS['uox_test_inline'], "tela {$tela}: não deveria carregar CSS" );
}

// Aparência > Widgets: um estilo só de inline, com o CSS das áreas do rodapé.
uox_wr_reset();
uonix_admin_widgets_rodape_fundo_escuro( 'widgets.php' );
$handle = 'uonix-admin-widgets-rodape';
uox_wr_assert( array_key_exists( $handle, $GLOBALS['uox_test_styles'] ) && false === $GLOBALS['uox_test_styles'][ $handle ], 'estilo inline-only não registrado' );
uox_wr_assert( array( $handle ) === $GLOBALS['uox_test_enqueued'], 'estilo não enfileirado em widgets.php' );
$css = implode( '', $GLOBALS['uox_test_inline'][ $handle ] ?? array() );
uox_wr_assert( false !== strpos( $css, '#1a202c' ), 'CSS sem a cor de fundo do rodapé' );

// Toda regra mira só as áreas footer1 a footer6, nunca as barras laterais nem o mega menu.
$seletores = array();
preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $seletores );
uox_wr_assert( count( $seletores[1] ) > 0, 'CSS sem regras' );

// #361: nenhuma regra usa o prefixo ^="footer" (pegaria footer-cta de outro plugin); a
// área é a lista explícita footer1..footer6.
uox_wr_assert( false === strpos( $css, '^="footer"' ), 'o prefixo ^="footer" pegaria uma área futura de outro plugin: use a lista footer1..footer6' );
foreach ( array( 'footer1', 'footer2', 'footer3', 'footer4', 'footer5', 'footer6' ) as $area ) {
	uox_wr_assert( false !== strpos( $css, '[data-widget-area-id="' . $area . '"]' ), "a área {$area} deveria estar na lista explícita" );
}
foreach ( array( 'footer', 'footer0', 'footer7', 'footer-cta', 'sidebar-1', 'mega-menu' ) as $fora ) {
	uox_wr_assert( false === strpos( $css, '[data-widget-area-id="' . $fora . '"]' ), "a área {$fora} não deveria receber o fundo escuro" );
}

// #361: o formulário do widget legado, a caixa "sem prévia" dele e os placeholders têm fundo
// claro próprio e título/link escuros. A regra de títulos e links não pode pintá-los de branco
// (branco sobre branco). Conferido pela FORMA de cada regra, não por texto solto no CSS
// (revisão do #465, M1): cada regra de texto, fundo e botão tem as seis áreas, e a de texto
// traz as três exclusões como `X *` (o ` *` exclui os descendentes, não só o próprio elemento).
function uox_wr_seletores_topo( $lista ) {
	$partes = array();
	$atual  = '';
	$nivel  = 0;
	foreach ( str_split( $lista ) as $c ) {
		if ( '(' === $c ) {
			++$nivel;
		} elseif ( ')' === $c ) {
			--$nivel;
		}
		if ( ',' === $c && 0 === $nivel ) {
			$partes[] = trim( $atual );
			$atual    = '';
			continue;
		}
		$atual .= $c;
	}
	$partes[] = trim( $atual );
	return array_filter( $partes, 'strlen' );
}

foreach ( $seletores[1] as $seletor ) {
	foreach ( uox_wr_seletores_topo( $seletor ) as $parte ) {
		uox_wr_assert( 1 === preg_match( '/^\.wp-block-widget-area__inner-blocks\[data-widget-area-id="footer[1-6]"\]/', $parte ), "regra fora das áreas footer1 a footer6: {$parte}" );
	}
}

$regras = array();
preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $regras, PREG_SET_ORDER );
$n_texto = 0;
$n_fundo = 0;
$n_botao = 0;
foreach ( $regras as $regra ) {
	foreach ( uox_wr_seletores_topo( $regra[1] ) as $parte ) {
		if ( false !== strpos( $regra[2], 'background-color' ) ) {
			++$n_fundo;
		}
		if ( false !== strpos( $parte, '.block-editor-button-block-appender' ) ) {
			++$n_botao;
		}
		if ( false === strpos( $parte, ':where(h1' ) ) {
			continue;
		}
		++$n_texto;
		uox_wr_assert( 'color:#fff' === $regra[2], 'a regra de títulos e links deixou de ser color:#fff' );
		uox_wr_assert( false !== strpos( $parte, ':where(h1,h2,h3,h4,h5,h6,a:' ), "regra de texto sem h1 a h6 e links: {$parte}" );
		foreach ( array( 'wp-block-legacy-widget__edit-form', 'wp-block-legacy-widget__edit-no-preview', 'components-placeholder' ) as $classe ) {
			uox_wr_assert( 1 === preg_match( '/:not\(\.' . preg_quote( $classe, '/' ) . ' \*\)/', $parte ), "a regra de texto não exclui {$classe} (com ` *`): {$parte}" );
		}
		// A exclusão fica dentro de :where(): a especificidade segue a da área, (0,2,0).
		uox_wr_assert( 1 === preg_match( '/\]\s+:where\(h1[^{]*\):where\(:not\(/', $parte ), "a exclusão deveria estar dentro de :where() para não subir a especificidade: {$parte}" );
	}
}
uox_wr_assert( 6 === $n_texto && 6 === $n_fundo && 6 === $n_botao, "cada regra deveria ter as 6 áreas (texto {$n_texto}, fundo {$n_fundo}, botão {$n_botao})" );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: áreas do rodapé com fundo escuro só no editor de widgets.\n";
