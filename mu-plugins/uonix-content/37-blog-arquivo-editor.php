<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Blog - arquivo, redirecionamento e editor classico.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 13619-13863 do export original.
// -----------------------------------------------------------------------------
/**
 * Redirecionamento de paginas
 */
/**
 * UÔNIX: Redirecionamento da Categoria Blog para a Página Blog
 */
add_action('template_redirect', 'uonix_redirecionar_categoria_blog');

function uonix_redirecionar_categoria_blog() {
    // Verifica se o usuário está acessando a categoria com o slug 'blog'
    if ( is_category('blog') ) {
        // Redireciona permanentemente (301) para a página /blog/
        wp_redirect( home_url( '/blog/' ), 301 );
        exit;
    }
}

/**
 * Usar Editor Clássico para Posts do Blog
 */
/**
 * UÔNIX: Usar Editor Clássico APENAS para Posts do Blog
 */
add_filter('use_block_editor_for_post_type', 'uonix_editor_classico_para_blog', 10, 2);

function uonix_editor_classico_para_blog($usar_blocos, $tipo_de_post) {
    // Se o tipo de publicação for 'post' (notícias do blog), retorna FALSE (desativa os blocos)
    if ($tipo_de_post === 'post') {
        return false;
    }
    
    // Para todo o resto (pages, produtos, serviços), mantém o padrão (Gutenberg/Kadence)
    return $usar_blocos;
}

/**
 * Arquivos do Blog (Tags, Categorias, Home do Blog)
 */
/**
 * UÔNIX: Estilo Premium para Arquivos do Blog (Tags, Categorias, Home do Blog)
 */
add_action('wp_head', 'uonix_estilos_premium_blog_archive');

function uonix_estilos_premium_blog_archive() {
    $is_shop             = function_exists( 'is_shop' ) && is_shop();
    $is_product_taxonomy = function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();

    // Só carrega se for a página inicial do blog, categoria ou tag (e ignora as páginas da loja)
    if ( ( is_home() || is_category() || is_tag() || is_archive() ) && ! $is_shop && ! $is_product_taxonomy && !is_search() ) {
        ?>
        <style>
            /* ==========================================================================
               1. HEADER DA PÁGINA DE TAG/CATEGORIA
               ========================================================================== */
            header.entry-header.post-archive-title {
                background: transparent !important;
                border: none !important;
                padding: 0 0 20px 0 !important;
                margin-bottom: 40px !important;
                text-align: center !important;
                position: relative;
            }

            header.entry-header.post-archive-title .page-title {
                color: #0e3780 !important;
                font-size: 36px !important;
                font-weight: 900 !important;
                text-transform: uppercase !important;
                letter-spacing: -0.5px;
                display: inline-block;
                position: relative;
                padding-bottom: 12px;
            }

            /* Sublinhado Laranja Uônix no Título */
            header.entry-header.post-archive-title .page-title::after {
                content: "";
                position: absolute;
                bottom: 0;
                left: 50%;
                transform: translateX(-50%);
                width: 60px;
                height: 4px;
                background: #f76a0c;
                border-radius: 4px;
            }

            /* ==========================================================================
               2. CARDS DE NOTÍCIAS (GRID)
               ========================================================================== */
            #archive-container.post-archive .entry-list-item article.loop-entry {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                overflow: hidden !important;
                transition: all 0.3s ease !important;
                box-shadow: 0 4px 15px rgba(14, 55, 128, 0.02) !important;
                
                /* Mágica Flexbox: Todos os cards com a mesma altura */
                display: flex !important;
                flex-direction: column !important;
                height: 100% !important;
                padding: 0 !important;
            }

            #archive-container.post-archive .entry-list-item article.loop-entry:hover {
                border-color: #cbd5e1 !important;
                box-shadow: 0 12px 30px rgba(14, 55, 128, 0.08) !important;
                transform: translateY(-4px) !important;
            }

   /* ==========================================================================
               3. IMAGEM DESTACADA (Força Bruta 2.0 - Garantia de Exibição)
               ========================================================================== */
            .post-archive .entry-list-item article.loop-entry .post-thumbnail {
                display: block !important;
                width: 100% !important;
                min-height: 200px !important; /* TRAVA DE SEGURANÇA: Impede que o Lazy Load zere a altura */
                flex: 0 0 auto !important; 
                margin: 0 !important;
                padding: 0 !important; 
                border-bottom: 1px solid #eaf0f6 !important;
            }

            .post-archive .entry-list-item article.loop-entry .post-thumbnail-inner {
                display: block !important;
                width: 100% !important;
                aspect-ratio: 4 / 3 !important; /* Mantém a proporção perfeita */
                padding: 0 !important;
                margin: 0 !important;
                height: auto !important;
                position: relative !important; /* Essencial para a imagem absoluta */
                overflow: hidden !important;
                background: #f8fafc !important;
            }

            .post-archive .entry-list-item article.loop-entry .post-thumbnail-inner img {
                display: block !important;
                position: absolute !important; /* Desprende a imagem do erro do Flexbox */
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                object-fit: cover !important;
                visibility: visible !important; /* Força exibição contra bloqueios do tema */
                opacity: 1 !important;
                transition: transform 0.5s ease !important;
                border-radius: 0 !important;
            }

            .post-archive .entry-list-item article.loop-entry:hover .post-thumbnail-inner img {
                transform: scale(1.05) !important;
            }

            /* ==========================================================================
               4. CONTEÚDO E TEXTOS
               ========================================================================== */
            .post-archive .entry-content-wrap {
                padding: 24px 28px !important;
                flex: 1 !important;
                display: flex !important;
                flex-direction: column !important;
            }

            .post-archive h2.entry-title {
                margin: 0 0 12px 0 !important;
            }

            .post-archive h2.entry-title a {
                color: #0e3780 !important;
                font-size: 19px !important;
                font-weight: 800 !important;
                line-height: 1.3 !important;
                text-decoration: none !important;
                transition: color 0.3s ease !important;
            }

            .post-archive article.loop-entry:hover h2.entry-title a {
                color: #f76a0c !important;
            }

            .post-archive .entry-summary {
                flex: 1 !important; /* Empurra o botão pro rodapé */
            }

            .post-archive .entry-summary p {
                color: #475569 !important;
                font-size: 15px !important;
                line-height: 1.5 !important;
                margin: 0 0 20px 0 !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 3 !important; /* Trava em exatas 3 linhas */
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
            }

            /* ==========================================================================
               5. BOTÃO "LER MAIS" (Clean & Enterprise)
               ========================================================================== */
            .post-archive .entry-footer {
                margin-top: auto !important; /* Gruda no final do card */
                padding: 0 !important;
                border: none !important;
            }

            .post-archive a.post-more-link {
                display: inline-flex !important;
                align-items: center !important;
                color: #0e3780 !important;
                background: transparent !important;
                padding: 0 !important;
                font-size: 13px !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
                text-decoration: none !important;
                transition: color 0.2s ease !important;
            }

            .post-archive a.post-more-link:hover {
                color: #f76a0c !important;
                box-shadow: none !important;
                transform: none !important;
            }

            .post-archive a.post-more-link svg {
                fill: currentColor !important;
                margin-left: 6px !important;
                transition: transform 0.2s ease !important;
            }

            .post-archive a.post-more-link:hover svg {
                transform: translateX(4px) !important;
            }

            /* ==========================================================================
               6. PAGINAÇÃO
               ========================================================================== */
            nav.pagination .nav-links { display: flex; gap: 8px; justify-content: center; margin-top: 30px; }
            nav.pagination .page-numbers { display: flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; border-radius: 6px; background: #ffffff; border: 1px solid #e2e8f0; color: #0e3780; font-weight: 800; text-decoration: none; transition: all 0.3s ease; }
            nav.pagination .page-numbers:hover { border-color: #f76a0c; color: #f76a0c; }
            nav.pagination .page-numbers.current { background: #f76a0c; border-color: #f76a0c; color: #ffffff; }

        </style>
        <?php
    }
}


