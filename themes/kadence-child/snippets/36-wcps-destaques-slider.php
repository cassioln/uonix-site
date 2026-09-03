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

// 2. Auxiliar leve para recalcular dimensões no carregamento do Splide
add_action( 'wp_footer', 'uonix_wcps_slider_init_fix', 99 );

function uonix_wcps_slider_init_fix() {
    ?>
    <script id="uonix-wcps-init-fix">
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 200);

        // Torna o card do slider da sidebar 100% clicável para a página do produto
        document.body.addEventListener('click', function(e) {
            const card = e.target.closest('.wcps-container-8643 .elements-wrapper');
            if (card && !e.target.closest('a')) {
                const link = card.querySelector('.wcps-items-title a');
                if (link && link.href) {
                    window.location.href = link.href;
                }
            }
        });
    });
    </script>
    <?php
}
