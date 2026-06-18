<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Mega menu - blog.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 6509-7166 do export original.
// -----------------------------------------------------------------------------
/**
 * Mega Menu Blog
 */
/**
 * UÔNIX: Mega Menu Blog V23 (Lógica de Prioridade e Fallback)
 * 1. Destaque (Mais recente)
 * 2. Mais Curtidas (Por likes. Se faltar, preenche com recentes)
 * 3. Leia Também (O que sobrar dos recentes)
 */
add_shortcode('uonix_menu_blog', 'uonix_gerar_mega_menu_blog_v23');

function uonix_gerar_mega_menu_blog_v23() {
    
    // --- 1. DESTAQUE (O Mais Recente Absoluto) ---
    $destaque = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC'
    ));
    $destaque_id = !empty($destaque) ? $destaque[0]->ID : 0;

	// --- 2. MAIS CURTIDAS (Busca pelo Plugin) ---
    $liked_posts = array();
    if ($destaque_id) {
        $liked_posts = get_posts(array(
            'post_type'      => 'post',
            'posts_per_page' => 2,
            'post_status'    => 'publish',
            'post__not_in'   => array($destaque_id),
            'meta_key'       => '_wthf_yes', // Sua chave correta!
            'orderby'        => array(
                'meta_value_num' => 'DESC', // 1º: Maior número de Likes
                'comment_count'  => 'DESC', // 2º: Maior número de Comentários
                'date'           => 'DESC'  // 3º: Mais recente
            )
            // A linha 'order' => 'DESC' foi apagada daqui!
        ));
    }

    // --- 2.1 FALLBACK (Preenche buracos se não tiver likes suficientes) ---
    $liked_ids = wp_list_pluck($liked_posts, 'ID');
    if (count($liked_posts) < 2) {
        $needed = 2 - count($liked_posts);
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
        $liked_ids = wp_list_pluck($liked_posts, 'ID'); // Atualiza a lista de IDs usados
    }

    // --- 3. LEIA TAMBÉM (Os próximos 3 recentes que sobraram) ---
    $exclude_all = array_merge(array($destaque_id), $liked_ids);
    $leia_posts = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => 3,
        'post_status'    => 'publish',
        'post__not_in'   => $exclude_all,
        'orderby'        => 'date',
        'order'          => 'DESC'
    ));

    // Se não tiver post nenhum, aborta
    if (empty($destaque)) return '';

    ob_start();
    ?>
		
	<style>
		
		/* =========================================================
		   CONTAINER PRINCIPAL DO MEGA MENU
		   - Define o layout geral em 2 colunas
		   - esquerda: destaque + mais curtidas
		   - direita: leia também + redes sociais
		   ========================================================= */
		.uonix-v21-container {
			display: grid !important;
			grid-template-columns: max-content minmax(0, 1fr) !important;
			gap: 0 !important;
			padding: 10px !important;
			background: #ffffff !important;
			border-radius: 0 0 14px 14px !important;
			box-sizing: border-box;
			align-items: start;
		}

		/* Aplica box-sizing consistente em todo o bloco */
		.uonix-v21-container,
		.uonix-v21-container * {
			box-sizing: border-box;
		}

		/* =========================================================
		   COLUNA ESQUERDA
		   - Contém o post em destaque e a seção "Mais curtidas"
		   ========================================================= */
		.uonix-v21-left {
			display: flex;
			flex-direction: column;
			gap: 0;
			width: fit-content;
			min-width: 0;
			padding-right: 28px;
			border-right: 1px solid #eef2f7;
		}

		/* =========================================================
		   COLUNA DIREITA
		   - Contém "Leia também" e o bloco social
		   ========================================================= */
		.uonix-v21-right {
			display: flex;
			flex-direction: column;
			width: 100%;
			min-width: 0;
			height: 100%;
		}

		/* Topo da coluna direita e lista ocupam toda a largura */
		.uonix-v21-right-top,
		.uonix-v21-list {
			width: 100%;
		}

		/* Ajuste fino do título de seção dentro da coluna direita */
		.uonix-v21-right-top .uonix-v21-section-title {
			margin-bottom: 0;
		}

		/* =========================================================
		   LABEL / SELO
		   - Ex.: "Destaque"
		   ========================================================= */
		.uonix-v21-label {
			display: inline-block;
			padding: 7px 11px;
			line-height: 1;
			border-radius: 4px;
			background: #f76a0c;
			color: #ffffff;
			font-size: 10px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 1.2px;
			box-shadow: 0 4px 14px rgba(247, 106, 12, 0.28);
		}

		/* =========================================================
		   TÍTULOS DE SEÇÃO
		   - "Mais curtidas"
		   - "Leia também"
		   ========================================================= */
		.uonix-v21-section-title {
			margin-left: 5px;
			margin-bottom: 5px;
			padding-bottom: 0;
			line-height: 1;
			border-bottom: 2px solid #f1f5f9;
			color: #0e3780;
			font-size: 14px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		/* =========================================================
		   WRAPPER PADRÃO DE IMAGEM
		   - Base comum para destaque, cards e thumbs
		   ========================================================= */
		.uonix-v21-img-wrap {
			position: relative;
			display: block;
			width: 100%;
			overflow: hidden;
			border: 1px solid #eef2f7;
			border-radius: 5px;
			background: #f8fafc;
		}

		/* Imagens com animação suave */
		.uonix-v21-img-wrap img {
			display: block;
			width: 100% !important;
			height: 100% !important;
			transition: transform 0.45s ease, opacity 0.3s ease;
		}

		/* Placeholder para posts sem thumbnail */
		.uonix-v21-placeholder {
			width: 100%;
			height: 100%;
			background: linear-gradient(
				135deg,
				rgba(14, 55, 128, 0.08),
				rgba(247, 106, 12, 0.08)
			), #f8fafc;
		}

		/* =========================================================
		   BLOCO DESTAQUE PRINCIPAL
		   - Card principal com imagem 16:9
		   - Texto e metadados sobre a imagem
		   ========================================================= */
		.uonix-v21-featured {
			display: block;
			text-decoration: none !important;
		}

		.uonix-v21-featured-media {
			position: relative;
			width: 538px;
			aspect-ratio: 16 / 9;
		}

		/* Imagem do destaque com recorte completo */
		.uonix-v21-featured-media img {
			object-fit: cover !important;
		}

		/* Overlay azul padrão sobre o destaque */
		.uonix-v21-featured-overlay {
			position: absolute;
			inset: 0;
			display: flex;
			align-items: flex-end;
			padding: 22px;
			background: linear-gradient(
				to top,
				rgba(14, 55, 128, 0.92) 10%,
				rgba(14, 55, 128, 0.55) 42%,
				rgba(14, 55, 128, 0.08) 100%
			);
			transition: background 0.3s ease;
		}

		/* Hover do destaque: overlay muda para laranja */
		.uonix-v21-featured:hover .uonix-v21-featured-overlay {
			background: linear-gradient(
				to top,
				rgb(247 106 12 / 66%) 12%,
				rgba(247, 106, 12, 0.52) 45%,
				rgba(247, 106, 12, 0.06) 100%
			);
		}

		/* Área interna de conteúdo do destaque */
		.uonix-v21-featured-text {
			max-width: 78%;
		}

		/* Título do destaque */
		.uonix-v21-featured-text h2 {
			margin: 10px 0 8px;
			overflow: hidden;
			color: #ffffff;
			font-size: 25px;
			line-height: 1.15;
			text-shadow: 0 2px 5px rgb(0 0 0 / 43%);
			display: -webkit-box;
			-webkit-line-clamp: 3;
			-webkit-box-orient: vertical;
			transition: color 0.3s ease;
		}

		/* Data do destaque */
		.uonix-v21-featured-meta {
			color: rgba(255, 255, 255, 0.88);
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.6px;
		}

		/* Zoom suave da imagem no hover do destaque */
		.uonix-v21-featured:hover img {
			transform: scale(1.04);
		}

		/* =========================================================
		   GRID "MAIS CURTIDAS"
		   - Dois cards menores lado a lado
		   ========================================================= */
		.uonix-v21-grid {
			display: grid;
			grid-template-columns: repeat(2, 260px);
			gap: 18px;
			width: max-content;
		}

		/* Espaço acima da seção "Mais curtidas" */
		.uonix-mais-curtidas-menu {
			margin-top: 10px;
		}

		/* Link do card */
		.uonix-v21-card {
			display: block;
			text-decoration: none !important;
		}

		/* Proporção do card menor */
		.uonix-v21-card-media {
			aspect-ratio: 4 / 3;
		}

		/* Imagem do card menor */
		.uonix-v21-card-media img {
			object-fit: cover !important;
			transition: transform 0.3s ease, filter 0.3s ease;
		}

		/* Overlay azul padrão dos cards menores */
		.uonix-v21-card-overlay {
			position: absolute;
			inset: 0;
			display: flex;
			align-items: flex-end;
			padding: 16px 14px;
			background: linear-gradient(
				to top,
				rgba(14, 55, 128, 0.94) 14%,
				rgba(14, 55, 128, 0.55) 45%,
				rgba(14, 55, 128, 0.06) 100%
			);
			transition: background 0.3s ease;
		}

		/* Título do card menor */
		.uonix-v21-card h3 {
			margin: 0;
			overflow: hidden;
			color: #ffffff !important;
			font-size: 18px;
			font-weight: 600;
			line-height: 1.22;
			text-shadow: 0 2px 5px rgb(0 0 0 / 43%);
			display: -webkit-box;
			-webkit-line-clamp: 3;
			-webkit-box-orient: vertical;
			transition: color 0.3s ease;
		}

		/* Zoom da imagem do card menor no hover */
		.uonix-v21-card:hover .uonix-v21-card-media img {
			transform: scale(1.05);
		}

		/* Hover do card menor: overlay muda para laranja */
		.uonix-v21-card:hover .uonix-v21-card-overlay {
			background: linear-gradient(
				to top,
				rgb(247 106 12 / 66%) 12%,
				rgba(247, 106, 12, 0.52) 45%,
				rgba(247, 106, 12, 0.06) 100%
			);
		}

		/* =========================================================
		   LISTA "LEIA TAMBÉM"
		   - Itens com thumb, título e data
		   ========================================================= */
		.uonix-v21-list {
			display: flex;
			flex-direction: column;
		}

		.uonix-v21-list-item {
			display: grid !important;
			grid-template-columns: 130px minmax(0, 1fr);
			gap: 16px !important;
			align-items: center;
			padding-bottom: 7px;
			padding-top: 7px;
			border-bottom: 1.5px solid #f3f4f6;
			text-decoration: none !important;
			transition: transform 0.25s ease;
		}

		/* Remove borda do último item */
		.uonix-v21-list-item:last-child {
			padding-bottom: 0;
			border-bottom: 0;
		}

		/* Thumb lateral */
		.uonix-v21-list-thumb {
			border: 0;
			aspect-ratio: 4 / 3 !important;
			border-left: solid 3px transparent;
			border-radius: 5px !important;
			transition: box-shadow 0.25s ease;
			transition: border-left 0.25s;
		}

		/* Imagem da thumb lateral */
		.uonix-v21-list-thumb img {
			background: #ffffff;
			object-fit: cover !important;
			transition: transform 0.25s ease, filter 0.25s ease;
		}

		
	
		.uonix-v21-list-content {
			display: flex;
			flex-direction: column;
			justify-content: space-around;
			height: 100%;
		}
	
		/* Título do item da lista */
		.uonix-v21-list-content h4 {
			overflow: hidden;
			color: #0e3780;
			font-size: 17px;
			font-weight: 600;
			line-height: 1.28;
			display: -webkit-box;
			-webkit-line-clamp: 3;
			-webkit-box-orient: vertical;
			transition: color 0.3s ease;
		}

		/* Data da lista */
		.uonix-v21-list-date {
			color: #94a3b8;
			font-size: 12px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.4px;
			line-height: 1;
		}
	
		/* Hover na thumb da lista */
		.uonix-v21-list-item:hover .uonix-v21-list-thumb {
			transform: scale(1.01);
			filter: brightness(0.96);
			border-left: solid 3px #f7630c;
			border-top-left-radius: 0px !important;
			border-bottom-left-radius: 0px !important;
			transition: box-shadow 0.25s ease;
		}

		/* Hover no título da lista */
		.uonix-v21-list-item:hover h4 {
			color: #f76a0c;
		}


		/* =========================================================
		   BLOCO SOCIAL
		   - Fixa visualmente no rodapé da coluna direita
		   ========================================================= */
		.uonix-v21-social {
			margin-top: auto;
			padding: 0 10px;
			border-top: 1px solid #f1f5f9;
		}

		/* Título do bloco social */
		.uonix-v21-social-title {
			display: block;
			padding: 10px 2px 5px;
			margin: 0 1px 10px 1px;
			line-height: 1 !important;
			border-bottom: 3px solid #0e3780 !important;
			color: #0e3780;
			font-size: 18px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.8px;
		}

		/* Linha de ícones sociais */
		.uonix-v21-social-row {
			display: flex;
			flex-wrap: nowrap;
			width: 100%;
			justify-content: space-between;
			align-items: center;
			gap: 0px;
		}

		/* Botão individual social */
		.uonix-v21-social-link {
			line-height: 0;
			color: #ffffff !important;
			padding: 18px;
			border-radius: 5px;
			background-color: #0e3780;
			transition: transform 0.25s ease, color 0.25s ease;
		}

		/* Hover do botão social */
		.uonix-v21-social-link:hover {
			transform: translateY(-2px) scale(1.05);
			background-color: #f7630c !important;
		}

		/* Tamanho dos ícones SVG */
		.uonix-v21-social-link svg {
			display: block;
			width: 35px !important;
			height: 35px !important;
		}

		/* =========================================================
		   ACESSIBILIDADE
		   - Texto visível apenas para leitores de tela
		   ========================================================= */
		.uonix-v21-sr-only {
			position: absolute !important;
			width: 1px !important;
			height: 1px !important;
			padding: 0 !important;
			margin: -1px !important;
			overflow: hidden !important;
			clip: rect(0, 0, 0, 0) !important;
			white-space: nowrap !important;
			border: 0 !important;
		}

		/* =========================================================
		   BLINDAGEM CONTRA HEADER STICKY
		   - Evita que o sticky do tema deforme as imagens
		   ========================================================= */
		.header-sticky-wrapper .uonix-v21-container img,
		.header-desktop-sticky .uonix-v21-container img,
		.is-stuck .uonix-v21-container img {
			max-height: none !important;
			min-height: 100% !important;
			height: 100% !important;
		}
	
	</style>

    <div class="uonix-v21-container" aria-label="Mega menu do blog Uônix">
        <div class="uonix-v21-left">
            <?php 
                $f_id    = $destaque[0]->ID;
                $f_title = get_the_title($f_id);
            ?>
            <a href="<?php echo esc_url(get_permalink($f_id)); ?>" class="uonix-v21-featured" aria-label="<?php echo esc_attr($f_title); ?>">
                <div class="uonix-v21-img-wrap uonix-v21-featured-media">
                    <?php if (has_post_thumbnail($f_id)) {
                        echo get_the_post_thumbnail($f_id, 'large', ['alt' => esc_attr($f_title), 'loading' => 'lazy']);
                    } else {
                        echo '<div class="uonix-v21-placeholder" aria-hidden="true"></div>';
                    } ?>
                    <div class="uonix-v21-featured-overlay">
                        <div class="uonix-v21-featured-text">
                            <span class="uonix-v21-label">Destaque</span>
                            <h2><?php echo esc_html($f_title); ?></h2>
                            <div class="uonix-v21-featured-meta"><?php echo esc_html(get_the_date('d M Y', $f_id)); ?></div>
                        </div>
                    </div>
                </div>
            </a>

            <?php if (!empty($liked_posts)) : ?>
            <div class="uonix-mais-curtidas-menu">
                <div class="uonix-v21-section-title">Mais curtidas</div>
                <div class="uonix-v21-grid">
                    <?php foreach ($liked_posts as $p) : 
                        $p_title = get_the_title($p->ID);
                    ?>
                        <a href="<?php echo esc_url(get_permalink($p->ID)); ?>" class="uonix-v21-card" aria-label="<?php echo esc_attr($p_title); ?>">
                            <div class="uonix-v21-img-wrap uonix-v21-card-media">
                                <?php if (has_post_thumbnail($p->ID)) {
                                    echo get_the_post_thumbnail($p->ID, 'medium_large', ['alt' => esc_attr($p_title), 'loading' => 'lazy']);
                                } else {
                                    echo '<div class="uonix-v21-placeholder" aria-hidden="true"></div>';
                                } ?>
                                <div class="uonix-v21-card-overlay">
                                    <h3><?php echo esc_html($p_title); ?></h3>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="uonix-v21-right">
            <div class="uonix-v21-right-top">
                <?php if (!empty($leia_posts)) : ?>
                    <div class="uonix-v21-section-title">Leia também</div>
                    <div class="uonix-v21-list">
                        <?php foreach ($leia_posts as $l) : 
                            $l_title = get_the_title($l->ID);
                        ?>
                            <a href="<?php echo esc_url(get_permalink($l->ID)); ?>" class="uonix-v21-list-item" aria-label="<?php echo esc_attr($l_title); ?>">
                                <div class="uonix-v21-img-wrap uonix-v21-list-thumb">
                                    <?php if (has_post_thumbnail($l->ID)) {
                                        echo get_the_post_thumbnail($l->ID, 'medium', ['alt' => esc_attr($l_title), 'loading' => 'lazy']);
                                    } else {
                                        echo '<div class="uonix-v21-placeholder" aria-hidden="true"></div>';
                                    } ?>
                                </div>
                                <div class="uonix-v21-list-content">
                                    <h4><?php echo esc_html($l_title); ?></h4>
                                    <div class="uonix-v21-list-date"><?php echo esc_html(get_the_date('d M Y', $l->ID)); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="uonix-v21-social">
                <span class="uonix-v21-social-title">Siga a Uônix</span>
				<div class="uonix-v21-social-row">
					<a href="[uonix social_facebook]" target="_blank" rel="noopener noreferrer" class="uonix-v21-social-link" aria-label="Facebook da Uônix">
						<span class="uonix-v21-sr-only">Facebook</span>
						<svg viewBox="0 0 320 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M279.14 288l14.22-92.66h-88.91v-60.13c0-25.35 12.42-50.06 52.24-50.06h40.42V6.26S260.43 0 225.36 0c-73.22 0-121.08 44.38-121.08 124.72v70.62H22.89V288h81.39v224h100.17V288z"></path>
						</svg>
					</a>

					<a href="[uonix social_linkedin]" target="_blank" rel="noopener noreferrer" class="uonix-v21-social-link" aria-label="LinkedIn da Uônix">
						<span class="uonix-v21-sr-only">LinkedIn</span>
						<svg viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M100.3 480H7.4V180.9h92.9V480zM53.8 140.1C24.1 140.1 0 115.5 0 85.8 0 56.1 24.1 32 53.8 32c29.7 0 53.8 24.1 53.8 53.8 0 29.7-24.1 54.3-53.8 54.3zM448 480h-92.7V334.4c0-34.7-.7-79.2-48.3-79.2-48.3 0-55.7 37.7-55.7 76.7V480h-92.8V180.9h89.1v40.8h1.3c12.4-23.5 42.7-48.3 87.9-48.3 94 0 111.3 61.9 111.3 142.3V480z"></path>
						</svg>
					</a>

					<a href="[uonix social_youtube]" target="_blank" rel="noopener noreferrer" class="uonix-v21-social-link" aria-label="YouTube da Uônix">
						<span class="uonix-v21-sr-only">YouTube</span>
						<svg viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"></path>
						</svg>
					</a>

					<a href="[uonix social_instagram]" target="_blank" rel="noopener noreferrer" class="uonix-v21-social-link" aria-label="Instagram da Uônix">
						<span class="uonix-v21-sr-only">Instagram</span>
						<svg viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"></path>
						</svg>
					</a>
				</div>
            </div>
        </div>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}

