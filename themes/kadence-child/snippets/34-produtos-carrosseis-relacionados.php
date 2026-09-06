<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Produtos - carrosseis relacionados.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 11760-12160 do export original.
// -----------------------------------------------------------------------------
/**
 * Carrossel de Produtos Relacionados (Carrinho)
 */
/**
 * UÔNIX: Motor Autoplay e Setas de Navegação (Carrossel Carrinho)
 */
add_action('wp_footer', 'uonix_carrossel_produtos_relacionados_script');

function uonix_carrossel_produtos_relacionados_script() {
    // Roda na página do carrinho ou na página de cotação
    if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_page' ) && is_page( 'cotacao' ) ) ) {
        ?>
        <style id="uonix-carrinho-relacionados-style">
        /* ==========================================================================
           UÔNIX: PRODUTOS RELACIONADOS NO CARRINHO / COTAÇÃO (.pordutos-relacionados-carrinho)
           PARIDADE VISUAL TOTAL COM A VITRINE / CATÁLOGO DE PRODUTOS
           ========================================================================== */

        /* 1. Container e Carrossel */
        .pordutos-relacionados-carrinho .wp-block-woocommerce-product-collection {
            position: relative !important;
            padding: 0 10px !important;
        }

        .pordutos-relacionados-carrinho ul.wc-block-product-template {
            display: flex !important;
            flex-wrap: nowrap !important;
            overflow-x: auto !important;
            gap: 20px !important;
            padding: 10px 5px 25px 5px !important;
            margin: 0 !important;
            list-style: none !important;
            scroll-behavior: smooth !important;
            scroll-snap-type: x mandatory !important;
            -webkit-overflow-scrolling: touch !important;
            align-items: stretch !important;
        }

        .pordutos-relacionados-carrinho ul.wc-block-product-template::-webkit-scrollbar {
            display: none !important;
        }

        .pordutos-relacionados-carrinho ul.wc-block-product-template {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        /* 2. Card do Produto (Grid de 4 produtos no Desktop) */
        .pordutos-relacionados-carrinho li.wc-block-product {
            flex: 0 0 calc(25% - 15px) !important;
            max-width: calc(25% - 15px) !important;
            min-width: 200px !important;
            scroll-snap-align: start !important;
            background: #ffffff !important;
            border: 1px solid #eef2f7 !important;
            border-radius: 10px !important;
            overflow: hidden !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            flex-direction: column !important;
            padding: 0 !important;
            box-shadow: 0 4px 15px rgba(14, 55, 128, 0.02) !important;
            box-sizing: border-box !important;
            position: relative !important;
            height: auto !important;
        }

        @media (max-width: 1200px) and (min-width: 860px) {
            .pordutos-relacionados-carrinho li.wc-block-product {
                flex: 0 0 calc(33.333% - 14px) !important;
                max-width: calc(33.333% - 14px) !important;
            }
        }

        @media (max-width: 859px) and (min-width: 600px) {
            .pordutos-relacionados-carrinho li.wc-block-product {
                flex: 0 0 calc(50% - 10px) !important;
                max-width: calc(50% - 10px) !important;
            }
        }

        @media (max-width: 599px) {
            .pordutos-relacionados-carrinho li.wc-block-product {
                flex: 0 0 85% !important;
                max-width: 85% !important;
                min-width: unset !important;
            }
        }

        .pordutos-relacionados-carrinho li.wc-block-product:hover {
            border-color: #f76a0c !important;
            box-shadow: 0 12px 30px rgba(14, 55, 128, 0.08) !important;
            transform: translateY(-4px) !important;
        }

        /* 3. Área da Imagem (Aspect Ratio 4:3) */
        .pordutos-relacionados-carrinho .wc-block-components-product-image {
            position: relative !important;
            width: 100% !important;
            aspect-ratio: 4 / 3 !important;
            background: #f8fafc !important;
            border-bottom: 1px solid #eef2f7 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .pordutos-relacionados-carrinho .wc-block-components-product-image a {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            height: 100% !important;
            position: relative !important;
        }

        .pordutos-relacionados-carrinho .wc-block-components-product-image img {
            width: 100% !important;
            height: 100% !important;
            max-height: none !important;
            object-fit: contain !important;
            padding: 15px !important;
            transition: transform 0.4s ease !important;
            background: transparent !important;
            margin: 0 auto !important;
            box-sizing: border-box !important;
        }

        .pordutos-relacionados-carrinho li.wc-block-product:hover .wc-block-components-product-image img {
            transform: scale(1.08) !important;
        }

        /* 4. Badge do Fabricante / Marca */
        .pordutos-relacionados-carrinho .uonix-loop-brand-badge {
            position: absolute !important;
            top: 12px !important;
            left: 12px !important;
            background: #ffffff !important;
            color: #0e3780 !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            padding: 5px 10px !important;
            border-radius: 4px !important;
            z-index: 2 !important;
            letter-spacing: 0.5px !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
            pointer-events: none !important;
        }

        /* 5. Conteúdo e Título */
        .pordutos-relacionados-carrinho .wp-block-post-title {
            font-size: 18px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            margin: 16px 0 12px 0 !important;
            text-align: left !important;
            text-wrap: balance !important;
            padding: 0 20px !important;
        }

        .pordutos-relacionados-carrinho .wp-block-post-title a {
            color: #0e3780 !important;
            text-decoration: none !important;
            transition: color 0.3s ease !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
        }

        .pordutos-relacionados-carrinho li.wc-block-product:hover .wp-block-post-title a {
            color: #f76a0c !important;
        }

        /* 6. Oculta Preço e Resumo */
        .pordutos-relacionados-carrinho .wp-block-woocommerce-product-price,
        .pordutos-relacionados-carrinho .wc-block-components-product-price {
            display: none !important;
        }

        /* 7. Botão de Ação */
        .pordutos-relacionados-carrinho .wp-block-woocommerce-product-button {
            margin-top: auto !important;
            margin-bottom: 20px !important;
            padding: 0 20px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .pordutos-relacionados-carrinho .wc-block-components-product-button__button,
        .pordutos-relacionados-carrinho .wp-block-button__link {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            background: #0e3780 !important;
            color: #ffffff !important;
            padding: 12px 15px !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: none !important;
            border-radius: 6px !important;
            transition: all 0.3s ease !important;
            box-sizing: border-box !important;
            text-align: center !important;
            line-height: 1.2 !important;
            white-space: normal !important;
            text-decoration: none !important;
            cursor: pointer !important;
        }

        .pordutos-relacionados-carrinho li.wc-block-product:hover .wc-block-components-product-button__button,
        .pordutos-relacionados-carrinho li.wc-block-product:hover .wp-block-button__link,
        .pordutos-relacionados-carrinho .wc-block-components-product-button__button:hover,
        .pordutos-relacionados-carrinho .wp-block-button__link:hover {
            background: #f76a0c !important;
            color: #ffffff !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.25) !important;
        }

        /* 8. Setas de Navegação */
        .pordutos-relacionados-carrinho .uonix-carousel-nav {
            position: absolute !important;
            top: 54% !important;
            transform: translateY(-50%) !important;
            width: 44px !important;
            height: 44px !important;
            background-color: #0e3780 !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 50% !important;
            cursor: pointer !important;
            z-index: 10 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
            transition: all 0.3s ease !important;
            font-size: 18px !important;
            line-height: 1 !important;
        }

        .pordutos-relacionados-carrinho .uonix-carousel-nav:hover {
            background-color: #f76a0c !important;
            transform: translateY(-50%) scale(1.1) !important;
        }

        .pordutos-relacionados-carrinho .uonix-carousel-prev {
            left: -22px !important;
        }

        .pordutos-relacionados-carrinho .uonix-carousel-next {
            right: -22px !important;
        }

        @media (max-width: 767px) {
            .pordutos-relacionados-carrinho .uonix-carousel-nav {
                width: 36px !important;
                height: 36px !important;
                font-size: 14px !important;
                top: 52% !important;
            }
            .pordutos-relacionados-carrinho .uonix-carousel-prev {
                left: -8px !important;
            }
            .pordutos-relacionados-carrinho .uonix-carousel-next {
                right: -8px !important;
            }
        }

        /* ==========================================================================
           UÔNIX: ITENS DO ORÇAMENTO / CARRINHO (TABELA PRINCIPAL)
           EXPANSÃO HORIZONTAL TOTAL E AUMENTO DAS IMAGENS DOS PRODUTOS
           ========================================================================== */
        /* Oculta a sidebar vazia do bloco WooCommerce Cart e expande a área principal */
        .wp-block-woocommerce-cart .wc-block-components-sidebar-layout .wc-block-cart__sidebar {
            display: none !important;
        }

        .wp-block-woocommerce-cart .wc-block-components-sidebar-layout .wc-block-cart__main {
            width: 100% !important;
            max-width: 100% !important;
            flex: 1 1 100% !important;
        }

        .wp-block-woocommerce-cart table.wc-block-cart-items,
        table.wc-block-cart-items {
            width: 100% !important;
            max-width: 100% !important;
            display: block !important;
        }

        .wp-block-woocommerce-cart table.wc-block-cart-items tbody,
        table.wc-block-cart-items tbody {
            width: 100% !important;
            display: block !important;
        }

        .wp-block-woocommerce-cart tr.wc-block-cart-items__row,
        table.wc-block-cart-items tr.wc-block-cart-items__row,
        .wc-block-cart tr.wc-block-cart-items__row {
            display: grid !important;
            grid-template-columns: 130px 1fr !important;
            column-gap: 20px !important;
            align-items: flex-start !important;
            padding: 20px 0 !important;
            border-bottom: 1px solid #eef2f7 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Coluna da Imagem */
        .wp-block-woocommerce-cart .wc-block-cart-item__image,
        table.wc-block-cart-items td.wc-block-cart-item__image {
            width: 130px !important;
            max-width: 130px !important;
            min-width: 130px !important;
            padding: 0 !important;
            margin: 0 !important;
            grid-column: 1 !important;
            grid-row: 1 / span 2 !important;
            display: flex !important;
            align-items: flex-start !important;
            justify-content: center !important;
            align-self: flex-start !important;
        }

        /* Link e Moldura da Imagem */
        .wp-block-woocommerce-cart .wc-block-cart-item__image a,
        table.wc-block-cart-items td.wc-block-cart-item__image a {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            aspect-ratio: 4 / 3 !important;
            background: #f8fafc !important;
            border: 1px solid #eef2f7 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            padding: 8px !important;
            box-sizing: border-box !important;
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease !important;
        }

        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a,
        table.wc-block-cart-items tr.wc-block-cart-items__row:hover td.wc-block-cart-item__image a,
        .wc-block-cart tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a,
        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:focus-within .wc-block-cart-item__image a,
        .wp-block-woocommerce-cart .wc-block-cart-item__image a:hover,
        table.wc-block-cart-items td.wc-block-cart-item__image a:hover {
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.08) !important;
            transform: scale(1.03) !important;
        }

        /* Imagem */
        .wp-block-woocommerce-cart .wc-block-cart-item__image img,
        table.wc-block-cart-items td.wc-block-cart-item__image img {
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

        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a img,
        table.wc-block-cart-items tr.wc-block-cart-items__row:hover td.wc-block-cart-item__image a img,
        .wc-block-cart tr.wc-block-cart-items__row:hover .wc-block-cart-item__image a img,
        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:focus-within .wc-block-cart-item__image a img,
        .wp-block-woocommerce-cart .wc-block-cart-item__image a:hover img,
        table.wc-block-cart-items td.wc-block-cart-item__image a:hover img {
            transform: scale(1.08) !important;
        }

        /* Coluna do Produto */
        .wp-block-woocommerce-cart .wc-block-cart-item__product,
        table.wc-block-cart-items td.wc-block-cart-item__product {
            grid-column: 2 !important;
            grid-row: 1 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
        }

        .wp-block-woocommerce-cart .wc-block-cart-item__quantity,
        table.wc-block-cart-items td.wc-block-cart-item__quantity {
            grid-column: 2 !important;
            grid-row: 2 !important;
            padding: 8px 0 0 0 !important;
        }

        /* Título do produto no carrinho */
        .wp-block-woocommerce-cart a.wc-block-components-product-name,
        table.wc-block-cart-items a.wc-block-components-product-name,
        .wc-block-cart a.wc-block-components-product-name {
            font-size: 16px !important;
            font-weight: 700 !important;
            color: #0e3780 !important;
            text-decoration: none !important;
            transition: color 0.2s ease !important;
        }

        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:hover a.wc-block-components-product-name,
        table.wc-block-cart-items tr.wc-block-cart-items__row:hover a.wc-block-components-product-name,
        .wc-block-cart tr.wc-block-cart-items__row:hover a.wc-block-components-product-name,
        .wp-block-woocommerce-cart tr.wc-block-cart-items__row:focus-within a.wc-block-components-product-name,
        .wp-block-woocommerce-cart a.wc-block-components-product-name:hover {
            color: #f76a0c !important;
        }

        /* Metadados e Variações do Produto lado a lado com divisor | (Página /cotacao) */
        .wp-block-woocommerce-cart .wc-block-components-product-metadata,
        .wc-block-cart .wc-block-components-product-metadata {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            row-gap: 4px !important;
            column-gap: 0 !important;
            margin-top: 4px !important;
            font-size: 14px !important;
            line-height: 1.4 !important;
            color: #4b5563 !important;
        }

        .wp-block-woocommerce-cart .wc-block-components-product-details,
        .wc-block-cart .wc-block-components-product-details {
            display: inline-flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .wp-block-woocommerce-cart .wc-block-components-product-details[hidden],
        .wp-block-woocommerce-cart .wc-block-components-product-details:empty,
        .wc-block-cart .wc-block-components-product-details[hidden],
        .wc-block-cart .wc-block-components-product-details:empty {
            display: none !important;
        }

        .wp-block-woocommerce-cart .wc-block-components-product-details + .wc-block-components-product-details:not([hidden]):not(:empty)::before,
        .wc-block-cart .wc-block-components-product-details + .wc-block-components-product-details:not([hidden]):not(:empty)::before {
            content: "|" !important;
            display: inline-block !important;
            margin: 0 8px !important;
            color: #9ca3af !important;
            font-weight: 400 !important;
            user-select: none !important;
        }

        /* Substitui / por | entre os atributos de variação na página /cotacao */
        .wp-block-woocommerce-cart .wc-block-components-product-details span[aria-hidden="true"],
        .wc-block-cart .wc-block-components-product-details span[aria-hidden="true"] {
            font-size: 0 !important;
            display: inline-block !important;
        }

        .wp-block-woocommerce-cart .wc-block-components-product-details span[aria-hidden="true"]:not([hidden])::after,
        .wc-block-cart .wc-block-components-product-details span[aria-hidden="true"]:not([hidden])::after {
            content: "|" !important;
            font-size: 14px !important;
            margin: 0 8px !important;
            color: #9ca3af !important;
            font-weight: 400 !important;
            user-select: none !important;
        }

        /* Responsividade para telas menores */
        @media (max-width: 600px) {
            .wp-block-woocommerce-cart tr.wc-block-cart-items__row,
            table.wc-block-cart-items tr.wc-block-cart-items__row,
            .wc-block-cart tr.wc-block-cart-items__row {
                grid-template-columns: 95px 1fr !important;
                column-gap: 12px !important;
            }
            .wp-block-woocommerce-cart .wc-block-cart-item__image,
            table.wc-block-cart-items td.wc-block-cart-item__image {
                width: 95px !important;
                max-width: 95px !important;
                min-width: 95px !important;
            }
        }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.pordutos-relacionados-carrinho .wp-block-woocommerce-product-collection') || document.querySelector('.wp-block-woocommerce-product-collection');
            const carousel = container ? container.querySelector('ul.wc-block-product-template') : null;
            
            if (container && carousel) {
                // 1. INJEÇÃO DOS BADGES DE MARCA
                const cards = container.querySelectorAll('li.wc-block-product');
                cards.forEach(function(card) {
                    const imgLink = card.querySelector('.wc-block-components-product-image a');
                    if (imgLink && !imgLink.querySelector('.uonix-loop-brand-badge')) {
                        let brandName = '';
                        for (let i = 0; i < card.classList.length; i++) {
                            const cls = card.classList[i];
                            if (cls.startsWith('product_brand-') || cls.startsWith('pa_fabricante-')) {
                                const slug = cls.replace('product_brand-', '').replace('pa_fabricante-', '');
                                if (slug === 'uonix') brandName = 'UÔNIX';
                                else if (slug === 'walsywa') brandName = 'WALSYWA';
                                else if (slug === 'ancora') brandName = 'ÂNCORA';
                                else if (slug === 'hard') brandName = 'HARD';
                                else if (slug === 'fischer') brandName = 'FISCHER';
                                else if (slug === 'ciser') brandName = 'CISER';
                                else brandName = slug.toUpperCase();
                                break;
                            }
                        }
                        if (brandName) {
                            const badge = document.createElement('span');
                            badge.className = 'uonix-loop-brand-badge';
                            badge.textContent = brandName;
                            imgLink.appendChild(badge);
                        }
                    }
                });

                // 2. INJEÇÃO DOS BOTÕES
                if (!container.querySelector('.uonix-carousel-prev')) {
                    const btnPrev = document.createElement('button');
                    btnPrev.className = 'uonix-carousel-nav uonix-carousel-prev';
                    btnPrev.innerHTML = '&#10094;'; // Ícone <
                    btnPrev.setAttribute('aria-label', 'Anterior');

                    const btnNext = document.createElement('button');
                    btnNext.className = 'uonix-carousel-nav uonix-carousel-next';
                    btnNext.innerHTML = '&#10095;'; // Ícone >
                    btnNext.setAttribute('aria-label', 'Próximo');

                    container.appendChild(btnPrev);
                    container.appendChild(btnNext);

                    // Visibilidade das setas conforme overflow
                    const updateArrowVisibility = () => {
                        const hasOverflow = carousel.scrollWidth > carousel.clientWidth + 10;
                        btnPrev.style.display = hasOverflow ? 'flex' : 'none';
                        btnNext.style.display = hasOverflow ? 'flex' : 'none';
                    };
                    setTimeout(updateArrowVisibility, 100);
                    window.addEventListener('resize', updateArrowVisibility);

                    let isPaused = false;
                    let resumeTimeout = null;

                    const pauseCarousel = () => {
                        isPaused = true;
                        if (resumeTimeout) clearTimeout(resumeTimeout);
                    };
                    const playCarousel = () => {
                        if (resumeTimeout) clearTimeout(resumeTimeout);
                        resumeTimeout = setTimeout(() => { isPaused = false; }, 1500);
                    };

                    carousel.addEventListener('mouseenter', pauseCarousel);
                    carousel.addEventListener('mouseleave', playCarousel);
                    carousel.addEventListener('touchstart', pauseCarousel);
                    carousel.addEventListener('touchend', playCarousel);
                    
                    btnPrev.addEventListener('mouseenter', pauseCarousel);
                    btnNext.addEventListener('mouseenter', pauseCarousel);
                    btnPrev.addEventListener('mouseleave', playCarousel);
                    btnNext.addEventListener('mouseleave', playCarousel);

                    // 4. LÓGICA DE ROLAGEM
                    const scrollCarousel = (direction) => {
                        const card = carousel.querySelector('li');
                        if (!card) return;
                        
                        const step = card.offsetWidth + 20; // Largura do card + gap
                        
                        if (direction === 'next') {
                            if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth - 25) {
                                carousel.scrollTo({ left: 0, behavior: 'smooth' });
                            } else {
                                carousel.scrollBy({ left: step, behavior: 'smooth' });
                            }
                        } else {
                            if (carousel.scrollLeft <= 15) {
                                carousel.scrollTo({ left: carousel.scrollWidth, behavior: 'smooth' });
                            } else {
                                carousel.scrollBy({ left: -step, behavior: 'smooth' });
                            }
                        }
                    };

                    // Eventos de clique nas setas com pausa prolongada de 6s
                    btnNext.addEventListener('click', (e) => {
                        e.preventDefault();
                        pauseCarousel();
                        scrollCarousel('next');
                        resumeTimeout = setTimeout(() => { isPaused = false; }, 6000);
                    });

                    btnPrev.addEventListener('click', (e) => {
                        e.preventDefault();
                        pauseCarousel();
                        scrollCarousel('prev');
                        resumeTimeout = setTimeout(() => { isPaused = false; }, 6000);
                    });

                    // 5. MOTOR AUTOPLAY (4.5 Segundos)
                    setInterval(function() {
                        if (!isPaused && carousel.scrollWidth > carousel.clientWidth + 10) {
                            scrollCarousel('next');
                        }
                    }, 4500);
                }
            }
        });
        </script>
        <?php
    }
}

/**
 * Autoplay para Produtos Relacionados (Página do Produto)
 */
/**
 * UÔNIX: CSS e Motor Autoplay para Produtos Relacionados, Upsells e Cross-Sells
 * Garante paridade visual idêntica à grade de produtos do catálogo.
 * Ativa o carrossel apenas em telas onde não cabem todos os produtos (<= 1024px).
 */
add_action('wp_footer', 'uonix_produtos_relacionados_carrossel_mobile');

function uonix_produtos_relacionados_carrossel_mobile() {
    // Executa na página de produto ou no carrinho (onde cross-sells e relacionados são exibidos)
    if ( ( function_exists( 'is_product' ) && is_product() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) {
        ?>
        <style id="uonix-relacionados-upsells-style">
        /* ==========================================================================
           UÔNIX: SEÇÕES DE PRODUTOS RELACIONADOS, UPSELLS E CROSS-SELLS
           ========================================================================== */

        /* 1. Títulos das Seções ("Produtos relacionados", "Você pode estar interessado…") */
        section.related.products > h2,
        section.up-sells.products > h2,
        section.upsells.products > h2,
        .cross-sells > h2,
        .cross-sells.products > h2 {
            font-size: 22px !important;
            color: #0e3780 !important;
            font-weight: 800 !important;
            text-transform: uppercase;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 30px !important;
            position: relative;
        }
        /* Linha laranja de detalhe abaixo do título */
        section.related.products > h2::after,
        section.up-sells.products > h2::after,
        section.upsells.products > h2::after,
        .cross-sells > h2::after,
        .cross-sells.products > h2::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 80px;
            height: 2px;
            background-color: #f76a0c;
        }

        /* 2. Carrossel Exclusivo para Tablet e Mobile (Touch Swipe) */
        @media (max-width: 1024px) {
            section.related.products ul.products,
            section.up-sells.products ul.products,
            section.upsells.products ul.products,
            .cross-sells ul.products {
                display: flex !important;
                flex-wrap: nowrap !important;
                overflow-x: auto !important;
                scroll-snap-type: x mandatory; /* Efeito de "travar" no card */
                scroll-behavior: smooth;
                gap: 15px;
                padding-bottom: 20px !important;
                -webkit-overflow-scrolling: touch; /* Rolar suave no iOS/Android */
                align-items: stretch !important; /* Mantém a altura igual */
            }
            
            /* Esconder barra de rolagem nativa */
            section.related.products ul.products::-webkit-scrollbar,
            section.up-sells.products ul.products::-webkit-scrollbar,
            section.upsells.products ul.products::-webkit-scrollbar,
            .cross-sells ul.products::-webkit-scrollbar {
                display: none;
            }
            section.related.products ul.products,
            section.up-sells.products ul.products,
            section.upsells.products ul.products,
            .cross-sells ul.products {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }

            /* Tablet: 3 na tela */
            section.related.products ul.products li.product,
            section.up-sells.products ul.products li.product,
            section.upsells.products ul.products li.product,
            .cross-sells ul.products li.product {
                flex: 0 0 calc(33.333% - 10px) !important; 
                scroll-snap-align: start;
                margin: 0 !important;
                max-width: none !important;
            }
        }
        
        @media (max-width: 768px) {
            /* Mobile: 1 na tela (mostrando o canto do próximo) */
            section.related.products ul.products li.product,
            section.up-sells.products ul.products li.product,
            section.upsells.products ul.products li.product,
            .cross-sells ul.products li.product {
                flex: 0 0 calc(85%) !important; 
            }
        }

        /* ==========================================================================
           UÔNIX: ESTILO PREMIUM DOS CARDS DE PRODUTOS
           PARIDADE VISUAL TOTAL COM A VITRINE / CATÁLOGO DE PRODUTOS
           ========================================================================== */

        /* 1. O Card do Produto */
        .related.products ul.products li.product,
        .up-sells.products ul.products li.product,
        .upsells.products ul.products li.product,
        .cross-sells ul.products li.product {
            background: #ffffff !important;
            border: 1px solid #eef2f7 !important;
            border-radius: 10px !important;
            overflow: hidden !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            flex-direction: column !important;
            padding: 0 !important;
            box-shadow: 0 4px 15px rgba(14, 55, 128, 0.02) !important;
            box-sizing: border-box !important;
            position: relative !important;
            height: auto !important;
        }

        .related.products ul.products li.product:hover,
        .up-sells.products ul.products li.product:hover,
        .upsells.products ul.products li.product:hover,
        .cross-sells ul.products li.product:hover {
            border-color: #f76a0c !important;
            box-shadow: 0 12px 30px rgba(14, 55, 128, 0.08) !important;
            transform: translateY(-4px) !important;
        }

        /* 2. Área da Imagem (Aspect Ratio 4:3) */
        .related.products ul.products li.product .woocommerce-loop-image-link,
        .up-sells.products ul.products li.product .woocommerce-loop-image-link,
        .upsells.products ul.products li.product .woocommerce-loop-image-link,
        .cross-sells ul.products li.product .woocommerce-loop-image-link {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            transition: none !important;
            aspect-ratio: 4 / 3 !important;
            background: #f8fafc !important;
            border-bottom: 1px solid #eef2f7 !important;
            overflow: hidden !important;
            padding: 0 !important;
            text-align: center !important;
        }

        .related.products ul.products li.product img,
        .up-sells.products ul.products li.product img,
        .upsells.products ul.products li.product img,
        .cross-sells ul.products li.product img {
            width: 100% !important;
            height: 100% !important;
            max-height: none !important;
            object-fit: contain !important;
            padding: 15px !important;
            transition: transform 0.4s ease !important;
            background: transparent !important;
            margin: 0 auto !important;
        }

        .related.products ul.products li.product:hover img,
        .up-sells.products ul.products li.product:hover img,
        .upsells.products ul.products li.product:hover img,
        .cross-sells ul.products li.product:hover img {
            transform: scale(1.08) !important;
        }

        /* 3. Badge do Fabricante / Marca */
        .related.products ul.products li.product .uonix-loop-brand-badge,
        .up-sells.products ul.products li.product .uonix-loop-brand-badge,
        .upsells.products ul.products li.product .uonix-loop-brand-badge,
        .cross-sells ul.products li.product .uonix-loop-brand-badge {
            position: absolute !important;
            top: 12px !important;
            left: 12px !important;
            background: #ffffff !important;
            color: #0e3780 !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            padding: 5px 10px !important;
            border-radius: 4px !important;
            z-index: 2 !important;
            letter-spacing: 0.5px !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        }

        /* 4. Área de Detalhes */
        .related.products ul.products li.product .product-details,
        .up-sells.products ul.products li.product .product-details,
        .upsells.products ul.products li.product .product-details,
        .cross-sells ul.products li.product .product-details {
            padding: 1rem 1rem 1.5rem !important;
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
            background: #ffffff !important;
            position: relative !important;
            transition: transform 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95) !important;
        }

        .related.products ul.products li.product:hover .product-details,
        .related.products ul.products li.product:hover .entry-content-wrap,
        .up-sells.products ul.products li.product:hover .product-details,
        .up-sells.products ul.products li.product:hover .entry-content-wrap,
        .upsells.products ul.products li.product:hover .product-details,
        .upsells.products ul.products li.product:hover .entry-content-wrap,
        .cross-sells ul.products li.product:hover .product-details,
        .cross-sells ul.products li.product:hover .entry-content-wrap {
            transform: translateY(-2rem) !important;
        }

        /* 5. Título do Produto */
        .related.products ul.products li.product .woocommerce-loop-product__title,
        .up-sells.products ul.products li.product .woocommerce-loop-product__title,
        .upsells.products ul.products li.product .woocommerce-loop-product__title,
        .cross-sells ul.products li.product .woocommerce-loop-product__title {
            font-size: 18px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            margin: 0 0 12px 0 !important;
            text-align: left !important;
            text-wrap: balance !important;
            padding: 0 !important;
        }

        .related.products ul.products li.product .woocommerce-loop-product__title a,
        .up-sells.products ul.products li.product .woocommerce-loop-product__title a,
        .upsells.products ul.products li.product .woocommerce-loop-product__title a,
        .cross-sells ul.products li.product .woocommerce-loop-product__title a {
            color: #0e3780 !important;
            text-decoration: none !important;
            transition: color 0.3s ease !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
        }

        .related.products ul.products li.product:hover .woocommerce-loop-product__title a,
        .up-sells.products ul.products li.product:hover .woocommerce-loop-product__title a,
        .upsells.products ul.products li.product:hover .woocommerce-loop-product__title a,
        .cross-sells ul.products li.product:hover .woocommerce-loop-product__title a {
            color: #f76a0c !important;
        }

        /* 6. Oculta Preço e Resumo */
        .related.products ul.products li.product .price,
        .up-sells.products ul.products li.product .price,
        .upsells.products ul.products li.product .price,
        .cross-sells ul.products li.product .price,
        .related.products ul.products li.product .product-excerpt,
        .up-sells.products ul.products li.product .product-excerpt,
        .upsells.products ul.products li.product .product-excerpt,
        .cross-sells ul.products li.product .product-excerpt {
            display: none !important;
        }

        /* 7. Envoltório do Botão (Oculto no repouso, desliza para cima no hover) */
        .related.products ul.products li.product .product-action-wrap,
        .up-sells.products ul.products li.product .product-action-wrap,
        .upsells.products ul.products li.product .product-action-wrap,
        .cross-sells ul.products li.product .product-action-wrap {
            position: absolute !important;
            bottom: -2rem !important;
            left: 0 !important;
            right: 0 !important;
            width: auto !important;
            padding: 0 1rem !important;
            margin-top: 0 !important;
            opacity: 0 !important;
            visibility: visible !important;
            pointer-events: none !important;
            transition: opacity 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95), bottom 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95) !important;
            z-index: 5 !important;
        }

        .related.products ul.products li.product:hover .product-action-wrap,
        .related.products ul.products li.product:focus-within .product-action-wrap,
        .up-sells.products ul.products li.product:hover .product-action-wrap,
        .up-sells.products ul.products li.product:focus-within .product-action-wrap,
        .upsells.products ul.products li.product:hover .product-action-wrap,
        .upsells.products ul.products li.product:focus-within .product-action-wrap,
        .cross-sells ul.products li.product:hover .product-action-wrap,
        .cross-sells ul.products li.product:focus-within .product-action-wrap {
            bottom: -0.8rem !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        /* 8. Botão "Ver Detalhes" */
        .related.products ul.products li.product .button,
        .related.products ul.products li.product .uonix-details-btn,
        .up-sells.products ul.products li.product .button,
        .up-sells.products ul.products li.product .uonix-details-btn,
        .upsells.products ul.products li.product .button,
        .upsells.products ul.products li.product .uonix-details-btn,
        .cross-sells ul.products li.product .button,
        .cross-sells ul.products li.product .uonix-details-btn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            background: #f76a0c !important;
            color: #ffffff !important;
            padding: 12px 15px !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: none !important;
            border-radius: 6px !important;
            transition: all 0.3s ease !important;
            box-sizing: border-box !important;
            text-align: center !important;
            line-height: 1.2 !important;
            white-space: normal !important;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.25) !important;
            text-decoration: none !important;
        }

        .related.products ul.products li.product:hover .uonix-details-btn,
        .related.products ul.products li.product:hover .button,
        .up-sells.products ul.products li.product:hover .uonix-details-btn,
        .up-sells.products ul.products li.product:hover .button,
        .upsells.products ul.products li.product:hover .uonix-details-btn,
        .upsells.products ul.products li.product:hover .button,
        .cross-sells ul.products li.product:hover .uonix-details-btn,
        .cross-sells ul.products li.product:hover .button,
        .related.products ul.products li.product .uonix-details-btn:hover,
        .related.products ul.products li.product .button:hover,
        .up-sells.products ul.products li.product .uonix-details-btn:hover,
        .up-sells.products ul.products li.product .button:hover,
        .upsells.products ul.products li.product .uonix-details-btn:hover,
        .upsells.products ul.products li.product .button:hover,
        .cross-sells ul.products li.product .uonix-details-btn:hover,
        .cross-sells ul.products li.product .button:hover {
            background: #e05e07 !important;
            color: #ffffff !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 20px rgba(247, 106, 12, 0.35) !important;
        }

        /* ==========================================================================
           BOTÕES DE NAVEGAÇÃO DO CARROSSEL (SETAS)
           ========================================================================== */
        .related.products,
        .up-sells.products,
        .upsells.products,
        .cross-sells { 
            position: relative; 
        }

        .uonix-rel-nav {
            position: absolute;
            top: 40%;
            transform: translateY(-50%);
            width: 44px;
            height: 44px;
            background-color: #0e3780;
            color: #ffffff;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            font-size: 18px;
            line-height: 1;
        }
        .uonix-rel-nav:hover { background-color: #f76a0c; transform: translateY(-50%) scale(1.1); }
        .uonix-rel-prev { left: -15px; }
        .uonix-rel-next { right: -15px; }

        @media (max-width: 768px) {
            .uonix-rel-nav { width: 36px; height: 36px; font-size: 14px; top: 35%; }
            .uonix-rel-prev { left: -5px; }
            .uonix-rel-next { right: -5px; }
        }
        </style>

        <script id="uonix-relacionados-upsells-js">
        document.addEventListener('DOMContentLoaded', function() {
            // Só executa o script se for uma tela menor ou igual a 1024px (onde o carrossel atua)
            if (window.innerWidth <= 1024) {
                const containers = document.querySelectorAll('.related.products, .up-sells.products, .upsells.products, .cross-sells');
                
                containers.forEach(function(container) {
                    const carousel = container.querySelector('ul.products');
                    if (!carousel) return;
                    
                    // Injeta as setas de navegação se ainda não existirem
                    if (!container.querySelector('.uonix-rel-prev')) {
                        const btnPrev = document.createElement('button');
                        btnPrev.className = 'uonix-rel-nav uonix-rel-prev';
                        btnPrev.innerHTML = '&#10094;';
                        btnPrev.setAttribute('aria-label', 'Anterior');

                        const btnNext = document.createElement('button');
                        btnNext.className = 'uonix-rel-nav uonix-rel-next';
                        btnNext.innerHTML = '&#10095;';
                        btnNext.setAttribute('aria-label', 'Próximo');

                        container.appendChild(btnPrev);
                        container.appendChild(btnNext);

                        let isPaused = false;

                        // Pausa o autoplay ao interagir
                        const pauseCarousel = () => isPaused = true;
                        const playCarousel = () => isPaused = false;

                        carousel.addEventListener('mouseenter', pauseCarousel);
                        carousel.addEventListener('mouseleave', playCarousel);
                        carousel.addEventListener('touchstart', pauseCarousel);
                        carousel.addEventListener('touchend', playCarousel);
                        
                        btnPrev.addEventListener('mouseenter', pauseCarousel);
                        btnNext.addEventListener('mouseenter', pauseCarousel);

                        // Ação de deslizar
                        const scrollCarousel = (direction) => {
                            const card = carousel.querySelector('li.product');
                            if (!card) return;
                            
                            const step = card.offsetWidth + 15; // Largura do card + gap do css
                            
                            if (direction === 'next') {
                                if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth - 50) {
                                    carousel.scrollTo({ left: 0, behavior: 'smooth' });
                                } else {
                                    carousel.scrollBy({ left: step, behavior: 'smooth' });
                                }
                            } else {
                                if (carousel.scrollLeft <= 0) {
                                    carousel.scrollTo({ left: carousel.scrollWidth, behavior: 'smooth' });
                                } else {
                                    carousel.scrollBy({ left: -step, behavior: 'smooth' });
                                }
                            }
                        };

                        btnNext.addEventListener('click', (e) => { e.preventDefault(); scrollCarousel('next'); });
                        btnPrev.addEventListener('click', (e) => { e.preventDefault(); scrollCarousel('prev'); });

                        // Autoplay de 4 segundos
                        setInterval(function() {
                            if (!isPaused) {
                                scrollCarousel('next');
                            }
                        }, 4000);
                    }
                });
            }
        });
        </script>
        <?php
    }
}



