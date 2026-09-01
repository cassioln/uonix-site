<?php
/**
 * Campo administrativo da imagem de cotas da tabela técnica consolidada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTST_Diagram_Admin {
	/**
	 * Registra hooks administrativos do seletor de imagem por produto.
	 */
	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 10, 1 );
		add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Adiciona o metabox à lateral do editor de produto.
	 */
	public static function register_metabox() {
		add_meta_box(
			'uonix_vtst_diagram_image',
			'Imagem do Esquema Técnico',
			array( __CLASS__, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Carrega a biblioteca de mídia e o script apenas ao editar produtos.
	 *
	 * @param mixed $hook_suffix Identificador da tela atual.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->post_type ) || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		$relative = 'uonix-woocommerce/assets/js/admin-vtst-diagram-image.js';
		wp_enqueue_script(
			'uonix-vtst-diagram-image',
			UONIX_MU_URL . $relative,
			array( 'jquery' ),
			(string) filemtime( UONIX_MU_PATH . $relative ),
			true
		);
	}

	/**
	 * Renderiza seletor e prévia da imagem, sem depender da aba manual legada.
	 *
	 * @param mixed $post Post do produto.
	 */
	public static function render_metabox( $post ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : 0;
		$image_id = $post_id ? absint( get_post_meta( $post_id, Uonix_VTST_Table::DIAGRAM_IMAGE_META_KEY, true ) ) : 0;
		$image = $image_id ? wp_get_attachment_image( $image_id, 'medium', false, array( 'class' => 'uonix-vtst-diagram-admin__preview-image' ) ) : '';

		wp_nonce_field( 'uonix_vtst_diagram_image_save', 'uonix_vtst_diagram_image_nonce' );
		echo '<p>Opcional. Esta imagem aparece acima da tabela automática de Especificações Técnicas.</p>';
		echo '<input type="hidden" id="uonix-vtst-diagram-image-id" name="uonix_vtst_diagram_image_id" value="' . esc_attr( (string) $image_id ) . '">';
		echo '<p><button type="button" class="button uonix-vtst-diagram-image-select">Selecionar imagem</button> <button type="button" class="button-link-delete uonix-vtst-diagram-image-remove">Remover</button></p>';
		echo '<div class="uonix-vtst-diagram-admin__preview">' . $image . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Salva somente um attachment ID explicitamente submetido e autorizado.
	 *
	 * @param int $post_id ID do produto.
	 * @param mixed $post Post em salvamento.
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['uonix_vtst_diagram_image_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['uonix_vtst_diagram_image_nonce'] ) ), 'uonix_vtst_diagram_image_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! array_key_exists( 'uonix_vtst_diagram_image_id', $_POST ) ) {
			return;
		}

		$image_id = absint( wp_unslash( $_POST['uonix_vtst_diagram_image_id'] ) );
		if ( $image_id ) {
			update_post_meta( $post_id, Uonix_VTST_Table::DIAGRAM_IMAGE_META_KEY, $image_id );
			return;
		}
		delete_post_meta( $post_id, Uonix_VTST_Table::DIAGRAM_IMAGE_META_KEY );
	}
}
