<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Blog - carrosseis, busca e autopreenchimento.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 12943-13618 do export original.
// -----------------------------------------------------------------------------
/**
 * Carrossel Produtos Destaque
 */
/**
 * UÔNIX: Carrossel Home (Banner Hero) - Título, Layout, Setas e Botão Ver Detalhes
 */
add_action('wp_footer', 'uonix_carrossel_banner_hero');

function uonix_carrossel_banner_hero() {
    ?>
    <style>
    /* ==========================================================================
       1. TÍTULO DO CARROSSEL (Injetado via JS)
       ========================================================================== */
    .uonix-carousel-title {
        text-align: center !important;
        font-size: 32px !important;
        font-weight: 900 !important;
        color: #0e3780 !important; /* Azul Uônix */
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
        background-color: #f76a0c !important; /* Detalhe Laranja Uônix */
        margin: 15px auto 0 auto;
        border-radius: 2px;
    }

    /* ==========================================================================
       2. LAYOUT BANNER HERO (Imagem esquerda, Texto direita - DESKTOP)
       ========================================================================== */
    .wcps-container-1546 .elements-wrapper {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        padding: 20px 40px !important;
        transition: none !important;
    }
    .wcps-container-1546 .elements-wrapper:hover { transform: none !important; }

    .wcps-container-1546 .layer-media { width: 50% !important; background: transparent !important; padding: 0 !important; }
    .wcps-container-1546 .layer-media img { max-height: 380px !important; width: auto !important; object-fit: contain !important; margin: 0 auto !important; transform: scale(1.05); transition: transform 0.5s ease; }
    .wcps-container-1546 .elements-wrapper:hover .layer-media img { transform: scale(1.12); }

    .wcps-container-1546 .layer-content { width: 50% !important; padding: 0 0 0 40px !important; align-items: flex-start !important; text-align: left !important; }
    .wcps-container-1546 .wcps-items-title { text-align: left !important; margin-bottom: 20px !important; width: 100% !important; }
    .wcps-container-1546 .wcps-items-title a { font-size: 38px !important; font-weight: 900 !important; line-height: 1.1 !important; color: #0e3780 !important; text-decoration: none !important; }
    .wcps-container-1546 .wcps-items-title a:hover { color: #f76a0c !important; }
    .wcps-container-1546 .wcps-items-price { display: none !important; }

    /* ==========================================================================
       3. SUBSTITUIÇÃO DO BOTÃO
       ========================================================================== */
    .wcps-container-1546 .wcps-items-cart a.add_to_cart_button,
    .wcps-container-1546 .wcps-items-cart a.added_to_cart,
    .wcps-container-1546 .wcps-items-cart span.screen-reader-text { display: none !important; }
    .wcps-container-1546 .wcps-items-cart p { border: none !important; padding: 0 !important; margin: 0 !important; }

    .wcps-container-1546 .wcps-items-cart { width: auto !important; margin-top: 10px !important; }
    .wcps-container-1546 .wcps-items-cart a.uonix-btn-detalhes { background-color: #f76a0c !important; color: #ffffff !important; border-radius: 50px !important; height: 54px !important; width: auto !important; display: inline-flex !important; align-items: center !important; padding: 0 40px !important; font-size: 16px !important; font-weight: 800 !important; text-transform: uppercase !important; letter-spacing: 1px !important; border: none !important; transition: all 0.3s ease !important; box-shadow: 0 4px 15px rgba(247, 106, 12, 0.3) !important; text-decoration: none !important; }
    .wcps-container-1546 .wcps-items-cart a.uonix-btn-detalhes:hover { background-color: #0e3780 !important; box-shadow: 0 6px 20px rgba(14, 55, 128, 0.3) !important; transform: translateY(-2px) !important; }

    /* ==========================================================================
       4. SETAS E PAGINAÇÃO MODERNAS (DESKTOP)
       ========================================================================== */
    .wcps-container-1546 .splide__arrows div.splide__arrow { background-color: #0e3780 !important; width: 48px !important; height: 48px !important; border-radius: 50% !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 0 !important; opacity: 0.9 !important; box-shadow: 0 4px 10px rgba(0,0,0,0.15) !important; transition: all 0.3s ease !important; position: absolute !important; top: 50% !important; transform: translateY(-50%) !important; z-index: 10 !important; }
    .wcps-container-1546 .splide__arrows div.splide__arrow:hover { background-color: #f76a0c !important; opacity: 1 !important; transform: translateY(-50%) scale(1.1) !important; }
    .wcps-container-1546 .splide__arrows div.splide__arrow .icon, .wcps-container-1546 .splide__arrows div.splide__arrow i { color: #ffffff !important; font-size: 16px !important; display: flex !important; align-items: center !important; justify-content: center !important; margin: 0 !important; padding: 0 !important; }
    .wcps-container-1546 .splide__arrows div.splide__arrow.prev { left: 10px !important; }
    .wcps-container-1546 .splide__arrows div.splide__arrow.next { right: 10px !important; left: auto !important; }

    .wcps-container-1546 .splide__pagination { bottom: -15px !important; display: flex !important; justify-content: center !important; align-items: center !important; gap: 6px !important; margin-bottom: 20px !important;}
    .wcps-container-1546 .splide__pagination li { margin: 0 !important; }
    .wcps-container-1546 .splide__pagination button.splide__pagination__page { background-color: #cbd5e1 !important; width: 10px !important; height: 10px !important; border-radius: 50% !important; padding: 0 !important; margin: 0 !important; border: none !important; transition: all 0.3s ease !important; opacity: 1 !important; }
    .wcps-container-1546 .splide__pagination button.splide__pagination__page.is-active { background-color: #f76a0c !important; width: 28px !important; border-radius: 10px !important; }

  /* ==========================================================================
       5. RESPONSIVIDADE (MOBILE) - BOTÃO NO HOVER/TOQUE
       ========================================================================== */
    @media (max-width: 768px) {
        
        .uonix-carousel-title { font-size: 20px !important; margin-top: 20px !important; margin-bottom: 15px !important; }
        .wcps-container-1546 .elements-wrapper { flex-direction: column !important; padding: 10px !important; }
        .wcps-container-1546 .layer-content { display: contents !important; }

        /* Título no topo e menor */
        .wcps-container-1546 .wcps-items-title { order: -1 !important; text-align: center !important; width: 100% !important; margin-bottom: 5px !important; }
        .wcps-container-1546 .wcps-items-title a { font-size: 18px !important; }

        /* Imagem no meio */
        .wcps-container-1546 .layer-media { order: 0 !important; width: 100% !important; padding: 0 !important; margin-bottom: 15px !important; }
        .wcps-container-1546 .layer-media img { max-height: 220px !important; }

        /* Botão escondido inicialmente */
        .wcps-container-1546 .wcps-items-cart { 
            order: 1 !important; 
            width: 100% !important;
            opacity: 0 !important;
            visibility: hidden !important;
            max-height: 0 !important; /* Zera a altura para não ocupar espaço */
            margin-top: 0 !important;
            transition: all 0.4s ease !important; /* Animação suave */
        }

        .wcps-container-1546 .wcps-items-cart a.uonix-btn-detalhes { width: 100% !important; justify-content: center !important; }

        /* Botão aparece deslizando ao tocar/passar o dedo no card */
        .wcps-container-1546 .elements-wrapper:hover .wcps-items-cart,
        .wcps-container-1546 .elements-wrapper:active .wcps-items-cart {
            opacity: 1 !important;
            visibility: visible !important;
            max-height: 60px !important; /* Altura suficiente para o botão */
            margin-top: 10px !important;
        }

        /* Oculta as setas para manter clean */
        .wcps-container-1546 .splide__arrows div.splide__arrow { opacity: 0 !important; width: 36px !important; height: 36px !important; }
        .wcps-container-1546:active .splide__arrows div.splide__arrow, .wcps-container-1546:hover .splide__arrows div.splide__arrow { opacity: 0.6 !important; }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        
        // 1. INJETA O TÍTULO PREMIUM ANTES DO CARROSSEL
        if ($('.wcps-container-1546').length && $('.uonix-carousel-title').length === 0) {
            $('.wcps-container-1546').before('<h2 class="uonix-carousel-title">Produtos para Ancoragem e Fixação</h2>');
        }

        // 2. FUNÇÃO PARA INJETAR O BOTÃO "VER DETALHES" SEGURO
        function injectUonixDetailsButton() {
            $('.wcps-container-1546 .item').each(function() {
                var $containerBotoes = $(this).find('.wcps-items-cart p.add_to_cart_inline');
                var urlProduto = $(this).find('.wcps-items-title a').attr('href');
                
                if (urlProduto && $containerBotoes.find('.uonix-btn-detalhes').length === 0) {
                    $containerBotoes.append('<a href="' + urlProduto + '" class="button uonix-btn-detalhes">Ver detalhes</a>');
                }
            });
        }

        setTimeout(injectUonixDetailsButton, 800);
        $(window).on('resize', function() { setTimeout(injectUonixDetailsButton, 500); });
    });
    </script>
    <?php
}

// -----------------------------------------------------------------------------
// Carrossel Sidebar Blog [wcps id='8643']:
// Hook de conversão do botão para "Solicitar Orçamento" gerenciado em:
// themes/kadence-child/snippets/36-wcps-destaques-slider.php
// Migração e integridade de banco automatizadas via scripts/migrate-wcps-sliders-and-widgets.php
// -----------------------------------------------------------------------------

/**
 * Scroll Suave e Autopreenchimento do Formulário na mesma página
 */
/**
 * UÔNIX: Autopreenchimento do Formulário via Link (Mantendo o Scroll Nativo do Tema)
 */
add_action('wp_footer', function() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        
        // Fica de olho apenas nos links que têm o ?assunto= e vão para o #contato
        $('a[href*="?assunto="][href*="#contato"]').on('click', function() {
            
            // Se o formulário estiver na tela, faz o preenchimento invisível:
            if ($('#ff_3_3_form_assunto').length) {
                var href = $(this).attr('href');
                var parametro = href.split('?assunto=')[1];
                var assunto = parametro ? parametro.split('#')[0] : '';
                
                if (assunto) {
                    $('#ff_3_3_form_assunto').val(assunto).trigger('change');
                }
            }
            
            // Note que NÃO bloqueamos a ação padrão (e.preventDefault) 
            // e NÃO fazemos a animação de scroll. O Kadence fará isso por nós!
        });
    });
    </script>
    <?php
});

/**
 *  Página de Resultados de Pesquisa
 */
/**
 * UÔNIX: Estilo Premium para a Página de Resultados de Pesquisa (V4 - Enterprise Clean)
 */
add_action('wp_head', 'uonix_estilos_premium_pesquisa_v4');

function uonix_estilos_premium_pesquisa_v4() {
    if ( !is_search() ) return;
    ?>
    <style>
        /* ==========================================================================
           1. HEADER DA PESQUISA (MINIMALISTA E MODERNO)
           ========================================================================== */
        header.entry-header.search-archive-title {
            background: transparent !important;
            border: none !important;
            border-bottom: 2px solid #e2e8f0 !important;
            border-radius: 0 !important;
            padding: 0 0 20px 0 !important;
            margin-bottom: 40px !important;
            box-shadow: none !important;
            text-align: left !important;
        }

        header.entry-header.search-archive-title .page-title {
            color: #0e3780 !important;
            font-size: 2rem !important;
            font-weight: 800 !important;
            margin: 0 !important;
            letter-spacing: -0.5px;
        }

        header.entry-header.search-archive-title .page-title span {
            color: #f76a0c !important;
        }

        /* ==========================================================================
           2. CARDS DE RESULTADOS (FLEXBOX ALINHADO AO TOPO)
           ========================================================================== */
        #archive-container .entry-list-item article.loop-entry {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            transition: all 0.25s ease !important;
            box-shadow: none !important;
            margin-bottom: 24px !important;
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
            min-height: 200px !important;
            padding: 0 !important;
        }

        #archive-container .entry-list-item article.loop-entry:hover {
            border-color: #cbd5e1 !important;
            box-shadow: 0 10px 30px -10px rgba(14, 55, 128, 0.12) !important;
            transform: translateY(-2px) !important;
        }

        /* ==========================================================================
           3. IMAGEM DO CARD (PROPORÇÃO CORRIGIDA)
           ========================================================================== */
        .entry-list-item article.loop-entry .post-thumbnail {
            width: 280px !important;
            flex-shrink: 0 !important;
            padding: 0 !important; 
            margin: 0 !important;
            height: auto !important;
            border-right: 1px solid #e2e8f0 !important;
            display: block !important;
            position: relative !important;
        }

        .entry-list-item article.loop-entry .post-thumbnail-inner {
            padding: 0 !important;
            margin: 0 !important;
            height: 100% !important;
            width: 100% !important;
            display: block !important;
            background: #f8fafc !important;
        }

        .entry-list-item article.loop-entry .post-thumbnail-inner img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            display: block !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            border-radius: 0 !important;
            transition: transform 0.5s ease !important;
        }

        article.loop-entry:hover .post-thumbnail-inner img {
            transform: scale(1.03) !important;
        }

        /* ==========================================================================
           4. CONTEÚDO E COMENTÁRIOS DISCRETOS
           ========================================================================== */
        #archive-container .entry-content-wrap {
            padding: 24px 32px !important;
            flex: 1 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-start !important;
            height: auto !important;
        }

        h2.entry-title { margin: 0 !important; }

        h2.entry-title a {
            color: #0e3780 !important;
            font-size: 1.5rem !important;
            font-weight: 800 !important;
            text-decoration: none !important;
            line-height: 1.3 !important;
            transition: color 0.2s ease !important;
        }

        article.loop-entry:hover h2.entry-title a {
            color: #f76a0c !important;
        }

        /* --- Início do Tratamento de Meta/Comentários --- */
        .entry-taxonomies { display: none !important; }

        /* Esconde a caixa de meta (data, autor, comentários) para produtos, serviços e páginas */
        .search article:not(.type-post) .entry-meta { 
            display: none !important; 
        }

        /* Mostra a caixa de meta SÓ no Blog, com espaçamento certinho abaixo do título */
        .search article.type-post .entry-meta {
            display: block !important;
            margin: 0 0 0 0 !important;
        }

        /* Esconde a categoria nativa e os separadores de pontinho (já fizemos a etiqueta nova no topo) */
        .search article.type-post .entry-meta .category-links,
        .search article.type-post .entry-meta .meta-separator {
            display: none !important;
        }

        /* Estiliza exclusivamente os comentários para ficarem pequenos e discretos */
        .search article.type-post .entry-meta .post-comments a {
            color: #94a3b8 !important; /* Cinza claro elegante */
            font-size: 13px !important;
            font-weight: 500 !important;
            text-transform: none !important;
            letter-spacing: normal !important;
            text-decoration: none !important;
        }

        .search article.type-post .entry-meta .post-comments a:hover {
            color: #f76a0c !important;
        }
        /* --- Fim do Tratamento --- */

        .loop-entry .entry-summary { margin: 0 !important; }

        .loop-entry .entry-summary p {
            color: #475569 !important;
            font-size: 15px !important;
            line-height: 1.5 !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            margin: 0 !important;
        }

        /* ==========================================================================
           5. BOTÃO "VEJA MAIS" (CLEAN LINK)
           ========================================================================== */
        .entry-footer {
            margin-top: auto !important;
            padding: 0 !important;
            border: none !important;
        }

        a.post-more-link {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
            background: transparent !important;
            color: #0e3780 !important;
            padding: 0 !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            text-decoration: none !important;
            transition: color 0.2s ease !important;
            width: max-content !important;
        }

        a.post-more-link:hover {
            background: transparent !important;
            color: #f76a0c !important;
            transform: none !important;
            box-shadow: none !important;
        }

        a.post-more-link svg {
            fill: currentColor !important;
            margin-left: 6px !important;
            transition: transform 0.2s ease !important;
        }

        a.post-more-link:hover svg {
            transform: translateX(5px) !important;
        }

        /* ==========================================================================
           6. PAGINAÇÃO E MOBILE
           ========================================================================== */
        nav.pagination .nav-links { display: flex; gap: 8px; justify-content: flex-start; margin-top: 20px; }
        nav.pagination .page-numbers { display: flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; border-radius: 4px; background: #ffffff; border: 1px solid #e2e8f0; color: #0e3780; font-weight: 700; text-decoration: none; transition: all 0.2s ease; }
        nav.pagination .page-numbers:hover { border-color: #f76a0c; color: #f76a0c; }
        nav.pagination .page-numbers.current { background: #f76a0c; border-color: #f76a0c; color: #ffffff; }

        @media (max-width: 768px) {
            header.entry-header.search-archive-title { padding: 0 0 15px 0 !important; margin-bottom: 25px !important; }
            header.entry-header.search-archive-title .page-title { font-size: 22px !important; }
            
            #archive-container .entry-list-item article.loop-entry { flex-direction: column !important; }
            .entry-list-item article.loop-entry .post-thumbnail { width: 100% !important; height: 200px !important; border-right: none !important; border-bottom: 1px solid #e2e8f0 !important; }
            #archive-container .entry-content-wrap { padding: 20px !important; }
        }

        /* ==========================================================================
           7. ETIQUETAS DINÂMICAS PADRONIZADAS (NO TOPO)
           ========================================================================== */
        .search article.loop-entry h2.entry-title::before {
            display: block !important;
            color: var(--global-palette1) !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            font-size: 0.9rem !important;
            letter-spacing: 0.5px !important;
			margin-bottom: 8px !important;
            font-family: inherit;
        }

        /* Injeção baseada na classe do tipo de post */
        .search article.type-product h2.entry-title::before { content: "PRODUTOS"; }
        .search article.type-servicos h2.entry-title::before { content: "SERVIÇOS"; }
        .search article.type-post h2.entry-title::before { content: "UÔNIX BLOG"; }
        .search article.type-page h2.entry-title::before { content: "PÁGINA"; }

    </style>
    <?php
}


