<?php
/**
 * Guarda do loader dos módulos: todo arquivo NN-*.php de cada mu-plugins/<módulo>/ é
 * carregado de verdade, e nenhum teste confere o carregamento lendo o texto do module.php (#435).
 *
 * Por que existe: uma linha comentada no module.php (`// '69-admin-...php',`) também casa numa
 * busca de texto. Na revisão do #434 o revisor comentou a linha do 69 numa cópia e o teste saiu
 * verde com exit 0, sem o arquivo ser carregado: em produção o Personalizador voltaria a abrir
 * em branco (429 da Locaweb) com a CI verde. Medido em 2026-11-17: 17 dos 21 testes que afirmam
 * "o module.php carrega X" passavam com a linha comentada.
 *
 * Duas partes, as duas EXECUTADAS, não lidas como texto:
 *
 *  1. Órfãos: executa cada module.php com o gravador de lib-loader-modulo.php e exige que todo
 *     arquivo NN-*.php do diretório esteja na lista que o loader mandaria carregar. Cobre, de
 *     uma vez, o que cada teste isolado só cobria para o seu próprio módulo. Um arquivo novo que
 *     o autor esqueça de registrar (ou uma linha comentada de propósito) reprova aqui.
 *     Exceções são declaradas por nome, com motivo, e uma exceção que já está carregada reprova
 *     (senão ela apodrece em silêncio).
 *
 *  2. Texto: nenhum scripts/tests/test-*.php lê um module.php com file_get_contents(). O caminho
 *     certo é uox_loader_arquivos_do_modulo(), de fixtures/lib-loader-modulo.php.
 *
 * Os dois controles negativos rodam aqui mesmo, numa cópia: a linha comentada reprova a parte 1,
 * e um teste que volta a ler o module.php como texto reprova a parte 2.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
// uonix-local/module.php só carrega fora de produção: 'local' o faz listar o mailpit.php.
define( 'UONIX_ENV', 'local' );

$raiz      = dirname( __DIR__, 2 );
$failures  = 0;
$asserts   = 0;

function uox_lc_assert( $condition, $message ) {
	global $failures, $asserts;
	++$asserts;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

require_once __DIR__ . '/fixtures/lib-loader-modulo.php';

/*
 * Arquivos que existem no diretório do módulo, têm nome NN-*.php e DE PROPÓSITO não entram no
 * loader. Hoje: nenhum. Cada exceção precisa de um motivo escrito, e a parte 1 reprova uma
 * exceção que o loader já carrega.
 */
$excecoes = array();

/**
 * Lista os arquivos NN-*.php de um módulo que o loader NÃO manda carregar.
 *
 * @param string   $dir_modulo Diretório do módulo.
 * @param string[] $carregados Lista devolvida pelo loader executado.
 * @return string[]
 */
function uox_lc_orfaos( $dir_modulo, array $carregados ) {
	$no_disco = array_map( 'basename', glob( $dir_modulo . '/[0-9][0-9]-*.php' ) ?: array() );
	sort( $no_disco );
	return array_values( array_diff( $no_disco, $carregados ) );
}

/**
 * Órfãos de um módulo depois de descontar as exceções declaradas.
 *
 * @param string   $dir_modulo Diretório do módulo.
 * @param string[] $carregados Lista devolvida pelo loader executado.
 * @param array    $excecoes   Arquivos que ficam fora de propósito, com o motivo.
 * @return string[]
 */
function uox_lc_orfaos_com_excecoes( $dir_modulo, array $carregados, array $excecoes ) {
	return array_values( array_diff( uox_lc_orfaos( $dir_modulo, $carregados ), array_keys( $excecoes ) ) );
}

/**
 * Exceções que o loader JÁ carrega: a exceção deixou de ser necessária e precisa sair da lista.
 *
 * @param string[] $carregados Lista devolvida pelo loader executado.
 * @param array    $excecoes   Arquivos declarados como fora do loader.
 * @return string[]
 */
function uox_lc_excecoes_ja_carregadas( array $carregados, array $excecoes ) {
	return array_values( array_intersect( array_keys( $excecoes ), $carregados ) );
}

// ---------------------------------------------------------------------------
// 1. Todo NN-*.php de cada módulo é carregado pelo module.php EXECUTADO.
// ---------------------------------------------------------------------------
$modulos = glob( $raiz . '/mu-plugins/*/module.php' ) ?: array();
sort( $modulos );
uox_lc_assert( count( $modulos ) >= 10, 'esperava ao menos 10 module.php em mu-plugins/*/ (achou ' . count( $modulos ) . ')' );

foreach ( $modulos as $module_php ) {
	$dir  = dirname( $module_php );
	$nome = basename( $dir );

	// uonix-shared não chama uonix_mu_require_files(): dá require_once direto em environment.php
	// e helpers.php. O helper acusa de propósito; o arquivo não tem NN-*.php para conferir.
	if ( 'uonix-shared' === $nome ) {
		uox_lc_assert( array() === ( glob( $dir . '/[0-9][0-9]-*.php' ) ?: array() ), "{$nome} não deveria ter arquivos NN-*.php (o loader dele não passa por uonix_mu_require_files)" );
		continue;
	}

	try {
		$carregados = uox_loader_arquivos_do_modulo( $module_php );
	} catch ( RuntimeException $e ) {
		uox_lc_assert( false, "{$nome}/module.php: " . $e->getMessage() );
		continue;
	}

	// Entrada pendurada: o loader lista um arquivo que não existe (ou não é legível). O loader real só registra
	// `error_log` e segue, então em produção vira ruído por requisição, e o gravador do helper o descarta: sem esta
	// checagem, recolocar uma entrada de um arquivo removido (o 60, por exemplo) passava sem ninguém ver (#483, revisão).
	$ausentes = uox_loader_entradas_ausentes();
	uox_lc_assert( array() === $ausentes, "{$nome}/module.php lista arquivo que não existe: " . implode( ', ', $ausentes ) );

	$orfaos = uox_lc_orfaos_com_excecoes( $dir, $carregados, $excecoes[ $nome ] ?? array() );
	uox_lc_assert( array() === $orfaos, "{$nome}/module.php não carrega: " . implode( ', ', $orfaos ) . ' (linha comentada ou esquecida?)' );

	uox_lc_assert( count( $carregados ) === count( array_unique( $carregados ) ), "{$nome}/module.php lista algum arquivo duas vezes" );

	foreach ( uox_lc_excecoes_ja_carregadas( $carregados, $excecoes[ $nome ] ?? array() ) as $excecao ) {
		uox_lc_assert( false, "exceção {$nome}/{$excecao} já é carregada pelo loader: remova da lista de exceções" );
	}
}

// ---------------------------------------------------------------------------
// 2. Nenhum teste lê um module.php como texto.
// ---------------------------------------------------------------------------
/**
 * Testes que leem um module.php com file_get_contents() (a forma que aceita linha comentada).
 *
 * @param string $dir_testes Diretório com os test-*.php.
 * @return string[] nomes dos arquivos
 */
function uox_lc_leitores_de_texto( $dir_testes ) {
	$achados = array();
	foreach ( glob( $dir_testes . '/test-*.php' ) ?: array() as $teste ) {
		if ( basename( $teste ) === basename( __FILE__ ) ) {
			continue;
		}
		$codigo = (string) file_get_contents( $teste );

		// 1. Lê o module.php direto: file_get_contents( ...module.php ).
		$le_direto = 1 === preg_match( '/file_get_contents\s*\([^;]*module\.php/', $codigo );

		// 2. Lê por variável: `$x = ...module.php;` e depois file_get_contents( $x ). Só atribuir o caminho
		//    não basta para acusar: o teste pode passá-lo ao helper, que é o caminho certo.
		$le_por_variavel = false;
		if ( preg_match_all( '/\$(\w+)\s*=[^;]*module\.php/', $codigo, $atribuicoes ) ) {
			foreach ( array_unique( $atribuicoes[1] ) as $variavel ) {
				if ( 1 === preg_match( '/file_get_contents\s*\([^;]*\$' . preg_quote( $variavel, '/' ) . '\b/', $codigo ) ) {
					$le_por_variavel = true;
					break;
				}
			}
		}

		if ( $le_direto || $le_por_variavel ) {
			$achados[] = basename( $teste );
		}
	}
	sort( $achados );
	return $achados;
}

$leitores = uox_lc_leitores_de_texto( __DIR__ );
uox_lc_assert( array() === $leitores, 'testes que leem o module.php como texto (use uox_loader_arquivos_do_modulo): ' . implode( ', ', $leitores ) );

// ---------------------------------------------------------------------------
// 3. Controles negativos, numa cópia: o guarda não pode ser um guarda que nunca reprova.
// ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/uox-loader-completo-' . getmypid();
@mkdir( $tmp . '/mod', 0777, true );
@mkdir( $tmp . '/testes', 0777, true );
file_put_contents( $tmp . '/mod/01-a.php', '<?php' );
file_put_contents( $tmp . '/mod/02-b.php', '<?php' );
file_put_contents( $tmp . '/mod/module-ok.php', "<?php\nuonix_mu_require_files( __DIR__, array( '01-a.php', '02-b.php' ), 'x' );\n" );
file_put_contents( $tmp . '/mod/module-comentado.php', "<?php\nuonix_mu_require_files( __DIR__, array(\n\t'01-a.php',\n\t// '02-b.php',\n), 'x' );\n" );
file_put_contents( $tmp . '/mod/module-esquecido.php', "<?php\nuonix_mu_require_files( __DIR__, array( '01-a.php' ), 'x' );\n" );

$ok         = uox_loader_arquivos_do_modulo( $tmp . '/mod/module-ok.php' );
$comentado  = uox_loader_arquivos_do_modulo( $tmp . '/mod/module-comentado.php' );
$esquecido  = uox_loader_arquivos_do_modulo( $tmp . '/mod/module-esquecido.php' );
uox_lc_assert( array() === uox_lc_orfaos( $tmp . '/mod', $ok ), 'controle: loader completo não tem órfãos' );
uox_lc_assert( array( '02-b.php' ) === uox_lc_orfaos( $tmp . '/mod', $comentado ), 'controle: linha COMENTADA vira órfão (a busca de texto a aceitaria)' );
uox_lc_assert( array( '02-b.php' ) === uox_lc_orfaos( $tmp . '/mod', $esquecido ), 'controle: arquivo esquecido no loader vira órfão' );

file_put_contents( $tmp . '/testes/test-texto-direto.php', "<?php\n\$loader = file_get_contents( \$raiz . '/mu-plugins/uonix-admin/module.php' );\n" );
file_put_contents( $tmp . '/testes/test-texto-variavel.php', "<?php\n\$loader_path = \$root . '/mu-plugins/uonix-navigation/module.php';\n\$loader = file_get_contents( \$loader_path );\n" );
file_put_contents( $tmp . '/testes/test-executado.php', "<?php\n\$carregados = uox_loader_arquivos_do_modulo( \$raiz . '/mu-plugins/uonix-admin/module.php' );\n" );
file_put_contents( $tmp . '/testes/test-outro-arquivo.php', "<?php\n\$x = file_get_contents( \$raiz . '/mu-plugins/uonix-admin/68-admin-editor-marketing.php' );\n" );
// Atribui o caminho do module.php e o entrega ao helper (o caminho certo), e lê OUTRO arquivo por outra variável.
file_put_contents( $tmp . '/testes/test-caminho-para-o-helper.php', "<?php\n\$loader_path = \$root . '/mu-plugins/uonix-navigation/module.php';\n\$carregados = is_file( \$loader_path ) ? uox_loader_arquivos_do_modulo( \$loader_path ) : array();\n\$modulo = file_get_contents( \$outro_caminho );\n" );
// Lê por variável com a chamada quebrada em várias linhas.
file_put_contents( $tmp . '/testes/test-texto-multilinha.php', "<?php\n\$caminho = \$raiz . '/mu-plugins/uonix-woocommerce/module.php';\n\$texto = file_get_contents(\n\t\$caminho\n);\n" );
uox_lc_assert(
	array( 'test-texto-direto.php', 'test-texto-multilinha.php', 'test-texto-variavel.php' ) === uox_lc_leitores_de_texto( $tmp . '/testes' ),
	'controle: o detector acusa a leitura direta, por variável e multilinha, e não acusa o teste executado, a leitura de outro arquivo nem o caminho entregue ao helper'
);

// Entrada pendurada: o helper a grava à parte, e o guarda a acusa. O loader completo e o que só tem arquivos reais não acusam.
file_put_contents( $tmp . '/mod/module-fantasma.php', "<?php\nuonix_mu_require_files( __DIR__, array( '01-a.php', '99-nao-existe.php' ), 'x' );\n" );
uox_loader_arquivos_do_modulo( $tmp . '/mod/module-ok.php' );
uox_lc_assert( array() === uox_loader_entradas_ausentes(), 'controle: o loader completo não tem entrada pendurada' );
$com_fantasma = uox_loader_arquivos_do_modulo( $tmp . '/mod/module-fantasma.php' );
uox_lc_assert( array( '01-a.php' ) === $com_fantasma, 'controle: a lista carregada só traz o arquivo legível' );
uox_lc_assert( array( '99-nao-existe.php' ) === uox_loader_entradas_ausentes(), 'controle: a entrada pendurada é devolvida à parte' );
// A lista de ausentes é zerada a cada execução: a de um módulo não vaza para o seguinte.
uox_loader_arquivos_do_modulo( $tmp . '/mod/module-ok.php' );
uox_lc_assert( array() === uox_loader_entradas_ausentes(), 'controle: as ausentes de um module.php não vazam para o próximo' );

// Duplicata: o mesmo arquivo duas vezes no loader. É inócua em runtime (`require_once`), mas é sinal de linha repetida por engano.
file_put_contents( $tmp . '/mod/module-duplicado.php', "<?php\nuonix_mu_require_files( __DIR__, array( '01-a.php', '02-b.php', '01-a.php' ), 'x' );\n" );
$dup = uox_loader_arquivos_do_modulo( $tmp . '/mod/module-duplicado.php' );
uox_lc_assert( count( $dup ) !== count( array_unique( $dup ) ), 'controle: a lista com duplicata tem repetição (é o que a asserção de duplicata do guarda enxerga)' );
uox_lc_assert( count( $ok ) === count( array_unique( $ok ) ), 'controle: a lista sem duplicata não tem repetição' );

// Exceção declarada: um arquivo que existe, tem nome NN-*.php e DE PROPÓSITO fica fora do loader não é órfão; e uma exceção que o
// loader JÁ carrega reprova (senão ela apodrece em silêncio). Os dois lados, sobre um módulo sintético.
$excecao_fora   = uox_lc_orfaos_com_excecoes( $tmp . '/mod', $comentado, array( '02-b.php' => 'fica fora de propósito' ) );
$excecao_dentro = uox_lc_excecoes_ja_carregadas( $ok, array( '02-b.php' => 'fica fora de propósito' ) );
uox_lc_assert( array() === $excecao_fora, 'controle: a exceção declarada e fora do loader não é órfã' );
uox_lc_assert( array( '02-b.php' ) === $excecao_dentro, 'controle: a exceção que o loader já carrega é acusada' );

array_map( 'unlink', glob( $tmp . '/mod/*.php' ) ?: array() );
array_map( 'unlink', glob( $tmp . '/testes/*.php' ) ?: array() );
@rmdir( $tmp . '/mod' );
@rmdir( $tmp . '/testes' );
@rmdir( $tmp );

if ( $failures > 0 ) {
	fwrite( STDERR, sprintf( "%d falha(s) em %d asserções.\n", $failures, $asserts ) );
	exit( 1 );
}

printf( "PASS: o loader carrega todo NN-*.php de cada módulo, e nenhum teste lê o module.php como texto (%d asserções).\n", $asserts );
