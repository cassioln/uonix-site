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

            /* Força a Sidebar a existir e ser visível */
            .kadence-column2932_0a9c6d-62 {
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
            .kadence-column2932_fec114-b0 {
                flex: 0 0 70% !important;
                max-width: 70% !important;
            }
        }

        /* 2. REGRAS PARA CELULAR (< 768px) */
        @media (max-width: 767px) {
            .kadence-column2932_0a9c6d-62 { display: none !important; }
            .kadence-column2932_fec114-b0 { flex: 0 0 100% !important; max-width: 100% !important; }
            
            .woof_show_filter_for_mobile.woof {
                position: fixed !important;
                z-index: 9999 !important;
                height: 100% !important;
                width: 100% !important;
                left: 0 !important;
                display: block !important;
                animation: move_top .5s ease forwards;
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

        $(document).ready(function() { setTimeout(updateHuskyLayout, 600); });
        $(window).on('resize', updateHuskyLayout);
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


