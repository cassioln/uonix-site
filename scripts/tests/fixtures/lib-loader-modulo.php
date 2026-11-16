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
 * `if (false)` ou código morto não contam.
 *
 * Uso:
 *   require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
 *   $arquivos = uox_loader_arquivos_do_modulo( $raiz . '/mu-plugins/uonix-navigation/module.php' );
 *   // array( '06-navegacao-posicoes-menu.php', ... ) na ordem em que o módulo os lista
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

$GLOBALS['uox_loader_registro'] = array();

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
		foreach ( $files as $file ) {
			$GLOBALS['uox_loader_registro'][] = $file;
		}
	}
}

/**
 * Executa o `module.php` e devolve, em ordem, os arquivos que ele manda carregar.
 *
 * @param string $module_php Caminho absoluto do module.php.
 * @return string[]
 */
function uox_loader_arquivos_do_modulo( $module_php ) {
	$GLOBALS['uox_loader_registro'] = array();
	include $module_php;

	return $GLOBALS['uox_loader_registro'];
}
