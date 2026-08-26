<?php
/**
 * Script de Sincronização de Metadados de SEO e Bloco FAQ via WP-CLI.
 *
 * Aplica de forma idempotente e SEGURA:
 * 1. Metadados Rank Math (Title, Description, Focus Keyword) por SLUG (compatível com IDs de produção).
 * 2. Sincroniza o Bloco Padrão de FAQ (wp_block slug 'faq') TRANSFORMANDO o post_content REAL do
 *    ambiente-alvo (nunca sobrescreve com um HTML de outro ambiente): corrige "olhar"->"olhal",
 *    neutraliza URLs de ambiente (localhost), insere perguntas ausentes ancorando no fechamento do
 *    accordion, e RECALCULA o paneCount a partir do número real de panes.
 * 3. Limpeza de cache ao finalizar.
 *
 * Segurança (fail-closed):
 * - NÃO usa ID hardcoded: o bloco FAQ é resolvido exclusivamente pelo slug 'faq'.
 * - Guarda anti-vazamento: aborta a gravação do FAQ se o conteúdo final contiver 'localhost' ou '<h1'.
 * - Backup automático (content + meta em base64->json) de cada alvo tocado antes de gravar (modo apply).
 * - Idempotente: reexecutar não deve produzir mudanças (CHANGES=0 no segundo dry-run).
 *
 * Compatível com PHP 7.1+ (ambiente de produção Locaweb).
 *
 * Modo de Uso:
 *   Dry-run (padrão, NÃO grava):
 *     Local:      wp eval-file scripts/apply-seo-metadata-production.php --allow-root
 *     Produção:   /usr/bin/php85 /caminho/wp-cli.phar eval-file scripts/apply-seo-metadata-production.php --path=/home/storage/f/34/12/siteuonix1/public_html
 *   Aplicar de fato (grava + backup):
 *     acrescente o argumento posicional literal: ... eval-file scripts/apply-seo-metadata-production.php apply
 *
 * O modo é lido de $args[0] (WP-CLI passa argumentos posicionais após o nome do arquivo).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Este script deve ser executado via WP-CLI (wp eval-file).\n" );
}

// -------------------------------------------------------------------------
// MODO: dry-run (padrão) vs apply. WP-CLI expõe posicionais em $args.
// -------------------------------------------------------------------------
$UONIX_APPLY = false;
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $a ) {
		if ( 'apply' === strtolower( trim( (string) $a ) ) ) {
			$UONIX_APPLY = true;
		}
	}
}

$GLOBALS['uonix_apply']   = $UONIX_APPLY;
$GLOBALS['uonix_changes'] = 0; // nº de gravações que ocorreriam (dry) ou ocorreram (apply)
$GLOBALS['uonix_skipped'] = 0;
$GLOBALS['uonix_noop']    = 0; // já estava no valor desejado

$mode_label = $UONIX_APPLY ? 'APLICAR (grava + backup)' : 'DRY-RUN (somente simulação)';

echo "========================================================================\n";
echo "🚀 SINCRONIZAÇÃO DE METADADOS SEO E FAQ (UÔNIX) — MODO: {$mode_label}\n";
echo "========================================================================\n\n";

// Diretório de backup (apenas no modo apply).
$GLOBALS['uonix_backup_dir'] = '';
if ( $UONIX_APPLY ) {
	$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH;
	$dir  = rtrim( $base, '/' ) . '/uploads/uonix-seo-backups/' . gmdate( 'Ymd-His' );
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	$GLOBALS['uonix_backup_dir'] = is_dir( $dir ) ? $dir : '';
	echo "🗂  Backups desta execução: " . ( $GLOBALS['uonix_backup_dir'] ?: '(FALHA ao criar — abortará gravações)' ) . "\n\n";
}

/**
 * Salva um snapshot (content + meta) do post/termo antes de gravar. Só no modo apply.
 * $type: 'post' | 'term'
 */
function uonix_backup_target( $type, $id ) {
	if ( ! $GLOBALS['uonix_apply'] ) {
		return true; // dry-run não faz backup
	}
	if ( empty( $GLOBALS['uonix_backup_dir'] ) ) {
		return false; // sem diretório de backup => fail-closed
	}
	$snapshot = array( 'type' => $type, 'id' => $id, 'time' => gmdate( 'c' ) );
	if ( 'post' === $type ) {
		$p = get_post( $id );
		if ( $p ) {
			$snapshot['post_title']   = $p->post_title;
			$snapshot['post_content'] = $p->post_content;
		}
		$snapshot['meta'] = get_post_meta( $id );
	} else {
		$snapshot['meta'] = get_term_meta( $id );
	}
	$json = wp_json_encode( $snapshot );
	if ( false === $json ) {
		echo "   ⚠️  backup: wp_json_encode falhou (" . ( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'erro desconhecido' ) . ") para {$type} ID {$id}.\n";
		return false;
	}
	$file = $GLOBALS['uonix_backup_dir'] . '/' . $type . '-' . $id . '.json';
	return false !== file_put_contents( $file, $json );
}

/**
 * Aplica uma meta se o valor for diferente do atual. Conta mudança/no-op.
 * Retorna true se houve (ou haveria) gravação.
 */
function uonix_set_post_meta_if_changed( $post_id, $key, $value ) {
	$current = get_post_meta( $post_id, $key, true );
	if ( (string) $current === (string) $value ) {
		$GLOBALS['uonix_noop']++;
		return false;
	}
	if ( $GLOBALS['uonix_apply'] ) {
		update_post_meta( $post_id, $key, $value );
	}
	$GLOBALS['uonix_changes']++;
	return true;
}

function uonix_set_term_meta_if_changed( $term_id, $key, $value ) {
	$current = get_term_meta( $term_id, $key, true );
	if ( (string) $current === (string) $value ) {
		$GLOBALS['uonix_noop']++;
		return false;
	}
	if ( $GLOBALS['uonix_apply'] ) {
		update_term_meta( $term_id, $key, $value );
	}
	$GLOBALS['uonix_changes']++;
	return true;
}

/**
 * Sincroniza metadados Rank Math de um post/página por slug (aceita aliases).
 */
function uonix_sync_post_meta( $slugs, $post_type, $title, $desc, $focus_kw ) {
	$candidates = (array) $slugs;
	$post_found = null;
	$matched_slug = '';

	foreach ( $candidates as $candidate_slug ) {
		$posts = get_posts( array(
			'name'           => $candidate_slug,
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
		) );
		if ( ! empty( $posts ) ) {
			$post_found   = $posts[0];
			$matched_slug = $candidate_slug;
			break;
		}
	}

	if ( ! $post_found ) {
		echo "⚠️  [NÃO ENCONTRADO] {$post_type}: " . implode( ' / ', $candidates ) . "\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$post_id = $post_found->ID;

	// backup antes de qualquer gravação (uma vez por post tocado)
	if ( ! uonix_backup_target( 'post', $post_id ) ) {
		echo "⛔ [ABORTA] Falha ao salvar backup do post ID {$post_id} — nada gravado.\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$c1 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_title', $title );
	$c2 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_description', $desc );
	$c3 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_focus_keyword', $focus_kw );

	$changed = ( $c1 || $c2 || $c3 );
	$tag     = $GLOBALS['uonix_apply'] ? ( $changed ? 'ATUALIZADO' : 'sem mudança' ) : ( $changed ? 'MUDARIA' : 'sem mudança' );
	echo "   [{$tag}] {$post_type} (ID {$post_id}) '{$matched_slug}'\n";
}

/**
 * Sincroniza título do post e metadados Rank Math por slug (aceita aliases).
 */
function uonix_sync_post_title_and_meta( $slugs, $post_type, $clean_title, $title, $desc, $focus_kw ) {
	$candidates = (array) $slugs;
	$post_found = null;
	$matched_slug = '';

	foreach ( $candidates as $candidate_slug ) {
		$posts = get_posts( array(
			'name'           => $candidate_slug,
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
		) );
		if ( ! empty( $posts ) ) {
			$post_found   = $posts[0];
			$matched_slug = $candidate_slug;
			break;
		}
	}

	if ( ! $post_found ) {
		echo "⚠️  [NÃO ENCONTRADO] {$post_type}: " . implode( ' / ', $candidates ) . "\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$post_id = $post_found->ID;

	if ( ! uonix_backup_target( 'post', $post_id ) ) {
		echo "⛔ [ABORTA] Falha ao salvar backup do post ID {$post_id} — nada gravado.\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$title_changed = false;
	if ( ! empty( $clean_title ) && $post_found->post_title !== $clean_title ) {
		$GLOBALS['uonix_changes']++;
		$title_changed = true;
		if ( $GLOBALS['uonix_apply'] ) {
			wp_update_post( array(
				'ID'         => $post_id,
				'post_title' => $clean_title,
			) );
		}
	} else {
		$GLOBALS['uonix_noop']++;
	}

	$c1 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_title', $title );
	$c2 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_description', $desc );
	$c3 = uonix_set_post_meta_if_changed( $post_id, 'rank_math_focus_keyword', $focus_kw );

	$changed = ( $title_changed || $c1 || $c2 || $c3 );
	$tag     = $GLOBALS['uonix_apply'] ? ( $changed ? 'ATUALIZADO' : 'sem mudança' ) : ( $changed ? 'MUDARIA' : 'sem mudança' );
	echo "   [{$tag}] {$post_type} (ID {$post_id}) '{$matched_slug}'\n";
}

/**
 * Sincroniza metadados Rank Math de um termo (categoria) por slug.
 */
function uonix_sync_term_meta( $slug, $taxonomy, $title, $desc, $focus_kw ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		echo "⚠️  [NÃO ENCONTRADO] Termo {$taxonomy}: '{$slug}'\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$term_id = $term->term_id;

	if ( ! uonix_backup_target( 'term', $term_id ) ) {
		echo "⛔ [ABORTA] Falha ao salvar backup do termo ID {$term_id} — nada gravado.\n";
		$GLOBALS['uonix_skipped']++;
		return;
	}

	$c1 = uonix_set_term_meta_if_changed( $term_id, 'rank_math_title', $title );
	$c2 = uonix_set_term_meta_if_changed( $term_id, 'rank_math_description', $desc );
	$c3 = uonix_set_term_meta_if_changed( $term_id, 'rank_math_focus_keyword', $focus_kw );

	$changed = ( $c1 || $c2 || $c3 );
	$tag     = $GLOBALS['uonix_apply'] ? ( $changed ? 'ATUALIZADO' : 'sem mudança' ) : ( $changed ? 'MUDARIA' : 'sem mudança' );
	echo "   [{$tag}] Categoria (ID {$term_id}) '{$slug}'\n";
}

// =========================================================================
// 1. PÁGINAS INSTITUCIONAIS E HUBS
// =========================================================================
echo "--- 1. PÁGINAS INSTITUCIONAIS E COMERCIAIS ---\n";

$front_page_id = get_option( 'page_on_front' );
if ( $front_page_id ) {
	if ( uonix_backup_target( 'post', $front_page_id ) ) {
		$h1 = uonix_set_post_meta_if_changed( $front_page_id, 'rank_math_title', 'Fabricante de Ancoragem Predial e Dispositivos de Ancoragem | Uônix' );
		$h2 = uonix_set_post_meta_if_changed( $front_page_id, 'rank_math_description', 'Fabricante de dispositivos de ancoragem predial e olhais em aço inox 304/316. Projetos, ensaios de arrancamento e instalação NR-35 com ART para todo o Brasil.' );
		$h3 = uonix_set_post_meta_if_changed( $front_page_id, 'rank_math_focus_keyword', 'home, ancoragem predial, dispositivos de ancoragem, olhal de ancoragem, nr-35' );
		$changed = ( $h1 || $h2 || $h3 );
		$tag = $GLOBALS['uonix_apply'] ? ( $changed ? 'ATUALIZADO' : 'sem mudança' ) : ( $changed ? 'MUDARIA' : 'sem mudança' );
		echo "   [{$tag}] Página Inicial / Home (ID {$front_page_id})\n";
	} else {
		echo "⛔ [ABORTA] Falha ao salvar backup da Home (ID {$front_page_id}).\n";
		$GLOBALS['uonix_skipped']++;
	}
}

uonix_sync_post_meta(
	'produtos', 'page',
	'Dispositivos de Ancoragem e Linha de Fixação | Fábrica Uônix',
	'Catálogo completo de dispositivos de ancoragem, olhais em inox 304 e 316, fixação química e mecânica. Venda direto da fábrica com laudo de teste para todo o Brasil.',
	'produtos, dispositivos de ancoragem, produtos de ancoragem, olhal de ancoragem'
);

uonix_sync_post_meta(
	'servicos', 'page',
	'Serviços de Ancoragem Predial e Linhas de Vida NR-35 | Uônix',
	'Serviços especializados em ancoragem predial: instalação de pontos, ensaios de arrancamento estático 1.500 kgf, projetos executivos e emissão de ART CREA em todo o Brasil.',
	'serviços, serviços de ancoragem, instalação de ancoragem, ensaio de arrancamento'
);

uonix_sync_post_meta(
	'empresa', 'page',
	'Sobre a Uônix | Especialista em Ancoragem Predial e Segurança',
	'Conheça a Uônix: fabricante nacional e consultoria de engenharia especializada em sistemas de ancoragem predial, linhas de vida e conformidade com a NR-35 e NBR 16325.',
	'empresa, sobre a uônix, uônix, fabricante de ancoragem predial'
);

uonix_sync_post_meta(
	'blog', 'page',
	'Blog Uônix | Artigos Técnicos sobre Ancoragem Predial e NR-35',
	'Artigos técnicos, guias e conteúdos especializados sobre ancoragem predial, ensaios de arrancamento, cálculo de ZLQ e normas de segurança em altura.',
	'blog, blog ancoragem predial, artigos nr-35'
);

uonix_sync_post_meta(
	array( 'cotacao', 'orcamento', 'contato' ), 'page',
	'Solicite Cotação e Orçamento | Uônix Ancoragem Predial',
	'Solicite seu orçamento de dispositivos de ancoragem direto da fábrica ou consulte nosso departamento de engenharia para projetos e instalações NR-35 em todo o país.',
	'orçamento, orçamento ancoragem predial, cotação dispositivos de ancoragem, contato uonix'
);

uonix_sync_post_meta(
	'finalizar-orcamento', 'page',
	'Finalizar Orçamento de Dispositivos e Serviços | Uônix',
	'Revise os itens selecionados e envie sua solicitação de orçamento de dispositivos de ancoragem e serviços de engenharia Uônix.',
	'finalizar orçamento, orçamento uonix'
);

uonix_sync_post_meta(
	array( 'politica-de-privacidade', 'privacidade' ), 'page',
	'Política de Privacidade e Proteção de Dados | Uônix',
	'Conheça nossa política de privacidade e conformidade com a LGPD no tratamento e proteção dos seus dados na Uônix.',
	'política de privacidade, privacidade uonix'
);

uonix_sync_post_meta(
	array( 'trabalhe-conosco', 'trabalhe-na-uonix' ), 'page',
	'Trabalhe na Uônix | Oportunidades em Engenharia e Segurança',
	'Faça parte da equipe Uônix. Conheça nossas oportunidades nas áreas de engenharia, vendas técnicas e fabricação de sistemas de ancoragem.',
	'trabalhe na uônix, trabalhe conosco, vagas uonix'
);

// =========================================================================
// 2. CATEGORIAS WOOCOMMERCE
// =========================================================================
echo "\n--- 2. CATEGORIAS DE PRODUTOS WOOCOMMERCE ---\n";

uonix_sync_term_meta(
	'olhal-de-ancoragem', 'product_cat',
	'Olhal de Ancoragem Inox 304 e 316 | Dispositivos de Ancoragem Uônix',
	'Olhais e dispositivos de ancoragem tipo A1 fabricados em aço inox 304 e 316. Resistência atestada acima de 1.500 kgf conforme NR-35 e NBR 16325-1. Envio para todo o Brasil.',
	'olhal de ancoragem, dispositivos de ancoragem, ancoragem inox'
);

uonix_sync_term_meta(
	'fixacao-quimica', 'product_cat',
	'Fixação Química para Ancoragem Predial | Chumbadores e Resinas Uônix',
	'Linha de fixação química de alta performance para instalação de pontos de ancoragem em concreto. Chumbadores químicos bicomponentes, ampolas e aplicadores profissionais.',
	'fixação química, chumbador químico, resina de ancoragem'
);

uonix_sync_term_meta(
	'fixacao-mecanica', 'product_cat',
	'Fixação Mecânica para Ancoragem | Chumbadores e Barras Roscadas Uônix',
	'Chumbadores mecânicos de expansão, barras roscadas em inox 304 e porcas de alta resistência para sistemas de ancoragem predial e fixação estrutural pesada.',
	'fixação mecânica, chumbador de expansão, barra roscada inox'
);

uonix_sync_term_meta(
	'acessorios', 'product_cat',
	'Acessórios para Sistemas de Ancoragem e Linhas de Vida | Uônix',
	'Acessórios essenciais para ancoragem predial: grampos para cabo de aço, arruelas de funileiro em inox 304 e componentes de fixação com alta durabilidade.',
	'acessórios de ancoragem, grampo cabo de aço'
);

// =========================================================================
// 3. SERVIÇOS DE ENGENHARIA (CPT 'servicos')
// =========================================================================
echo "\n--- 3. SERVIÇOS TÉCNICOS DE ENGENHARIA (CPT servicos) ---\n";

$servicos_data = array(
	array(
		'aliases'     => array( 'instalacao-de-pontos-de-ancoragem', 'instalacao-pontos-ancoragem' ),
		'clean_title' => 'Instalação de Pontos de Ancoragem',
		'title'       => 'Instalação de Pontos de Ancoragem Predial NR-35 com ART | Uônix',
		'desc'        => 'Instalação profissional de pontos de ancoragem predial em concreto e estrutura metálica. Atendimento às normas NR-35, NR-18 e NBR 16325 com emissão de ART em todo o Brasil.',
		'kw'          => 'instalação de pontos de ancoragem, ancoragem predial nr-35, pontos de ancoragem',
	),
	array(
		'aliases'     => array( 'ensaios-de-arrancamento', 'ensaio-de-arrancamento' ),
		'clean_title' => 'Ensaios de Arrancamento',
		'title'       => 'Ensaio de Arrancamento Estático de Ancoragem (15 kN) | Uônix',
		'desc'        => 'Teste e ensaio de arrancamento estático com carga de 1.500 kgf (15 kN) para validação de pontos de ancoragem conforme NBR 16325-1. Laudo técnico e ART inclusos.',
		'kw'          => 'ensaios de arrancamento, ensaio de arrancamento, teste de arrancamento estático',
	),
	array(
		'aliases'     => array( 'projeto-ancoragem', 'projeto-de-ancoragem' ),
		'clean_title' => 'Projeto de Ancoragem',
		'title'       => 'Projeto de Ancoragem Predial e Linhas de Vida com ART | Uônix',
		'desc'        => 'Desenvolvimento de projeto executivo de sistemas de ancoragem predial, memorial de cálculo, dimensionamento de fixações e planta de pontos conforme NR-35 e NBR 16325.',
		'kw'          => 'projeto de ancoragem, projeto de ancoragem predial, projeto linha de vida',
	),
	array(
		'aliases'     => array( 'relatorio-tecnico-e-fotografico', 'laudo-tecnico-e-fotografico' ),
		'clean_title' => 'Relatório Técnico e Fotográfico',
		'title'       => 'Laudo Técnico e Fotográfico de Ancoragem Predial NR-35 | Uônix',
		'desc'        => 'Inspeção e laudo técnico com relatório fotográfico detalhado de conformidade dos pontos de ancoragem existentes. Emissão de parecer de engenharia e ART CREA.',
		'kw'          => 'relatório técnico e fotográfico, laudo técnico de ancoragem, laudo fotográfico nr-35',
	),
	array(
		'aliases'     => array( 'art' ),
		'clean_title' => 'ART',
		'title'       => 'Emissão de ART para Ancoragem Predial e Trabalho em Altura | Uônix',
		'desc'        => 'Emissão de Anotação de Responsabilidade Técnica (ART) por engenheiros habilitados pelo CREA para projetos, instalações e testes de ancoragem predial e linhas de vida.',
		'kw'          => 'art, art ancoragem predial, art nr-35',
	),
	array(
		'aliases'     => array( 'projeto-cadeirinha-pintura', 'projeto-de-cadeirinha-de-pintura' ),
		'clean_title' => 'Projeto de Cadeirinha de Pintura',
		'title'       => 'Projeto de Ancoragem para Cadeirinha de Pintura Fachada NR-18 | Uônix',
		'desc'        => 'Dimensionamento e projeto de pontos de ancoragem específicos para uso de cadeirinha suspensa (balancim individual) em manutenção e pintura predial conforme NR-18.',
		'kw'          => 'projeto de cadeirinha de pintura, projeto cadeirinha de pintura, nr-18 fachada',
	),
	array(
		'aliases'     => array( 'projeto-balancim', 'projeto-de-balancim' ),
		'clean_title' => 'Projeto de Balancim',
		'title'       => 'Projeto de Ancoragem para Balancim Elétrico e Manual | Uônix',
		'desc'        => 'Projeto estrutural e pontos de fixação para balancins suspensos elétricos e manuais em fachadas prediais. Cálculo de sobrecargas e emissão de ART.',
		'kw'          => 'projeto de balancim, ancoragem para balancim elétrico, balancim fachada',
	),
	array(
		'aliases'     => array( 'projeto-andaime-fachadeiro', 'projeto-de-andaime-fachadeiro' ),
		'clean_title' => 'Projeto de Andaime Fachadeiro',
		'title'       => 'Projeto de Amarração e Ancoragem de Andaime Fachadeiro | Uônix',
		'desc'        => 'Projeto de fixação e estroncamento de andaimes fachadeiros para obras e reformas prediais. Conformidade com NR-18 e cálculo estrutural com ART.',
		'kw'          => 'projeto de andaime fachadeiro, projeto andaime fachadeiro, amarração de andaime nr-18',
	),
	array(
		'aliases'     => array( 'projetos-de-instalacao', 'projetos-de-instalac-a-o' ),
		'clean_title' => 'Projetos de Instalação',
		'title'       => 'Projetos de Instalação de Sistemas de Proteção contra Quedas | Uônix',
		'desc'        => 'Engenharia completa para instalação de sistemas de ancoragem e proteção coletiva em edificações comerciais, residenciais e industriais em todo o território nacional.',
		'kw'          => 'projetos de instalação, projetos de instalação ancoragem, proteção contra quedas nr-35',
	),
);

foreach ( $servicos_data as $s_meta ) {
	uonix_sync_post_title_and_meta( $s_meta['aliases'], 'servicos', $s_meta['clean_title'], $s_meta['title'], $s_meta['desc'], $s_meta['kw'] );
}

// =========================================================================
// 4. PRODUTOS WOOCOMMERCE (Limpeza de <br> e Alinhamento de Metadados)
// =========================================================================
echo "\n--- 4. PRODUTOS WOOCOMMERCE (Limpeza de <br> e Metadados) ---\n";

$produtos_data = array(
	array(
		'aliases'     => array( 'ancoragem-uonix-modelo-210-inox' ),
		'clean_title' => 'Olhal de Ancoragem Modelo 210 Inox 304',
		'title'       => 'Olhal de Ancoragem Modelo 210 Inox 304 | Uônix',
		'desc'        => 'Olhal de ancoragem inox 304 modelo 210 para ancoragem predial em concreto ou estrutura metálica. Sistema com barra roscada, chumbador, porcas e arruelas.',
		'kw'          => 'olhal de ancoragem modelo 210 inox 304, olhal de ancoragem inox 304, ancoragem modelo 210',
	),
	array(
		'aliases'     => array( 'ancoragem-modelo-210-inox-316' ),
		'clean_title' => 'Olhal de Ancoragem Modelo 210 Inox 316',
		'title'       => 'Olhal de Ancoragem Modelo 210 Inox 316 | Uônix',
		'desc'        => 'Olhal de ancoragem inox 316 modelo 210 para ambientes agressivos e alta corrosão. Sistema com barra roscada, chumbador químico, porcas e arruelas.',
		'kw'          => 'olhal de ancoragem modelo 210 inox 316, olhal de ancoragem inox 316, ancoragem modelo 210',
	),
	array(
		'aliases'     => array( 'ancoragem-uonix-modelo-277-inox-316' ),
		'clean_title' => 'Olhal de Ancoragem Modelo 277 Inox 316',
		'title'       => 'Olhal de Ancoragem Modelo 277 Inox 316 | Uônix',
		'desc'        => 'Olhal de ancoragem inox 316 modelo 277 para ancoragem predial em ambientes agressivos. Sistema com barra roscada, chumbador, porcas e arruelas.',
		'kw'          => 'olhal de ancoragem modelo 277 inox 316, olhal de ancoragem inox 316, ancoragem modelo 277',
	),
	array(
		'aliases'     => array( 'arruela-funileiroinox-304', 'arruela-funileiro-inox-304' ),
		'clean_title' => 'Arruela Funileiro Inox 304',
		'title'       => 'Arruela Funileiro Inox 304 1/2" | Uônix',
		'desc'        => 'Arruela funileiro inox 304 de 1/2" para distribuição uniforme da força de aperto. Aba ampla e alta resistência à corrosão em ambientes úmidos.',
		'kw'          => 'arruela funileiro inox 304, arruela funileiro inox, arruela aba larga inox',
	),
	array(
		'aliases'     => array( 'porca-sextavada-inox' ),
		'clean_title' => 'Porca Sextavada Inox 304',
		'title'       => 'Porca Sextavada Inox 304 para Fixação | Uônix',
		'desc'        => 'Porca sextavada inox 304 para fixações resistentes à corrosão em ambientes agressivos. Alta resistência mecânica para uso estrutural, naval e industrial.',
		'kw'          => 'porca sextavada inox 304, porca sextavada inox, porca inox 304',
	),
	array(
		'aliases'     => array( 'porca-sextavada-zincada' ),
		'clean_title' => 'Porca Sextavada Aço Carbono',
		'title'       => 'Porca Sextavada Zincada Aço Carbono | Uônix',
		'desc'        => 'Porca sextavada zincada em aço carbono, resistente e econômica para fixações. Revestimento de zinco que retarda a corrosão em umidade moderada.',
		'kw'          => 'porca sextavada aço carbono, porca sextavada zincada, porca de aço carbono',
	),
	array(
		'aliases'     => array( 'barra-roscada-304-com-chanfro' ),
		'clean_title' => 'Barra Roscada Inox 304 com Chanfro',
		'title'       => 'Barra Roscada Inox 304 com Chanfro 45° | Uônix',
		'desc'        => 'Barra roscada inox 304 com chanfro de 45° para ancoragem química em concreto. Rosca UNC de 13 fios; o chanfro evita bolhas e preenche todo o furo.',
		'kw'          => 'barra roscada inox 304 com chanfro, barra roscada com chanfro, barra roscada inox 304',
	),
	array(
		'aliases'     => array( 'barra-roscada-inox-304' ),
		'clean_title' => 'Barra Roscada Inox 304',
		'title'       => 'Barra Roscada Inox 304 para Ancoragem | Uônix',
		'desc'        => 'Barra roscada em aço inox 304 para fixações estruturais e ancoragem predial. Alta resistência mecânica e durabilidade contra corrosão.',
		'kw'          => 'barra roscada inox 304, barra roscada inox, haste roscada inox',
	),
	array(
		'aliases'     => array( 'grampo-de-cabo-de-aco-galvanizado' ),
		'clean_title' => 'Grampo de Cabo de Aço',
		'title'       => 'Grampo para Cabo de Aço Galvanizado (Clip) | Uônix',
		'desc'        => 'Grampo para cabo de aço galvanizado (clip) para fixação, emenda e laços em cabos. Sela e arco em U que comprimem o cabo com total segurança.',
		'kw'          => 'grampo de cabo de aço, grampo para cabo de aço, clip de cabo de aço',
	),
	array(
		'aliases'     => array( 'chumbador-de-expansao-com-prisioneiro' ),
		'clean_title' => 'Chumbador de Expansão com Prisioneiro',
		'title'       => 'Chumbador de Expansão com Prisioneiro | Uônix',
		'desc'        => 'Chumbador mecânico de expansão com prisioneiro para fixações estruturais pesadas em concreto maciço. Alta carga de tração e cisalhamento.',
		'kw'          => 'chumbador de expansão com prisioneiro, chumbador de expansão, chumbador mecânico',
	),
	array(
		'aliases'     => array( 'chumbador-quimico-aqi380-pro' ),
		'clean_title' => 'Chumbador Químico AQI380 PRO',
		'title'       => 'Chumbador Químico AQI380 PRO Âncora | Uônix',
		'desc'        => 'Chumbador químico bicomponente por injeção AQI380 PRO em resina viniléster. Alto desempenho para ancoragens estruturais pesadas em concreto.',
		'kw'          => 'chumbador químico aqi380 pro, chumbador químico, resina química ancoragem',
	),
	array(
		'aliases'     => array( 'chumbador-quimico-walsywa' ),
		'clean_title' => 'Chumbador Químico WQI 44',
		'title'       => 'Chumbador Químico WQI 44 Plus Walsywa | Uônix',
		'desc'        => 'Chumbador químico por injeção WQI 44 Plus para fixações estruturais em concreto e alvenaria. Cura rápida e excelente aderência química.',
		'kw'          => 'chumbador químico wqi 44, chumbador químico walsywa, chumbador químico',
	),
	array(
		'aliases'     => array( 'aplicador-apl-380-ancora' ),
		'clean_title' => 'Aplicador APL 380',
		'title'       => 'Aplicador APL 380 para Chumbador Químico | Uônix',
		'desc'        => 'Aplicador APL 380 para chumbador químico com gatilho suave e preciso. Design ergonômico e resistente para aplicação contínua, uniforme e profissional.',
		'kw'          => 'aplicador apl 380, aplicador de chumbador químico, pistola para chumbador químico',
	),
	array(
		'aliases'     => array( 'aplicador-de-chumbador-quimico-300ml' ),
		'clean_title' => 'Aplicador de Chumbador Químico 300ml',
		'title'       => 'Aplicador de Chumbador Químico 300ml | Uônix',
		'desc'        => 'Aplicador manual profissional para cartuchos de chumbador químico de 300ml. Estrutura reforçada para aplicação suave e precisa.',
		'kw'          => 'aplicador de chumbador químico 300ml, aplicador de chumbador químico, pistola 300ml',
	),
	array(
		'aliases'     => array( 'aplicador-de-chumbador-quimico-345ml' ),
		'clean_title' => 'Aplicador de Chumbador Químico 345ml',
		'title'       => 'Aplicador de Chumbador Químico 345ml | Uônix',
		'desc'        => 'Aplicador manual coaxial reforçado para cartuchos de resina química de 345ml e 380ml. Máximo rendimento e esforço reduzido.',
		'kw'          => 'aplicador de chumbador químico 345ml, aplicador de chumbador químico, pistola coaxial',
	),
	array(
		'aliases'     => array( 'adesivo-anaerobico-120' ),
		'clean_title' => 'Adesivo Anaeróbico 120',
		'title'       => 'Adesivo Anaeróbico 120 Trava Rosca | Uônix',
		'desc'        => 'Adesivo químico anaeróbico de alto torque para travamento e vedação de roscas e fixações metálicas. Evita afrouxamento por vibração.',
		'kw'          => 'adesivo anaeróbico 120, adesivo anaeróbico, trava rosca alto torque',
	),
	array(
		'aliases'     => array( 'ampola-quimica-ancora' ),
		'clean_title' => 'Ampola Química AQA',
		'title'       => 'Ampola Química AQA Âncora para Concreto | Uônix',
		'desc'        => 'Ampola química de vidro com dosagem precisa de resina viniléster e catalisador para ancoragem de barras roscadas em concreto maciço.',
		'kw'          => 'ampola química aqa, ampola química, ancoragem química por cápsula',
	),
	array(
		'aliases'     => array( 'ampola-quimica-walsywa' ),
		'clean_title' => 'Ampola Química WQA',
		'title'       => 'Ampola Química WQA Walsywa para Ancoragem | Uônix',
		'desc'        => 'Ampola de ancoragem química pré-dosada para fixações estruturais rápidas e de alta capacidade de carga em bases de concreto.',
		'kw'          => 'ampola química wqa, ampola química walsywa, ampola de ancoragem',
	),
);

foreach ( $produtos_data as $p_meta ) {
	uonix_sync_post_title_and_meta( $p_meta['aliases'], 'product', $p_meta['clean_title'], $p_meta['title'], $p_meta['desc'], $p_meta['kw'] );
}

// =========================================================================
// 5. POSTS DE BLOG (Alinhamento de Palavras-Chave de Foco)
// =========================================================================
echo "\n--- 5. POSTS DE BLOG (Alinhamento de Palavras-Chave de Foco) ---\n";

$posts_data = array(
	array(
		'aliases'     => array( 'fator-de-queda-o-risco-comeca-no-projeto-nao-na-queda' ),
		'clean_title' => 'Fator de queda: o risco começa no projeto, não na queda',
		'title'       => 'Fator de Queda: O Risco Começa no Projeto, Não na Queda | Blog Uônix',
		'desc'        => 'Entenda o conceito de fator de queda no trabalho em altura, por que ele deve ser previsto no projeto de ancoragem e como mitigar riscos conforme a NR-35.',
		'kw'          => 'fator de queda, fator de queda no trabalho em altura, projeto de ancoragem nr-35',
	),
	array(
		'aliases'     => array( 'aco-inox-304-vs-316-em-ancoragem-predial-qual-escolher' ),
		'clean_title' => 'Aço Inox 304 vs. 316 em ancoragem predial: Qual escolher?',
		'title'       => 'Aço Inox 304 vs. 316 em Ancoragem Predial: Qual Escolher? | Blog Uônix',
		'desc'        => 'Comparativo técnico entre aço inox 304 e 316 para dispositivos de ancoragem predial. Conheça as diferenças de resistência à corrosão, custo e aplicações.',
		'kw'          => 'aço inox 304 vs. 316, aço inox 304 vs 316, ancoragem inox 304 ou 316',
	),
	array(
		'aliases'     => array( 'trabalho-em-altura-conceito-riscos-e-o-papel-do-sistema-de-ancoragem-2', 'trabalho-em-altura-conceito-riscos-e-o-papel-do-sistema-de-ancoragem' ),
		'clean_title' => 'Trabalho em altura: conceito, riscos e o papel do sistema de ancoragem',
		'title'       => 'Trabalho em Altura: Conceito, Riscos e o Papel da Ancoragem | Blog Uônix',
		'desc'        => 'O que é trabalho em altura conforme a NR-35, principais riscos operacionais e a importância de um sistema de ancoragem predial certificado e inspecionado.',
		'kw'          => 'trabalho em altura, riscos no trabalho em altura, sistema de ancoragem nr-35',
	),
	array(
		'aliases'     => array( 'ensaio-de-arrancamento-o-que-e-como-funciona-e-por-que-e-exigido-por-norma' ),
		'clean_title' => 'Ensaio de arrancamento: o que é, como funciona e por que é exigido por norma',
		'title'       => 'Ensaio de Arrancamento: O que É, Como Funciona e Exigências | Blog Uônix',
		'desc'        => 'Saiba tudo sobre o ensaio de arrancamento estático para pontos de ancoragem: metodologia de teste de 1.500 kgf, normas NBR 16325 e laudo técnico com ART.',
		'kw'          => 'ensaio de arrancamento, ensaio de arrancamento estático, teste de arrancamento ancoragem',
	),
	array(
		'aliases'     => array( 'art-em-ancoragem-predial-o-que-e-por-que-e-obrigatoria-e-como-obte-la' ),
		'clean_title' => 'ART em ancoragem predial: o que é, por que é obrigatória e como obtê-la',
		'title'       => 'ART em Ancoragem Predial: O que É e por que É Obrigatória | Blog Uônix',
		'desc'        => 'Entenda a importância da Anotação de Responsabilidade Técnica (ART) do CREA em projetos, instalações e inspeções de ancoragem predial e trabalho em altura.',
		'kw'          => 'art em ancoragem predial, art ancoragem predial, art crea nr-35',
	),
	array(
		'aliases'     => array( 'zona-livre-de-queda-zlq-como-calcular-corretamente' ),
		'clean_title' => 'Zona Livre de Queda (ZLQ): Como Calcular Corretamente',
		'title'       => 'Zona Livre de Queda (ZLQ): Como Calcular Corretamente | Blog Uônix',
		'desc'        => 'Aprenda a calcular a Zona Livre de Queda (ZLQ) em sistemas de proteção contra quedas com memorial prático, variáveis do talabarte e normas NR-35.',
		'kw'          => 'zona livre de queda, como calcular zona livre de queda, zlq nr-35',
	),
	array(
		'aliases'     => array( 'ensaio-estatico-e-ensaio-dinamico-em-ancoragem-qual-a-diferenca-e-quando-cada-um-e-exigido' ),
		'clean_title' => 'Ensaio Estático e Ensaio Dinâmico em Ancoragem: Qual a Diferença e Quando Cada um é Exigido?',
		'title'       => 'Ensaio Estático e Ensaio Dinâmico em Ancoragem: Diferenças | Blog Uônix',
		'desc'        => 'Descubra a diferença prática e normativa entre o ensaio estático e o ensaio dinâmico em dispositivos de ancoragem conforme a NBR 16325-1.',
		'kw'          => 'ensaio estático e ensaio dinâmico, ensaio estático e dinâmico, testes de ancoragem',
	),
	array(
		'aliases'     => array( 'ancoragem-predial-e-legislacao-o-que-nr-18-nr-35-e-nbr-16325-1-exigem-na-pratica' ),
		'clean_title' => 'Ancoragem predial e legislação: O que NR-18, NR-35 e NBR 16325-1 exigem na prática',
		'title'       => 'Ancoragem Predial e Legislação: NR-18, NR-35 e NBR 16325 | Blog Uônix',
		'desc'        => 'Panorama completo sobre a legislação brasileira de ancoragem predial: requisitos da NR-18, diretrizes da NR-35 e padrões técnicos da NBR 16325-1.',
		'kw'          => 'ancoragem predial e legislação, legislação de ancoragem predial, normas ancoragem predial',
	),
	array(
		'aliases'     => array( 'inspecao-de-pontos-de-ancoragem-prazos-responsabilidades-e-exigencias-normativas' ),
		'clean_title' => 'Inspeção de pontos de ancoragem: Prazos, responsabilidades e exigências normativas',
		'title'       => 'Inspeção de Pontos de Ancoragem: Prazos e Responsabilidades | Blog Uônix',
		'desc'        => 'Periodicidade de inspeção de pontos de ancoragem predial, responsabilidade legal do síndico e documentação obrigatória conforme a NR-35.',
		'kw'          => 'inspeção de pontos de ancoragem, inspeção anual de ancoragem, laudo de ancoragem predial',
	),
	array(
		'aliases'     => array( 'zona-livre-de-queda-zlq-o-que-e-e-por-que-ela-salva-vidas' ),
		'clean_title' => 'Zona Livre de Queda (ZLQ): O que é e por que ela salva vidas',
		'title'       => 'Zona Livre de Queda (ZLQ): O que É e por que Salva Vidas | Blog Uônix',
		'desc'        => 'Entenda o conceito de Zona Livre de Queda (ZLQ), a física da retenção de queda em altura e como o dimensionamento correto evita acidentes fatais.',
		'kw'          => 'zona livre de queda, o que é zona livre de queda, zlq retenção de queda',
	),
	array(
		'aliases'     => array( 'toda-edificacao-e-obrigada-a-ter-olhal-de-ancoragem' ),
		'clean_title' => 'Toda edificação é obrigada a ter olhal de ancoragem?',
		'title'       => 'Toda Edificação É Obrigada a Ter Olhal de Ancoragem? | Blog Uônix',
		'desc'        => 'Entenda quando a instalação de olhais e dispositivos de ancoragem predial é obrigatória por lei segundo a NR-18, NR-35 e o Código Civil.',
		'kw'          => 'toda edificação é obrigada a ter olhal de ancoragem, olhal de ancoragem obrigatório, obrigatoriedade ancoragem predial',
	),
);

foreach ( $posts_data as $post_meta ) {
	uonix_sync_post_title_and_meta( $post_meta['aliases'], 'post', $post_meta['clean_title'], $post_meta['title'], $post_meta['desc'], $post_meta['kw'] );
}

// =========================================================================
// 4. SINCRONIZAÇÃO SEGURA DO BLOCO FAQ (wp_block slug 'faq')
//    Transforma o post_content REAL do ambiente-alvo. Fail-closed.
// =========================================================================
echo "\n--- 4. BLOCO PADRÃO FAQ (wp_block 'faq') ---\n";

$faq_posts = get_posts( array(
	'post_type'      => 'wp_block',
	'name'           => 'faq',
	'post_status'    => 'any',
	'posts_per_page' => 1,
) );

if ( empty( $faq_posts ) ) {
	echo "⚠️  [AVISO] Bloco wp_block 'faq' não localizado por slug — FAQ não sincronizado (sem fallback por ID).\n";
	$GLOBALS['uonix_skipped']++;
} else {
	$faq_post = $faq_posts[0];
	$faq_id   = $faq_post->ID;
	$orig     = (string) $faq_post->post_content;
	$c        = $orig;

	// (a) Correção textual "olhar" -> "olhal" (todas as variações de espaçamento antes de '?').
	$c = str_replace(
		'Como é feita a instalação do olhar de ancoragem da Uônix ?',
		'Como é feita a instalação do olhal de ancoragem da Uônix?',
		$c
	);
	$c = str_replace(
		'Como é feita a instalação do olhar de ancoragem da Uônix?',
		'Como é feita a instalação do olhal de ancoragem da Uônix?',
		$c
	);
	// fallback genérico: qualquer "olhar de ancoragem" remanescente vira "olhal de ancoragem".
	$c = str_replace( 'olhar de ancoragem', 'olhal de ancoragem', $c );

	// (b) Neutraliza QUALQUER URL de ambiente local para caminho relativo (não só a específica).
	$c = str_replace( 'http://localhost:8080', '', $c );
	$c = str_replace( 'https://localhost:8080', '', $c );
	$c = str_replace( 'http://127.0.0.1:8080', '', $c );

	// (c) Insere perguntas ausentes ANCORANDO no fechamento do accordion (âncora única e estável).
	$anchor = '<!-- /wp:kadence/accordion -->';
	$new_questions = array(
		array(
			'marker' => 'A Uônix envia dispositivos de ancoragem para todo o Brasil?',
			'q'      => 'A Uônix envia dispositivos de ancoragem para todo o Brasil?',
			'a'      => 'Sim, realizamos entregas para todo o território nacional, com embalagem reforçada e documentação técnica de acompanhamento.',
		),
		array(
			'marker' => 'a Uônix realiza o projeto e a instalação',
			'q'      => 'Além de fabricar os produtos, a Uônix realiza o projeto e a instalação?',
			'a'      => 'Sim, dispomos de corpo de engenharia próprio para elaboração de projetos executivos, instalação in loco, ensaios de arrancamento estático e emissão de ART registrada no CREA.',
		),
	);

	$anchor_pos = strpos( $c, $anchor );
	if ( false === $anchor_pos ) {
		echo "⚠️  [AVISO] Âncora de fechamento do accordion não encontrada — perguntas novas NÃO inseridas (metadados/correções textuais preservados).\n";
	} else {
		// Descobre o próximo id/uniqueID de pane a partir do markup real (não inventa base).
		// uniqueID base: extrai o prefixo antes do sufixo '-NN' em kt-pane<base>-<n> ou uniqueID "<base>-<n>".
		$uid_base = 'uonix_faq';
		if ( preg_match( '/"uniqueID":"([0-9a-zA-Z]+_[0-9a-zA-Z]+)-[0-9]+"/', $c, $m ) ) {
			$uid_base = $m[1];
		}
		// maior id de pane atual
		$max_id = 0;
		if ( preg_match_all( '/<!--\s*wp:kadence\/pane\s*\{[^}]*"id":([0-9]+)/', $c, $mm ) ) {
			foreach ( $mm[1] as $idv ) {
				$idv = (int) $idv;
				if ( $idv > $max_id ) { $max_id = $idv; }
			}
		}

		$panes_to_add = '';
		$added        = 0;
		foreach ( $new_questions as $nq ) {
			if ( false !== strpos( $c, $nq['marker'] ) ) {
				continue; // já existe -> idempotente
			}
			$max_id++;
			$uid   = $uid_base . '-' . $max_id;
			$class = 'kt-accordion-pane-' . $max_id . ' kt-pane' . $uid;
			$q     = htmlspecialchars( $nq['q'], ENT_QUOTES, 'UTF-8' );
			$a     = htmlspecialchars( $nq['a'], ENT_QUOTES, 'UTF-8' );
			$panes_to_add .=
				"\n<!-- wp:kadence/pane {\"id\":{$max_id},\"uniqueID\":\"{$uid}\"} -->\n"
				. "<div class=\"wp-block-kadence-pane kt-accordion-pane {$class}\"><div class=\"kt-accordion-header-wrap\"><button class=\"kt-blocks-accordion-header kt-acccordion-button-label-show\" type=\"button\"><span class=\"kt-blocks-accordion-title-wrap\"><span class=\"kt-blocks-accordion-title\"><strong>{$q}</strong></span></span><span class=\"kt-blocks-accordion-icon-trigger\"></span></button></div><div class=\"kt-accordion-panel\"><div class=\"kt-accordion-panel-inner\"><!-- wp:paragraph -->\n"
				. "<p>{$a}</p>\n"
				. "<!-- /wp:paragraph --></div></div></div>\n"
				. "<!-- /wp:kadence/pane -->\n";
			$added++;
		}

		if ( $added > 0 ) {
			// A âncora é precedida pelos </div> de fechamento das panes; inserimos ANTES desses fechamentos.
			// Estratégia robusta: inserir imediatamente antes da sequência "</div></div></div>\n<!-- /wp:kadence/accordion -->"
			// quando presente; caso contrário, imediatamente antes da âncora.
			$closing = "</div></div></div>\n" . $anchor;
			if ( false !== strpos( $c, $closing ) ) {
				$c = str_replace( $closing, $panes_to_add . $closing, $c );
			} else {
				$c = substr( $c, 0, $anchor_pos ) . $panes_to_add . substr( $c, $anchor_pos );
			}
		}
	}

	// (d) RECALCULA o paneCount a partir do número REAL de panes (corrige a dessincronização de origem).
	$real_panes = preg_match_all( '/<!--\s*wp:kadence\/pane\b/', $c, $tmp );
	$real_panes = (int) $real_panes;
	$pane_anomaly = '';
	if ( $real_panes > 0 ) {
		$c = preg_replace( '/("paneCount":)[0-9]+/', '${1}' . $real_panes, $c, 1 );
	} elseif ( false !== strpos( $c, '"paneCount"' ) ) {
		// Anomalia: o bloco declara paneCount mas nenhuma pane foi detectada
		// (regex/markup inesperado). Marca para abortar via guarda fail-closed abaixo.
		$pane_anomaly = "anomalia: 'paneCount' presente mas 0 panes detectadas (regex/markup inesperado)";
	}

	// (e) GUARDAS FAIL-CLOSED antes de qualquer gravação.
	$abort_reason = '';
	if ( '' !== $pane_anomaly ) {
		$abort_reason = $pane_anomaly;
	} elseif ( false !== strpos( $c, 'localhost' ) ) {
		$abort_reason = "conteúdo final ainda contém 'localhost'";
	} elseif ( false !== stripos( $c, '<h1' ) ) {
		$abort_reason = "conteúdo final contém '<h1' (não permitido em bloco reutilizável)";
	}

	$will_change = ( $c !== $orig );

	if ( '' !== $abort_reason ) {
		echo "⛔ [ABORTA FAQ] {$abort_reason} — bloco NÃO gravado (fail-closed).\n";
		$GLOBALS['uonix_skipped']++;
	} elseif ( ! $will_change ) {
		echo "   [sem mudança] Bloco FAQ (ID {$faq_id}) já está sincronizado (paneCount={$real_panes}).\n";
		$GLOBALS['uonix_noop']++;
	} else {
		if ( ! uonix_backup_target( 'post', $faq_id ) ) {
			echo "⛔ [ABORTA FAQ] Falha ao salvar backup do bloco FAQ (ID {$faq_id}) — nada gravado.\n";
			$GLOBALS['uonix_skipped']++;
		} else {
			if ( $GLOBALS['uonix_apply'] ) {
				$res = wp_update_post( array(
					'ID'           => $faq_id,
					'post_content' => $c,
				), true );
				if ( is_wp_error( $res ) ) {
					echo "⛔ [ERRO FAQ] wp_update_post falhou: " . $res->get_error_message() . "\n";
					$GLOBALS['uonix_skipped']++;
				} else {
					echo "✅ [ATUALIZADO] Bloco FAQ (ID {$faq_id}) sincronizado (paneCount={$real_panes}).\n";
					$GLOBALS['uonix_changes']++;
				}
			} else {
				echo "   [MUDARIA] Bloco FAQ (ID {$faq_id}) seria sincronizado (paneCount recalculado={$real_panes}).\n";
				$GLOBALS['uonix_changes']++;
			}
		}
	}
}

// =========================================================================
// 5. FLUSH DE CACHE (apenas no modo apply)
// =========================================================================
echo "\n--- 5. LIMPEZA DE CACHE ---\n";
if ( $GLOBALS['uonix_apply'] && function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
	echo "✅ [OK] wp_cache_flush() executado.\n";
} else {
	echo "   (dry-run: cache não foi limpo)\n";
}

echo "\n========================================================================\n";
echo ( $GLOBALS['uonix_apply'] ? "🎉 APLICAÇÃO CONCLUÍDA!\n" : "🔎 DRY-RUN CONCLUÍDO (nada foi gravado).\n" );
echo "   CHANGES={$GLOBALS['uonix_changes']}  (gravações " . ( $GLOBALS['uonix_apply'] ? 'efetuadas' : 'que ocorreriam' ) . ")\n";
echo "   NOOP={$GLOBALS['uonix_noop']}  (já no valor desejado)\n";
echo "   SKIPPED={$GLOBALS['uonix_skipped']}  (não encontrados / abortados)\n";
if ( $GLOBALS['uonix_apply'] && $GLOBALS['uonix_backup_dir'] ) {
	echo "   BACKUP_DIR={$GLOBALS['uonix_backup_dir']}\n";
}
echo "========================================================================\n";
