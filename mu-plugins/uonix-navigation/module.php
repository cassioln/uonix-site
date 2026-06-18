<?php
/**
 * Navegação e mega menus.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'06-navegacao-posicoes-menu.php',
		'23-mega-menu-servicos.php',
		'24-mega-menu-blog.php',
		'27-mega-menu-produtos-marcas.php',
		'42-mega-menu-projetos.php',
	),
	'uonix-navigation'
);
