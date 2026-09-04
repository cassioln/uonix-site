<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Catalogo - filtros Husky, busca e comportamento mobile.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1369-1397 do export original.
// -----------------------------------------------------------------------------
/**
 *  * UÔNIX: Mover filtro para o Body APENAS em Celulares (< 768px)
 */
/**
 * UÔNIX: Mover filtro para o Body APENAS em Celulares (< 768px)
 */
add_action( 'wp_footer', function() {
    ?>
    <script type="text/javascript">
        (function($) {
            $(document).ready(function() {
                setTimeout(function() {
                    // Agora o limite é 767px para não bugar tablets com sidebar
                    if (window.innerWidth <= 767) {
                        var $filter = $('.woof-front-builder-container');
                        if ($filter.length) {
                            $filter.appendTo('body');
                            console.log('Uônix: Filtro movido para o Body (Modo Mobile).');
                        }
                    } else {
                        console.log('Uônix: Filtro mantido na Row original (Desktop/Tablet).');
                    }
                }, 600);
            });
        })(jQuery);
    </script>
    <?php
}, 100 );


// -----------------------------------------------------------------------------
// Bloco 2 - linhas 5148-5166 do export original.
// -----------------------------------------------------------------------------
/**
 * UÔNIX: Force Husky Template Path
 * ---------------------------------------------------------
 * Garante que o plugin use o arquivo do tema Child
 */
add_filter('woof_husky_txt_template_path', function($factory_path) {
    // Aponta para a pasta que você criou no seu tema child
    $child_theme_path = get_stylesheet_directory() . '/woof/ext/by_text/views/templates/default.php';
    
    // Se você renomeou para 'husky', use esta linha abaixo:
    // $child_theme_path = get_stylesheet_directory() . '/husky/ext/by_text/views/templates/default.php';

    if (file_exists($child_theme_path)) {
        return $child_theme_path;
    }

    return $factory_path;
}, 9999);


// -----------------------------------------------------------------------------
// Bloco 3 - linhas 5395-5674 do export original.
// -----------------------------------------------------------------------------
/**
 * Ajustes Filtro Produtos etc Forçar Breakpoint do Filtro Husky para 768px
 */
/**
 * UÔNIX: Filtro Husky - Estrutura, Lógica e Correções (V4.3)
 * ---------------------------------------------------------
 * - Define Breakpoint em 768px (Tablet = Desktop).
 * - Controla visibilidade de colunas Kadence.
 * - Move o filtro para o Body apenas em celulares.
 * - CORREÇÕES: Select2 Mobile, Labels Ativos e Breadcrumb Busca.
 */

add_action('wp_footer', function() {
    // Executa apenas em páginas de produtos ou na página específica do catálogo
    if ( ! is_post_type_archive( 'product' ) && ! is_tax( get_object_taxonomies( 'product' ) ) && ! is_page(7150) ) return;
    ?>
    
    <style id="uonix-husky-structural-plus">
        /* 1. REGRAS PARA TABLET E DESKTOP (>= 768px) */
        @media (min-width: 768px) {
            /* Esconde elementos de acionamento mobile */
            .woof_show_mobile_filter, 
            .woof_show_mobile_filter_container, 
            .woof_hide_mobile_filter { 
                display: none !important; 
            }

            /* Força a Sidebar a existir e ser visível por padrão */
            .kadence-column2932_0a9c6d-62,
            .kadence-column7150_89634e-21 {
                display: block !important;
                flex: 0 0 30% !important;
                max-width: 30% !important;
                min-width: 280px !important;
                visibility: visible !important;
            }

            /* Mantém o container do filtro em modo bloco estático */
            .woof-front-builder-container, .woof {
                display: block !important;
                position: relative !important;
                top: 0 !important;
                opacity: 1 !important;
                height: auto !important;
            }

            /* Coluna de produtos ao lado da sidebar */
            .kadence-column2932_fec114-b0,
            .kadence-column7150_2a56fa-f9 {
                flex: 0 0 70% !important;
                max-width: 70% !important;
            }

            /* CABEÇALHO DO FILTRO COM BOTÃO DE RECOLHER */
            .uonix-sidebar-toggle-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 14px;
                margin-bottom: 18px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }
            .uonix-sidebar-toggle-title {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                font-weight: 700;
                color: #0e3780;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .uonix-sidebar-toggle-title svg {
                stroke: #0e3780;
                flex-shrink: 0;
            }

            /* BOTÕES DE TOGGLE (RECOLHER E EXPANDIR) */
            .uonix-btn-toggle-filters {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                color: #1e293b;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                line-height: 1.2;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            }
            .uonix-btn-toggle-filters:hover {
                background: #0e3780;
                border-color: #0e3780;
                color: #ffffff;
            }
            .uonix-btn-toggle-filters:hover svg {
                stroke: #ffffff;
            }
            .uonix-btn-toggle-filters svg {
                stroke: #475569;
                transition: stroke 0.2s ease;
                flex-shrink: 0;
            }

            /* BARRA EXPANDIR ACIMA DOS PRODUTOS (QUANDO RECOLHIDO) */
            .uonix-sidebar-expand-wrap {
                display: none;
                align-items: center;
                margin-bottom: 18px;
                padding-bottom: 8px;
            }

            .uonix-sidebar-expand-wrap .uonix-btn-expand {
                padding: 8px 16px;
                background: #ffffff;
                border: 1.5px solid #0e3780;
                color: #0e3780;
                font-size: 13px;
                font-weight: 700;
                border-radius: 6px;
                box-shadow: 0 2px 8px rgba(14, 55, 128, 0.08);
            }
            .uonix-sidebar-expand-wrap .uonix-btn-expand svg {
                stroke: #0e3780;
            }
            .uonix-sidebar-expand-wrap .uonix-btn-expand:hover {
                background: #0e3780;
                color: #ffffff;
            }
            .uonix-sidebar-expand-wrap .uonix-btn-expand:hover svg {
                stroke: #ffffff;
            }

            /* ESTADO RECOLHIDO (COLLAPSED) */
            #catalogo-produtos.uonix-filters-collapsed .kadence-column2932_0a9c6d-62,
            #catalogo-produtos.uonix-filters-collapsed .kadence-column7150_89634e-21,
            .uonix-filters-collapsed .kadence-column2932_0a9c6d-62,
            .uonix-filters-collapsed .kadence-column7150_89634e-21 {
                display: none !important;
            }

            #catalogo-produtos.uonix-filters-collapsed > .kt-row-column-wrap,
            .uonix-filters-collapsed #catalogo-produtos > .kt-row-column-wrap {
                grid-template-columns: 1fr !important;
                column-gap: 0 !important;
            }

            #catalogo-produtos.uonix-filters-collapsed .kadence-column2932_fec114-b0,
            #catalogo-produtos.uonix-filters-collapsed .kadence-column7150_2a56fa-f9,
            .uonix-filters-collapsed .kadence-column2932_fec114-b0,
            .uonix-filters-collapsed .kadence-column7150_2a56fa-f9 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
            }

            #catalogo-produtos.uonix-filters-collapsed .uonix-sidebar-expand-wrap,
            .uonix-filters-collapsed .uonix-sidebar-expand-wrap {
                display: flex !important;
            }

            /* GRID EM 4 COLUNAS NO DESKTOP QUANDO RECOLHIDO */
            @media (min-width: 1025px) {
                #catalogo-produtos.uonix-filters-collapsed ul.products.product-archive,
                #catalogo-produtos.uonix-filters-collapsed .woof_shortcode_output ul.products,
                .uonix-filters-collapsed #catalogo-produtos ul.products.product-archive,
                .uonix-filters-collapsed #catalogo-produtos .woof_shortcode_output ul.products {
                    grid-template-columns: repeat(4, minmax(0px, 1fr)) !important;
                }
            }

            /* GRID EM 3 COLUNAS NO TABLET QUANDO RECOLHIDO */
            @media (min-width: 768px) and (max-width: 1024px) {
                #catalogo-produtos.uonix-filters-collapsed ul.products.product-archive,
                #catalogo-produtos.uonix-filters-collapsed .woof_shortcode_output ul.products,
                .uonix-filters-collapsed #catalogo-produtos ul.products.product-archive,
                .uonix-filters-collapsed #catalogo-produtos .woof_shortcode_output ul.products {
                    grid-template-columns: repeat(3, minmax(0px, 1fr)) !important;
                }
            }
        }

        /* 2. REGRAS PARA CELULAR (< 768px) */
        @media (max-width: 767px) {
            /* Esconde botões de toggle desktop/tablet no mobile */
            .uonix-sidebar-toggle-header,
            .uonix-sidebar-expand-wrap {
                display: none !important;
            }

            .kadence-column2932_0a9c6d-62,
            .kadence-column7150_89634e-21 { display: none !important; }
            .kadence-column2932_fec114-b0,
            .kadence-column7150_2a56fa-f9 { flex: 0 0 100% !important; max-width: 100% !important; }
            
            /* Garante que a coluna sticky e o botão de filtro mobile fiquem sempre acima do grid de produtos */
            .kadence-column7150_2b9bfb-d5,
            .kadence-column7150_2b9bfb-d5 .kt-inside-inner-col,
            .wp-block-kadence-column:has(.woof_show_mobile_filter),
            .woof_show_mobile_filter_container,
            .woof_show_mobile_filter {
                z-index: 99 !important;
            }
            
            @keyframes uonix_move_top_filter {
                0% {
                    top: 100%;
                }
                100% {
                    top: 60px;
                }
            }

            @keyframes move_top {
                0% {
                    top: 100%;
                }
                100% {
                    top: 60px;
                }
            }

            .woof_show_filter_for_mobile.woof {
                position: fixed !important;
                z-index: 99999 !important;
                top: 100%;
                margin-top: 0 !important;
                height: calc(100% - 60px) !important;
                height: calc(100dvh - 60px) !important;
                width: 100% !important;
                left: 0 !important;
                right: 0 !important;
                padding: 15px 20px 100px 20px !important;
                box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.12) !important;
                border-top: 1px solid #e2e8f0 !important;
                display: block !important;
                animation: move_top .4s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
            }

            @keyframes uonix_move_down_filter {
                0% {
                    top: 60px;
                }
                100% {
                    top: 100%;
                }
            }

            .woof_show_filter_for_mobile.woof.uonix_closing {
                top: 60px;
                animation: uonix_move_down_filter .35s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
            }

            .woof_hide_mobile_filter {
                margin: 8px 0 16px auto !important;
            }

            /* Oculta o primeiro botão "Redefinir" no topo no mobile */
            .woof_redraw_zone > .woof_submit_search_form_container:first-of-type {
                display: none !important;
            }

            /* CORREÇÃO SELECT2 NO MOBILE: Garante que as opções fiquem na frente do overlay */
            .select2-container--open { z-index: 9999999 !important; }
            .select2-dropdown { z-index: 9999999 !important; }
        }

        /* 3. LIMPEZA DE TEXTOS E LABELS (FUNCIONAL) */

        /* Remove labels como "Fabricante:", "Categorias de produto:" das tags superiores */
        .woof_products_top_panel_ul ul li:first-child { 
            display: none !important; 
        }

        /* Remove "Início >" da busca de texto no autocomplete */
        .woof_husky_txt-option-breadcrumb { 
            font-size: 0 !important; 
        }
        .woof_husky_txt-option-breadcrumb a { 
            font-size: 11px !important; 
        }
        .woof_husky_txt-option-breadcrumb a:first-child { 
            display: none !important; 
        }

        /* Limpeza de ícones e botões redundantes nativos */
        .woof_products_top_panel_ul a img, 
        .woof_products_top_panel_ul a svg, 
        .woof_reset_button_2_redundant { 
            display: none !important; 
        }

        /* 4. TÍTULOS BALANCEADOS NA GRADE DE PRODUTOS (Sem palavras órfãs) */
        .woocommerce ul.products li.product .woocommerce-loop-product__title,
        .woocommerce ul.products li.product .woocommerce-loop-product__title a,
        ul.products.product-archive li.product .woocommerce-loop-product__title,
        ul.products.product-archive li.product .woocommerce-loop-product__title a {
            text-wrap: balance !important;
        }
    </style>

    <script id="uonix-husky-logic-js">
    (function($) {
        function updateHuskyLayout() {
            var width = window.innerWidth;
            
            if (typeof woof_is_mobile !== 'undefined') {
                woof_is_mobile = (width < 768) ? 1 : 0;
            }

            if (width < 768) {
                var $filter = $('.woof-front-builder-container');
                if ($filter.length && !$filter.parent().is('body')) {
                    $filter.find('.woof').removeClass('woof_show_filter_for_mobile');
                    $filter.appendTo('body');
                }
            }
        }

        // Toggle de recolhimento dos filtros em Desktop e Tablet (>= 768px)
        function setupDesktopFilterToggle() {
            var $catalog = $('#catalogo-produtos');
            if (!$catalog.length) return;

            var $filterCol = $catalog.find('.kadence-column7150_89634e-21, .kadence-column2932_0a9c6d-62');
            var $productsCol = $catalog.find('.kadence-column7150_82068b-b2, .kadence-column2932_fec114-b0');

            // 1. Injeta cabeçalho com botão "Recolher" na sidebar se ainda não existir
            if ($filterCol.length && !$filterCol.find('.uonix-sidebar-toggle-header').length) {
                var headerHtml = '<div class="uonix-sidebar-toggle-header">' +
                    '<span class="uonix-sidebar-toggle-title">' +
                        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>' +
                        'Filtros' +
                    '</span>' +
                    '<button type="button" class="uonix-btn-toggle-filters uonix-btn-collapse" title="Ocultar barra de filtros">' +
                        '<span>Recolher</span>' +
                        '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"></polyline><polyline points="18 17 13 12 18 7"></polyline></svg>' +
                    '</button>' +
                '</div>';
                $filterCol.find('.kt-inside-inner-col').prepend(headerHtml);
            }

            // 2. Injeta botão "Mostrar Filtros" na coluna dos produtos se ainda não existir
            if ($productsCol.length && !$productsCol.find('.uonix-sidebar-expand-wrap').length) {
                var expandHtml = '<div class="uonix-sidebar-expand-wrap">' +
                    '<button type="button" class="uonix-btn-toggle-filters uonix-btn-expand" title="Exibir filtros laterais">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>' +
                        '<span>Mostrar Filtros</span>' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>' +
                    '</button>' +
                '</div>';
                var $ajaxWrap = $productsCol.find('.woof_results_by_ajax_shortcode');
                if ($ajaxWrap.length) {
                    $ajaxWrap.before(expandHtml);
                } else {
                    $productsCol.find('.kt-inside-inner-col').prepend(expandHtml);
                }
            }

            // 3. Aplica preferência salva
            try {
                var savedState = localStorage.getItem('uonix_catalog_filters_collapsed');
                if (savedState === '1' && window.innerWidth >= 768) {
                    $catalog.addClass('uonix-filters-collapsed');
                }
            } catch (e) {}
        }

        // Handlers de clique para recolher / expandir
        $(document).on('click', '.uonix-btn-collapse', function(e) {
            e.preventDefault();
            $('#catalogo-produtos').addClass('uonix-filters-collapsed');
            try { localStorage.setItem('uonix_catalog_filters_collapsed', '1'); } catch (e) {}
        });

        $(document).on('click', '.uonix-btn-expand', function(e) {
            e.preventDefault();
            $('#catalogo-produtos').removeClass('uonix-filters-collapsed');
            try { localStorage.setItem('uonix_catalog_filters_collapsed', '0'); } catch (e) {}
        });

        // Animação suave invertida (slide down) ao fechar o filtro mobile
        document.addEventListener('click', function(e) {
            var hideBtn = e.target.closest('.woof_hide_mobile_filter');
            if (!hideBtn) return;

            var woof = hideBtn.closest('.woof');
            if (!woof || !woof.classList.contains('woof_show_filter_for_mobile') || woof.classList.contains('uonix_closing')) return;

            // Intercepta fechamento instantâneo do plugin WOOF
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            woof.classList.add('uonix_closing');

            setTimeout(function() {
                woof.classList.remove('uonix_closing');
                woof.classList.remove('woof_show_filter_for_mobile');
            }, 340);
        }, true);

        $(document).ready(function() {
            setTimeout(updateHuskyLayout, 600);
            setupDesktopFilterToggle();
        });

        $(window).on('resize', function() {
            updateHuskyLayout();
            if (window.innerWidth < 768) {
                $('#catalogo-produtos').removeClass('uonix-filters-collapsed');
            }
        });
    })(jQuery);
    </script>
    <?php
}, 999);

/**
 * Input Pesquisar Search Produtos na pag produtos
 */
/**
 * UÔNIX: Pesquisa Master Integrada (V5 - Minimalista + Autocomplete Profissional)
 * -----------------------------------------------------------------------------
 * - Unificação da Lógica de UX (JS) com Design Industrial (CSS).
 * - Loader centralizado no componente nativo.
 * - Autocomplete com imagens 70x70 e remoção de "Início".
 */

add_action('wp_footer', function() {
    // Correção do slug 'product' para garantir o funcionamento
    if ( ! is_post_type_archive( 'product' ) && ! is_tax( get_object_taxonomies( 'product' ) ) && ! is_page(7150) ) return;
    ?>
    
    <style id="uonix-husky-integrated-search">
        /* 1. REMOÇÃO DE ELEMENTOS NATIVOS DO PLUGIN */
        .woof_husky_txt-cross, 
        .woof_text_search_go { 
            display: none !important; 
        }

        /* 2. LOADER (CONFIGURAÇÃO PERSONALIZADA CÁSSIO) */
        .woof_text_search_container .woof_husky_txt-loader {
            display: block !important;
            width: 30px !important;
            height: 30px !important;
            right: 6px !important; 
            top: 50% !important;
            margin-top: -35px !important; /* Ajuste preciso conforme seu teste */
            border: 2px solid rgba(14, 55, 128, 0.2) !important;
            border-right-color: #0e3780 !important;
            border-radius: 50% !important;
            z-index: 5 !important;
        }

        /* 3. DROPDOWN AUTOCOMPLETE (VISUAL PROFISSIONAL) & CONTROLE DE CAMADAS (STACKING CONTEXT) */
        .woof_text_search_container {
            position: relative !important;
            z-index: 99999 !important;
        }

        /* Eleva as colunas e linha que envelopam a busca */
        .wp-block-kadence-column:has(.woof_text_search_container),
        .kadence-column7150_2a56fa-f9,
        .kadence-column7150_c66229-c0 {
            position: relative !important;
            z-index: 99999 !important;
        }

        .wp-block-kadence-rowlayout:has(.woof_text_search_container),
        .kb-row-layout-id7150_219091-69 {
            position: relative !important;
            z-index: 100 !important;
        }

        /* Garante que o painel de tags ativas e o grid fiquem abaixo do dropdown */
        .woof_products_top_panel,
        .woof_products_top_panel_ul,
        .woof_products_top_panel_content,
        .kadence-column7150_6a9c4f-25 {
            position: relative !important;
            z-index: 10 !important;
        }

        .kadence-column7150_82068b-b2,
        ul.products.product-archive {
            position: relative !important;
            z-index: 5 !important;
        }

        /* Garante que o bloco de FAQ fique abaixo dos resultados de busca */
        #faq,
        [id="faq"],
        .kb-row-layout-wrap#faq,
        .kb-row-layout-id2859_1404ed-e8 {
            position: relative !important;
            z-index: 1 !important;
        }

        .woof_husky_txt-container {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 4px !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
            margin-top: 5px !important;
            padding: 10px 0 !important;
            position: absolute !important;
            z-index: 999999 !important;
        }

        /* Itens da Lista */
        .woof_husky_txt-option {
            padding: 12px 15px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            transition: background 0.2s ease !important;
            display: flex !important;
            align-items: center !important;
        }

        .woof_husky_txt-option:last-child { border-bottom: none !important; }
        .woof_husky_txt-option:hover { background-color: #e8e8e8 !important; }

        /* Miniaturas (70x70) */
        .woof_husky_txt-option-thumbnail {
            width: 70px !important;
            height: 70px !important;
            border-radius: 4px !important;
            border: 1px solid #e2e8f0 !important;
            margin-right: 15px !important;
            object-fit: cover !important;
        }

        /* Títulos e Textos */
        .woof_husky_txt-option-title a {
            color: #2c2c2c !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            text-decoration: none !important;
            line-height: normal !important;
            display: block !important;
            margin-bottom: 2px !important;
        }

        .woof_husky_txt-option:hover .woof_husky_txt-option-title a {
            color: #0e3780 !important; /* Azul Uônix */
        }

        .woof_husky_txt-option-text {
            font-size: 12px !important;
            color: #64748b !important;
            line-height: 1.4 !important;
        }

        /* 4. LIMPEZA DE BREADCRUMB (REMOVER INÍCIO) */
        .woof_husky_txt-option-breadcrumb {
            font-size: 0 !important;
            margin-bottom: 0px !important;
			padding-bottom: 0px !important;
			line-height: normal !important;
        }

        .woof_husky_txt-option-breadcrumb a {
            font-size: 11px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            font-weight: 600 !important;
            color: #f76a0c !important; /* Laranja Uônix */
            text-decoration: none !important;
        }

        .woof_husky_txt-option-breadcrumb a:first-child {
            display: none !important;
        }

        /* 5. AUXILIARES */
        .woof_husky_txt.uonix-hidden { display: none !important; }

        /* 6. AVISO DE NENHUM PRODUTO ENCONTRADO (IDENTIDADE VISUAL UÔNIX) */
        .woocommerce-no-products-found {
            margin: 20px 0 35px 0 !important;
            width: 100% !important;
        }

        .woocommerce-no-products-found .woocommerce-info {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-left: 4px solid #0e3780 !important; /* Azul Uônix */
            border-radius: 8px !important;
            box-shadow: 0 4px 18px -2px rgba(14, 55, 128, 0.07), 0 2px 6px -1px rgba(0, 0, 0, 0.04) !important;
            padding: 18px 22px !important;
            margin: 0 !important;
            color: #123063 !important; /* Azul nobre escuro */
            font-size: 15px !important;
            font-weight: 600 !important;
            line-height: 1.5 !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            position: relative !important;
        }

        .woocommerce-no-products-found .woocommerce-info::before {
            content: "" !important;
            display: inline-flex !important;
            width: 22px !important;
            height: 22px !important;
            min-width: 22px !important;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230e3780' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3Cpath d='M11 8v4'%3E%3C/path%3E%3Cpath d='M11 15h.01'%3E%3C/path%3E%3C/svg%3E") no-repeat center center / contain !important;
            margin: 0 !important;
            position: static !important;
            flex-shrink: 0 !important;
        }
    </style>

    <script id="uonix-husky-integrated-js">
    (function($) {
        $(document).ready(function() {
            
            // A) CLIQUE FORA: Esconde resultados ao perder o foco da área de busca
            $(document).on('mousedown touchstart', function(e) {
                var container = $(".woof_text_search_container");
                if (!container.is(e.target) && container.has(e.target).length === 0) {
                    $(".woof_husky_txt").hide();
                }
            });

            // B) TECLA ENTER: Esconde resultados e tira o foco
            $(document).on('keydown', '.woof_husky_txt-input', function(e) {
                if (e.which == 13) {
                    var $input = $(this);
                    var $results = $input.closest('.woof_container_inner').find('.woof_husky_txt');

                    setTimeout(function() {
                        $results.hide();
                        $input.blur(); 
                    }, 100);
                }
            });

            // C) VOLTAR A DIGITAR: Reabre a lista se houver foco ou interação
            $(document).on('input focus', '.woof_husky_txt-input', function() {
                $(this).closest('.woof_container_inner').find('.woof_husky_txt').show();
            });

        });
    })(jQuery);
    </script>
    <?php
}, 999);


