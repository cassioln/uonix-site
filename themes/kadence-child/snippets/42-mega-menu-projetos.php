<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Mega menu - projetos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 16204-16482 do export original.
// -----------------------------------------------------------------------------
/**
 * Mega menu Projetos
 */
/**
 * UÔNIX: Mega Menu de PROJETOS DE INSTALAÇÃO V6 - Final
 * - Imagens cravadas em 4:3 (Destaque redimensionado para 320px)
 * - Texto de descrição puxado dinamicamente e sem limite de linhas
 * - Background Hero atualizado
 */
add_shortcode('uonix_menu_projetos', 'uonix_gerar_mega_menu_projetos');

function uonix_gerar_mega_menu_projetos() {
    $slug_certo = post_type_exists('servico') ? 'servico' : 'servicos';
    
    // Slugs exatos
    $slugs_permitidos = array(
        'projeto-ancoragem',
        'projeto-cadeirinha-pintura',
        'projeto-andaime-fachadeiro',
        'projeto-balancim'
    );

    $args = array(
        'post_type'      => $slug_certo, 
        'posts_per_page' => 4, 
        'post_status'    => 'publish',
        'post_name__in'  => $slugs_permitidos, 
        'orderby'        => 'post_name__in',   
    );
    
    $query_projetos = new WP_Query($args);
    
    $html_destaque = '';
    $html_secundarios = '';

    if ($query_projetos->have_posts()) {
        while ($query_projetos->have_posts()) {
            $query_projetos->the_post(); 
            global $post;
            
            $titulo_limpo = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
            $img_url = get_the_post_thumbnail_url(get_the_ID(), 'medium_large');
            
            // Puxa o resumo nativo do WordPress
            $excerpt = get_the_excerpt();
            $link = get_permalink();
            
            $img_tag = $img_url ? '<img src="'.esc_url($img_url).'" alt="'.esc_attr(get_the_title()).'">' : '<div class="uonix-pj-placeholder">UÔNIX</div>';

            // Monta o Card de Destaque
            if ($post->post_name === 'projeto-ancoragem') {
                $html_destaque .= '
                <a href="'.$link.'" class="uonix-pj-item destaque">
                    <div class="uonix-pj-thumb-box img-destaque">
                        '.$img_tag.'
                    </div>
                    <div class="uonix-pj-content">
                        <div>
                            <h5>'.esc_html($titulo_limpo).'</h5>
                            <p>'.esc_html($excerpt).'</p>
                        </div>
                        <p class="uonix-pj-view-detail">Detalhes →</p>
                    </div>
                </a>';
            } 
            // Monta os Cards em Coluna
            else {
                $html_secundarios .= '
                <a href="'.$link.'" class="uonix-pj-item small">
                    <div class="uonix-pj-thumb-box img-small">
                        '.$img_tag.'
                    </div>
                    <div class="uonix-pj-content">
                        <div>
                            <h5>'.esc_html($titulo_limpo).'</h5>
                            <p>'.esc_html($excerpt).'</p>
                        </div>
                        <p class="uonix-pj-view-detail">Detalhes →</p>
                    </div>
                </a>';
            }
        }
        wp_reset_postdata();
    }

    ob_start();
    ?>
    
    <style>
        /* ==========================================================
           1. CONTAINER E GRID ASSIMÉTRICO (4 COLUNAS)
           ========================================================== */
        .uonix-pj-wrapper {
            background: #ffffff !important;
            border-radius: 12px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            padding-top: 10px !important; 
        }

        .uonix-pj-grid {
            display: grid !important;
            grid-template-columns: 250px repeat(3, 1fr) !important;
            grid-template-rows: auto 1fr !important;
            gap: 20px !important; 
            align-items: stretch !important;
        }

        /* ==========================================================
           2. HERO CARD 
           ========================================================== */
        .uonix-pj-hero {
            grid-column: 1 / 2 !important;
            grid-row: 1 / 3 !important; 
            border-radius: 10px !important;
            overflow: hidden !important;
            background-image: url('/wp-content/uploads/2026/06/projetos-menu-thumb.png') !important;
            background-size: cover !important;
            background-position: center !important;
            position: relative !important;
            display: flex !important;
        }

        .uonix-pj-hero-overlay {
            width: 100%; height: 100%;
            background: rgba(14, 55, 128, 0.75) !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            transition: 0.3s;
            text-decoration: none !important;
        }

        .uonix-pj-hero:hover .uonix-pj-hero-overlay { background: rgba(10, 39, 91, 0.9) !important; }
        .uonix-pj-hero h3 { font-size: 26px !important; color: #ffffff !important; font-weight: 900 !important; margin: 0 !important; text-transform: uppercase !important; text-align: center; }
        .uonix-pj-hero p { font-size: 13px !important; color: #fff !important; margin: 5px 0 0 0 !important; text-align: center; padding: 0 10px; }

        /* ==========================================================
           3. ESTILOS BASE DOS CARDS (DESTAQUE E PEQUENOS)
           ========================================================== */
        .uonix-pj-item {
            text-decoration: none !important;
            border: 1px solid #f1f5f9 !important;
            border-radius: 10px !important;
            transition: all 0.3s ease !important;
            background: #ffffff !important;
            display: flex !important;
            position: relative !important;
        }

        .uonix-pj-item:hover {
            border-color: #f76a0c !important;
            box-shadow: 0 10px 20px rgba(14, 55, 128, 0.05) !important;
            transform: translateY(-3px) !important;
        }

        /* --- O SUPER CARD DE DESTAQUE --- */
        .uonix-pj-item.destaque {
            grid-column: 2 / 5 !important; 
            flex-direction: row !important; 
            padding: 15px !important;
            gap: 20px !important;
            border-color: #e2e8f0 !important; 
            background: #fafaf9 !important; 
        }
        
        .uonix-pj-item.destaque:hover {
            border-color: #f76a0c !important;
        }

        /* --- OS CARDS VERTICAIS SECUNDÁRIOS --- */
        .uonix-pj-item.small {
            flex-direction: column !important; 
            padding: 12px !important;
            gap: 12px !important;
        }

        /* CONFIGURAÇÃO COMUM DAS IMAGENS (PROPORÇÃO 4:3 CRAVADA) */
        .uonix-pj-thumb-box {
            border-radius: 6px !important;
            overflow: hidden !important;
        }

        /* IMAGEM DO DESTAQUE AUMENTADA PARA 320PX */
        .img-destaque {
            width: 320px !important;
            aspect-ratio: 4 / 3 !important; 
            height: auto !important;
            flex-shrink: 0 !important;
        }

        .img-small {
            width: 100% !important;
            aspect-ratio: 4 / 3 !important; 
            height: auto !important;
        }

        .uonix-pj-thumb-box img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            display: block !important;
        }

        .uonix-pj-placeholder {
            width: 100%; height: 100%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 12px; font-weight: 800;
        }

        /* ==========================================================
           4. CONTEÚDO E TEXTO (SEM LIMITADOR DE LINHAS)
           ========================================================== */
        .uonix-pj-content { 
            flex: 1 !important; 
            display: flex !important;
            flex-direction: column !important; 
            justify-content: space-between !important;
        }
                          
        .uonix-pj-content h5 { 
            color: #0e3780 !important; 
            font-weight: 800 !important; 
            margin: 0 0 5px 0 !important; 
            line-height: 1.2 !important; 
        }
        
        .uonix-pj-item.destaque h5 { font-size: 2.2rem !important; margin-bottom: 8px !important; }
        .uonix-pj-item.small h5 { font-size: 1.15rem !important; }
                              
        .uonix-pj-content p { 
            color: #64748b !important; line-height: 1.4 !important; margin: 0 !important;
        }

        .uonix-pj-item.destaque .uonix-pj-content p { font-size: 20px !important; line-height: 1.5 !important; }
        .uonix-pj-item.small .uonix-pj-content p { font-size: 13px !important; }

        .uonix-pj-view-detail {
            text-align: right !important;
            font-weight: 700 !important;
            color: #f76a0c !important;
            margin-top: 10px !important;
            transition: transform 0.3s ease !important;
        }
        
        .uonix-pj-item.destaque .uonix-pj-view-detail { font-size: 15px !important; }
        .uonix-pj-item.small .uonix-pj-view-detail { font-size: 13px !important; }

        .uonix-pj-item:hover .uonix-pj-view-detail { color: #0e3780 !important; transform: translateX(3px) !important; }                  
                                
        /* ==========================================================
           5. RESPONSIVIDADE
           ========================================================== */
        @media (max-width: 1100px) {
            .uonix-pj-grid { grid-template-columns: 200px repeat(3, 1fr) !important; }
            .img-destaque { width: 220px !important; }
        }
    </style>

    <div class="uonix-pj-wrapper">
        <div class="uonix-pj-grid">
            
            <a href="/servico/projetos-de-instalacao/" class="uonix-pj-hero">
                <div class="uonix-pj-hero-overlay">
                    <h3>PROJETOS</h3>
                    <p>Engenharia e cálculos estruturais</p>
                </div>
            </a>

            <?php 
                echo $html_destaque; 
                echo $html_secundarios;
            ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}


