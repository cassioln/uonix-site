<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - sticky lead slide-in.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 9807-10153 do export original.
// -----------------------------------------------------------------------------
/**
 * Sticky Captura Lead Download Checklist
 */
/**
 * UÔNIX: Sticky Slide-in Widget (Scroll Box) Premium V5
 * - Layout Desktop Moderno (Posicionado à Direita)
 * - Layout Mobile Compacto (Botão à direita e sem espaços mortos)
 * - Auto-hide inteligente ao chegar ao rodapé
 * - Scroll-up (Seta) sobe com altura ajustada para o widget "gordinho"
 * Uso: [uonix_sticky_lead]
 */

add_shortcode('uonix_sticky_lead', 'uonix_gerar_sticky_slidein');

function uonix_gerar_sticky_slidein() {
    ob_start(); ?>

    <style>
        /* ==========================================================
           1. O CARTÃO FLUTUANTE (SLIDE-IN) - DESKTOP
           ========================================================== */
        .uonix-slidein-widget {
            position: fixed;
            bottom: 30px;
            right: 30px; /* Movido para a direita */
            width: 340px;
            background: #ffffff;
            border: 1px solid #eaf0f6;
            border-left: 4px solid #f76a0c;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(14, 55, 128, 0.1);
            z-index: 9998; /* Um nível abaixo do Modal */
            padding: 20px 24px 20px 20px;
            cursor: pointer;
            transform: translateX(150%); /* Animação entrando da direita */
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            box-sizing: border-box;
        }

        .uonix-slidein-widget.show {
            transform: translateX(0);
            opacity: 1;
            visibility: visible;
        }

        .uonix-slidein-widget:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 45px rgba(14, 55, 128, 0.15);
            border-color: #cbd5e1;
        }

        /* Botão de Fechar Desktop */
        .uonix-slidein-close {
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
        .uonix-slidein-close svg { width: 16px !important; height: 16px !important; stroke-width: 2.5 !important; }
        .uonix-slidein-close:hover { color: #dc2626 !important; background: transparent !important; }

        /* Estrutura Interna Desktop */
        .uonix-slidein-content {
            display: flex;
            align-items: center;
            gap: 18px;
            width: 100%;
        }

        .uonix-slidein-icon {
            width: 48px; 
            height: 48px; 
            border-radius: 12px; 
            background: linear-gradient(135deg, #f76a0c 0%, #ff8a3d 100%); 
            color: #ffffff; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-shrink: 0;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.3);
        }
        .uonix-slidein-icon svg { width: 22px; height: 22px; }

        .uonix-slidein-text { 
            display: flex; 
            flex-direction: column; 
            flex-grow: 1;
        }
        .uonix-si-tag { 
            font-size: 10px; 
            font-weight: 800; 
            color: #f76a0c; 
            text-transform: uppercase; 
            letter-spacing: 0.8px; 
            margin-bottom: 4px; 
            line-height: 1;
        }
        .uonix-si-title { 
            font-size: 16px; 
            font-weight: 800; 
            color: #0e3780; 
            line-height: 1.2; 
            display: block; 
            margin-bottom: 3px;
        }
        .uonix-si-desc { 
            font-size: 13px; 
            color: #64748b; 
            line-height: 1.3; 
            font-weight: 500;
            display: block; 
        }
        .uonix-si-action {
            font-size: 12px;
            font-weight: 800;
            color: #f76a0c;
            margin-top: 8px;
            display: flex;
            align-items: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease;
        }
        .uonix-slidein-widget:hover .uonix-si-action {
            transform: translateX(4px);
        }

        /* ==========================================================
           2. ANIMAÇÃO SINCRONIZADA DO BOTÃO "SCROLL UP" (KADENCE)
           ========================================================== */
        #kt-scroll-up {
            transition: bottom 0.5s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        /* Desktop: Empurra pra cima SÓ quando o Widget estiver visível */
        @media (min-width: 769px) {
            body.uonix-slidein-visible #kt-scroll-up {
                bottom: 180px !important; /* Aumentado para limpar a altura do widget mais gordinho */
            }
        }

        /* ==========================================================
           3. ADAPTAÇÃO EXCLUSIVA PARA MOBILE (TELEFONE)
           ========================================================== */
        @media (max-width: 768px) {
            .uonix-slidein-widget {
                bottom: 0; left: 0; width: 100%; border-radius: 16px 16px 0 0; 
                padding: 16px 20px;
                transform: translateY(150%); border-bottom: none; border-left: none; 
                border-top: 3px solid #f76a0c;
            }
            .uonix-slidein-widget.show { transform: translateY(0); }

            /* Mobile: Empurra a setinha pra cima para não ficar atrás do Widget */
            body.uonix-slidein-visible #kt-scroll-up {
                bottom: 120px !important; /* Ajustado para dar respiro acima do widget no mobile */
            }

            .uonix-slidein-close {
                top: -14px !important;
                right: 16px !important;
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 50% !important;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
                width: 28px !important;
                height: 28px !important;
                color: #64748b !important;
            }
            .uonix-slidein-close svg { width: 14px !important; height: 14px !important; stroke-width: 3 !important; }
            
            .uonix-slidein-content {
                position: relative;
                align-items: center;
                gap: 14px;
            }
            
            .uonix-slidein-icon { width: 40px; height: 40px; }
            .uonix-slidein-icon svg { width: 18px; height: 18px; }

            .uonix-slidein-text { padding-right: 110px; }
            .uonix-si-desc { display: none; }
            .uonix-si-title { font-size: 14px; margin-bottom: 0; }
            
            .uonix-si-action {
                position: absolute; right: 0; top: 50%; transform: translateY(-50%); margin-top: 0;
                background: #0e3780; color: #ffffff; padding: 8px 12px; border-radius: 6px;
                font-size: 11px; box-shadow: 0 4px 10px rgba(14, 55, 128, 0.15);
            }
            .uonix-slidein-widget:hover .uonix-si-action { transform: translateY(-50%) scale(1.02); }
        }

        /* ==========================================================
           4. O MODAL EXCLUSIVO DO STICKY
           ========================================================== */
        .uonix-sticky-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(14, 55, 128, 0.85); backdrop-filter: blur(5px);
            z-index: 999999; display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .uonix-sticky-overlay.active { opacity: 1; visibility: visible; }
        
        .uonix-sticky-modal-box {
            position: relative; background: #ffffff; width: 90%; max-width: 480px; max-height: 90vh; overflow-y: auto; 
            padding: 35px 30px 40px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); 
            transform: translateY(30px); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); text-align: left;
        }
        .uonix-sticky-overlay.active .uonix-sticky-modal-box { transform: translateY(0); }
        
        .uonix-sticky-close-modal {
            position: absolute !important; top: 16px !important; right: 16px !important; 
            background: transparent !important; border: none !important; box-shadow: none !important;
            color: #94a3b8 !important; cursor: pointer !important; transition: all 0.2s ease !important; 
            z-index: 10 !important; padding: 4px !important; display: flex !important; 
            align-items: center !important; justify-content: center !important; min-width: 0 !important;
        }
        .uonix-sticky-close-modal svg { width: 26px !important; height: 26px !important; stroke-width: 1.5 !important; }
        .uonix-sticky-close-modal:hover { color: #f76a0c !important; transform: scale(1.1) !important; background: transparent !important; }
    </style>

    <div id="uonix-slidein-box" class="uonix-slidein-widget">
        
        <button id="uonix-slidein-close-btn" class="uonix-slidein-close" aria-label="Dispensar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        
        <div class="uonix-slidein-content">
            <div class="uonix-slidein-icon">
                <svg viewBox="0 0 384 512" fill="currentColor"><path d="M181.9 256.1c-5-16-4.9-46.9-2-46.9 8.4 0 7.6 36.9 2 46.9zm-1.7 47.2c-7.7 20.2-17.3 43.3-28.4 62.7 18.3-7 39-17.2 62.9-21.9-12.7-9.6-24.9-23.4-34.5-40.8zM86.1 428.1c0 .8 13.2-5.4 34.9-40.2-6.7 6.3-29.1 24.5-34.9 40.2zM248 160h136v328c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V24C0 10.7 10.7 0 24 0h200v136c0 13.2 10.8 24 24 24zm-8 171.8c-20-12.2-33.3-29-42.7-53.8 4.5-18.5 11.6-46.6 6.2-64.2-4.7-29.4-42.4-26.5-47.8-6.8-5 18.3-.4 44.1 8.1 77-11.6 27.6-28.7 64.6-40.8 85.8-.1 0-.1.1-.2.1-27.1 13.9-73.6 44.5-54.5 68 5.6 6.9 16 10 21.5 10 17.9 0 35.7-18 61.1-61.8 25.8-8.5 54.1-19.1 79-23.2 21.7 11.8 47.1 19.5 64 19.5 29.2 0 31.2-32 19.7-43.4-13.9-13.6-54.3-9.7-73.6-7.2zM377 105L279 7c-4.5-4.5-10.6-7-17-7h-6v128h128v-6.1c0-6.3-2.5-12.4-7-16.9zm-74.1 255.3c4.1-2.7-2.5-11.9-42.8-9 37.1 15.8 42.8 9 42.8 9z"></path></svg>
            </div>
            
            <div class="uonix-slidein-text">
                <span class="uonix-si-tag">Material Gratuito</span>
                <strong class="uonix-si-title">Checklist Técnico</strong>
                <span class="uonix-si-desc">Sistemas de Ancoragem</span>
                <div class="uonix-si-action">Baixar &rarr;</div>
            </div>
        </div>

    </div>

    <div id="uonix-sticky-modal" class="uonix-sticky-overlay">
        <div class="uonix-sticky-modal-box">
            
            <button id="uonix-sticky-modal-close" class="uonix-sticky-close-modal" aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
            
            <?php echo do_shortcode('[uonix_form_captura]'); ?>
            
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slideInBox = document.getElementById('uonix-slidein-box');
            const btnCloseSlideIn = document.getElementById('uonix-slidein-close-btn');
            const stickyModal = document.getElementById('uonix-sticky-modal');
            const btnCloseModal = document.getElementById('uonix-sticky-modal-close');
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
                
                let offsetRodape = 650; 
                
                if (window.innerWidth <= 768) {
                    offsetRodape = 1400; 
                } else if (window.innerWidth <= 1024) {
                    offsetRodape = 1000; 
                }
                
                // Exibe a caixa se descer mais de 600px E não encostar no rodapé
                if (scrollY > 600 && (documentHeight - (scrollY + windowHeight)) > offsetRodape) {
                    slideInBox.classList.add('show');
                    body.classList.add('uonix-slidein-visible'); // Avisa o CSS que a caixa abriu
                } else {
                    slideInBox.classList.remove('show');
                    body.classList.remove('uonix-slidein-visible'); // Avisa o CSS que a caixa fechou
                }
            });

            // Clique no X fecha a caixa de vez e desce a setinha
            btnCloseSlideIn.addEventListener('click', function(e) {
                e.stopPropagation(); 
                isDismissed = true;
                slideInBox.classList.remove('show');
                body.classList.remove('uonix-slidein-visible');
            });

            // Clique na Caixa abre o Modal
            slideInBox.addEventListener('click', function() {
                stickyModal.classList.add('active');
                body.style.overflow = 'hidden'; 
                
                slideInBox.classList.remove('show');
                body.classList.remove('uonix-slidein-visible'); // Garante que a setinha desça no fundo escuro
                isDismissed = true; 
            });

            // Fechar o Modal
            btnCloseModal.addEventListener('click', function() {
                stickyModal.classList.remove('active');
                body.style.overflow = '';
            });

            // Fechar o Modal clicando fora dele
            stickyModal.addEventListener('click', function(e) {
                if (e.target === stickyModal) {
                    stickyModal.classList.remove('active');
                    body.style.overflow = '';
                }
            });
        });
    </script>

    <?php return ob_get_clean();
}


