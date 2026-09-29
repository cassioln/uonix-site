<?php
/**
 * Governança do menu ksio.dev: quem vê as ferramentas internas, e como.
 *
 * O menu ksio.dev é do usuário da ksio.dev, e de mais ninguém — nem de outro
 * administrador. Dentro dele ficam Limpeza de Conteúdo, Clone de Ambientes, Uônix
 * Insights e a tela que decide quais dessas ferramentas os demais usuários veem.
 * Para os demais, cada ferramenta liberada aparece como item próprio do menu, com a
 * capacidade que ela sempre exigiu; nunca como submenu de ksio.dev.
 *
 * Este é o ÚNICO lugar que registra o menu dessas ferramentas. Os arquivos 46, 48 e
 * 52 só têm as páginas.
 *
 * Não é barreira contra outro administrador: quem tem `manage_options` pode editar o
 * usuário da ksio.dev pela tela de Usuários. O controle é de governança de interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_ksio_owner_login' ) ) {
	/**
	 * Login do dono do menu ksio.dev.
	 *
	 * `ksiodev` é o único usuário @ksio.dev em produção (conferido em 2026-09-28). A
	 * constante existe para o ambiente que não tiver esse usuário — QA ou local
	 * clonado sem substituir usuários —, onde sem ela ninguém veria o menu.
	 */
	function uonix_ksio_owner_login() {
		return defined( 'UONIX_KSIO_OWNER_LOGIN' ) && '' !== (string) UONIX_KSIO_OWNER_LOGIN
			? (string) UONIX_KSIO_OWNER_LOGIN
			: 'ksiodev';
	}
}

if ( ! function_exists( 'uonix_ksio_is_owner' ) ) {
	/**
	 * O usuário atual é o dono? Comparação estrita do login: `KSIODEV` ou `ksiodev2`
	 * não são o dono.
	 */
	function uonix_ksio_is_owner() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}
		$usuario = wp_get_current_user();

		return is_object( $usuario ) && isset( $usuario->user_login ) && uonix_ksio_owner_login() === (string) $usuario->user_login;
	}
}

if ( ! function_exists( 'uonix_ksio_tools' ) ) {
	/**
	 * As ferramentas que o dono pode liberar, com o slug, a capacidade e a página de
	 * sempre. Os slugs não mudam: o e-mail semanal e o alerta de anomalias apontam
	 * para `admin.php?page=uonix-analytics`.
	 */
	function uonix_ksio_tools() {
		return array(
			'limpeza'   => array(
				'title'      => 'Limpeza de Conteúdo',
				'slug'       => 'ksio-dev-limpeza-conteudo',
				'capability' => 'manage_options',
				'callback'   => 'uox_content_render_cleanup_page',
				'icon'       => 'dashicons-trash',
				'position'   => 58,
			),
			'clone'     => array(
				'title'      => 'Clone de Ambientes',
				'slug'       => 'ksio-dev-clone-ambientes',
				'capability' => 'manage_options',
				'callback'   => 'uox_clone_render_page',
				'icon'       => 'dashicons-admin-site-alt3',
				'position'   => 59,
			),
			'analytics' => array(
				'title'      => 'Uônix Insights',
				'slug'       => 'uonix-analytics',
				'capability' => 'edit_posts',
				'callback'   => 'uonix_render_analytics_dashboard_page',
				'icon'       => 'dashicons-chart-area',
				'position'   => 3,
			),
		);
	}
}

if ( ! function_exists( 'uonix_ksio_visibility_option' ) ) {
	function uonix_ksio_visibility_option() {
		return 'uonix_ksio_tools_visibility';
	}
}

if ( ! function_exists( 'uonix_ksio_tools_visibility' ) ) {
	/**
	 * Quais ferramentas os demais usuários veem.
	 *
	 * O padrão é OCULTO para as três (decisão do Cassio em 2026-09-28): sem opção
	 * gravada, ou com opção corrompida, nada aparece para ninguém além do dono. Só as
	 * chaves do registro valem; chave desconhecida é ignorada.
	 *
	 * @return array<string, bool>
	 */
	function uonix_ksio_tools_visibility() {
		$gravado = function_exists( 'get_option' ) ? get_option( uonix_ksio_visibility_option(), array() ) : array();
		$gravado = is_array( $gravado ) ? $gravado : array();

		$saida = array();
		foreach ( array_keys( uonix_ksio_tools() ) as $chave ) {
			$saida[ $chave ] = isset( $gravado[ $chave ] ) && true === $gravado[ $chave ];
		}

		return $saida;
	}
}

if ( ! function_exists( 'uonix_ksio_can_access_tool' ) ) {
	/**
	 * Regra única de acesso a uma ferramenta: o dono sempre; os demais só com a
	 * ferramenta liberada E a capacidade dela. Vale para a página e para os handlers
	 * `admin_post` da ferramenta — esconder o menu não bloqueia um POST direto.
	 */
	function uonix_ksio_can_access_tool( $chave ) {
		$ferramentas = uonix_ksio_tools();
		if ( ! isset( $ferramentas[ $chave ] ) ) {
			return false;
		}
		if ( uonix_ksio_is_owner() ) {
			return true;
		}
		$visivel = uonix_ksio_tools_visibility();

		return ! empty( $visivel[ $chave ] ) && function_exists( 'current_user_can' ) && current_user_can( $ferramentas[ $chave ]['capability'] );
	}
}

if ( ! function_exists( 'uonix_ksio_can_configure_insights' ) ) {
	/**
	 * Quem altera as Configurações do Uônix Insights: destinatários, envio de teste e
	 * licença. Só o dono, e com `manage_options`, para que a constante
	 * UONIX_KSIO_OWNER_LOGIN apontada para um editor não lhe dê escrita.
	 *
	 * Ver o Insights liberado não basta: os demais leem a aba, e não a alteram.
	 */
	function uonix_ksio_can_configure_insights() {
		return uonix_ksio_is_owner() && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}
}

if ( ! function_exists( 'uonix_ksio_register_menus' ) ) {
	/**
	 * Registra o menu conforme quem está logado.
	 *
	 * Para os demais, a página de ferramenta oculta simplesmente não é registrada, e
	 * o WordPress responde "sem permissão" a quem abrir a URL direto.
	 */
	function uonix_ksio_register_menus() {
		$ferramentas = uonix_ksio_tools();

		if ( uonix_ksio_is_owner() ) {
			add_menu_page( 'ksio.dev', 'ksio.dev', 'manage_options', 'ksio-dev', 'uox_content_render_ksio_tools_home', 'dashicons-admin-tools', 58 );
			add_submenu_page( 'ksio-dev', 'Ferramentas ksio.dev', 'Visão Geral', 'manage_options', 'ksio-dev', 'uox_content_render_ksio_tools_home' );
			foreach ( $ferramentas as $ferramenta ) {
				add_submenu_page( 'ksio-dev', $ferramenta['title'], $ferramenta['title'], $ferramenta['capability'], $ferramenta['slug'], $ferramenta['callback'] );
			}
			add_submenu_page( 'ksio-dev', 'Visibilidade para usuários', 'Visibilidade para usuários', 'manage_options', 'ksio-dev-visibilidade', 'uonix_ksio_render_visibility_page' );
			return;
		}

		foreach ( uonix_ksio_tools_visibility() as $chave => $visivel ) {
			if ( ! $visivel ) {
				continue;
			}
			$ferramenta = $ferramentas[ $chave ];
			add_menu_page( $ferramenta['title'], $ferramenta['title'], $ferramenta['capability'], $ferramenta['slug'], $ferramenta['callback'], $ferramenta['icon'], $ferramenta['position'] );
		}
	}
}
add_action( 'admin_menu', 'uonix_ksio_register_menus', 20 );

if ( ! function_exists( 'uonix_ksio_render_visibility_page' ) ) {
	/**
	 * Tela do dono para escolher o que os demais usuários veem.
	 */
	function uonix_ksio_render_visibility_page() {
		if ( ! uonix_ksio_is_owner() ) {
			wp_die( 'Você não tem permissão para acessar esta página.', '', array( 'response' => 403 ) );
		}
		$visivel = uonix_ksio_tools_visibility();
		$quem    = array(
			'manage_options' => 'administradores',
			'edit_posts'     => 'administradores e editores',
		);
		?>
		<div class="wrap">
			<h1>Visibilidade para usuários</h1>
			<?php if ( isset( $_GET['uonix_ksio_saved'] ) ) : ?>
				<div class="notice notice-success"><p>Visibilidade salva.</p></div>
			<?php endif; ?>
			<p>Ferramenta marcada aparece para os demais usuários como item próprio do menu. O menu ksio.dev continua só seu.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uonix_ksio_save_visibility">
				<?php wp_nonce_field( 'uonix_ksio_save_visibility' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( uonix_ksio_tools() as $chave => $ferramenta ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $ferramenta['title'] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="uonix_ksio_visible[<?php echo esc_attr( $chave ); ?>]" value="1" <?php echo ! empty( $visivel[ $chave ] ) ? 'checked' : ''; ?>>
									<?php echo esc_html( 'Visível para ' . ( $quem[ $ferramenta['capability'] ] ?? $ferramenta['capability'] ) ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( 'Salvar visibilidade' ); ?>
			</form>
		</div>
		<?php
	}
}

if ( ! function_exists( 'uonix_ksio_save_visibility' ) ) {
	/**
	 * Grava a visibilidade. Só o dono, com nonce. Percorre as chaves do REGISTRO, nunca
	 * os nomes vindos do POST: um campo extra não cria opção nem ferramenta (a lição
	 * da #249).
	 */
	function uonix_ksio_save_visibility() {
		if ( ! uonix_ksio_is_owner() ) {
			wp_die( 'Sem permissão para alterar a visibilidade das ferramentas.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'uonix_ksio_save_visibility' );

		$marcados = isset( $_POST['uonix_ksio_visible'] ) && is_array( $_POST['uonix_ksio_visible'] ) ? $_POST['uonix_ksio_visible'] : array();
		$novo     = array();
		foreach ( array_keys( uonix_ksio_tools() ) as $chave ) {
			$novo[ $chave ] = ! empty( $marcados[ $chave ] );
		}
		update_option( uonix_ksio_visibility_option(), $novo, true );

		wp_safe_redirect( add_query_arg( array( 'page' => 'ksio-dev-visibilidade', 'uonix_ksio_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
add_action( 'admin_post_uonix_ksio_save_visibility', 'uonix_ksio_save_visibility' );
