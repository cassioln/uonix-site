<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Blog - pagina principal em shortcode.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 7167-7620 do export original.
// -----------------------------------------------------------------------------
/**
 * Página de Blog 
 */
/**
 * UÔNIX: Página de Blog Premium V2.2 (Versão Limpa e Comentada)
 * Shortcode: [uonix_pagina_blog]
 */
add_shortcode('uonix_pagina_blog', 'uonix_gerar_pagina_blog_v2_2');

function uonix_gerar_pagina_blog_v2_2() {
    
    $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
    
    // 1. DESTAQUE (1 Post)
    $destaque = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC'
    ));
    $destaque_id = !empty($destaque) ? $destaque[0]->ID : 0;

    // 2. MAIS CURTIDAS (3 Posts)
    $liked_posts = array();
    if ($destaque_id) {
        $liked_posts = get_posts(array(
            'post_type'      => 'post',
            'posts_per_page' => 3,
            'post_status'    => 'publish',
            'post__not_in'   => array($destaque_id),
            'meta_key'       => '_wthf_yes',
            'orderby'        => array('meta_value_num' => 'DESC', 'comment_count' => 'DESC', 'date' => 'DESC')
        ));
    }

    // Fallback dos curtidos (Caso não tenha 3 posts curtidos, preenche com os mais recentes)
    $liked_ids = wp_list_pluck($liked_posts, 'ID');
    if (count($liked_posts) < 3) {
        $needed = 3 - count($liked_posts);
        $exclude_fill = array_merge(array($destaque_id), $liked_ids);
        
        $fill_posts = get_posts(array(
            'post_type'      => 'post',
            'posts_per_page' => $needed,
            'post_status'    => 'publish',
            'post__not_in'   => $exclude_fill,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ));
        $liked_posts = array_merge($liked_posts, $fill_posts);
        $liked_ids = wp_list_pluck($liked_posts, 'ID');
    }

    $vitrine_ids = array_merge(array($destaque_id), $liked_ids);

    // 3. FEED DE NOTÍCIAS (Restante dos posts)
    $args_feed = array(
        'post_type'      => 'post',
        'posts_per_page' => 12,
        'paged'          => $paged,
        'post_status'    => 'publish',
        'post__not_in'   => $vitrine_ids,
        'orderby'        => 'date',
        'order'          => 'DESC'
    );
    $feed_query = new WP_Query($args_feed);

    ob_start();
    ?>
    <style>
        /* ==========================================================================
           1. ESTRUTURA GERAL E TÍTULOS
           ========================================================================== */
        .uonix-bp-wrapper { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 40px 20px; 
            box-sizing: border-box; 
            font-family: inherit; 
        }

        .uonix-bp-wrapper * { 
            box-sizing: border-box; 
        }

        /* Novo Estilo de Título (Baseado na Referência) */
        .uonix-bp-section-title { 
            margin: 60px 0 30px 0; 
            padding-left: 15px; 
            border-left: 6px solid #f76a0c; /* Barra Laranja */
            color: #0e3780; 
            font-size: 28px; 
            font-weight: 900; 
            text-transform: uppercase; 
            letter-spacing: -0.5px; 
            line-height: 1.1;
        }

        /* Tag de Categoria/Destaque */
        .uonix-bp-label { 
            display: inline-block; 
            margin-bottom: 12px; 
            padding: 6px 12px; 
            border-radius: 4px; 
            background: #f76a0c; 
            color: #fff; 
            font-size: 11px; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
            box-shadow: 0 4px 10px rgba(247, 106, 12, 0.3); 
        }

        /* ==========================================================================
           2. HERO DESTAQUE PRINCIPAL (1 POST)
           ========================================================================== */
        .uonix-bp-hero { 
            display: block; 
            position: relative; 
            width: 100%; 
            aspect-ratio: 16 / 7; 
            margin-bottom: 30px; 
            overflow: hidden; 
            border: 1px solid #eef2f7; 
            border-radius: 5px; 
            text-decoration: none !important; 
        }

        .uonix-bp-hero img { 
            display: block; 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            transition: transform 0.6s ease; 
        }

        .uonix-bp-hero:hover img { 
            transform: scale(1.03); 
        }

        .uonix-bp-hero:hover h2 { 
            border-left: solid 6px #f7630c; 
            padding-left: 10px; 
        }

        .uonix-bp-hero-overlay { 
            position: absolute; 
            inset: 0; 
            display: flex; 
            align-items: flex-end; 
            padding: 20px; 
            background: linear-gradient(to top, rgba(14, 55, 128, 0.95) 0%, rgba(14, 55, 128, 0.4) 60%, transparent 100%); 
        }

        .uonix-bp-hero-content { 
            max-width: 1020px; 
        }

        .uonix-bp-hero-content h2 { 
            margin: 0 0 12px 0; 
            padding-right: 16px; 
            overflow: hidden; 
            color: #fff !important; 
            font-size: 36px; 
            font-weight: 800; 
            line-height: 1.2; 
            text-shadow: 0 2px 5px rgba(0,0,0,0.43); 
            display: -webkit-box; 
            -webkit-line-clamp: 2; 
            -webkit-box-orient: vertical; 
            transition: 0.3s; 
        }

        .uonix-bp-hero-content p { 
            margin: 0; 
            padding-right: 20px; 
            overflow: hidden; 
            color: #e2e8f0; 
            font-size: 18px; 
            font-weight: 400; 
            line-height: 1.5; 
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5); 
            display: -webkit-box; 
            -webkit-line-clamp: 3; 
            -webkit-box-orient: vertical; 
        }

        /* ==========================================================================
           3. GRID DE MAIS CURTIDAS (3 POSTS)
           ========================================================================== */
        .uonix-bp-liked-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 30px; 
        }

        .uonix-bp-liked-card { 
            display: block; 
            position: relative; 
            width: 100%; 
            aspect-ratio: 4 / 3; 
            overflow: hidden; 
            border: 1px solid #eef2f7; 
            border-radius: 5px; 
            text-decoration: none !important; 
        }

        .uonix-bp-liked-card img { 
            display: block; 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            transition: transform 0.6s ease; 
        }

        .uonix-bp-liked-card:hover img { 
            transform: scale(1.05); 
        }

        .uonix-bp-liked-overlay { 
            position: absolute; 
            inset: 0; 
            display: flex; 
            align-items: flex-end; 
            padding: 25px 9px 25px 25px !important; 
            background: linear-gradient(to top, rgba(14, 55, 128, 0.88) 0%, rgba(14, 55, 128, 0.37) 50%, transparent 100%); 
            transition: 0.3s; 
        }

        .uonix-bp-liked-overlay h3 { 
            margin: 0 !important; 
            padding-right: 16px; 
            overflow: hidden; 
            color: #fff !important; 
            font-size: 18px; 
            font-weight: 600; 
            line-height: 1.3; 
            text-shadow: 0 2px 5px rgba(0,0,0,0.43); 
            display: -webkit-box; 
            -webkit-line-clamp: 3; 
            -webkit-box-orient: vertical; 
            transition: 0.3s; 
        }

        .uonix-bp-liked-overlay:hover h3 { 
            border-left: solid 6px #f7630c; 
            padding-left: 10px; 
            padding-right: 0; 
        }

        /* ==========================================================================
           4. GRID FEED GERAL (4 COLUNAS)
           ========================================================================== */
        .uonix-bp-feed-grid { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 25px; 
            margin-top: 20px; 
        }

        .uonix-bp-feed-card { 
            display: flex; 
            flex-direction: column; 
            text-decoration: none !important; 
            transition: transform 0.3s ease; 
        }

        .uonix-bp-feed-card:hover { 
            transform: translateY(-5px); 
        }

        .uonix-bp-feed-thumb { 
            width: 100%; 
            aspect-ratio: 4 / 3; 
            margin-bottom: 12px; 
            overflow: hidden; 
            border: 1px solid #eef2f7; 
            border-radius: 4px; 
            background: #f8fafc; 
        }

        .uonix-bp-feed-thumb img { 
            display: block; 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            transition: filter 0.4s ease; 
        }

        .uonix-bp-feed-card:hover .uonix-bp-feed-thumb img { 
            filter: brightness(0.75); 
        }

        .uonix-bp-feed-meta { 
            margin-bottom: 6px; 
            color: #94a3b8; 
            font-size: 11px; 
            font-weight: 700; 
            text-transform: uppercase; 
        }

        .uonix-bp-feed-title { 
            margin: 0 !important; 
            overflow: hidden; 
            color: #1a202c !important; 
            font-size: 18px; 
            font-weight: 600; 
            line-height: 1.3; 
            transition: 0.3s; 
            display: -webkit-box; 
            -webkit-line-clamp: 3; 
            -webkit-box-orient: vertical; 
        }

        .uonix-bp-feed-card:hover .uonix-bp-feed-title { 
            color: #f76a0c !important; 
        }

        /* ==========================================================================
           5. PAGINAÇÃO
           ========================================================================== */
        .uonix-bp-pagination { 
            display: flex; 
            justify-content: center; 
            gap: 10px; 
            margin-top: 50px; 
        }

        .uonix-bp-pagination a, 
        .uonix-bp-pagination span { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            width: 40px; 
            height: 40px; 
            border-radius: 5px; 
            font-size: 14px; 
            font-weight: 700; 
            text-decoration: none; 
            transition: 0.3s; 
        }

        .uonix-bp-pagination a { 
            border: 1px solid #e2e8f0; 
            background: #f1f5f9; 
            color: #0e3780; 
        }

        .uonix-bp-pagination a:hover { 
            border-color: #0e3780; 
            background: #0e3780; 
            color: #fff; 
        }

        .uonix-bp-pagination span.current { 
            border: 1px solid #f76a0c; 
            background: #f76a0c; 
            color: #fff; 
        }

        .uonix-bp-pagination span.dots { 
            border: none; 
            background: transparent; 
            color: #94a3b8; 
        }

        /* ==========================================================================
           6. RESPONSIVO
           ========================================================================== */
        @media (max-width: 992px) {
            .uonix-bp-hero { aspect-ratio: 16 / 9; }
            .uonix-bp-hero-content h2 { font-size: 28px; }
            .uonix-bp-liked-grid, .uonix-bp-feed-grid { grid-template-columns: repeat(2, 1fr); }
            .uonix-bp-section-title { font-size: 24px; } /* Ajuste de título para tablet */
        }

        @media (max-width: 768px) {
            .uonix-bp-hero { aspect-ratio: 4 / 3; }
            .uonix-bp-liked-grid { grid-template-columns: 1fr; }
            .uonix-bp-feed-grid { grid-template-columns: 1fr; gap: 0; }
            
            /* Formato em Lista no Mobile */
            .uonix-bp-feed-card { 
                display: grid !important; 
                grid-template-columns: 130px minmax(0, 1fr); 
                gap: 16px !important; 
                align-items: center; 
                padding: 7px 0; 
                border-bottom: 1.5px solid #f3f4f6; 
            }
            .uonix-bp-feed-thumb { margin-bottom: 0; border-radius: 5px; }
            .uonix-bp-feed-meta { font-size: 12px; margin-bottom: 0; order: 2; }
            .uonix-bp-feed-title { font-size: 16px; order: 1; }
            
            .uonix-bp-section-title { font-size: 22px; margin: 40px 0 20px 0; } /* Ajuste de título para mobile */
        }

        /* Título principal (H1) da página do blog */
        .uonix-bp-page-title {
            margin: 0 0 24px 0;
            font-size: 30px;
            font-weight: 800;
            line-height: 1.2;
            color: #0e3780;
        }
        @media (max-width: 768px) {
            .uonix-bp-page-title { font-size: 24px; margin-bottom: 18px; }
        }
    </style>

    <div class="uonix-bp-wrapper">
        <?php if ($paged == 1) : ?>
            <h1 class="uonix-bp-page-title">Blog Uônix: Ancoragem Predial e Trabalho em Altura</h1>
        <?php else : ?>
            <h1 class="uonix-bp-page-title">Blog Uônix — Página <?php echo (int) $paged; ?></h1>
        <?php endif; ?>
        <?php if ($paged == 1 && !empty($destaque)) : 
            $f_id = $destaque[0]->ID;
            $f_title = get_the_title($f_id);
        ?>
            <a href="<?php echo esc_url(get_permalink($f_id)); ?>" class="uonix-bp-hero">
                <?php if (has_post_thumbnail($f_id)) { echo get_the_post_thumbnail($f_id, 'full', ['alt' => esc_attr($f_title), 'loading' => 'eager']); } ?>
                <div class="uonix-bp-hero-overlay">
                    <div class="uonix-bp-hero-content">
                        <span class="uonix-bp-label">Destaque</span>
                        <h2><?php echo esc_html($f_title); ?></h2>
                        <p><?php echo wp_trim_words(get_the_excerpt($f_id), 40, '...'); ?></p>
                    </div>
                </div>
            </a>

            <?php if (!empty($liked_posts)) : ?>
                <h3 class="uonix-bp-section-title">Mais Curtidas</h3>
                <div class="uonix-bp-liked-grid">
                    <?php foreach ($liked_posts as $p) : $p_title = get_the_title($p->ID); ?>
                        <a href="<?php echo esc_url(get_permalink($p->ID)); ?>" class="uonix-bp-liked-card">
                            <?php if (has_post_thumbnail($p->ID)) { echo get_the_post_thumbnail($p->ID, 'medium_large', ['alt' => esc_attr($p_title)]); } ?>
                            <div class="uonix-bp-liked-overlay"><h3><?php echo esc_html($p_title); ?></h3></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($feed_query->have_posts()) : ?>
            <h3 class="uonix-bp-section-title"><?php echo ($paged == 1) ? 'Últimas Postagens' : 'Arquivo do Blog - Página ' . $paged; ?></h3>
            <div class="uonix-bp-feed-grid">
                <?php while ($feed_query->have_posts()) : $feed_query->the_post(); ?>
                    <a href="<?php the_permalink(); ?>" class="uonix-bp-feed-card">
                        <div class="uonix-bp-feed-thumb"><?php if (has_post_thumbnail()) { the_post_thumbnail('medium_large'); } ?></div>
                        <div class="uonix-bp-feed">
                            <div class="uonix-bp-feed-meta">
                                <?php echo get_the_date('d M Y'); ?>
                                <?php if ( get_comments_number() > 0 ) : ?> &bull; <?php comments_number('0', '1 comentário', '% comentários'); endif; ?>
                            </div>
                            <h4 class="uonix-bp-feed-title"><?php the_title(); ?></h4>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>

            <div class="uonix-bp-pagination">
                <?php echo paginate_links(array('total' => $feed_query->max_num_pages, 'current' => $paged, 'prev_text' => '«', 'next_text' => '»', 'mid_size' => 2)); ?>
            </div>
        <?php endif; wp_reset_postdata(); ?>
    </div>
    <?php
    return ob_get_clean();
}


