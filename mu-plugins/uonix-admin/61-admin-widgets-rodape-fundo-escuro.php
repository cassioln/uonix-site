<?php
/**
 * Editor de widgets: áreas do rodapé com o fundo escuro do rodapé do site.
 *
 * No site, o rodapé do Kadence tem fundo #1a202c com texto, títulos e links
 * brancos (medido em 2026-10-01). Em Aparência > Widgets as áreas têm fundo
 * branco, e esse conteúdo fica invisível. Aqui as áreas `footer*` recebem o
 * mesmo fundo (footer1 a footer6, lista explícita), e títulos e links ficam brancos
 * como no site, menos dentro do formulário do widget legado e dos placeholders do
 * editor, que têm fundo branco próprio. Só afeta o editor.
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
	// Lista explícita, não o prefixo ^="footer": uma área futura de outro plugin com id
	// começando por "footer" (footer-cta) também ficaria escura (#361).
	$areas = array();
	foreach ( array( 1, 2, 3, 4, 5, 6 ) as $n ) {
		$areas[] = '.wp-block-widget-area__inner-blocks[data-widget-area-id="footer' . $n . '"]';
	}
	$fundo = implode( ',', $areas );

	// O formulário do widget legado e os placeholders do editor têm fundo branco próprio e
	// título e link escuros (medido no CSS do núcleo: `.wp-block-legacy-widget__edit-form`
	// com `background:#fff` e título `#000`; `.components-placeholder` com `#fff` e `#1e1e1e`).
	// Pintar o texto deles de branco o deixaria invisível (#361).
	$excecao = ':not(.wp-block-legacy-widget__edit-form *):not(.components-placeholder *)';
	$textos  = array();
	foreach ( $areas as $area ) {
		$textos[] = $area . ' :where(h1,h2,h3,h4,h5,h6,a:not(.button):not(.wp-block-button__link):not(.wp-element-button))' . $excecao;
	}
	$botao = array();
	foreach ( $areas as $area ) {
		$botao[] = $area . ' .block-editor-button-block-appender';
	}

	return $fundo . '{background-color:#1a202c;color:#fff}'
		. implode( ',', $textos ) . '{color:#fff}'
		. implode( ',', $botao ) . '{color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.6)}';
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
