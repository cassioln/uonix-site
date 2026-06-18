<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Footer - copyright customizado.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 16763-16818 do export original.
// -----------------------------------------------------------------------------
/**
 * copyright shortcode
 */
/**
 * Rodapé personalizado com copyright.
 * Usa classes próprias para não conflitar com o bloco "Powered by ksio.dev".
 */

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }

    $ano_atual   = date_i18n('Y');
    $titulo_site = get_bloginfo('name');
    ?>
    <div class="uonix-copyright-footer">
        <div class="uonix-copyright-text">
            © <?php echo esc_html($ano_atual); ?> <?php echo esc_html($titulo_site); ?>. Todos os direitos reservados.
        </div>
    </div>
    <?php
}, 98);

add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <style>
        .uonix-copyright-footer {
            width: 100%;
            padding: 18px 24px;
            font-size: 13px;
            line-height: 1.6;
            color: #f5f5f5;
            background: #10141d;
            box-sizing: border-box;
            border-top: none !important;
            box-shadow: none !important;
        }

        .uonix-copyright-text {
            text-align: center;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .uonix-copyright-text {
                white-space: normal;
            }
        }
    </style>
    <?php
}, 99);


