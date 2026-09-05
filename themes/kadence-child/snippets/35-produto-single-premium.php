<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Produto - pagina single premium e WhatsApp.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 12161-12942 do export original.
// -----------------------------------------------------------------------------
/**
 * Pagina Produtos 
 */
/**
 * UÔNIX: Produto Premium Unificado
 * ---------------------------------------------------------
 * - Move "Marca" para baixo do título e renomeia para "Fabricante".
 * - Oculta metadados originais do WooCommerce.
 * - Estiliza primeira dobra do produto.
 * - Adiciona botão de WhatsApp no resumo do produto.
 * - Adiciona balão flutuante do WhatsApp após scroll.
 * - Adiciona compartilhamento premium na galeria.
 * - Adiciona loading visual ao adicionar ao carrinho.
 */

add_action('woocommerce_single_product_summary', 'uonix_product_whatsapp_box', 35);
add_action('wp_footer', 'uonix_product_premium_footer_assets');

function uonix_get_whatsapp_link() {
    $numero_whatsapp = '5511947254885';
    $texto_mensagem  = 'Olá, gostaria de tirar dúvidas sobre o produto: ' . get_the_title();

    return 'https://wa.me/' . $numero_whatsapp . '?text=' . urlencode($texto_mensagem);
}

function uonix_whatsapp_svg() {
    return '<svg viewBox="0 0 448 512" fill="currentColor" width="20" height="20" xmlns="http://www.w3.org/2000/svg"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"></path></svg>';
}

function uonix_product_whatsapp_box() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    $link_wa = uonix_get_whatsapp_link();
    ?>

    <div class="uonix-seller-box">
        <h5 class="uonix-seller-title">
            Alguma dúvida?
        </h5>

        <a href="<?php echo esc_url($link_wa); ?>" target="_blank" rel="noopener" class="uonix-wa-btn">
            <span class="uonix-wa-icon">
                <?php echo uonix_whatsapp_svg(); ?>
            </span>
            Fale com um Especialista
        </a>

        <p class="uonix-seller-note">
            Responderemos o mais breve possível
        </p>
    </div>

    <?php
}

function uonix_product_premium_footer_assets() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    $link_wa = uonix_get_whatsapp_link();
    ?>

    <style id="uonix-product-premium-css">
        /* Oculta metadados originais */
        .product_meta {
            display: none !important;
        }

        /* Galeria */
        .woocommerce-product-gallery {
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 10px !important;
            background: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            position: relative !important;
        }



        /* Categoria */
        .single-product-category a {
            color: #f76a0c !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Resumo */
        .summary.entry-summary {
            position: relative;
        }

        .summary.entry-summary h1.product_title {
            color: #0e3780 !important;
            font-weight: 800 !important;
            font-size: 28px !important;
            line-height: 1.2 !important;
            margin-bottom: 8px !important;
            margin-top: 5px !important;
        }

        .uonix-manufacturer-row {
            display: block;
            color: #64748b !important;
            font-size: 14px !important;
            margin-bottom: 20px !important;
        }

        .uonix-manufacturer-row a {
            color: #0e3780 !important;
            font-weight: 700 !important;
            text-decoration: none !important;
        }

        .woocommerce-product-details__short-description {
            color: #475569 !important;
            font-size: 15px !important;
            line-height: 1.6 !important;
            margin-bottom: 25px !important;
        }

        /* Box WhatsApp */
        .uonix-seller-box {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e2e2;
        }

        .uonix-seller-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #777;
            letter-spacing: 1px;
            margin-bottom: 15px;
            font-weight: 700;
        }

        .uonix-wa-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background-color: #25D366;
            color: white !important;
            padding: 12px 20px;
            border-radius: 4px;
            text-decoration: none !important;
            font-weight: 600;
            font-size: 18px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .uonix-wa-btn:hover {
            background-color: #1ebc57;
            transform: translateY(-2px);
            color: white !important;
        }

        .uonix-wa-icon {
            display: flex;
            align-items: center;
        }

        .uonix-seller-note {
            font-size: 11px;
            color: #888;
            margin-top: 8px;
            text-align: center;
        }

        /* Formulário / variações */
        form.variations_form,
        .summary form.cart:not(.variations_form) {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 24px;
            border-radius: 8px;
            margin-bottom: 25px !important;
        }

        .summary form.cart:not(.variations_form) {
            display: flex !important;
            gap: 0 !important;
            align-items: center !important;
            flex-wrap: wrap !important;
        }

        table.variations {
            width: 100% !important;
            margin-bottom: 0 !important;
        }

		table.variations tbody tr {
			display: flex !important;
			align-items: center !important;
			margin-bottom: 8px !important;
			gap: 22px !important;
		}

		table.variations th.label {
			padding: 0 !important;
			min-width: 80px !important;
			flex-shrink: 0 !important;
		}

        table.variations th.label label {
            color: #1a2b3c !important;
            font-weight: 700 !important;
            font-size: 14px;
            margin: 0 !important;
        }

        table.variations td.value {
            position: relative !important;
            display: flex !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            gap: 0 !important;
            flex-grow: 1;
        }

        table.variations td.value select {
            flex-grow: 1 !important;
            width: 100% !important;
            height: 50px !important;
            margin: 0 !important;
            padding: 12px 15px !important;
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 15px !important;
            color: #1a2b3c !important;
            background-color: #ffffff !important;
            outline: none !important;
            box-shadow: none !important;
            transition: all 0.3s ease;
        }

        table.variations td.value select:focus {
            border-color: #0e3780 !important;
        }

        table.variations a.reset_variations {
            align-items: center !important;
            justify-content: center !important;
            width: 50px !important;
            height: 50px !important;
            flex-shrink: 0 !important;
            background-color: #fee2e2 !important;
            border-radius: 6px !important;
            transition: all 0.2s ease !important;
            font-size: 0 !important;
            text-decoration: none !important;
            margin: 0 0 0 8px !important;
        }

        table.variations a.reset_variations[style*="visibility: hidden"],
        table.variations a.reset_variations[style*="display: none"] {
            display: none !important;
        }

        table.variations a.reset_variations[style*="visibility: visible"],
        table.variations a.reset_variations[style*="display: block"],
        table.variations a.reset_variations[style*="display: inline-block"] {
            display: flex !important;
        }

        table.variations a.reset_variations::before {
            content: "";
            display: block;
            width: 18px;
            height: 20px;
            background-color: #dc2626 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 448 512'%3E%3Cpath d='M135.2 17.7C140.6 6.8 151.7 0 163.8 0H284.2c12.1 0 23.2 6.8 28.6 17.7L320 32h96c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 96 0 81.7 0 64S14.3 32 32 32h96l7.2-14.3zM32 128H416V448c0 35.3-28.7 64-64 64H96c-35.3 0-64-28.7-64-64V128z'/%3E%3C/svg%3E") no-repeat center / contain;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 448 512'%3E%3Cpath d='M135.2 17.7C140.6 6.8 151.7 0 163.8 0H284.2c12.1 0 23.2 6.8 28.6 17.7L320 32h96c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 96 0 81.7 0 64S14.3 32 32 32h96l7.2-14.3zM32 128H416V448c0 35.3-28.7 64-64 64H96c-35.3 0-64-28.7-64-64V128z'/%3E%3C/svg%3E") no-repeat center / contain;
        }

        table.variations a.reset_variations:hover {
            background-color: #fca5a5 !important;
            transform: translateY(-2px);
        }

        table.variations a.reset_variations:hover::before {
            background-color: #991b1b !important;
        }

        .reset_variations_alert.screen-reader-text {
            display: none !important;
        }

        /* Adicionar ao carrinho */
        .woocommerce-variation-add-to-cart {
            display: flex !important;
            gap: 0 !important;
            align-items: center !important;
            margin-top: 20px !important;
            padding-top: 20px !important;
            border-top: 1px solid #e2e8f0;
            padding-left: 50px !important;
        }

        button.single_add_to_cart_button.button.alt {
            margin-left: 10px;
            line-height: 18px !important;
        }

        .woocommerce-variation-add-to-cart .quantity,
        .summary form.cart:not(.variations_form) .quantity {
            margin: 0 !important;
            height: 50px !important;
            flex-shrink: 0 !important;
        }

        .woocommerce-variation-add-to-cart .quantity input.qty,
        .summary form.cart:not(.variations_form) .quantity input.qty {
            height: 50px !important;
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            background: #ffffff !important;
            font-weight: 700 !important;
        }

        .woocommerce-variation-add-to-cart .single_add_to_cart_button,
        .summary form.cart:not(.variations_form) .single_add_to_cart_button {
            background-color: #0e3780 !important;
            color: #ffffff !important;
            border-radius: 6px !important;
            height: 50px !important;
            flex-grow: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 15px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: none !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important;
        }

        .woocommerce-variation-add-to-cart .single_add_to_cart_button:hover,
        .summary form.cart:not(.variations_form) .single_add_to_cart_button:hover {
            background-color: #f76a0c !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.3) !important;
        }

        .kadence-cart-button-medium-large.woocommerce div.product form.cart div.quantity.spinners-added~.button.single_add_to_cart_button {
            width: 0;
        }

        @media screen and (min-width: 576px) {
            .kadence-cart-button-medium-large.woocommerce div.product form.cart div.quantity.spinners-added {
                width: 115px;
            }
        }

        @media (max-width: 850px) {
            .woocommerce-variation-add-to-cart {
                padding-left: 0 !important;
            }
        }

        /* Loading */
        .uonix-loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            backdrop-filter: blur(2px);
        }

        .uonix-loading-overlay.is-active {
            opacity: 1;
            visibility: visible;
        }

        .uonix-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(14, 55, 128, 0.2);
            border-top: 4px solid #0e3780;
            border-radius: 50%;
            animation: uonix-spin 1s linear infinite;
        }

        @keyframes uonix-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mensagem WooCommerce */
        .woocommerce-notices-wrapper {
            margin-bottom: 25px !important;
        }

        .woocommerce-message {
            background-color: #ffffff !important;
            border: none !important;
            border-left: 6px solid #22c55e !important;
            border-radius: 8px !important;
            padding: 16px 24px !important;
            color: #1a2b3c !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05) !important;
            display: flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 15px;
        }

        .woocommerce-message a {
            background-color: #0e3780 !important;
            color: #ffffff !important;
            padding: 10px 24px !important;
            border-radius: 6px !important;
            text-decoration: none !important;
            font-weight: 800 !important;
            font-size: 14px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 10px rgba(14, 55, 128, 0.15) !important;
            margin-left: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .woocommerce-message a:hover {
            background-color: #f76a0c !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.3) !important;
        }

        @media (max-width: 768px) {
            .woocommerce-message {
                flex-direction: column !important;
                align-items: stretch !important;
                text-align: center !important;
            }

            .woocommerce-message a {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Compartilhamento */
        .uonix-share-container {
            position: absolute;
            top: 15px;
            left: 15px;
            display: flex;
            justify-content: flex-start;
            z-index: 99;
        }

        .uonix-share-btn {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: transform 0.3s ease, color 0.3s ease;
            text-transform: lowercase;
            box-shadow: none !important;
        }

        .uonix-share-btn svg,
        .uonix-share-item svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
        }

        .uonix-share-btn:hover,
        .uonix-share-btn:focus {
            color: #0e3780;
            transform: scale(1.05);
        }

        .uonix-share-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(14, 55, 128, 0.1);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            padding: 8px 0;
        }

        .uonix-share-dropdown.is-active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .uonix-share-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .uonix-share-item:hover {
            background-color: #f8fafc;
            color: #0e3780;
        }

        .uonix-copy-link-feedback {
            font-size: 12px;
            color: #22c55e;
            margin-left: auto;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .uonix-copy-link-feedback.show {
            opacity: 1;
        }

        /* Balão flutuante WhatsApp */
        .uonix-wa-floating-btn {
            position: fixed;
            bottom: 99px;
            right: 18px;
            width: 60px;
            height: 60px;
            background-color: #25D366;
            color: #ffffff !important;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4);
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            transform: scale(0) translateY(20px);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none !important;
        }

        .uonix-wa-floating-btn svg {
            width: 32px;
            height: 32px;
            fill: currentColor;
        }

        .uonix-wa-floating-btn:hover {
            background-color: #1ebc57;
            transform: scale(1.1) translateY(0);
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.6);
            color: white !important;
        }

        .uonix-wa-floating-btn.is-active {
            opacity: 1;
            visibility: visible;
            transform: scale(1) translateY(0);
        }

        @media (max-width: 768px) {
            .uonix-wa-floating-btn {
                bottom: 20px;
                right: 20px;
                width: 55px;
                height: 55px;
            }

            .uonix-wa-floating-btn svg {
                width: 28px;
                height: 28px;
            }
        }

        /* ---------------------------------------------------------
         * UÔNIX: Modal de Zoom da Galeria (PhotoSwipe Premium)
         * --------------------------------------------------------- */
        /* Backdrop com desfoque e tom azul escuro da marca */
        #uonix-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 25, 47, 0.72);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999998;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        body.uonix-modal-active #uonix-modal-backdrop {
            display: block;
            opacity: 1;
        }

        /* Modal Card Flutuante PhotoSwipe */
        .pswp {
            position: fixed !important;
            z-index: 9999999 !important;
            font-family: inherit !important;
        }

        @media (min-width: 769px) {
            .pswp {
                top: 50% !important;
                left: 50% !important;
                transform: translate(-50%, -50%) !important;
                width: min(92vw, 1100px) !important;
                height: min(88vh, 800px) !important;
                border-radius: 16px !important;
                box-shadow: 0 25px 60px -15px rgba(14, 55, 128, 0.35), 0 0 0 1px rgba(14, 55, 128, 0.08) !important;
                overflow: hidden !important;
            }
            .pswp__bg {
                border-radius: 16px !important;
            }
            .pswp__scroll-wrap {
                border-radius: 16px !important;
            }
        }

        @media (max-width: 768px) {
            .pswp {
                top: 0 !important;
                left: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                border-radius: 0 !important;
            }
            .pswp__top-bar {
                height: 50px !important;
                padding: 0 10px !important;
                gap: 6px !important;
            }
            .uonix-pswp-brand-title {
                gap: 6px !important;
                max-width: 55% !important;
            }
            .uonix-pswp-badge {
                font-size: 10px !important;
                padding: 3px 6px !important;
            }
            .uonix-pswp-prod-name {
                font-size: 12px !important;
                max-width: 140px !important;
            }
            .pswp__counter {
                height: 28px !important;
                line-height: 28px !important;
                padding: 0 8px !important;
                font-size: 11px !important;
            }
            .pswp__button {
                width: 32px !important;
                height: 32px !important;
            }
            .pswp__button--arrow--left,
            .pswp__button--arrow--right {
                width: 38px !important;
                height: 38px !important;
            }
            .pswp__button--arrow--left {
                left: 10px !important;
            }
            .pswp__button--arrow--right {
                right: 10px !important;
            }
            .pswp button.pswp__button--arrow--left::before,
            .pswp button.pswp__button--arrow--right::before,
            button.pswp__button--arrow--left::before,
            button.pswp__button--arrow--right::before {
                width: 18px !important;
                height: 18px !important;
            }
            .pswp__caption__center::after {
                display: none !important;
            }
            .pswp__caption {
                min-height: 38px !important;
                padding: 6px 12px !important;
            }
            .pswp__caption__center {
                font-size: 12px !important;
            }
        }

        /* Fundo branco puro para valorizar imagens com ou sem fundo */
        .pswp__bg {
            background: #ffffff !important;
            opacity: 1 !important;
        }

        /* Sombra natural e sutil na imagem */
        .pswp__img {
            filter: drop-shadow(0 15px 35px rgba(14, 55, 128, 0.08)) !important;
        }

        /* Top Bar / Cabeçalho do Modal */
        .pswp__top-bar {
            height: 60px !important;
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(10px) !important;
            -webkit-backdrop-filter: blur(10px) !important;
            border-bottom: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            padding: 0 20px !important;
            gap: 10px !important;
            opacity: 1 !important;
        }

        /* Branding no Top Bar */
        .uonix-pswp-brand-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-right: auto;
            min-width: 0;
            overflow: hidden;
        }
        .uonix-pswp-badge {
            background: #0e3780;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 4px 8px;
            border-radius: 4px;
            line-height: 1;
            flex-shrink: 0;
        }
        .uonix-pswp-prod-name {
            color: #0e3780;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 480px;
            flex: 1;
            min-width: 0;
        }

        /* Contador de imagens estilo Pill Badge */
        .pswp__counter {
            position: relative !important;
            top: auto !important;
            left: auto !important;
            height: 30px !important;
            line-height: 30px !important;
            padding: 0 12px !important;
            background: #f1f5f9 !important;
            color: #475569 !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            border-radius: 20px !important;
            border: 1px solid #e2e8f0 !important;
            margin: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
        }

        /* Oculta botão share nativo e modais desnecessários */
        .pswp__element--disabled,
        .pswp__button.pswp__button--share,
        button.pswp__button--share,
        .pswp__button.pswp__element--disabled,
        .pswp__share-modal {
            display: none !important;
        }

        /* Oculta setas e contador quando só há 1 imagem */
        .pswp__ui--one-slide .pswp__button--arrow--left,
        .pswp__ui--one-slide .pswp__button--arrow--right,
        .pswp__ui--one-slide .pswp__counter {
            display: none !important;
        }

        /* ==========================================================================
           PhotoSwipe - Botões de Ação e Setas (Foco, Hover e Contraste Blindados)
           ========================================================================== */

        /* Reset forçado de backgrounds e sombras para anular o WooCommerce */
        .pswp button.pswp__button,
        button.pswp__button,
        .pswp .pswp__button {
            background-image: none !important;
            outline: none !important;
            opacity: 1 !important;
        }

        /* Botões do Header: Zoom & Tela Cheia */
        .pswp button.pswp__button--zoom,
        .pswp button.pswp__button--fs,
        button.pswp__button--zoom,
        button.pswp__button--fs {
            width: 38px !important;
            height: 38px !important;
            background-color: #f1f5f9 !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06) !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            margin: 0 !important;
        }

        /* Ícones Zoom e Fullscreen - Normal */
        .pswp button.pswp__button--zoom::before,
        button.pswp__button--zoom::before {
            content: '' !important;
            display: block !important;
            width: 17px !important;
            height: 17px !important;
            background-color: #0e3780 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3Cline x1='11' y1='8' x2='11' y2='14'%3E%3C/line%3E%3Cline x1='8' y1='11' x2='14' y2='11'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3Cline x1='11' y1='8' x2='11' y2='14'%3E%3C/line%3E%3Cline x1='8' y1='11' x2='14' y2='11'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
        }

        /* Ícone Zoom com Menos (-) quando o zoom estiver aplicado no modal */
        .pswp.pswp--zoomed-in button.pswp__button--zoom::before,
        .pswp--zoomed-in .pswp button.pswp__button--zoom::before,
        .pswp--zoomed-in button.pswp__button--zoom::before {
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3Cline x1='8' y1='11' x2='14' y2='11'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3Cline x1='8' y1='11' x2='14' y2='11'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
        }

        .pswp button.pswp__button--fs::before,
        button.pswp__button--fs::before {
            content: '' !important;
            display: block !important;
            width: 16px !important;
            height: 16px !important;
            background-color: #0e3780 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3'%3E%3C/path%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3'%3E%3C/path%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
        }

        /* Hover e Focus no Zoom & Fullscreen: Fundo azul institucional Uônix, Ícone BRANCO nítido */
        .pswp button.pswp__button--zoom:hover,
        .pswp button.pswp__button--zoom:focus,
        .pswp button.pswp__button--zoom:active,
        button.pswp__button--zoom:hover,
        button.pswp__button--zoom:focus,
        button.pswp__button--zoom:active,
        .pswp button.pswp__button--fs:hover,
        .pswp button.pswp__button--fs:focus,
        .pswp button.pswp__button--fs:active,
        button.pswp__button--fs:hover,
        button.pswp__button--fs:focus,
        button.pswp__button--fs:active {
            background-color: #0e3780 !important;
            background: #0e3780 !important;
            border-color: #0e3780 !important;
            transform: translateY(-1px) scale(1.05) !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.28) !important;
        }

        .pswp button.pswp__button--zoom:hover::before,
        .pswp button.pswp__button--zoom:focus::before,
        .pswp button.pswp__button--zoom:active::before,
        button.pswp__button--zoom:hover::before,
        button.pswp__button--zoom:focus::before,
        button.pswp__button--zoom:active::before,
        .pswp button.pswp__button--fs:hover::before,
        .pswp button.pswp__button--fs:focus::before,
        .pswp button.pswp__button--fs:active::before,
        button.pswp__button--fs:hover::before,
        button.pswp__button--fs:focus::before,
        button.pswp__button--fs:active::before {
            background-color: #ffffff !important;
            background: #ffffff !important;
        }

        /* Foco persistente no botão de Zoom/Fullscreen pós-clique sem mouse por cima */
        .pswp button.pswp__button--zoom:focus:not(:hover),
        .pswp button.pswp__button--fs:focus:not(:hover),
        button.pswp__button--zoom:focus:not(:hover),
        button.pswp__button--fs:focus:not(:hover) {
            background-color: #f1f5f9 !important;
            background: #f1f5f9 !important;
            border-color: #0e3780 !important;
            transform: none !important;
            box-shadow: 0 0 0 3px rgba(14, 55, 128, 0.2) !important;
        }
        .pswp button.pswp__button--zoom:focus:not(:hover)::before,
        .pswp button.pswp__button--fs:focus:not(:hover)::before,
        button.pswp__button--zoom:focus:not(:hover)::before,
        button.pswp__button--fs:focus:not(:hover)::before {
            background-color: #0e3780 !important;
            background: #0e3780 !important;
        }

        /* Botão Fechar */
        .pswp button.pswp__button--close,
        button.pswp__button--close {
            width: 38px !important;
            height: 38px !important;
            background-color: #fef2f2 !important;
            background: #fef2f2 !important;
            border: 1px solid #fee2e2 !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            margin: 0 !important;
        }

        .pswp button.pswp__button--close::before,
        button.pswp__button--close::before {
            content: '' !important;
            display: block !important;
            width: 15px !important;
            height: 15px !important;
            background-color: #dc2626 !important;
            background: #dc2626 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='18' y1='6' x2='6' y2='18'%3E%3C/line%3E%3Cline x1='6' y1='6' x2='18' y2='18'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='18' y1='6' x2='6' y2='18'%3E%3C/line%3E%3Cline x1='6' y1='6' x2='18' y2='18'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
        }

        .pswp button.pswp__button--close:hover,
        .pswp button.pswp__button--close:focus,
        .pswp button.pswp__button--close:active,
        button.pswp__button--close:hover,
        button.pswp__button--close:focus,
        button.pswp__button--close:active {
            background-color: #dc2626 !important;
            background: #dc2626 !important;
            border-color: #dc2626 !important;
            transform: translateY(-1px) scale(1.05) !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3) !important;
        }

        .pswp button.pswp__button--close:hover::before,
        .pswp button.pswp__button--close:focus::before,
        .pswp button.pswp__button--close:active::before,
        button.pswp__button--close:hover::before,
        button.pswp__button--close:focus::before,
        button.pswp__button--close:active::before {
            background-color: #ffffff !important;
            background: #ffffff !important;
        }

        /* Foco persistente no botão de Fechar pós-clique sem mouse por cima */
        .pswp button.pswp__button--close:focus:not(:hover),
        button.pswp__button--close:focus:not(:hover) {
            background-color: #fef2f2 !important;
            background: #fef2f2 !important;
            border-color: #dc2626 !important;
            transform: none !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2) !important;
        }
        .pswp button.pswp__button--close:focus:not(:hover)::before,
        button.pswp__button--close:focus:not(:hover)::before {
            background-color: #dc2626 !important;
            background: #dc2626 !important;
        }

        /* Setas de Navegação Lateral */
        .pswp button.pswp__button--arrow--left,
        .pswp button.pswp__button--arrow--right,
        button.pswp__button--arrow--left,
        button.pswp__button--arrow--right,
        .pswp__button--arrow--left,
        .pswp__button--arrow--right {
            position: absolute !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 48px !important;
            height: 48px !important;
            border-radius: 50% !important;
            background-color: #ffffff !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 4px 14px rgba(14, 55, 128, 0.12) !important;
            z-index: 10000010 !important;
            opacity: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .pswp button.pswp__button--arrow--left,
        button.pswp__button--arrow--left {
            left: 20px !important;
        }

        .pswp button.pswp__button--arrow--right,
        button.pswp__button--arrow--right {
            right: 20px !important;
        }

        /* Ícones das Setas - Normal */
        .pswp button.pswp__button--arrow--left::before,
        button.pswp__button--arrow--left::before {
            content: '' !important;
            display: block !important;
            position: static !important;
            top: auto !important;
            left: auto !important;
            width: 22px !important;
            height: 22px !important;
            background-color: #0e3780 !important;
            background: #0e3780 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='15 18 9 12 15 6'%3E%3C/polyline%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='15 18 9 12 15 6'%3E%3C/polyline%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
        }

        .pswp button.pswp__button--arrow--right::before,
        button.pswp__button--arrow--right::before {
            content: '' !important;
            display: block !important;
            position: static !important;
            top: auto !important;
            left: auto !important;
            width: 22px !important;
            height: 22px !important;
            background-color: #0e3780 !important;
            background: #0e3780 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='9 18 15 12 9 6'%3E%3C/polyline%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='9 18 15 12 9 6'%3E%3C/polyline%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
        }

        /* Hover, Focus e Active das Setas: Fundo azul institucional Uônix, Ícone BRANCO puro */
        .pswp button.pswp__button--arrow--left:hover,
        .pswp button.pswp__button--arrow--left:focus,
        .pswp button.pswp__button--arrow--left:active,
        button.pswp__button--arrow--left:hover,
        button.pswp__button--arrow--left:focus,
        button.pswp__button--arrow--left:active,
        .pswp button.pswp__button--arrow--right:hover,
        .pswp button.pswp__button--arrow--right:focus,
        .pswp button.pswp__button--arrow--right:active,
        button.pswp__button--arrow--right:hover,
        button.pswp__button--arrow--right:focus,
        button.pswp__button--arrow--right:active {
            background-color: #0e3780 !important;
            background: #0e3780 !important;
            border-color: #0e3780 !important;
            transform: translateY(-50%) scale(1.08) !important;
            box-shadow: 0 6px 18px rgba(14, 55, 128, 0.3) !important;
        }

        .pswp button.pswp__button--arrow--left:hover::before,
        .pswp button.pswp__button--arrow--left:focus::before,
        .pswp button.pswp__button--arrow--left:active::before,
        button.pswp__button--arrow--left:hover::before,
        button.pswp__button--arrow--left:focus::before,
        button.pswp__button--arrow--left:active::before,
        .pswp button.pswp__button--arrow--right:hover::before,
        .pswp button.pswp__button--arrow--right:focus::before,
        .pswp button.pswp__button--arrow--right:active::before,
        button.pswp__button--arrow--right:hover::before,
        button.pswp__button--arrow--right:focus::before,
        button.pswp__button--arrow--right:active::before {
            background-color: #ffffff !important;
            background: #ffffff !important;
        }

        /* Estado de foco persistente pós-clique sem mouse por cima */
        .pswp button.pswp__button--arrow--left:focus:not(:hover),
        .pswp button.pswp__button--arrow--right:focus:not(:hover),
        button.pswp__button--arrow--left:focus:not(:hover),
        button.pswp__button--arrow--right:focus:not(:hover) {
            background-color: #f1f5f9 !important;
            background: #f1f5f9 !important;
            border-color: #0e3780 !important;
            transform: translateY(-50%) !important;
            box-shadow: 0 0 0 3px rgba(14, 55, 128, 0.2) !important;
        }
        .pswp button.pswp__button--arrow--left:focus:not(:hover)::before,
        .pswp button.pswp__button--arrow--right:focus:not(:hover)::before,
        button.pswp__button--arrow--left:focus:not(:hover)::before,
        button.pswp__button--arrow--right:focus:not(:hover)::before {
            background-color: #0e3780 !important;
            background: #0e3780 !important;
        }

        /* Rodapé / Legenda */
        .pswp__caption {
            position: absolute !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            min-height: 46px !important;
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(8px) !important;
            -webkit-backdrop-filter: blur(8px) !important;
            border-top: 1px solid #f1f5f9 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 8px 20px !important;
        }

        .pswp__caption__center {
            text-align: center !important;
            color: #0e3780 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            line-height: 1.4 !important;
            max-width: 780px !important;
            margin: 0 auto !important;
        }

        .pswp__caption__center::after {
            content: "  •  Dica: clique para aproximar ou use a rolagem do mouse";
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
        }

        /* Botão Trigger de Zoom na Galeria do Produto (Lupa Normal, Centralizada) */
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger,
        a.woocommerce-product-gallery__trigger {
            position: absolute !important;
            top: 16px !important;
            right: 16px !important;
            left: auto !important;
            bottom: auto !important;
            z-index: 99 !important;
            background: #ffffff !important;
            background-color: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 50% !important;
            width: 42px !important;
            height: 42px !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.1) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
            cursor: pointer !important;
            text-decoration: none !important;
            overflow: hidden !important;
            padding: 0 !important;
            margin: 0 !important;
            outline: none !important;
            text-indent: 0 !important;
            box-sizing: border-box !important;
            font-size: 0 !important;
            line-height: 0 !important;
        }

        /* Anula totalmente qualquer elemento nativo ou interferência do WooCommerce */
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger::before,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger::before,
        a.woocommerce-product-gallery__trigger::before {
            display: none !important;
            content: none !important;
            width: 0 !important;
            height: 0 !important;
            border: none !important;
            position: static !important;
        }
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger img,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger .emoji,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger span {
            display: none !important;
        }

        /* Ícone de Lupa Normal (Sem o +, Perfeitamente Centralizado) */
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger::after,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger::after,
        a.woocommerce-product-gallery__trigger::after {
            content: '' !important;
            display: block !important;
            position: static !important;
            top: auto !important;
            left: auto !important;
            right: auto !important;
            bottom: auto !important;
            transform: none !important;
            margin: 0 auto !important;
            width: 20px !important;
            height: 20px !important;
            min-width: 20px !important;
            min-height: 20px !important;
            background-color: #0e3780 !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7.5'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.5' y2='16.5'%3E%3C/line%3E%3C/svg%3E") no-repeat center / contain !important;
            transition: background-color 0.2s ease !important;
            box-sizing: border-box !important;
            border-radius: 0 !important;
            background-image: none !important;
        }

        /* Hover e Focus no Botão de Lupa */
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:hover,
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:focus,
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:active,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:hover,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:focus,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:active,
        a.woocommerce-product-gallery__trigger:hover,
        a.woocommerce-product-gallery__trigger:focus,
        a.woocommerce-product-gallery__trigger:active {
            background: #0e3780 !important;
            background-color: #0e3780 !important;
            border-color: #0e3780 !important;
            transform: scale(1.08) !important;
            box-shadow: 0 6px 16px rgba(14, 55, 128, 0.25) !important;
            outline: none !important;
        }
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:hover::after,
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:focus::after,
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:active::after,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:hover::after,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:focus::after,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:active::after,
        a.woocommerce-product-gallery__trigger:hover::after,
        a.woocommerce-product-gallery__trigger:focus::after,
        a.woocommerce-product-gallery__trigger:active::after {
            background-color: #ffffff !important;
            transform: none !important;
        }

        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:focus:not(:hover),
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:focus:not(:hover),
        a.woocommerce-product-gallery__trigger:focus:not(:hover) {
            background: #ffffff !important;
            background-color: #ffffff !important;
            border-color: #0e3780 !important;
            box-shadow: 0 0 0 3px rgba(14, 55, 128, 0.2) !important;
            transform: none !important;
        }
        .woocommerce div.product div.images .woocommerce-product-gallery__trigger:focus:not(:hover)::after,
        .woocommerce-product-gallery .woocommerce-product-gallery__trigger:focus:not(:hover)::after,
        a.woocommerce-product-gallery__trigger:focus:not(:hover)::after {
            background-color: #0e3780 !important;
            transform: none !important;
        }
    </style>

    <script id="uonix-product-premium-js">
    jQuery(document).ready(function($) {
        var waLink = <?php echo wp_json_encode($link_wa); ?>;

        /* Fabricante abaixo do título */
        if (!$('.uonix-manufacturer-row').length) {
            var $marcaSpan = $('.product_meta').find('span').filter(function() {
                return $(this).text().indexOf('Marca:') !== -1;
            }).first();

            if ($marcaSpan.length) {
                var $marcaContent = $marcaSpan.find('a').first().clone();

                if (!$marcaContent.length) {
                    var marcaText = $marcaSpan.text().replace('Marca:', '').trim();
                    $marcaContent = $('<span></span>').text(marcaText);
                }

                var $newRow = $('<div class="uonix-manufacturer-row">Fabricante: </div>').append($marcaContent);
                $newRow.insertAfter('.product_title');
            }
        }

        /* Loading no resumo */
        var $summary = $('.summary.entry-summary');

        if ($summary.length && !$('.uonix-loading-overlay').length) {
            $summary.append('<div class="uonix-loading-overlay"><div class="uonix-spinner"></div></div>');
        }

        function uonixForceReset() {
            $('.reset_variations').trigger('click');
            $('form.variations_form select').val('').change();
            $('.reset_variations').css('display', 'none');
        }

        if ($('.woocommerce-message').length > 0) {
            uonixForceReset();
        }

        $('.single_add_to_cart_button').on('click', function() {
            if (!$(this).is('.disabled')) {
                $('.uonix-loading-overlay').addClass('is-active');

                setTimeout(function() {
                    uonixForceReset();
                    $('.uonix-loading-overlay').removeClass('is-active');
                }, 1200);
            }
        });

        $(document.body).on('added_to_cart', function() {
            $('.uonix-loading-overlay').removeClass('is-active');
            uonixForceReset();
        });

        /* Compartilhamento */
        var $gallery = $('.woocommerce-product-gallery');

        if ($gallery.length && !$('.uonix-share-container').length) {
            var productUrl = encodeURIComponent(window.location.href);
            var productTitle = encodeURIComponent($('h1.product_title').text());

            var iconShare = '<svg viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>';
            var iconWhatsApp = '<svg viewBox="0 0 448 512"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157z"/></svg>';
            var iconFacebook = '<svg viewBox="0 0 320 512"><path d="M279.14 288l14.22-92.66h-88.91v-60.13c0-25.35 12.42-50.06 52.24-50.06h40.42V6.26S260.43 0 225.36 0c-73.22 0-121.08 44.38-121.08 124.72v70.62H22.89V288h81.39v224h100.17V288z"/></svg>';
            var iconLinkedin = '<svg viewBox="0 0 448 512"><path d="M100.28 448H7.4V148.9h92.88zM53.79 108.1C24.09 108.1 0 83.5 0 53.8a53.79 53.79 0 0 1 107.58 0c0 29.7-24.1 54.3-53.79 54.3zM447.9 448h-92.68V302.4c0-34.7-.7-79.2-48.29-79.2-48.29 0-55.69 37.7-55.69 76.7V448h-92.78V148.9h89.08v40.8h1.3c12.4-23.5 42.69-48.3 87.88-48.3 94 0 111.28 61.9 111.28 142.3V448z"/></svg>';
            var iconX = '<svg viewBox="0 0 512 512"><path d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L273 181.4 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/></svg>';
            var iconCopy = '<svg viewBox="0 0 448 512"><path d="M320 448v40c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V120c0-13.3 10.7-24 24-24h72v296c0 30.9 25.1 56 56 56h168zm0-344V0H152c-13.3 0-24 10.7-24 24v368c0 13.3 10.7 24 24 24h272c13.3 0 24-10.7 24-24V128H344c-13.2 0-24-10.8-24-24zm121-31L375 6.1c-6-6-14.1-9.4-22.6-9.4H352v104h104v-5.2c0-8.5-3.4-16.6-9.4-22.5z"/></svg>';

            var shareHtml = `
                <div class="uonix-share-container">
                    <button class="uonix-share-btn" aria-label="Compartilhar produto">
                        ${iconShare} compartilhar
                    </button>

                    <div class="uonix-share-dropdown">
                        <a href="https://api.whatsapp.com/send?text=${productTitle}%20-%20${productUrl}" target="_blank" rel="noopener" class="uonix-share-item">${iconWhatsApp} WhatsApp</a>
                        <a href="https://www.linkedin.com/sharing/share-offsite/?url=${productUrl}" target="_blank" rel="noopener" class="uonix-share-item">${iconLinkedin} LinkedIn</a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=${productUrl}" target="_blank" rel="noopener" class="uonix-share-item">${iconFacebook} Facebook</a>
                        <a href="https://twitter.com/intent/tweet?text=${productTitle}&url=${productUrl}" target="_blank" rel="noopener" class="uonix-share-item">${iconX} X (Twitter)</a>
                        <button class="uonix-share-item uonix-copy-link-action">${iconCopy} Copiar Link <span class="uonix-copy-link-feedback">Copiado!</span></button>
                    </div>
                </div>
            `;

            $gallery.prepend(shareHtml);

            var $shareBtn = $('.uonix-share-btn');
            var $dropdown = $('.uonix-share-dropdown');

            $shareBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $dropdown.toggleClass('is-active');
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('.uonix-share-container').length) {
                    $dropdown.removeClass('is-active');
                }
            });

            $('.uonix-copy-link-action').on('click', function(e) {
                e.preventDefault();

                function showFeedback() {
                    var $feedback = $('.uonix-copy-link-feedback');
                    $feedback.addClass('show');

                    setTimeout(function() {
                        $feedback.removeClass('show');
                        $dropdown.removeClass('is-active');
                    }, 1500);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(window.location.href).then(showFeedback);
                } else {
                    var tempInput = document.createElement('input');
                    tempInput.value = window.location.href;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                    showFeedback();
                }
            });
        }

        /* Balão flutuante WhatsApp */
        var waBoxEl = document.querySelector('.uonix-seller-box');

        if (waBoxEl && !$('.uonix-wa-floating-btn').length) {
            var waIconSvg = $('.uonix-wa-btn').find('svg').prop('outerHTML') || '';

            var $floatingBtn = $('<a href="' + waLink + '" target="_blank" rel="noopener" class="uonix-wa-floating-btn" aria-label="Fale com um Especialista no WhatsApp">' + waIconSvg + '</a>');
            $('body').append($floatingBtn);

            if ('IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function(entries) {
                    if (!entries[0].isIntersecting && entries[0].boundingClientRect.top < 0) {
                        $floatingBtn.addClass('is-active');
                    } else {
                        $floatingBtn.removeClass('is-active');
                    }
                }, { threshold: 0 });

                observer.observe(waBoxEl);
            }
        }

        /* ---------------------------------------------------------
         * UÔNIX: Modal PhotoSwipe - Backdrop e Título da Marca
         * --------------------------------------------------------- */
        var $modalBackdrop = $('#uonix-modal-backdrop');
        if (!$modalBackdrop.length) {
            $modalBackdrop = $('<div id="uonix-modal-backdrop"></div>');
            $('body').append($modalBackdrop);
        }

        $modalBackdrop.on('click', function(e) {
            e.preventDefault();
            var $closeBtn = $('.pswp__button--close');
            if ($closeBtn.length) {
                $closeBtn.trigger('click');
            }
        });

        function uonixSyncPswpModal() {
            var $pswp = $('.pswp');
            if ($pswp.length && $pswp.hasClass('pswp--open')) {
                $('body').addClass('uonix-modal-active');

                var $topBar = $pswp.find('.pswp__top-bar');
                if ($topBar.length && !$topBar.find('.uonix-pswp-brand-title').length) {
                    var prodTitle = $('h1.product_title').text().trim() || 'Produto Uônix';
                    var brandTitleHtml = '<div class="uonix-pswp-brand-title">' +
                        '<span class="uonix-pswp-badge">UÔNIX</span>' +
                        '<span class="uonix-pswp-prod-name">' + prodTitle + '</span>' +
                        '</div>';
                    $topBar.prepend(brandTitleHtml);
                }
            } else {
                $('body').removeClass('uonix-modal-active');
            }
        }

        var pswpElem = document.querySelector('.pswp');
        if (pswpElem && 'MutationObserver' in window) {
            var pswpObserver = new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].attributeName === 'class') {
                        uonixSyncPswpModal();
                        break;
                    }
                }
            });
            pswpObserver.observe(pswpElem, { attributes: true });
        }

        function uonixCleanZoomTrigger() {
            var $trigger = $('.woocommerce-product-gallery__trigger');
            if ($trigger.length) {
                $trigger.find('img, .emoji, span').remove();
            }
        }
        uonixCleanZoomTrigger();
        setTimeout(uonixCleanZoomTrigger, 100);
        setTimeout(uonixCleanZoomTrigger, 400);
        setTimeout(uonixCleanZoomTrigger, 1000);

        $(document).on('click', '.woocommerce-product-gallery__trigger, .woocommerce-product-gallery__image a', function() {
            setTimeout(uonixSyncPswpModal, 50);
            setTimeout(uonixSyncPswpModal, 250);
        });

        $(window).on('keydown', function(e) {
            if (e.key === 'Escape') {
                setTimeout(uonixSyncPswpModal, 100);
            }
        });
    });
    </script>

    <?php
}


