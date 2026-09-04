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

		$attribute_columns       = self::attribute_columns( $product, $children );
		$technical_columns       = array();
		$canonical_columns       = array();
		$raw_rows                = array();
		$has_valid_sheet         = false;
		$inherited_single_values = self::parent_single_value_attributes( $product );

		$canonical_inherited = array();
		foreach ( $inherited_single_values as $inh_label => $inh_val ) {
			$canonical_inherited[ self::canonical_key( $inh_label ) ] = $inh_val;
		}

		$column_sections = array();
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

			$raw_rows[] = array(
				'variation'       => $variation,
				'raw_values'      => self::sheet_canonical_values( $sheet, $canonical_columns, $technical_columns, $column_sections ),
				'has_valid_sheet' => null !== $sheet,
			);
		}

		if ( ! $has_valid_sheet || empty( $raw_rows ) ) {
			return null;
		}

		foreach ( $inherited_single_values as $inherited_label => $inherited_val ) {
			$canon = self::canonical_key( $inherited_label );
			if ( ! isset( $canonical_columns[ $canon ] ) ) {
				$canonical_columns[ $canon ] = $inherited_label;
				$technical_columns[]         = $inherited_label;
			}
		}

		$global_tax_map = self::product_global_attribute_taxonomies( $product );
		$rows           = array();

		foreach ( $raw_rows as $raw_row ) {
			$technical_values = array();
			$technical_cells  = array();

			foreach ( $technical_columns as $label ) {
				$canon = self::canonical_key( $label );
				if ( isset( $raw_row['raw_values'][ $canon ] ) && '' !== $raw_row['raw_values'][ $canon ] ) {
					$val = $raw_row['raw_values'][ $canon ];
				} elseif ( isset( $canonical_inherited[ $canon ] ) ) {
					$val = $canonical_inherited[ $canon ];
				} else {
					$val = self::EMPTY_VALUE;
				}

				$taxonomy                   = self::match_global_taxonomy( $label, $global_tax_map );
				$cell                       = self::attribute_cell( $taxonomy, $val );
				$technical_values[ $label ] = $val;
				$technical_cells[ $label ]  = $cell;
			}

			$rows[] = array(
				'attribute_cells'  => self::attribute_cells( $raw_row['variation'], $attribute_columns ),
				'technical_values' => $technical_values,
				'technical_cells'  => $technical_cells,
				'has_valid_sheet'  => $raw_row['has_valid_sheet'],
			);
		}

		$column_groups = array();
		foreach ( $attribute_columns as $col ) {
			$column_groups[] = self::column_section_title( isset( $col['label'] ) ? $col['label'] : '' );
		}
		foreach ( $technical_columns as $label ) {
			$canon    = self::canonical_key( $label );
			$explicit = isset( $column_sections[ $canon ] ) ? $column_sections[ $canon ] : '';
			$column_groups[] = self::column_section_title( $label, $explicit );
		}

		$header_groups = array();
		foreach ( $column_groups as $sec_title ) {
			if ( empty( $header_groups ) ) {
				$header_groups[] = array(
					'title'   => $sec_title,
					'colspan' => 1,
				);
			} else {
				$last_idx = count( $header_groups ) - 1;
				if ( self::canonical_key( $header_groups[ $last_idx ]['title'] ) === self::canonical_key( $sec_title ) ) {
					$header_groups[ $last_idx ]['colspan']++;
				} else {
					$header_groups[] = array(
						'title'   => $sec_title,
						'colspan' => 1,
					);
				}
			}
		}

		return array(
			'attribute_columns' => $attribute_columns,
			'technical_columns' => $technical_columns,
			'header_groups'     => $header_groups,
			'rows'              => $rows,
		);
	}

	/**
	 * Retorna o título da seção se for uma coluna de seção/dimensão, ou vazio para atributos gerais.
	 *
	 * @param string $label Rótulo do campo.
	 * @param string $explicit_section Seção explícita da ficha técnica.
	 * @return string
	 */
	public static function column_section_title( $label, $explicit_section = '' ) {
		$clean_sec = trim( (string) $explicit_section );
		if ( '' !== $clean_sec && false !== mb_strpos( mb_strtolower( $clean_sec, 'UTF-8' ), 'dimen', 0, 'UTF-8' ) ) {
			return $clean_sec;
		}

		$trimmed_label = trim( (string) $label );
		if ( self::is_cota_column( $trimmed_label ) ) {
			return '' !== $clean_sec ? $clean_sec : 'Dimensões (mm)';
		}

		return '';
	}

	/**
	 * Identifica se um rótulo pertence a uma cota de dimensão (ex: A..L, R, H, W, Ø e compostos como L1, L2).
	 * Usa allowlist estrita para não promover códigos técnicos não dimensionais (ex: M10, N10, Z1).
	 *
	 * @param string $label Rótulo do campo.
	 * @return bool
	 */
	public static function is_cota_column( $label ) {
		$trimmed_label = trim( (string) $label );
		return (bool) preg_match( '/^(?:[A-LRHW]\d*|Ø|ø)(?:\s*\(mm\))?$/u', $trimmed_label );
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
		echo '<div class="uonix-vtst-wrapper">';
		echo '<div class="uonix-vtst-scroll-hint" aria-hidden="true"><span>&larr; Deslize para visualizar todas as especificações &rarr;</span></div>';
		echo '<div class="uonix-vtst-responsive-container">';
		echo '<table class="woocommerce-product-attributes shop_attributes uonix-vtst-table">';
		echo '<thead>';

		$has_named_group = false;
		if ( ! empty( $matrix['header_groups'] ) ) {
			foreach ( $matrix['header_groups'] as $group ) {
				if ( '' !== trim( (string) $group['title'] ) ) {
					$has_named_group = true;
					break;
				}
			}
		}

		if ( $has_named_group ) {
			echo '<tr class="uonix-vtst-group-row">';
			foreach ( $matrix['header_groups'] as $group ) {
				$title = trim( (string) $group['title'] );
				if ( '' !== $title ) {
					echo '<th scope="colgroup" colspan="' . absint( $group['colspan'] ) . '" class="uonix-vtst-group-header">' . esc_html( mb_strtoupper( $title, 'UTF-8' ) ) . '</th>';
				} else {
					echo '<td colspan="' . absint( $group['colspan'] ) . '" class="uonix-vtst-group-empty"></td>';
				}
			}
			echo '</tr>';
		}

		echo '<tr>';
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
				$cell = isset( $row['technical_cells'][ $label ] ) ? $row['technical_cells'][ $label ] : array(
					'text' => ( isset( $row['technical_values'][ $label ] ) ? $row['technical_values'][ $label ] : '' ),
					'url'  => '',
				);
				echo '<td>';
				if ( '' !== $cell['url'] ) {
					echo '<a href="' . esc_url( $cell['url'] ) . '" rel="tag">' . esc_html( $cell['text'] ) . '</a>';
				} else {
					echo esc_html( $cell['text'] );
				}
				echo '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Retorna atributos informativos do produto pai que possuem exatamente um valor (para herança).
	 *
	 * @param mixed $product Produto pai.
	 * @return array<string, string>
	 */
	public static function parent_single_value_attributes( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$parent_attributes = $product->get_attributes();
		if ( ! is_array( $parent_attributes ) ) {
			return array();
		}

		$single_values = array();

		foreach ( $parent_attributes as $name => $attribute ) {
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
			if ( '' === $label ) {
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

			$unique_options = array_values( array_unique( $options ) );
			if ( 1 === count( $unique_options ) ) {
				$single_values[ $label ] = $unique_options[0];
			}
		}

		return $single_values;
	}

	/**
	 * @param mixed $product Produto pai.
	 * @param array<int, mixed> $children IDs das variações.
	 * @return array<int, array<string, string>>
	 */
	public static function attribute_columns( $product, array $children ) {
		$parent_attributes = method_exists( $product, 'get_attributes' ) ? $product->get_attributes() : array();
		$columns           = array();

		if ( is_array( $parent_attributes ) ) {
			foreach ( $parent_attributes as $name => $attribute ) {
				if ( is_object( $attribute ) && method_exists( $attribute, 'get_variation' ) && ! $attribute->get_variation() ) {
					continue;
				}
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
	 * Retorna chave canônica insensível a maiúsculas/minúsculas para agrupamento de rótulos.
	 *
	 * @param mixed $label Rótulo do campo.
	 * @return string
	 */
	public static function canonical_key( $label ) {
		return mb_strtolower( trim( (string) $label ), 'UTF-8' );
	}

	/**
	 * Coleta valores da ficha técnica indexados por chave canônica e registra as colunas preservando o primeiro rótulo exibível.
	 *
	 * @param array<string, mixed>|null $sheet Ficha normalizada.
	 * @param array<string, string> $canonical_columns Mapa de chave canônica para primeiro display label encontrado.
	 * @param array<int, string> $technical_columns Colunas ordenadas por referência.
	 * @return array<string, string> Mapa canonical_key => value
	 */
	private static function sheet_canonical_values( $sheet, array &$canonical_columns, array &$technical_columns, array &$column_sections = array() ) {
		$values = array();
		if ( ! is_array( $sheet ) || empty( $sheet['sections'] ) || ! is_array( $sheet['sections'] ) ) {
			return $values;
		}

		foreach ( $sheet['sections'] as $section ) {
			$sec_title = isset( $section['title'] ) ? trim( (string) $section['title'] ) : '';
			foreach ( $section['items'] ?? array() as $item ) {
				$label = isset( $item['label'] ) ? (string) $item['label'] : '';
				$value = isset( $item['value'] ) ? (string) $item['value'] : '';
				$canon = self::canonical_key( $label );
				if ( '' === $canon || '' === $value ) {
					continue;
				}
				if ( ! isset( $canonical_columns[ $canon ] ) ) {
					$canonical_columns[ $canon ] = $label;
					$technical_columns[]         = $label;
					if ( '' !== $sec_title ) {
						$column_sections[ $canon ] = $sec_title;
					}
				}
				$values[ $canon ] = $value;
			}
		}

		return $values;
	}

	/**
	 * @param array<string, mixed>|null $sheet Ficha normalizada.
	 * @param array<int, string> $technical_columns Colunas encontradas por referência.
	 * @return array<string, string>
	 */
	private static function sheet_values( $sheet, array &$technical_columns ) {
		$canonical_columns = array();
		foreach ( $technical_columns as $col ) {
			$canonical_columns[ self::canonical_key( $col ) ] = $col;
		}
		return self::sheet_canonical_values( $sheet, $canonical_columns, $technical_columns );
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
			foreach ( $section['items'] ?? array() as $item ) {
				$label = self::canonical_key( isset( $item['label'] ) ? $item['label'] : '' );
				if ( '' === $label ) {
					continue;
				}
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
		if ( '' === $value || self::EMPTY_VALUE === $value ) {
			return array( 'text' => self::EMPTY_VALUE, 'url' => '' );
		}
		if ( '' !== $taxonomy && function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) && function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( ! $term || ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
				$term = get_term_by( 'name', $value, $taxonomy );
			}
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
	 * Mapeia atributos globais (taxonomias) do produto para seus respectivos nomes de taxonomia.
	 * Atributos locais/personalizados do produto (não globais) são explicitamente excluídos.
	 *
	 * @param mixed $product Produto.
	 * @return array<string, string> Mapa [rótulo_normalizado => taxonomia]
	 */
	public static function product_global_attribute_taxonomies( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}
		$attributes = $product->get_attributes();
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$global_map = array();
		$custom_set = array();

		foreach ( $attributes as $name => $attribute ) {
			if ( ! is_object( $attribute ) ) {
				continue;
			}

			$attr_name = method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : (string) $name;
			$label     = '';
			if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
				$tax_obj = method_exists( $attribute, 'get_taxonomy_object' ) ? $attribute->get_taxonomy_object() : null;
				if ( $tax_obj && isset( $tax_obj->attribute_label ) && '' !== trim( (string) $tax_obj->attribute_label ) ) {
					$label = (string) $tax_obj->attribute_label;
				} elseif ( function_exists( 'wc_attribute_label' ) ) {
					$label = (string) wc_attribute_label( $attr_name );
				}
			} else {
				$label = $attr_name;
			}

			$label = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $label, true ) : strip_tags( $label );
			$label = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $label ) : trim( $label );

			$is_global = method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy();

			if ( ! $is_global ) {
				if ( '' !== $label ) {
					$custom_set[ mb_strtolower( $label, 'UTF-8' ) ] = true;
				}
				$custom_set[ mb_strtolower( $attr_name, 'UTF-8' ) ] = true;
				continue;
			}

			$taxonomy = $attr_name;
			if ( '' !== $label ) {
				$global_map[ mb_strtolower( $label, 'UTF-8' ) ] = $taxonomy;
			}
			$global_map[ mb_strtolower( $taxonomy, 'UTF-8' ) ] = $taxonomy;
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				$global_map[ mb_strtolower( substr( $taxonomy, 3 ), 'UTF-8' ) ] = $taxonomy;
			}
		}

		foreach ( $custom_set as $custom_key => $_ ) {
			unset( $global_map[ $custom_key ] );
		}

		return $global_map;
	}

	/**
	 * Localiza a taxonomia global associada a um rótulo, se for atributo global do produto.
	 *
	 * @param string $label Rótulo do campo técnico.
	 * @param array<string, string> $global_tax_map Mapa de taxonomias globais.
	 * @return string Nome da taxonomia ou vazio.
	 */
	public static function match_global_taxonomy( $label, array $global_tax_map ) {
		$normalized = mb_strtolower( trim( (string) $label ), 'UTF-8' );
		if ( '' !== $normalized && isset( $global_tax_map[ $normalized ] ) ) {
			return $global_tax_map[ $normalized ];
		}
		return '';
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
