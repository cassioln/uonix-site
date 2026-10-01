<?php
/**
 * Teste: instâncias de widget de bloco sem `content` não quebram o editor de widgets.
 *
 * MOTIVAÇÃO (relato de 2026-10-01): em Aparência > Widgets todas as áreas
 * apareciam vazias e salvar dava "Cannot read properties of undefined (reading
 * 'map')". O Max Mega Menu cria widgets "Bloco" só com as chaves dele; o editor
 * passa `content` indefinido ao parser e a carga de todos os widgets aborta.
 *
 * O teste falha se a leitura de `widget_block` não completar `content` vazio
 * nessas instâncias, se mexer em instâncias que já têm `content`, ou se tocar
 * em valores que não são instâncias.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-navigation/50-mega-menu-widget-bloco.php';

$failures = 0;

function uox_wb_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$registrado = $GLOBALS['uox_test_filters']['option_widget_block'][0] ?? null;
uox_wb_assert( null !== $registrado, 'filtro option_widget_block não registrado' );
uox_wb_assert( null !== $registrado && 'uonix_navigation_widget_block_content_padrao' === $registrado[0], 'callback registrado é outro' );

// Estado medido em produção, QA e local em 2026-10-01 (block-87, 88, 89 e 93).
$opcao = array(
	87             => array(
		'content'                  => '[uonix_vitrine_marcas]',
		'mega_menu_is_grid_widget' => 'true',
	),
	88             => array( 'mega_menu_is_grid_widget' => 'true' ),
	89             => array( 'mega_menu_is_grid_widget' => 'true' ),
	93             => array(
		'mega_menu_columns'        => 2,
		'mega_menu_parent_menu_id' => 8848,
		'mega_menu_order'          => array( 8848 => 2 ),
	),
	156            => array( 'content' => '' ),
	'_multiwidget' => 1,
);

$saida = uonix_navigation_widget_block_content_padrao( $opcao );

foreach ( array( 88, 89, 93 ) as $n ) {
	uox_wb_assert( isset( $saida[ $n ] ) && array_key_exists( 'content', $saida[ $n ] ), "block-{$n}: deveria ganhar content" );
	uox_wb_assert( isset( $saida[ $n ]['content'] ) && '' === $saida[ $n ]['content'], "block-{$n}: content deveria ser string vazia" );
	foreach ( $opcao[ $n ] as $chave => $valor ) {
		uox_wb_assert( isset( $saida[ $n ][ $chave ] ) && $valor === $saida[ $n ][ $chave ], "block-{$n}: chave {$chave} do Max Mega Menu foi alterada" );
	}
}

uox_wb_assert( $opcao[87] === $saida[87], 'block-87: instância com content não deveria mudar' );
uox_wb_assert( $opcao[156] === $saida[156], 'block-156: content vazio existente não deveria mudar' );
uox_wb_assert( 1 === $saida['_multiwidget'], '_multiwidget não deveria mudar' );
uox_wb_assert( array_keys( $opcao ) === array_keys( $saida ), 'ordem ou conjunto de chaves da opção mudou' );

uox_wb_assert( false === uonix_navigation_widget_block_content_padrao( false ), 'opção inexistente (false) deveria passar intacta' );
uox_wb_assert( 'x' === uonix_navigation_widget_block_content_padrao( 'x' ), 'valor que não é array deveria passar intacto' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: widgets de bloco sem content recebem content vazio na leitura.\n";
