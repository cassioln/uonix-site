<?php
/**
 * Perfil editor: organização do menu lateral.
 *
 * 1. "Seções do Site": Rodapé (widgets.php, ver 64-admin-editor-rodape.php),
 *    Banner Home, Banner Produtos, Selo Aniversário, Topo (Contatos) e
 *    Dúvidas (FAQ), os mesmos destinos do Acesso Rápido do painel
 *    (39-admin-editor-dashboard.php).
 * 2. "Posts" vira "Blog", e "Comentários" (com o contador de moderação do
 *    núcleo) passa a ser item dele, depois de "Adicionar post".
 * 3. Os menus que só agrupam atalhos, "Seções do Site" e "Políticas e LGPD"
 *    (65-admin-editor-politicas-lgpd.php), não navegam ao clicar: abrem e
 *    fecham o submenu no lugar, como um menu pai aberto do WordPress. Com o
 *    menu recolhido ou em tela estreita o clique só não navega; o submenu
 *    aparece pelo comportamento do próprio WordPress.
 *
 * Administrador (manage_options) não é afetado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O usuário atual é o editor que recebe estes ajustes?
 *
 * @return bool
 */
function uonix_admin_editor_menus_ativo() {
	return current_user_can( 'edit_theme_options' ) && ! current_user_can( 'manage_options' );
}

/**
 * Itens de "Seções do Site", na ordem de exibição: título => endereço.
 *
 * @return array<string, string>
 */
function uonix_admin_editor_menus_secoes() {
	return array(
		'Rodapé'           => 'widgets.php',
		'Banner Home'      => 'post.php?post=6130&action=edit',
		'Banner Produtos'  => 'site-editor.php?p=%2Fwp_block%2F7255&canvas=edit',
		'Selo Aniversário' => 'site-editor.php?p=%2Fwp_block%2F10973&canvas=edit',
		'Topo (Contatos)'  => 'site-editor.php?p=%2Fwp_block%2F3631&canvas=edit',
		'Dúvidas (FAQ)'    => 'site-editor.php?p=%2Fwp_block%2F2859&canvas=edit',
	);
}

/**
 * Slugs dos menus pai que só agrupam atalhos.
 *
 * @return string[]
 */
function uonix_admin_editor_menus_grupos() {
	$grupos = array( 'widgets.php' );

	if ( function_exists( 'uonix_admin_editor_politicas_slug_pai' ) ) {
		$politicas = uonix_admin_editor_politicas_slug_pai();
		if ( '' !== $politicas ) {
			$grupos[] = $politicas;
		}
	}

	return $grupos;
}

/**
 * Menu "Seções do Site".
 *
 * O slug é widgets.php, o primeiro item: sem JavaScript o clique cai no
 * Rodapé, e o destaque em widgets.php vem de 64-admin-editor-rodape.php.
 *
 * @return void
 */
function uonix_admin_editor_menus_secoes_do_site() {
	global $submenu;

	if ( ! uonix_admin_editor_menus_ativo() ) {
		return;
	}

	add_menu_page( 'Seções do Site', 'Seções do Site', 'edit_theme_options', 'widgets.php', '', 'dashicons-layout', 56 );

	$itens = array();
	foreach ( uonix_admin_editor_menus_secoes() as $titulo => $url ) {
		$itens[] = array( $titulo, 'edit_theme_options', $url );
	}

	$submenu['widgets.php'] = $itens; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
add_action( 'admin_menu', 'uonix_admin_editor_menus_secoes_do_site', 1000 );

/**
 * "Posts" vira "Blog" e "Comentários" entra nele, depois de "Adicionar post".
 *
 * O título de Comentários é o do próprio núcleo, com o contador de moderação.
 *
 * @return void
 */
function uonix_admin_editor_menus_blog() {
	global $menu, $submenu;

	if ( ! uonix_admin_editor_menus_ativo() || ! is_array( $menu ) ) {
		return;
	}

	$comentarios = null;
	foreach ( $menu as $posicao => $item ) {
		if ( 'edit.php' === $item[2] ) {
			$menu[ $posicao ][0] = 'Blog'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} elseif ( 'edit-comments.php' === $item[2] ) {
			$comentarios = $item[0];
			unset( $menu[ $posicao ] );
		}
	}

	if ( null === $comentarios || ! isset( $submenu['edit.php'] ) ) {
		return;
	}

	// O submenu do núcleo sob edit-comments.php faria a tela achar outro pai.
	unset( $submenu['edit-comments.php'] );

	// 5 = Todos os posts, 10 = Adicionar post; 11 fica logo depois.
	$submenu['edit.php'][11] = array( $comentarios, 'edit_posts', 'edit-comments.php' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	ksort( $submenu['edit.php'] );
}
add_action( 'admin_menu', 'uonix_admin_editor_menus_blog', 1001 );

/**
 * Marca os menus que só agrupam atalhos, para o JavaScript do clique.
 *
 * O núcleo põe a classe do item ($menu[][4]) no li e no link do menu pai.
 *
 * @return void
 */
function uonix_admin_editor_menus_marca_grupos() {
	global $menu;

	if ( ! uonix_admin_editor_menus_ativo() || ! is_array( $menu ) ) {
		return;
	}

	$grupos = uonix_admin_editor_menus_grupos();
	foreach ( $menu as $posicao => $item ) {
		if ( in_array( $item[2], $grupos, true ) ) {
			$menu[ $posicao ][4] = trim( ( $item[4] ?? '' ) . ' uonix-menu-grupo' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
}
add_action( 'admin_menu', 'uonix_admin_editor_menus_marca_grupos', 1002 );

/**
 * Página aberta agora no editor de posts que é item de "Seções do Site".
 *
 * @return string Endereço do item, ou '' fora dessas telas.
 */
function uonix_admin_editor_menus_secao_atual() {
	global $pagenow;

	if ( 'post.php' !== $pagenow || ! isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}

	$url = 'post.php?post=' . (int) $_GET['post'] . '&action=edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return in_array( $url, uonix_admin_editor_menus_secoes(), true ) ? $url : '';
}

/**
 * Destaque do menu: Blog nas telas de comentários, Seções do Site no Banner Home.
 *
 * @param string $parent_file Arquivo pai do menu.
 * @return string
 */
function uonix_admin_editor_menus_parent_file( $parent_file ) {
	if ( ! uonix_admin_editor_menus_ativo() ) {
		return $parent_file;
	}

	// comment.php define edit-comments.php como pai.
	if ( 'edit-comments.php' === $parent_file ) {
		return 'edit.php';
	}

	if ( '' !== uonix_admin_editor_menus_secao_atual() ) {
		return 'widgets.php';
	}

	return $parent_file;
}
add_filter( 'parent_file', 'uonix_admin_editor_menus_parent_file' );

/**
 * Destaque do item: o Banner Home dentro de Seções do Site.
 *
 * @param string|null $submenu_file Item atual do submenu.
 * @return string|null
 */
function uonix_admin_editor_menus_submenu_file( $submenu_file ) {
	$secao = uonix_admin_editor_menus_secao_atual();
	if ( uonix_admin_editor_menus_ativo() && '' !== $secao ) {
		return $secao;
	}

	return $submenu_file;
}
add_filter( 'submenu_file', 'uonix_admin_editor_menus_submenu_file' );

/**
 * Clique no menu de grupo abre e fecha o submenu em vez de navegar.
 *
 * @return void
 */
function uonix_admin_editor_menus_clique_grupo() {
	if ( ! uonix_admin_editor_menus_ativo() ) {
		return;
	}
	?>
<script>
document.querySelectorAll( '#adminmenu li.uonix-menu-grupo > a' ).forEach( function ( link ) {
	link.addEventListener( 'click', function ( evento ) {
		evento.preventDefault();
		if ( document.body.classList.contains( 'folded' ) || window.innerWidth < 783 ) {
			return;
		}
		var abrir = ! link.parentNode.classList.contains( 'wp-menu-open' );
		[ link.parentNode, link ].forEach( function ( el ) {
			el.classList.toggle( 'wp-has-current-submenu', abrir );
			el.classList.toggle( 'wp-menu-open', abrir );
			el.classList.toggle( 'wp-not-current-submenu', ! abrir );
		} );
		link.setAttribute( 'aria-expanded', abrir ? 'true' : 'false' );
	} );
} );
</script>
	<?php
}
add_action( 'admin_footer', 'uonix_admin_editor_menus_clique_grupo' );
