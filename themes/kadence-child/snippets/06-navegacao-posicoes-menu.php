<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Navegacao - posicoes extras de menu.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1398-1411 do export original.
// -----------------------------------------------------------------------------
/**
 * Criar Posição de Menu Extra
 */
function uonix_registrar_novos_menus() {
  register_nav_menus(
    array(
      'menu-extra-1' => __( 'Menu Pesquisar' ),
      'menu-extra-2' => __( 'Menu Contato' ),
	  'menu-extra-3' => __( 'Menu Carrinho' )
    )
  );
}
add_action( 'init', 'uonix_registrar_novos_menus' );


