<?php
/**
 * Script de Sincronização e Publicação das Páginas Legais (LGPD) via WP-CLI.
 *
 * Aplica de forma idempotente e SEGURA o conteúdo canônico de docs/legal/ nas páginas do WordPress:
 * 1. Política de Cookies (/politica-de-cookies/) <- docs/legal/politica-de-cookies-content.html
 * 2. Política de Privacidade (/politica-de-privacidade/) <- docs/legal/politica-de-privacidade-content.html
 * 3. Termos de Uso (/termos-de-uso/) <- docs/legal/termos-de-uso-content.html
 *
 * Governança e Segurança (fail-closed):
 * - NÃO usa IDs hardcoded: resolve as páginas exclusivamente pelos slugs canônicos.
 * - Modo DRY-RUN por padrão: nenhuma alteração é feita a menos que o argumento literal 'apply' seja fornecido.
 * - Backup automático prévio: salva snapshot completo do post antes de qualquer mutação, validando escrita > 0 bytes.
 * - Readback Integral Verificado: valida que o conteúdo persistido no banco possui exatamente o mesmo hash SHA256 do documento canônico.
 * - Verificação de Integridade Pública (--verify-public): inspeciona o HTML público das páginas para confirmar ausência de descompasso de cache.
 * - Idempotente: reexecuções subsequentes identificam 0 alterações necessárias.
 * - Validação de integridade: aborta se os arquivos canônicos em docs/legal/ estiverem vazios ou ausentes.
 *
 * Modo de Uso:
 *   Dry-run (somente auditoria/planejamento):
 *     Local:      wp eval-file scripts/apply-legal-policies-production.php --allow-root
 *     Produção:   /usr/bin/php85 /caminho/wp-cli.phar eval-file scripts/apply-legal-policies-production.php --path=/home/storage/f/34/12/siteuonix1/public_html
 *   Aplicar de fato (grava + backup verificado + readback SHA256 + cache flush):
 *     Local:      wp eval-file scripts/apply-legal-policies-production.php apply --allow-root
 *     Produção:   /usr/bin/php85 /caminho/wp-cli.phar eval-file scripts/apply-legal-policies-production.php apply --path=/home/storage/f/34/12/siteuonix1/public_html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Este script deve ser executado via WP-CLI (wp eval-file).\n" );
}

// -------------------------------------------------------------------------
// 1. Definição do Modo: DRY-RUN vs APPLY e Flags de Verificação
// -------------------------------------------------------------------------
$UONIX_APPLY         = false;
$UONIX_VERIFY_PUBLIC = false;

if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $a ) {
		$arg_str = strtolower( trim( (string) $a ) );
		if ( 'apply' === $arg_str ) {
			$UONIX_APPLY = true;
		} elseif ( in_array( $arg_str, array( 'verify-public', '--verify-public' ), true ) ) {
			$UONIX_VERIFY_PUBLIC = true;
		}
	}
}

$mode_label = $UONIX_APPLY ? 'APLICAR (grava + backup + readback SHA256)' : 'DRY-RUN (somente planejamento/auditoria)';

echo "========================================================================\n";
echo "📜 SINCRONIZAÇÃO DE PÁGINAS LEGAIS (LGPD) — MODO: {$mode_label}\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// 2. Mapeamento dos Arquivos Canônicos e Slugs
// -------------------------------------------------------------------------
$root_dir  = dirname( __DIR__ );
$legal_dir = $root_dir . '/docs/legal';
if ( ! file_exists( $legal_dir . '/politica-de-cookies-content.html' ) ) {
	if ( file_exists( '/tmp/legal/politica-de-cookies-content.html' ) ) {
		$legal_dir = '/tmp/legal';
	} elseif ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'docs/legal/politica-de-cookies-content.html' ) ) {
		$legal_dir = ABSPATH . 'docs/legal';
	}
}

$policies = array(
	'politica-de-cookies' => array(
		'title'         => 'Política de Cookies',
		'file'          => $legal_dir . '/politica-de-cookies-content.html',
		'checks_db'     => array(
			'_gcl_aw',
			'_gcl_dc',
			'_gac_*',
			'Conversões e Atribuição Ads',
			'Google LLC (Google Ads / Vinculador de Conversões, via Google Tag Manager)',
		),
		'checks_public' => array(
			'_gcl_aw',
			'_gcl_dc',
			'_gac_*',
			'Conversões e Atribuição Ads',
			'Google LLC',
		),
	),
	'politica-de-privacidade' => array(
		'title'         => 'Política de Privacidade',
		'file'          => $legal_dir . '/politica-de-privacidade-content.html',
		'checks_db'     => array(
			'[uonix email_lgpd]',
			'Canal de Privacidade e Atendimento ao Titular',
			'Autoridade Nacional de Proteção de Dados',
			'Lei Geral de Proteção de Dados',
		),
		'checks_public' => array(
			'[uonix email_lgpd]',
			'Canal de Privacidade e Atendimento ao Titular',
			'Autoridade Nacional de Proteção de Dados',
			'Lei Geral de Proteção de Dados',
		),
	),
	'termos-de-uso' => array(
		'title'         => 'Termos de Uso',
		'file'          => $legal_dir . '/termos-de-uso-content.html',
		'checks_db'     => array(
			'Política de Cookies',
			'Política de Privacidade',
		),
		'checks_public' => array(
			'Política de Cookies',
			'Política de Privacidade',
		),
	),
);

// Diretório de backup para o modo apply
$backup_dir = '';
if ( $UONIX_APPLY ) {
	$upload_dir = wp_upload_dir();
	$backup_dir = $upload_dir['basedir'] . '/_uonix-backups/legal-policies';
	if ( ! file_exists( $backup_dir ) ) {
		wp_mkdir_p( $backup_dir );
		file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" );
		file_put_contents( $backup_dir . '/index.php', "<?php // Silence\n" );
	}
}

$changes = 0;
$noops   = 0;
$errors  = 0;

foreach ( $policies as $slug => $config ) {
	echo "--- Verificando página: {$config['title']} (slug: '{$slug}') ---\n";

	if ( ! file_exists( $config['file'] ) ) {
		echo "  [ERRO] Arquivo canônico não encontrado: {$config['file']}\n";
		$errors++;
		continue;
	}

	$canonical_content = file_get_contents( $config['file'] );
	if ( empty( $canonical_content ) || strlen( $canonical_content ) < 100 ) {
		echo "  [ERRO] Conteúdo do arquivo canônico inválido ou vazio: {$config['file']}\n";
		$errors++;
		continue;
	}

	// Localiza o post pelo slug
	$query = new WP_Query( array(
		'name'           => $slug,
		'post_type'      => 'page',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );

	if ( ! $query->have_posts() ) {
		echo "  [AVISO] Página com slug '{$slug}' não encontrada no banco do WordPress.\n";
		$errors++;
		continue;
	}

	$post            = $query->posts[0];
	$current_content = $post->post_content;

	// Normaliza quebras de linha para comparação justa e cálculo de hash canônico
	$norm_current   = trim( str_replace( "\r\n", "\n", $current_content ) );
	$norm_canonical = trim( str_replace( "\r\n", "\n", $canonical_content ) );

	$current_hash   = hash( 'sha256', $norm_current );
	$canonical_hash = hash( 'sha256', $norm_canonical );

	$is_identical = ( $current_hash === $canonical_hash );

	// Validação das chaves essenciais no banco atual
	$missing_in_current = array();
	foreach ( $config['checks_db'] as $chk ) {
		if ( false === strpos( $norm_current, $chk ) ) {
			$missing_in_current[] = $chk;
		}
	}

	if ( $is_identical ) {
		echo "  [OK] Conteúdo já está 100% sincronizado com docs/legal/ (ID: {$post->ID}, SHA256: {$current_hash}).\n\n";
		$noops++;
		continue;
	}

	echo "  [DIVERGÊNCIA] Conteúdo do banco difere do documento canônico no repositório.\n";
	echo "    Hash Canônico: {$canonical_hash}\n";
	echo "    Hash Banco:    {$current_hash}\n";
	if ( ! empty( $missing_in_current ) ) {
		echo "  [CRÍTICO] A versão do banco NÃO contém termos obrigatórios: " . implode( ', ', $missing_in_current ) . "\n";
	}

	if ( ! $UONIX_APPLY ) {
		echo "  [DRY-RUN] Seria atualizado o post ID {$post->ID} com o conteúdo canônico (" . strlen( $canonical_content ) . " bytes).\n\n";
		$changes++;
	} else {
		// Modo APPLY: Backup prévio estritamente verificado
		$backup_file = sprintf(
			'%s/backup-%s-%d-%s.json',
			$backup_dir,
			$slug,
			$post->ID,
			date( 'Ymd-His' )
		);
		$backup_data = array(
			'post_id'      => $post->ID,
			'post_title'   => $post->post_title,
			'post_name'    => $post->post_name,
			'post_content' => base64_encode( $current_content ),
			'sha256'       => $current_hash,
			'timestamp'    => time(),
			'date'         => date( 'c' ),
		);
		$written = @file_put_contents( $backup_file, json_encode( $backup_data, JSON_PRETTY_PRINT ) );
		if ( false === $written || ! file_exists( $backup_file ) || filesize( $backup_file ) === 0 ) {
			echo "  [ERRO CRÍTICO] Falha ao criar arquivo de backup para o post ID {$post->ID}. Mutação abortada em fail-closed.\n\n";
			$errors++;
			continue;
		}
		echo "  [BACKUP VERIFICADO] Criado em: {$backup_file} (" . filesize( $backup_file ) . " bytes)\n";

		// Aplica atualização garantindo integridade dos blocos Gutenberg/Kadence
		if ( function_exists( 'kses_remove_filters' ) ) {
			kses_remove_filters();
		}
		$update_result = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $canonical_content ),
		), true );
		if ( function_exists( 'kses_init_filters' ) ) {
			kses_init_filters();
		}

		if ( is_wp_error( $update_result ) || (int) $update_result <= 0 ) {
			$err_msg = is_wp_error( $update_result ) ? $update_result->get_error_message() : 'Retorno inválido de wp_update_post';
			echo "  [ERRO] Falha ao atualizar post ID {$post->ID}: {$err_msg}\n\n";
			$errors++;
			continue;
		}

		// Readback Verificado Integral: valida que o post persistido reflete 100% o hash SHA256 canônico
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post->ID );
		}
		$fresh_post = get_post( $post->ID );
		if ( ! $fresh_post ) {
			echo "  [ERRO CRÍTICO READBACK] Post ID {$post->ID} atualizado mas get_post retornou nulo!\n\n";
			$errors++;
			continue;
		}

		$norm_fresh = trim( str_replace( "\r\n", "\n", $fresh_post->post_content ) );
		$fresh_hash = hash( 'sha256', $norm_fresh );

		if ( $fresh_hash !== $canonical_hash ) {
			echo "  [ERRO CRÍTICO READBACK] Post ID {$post->ID} atualizado mas o readback integral falhou: hash SHA256 diverge do documento canônico!\n";
			echo "    Hash Esperado (canônico): {$canonical_hash}\n";
			echo "    Hash Obtido (banco):      {$fresh_hash}\n\n";
			$errors++;
			continue;
		}

		// Valida adicionalmente que todas as chaves obrigatórias de banco estão presentes no readback
		$missing_readback = array();
		foreach ( $config['checks_db'] as $chk ) {
			if ( false === strpos( $norm_fresh, $chk ) ) {
				$missing_readback[] = $chk;
			}
		}
		if ( ! empty( $missing_readback ) ) {
			echo "  [ERRO CRÍTICO READBACK] Post ID {$post->ID} atualizado mas termos obrigatórios ausentes no readback: " . implode( ', ', $missing_readback ) . "\n\n";
			$errors++;
			continue;
		}

		echo "  [SUCESSO & READBACK VERIFICADO] Post ID {$post->ID} atualizado e confirmado com paridade SHA256 integral ({$fresh_hash})!\n\n";
		$changes++;
	}
}

if ( $UONIX_APPLY && $changes > 0 ) {
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
		echo "🧹 Cache do WordPress limpo com sucesso.\n";
	}
}

// -------------------------------------------------------------------------
// 3. Verificação de Integridade Pública (Opcional ou sob demanda com fail-closed)
// -------------------------------------------------------------------------
if ( ! function_exists( 'uonix_verify_public_policy_response' ) ) {
	function uonix_verify_public_policy_response( $http_code, $public_html, $expected_terms, $error_detail = '' ) {
		if ( empty( $public_html ) || 200 !== (int) $http_code ) {
			return array(
				'success' => false,
				'errors'  => 1,
				'missing' => $expected_terms,
				'reason'  => "Não foi possível consultar a página pública (HTTP {$http_code}; erro: {$error_detail})"
			);
		}

		$missing_public = array();
		foreach ( $expected_terms as $chk ) {
			if ( false === strpos( $public_html, $chk ) ) {
				$missing_public[] = $chk;
			}
		}

		if ( ! empty( $missing_public ) ) {
			return array(
				'success' => false,
				'errors'  => count( $missing_public ),
				'missing' => $missing_public,
				'reason'  => 'Página pública ainda não reflete termos obrigatórios: ' . implode( ', ', $missing_public ) . ' (necessário expirar cache de CDN/Nginx da Locaweb)'
			);
		}

		return array(
			'success' => true,
			'errors'  => 0,
			'missing' => array(),
			'reason'  => 'Conteúdo público reflete todos os termos canônicos (HTTP 200)'
		);
	}
}

if ( ! function_exists( 'uonix_resolve_public_policy_terms' ) ) {
	function uonix_resolve_public_policy_terms( $expected_terms ) {
		$resolved_terms = array();
		foreach ( $expected_terms as $expected_term ) {
			if ( '[uonix email_lgpd]' !== $expected_term ) {
				$resolved_terms[] = $expected_term;
				continue;
			}

			$rendered_email = function_exists( 'do_shortcode' ) ? trim( do_shortcode( $expected_term ) ) : '';
			$is_valid_email = function_exists( 'is_email' )
				? false !== is_email( $rendered_email )
				: false !== filter_var( $rendered_email, FILTER_VALIDATE_EMAIL );
			if ( '' === $rendered_email || '[uonix email_lgpd]' === $rendered_email || ! $is_valid_email ) {
				return array(
					'success' => false,
					'terms'   => array(),
					'reason'  => 'Shortcode [uonix email_lgpd] não foi resolvido para um e-mail válido e público verificável.',
				);
			}

			$resolved_terms[] = $rendered_email;
		}

		return array(
			'success' => true,
			'terms'   => $resolved_terms,
			'reason'  => '',
		);
	}
}

if ( $UONIX_VERIFY_PUBLIC ) {
	echo "\n🌐 Verificando páginas públicas para conformidade contra cache de borda...\n";
	foreach ( $policies as $slug => $config ) {
		$page_url = function_exists( 'home_url' ) ? home_url( '/' . $slug . '/' ) : 'https://www.uonix.com.br/' . $slug . '/';
		echo "  Testando URL: {$page_url} ... ";

		$public_html  = '';
		$http_code    = 0;
		$error_detail = '';

		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get( $page_url, array( 'timeout' => 15, 'sslverify' => true ) );
			if ( is_wp_error( $response ) ) {
				$error_detail = $response->get_error_message();
			} else {
				$http_code   = (int) wp_remote_retrieve_response_code( $response );
				$public_html = wp_remote_retrieve_body( $response );
			}
		}

		if ( empty( $public_html ) && function_exists( 'curl_init' ) ) {
			$ch = curl_init( $page_url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			$public_html = curl_exec( $ch );
			$http_code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( curl_errno( $ch ) ) {
				$error_detail = curl_error( $ch );
			}
			curl_close( $ch );
		}

		$resolved_checks = uonix_resolve_public_policy_terms( $config['checks_public'] );
		if ( ! $resolved_checks['success'] ) {
			echo "[ERRO PÚBLICO] {$resolved_checks['reason']}\n";
			$errors++;
			continue;
		}

		$ver_res = uonix_verify_public_policy_response( $http_code, $public_html, $resolved_checks['terms'], $error_detail );
		if ( ! $ver_res['success'] ) {
			echo "[ERRO PÚBLICO] {$ver_res['reason']}\n";
			$errors += $ver_res['errors'];
		} else {
			echo "[OK PÚBLICO] {$ver_res['reason']}.\n";
		}
	}
}

echo "\n========================================================================\n";
echo "RELATÓRIO: Modificações: {$changes} | Já sincronizados: {$noops} | Erros: {$errors}\n";
echo "========================================================================\n";

if ( ! $UONIX_APPLY && $changes > 0 ) {
	echo "\n🛡️  Modo DRY-RUN: Nenhuma alteração foi gravada.\n";
	echo "Para aplicar de fato com backup prévio e readback verificado, execute:\n";
	echo "  wp eval-file scripts/apply-legal-policies-production.php apply\n\n";
}

// Bloqueio Fail-Closed: se houver qualquer erro, encerra com código de saída 1
if ( $errors > 0 ) {
	echo "\n[FAIL-CLOSED] Execução finalizada com {$errors} erro(s). Abortando com código 1.\n";
	exit( 1 );
}
