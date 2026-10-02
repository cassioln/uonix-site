<?php
/**
 * Perfil editor: menu "Políticas e LGPD" com atalhos para as páginas legais.
 *
 * Os itens abrem direto o editor de cada página. O de privacidade depende de
 * 39-admin-editor-dashboard.php, que libera ao editor a página definida em
 * wp_page_for_privacy_policy (o núcleo exige manage_privacy_options). O item
 * Adopt abre o painel da AdOpt em outra aba.
 *
 * Os ids são os de produção, conferidos pelo endereço público em 2026-10-02:
 * 3 = /politica-de-privacidade/, 11343 = /politica-de-cookies/,
 * 11344 = /termos-de-uso/. Página que não existir no ambiente fica fora do menu.
 *
 * Administrador (manage_options) não é afetado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Páginas do menu, na ordem de exibição: título => id.
 *
 * @return array<string, int>
 */
function uonix_admin_editor_politicas_paginas() {
	return array(
		'Política de Privacidade' => 3,
		'Política de Cookies'     => 11343,
		'Termos de Uso'           => 11344,
	);
}

/**
 * Painel da AdOpt.
 *
 * @return string
 */
function uonix_admin_editor_politicas_url_adopt() {
	return 'https://dash.goadopt.io/org/uonix/disclaimers';
}

/**
 * Endereço de edição de uma página, relativo ao wp-admin.
 *
 * @param int $id Id da página.
 * @return string
 */
function uonix_admin_editor_politicas_url_edicao( $id ) {
	return 'post.php?post=' . (int) $id . '&action=edit';
}

/**
 * O usuário atual é o editor que recebe o menu?
 *
 * @return bool
 */
function uonix_admin_editor_politicas_ativo() {
	return current_user_can( 'edit_pages' ) && ! current_user_can( 'manage_options' );
}

/**
 * Páginas que existem neste ambiente: título => id.
 *
 * @return array<string, int>
 */
function uonix_admin_editor_politicas_paginas_existentes() {
	$existentes = array();
	foreach ( uonix_admin_editor_politicas_paginas() as $titulo => $id ) {
		$post = get_post( $id );
		if ( $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
			$existentes[ $titulo ] = $id;
		}
	}

	return $existentes;
}

/**
 * Slug do menu pai: a primeira página existente, ou '' se não houver nenhuma.
 *
 * Nunca o endereço da AdOpt: add_menu_page passa o slug por plugin_basename(),
 * que reduz o "//" de https:// a uma barra só.
 *
 * @return string
 */
function uonix_admin_editor_politicas_slug_pai() {
	$paginas = uonix_admin_editor_politicas_paginas_existentes();

	return $paginas ? uonix_admin_editor_politicas_url_edicao( reset( $paginas ) ) : '';
}

/**
 * Menu "Políticas e LGPD" e seus itens.
 *
 * @return void
 */
function uonix_admin_editor_politicas_menu() {
	global $submenu;

	if ( ! uonix_admin_editor_politicas_ativo() ) {
		return;
	}

	$pai = uonix_admin_editor_politicas_slug_pai();
	if ( '' === $pai ) {
		return;
	}

	add_menu_page( 'Políticas e LGPD', 'Políticas e LGPD', 'edit_pages', $pai, '', 'dashicons-shield', 57 );

	// Itens direto no $submenu, como os atalhos do Leads em 39-admin-editor-dashboard.php:
	// o href sai do próprio item, inclusive o endereço externo da AdOpt.
	$itens = array();
	foreach ( uonix_admin_editor_politicas_paginas_existentes() as $titulo => $id ) {
		$itens[] = array( $titulo, 'edit_pages', uonix_admin_editor_politicas_url_edicao( $id ) );
	}
	$itens[] = array( 'Adopt', 'edit_pages', uonix_admin_editor_politicas_url_adopt() );

	$submenu[ $pai ] = $itens; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
add_action( 'admin_menu', 'uonix_admin_editor_politicas_menu', 1000 );

/**
 * Id da página do menu aberta no editor agora, ou 0.
 *
 * @return int
 */
function uonix_admin_editor_politicas_pagina_atual() {
	global $pagenow;

	if ( 'post.php' !== $pagenow || ! isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0;
	}

	$id = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return in_array( $id, uonix_admin_editor_politicas_paginas(), true ) ? $id : 0;
}

/**
 * Ao editar uma das páginas, destaca "Políticas e LGPD" em vez de Páginas.
 *
 * @param string $parent_file Arquivo pai do menu.
 * @return string
 */
function uonix_admin_editor_politicas_parent_file( $parent_file ) {
	if ( uonix_admin_editor_politicas_ativo() && uonix_admin_editor_politicas_pagina_atual() ) {
		return uonix_admin_editor_politicas_slug_pai();
	}

	return $parent_file;
}
add_filter( 'parent_file', 'uonix_admin_editor_politicas_parent_file' );

/**
 * Ao editar uma das páginas, destaca o item dela no submenu.
 *
 * @param string|null $submenu_file Item atual do submenu.
 * @return string|null
 */
function uonix_admin_editor_politicas_submenu_file( $submenu_file ) {
	$id = uonix_admin_editor_politicas_pagina_atual();
	if ( uonix_admin_editor_politicas_ativo() && $id ) {
		return uonix_admin_editor_politicas_url_edicao( $id );
	}

	return $submenu_file;
}
add_filter( 'submenu_file', 'uonix_admin_editor_politicas_submenu_file' );

/**
 * O item Adopt abre o painel externo em outra aba.
 *
 * @return void
 */
function uonix_admin_editor_politicas_adopt_nova_aba() {
	if ( ! uonix_admin_editor_politicas_ativo() ) {
		return;
	}

	$url = wp_json_encode( uonix_admin_editor_politicas_url_adopt() );
	echo "<script>document.querySelectorAll('#adminmenu a[href=' + JSON.stringify(" . $url . ") + ']').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'admin_footer', 'uonix_admin_editor_politicas_adopt_nova_aba' );
