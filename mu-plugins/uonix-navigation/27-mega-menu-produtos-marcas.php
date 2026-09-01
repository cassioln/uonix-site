<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UONIX Snippets - Mega menu - produtos, categorias e vitrine de marcas.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 8813-9155 do export original.
// -----------------------------------------------------------------------------
/**
 * Produtos Mega Menu
 */
/**
 * UÔNIX: Master Premium V17.0 (Mega Menu Dinâmico com Descrição Completa e Posições Travadas)
 * - Exibição da descrição completa da categoria e produtos sem cortes artificiais
 * - Posição das grades de links 100% travada e estável em todos os estados de hover
 * - Header interativo superior nas categorias normais e palco dinâmico nobre em Olhais
 * - Anti-Squash Absoluto para Header Fixo do Kadence
 */

// ==============================================================================
// 1. ESTILOS COMPARTILHADOS (MEGA MENU E MARCAS)
// ==============================================================================
add_action('wp_head', 'uonix_estilos_mega_menu_v14');
function uonix_estilos_mega_menu_v14()
{
    ?>
    <style id="uonix-megamenu-hybrid-css">
        /* ==========================================================
                           MEGA MENU: ESTRUTURA E PAINÉIS PRINCIPAIS
                           ========================================================== */
        .uonix-dynamic-cats-wrapper,
        .uonix-dc-panel {
            pointer-events: none !important;
        }

        #mega-menu-wrap-primary .mega-menu-item.mega-toggle-on .uonix-dynamic-cats-wrapper,
        #mega-menu-wrap-primary .mega-menu-item.mega-hover .uonix-dynamic-cats-wrapper,
        #mega-menu-wrap-primary .mega-menu-item:hover .uonix-dynamic-cats-wrapper,
        #mega-menu-wrap-primary .mega-menu-item.mega-toggle-on .uonix-dc-panel,
        #mega-menu-wrap-primary .mega-menu-item.mega-hover .uonix-dc-panel,
        #mega-menu-wrap-primary .mega-menu-item:hover .uonix-dc-panel {
            pointer-events: auto !important;
        }

        .uonix-dynamic-cats-wrapper {
            position: relative !important;
            display: flex !important;
            width: 100% !important;
            min-height: 420px !important;
            background: #ffffff !important;
            border: 2px solid #e2e8f0 !important;
            border-radius: 8px 8px 0 0 !important;
            border-bottom: none !important;
            margin-bottom: 0 !important;
            overflow: hidden !important;
            box-shadow: none !important;
        }

        /* SIDEBAR LATERAL DE CATEGORIAS */
        .uonix-dc-sidebar {
            width: clamp(260px, 20vw, 300px) !important;
            flex-shrink: 0 !important;
            background: #f8fafc !important;
            display: flex !important;
            flex-direction: column !important;
            border-right: 1px solid #e2e8f0 !important;
        }

        .uonix-dc-catalog-btn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #0e3780 !important;
            color: #ffffff !important;
            padding: 15px !important;
            font-size: 15px !important;
            font-weight: 800 !important;
            text-decoration: none !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            transition: all 0.3s ease !important;
        }

        .uonix-dc-catalog-btn:hover {
            background: rgba(10, 39, 91, 0.92) !important;
            box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.4) !important;
        }

        .uonix-dc-list {
            margin: 0 !important;
            padding: 0 !important;
            list-style: none !important;
            flex: 1 !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .uonix-dc-item {
            border-bottom: 1px solid #e2e8f0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .uonix-dc-link {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 15px 20px !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            color: #475569 !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            border-left: 4px solid transparent !important;
            text-transform: uppercase !important;
        }

        .uonix-dc-item:hover .uonix-dc-link,
        .uonix-dc-list:not(:hover) .uonix-dc-item:first-child .uonix-dc-link {
            background: #ffffff !important;
            color: #0e3780 !important;
            border-left: 4px solid #f76a0c !important;
        }

        /* PAINEL DE CONTEÚDO DA CATEGORIA */
        .uonix-dc-panel {
            position: absolute !important;
            top: 0 !important;
            left: clamp(260px, 20vw, 300px) !important;
            right: 0 !important;
            bottom: 0 !important;
            height: 100% !important;
            padding: 22px 30px !important;
            background: #ffffff !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            opacity: 0 !important;
            visibility: hidden !important;
            z-index: 1 !important;
            box-sizing: border-box !important;
            transition: opacity 0.15s ease, visibility 0.15s ease !important;
        }

        .uonix-dc-item:first-child .uonix-dc-panel {
            opacity: 1 !important;
            visibility: visible !important;
            z-index: 2 !important;
            transition: none !important;
        }

        .uonix-dc-item:hover .uonix-dc-panel {
            opacity: 1 !important;
            visibility: visible !important;
            z-index: 10 !important;
            transition: opacity 0.15s ease, visibility 0.15s ease !important;
        }

        /* ==========================================================
                           1. LAYOUT CATEGORIAS PADRÃO (FIXAÇÃO QUÍMICA, MECÂNICA, ACESSÓRIOS)
                           ========================================================== */

        /* PARTE SUPERIOR: HEADER DINÂMICO COM ALTURA TRAVADA */
        .uonix-dc-header {
            display: flex !important;
            align-items: center !important;
            gap: 24px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            padding-bottom: 14px !important;
            margin-bottom: 10px !important;
            height: 152px !important;
            min-height: 152px !important;
            max-height: 152px !important;
            flex-shrink: 0 !important;
            box-sizing: border-box !important;
        }

        .uonix-dc-header-media {
            width: 230px !important;
            min-width: 230px !important;
            height: 136px !important;
            background: #f8fafc !important;
            border: 1px solid #edf2f7 !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            overflow: hidden !important;
            padding: 6px !important;
            box-sizing: border-box !important;
            flex-shrink: 0 !important;
        }

        .uonix-header-brand-badge {
            position: absolute !important;
            top: 7px !important;
            left: 7px !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            color: #0e3780 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            background: rgba(233, 243, 255, 0.95) !important;
            border: 1px solid #dbeafe !important;
            padding: 2px 7px !important;
            border-radius: 4px !important;
            line-height: 1.2 !important;
            z-index: 2 !important;
            transition: all 0.2s ease !important;
        }

        .uonix-dc-info {
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            flex: 1 !important;
            height: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }

        .uonix-header-title {
            font-size: 24px !important;
            text-transform: uppercase !important;
            color: #0e3780 !important;
            font-weight: 800 !important;
            margin: 0 !important;
            letter-spacing: -0.3px !important;
            line-height: 1.15 !important;
        }

        /* ÁREA DE DESCRIÇÃO DINÂMICA COM ALTURA RIGOROSAMENTE FIXA */
        .uonix-header-desc-wrap {
            height: 56px !important;
            min-height: 56px !important;
            max-height: 56px !important;
            overflow-y: auto !important;
            scrollbar-width: thin !important;
            margin: 4px 0 !important;
            width: 100% !important;
        }

        .uonix-header-desc {
            font-size: 13.5px !important;
            color: #64748b !important;
            margin: 0 !important;
            line-height: 1.4 !important;
            transition: color 0.2s ease !important;
        }

        /* PARTE INFERIOR: GRADE DE PRODUTOS EM 3 COLUNAS */
        .uonix-dc-products-grid.uonix-grid-3cols {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 8px 14px !important;
            flex: 1 !important;
            align-content: start !important;
            margin-top: 4px !important;
        }

        /* LINKS DE PRODUTOS ESTILIZADOS E INTERATIVOS */
        .uonix-prod-stage-item {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 7px 10px !important;
            background: #ffffff !important;
            border: 1px solid #f1f5f9 !important;
            border-left: 3.5px solid #e9f3ff !important;
            border-radius: 4px !important;
            color: #475569 !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            line-height: 1.3 !important;
            box-sizing: border-box !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
            cursor: pointer !important;
        }

        .uonix-prod-bullet {
            width: 5px !important;
            height: 5px !important;
            background: #94a3b8 !important;
            border-radius: 50% !important;
            margin-right: 7px !important;
            flex-shrink: 0 !important;
            transition: background 0.2s !important;
        }

        .uonix-prod-label {
            flex: 1 !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }

        .uonix-prod-hover-arrow {
            opacity: 0 !important;
            transform: translateX(-4px) !important;
            color: #f76a0c !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            margin-left: 4px !important;
            transition: all 0.2s ease !important;
        }

        .uonix-prod-stage-item:hover,
        .uonix-prod-stage-item.is-active {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
            border-left: 3.5px solid #f76a0c !important;
            color: #0e3780 !important;
            box-shadow: 0 3px 8px rgba(14, 55, 128, 0.06) !important;
            transform: translateX(3px) !important;
        }

        .uonix-prod-stage-item:hover .uonix-prod-bullet,
        .uonix-prod-stage-item.is-active .uonix-prod-bullet {
            background: #f76a0c !important;
        }

        .uonix-prod-stage-item:hover .uonix-prod-hover-arrow,
        .uonix-prod-stage-item.is-active .uonix-prod-hover-arrow {
            opacity: 1 !important;
            transform: translateX(0) !important;
        }

        /* LINK "VER TODA A LINHA" */
        .uonix-link-ver-linha {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 13.5px !important;
            font-weight: 800 !important;
            line-height: 1.3 !important;
            color: #f76a0c !important;
            text-decoration: none !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
            transition: all 0.2s ease !important;
        }

        .uonix-link-ver-linha:hover {
            color: #0e3780 !important;
            transform: translateX(4px) !important;
        }

        /* ==========================================================
                           2. LAYOUT ESPECIAL DESTAQUE: OLHAIS DE ANCORAGEM (CATEGORIA 1)
                           ========================================================== */
        .uonix-dc-panel-featured {
            padding: 24px 30px !important;
            flex-direction: row !important;
            align-items: stretch !important;
            gap: 32px !important;
        }

        .uonix-featured-text {
            flex: 1.25 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            height: 100% !important;
            min-width: 0 !important;
        }

        .uonix-featured-header {
            width: 100% !important;
            margin-bottom: 6px !important;
        }

        .uonix-featured-title {
            font-size: 26px !important;
            color: #0e3780 !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            margin: 0 0 10px 0 !important;
            line-height: 1.1 !important;
            letter-spacing: -0.5px !important;
        }

        /* CONTAINER DE DESCRIÇÃO COMPLETA DA CATEGORIA EM OLHAIS */
        .uonix-featured-desc-wrap {
            height: 90px !important;
            min-height: 150px !important;
            max-height: 90px !important;
            overflow-y: auto !important;
            scrollbar-width: thin !important;
            width: 100% !important;
            padding-right: 4px !important;
        }

        .uonix-featured-desc {
            font-size: 13.5px !important;
            color: #64748b !important;
            line-height: 1.45 !important;
            margin: 0 !important;
        }

        .uonix-featured-products {
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            width: 100% !important;
            margin: 8px 0 !important;
        }

        .uonix-featured-image-stage {
            flex: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            height: 100% !important;
            min-height: 270px !important;
        }

        .uonix-stage-frame {
            width: 100% !important;
            height: 100% !important;
            background: #f8fafc !important;
            border: 1.5px solid #edf2f7 !important;
            border-radius: 12px !important;
            padding: 14px !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: space-between !important;
            box-sizing: border-box !important;
            position: relative !important;
            box-shadow: 0 8px 24px -4px rgba(14, 55, 128, 0.07) !important;
        }

        .uonix-stage-top-tag {
            width: 100% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            margin-bottom: 4px !important;
        }

        .uonix-stage-brand-label {
            font-size: 10.5px !important;
            font-weight: 800 !important;
            color: #0e3780 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            background: #e9f3ff !important;
            padding: 3px 8px !important;
            border-radius: 4px !important;
        }

        .uonix-stage-status-dot {
            width: 6px !important;
            height: 6px !important;
            background: #10b981 !important;
            border-radius: 50% !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2) !important;
        }

        .uonix-stage-media-wrap {
            flex: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            overflow: hidden !important;
            padding: 4px 0 !important;
        }

        .uonix-stage-bottom-info {
            width: 100% !important;
            text-align: center !important;
            margin-top: 4px !important;
            padding: 0 !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            transition: all 0.2s ease !important;
        }

        .uonix-stage-dyn-title {
            font-size: 14.5px !important;
            font-weight: 800 !important;
            color: #0e3780 !important;
            display: block !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            margin-bottom: 2px !important;
            line-height: 1.25 !important;
        }

        .uonix-stage-dyn-desc {
            font-size: 11.5px !important;
            color: #64748b !important;
            line-height: 1.35 !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            margin: 0 !important;
            min-height: 28px !important;
        }

        /* ==========================================================
                           TRAVAS ABSOLUTAS CONTRA ACHATAMENTO NO MENU FIXO (ANTI-SQUASH)
                           ========================================================== */
        #mega-menu-wrap-primary .uonix-dc-header-img,
        .is-sticky .uonix-dc-header-img,
        .site-header-sticky-inner .uonix-dc-header-img,
        .header-desktop-sticky .uonix-dc-header-img,
        .kadence-sticky-header .uonix-dc-header-img,
        .uonix-dc-header-img {
            width: 100% !important;
            height: 124px !important;
            min-height: 124px !important;
            max-height: 124px !important;
            object-fit: contain !important;
            background: transparent !important;
            border-radius: 4px !important;
            flex-shrink: 0 !important;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        #mega-menu-wrap-primary .uonix-featured-img,
        .is-sticky .uonix-featured-img,
        .site-header-sticky-inner .uonix-featured-img,
        .header-desktop-sticky .uonix-featured-img,
        .kadence-sticky-header .uonix-featured-img,
        .uonix-featured-img {
            width: 100% !important;
            height: 200px !important;
            min-height: 200px !important;
            max-height: 215px !important;
            object-fit: contain !important;
            background: transparent !important;
            border-radius: 6px !important;
            flex-shrink: 0 !important;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        /* ==========================================================
                           MEGA MARCAS ROW
                           ========================================================== */
        #mega-menu-wrap-primary #mega-menu-primary li.mega-menu-row:has(.uonix-mega-brands-wrap) {
            background: #ffffff !important;
            border: 2px solid #e2e8f0 !important;
            border-top: 1px solid #f1f5f9 !important;
            border-radius: 0 0 8px 8px !important;
            margin-top: -2px !important;
            padding: 22px 30px !important;
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.06) !important;
            display: block !important;
        }

        .uonix-mega-brands-wrap .mega-block-title {
            font-size: 16px !important;
            color: #94a3b8 !important;
            text-transform: uppercase !important;
            letter-spacing: 1.2px !important;
            margin-bottom: 16px !important;
            margin-left: 4px !important;
            font-weight: 700 !important;
        }

        .uonix-brands-grid {
            display: grid !important;
            grid-template-columns: repeat(6, 1fr) !important;
            gap: 12px !important;
            margin-bottom: 4px !important;
        }

        .uonix-brand-item {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #ffffff !important;
            border: 1px solid #f1f5f9 !important;
            border-radius: 6px !important;
            width: 100% !important;
            padding: 0 15px !important;
            transition: all 0.3s ease !important;
            text-decoration: none !important;
        }

        .uonix-brand-item:hover {
            border-color: #0e3780 !important;
            transform: translateY(-4px) !important;
        }

        .uonix-brand-item img {
            max-height: none !important;
            min-height: 42px !important;
            height: 42px !important;
            width: auto !important;
            filter: grayscale(1) opacity(0.6);
            transition: 0.3s ease;
            object-fit: contain !important;
            flex-shrink: 0 !important;
        }

        .uonix-brand-item:hover img {
            filter: grayscale(0) opacity(1);
        }
    </style>

    <script id="uonix-megamenu-hybrid-js">
        (function () {
            function initUonixMegaMenuHybrid() {
                var panels = document.querySelectorAll('.uonix-dc-panel');
                if (!panels.length) return;

                panels.forEach(function (panel) {
                    var defaultImg = panel.getAttribute('data-default-img');
                    var defaultTitle = panel.getAttribute('data-default-title');
                    var defaultDesc = panel.getAttribute('data-default-desc');
                    var defaultBrand = panel.getAttribute('data-default-brand') || 'UÔNIX';

                    var isFeatured = panel.classList.contains('uonix-dc-panel-featured');
                    var imgElem = panel.querySelector('.uonix-dc-header-img, .uonix-featured-img');
                    var descElem = isFeatured ? null : panel.querySelector('.uonix-header-desc');
                    var brandElem = panel.querySelector('.uonix-header-brand-badge, .uonix-stage-brand-label');
                    var dynTitle = panel.querySelector('.uonix-stage-dyn-title');
                    var dynDesc = panel.querySelector('.uonix-stage-dyn-desc');
                    var stageBottom = panel.querySelector('.uonix-stage-bottom-info');

                    var items = panel.querySelectorAll('.uonix-prod-stage-item');
                    var gridContainer = panel.querySelector('.uonix-dc-products-grid, .uonix-featured-products');

                    items.forEach(function (item) {
                        item.addEventListener('mouseenter', function () {
                            var newImg = item.getAttribute('data-img');
                            var newTitle = item.getAttribute('data-title');
                            var newDesc = item.getAttribute('data-desc');
                            var newBrand = item.getAttribute('data-brand');

                            if (imgElem && newImg && imgElem.src !== newImg) {
                                imgElem.style.opacity = '0.25';
                                imgElem.style.transform = 'scale(0.96)';
                                setTimeout(function () {
                                    imgElem.src = newImg;
                                    imgElem.alt = newTitle || '';
                                    imgElem.style.opacity = '1';
                                    imgElem.style.transform = 'scale(1)';
                                }, 70);
                            }

                            if (brandElem && newBrand) {
                                brandElem.textContent = newBrand;
                            }

                            if (descElem && newDesc) {
                                descElem.textContent = newDesc;
                            }

                            if (dynTitle && newTitle) {
                                dynTitle.textContent = newTitle;
                            }

                            if (dynDesc && newDesc) {
                                dynDesc.textContent = newDesc;
                            }

                            if (stageBottom) {
                                stageBottom.style.display = 'block';
                            }

                            items.forEach(function (i) { i.classList.remove('is-active'); });
                            item.classList.add('is-active');
                        });
                    });

                    if (gridContainer) {
                        gridContainer.addEventListener('mouseleave', function () {
                            if (imgElem && defaultImg && imgElem.src !== defaultImg) {
                                imgElem.style.opacity = '0.25';
                                imgElem.style.transform = 'scale(0.96)';
                                setTimeout(function () {
                                    imgElem.src = defaultImg;
                                    imgElem.alt = defaultTitle || '';
                                    imgElem.style.opacity = '1';
                                    imgElem.style.transform = 'scale(1)';
                                }, 70);
                            }

                            if (brandElem) {
                                brandElem.textContent = defaultBrand;
                            }

                            if (descElem && defaultDesc) {
                                descElem.textContent = defaultDesc;
                            }

                            if (stageBottom) {
                                stageBottom.style.display = 'none';
                            }

                            if (dynTitle) {
                                dynTitle.textContent = '';
                            }

                            if (dynDesc) {
                                dynDesc.textContent = '';
                            }

                            items.forEach(function (i) { i.classList.remove('is-active'); });
                        });
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initUonixMegaMenuHybrid);
            } else {
                initUonixMegaMenuHybrid();
            }
        })();
    </script>
    <?php
}

// ==============================================================================
// 2. SHORTCODE MEGA MENU
// ==============================================================================
add_shortcode('uonix_menu_categorias', 'uonix_gerar_mega_menu_v14');
function uonix_gerar_mega_menu_v14()
{
    $categorias = [
        'olhais' => ['titulo' => 'Olhais de Ancoragem', 'slug' => 'olhal-de-ancoragem'],
        'quimica' => ['titulo' => 'Fixação Química', 'slug' => 'fixacao-quimica'],
        'mecanica' => ['titulo' => 'Fixação Mecânica', 'slug' => 'fixacao-mecanica'],
        'acessorios' => ['titulo' => 'Acessórios', 'slug' => 'acessorios'],
    ];

    ob_start();
    ?>
    <div class="uonix-dynamic-cats-wrapper">
        <div class="uonix-dc-sidebar">
            <a href="/produtos/#catalogo-produtos" class="uonix-dc-catalog-btn">Acessar Catálogo</a>
            <ul class="uonix-dc-list">
                <?php
                $index = 0;
                foreach ($categorias as $cat):
                    $is_featured = ($index === 0);
                    $index++;

                    $term = get_term_by('slug', $cat['slug'], 'product_cat');
                    $link_padrao = get_term_link($term) . '#catalogo-produtos';
                    $link_husky = '/produtos/swoof2/product_cat-' . $cat['slug'] . '/#catalogo-produtos';

                    // Descrição completa da categoria
                    $descricao = (!empty($term) && !empty($term->description)) ? wp_strip_all_tags($term->description) : 'Confira a nossa linha completa para fixação e ancoragem.';

                    // Limite: 4 produtos na categoria destaque, 9 nas categorias padrão (3 colunas x 3 linhas)
                    $product_limit = $is_featured ? 4 : 9;
                    $args = [
                        'post_type' => 'product',
                        'posts_per_page' => $product_limit,
                        'orderby' => 'menu_order title',
                        'order' => 'ASC',
                        'tax_query' => [
                            [
                                'taxonomy' => 'product_cat',
                                'field' => 'slug',
                                'terms' => $cat['slug']
                            ]
                        ]
                    ];
                    $produtos = new WP_Query($args);

                    // Imagem da Categoria para fallback
                    $imagem_final = '';
                    if (!empty($term)) {
                        $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                        if ($thumbnail_id) {
                            $imagem_final = wp_get_attachment_url($thumbnail_id);
                        }
                    }
                    if (empty($imagem_final) && $produtos->have_posts()) {
                        $imagem_final = get_the_post_thumbnail_url($produtos->posts[0]->ID, 'medium_large');
                    }
                    if (empty($imagem_final) && function_exists('wc_placeholder_img_src')) {
                        $imagem_final = wc_placeholder_img_src('woocommerce_single');
                    }

                    // Processar lista de produtos estruturada com breve descrição
                    $produtos_lista = [];
                    if ($produtos->have_posts()) {
                        while ($produtos->have_posts()) {
                            $produtos->the_post();
                            $p_id = get_the_ID();
                            $p_title = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
                            $p_link = get_the_permalink() . '#catalogo-produtos';

                            $p_img = get_the_post_thumbnail_url($p_id, 'medium');
                            if (empty($p_img) && function_exists('wc_placeholder_img_src')) {
                                $p_img = wc_placeholder_img_src('woocommerce_thumbnail');
                            }

                            // Identificação do fabricante / marca
                            $p_marca = '';
                            $brands = wp_get_post_terms($p_id, 'product_brand');
                            if (!is_wp_error($brands) && !empty($brands)) {
                                $p_marca = $brands[0]->name;
                            }
                            if (empty($p_marca)) {
                                $pa_brands = wp_get_post_terms($p_id, 'pa_marca');
                                if (!is_wp_error($pa_brands) && !empty($pa_brands)) {
                                    $p_marca = $pa_brands[0]->name;
                                }
                            }
                            if (empty($p_marca)) {
                                $p_marca = 'UÔNIX';
                            }

                            // Descrição do produto completa e limpa
                            $p_desc = get_the_excerpt($p_id);
                            if (empty($p_desc)) {
                                $p_desc = wp_strip_all_tags(get_post_field('post_content', $p_id));
                            }
                            $p_desc = wp_strip_all_tags(strip_shortcodes($p_desc));
                            if (empty($p_desc)) {
                                $p_desc = $descricao;
                            }

                            $produtos_lista[] = [
                                'id' => $p_id,
                                'title' => $p_title,
                                'link' => $p_link,
                                'img' => $p_img,
                                'brand' => $p_marca,
                                'desc' => $p_desc,
                            ];
                        }
                        wp_reset_postdata();
                    }

                    $titulo_limpo = str_replace(['⚙️ ', '🧪 ', '🔗 ', '🛠️ '], '', $cat['titulo']);
                    ?>
                    <li class="uonix-dc-item">
                        <a href="<?php echo esc_url($link_husky); ?>" class="uonix-dc-link">
                            <span class="uonix-dc-text"><?php echo esc_html($cat['titulo']); ?></span>
                            <span class="uonix-dc-arrow">&rsaquo;</span>
                        </a>

                        <?php if ($is_featured): ?>
                            <!-- CATEGORIA 1: DESTAQUE NOBRE (OLHAIS DE ANCORAGEM) -->
                            <div class="uonix-dc-panel uonix-dc-panel-featured"
                                data-default-img="<?php echo esc_url($imagem_final); ?>"
                                data-default-title="<?php echo esc_attr($titulo_limpo); ?>"
                                data-default-desc="<?php echo esc_attr($descricao); ?>" data-default-brand="UÔNIX"
                                data-default-link="<?php echo esc_url($link_padrao); ?>">

                                <div class="uonix-featured-text">
                                    <div class="uonix-featured-header">
                                        <h5 class="uonix-featured-title"><?php echo esc_html($titulo_limpo); ?></h5>
                                        <div class="uonix-featured-desc-wrap">
                                            <p class="uonix-featured-desc"><?php echo esc_html($descricao); ?></p>
                                        </div>
                                    </div>

                                    <div class="uonix-featured-products">
                                        <?php if (!empty($produtos_lista)):
                                            foreach ($produtos_lista as $prod_item): ?>
                                                <a href="<?php echo esc_url($prod_item['link']); ?>" class="uonix-prod-stage-item"
                                                    data-img="<?php echo esc_url($prod_item['img']); ?>"
                                                    data-title="<?php echo esc_attr($prod_item['title']); ?>"
                                                    data-desc="<?php echo esc_attr($prod_item['desc']); ?>"
                                                    data-brand="<?php echo esc_attr($prod_item['brand']); ?>"
                                                    data-link="<?php echo esc_url($prod_item['link']); ?>">
                                                    <span class="uonix-prod-bullet"></span>
                                                    <span class="uonix-prod-label"><?php echo esc_html($prod_item['title']); ?></span>
                                                    <span class="uonix-prod-hover-arrow">&rsaquo;</span>
                                                </a>
                                                <?php
                                            endforeach;
                                        endif; ?>
                                    </div>

                                    <a href="<?php echo esc_url($link_padrao); ?>" class="uonix-link-ver-linha">
                                        Ver toda a linha <?php echo esc_html($titulo_limpo); ?> &rarr;
                                    </a>
                                </div>

                                <div class="uonix-featured-image-stage">
                                    <div class="uonix-stage-frame">
                                        <div class="uonix-stage-top-tag">
                                            <span class="uonix-stage-brand-label">UÔNIX</span>
                                            <span class="uonix-stage-status-dot"></span>
                                        </div>
                                        <div class="uonix-stage-media-wrap">
                                            <img class="uonix-featured-img" src="<?php echo esc_url($imagem_final); ?>"
                                                alt="<?php echo esc_attr($titulo_limpo); ?>" loading="lazy" width="280"
                                                height="200" />
                                        </div>
                                        <div class="uonix-stage-bottom-info" style="display: none;">
                                            <span class="uonix-stage-dyn-title"></span>
                                            <p class="uonix-stage-dyn-desc"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- CATEGORIAS 2, 3, 4: DISPOSIÇÃO SUPERIOR (FOTO + INFOS) + GRADE EM 3 COLUNAS -->
                            <div class="uonix-dc-panel uonix-dc-panel-standard"
                                data-default-img="<?php echo esc_url($imagem_final); ?>"
                                data-default-title="<?php echo esc_attr($titulo_limpo); ?>"
                                data-default-desc="<?php echo esc_attr($descricao); ?>" data-default-brand="UÔNIX"
                                data-default-link="<?php echo esc_url($link_padrao); ?>">

                                <!-- HEADER DINÂMICO SUPERIOR COM ALTURA TRAVADA -->
                                <div class="uonix-dc-header">
                                    <div class="uonix-dc-header-media">
                                        <span class="uonix-header-brand-badge">UÔNIX</span>
                                        <img class="uonix-dc-header-img" src="<?php echo esc_url($imagem_final); ?>"
                                            alt="<?php echo esc_attr($titulo_limpo); ?>" loading="lazy" width="230" height="124" />
                                    </div>
                                    <div class="uonix-dc-info">
                                        <h5 class="uonix-header-title"><?php echo esc_html($titulo_limpo); ?></h5>
                                        <div class="uonix-header-desc-wrap">
                                            <p class="uonix-header-desc"><?php echo esc_html($descricao); ?></p>
                                        </div>

                                        <a href="<?php echo esc_url($link_padrao); ?>" class="uonix-link-ver-linha">
                                            Ver toda a linha <?php echo esc_html($titulo_limpo); ?> &rarr;
                                        </a>
                                    </div>
                                </div>

                                <!-- GRADE DE PRODUTOS EM 3 COLUNAS -->
                                <div class="uonix-dc-products-grid uonix-grid-3cols">
                                    <?php if (!empty($produtos_lista)):
                                        foreach ($produtos_lista as $prod_item): ?>
                                            <a href="<?php echo esc_url($prod_item['link']); ?>" class="uonix-prod-stage-item"
                                                data-img="<?php echo esc_url($prod_item['img']); ?>"
                                                data-title="<?php echo esc_attr($prod_item['title']); ?>"
                                                data-desc="<?php echo esc_attr($prod_item['desc']); ?>"
                                                data-brand="<?php echo esc_attr($prod_item['brand']); ?>"
                                                data-link="<?php echo esc_url($prod_item['link']); ?>">
                                                <span class="uonix-prod-bullet"></span>
                                                <span class="uonix-prod-label"><?php echo esc_html($prod_item['title']); ?></span>
                                                <span class="uonix-prod-hover-arrow">&rsaquo;</span>
                                            </a>
                                            <?php
                                        endforeach;
                                    else:
                                        echo '<span style="color:#64748b; font-size:14px;">Nenhum produto cadastrado nesta categoria.</span>';
                                    endif;
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ==============================================================================
// 3. SHORTCODE VITRINE DE MARCAS
// ==============================================================================
add_shortcode('uonix_vitrine_marcas', 'uonix_gerar_grid_marcas_premium_v14');

function uonix_gerar_grid_marcas_premium_v14()
{

    // Marcas que podem aparecer, por slug
    $marcas_visiveis = ['walsywa', 'ancora', 'tekbond', 'uonix'];

    // Blacklist de marcas/fabricantes por ID
    $fabricantes_blacklist = [72];

    $taxonomy = 'product_brand';

    $args = [
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ];

    if (!empty($marcas_visiveis)) {
        $args['slug'] = $marcas_visiveis;
        $args['orderby'] = 'slug__in';
    }

    if (!empty($fabricantes_blacklist)) {
        $args['exclude'] = $fabricantes_blacklist;
    }

    $terms = get_terms($args);

    if (empty($terms) || is_wp_error($terms)) {
        return '';
    }

    $marcas_fixas = [
        'uonix' => [
            'nome' => 'Uônix',
            'logo' => 'https://uonix.com.br/wp-content/uploads/2026/02/uonix.webp',
            'link' => 'https://uonix.com.br/produtos/swoof2/product_brand-uonix/#catalogo-produtos',
        ],
        'walsywa' => [
            'nome' => 'Walsywa',
            'logo' => 'https://uonix.com.br/wp-content/uploads/2026/02/walsywa.webp',
            'link' => 'https://uonix.com.br/produtos/swoof2/product_brand-walsywa/#catalogo-produtos',
        ],
        'ancora' => [
            'nome' => 'Âncora',
            'logo' => 'https://uonix.com.br/wp-content/uploads/2026/02/ancora.webp',
            'link' => 'https://uonix.com.br/produtos/swoof2/product_brand-ancora/#catalogo-produtos',
        ],
        'tekbond' => [
            'nome' => 'Tekbond',
            'logo' => 'https://uonix.com.br/wp-content/uploads/2026/02/tekbond.webp',
            'link' => 'https://uonix.com.br/produtos/swoof2/product_brand-tekbond/#catalogo-produtos',
        ],
    ];

    ob_start();
    ?>
    <div class="uonix-mega-brands-wrap">
        <div class="mega-block-title">Compre por Fabricante</div>
        <div class="uonix-brands-grid">
            <?php
            foreach ($terms as $term):
                $slug = $term->slug;
                $logo_url = '';
                $link_final = '';

                if (isset($marcas_fixas[$slug])) {
                    $logo_url = $marcas_fixas[$slug]['logo'];
                    $link_final = $marcas_fixas[$slug]['link'];
                }

                if (empty($link_final)) {
                    $link_final = '/produtos/swoof2/product_brand-' . $slug . '/#catalogo-produtos';
                }

                if (empty($logo_url)) {
                    $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                    if ($thumbnail_id) {
                        $logo_url = wp_get_attachment_url($thumbnail_id);
                    }
                }

                if (empty($logo_url)) {
                    continue;
                }
                ?>
                <a href="<?php echo esc_url($link_final); ?>" class="uonix-brand-item"
                    title="<?php echo esc_attr($term->name); ?>">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy"
                        width="120" height="42">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
