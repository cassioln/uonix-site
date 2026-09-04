<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UÔNIX: Hook de Orçamento para Sliders WCPS
 *
 * - Todas as opções do slider (1 coluna banner, autoplay, setas, paginação) e o CSS
 *   ficam configurados nativamente dentro do painel do plugin WCPS (wp-admin).
 * - O layout sem preço está configurado nativamente em WCPS > Layouts (ID 11188).
 * - Este arquivo mantém APENAS a conversão do botão nativo de compra para o botão
 *   institucional "Solicitar Orçamento" com link para o produto e texto técnico de normas.
 */

// 1. Substitui o botão de compra pelo botão institucional "Solicitar Orçamento"
add_action( 'init', 'uonix_customize_wcps_destaques_hook', 20 );

function uonix_customize_wcps_destaques_hook() {
    remove_action( 'wcps_layout_element_add_to_cart', 'wcps_layout_element_add_to_cart', 10 );
    add_action( 'wcps_layout_element_add_to_cart', 'uonix_wcps_custom_banner_action', 10 );
}

function uonix_wcps_custom_banner_action( $args ) {
    $product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : get_the_ID();
    $link = get_permalink( $product_id );

    // Obtém a "Breve descrição sobre o produto" (post_excerpt do WooCommerce)
    $product = wc_get_product( $product_id );
    $short_desc = $product ? $product->get_short_description() : '';
    if ( empty( $short_desc ) ) {
        $post = get_post( $product_id );
        $short_desc = ! empty( $post->post_excerpt ) ? $post->post_excerpt : '';
    }

    if ( ! empty( $short_desc ) ) {
        $short_desc = wp_strip_all_tags( $short_desc );
    } else {
        $short_desc = 'Dispositivos de ancoragem de alta resistência, certificados e fabricados rigorosamente em conformidade com as normas NR-18, NR-35 e NBR 16325. Solicite uma cotação para o seu projeto com garantia de fábrica.';
    }
    ?>
    <div class="uonix-wcps-banner-action">
        <p class="uonix-wcps-banner-desc">
            <?php echo esc_html( $short_desc ); ?>
        </p>
        <a href="<?php echo esc_url( $link ); ?>" class="uonix-wcps-btn">
            <span>Solicitar Orçamento</span>
            <svg class="uonix-wcps-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
        </a>
    </div>
    <?php
}

// 2. Estilos canônicos gerenciados para o slider banner hero da home [wcps id='1546']
add_action( 'wp_head', 'uonix_wcps_banner_hero_styles', 30 );

function uonix_wcps_banner_hero_styles() {
    ?>
    <style id="uonix-wcps-banner-hero-styles">
    /* ==========================================================================
       UONIX: TITULO DA SECAO DO CARROSSEL
       ========================================================================== */
    .uonix-carousel-title {
        text-align: center !important;
        font-size: 32px !important;
        font-weight: 900 !important;
        color: #0e3780 !important;
        margin-top: 40px !important;
        margin-bottom: 30px !important;
        text-transform: uppercase !important;
        letter-spacing: 1px !important;
        position: relative;
    }
    .uonix-carousel-title::after {
        content: "";
        display: block;
        width: 80px;
        height: 4px;
        background-color: #f76a0c !important;
        margin: 15px auto 0 auto;
        border-radius: 2px;
    }

    /* ==========================================================================
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

    /* 5. SETAS CIRCULARES MODERNAS */
    .wcps-container-1546 .splide__arrows {
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

    .wcps-container-1546 .splide__arrow {
        width: 44px !important;
        height: 44px !important;
        background-color: #0e3780 !important;
        border: none !important;
        border-radius: 50% !important;
        box-shadow: 0 4px 16px rgba(14, 55, 128, 0.2) !important;
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
        color: #ffffff !important;
    }

    .wcps-container-1546 .splide__arrow--prev,
    .wcps-container-1546 .splide__arrow.prev {
        left: 15px !important;
    }

    .wcps-container-1546 .splide__arrow--next,
    .wcps-container-1546 .splide__arrow.next {
        right: 15px !important;
    }

    .wcps-container-1546 .splide__arrow:hover:not(:disabled) {
        background-color: #f76a0c !important;
        color: #ffffff !important;
        transform: translateY(-50%) scale(1.1) !important;
        box-shadow: 0 8px 24px rgba(247, 106, 12, 0.35) !important;
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

    /* 7. RESPONSIVIDADE (TABLET E MOBILE) */
    @media (max-width: 991px) {
        .uonix-carousel-title {
            font-size: 24px !important;
            margin-top: 25px !important;
            margin-bottom: 20px !important;
        }

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

        .wcps-container-1546 .splide__arrow--prev,
        .wcps-container-1546 .splide__arrow.prev {
            left: 8px !important;
        }

        .wcps-container-1546 .splide__arrow--next,
        .wcps-container-1546 .splide__arrow.next {
            right: 8px !important;
        }
    }

    @media (max-width: 600px) {
        .uonix-carousel-title {
            font-size: 20px !important;
            margin-top: 20px !important;
            margin-bottom: 15px !important;
        }

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

        .wcps-container-1546 .splide__arrow--prev,
        .wcps-container-1546 .splide__arrow.prev {
            left: 4px !important;
        }

        .wcps-container-1546 .splide__arrow--next,
        .wcps-container-1546 .splide__arrow.next {
            right: 4px !important;
        }
    }
    </style>
    <?php
}

// 3. Auxiliar leve para recalcular dimensões no carregamento do Splide e injetar título
add_action( 'wp_footer', 'uonix_wcps_slider_init_fix', 99 );

function uonix_wcps_slider_init_fix() {
    ?>
    <script id="uonix-wcps-init-fix">
    document.addEventListener('DOMContentLoaded', function() {
        // Injeta o título canônico antes do carrossel da home caso não esteja presente
        var carousel1546 = document.querySelector('.wcps-container-1546');
        if (carousel1546 && !document.querySelector('.uonix-carousel-title')) {
            var titleEl = document.createElement('h2');
            titleEl.className = 'uonix-carousel-title';
            titleEl.textContent = 'Produtos para Ancoragem e Fixação';
            carousel1546.parentNode.insertBefore(titleEl, carousel1546);
        }

        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 200);

        // Torna o card do slider da sidebar 100% clicável para a página do produto
        document.body.addEventListener('click', function(e) {
            var card = e.target.closest('.wcps-container-8643 .elements-wrapper');
            if (card && !e.target.closest('a')) {
                var link = card.querySelector('.wcps-items-title a');
                if (link && link.href) {
                    window.location.href = link.href;
                }
            }
        });
    });
    </script>
    <?php
}
