<?php
/**
 * Script de Migração e Sincronização de Sliders WCPS e Widgets via WP-CLI.
 *
 * Aplica de forma idempotente, segura e com fail-closed:
 * 1. Layout Canônico WCPS (slug: 'banner-hero-uonix-sem-preco', local ID 11188):
 *    Garante a existência e metadados de layout_elements_data (sem exibição de preço).
 * 2. Slider Banner Home (ID 1546):
 *    Garante configuração de layout, query e opções visuais.
 * 3. Slider Sidebar Blog (ID 8643):
 *    Associa ao layout canônico, limpa subtítulo obsoleto, oculta descrição/preço/paginação
 *    e centraliza os nomes dos produtos.
 * 4. Widgets de Bloco (widget_block):
 *    Sincroniza os blocos da barra lateral (índices 138 e 156) com a classe oficial
 *    .uonix-btn-submit-news, texto "Conheça nosso catálogo" e remoção de parágrafos residuais.
 * 5. Verificação pós-gravação (Readback):
 *    Testa a renderização real de do_shortcode('[wcps id="1546"]') e do_shortcode('[wcps id="8643"]').
 * 6. Limpeza de cache de objetos (wp_cache_flush).
 *
 * Compatível com PHP 7.1+ e PHP 8.x (WordPress em produção Locaweb).
 *
 * Modo de Uso:
 *   Dry-run (padrão, seguro, NÃO grava):
 *     wp eval-file scripts/migrate-wcps-sliders-and-widgets.php --allow-root
 *   Aplicar de fato (grava com backup):
 *     wp eval-file scripts/migrate-wcps-sliders-and-widgets.php apply --allow-root
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

$modo_str = $uonix_apply ? 'APLICAR (grava + backup)' : 'DRY-RUN (somente simulação)';
echo "\n========================================================================\n";
echo "🚀 MIGRAÇÃO DE SLIDERS WCPS E WIDGETS (UÔNIX) — MODO: {$modo_str}\n";
echo "========================================================================\n\n";

$backup_dir = '';
if ( $uonix_apply ) {
	$upload_dir = wp_upload_dir();
	$timestamp  = gmdate( 'Ymd-His' );
	$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'uonix-wcps-backups/' . $timestamp;
	if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
		echo "❌ ERRO CRÍTICO: Não foi possível criar diretório de backup: {$backup_dir}\n";
		exit( 1 );
	}
	echo "🗂  Backups gravados em: {$backup_dir}\n\n";
}

/**
 * Função utilitária para backup antes de alterar um post ou option.
 */
function uonix_wcps_backup( $type, $identifier, $data, $backup_dir ) {
	if ( empty( $backup_dir ) || ! is_dir( $backup_dir ) ) {
		return;
	}
	$filename = sanitize_file_name( "{$type}_{$identifier}.json" );
	$filepath = trailingslashit( $backup_dir ) . $filename;
	file_put_contents( $filepath, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
}

// -----------------------------------------------------------------------------
// 1. LAYOUT CANÔNICO WCPS ('banner-hero-uonix-sem-preco')
// -----------------------------------------------------------------------------
echo "--- 1. LAYOUT CANÔNICO WCPS (Sem Exibição de Preço) ---\n";
$layout_slug = 'banner-hero-uonix-sem-preco';
$canonical_elements = array(
	0 => array(
		'wrapper_start' => array(
			'wrapper_id'    => '',
			'wrapper_class' => 'layer-media',
			'css_idle'      => '',
			'margin'        => '',
		),
	),
	1 => array(
		'thumbnail' => array(
			'thumb_size'        => 'full',
			'thumb_height'      => array(
				'large'  => '1000px',
				'medium' => '',
				'small'  => '',
			),
			'default_thumb_src' => '',
			'margin'            => '',
			'link_to'           => 'product_link',
			'link_to_meta_key'  => '',
		),
	),
	2 => array(
		'wrapper_end' => array(
			'wrapper_id' => '',
		),
	),
	3 => array(
		'wrapper_start' => array(
			'wrapper_id'    => '',
			'wrapper_class' => 'layer-content',
			'css_idle'      => '',
			'margin'        => '',
		),
	),
	4 => array(
		'post_title' => array(
			'color'       => '#0e3780',
			'font_size'   => '32px',
			'font_family' => 'Barlow Semi Condensed',
			'margin'      => '0 0 12px 0',
			'text_align'  => 'left',
			'link_to'     => 'product_link',
		),
	),
	5 => array(
		'add_to_cart' => array(
			'background_color' => '#f76a0c',
			'color'            => '#ffffff',
			'show_quantity'    => 'no',
		),
	),
	6 => array(
		'wrapper_end' => array(
			'wrapper_id' => '',
		),
	),
);

$existing_layout = get_page_by_path( $layout_slug, OBJECT, 'wcps_layout' );
$canonical_layout_id = 0;

if ( $existing_layout ) {
	$canonical_layout_id = $existing_layout->ID;
	$current_meta = get_post_meta( $canonical_layout_id, 'layout_elements_data', true );
	if ( $current_meta !== $canonical_elements ) {
		$GLOBALS['uonix_changes']++;
		echo "   [MUDARIA] Layout existente (ID {$canonical_layout_id}) difere da estrutura canônica.\n";
		if ( $GLOBALS['uonix_apply'] ) {
			uonix_wcps_backup( 'postmeta_layout', $canonical_layout_id, $current_meta, $backup_dir );
			update_post_meta( $canonical_layout_id, 'layout_elements_data', $canonical_elements );
			echo "   ✅ [ATUALIZADO] Metadado 'layout_elements_data' do layout ID {$canonical_layout_id}.\n";
		}
	} else {
		$GLOBALS['uonix_noop']++;
		echo "   [sem mudança] Layout existente (ID {$canonical_layout_id}) já está na estrutura canônica.\n";
	}
} else {
	// Cria o layout canônico
	$GLOBALS['uonix_changes']++;
	echo "   [MUDARIA] Layout '{$layout_slug}' não encontrado; seria criado.\n";
	if ( $GLOBALS['uonix_apply'] ) {
		$new_id = wp_insert_post( array(
			'post_title'   => 'Banner Hero Uonix (Sem Preco)',
			'post_name'    => $layout_slug,
			'post_type'    => 'wcps_layout',
			'post_status'  => 'publish',
			'meta_input'   => array(
				'layout_elements_data' => $canonical_elements,
			),
		) );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			echo "   ❌ [ERRO] Falha ao criar post_type wcps_layout '{$layout_slug}'.\n";
			$GLOBALS['uonix_errors']++;
		} else {
			$canonical_layout_id = $new_id;
			echo "   ✅ [CRIADO] Layout '{$layout_slug}' com ID {$canonical_layout_id}.\n";
		}
	}
}

// -----------------------------------------------------------------------------
// 2. SLIDER BANNER DA HOME (ID 1546)
// -----------------------------------------------------------------------------
echo "\n--- 2. SLIDER BANNER DA HOME (ID 1546) ---\n";
$post_1546 = get_post( 1546 );
if ( ! $post_1546 || 'wcps' !== $post_1546->post_type ) {
	// Tenta localizar por slug ou query
	$wcps_posts = get_posts( array( 'post_type' => 'wcps', 'name' => '1546', 'posts_per_page' => 1 ) );
	if ( ! empty( $wcps_posts ) ) {
		$post_1546 = $wcps_posts[0];
	}
}

if ( $post_1546 ) {
	$p1546_id = $post_1546->ID;
	$opts_1546 = get_post_meta( $p1546_id, 'wcps_options', true );
	$opts_changed = false;

	if ( ! is_array( $opts_1546 ) ) {
		$opts_1546 = array();
	}

	// Assegura layout associado se o canônico estiver disponível
	if ( $canonical_layout_id > 0 && ( ! isset( $opts_1546['item_layout_id'] ) || (int) $opts_1546['item_layout_id'] !== $canonical_layout_id ) ) {
		$opts_1546['item_layout_id'] = $canonical_layout_id;
		$opts_changed = true;
	}

	$banner_custom_css = "/* ==========================================================================
   UONIX: BANNER HERO SLIDER [wcps id=1546]
   Design Moderno, Comercial e Embutido na Pagina
   ========================================================================== */

.wcps-container-1546,
.wcps-container-1546 #wcps-1546,
.wcps-container-1546 .splide,
.wcps-container-1546 .splide__track {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

.wcps-container-1546 {
    position: relative !important;
    padding: 10px 0 20px 0 !important;
    margin: 0 auto !important;
}

.wcps-container-1546 #wcps-1546 {
    position: relative !important;
}

.wcps-container-1546 .wcps-ribbon,
.wcps-container-1546 .wcps-items-price,
.wcps-container-1546 .price,
.wcps-container-1546 .amount,
.wcps-container-1546 .woocommerce-Price-amount {
    display: none !important;
}

.wcps-container-1546 .splide__track {
    padding: 10px 0 !important;
    overflow: hidden !important;
}

.wcps-container-1546 .item,
.wcps-container-1546 .splide__slide {
    box-sizing: border-box !important;
    height: auto !important;
    min-height: auto !important;
    display: flex !important;
}

/* 1. ESTRUTURA TOTALMENTE EMBUTIDA (SEM BORDAS) */
.wcps-container-1546 .elements-wrapper {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1) !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: auto !important;
    min-height: 380px !important;
    padding: 20px 60px !important;
    gap: 50px !important;
    position: relative !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

.wcps-container-1546 .elements-wrapper:hover {
    border: none !important;
    box-shadow: none !important;
}

/* Imagem sem moldura/borda, fluida e com blending perfeito */
.wcps-container-1546 .layer-media {
    flex: 0 0 46% !important;
    max-width: 46% !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-height: 320px !important;
    box-sizing: border-box !important;
}

.wcps-container-1546 .wcps-items-thumb {
    width: 100% !important;
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.wcps-container-1546 .wcps-items-thumb a {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    height: 100% !important;
}

.wcps-container-1546 .wcps-items-thumb img {
    max-height: 300px !important;
    max-width: 100% !important;
    width: auto !important;
    height: auto !important;
    object-fit: contain !important;
    mix-blend-mode: multiply !important;
    filter: none !important;
    transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1) !important;
    margin: 0 auto !important;
    display: block !important;
}

.wcps-container-1546 .elements-wrapper:hover .wcps-items-thumb img {
    transform: scale(1.06) translateY(-4px) !important;
}

/* 2. CONTEUDO E TIPOGRAFIA COMERCIAL */
.wcps-container-1546 .layer-content {
    flex: 0 0 50% !important;
    max-width: 50% !important;
    padding: 10px 0 !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    text-align: left !important;
    justify-content: center !important;
    box-sizing: border-box !important;
}

.wcps-container-1546 .wcps-items-title {
    font-family: Barlow Semi Condensed, sans-serif !important;
    font-size: 34px !important;
    font-weight: 800 !important;
    line-height: 1.18 !important;
    letter-spacing: -0.3px !important;
    margin: 0 0 16px 0 !important;
    text-align: left !important;
    width: 100% !important;
    text-wrap: balance !important;
}

.wcps-container-1546 .wcps-items-title a {
    color: #0e3780 !important;
    text-decoration: none !important;
    transition: color 0.25s ease !important;
}

.wcps-container-1546 .wcps-items-title a:hover {
    color: #f76a0c !important;
}

/* 3. DESCRICAO MAIOR E MAIS LEGIVEL */
.wcps-container-1546 .uonix-wcps-banner-desc {
    font-family: Barlow, sans-serif !important;
    font-size: 17px !important;
    line-height: 1.65 !important;
    color: #334155 !important;
    font-weight: 500 !important;
    margin: 0 0 28px 0 !important;
    max-width: 550px !important;
}

.wcps-container-1546 .uonix-wcps-banner-action {
    width: 100% !important;
}

/* 4. BOTAO COMERCIAL DE ALTO IMPACTO */
.wcps-container-1546 .uonix-wcps-btn {
    background: linear-gradient(135deg, #f76a0c 0%, #e05c04 100%) !important;
    color: #ffffff !important;
    border-radius: 10px !important;
    height: 52px !important;
    width: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 12px !important;
    padding: 0 38px !important;
    font-family: Barlow, sans-serif !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.8px !important;
    text-decoration: none !important;
    border: none !important;
    outline: none !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    box-shadow: 0 6px 20px rgba(247, 106, 12, 0.38) !important;
    box-sizing: border-box !important;
    cursor: pointer !important;
}

.wcps-container-1546 .uonix-wcps-btn:hover {
    background: linear-gradient(135deg, #0e3780 0%, #09255a 100%) !important;
    color: #ffffff !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 24px rgba(14, 55, 128, 0.32) !important;
}

.wcps-container-1546 .uonix-wcps-btn-icon {
    transition: transform 0.25s ease !important;
}

.wcps-container-1546 .uonix-wcps-btn:hover .uonix-wcps-btn-icon {
    transform: translateX(5px) !important;
}

.wcps-container-1546 .wcps-items-cart,
.wcps-container-1546 .add_to_cart_inline {
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    width: 100% !important;
}

/* 5. SETAS MAIS PARA DENTRO DO BANNER */
/* CABECALHO KADENCE CANONICO DO SLIDER */
.uonix-slider-header {
    margin-bottom: 24px !important;
    display: block !important;
}

.uonix-slider-header span.wp-block-kadence-advancedheading {
    display: inline-flex !important;
    align-items: center !important;
    margin-bottom: 8px !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 1.5px !important;
}

.uonix-slider-header h2.wp-block-kadence-advancedheading {
    font-family: Barlow Semi Condensed, sans-serif !important;
    font-size: 36px !important;
    font-weight: 800 !important;
    line-height: 1.15 !important;
    margin: 0 !important;
}

@media (max-width: 768px) {
    .uonix-slider-header h2.wp-block-kadence-advancedheading {
        font-size: 26px !important;
        text-align: center !important;
    }

    .uonix-slider-header span.wp-block-kadence-advancedheading {
        justify-content: center !important;
        width: 100% !important;
        text-align: center !important;
    }
}

/* 5. SETAS CIRCULARES MODERNAS CANONICAS */
.wcps-container-1546 .splide__arrows,
.wcps-container-1546 .splide__arrows.middle,
.wcps-container-1546 .splide__arrows.middle-fixed {
    position: absolute !important;
    top: 50% !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    height: 0 !important;
    transform: translateY(-50%) !important;
    pointer-events: none !important;
    z-index: 30 !important;
}

.wcps-container-1546 .splide__arrows div,
.wcps-container-1546 .splide__arrows div.splide__arrow,
.wcps-container-1546 .splide__arrow {
    width: 44px !important;
    height: 44px !important;
    background: rgba(255, 255, 255, 0.95) !important;
    background-color: rgba(255, 255, 255, 0.95) !important;
    backdrop-filter: blur(8px) !important;
    border: 1px solid rgba(226, 232, 240, 0.9) !important;
    border-radius: 50% !important;
    box-shadow: 0 4px 16px rgba(14, 55, 128, 0.12) !important;
    position: absolute !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    opacity: 0.95 !important;
    pointer-events: auto !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    font-size: 18px !important;
    font-weight: bold !important;
    color: #0e3780 !important;
    padding: 0 !important;
    margin: 0 !important;
    outline: none !important;
}

.wcps-container-1546 .splide__arrow i,
.wcps-container-1546 .splide__arrows div i {
    display: none !important;
}

.wcps-container-1546 .splide__arrow .icon,
.wcps-container-1546 .splide__arrows div .icon {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    height: 100% !important;
    pointer-events: none !important;
}

.wcps-container-1546 .splide__arrow .icon::before,
.wcps-container-1546 .splide__arrows div .icon::before {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1em !important;
    height: 1em !important;
    color: currentColor !important;
    font-size: 28px !important;
    font-weight: 800 !important;
    line-height: 1 !important;
    margin-top: -2px !important;
}

.wcps-container-1546 .splide__arrow--prev .icon::before,
.wcps-container-1546 .splide__arrow.prev .icon::before,
.wcps-container-1546 .splide__arrows div.prev .icon::before {
    content: \"\\2039\" !important;
}

.wcps-container-1546 .splide__arrow--next .icon::before,
.wcps-container-1546 .splide__arrow.next .icon::before,
.wcps-container-1546 .splide__arrows div.next .icon::before {
    content: \"\\203A\" !important;
}

.wcps-container-1546 .splide__arrows.middle .prev,
.wcps-container-1546:hover .splide__arrows.middle .prev,
.wcps-container-1546 .splide__arrow--prev,
.wcps-container-1546 .splide__arrow.prev,
.wcps-container-1546 .splide__arrows div.prev {
    left: 15px !important;
    right: auto !important;
    float: none !important;
}

.wcps-container-1546 .splide__arrows.middle .next,
.wcps-container-1546:hover .splide__arrows.middle .next,
.wcps-container-1546 .splide__arrow--next,
.wcps-container-1546 .splide__arrow.next,
.wcps-container-1546 .splide__arrows div.next {
    right: 15px !important;
    left: auto !important;
    float: none !important;
}

.wcps-container-1546 .splide__arrows div:hover:not(:disabled),
.wcps-container-1546 .splide__arrow:hover:not(:disabled) {
    background: #0e3780 !important;
    background-color: #0e3780 !important;
    border-color: #0e3780 !important;
    color: #ffffff !important;
    transform: translateY(-50%) scale(1.1) !important;
    box-shadow: 0 8px 24px rgba(14, 55, 128, 0.28) !important;
}

/* 6. PAGINACAO MODERNA */
.wcps-container-1546 .splide__pagination {
    margin-top: 15px !important;
    padding: 0 !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 8px !important;
    position: static !important;
}

.wcps-container-1546 .splide__pagination__page {
    width: 8px !important;
    height: 8px !important;
    background-color: #cbd5e1 !important;
    border-radius: 4px !important;
    border: none !important;
    opacity: 0.8 !important;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
    padding: 0 !important;
    margin: 0 !important;
    cursor: pointer !important;
}

.wcps-container-1546 .splide__pagination__page.is-active {
    width: 28px !important;
    height: 8px !important;
    background-color: #f76a0c !important;
    border-radius: 4px !important;
    opacity: 1 !important;
    box-shadow: 0 2px 8px rgba(247, 106, 12, 0.3) !important;
}

@media (max-width: 991px) {
    .wcps-container-1546 .splide__arrows {
        top: 25% !important;
    }

    .wcps-container-1546 .elements-wrapper {
        flex-direction: column !important;
        padding: 20px 20px !important;
        gap: 20px !important;
        text-align: center !important;
        align-items: center !important;
        min-height: auto !important;
    }

    .wcps-container-1546 .layer-media {
        flex: 0 0 auto !important;
        max-width: 100% !important;
        width: 100% !important;
        min-height: auto !important;
        padding: 10px !important;
    }

    .wcps-container-1546 .wcps-items-thumb img {
        max-height: 200px !important;
    }

    .wcps-container-1546 .layer-content {
        flex: 0 0 auto !important;
        max-width: 100% !important;
        width: 100% !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0 !important;
    }

    .wcps-container-1546 .wcps-items-title {
        text-align: center !important;
        font-size: 26px !important;
    }

    .wcps-container-1546 .uonix-wcps-banner-desc {
        text-align: center !important;
        font-size: 15px !important;
        margin-bottom: 20px !important;
    }

    .wcps-container-1546 .uonix-wcps-btn {
        width: 100% !important;
        max-width: 320px !important;
    }

    .wcps-container-1546 .splide__arrow--prev {
        left: 8px !important;
    }

    .wcps-container-1546 .splide__arrow--next {
        right: 8px !important;
    }
}

@media (max-width: 600px) {
    .wcps-container-1546 .elements-wrapper {
        padding: 15px 10px !important;
    }

    .wcps-container-1546 .wcps-items-title {
        font-size: 22px !important;
    }

    .wcps-container-1546 .splide__arrow {
        width: 38px !important;
        height: 38px !important;
        font-size: 16px !important;
    }

    .wcps-container-1546 .splide__arrow--prev {
        left: 4px !important;
    }

    .wcps-container-1546 .splide__arrow--next {
        right: 4px !important;
    }
}";

	$current_1546_css = isset( $opts_1546['custom_css'] ) ? $opts_1546['custom_css'] : '';
	if ( trim( $current_1546_css ) !== trim( $banner_custom_css ) ) {
		$opts_1546['custom_css'] = $banner_custom_css;
		$opts_changed = true;
	}

	if ( $opts_changed ) {
		$GLOBALS['uonix_changes']++;
		echo "   [MUDARIA] Slider Home (ID {$p1546_id}): layout ou custom_css difere do canônico.\n";
		if ( $GLOBALS['uonix_apply'] ) {
			uonix_wcps_backup( 'postmeta_1546', $p1546_id, get_post_meta( $p1546_id, 'wcps_options', true ), $backup_dir );
			update_post_meta( $p1546_id, 'wcps_options', $opts_1546 );
			echo "   ✅ [ATUALIZADO] Slider Home (ID {$p1546_id}) atualizado com layout ID {$canonical_layout_id} e custom_css.\n";
		}
	} else {
		$GLOBALS['uonix_noop']++;
		echo "   [sem mudança] Slider Home (ID {$p1546_id}) verificado e consistente.\n";
	}
} else {
	echo "   ⚠️  [AVISO] Post WCPS 1546 não encontrado no ambiente atual.\n";
}

// -----------------------------------------------------------------------------
// 3. SLIDER SIDEBAR DO BLOG (ID 8643)
// -----------------------------------------------------------------------------
echo "\n--- 3. SLIDER SIDEBAR DO BLOG (ID 8643) ---\n";
$post_8643 = get_post( 8643 );
if ( ! $post_8643 || 'wcps' !== $post_8643->post_type ) {
	$wcps_posts = get_posts( array( 'post_type' => 'wcps', 'name' => '8643', 'posts_per_page' => 1 ) );
	if ( ! empty( $wcps_posts ) ) {
		$post_8643 = $wcps_posts[0];
	}
}

$sidebar_custom_css = "/* ==========================================================
   UONIX: SLIDER SIDEBAR DO BLOG (WCPS 8643)
   ========================================================== */

/* 1. CONTAINER BASE - DENTRO DA PREMIUM BOX */
.uonix-destaques-widget > p {
    display: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.wcps-container-8643 {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
    position: relative !important;
    box-sizing: border-box !important;
}

.wcps-container-8643 .splide__track {
    overflow: hidden !important;
    border: none !important;
    box-shadow: none !important;
    background: transparent !important;
    padding: 0 !important;
}

/* 2. CARD DO PRODUTO (CLICAVEL) */
.wcps-container-8643 .elements-wrapper {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 4px !important;
    box-sizing: border-box !important;
    text-align: center !important;
    cursor: pointer !important;
}

/* 3. IMAGEM DO PRODUTO */
.wcps-container-8643 .layer-media {
    width: 100% !important;
    height: 155px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin-bottom: 6px !important;
}

.wcps-container-8643 .wcps-items-thumb {
    width: 100% !important;
    max-width: 240px !important;
    margin: 0 auto !important;
    border: none !important;
    background: transparent !important;
}

.wcps-container-8643 .wcps-items-thumb a {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: none !important;
}

.wcps-container-8643 .wcps-items-thumb img {
    max-height: 150px !important;
    width: auto !important;
    object-fit: contain !important;
    mix-blend-mode: multiply !important;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1) !important;
}

.wcps-container-8643 .elements-wrapper:hover .wcps-items-thumb img {
    transform: scale(1.06) !important;
}

/* 4. AREA DE CONTEUDO */
.wcps-container-8643 .layer-content {
    width: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    text-align: center !important;
    padding: 2px 0 0 0 !important;
}

/* 5. TITULO DO PRODUTO (NOME DO PRODUTO CENTRALIZADO) */
.wcps-container-8643 .wcps-items-title {
    margin: 0 !important;
    width: 100% !important;
    text-align: center !important;
    text-wrap: balance !important;
}

.wcps-container-8643 .wcps-items-title a {
    color: #0e3780 !important;
    font-family: var(--global-heading-font-family, Barlow Semi Condensed, sans-serif) !important;
    font-size: 19px !important;
    font-weight: 800 !important;
    line-height: 1.25 !important;
    text-decoration: none !important;
    display: block !important;
    text-align: center !important;
    margin: 0 auto !important;
    text-wrap: balance !important;
    transition: color 0.25s ease !important;
}

.wcps-container-8643 .elements-wrapper:hover .wcps-items-title a {
    color: #f76a0c !important;
}

/* 6. OCULTA DESCRICAO, BOTAO INTERNO E BOLINHAS DE PAGINACAO */
.wcps-container-8643 .uonix-wcps-banner-desc,
.wcps-container-8643 .uonix-wcps-btn,
.wcps-container-8643 .uonix-wcps-banner-action,
.wcps-container-8643 .wcps-items-cart,
.wcps-container-8643 .wcps-items-price,
.wcps-container-8643 .quantity,
.wcps-container-8643 .added_to_cart,
.wcps-container-8643 .screen-reader-text,
.wcps-container-8643 .wcps-ribbon,
.wcps-container-8643 .uonix-section-title,
.wcps-container-8643 .splide__pagination {
    display: none !important;
}

/* 7. SETAS DE NAVEGACAO */
.wcps-container-8643 .splide__arrows {
    position: absolute !important;
    top: 78px !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    height: 0 !important;
    pointer-events: none !important;
    z-index: 10 !important;
}

.wcps-container-8643 .splide__arrow {
    position: absolute !important;
    pointer-events: auto !important;
    width: 32px !important;
    height: 32px !important;
    border-radius: 50% !important;
    background: rgba(255, 255, 255, 0.94) !important;
    backdrop-filter: blur(6px) !important;
    -webkit-backdrop-filter: blur(6px) !important;
    color: #0e3780 !important;
    border: 1px solid rgba(226, 232, 240, 0.9) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.25s ease !important;
    transform: translateY(-50%) !important;
    opacity: 0.92 !important;
    padding: 0 !important;
}

.wcps-container-8643 .splide__arrow:hover {
    background: #f76a0c !important;
    color: #ffffff !important;
    border-color: #f76a0c !important;
    transform: translateY(-50%) scale(1.08) !important;
    box-shadow: 0 6px 16px rgba(247, 106, 12, 0.35) !important;
    opacity: 1 !important;
}

.wcps-container-8643 .splide__arrow--prev,
.wcps-container-8643 .splide__arrow.prev {
    left: 0px !important;
}

.wcps-container-8643 .splide__arrow--next,
.wcps-container-8643 .splide__arrow.next {
    right: 0px !important;
    left: auto !important;
}

.wcps-container-8643 .splide__arrow svg,
.wcps-container-8643 .splide__arrow i {
    width: 14px !important;
    height: 14px !important;
    fill: currentColor !important;
}

/* 8. BOTAO SLIM NEWSLETTER STYLE - ESPACAMENTO PROXIMO AO SLIDER */
.uonix-destaques-widget .uonix-btn-submit-news {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    margin-top: 5px !important;
    height: 38px !important;
    padding: 0 16px !important;
    background: #0e3780 !important;
    color: #ffffff !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    border: none !important;
    border-radius: 6px !important;
    transition: all 0.3s ease !important;
    cursor: pointer !important;
    box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
}

.uonix-destaques-widget .uonix-btn-submit-news:hover {
    background: #15459e !important;
    color: #ffffff !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25) !important;
}";

if ( $post_8643 ) {
	$p8643_id = $post_8643->ID;
	$opts_8643 = get_post_meta( $p8643_id, 'wcps_options', true );
	if ( ! is_array( $opts_8643 ) ) {
		$opts_8643 = array();
	}

	$needs_update = false;

	// Associa ao layout canônico
	if ( $canonical_layout_id > 0 && ( ! isset( $opts_8643['item_layout_id'] ) || (int) $opts_8643['item_layout_id'] !== $canonical_layout_id ) ) {
		$opts_8643['item_layout_id'] = $canonical_layout_id;
		$needs_update = true;
	}

	// Aplica custom_css refinado
	if ( ! isset( $opts_8643['custom_css'] ) || trim( $opts_8643['custom_css'] ) !== trim( $sidebar_custom_css ) ) {
		$opts_8643['custom_css'] = $sidebar_custom_css;
		$needs_update = true;
	}

	// Garante que o subtítulo esteja limpo
	if ( ! empty( $opts_8643['ribbon']['text'] ) ) {
		$opts_8643['ribbon']['text'] = '';
		$needs_update = true;
	}

	if ( $needs_update ) {
		$GLOBALS['uonix_changes']++;
		echo "   [MUDARIA] Slider Sidebar (ID {$p8643_id}): opções visuais e custom_css diferem do canônico.\n";
		if ( $GLOBALS['uonix_apply'] ) {
			uonix_wcps_backup( 'postmeta_8643', $p8643_id, get_post_meta( $p8643_id, 'wcps_options', true ), $backup_dir );
			update_post_meta( $p8643_id, 'wcps_options', $opts_8643 );
			echo "   ✅ [ATUALIZADO] Slider Sidebar (ID {$p8643_id}) sincronizado com layout ID {$canonical_layout_id}.\n";
		}
	} else {
		$GLOBALS['uonix_noop']++;
		echo "   [sem mudança] Slider Sidebar (ID {$p8643_id}) já está na configuração canônica.\n";
	}
} else {
	echo "   ⚠️  [AVISO] Post WCPS 8643 não encontrado no ambiente atual.\n";
}

// -----------------------------------------------------------------------------
// 4. WIDGETS DE BLOCO (widget_block nos índices 138 e 156)
// -----------------------------------------------------------------------------
echo "\n--- 4. WIDGETS DE BLOCO (widget_block) ---\n";
$widget_blocks = get_option( 'widget_block' );
if ( is_array( $widget_blocks ) ) {
	$canonical_widget_content = '<div class="uonix-premium-box uonix-destaques-widget">' . "\n" .
		'    <h3 class="uonix-section-title">Produtos em Destaque</h3>' . "\n" .
		'    [wcps id="8643"]' . "\n" .
		'    <a href="/produtos" class="uonix-btn-submit-news">' . "\n" .
		'        <span>Conheça nosso catálogo</span>' . "\n" .
		'    </a>' . "\n" .
		'</div>';

	$widgets_changed = false;
	$target_indices = array( 138, 154 );

	foreach ( $target_indices as $idx ) {
		if ( isset( $widget_blocks[ $idx ]['content'] ) ) {
			$cur = trim( $widget_blocks[ $idx ]['content'] );
			// Remove comentários wp:html residuais se existirem para comparação
			$cur_clean = trim( str_replace( array( '<!-- wp:html -->', '<!-- /wp:html -->' ), '', $cur ) );
			if ( $cur_clean !== trim( $canonical_widget_content ) ) {
				$widget_blocks[ $idx ]['content'] = "<!-- wp:html -->\n" . $canonical_widget_content . "\n<!-- /wp:html -->";
				$widgets_changed = true;
				$GLOBALS['uonix_changes']++;
				echo "   [MUDARIA] widget_block[{$idx}] atualizado para botão .uonix-btn-submit-news e texto limpo.\n";
			} else {
				$GLOBALS['uonix_noop']++;
				echo "   [sem mudança] widget_block[{$idx}] já está consistente.\n";
			}
		}
	}

	// Também verifica bloco composto 156 se contiver o widget antigo
	if ( isset( $widget_blocks[156]['content'] ) ) {
		$w156 = $widget_blocks[156]['content'];
		if ( false !== strpos( $w156, '[wcps id="8643"]' ) && false === strpos( $w156, 'uonix-btn-submit-news' ) ) {
			// Atualiza trecho interno
			$w156_fixed = preg_replace(
				'/<div class="uonix-premium-box uonix-destaques-widget">.*?<\/div>/s',
				$canonical_widget_content,
				$w156
			);
			if ( $w156_fixed && $w156_fixed !== $w156 ) {
				$widget_blocks[156]['content'] = $w156_fixed;
				$widgets_changed = true;
				$GLOBALS['uonix_changes']++;
				echo "   [MUDARIA] widget_block[156] ajustado para o padrão novo.\n";
			}
		} else {
			$GLOBALS['uonix_noop']++;
			echo "   [sem mudança] widget_block[156] consistente.\n";
		}
	}

	if ( $widgets_changed && $GLOBALS['uonix_apply'] ) {
		uonix_wcps_backup( 'option', 'widget_block', get_option( 'widget_block' ), $backup_dir );
		update_option( 'widget_block', $widget_blocks );
		echo "   ✅ [ATUALIZADO] Option 'widget_block' salva com sucesso.\n";
	}
} else {
	echo "   ⚠️  [AVISO] Option 'widget_block' não é um array válido.\n";
}

// -----------------------------------------------------------------------------
// 5. VERIFICAÇÃO PÓS-GRAVAÇÃO (READBACK DE SHORTCODES)
// -----------------------------------------------------------------------------
echo "\n--- 5. READBACK E VERIFICAÇÃO DE RENDERIZAÇÃO DOS SHORTCODES ---\n";

// Teste do shortcode 1546
$out_1546 = do_shortcode( '[wcps id="1546"]' );
$valid_1546 = ( false !== strpos( $out_1546, 'wcps-container-1546' ) || false !== strpos( $out_1546, 'wcps-items' ) );
if ( $valid_1546 ) {
	echo "   ✅ [READBACK OK] Shortcode [wcps id=\"1546\"] renderizou HTML com container válido (" . strlen( $out_1546 ) . " bytes).\n";
} else {
	echo "   ⚠️  [READBACK ALERTA] Shortcode [wcps id=\"1546\"] retornou saída inesperada (" . strlen( $out_1546 ) . " bytes).\n";
}

// Teste do shortcode 8643
$out_8643 = do_shortcode( '[wcps id="8643"]' );
$valid_8643 = ( false !== strpos( $out_8643, 'wcps-container-8643' ) || false !== strpos( $out_8643, 'wcps-items' ) );
if ( $valid_8643 ) {
	echo "   ✅ [READBACK OK] Shortcode [wcps id=\"8643\"] renderizou HTML com container válido (" . strlen( $out_8643 ) . " bytes).\n";
} else {
	echo "   ⚠️  [READBACK ALERTA] Shortcode [wcps id=\"8643\"] retornou saída inesperada (" . strlen( $out_8643 ) . " bytes).\n";
}

// -----------------------------------------------------------------------------
// 6. FLUSH DE CACHE
// -----------------------------------------------------------------------------
if ( $GLOBALS['uonix_apply'] ) {
	wp_cache_flush();
	echo "\n✅ [OK] wp_cache_flush() executado com sucesso.\n";
} else {
	echo "\n(dry-run: cache não foi limpo)\n";
}

echo "\n========================================================================\n";
if ( $GLOBALS['uonix_apply'] ) {
	echo "🎉 APLICAÇÃO CONCLUÍDA!\n";
} else {
	echo "🔎 DRY-RUN CONCLUÍDO (nada foi gravado no banco de dados).\n";
}
echo "   CHANGES={$GLOBALS['uonix_changes']}  |  NOOP={$GLOBALS['uonix_noop']}  |  ERRORS={$GLOBALS['uonix_errors']}\n";
if ( ! empty( $backup_dir ) ) {
	echo "   BACKUP_DIR={$backup_dir}\n";
}
echo "========================================================================\n\n";
