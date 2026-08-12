<?php
/**
 * Mantém o editor Visual da breve descrição estável ao mover sua metabox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carrega o lifecycle do TinyMCE somente na edição de produtos.
 *
 * @param mixed $hook_suffix Identificador da tela administrativa.
 */
function uonix_admin_resumo_editor_estavel_enqueue( $hook_suffix ) {
	if (
		! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ||
		! function_exists( 'get_current_screen' )
	) {
		return;
	}

	$screen = get_current_screen();
	if ( ! is_object( $screen ) || ! isset( $screen->post_type ) || 'product' !== $screen->post_type ) {
		return;
	}

	$script_relative = 'uonix-woocommerce/assets/js/admin-product-excerpt-editor.js';

	wp_enqueue_script(
		'uonix-product-excerpt-editor',
		UONIX_MU_URL . $script_relative,
		array( 'jquery', 'jquery-ui-sortable', 'postbox', 'editor' ),
		(string) filemtime( UONIX_MU_PATH . $script_relative ),
		true
	);
}

add_action( 'admin_enqueue_scripts', 'uonix_admin_resumo_editor_estavel_enqueue', 10, 1 );
