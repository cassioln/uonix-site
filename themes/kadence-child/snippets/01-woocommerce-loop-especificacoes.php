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

    // Pega o link do produto
    $link = get_permalink( $product->get_id() );
    
    // Define as classes:
    // 'button' -> Pega o estilo padrão de botões do seu tema
    // 'uonix-details-btn' -> Classe extra para estilizar via CSS se precisar
    $classes = 'button product_type_simple add_to_cart_button uonix-details-btn';

    echo '<a href="' . esc_url( $link ) . '" class="' . $classes . '">Ver detalhes</a>';
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


