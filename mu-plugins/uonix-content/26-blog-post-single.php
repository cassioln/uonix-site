<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UONIX Snippets - Blog - post single, sidebar e componentes premium.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 7621-8812 do export original.
// -----------------------------------------------------------------------------
/**
 * Pagina Post Blog
 */
/**
 * UÔNIX: Componentes do Post Premium V5.2 (Master Final + Newsletter Ajustada)
 * - Header e Imagem Destacada (Aspect Ratio 4/3)
 * - Actions Row (Foi Útil + Share) e Carrossel Estáveis
 * - Sidebar Completa: Recentes, Lead Magnet, Autoridade, Assuntos e Newsletter
 * - Botões padronizados
 * - Bloco "Sobre Nós" no mesmo layout de "Material Gratuito"
 * - Newsletter com botão no padrão .uonix-bio-btn e espaçamentos reduzidos
 */

// ==============================================================================
// 1. ESTILO COMPARTILHADO MASTER
// ==============================================================================
add_action('wp_head', 'uonix_estilos_premium_master');
function uonix_estilos_premium_master()
{
    ?>
    <style>
        /* =========================================================
           HEADER DO POST + IMAGEM DESTACADA
           ========================================================= */
        .entry-header.post-title {
            position: relative !important;
            margin: 0 0 24px !important;
            padding: 26px 28px 22px !important;
            border: 1px solid #eaf0f6 !important;
            border-radius: 10px !important;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%) !important;
            box-shadow: 0 10px 30px rgba(14, 55, 128, 0.04) !important;
            overflow: hidden !important;
        }

        .entry-header.post-title::before {
            content: "" !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 4px !important;
            background: linear-gradient(90deg, #f76a0c 0%, #0e3780 100%) !important;
        }

        .entry-header.post-title .entry-title {
            position: relative !important;
            margin: 0 0 16px !important;
            color: #0e3780 !important;
            font-weight: 800 !important;
            line-height: 1.12 !important;
            letter-spacing: -0.02em !important;
            text-wrap: balance !important;
        }

        .entry-header.post-title .entry-title::after {
            content: "" !important;
            display: block !important;
            width: 74px !important;
            height: 4px !important;
            margin-top: 14px !important;
            border-radius: 999px !important;
            background: #f76a0c !important;
        }

        .entry-header.post-title .entry-meta {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            gap: 10px 12px !important;
            margin: 0 !important;
            padding: 0 !important;
            color: #64748b !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.06em !important;
        }

        .entry-header.post-title .meta-label {
            color: #94a3b8 !important;
            font-weight: 700 !important;
        }

        .entry-header.post-title .posted-on a,
        .entry-header.post-title .entry-date,
        .entry-header.post-title .updated {
            color: #64748b !important;
        }

        .entry-header.post-title .meta-comments-link {
            color: #0e3780 !important;
            text-decoration: none !important;
            transition: color 0.25s ease !important;
        }

        .entry-header.post-title .meta-comments-link:hover {
            color: #f76a0c !important;
        }

        .entry-header.post-title .entry-meta-divider-dot>*+*::before {
            display: none !important;
        }

        .post-thumbnail.article-post-thumbnail {
            margin: 0 0 30px !important;
        }

        .post-thumbnail.article-post-thumbnail .post-thumbnail-inner {
            overflow: hidden !important;
            border: 1px solid #eaf0f6 !important;
            border-radius: 10px !important;
            background: #ffffff !important;
            box-shadow: 0 10px 30px rgba(14, 55, 128, 0.05) !important;
        }

        .post-thumbnail.article-post-thumbnail img.post-top-featured,
        .post-thumbnail.article-post-thumbnail img.wp-post-image {
            display: block !important;
            width: 100% !important;
            height: auto !important;
            border-radius: 0 !important;
            aspect-ratio: 4 / 3 !important;
        }

        .post-thumbnail.article-post-thumbnail.kadence-thumbnail-ratio-3-4 .post-thumbnail-inner {
            max-height: none !important;
        }

        .entry-header.post-title+.post-thumbnail.article-post-thumbnail {
            margin-top: 0 !important;
        }

        @media (max-width: 768px) {
            .entry-header.post-title {
                margin-bottom: 20px !important;
                padding: 22px 18px 18px !important;
                border-radius: 8px !important;
            }

            .entry-header.post-title .entry-title {
                margin-bottom: 14px !important;
                line-height: 1.16 !important;
            }

            .entry-header.post-title .entry-title::after {
                width: 56px !important;
                height: 3px !important;
                margin-top: 12px !important;
            }

            .entry-header.post-title .entry-meta {
                gap: 8px !important;
                font-size: 11px !important;
            }

            .entry-header.post-title .posted-on,
            .entry-header.post-title .updated-on,
            .entry-header.post-title .meta-comments {
                padding: 7px 10px !important;
            }

            .post-thumbnail.article-post-thumbnail .post-thumbnail-inner {
                border-radius: 8px !important;
            }
        }

        /* =========================================================
           ESPAÇAMENTO CONTEÚDO vs SIDEBAR (KADENCE)
           ========================================================= */
        @media screen and (min-width: 1025px) {
            .has-sidebar .content-container {
                grid-gap: 30px !important;
            }
        }

        /* Remove parágrafos vazios residuais na coluna da barra lateral Kadence */
        .kt-inside-inner-col > p:empty {
            display: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* =========================================================
           TÍTULOS E CAIXAS PREMIUM
           ========================================================= */
        .uonix-section-title {
            position: relative;
            margin: 0 0 20px;
            padding-bottom: 12px;
            color: #0e3780;
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            line-height: 1.15;
            border-bottom: 1px solid #edf2f7;
            display: block;
        }

        .uonix-tags-widget h3.uonix-section-title {
            margin: 0 0 7px 0;
        }


        .uonix-section-title::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -1px;
            width: 68px;
            height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, #f76a0c 0%, #ff8a3d 100%);
        }

        .uonix-premium-box {
            width: 100%;
            margin: 0;
            padding: 24px 22px 22px;
            border: 1px solid #eaf0f6;
            border-radius: 10px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            box-shadow: 0 10px 30px rgba(14, 55, 128, 0.04);
            box-sizing: border-box;
        }

        .swiper {
            z-index: 0 !important;
        }

        /* =========================================================
           1. TAGS GLOBAIS E SIDEBAR (Estilo Mosaico Denso WordCloud2)
           ========================================================= */
        .uonix-tags-widget .uonix-wordcloud-wrap {
            width: 100%;
            position: relative;
            min-height: 280px;
            overflow: visible;
            user-select: none;
            -webkit-user-select: none;
        }

        .uonix-wordcloud-wrap span {
            transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.18s ease, box-shadow 0.22s ease !important;
            cursor: pointer !important;
            will-change: transform;
            border-radius: 4px;
        }

        /* A palavra selecionada ganha destaque, fundo translúcido e projeta-se para a frente */
        .uonix-wordcloud-wrap span.is-active-word {
            z-index: 100 !important;
            background: rgba(255, 255, 255, 0.9) !important;
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.9), 0 6px 20px rgba(14, 55, 128, 0.18) !important;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .uonix-tags-cloud-typographic {
            display: block;
            text-align: center;
            line-height: 1.1;
            padding: 10px 0;
        }

        .uonix-tag-text {
            display: inline-block;
            margin: 0 6px 4px 6px;
            font-weight: 900;
            text-decoration: none !important;
            letter-spacing: -0.5px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-transform: uppercase;
        }

        .uonix-tag-text:hover {
            transform: scale(1.15) translateY(-3px) rotate(-2deg);
            z-index: 10;
            position: relative;
            text-shadow: 0 10px 20px rgba(14, 55, 128, 0.2);
        }

        /* =========================================================
           2. TAGS DO RODAPÉ DO POST (Estilo Pílulas Original)
           ========================================================= */
        .uonix-tags-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .uonix-tag-item {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #f8fafc;
            color: #475569 !important;
            text-decoration: none !important;
            text-transform: uppercase;
            letter-spacing: 0.45px;
            line-height: 1.15;
            transition: all 0.25s ease;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.9) inset;
        }

        .uonix-tag-item:hover {
            color: #f76a0c !important;
            background: #ffffff !important;
            border-color: rgba(247, 106, 12, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(14, 55, 128, 0.06);
        }

        .uonix-tag-hash {
            margin-right: 2px;
            color: #94a3b8;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .uonix-tag-item:hover .uonix-tag-hash {
            color: #f76a0c;
        }

        .uonix-tag-bold {
            font-weight: 800;
            color: #475569 !important;
        }

        .uonix-tag-medium {
            font-weight: 700;
        }

        .uonix-tag-soft {
            font-weight: 600;
        }

        /* Oculta as tags nativas do Kadence no final do post */
        .single-post footer.entry-footer .entry-tags {
            display: none !important;
        }

        /* SIDEBAR MAIS RECENTES */
        .uonix-sb-widget {
            display: flex;
            flex-direction: column;
        }

        .uonix-sb-item {
            display: grid !important;
            grid-template-columns: 104px minmax(0, 1fr);
            gap: 15px !important;
            align-items: center;
            padding: 13px 0;
            border-bottom: 1px solid #f1f5f9;
            text-decoration: none !important;
        }

        .uonix-sb-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .uonix-sb-thumb {
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            border: 1px solid #eef2f7;
            border-left: solid 3px transparent;
            border-radius: 6px;
            background: #f8fafc;
            transition: all 0.25s ease;
        }

        .uonix-sb-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: all 0.3s ease;
        }

        .uonix-sb-item-title {
            margin: 0 !important;
            color: #0e3780 !important;
            font-size: 18px !important;
            font-weight: 600;
            line-height: 1.12;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            transition: color 0.3s ease;
        }

        .uonix-sb-item:hover .uonix-sb-thumb {
            border-left-color: #f76a0c;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(14, 55, 128, 0.08);
        }

        .uonix-sb-item:hover .uonix-sb-thumb img {
            filter: brightness(0.96);
        }

        .uonix-sb-item:hover .uonix-sb-item-title {
            color: #f76a0c !important;
        }

        /* SIDEBAR LEAD MAGNET, AUTORIDADE E NEWSLETTER */
        .uonix-lm-widget,
        .uonix-bio-widget,
        .uonix-nl-widget {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
        }

        .uonix-lm-widget .uonix-section-title,
        .uonix-bio-widget .uonix-section-title,
        .uonix-nl-widget .uonix-section-title {
            width: 100%;
            text-align: left;
        }

        .uonix-lm-header-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
            width: 100%;
        }

        .uonix-lm-icon-wrapper,
        .uonix-bio-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(247, 106, 12, 0.08);
            color: #f76a0c;
            flex-shrink: 0;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .uonix-lm-icon-wrapper svg {
            width: 24px;
            height: 24px;
        }

        .uonix-bio-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .uonix-lm-title,
        .uonix-bio-title {
            color: #0e3780;
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
            margin: 0;
            letter-spacing: -0.3px;
        }

        .uonix-lm-desc,
        .uonix-bio-desc {
            color: #475569;
            font-size: 15px;
            line-height: 1.4;
            margin: 0 0 18px;
        }

        /* BOTÕES DA NEWSLETTER E BIO (Classe lm-btn apagada) */
        .uonix-bio-btn,
        .uonix-fluent-wrapper button.ff-btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100% !important;
            padding: 12px 20px !important;
            background: #0e3780 !important;
            color: #ffffff !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: none !important;
            border-radius: 8px !important;
            text-decoration: none !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important;
            transition: all 0.3s ease !important;
        }

        .fluentform_wrapper_2 .ff-el-group+.ff-el-group {
            margin-top: 0px !important;
        }

        .uonix-lm-widget:hover .uonix-lm-icon-wrapper,
        .uonix-bio-widget:hover .uonix-bio-logo {
            transform: translateY(-3px) scale(1.05);
        }

        .uonix-bio-btn:hover,
        .uonix-fluent-wrapper button.ff-btn-submit:hover {
            background: #15459e !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25) !important;
            color: #fff !important;
        }

        /* =======================================================
           ACTIONS ROW (Barra Unificada)
           ======================================================= */
        .uonix-actions-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            width: 100%;
            margin: 40px 0 20px;
            padding: 16px 24px;
            border: 1px solid #eaf0f6;
            border-radius: 10px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            box-shadow: 0 10px 30px rgba(14, 55, 128, 0.04);
            flex-wrap: wrap;
            box-sizing: border-box;
        }

        #was-this-helpful {
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            display: flex !important;
            align-items: center !important;
            gap: 15px !important;
            width: auto !important;
        }

        #wthf-title,
        .uonix-share-title {
            margin: 0 !important;
            padding: 0 !important;
            color: #1a202c !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            text-transform: none !important;
            border: none !important;
            letter-spacing: 0 !important;
            white-space: nowrap;
        }

        #wthf-title::after,
        .uonix-share-title::after {
            display: none !important;
        }

        #wthf-yes-no {
            display: flex !important;
            gap: 8px !important;
            margin: 0 !important;
        }

        #wthf-yes-no span {
            padding: 6px 18px !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 6px !important;
            background: #f8fafc !important;
            color: #475569 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.9) inset !important;
        }

        #wthf-yes-no span:hover {
            border-color: #f76a0c !important;
            color: #f76a0c !important;
            background: #fff !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 10px rgba(14, 55, 128, 0.06) !important;
        }

        #was-this-helpful.wthf-voted #wthf-title {
            color: #25D366 !important;
        }

        .uonix-share-container {
            display: flex !important;
            align-items: center !important;
            gap: 15px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            width: auto !important;
        }

        .uonix-share-icons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .share-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid #e2e8f0;
            border-radius: 50%;
            background: #ffffff;
            color: #475569 !important;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }

        .share-btn svg {
            width: 17px;
            height: 17px;
            display: block;
        }

        .share-btn:hover {
            transform: translateY(-2px);
            color: #f76a0c !important;
            border-color: #f76a0c;
            box-shadow: 0 4px 10px rgba(14, 55, 128, 0.06);
        }

        @media (max-width: 900px) {
            .uonix-actions-row {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
                gap: 16px;
            }

            #was-this-helpful {
                width: 100% !important;
                justify-content: space-between !important;
                padding-bottom: 16px !important;
                border-bottom: 1px solid #edf2f7 !important;
            }

            .uonix-share-container {
                width: 100% !important;
                justify-content: space-between !important;
            }
        }

        /* CARROSSEL LEIA TAMBÉM */
        .uonix-rel-header {
            position: relative;
            margin-bottom: 24px;
            padding-right: 90px;
        }

        .uonix-rel-title {
            margin: 0 !important;
            padding-right: 0 !important;
        }

        .uonix-rel-nav-outer {
            position: absolute;
            right: 0;
            bottom: 10px;
            /*    z-index: 10;*/
        }

        .uonix-nav {
            display: flex;
            gap: 10px;
        }

        .uonix-prev,
        .uonix-next {
            width: 38px;
            height: 38px;
            border: 1.5px solid #e2e8f0;
            color: #0e3780;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 18px;
            user-select: none;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.04);
        }

        .uonix-prev:hover,
        .uonix-next:hover {
            border-color: #f76a0c;
            color: #f76a0c;
            background: #fff;
            transform: translateY(-1px) scale(1.04);
            box-shadow: 0 8px 16px rgba(14, 55, 128, 0.08);
        }

        .uonix-rel-card {
            display: flex;
            flex-direction: column;
            text-decoration: none !important;
        }

        .uonix-rel-thumb {
            width: 100%;
            aspect-ratio: 4 / 3;
            margin-bottom: 12px;
            overflow: hidden;
            border: 1px solid #eef2f7;
            border-radius: 6px;
            background: #f8fafc;
        }

        .uonix-rel-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.35s ease, filter 0.35s ease;
            display: block;
        }

        .uonix-rel-item-title {
            margin: 0 !important;
            color: #0e3780 !important;
            font-size: 17px;
            font-weight: 600;
            line-height: 1.12;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            transition: color 0.3s ease;
        }

        .uonix-rel-card:hover .uonix-rel-thumb img {
            transform: scale(1.03);
            filter: brightness(0.88);
        }

        .uonix-rel-card:hover .uonix-rel-item-title {
            color: #f76a0c !important;
        }

        @media (max-width: 550px) {
            #wthf-yes-no span {
                width: 42px !important;
                height: 42px !important;
                padding: 0 !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 0 !important;
                line-height: 0 !important;
                position: relative !important;
            }

            #wthf-yes-no span::before {
                content: "";
                display: block;
                width: 20px;
                height: 20px;
                background-repeat: no-repeat;
                background-position: center;
                background-size: contain;
            }

            /* LIKE */
            #wthf-yes-no span[data-value="1"]::before {
                background-image: url("data:image/svg+xml;utf8,\
    <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>\
    <path d='M7 10v10'/>\
    <path d='M15 5.88L14 10h5.83a2 2 0 0 1 1.94 2.47l-1.1 5A2 2 0 0 1 18.72 19H7a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3l3-5a2 2 0 0 1 2 2.88z'/>\
    </svg>");
            }

            /* DISLIKE */
            #wthf-yes-no span[data-value="0"]::before {
                background-image: url("data:image/svg+xml;utf8,\
    <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>\
    <path d='M17 14V4'/>\
    <path d='M9 18.12L10 14H4.17a2 2 0 0 1-1.94-2.47l1.1-5A2 2 0 0 1 5.28 5H17a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3l-3 5A2 2 0 0 1 9 18.12z'/>\
    </svg>");
            }

            #wthf-yes-no span:hover::before {
                transform: scale(1.08);
            }
        }

        @media (max-width: 460px) {

            #was-this-helpful,
            .uonix-share-container {
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 10px !important;
            }

            #wthf-title,
            .uonix-share-title {
                white-space: normal !important;
            }

            #wthf-yes-no,
            .uonix-share-icons {
                width: 100% !important;
                display: flex !important;
                gap: 8px !important;
                justify-content: stretch !important;
            }

            #wthf-yes-no span,
            .uonix-share-icons .share-btn {
                flex: 1 1 0 !important;
                width: 100% !important;
            }

            .uonix-share-icons .share-btn {
                border-radius: 8px !important;
            }
        }

        @media (max-width: 768px) {
            .uonix-premium-box {
                padding: 24px 18px 20px;
            }

            .uonix-rel-nav-outer {
                bottom: 10px;
                right: 0;
            }
        }

        /* =======================================================
           NEWSLETTER (FLUENT FORMS) — ESPAÇAMENTO
           ======================================================= */
        .fluentform_wrapper_2 .ff-t-cell>.ff-el-group:nth-of-type(2) {
            margin-top: 0 !important;
        }

        /* =======================================================
           NEWSLETTER (FLUENT FORMS) — ERRO NO CHECKBOX
           ======================================================= */

        /* Container do erro mais organizado */
        .fluentform_wrapper_2 .ff-el-is-error {
            margin-top: 10px !important;
        }

        /* Checkbox com destaque de erro */
        .fluentform_wrapper_2 .ff-el-is-error .ff-el-form-check-label {
            padding: 10px 12px !important;
            border: 1.5px solid #dc2626 !important;
            border-radius: 8px !important;
            background: rgba(220, 38, 38, 0.04) !important;
        }

        /* Checkbox visual mais forte */
        .fluentform_wrapper_2 .ff-el-is-error .ff-el-form-check-input {
            accent-color: #dc2626 !important;
            outline: 2px solid rgba(220, 38, 38, 0.3);
            border-radius: 4px;
        }

        /* Texto do termo em erro */
        .fluentform_wrapper_2 .ff-el-is-error .ff_t_c {
            color: #991b1b !important;
        }

        /* Mensagem de erro mais visível e bem posicionada */
        .fluentform_wrapper_2 .ff-el-is-error .error.text-danger {
            margin-top: 8px !important;
            padding-left: 28px !important;
            color: #dc2626 !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            position: relative;
        }

        /* Ícone de alerta antes da mensagem */
        .fluentform_wrapper_2 .ff-el-is-error .error.text-danger::before {
            content: "⚠";
            position: absolute;
            left: 8px;
            top: 0;
            font-size: 13px;
        }

        /* Ajuste de alinhamento do checkbox */
        .fluentform_wrapper_2 .ff-el-form-check-label {
            align-items: center !important;
        }
    </style>
    <?php
}

// ==============================================================================
// 2. SHORTCODES DA SIDEBAR (Recentes, Tags, PDF, Autoridade, Newsletter)
// ==============================================================================

/**
 * UÔNIX: Remove parágrafos automáticos indesejados gerados pelo bloco nativo wp:shortcode
 */
add_filter('render_block_core/shortcode', function ($block_content) {
    return shortcode_unautop(trim($block_content));
}, 10);

/**
 * UÔNIX: Higieniza tags <p> vazias ou contendo apenas espaços deixadas pelo Gutenberg nos widgets de bloco
 */
add_filter('widget_block_content', function ($content) {
    return preg_replace('#<p[^>]*>(\s|&nbsp;)*</p>#i', '', $content);
}, 20);

/* 2.1 Mais Recentes */
add_shortcode('uonix_sidebar_posts', 'uonix_gerar_sidebar_posts');
function uonix_gerar_sidebar_posts()
{
    $current_post_id = get_the_ID();
    $sidebar_posts = get_posts(array('post_type' => 'post', 'posts_per_page' => 5, 'post__not_in' => array($current_post_id), 'orderby' => 'date', 'order' => 'DESC'));
    if (empty($sidebar_posts))
        return '';
    ob_start(); ?>
    <div class="uonix-premium-box uonix-sb-widget">
        <h3 class="uonix-section-title">Mais Recentes</h3>
        <?php foreach ($sidebar_posts as $post): ?>
            <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="uonix-sb-item">
                <div class="uonix-sb-thumb"><?php echo get_the_post_thumbnail($post->ID, 'thumbnail'); ?></div>
                <div class="uonix-sb-content">
                    <h4 class="uonix-sb-item-title"><?php echo esc_html(get_the_title($post->ID)); ?></h4>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php return trim(ob_get_clean());
}

/* 2.2 Nuvem de Assuntos (Visual Tipográfico WordCloud2) */
add_shortcode('uonix_global_tags', 'uonix_gerar_nuvem_tags_global_v3');
function uonix_gerar_nuvem_tags_global_v3()
{
    // 28 tags para volume ideal no mosaico denso estilo quebra-cabeça
    $tags = get_tags(array(
        'orderby' => 'count',
        'order' => 'DESC',
        'hide_empty' => true,
        'number' => 28
    ));
    if (empty($tags))
        return '';

    $counts = wp_list_pluck($tags, 'count');
    $min_count = min($counts);
    $max_count = max($counts);
    $divisor = max(1, $max_count - $min_count);

    $min_size = 13;
    $max_size = 36;

    $word_list = array();
    foreach ($tags as $tag) {
        $size = round($min_size + (($tag->count - $min_count) * ($max_size - $min_size) / $divisor));
        $word_list[] = array(
            mb_strtoupper($tag->name, 'UTF-8'),
            $size,
            esc_url(get_tag_link($tag->term_id))
        );
    }

    // Registra o script local do WordCloud2
    wp_enqueue_script(
        'uonix-wordcloud2',
        get_stylesheet_directory_uri() . '/assets/js/wordcloud2.min.js',
        array(),
        '1.2.2',
        true
    );

    ob_start(); ?>
    <div class="uonix-premium-box uonix-tags-widget">
        <h3 class="uonix-section-title">Principais assuntos</h3>

        <div id="uonix-wordcloud-wrap" class="uonix-wordcloud-wrap"></div>

        <!-- Fallback Semântico e Acessível para SEO (Googlebot lê todos os links âncora) -->
        <div class="uonix-tags-seo-fallback screen-reader-text"
            style="position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0, 0, 0, 0) !important; white-space: nowrap !important; border: 0 !important;">
            <?php foreach ($tags as $tag): ?>
                <a href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>"><?php echo esc_html($tag->name); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <script id="uonix-wordcloud-init">
        (function () {
            var wordList = <?php echo json_encode($word_list); ?>;
            var colors = ['#0e3780', '#f76a0c', '#1e293b', '#475569', '#15459e', '#ea580c', '#2563eb', '#c2410c'];

            function renderCloud() {
                var wrap = document.getElementById('uonix-wordcloud-wrap');
                if (!wrap || !window.WordCloud) return;

                wrap.innerHTML = '';
                var width = wrap.clientWidth || 300;
                if (width < 50) width = 300;
                wrap.style.height = '280px';

                window.WordCloud(wrap, {
                    list: wordList,
                    gridSize: 6,
                    weightFactor: function (size) {
                        return size * 1.05 * (width / 310);
                    },
                    fontFamily: 'Barlow Semi Condensed, Impact, sans-serif',
                    fontWeight: '800',
                    color: function () {
                        return colors[Math.floor(Math.random() * colors.length)];
                    },
                    rotateRatio: 0.35,
                    rotationSteps: 2,
                    minRotation: -Math.PI / 2,
                    maxRotation: 0,
                    backgroundColor: 'transparent',
                    drawOutOfBound: false,
                    shrinkToFit: true,
                    ellipticity: 0.7
                });

                // Configura a interação física de trazer a palavra para mais perto
                wrap.addEventListener('wordcloudstop', function onStop() {
                    wrap.removeEventListener('wordcloudstop', onStop);
                    setupWordInteractions();
                });

                setTimeout(setupWordInteractions, 300);
            }

            function setupWordInteractions() {
                var wrap = document.getElementById('uonix-wordcloud-wrap');
                if (!wrap) return;
                var spans = wrap.querySelectorAll('span');
                if (!spans.length) return;

                spans.forEach(function (s) {
                    if (s.dataset.hasWordCloudEvents) return;
                    s.dataset.hasWordCloudEvents = '1';

                    // Salva o transform original de rotação
                    s.dataset.origTransform = s.style.transform || '';

                    // Mapeia o link correspondente
                    var text = s.innerText.trim();
                    var match = wordList.find(function (item) {
                        return item[0] === text;
                    });
                    if (match && match[2]) {
                        s.dataset.url = match[2];
                    }

                    // Efeito dinâmico: traz a palavra selecionada para mais perto (pop zoom) sem mexer na opacidade
                    s.addEventListener('mouseenter', function () {
                        s.classList.add('is-active-word');
                        var base = s.dataset.origTransform ? s.dataset.origTransform + ' ' : '';
                        s.style.transform = base + 'scale(1.22)';
                    });

                    s.addEventListener('mouseleave', function () {
                        s.classList.remove('is-active-word');
                        s.style.transform = s.dataset.origTransform;
                    });

                    // Clique para navegar
                    s.addEventListener('click', function () {
                        if (s.dataset.url) {
                            window.location.href = s.dataset.url;
                        }
                    });
                });
            }

            function tryInit(attempts) {
                if (window.WordCloud) {
                    renderCloud();
                } else if (attempts > 0) {
                    setTimeout(function () { tryInit(attempts - 1); }, 80);
                }
            }

            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                tryInit(30);
            } else {
                document.addEventListener('DOMContentLoaded', function () {
                    tryInit(30);
                });
            }

            var resizeTimer;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(renderCloud, 250);
            });
        })();
    </script>
    <?php return trim(ob_get_clean());
}

/* 2.3 PDF Lead Magnet (Integrado com o Pop-up Modular) */
add_shortcode('uonix_lead_magnet', 'uonix_gerar_lead_magnet');
function uonix_gerar_lead_magnet()
{
    ob_start(); ?>

    <style>
        /* Transforma o botão do modal (Laranja) no padrão Newsletter (Azul Marinho) */
        .uonix-lm-widget .uonix-btn-modal-destaque {
            width: 100% !important;
            height: 38px !important;
            margin-top: 10px;
            background: #0e3780 !important;
            /* Azul Uônix */
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important;
            padding: 0px 20px !important;
            font-size: 15px !important;
        }

        .uonix-lm-widget .uonix-btn-modal-destaque:hover {
            background: #15459e !important;
            /* Azul mais claro no Hover */
            box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25) !important;
            transform: translateY(-2px) !important;
        }
    </style>

    <div class="uonix-premium-box uonix-lm-widget">
        <h3 class="uonix-section-title">Material Gratuito</h3>

        <div class="uonix-lm-header-row">
            <div class="uonix-lm-icon-wrapper">
                <svg viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path
                        d="M181.9 256.1c-5-16-4.9-46.9-2-46.9 8.4 0 7.6 36.9 2 46.9zm-1.7 47.2c-7.7 20.2-17.3 43.3-28.4 62.7 18.3-7 39-17.2 62.9-21.9-12.7-9.6-24.9-23.4-34.5-40.8zM86.1 428.1c0 .8 13.2-5.4 34.9-40.2-6.7 6.3-29.1 24.5-34.9 40.2zM248 160h136v328c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V24C0 10.7 10.7 0 24 0h200v136c0 13.2 10.8 24 24 24zm-8 171.8c-20-12.2-33.3-29-42.7-53.8 4.5-18.5 11.6-46.6 6.2-64.2-4.7-29.4-42.4-26.5-47.8-6.8-5 18.3-.4 44.1 8.1 77-11.6 27.6-28.7 64.6-40.8 85.8-.1 0-.1.1-.2.1-27.1 13.9-73.6 44.5-54.5 68 5.6 6.9 16 10 21.5 10 17.9 0 35.7-18 61.1-61.8 25.8-8.5 54.1-19.1 79-23.2 21.7 11.8 47.1 19.5 64 19.5 29.2 0 31.2-32 19.7-43.4-13.9-13.6-54.3-9.7-73.6-7.2zM377 105L279 7c-4.5-4.5-10.6-7-17-7h-6v128h128v-6.1c0-6.3-2.5-12.4-7-16.9zm-74.1 255.3c4.1-2.7-2.5-11.9-42.8-9 37.1 15.8 42.8 9 42.8 9z">
                    </path>
                </svg>
            </div>
            <h4 class="uonix-lm-title">Checklist Técnico:<br>Sistemas de Ancoragem</h4>
        </div>

        <p class="uonix-lm-desc">Vai instalar dispositivos de ancoragem? Baixe o guia prático para proteger vidas e evitar
            passivos.</p>

        <?php
        // Chama os nossos shortcodes independentes!
        echo do_shortcode('[uonix_modal texto_botao="Baixar Grátis &rarr;" estilo_botao="destaque"][uonix_form_captura][/uonix_modal]');
        ?>

    </div>

    <?php return trim(ob_get_clean());
}
/* 2.4 Caixa de Autoridade (Empresa) */
add_shortcode('uonix_autoridade', 'uonix_gerar_autoridade');
function uonix_gerar_autoridade()
{
    ob_start(); ?>
    <div class="uonix-premium-box uonix-bio-widget">
        <h3 class="uonix-section-title">Sobre Nós</h3>
        <div class="uonix-lm-header-row">
            <div class="uonix-bio-logo">
                <img src="/wp-content/uploads/2026/02/unnamed-9-e1772227439846-300x300.jpg" alt="Uônix">
            </div>
            <h4 class="uonix-bio-title">Especialistas em Segurança</h4>
        </div>
        <p class="uonix-bio-desc">A Uônix é referência em engenharia de acesso e proteção contra quedas. Garantimos a
            segurança da sua equipa com soluções que cumprem rigorosamente as normas NR-35 e ABNT.</p>
        <a href="/empresa" class="uonix-bio-btn">Conheça a Empresa</a>
    </div>
    <?php return trim(ob_get_clean());
}

/* 2.5 Sidebar Newsletter (Formulário Embutido Direto) */
add_shortcode('uonix_newsletter', 'uonix_gerar_newsletter');
function uonix_gerar_newsletter()
{
    ob_start(); ?>
    <div class="uonix-premium-box uonix-nl-widget">
        <h3 class="uonix-section-title">Newsletter</h3>

        <div class="uonix-lm-header-row">
            <div class="uonix-lm-icon-wrapper" style="background: rgba(14, 55, 128, 0.05); color: #0e3780;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </div>
            <h4 class="uonix-lm-title">Receba nossas<br>atualizações</h4>
        </div>

        <p class="uonix-lm-desc">Receba atualizações, conteúdos técnicos e novidades do setor diretamente no seu e-mail.</p>

        <div class="uonix-fluent-wrapper" style="margin-top: 2px; width: 100%;">
            <?php echo do_shortcode('[uonix_form_newsletter]'); ?>
        </div>
    </div>
    <?php return trim(ob_get_clean());
}

// ==============================================================================
// 3. FUNÇÃO DE COMPARTILHAMENTO E RODAPÉ DO POST
// ==============================================================================
function uonix_obter_html_social_share()
{
    $url = urlencode(get_permalink());
    $title = urlencode(get_the_title());

    $links = array(
        'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$url}",
        'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$url}",
        'x' => "https://twitter.com/intent/tweet?text={$title}&url={$url}",
        'whatsapp' => "https://api.whatsapp.com/send?text={$title}%20{$url}"
    );

    $icons = array(
        'facebook' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"></path></svg>',
        'linkedin' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z"></path><circle cx="4" cy="4" r="2"></circle></svg>',
        'x' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"></path></svg>',
        'whatsapp' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.008-.57-.008-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"></path></svg>'
    );

    $html = '<div class="uonix-share-container">';
    $html .= '    <h3 class="uonix-share-title">Compartilhe</h3>';
    $html .= '    <div class="uonix-share-icons">';
    foreach ($links as $network => $link) {
        $html .= '        <a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" class="share-btn ' . esc_attr($network) . '" aria-label="Compartilhar no ' . ucfirst($network) . '">' . $icons[$network] . '</a>';
    }
    $html .= '    </div></div>';
    return $html;
}

add_shortcode('uonix_leia_mais', 'uonix_gerar_leia_mais_carousel');
function uonix_gerar_leia_mais_carousel()
{
    $current_id = get_the_ID();
    $cats = wp_get_post_categories($current_id);

    $args = array(
        'post_type' => 'post',
        'posts_per_page' => 8,
        'post__not_in' => array($current_id),
        'category__in' => $cats
    );

    $posts = get_posts($args);

    if (count($posts) < 3) {
        $posts = array_merge(
            $posts,
            get_posts(array(
                'posts_per_page' => 8 - count($posts),
                'post__not_in' => array_merge(array($current_id), wp_list_pluck($posts, 'ID'))
            ))
        );
    }

    $swiper_base_path = UONIX_MU_PATH . 'uonix-content/assets/vendor/swiper/';
    $swiper_base_url = UONIX_MU_URL . 'uonix-content/assets/vendor/swiper/';
    $swiper_css = $swiper_base_url . 'swiper-bundle.min.css';
    $swiper_js = $swiper_base_url . 'swiper-bundle.min.js';
    $swiper_css_ver = file_exists($swiper_base_path . 'swiper-bundle.min.css') ? filemtime($swiper_base_path . 'swiper-bundle.min.css') : '11';
    $swiper_js_ver = file_exists($swiper_base_path . 'swiper-bundle.min.js') ? filemtime($swiper_base_path . 'swiper-bundle.min.js') : '11';

    ob_start(); ?>
    <link rel="stylesheet" href="<?php echo esc_url(add_query_arg('ver', $swiper_css_ver, $swiper_css)); ?>" />
    <div class="uonix-premium-box uonix-rel-container">
        <div class="uonix-rel-header">
            <h3 class="uonix-section-title uonix-rel-title">Leia Também</h3>
            <div class="uonix-rel-nav-outer">
                <div class="uonix-nav">
                    <div class="uonix-prev">‹</div>
                    <div class="uonix-next">›</div>
                </div>
            </div>
        </div>
        <div class="swiper swiper-leia-mais">
            <div class="swiper-wrapper">
                <?php foreach ($posts as $p): ?>
                    <div class="swiper-slide">
                        <a href="<?php echo esc_url(get_permalink($p->ID)); ?>" class="uonix-rel-card">
                            <div class="uonix-rel-thumb"><?php echo get_the_post_thumbnail($p->ID, 'medium'); ?></div>
                            <h4 class="uonix-rel-item-title"><?php echo esc_html(get_the_title($p->ID)); ?></h4>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script src="<?php echo esc_url(add_query_arg('ver', $swiper_js_ver, $swiper_js)); ?>"></script>
    <script>
        if (typeof Swiper !== 'undefined') {
            new Swiper('.swiper-leia-mais', {
                slidesPerView: 1.2,
                spaceBetween: 20,
                loop: true,
                autoplay: { delay: 4000, disableOnInteraction: false },
                navigation: { nextEl: '.uonix-next', prevEl: '.uonix-prev' },
                breakpoints: {
                    640: { slidesPerView: 3, spaceBetween: 25 },
                    1025: { slidesPerView: 3, spaceBetween: 30 }
                }
            });
        }
    </script>
    <?php return ob_get_clean();
}

add_filter('the_content', 'uonix_injetar_elementos_final_post', PHP_INT_MAX);
function uonix_injetar_elementos_final_post($content)
{
    if (!is_singular('post') || !in_the_loop() || !is_main_query())
        return $content;

    $share_html = uonix_obter_html_social_share();

    $helpful_marker = '<div id="was-this-helpful"';
    if (strpos($content, $helpful_marker) !== false) {
        $parts = explode($helpful_marker, $content, 2);
        $content = $parts[0];
        $helpful_html = $helpful_marker . $parts[1];
        $actions_row = '<div class="uonix-actions-row">' . $helpful_html . $share_html . '</div>';
    } else {
        $actions_row = '<div class="uonix-actions-row">' . $share_html . '</div>';
    }

    $tags_html = '';
    $post_tags = get_the_tags();
    if (!empty($post_tags)) {
        $tags_html .= '<div class="uonix-premium-box uonix-post-tags-wrapper" style="margin-top: 0;">';
        $tags_html .= '<h3 class="uonix-section-title">Assuntos desse Post</h3>';
        $tags_html .= '<div class="uonix-tags-cloud">';
        foreach ($post_tags as $tag) {
            $tags_html .= '<a href="' . esc_url(get_tag_link($tag->term_id)) . '" class="uonix-tag-item uonix-tag-soft" style="font-size: 13px;">';
            $tags_html .= '<span class="uonix-tag-hash">#</span>' . esc_html($tag->name);
            $tags_html .= '</a>';
        }
        $tags_html .= '</div></div>';
    }

    $leia_mais_html = do_shortcode('[uonix_leia_mais]');

    return $content . $actions_row . $tags_html . $leia_mais_html;
}

add_filter('comment_form_default_fields', 'uonix_placeholders_comentarios_premium', 99);
function uonix_placeholders_comentarios_premium($fields)
{

    // 1. Altera o placeholder do Nome
    if (isset($fields['author'])) {
        $fields['author'] = str_replace('placeholder="João Ninguém"', 'placeholder="Nome completo"', $fields['author']);
    }

    // 2. Altera o placeholder do E-mail para um formato corporativo/profissional
    if (isset($fields['email'])) {
        $fields['email'] = str_replace('placeholder="john@example.com"', 'placeholder="Email para contato"', $fields['email']);
    }

    return $fields;
}
