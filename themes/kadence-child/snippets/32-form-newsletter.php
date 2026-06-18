<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - newsletter customizada.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 10453-10876 do export original.
// -----------------------------------------------------------------------------
/**
 * Formulario Newsletter 
 */
/**
 * UÔNIX: Formulário de Newsletter Customizado (Modular)
 * - Interface Ultra Slim (Campos finos)
 * - Layout Flutuante Blindado com Fundo Escuro (layout="accordion")
 * - Mensagem de Sucesso Limpa
 * - Envia via API para Form 2 (Newsletter) e Form 4 (Leads)
 */

// ==============================================================================
// 1. FRONTEND: VISUAL DO FORMULÁRIO (SHORTCODE)
// ==============================================================================
add_shortcode('uonix_form_newsletter', 'uonix_gerar_form_newsletter_html');

function uonix_gerar_form_newsletter_html($atts) {
    $a = shortcode_atts(array(
        'layout' => 'default' 
    ), $atts);

    $is_accordion = ($a['layout'] === 'accordion');
    $titulo_pagina = esc_attr(get_the_title());
    $unique_id = uniqid('unf_');
    
    ob_start(); 
    ?>
    
    <style>
        /* =======================================================
           ESTILOS BASE (CLAROS / SIDEBAR) - SLIM DESIGN
           ======================================================= */
        .uonix-news-wrapper { display: flex; flex-direction: column; width: 100%; font-family: inherit; position: relative; }
        .uonix-news-wrapper .unf_form_fields { display: flex; flex-direction: column; gap: 12px; width: 100%; transition: opacity 0.3s ease; }
        
        .uonix-form-group { position: relative; width: 100%; display: flex; flex-direction: column; }
        .uonix-form-group input { 
            width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; 
            padding: 0 12px !important; height: 38px !important; /* SLIM: Reduzido de 44px para 38px */
            font-size: 14px !important; color: #1a202c; 
            background: #ffffff; transition: all 0.3s ease; 
            outline: none !important; box-sizing: border-box; box-shadow: none !important;
        }
        .uonix-form-group input:focus { border-color: #0e3780; box-shadow: 0 0 0 2px rgba(14, 55, 128, 0.1) !important; }
        
        .uonix-floating-label { 
            position: absolute; left: 12px; top: 11px; color: #64748b; font-size: 14px;
            pointer-events: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); margin: 0; line-height: 1; 
        }
        .uonix-form-group input:focus ~ .uonix-floating-label,
        .uonix-form-group input:not(:placeholder-shown) ~ .uonix-floating-label { 
            top: -7px; left: 8px; padding: 0 4px; font-size: 11px; color: #0e3780; 
            font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; background: #ffffff; outline: none !important;
        }
        .uonix-form-group input:not(:focus):not(:placeholder-shown) ~ .uonix-floating-label { color: #64748b; }

        /* Checkbox Customizado */
        .uonix-custom-checkbox-wrapper { display: flex; align-items: flex-start; cursor: pointer; position: relative; user-select: none; margin-top: 2px;}
        .uonix-custom-checkbox-wrapper input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; margin: 0; }
        .uonix-custom-box { 
            width: 18px; height: 18px; border: 2px solid #cbd5e1; border-radius: 4px; 
            display: inline-flex; align-items: center; justify-content: center; 
            margin-right: 10px; transition: all 0.2s; flex-shrink: 0; background: #fff; margin-top: 1px;
        }
        .uonix-custom-checkbox-wrapper input:checked ~ .uonix-custom-box { background: #0e3780; border-color: #0e3780; } 
        .uonix-custom-box::after { content: ""; width: 4px; height: 8px; border: solid white; border-width: 0 2px 2px 0; transform: rotate(45deg); opacity: 0; margin-bottom: 2px;}
        .uonix-custom-checkbox-wrapper input:checked ~ .uonix-custom-box::after { opacity: 1; }
        .uonix-check-text { font-size: 12px; color: #475569; line-height: 1.4; margin: 0; }

        /* Botão SLIM */
        .uonix-btn-submit-news { 
            display: inline-flex; align-items: center; justify-content: center; width: 100%; margin-top: 2px; 
            height: 38px !important; padding: 0 16px !important; background: #0e3780; color: #ffffff !important; font-size: 15px !important; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.5px; border: none; border-radius: 6px; 
            transition: all 0.3s ease; cursor: pointer; box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15);
        }
        .uonix-btn-submit-news:hover:not(:disabled) { background: #15459e; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25); }
        .uonix-btn-submit-news:disabled { opacity: 0.7; cursor: not-allowed; }

        /* Erros e Sucesso Base Limpo */
        .uonix-news-wrapper .uonix-helper-text { display: none; color: #dc2626; font-size: 11px; font-weight: 600; margin-top: 4px; padding-left: 4px; }
        .uonix-news-wrapper .uonix-helper-text.active { display: block; }
        .uonix-news-wrapper .uonix-input-error { border-color: #dc2626 !important; background: #fffbfa !important; }
        .uonix-news-wrapper .uonix-custom-box.uonix-error-box { border-color: #dc2626 !important; background: #fef2f2; }
        .uonix-news-wrapper .uonix-label-error { color: #dc2626 !important; }
        .uonix-news-wrapper .uonix-feedback-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; display: none; padding: 12px; margin-top: 5px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        
        .uonix-news-wrapper .uonix-success-wrapper { display: none; flex-direction: column; width: 100%; animation: uonixFadeInUp 0.5s ease forwards; padding-top: 5px;}
        .uonix-news-wrapper .uonix-success-inline { display: flex; align-items: flex-start; gap: 10px; color: #166534; font-size: 13px; line-height: 1.4; background: #f0fdf4; padding: 12px; border-radius: 8px; border: 1px solid #bbf7d0;}
        .uonix-news-wrapper .uonix-success-icon { width: 20px; height: 20px; border-radius: 50%; background: #22c55e; color: #ffffff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
        .uonix-news-wrapper .uonix-success-text strong { font-weight: 800; display: block; margin-bottom: 2px; }
        @keyframes uonixFadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

        /* =======================================================
           ESTILOS EXCLUSIVOS PARA O RODAPÉ (DARK MODE + FLUTUANTE)
           ======================================================= */
        
        .uonix-news-accordion-container {
            position: relative;
        }

        /* O Botão Accordion */
        .uonix-news-accordion-container .uonix-news-trigger {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%; background: rgba(255,255,255,0.04); color: #f8fafc;
            border: 1px solid rgba(255,255,255,0.1); padding: 12px 16px;
            border-radius: 6px; cursor: pointer; transition: all 0.3s ease;
            font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .uonix-news-accordion-container .uonix-news-trigger .trigger-left { display: flex; align-items: center; gap: 12px; }
        .uonix-news-accordion-container .uonix-news-trigger svg { width: 16px; height: 16px; fill: currentColor; }
        .uonix-news-accordion-container .uonix-news-trigger .trigger-icon { transition: transform 0.3s ease; font-size: 11px;}
        
        /* Efeito Hover Laranja no Accordion */
        .uonix-news-accordion-container .uonix-news-trigger:hover,
        .uonix-news-accordion-container .uonix-news-trigger.is-active {
            border-color: var(--wp--preset--color--theme-palette-15, #f76a0c);
            color: var(--wp--preset--color--theme-palette-15, #f76a0c);
            background: rgba(247, 106, 12, 0.06);
        }
        .uonix-news-accordion-container .uonix-news-trigger.is-active .trigger-icon { transform: rotate(180deg); }

        /* A Caixa que Abre (AGORA FLUTUANTE COM FUNDO ESCURO BLINDADO) */
        .uonix-news-accordion-container.is-accordion-layout .uonix-news-content {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            min-width: 280px;
            z-index: 999999 !important; 
            background-color: #1a202c !important; /* COR DE FUNDO FORÇADA E OPACA */
            border: 1px solid rgba(255,255,255,0.15) !important;
            border-radius: 8px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.8) !important; /* Sombra pesada para destacar do fundo */
            padding: 16px;
            
            /* Estado Fechado */
            visibility: hidden;
            opacity: 0;
            transform: translateY(0);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }
        
        /* Estado Aberto */
        .uonix-news-accordion-container.is-accordion-layout .uonix-news-content.is-open {
            visibility: visible;
            opacity: 1;
            transform: translateY(12px); 
            pointer-events: auto;
        }

        /* Inputs Dark Mode */
        .uonix-news-accordion-container.is-accordion-layout .uonix-form-group input {
            background: rgba(255,255,255,0.03) !important; 
            border: 1px solid rgba(255,255,255,0.2) !important;
            color: #ffffff !important;
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-form-group input:focus {
            border-color: var(--wp--preset--color--theme-palette-15, #f76a0c) !important;
            background: rgba(0,0,0,0.25) !important;
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-floating-label { color: rgba(255,255,255,0.5); }
        .uonix-news-accordion-container.is-accordion-layout .uonix-form-group input:focus ~ .uonix-floating-label,
        .uonix-news-accordion-container.is-accordion-layout .uonix-form-group input:not(:placeholder-shown) ~ .uonix-floating-label {
            color: var(--wp--preset--color--theme-palette-15, #f76a0c); 
            background-color: #1a202c !important; /* Camufla exatamente com o fundo da caixa */
        }

		/* Checkbox Dark Mode */
        .uonix-news-accordion-container.is-accordion-layout .uonix-custom-box {
            background: transparent; border-color: rgba(255,255,255,0.3);
        }
        .uonix-news-accordion-container.is-accordion-layout input:checked ~ .uonix-custom-box {
            background: var(--wp--preset--color--theme-palette-15, #f76a0c);
            border-color: var(--wp--preset--color--theme-palette-15, #f76a0c);
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-check-text { color: rgba(255,255,255,0.7); font-size: 11px; }
        
        /* Ajuste do Link da Política de Privacidade */
        .uonix-news-accordion-container.is-accordion-layout .uonix-check-text a { 
            color: #60a5fa !important; /* Azul claro para contraste em fundo escuro */
            text-decoration: underline !important;
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-check-text a:hover { 
            color: #ffffff !important; 
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-btn-submit-news:hover:not(:disabled) {
            background: #e05e07 !important; transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(247, 106, 12, 0.3) !important;
        }

        /* Sucesso Dark Mode */
        .uonix-news-accordion-container.is-accordion-layout .uonix-success-inline {
            background: rgba(34, 197, 94, 0.1) !important; border: 1px solid rgba(34, 197, 94, 0.2) !important;
        }
        .uonix-news-accordion-container.is-accordion-layout .uonix-success-text { color: #e2e8f0 !important;}
        .uonix-news-accordion-container.is-accordion-layout .uonix-success-text strong { color: #4ade80 !important; }
    </style>

    <div class="uonix-news-accordion-container<?php echo $is_accordion ? ' is-accordion-layout' : ''; ?>" id="container_<?php echo $unique_id; ?>">
        
        <?php if ($is_accordion) : ?>
        <button type="button" class="uonix-news-trigger" id="trigger_<?php echo $unique_id; ?>">
            <span class="trigger-left">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                Assinar Newsletter
            </span>
            <span class="trigger-icon">▼</span>
        </button>
        <div class="uonix-news-content" id="content_<?php echo $unique_id; ?>">
        <?php endif; ?>

            <form id="form_<?php echo $unique_id; ?>" class="uonix-news-wrapper" novalidate="">
                <input type="hidden" name="origem" value="<?php echo $titulo_pagina; ?>">
                
                <div class="unf_form_fields" id="fields_<?php echo $unique_id; ?>">
                    <div class="uonix-form-group">
                        <input type="email" name="email" id="email_<?php echo $unique_id; ?>" placeholder=" ">
                        <label for="email_<?php echo $unique_id; ?>" class="uonix-floating-label">E-mail</label>
                        <div class="uonix-helper-text" id="help_email_<?php echo $unique_id; ?>">Insira um e-mail válido.</div>
                    </div>

                    <label class="uonix-custom-checkbox-wrapper">
                        <input type="checkbox" name="termo" id="termo_<?php echo $unique_id; ?>" value="sim">
                        <span class="uonix-custom-box" id="box_termo_<?php echo $unique_id; ?>"></span>
                        <span class="uonix-check-text" id="label_termo_<?php echo $unique_id; ?>">
                            Concordo em receber comunicações e li a 
                            <a class="policy-privacy-link" href="/politica-de-privacidade/" target="_blank">Política de Privacidade</a> *
                        </span>
                    </label>
                    <div class="uonix-helper-text" id="help_termo_<?php echo $unique_id; ?>" style="margin-left: 28px;">É obrigatório aceitar a política.</div>

                    <div class="uonix-feedback-error" id="error_<?php echo $unique_id; ?>"></div>

                    <button type="submit" id="btn_<?php echo $unique_id; ?>" class="uonix-btn-submit-news">
                        <span>Assinar</span>
                    </button>
                </div>

                <div class="uonix-success-wrapper" id="success_<?php echo $unique_id; ?>">
                    <div class="uonix-success-inline">
                        <span class="uonix-success-icon">✔</span>
                        <div class="uonix-success-text">
                            <strong>Inscrição confirmada!</strong> 
                            Você começará a receber conteúdos exclusivos da Uônix.
                        </div>
                    </div>
                </div>
            </form>

        <?php if ($is_accordion) : ?>
        </div> <?php endif; ?>
        
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const uid = '<?php echo $unique_id; ?>';
        
        // Logica do Accordion Flutuante
        const triggerBtn = document.getElementById('trigger_' + uid);
        const accordionContent = document.getElementById('content_' + uid);
        if (triggerBtn && accordionContent) {
            triggerBtn.addEventListener('click', function(e) {
                e.stopPropagation(); 
                this.classList.toggle('is-active');
                accordionContent.classList.toggle('is-open');
            });
            
            // Clicar fora fecha a caixa flutuante
            document.addEventListener('click', function(e) {
                if (accordionContent.classList.contains('is-open') && !accordionContent.contains(e.target) && e.target !== triggerBtn) {
                    triggerBtn.classList.remove('is-active');
                    accordionContent.classList.remove('is-open');
                }
            });
            
            accordionContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        // Logica do Formulario
        const form = document.getElementById('form_' + uid);
        if (!form) return;

        const formFieldsBlock = document.getElementById('fields_' + uid);
        const successBlock = document.getElementById('success_' + uid);
        const btn = document.getElementById('btn_' + uid);
        const feedbackError = document.getElementById('error_' + uid);
        const emailInput = document.getElementById('email_' + uid);
        const termoInput = document.getElementById('termo_' + uid);
        const boxTermo = document.getElementById('box_termo_' + uid);
        const labelTermo = document.getElementById('label_termo_' + uid);

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            let hasError = false;

            emailInput.classList.remove('uonix-input-error');
            boxTermo.classList.remove('uonix-error-box');
            labelTermo.classList.remove('uonix-label-error');
            form.querySelectorAll('.uonix-helper-text.active').forEach(el => el.classList.remove('active'));
            feedbackError.style.display = 'none';

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailInput.value || !emailRegex.test(emailInput.value)) {
                emailInput.classList.add('uonix-input-error');
                document.getElementById('help_email_' + uid).classList.add('active');
                hasError = true;
            }

            if (!termoInput.checked) {
                boxTermo.classList.add('uonix-error-box');
                labelTermo.classList.add('uonix-label-error');
                document.getElementById('help_termo_' + uid).classList.add('active');
                hasError = true;
            }

            if (hasError) return;

            btn.disabled = true;
            btn.innerHTML = '<span>Processando...</span>';
            
            const formData = new FormData(form);
            formData.append('action', 'uonix_processar_newsletter_customizada');

            fetch(window.location.origin + '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    formFieldsBlock.style.display = 'none';
                    successBlock.style.display = 'flex'; 
                } else {
                    feedbackError.style.display = 'block';
                    feedbackError.innerHTML = '⚠ ' + data.data.message;
                    btn.disabled = false;
                    btn.innerHTML = '<span>Assinar</span>';
                }
            })
            .catch(error => {
                feedbackError.style.display = 'block';
                feedbackError.innerHTML = '⚠ Falha na conexão. Tente novamente.';
                btn.disabled = false;
                btn.innerHTML = '<span>Assinar</span>';
            });
        });
        
        emailInput.addEventListener('input', function() {
            this.classList.remove('uonix-input-error');
            document.getElementById('help_email_' + uid).classList.remove('active');
        });
        
        termoInput.addEventListener('change', function() {
            boxTermo.classList.remove('uonix-error-box');
            labelTermo.classList.remove('uonix-label-error');
            document.getElementById('help_termo_' + uid).classList.remove('active');
        });
    });
    </script>
    <?php return ob_get_clean();
}

// ==============================================================================
// 2. BACKEND: MOTOR AJAX (Integração com Fluent Forms)
// ==============================================================================
add_action('wp_ajax_uonix_processar_newsletter_customizada', 'uonix_processar_newsletter_handler');
add_action('wp_ajax_nopriv_uonix_processar_newsletter_customizada', 'uonix_processar_newsletter_handler');

function uonix_processar_newsletter_handler() {
    if (!function_exists('wpFluentForm')) {
        wp_send_json_error(['message' => 'Sistema temporariamente indisponível.']);
    }

    $email  = strtolower(sanitize_email($_POST['email'] ?? ''));
    $termo  = isset($_POST['termo']) && $_POST['termo'] === 'sim';
    //$origem = sanitize_text_field($_POST['origem'] ?? 'Origem Desconhecida');
	$origem = 'RODAPÉ';

    if (empty($email) || !is_email($email)) {
        wp_send_json_error(['message' => 'E-mail inválido.']);
    }
    if (!$termo) {
        wp_send_json_error(['message' => 'É necessário aceitar os termos.']);
    }

    $embedded_post_id = (int) get_option('page_on_front');
    if ($embedded_post_id <= 0) { $embedded_post_id = (int) url_to_postid(home_url('/')); }
    $referer_path = wp_get_referer() ?: '/';

    try {
        /** @var \FluentForm\App\Services\Form\SubmissionHandlerService $submissionHandler */
        $submissionHandler = wpFluentForm()->make('\FluentForm\App\Services\Form\SubmissionHandlerService');

        // 1. Grava no Form 2 (Newsletter)
        $submissionHandler->handleSubmission([
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer_path,
            'newsletters_email'            => $email,
            'newsletters_termo'            => 'on',
            'newsletters_origem'           => $origem,
        ], 2);

        // 2. Grava no Form 4 (Captura de Leads)
        $submissionHandler->handleSubmission([
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer_path,
            'capturalead_email'            => $email,
            'capturalead_newsletters'      => 'SIM',
            'capturalead_origem'           => 'ASSINATURA NEWSLETTERS: ' . $origem
        ], 4);

        wp_send_json_success(['message' => 'Inscrição realizada.']);

    } catch (\Exception $e) {
        error_log('Uônix AJAX Sync Error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Erro interno do servidor. Tente novamente.']);
    }
}

