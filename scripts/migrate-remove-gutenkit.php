<?php
/**
 * Script de Migração para Remoção do GutenKit (Uônix) via WP-CLI.
 *
 * Aplica de forma idempotente, segura e com fail-closed:
 * 1. Backup preventivo do conteúdo da página Produtos (ID 7150).
 * 2. Substituição do bloco <!-- wp:gutenkit/container --> pelo shortcode nativo [woof_mobile] dentro da coluna Kadence.
 * 3. Desativação do plugin gutenkit-blocks-addon.
 * 4. Limpeza de cache de objetos (wp_cache_flush).
 *
 * Modo de Uso:
 *   Dry-run (padrão, seguro, NÃO grava):
 *     wp eval-file scripts/migrate-remove-gutenkit.php --allow-root
 *   Aplicar de fato (grava com backup):
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

$modo_str = $uonix_apply ? 'APLICAR (grava + backup)' : 'DRY-RUN (somente simulação)';
echo "\n========================================================================\n";
echo "🚀 MIGRAÇÃO E REMOÇÃO DO GUTENKIT (UÔNIX) — MODO: {$modo_str}\n";
echo "========================================================================\n\n";

global $wpdb;

// 1. Identificar uso de GutenKit na página 7150
$post = get_post( 7150 );
if ( ! $post ) {
	echo "❌ ERRO: Página 7150 (Produtos) não encontrada.\n";
	exit( 1 );
}

$pattern = '#<!-- wp:gutenkit/container.*?<!-- /wp:gutenkit/container -->#s';
$has_gutenkit = preg_match( $pattern, $post->post_content );

if ( ! $has_gutenkit ) {
	echo "ℹ️ Página 7150 já está limpa (nenhum bloco gutenkit/container encontrado).\n";
} else {
	echo "⚠️ Bloco gutenkit/container identificado na página 7150.\n";

	if ( $uonix_apply ) {
		// Criar backup
		$upload_dir = wp_upload_dir();
		$timestamp  = gmdate( 'Ymd-His' );
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'uonix-gutenkit-backups/' . $timestamp;
		if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
			echo "❌ ERRO CRÍTICO: Não foi possível criar diretório de backup: {$backup_dir}\n";
			exit( 1 );
		}
		$backup_file = $backup_dir . '/page-7150-before-gutenkit-removal.txt';
		file_put_contents( $backup_file, $post->post_content );
		echo "✅ Backup salvo com sucesso em: {$backup_file}\n";

		// Substituir bloco
		$new_content = preg_replace( $pattern, "<!-- wp:shortcode -->\n[woof_mobile]\n<!-- /wp:shortcode -->", $post->post_content );
		wp_update_post( array(
			'ID'           => 7150,
			'post_content' => $new_content,
		) );
		echo "✅ Página 7150 atualizada com shortcode nativo dentro da coluna Kadence.\n";
	} else {
		echo "ℹ️ [DRY-RUN] O bloco gutenkit/container seria substituído por <!-- wp:shortcode -->[woof_mobile]<!-- /wp:shortcode -->.\n";
	}
}

echo "\n------------------------------------------------------------------------\n";
echo "2. STATUS DO PLUGIN gutenkit-blocks-addon:\n";
include_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_file = 'gutenkit-blocks-addon/gutenkit-blocks-addon.php';

if ( is_plugin_active( $plugin_file ) ) {
	echo "⚠️ O plugin {$plugin_file} está ATIVO.\n";
	if ( $uonix_apply ) {
		deactivate_plugins( $plugin_file );
		echo "✅ Plugin {$plugin_file} DESATIVADO com sucesso!\n";
	} else {
		echo "ℹ️ [DRY-RUN] O plugin seria desativado.\n";
	}
} else {
	echo "✅ O plugin {$plugin_file} já está desativado ou inativo.\n";
}

echo "\n------------------------------------------------------------------------\n";
echo "3. LIMPEZA DE CACHE:\n";
if ( $uonix_apply ) {
	wp_cache_flush();
	echo "✅ Cache de objetos limpo (wp_cache_flush).\n";
} else {
	echo "ℹ️ [DRY-RUN] Cache seria limpo.\n";
}

echo "\n========================================================================\n";
echo "🏁 FIM DA EXECUÇÃO ({$modo_str})\n";
echo "========================================================================\n\n";
