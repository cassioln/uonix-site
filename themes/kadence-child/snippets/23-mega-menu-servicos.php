<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Mega menu - servicos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 6299-6508 do export original.
// -----------------------------------------------------------------------------
/**
 * Mega Menu Servicos
 */
/**
 * UÔNIX: Vitrine de SERVIÇOS V12 - Versão Blindada e Simétrica
 * - 6 Itens em 2 colunas (1 Hero + 5 Serviços Específicos)
 * - Correção de deformação de imagem (Sticky Header)
 * - Responsividade aprimorada (Fim dos cortes de texto)
 */
add_shortcode('uonix_menu_servicos', 'uonix_gerar_mega_menu_servicos_v12');

function uonix_gerar_mega_menu_servicos_v12() {
    $slug_certo = post_type_exists('servico') ? 'servico' : 'servicos';
    
    // Slugs exatos baseados nas URLs que você forneceu
    $slugs_permitidos = array(
        'instalacao-de-pontos-de-ancoragem',
        'art',
        'ensaios-de-arrancamento',
        'relatorio-tecnico-e-fotografico',
        'projetos-de-instalacao'
    );

    $args = array(
        'post_type'      => $slug_certo, 
        'posts_per_page' => 5, 
        'post_status'    => 'publish',
        'post_name__in'  => $slugs_permitidos, // Filtra apenas estes serviços
        'orderby'        => 'post_name__in',   // Garante que apareçam na exata ordem do array acima
    );
    
    $query_servicos = new WP_Query($args);
    ob_start();
    ?>
    
    <style>
        /* ==========================================================
           1. CONTAINER E GRID (FIM DO CORTE DE TEXTO)
           ========================================================== */
        .uonix-ed-wrapper {
            background: #ffffff !important;
            border-radius: 12px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .uonix-ed-grid {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 20px 40px !important; 
            align-items: stretch !important;
        }

        /* ==========================================================
           2. ESTILO DOS CARDS (SIMETRIA TOTAL)
           ========================================================== */
        .uonix-ed-item, .uonix-ed-hero {
            display: flex !important;
            gap: 20px !important;
            text-decoration: none !important;
            padding: 15px !important;
            border: 1px solid #f1f5f9 !important;
            border-radius: 10px !important;
            transition: all 0.3s ease !important;
            background: #ffffff !important;
            min-height: 95px !important; 
        }

		.uonix-ed-item:hover {
			border-color: #f76a0c !important;
			box-shadow: 0 10px 20px rgba(14, 55, 128, 0.05) !important;
			transform: translateY(-3px) !important;
		}

		.uonix-ed-item:hover .uonix-ed-view-detail {
			color: #0e3780 !important;
			transform: translateX(5px) !important;
		}

        /* BLOQUEIO CONTRA IMAGEM ESMAGADA (STICKY HEADER FIX) */
        .uonix-ed-thumb-box {
            width: 140px !important;
            height: 105px !important; 
            flex-shrink: 0 !important;
            overflow: hidden !important;
            border-radius: 6px !important;
        }

        .uonix-ed-thumb-box img {
            width: 100% !important;
            height: 100% !important;
            max-height: none !important; 
            min-height: 100% !important;
            object-fit: cover !important;
            display: block !important;
        }

        /* ==========================================================
           3. HERO CARD (SIMÉTRICO)
           ========================================================== */
        .uonix-ed-hero {
            padding: 0 !important;
            border: none !important;
            background-image: url('/wp-content/uploads/2026/03/instalacao-ponto-ancoragem-mp4-image.jpg') !important;
            background-size: cover !important;
            background-position: center !important;
            justify-content: center !important;
            align-items: center !important;
            position: relative !important;
        }

        .uonix-ed-hero-overlay {
            position: absolute !important;
            width: 100%; height: 100%;
            background: rgba(14, 55, 128, 0.75) !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            transition: 0.3s;
        }

        .uonix-ed-hero:hover .uonix-ed-hero-overlay { background: rgba(10, 39, 91, 0.9) !important; }
        .uonix-ed-hero h3 { font-size: 26px !important; color: #ffffff !important; font-weight: 900 !important; margin: 0 !important; text-transform: uppercase !important; }
        .uonix-ed-hero p { font-size: 13px !important; color: #fff !important; margin: 5px 0 0 0 !important; text-align: center; }

        /* ==========================================================
           4. CONTEÚDO E TEXTO (DIMENSIONAMENTO)
           ========================================================== */
        .uonix-ed-content { 
			flex: 1 !important; 
			display: flex !important;
			flex-direction: column !important; 
		    justify-content: space-between;
    		height: 100%;
		}
						  
        .uonix-ed-content h5 { font-size: 1.5rem !important;
							  color: #0e3780 !important; 
							  font-weight: 800 !important; 
							  margin: 0 0 5px 0 !important; 
							  line-height: 1.2 !important; }
							  
        .uonix-ed-content p { 
            font-size: 16px !important; color: #64748b !important; line-height: 1.4 !important; margin: 0 !important;
            display: -webkit-box !important; -webkit-line-clamp: 2 !important; -webkit-box-orient: vertical !important; overflow: hidden !important;
        }
        .uonix-ed-content > .uonix-ed-view-detail {
		    text-align: right !important;
            font-size: 15px !important;
            font-weight: 600 !important;
			color: #f76a0c !important;
		}				  
							  	
        /* ==========================================================
           5. RESPONSIVIDADE (FIX PARA TELAS PEQUENAS)
           ========================================================== */
        @media (max-width: 1300px) {
            .uonix-ed-content h5 { font-size: 1.2rem !important; }
        }

        @media (max-width: 1200px) {
            .uonix-ed-grid { grid-template-columns: 1fr !important; }
            .uonix-ed-item { min-height: 0 !important; }
        }
    </style>

    <div class="uonix-ed-wrapper">
        <div class="uonix-ed-grid">
            
            <a href="/servicos/" class="uonix-ed-hero">
                <div class="uonix-ed-hero-overlay">
                    <h3>SERVIÇOS</h3>
                    <p>Soluções completas em ancoragem predial</p>
                </div>
            </a>

            <?php 
            if ($query_servicos->have_posts()) :
                while ($query_servicos->have_posts()) : $query_servicos->the_post(); 
                    $titulo_limpo = str_replace(['<br>', '<br/>', '<br />'], ' - ', get_the_title());
                    $img_url = get_the_post_thumbnail_url(get_the_ID(), 'medium_large');
                    $excerpt = get_the_excerpt();
            ?>
            <a href="<?php the_permalink(); ?>" class="uonix-ed-item">
                <div class="uonix-ed-thumb-box">
                    <?php if ($img_url) : ?>
                        <img src="<?php echo esc_url($img_url); ?>" alt="<?php the_title(); ?>">
                    <?php else: ?>
                        <div style="width:100%; height:100%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:10px; font-weight:800;">UÔNIX</div>
                    <?php endif; ?>
                </div>

                <div class="uonix-ed-content">
					<div>
                    	<h5><?php echo esc_html($titulo_limpo); ?></h5>
                    	<p><?php echo esc_html($excerpt); ?></p>
					</div>
					<p class="uonix-ed-view-detail"><?php echo esc_html("Ver Detalhes →"); ?></p>
                </div>
            </a>
            <?php endwhile; wp_reset_postdata(); endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
