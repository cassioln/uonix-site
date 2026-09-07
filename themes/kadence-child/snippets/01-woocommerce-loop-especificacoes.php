<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - WooCommerce - loop de produtos e especificacoes.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 2-51 do export original.
// -----------------------------------------------------------------------------

/**
 * Desativar Zoom do Produto
 *
 * Desativa o zoom ao passar o mouse na imagem na página do produto
 */
/* ------------------------------------------------------------------------- *
 * Substituir Botão "Comprar" por "Ver Detalhes" na Listagem
 * ------------------------------------------------------------------------- */

// 1. Remove o botão original (padrão do WooCommerce)
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

// 2. Adiciona o novo botão personalizado no mesmo lugar
add_action( 'woocommerce_after_shop_loop_item', 'uonix_botao_ver_detalhes', 10 );

function uonix_botao_ver_detalhes() {
    global $product;

    if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
        return;
    }

    $product_id = $product->get_id();
    $link       = get_permalink( $product_id );

    // 1. Produtos com Variações: Botão "Ver Opções" direcionando para a página do produto
    if ( $product->is_type( 'variable' ) ) {
        $classes = 'button uonix-details-btn uonix-btn-variable';
        echo '<a href="' . esc_url( $link ) . '" class="' . esc_attr( $classes ) . '">Ver Opções</a>';
        return;
    }

    // 2. Produtos Simples: Botão "Adicionar ao carrinho" com Seletor de Quantidade Interativo
    $qty_in_cart = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] === $product_id ) {
                $qty_in_cart += (int) $cart_item['quantity'];
            }
        }
    }

    $has_items   = ( $qty_in_cart > 0 );
    $wrap_class  = $has_items ? 'uonix-product-action-wrap has-items' : 'uonix-product-action-wrap';
    $is_trash    = ( $qty_in_cart === 1 );

    $trash_svg = function_exists( 'uonix_get_cart_loop_svg' )
        ? uonix_get_cart_loop_svg( 'trash' )
        : '<svg class="uonix-qty-icon uonix-icon-trash" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';

    $minus_svg = function_exists( 'uonix_get_cart_loop_svg' )
        ? uonix_get_cart_loop_svg( 'minus' )
        : '<svg class="uonix-qty-icon uonix-icon-minus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

    $plus_svg = function_exists( 'uonix_get_cart_loop_svg' )
        ? uonix_get_cart_loop_svg( 'plus' )
        : '<svg class="uonix-qty-icon uonix-icon-plus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

    $left_icon  = $is_trash ? $trash_svg : $minus_svg;
    $left_title = $is_trash ? 'Remover do carrinho' : 'Diminuir quantidade';
    $left_class = $is_trash ? 'uonix-qty-btn uonix-qty-minus is-trash' : 'uonix-qty-btn uonix-qty-minus';

    $is_sold_individually = method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually();
    $limit_reached        = ( $is_sold_individually && $qty_in_cart >= 1 );

    $plus_title = $limit_reached ? 'Limite de 1 unidade atingido' : 'Aumentar quantidade';
    $plus_class = $limit_reached ? 'uonix-qty-btn uonix-qty-plus is-disabled' : 'uonix-qty-btn uonix-qty-plus';
    $plus_attr  = $limit_reached ? ' disabled="disabled"' : '';

    echo '<div class="' . esc_attr( $wrap_class ) . '" data-product-id="' . esc_attr( $product_id ) . '" data-qty="' . esc_attr( $qty_in_cart ) . '" data-sold-individually="' . ( $is_sold_individually ? '1' : '0' ) . '">';
    
    // Botão Adicionar ao Carrinho inicial
    echo '<button type="button" class="button uonix-details-btn uonix-add-to-cart-btn" data-product-id="' . esc_attr( $product_id ) . '">';
    echo 'Adicionar ao carrinho';
    echo '</button>';

    // Seletor de Quantidade Retangular (Design System Uônix)
    echo '<div class="uonix-qty-control">';
    echo '<button type="button" class="' . esc_attr( $left_class ) . '" title="' . esc_attr( $left_title ) . '" aria-label="' . esc_attr( $left_title ) . '">';
    echo $left_icon;
    echo '</button>';
    echo '<div class="uonix-qty-text"><span class="uonix-qty-num">' . esc_html( $qty_in_cart ) . '</span> no carrinho</div>';
    echo '<button type="button" class="' . esc_attr( $plus_class ) . '" title="' . esc_attr( $plus_title ) . '" aria-label="' . esc_attr( $plus_title ) . '"' . $plus_attr . '>';
    echo $plus_svg;
    echo '</button>';
    echo '</div>';

    echo '</div>';
}

/**
 * Adicionar Link "Voltar" na página de "Nenhum produto encontrado"
 */
/* ------------------------------------------------------------------------- *
 * Adicionar Link "Voltar" na página de "Nenhum produto encontrado"
 * ------------------------------------------------------------------------- */
add_action( 'woocommerce_no_products_found', 'uonix_link_voltar_vazio', 20 );

function uonix_link_voltar_vazio() {
    if ( ! function_exists( 'wc_get_page_id' ) ) {
        return;
    }

    // Define para onde o link vai (Página principal da Loja)
    $link_destino = get_permalink( wc_get_page_id( 'shop' ) );
    
    // Se preferir que volte para a página anterior do navegador, use a linha abaixo:
    // $link_destino = 'javascript:history.back()';

    echo '<div class="uonix-voltar-container" style="margin-top: 15px;">';
    echo '<a href="' . esc_url( $link_destino ) . '" style="font-weight: bold; text-decoration: none;">← Clique para voltar</a>';
    echo '</div>';
}


// -----------------------------------------------------------------------------
// Bloco 2 - linhas 133-216 do export original.
// -----------------------------------------------------------------------------
/**
 * Tabela Especificacao Tecnica
 */
/* * UÔNIX: Personalização da Aba de Especificações
 * 1. Renomeia "Informação Adicional" -> "Especificações Técnicas"
 * 2. Tabela Zebrada + Ajuste Automático de Largura (Sem quebra de linha)
 */

// 1. RENOMEAR A ABA
add_filter( 'woocommerce_product_tabs', 'uonix_renomear_aba_specs', 98 );

function uonix_renomear_aba_specs( $tabs ) {
    if ( isset( $tabs['additional_information'] ) ) {
        $tabs['additional_information']['title'] = 'Especificações Técnicas';
    }
    return $tabs;
}

// 2. CSS VISUAL (Largura Auto)
add_action( 'wp_head', 'uonix_css_tabela_specs' );

function uonix_css_tabela_specs() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }
    ?>
    <style>
        /* Esconde o título repetido */
        #tab-additional_information > h2 {
            display: none !important;
        }

        /* Estrutura Geral da Tabela */
        table.shop_attributes {
            border: 1px solid #eee;
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
            font-size: 15px;
        }

        table.shop_attributes th, 
        table.shop_attributes td {
            padding: 12px 15px !important;
            border-bottom: 1px solid #e2e2e2;
            text-align: left;
            vertical-align: middle; /* Alinha o texto no meio verticalmente */
        }

        /* --- COLUNA DOS NOMES (AUTO) --- */
        table.shop_attributes th {
            /* O Segredo do Auto: */
            width: 1%;             /* Força a ser o menor possível... */
            white-space: nowrap;   /* ...mas PROÍBE quebrar a linha. */
            
            color: #333;
            font-weight: 700;
            background-color: #fff;
        }

        /* --- COLUNA DOS VALORES --- */
        table.shop_attributes td {
            color: #666;
            font-style: normal !important;
            /* A coluna de valor pega todo o espaço que sobrar */
        }

        /* Efeito Zebrado */
        table.shop_attributes tr:nth-child(even) th,
        table.shop_attributes tr:nth-child(even) td {
            background-color: #f8f9fa;
        }
        
        /* AJUSTE MOBILE: Libera a quebra de linha no celular para não estourar a tela */
        @media (max-width: 600px) {
            table.shop_attributes th {
                white-space: normal; /* Permite quebrar linha no celular */
                width: 40%; /* Volta a ter uma largura fixa no celular */
            }
        }
    </style>
    <?php
}


