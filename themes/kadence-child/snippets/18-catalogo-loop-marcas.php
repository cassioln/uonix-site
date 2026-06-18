<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Catalogo - fabricante no loop de produtos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 5066-5147 do export original.
// -----------------------------------------------------------------------------
/**
 * Catalogo Produtos - Exibe o nome do fabricante no canto superior direito da imagem.
 */
/**
 * UÔNIX: Fabricante no Catálogo V1.2
 * ---------------------------------------------------------
 * - Exibe o nome do fabricante no canto superior ESQUERDO da imagem.
 * - Hover: Fundo Azul (#003399) com texto Branco.
 * - Design discreto e elegante.
 */

// 1. INJETA O NOME DO FABRICANTE NO LOOP DE PRODUTOS
add_action( 'woocommerce_before_shop_loop_item_title', 'uonix_display_brand_in_loop', 15 );

function uonix_display_brand_in_loop() {
    global $product;
    
    // Tenta buscar a marca (Atributo pa_marca ou Taxonomia product_brand)
    $marca = $product->get_attribute('pa_marca');
    
    if ( empty( $marca ) ) {
        $brands = wp_get_post_terms( $product->get_id(), 'product_brand' );
        if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
            $marca = $brands[0]->name;
        }
    }

    // Se houver marca, exibe o badge
    if ( ! empty( $marca ) ) {
        echo '<span class="uonix-loop-brand-badge">' . esc_html( $marca ) . '</span>';
    }
}

// 2. ESTILIZAÇÃO DO BADGE E POSICIONAMENTO
add_action('wp_footer', function() {
    ?>
    <style id="uonix-catalog-brand-css">
        /* Container do produto precisa ser relativo para o badge se posicionar nele */
        .products .product {
            position: relative !important;
        }

        .uonix-loop-brand-badge {
            position: absolute !important;
            top: 0px !important;
            left: 12px !important;
            z-index: 0;
            
            /* Design Elegante e Discreto */
            background-color: rgba(255, 255, 255, 0.9) !important;
            color: #1a202c !important;
            padding: 4px 10px !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            
            border-radius: 4px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08) !important;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        /* Hover: Troca para o Azul Uônix (#003399) */
//         .product:hover .uonix-loop-brand-badge {
//             background-color: #003399 !important; 
//             color: #003399 !important;
//         }

        /* Ajuste para dispositivos móveis */
        @media (max-width: 768px) {
            .uonix-loop-brand-badge {
                top: 8px !important;
                left: 8px !important;
                font-size: 9px !important;
                padding: 3px 8px !important;
            }
        }
    </style>
    <?php
}, 100);


