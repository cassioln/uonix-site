<?php
/**
 * Teste: widgets do rodapé visíveis no Personalizador para o papel editor.
 *
 * MOTIVAÇÃO (relato de 2026-10-01): logado como editor, o lápis do rodapé na
 * prévia do Personalizador não abria nada; como administrador, abria. O Kadence
 * põe as seções `sidebar-widgets-footerN` no painel `kadence_customizer_footer`,
 * que exige `manage_options`. O editor tem só `edit_theme_options`, então o
 * painel some e leva a seção junto.
 *
 * O teste falha se a seção do rodapé continuar presa ao painel do Kadence para
 * quem não tem a capability do tema, se ela sair do painel para quem tem, ou se
 * o filtro mexer em seções que não são do rodapé.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_filters']   = array();
$GLOBALS['uox_test_caps']      = array();
$GLOBALS['uox_test_theme_cap'] = 'manage_options';

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	if ( 'kadence_theme_customizer_capability' === $hook ) {
		return $GLOBALS['uox_test_theme_cap'];
	}
	return $value;
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['uox_test_caps'], true );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/60-admin-customizer-widgets-rodape.php';

$failures = 0;

function uox_rodape_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_rodape_secao( $panel ) {
	return array(
		'title' => 'Footer 4',
		'panel' => $panel,
	);
}

function uox_rodape_filtrar( $args ) {
	return uonix_admin_footer_widgets_section_args( $args, 'sidebar-widgets-footer4', 'footer4' );
}

$editor = array( 'edit_theme_options', 'customize', 'edit_posts' );
$admin  = array_merge( $editor, array( 'manage_options' ) );

// Registro: depois do Kadence (prioridade 10) e recebendo os três argumentos.
$registrado = $GLOBALS['uox_test_filters']['customizer_widgets_section_args'][0] ?? null;
uox_rodape_assert( null !== $registrado, 'filtro customizer_widgets_section_args não registrado' );
uox_rodape_assert( null !== $registrado && 'uonix_admin_footer_widgets_section_args' === $registrado[0], 'callback registrado é outro' );
uox_rodape_assert( null !== $registrado && $registrado[1] > 10, 'prioridade precisa ser maior que a do Kadence (10)' );
uox_rodape_assert( null !== $registrado && 3 === $registrado[2], 'filtro precisa aceitar 3 argumentos' );

// Editor, sem a capability do tema: a seção volta ao painel Widgets do núcleo.
$GLOBALS['uox_test_caps'] = $editor;
$saida                    = uox_rodape_filtrar( uox_rodape_secao( 'kadence_customizer_footer' ) );
uox_rodape_assert( 'widgets' === $saida['panel'], 'editor: seção do rodapé deveria ir para o painel widgets' );
uox_rodape_assert( 'Footer 4' === $saida['title'], 'editor: demais argumentos da seção foram alterados' );

// Administrador: o painel do Kadence é preservado.
$GLOBALS['uox_test_caps'] = $admin;
$saida                    = uox_rodape_filtrar( uox_rodape_secao( 'kadence_customizer_footer' ) );
uox_rodape_assert( 'kadence_customizer_footer' === $saida['panel'], 'admin: seção deveria continuar no painel do Kadence' );

// Seções fora do painel de rodapé do Kadence não são tocadas.
$GLOBALS['uox_test_caps'] = $editor;
foreach ( array( 'kadence_customizer_header', 'widgets', 'outro_painel' ) as $painel ) {
	$saida = uox_rodape_filtrar( uox_rodape_secao( $painel ) );
	uox_rodape_assert( $painel === $saida['panel'], "editor: painel {$painel} não deveria mudar" );
}
$sem_painel = uox_rodape_filtrar( array( 'title' => 'Sidebar' ) );
uox_rodape_assert( ! isset( $sem_painel['panel'] ), 'seção sem painel não deveria ganhar painel' );
uox_rodape_assert( 'x' === uonix_admin_footer_widgets_section_args( 'x' ), 'valor que não é array deveria passar intacto' );

// Se o site reduzir a capability do tema, o editor volta a ver o painel do Kadence.
$GLOBALS['uox_test_theme_cap'] = 'edit_theme_options';
$saida                         = uox_rodape_filtrar( uox_rodape_secao( 'kadence_customizer_footer' ) );
uox_rodape_assert( 'kadence_customizer_footer' === $saida['panel'], 'capability do tema reduzida: seção deveria ficar no painel do Kadence' );
$GLOBALS['uox_test_theme_cap'] = 'manage_options';

// Com o tema carregado, a capability vem de \Kadence\Theme_Customizer::get_capability().
eval( 'namespace Kadence; class Theme_Customizer { public static $cap = "edit_theme_options"; public static function get_capability() { return self::$cap; } }' );
$saida = uox_rodape_filtrar( uox_rodape_secao( 'kadence_customizer_footer' ) );
uox_rodape_assert( 'kadence_customizer_footer' === $saida['panel'], 'tema carregado: deveria usar a capability do Kadence, não o padrão' );
\Kadence\Theme_Customizer::$cap = 'manage_options';
$saida                          = uox_rodape_filtrar( uox_rodape_secao( 'kadence_customizer_footer' ) );
uox_rodape_assert( 'widgets' === $saida['panel'], 'tema carregado com manage_options: editor deveria ir para widgets' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: widgets do rodapé no Personalizador para quem não administra o tema.\n";
