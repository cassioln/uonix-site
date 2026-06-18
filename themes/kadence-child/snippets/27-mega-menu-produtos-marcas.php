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
 * UÔNIX: Master Premium V14.0 (Mega Menu + Marcas) - Versão Limpa
 * - Destaque Categoria + Anti-Squash Absoluto nas imagens
 * - Limpo de códigos de Sidebar e Posts
 */

// ==============================================================================
// 1. ESTILO COMPARTILHADO (MEGA MENU E MARCAS)
// ==============================================================================
add_action('wp_head', 'uonix_estilos_mega_menu_v14');
function uonix_estilos_mega_menu_v14() {
    ?>
    <style>
        /* ==========================================================
           MEGA MENU: ESTRUTURA E PAINÉIS
           ========================================================== */
        .uonix-dynamic-cats-wrapper, .uonix-dc-panel { pointer-events: none !important; }
        #mega-menu-wrap-primary .mega-menu-item.mega-toggle-on .uonix-dynamic-cats-wrapper, #mega-menu-wrap-primary .mega-menu-item.mega-hover .uonix-dynamic-cats-wrapper, #mega-menu-wrap-primary .mega-menu-item:hover .uonix-dynamic-cats-wrapper, #mega-menu-wrap-primary .mega-menu-item.mega-toggle-on .uonix-dc-panel, #mega-menu-wrap-primary .mega-menu-item.mega-hover .uonix-dc-panel, #mega-menu-wrap-primary .mega-menu-item:hover .uonix-dc-panel { pointer-events: auto !important; }
        
        .uonix-dynamic-cats-wrapper { position: relative !important; display: flex !important; width: 100% !important; min-height: 420px !important; background: #ffffff !important; border: 2px solid #e2e8f0 !important; border-radius: 8px 8px 0 0 !important; border-bottom: none !important; margin-bottom: 0 !important; overflow: hidden !important; box-shadow: none !important; }
        
        .uonix-dc-sidebar { width: clamp(280px, 20vw, 320px) !important; flex-shrink: 0 !important; background: #f8fafc !important; display: flex !important; flex-direction: column !important; border-right: none !important; }
        .uonix-dc-catalog-btn { display: flex !important; align-items: center !important; margin-bottom: 15px; justify-content: center !important; background: #0e3780 !important; color: #ffffff !important; padding: 15px !important; font-size: 16px !important; font-weight: 800 !important; text-decoration: none !important; text-transform: uppercase !important; transition: all 0.3s ease !important; }
        .uonix-dc-catalog-btn:hover { background: rgba(10, 39, 91, 0.92) !important; box-shadow: inset 0 0 40px rgba(0,0,0,0.5) !important; }

        .uonix-dc-list { margin: 0 !important; padding: 0 !important; list-style: none !important; flex: 1 !important; display: flex !important; flex-direction: column !important; }
        .uonix-dc-item { border-bottom: 1px solid #e2e8f0 !important; margin: 0 !important; padding: 0 !important; }
        .uonix-dc-link { display: flex !important; align-items: center !important; justify-content: space-between !important; padding: 15px 22px !important; font-size: 17px !important; font-weight: 700 !important; color: #475569 !important; text-decoration: none !important; transition: all 0.2s ease !important; border-left: 4px solid transparent !important; border-right: 1px solid #e2e8f0 !important; text-transform: uppercase !important; }
        
        .uonix-dc-item:hover .uonix-dc-link, .uonix-dc-list:not(:hover) .uonix-dc-item:first-child .uonix-dc-link { background: #ffffff !important; color: #0e3780 !important; border-left: 4px solid #f76a0c !important; border-right: 1px solid transparent !important; }

        .uonix-dc-panel { position: absolute !important; top: 0 !important; left: clamp(280px, 20vw, 320px) !important; right: 0 !important; height: 100% !important; padding: 20px 40px 20px 40px !important; background: #ffffff !important; display: flex !important; flex-direction: column !important; opacity: 0 !important; visibility: hidden !important; z-index: 1 !important; transition: opacity 0s 0.1s, visibility 0s 0.1s !important; }
        .uonix-dc-item:first-child .uonix-dc-panel { opacity: 1 !important; visibility: visible !important; z-index: 2 !important; transition: none !important; }
        .uonix-dc-item:hover .uonix-dc-panel { opacity: 1 !important; visibility: visible !important; z-index: 10 !important; transition: opacity 0s 0s, visibility 0s 0s !important; }

        /* O NOVO LINK "VER TODA A LINHA" INLINE */
        .uonix-link-ver-linha {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            line-height: 1.5;
            color: #f76a0c !important;
            text-decoration: none !important;
            margin-top: 10px !important;
            text-transform: uppercase !important;
            transition: all 0.3s ease !important;
        }
                        
        .uonix-dc-panel-featured .uonix-link-ver-linha {
            margin-left: 20px !important;
        }
                        
        .uonix-link-ver-linha:hover { color: #0e3780 !important; transform: translateX(5px) !important; }

        /* LAYOUT DESTAQUE (PRIMEIRA CATEGORIA) */
        .uonix-dc-panel-featured { padding: 30px 40px !important; flex-direction: row !important; align-items: center !important; gap: 40px !important; }
        .uonix-featured-text { flex: 1.2 !important; display: flex !important; flex-direction: column !important; justify-content: center !important; align-items: flex-start !important; }
        .uonix-featured-text h5 { font-size: 32px !important; color: #0e3780 !important; font-weight: 800 !important; text-transform: uppercase !important; margin: 0 0 12px 0 !important; line-height: 1.1 !important; letter-spacing: -0.5px !important; }
        .uonix-featured-text p { font-size: 17px !important; color: #475569 !important; line-height: 1.5 !important; margin: 0 0 20px 0 !important; }
        
        .uonix-featured-products { display: flex !important; flex-direction: column !important; gap: 12px !important; border-left: 3px solid #f1f5f9 !important; padding-left: 15px !important; width: 100%; margin-bottom: 10px !important; }
        .uonix-featured-products a { color: #0e3780 !important; font-weight: 600 !important; font-size: 18px !important; line-height: 1.5; text-decoration: none !important; display: flex !important; align-items: center !important; transition: color 0.2s ease !important; }
        .uonix-featured-products a::before { content: "•"; color: #f76a0c !important; margin-right: 8px !important; font-size: 18px !important; }
        .uonix-featured-products a:hover { color: #f76a0c !important; }
        
        .uonix-featured-image { flex: 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; height: 100% !important; min-height: 250px !important; }
        
        /* LAYOUT CATEGORIAS NORMAIS */
        .uonix-dc-header { display: flex !important; align-items: center !important; gap: 30px !important; border-bottom: 1px solid #f1f5f9 !important; padding-bottom: 15px !important; margin-bottom: 5px !important; min-height: 200px !important; }
        .uonix-dc-info { display: flex; flex-direction: column; align-items: flex-start; }
        .uonix-dc-info h5 { font-size: 26px !important; text-transform: uppercase !important; color: #0e3780 !important; font-weight: 800 !important; margin: 0 0 5px 0 !important; }
        .uonix-dc-info p { font-size: 16px !important; color: #64748b !important; margin-bottom: 5px !important; line-height: 1.5; }
        
        .uonix-dc-sublinks { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 15px 25px !important; line-height: 1.5; padding-left: 0px; padding-right: 0px; margin-top: 15px; }
        .uonix-dc-sublinks a { font-size: 15px !important; color: #475569 !important; font-weight: 600 !important; text-decoration: none !important; display: flex !important; align-items: flex-start !important; line-height: 1.35 !important; padding-left: 5px; border-left: solid 5px #e9f3ff; }
        .uonix-dc-sublinks a:hover { color: #003399 !important; border-left: solid 3px #f76a0b; }

        /* MEGA MARCAS ROW */
        #mega-menu-wrap-primary #mega-menu-primary li.mega-menu-row:has(.uonix-mega-brands-wrap) { background: #ffffff !important; border: 2px solid #e2e8f0 !important; border-top: 1px solid #f1f5f9 !important; border-radius: 0 0 8px 8px !important; margin-top: -2px !important; padding: 25px 40px !important; box-shadow: 0 12px 25px rgba(0, 0, 0, 0.06) !important; display: block !important; }
        .uonix-mega-brands-wrap .mega-block-title { font-size: 18px !important; color: #94a3b8 !important; text-transform: uppercase !important; letter-spacing: 1.2px !important; margin-bottom: 20px !important; margin-left: 5px !important; font-weight: 700 !important; }
        .uonix-brands-grid { display: grid !important; grid-template-columns: repeat(6, 1fr) !important; gap: 12px !important; margin-bottom: 5px !important; }
        .uonix-brand-item { display: flex !important; align-items: center !important; justify-content: center !important; background: #ffffff !important; border: 1px solid #f1f5f9 !important; border-radius: 6px !important; width: 100% !important; padding: 0 15px; transition: all 0.3s ease !important; text-decoration: none !important; }
        .uonix-brand-item:hover { border-color: #0e3780 !important; transform: translateY(-4px) !important; }

        /* ==========================================================
           TRAVAS ABSOLUTAS CONTRA ACHATAMENTO NO MENU FIXO (ANTI-SQUASH)
           ========================================================== */
        
        /* 1. Neutraliza o CSS do Kadence Sticky Header para TODAS as imagens do menu */
        #mega-menu-wrap-primary .uonix-dynamic-cats-wrapper img,
        #mega-menu-wrap-primary .uonix-mega-brands-wrap img,
        .is-sticky .uonix-dynamic-cats-wrapper img,
        .site-header-sticky-inner .uonix-dynamic-cats-wrapper img,
        .header-desktop-sticky .uonix-dynamic-cats-wrapper img,
        .kadence-sticky-header .uonix-dynamic-cats-wrapper img,
        .is-sticky .uonix-mega-brands-wrap img,
        .site-header-sticky-inner .uonix-mega-brands-wrap img,
        .header-desktop-sticky .uonix-mega-brands-wrap img,
        .kadence-sticky-header .uonix-mega-brands-wrap img {
            max-height: none !important;
            height: auto !important;
        }

        /* 2. Trava absoluta na Categoria Destaque (Olhais) */
        .uonix-featured-image img { 
            width: 100% !important; 
            min-height: 250px !important; /* Força a não encolher */
            max-height: 300px !important; 
            object-fit: contain !important; 
            border-radius: 8px !important; 
            flex-shrink: 0 !important; /* Impede que o flexbox amasse a foto */
        }

        /* 3. Trava absoluta nas Categorias Normais (Química, Mecânica, etc) */
        .uonix-dc-header img { 
            width: 300px !important; 
            min-width: 300px !important; /* Força a não perder largura */
            min-height: 200px !important; /* Força a não encolher a altura */
            aspect-ratio: 4/3 !important; 
            object-fit: contain !important; 
            border-radius: 8px !important; 
            flex-shrink: 0 !important; 
        }

        /* 4. Trava absoluta nas Logos das Marcas (Abaixo) */
        .uonix-brand-item img {
            max-height: none !important;
            min-height: 45px !important; /* Protege a altura mínima da logo */
            height: 45px !important;
            width: auto !important;
            filter: grayscale(1) opacity(0.6); 
            transition: 0.3s; 
            object-fit: contain !important;
            flex-shrink: 0 !important;
        }
        .uonix-brand-item:hover img { filter: grayscale(0) opacity(1); }
    </style>
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
                    $is_featured = ($index === 0);
                    $index++;

                    $term = get_term_by('slug', $cat['slug'], 'product_cat');
                    $link_padrao = get_term_link($term) . '#catalogo-produtos';
                    $link_husky = '/produtos/swoof2/product_cat-' . $cat['slug'] . '/#catalogo-produtos';
                    
                    $descricao = (!empty($term) && !empty($term->description)) ? wp_strip_all_tags($term->description) : 'Confira a nossa linha completa.';

                    // Limite de 3 produtos na destaque, 9 nas outras
                    $product_limit = $is_featured ? 3 : 9;
                    $args = ['post_type' => 'product', 'posts_per_page' => $product_limit, 'orderby' => 'rand', 'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $cat['slug']]]];
                    $produtos = new WP_Query($args);

                    // LOGICA DE IMAGEM INVERTIDA
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

                    $titulo_limpo = str_replace(['⚙️ ', '🧪 ', '🔗 ', '🛠️ '], '', $cat['titulo']);
                ?>
                    <li class="uonix-dc-item">
                        <a href="<?php echo esc_url($link_husky); ?>" class="uonix-dc-link">
                            <span class="uonix-dc-text"><?php echo esc_html($cat['titulo']); ?></span>
                            <span class="uonix-dc-arrow">&rsaquo;</span>
                        </a>

                        <div class="uonix-dc-panel <?php echo $is_featured ? 'uonix-dc-panel-featured' : ''; ?>">
                            
                            <?php if ($is_featured) : ?>
                                <div class="uonix-featured-text">
                                    <h5><?php echo esc_html($titulo_limpo); ?></h5>
                                    <p><?php echo esc_html($descricao); ?></p>
                                    
                                    <div class="uonix-featured-products">
                                        <?php if ($produtos->have_posts()) :
                                            while ($produtos->have_posts()) : $produtos->the_post(); 
                                                $t_prod = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
                                                echo '<a href="' . get_the_permalink() . '#catalogo-produtos">' . esc_html($t_prod) . '</a>';
                                            endwhile;
                                        endif; wp_reset_postdata(); ?>
                                    </div>
                                    
                                    <a href="<?php echo esc_url($link_padrao); ?>" class="uonix-link-ver-linha">Ver toda a linha <?php echo esc_html($titulo_limpo); ?> &rarr;</a>
                                </div>
                                <?php if (!empty($imagem_final)) : ?>
                                    <div class="uonix-featured-image">
                                        <img src="<?php echo esc_url($imagem_final); ?>" alt="<?php echo esc_attr($titulo_limpo); ?>">
                                    </div>
                                <?php endif; ?>

                            <?php else : ?>
                                <div class="uonix-dc-header">
                                    <?php if (!empty($imagem_final)) : ?>
                                        <img src="<?php echo esc_url($imagem_final); ?>" alt="<?php echo esc_attr($titulo_limpo); ?>">
                                    <?php endif; ?>
                                    <div class="uonix-dc-info">
                                        <h5><?php echo esc_html($titulo_limpo); ?></h5>
                                        <p><?php echo esc_html($descricao); ?></p>
                                        
                                        <a href="<?php echo esc_url($link_padrao); ?>" class="uonix-link-ver-linha">Ver toda a linha <?php echo esc_html($titulo_limpo); ?> &rarr;</a>
                                    </div>
                                </div>

                                <div class="uonix-dc-sublinks">
                                    <?php if ($produtos->have_posts()) :
                                        while ($produtos->have_posts()) : $produtos->the_post(); 
                                            $t_prod = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
                                            echo '<a href="' . get_the_permalink() . '#catalogo-produtos">' . esc_html($t_prod) . '</a>';
                                        endwhile;
                                    else: 
                                        echo '<span>Nenhum produto cadastrado.</span>'; 
                                    endif; 
                                    wp_reset_postdata(); ?>
                                </div>
                            <?php endif; ?>

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
// ==============================================================================
// 3. SHORTCODE VITRINE DE MARCAS
// ==============================================================================
add_shortcode('uonix_vitrine_marcas', 'uonix_gerar_grid_marcas_premium_v14');

function uonix_gerar_grid_marcas_premium_v14() {

    // Marcas que podem aparecer, por slug
    $marcas_visiveis = ['walsywa', 'ancora', 'tekbond', 'uonix'];

    // Blacklist de marcas/fabricantes por ID
    // Qualquer ID colocado aqui não será exibido
    // ID 72 = Walsywa
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

    $marcas = get_terms($args);

    if (is_wp_error($marcas) || empty($marcas)) {
        return '';
    }

    ob_start();
    ?>

    <div class="uonix-mega-brands-wrap">
        <h4 class="mega-block-title">Trabalhamos com as melhores marcas:</h4>

        <div class="uonix-brands-grid">
            <?php foreach ($marcas as $marca) :

                $thumb_id = get_term_meta($marca->term_id, 'thumbnail_id', true);

                if (!$thumb_id) {
                    $thumb_id = get_term_meta($marca->term_id, 'pwb_brand_image', true);
                }

                if (!$thumb_id) {
                    $thumb_id = get_term_meta($marca->term_id, 'image', true);
                }

                $logo_url = wp_get_attachment_url($thumb_id);

                if ($logo_url) :
                    ?>

                    <a href="<?php echo esc_url(get_term_link($marca)); ?>"
                       class="uonix-brand-item"
                       title="Ver produtos <?php echo esc_attr($marca->name); ?>">

                        <img src="<?php echo esc_url($logo_url); ?>"
                             alt="<?php echo esc_attr($marca->name); ?>">

                    </a>

                <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>

    <?php
    return ob_get_clean();
}


