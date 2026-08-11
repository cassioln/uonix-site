<?php
/**
 * Migração fail-closed das fichas técnicas legadas por variação.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uonix_VTS_Migration_Command {
	private const LEGACY_WRAPPER_CLASS = 'uonix-fichas-compactas';
	private const LEGACY_SHEET_CLASS   = 'uonix-ficha-compacta';
	private const LEGACY_HEADER_CLASS  = 'uonix-ficha-header';
	private const LEGACY_MEASURES_CLASS = 'uonix-medidas-grid';
	private const LEGACY_INFO_CLASS     = 'uonix-info-grid';

	/**
	 * Registra o comando apenas no runtime WP-CLI.
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'uonix ficha-tecnica', __CLASS__ );
	}

	/**
	 * Executa dry-run, migração ou rollback de forma explícita.
	 *
	 * @param array<int, string>   $args Argumentos posicionais do WP-CLI.
	 * @param array<string, mixed> $assoc_args Opções nomeadas.
	 */
	public function migrate( $args, $assoc_args ) {
		$execute          = isset( $assoc_args['execute'] );
		$rollback         = isset( $assoc_args['rollback'] );
		$explicit_dry_run = isset( $assoc_args['dry-run'] );
		$dry_run          = $explicit_dry_run || ( ! $execute && ! $rollback );

		if ( (int) $execute + (int) $rollback + (int) $explicit_dry_run > 1 ) {
			WP_CLI::error( 'Escolha somente --dry-run, --execute ou --rollback.' );
		}

		if ( $rollback ) {
			$rollback_candidates = self::preflight_rollback();
			self::rollback_candidates( $rollback_candidates );
			WP_CLI::log( 'ROLLBACK OK: 5 descrições restauradas; backups preservados.' );
			return;
		}

		$candidates = self::preflight_legacy_candidates( $execute );
		if ( $execute ) {
			if ( empty( $candidates ) ) {
				self::verify_migrated_state();
				WP_CLI::log( 'NO-CHANGE: 5 fichas já migradas e verificadas.' );
				return;
			}
			self::execute_candidates( $candidates );
			WP_CLI::log( 'EXECUTE OK: 5 fichas migradas; 5 backups verificados.' );
			return;
		}

		if ( $dry_run ) {
			foreach ( $candidates as $candidate ) {
				WP_CLI::log(
					sprintf(
						'DRY-RUN #%d: source_sha256=%s; título=%s; compacta=%d; detalhada=%d.',
						$candidate['id'],
						$candidate['backup']['source_hash'],
						$candidate['backup']['sheet']['title'],
						count( $candidate['backup']['sheet']['sections'][0]['items'] ),
						count( $candidate['backup']['sheet']['sections'][1]['items'] )
					)
				);
			}
			WP_CLI::log( 'DRY-RUN OK: 5 fichas legadas reconhecidas; nenhuma alteração realizada.' );
		}
	}

	/**
	 * Analisa todas as candidatas antes de qualquer escrita.
	 *
	 * @param bool $allow_none Permite zero wrappers para verificar idempotência.
	 * @return array<int, array<string, mixed>>
	 */
	private static function preflight_legacy_candidates( $allow_none = false ) {
		$ids = self::find_variation_ids( '_variation_description', self::LEGACY_WRAPPER_CLASS, 'LIKE' );
		if ( $allow_none && empty( $ids ) ) {
			return array();
		}
		if ( 5 !== count( $ids ) ) {
			WP_CLI::error( 'O preflight exige exatamente 5 variações legadas distintas.' );
		}
		$existing_backup_ids = self::find_variation_ids( Uonix_VTS_Schema::BACKUP_META_KEY, null, 'EXISTS' );
		if ( array_diff( $existing_backup_ids, $ids ) ) {
			WP_CLI::error( 'Existe backup fora das cinco candidatas; migração recusada.' );
		}

		$candidates      = array();
		$needs_timestamp = false;
		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! self::is_variation_object( $variation, $variation_id ) ) {
				WP_CLI::error( sprintf( '#%d não corresponde a uma variação WooCommerce válida.', $variation_id ) );
			}

			$description = $variation->get_description( 'edit' );
			if ( ! is_string( $description ) ) {
				WP_CLI::error( sprintf( '#%d possui descrição não textual.', $variation_id ) );
			}
			if ( self::meta_exists( $variation_id, Uonix_VTS_Schema::META_KEY ) ) {
				WP_CLI::error( sprintf( '#%d já possui ficha estruturada em conflito com o wrapper legado.', $variation_id ) );
			}

			$parsed = self::parse_legacy_description( $description );
			if ( ! $parsed['ok'] ) {
				WP_CLI::error( sprintf( '#%d: %s', $variation_id, $parsed['message'] ) );
			}
			$expected_backup = self::build_backup( $description, $parsed, null );

			$stored_backup = $variation->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
			$backup_exists = self::meta_exists( $variation_id, Uonix_VTS_Schema::BACKUP_META_KEY );
			if ( $backup_exists ) {
				if ( ! self::backup_matches_source( $stored_backup, $expected_backup ) ) {
					WP_CLI::error( sprintf( '#%d possui backup legado divergente; ele não será sobrescrito.', $variation_id ) );
				}
				$backup = $stored_backup;
			} else {
				$backup           = $expected_backup;
				$needs_timestamp = true;
			}

			$candidates[] = array(
				'id'             => $variation_id,
				'backup'         => $backup,
				'backup_existed' => $backup_exists,
			);
		}

		if ( $needs_timestamp ) {
			$migrated_at = current_time( 'mysql', true );
			if ( ! self::is_valid_gmt_timestamp( $migrated_at ) ) {
				WP_CLI::error( 'Não foi possível obter o horário GMT válido da migração.' );
			}
			foreach ( $candidates as &$candidate ) {
				if ( ! $candidate['backup_existed'] ) {
					$candidate['backup']['migrated_at_gmt'] = $migrated_at;
				}
			}
			unset( $candidate );
		}

		return $candidates;
	}

	/**
	 * @return array<int, int>
	 */
	private static function find_variation_ids( $meta_key, $meta_value, $compare ) {
		$meta_query = array( 'key' => $meta_key );
		if ( null !== $meta_value ) {
			$meta_query['value'] = $meta_value;
		}
		$meta_query['compare'] = $compare;
		$raw_ids = get_posts(
			array(
				'post_type'     => 'product_variation',
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'meta_query'    => array( $meta_query ),
			)
		);
		$ids = array_map( 'absint', is_array( $raw_ids ) ? $raw_ids : array() );
		if ( count( $ids ) !== count( array_unique( $ids ) ) || in_array( 0, $ids, true ) ) {
			WP_CLI::error( 'A consulta da migração retornou IDs inválidos ou duplicados.' );
		}
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * @param string               $description Origem integral.
	 * @param array<string, mixed> $parsed Resultado do parser.
	 * @param string|null          $migrated_at Timestamp GMT.
	 * @return array<string, mixed>
	 */
	private static function build_backup( $description, $parsed, $migrated_at ) {
		$sheet_json = wp_json_encode( $parsed['sheet'] );
		if ( ! is_string( $sheet_json ) ) {
			WP_CLI::error( 'Não foi possível gerar o hash da ficha migrada.' );
		}
		return array(
			'original_description'       => $description,
			'source_hash'                => hash( 'sha256', $description ),
			'remaining_description'      => $parsed['remaining_description'],
			'remaining_description_hash' => hash( 'sha256', $parsed['remaining_description'] ),
			'sheet'                      => $parsed['sheet'],
			'sheet_hash'                 => hash( 'sha256', $sheet_json ),
			'migrated_at_gmt'            => $migrated_at,
			'version'                    => 1,
		);
	}

	/**
	 * @param mixed                $stored Backup já persistido.
	 * @param array<string, mixed> $expected Estado derivado da origem atual.
	 */
	private static function backup_matches_source( $stored, $expected ) {
		if ( ! self::is_valid_backup( $stored ) ) {
			return false;
		}
		foreach (
			array(
				'original_description',
				'source_hash',
				'remaining_description',
				'remaining_description_hash',
				'sheet',
				'sheet_hash',
				'version',
			) as $key
		) {
			if ( $stored[ $key ] !== $expected[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $backup Backup persistido.
	 */
	private static function is_valid_backup( $backup ) {
		$expected_keys = array(
			'original_description',
			'source_hash',
			'remaining_description',
			'remaining_description_hash',
			'sheet',
			'sheet_hash',
			'migrated_at_gmt',
			'version',
		);
		if ( ! is_array( $backup ) || $expected_keys !== array_keys( $backup ) || 1 !== $backup['version'] ) {
			return false;
		}
		if (
			! is_string( $backup['original_description'] ) ||
			! is_string( $backup['source_hash'] ) ||
			! is_string( $backup['remaining_description'] ) ||
			! is_string( $backup['remaining_description_hash'] ) ||
			! is_array( $backup['sheet'] ) ||
			! is_string( $backup['sheet_hash'] ) ||
			! self::is_valid_gmt_timestamp( $backup['migrated_at_gmt'] )
		) {
			return false;
		}
		$normalized = Uonix_VTS_Schema::normalize_sheet( $backup['sheet'] );
		$sheet_json = wp_json_encode( $backup['sheet'] );
		return $normalized['ok'] &&
			$normalized['sheet'] === $backup['sheet'] &&
			is_string( $sheet_json ) &&
			hash_equals( hash( 'sha256', $backup['original_description'] ), $backup['source_hash'] ) &&
			hash_equals( hash( 'sha256', $backup['remaining_description'] ), $backup['remaining_description_hash'] ) &&
			hash_equals( hash( 'sha256', $sheet_json ), $backup['sheet_hash'] );
	}

	/**
	 * @param mixed $timestamp Timestamp MySQL em GMT.
	 */
	private static function is_valid_gmt_timestamp( $timestamp ) {
		if ( ! is_string( $timestamp ) ) {
			return false;
		}
		$matched = preg_match(
			'~\A(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):([0-5]\d):([0-5]\d)\z~D',
			$timestamp,
			$parts
		);
		return 1 === $matched && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates Candidatas validadas.
	 */
	private static function execute_candidates( $candidates ) {
		$changed   = array();
		$snapshots = array();
		foreach ( $candidates as $candidate ) {
			$snapshots[ $candidate['id'] ] = array(
				'description'   => $candidate['backup']['original_description'],
				'sheet_exists'  => false,
				'sheet'         => null,
				'backup_exists' => $candidate['backup_existed'],
				'backup'        => $candidate['backup_existed'] ? $candidate['backup'] : null,
			);
		}

		try {
			foreach ( $candidates as $candidate ) {
				$variation = wc_get_product( $candidate['id'] );
				if ( ! self::is_variation_object( $variation, $candidate['id'] ) ) {
					throw new RuntimeException( sprintf( '#%d deixou de ser uma variação válida durante a execução.', $candidate['id'] ) );
				}
				if (
					$candidate['backup']['source_hash'] !== hash( 'sha256', $variation->get_description( 'edit' ) ) ||
					self::meta_exists( $candidate['id'], Uonix_VTS_Schema::META_KEY )
				) {
					throw new RuntimeException( sprintf( '#%d mudou depois do preflight.', $candidate['id'] ) );
				}
				$current_backup        = $variation->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
				$current_backup_exists = self::meta_exists( $candidate['id'], Uonix_VTS_Schema::BACKUP_META_KEY );
				if (
					$candidate['backup_existed'] !== $current_backup_exists ||
					( $current_backup_exists && $current_backup !== $candidate['backup'] )
				) {
					throw new RuntimeException( sprintf( '#%d teve o backup alterado depois do preflight.', $candidate['id'] ) );
				}

				$changed[] = $candidate['id'];
				$variation->set_description( $candidate['backup']['remaining_description'] );
				$variation->update_meta_data( Uonix_VTS_Schema::META_KEY, $candidate['backup']['sheet'] );
				if ( ! $candidate['backup_existed'] ) {
					$variation->update_meta_data( Uonix_VTS_Schema::BACKUP_META_KEY, $candidate['backup'] );
				}
				$variation->save();
				if ( ! self::post_migration_matches( $candidate['id'], $candidate['backup'] ) ) {
					throw new RuntimeException( sprintf( '#%d divergiu dos hashes esperados após a migração.', $candidate['id'] ) );
				}
			}
		} catch ( Throwable $exception ) {
			$restored = self::restore_snapshots( $changed, $snapshots );
			if ( ! $restored ) {
				WP_CLI::error( 'Migração abortada e restauração incompleta; interrompa novas escritas e faça resolução manual.' );
			}
			WP_CLI::error( 'Migração abortada; todas as alterações da tentativa foram restauradas. Motivo: ' . $exception->getMessage() );
		}
	}

	/**
	 * Valida todos os backups e estados pós-migração antes do primeiro rollback.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function preflight_rollback() {
		$ids = self::find_variation_ids( Uonix_VTS_Schema::BACKUP_META_KEY, null, 'EXISTS' );
		if ( 5 !== count( $ids ) ) {
			WP_CLI::error( 'O rollback exige exatamente cinco backups verificados.' );
		}

		$candidates = array();
		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! self::is_variation_object( $variation, $variation_id ) ) {
				WP_CLI::error( sprintf( '#%d não corresponde a uma variação válida para rollback.', $variation_id ) );
			}
			$backup = $variation->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
			if ( ! self::is_valid_backup( $backup ) ) {
				WP_CLI::error( sprintf( '#%d possui backup inválido; rollback recusado.', $variation_id ) );
			}
			if ( ! self::post_migration_matches( $variation_id, $backup ) ) {
				WP_CLI::error( sprintf( '#%d possui edição posterior à migração; rollback recusado sem gravar.', $variation_id ) );
			}
			$candidates[] = array(
				'id'     => $variation_id,
				'backup' => $backup,
			);
		}
		return $candidates;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates Backups validados.
	 */
	private static function rollback_candidates( $candidates ) {
		$changed   = array();
		$snapshots = array();
		foreach ( $candidates as $candidate ) {
			$snapshots[ $candidate['id'] ] = array(
				'description'   => $candidate['backup']['remaining_description'],
				'sheet_exists'  => true,
				'sheet'         => $candidate['backup']['sheet'],
				'backup_exists' => true,
				'backup'        => $candidate['backup'],
			);
		}

		try {
			foreach ( $candidates as $candidate ) {
				if ( ! self::post_migration_matches( $candidate['id'], $candidate['backup'] ) ) {
					throw new RuntimeException( sprintf( '#%d mudou depois do preflight de rollback.', $candidate['id'] ) );
				}
				$variation = wc_get_product( $candidate['id'] );
				$changed[] = $candidate['id'];
				$variation->set_description( $candidate['backup']['original_description'] );
				$variation->delete_meta_data( Uonix_VTS_Schema::META_KEY );
				$variation->save();
				if ( ! self::rollback_state_matches( $candidate['id'], $candidate['backup'] ) ) {
					throw new RuntimeException( sprintf( '#%d divergiu após a restauração.', $candidate['id'] ) );
				}
			}
		} catch ( Throwable $exception ) {
			$restored = self::restore_snapshots( $changed, $snapshots );
			if ( ! $restored ) {
				WP_CLI::error( 'Rollback abortado e restauração incompleta; interrompa novas escritas e faça resolução manual.' );
			}
			WP_CLI::error( 'Rollback abortado; todas as alterações da tentativa foram restauradas. Motivo: ' . $exception->getMessage() );
		}
	}

	/**
	 * Restaura snapshots em ordem inversa e verifica a releitura.
	 *
	 * @param array<int, int>                  $changed IDs possivelmente alterados.
	 * @param array<int, array<string, mixed>> $snapshots Estado anterior por ID.
	 */
	private static function restore_snapshots( $changed, $snapshots ) {
		$all_restored = true;
		foreach ( array_reverse( $changed ) as $variation_id ) {
			try {
				if ( ! isset( $snapshots[ $variation_id ] ) ) {
					$all_restored = false;
					continue;
				}
				$snapshot  = $snapshots[ $variation_id ];
				$variation = wc_get_product( $variation_id );
				if ( ! self::is_variation_object( $variation, $variation_id ) ) {
					$all_restored = false;
					continue;
				}
				$variation->set_description( $snapshot['description'] );
				if ( $snapshot['sheet_exists'] ) {
					$variation->update_meta_data( Uonix_VTS_Schema::META_KEY, $snapshot['sheet'] );
				} else {
					$variation->delete_meta_data( Uonix_VTS_Schema::META_KEY );
				}
				if ( $snapshot['backup_exists'] ) {
					$variation->update_meta_data( Uonix_VTS_Schema::BACKUP_META_KEY, $snapshot['backup'] );
				} else {
					$variation->delete_meta_data( Uonix_VTS_Schema::BACKUP_META_KEY );
				}
				$variation->save();
				if ( ! self::snapshot_matches( $variation_id, $snapshot ) ) {
					$all_restored = false;
				}
			} catch ( Throwable $exception ) {
				$all_restored = false;
			}
		}
		return $all_restored;
	}

	/**
	 * @param int                  $variation_id ID relido.
	 * @param array<string, mixed> $snapshot Estado esperado.
	 */
	private static function snapshot_matches( $variation_id, $snapshot ) {
		$variation = wc_get_product( $variation_id );
		if ( ! self::is_variation_object( $variation, $variation_id ) || $snapshot['description'] !== $variation->get_description( 'edit' ) ) {
			return false;
		}
		$sheet_exists  = self::meta_exists( $variation_id, Uonix_VTS_Schema::META_KEY );
		$backup_exists = self::meta_exists( $variation_id, Uonix_VTS_Schema::BACKUP_META_KEY );
		$sheet_matches = $snapshot['sheet_exists'] === $sheet_exists &&
			( ! $sheet_exists || $snapshot['sheet'] === $variation->get_meta( Uonix_VTS_Schema::META_KEY, true ) );
		$backup_matches = $snapshot['backup_exists'] === $backup_exists &&
			( ! $backup_exists || $snapshot['backup'] === $variation->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true ) );
		return $sheet_matches && $backup_matches;
	}

	/**
	 * Verifica uma migração completa já existente sem reescrever dados.
	 */
	private static function verify_migrated_state() {
		$ids = self::find_variation_ids( Uonix_VTS_Schema::BACKUP_META_KEY, null, 'EXISTS' );
		if ( 5 !== count( $ids ) ) {
			WP_CLI::error( 'Não há cinco backups para comprovar uma migração idempotente.' );
		}
		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! self::is_variation_object( $variation, $variation_id ) ) {
				WP_CLI::error( sprintf( '#%d não corresponde a uma variação válida no estado migrado.', $variation_id ) );
			}
			$backup = $variation->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
			if ( ! self::is_valid_backup( $backup ) ) {
				WP_CLI::error( sprintf( '#%d possui backup inválido no estado migrado.', $variation_id ) );
			}
			if ( ! self::post_migration_matches( $variation_id, $backup ) ) {
				WP_CLI::error( sprintf( '#%d divergiu dos hashes esperados no estado migrado.', $variation_id ) );
			}
		}
	}

	/**
	 * @param int                  $variation_id ID relido.
	 * @param array<string, mixed> $backup Backup esperado.
	 */
	private static function post_migration_matches( $variation_id, $backup ) {
		$reloaded = wc_get_product( $variation_id );
		if ( ! self::is_variation_object( $reloaded, $variation_id ) ) {
			return false;
		}
		$stored_sheet = $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true );
		$sheet_json   = wp_json_encode( $stored_sheet );
		return self::meta_exists( $variation_id, Uonix_VTS_Schema::META_KEY ) &&
			self::meta_exists( $variation_id, Uonix_VTS_Schema::BACKUP_META_KEY ) &&
			$backup['remaining_description'] === $reloaded->get_description( 'edit' ) &&
			$backup['remaining_description_hash'] === hash( 'sha256', $reloaded->get_description( 'edit' ) ) &&
			is_string( $sheet_json ) &&
			$backup['sheet_hash'] === hash( 'sha256', $sheet_json ) &&
			$backup['sheet'] === $stored_sheet &&
			$backup === $reloaded->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
	}

	/**
	 * @param int                  $variation_id ID relido.
	 * @param array<string, mixed> $backup Backup preservado.
	 */
	private static function rollback_state_matches( $variation_id, $backup ) {
		$reloaded = wc_get_product( $variation_id );
		return self::is_variation_object( $reloaded, $variation_id ) &&
			! self::meta_exists( $variation_id, Uonix_VTS_Schema::META_KEY ) &&
			self::meta_exists( $variation_id, Uonix_VTS_Schema::BACKUP_META_KEY ) &&
			$backup['original_description'] === $reloaded->get_description( 'edit' ) &&
			$backup['source_hash'] === hash( 'sha256', $reloaded->get_description( 'edit' ) ) &&
			$backup === $reloaded->get_meta( Uonix_VTS_Schema::BACKUP_META_KEY, true );
	}

	/**
	 * Distingue meta ausente de meta fisicamente presente com valor vazio.
	 *
	 * @param int    $variation_id ID da variação.
	 * @param string $meta_key Chave consultada.
	 */
	private static function meta_exists( $variation_id, $meta_key ) {
		return metadata_exists( 'post', $variation_id, $meta_key );
	}

	/**
	 * @param mixed $variation Produto carregado.
	 */
	private static function is_variation_object( $variation, $expected_id ) {
		return is_object( $variation ) &&
			method_exists( $variation, 'get_id' ) &&
			$expected_id === absint( $variation->get_id() ) &&
			method_exists( $variation, 'get_type' ) &&
			'variation' === $variation->get_type() &&
			method_exists( $variation, 'get_description' ) &&
			method_exists( $variation, 'set_description' ) &&
			method_exists( $variation, 'get_meta' ) &&
			method_exists( $variation, 'update_meta_data' ) &&
			method_exists( $variation, 'delete_meta_data' ) &&
			method_exists( $variation, 'save' );
	}

	/**
	 * Converte uma única descrição legada, preservando bytes externos ao wrapper.
	 *
	 * @param mixed $description Descrição integral da variação.
	 * @return array<string, mixed>
	 */
	public static function parse_legacy_description( $description ) {
		if ( ! is_string( $description ) ) {
			return self::parse_failure( 'invalid_description', 'A descrição legada precisa ser textual.' );
		}

		$extracted = self::extract_balanced_wrapper( $description );
		if ( ! $extracted['ok'] ) {
			return self::parse_failure( $extracted['code'], $extracted['message'] );
		}

		$sheet = self::parse_wrapper( $extracted['wrapper'] );
		if ( ! $sheet['ok'] ) {
			return self::parse_failure( $sheet['code'], $sheet['message'] );
		}

		return array(
			'ok'                    => true,
			'code'                  => null,
			'message'               => null,
			'sheet'                 => $sheet['sheet'],
			'remaining_description' => substr( $description, 0, $extracted['start'] ) . substr( $description, $extracted['end'] ),
		);
	}

	/**
	 * Localiza o único wrapper e percorre tags div balanceadas por profundidade.
	 *
	 * @param string $description Descrição integral.
	 * @return array<string, mixed>
	 */
	private static function extract_balanced_wrapper( $description ) {
		$matched = preg_match_all( '/<\/?div\b[^>]*>/iu', $description, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $matched ) {
			return self::extraction_failure( 'invalid_markup', 'Não foi possível interpretar as tags div da descrição legada.' );
		}

		$wrapper_indexes = array();
		foreach ( $matches[0] as $index => $matched_tag ) {
			$tag = $matched_tag[0];
			if ( 0 === preg_match( '/^<\s*\/\s*div\b/iu', $tag ) && self::tag_has_class( $tag, self::LEGACY_WRAPPER_CLASS ) ) {
				$wrapper_indexes[] = $index;
			}
		}

		if ( 1 !== count( $wrapper_indexes ) ) {
			return self::extraction_failure( 'wrapper_count', 'A descrição precisa conter exatamente um wrapper legado reconhecido.' );
		}

		$wrapper_index = $wrapper_indexes[0];
		$start         = $matches[0][ $wrapper_index ][1];
		$depth         = 0;
		$end           = null;

		for ( $index = $wrapper_index; $index < count( $matches[0] ); ++$index ) {
			$tag      = $matches[0][ $index ][0];
			$is_close = 1 === preg_match( '/^<\s*\/\s*div\b/iu', $tag );
			$depth   += $is_close ? -1 : 1;
			if ( $depth < 0 ) {
				break;
			}
			if ( 0 === $depth ) {
				$end = $matches[0][ $index ][1] + strlen( $tag );
				break;
			}
		}

		if ( null === $end ) {
			return self::extraction_failure( 'unbalanced_wrapper', 'O wrapper legado está desbalanceado.' );
		}

		return array(
			'ok'      => true,
			'code'    => null,
			'message' => null,
			'start'   => $start,
			'end'     => $end,
			'wrapper' => substr( $description, $start, $end - $start ),
		);
	}

	/**
	 * Valida a pilha completa de tags antes que o parser HTML possa repará-la.
	 *
	 * @param string $wrapper Fragmento legado isolado.
	 */
	private static function has_strictly_balanced_legacy_tags( $wrapper ) {
		$matched = preg_match_all( '/<\/?(?:div|strong|span)\b[^>]*>/iu', $wrapper, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $matched || 0 === $matched ) {
			return false;
		}

		$stack  = array();
		$cursor = 0;
		foreach ( $matches[0] as $matched_tag ) {
			$tag    = $matched_tag[0];
			$offset = $matched_tag[1];
			$plain  = substr( $wrapper, $cursor, $offset - $cursor );
			if ( false !== strpbrk( $plain, '<>' ) ) {
				return false;
			}

			if ( 1 === preg_match( '/^<\/([a-z][a-z0-9]*)\s*>$/iu', $tag, $closing ) ) {
				$name = strtolower( $closing[1] );
				if ( empty( $stack ) || $name !== end( $stack ) ) {
					return false;
				}
				array_pop( $stack );
			} elseif ( 1 === preg_match( '/^<([a-z][a-z0-9]*)\b[^>]*>$/iu', $tag, $opening ) ) {
				if ( 1 === preg_match( '/\/\s*>$/u', $tag ) ) {
					return false;
				}
				$stack[] = strtolower( $opening[1] );
			} else {
				return false;
			}
			$cursor = $offset + strlen( $tag );
		}

		$tail = substr( $wrapper, $cursor );
		return false === strpbrk( $tail, '<>' ) && empty( $stack );
	}

	/**
	 * @param string $tag Tag de abertura.
	 * @param string $class_name Token de classe esperado.
	 */
	private static function tag_has_class( $tag, $class_name ) {
		$matched = preg_match( '~\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~iu', $tag, $matches );
		if ( 1 !== $matched ) {
			return false;
		}

		$class_value = '';
		foreach ( array( 1, 2, 3 ) as $capture ) {
			if ( isset( $matches[ $capture ] ) && '' !== $matches[ $capture ] ) {
				$class_value = $matches[ $capture ];
				break;
			}
		}

		$tokens = preg_split( '/\s+/u', trim( $class_value ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $tokens ) && in_array( $class_name, $tokens, true );
	}

	/**
	 * @param string $wrapper Fragmento isolado do wrapper.
	 * @return array<string, mixed>
	 */
	private static function parse_wrapper( $wrapper ) {
		if ( ! self::has_strictly_balanced_legacy_tags( $wrapper ) ) {
			return self::sheet_failure( 'invalid_markup', 'O wrapper legado possui tags ausentes, cruzadas ou inesperadas.' );
		}
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return self::sheet_failure( 'dom_unavailable', 'A extensão DOM é obrigatória para migrar as fichas legadas.' );
		}

		$dom        = new DOMDocument( '1.0', 'UTF-8' );
		$previous   = libxml_use_internal_errors( true );
		$loaded     = false;
		$had_errors = false;
		try {
			$loaded = $dom->loadHTML(
				'<?xml encoding="UTF-8"><div id="uonix-vts-legacy-root">' . $wrapper . '</div>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
			);
			$had_errors = ! empty( libxml_get_errors() );
		} catch ( Throwable $exception ) {
			$had_errors = true;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || $had_errors ) {
			return self::sheet_failure( 'invalid_markup', 'O wrapper legado contém HTML inválido.' );
		}

		$xpath = new DOMXPath( $dom );
		$roots = $xpath->query( '//*[@id="uonix-vts-legacy-root"]' );
		if ( false === $roots || 1 !== $roots->length ) {
			return self::sheet_failure( 'invalid_structure', 'A raiz temporária do wrapper legado é inválida.' );
		}
		$root = $roots->item( 0 );

		$wrappers = self::query_class( $xpath, $root, './div', self::LEGACY_WRAPPER_CLASS );
		if (
			false === $wrappers ||
			1 !== $wrappers->length ||
			1 !== self::element_child_count( $root ) ||
			self::has_unexpected_direct_content( $root )
		) {
			return self::sheet_failure( 'invalid_structure', 'O wrapper legado não possui a estrutura esperada.' );
		}
		$wrapper_node = $wrappers->item( 0 );

		$sheets = self::query_class( $xpath, $wrapper_node, './div', self::LEGACY_SHEET_CLASS );
		if (
			false === $sheets ||
			1 !== $sheets->length ||
			1 !== self::element_child_count( $wrapper_node ) ||
			self::has_unexpected_direct_content( $wrapper_node )
		) {
			return self::sheet_failure( 'invalid_structure', 'A ficha compacta legada não é única.' );
		}
		$sheet_node = $sheets->item( 0 );

		$headers  = self::query_class( $xpath, $sheet_node, './div', self::LEGACY_HEADER_CLASS );
		$measures = self::query_class( $xpath, $sheet_node, './div', self::LEGACY_MEASURES_CLASS );
		$info     = self::query_class( $xpath, $sheet_node, './div', self::LEGACY_INFO_CLASS );
		if (
			false === $headers || 1 !== $headers->length ||
			false === $measures || 1 !== $measures->length ||
			false === $info || 1 !== $info->length ||
			3 !== self::element_child_count( $sheet_node ) ||
			self::has_unexpected_direct_content( $sheet_node )
		) {
			return self::sheet_failure( 'invalid_structure', 'Cabeçalho e grades legadas precisam ser únicos.' );
		}

		$title = self::extract_header_title( $xpath, $headers->item( 0 ) );
		if ( null === $title ) {
			return self::sheet_failure( 'invalid_header', 'O cabeçalho legado precisa conter título e subtítulo únicos.' );
		}

		$measure_items = self::extract_pairs( $xpath, $measures->item( 0 ), 6 );
		$info_items    = self::extract_pairs( $xpath, $info->item( 0 ), 4 );
		if ( null === $measure_items || null === $info_items ) {
			return self::sheet_failure( 'invalid_pair_count', 'A ficha legada precisa conter seis medidas e quatro informações.' );
		}

		$normalized = Uonix_VTS_Schema::normalize_sheet(
			array(
				'version'  => Uonix_VTS_Schema::VERSION,
				'title'    => $title,
				'sections' => array(
					array(
						'title'  => '',
						'layout' => 'compact',
						'items'  => $measure_items,
					),
					array(
						'title'  => '',
						'layout' => 'detailed',
						'items'  => $info_items,
					),
				),
			)
		);
		if ( ! $normalized['ok'] ) {
			return self::sheet_failure( 'invalid_sheet', 'Os textos extraídos não formam uma ficha técnica válida.' );
		}

		return array(
			'ok'      => true,
			'code'    => null,
			'message' => null,
			'sheet'   => $normalized['sheet'],
		);
	}

	/**
	 * @return DOMNodeList|false
	 */
	private static function query_class( DOMXPath $xpath, DOMNode $context, $path, $class_name ) {
		$query = $path . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class_name . ' ")]';
		return $xpath->query( $query, $context );
	}

	/**
	 * @return string|null
	 */
	private static function extract_header_title( DOMXPath $xpath, DOMNode $header ) {
		$strong = $xpath->query( './strong', $header );
		$span   = $xpath->query( './span', $header );
		if (
			false === $strong || 1 !== $strong->length ||
			false === $span || 1 !== $span->length ||
			2 !== self::element_child_count( $header ) ||
			self::has_unexpected_direct_content( $header )
		) {
			return null;
		}
		return $strong->item( 0 )->textContent;
	}

	/**
	 * @return array<int, array<string, string>>|null
	 */
	private static function extract_pairs( DOMXPath $xpath, DOMNode $grid, $expected_count ) {
		$pairs = $xpath->query( './div', $grid );
		if (
			false === $pairs ||
			$expected_count !== $pairs->length ||
			$expected_count !== self::element_child_count( $grid ) ||
			self::has_unexpected_direct_content( $grid )
		) {
			return null;
		}

		$items = array();
		foreach ( $pairs as $pair ) {
			$strong = $xpath->query( './strong', $pair );
			$span   = $xpath->query( './span', $pair );
			if (
				false === $strong || 1 !== $strong->length ||
				false === $span || 1 !== $span->length ||
				2 !== self::element_child_count( $pair ) ||
				self::has_unexpected_direct_content( $pair )
			) {
				return null;
			}
			$items[] = array(
				'label' => $strong->item( 0 )->textContent,
				'value' => $span->item( 0 )->textContent,
			);
		}
		return $items;
	}

	/**
	 * Rejeita texto não vazio, comentários e outros nós diretos que seriam descartados.
	 */
	private static function has_unexpected_direct_content( DOMNode $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				continue;
			}
			if ( XML_TEXT_NODE === $child->nodeType && 1 === preg_match( '/\A\s*\z/u', $child->nodeValue ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	private static function element_child_count( DOMNode $node ) {
		$count = 0;
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				++$count;
			}
		}
		return $count;
	}

	private static function extraction_failure( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'start'   => null,
			'end'     => null,
			'wrapper' => null,
		);
	}

	private static function sheet_failure( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'sheet'   => null,
		);
	}

	private static function parse_failure( $code, $message ) {
		return array(
			'ok'                    => false,
			'code'                  => $code,
			'message'               => $message,
			'sheet'                 => null,
			'remaining_description' => null,
		);
	}
}
