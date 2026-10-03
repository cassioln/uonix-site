<?php
/**
 * Perfil editor: menu "Marketing".
 *
 * "Visão Geral" mostra a mesma tela da aba "Destinos de marketing
 * configurados" do Uônix Insights, sem o AdOpt (que fica em "Políticas e
 * LGPD", 65-admin-editor-politicas-lgpd.php). Tela e dados vêm do próprio
 * Insights (52-admin-analytics-dashboard.php), para nunca divergirem; só
 * mostram configuração local, nada consultado nas plataformas.
 *
 * Os demais itens abrem cada plataforma em outra aba, nos mesmos endereços
 * dos botões dos cards. O clique em "Marketing" só abre o submenu
 * (67-admin-editor-menus.php).
 *
 * O Insights continua sob a governança do menu ksio.dev (49); este menu é do
 * editor. Administrador (manage_options) não é afetado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O usuário atual é o editor que recebe o menu?
 *
 * @return bool
 */
function uonix_admin_editor_marketing_ativo() {
	return current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' );
}

/**
 * Atalhos externos do submenu, na ordem dos cards: título => chave do endereço
 * em uonix_analytics_destinations_context().
 *
 * @return array<string, string>
 */
function uonix_admin_editor_marketing_atalhos() {
	return array(
		'Google Tag Manager' => 'gtm_url',
		'Google Analytics 4' => 'ga4_url',
		'Google Ads'         => 'google_ads_url',
		'Meta Pixel'         => 'meta_events_url',
		'Search Console'     => 'gsc_domain_url',
		'Looker Studio'      => 'looker_url',
	);
}

/**
 * Menu "Marketing": Visão Geral e os atalhos das plataformas.
 *
 * @return void
 */
function uonix_admin_editor_marketing_menu() {
	global $submenu;

	if ( ! uonix_admin_editor_marketing_ativo() ) {
		return;
	}

	add_menu_page( 'Marketing', 'Marketing', 'edit_posts', 'uonix-marketing', 'uonix_admin_editor_marketing_render', 'dashicons-megaphone', 58 );
	add_submenu_page( 'uonix-marketing', 'Marketing — Visão Geral', 'Visão Geral', 'edit_posts', 'uonix-marketing', 'uonix_admin_editor_marketing_render' );

	if ( ! function_exists( 'uonix_analytics_destinations_context' ) ) {
		return;
	}

	// Itens direto no $submenu, como a AdOpt em 65: o href sai do próprio item.
	$destinos = uonix_analytics_destinations_context();
	foreach ( uonix_admin_editor_marketing_atalhos() as $titulo => $chave ) {
		if ( ! empty( $destinos[ $chave ] ) ) {
			$submenu['uonix-marketing'][] = array( $titulo, 'edit_posts', $destinos[ $chave ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
}
add_action( 'admin_menu', 'uonix_admin_editor_marketing_menu', 1000 );

/**
 * Tela "Visão Geral".
 *
 * @return void
 */
function uonix_admin_editor_marketing_render() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'uonix' ) );
	}
	?>
	<div class="wrap uonix-analytics-wrap">
		<div class="uonix-analytics-header">
			<div class="uonix-header-content">
				<div class="uonix-header-badge">UÔNIX - ANCORAGEM PREDIAL</div>
				<h1>Marketing</h1>
				<p>Visão geral dos destinos de marketing configurados no site.</p>
			</div>
		</div>

		<?php if ( function_exists( 'uonix_analytics_render_destinations' ) && function_exists( 'uonix_analytics_destinations_context' ) ) : ?>
		<section class="uonix-marketing-section" aria-labelledby="uonix-marketing-title">
			<?php uonix_analytics_render_destinations( uonix_analytics_destinations_context(), false ); ?>
		</section>
		<?php else : ?>
		<div class="notice notice-warning"><p>A visão geral de marketing não está disponível neste ambiente.</p></div>
		<?php endif; ?>
	</div>
	<?php
	if ( function_exists( 'uonix_analytics_print_dashboard_css' ) ) {
		uonix_analytics_print_dashboard_css();
	}
}

/**
 * Os atalhos das plataformas abrem em outra aba.
 *
 * @return void
 */
function uonix_admin_editor_marketing_nova_aba() {
	if ( ! uonix_admin_editor_marketing_ativo() ) {
		return;
	}

	echo "<script>document.querySelectorAll('#toplevel_page_uonix-marketing .wp-submenu a[href^=\"https://\"]').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });</script>\n";
}
add_action( 'admin_footer', 'uonix_admin_editor_marketing_nova_aba' );
