<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Catalogo - arquivos, marcas e titulos de taxonomia.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 5828-6298 do export original.
// -----------------------------------------------------------------------------
/**
 * Link do Catálogo nas Páginas de Arquivo woocomerce (MARCAS, CATEGORIA, TAGS, etc)
 */
/**
 * UÔNIX: Link de Retorno ao Catálogo + Estilo Premium da Vitrine
 * Versão: 10.0 - Unificação do layout e CSS + Prefixo em Atributos
 */
add_action( 'woocommerce_before_main_content', 'uonix_inject_catalog_link_v9', 15 );

function uonix_inject_catalog_link_v9() {
    
    // Ativa em Loja, Categorias, Marcas e Tags (e atributos)
    if ( is_shop() || is_product_taxonomy() ) {
        ?>
        <style>
            /* ==========================================================================
               1. LAYOUT GERAL E BOTÃO VOLTAR
               ========================================================================== */
            /* FORÇA O CONTAINER #MAIN A SE COMPORTAR COMO UMA COLUNA */
            .archive #main.site-main {
                display: flex !important;
                flex-direction: column !important;
            }

            /* MOVE O LINK PARA O TOPO (ORDER: -1), INDEPENDENTE DO PHP */
            .uonix-primary-nav-link {
                order: -1 !important; /* Faz o link "pular" para antes do header */
                display: block;
                width: 100%;
                margin-top: 20px !important; 
                margin-bottom: 20px !important;
            }

            /* Estilo visual do link */
            .uonix-back-to-catalog {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #0e3780 !important;
                font-size: 13px !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                text-decoration: none !important;
                letter-spacing: 0.5px !important;
                transition: all 0.3s ease;
            }

            .uonix-back-to-catalog::before {
                content: '←';
                font-size: 16px;
                line-height: 1;
                transition: transform 0.3s ease;
            }

            .uonix-back-to-catalog:hover {
                color: #f76a0c !important;
            }

            .uonix-back-to-catalog:hover::before {
                transform: translateX(-5px);
            }

            /* ==========================================================================
               2. HEADER DA CATEGORIA / MARCA
               ========================================================================== */
            header.entry-header.product-archive-title {
                background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%) !important;
                border: 1px solid #eaf0f6 !important;
                border-radius: 12px !important;
                padding: 30px 40px !important;
                margin-bottom: 30px !important;
                box-shadow: 0 10px 30px rgba(14, 55, 128, 0.03) !important;
            }

            header.entry-header.product-archive-title .page-title {
                color: #0e3780 !important;
                font-size: 32px !important;
                font-weight: 900 !important;
                text-transform: uppercase !important;
                margin-bottom: 15px !important;
                letter-spacing: -0.5px;
            }

            header.entry-header.product-archive-title .archive-description p {
                color: #475569 !important;
                font-size: 16px !important;
                line-height: 1.5 !important;
                margin: 0 !important;
            }

            /* Linha de contagem e filtros (Grid/List) */
            .kadence-shop-top-row {
                border-bottom: 2px solid #f1f5f9 !important;
                padding-bottom: 15px !important;
                margin-bottom: 25px !important;
                margin-left: 15px !important
            }

            /* ==========================================================================
               3. CARDS DE PRODUTOS (VITRINE)
               ========================================================================== */
            .woocommerce ul.products li.product {
                background: #ffffff !important;
                border: 1px solid #eef2f7 !important;
                border-radius: 10px !important;
                overflow: hidden !important;
                transition: all 0.3s ease !important;
                display: flex !important;
                flex-direction: column !important;
                padding: 0 !important;
                box-shadow: 0 4px 15px rgba(14, 55, 128, 0.02) !important;
            }

            .woocommerce ul.products li.product:hover {
                border-color: #f76a0c !important;
                box-shadow: 0 12px 30px rgba(14, 55, 128, 0.08) !important;
                transform: translateY(-4px) !important;
            }

            /* ==========================================================================
               4. IMAGEM DO PRODUTO (Aspect Ratio 4:3)
               ========================================================================== */
            .woocommerce ul.products li.product .woocommerce-loop-image-link {
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
            }

            .woocommerce ul.products li.product img {
                width: 100% !important;
                height: 100% !important;
                object-fit: contain !important;
                padding: 15px !important;
                transition: transform 0.4s ease !important;
                background: transparent !important;
            }

            .woocommerce ul.products li.product:hover img {
                transform: scale(1.08) !important;
            }

            /* Selo da Marca / Badge Uônix */
            .uonix-loop-brand-badge {
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
                box-shadow: 0 2px 8px rgba(0,0,0,0.05) !important;
            }

            /* ==========================================================================
               5. INFORMAÇÕES DO PRODUTO
               ========================================================================== */
            .woocommerce ul.products li.product .product-details {
                padding: 20px !important;
                display: flex !important;
                flex-direction: column !important;
                flex: 1 !important; /* Empurra o botão pro final */
                background: #ffffff !important;
            }

            /* Título */
            .woocommerce ul.products li.product .woocommerce-loop-product__title {
                font-size: 18px !important;
                font-weight: 800 !important;
                line-height: 1.2 !important;
                margin: 0 0 12px 0 !important;
                text-align: left !important;
            }

            .woocommerce ul.products li.product .woocommerce-loop-product__title a {
                color: #0e3780 !important;
                text-decoration: none !important;
                transition: color 0.3s ease !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important; 
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
            }

            .woocommerce ul.products li.product:hover .woocommerce-loop-product__title a {
                color: #f76a0c !important;
            }

            /* Resumo (Excerpt) */
            .woocommerce ul.products li.product .product-excerpt {
                margin: 0 0 20px 0 !important;
                flex: 1 !important;
                text-align: left !important;
            }

            .woocommerce ul.products li.product .product-excerpt p {
                color: #64748b !important;
                font-size: 14px !important;
                line-height: 1.45 !important;
                margin: 0 !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 3 !important; /* Limita a 3 linhas */
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
            }

            /* Esconde Preço Nativo */
            .woocommerce ul.products li.product .price {
                display: none !important;
            }

            /* ==========================================================================
               6. BOTÃO "VER DETALHES"
               ========================================================================== */
            .woocommerce ul.products li.product .product-action-wrap {
                margin-top: auto !important; /* Fixa no rodapé do card */
                width: 100% !important;
            }

            .woocommerce ul.products li.product .uonix-details-btn {
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
            }

            .woocommerce ul.products li.product .uonix-details-btn:hover {
                background: #f76a0c !important;
                color: #ffffff !important;
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 15px rgba(247, 106, 12, 0.25) !important;
            }

            /* ==========================================================================
               7. MODO LISTA (KADENCE TOGGLE SUPPORT)
               ========================================================================== */
            .woocommerce ul.products.products-list-view li.product {
                flex-direction: row !important;
                align-items: stretch !important;
            }
            .woocommerce ul.products.products-list-view li.product .woocommerce-loop-image-link {
                width: 320px !important;
                border-bottom: none !important;
                border-right: 1px solid #eef2f7 !important;
            }
            .woocommerce ul.products.products-list-view li.product .product-action-wrap {
                width: max-content !important;
                align-self: flex-start !important;
            }

            /* ==========================================================================
               8. RESPONSIVO MOBILE
               ========================================================================== */
            @media (max-width: 768px) {
                .uonix-primary-nav-link { margin-top: 15px !important; }
                .uonix-back-to-catalog { font-size: 11px !important; }
                
                header.entry-header.product-archive-title { padding: 20px !important; }
                header.entry-header.product-archive-title .page-title { font-size: 24px !important; }
                
                .woocommerce ul.products.products-list-view li.product { flex-direction: column !important; }
                .woocommerce ul.products.products-list-view li.product .woocommerce-loop-image-link { width: 100% !important; border-right: none !important; border-bottom: 1px solid #eef2f7 !important; }
                .woocommerce ul.products.products-list-view li.product .product-action-wrap { width: 100% !important; }
            }
                
                /* "Invisibiliza" a tag strong para o grid/flexbox, mas mantém o texto em negrito */
                .woocommerce ul.products li.product .product-excerpt p strong,
                .woocommerce ul.products li.product .product-excerpt p b,
                .woocommerce ul.products li.product .product-excerpt p span {
                    display: contents !important;
                    font-weight: 800 !important;
                }

                /* Remove quebras de linha que o WordPress possa ter injetado */
                .woocommerce ul.products li.product .product-excerpt p br {
                    display: none !important;
                }
        </style>

        <div class="uonix-primary-nav-link">
            <a href="/produtos/#catalogo-produtos" class="uonix-back-to-catalog">
                Ver Catálogo completo de produtos
            </a>
        </div>
        <?php
    }
}


/**
 * UÔNIX: Adiciona o nome da taxonomia pai no título de arquivos de atributos (Força Bruta para Kadence)
 */
add_filter( 'get_the_archive_title', 'uonix_prefix_attribute_title_kadence', 999 );
add_filter( 'woocommerce_page_title', 'uonix_prefix_attribute_title_kadence', 999 );

function uonix_prefix_attribute_title_kadence( $title ) {
    if ( is_tax() ) {
        $queried_object = get_queried_object();
        
        if ( isset( $queried_object->taxonomy ) && strpos( $queried_object->taxonomy, 'pa_' ) === 0 ) {
            $taxonomy_obj = get_taxonomy( $queried_object->taxonomy );
            
            if ( $taxonomy_obj && isset( $taxonomy_obj->labels->singular_name ) ) {
                $attribute_name = $taxonomy_obj->labels->singular_name;
                $term_name = single_term_title( '', false );
                
                // A MÁGICA AQUI: O valor ($term_name) ganha a cor da paleta 1
                return $attribute_name . ': <span style="color: var(--global-palette1) !important;">' . $term_name . '</span>';
            }
        }
    }
    return $title;
}
											
/**
 * UÔNIX: Estilo Premium Exclusivo para Páginas de Fabricantes/Marcas (V4 - À prova de falhas)
 */

// 1. INJETA O CSS DIRETO NO CORPO DA PÁGINA (Garante que o Kadence vai ler)
add_action('woocommerce_before_main_content', 'uonix_estilos_premium_marcas_banner', 20);

function uonix_estilos_premium_marcas_banner() {
    if ( is_tax('product_brand') ) {
        ?>
        <style>
            /* ==========================================================================
               1. BANNER DO FABRICANTE (CONTAINER)
               ========================================================================== */
            header.entry-header.product-archive-title {
                background: url('data:image/svg+xml;utf8,<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><circle cx="2" cy="2" r="1" fill="%230e3780" opacity="0.05"/></svg>'), linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%) !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 16px !important;
                padding: 50px 40px !important;
                margin-bottom: 40px !important;
                box-shadow: 0 15px 35px rgba(14, 55, 128, 0.05) !important;
                
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                
                position: relative;
                overflow: hidden;
            }

            header.entry-header.product-archive-title::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 5px;
                background: var(--global-palette1, #f76a0c);
            }

            /* ==========================================================================
               2. LOGO DO FABRICANTE (COM SUPORTE FORÇADO PARA SVG)
               ========================================================================== */
            .uonix-brand-logo-wrapper {
                width: 100%;
                text-align: center;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
            }

            .uonix-brand-logo-wrapper img {
                max-width: 450px !important; 
                max-height: 200px !important; 
                width: 100% !important; /* CRÍTICO: Força o SVG a renderizar na tela */
                height: auto !important;
                object-fit: contain !important;
                margin: 0 auto !important;
                display: block !important;
                mix-blend-mode: multiply !important;
            }

            /* ==========================================================================
               3. TEXTOS (Plano B: Se não tiver imagem, exibe o nome do fabricante)
               ========================================================================== */
            header.entry-header.product-archive-title .page-title {
                display: block !important;
                color: #0e3780 !important;
                font-size: 32px !important;
                font-weight: 900 !important;
                text-transform: uppercase !important;
                margin-top: 0 !important;
                margin-bottom: 0 !important;
                letter-spacing: -0.5px;
                width: 100%;
            }

            header.entry-header.product-archive-title .archive-description p {
                color: #475569 !important;
                font-size: 16px !important;
                line-height: 1.6 !important;
                max-width: 800px !important;
                margin: 15px auto 0 auto !important;
            }

            /* ==========================================================================
               4. RESPONSIVO MOBILE
               ========================================================================== */
            @media (max-width: 768px) {
                header.entry-header.product-archive-title { padding: 30px 20px !important; }
                .uonix-brand-logo-wrapper img { max-width: 280px !important; max-height: 120px !important; }
                header.entry-header.product-archive-title .page-title { font-size: 26px !important; }
            }
        </style>
        <?php
    }
}

// 2. INJETA A LOGO NO TÍTULO (O que já estava funcionando)
add_filter( 'get_the_archive_title', 'uonix_injetar_logo_marca_no_titulo', 998 );
add_filter( 'woocommerce_page_title', 'uonix_injetar_logo_marca_no_titulo', 998 );

function uonix_injetar_logo_marca_no_titulo( $title ) {
    if ( is_tax('product_brand') ) {
        $term = get_queried_object();
        $image = '';
        
        $thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
        
        if ( empty($thumbnail_id) ) {
            $thumbnail_id = get_term_meta( $term->term_id, 'pwb_brand_image', true );
        }
        if ( empty($thumbnail_id) ) {
            $thumbnail_id = get_term_meta( $term->term_id, 'brand_image', true );
        }

        if ( !empty($thumbnail_id) && is_numeric($thumbnail_id) ) {
            $image = wp_get_attachment_image( $thumbnail_id, 'full', false, array('class' => 'uonix-brand-logo-img', 'alt' => $term->name) );
        } 
        elseif ( !empty($thumbnail_id) && is_string($thumbnail_id) && filter_var($thumbnail_id, FILTER_VALIDATE_URL) ) {
            $image = '<img src="' . esc_url($thumbnail_id) . '" class="uonix-brand-logo-img" alt="' . esc_attr($term->name) . '">';
        }
        
        // Se tem imagem: Oculta o título em texto deixando ele invisível
        if ( !empty($image) ) {
            return '<div class="uonix-brand-logo-wrapper">' . $image . '</div><span style="display: none !important;">' . $title . '</span>';
        }
    }
    
    // Se NÃO tem imagem: O título original continua normalmente (Fallback)
    return $title;
}


