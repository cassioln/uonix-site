<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - sticky WhatsApp de servicos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 10154-10452 do export original.
// -----------------------------------------------------------------------------
/**
 * Sticky Slide-in WhatsApp (Serviços)
 */
/**
 * UÔNIX: Sticky Slide-in WhatsApp (Serviços) V6
 * - Captura dinamicamente o nome da página/serviço atual
 * - Posicionado à direita (Padrão Global)
 * - Layout Mobile flexível para títulos longos
 * - Scroll-up (Seta) do Kadence sobe e desce em sincronia com o Widget
 * Uso: [uonix_sticky_whatsapp]
 */

add_shortcode('uonix_sticky_whatsapp', 'uonix_gerar_sticky_whatsapp_servicos');

function uonix_gerar_sticky_whatsapp_servicos() {
    // 1. Captura o nome da página atual (O nome do Serviço)
    $nome_servico = get_the_title();
    
	
	// 2. Configurações do WhatsApp dinâmicas
    $telefone_raw = get_option('uox_whatsapp_1', '11947254885'); 
    $telefone = preg_replace('/[^0-9]/', '', $telefone_raw); 

    $mensagem_crua = "Olá, equipe Uônix! Gostaria de saber mais detalhes e solicitar um orçamento para o serviço: *" . $nome_servico . "*.";
    $link_whatsapp = "https://api.whatsapp.com/send?phone=55" . $telefone . "&text=" . urlencode($mensagem_crua);
	
    ob_start(); ?>

    <style>
        /* ==========================================================
           1. CARTÃO FLUTUANTE DO WHATSAPP - DESKTOP (ALINHADO À DIREITA)
           ========================================================== */
        .uonix-wpp-widget {
            position: fixed;
            bottom: 30px;
            right: 30px;
            max-width: 460px;
            background: #ffffff;
            border: 1px solid #eaf0f6;
            border-left: 4px solid #25D366; /* Verde WhatsApp */
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(37, 211, 102, 0.15);
            z-index: 99998;
            padding: 20px 24px 20px 20px;
            cursor: pointer;
            transform: translateX(150%); /* Animação sai da direita */
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            box-sizing: border-box;
            text-decoration: none !important;
            display: block;
        }

        .uonix-wpp-widget.show {
            transform: translateX(0);
            opacity: 1;
            visibility: visible;
        }

        .uonix-wpp-widget:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 45px rgba(37, 211, 102, 0.2);
            border-color: #bbf7d0;
        }

        /* Botão X Blindado */
        .uonix-wpp-close {
            position: absolute !important; 
            top: 8px !important; 
            right: 8px !important; 
            background: transparent !important; 
            border: none !important; 
            box-shadow: none !important;
            color: #cbd5e1 !important;
            cursor: pointer !important; 
            width: 24px !important;
            height: 24px !important;
            min-width: 0 !important;
            padding: 0 !important; 
            display: flex !important; 
            align-items: center !important; 
            justify-content: center !important; 
            transition: color 0.2s ease !important;
            z-index: 10 !important;
            border-radius: 0 !important;
        }
        .uonix-wpp-close svg { width: 16px !important; height: 16px !important; stroke-width: 2.5 !important; }
        .uonix-wpp-close:hover { color: #dc2626 !important; background: transparent !important; }

        /* Estrutura Interna */
        .uonix-wpp-content {
            display: flex;
            align-items: center;
            gap: 18px;
            width: 100%;
        }

        .uonix-wpp-icon {
            width: 48px; 
            height: 48px; 
            border-radius: 12px; 
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); 
            color: #ffffff; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-shrink: 0;
            box-shadow: 0 6px 15px rgba(37, 211, 102, 0.3);
        }
        .uonix-wpp-icon svg { width: 24px; height: 24px; }

        .uonix-wpp-text { 
            display: flex; 
            flex-direction: column; 
            flex-grow: 1;
        }
        .uonix-wpp-tag { 
            font-size: 10px; 
            font-weight: 800; 
            color: #128C7E; 
            text-transform: uppercase; 
            letter-spacing: 0.8px; 
            margin-bottom: 4px; 
            line-height: 1;
        }
        .uonix-wpp-title { 
            font-size: 16px; 
            font-weight: 800; 
            color: #0e3780; 
            line-height: 1.2; 
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 3px;
        }
        .uonix-wpp-action {
            font-size: 12px;
            font-weight: 800;
            color: #25D366;
            margin-top: 12px;
            display: flex;
            align-items: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease;
        }
        .uonix-wpp-widget:hover .uonix-wpp-action {
            transform: translateX(4px);
        }

        /* ==========================================================
           2. ANIMAÇÃO SINCRONIZADA DO BOTAO "SCROLL UP" (KADENCE)
           ========================================================== */
        /* Dá à setinha a mesma fluidez de animação do WhatsApp */
        #kt-scroll-up {
            transition: bottom 0.5s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        /* Desktop: Empurra pra cima SÓ quando o Wpp estiver visível */
        @media (min-width: 720px) {
            body.uonix-wpp-visible #kt-scroll-up {
                bottom: 140px !important; 
            }
        }

        /* ==========================================================
           3. ADAPTAÇÃO PARA MOBILE E TABLET (Rodapé)
           ========================================================== */
        @media (max-width: 719px) {
            .uonix-wpp-widget {
                bottom: 0; right: 0; left: 0; width: 100%; border-radius: 16px 16px 0 0; 
                padding: 16px 16px;
                transform: translateY(150%); border-bottom: none; border-left: none; 
                border-top: 3px solid #25D366;
            }
            .uonix-wpp-widget.show { transform: translateY(0); }

            .uonix-wpp-close {
                top: -14px !important; right: 16px !important;
                background: #ffffff !important; border: 1px solid #e2e8f0 !important;
                border-radius: 50% !important; box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
                width: 28px !important; height: 28px !important; color: #64748b !important;
            }
            .uonix-wpp-close svg { width: 14px !important; height: 14px !important; stroke-width: 3 !important; }
            
            .uonix-wpp-content {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 12px !important;
            }
            
            .uonix-wpp-icon { 
                width: 42px !important; height: 42px !important; 
                flex-shrink: 0 !important; margin-top: 0 !important; 
            }
            .uonix-wpp-icon svg { width: 20px !important; height: 20px !important; }

            .uonix-wpp-text { flex-grow: 1 !important; padding-right: 0 !important; min-width: 0 !important; }
            .uonix-wpp-tag { font-size: 9px !important; margin-bottom: 2px !important; }

            .uonix-wpp-title { 
                font-size: 13px !important; margin-bottom: 0 !important; line-height: 1.2 !important;
                display: -webkit-box !important; -webkit-line-clamp: 2 !important;
                -webkit-box-orient: vertical !important; overflow: hidden !important;
            }
            
            .uonix-wpp-action {
                position: static !important; transform: none !important; margin-top: 0 !important;
                background: #25D366 !important; color: #ffffff !important; 
                padding: 10px 14px !important; border-radius: 8px !important;
                font-size: 11px !important; font-weight: 800 !important;
                box-shadow: 0 4px 10px rgba(37, 211, 102, 0.2) !important;
                display: inline-flex !important; flex-shrink: 0 !important; white-space: nowrap !important; 
            }
            .uonix-wpp-widget:hover .uonix-wpp-action { transform: scale(1.02) !important; }

            /* Mobile: Empurra a setinha pra cima para não ficar atrás do Widget colado no rodapé */
            body.uonix-wpp-visible #kt-scroll-up {
                bottom: 90px !important; 
            }
        }
    </style>

    <a href="<?php echo esc_url($link_whatsapp); ?>" target="_blank" rel="noopener noreferrer" id="uonix-wpp-box" class="uonix-wpp-widget">
        
        <button id="uonix-wpp-close-btn" class="uonix-wpp-close" aria-label="Dispensar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        
        <div class="uonix-wpp-content">
            <div class="uonix-wpp-icon">
                <svg viewBox="0 0 448 512" fill="currentColor"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zM223.9 413.6c-33.6 0-66.6-9-95.6-26.1l-6.8-4-71 18.6 18.9-69.2-4.4-7C46.8 293.4 37 259 37 223.9 37 121.2 120.7 39 224 39c49.8 0 96.6 19.4 131.8 54.6 35.2 35.2 54.6 82 54.6 131.8 0 102.7-83.7 184.8-186.5 188.2zm101.9-140.3c-5.6-2.8-33-16.3-38.1-18.1-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.4 18.1-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"></path></svg>
            </div>
            
            <div class="uonix-wpp-text">
                <span class="uonix-wpp-tag">Fale com um especialista</span>
                <strong class="uonix-wpp-title"><?php echo esc_html($nome_servico); ?></strong>
            </div>

            <div class="uonix-wpp-action">WhatsApp</div>
        </div>
    </a>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const wppBox = document.getElementById('uonix-wpp-box');
            const btnCloseWpp = document.getElementById('uonix-wpp-close-btn');
            const body = document.body;

            let isDismissed = false;

            window.addEventListener('scroll', function() {
                if (isDismissed) return;

                const scrollY = window.scrollY;
                const windowHeight = window.innerHeight;

                const documentHeight = Math.max(
                    document.body.scrollHeight, document.documentElement.scrollHeight,
                    document.body.offsetHeight, document.documentElement.offsetHeight,
                    document.body.clientHeight, document.documentElement.clientHeight
                );

                let offsetRodape = 650; // Padrão para Desktop

                if (window.innerWidth <= 768) {
                    offsetRodape = 1400; // Mobile
                } else if (window.innerWidth <= 1024) {
                    offsetRodape = 1200; // Tablet
                }

                // Quando o Wpp entra na tela
                if (scrollY > 150 && (documentHeight - (scrollY + windowHeight)) > offsetRodape) {
                    wppBox.classList.add('show');
                    body.classList.add('uonix-wpp-visible'); // Avisa o CSS que a caixa abriu
                } else {
                    wppBox.classList.remove('show');
                    body.classList.remove('uonix-wpp-visible'); // Avisa o CSS que a caixa fechou
                }
            });

            btnCloseWpp.addEventListener('click', function(e) {
                e.preventDefault(); 
                e.stopPropagation(); 

                isDismissed = true;
                wppBox.classList.remove('show');
                body.classList.remove('uonix-wpp-visible'); // Garante que a setinha desça se o usuário fechar no 'X'
            });
        });
    </script>
    <?php return ob_get_clean();
}


