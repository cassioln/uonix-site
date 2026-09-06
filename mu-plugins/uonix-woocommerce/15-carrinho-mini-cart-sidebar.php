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
        array_unshift( $item_data, array(
            'key'   => 'Marca',
            'value' => $marca,
        ) );
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

        /* Estrutura do drawer e eliminação de scroll horizontal. */
        .wc-block-components-drawer__screen-overlay {
            z-index: 3000000 !important;
        }

        .wc-block-components-drawer__screen-overlay .wc-block-components-drawer,
        .wc-block-components-drawer__screen-overlay .wc-block-mini-cart__drawer,
        .wc-block-mini-cart__drawer,
        .wc-block-components-drawer {
            z-index: 3000001 !important;
            overflow-x: hidden !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-drawer__content,
        .wc-block-mini-cart__drawer .wc-block-mini-cart__template-part,
        .wc-block-mini-cart__drawer .wp-block-woocommerce-mini-cart-contents,
        .wc-block-mini-cart__drawer .wc-block-mini-cart__items,
        .wc-block-mini-cart__drawer .wc-block-mini-cart__products-table {
            overflow-x: hidden !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .wc-block-mini-cart__title {
            margin-bottom: 0 !important;
            padding-bottom: 15px !important;
            border-bottom: 7px solid #003399 !important;
            color: #003399 !important;
            font-size: 30px !important;
            font-weight: 800 !important;
        }

        /* Tabela do mini-cart sem scroll horizontal */
        .wc-block-mini-cart__drawer table.wc-block-cart-items,
        .wc-block-mini-cart__drawer table.wc-block-mini-cart-items {
            width: 100% !important;
            max-width: 100% !important;
            display: block !important;
            box-sizing: border-box !important;
            overflow-x: hidden !important;
        }

        .wc-block-mini-cart__drawer table.wc-block-cart-items tbody,
        .wc-block-mini-cart__drawer table.wc-block-mini-cart-items tbody {
            width: 100% !important;
            display: block !important;
            box-sizing: border-box !important;
        }

        /* Linha do item no mini-cart */
        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row,
        tr.wc-block-cart-items__row {
            display: grid !important;
            grid-template-columns: 85px minmax(0, 1fr) !important;
            column-gap: 16px !important;
            align-items: flex-start !important;
            padding: 16px 0 !important;
            border-bottom: 1px solid #eef2f7 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
            transition: background-color 0.2s ease !important;
        }

        /* Oculta colunas e preços desnecessários no mini-cart */
        .wc-block-cart-item__prices,
        .wc-block-cart-item__total,
        .wc-block-mini-cart__footer-subtotal,
        .wp-block-woocommerce-mini-cart-title-items-counter-block,
        .wc-block-mini-cart__drawer td.wc-block-cart-item__total {
            display: none !important;
        }

        /* Coluna da Imagem */
        .wc-block-mini-cart__drawer .wc-block-cart-item__image {
            grid-column: 1 !important;
            grid-row: 1 / span 2 !important;
            width: 85px !important;
            max-width: 85px !important;
            min-width: 85px !important;
            padding: 0 !important;
            margin: 0 !important;
            display: flex !important;
            align-items: flex-start !important;
            justify-content: center !important;
            align-self: flex-start !important;
        }

        .wc-block-mini-cart__drawer .wc-block-cart-item__image > img {
            display: none !important;
        }

        /* Moldura da Imagem com Aspect Ratio 4:3 */
        .wc-block-mini-cart__drawer .wc-block-cart-item__image a {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            aspect-ratio: 4 / 3 !important;
            background: #f8fafc !important;
            border: 1px solid #eef2f7 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            padding: 6px !important;
            box-sizing: border-box !important;
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease !important;
        }

        .wc-block-mini-cart__drawer .wc-block-cart-item__image a img {
            width: 100% !important;
            height: 100% !important;
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: contain !important;
            background: transparent !important;
            display: block !important;
            margin: 0 auto !important;
            transition: transform 0.3s ease !important;
        }

        /* Efeito de Hover Acionado pela Linha Inteira (.wc-block-cart-items__row:hover) */
        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a,
        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:focus-within .wc-block-cart-item__image a,
        .wc-block-mini-cart__drawer .wc-block-cart-item__image a:hover {
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.08) !important;
            transform: scale(1.03) !important;
        }

        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a img,
        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:focus-within .wc-block-cart-item__image a img,
        .wc-block-mini-cart__drawer .wc-block-cart-item__image a:hover img {
            transform: scale(1.08) !important;
        }

        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:hover a.wc-block-components-product-name,
        .wc-block-mini-cart__drawer tr.wc-block-cart-items__row:focus-within a.wc-block-components-product-name,
        .wc-block-mini-cart__drawer a.wc-block-components-product-name:hover {
            color: #f76a0c !important;
        }

        /* Coluna do Produto */
        .wc-block-mini-cart__drawer .wc-block-cart-item__product {
            grid-column: 2 !important;
            grid-row: 1 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            min-width: 0 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .wc-block-mini-cart__drawer .wc-block-cart-item__wrap {
            justify-content: center !important;
            width: 100% !important;
            min-width: 0 !important;
        }

        /* Título do produto no mini-cart */
        .wc-block-mini-cart__drawer a.wc-block-components-product-name,
        a.wc-block-components-product-name {
            display: block !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            color: #0e3780 !important;
            text-decoration: none !important;
            transition: color 0.2s ease !important;
            line-height: 1.25 !important;
            margin-bottom: 2px !important;
            word-break: break-word !important;
        }

        /* Oculta resumo/descricao curta dentro do mini-cart */
        .wc-block-mini-cart__drawer .wc-block-components-product-metadata__description,
        .wc-block-mini-cart__drawer [data-wp-watch="callbacks.itemShortDescription"],
        .wc-block-mini-cart__drawer .wc-block-components-product-badge {
            display: none !important;
        }

        /* Metadados e Variações do Produto no mini-cart: Marca em cima, Variações embaixo separadas por | */
        .wc-block-mini-cart__drawer .wc-block-components-product-metadata {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            row-gap: 3px !important;
            margin-top: 4px !important;
            font-size: 13px !important;
            line-height: 1.35 !important;
            color: #4b5563 !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-product-details {
            display: inline-flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-product-details[hidden],
        .wc-block-mini-cart__drawer .wc-block-components-product-details:empty {
            display: none !important;
        }

        /* Garante que o bloco de detalhes da Marca fique no topo na ordem flex */
        .wc-block-mini-cart__drawer .wc-block-components-product-details:has(.wc-block-components-product-details__marca),
        .wc-block-mini-cart__drawer .wc-block-components-product-details.uonix-brand-first {
            order: -1 !important;
        }

        /* Substitui / por | entre os atributos de variação no mini-cart */
        .wc-block-mini-cart__drawer .wc-block-components-product-details span[aria-hidden="true"] {
            font-size: 0 !important;
            display: inline-block !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-product-details span[aria-hidden="true"]:not([hidden])::after {
            content: "|" !important;
            font-size: 13px !important;
            margin: 0 6px !important;
            color: #9ca3af !important;
            font-weight: 400 !important;
            user-select: none !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-product-details__name {
            color: #64748b !important;
            font-weight: 600 !important;
            margin-right: 3px !important;
        }

        .wc-block-mini-cart__drawer .wc-block-components-product-details__value {
            color: #334155 !important;
        }

        .wc-block-components-product-details {
            margin: 0 !important;
        }

        /* Quantidade e botão de remover */
        .wc-block-mini-cart__drawer .wc-block-cart-item__quantity,
        .wc-block-cart-item__quantity {
            grid-column: 2 !important;
            grid-row: 2 !important;
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 12px !important;
            min-height: auto !important;
            padding: 8px 0 0 0 !important;
            margin: 0 !important;
            min-width: 0 !important;
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
            padding: 16px 15px !important;
            border-top: 1px dashed #eef2f7;
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

        /* Rodapé do mini carrinho e Botões de Ação */
        .wc-block-mini-cart__footer-actions {
            display: flex !important;
            gap: 10px !important;
            padding: 15px !important;
            box-sizing: border-box !important;
            width: 100% !important;
        }

        .wc-block-mini-cart__footer-cart {
            flex: 0 0 42% !important;
            border: 2px solid #003399 !important;
            border-radius: 6px !important;
            background: transparent !important;
            color: #003399 !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            padding: 10px 8px !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            box-sizing: border-box !important;
        }

        .wc-block-mini-cart__footer-cart:hover {
            border-color: #f76a0c !important;
            color: #f76a0c !important;
            background: rgba(247, 106, 12, 0.05) !important;
        }

        .wc-block-mini-cart__footer-cart .wc-block-components-button__text {
            font-size: 0 !important;
        }

        .wc-block-mini-cart__footer-cart .wc-block-components-button__text::before {
            content: "Ver mais Produtos" !important;
            font-size: 13px !important;
            font-weight: 700 !important;
        }

        .wc-block-mini-cart__footer-checkout {
            flex: 1 1 58% !important;
            border-radius: 6px !important;
            background: #003399 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            padding: 10px 8px !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 4px 12px rgba(0, 51, 153, 0.2) !important;
            box-sizing: border-box !important;
        }

        .wc-block-mini-cart__footer-checkout:hover {
            background: #f76a0c !important;
            box-shadow: 0 4px 15px rgba(247, 106, 12, 0.3) !important;
        }

        .wc-block-mini-cart__footer-checkout .wc-block-components-button__text {
            font-size: 0 !important;
        }

        .wc-block-mini-cart__footer-checkout .wc-block-components-button__text::before {
            content: "Solicitar Orçamento  \2192" !important;
            font-size: 13px !important;
            font-weight: 700 !important;
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
                var $metadata = $row.find('.wc-block-components-product-metadata');
                if (!$metadata.length) {
                    return;
                }

                var $brandDetail = $metadata.children('.wc-block-components-product-details').filter(function () {
                    return $(this).find('.wc-block-components-product-details__marca').length > 0 ||
                           $(this).text().toLowerCase().indexOf('marca:') !== -1;
                }).first();

                if ($brandDetail.length) {
                    $brandDetail.addClass('uonix-brand-first');
                    if ($brandDetail.index() !== 0) {
                        $brandDetail.prependTo($metadata);
                    }
                }
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


