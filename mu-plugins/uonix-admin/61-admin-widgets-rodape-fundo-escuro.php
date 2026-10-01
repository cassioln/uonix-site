<?php
/**
 * Editor de widgets: áreas do rodapé com o fundo escuro do rodapé do site.
 *
 * No site, o rodapé do Kadence tem fundo #1a202c com texto, títulos e links
 * brancos (medido em 2026-10-01). Em Aparência > Widgets as áreas têm fundo
 * branco, e esse conteúdo fica invisível. Aqui as áreas `footer*` recebem o
 * mesmo fundo, e títulos e links ficam brancos como no site. Só afeta o editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS das áreas do rodapé no editor de widgets.
 *
 * @return string
 */
function uonix_admin_widgets_rodape_css() {
	$area = '.wp-block-widget-area__inner-blocks[data-widget-area-id^="footer"]';

	return $area . '{background-color:#1a202c;color:#fff}'
		. $area . ' :where(h1,h2,h3,h4,h5,h6,a:not(.button):not(.wp-block-button__link):not(.wp-element-button)){color:#fff}'
		. $area . ' .block-editor-button-block-appender{color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.6)}';
}

/**
 * Carrega o CSS só na tela Aparência > Widgets.
 *
 * @param string $hook_suffix Tela atual do admin.
 * @return void
 */
function uonix_admin_widgets_rodape_fundo_escuro( $hook_suffix ) {
	if ( 'widgets.php' !== $hook_suffix ) {
		return;
	}

	wp_register_style( 'uonix-admin-widgets-rodape', false, array(), null );
	wp_enqueue_style( 'uonix-admin-widgets-rodape' );
	wp_add_inline_style( 'uonix-admin-widgets-rodape', uonix_admin_widgets_rodape_css() );
}
add_action( 'admin_enqueue_scripts', 'uonix_admin_widgets_rodape_fundo_escuro' );
