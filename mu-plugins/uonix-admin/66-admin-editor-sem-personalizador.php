<?php
/**
 * Perfil editor: sem acesso ao Personalizador.
 *
 * O núcleo mapeia a capability `customize` para `edit_theme_options`, que o
 * editor tem. O Personalizador grava áreas e widgets por `customize_save`, que
 * confere só `customize`, sem olhar qual área está sendo gravada: um pedido
 * montado à mão esvaziaria a área mega-menu, que guarda o conteúdo do megamenu
 * (issue #388).
 *
 * O editor edita o rodapé em "Editar Rodapé" (64-admin-editor-rodape.php), que
 * recusa gravação fora do rodapé. Negar `customize` tira o "Personalizar" da
 * barra, faz customize.php recusar a entrada e o `customize_save` recusar o
 * pedido.
 *
 * Administrador (manage_options) não é afetado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nega `customize` a quem tem edit_theme_options sem manage_options.
 *
 * @param string[] $caps    Capabilities primitivas exigidas.
 * @param string   $cap     Capability pedida.
 * @param int      $user_id Usuário.
 * @return string[]
 */
function uonix_admin_editor_sem_personalizador( $caps, $cap, $user_id ) {
	if ( 'customize' !== $cap || user_can( $user_id, 'manage_options' ) ) {
		return $caps;
	}

	return array( 'do_not_allow' );
}
add_filter( 'map_meta_cap', 'uonix_admin_editor_sem_personalizador', 10, 3 );
