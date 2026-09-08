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

// 2. Garante o CSS das setas modernas e glifos canônicos dos sliders WCPS (1546 e 8643)
add_action( 'wp_head', 'uonix_wcps_arrows_fallback_css', 30 );

function uonix_wcps_arrows_fallback_css() {
    ?>
    <style id="uonix-wcps-arrows-css">
    .wcps-container-1546 .splide__arrow i,
    .wcps-container-8643 .splide__arrow i {
        display: none !important;
    }
    .wcps-container-1546 .splide__arrow .icon,
    .wcps-container-8643 .splide__arrow .icon {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 100% !important;
        height: 100% !important;
        pointer-events: none !important;
    }
    .wcps-container-1546 .splide__arrow .icon::before,
    .wcps-container-8643 .splide__arrow .icon::before {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 1em !important;
        height: 1em !important;
        color: currentColor !important;
        font-size: 28px !important;
        font-weight: 800 !important;
        line-height: 1 !important;
    }
    .wcps-container-1546 .splide__arrow.prev .icon::before,
    .wcps-container-1546 .splide__arrow--prev .icon::before,
    .wcps-container-8643 .splide__arrow.prev .icon::before,
    .wcps-container-8643 .splide__arrow--prev .icon::before {
        content: "\2039" !important;
    }
    .wcps-container-1546 .splide__arrow.next .icon::before,
    .wcps-container-1546 .splide__arrow--next .icon::before,
    .wcps-container-8643 .splide__arrow.next .icon::before,
    .wcps-container-8643 .splide__arrow--next .icon::before {
        content: "\203A" !important;
    }
    </style>
    <?php
}

// 3. Auxiliar leve para recalcular dimensões no carregamento do Splide e remover títulos residuais
add_action( 'wp_footer', 'uonix_wcps_slider_init_fix', 99 );

function uonix_wcps_slider_init_fix() {
    ?>
    <script id="uonix-wcps-init-fix">
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Remove qualquer título legado se existir no DOM
        var legacyTitle = document.querySelector('.uonix-carousel-title');
        if (legacyTitle) {
            legacyTitle.remove();
        }

        // 2. Garante o cabeçalho canônico Kadence antes do slider caso a página não tenha sido salva no editor
        var carousel1546 = document.querySelector('.wcps-container-1546');
        var hasKadenceHeading = document.querySelector('.kt-adv-heading2150_d3dd23-bf') || document.querySelector('.kt-adv-heading2150_d38c94-30') || document.querySelector('.uonix-slider-header');
        if (carousel1546 && !hasKadenceHeading) {
            var headerDiv = document.createElement('div');
            headerDiv.className = 'uonix-slider-header';
            headerDiv.style.marginBottom = '24px';
            headerDiv.innerHTML = '<span class="kt-adv-heading2150_d3dd23-bf wp-block-kadence-advancedheading kt-adv-heading-has-icon has-theme-palette14-color has-text-color" data-kb-block="kb-adv-heading2150_d3dd23-bf"><span class="kb-svg-icon-wrap kb-adv-heading-icon kb-svg-icon-fe_minus kb-adv-heading-icon-side-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg></span><span class="kb-adv-text-inner">Produtos em destaque</span></span><h2 class="kt-adv-heading2150_d38c94-30 wp-block-kadence-advancedheading has-kb-palette-3-color has-text-color" data-kb-block="kb-adv-heading2150_d38c94-30">Produtos para Ancoragem e Fixação</h2>';
            carousel1546.parentNode.insertBefore(headerDiv, carousel1546);
        }

        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 200);

        // 3. Torna o card do slider da sidebar 100% clicável para a página do produto
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

// 4. Garante o cabeçalho canônico Kadence da seção na Home (no SSR)
add_filter( 'the_content', 'uonix_wcps_slider_header_canonical_filter', 20 );

function uonix_wcps_slider_header_canonical_filter( $content ) {
    if ( is_front_page() || is_home() ) {
        $canonical_header = '<div class="uonix-slider-header" style="margin-bottom: 24px;">' .
            '<span class="kt-adv-heading2150_d3dd23-bf wp-block-kadence-advancedheading kt-adv-heading-has-icon has-theme-palette14-color has-text-color" data-kb-block="kb-adv-heading2150_d3dd23-bf"><span class="kb-svg-icon-wrap kb-adv-heading-icon kb-svg-icon-fe_minus kb-adv-heading-icon-side-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg></span><span class="kb-adv-text-inner">Produtos em destaque</span></span>' .
            '<h2 class="kt-adv-heading2150_d38c94-30 wp-block-kadence-advancedheading has-kb-palette-3-color has-text-color" data-kb-block="kb-adv-heading2150_d38c94-30">Produtos para Ancoragem e Fixação</h2>' .
            '</div>';

        $has_existing_heading = (
            false !== strpos( $content, 'kt-adv-heading2150_d3dd23-bf' ) ||
            false !== strpos( $content, 'kt-adv-heading2150_d38c94-30' ) ||
            false !== strpos( $content, 'uonix-slider-header' )
        );

        if ( $has_existing_heading ) {
            // Caso 1: O bloco já existe no banco (substitui texto antigo se presente)
            if ( false !== strpos( $content, 'Os melhores produtos para construir com segurança.' ) ) {
                $content = str_replace(
                    'Os melhores produtos para construir com segurança.',
                    'Produtos para Ancoragem e Fixação',
                    $content
                );
            }
        } else {
            // Caso 2: Onde o bloco não foi inserido no banco de dados.
            // Insere o cabeçalho canônico exatamente antes da chamada do slider 1546.
            $slider_patterns = array(
                "[wcps id='1546']",
                '[wcps id="1546"]',
                '[wcps id=1546]',
            );
            foreach ( $slider_patterns as $p ) {
                if ( false !== strpos( $content, $p ) ) {
                    $content = str_replace( $p, $canonical_header . "\n" . $p, $content );
                    break;
                }
            }
        }

        // Padroniza texto do link para o catálogo de produtos no rodapé da seção
        if ( false !== strpos( $content, 'Catálago de Produtos' ) ) {
            $content = str_replace( 'Catálago de Produtos', 'Conheça todos nossos produtos', $content );
        }
    }
    return $content;
}
