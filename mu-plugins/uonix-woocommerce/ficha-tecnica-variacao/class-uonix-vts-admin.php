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
		add_action( 'wp_ajax_uonix_get_variation_technical_sheet', array( __CLASS__, 'ajax_get_copy_sheet' ), 10, 0 );
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
		$parent_id = function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
		wp_localize_script(
			'uonix-vts-admin',
			'uonixVtsAdmin',
			array(
				'parentId'         => $parent_id,
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'uonix_variation_technical_sheet_copy' ),
				'copyAction'       => 'uonix_get_variation_technical_sheet',
				'copyOptions'      => self::copy_options( $parent_id ),
				'parentAttributes' => self::parent_non_variation_attributes( $parent_id ),
				'strings'          => array(
					'removeConfirm'   => 'Remover a ficha técnica desta variação ao salvar?',
					'payloadError'    => 'Não foi possível carregar a ficha técnica salva.',
					'copyConfirm'     => 'Substituir a ficha atual pela ficha selecionada?',
					'copyError'       => 'Não foi possível copiar a ficha selecionada.',
					'copyPlaceholder' => 'Selecione uma variação',
				),
			)
		);
	}

	/**
	 * Retorna os atributos informativos do produto pai que não são usados para variação.
	 *
	 * @param mixed $parent_id Produto variável pai.
	 * @return array<int, array{label:string,options:array<int, string>}>
	 */
	public static function parent_non_variation_attributes( $parent_id ) {
		$parent_id = absint( $parent_id );
		if ( ! $parent_id || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $parent_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$attributes = $product->get_attributes();
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$results     = array();
		$seen_labels = array();

		foreach ( $attributes as $name => $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_variation' ) && $attribute->get_variation() ) {
				continue;
			}

			$label = '';
			if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
				$tax = method_exists( $attribute, 'get_taxonomy_object' ) ? $attribute->get_taxonomy_object() : null;
				if ( $tax && isset( $tax->attribute_label ) && '' !== trim( (string) $tax->attribute_label ) ) {
					$label = (string) $tax->attribute_label;
				} elseif ( function_exists( 'wc_attribute_label' ) && method_exists( $attribute, 'get_name' ) ) {
					$label = (string) wc_attribute_label( $attribute->get_name() );
				}
			} elseif ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
				$label = (string) $attribute->get_name();
			} else {
				$label = (string) $name;
			}

			$label = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $label, true ) : strip_tags( $label );
			$label = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $label ) : trim( $label );
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
			if ( '' === $label || isset( $seen_labels[ $lower ] ) ) {
				continue;
			}

			$options = array();
			if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' ) && method_exists( $attribute, 'get_name' ) && method_exists( $product, 'get_id' ) ) {
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$val = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $term, true ) : strip_tags( (string) $term );
						$val = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $val ) : trim( $val );
						if ( '' !== $val ) {
							$options[] = $val;
						}
					}
				}
			} elseif ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
				$raw_options = $attribute->get_options();
				if ( is_array( $raw_options ) ) {
					foreach ( $raw_options as $raw ) {
						$val = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $raw, true ) : strip_tags( (string) $raw );
						$val = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $val ) : trim( $val );
						if ( '' !== $val ) {
							$options[] = $val;
						}
					}
				}
			}

			$results[] = array(
				'label'   => $label,
				'options' => array_values( array_unique( $options ) ),
			);
			$seen_labels[ $lower ] = true;
		}

		return $results;
	}

	/**
	 * Lista variações filhas válidas para o seletor de cópia.
	 *
	 * @param mixed $parent_id Produto variável pai.
	 * @return array<int, array{id:int,label:string}>
	 */
	public static function copy_options( $parent_id ) {
		$parent_id = absint( $parent_id );
		if ( ! $parent_id || ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_formatted_variation' ) ) {
			return array();
		}

		$parent = wc_get_product( $parent_id );
		if ( ! is_object( $parent ) || ! method_exists( $parent, 'get_children' ) ) {
			return array();
		}
		$children = $parent->get_children();
		if ( ! is_array( $children ) ) {
			return array();
		}

		$options = array();
		$seen    = array();
		foreach ( $children as $child_id ) {
			$child_id = absint( $child_id );
			if ( ! $child_id || isset( $seen[ $child_id ] ) ) {
				continue;
			}
			$variation = wc_get_product( $child_id );
			if (
				! is_object( $variation ) ||
				! method_exists( $variation, 'get_id' ) ||
				! method_exists( $variation, 'get_parent_id' ) ||
				$child_id !== absint( $variation->get_id() ) ||
				$parent_id !== absint( $variation->get_parent_id() )
			) {
				continue;
			}

			$formatted = wc_get_formatted_variation( $variation, true, false, false );
			$formatted = is_scalar( $formatted ) ? sanitize_text_field( wp_strip_all_tags( (string) $formatted, true ) ) : '';
			$options[] = array(
				'id'    => $child_id,
				'label' => sprintf( '#%d%s', $child_id, '' === $formatted ? '' : ' — ' . $formatted ),
			);
			$seen[ $child_id ] = true;
		}
		return $options;
	}

	/**
	 * Retorna somente uma ficha normalizada pertencente ao produto informado.
	 *
	 * @param mixed $source_id Variação de origem.
	 * @param mixed $parent_id Produto variável pai.
	 * @return array{ok:bool,code:string|null,message:string|null,sheet:array|null}
	 */
	public static function get_copy_sheet( $source_id, $parent_id ) {
		$source_id = absint( $source_id );
		$parent_id = absint( $parent_id );
		if ( ! $source_id || ! $parent_id || ! function_exists( 'wc_get_product' ) ) {
			return self::copy_failure( 'invalid_source', 'A variação de origem é inválida.' );
		}

		$variation = wc_get_product( $source_id );
		if (
			! is_object( $variation ) ||
			! method_exists( $variation, 'get_id' ) ||
			! method_exists( $variation, 'get_parent_id' ) ||
			! method_exists( $variation, 'get_meta' ) ||
			$source_id !== absint( $variation->get_id() ) ||
			$parent_id !== absint( $variation->get_parent_id() )
		) {
			return self::copy_failure( 'invalid_parent', 'A variação de origem não pertence a este produto.' );
		}

		$stored = $variation->get_meta( Uonix_VTS_Schema::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return self::copy_failure( 'missing_sheet', 'A variação de origem não possui ficha técnica válida.' );
		}
		$normalized = Uonix_VTS_Schema::normalize_sheet( $stored );
		if ( ! $normalized['ok'] ) {
			return self::copy_failure( 'invalid_sheet', 'A ficha técnica da variação de origem é inválida.' );
		}
		return array(
			'ok'      => true,
			'code'    => null,
			'message' => null,
			'sheet'   => $normalized['sheet'],
		);
	}

	/**
	 * Endpoint autenticado da cópia entre variações irmãs.
	 */
	public static function ajax_get_copy_sheet() {
		if ( false === check_ajax_referer( 'uonix_variation_technical_sheet_copy', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
			return;
		}

		$source_raw = isset( $_POST['source_id'] ) ? wp_unslash( $_POST['source_id'] ) : 0;
		$parent_raw = isset( $_POST['parent_id'] ) ? wp_unslash( $_POST['parent_id'] ) : 0;
		$source_id  = is_scalar( $source_raw ) ? absint( $source_raw ) : 0;
		$parent_id  = is_scalar( $parent_raw ) ? absint( $parent_raw ) : 0;
		if ( ! $source_id || ! $parent_id ) {
			wp_send_json_error( array( 'code' => 'invalid_request' ), 400 );
			return;
		}
		if ( ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
			return;
		}

		$result = self::get_copy_sheet( $source_id, $parent_id );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'code' => $result['code'] ), 422 );
			return;
		}
		wp_send_json_success( array( 'sheet' => $result['sheet'] ) );
	}

	/**
	 * @param string $code Código estável da falha.
	 * @param string $message Mensagem administrativa.
	 * @return array{ok:bool,code:string,message:string,sheet:null}
	 */
	private static function copy_failure( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'sheet'   => null,
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
				<div class="uonix-vts-admin__copy-actions">
					<label class="uonix-vts-admin__copy-control">Copiar de outra variação
						<select class="uonix-vts-admin__copy-source" aria-label="Variação de origem"></select>
					</label>
					<button type="button" class="button uonix-vts-admin__copy" disabled>Copiar</button>
				</div>
				<label>Título geral<input type="text" class="uonix-vts-admin__sheet-title" aria-label="Título geral da ficha técnica"></label>
				<label>Cabeçalho automático<input type="text" class="uonix-vts-admin__subtitle" aria-label="Cabeçalho automático da variação" readonly value="<?php echo esc_attr( implode( ' · ', $subtitle ) ); ?>"></label>
				<div class="uonix-vts-admin__sections"></div>
				<div class="uonix-vts-admin__sheet-footer">
					<button type="button" class="button uonix-vts-admin__add-section">Adicionar seção</button>
					<button type="button" class="button uonix-vts-admin__remove-sheet"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span>Remover ficha</span></button>
				</div>
			</div>
			<template class="uonix-vts-admin__section-template">
				<section class="uonix-vts-admin__section">
					<div class="uonix-vts-admin__section-head">
						<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__drag-handle uonix-vts-admin__section-handle" aria-label="Arrastar para reordenar seção"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
						<input type="text" class="uonix-vts-admin__section-title" aria-label="Título opcional da seção" placeholder="Título opcional">
						<select class="uonix-vts-admin__section-layout" aria-label="Formato da seção">
							<option value="compact">Compacta</option>
							<option value="detailed">Detalhada</option>
						</select>
						<div class="uonix-vts-admin__actions" role="group" aria-label="Ações da seção">
							<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__move-section-up" aria-label="Mover seção para cima"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
							<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__move-section-down" aria-label="Mover seção para baixo"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
							<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__icon-button--danger uonix-vts-admin__remove-section" aria-label="Remover seção"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
						</div>
					</div>
					<div class="uonix-vts-admin__items"></div>
					<div class="uonix-vts-admin__section-footer">
						<button type="button" class="button uonix-vts-admin__add-item">Adicionar item</button>
					</div>
				</section>
			</template>
			<template class="uonix-vts-admin__item-template">
				<div class="uonix-vts-admin__item">
					<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__drag-handle uonix-vts-admin__item-handle" aria-label="Arrastar para reordenar item"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
					<input type="text" class="uonix-vts-admin__item-label" aria-label="Rótulo do item" placeholder="Rótulo">
					<input type="text" class="uonix-vts-admin__item-value" aria-label="Valor do item" placeholder="Valor">
					<div class="uonix-vts-admin__actions" role="group" aria-label="Ações do item">
						<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__move-item-up" aria-label="Mover item para cima"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
						<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__move-item-down" aria-label="Mover item para baixo"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
						<button type="button" class="button uonix-vts-admin__icon-button uonix-vts-admin__icon-button--danger uonix-vts-admin__remove-item" aria-label="Remover item"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
					</div>
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
