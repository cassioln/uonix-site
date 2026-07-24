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
    // Roda apenas na página do carrinho
    if ( function_exists( 'is_cart' ) && is_cart() ) {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.wp-block-woocommerce-product-collection');
            const carousel = container ? container.querySelector('ul.wc-block-product-template') : null;
            
            if (container && carousel) {
                // 1. INJEÇÃO DOS BOTÕES
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

                let isPaused = false;

                // 2. LÓGICA DE PAUSA (UX)
                const pauseCarousel = () => isPaused = true;
                const playCarousel = () => isPaused = false;

                carousel.addEventListener('mouseenter', pauseCarousel);
                carousel.addEventListener('mouseleave', playCarousel);
                carousel.addEventListener('touchstart', pauseCarousel);
                carousel.addEventListener('touchend', playCarousel);
                
                btnPrev.addEventListener('mouseenter', pauseCarousel);
                btnNext.addEventListener('mouseenter', pauseCarousel);
                btnPrev.addEventListener('mouseleave', playCarousel);
                btnNext.addEventListener('mouseleave', playCarousel);

                // 3. LÓGICA DE ROLAGEM
                const scrollCarousel = (direction) => {
                    const card = carousel.querySelector('li');
                    if (!card) return;
                    
                    const step = card.offsetWidth + 20; // Largura do card + gap
                    
                    if (direction === 'next') {
                        // Se chegou no fim, volta pro começo
                        if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth - 50) {
                            carousel.scrollTo({ left: 0, behavior: 'smooth' });
                        } else {
                            carousel.scrollBy({ left: step, behavior: 'smooth' });
                        }
                    } else {
                        // Se tá no começo e clica pra voltar, vai pro final
                        if (carousel.scrollLeft <= 0) {
                            carousel.scrollTo({ left: carousel.scrollWidth, behavior: 'smooth' });
                        } else {
                            carousel.scrollBy({ left: -step, behavior: 'smooth' });
                        }
                    }
                };

                // Eventos de clique nas setas
                btnNext.addEventListener('click', (e) => {
                    e.preventDefault();
                    scrollCarousel('next');
                });

                btnPrev.addEventListener('click', (e) => {
                    e.preventDefault();
                    scrollCarousel('prev');
                });

                // 4. MOTOR AUTOPLAY (4 Segundos)
                setInterval(function() {
                    if (!isPaused) {
                        scrollCarousel('next');
                    }
                }, 4000);
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
 * UÔNIX: CSS e Motor Autoplay para Produtos Relacionados (Página do Produto)
 * Ativa o carrossel apenas em telas onde não cabem os 4 produtos (<= 1024px).
 */
add_action('wp_footer', 'uonix_produtos_relacionados_carrossel_mobile');

function uonix_produtos_relacionados_carrossel_mobile() {
    // Executa apenas na página de produto individual
    if ( function_exists( 'is_product' ) && is_product() ) {
        ?>
        <style>
        /* ==========================================================================
           UÔNIX: PRODUTOS RELACIONADOS (PÁGINA DO PRODUTO)
           ========================================================================== */

        /* 1. Título da Seção ("Produtos relacionados") */
        section.related.products > h2 {
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
        section.related.products > h2::after {
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
            section.related.products ul.products {
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
            section.related.products ul.products::-webkit-scrollbar {
                display: none;
            }
            section.related.products ul.products {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }

            /* Tablet: 3 na tela */
            section.related.products ul.products li.product {
                flex: 0 0 calc(33.333% - 10px) !important; 
                scroll-snap-align: start;
                margin: 0 !important;
                max-width: none !important;
            }
        }
        
        @media (max-width: 768px) {
            /* Mobile: 1 na tela (mostrando o canto do próximo) */
            section.related.products ul.products li.product {
                flex: 0 0 calc(85%) !important; 
            }
        }

        /* ==========================================================================
           UÔNIX: ESTILO PARA O CARD DE PRODUTOS RELACIONADOS
           ========================================================================== */

        /* 1. O Card do Produto */
        .related.products ul.products li.product {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            flex-direction: column !important;
            height: 100% !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02) !important;
            box-sizing: border-box !important;
            position: relative !important;
            padding: 0 !important; 
            overflow: hidden !important;
        }

        .related.products ul.products li.product:hover {
            border-color: #0e3780 !important;
            box-shadow: 0 10px 20px rgba(14, 55, 128, 0.08) !important;
            transform: translateY(-5px) !important;
        }

        /* 2. Área da Imagem */
        .related.products ul.products li.product .woocommerce-loop-image-link {
            display: block !important;
            padding: 20px 15px !important;
            text-align: center !important;
        }
        .related.products ul.products li.product .woocommerce-loop-image-link img {
            max-height: 180px !important;
            width: auto !important;
            object-fit: contain !important;
            margin: 0 auto !important;
        }

        /* 3. Área de Detalhes */
        .related.products ul.products li.product .product-details {
            padding: 0 15px 20px 15px !important;
            display: flex !important;
            flex-direction: column !important;
            flex-grow: 1 !important;
        }

        /* 4. Título do Produto */
        .related.products ul.products li.product .woocommerce-loop-product__title {
            font-family: var(--global-body-font-family, inherit) !important;
            font-size: 15px !important;
            color: #1a2b3c !important;
            font-weight: 600 !important;
            line-height: 1.4 !important;
            margin-bottom: 15px !important;
            text-align: center !important;
            padding: 0 !important;
        }
        .related.products ul.products li.product .woocommerce-loop-product__title a {
            color: inherit !important;
            text-decoration: none !important;
        }
        .related.products ul.products li.product .woocommerce-loop-product__title a:hover {
            color: #f76a0c !important;
        }

        /* 6. Envoltório do Botão */
        .related.products ul.products li.product .product-action-wrap {
            margin-top: auto !important;
            width: 100% !important;
            opacity: 1 !important; 
            transform: none !important;
            position: static !important;
        }

        /* 7. O Botão (Azul Uônix) */
        .related.products ul.products li.product .button,
        .related.products ul.products li.product .uonix-details-btn {
            background-color: #0e3780 !important;
            color: #ffffff !important;
            border-radius: 6px !important;
            height: 48px !important;
            width: 100% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 5px 10px !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: none !important;
            transition: all 0.3s ease !important;
            box-sizing: border-box !important;
            text-align: center !important;
            line-height: 1.2 !important;
            white-space: normal !important;
        }
        .related.products ul.products li.product .button:hover,
        .related.products ul.products li.product .uonix-details-btn:hover {
            background-color: #f76a0c !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 10px rgba(247, 106, 12, 0.3) !important;
        }

        /* Bloqueia animação nativa do Kadence */
        .woocommerce ul.products li.product:hover .product-details,
        .woocommerce ul.products li.product:hover .entry-content-wrap,
        ul.products.product-archive li.product:hover .product-details,
        ul.products.product-archive li.product:hover .entry-content-wrap,
        .related.products ul.products li.product:hover .product-details,
        .related.products ul.products li.product:hover .entry-content-wrap {
            transform: none !important;
        }

        /* ==========================================================================
           BOTÕES DE NAVEGAÇÃO DO CARROSSEL (SETAS)
           ========================================================================== */
        .related.products { position: relative; }
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

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Só executa o script se for uma tela menor que 1025px (onde o carrossel atua)
            if (window.innerWidth <= 1024) {
                const container = document.querySelector('.related.products');
                const carousel = container ? container.querySelector('ul.products') : null;
                
                if (container && carousel) {
                    
                    // Injeta as setas de navegação
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
            }
        });
        </script>
        <?php
    }
}


