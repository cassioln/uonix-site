<?php
/**
 * Gera o HTML real das "Atualizações Sistêmicas" para um cenário, para o teste de
 * navegador (scripts/tests/test-admin-atualizacoes-sistemicas-navegador.mjs).
 *
 * Uso: php atualizacoes-sistemicas-cenario.php <cenario>
 *
 * O bloco sai dentro de <div id="moldura">, cuja largura o cenário define e o
 * teste pode alterar (estreitar o card sem disparar resize da janela).
 */

declare( strict_types=1 );

require_once __DIR__ . '/atualizacoes-sistemicas-stubs.php';

// N plugins: múltiplos de 3 com salto de major (alta), os demais de patch (baixa).
function uox_cenario_plugins( $quantidade, $nome_longo_em = 0 ) {
	$resposta   = array();
	$instalados = array();
	for ( $n = 1; $n <= $quantidade; $n++ ) {
		$arquivo                = sprintf( 'p%02d/p%02d.php', $n, $n );
		$resposta[ $arquivo ]   = (object) array( 'new_version' => 0 === $n % 3 ? '2.0.0' : '1.0.1' );
		$instalados[ $arquivo ] = array(
			'Name'    => $nome_longo_em === $n ? 'Plugin com um nome comercial bem comprido que quebra em várias linhas no card estreito' : sprintf( 'Plugin %02d', $n ),
			'Version' => '1.0.0',
		);
	}
	$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => $resposta );
	$GLOBALS['uox_test_plugins']                           = $instalados;
}

function uox_cenario_nucleo() {
	$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
		'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '6.9' ) ),
	);
}

function uox_cenario_tema() {
	$GLOBALS['uox_test_site_transients']['update_themes'] = (object) array(
		'response' => array( 'kadence' => array( 'new_version' => '1.3.0' ) ),
	);
	$GLOBALS['uox_test_themes'] = array( 'kadence' => array( 'Name' => 'Kadence', 'Version' => '1.2.0' ) );
}

$cenario = $argv[1] ?? '';
$largura = 520;
$fechado = false;

switch ( $cenario ) {
	case 'vazio':
		break;
	case 'sete': // 1 núcleo + 5 plugins + 1 tema: uma página só.
		uox_cenario_nucleo();
		uox_cenario_plugins( 5 );
		uox_cenario_tema();
		break;
	case 'paginado': // 1 + 25 + 1 = 27: Todos e Plugins com 3 páginas.
	case 'fechado':  // O mesmo, com o widget recolhido (display:none) no carregamento.
		uox_cenario_nucleo();
		uox_cenario_plugins( 25 );
		uox_cenario_tema();
		$fechado = 'fechado' === $cenario;
		break;
	case 'longo': // O 10º item da página 1 de Plugins (Plugin 08) quebra em várias linhas.
		uox_cenario_nucleo();
		uox_cenario_plugins( 12, 8 );
		$largura = 400;
		break;
	case 'longo-p2': // O nome longo (Plugin 10) cai na página 2 de Todos e de Plugins:
		// a página mais alta não é uma página 1.
		uox_cenario_nucleo();
		uox_cenario_plugins( 25, 10 );
		$largura = 400;
		break;
	case 'so-plugins': // Uma categoria só: sem a aba "Todos".
		uox_cenario_plugins( 12 );
		break;
	default:
		fwrite( STDERR, "cenário desconhecido: {$cenario}\n" );
		exit( 2 );
}

echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>' . esc_html( $cenario ) . '</title></head>';
echo '<body style="margin:20px; font-family: Arial, sans-serif;">';
printf( '<div id="moldura" style="width:%dpx;%s">', $largura, $fechado ? ' display:none;' : '' );
uox_render_lista_atualizacoes_pendentes();
echo '</div></body></html>';
