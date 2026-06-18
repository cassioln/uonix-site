<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Carrinho - sidebar do mini-cart e ajustes visuais.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 3784-4202 do export original.
// -----------------------------------------------------------------------------
/**
 * SIDEBAR CARRINHO MOBILE / DESKTOP
 */
/**
 * UONIX: Sticky Cart Pro (V50 - Ajuste do icone de remover)
 * ----------------------------------------------------------------
 * - Adiciona a marca do produto no mini carrinho.
 * - Ajusta layout, imagens, quantidade, botao de remover e rodape.
 * - Redireciona os botoes do mini carrinho para produtos e cotacao.
 * - Remove a duplicacao do icone de lixeira usando apenas o SVG nativo.
 */

// Adiciona "Marca" aos metadados do item exibidos no mini carrinho.
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
    $product_id = $cart_item['product_id'];
    $marca      = $cart_item['data']->get_attribute( 'pa_marca' );

    if ( empty( $marca ) ) {
        $brands = wp_get_post_terms( $product_id, 'product_brand' );

        if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
            $marca = $brands[0]->name;
        }
    }

    if ( ! empty( $marca ) ) {
        $item_data[] = array(
            'key'   => 'Marca',
            'value' => $marca,
        );
    }

    return $item_data;
}, 10, 2 );

// Injeta os ajustes visuais e os pequenos comportamentos do mini carrinho.
add_action( 'wp_footer', function() {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }
    ?>
    <style id="uonix-sticky-cart-css">
        /* Icone do carrinho no menu. */
        .uonix-menu-cart {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #003399;
            text-decoration: none;
            background: transparent;
            line-height: 1;
        }

        .uonix-menu-cart svg {
            display: block;
            width: 45px;
            height: 45px;
            fill: currentColor;
        }

        .uonix-menu-cart-badge {
            position: absolute !important;
            top: -3px !important;
            left: 25px !important;
            z-index: 101 !important;
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            width: 22px !important;
            height: 22px !important;
            border-radius: 50% !important;
            background-color: #f76a0c !important;
            color: #ffffff !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            line-height: 1 !important;
        }

        .uonix-menu-cart-badge.is-active {
            display: flex !important;
        }

        /* Estrutura do drawer e dos itens. */
        tr.wc-block-cart-items__row {
            grid-template-columns: 30% 70% !important;
            align-items: stretch !important;
            border-bottom: 1px solid #eeeeee !important;
        }

        .wc-block-mini-cart__title {
            margin-bottom: 0 !important;
            padding-bottom: 15px !important;
            border-bottom: 7px solid #003399 !important;
            color: #003399 !important;
            font-size: 30px !important;
            font-weight: 800 !important;
        }

        a.wc-block-components-product-name {
            display: block !important;
            margin-bottom: 0 !important;
            line-height: normal !important;
        }

        .wc-block-cart-item__prices,
        .wc-block-cart-item__total,
        .wc-block-mini-cart__footer-subtotal,
        .wp-block-woocommerce-mini-cart-title-items-counter-block {
            display: none !important;
        }

        .wc-block-cart-item__product {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            justify-content: center !important;
            height: 100% !important;
        }

        .wc-block-cart-item__wrap {
            justify-content: center !important;
            width: 100% !important;
        }

        /* Imagem do produto: usa apenas a imagem dentro do link. */
        .wc-block-mini-cart__drawer.wc-block-components-drawer .wc-block-cart-item__image {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding-right: 10px !important;
        }

        .wc-block-mini-cart__drawer.wc-block-components-drawer .wc-block-cart-item__image > img {
            display: none !important;
        }

        .wc-block-mini-cart__drawer.wc-block-components-drawer .wc-block-cart-item__image a {
            display: block !important;
            width: 100% !important;
        }

        .wc-block-mini-cart__drawer.wc-block-components-drawer .wc-block-cart-item__image a img {
            display: block !important;
            width: 100% !important;
            aspect-ratio: 1 / 1 !important;
            border-radius: 4px;
            background-color: #ffffff !important;
            object-fit: contain !important;
        }

        /* Marca e metadados do produto. */
        .uonix-marca-final {
            display: block !important;
            color: #f76a0c !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            text-transform: uppercase !important;
        }

        .uonix-marca-final .wc-block-components-product-details__name {
            display: none !important;
        }

        .wc-block-components-product-details {
            margin: 0 !important;
        }

        /* Quantidade e botao de remover. */
        .wc-block-cart-item__quantity {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 12px !important;
            min-height: 38px !important;
            margin-top: 15px !important;
        }

        .wc-block-components-quantity-selector {
            flex: 0 0 100px !important;
            max-width: 100px !important;
            margin: 0 !important;
        }

        .wc-block-cart-item__remove-link {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex: 0 0 34px !important;
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid transparent !important;
            border-radius: 4px !important;
            background: transparent !important;
            color: #6b7a90 !important;
            line-height: 1 !important;
            text-decoration: none !important;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease !important;
        }

        .wc-block-cart-item__remove-link::before {
            content: none !important;
            display: none !important;
        }

        .wc-block-cart-item__remove-link svg {
            display: block !important;
            width: 20px !important;
            height: 20px !important;
            margin: 0 !important;
            color: currentColor !important;
            fill: currentColor !important;
        }

        .wc-block-cart-item__remove-link:hover,
        .wc-block-cart-item__remove-link:focus-visible {
            border-color: rgba(204, 0, 0, 0.22) !important;
            background-color: rgba(204, 0, 0, 0.08) !important;
            color: #cc0000 !important;
            text-decoration: none !important;
            outline: none !important;
        }

        /* Link para adicionar mais produtos. */
        .uonix-add-more-wrapper {
            padding: 20px 15px !important;
            border-top: 1px dashed #eeeeee;
            text-align: center !important;
        }

        .uonix-add-more-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #003399 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            text-decoration: none !important;
            text-transform: uppercase;
            transition: color 0.3s ease;
        }

        .uonix-add-more-link:hover {
            color: #f76a0c !important;
        }

        .uonix-add-more-link .plus-icon {
            font-size: 18px;
            line-height: 1;
        }

        /* Rodape do mini carrinho. */
        .wc-block-mini-cart__footer-actions {
            display: flex !important;
            gap: 10px !important;
            padding: 15px !important;
        }

        .wc-block-mini-cart__footer-cart {
            flex: 0 0 40% !important;
            border: 2px solid var(--global-palette-btn-bg) !important;
            border-radius: 3px !important;
            background: transparent !important;
            color: #003399 !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
        }

        .wc-block-mini-cart__footer-cart:hover {
            border: 2.5px solid var(--global-palette-btn-bg-hover) !important;
            color: #0e3780 !important;
        }

        .wc-block-mini-cart__footer-cart .wc-block-components-button__text {
            font-size: 0 !important;
        }

        .wc-block-mini-cart__footer-cart .wc-block-components-button__text::before {
            content: "Ver Produtos";
            font-size: 13px;
        }

        .wc-block-mini-cart__footer-checkout {
            flex: 0 0 calc(60% - 10px) !important;
            border-radius: 3px !important;
            background: var(--global-palette-btn-bg) !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
        }

        .wc-block-mini-cart__footer-checkout:hover {
            background: var(--global-palette-btn-bg-hover) !important;
        }

        .wc-block-mini-cart__footer-checkout .wc-block-components-button__text {
            font-size: 0 !important;
        }

        .wc-block-mini-cart__footer-checkout .wc-block-components-button__text::before {
            content: "Ir para Revisão  \2192";
            font-size: 14px;
        }

        .wc-block-components-button__text {
            font-size: 15px !important;
        }

        /* Estado de carrinho vazio. */
        .wc-block-mini-cart__shopping-button .wc-block-components-button__text {
            font-size: 0 !important;
        }

        .wc-block-mini-cart__shopping-button .wc-block-components-button__text::before {
            content: "Ver Catálogo de Produtos";
            font-size: 16px;
        }
    </style>

    <script id="uonix-sticky-cart-js">
    (function ($) {
        'use strict';

        function normalizeProductNames() {
            $('.wc-block-components-product-name').each(function () {
                var $element = $(this);
                var html = $element.html();

                if (!html || (html.indexOf('<br') === -1 && html.indexOf('&lt;br') === -1)) {
                    return;
                }

                $element.html(
                    html
                        .replace(/<br\s*[/]?>/gi, ' - ')
                        .replace(/&lt;br\s*[/]?&gt;/gi, ' - ')
                );
            });
        }

        function moveBrandMetadata() {
            $('.wc-block-cart-items__row').each(function () {
                var $row = $(this);
                var $wrap = $row.find('.wc-block-cart-item__wrap');
                var brandFound = false;

                $row.find('.wc-block-components-product-details div, .wc-block-components-product-details li').each(function () {
                    var $detail = $(this);

                    if ($detail.text().toLowerCase().indexOf('marca:') === -1) {
                        return;
                    }

                    if (brandFound) {
                        $detail.remove();
                        return;
                    }

                    $detail
                        .addClass('uonix-marca-final')
                        .removeAttr('hidden')
                        .css('display', 'block')
                        .prependTo($wrap);

                    brandFound = true;
                });
            });
        }

        function addMoreProductsLink() {
            if ($('.uonix-add-more-wrapper').length > 0) {
                return;
            }

            var addMoreHtml = [
                '<div class="uonix-add-more-wrapper">',
                    '<a href="/produtos/#catalogo-produtos" class="uonix-add-more-link">',
                        '<span class="plus-icon">+</span>',
                        'Adicione mais produtos ao orçamento',
                    '</a>',
                '</div>'
            ].join('');

            $(addMoreHtml).insertAfter('.wc-block-mini-cart__products-table');
        }

        function updateCartLinks() {
            $('.wc-block-mini-cart__footer-cart').attr('href', '/produtos');
            $('.wc-block-mini-cart__footer-checkout').attr('href', '/cotacao/');
            $('.wc-block-mini-cart__shopping-button').first().attr('href', '/produtos/#catalogo-produtos');
        }

        function formatUonixSidebar() {
            normalizeProductNames();
            moveBrandMetadata();
            addMoreProductsLink();
            updateCartLinks();
        }

        $(function () {
            var observer = new MutationObserver(formatUonixSidebar);

            formatUonixSidebar();
            observer.observe(document.body, { childList: true, subtree: true });

            $(document).on('click', '.uonix-menu-cart', function (event) {
                event.preventDefault();
                $('.wc-block-mini-cart__button').first().trigger('click');
            });
        });
    })(jQuery);
    </script>
    <?php
}, 100 );


