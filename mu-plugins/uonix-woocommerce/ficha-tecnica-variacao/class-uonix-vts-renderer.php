<?php
/**
 * Renderer e integração frontend da ficha técnica por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTS_Renderer {
	/**
	 * Registra somente os pontos públicos necessários no frontend.
	 */
	public static function register_hooks() {
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'append_to_variation_data' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 10, 0 );
	}

	/**
	 * Retorna os atributos oficiais na ordem aprovada para o cabeçalho.
	 *
	 * @param mixed $variation Variação WooCommerce.
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function attribute_pairs( $variation ) {
		if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_attributes' ) ) {
			return array();
		}

		$attributes = $variation->get_attributes();
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$aliases = array(
			'pa_tipo'     => 'Modelo',
			'pa_material' => 'Material',
			'pa_bitola'   => 'Pol.',
		);
		$known     = array();
		$remaining = array();

		foreach ( $attributes as $raw_name => $raw_value ) {
			$name = (string) $raw_name;
			if ( 0 === strpos( $name, 'attribute_' ) ) {
				$name = substr( $name, strlen( 'attribute_' ) );
			}

			$value = self::official_attribute_value( $name, $raw_value );
			if ( '' === $value ) {
				continue;
			}

			$label = isset( $aliases[ $name ] )
				? $aliases[ $name ]
				: ( function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $variation ) : $name );
			$pair  = array(
				'label' => (string) $label,
				'value' => $value,
			);

			if ( isset( $aliases[ $name ] ) ) {
				$known[ $name ] = $pair;
			} else {
				$remaining[] = $pair;
			}
		}

		$pairs = array();
		foreach ( array_keys( $aliases ) as $name ) {
			if ( isset( $known[ $name ] ) ) {
				$pairs[] = $known[ $name ];
			}
		}

		return array_merge( $pairs, $remaining );
	}

	/**
	 * Gera marcação escapada para uma ficha normalizada.
	 *
	 * @param array $sheet Ficha no esquema v1.
	 * @param mixed $variation Variação WooCommerce.
	 * @return string
	 */
	public static function render( array $sheet, $variation ) {
		$normalized = Uonix_VTS_Schema::normalize_sheet( $sheet );
		if ( ! $normalized['ok'] ) {
			return '';
		}
		$sheet = $normalized['sheet'];

		$subtitle = array();
		foreach ( self::attribute_pairs( $variation ) as $pair ) {
			$subtitle[] = esc_html( $pair['label'] ) . ': ' . esc_html( $pair['value'] );
		}

		$html  = '<div class="uonix-vts">';
		$html .= '<div class="uonix-vts__card">';
		$html .= '<div class="uonix-vts__header">';
		$html .= '<strong class="uonix-vts__title">' . esc_html( $sheet['title'] ) . '</strong>';
		if ( ! empty( $subtitle ) ) {
			$html .= '<span class="uonix-vts__subtitle">' . implode( ' · ', $subtitle ) . '</span>';
		}
		$html .= '</div>';

		foreach ( $sheet['sections'] as $section ) {
			$layout = $section['layout'];
			$html  .= '<section class="uonix-vts__section uonix-vts__section--' . esc_html( $layout ) . '">';
			if ( '' !== $section['title'] ) {
				$html .= '<div class="uonix-vts__section-title">' . esc_html( $section['title'] ) . '</div>';
			}
			$html .= '<div class="uonix-vts__grid uonix-vts__grid--' . esc_html( $layout ) . '">';
			foreach ( $section['items'] as $item ) {
				$html .= '<div class="uonix-vts__item">';
				$html .= '<strong>' . esc_html( $item['label'] ) . '</strong>';
				$html .= '<span>' . esc_html( $item['value'] ) . '</span>';
				$html .= '</div>';
			}
			$html .= '</div>';
			$html .= '</section>';
		}

		$html .= '</div>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Anexa a ficha depois da descrição livre no payload nativo da variação.
	 *
	 * @param mixed $data Payload do WooCommerce.
	 * @param mixed $parent Produto pai.
	 * @param mixed $variation Variação WooCommerce.
	 * @return mixed
	 */
	public static function append_to_variation_data( $data, $parent, $variation ) {
		if ( ! is_array( $data ) || ! is_object( $variation ) || ! method_exists( $variation, 'get_meta' ) ) {
			return $data;
		}

		$stored = $variation->get_meta( Uonix_VTS_Schema::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return $data;
		}

		$html = self::render( $stored, $variation );
		if ( '' === $html ) {
			return $data;
		}

		$description                   = isset( $data['variation_description'] ) ? (string) $data['variation_description'] : '';
		$data['variation_description'] = $description . $html;
		return $data;
	}

	/**
	 * Carrega o CSS versionado somente em páginas de produto.
	 */
	public static function enqueue_frontend_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( ! defined( 'UONIX_MU_PATH' ) || ! defined( 'UONIX_MU_URL' ) ) {
			return;
		}

		$relative = 'uonix-woocommerce/assets/css/ficha-tecnica-variacao.css';
		$path     = UONIX_MU_PATH . $relative;
		$url      = UONIX_MU_URL . $relative;
		$version  = file_exists( $path ) ? (string) filemtime( $path ) : '1';

		wp_enqueue_style( 'uonix-vts', $url, array(), $version );
	}

	/**
	 * Resolve o nome oficial de termos taxonômicos sem abreviar valores.
	 *
	 * @param string $name Nome do atributo.
	 * @param mixed  $raw_value Valor armazenado.
	 * @return string
	 */
	private static function official_attribute_value( $name, $raw_value ) {
		if ( ! is_scalar( $raw_value ) && null !== $raw_value ) {
			return '';
		}
		$value = (string) $raw_value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $name ) && function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $value, $name );
			if ( $term && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $term ) ) && isset( $term->name ) ) {
				return (string) $term->name;
			}
		}

		return $value;
	}
}
