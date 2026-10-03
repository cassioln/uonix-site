<?php
/**
 * Gera o menu lateral do editor para o teste de navegador do clique dos menus
 * de grupo (scripts/tests/test-admin-editor-menus-navegador.mjs).
 *
 * Uso: php menus-grupo-cenario.php <normal|folded>
 *
 * As classes dos itens vêm do código real (uonix_admin_editor_menus_marca_grupos),
 * montadas como o núcleo faz em wp-admin/menu-header.php: a mesma string de
 * classes no <li> e no <a> do menu pai. O script é o real
 * (uonix_admin_editor_menus_clique_grupo). O CSS são as regras do núcleo que
 * escondem e mostram o submenu, copiadas do wp-admin/css/admin-menu.css do
 * WordPress 6.9.4 (linhas indicadas abaixo), porque o núcleo não está no
 * repositório.
 *
 * Todo link aponta para destino.html, que o teste cria ao lado: se o clique
 * navegar, a página muda e o teste vê.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

// Editor: edit_theme_options sem manage_options.
function current_user_can( $cap ) {
	return in_array( $cap, array( 'edit_theme_options', 'edit_posts', 'edit_pages' ), true );
}

// Slug do menu "Políticas e LGPD" (65-admin-editor-politicas-lgpd.php).
function uonix_admin_editor_politicas_slug_pai() {
	return 'post.php?post=3&action=edit';
}

require_once dirname( __DIR__, 3 ) . '/mu-plugins/uonix-admin/67-admin-editor-menus.php';

$cenario = $argv[1] ?? '';
if ( ! in_array( $cenario, array( 'normal', 'folded' ), true ) ) {
	fwrite( STDERR, "cenário desconhecido: {$cenario}\n" );
	exit( 1 );
}

// Menu como o núcleo monta; os grupos ganham a classe pelo código real.
$GLOBALS['menu'] = array(
	5  => array( 'Blog', 'edit_posts', 'edit.php', '', 'menu-top menu-icon-post', 'menu-posts', 'dashicons-admin-post' ),
	56 => array( 'Seções do Site', 'edit_theme_options', 'widgets.php', 'Seções do Site', 'menu-top toplevel_page_widgets', 'toplevel_page_widgets', 'dashicons-layout' ),
	57 => array( 'Políticas e LGPD', 'edit_pages', 'post.php?post=3&action=edit', 'Políticas e LGPD', 'menu-top toplevel_page_politicas', 'toplevel_page_politicas', 'dashicons-shield' ),
);
uonix_admin_editor_menus_marca_grupos();

$submenus = array(
	5  => array( 'Todos os posts', 'Adicionar post', 'Comentários' ),
	56 => array( 'Rodapé', 'Banner Home', 'Banner Produtos', 'Selo Aniversário', 'Topo (Contatos)', 'Dúvidas (FAQ)' ),
	57 => array( 'Política de Privacidade', 'Política de Cookies', 'Termos de Uso', 'Adopt' ),
);

$corpo = 'wp-admin wp-core-ui js auto-fold' . ( 'folded' === $cenario ? ' folded' : '' );
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Menu do editor</title>
<style>
	body { margin: 0; font: 14px sans-serif; }
	#adminmenu, #adminmenu ul { margin: 0; padding: 0; list-style: none; }
	#adminmenu li.menu-top { position: relative; }
	#adminmenu a { display: block; padding: 8px; color: #fff; text-decoration: none; }

	/* admin-menu.css 6.9.4, linhas 1-7 (só os seletores do menu) */
	#adminmenu,
	#adminmenu .wp-submenu {
		width: 160px;
		background-color: #1d2327;
	}

	/* admin-menu.css 6.9.4, linhas 123-134 */
	#adminmenu .wp-submenu {
		list-style: none;
		position: absolute;
		top: -1000em;
		left: 160px;
		overflow: visible;
		word-wrap: break-word;
		padding: 6px 0;
		z-index: 9999;
		background-color: #2c3338;
		box-shadow: 0 3px 5px rgba(0, 0, 0, 0.2);
	}

	/* admin-menu.css 6.9.4, linhas 147-160 */
	#adminmenu .wp-has-current-submenu .wp-submenu,
	.no-js li.wp-has-current-submenu:hover .wp-submenu,
	#adminmenu .wp-has-current-submenu .wp-submenu.sub-open,
	#adminmenu .wp-has-current-submenu.opensub .wp-submenu {
		position: relative;
		z-index: 3;
		top: auto;
		left: auto;
		right: auto;
		bottom: auto;
		border: 0 none;
		margin-top: 0;
		box-shadow: none;
	}

	/* admin-menu.css 6.9.4, linhas 199-203 */
	.folded #adminmenu a.wp-has-current-submenu:focus + .wp-submenu,
	.folded #adminmenu .wp-has-current-submenu .wp-submenu {
		position: absolute;
		top: -1000em;
	}

	/* admin-menu.css 6.9.4, linhas 546 e 569-576 */
	@media only screen and (max-width: 960px) {
		.auto-fold #adminmenu a.wp-has-current-submenu:focus + .wp-submenu,
		.auto-fold #adminmenu .wp-has-current-submenu .wp-submenu {
			position: absolute;
			top: -1000em;
			margin-right: -1px;
			padding: 6px 0;
			z-index: 9999;
		}
	}
</style>
</head>
<body class="<?php echo $corpo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
<ul id="adminmenu">
<?php foreach ( $GLOBALS['menu'] as $posicao => $item ) : ?>
	<?php $classes = 'wp-has-submenu wp-not-current-submenu ' . $item[4]; ?>
	<li class="<?php echo $classes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" id="<?php echo $item[5]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<a href="destino.html" class="<?php echo $classes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" aria-haspopup="true"><div class="wp-menu-name"><?php echo $item[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></a>
		<ul class="wp-submenu wp-submenu-wrap">
		<?php foreach ( $submenus[ $posicao ] as $titulo ) : ?>
			<li><a href="destino.html"><?php echo $titulo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
		<?php endforeach; ?>
		</ul>
	</li>
<?php endforeach; ?>
</ul>
<?php uonix_admin_editor_menus_clique_grupo(); ?>
</body>
</html>
