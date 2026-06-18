<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Modal generico reutilizavel.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 9156-9388 do export original.
// -----------------------------------------------------------------------------
/**
 * Modal Pop-up
 */
/**
 * UÔNIX: Pop-up Genérico e Reutilizável (Enclosing Shortcode)
 * - Botão de fechar minimalista e blindado (estilo Sticky V3)
 * - Efeito "Floating Label" mágico para campos do Fluent Forms
 * * Uso:
 * [uonix_modal texto_botao="Ver Vídeo" titulo="Assista ao Tutorial" desc="Veja como instalar."]
 * Aqui entra o seu conteúdo, HTML, texto ou [fluentform id="2"]
 * [/uonix_modal]
 */

add_shortcode('uonix_modal', 'uonix_gerar_modal_generico_v1');
function uonix_gerar_modal_generico_v1($atts, $content = null) {
    // 1. Configuração dos atributos que você pode mudar
    $a = shortcode_atts(array(
        'texto_botao'  => 'Abrir',
        'titulo'       => '',
        'desc'         => '',
        'estilo_botao' => 'neutro' // Opções: 'neutro' ou 'destaque'
    ), $atts);

    // Gera um ID único para permitir múltiplos pop-ups na mesma página
    $modal_id = uniqid('uonix_modal_id_');
    
    // Define a classe do botão
    $classe_botao = ($a['estilo_botao'] === 'destaque') ? 'uonix-btn-modal-destaque' : 'uonix-btn-modal-neutro';

    ob_start(); ?>

    <button class="<?php echo esc_attr($classe_botao); ?> uonix-trigger-modal" data-target="<?php echo esc_attr($modal_id); ?>">
        <?php echo esc_html($a['texto_botao']); ?>
    </button>

    <div id="<?php echo esc_attr($modal_id); ?>" class="uonix-modal-overlay uonix-modal-generico">
        <div class="uonix-modal-box">
            
            <button class="uonix-modal-close" aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
            
            <?php if (!empty($a['titulo'])) : ?>
                <div class="uonix-lm-header-row" style="margin-top: 10px;">
                    <div class="uonix-lm-icon-wrapper" style="background: rgba(14, 55, 128, 0.05); color: #0e3780;">
                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8-8-8z"/></svg>
                    </div>
                    <h4 class="uonix-lm-title"><?php echo wp_kses_post($a['titulo']); ?></h4>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($a['desc'])) : ?>
                <p class="uonix-lm-desc"><?php echo esc_html($a['desc']); ?></p>
            <?php endif; ?>
            
            <div class="uonix-modal-conteudo-interno">
                <?php echo do_shortcode($content); ?>
            </div>
        </div>
    </div>

    <?php
    // 2. CONTROLE DE ASSETS (CSS e JS carregam apenas 1 vez, mesmo com 10 botões na página)
    static $assets_carregados = false;
    
    if (!$assets_carregados) {
        $assets_carregados = true;
        ?>
        <style>
            /* ESTILOS DOS BOTÕES GATILHO */
            .uonix-btn-modal-neutro {
                display: inline-block; padding: 12px 24px; font-family: inherit; font-size: 15px; font-weight: 700;
                cursor: pointer; border: 2px solid currentColor; background: transparent; color: inherit;
                border-radius: 6px; transition: all 0.3s ease; text-transform: uppercase;
            }
            .uonix-btn-modal-neutro:hover { opacity: 0.7; transform: translateY(-2px); }

            .uonix-btn-modal-destaque {
                display: inline-flex; align-items: center; justify-content: center; padding: 14px 24px;
                background: linear-gradient(90deg, #f76a0c 0%, #ff8a3d 100%); color: #ffffff !important;
                font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
                border: none; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 14px rgba(247, 106, 12, 0.3);
                transition: all 0.3s ease;
            }
            .uonix-btn-modal-destaque:hover { background: #d95700; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(247, 106, 12, 0.4); }

            /* ESTILOS DO POP-UP (MODAL) */
            .uonix-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(14, 55, 128, 0.85); backdrop-filter: blur(5px); z-index: 999999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; }
            .uonix-modal-overlay.active { opacity: 1; visibility: visible; }
            
            .uonix-modal-box { position: relative; background: #ffffff; width: 90%; max-width: 480px; max-height: 90vh; overflow-y: auto; padding: 35px 30px 40px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); transform: translateY(30px); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); text-align: left; }
            .uonix-modal-overlay.active .uonix-modal-box { transform: translateY(0); }
            
            /* Botão Fechar Atualizado e Blindado */
            .uonix-modal-close { 
                position: absolute !important; 
                top: 16px !important; 
                right: 16px !important; 
                background: transparent !important; 
                border: none !important; 
                box-shadow: none !important;
                color: #94a3b8 !important; 
                cursor: pointer !important; 
                transition: all 0.2s ease !important; 
                z-index: 10 !important; 
                padding: 4px !important; 
                display: flex !important; 
                align-items: center !important; 
                justify-content: center !important; 
                min-width: 0 !important; 
                border-radius: 0 !important;
            }
            .uonix-modal-close svg { width: 26px !important; height: 26px !important; stroke-width: 1.5 !important; }
            .uonix-modal-close:hover { color: #f76a0c !important; transform: scale(1.1) !important; background: transparent !important; }
            
            .uonix-lm-header-row { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; width: 100%; }
            .uonix-lm-icon-wrapper { display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 12px; background: rgba(247, 106, 12, 0.08); color: #f76a0c; flex-shrink: 0; }
            .uonix-lm-title { color: #0e3780; font-size: 20px; font-weight: 800; line-height: 1.2; margin: 0; letter-spacing: -0.3px; }
            .uonix-lm-desc { color: #475569; font-size: 15px; line-height: 1.4; margin: 0 0 18px; }
            
            .uonix-modal-conteudo-interno { width: 100%; margin-top: 15px; }

            /* =======================================================
               FLUENT FORMS: EFEITO FLOATING LABEL MÁGICO
               ======================================================= */
            .uonix-modal-conteudo-interno .fluentform { width: 100%; }
            .uonix-modal-conteudo-interno .ff-el-input--label { display: none !important; }
            .uonix-modal-conteudo-interno .ff_columns_total_2 { display: flex !important; flex-direction: column !important; gap: 12px !important; }
            .uonix-modal-conteudo-interno .ff-t-cell { flex-basis: 100% !important; max-width: 100% !important; padding: 0 !important; }
            
            .uonix-modal-conteudo-interno .ff-el-input--content { position: relative; display: block; }
            
            /* Esconde o placeholder visualmente (pois ele será substituído pela label flutuante) */
            .uonix-modal-conteudo-interno input[type="email"]::placeholder, 
            .uonix-modal-conteudo-interno input[type="text"]::placeholder { color: transparent !important; }

            /* Os inputs ganham mais espaço no topo para acomodar a label que sobe */
            .uonix-modal-conteudo-interno input[type="email"], 
            .uonix-modal-conteudo-interno input[type="text"] { 
                width: 100% !important; border: 1px solid #e2e8f0 !important;
				border-radius: 8px !important; 
                /* padding: 22px 16px 8px 16px !important;*/
				font-size: 15px !important; color: #1a202c !important; 
                background: #f8fafc !important; transition: all 0.3s ease !important; 
            }
            .uonix-modal-conteudo-interno input:focus { outline: none !important; border-color: #f76a0c !important; box-shadow: 0 0 0 3px rgba(247, 106, 12, 0.15) !important; background: #ffffff !important; }
            
            /* Estilo da Label Flutuante que o JS vai injetar */
            .uonix-floating-label {
                position: absolute; left: 16px; top: 16px; font-size: 15px; color: #94a3b8;
                pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            /* A mágica: quando o input tem foco, OU quando o input não está vazio (não exibe o placeholder nativo) */
            .uonix-modal-conteudo-interno input:focus + .uonix-floating-label,
            .uonix-modal-conteudo-interno input:not(:placeholder-shown) + .uonix-floating-label {
                top: 6px; font-size: 11px; color: #f76a0c; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
            }

            /* Botão de Envio do Form */
            .uonix-modal-conteudo-interno button.ff-btn-submit { display: inline-flex; align-items: center; justify-content: center; width: 100% !important; padding: 14px 20px !important; background: #0e3780 !important; color: #ffffff !important; font-size: 16px !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; border: none !important; border-radius: 8px !important; box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important; transition: all 0.3s ease !important; cursor: pointer; margin-top: 5px; }
            .uonix-modal-conteudo-interno button.ff-btn-submit:hover { background: #15459e !important; transform: translateY(-2px) !important; box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25) !important; }
            
            /* Checkbox e Erros */
            .uonix-modal-conteudo-interno .ff-el-form-check { margin-top: 10px !important; display: flex; align-items: flex-start;}
            .uonix-modal-conteudo-interno .ff_tc_checkbox { margin-top: 3px !important; margin-right: 8px;}
            .uonix-modal-conteudo-interno .ff_t_c { font-size: 12px !important; color: #64748b !important; line-height: 1.4 !important; }
            .uonix-modal-conteudo-interno .ff-el-is-error .ff-el-form-check-label { padding: 10px 12px !important; border: 1.5px solid #dc2626 !important; border-radius: 8px !important; background: rgba(220, 38, 38, 0.04) !important; }
            .uonix-modal-conteudo-interno .ff-el-is-error .error.text-danger { margin-top: 8px !important; padding-left: 28px !important; color: #dc2626 !important; font-weight: 600 !important; font-size: 12px !important; position: relative; display: block; }
            .uonix-modal-conteudo-interno .ff-el-is-error .error.text-danger::before { content: "⚠"; position: absolute; left: 8px; top: 0; font-size: 13px; }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Controle de Abertura/Fecho do Pop-up Genérico
                document.body.addEventListener('click', function(e) {
                    // Abrir
                    if (e.target.closest('.uonix-trigger-modal')) {
                        e.preventDefault();
                        const btn = e.target.closest('.uonix-trigger-modal');
                        const targetId = btn.getAttribute('data-target');
                        const modal = document.getElementById(targetId);
                        if (modal) {
                            modal.classList.add('active');
                            document.body.style.overflow = 'hidden'; // Evita scroll atrás
                            initFloatingLabels(modal); // Inicializa a mágica dos labels
                        }
                    }
                    
                    // Fechar no botão X
                    if (e.target.closest('.uonix-modal-close')) {
                        const modal = e.target.closest('.uonix-modal-generico');
                        if (modal) {
                            modal.classList.remove('active');
                            document.body.style.overflow = ''; 
                        }
                    }
                    
                    // Fechar clicando no fundo escuro
                    if (e.target.classList.contains('uonix-modal-generico')) {
                        e.target.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });

                // Função que transforma Placeholders em Floating Labels
                function initFloatingLabels(modal) {
                    const inputs = modal.querySelectorAll('.fluentform input[type="text"], .fluentform input[type="email"]');
                    
                    inputs.forEach(function(input) {
                        if(input.dataset.floating) return; // Evita duplicar se abrir o modal 2 vezes
                        input.dataset.floating = 'true';

                        const placeholderText = input.getAttribute('placeholder');
                        if(placeholderText) {
                            input.setAttribute('placeholder', ' ');

                            const label = document.createElement('span');
                            label.className = 'uonix-floating-label';
                            label.innerText = placeholderText;

                            input.parentNode.insertBefore(label, input.nextSibling);
                        }
                    });
                }
            });
        </script>
        <?php
    }

    return ob_get_clean();
}


