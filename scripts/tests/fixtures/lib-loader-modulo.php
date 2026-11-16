<?php
/**
 * Confere o que um module.php carrega de verdade, executando-o.
 *
 * Substitui a checagem por `strpos` no texto do arquivo, que não distingue uma
 * linha comentada (`// '50-...php',`) de uma ativa: o módulo deixa de carregar e o
 * teste segue verde (#354 e #362).
 *
 * Define `uonix_mu_require_files()` como um gravador, que guarda os nomes dos
 * arquivos e não carrega nenhum. Só o PHP decide o que está ativo: comentário,
 * `if (false)` ou código morto não contam. Como o loader real
 * (`mu-plugins/uonix-core.php`), só grava o arquivo que é legível em
 * `$base_dir/$file`: um `__DIR__` trocado no module.php faz a lista sair vazia, e o
 * helper acusa, em vez de passar com a lista certa e o caminho errado.
 *
 * Falha alto, com RuntimeException, quando:
 *  - o module.php não existe;
 *  - o gravador não é o de `uonix_mu_require_files()` neste processo (outro teste
 *    declarou a função antes: a lista sairia vazia e uma asserção "X não está na
 *    lista" daria falso verde);
 *  - o module.php termina sem nenhuma chamada ao loader.
 *
 * Escopo: o helper EXECUTA o module.php. Dos módulos atuais, só dois fazem algo além
 * de chamar o loader: `uonix-local` (`UONIX_ENV` precisa estar definida) e
 * `uonix-shared` (dá `require_once` em environment.php e helpers.php).
 *
 * Ordem de uso: defina `ABSPATH` ANTES de incluir este arquivo, como o teste faz.
 *
 *   define( 'ABSPATH', __DIR__ );
 *   require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
 *   $arquivos = uox_loader_arquivos_do_modulo( $raiz . '/mu-plugins/uonix-navigation/module.php' );
 *   // array( '06-navegacao-posicoes-menu.php', ... ) na ordem em que o módulo os lista
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

$GLOBALS['uox_loader_registro'] = array();
$GLOBALS['uox_loader_chamadas'] = 0;

if ( ! function_exists( 'uonix_mu_require_files' ) ) {
	/**
	 * Grava a lista em vez de carregar os arquivos.
	 *
	 * @param string   $base_dir    Diretório do módulo.
	 * @param string[] $files       Arquivos listados pelo módulo.
	 * @param string   $module_name Nome do módulo.
	 * @return void
	 */
	function uonix_mu_require_files( $base_dir, array $files, $module_name ) {
		++$GLOBALS['uox_loader_chamadas'];
		foreach ( $files as $file ) {
			if ( is_readable( rtrim( (string) $base_dir, '/' ) . '/' . $file ) ) {
				$GLOBALS['uox_loader_registro'][] = $file;
			}
		}
	}
}

/**
 * Executa o `module.php` e devolve, em ordem, os arquivos que ele manda carregar.
 *
 * @param string $module_php Caminho absoluto do module.php.
 * @return string[]
 * @throws RuntimeException Se o module.php não existe, se o gravador não é este, ou
 *                          se o módulo não chamou o loader.
 */
function uox_loader_arquivos_do_modulo( $module_php ) {
	if ( ! is_readable( $module_php ) ) {
		throw new RuntimeException( "module.php inexistente ou ilegível: {$module_php}" );
	}

	$GLOBALS['uox_loader_registro'] = array();
	$GLOBALS['uox_loader_chamadas'] = 0;
	include $module_php;

	if ( 0 === $GLOBALS['uox_loader_chamadas'] ) {
		throw new RuntimeException( "o module.php não chamou o gravador de uonix_mu_require_files() (outro teste declarou a função antes, ou o módulo não chama o loader): {$module_php}" );
	}

	return $GLOBALS['uox_loader_registro'];
}
