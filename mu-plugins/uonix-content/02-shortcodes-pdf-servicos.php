<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Shortcodes utilitarios de PDFs e servicos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 52-132 do export original.
// -----------------------------------------------------------------------------
/**
 * Botão de documento com Ícone PDF
 *
 * exemplo:
 * [doc_pdf link="..." texto="Baixar Catálogo 2026"]
 */
/* Shortcode para Botão PDF (doc_pdf) */
add_shortcode( 'doc_pdf', 'uonix_botao_doc_pdf' );

function uonix_botao_doc_pdf( $atts ) {
    // Configurações padrão
    $atts = shortcode_atts( array(
        'link'  => '#',
        'texto' => 'Baixar Arquivo (PDF)', // Texto padrão genérico
    ), $atts );

    // Início do Botão
    $html = '<a href="' . esc_url( $atts['link'] ) . '" target="_blank" class="kb-button kt-button button button-style-secondary kt-btn-size-standard" style="display: inline-flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none;">';
    
    // Ícone SVG (PDF)
    $html .= '<span class="kb-svg-icon-wrap" style="display: flex; align-items: center;"><svg viewBox="0 0 384 512" fill="currentColor" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M181.9 256.1c-5-16-4.9-46.9-2-46.9 8.4 0 7.6 36.9 2 46.9zm-1.7 47.2c-7.7 20.2-17.3 43.3-28.4 62.7 18.3-7 39-17.2 62.9-21.9-12.7-9.6-24.9-23.4-34.5-40.8zM86.1 428.1c0 .8 13.2-5.4 34.9-40.2-6.7 6.3-29.1 24.5-34.9 40.2zM248 160h136v328c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V24C0 10.7 10.7 0 24 0h200v136c0 13.2 10.8 24 24 24zm-8 171.8c-20-12.2-33.3-29-42.7-53.8 4.5-18.5 11.6-46.6 6.2-64.2-4.7-29.4-42.4-26.5-47.8-6.8-5 18.3-.4 44.1 8.1 77-11.6 27.6-28.7 64.6-40.8 85.8-.1 0-.1.1-.2.1-27.1 13.9-73.6 44.5-54.5 68 5.6 6.9 16 10 21.5 10 17.9 0 35.7-18 61.1-61.8 25.8-8.5 54.1-19.1 79-23.2 21.7 11.8 47.1 19.5 64 19.5 29.2 0 31.2-32 19.7-43.4-13.9-13.6-54.3-9.7-73.6-7.2zM377 105L279 7c-4.5-4.5-10.6-7-17-7h-6v128h128v-6.1c0-6.3-2.5-12.4-7-16.9zm-74.1 255.3c4.1-2.7-2.5-11.9-42.8-9 37.1 15.8 42.8 9 42.8 9z"></path></svg></span>';
    
    // Texto Dinâmico
    $html .= '<span class="kt-btn-inner-text">' . esc_html( $atts['texto'] ) . '</span>';
    
    $html .= '</a>';

    return $html;
}

/**
 * Botao img download manual tecnico
 *
 * exemplo de uso:
 * [manual_pdf link="LINK_DO_SEU_PDF_AQUI"]
 */
/* Shortcode Específico: Botão Imagem Manual PDF */
add_shortcode( 'manual_pdf', 'uonix_botao_imagem_manual' );

function uonix_botao_imagem_manual( $atts ) {
    // Pega o link do PDF
    $atts = shortcode_atts( array(
        'link' => '#',
    ), $atts );

    // URL da sua imagem (Caminho relativo para funcionar no localhost e depois no online)
    $img_url = '/wp-content/uploads/2026/02/download_manualuonix.png';

    // CSS embutido para controlar o Hover (efeito de escurecer)
    $style = '<style>
        .uonix-btn-manual-img {
            display: inline-block;
            text-decoration: none !important; /* Remove sublinhado padrão do tema */
            border: none;
            transition: all 0.3s ease;
        }
        
        .uonix-btn-manual-img img {
            display: block;
            max-width: 100%; /* Garante que não estoure no celular */
            height: auto;
            transition: filter 0.3s ease, transform 0.3s ease;
            box-shadow: none !important; /* Remove bordas padrões de imagens do WP */
        }

        /* O Efeito Hover (Ao passar o mouse) */
        .uonix-btn-manual-img:hover img {
            filter: brightness(0.85); /* Escurece para 85% */
            transform: translateY(-3px); /* Sobe um pouquinho (3px) */
        }
    </style>';

    // Monta o HTML
    $html = $style;
    $html .= '<a href="' . esc_url( $atts['link'] ) . '" target="_blank" class="uonix-btn-manual-img">';
    $html .= '<img src="' . esc_url( $img_url ) . '" alt="Clique para Baixar o Manual Técnico" />';
    $html .= '</a>';

    return $html;
}


// -----------------------------------------------------------------------------
// Bloco 2 - linhas 217-332 do export original.
// -----------------------------------------------------------------------------
/**
 * Lista todos os serviços do Pods automaticamente em grade
 */
/* * Shortcode: [uonix_lista_servicos]
 * Lista todos os serviços do Pods automaticamente em grade
 */
add_shortcode('uonix_lista_servicos', 'uonix_render_lista_servicos');

function uonix_render_lista_servicos() {
    // 1. Configurar a busca (Query) para pegar o Post Type 'servico'
    $args = array(
        'post_type'      => 'servico', // Certifique-se que o slug no Pods é 'servico'
        'posts_per_page' => -1,        // -1 mostra todos
        'orderby'        => 'date',    // Ordenar por data
        'order'          => 'DESC',    // Mais recentes primeiro
    );

    $query = new WP_Query($args);
    $html = '';

    if ($query->have_posts()) {
        $html .= '<div class="uonix-servicos-grid">';

        while ($query->have_posts()) {
            $query->the_post();
            
            $link = get_permalink();
            $titulo = get_the_title();
            $imagem = get_the_post_thumbnail_url(get_the_ID(), 'medium_large');
            $resumo = wp_trim_words(get_the_excerpt(), 15); // Pega as primeiras 15 palavras

            // Se não tiver imagem cadastrada, usa uma padrão
            if (!$imagem) $imagem = '/wp-content/uploads/2026/01/placeholder_uonix.jpg';

            $html .= '
            <div class="uonix-servico-card">
                <div class="uonix-servico-img" style="background-image: url('.$imagem.');"></div>
                <div class="uonix-servico-content">
                    <h3>'.$titulo.'</h3>
                    <p>'.$resumo.'</p>
                    <a href="'.$link.'" class="uonix-btn-servico">Ver Detalhes</a>
                </div>
            </div>';
        }

        $html .= '</div>';
        wp_reset_postdata();
    } else {
        $html = '<p>Nenhum serviço cadastrado no momento.</p>';
    }

    // 2. CSS para deixar o layout bonito (Grid de 3 colunas)
    $css = '
    <style>
        .uonix-servicos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }
        .uonix-servico-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .uonix-servico-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08);
        }
        .uonix-servico-img {
            height: 200px;
            background-size: cover;
            background-position: center;
        }
        .uonix-servico-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .uonix-servico-content h3 {
            margin: 0 0 10px;
            font-size: 1.25rem;
            color: #333;
        }
        .uonix-servico-content p {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .uonix-btn-servico {
            margin-top: auto;
            background: #222;
            color: #fff;
            text-align: center;
            padding: 10px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.3s;
        }
        .uonix-btn-servico:hover {
            background: #ff6b00; /* Cor de destaque da Uônix */
            color: #fff;
        }
    </style>';

    return $css . $html;
}


// -----------------------------------------------------------------------------
// Bloco 3 - linhas 1177-1273 do export original.
// -----------------------------------------------------------------------------
/**
 * UÔNIX: Shortcode para Listagem de Serviços (Grid de Cards)
 * Uso: [uonix_servicos]
 */
add_shortcode('uonix_servicos', 'uonix_listagem_servicos_pods');

function uonix_listagem_servicos_pods() {
    $args = array(
        'post_type'      => 'servicos', // Certifique-se que o slug no Pods é este
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC'
    );

    $query = new WP_Query($args);

	$output = '
    <style>
        .uonix-services-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 30px; 
            margin: 30px 0; 
        }
        .uonix-service-card { 
            background: #fff; 
            border: 1px solid #eee; 
            border-radius: 8px; 
            overflow: hidden; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.05); 
            display: flex; 
            flex-direction: column; /* Faz o card ser um container flex vertical */
            height: 100%; 
        }
        .service-card-image { 
            aspect-ratio: 4/3; 
            width:100%; 
            overflow:hidden; 
        }
        .service-card-image img { 
            width:100%; 
            height:100%; 
            object-fit:cover; 
        }
        .service-card-content { 
            padding: 25px; 
            display: flex; 
            flex-direction: column; /* Transforma o conteúdo em flex também */
            flex-grow: 1; /* Faz esta área ocupar todo o espaço restante do card */
        }
        .service-card-content h3 { 
            margin-top: 0; 
            font-size: 20px; 
        }
        .service-card-content p {
            flex-grow: 1; /* O parágrafo cresce para ocupar o espaço vago */
            margin-bottom: 20px;
        }
        .uonix-link { 
            color: #ff6b00; 
            font-weight: bold; 
            text-decoration: none; 
            margin-top: auto; /* empurra o link para o rodapé */
            display: inline-block;
            text-transform: uppercase;
            font-size: 13px;
        }
    </style>
    <div class="uonix-services-grid">';

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $link = get_permalink();
            $thumb = get_the_post_thumbnail_url(get_the_ID(), 'large');
            $resumo = get_the_excerpt();

            $output .= '
            <div class="uonix-service-card">
                <div class="service-card-image">
                    <img src="' . $thumb . '" alt="' . get_the_title() . '">
                </div>
                <div class="service-card-content">
                    <h3>' . get_the_title() . '</h3>
                    <p>' . wp_trim_words($resumo, 20) . '</p>
                    <a href="' . $link . '" class="uonix-link">SAIBA MAIS →</a>
                </div>
            </div>';
        }
        wp_reset_postdata();
    } else {
        $output .= '<p>Nenhum serviço cadastrado no momento.</p>';
    }

    $output .= '</div>';
    return $output;
}

