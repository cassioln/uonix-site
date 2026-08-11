<?php
/**
 * Editor administrativo e persistência da ficha técnica por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTS_Admin {
	/**
	 * Registra somente os pontos públicos do editor de variações.
	 */
	public static function register_hooks() {
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_editor' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 10, 1 );
	}

	/**
	 * Carrega o editor somente nas telas de produto.
	 *
	 * @param mixed $hook_suffix Identificador da tela administrativa.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->post_type ) || 'product' !== $screen->post_type ) {
			return;
		}

		$script_relative = 'uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js';
		$style_relative  = 'uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css';
		wp_enqueue_script(
			'uonix-vts-admin',
			UONIX_MU_URL . $script_relative,
			array( 'jquery', 'jquery-ui-sortable' ),
			(string) filemtime( UONIX_MU_PATH . $script_relative ),
			true
		);
		wp_enqueue_style(
			'uonix-vts-admin',
			UONIX_MU_URL . $style_relative,
			array(),
			(string) filemtime( UONIX_MU_PATH . $style_relative )
		);
		wp_localize_script(
			'uonix-vts-admin',
			'uonixVtsAdmin',
			array(
				'parentId' => function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0,
				'strings'  => array(
					'removeConfirm' => 'Remover a ficha técnica desta variação ao salvar?',
					'payloadError'  => 'Não foi possível carregar a ficha técnica salva.',
				),
			)
		);
	}

	/**
	 * Renderiza o shell acessível do editor inline de uma variação.
	 *
	 * @param mixed $loop Índice da variação no formulário.
	 * @param mixed $variation_data Dados nativos fornecidos pelo WooCommerce.
	 * @param mixed $variation_post Post da variação.
	 */
	public static function render_editor( $loop, $variation_data, $variation_post ) {
		if ( ! is_object( $variation_post ) || ! isset( $variation_post->ID ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$variation = wc_get_product( absint( $variation_post->ID ) );
		if (
			! is_object( $variation ) ||
			! method_exists( $variation, 'get_id' ) ||
			! method_exists( $variation, 'get_parent_id' ) ||
			! method_exists( $variation, 'get_meta' )
		) {
			return;
		}

		$parent_id = absint( $variation->get_parent_id() );
		if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
			return;
		}

		$has_sheet = false;
		$payload   = '';
		$stored    = $variation->get_meta( Uonix_VTS_Schema::META_KEY, true );
		if ( is_array( $stored ) ) {
			$normalized = Uonix_VTS_Schema::normalize_sheet( $stored );
			if ( $normalized['ok'] ) {
				$encoded = wp_json_encode(
					array(
						'action' => 'upsert',
						'sheet'  => $normalized['sheet'],
					)
				);
				if ( is_string( $encoded ) ) {
					$has_sheet = true;
					$payload   = $encoded;
				}
			}
		}

		$subtitle = array();
		foreach ( Uonix_VTS_Renderer::attribute_pairs( $variation ) as $pair ) {
			$subtitle[] = $pair['label'] . ': ' . $pair['value'];
		}

		$root_class   = 'uonix-vts-admin' . ( $has_sheet ? ' is-active' : '' );
		$variation_id = absint( $variation->get_id() );
		$field_name   = 'uonix_variation_technical_sheet[' . absint( $loop ) . ']';
		?>
		<div class="<?php echo esc_attr( $root_class ); ?>" data-had-sheet="<?php echo esc_attr( $has_sheet ? '1' : '0' ); ?>" data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>">
			<input type="hidden" class="uonix-vts-admin__payload" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $payload ); ?>"<?php if ( ! $has_sheet ) : ?> disabled<?php endif; ?>>
			<button type="button" class="button uonix-vts-admin__add">Adicionar ficha técnica</button>
			<div class="uonix-vts-admin__editor">
				<label>Título geral<input type="text" class="uonix-vts-admin__sheet-title" aria-label="Título geral da ficha técnica"></label>
				<label>Cabeçalho automático<input type="text" class="uonix-vts-admin__subtitle" aria-label="Cabeçalho automático da variação" readonly value="<?php echo esc_attr( implode( ' · ', $subtitle ) ); ?>"></label>
				<div class="uonix-vts-admin__sections"></div>
				<button type="button" class="button uonix-vts-admin__add-section">Adicionar seção</button>
				<button type="button" class="button-link-delete uonix-vts-admin__remove-sheet">Remover ficha</button>
			</div>
			<template class="uonix-vts-admin__section-template">
				<section class="uonix-vts-admin__section">
					<div class="uonix-vts-admin__section-head">
						<button type="button" class="button-link uonix-vts-admin__section-handle" aria-label="Reordenar seção">↕</button>
						<input type="text" class="uonix-vts-admin__section-title" aria-label="Título opcional da seção" placeholder="Título opcional">
						<select class="uonix-vts-admin__section-layout" aria-label="Formato da seção">
							<option value="compact">Compacta</option>
							<option value="detailed">Detalhada</option>
						</select>
						<button type="button" class="button-link uonix-vts-admin__remove-section" aria-label="Remover seção">×</button>
					</div>
					<div class="uonix-vts-admin__items"></div>
					<button type="button" class="button uonix-vts-admin__add-item">Adicionar item</button>
				</section>
			</template>
			<template class="uonix-vts-admin__item-template">
				<div class="uonix-vts-admin__item">
					<button type="button" class="button-link uonix-vts-admin__item-handle" aria-label="Reordenar item">↕</button>
					<input type="text" class="uonix-vts-admin__item-label" aria-label="Rótulo do item" placeholder="Rótulo">
					<input type="text" class="uonix-vts-admin__item-value" aria-label="Valor do item" placeholder="Valor">
					<button type="button" class="button-link uonix-vts-admin__remove-item" aria-label="Remover item">×</button>
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * Valida e anexa a mutação de meta ao objeto que o WooCommerce salvará.
	 *
	 * @param mixed $variation Objeto de variação WooCommerce.
	 * @param mixed $loop Índice da variação no formulário.
	 */
	public static function save_variation( $variation, $loop ) {
		if (
			! is_object( $variation ) ||
			! method_exists( $variation, 'get_parent_id' ) ||
			! method_exists( $variation, 'get_id' ) ||
			! method_exists( $variation, 'update_meta_data' ) ||
			! method_exists( $variation, 'delete_meta_data' )
		) {
			return;
		}
		if ( ! isset( $_POST['uonix_variation_technical_sheet'] ) || ! is_array( $_POST['uonix_variation_technical_sheet'] ) ) {
			return;
		}
		if ( ! array_key_exists( $loop, $_POST['uonix_variation_technical_sheet'] ) ) {
			return;
		}

		$parent_id = absint( $variation->get_parent_id() );
		if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
			return;
		}

		$raw    = wp_unslash( $_POST['uonix_variation_technical_sheet'][ $loop ] );
		$result = Uonix_VTS_Schema::normalize_envelope( $raw );
		if ( ! $result['ok'] ) {
			WC_Admin_Meta_Boxes::add_error(
				'A ficha técnica da variação #' . absint( $variation->get_id() ) . ' não foi salva: ' . $result['message']
			);
			return;
		}
		if ( 'delete' === $result['action'] ) {
			$variation->delete_meta_data( Uonix_VTS_Schema::META_KEY );
			return;
		}

		$variation->update_meta_data( Uonix_VTS_Schema::META_KEY, $result['sheet'] );
	}
}
