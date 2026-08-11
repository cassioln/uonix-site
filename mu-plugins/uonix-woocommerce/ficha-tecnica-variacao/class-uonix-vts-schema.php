<?php
/**
 * Esquema e normalização da ficha técnica por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTS_Schema {
	public const VERSION             = 1;
	public const MAX_PAYLOAD_BYTES   = 262144;
	public const MAX_SECTIONS        = 50;
	public const MAX_ITEMS           = 100;
	public const MAX_TITLE_CHARS     = 160;
	public const MAX_SECTION_CHARS   = 120;
	public const MAX_LABEL_CHARS     = 120;
	public const MAX_VALUE_CHARS     = 500;
	public const META_KEY            = '_uonix_variation_technical_sheet';
	public const BACKUP_META_KEY     = '_uonix_variation_technical_sheet_legacy_backup_v1';

	/**
	 * Normaliza o envelope JSON submetido pelo editor.
	 *
	 * @param mixed $raw JSON bruto.
	 * @return array<string, mixed>
	 */
	public static function normalize_envelope( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::failure( 'empty_json', 'A ficha técnica recebida está vazia.' );
		}
		if ( strlen( $raw ) > self::MAX_PAYLOAD_BYTES ) {
			return self::failure( 'payload_too_large', 'A ficha técnica excede o limite de segurança.' );
		}
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::failure( 'invalid_json', 'A ficha técnica contém JSON inválido.' );
		}
		$action = isset( $decoded['action'] ) && is_string( $decoded['action'] ) ? $decoded['action'] : '';
		if ( 'delete' === $action ) {
			return self::success( 'delete', null );
		}
		if ( 'upsert' !== $action || ! isset( $decoded['sheet'] ) ) {
			return self::failure( 'invalid_action', 'A ação da ficha técnica é inválida.' );
		}
		$result = self::normalize_sheet( $decoded['sheet'] );
		if ( ! $result['ok'] ) {
			return $result;
		}
		return self::success( 'upsert', $result['sheet'] );
	}

	/**
	 * Valida e sanitiza uma ficha no esquema v1 sem truncar dados.
	 *
	 * @param mixed $sheet Ficha decodificada.
	 * @return array<string, mixed>
	 */
	public static function normalize_sheet( $sheet ) {
		if ( ! is_array( $sheet ) || ! isset( $sheet['version'] ) || self::VERSION !== $sheet['version'] ) {
			return self::failure( 'invalid_version', 'A versão da ficha técnica não é compatível.' );
		}

		$title = self::sanitize_string( isset( $sheet['title'] ) ? $sheet['title'] : '' );
		if ( null === $title || '' === $title ) {
			return self::failure( 'missing_title', 'Informe o título geral da ficha técnica.' );
		}
		if ( self::text_length( $title ) > self::MAX_TITLE_CHARS ) {
			return self::failure( 'title_too_long', 'O título geral da ficha técnica excede o limite permitido.' );
		}

		if ( ! isset( $sheet['sections'] ) || ! is_array( $sheet['sections'] ) ) {
			return self::failure( 'empty_sections', 'Adicione ao menos uma seção válida à ficha técnica.' );
		}
		if ( count( $sheet['sections'] ) > self::MAX_SECTIONS ) {
			return self::failure( 'too_many_sections', 'A ficha técnica excede o limite de seções.' );
		}

		$sections = array();
		foreach ( $sheet['sections'] as $section ) {
			if ( ! is_array( $section ) ) {
				return self::failure( 'invalid_section', 'Uma seção da ficha técnica é inválida.' );
			}

			$section_title = self::sanitize_string( isset( $section['title'] ) ? $section['title'] : '' );
			if ( null === $section_title ) {
				return self::failure( 'invalid_section', 'Uma seção da ficha técnica é inválida.' );
			}
			if ( self::text_length( $section_title ) > self::MAX_SECTION_CHARS ) {
				return self::failure( 'section_title_too_long', 'O título de uma seção excede o limite permitido.' );
			}

			$layout = isset( $section['layout'] ) && is_scalar( $section['layout'] ) ? (string) $section['layout'] : '';
			if ( ! in_array( $layout, array( 'compact', 'detailed' ), true ) ) {
				return self::failure( 'invalid_layout', 'O formato de uma seção da ficha técnica é inválido.' );
			}

			if ( ! isset( $section['items'] ) || ! is_array( $section['items'] ) ) {
				return self::failure( 'invalid_items', 'Os itens de uma seção da ficha técnica são inválidos.' );
			}
			if ( count( $section['items'] ) > self::MAX_ITEMS ) {
				return self::failure( 'too_many_items', 'Uma seção da ficha técnica excede o limite de itens.' );
			}

			$items = array();
			foreach ( $section['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					return self::failure( 'invalid_item', 'Um item da ficha técnica é inválido.' );
				}

				$label = self::sanitize_string( isset( $item['label'] ) ? $item['label'] : '' );
				$value = self::sanitize_string( isset( $item['value'] ) ? $item['value'] : '' );
				if ( null === $label || null === $value ) {
					return self::failure( 'invalid_item', 'Um item da ficha técnica é inválido.' );
				}
				if ( '' === $label && '' === $value ) {
					continue;
				}
				if ( '' === $label || '' === $value ) {
					return self::failure( 'partial_item', 'Preencha o rótulo e o valor de cada item da ficha técnica.' );
				}
				if ( self::text_length( $label ) > self::MAX_LABEL_CHARS ) {
					return self::failure( 'label_too_long', 'O rótulo de um item excede o limite permitido.' );
				}
				if ( self::text_length( $value ) > self::MAX_VALUE_CHARS ) {
					return self::failure( 'value_too_long', 'O valor de um item excede o limite permitido.' );
				}

				$items[] = array(
					'label' => $label,
					'value' => $value,
				);
			}

			if ( empty( $items ) ) {
				if ( '' === $section_title ) {
					continue;
				}
				return self::failure( 'empty_section', 'Uma seção com título precisa ter ao menos um item válido.' );
			}

			$sections[] = array(
				'title'  => $section_title,
				'layout' => $layout,
				'items'  => $items,
			);
		}

		if ( empty( $sections ) ) {
			return self::failure( 'empty_sections', 'Adicione ao menos uma seção válida à ficha técnica.' );
		}

		return self::success(
			null,
			array(
				'version'  => self::VERSION,
				'title'    => $title,
				'sections' => $sections,
			)
		);
	}

	/**
	 * Sanitiza texto simples e recusa tipos compostos.
	 *
	 * @param mixed $value Valor recebido.
	 * @return string|null
	 */
	private static function sanitize_string( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return null;
		}

		$value = wp_strip_all_tags( (string) $value, true );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		if ( null === $value ) {
			return null;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Conta caracteres Unicode mesmo quando mbstring não está disponível.
	 *
	 * @param string $value Texto sanitizado.
	 * @return int
	 */
	private static function text_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? strlen( $value ) : $count;
	}

	/**
	 * @param string|null $action Ação normalizada.
	 * @param array|null  $sheet Ficha normalizada.
	 * @return array<string, mixed>
	 */
	private static function success( $action, $sheet ) {
		return array(
			'ok'      => true,
			'code'    => null,
			'message' => null,
			'action'  => $action,
			'sheet'   => $sheet,
		);
	}

	/**
	 * @param string $code Código legível por máquina.
	 * @param string $message Mensagem administrativa.
	 * @return array<string, mixed>
	 */
	private static function failure( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'action'  => null,
			'sheet'   => null,
		);
	}
}
