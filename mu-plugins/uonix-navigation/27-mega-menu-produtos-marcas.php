<?php
if ( ! defined( 'ABSPATH' ) ) {
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
 * UÔNIX: Master Premium V15.0 (Mega Menu com Vitrine Interativa + Marcas)
 * - Vitrine Interativa de Alta Definição com Palco de Preview Padronizado
 * - Troca dinâmica e suave de imagem, marca e detalhes no hover do produto
 * - Anti-Squash Absoluto para Header Fixo do Kadence
 */

// ==============================================================================
// 1. ESTILO COMPARTILHADO (MEGA MENU E MARCAS)
// ==============================================================================
add_action('wp_head', 'uonix_estilos_mega_menu_v14');
function uonix_estilos_mega_menu_v14() {
    ?>
    <style id="uonix-megamenu-stage-css">
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
            box-shadow: inset 0 0 40px rgba(0,0,0,0.4) !important; 
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
            padding: 24px 30px !important; 
            background: #ffffff !important; 
            display: flex !important; 
            flex-direction: column !important; 
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
           GRID INTERNO DO PAINEL: LISTA + PALCO DE PREVIEW
           ========================================================== */
        .uonix-dc-panel-grid {
            display: grid !important;
            grid-template-columns: 1fr 270px !important;
            gap: 25px !important;
            height: 100% !important;
            align-items: stretch !important;
            box-sizing: border-box !important;
        }

        .uonix-dc-panel-main {
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            min-width: 0 !important;
        }

        /* CABEÇALHO DA CATEGORIA */
        .uonix-dc-header-wrap {
            margin-bottom: 12px !important;
        }

        .uonix-dc-badge {
            display: inline-block !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            color: #f76a0c !important;
            text-transform: uppercase !important;
            letter-spacing: 0.8px !important;
            margin-bottom: 4px !important;
        }

        .uonix-dc-title {
            font-size: 24px !important;
            color: #0e3780 !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            margin: 0 0 6px 0 !important;
            line-height: 1.15 !important;
            letter-spacing: -0.3px !important;
        }

        .uonix-dc-desc {
            font-size: 14px !important;
            color: #64748b !important;
            line-height: 1.4 !important;
            margin: 0 !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
        }

        /* GRADE DE PRODUTOS INTERATIVA */
        .uonix-dc-products-grid {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 8px 12px !important;
            margin: 10px 0 !important;
            flex: 1 !important;
            align-content: start !important;
        }

        .uonix-prod-stage-item {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 8px 12px !important;
            background: #f8fafc !important;
            border: 1px solid #f1f5f9 !important;
            border-left: 3px solid #e2e8f0 !important;
            border-radius: 6px !important;
            color: #334155 !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            line-height: 1.3 !important;
            box-sizing: border-box !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
            cursor: pointer !important;
        }

        .uonix-prod-bullet {
            width: 6px !important;
            height: 6px !important;
            background: #94a3b8 !important;
            border-radius: 50% !important;
            margin-right: 8px !important;
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
            font-size: 15px !important;
            font-weight: 800 !important;
            margin-left: 6px !important;
            transition: all 0.2s ease !important;
        }

        .uonix-prod-stage-item:hover,
        .uonix-prod-stage-item.is-active {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            border-left: 3px solid #f76a0c !important;
            color: #0e3780 !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.08) !important;
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

        /* LINK VER TODA A LINHA */
        .uonix-link-ver-linha {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 14.5px !important;
            font-weight: 800 !important;
            line-height: 1.4 !important;
            color: #f76a0c !important;
            text-decoration: none !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
            margin-top: 6px !important;
            transition: all 0.2s ease !important;
        }
        .uonix-link-ver-linha:hover { 
            color: #0e3780 !important; 
            transform: translateX(4px) !important; 
        }

        /* ==========================================================
           PALCO DE PREVIEW DINÂMICO (LADO DIREITO)
           ========================================================== */
        .uonix-dc-panel-stage {
            display: flex !important;
            flex-direction: column !important;
            height: 100% !important;
            min-width: 0 !important;
        }

        .uonix-preview-stage-card {
            height: 100% !important;
            background: #ffffff !important;
            border: 1.5px solid #e2e8f0 !important;
            border-radius: 10px !important;
            padding: 14px !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            box-shadow: 0 8px 20px -4px rgba(14, 55, 128, 0.08) !important;
            box-sizing: border-box !important;
            position: relative !important;
        }

        .uonix-stage-topbar {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            margin-bottom: 10px !important;
        }

        .uonix-stage-brand-badge {
            font-size: 10px !important;
            font-weight: 800 !important;
            color: #0e3780 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            background: #e9f3ff !important;
            padding: 3px 8px !important;
            border-radius: 4px !important;
            display: inline-block !important;
            max-width: 170px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            box-sizing: border-box !important;
            transition: all 0.2s ease !important;
        }

        .uonix-stage-live-badge {
            font-size: 9.5px !important;
            font-weight: 800 !important;
            color: #10b981 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
        }

        .uonix-stage-live-dot {
            width: 6px !important;
            height: 6px !important;
            background: #10b981 !important;
            border-radius: 50% !important;
            display: inline-block !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2) !important;
        }

        .uonix-stage-media {
            flex: 1 !important;
            min-height: 160px !important;
            max-height: 180px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #f8fafc !important;
            border: 1px solid #edf2f7 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            padding: 10px !important;
            margin-bottom: 10px !important;
            box-sizing: border-box !important;
        }

        #mega-menu-wrap-primary .uonix-stage-img,
        .is-sticky .uonix-stage-img,
        .site-header-sticky-inner .uonix-stage-img,
        .header-desktop-sticky .uonix-stage-img,
        .kadence-sticky-header .uonix-stage-img,
        .uonix-stage-img {
            width: 100% !important;
            height: 155px !important;
            min-height: 155px !important;
            max-height: 155px !important;
            object-fit: contain !important;
            background: transparent !important;
            border-radius: 4px !important;
            flex-shrink: 0 !important;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        .uonix-stage-meta {
            display: flex !important;
            flex-direction: column !important;
            gap: 4px !important;
        }

        .uonix-stage-prod-title {
            font-size: 13px !important;
            font-weight: 700 !important;
            color: #0e3780 !important;
            line-height: 1.3 !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            min-height: 34px !important;
            margin: 0 !important;
            transition: color 0.2s ease !important;
        }

        .uonix-stage-cta {
            font-size: 11.5px !important;
            font-weight: 800 !important;
            color: #f76a0c !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 3px !important;
            transition: transform 0.2s ease !important;
        }

        .uonix-stage-cta:hover {
            color: #0e3780 !important;
            transform: translateX(3px) !important;
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

        /* ANTI-SQUASH NAS LOGOS DE MARCAS */
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

    <script id="uonix-megamenu-stage-js">
        (function() {
            function initUonixMegaMenuStage() {
                var stageLinks = document.querySelectorAll('.uonix-prod-stage-item');
                if (!stageLinks.length) return;

                stageLinks.forEach(function(link) {
                    link.addEventListener('mouseenter', function() {
                        var panel = link.closest('.uonix-dc-panel');
                        if (!panel) return;

                        var stageImg   = panel.querySelector('.uonix-stage-img');
                        var stageBrand = panel.querySelector('.uonix-stage-brand-badge');
                        var stageTitle = panel.querySelector('.uonix-stage-prod-title');
                        var stageCta   = panel.querySelector('.uonix-stage-cta');

                        var newImg   = link.getAttribute('data-img');
                        var newBrand = link.getAttribute('data-brand');
                        var newTitle = link.getAttribute('data-title');
                        var newLink  = link.getAttribute('data-link');

                        if (stageImg && newImg && stageImg.src !== newImg) {
                            stageImg.style.opacity = '0.3';
                            stageImg.style.transform = 'scale(0.95)';
                            setTimeout(function() {
                                stageImg.src = newImg;
                                stageImg.alt = newTitle || '';
                                stageImg.style.opacity = '1';
                                stageImg.style.transform = 'scale(1)';
                            }, 80);
                        }

                        if (stageBrand && newBrand) {
                            stageBrand.textContent = newBrand;
                        }

                        if (stageTitle && newTitle) {
                            stageTitle.textContent = newTitle;
                        }

                        if (stageCta && newLink) {
                            stageCta.href = newLink;
                        }

                        var siblingItems = panel.querySelectorAll('.uonix-prod-stage-item');
                        siblingItems.forEach(function(s) { s.classList.remove('is-active'); });
                        link.classList.add('is-active');
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initUonixMegaMenuStage);
            } else {
                initUonixMegaMenuStage();
            }
        })();
    </script>
    <?php
}

// ==============================================================================
// 2. SHORTCODE MEGA MENU
// ==============================================================================
add_shortcode('uonix_menu_categorias', 'uonix_gerar_mega_menu_v14');
function uonix_gerar_mega_menu_v14() {
    $categorias = [
        'olhais'     => ['titulo' => 'Olhais de Ancoragem', 'slug' => 'olhal-de-ancoragem'],
        'quimica'    => ['titulo' => 'Fixação Química', 'slug' => 'fixacao-quimica'],
        'mecanica'   => ['titulo' => 'Fixação Mecânica', 'slug' => 'fixacao-mecanica'],
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
                foreach ($categorias as $cat) : 
                    $index++;

                    $term = get_term_by('slug', $cat['slug'], 'product_cat');
                    $link_padrao = get_term_link($term) . '#catalogo-produtos';
                    $link_husky = '/produtos/swoof2/product_cat-' . $cat['slug'] . '/#catalogo-produtos';
                    
                    $descricao = (!empty($term) && !empty($term->description)) ? wp_strip_all_tags($term->description) : 'Confira a nossa linha completa para fixação e ancoragem.';

                    // Limite padronizado de 8 produtos por categoria para preenchimento harmônico em 2 colunas
                    $args = [
                        'post_type'      => 'product',
                        'posts_per_page' => 8,
                        'orderby'        => 'menu_order title',
                        'order'          => 'ASC',
                        'tax_query'      => [
                            [
                                'taxonomy' => 'product_cat',
                                'field'    => 'slug',
                                'terms'    => $cat['slug']
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

                    // Processar lista de produtos estruturada
                    $produtos_lista = [];
                    if ($produtos->have_posts()) {
                        while ($produtos->have_posts()) {
                            $produtos->the_post();
                            $p_id    = get_the_ID();
                            $p_title = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
                            $p_link  = get_the_permalink() . '#catalogo-produtos';
                            
                            $p_img = get_the_post_thumbnail_url($p_id, 'medium');
                            if (empty($p_img) && function_exists('wc_placeholder_img_src')) {
                                $p_img = wc_placeholder_img_src('woocommerce_thumbnail');
                            }

                            // Identificação do fabricante / marca
                            $p_marca = '';
                            $brands  = wp_get_post_terms($p_id, 'product_brand');
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

                            $produtos_lista[] = [
                                'id'    => $p_id,
                                'title' => $p_title,
                                'link'  => $p_link,
                                'img'   => $p_img,
                                'brand' => $p_marca,
                            ];
                        }
                        wp_reset_postdata();
                    }

                    $first_prod = !empty($produtos_lista[0]) ? $produtos_lista[0] : [
                        'title' => $cat['titulo'],
                        'link'  => $link_padrao,
                        'img'   => $imagem_final,
                        'brand' => 'UÔNIX',
                    ];

                    $titulo_limpo = str_replace(['⚙️ ', '🧪 ', '🔗 ', '🛠️ '], '', $cat['titulo']);
                ?>
                    <li class="uonix-dc-item">
                        <a href="<?php echo esc_url($link_husky); ?>" class="uonix-dc-link">
                            <span class="uonix-dc-text"><?php echo esc_html($cat['titulo']); ?></span>
                            <span class="uonix-dc-arrow">&rsaquo;</span>
                        </a>

                        <div class="uonix-dc-panel">
                            <div class="uonix-dc-panel-grid">
                                
                                <!-- COLUNA PRINCIPAL: TEXTO + GRID DE PRODUTOS -->
                                <div class="uonix-dc-panel-main">
                                    <div class="uonix-dc-header-wrap">
                                        <span class="uonix-dc-badge">Linha de Produtos</span>
                                        <h5 class="uonix-dc-title"><?php echo esc_html($titulo_limpo); ?></h5>
                                        <p class="uonix-dc-desc"><?php echo esc_html($descricao); ?></p>
                                    </div>

                                    <div class="uonix-dc-products-grid">
                                        <?php if (!empty($produtos_lista)) : 
                                            foreach ($produtos_lista as $p_idx => $prod_item) : 
                                                $is_first = ($p_idx === 0);
                                        ?>
                                                <a href="<?php echo esc_url($prod_item['link']); ?>" 
                                                   class="uonix-prod-stage-item <?php echo $is_first ? 'is-active' : ''; ?>"
                                                   data-img="<?php echo esc_url($prod_item['img']); ?>"
                                                   data-title="<?php echo esc_attr($prod_item['title']); ?>"
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

                                    <div class="uonix-dc-footer-action">
                                        <a href="<?php echo esc_url($link_padrao); ?>" class="uonix-link-ver-linha">
                                            Ver toda a linha <?php echo esc_html($titulo_limpo); ?> &rarr;
                                        </a>
                                    </div>
                                </div>

                                <!-- COLUNA DO PALCO DE PREVIEW (LADO DIREITO) -->
                                <div class="uonix-dc-panel-stage">
                                    <div class="uonix-preview-stage-card">
                                        <div class="uonix-stage-topbar">
                                            <span class="uonix-stage-brand-badge"><?php echo esc_html($first_prod['brand']); ?></span>
                                            <span class="uonix-stage-live-badge">
                                                <span class="uonix-stage-live-dot"></span>
                                                Preview
                                            </span>
                                        </div>

                                        <div class="uonix-stage-media">
                                            <img class="uonix-stage-img" 
                                                 src="<?php echo esc_url($first_prod['img']); ?>" 
                                                 alt="<?php echo esc_attr($first_prod['title']); ?>"
                                                 loading="lazy" 
                                                 width="200" 
                                                 height="155" />
                                        </div>

                                        <div class="uonix-stage-meta">
                                            <span class="uonix-stage-prod-title"><?php echo esc_html($first_prod['title']); ?></span>
                                            <a href="<?php echo esc_url($first_prod['link']); ?>" class="uonix-stage-cta">
                                                Ver especificações <span>&rsaquo;</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
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

function uonix_gerar_grid_marcas_premium_v14() {

    // Marcas que podem aparecer, por slug
    $marcas_visiveis = ['walsywa', 'ancora', 'tekbond', 'uonix'];

    // Blacklist de marcas/fabricantes por ID
    $fabricantes_blacklist = [72]; 

    $taxonomy = 'product_brand';

    $args = [
        'taxonomy'   => $taxonomy,
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
            foreach ($terms as $term) : 
                $slug = $term->slug;
                $logo_url = '';
                $link_final = '';

                if (isset($marcas_fixas[$slug])) {
                    $logo_url   = $marcas_fixas[$slug]['logo'];
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
                <a href="<?php echo esc_url($link_final); ?>" class="uonix-brand-item" title="<?php echo esc_attr($term->name); ?>">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($term->name); ?>" loading="lazy" width="120" height="42">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
