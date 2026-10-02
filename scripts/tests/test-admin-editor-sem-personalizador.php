<?php
/**
 * Teste: perfil editor sem acesso ao Personalizador.
 *
 * MOTIVAÇÃO (issue #388): o editor tem edit_theme_options, que o núcleo aceita
 * como `customize`, e o `customize_save` não confere a área gravada. Um pedido
 * montado à mão esvaziaria o megamenu.
 *
 * O teste falha se `customize` voltar a passar para quem não administra, se o
 * administrador perder o Personalizador, se outra capability for afetada, ou
 * se o módulo sair do loader (ou o arquivo 60, substituído, voltar a ele).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$GLOBALS['uox_test_filters'] = array();
$GLOBALS['uox_test_users']   = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function user_can( $user_id, $cap ) {
	return in_array( $cap, $GLOBALS['uox_test_users'][ $user_id ] ?? array(), true );
}

$raiz = dirname( __DIR__, 2 );
require_once $raiz . '/mu-plugins/uonix-admin/66-admin-editor-sem-personalizador.php';

$failures = 0;

function uox_sp_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$GLOBALS['uox_test_users'] = array(
	1 => array( 'manage_options', 'edit_theme_options' ),
	2 => array( 'edit_theme_options', 'edit_pages' ),
	3 => array( 'edit_posts' ),
);

// Loader: 66 entra, 60 (seções do rodapé no Personalizador) saiu.
$loader = (string) file_get_contents( $raiz . '/mu-plugins/uonix-admin/module.php' );
uox_sp_assert( false !== strpos( $loader, "'66-admin-editor-sem-personalizador.php'" ), 'module.php deve carregar 66-admin-editor-sem-personalizador.php' );
uox_sp_assert( false === strpos( $loader, '60-admin-customizer-widgets-rodape.php' ), 'module.php não deve mais carregar o 60' );
uox_sp_assert( ! file_exists( $raiz . '/mu-plugins/uonix-admin/60-admin-customizer-widgets-rodape.php' ), 'arquivo 60 deveria ter sido removido' );

$registrado = $GLOBALS['uox_test_filters']['map_meta_cap'][0] ?? null;
uox_sp_assert( null !== $registrado && 'uonix_admin_editor_sem_personalizador' === $registrado[0], 'filtro map_meta_cap não registrado' );
uox_sp_assert( null !== $registrado && 3 === $registrado[2], 'filtro precisa receber o user_id (3 argumentos)' );

// Editor e quem só edita posts: customize negado.
foreach ( array( 2, 3 ) as $usuario ) {
	uox_sp_assert( array( 'do_not_allow' ) === uonix_admin_editor_sem_personalizador( array( 'edit_theme_options' ), 'customize', $usuario ), "usuário {$usuario}: customize deveria ser negado" );
}

// Administrador mantém o Personalizador.
uox_sp_assert( array( 'edit_theme_options' ) === uonix_admin_editor_sem_personalizador( array( 'edit_theme_options' ), 'customize', 1 ), 'administrador mantém customize' );

// Outras capabilities do editor seguem intactas, inclusive a do Editar Rodapé.
foreach ( array( 'edit_theme_options', 'edit_post', 'edit_pages', 'manage_options' ) as $cap ) {
	uox_sp_assert( array( 'x' ) === uonix_admin_editor_sem_personalizador( array( 'x' ), $cap, 2 ), "{$cap} não deveria ser alterada" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: só quem administra abre o Personalizador.\n";
