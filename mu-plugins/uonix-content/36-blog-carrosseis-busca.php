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
// -----------------------------------------------------------------------------
// Carrosseis WCPS:
// - Banner Hero da Home [wcps id='1546']:
//   Layout comercial, estilizacao completa, conversao para botao "Solicitar Orcamento"
//   e injecao de titulo gerenciados em:
//   themes/kadence-child/snippets/36-wcps-destaques-slider.php
//
// - Carrossel Sidebar Blog [wcps id='8643']:
//   Hook de conversao do botao para "Solicitar Orcamento" gerenciado em:
//   themes/kadence-child/snippets/36-wcps-destaques-slider.php
//   Migracao e integridade de banco automatizadas via scripts/migrate-wcps-sliders-and-widgets.php
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


