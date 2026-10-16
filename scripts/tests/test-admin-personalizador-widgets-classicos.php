<?php
/**
 * Teste: Personalizador com widgets clássicos.
 *
 * MOTIVAÇÃO: com o editor de blocos nos widgets, customize.php pede cerca de
 * 475 arquivos e a produção responde 429 a dezenas deles, inclusive
 * customize-controls.min.js. O Personalizador abre em branco.
 *
 * O teste falha se o Personalizador voltar a usar blocos, se a tela de
 * widgets fora dele perder os blocos ou passar a forçá-los, ou se o módulo
 * sair do loader.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_filters']   = array();
$GLOBALS['uox_test_customize'] = false;

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function is_customize_preview() {
	return $GLOBALS['uox_test_customize'];
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/69-admin-personalizador-widgets-classicos.php';

$failures = 0;

function uox_pwc_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_pwc_assert( false !== strpos( $loader, "'69-admin-personalizador-widgets-classicos.php'" ), 'module.php deve carregar 69-admin-personalizador-widgets-classicos.php' );

$registrado = $GLOBALS['uox_test_filters']['use_widgets_block_editor'][0] ?? null;
uox_pwc_assert( null !== $registrado && 'uonix_admin_personalizador_widgets_classicos' === $registrado[0], 'filtro use_widgets_block_editor não registrado' );

// Dentro do Personalizador (controles, prévia e customize_save): sempre clássico.
$GLOBALS['uox_test_customize'] = true;
uox_pwc_assert( false === uonix_admin_personalizador_widgets_classicos( true ), 'no Personalizador, o editor de blocos deveria ser desligado' );
uox_pwc_assert( false === uonix_admin_personalizador_widgets_classicos( false ), 'no Personalizador, o modo clássico deveria se manter' );

// Fora dele (widgets.php, REST): a decisão anterior passa intacta.
$GLOBALS['uox_test_customize'] = false;
uox_pwc_assert( true === uonix_admin_personalizador_widgets_classicos( true ), 'fora do Personalizador, os blocos deveriam continuar' );
uox_pwc_assert( false === uonix_admin_personalizador_widgets_classicos( false ), 'fora do Personalizador, o filtro não deveria forçar blocos' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: Personalizador com widgets clássicos.\n";
