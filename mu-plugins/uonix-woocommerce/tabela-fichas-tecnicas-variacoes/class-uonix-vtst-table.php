<?php
/**
 * Tabela consolidada de fichas técnicas por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTST_Table {
	public const TAB_KEY   = 'uonix_variation_technical_specifications';
	public const TAB_TITLE = 'Especificações Técnicas';
	public const DIAGRAM_IMAGE_META_KEY = '_uonix_vtst_diagram_image_id';

	private const EMPTY_VALUE = '—';

	/**
	 * Registra a aba depois do plugin externo de abas.
	 */
	public static function register_hooks() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'replace_additional_information_tab' ), 30, 1 );
	}

	/**
	 * Monta a matriz consolidada sem persistir HTML.
	 *
	 * @param mixed $product Produto pai.
	 * @return array<string, mixed>|null
	 */
	public static function build_matrix( $product ) {
		if (
			! is_object( $product ) ||
			! method_exists( $product, 'is_type' ) ||
			! $product->is_type( 'variable' ) ||
			! method_exists( $product, 'get_children' )
		) {
			return null;
		}

		$children = $product->get_children();
		if ( ! is_array( $children ) || empty( $children ) ) {
			return null;
		}

		$attribute_columns = self::attribute_columns( $product, $children );
		$technical_columns = array();
		$rows              = array();
		$has_valid_sheet   = false;

		foreach ( $children as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : false;
			if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_attributes' ) ) {
				continue;
			}

			$sheet = self::valid_sheet( $variation );
			if ( null !== $sheet && self::has_duplicate_item_labels( $sheet ) ) {
				return null;
			}
			if ( null !== $sheet ) {
				$has_valid_sheet = true;
			}

			$rows[] = array(
				'attribute_cells' => self::attribute_cells( $variation, $attribute_columns ),
				'technical_values' => self::sheet_values( $sheet, $technical_columns ),
				'has_valid_sheet' => null !== $sheet,
			);
		}

		if ( ! $has_valid_sheet || empty( $rows ) ) {
			return null;
		}

		foreach ( $rows as &$row ) {
			foreach ( $technical_columns as $label ) {
				if ( ! array_key_exists( $label, $row['technical_values'] ) ) {
					$row['technical_values'][ $label ] = self::EMPTY_VALUE;
				}
			}
		}
		unset( $row );

		return array(
			'attribute_columns' => $attribute_columns,
			'technical_columns' => $technical_columns,
			'rows'              => $rows,
		);
	}

	/**
	 * Troca a aba nativa de atributos somente quando existir uma matriz válida.
	 *
	 * @param mixed $tabs Abas WooCommerce.
	 * @return mixed
	 */
	public static function replace_additional_information_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$matrix = self::build_matrix( self::current_product() );
		if ( null === $matrix ) {
			return $tabs;
		}

		unset( $tabs['additional_information'] );
		$tabs[ self::TAB_KEY ] = array(
			'title'    => self::TAB_TITLE,
			'priority' => 25,
			'callback' => array( __CLASS__, 'render_tab' ),
		);

		return $tabs;
	}

	/**
	 * Renderiza a tabela automática.
	 *
	 * @param mixed $key Chave da aba.
	 * @param mixed $tab Dados da aba.
	 */
	public static function render_tab( $key, $tab ) {
		$matrix = self::build_matrix( self::current_product() );
		if ( null === $matrix ) {
			return;
		}

		self::render_diagram( self::current_product() );
		echo '<table class="woocommerce-product-attributes shop_attributes uonix-vtst-table">';
		echo '<thead><tr>';
		foreach ( $matrix['attribute_columns'] as $column ) {
			echo '<th scope="col">' . esc_html( $column['label'] ) . '</th>';
		}
		foreach ( $matrix['technical_columns'] as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $matrix['rows'] as $row ) {
			echo '<tr>';
			foreach ( $row['attribute_cells'] as $index => $cell ) {
				$tag = 0 === $index ? 'th scope="row"' : 'td';
				echo '<' . $tag . '>';
				if ( '' !== $cell['url'] ) {
					echo '<a href="' . esc_url( $cell['url'] ) . '" rel="tag">' . esc_html( $cell['text'] ) . '</a>';
				} else {
					echo esc_html( $cell['text'] );
				}
				echo 0 === $index ? '</th>' : '</td>';
			}
			foreach ( $matrix['technical_columns'] as $label ) {
				echo '<td>' . esc_html( $row['technical_values'][ $label ] ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param mixed $product Produto pai.
	 * @param array<int, mixed> $children IDs das variações.
	 * @return array<int, array<string, string>>
	 */
	private static function attribute_columns( $product, array $children ) {
		$parent_attributes = method_exists( $product, 'get_attributes' ) ? $product->get_attributes() : array();
		$columns           = array();

		if ( is_array( $parent_attributes ) ) {
			foreach ( $parent_attributes as $name => $attribute ) {
				$name = self::attribute_name( $name, $attribute );
				if ( '' !== $name ) {
					$columns[ $name ] = self::attribute_column( $name );
				}
			}
		}

		foreach ( $children as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : false;
			if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_attributes' ) ) {
				continue;
			}
			$attributes = $variation->get_attributes();
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $name => $value ) {
				$name = self::attribute_name( $name, null );
				if ( '' !== $name && ! isset( $columns[ $name ] ) ) {
					$columns[ $name ] = self::attribute_column( $name );
				}
			}
		}

		return array_values( $columns );
	}

	/**
	 * @param string|int $raw_name Nome bruto.
	 * @param mixed $attribute Atributo do produto pai.
	 * @return string
	 */
	private static function attribute_name( $raw_name, $attribute ) {
		if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
			$raw_name = $attribute->get_name();
		}
		$name = (string) $raw_name;
		return 0 === strpos( $name, 'attribute_' ) ? substr( $name, strlen( 'attribute_' ) ) : $name;
	}

	/**
	 * @param string $name Nome de atributo.
	 * @return array<string, string>
	 */
	private static function attribute_column( $name ) {
		return array(
			'key'      => $name,
			'label'    => function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name ) : $name,
			'taxonomy' => $name,
		);
	}

	/**
	 * @param mixed $variation Variação.
	 * @return array<string, mixed>|null
	 */
	private static function valid_sheet( $variation ) {
		if ( ! class_exists( 'Uonix_VTS_Schema' ) || ! method_exists( $variation, 'get_meta' ) ) {
			return null;
		}
		$stored = $variation->get_meta( Uonix_VTS_Schema::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$normalized = Uonix_VTS_Schema::normalize_sheet( $stored );
		return ! empty( $normalized['ok'] ) ? $normalized['sheet'] : null;
	}

	/**
	 * @param array<string, mixed>|null $sheet Ficha normalizada.
	 * @param array<int, string> $technical_columns Colunas encontradas por referência.
	 * @return array<string, string>
	 */
	private static function sheet_values( $sheet, array &$technical_columns ) {
		$values = array();
		if ( ! is_array( $sheet ) || empty( $sheet['sections'] ) || ! is_array( $sheet['sections'] ) ) {
			return $values;
		}

		foreach ( $sheet['sections'] as $section ) {
			foreach ( $section['items'] ?? array() as $item ) {
				$label = isset( $item['label'] ) ? (string) $item['label'] : '';
				$value = isset( $item['value'] ) ? (string) $item['value'] : '';
				if ( '' === $label || '' === $value ) {
					continue;
				}
				if ( ! in_array( $label, $technical_columns, true ) ) {
					$technical_columns[] = $label;
				}
				$values[ $label ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Recusa uma ficha cuja tabela consolidada perderia valores por rótulo igual.
	 *
	 * @param array<string, mixed> $sheet Ficha normalizada.
	 * @return bool
	 */
	private static function has_duplicate_item_labels( array $sheet ) {
		$labels = array();
		foreach ( $sheet['sections'] as $section ) {
			foreach ( $section['items'] as $item ) {
				$label = (string) $item['label'];
				if ( isset( $labels[ $label ] ) ) {
					return true;
				}
				$labels[ $label ] = true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $variation Variação.
	 * @param array<int, array<string, string>> $columns Colunas.
	 * @return array<int, array<string, string>>
	 */
	private static function attribute_cells( $variation, array $columns ) {
		$attributes = $variation->get_attributes();
		$cells      = array();
		foreach ( $columns as $column ) {
			$value = isset( $attributes[ $column['key'] ] ) ? (string) $attributes[ $column['key'] ] : '';
			$cells[] = self::attribute_cell( $column['taxonomy'], $value );
		}
		return $cells;
	}

	/**
	 * @param string $taxonomy Taxonomia ou nome de atributo.
	 * @param string $value Slug ou valor de atributo.
	 * @return array<string, string>
	 */
	private static function attribute_cell( $taxonomy, $value ) {
		if ( '' === $value ) {
			return array( 'text' => self::EMPTY_VALUE, 'url' => '' );
		}
		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) && function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $term ) ) && isset( $term->name ) ) {
				$url = function_exists( 'get_term_link' ) ? get_term_link( $term ) : '';
				return array(
					'text' => (string) $term->name,
					'url'  => is_string( $url ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $url ) ) ? $url : '',
				);
			}
		}
		return array( 'text' => $value, 'url' => '' );
	}

	/**
	 * @return mixed
	 */
	private static function current_product() {
		global $product;
		return isset( $product ) ? $product : null;
	}

	/**
	 * Exibe a imagem de cotas escolhida no produto, quando houver uma mídia válida.
	 *
	 * @param mixed $product Produto pai.
	 */
	private static function render_diagram( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'wp_get_attachment_image' ) ) {
			return;
		}

		$image_id = self::diagram_image_id( $product->get_id() );
		if ( ! $image_id ) {
			return;
		}

		$image = wp_get_attachment_image(
			$image_id,
			'large',
			false,
			array(
				'class'   => 'uonix-vtst-diagram__image',
				'loading' => 'lazy',
				'alt'     => 'Esquema técnico de dimensões',
			)
		);
		if ( ! is_string( $image ) || '' === $image ) {
			return;
		}

		echo '<figure class="uonix-vtst-diagram">' . $image . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Resolve a escolha explícita e, na ausência dela, aproveita uma mídia
	 * identificável da aba manual legada sem copiar ou modificar seu conteúdo.
	 *
	 * @param int $product_id ID do produto.
	 * @return int
	 */
	private static function diagram_image_id( $product_id ) {
		$image_id = absint( get_post_meta( $product_id, self::DIAGRAM_IMAGE_META_KEY, true ) );
		if ( $image_id ) {
			return $image_id;
		}

		$tabs = get_post_meta( $product_id, 'wb_custom_tabs', true );
		if ( ! is_array( $tabs ) ) {
			return 0;
		}

		foreach ( $tabs as $tab ) {
			if ( ! self::is_technical_legacy_tab( $tab ) ) {
				continue;
			}
			$content = isset( $tab['content'] ) ? (string) $tab['content'] : '';
			if ( 1 === preg_match( '/\bwp-image-(\d+)\b/', $content, $matches ) ) {
				return absint( $matches[1] );
			}
		}

		return 0;
	}

	/**
	 * Aceita fallback apenas de abas manuais destinadas a medidas/especificações.
	 *
	 * @param mixed $tab Dados da aba legada.
	 * @return bool
	 */
	private static function is_technical_legacy_tab( $tab ) {
		if ( ! is_array( $tab ) ) {
			return false;
		}

		$label = '';
		foreach ( array( 'title', 'nickname' ) as $key ) {
			if ( isset( $tab[ $key ] ) && is_scalar( $tab[ $key ] ) ) {
				$label .= ' ' . (string) $tab[ $key ];
			}
		}

		return false !== stripos( $label, 'dimens' ) || false !== stripos( $label, 'especifica' );
	}
}
