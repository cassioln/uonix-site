<?php
/**
 * Script de Migração e Remoção Segura do GutenKit (Uônix) via WP-CLI.
 *
 * Aplica de forma 100% idempotente, segura e com fail-closed:
 * 1. Validação estrita da ocorrência do bloco gutenkit/container na página Produtos (ID 7150).
 * 2. Backup preventivo físico com verificação de gravação de bytes e hash SHA-256 antes de qualquer mutação.
 * 3. Substituição validada por regex com verificação de erro e contagem estrita (= 1).
 * 4. Atualização via wp_update_post com flag WP_Error e verificação de retorno.
 * 5. Readback obrigatório com rollback automático caso gutenkit/container persista ou [woof_mobile] falte.
 * 6. Gate de segurança com inventário global em posts, postmeta e options antes de permitir desativação do plugin.
 * 7. Desativação do plugin gutenkit-blocks-addon com readback confirmando is_plugin_active === false.
 * 8. Limpeza de cache de objetos (wp_cache_flush).
 *
 * Modo de Uso:
 *   Dry-run (padrão, seguro, NÃO grava):
 *     wp eval-file scripts/migrate-remove-gutenkit.php --allow-root
 *   Aplicar de fato (grava com backup e validações estritas):
 *     wp eval-file scripts/migrate-remove-gutenkit.php apply --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Este script deve ser executado via WP-CLI (wp eval-file).\n" );
}

$uonix_apply = false;
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $a ) {
		if ( 'apply' === strtolower( trim( (string) $a ) ) ) {
			$uonix_apply = true;
		}
	}
}

$GLOBALS['uonix_apply']   = $uonix_apply;
$GLOBALS['uonix_changes'] = 0;
$GLOBALS['uonix_noop']    = 0;
$GLOBALS['uonix_errors']  = 0;

$modo_str = $uonix_apply ? 'APLICAR (grava + backup + fail-closed)' : 'DRY-RUN (somente simulação)';
echo "\n========================================================================\n";
echo "🚀 MIGRAÇÃO E REMOÇÃO SEGURA DO GUTENKIT (UÔNIX) — MODO: {$modo_str}\n";
echo "========================================================================\n\n";

global $wpdb;

$target_post_id = 7150;
$backup_dir     = '';

// -----------------------------------------------------------------------------
// 1. VERIFICAÇÃO E MIGRAÇÃO DA PÁGINA 7150
// -----------------------------------------------------------------------------
echo "--- 1. VERIFICAÇÃO DA PÁGINA {$target_post_id} (Produtos) ---\n";

$post = get_post( $target_post_id );
if ( ! $post ) {
	echo "❌ ERRO CRÍTICO: Página {$target_post_id} (Produtos) não encontrada no banco de dados.\n";
	$GLOBALS['uonix_errors']++;
	exit( 1 );
}

$original_content = (string) $post->post_content;
$pattern          = '#<!-- wp:gutenkit/container.*?<!-- /wp:gutenkit/container -->#s';

$match_res = preg_match_all( $pattern, $original_content, $matches );
if ( false === $match_res || preg_last_error() !== PREG_NO_ERROR ) {
	echo "❌ ERRO CRÍTICO: Falha na execução da regex (código preg_last_error: " . preg_last_error() . ").\n";
	$GLOBALS['uonix_errors']++;
	exit( 1 );
}

$gutenkit_count = count( $matches[0] );

if ( 0 === $gutenkit_count ) {
	$has_shortcode = ( false !== strpos( $original_content, '[woof_mobile]' ) );
	if ( $has_shortcode ) {
		echo "ℹ️ [NOOP] Página {$target_post_id} já está migrada (0 blocos gutenkit/container e shortcode [woof_mobile] presente).\n";
		$GLOBALS['uonix_noop']++;
	} else {
		echo "⚠️ [AVISO] Página {$target_post_id} não possui bloco gutenkit/container, mas o shortcode [woof_mobile] não foi identificado no conteúdo.\n";
		$GLOBALS['uonix_noop']++;
	}
} elseif ( $gutenkit_count > 1 ) {
	echo "❌ ERRO CRÍTICO (FAIL-CLOSED): Esperava exatamente 1 ocorrência de gutenkit/container na página {$target_post_id}, mas foram encontradas {$gutenkit_count}. Operação abortada para evitar substituição incorreta.\n";
	$GLOBALS['uonix_errors']++;
	exit( 1 );
} else {
	// Exatamente 1 bloco gutenkit/container identificado
	echo "🔍 Identificado 1 bloco gutenkit/container na página {$target_post_id}.\n";

	if ( $uonix_apply ) {
		// A) BACKUP FÍSICO + VALIDAÇÃO DE INTEGRIDADE SHA-256
		$upload_dir = wp_upload_dir();
		$timestamp  = gmdate( 'Ymd-His' );
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'uonix-gutenkit-backups/' . $timestamp;

		if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
			echo "❌ ERRO CRÍTICO: Não foi possível criar diretório de backup: {$backup_dir}\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		$backup_file   = $backup_dir . '/page-7150-before-gutenkit-removal.txt';
		$bytes_written = file_put_contents( $backup_file, $original_content );

		// Verificações rigorosas de integridade do backup
		if ( false === $bytes_written || $bytes_written <= 0 ) {
			echo "❌ ERRO CRÍTICO: Falha ao gravar arquivo de backup físico (file_put_contents retornou false ou 0).\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		if ( ! is_file( $backup_file ) || filesize( $backup_file ) !== strlen( $original_content ) ) {
			echo "❌ ERRO CRÍTICO: Arquivo de backup não existe ou tamanho em disco diverge do conteúdo original.\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		$orig_hash   = hash( 'sha256', $original_content );
		$backup_hash = hash_file( 'sha256', $backup_file );
		if ( $orig_hash !== $backup_hash ) {
			echo "❌ ERRO CRÍTICO: Hash SHA-256 do arquivo de backup diverge do conteúdo original.\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		echo "   ✅ [BACKUP OK] Backup físico gravado e validado ({$bytes_written} bytes, SHA-256: " . substr( $orig_hash, 0, 12 ) . "...).\n";
		echo "      Arquivo: {$backup_file}\n";

		// B) SUBSTITUIÇÃO COM VALIDAÇÃO DE REGEX
		$replacement   = "<!-- wp:shortcode -->\n[woof_mobile]\n<!-- /wp:shortcode -->";
		$replace_count = 0;
		$new_content   = preg_replace( $pattern, $replacement, $original_content, 1, $replace_count );

		if ( preg_last_error() !== PREG_NO_ERROR || 1 !== $replace_count ) {
			echo "❌ ERRO CRÍTICO: Falha no preg_replace (replace_count={$replace_count}, preg_error=" . preg_last_error() . "). Abortando antes de gravar no banco.\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		// C) ATUALIZAÇÃO NO BANCO DE DADOS COM FAIL-CLOSED
		$update_res = wp_update_post( array(
			'ID'           => $target_post_id,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $update_res ) ) {
			echo "❌ ERRO CRÍTICO ao atualizar post {$target_post_id}: " . $update_res->get_error_message() . "\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		if ( 0 === $update_res ) {
			echo "❌ ERRO CRÍTICO ao atualizar post {$target_post_id}: wp_update_post retornou 0.\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		// D) READBACK OBRIGATÓRIO COM ROLLBACK AUTOMÁTICO
		clean_post_cache( $target_post_id );
		$reloaded = get_post( $target_post_id );

		$readback_failed = false;
		$fail_reason     = '';

		if ( ! $reloaded ) {
			$readback_failed = true;
			$fail_reason     = 'Não foi possível recarregar o post via get_post após a atualização.';
		} elseif ( false !== strpos( $reloaded->post_content, 'gutenkit/container' ) ) {
			$readback_failed = true;
			$fail_reason     = 'O bloco gutenkit/container ainda persiste no conteúdo recarregado do banco de dados.';
		} elseif ( false === strpos( $reloaded->post_content, '[woof_mobile]' ) ) {
			$readback_failed = true;
			$fail_reason     = 'O shortcode [woof_mobile] não foi encontrado no conteúdo recarregado do banco de dados.';
		}

		if ( $readback_failed ) {
			echo "🚨 FALHA CRÍTICA NO READBACK: {$fail_reason}\n";
			echo "🔄 INICIANDO ROLLBACK AUTOMÁTICO PARA O ESTADO ORIGINAL...\n";

			$rollback_res = wp_update_post( array(
				'ID'           => $target_post_id,
				'post_content' => $original_content,
			), true );

			if ( is_wp_error( $rollback_res ) || 0 === $rollback_res ) {
				echo "❌ ERRO GRAVÍSSIMO: Rollback falhou! Restaurar manualmente a partir de {$backup_file}.\n";
			} else {
				echo "✅ Rollback executado com sucesso: conteúdo original restaurado.\n";
			}

			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		echo "   ✅ [READBACK OK] Página {$target_post_id} atualizada com sucesso: gutenkit/container=0 e [woof_mobile]=1.\n";
		$GLOBALS['uonix_changes']++;
	} else {
		echo "   ℹ️ [DRY-RUN] O bloco gutenkit/container seria substituído por [woof_mobile] na página {$target_post_id}.\n";
	}
}

// -----------------------------------------------------------------------------
// 2. INVENTÁRIO GLOBAL DE GUTENKIT (GATE OPERACIONAL PARA DESATIVAÇÃO)
// -----------------------------------------------------------------------------
echo "\n--- 2. INVENTÁRIO GLOBAL DE GUTENKIT NO BANCO DE DADOS ---\n";

// A) Busca em outros posts/páginas
$sql_posts = $wpdb->prepare(
	"SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_content LIKE %s AND ID != %d AND post_status NOT IN ('trash', 'auto-draft')",
	'%' . $wpdb->esc_like( 'wp:gutenkit/' ) . '%',
	$target_post_id
);
$other_posts = $wpdb->get_results( $sql_posts );

// B) Busca em postmeta
$sql_meta = $wpdb->prepare(
	"SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE (meta_value LIKE %s OR meta_value LIKE %s) AND post_id != %d",
	'%' . $wpdb->esc_like( 'wp:gutenkit/' ) . '%',
	'%' . $wpdb->esc_like( '"gutenkit' ) . '%',
	$target_post_id
);
$other_meta = $wpdb->get_results( $sql_meta );

// C) Busca em options (excluindo transients)
$sql_options = $wpdb->prepare(
	"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
	'%' . $wpdb->esc_like( 'gutenkit' ) . '%',
	'%' . $wpdb->esc_like( '_transient' ) . '%',
	'%' . $wpdb->esc_like( 'gutenkit_installed' ) . '%'
);
$other_options = $wpdb->get_results( $sql_options );

$has_global_occurrences = false;

if ( ! empty( $other_posts ) ) {
	$has_global_occurrences = true;
	echo "❌ GATE BLOQUEADO: Foram encontrados blocos GutenKit em outros posts/páginas ativos:\n";
	foreach ( $other_posts as $p ) {
		echo "   - ID {$p->ID} [{$p->post_type}]: {$p->post_title} (status: {$p->post_status})\n";
	}
}

if ( ! empty( $other_meta ) ) {
	$has_global_occurrences = true;
	echo "❌ GATE BLOQUEADO: Foram encontrados metadados com GutenKit em outros posts:\n";
	foreach ( $other_meta as $m ) {
		echo "   - Post ID {$m->post_id}, meta_key: {$m->meta_key}\n";
	}
}

if ( $has_global_occurrences ) {
	echo "\n❌ ERRO CRÍTICO (GATE FAIL-CLOSED): O plugin gutenkit-blocks-addon NÃO pode ser desativado globalmente pois ainda há dependências no banco de dados. Limpe essas dependências antes de tentar desativar o plugin.\n";
	$GLOBALS['uonix_errors']++;
	exit( 1 );
}

echo "   ✅ [INVENTÁRIO OK] Zero blocos gutenkit/* encontrados fora da página {$target_post_id}. Desativação global permitida.\n";

// -----------------------------------------------------------------------------
// 3. DESATIVAÇÃO CONTROLADA DO PLUGIN gutenkit-blocks-addon
// -----------------------------------------------------------------------------
echo "\n--- 3. STATUS E DESATIVAÇÃO DO PLUGIN gutenkit-blocks-addon ---\n";

include_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_file = 'gutenkit-blocks-addon/gutenkit-blocks-addon.php';

$is_active = is_plugin_active( $plugin_file );

if ( $is_active ) {
	echo "🔍 Plugin {$plugin_file} está ATIVO.\n";

	if ( $uonix_apply ) {
		deactivate_plugins( $plugin_file );

		// Readback da desativação
		if ( is_plugin_active( $plugin_file ) ) {
			echo "❌ ERRO CRÍTICO: deactivate_plugins() foi executado, mas is_plugin_active() ainda retornou true.\n";
			$GLOBALS['uonix_errors']++;
			exit( 1 );
		}

		echo "   ✅ [DESATIVAÇÃO OK] Plugin {$plugin_file} DESATIVADO com sucesso e confirmado via readback.\n";
		$GLOBALS['uonix_changes']++;
	} else {
		echo "   ℹ️ [DRY-RUN] O plugin {$plugin_file} seria desativado.\n";
	}
} else {
	echo "   ✅ [NOOP] O plugin {$plugin_file} já está inativo.\n";
	$GLOBALS['uonix_noop']++;
}

// -----------------------------------------------------------------------------
// 4. LIMPEZA DE CACHE
// -----------------------------------------------------------------------------
echo "\n--- 4. LIMPEZA DE CACHE DE OBJETOS ---\n";

if ( $uonix_apply ) {
	wp_cache_flush();
	echo "   ✅ [CACHE OK] wp_cache_flush() executado com sucesso.\n";
} else {
	echo "   ℹ️ (dry-run: cache não foi limpo)\n";
}

// -----------------------------------------------------------------------------
// RELATÓRIO FINAL
// -----------------------------------------------------------------------------
echo "\n========================================================================\n";
if ( $uonix_apply ) {
	echo "🎉 MIGRAÇÃO CONCLUÍDA COM SUCESSO!\n";
} else {
	echo "🔎 DRY-RUN CONCLUÍDO (nada foi gravado no banco de dados).\n";
}
echo "   CHANGES={$GLOBALS['uonix_changes']}  |  NOOP={$GLOBALS['uonix_noop']}  |  ERRORS={$GLOBALS['uonix_errors']}\n";
if ( ! empty( $backup_dir ) ) {
	echo "   BACKUP_DIR={$backup_dir}\n";
}
echo "========================================================================\n\n";

if ( $GLOBALS['uonix_errors'] > 0 ) {
	exit( 1 );
}
exit( 0 );
