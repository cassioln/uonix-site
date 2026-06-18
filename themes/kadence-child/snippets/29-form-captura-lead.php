<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - captura de lead com download.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 9389-9806 do export original.
// -----------------------------------------------------------------------------
/**
 * Formulário de Captura Lead
 */
/**
 * UÔNIX: Formulário de Captura Customizado (Master Final V5)
 * - Link da Política de Privacidade integrado
 * - Títulos do cabeçalho blindados com !important
 * - Tela de Sucesso Interativa (Oculta o form)
 * - Auto-Download do PDF com Fallback Link
 */

// ==============================================================================
// 1. FRONTEND: VISUAL DO FORMULÁRIO (SHORTCODE)
// ==============================================================================
add_shortcode('uonix_form_captura', 'uonix_gerar_form_captura_html');

if ( ! function_exists( 'uonix_form_captura_upper_text' ) ) {
    function uonix_form_captura_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_form_captura_format_phone' ) ) {
    function uonix_form_captura_format_phone( $value ) {
        $digits = preg_replace( '/\D+/', '', (string) $value );

        if ( 11 === strlen( $digits ) ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 5 ), substr( $digits, 7, 4 ) );
        }

        if ( 10 === strlen( $digits ) ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 4 ), substr( $digits, 6, 4 ) );
        }

        return $digits;
    }
}

function uonix_gerar_form_captura_html() {
    ob_start(); ?>
    
    <style>
        /* ==========================================
           1. CABEÇALHO DO FORMULÁRIO
           ========================================== */
        .uonix-form-header-wrapper { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #eaf0f6; }
        .uonix-fh-icon { width: 48px; height: 48px; background: rgba(247, 106, 12, 0.08); color: #f76a0c; display: flex; align-items: center; justify-content: center; border-radius: 12px; flex-shrink: 0; }
        .uonix-fh-icon svg { width: 24px; height: 24px; }
        .uonix-fh-text { display: flex; flex-direction: column; justify-content: center; }
        
        .uonix-fh-text h3 { margin: 0 !important; font-size: 12px !important; font-weight: 800 !important; color: #94a3b8 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; line-height: 1 !important; }
        .uonix-fh-text h4 { margin: 4px 0 0 0 !important; font-size: 20px !important; font-weight: 800 !important; color: #0e3780 !important; line-height: 1 !important; letter-spacing: -0.3px !important; }

        /* ==========================================
           2. CAMPOS DE TEXTO E FLOATING LABELS
           ========================================== */
        .uonix-custom-form-wrapper { display: flex; flex-direction: column; width: 100%; font-family: inherit; position: relative; }
        #ucf_form_fields { display: flex; flex-direction: column; gap: 16px; width: 100%; transition: opacity 0.3s ease; }
        
        .uonix-form-group { position: relative; width: 100%; display: flex; flex-direction: column; }
  																				 
																							 
														
				.uonix-form-group input { 
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 16px;
    font-size: 16px;
    color: #1a202c;
    background: #ffffff;
    transition: all 0.3s ease;
    outline: none !important;
    box-sizing: border-box;
    box-shadow: none !important;
}

#uonix-custom-lead-form .uonix-form-group input:focus {
    border-color: #f76a0c;
    box-shadow: 0 0 0 2px rgba(247, 106, 12, 0.15) !important;
}

#uonix-custom-lead-form .uonix-floating-label { 
    position: absolute;
    left: 16px;
    /* top: 18px; */
    font-size: 16px;
    color: #64748b;
    pointer-events: none;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    margin: 0;
    line-height: 1;
}

#uonix-custom-lead-form .uonix-form-group input:focus ~ .uonix-floating-label,
#uonix-custom-lead-form .uonix-form-group input:not(:placeholder-shown) ~ .uonix-floating-label { 
    top: -7px;
    left: 12px;
    padding: 0 4px;
    font-size: 12px;
    color: #f76a0c;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #ffffff;
	outline: none !important;
}

#uonix-custom-lead-form .uonix-form-group input:not(:focus):not(:placeholder-shown) ~ .uonix-floating-label {
    color: #64748b;
}							 
																							 
																							 
																							 

        /* ==========================================
           3. CHECKBOXES CUSTOMIZADOS E LINKS
           ========================================== */
        .uonix-custom-checkbox-wrapper { display: flex; align-items: flex-start; cursor: pointer; position: relative; margin-top: 4px; user-select: none; }
        .uonix-custom-checkbox-wrapper input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; margin: 0; }
        
        .uonix-custom-box { 
            width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 6px; 
            display: inline-flex; align-items: center; justify-content: center; 
            margin-right: 12px; transition: all 0.2s; flex-shrink: 0; background: #fff; margin-top: 1px;
        }
        
        .uonix-custom-checkbox-wrapper input:checked ~ .uonix-custom-box { background: #f76a0c; border-color: #f76a0c; }
        .uonix-custom-box::after { 
            content: ""; width: 5px; height: 10px; border: solid white; border-width: 0 2px 2px 0; 
            transform: rotate(45deg); opacity: 0; margin-bottom: 2px; transition: opacity 0.2s;
        }
        .uonix-custom-checkbox-wrapper input:checked ~ .uonix-custom-box::after { opacity: 1; }
        
        .uonix-check-text { font-size: 13px; color: #475569; line-height: 1.45; margin: 0; transition: color 0.3s; }
        


        /* ==========================================
           4. BOTÃO SUBMIT E FEEDBACK
           ========================================== */
        .uonix-btn-submit-custom { 
            display: inline-flex; align-items: center; justify-content: center; width: 100%; margin-top: 10px; 
            padding: 16px 20px; background: #0e3780; color: #ffffff !important; font-size: 16px; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.5px; border: none; border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15); transition: all 0.3s ease; cursor: pointer; 
        }
        .uonix-btn-submit-custom:hover:not(:disabled) { background: #15459e; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(14, 55, 128, 0.25); }
        .uonix-btn-submit-custom:disabled { opacity: 0.7; cursor: not-allowed; }

        /* ESTADOS DE VALIDAÇÃO (ERRO) */
        .uonix-helper-text { display: none; color: #dc2626; font-size: 12px; font-weight: 600; margin-top: 6px; padding-left: 4px; animation: uonixFadeIn 0.3s; }
        .uonix-helper-text.active { display: block; }
        
        .uonix-input-error { border-color: #dc2626 !important; box-shadow: 0 0 0 1px #dc2626 !important; background: #fffbfa !important; }
        .uonix-input-error ~ .uonix-floating-label { color: #dc2626 !important; }
        
        .uonix-custom-box.uonix-error-box { border-color: #dc2626 !important; background: #fef2f2; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15); }
        .uonix-label-error { color: #dc2626 !important; }
        .uonix-feedback-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; display: none; padding: 14px 16px; margin-top: 5px; border-radius: 8px; font-size: 14px; font-weight: 600; line-height: 1.4; text-align: left; }

        /* ==========================================
           5. TELA DE SUCESSO (SUCCESS STATE)
           ========================================== */
        .uonix-success-state {
            display: none; flex-direction: column; align-items: center; text-align: center;
            padding: 10px 10px 20px 10px; animation: uonixFadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .uonix-ss-icon {
            width: 72px; height: 72px; border-radius: 50%; background: #dcfce7; color: #16a34a;
            display: flex; align-items: center; justify-content: center; margin-bottom: 20px;
            box-shadow: 0 0 0 8px rgba(22, 163, 74, 0.1);
        }
        .uonix-ss-icon svg { width: 36px; height: 36px; }
        .uonix-success-state h4 { font-size: 24px !important; color: #0e3780 !important; font-weight: 800 !important; margin: 0 0 10px 0 !important; line-height: 1.2 !important; }
        .uonix-success-state p { font-size: 15px !important; color: #475569 !important; margin: 0 0 24px 0 !important; line-height: 1.5 !important; }
        
        .uonix-ss-fallback { font-size: 13px; color: #64748b; padding: 18px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; width: 100%; box-sizing: border-box; }
        .uonix-ss-fallback a { color: #f76a0c !important; font-weight: 800 !important; text-decoration: none !important; display: inline-block; margin-top: 6px; transition: color 0.3s ease !important; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;}
        .uonix-ss-fallback a:hover { color: #0e3780 !important; }

        @keyframes uonixFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes uonixFadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="uonix-form-header-wrapper">
        <div class="uonix-fh-icon">
            <svg viewBox="0 0 384 512" fill="currentColor"><path d="M181.9 256.1c-5-16-4.9-46.9-2-46.9 8.4 0 7.6 36.9 2 46.9zm-1.7 47.2c-7.7 20.2-17.3 43.3-28.4 62.7 18.3-7 39-17.2 62.9-21.9-12.7-9.6-24.9-23.4-34.5-40.8zM86.1 428.1c0 .8 13.2-5.4 34.9-40.2-6.7 6.3-29.1 24.5-34.9 40.2zM248 160h136v328c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V24C0 10.7 10.7 0 24 0h200v136c0 13.2 10.8 24 24 24zm-8 171.8c-20-12.2-33.3-29-42.7-53.8 4.5-18.5 11.6-46.6 6.2-64.2-4.7-29.4-42.4-26.5-47.8-6.8-5 18.3-.4 44.1 8.1 77-11.6 27.6-28.7 64.6-40.8 85.8-.1 0-.1.1-.2.1-27.1 13.9-73.6 44.5-54.5 68 5.6 6.9 16 10 21.5 10 17.9 0 35.7-18 61.1-61.8 25.8-8.5 54.1-19.1 79-23.2 21.7 11.8 47.1 19.5 64 19.5 29.2 0 31.2-32 19.7-43.4-13.9-13.6-54.3-9.7-73.6-7.2zM377 105L279 7c-4.5-4.5-10.6-7-17-7h-6v128h128v-6.1c0-6.3-2.5-12.4-7-16.9zm-74.1 255.3c4.1-2.7-2.5-11.9-42.8-9 37.1 15.8 42.8 9 42.8 9z"></path></svg>
        </div>
        <div class="uonix-fh-text">
            <h3>Download Checklist Técnico</h3>
            <h4>Sistemas de Ancoragem</h4>
        </div>
    </div>

    <form id="uonix-custom-lead-form" class="uonix-custom-form-wrapper" novalidate>
        
        <div id="ucf_form_fields">
            <div class="uonix-form-group">
                <input type="text" name="nome" id="ucf_nome" placeholder=" ">
                <label for="ucf_nome" class="uonix-floating-label">Nome</label>
            </div>

            <div class="uonix-form-group">
                <input type="text" name="empresa" id="ucf_empresa" placeholder=" ">
                <label for="ucf_empresa" class="uonix-floating-label">Empresa *</label>
                <div class="uonix-helper-text" id="help_empresa">Insira o nome da empresa.</div>
            </div>

            <div class="uonix-form-group">
                <input type="email" name="email" id="ucf_email" placeholder=" ">
                <label for="ucf_email" class="uonix-floating-label">E-mail *</label>
                <div class="uonix-helper-text" id="help_email">Insira um endereço de e-mail válido.</div>
            </div>

            <div class="uonix-form-group">
                <input type="tel" name="telefone" id="ucf_telefone" placeholder=" " inputmode="numeric" autocomplete="tel" maxlength="15">
                <label for="ucf_telefone" class="uonix-floating-label">Telefone</label>
            </div>

            <label class="uonix-custom-checkbox-wrapper">
                <input type="checkbox" name="newsletters" value="sim">
                <span class="uonix-custom-box"></span>
                <span class="uonix-check-text">Desejo receber comunicações com notícias, novidades e promoções da Uônix</span>
            </label>

            <div style="display: flex; flex-direction: column;">
                <label class="uonix-custom-checkbox-wrapper" style="margin-top: 8px;">
                    <input type="checkbox" name="termo" id="ucf_termo" value="sim">
                    <span class="uonix-custom-box" id="box_termo"></span>
                    <span class="uonix-check-text" id="label_termo">Declaro que li e estou de acordo com a <a href="/politica-de-privacidade/" target="_blank" class="policy-privacy-link">Política de Privacidade</a> *</span>
                </label>
                <div class="uonix-helper-text" id="help_termo" style="margin-left: 34px;">É obrigatório aceitar a política de privacidade.</div>
            </div>

            <div id="ucf_feedback_error" class="uonix-feedback-error"></div>

            <button type="submit" id="ucf_submit_btn" class="uonix-btn-submit-custom">
                <span>Baixar Material Grátis</span>
            </button>
        </div>

        <div id="ucf_success_state" class="uonix-success-state">
            <div class="uonix-ss-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <h4>Download Iniciado!</h4>
            <p>Obrigado! O arquivo será transferido para o seu dispositivo em instantes.</p>
            
            <div class="uonix-ss-fallback">
                Caso o download não inicie automaticamente,<br>
                <a href="/wp-content/uploads/2026/03/Ebook-Check-List-Tecnico-Sistemas-de-Ancoragem.pdf" target="_blank" id="ucf_download_link" download="Ebook-Checklist-Ancoragem-Uonix.pdf">Clique aqui para baixar</a>
            </div>
        </div>

    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('uonix-custom-lead-form');
        if (!form) return;

        const formFieldsBlock = document.getElementById('ucf_form_fields');
        const successBlock = document.getElementById('ucf_success_state');
        
        const btn = document.getElementById('ucf_submit_btn');
        const feedbackError = document.getElementById('ucf_feedback_error');
        const empresaInput = document.getElementById('ucf_empresa');
        const emailInput = document.getElementById('ucf_email');
        const telefoneInput = document.getElementById('ucf_telefone');
        const termoInput = document.getElementById('ucf_termo');
        const boxTermo = document.getElementById('box_termo');
        const labelTermo = document.getElementById('label_termo');

        function formatUonixPhone(value) {
            const phone = (value || '').replace(/\D/g, '').slice(0, 11);

            if (phone.length > 10) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 7) + '-' + phone.substring(7, 11);
            }

            if (phone.length > 6) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 6) + '-' + phone.substring(6);
            }

            if (phone.length > 2) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2);
            }

            return phone;
        }

        if (telefoneInput) {
            telefoneInput.addEventListener('input', function() {
                this.value = formatUonixPhone(this.value);
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            let hasError = false;

            // 1. Limpa erros visuais anteriores
            form.querySelectorAll('.uonix-input-error').forEach(el => el.classList.remove('uonix-input-error'));
            boxTermo.classList.remove('uonix-error-box');
            labelTermo.classList.remove('uonix-label-error');
            form.querySelectorAll('.uonix-helper-text.active').forEach(el => el.classList.remove('active'));
            feedbackError.style.display = 'none';

            // 2. Validação Empresa
            if (!empresaInput.value.trim()) {
                empresaInput.classList.add('uonix-input-error');
                document.getElementById('help_empresa').classList.add('active');
                hasError = true;
            }

            // 3. Validação E-mail
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailInput.value || !emailRegex.test(emailInput.value)) {
                emailInput.classList.add('uonix-input-error');
                document.getElementById('help_email').classList.add('active');
                hasError = true;
            }

            // 4. Validação Termo
            if (!termoInput.checked) {
                boxTermo.classList.add('uonix-error-box');
                labelTermo.classList.add('uonix-label-error');
                document.getElementById('help_termo').classList.add('active');
                hasError = true;
            }

            if (hasError) return;

            // 4. Setup Loading e Envio AJAX
            btn.disabled = true;
            btn.innerHTML = '<span>Processando...</span>';
            
            const formData = new FormData(form);
            formData.append('action', 'uonix_processar_lead_customizado');

            fetch(window.location.origin + '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // SUCESSO: Esconde o Form e Mostra a Tela de Sucesso
                    formFieldsBlock.style.display = 'none';
                    successBlock.style.display = 'flex';
                    
                    // Força o Download automático programaticamente
                    const fileUrl = document.getElementById('ucf_download_link').href;
                    const tempLink = document.createElement('a');
                    tempLink.href = fileUrl;
                    tempLink.download = 'Ebook-Checklist-Ancoragem-Uonix.pdf';
                    tempLink.target = '_blank';
                    document.body.appendChild(tempLink);
                    tempLink.click();
                    document.body.removeChild(tempLink);
                    
                } else {
                    feedbackError.style.display = 'block';
                    feedbackError.innerHTML = '⚠ ' + data.data.message;
                    btn.disabled = false;
                    btn.innerHTML = '<span>Baixar Material Grátis</span>';
                }
            })
            .catch(error => {
                feedbackError.style.display = 'block';
                feedbackError.innerHTML = '⚠ Falha na conexão. Tente novamente.';
                btn.disabled = false;
                btn.innerHTML = '<span>Baixar Material Grátis</span>';
            });
        });
        
        // Remove os avisos de erro em tempo real ao corrigir os campos
        empresaInput.addEventListener('input', function() {
            this.classList.remove('uonix-input-error');
            document.getElementById('help_empresa').classList.remove('active');
        });

        emailInput.addEventListener('input', function() {
            this.classList.remove('uonix-input-error');
            document.getElementById('help_email').classList.remove('active');
        });
        
        termoInput.addEventListener('change', function() {
            boxTermo.classList.remove('uonix-error-box');
            labelTermo.classList.remove('uonix-label-error');
            document.getElementById('help_termo').classList.remove('active');
        });
    });
    </script>
    <?php return ob_get_clean();
}

// ==============================================================================
// 2. BACKEND: MOTOR AJAX (Integração com Fluent Forms)
// ==============================================================================
add_action('wp_ajax_uonix_processar_lead_customizado', 'uonix_processar_lead_customizado_handler');
add_action('wp_ajax_nopriv_uonix_processar_lead_customizado', 'uonix_processar_lead_customizado_handler');

function uonix_processar_lead_customizado_handler() {
    if (!function_exists('wpFluentForm')) {
        wp_send_json_error(['message' => 'Sistema temporariamente indisponível.']);
    }

    $nome     = uonix_form_captura_upper_text(sanitize_text_field($_POST['nome'] ?? ''));
    $empresa  = uonix_form_captura_upper_text(sanitize_text_field($_POST['empresa'] ?? ''));
    $email    = strtolower(sanitize_email($_POST['email'] ?? ''));
    $telefone = uonix_form_captura_format_phone(sanitize_text_field($_POST['telefone'] ?? ''));
    $opt_in   = isset($_POST['newsletters']) && $_POST['newsletters'] === 'sim';
    $termo    = isset($_POST['termo']) && $_POST['termo'] === 'sim';

    if (empty($empresa)) {
        wp_send_json_error(['message' => 'Empresa é obrigatória.']);
    }
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

        // Form 4 (Leads)
        $submissionHandler->handleSubmission([
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer_path,
            'capturalead_nome'             => $nome,
            'capturalead_email'            => $email,
            'capturalead_telefone'         => $telefone,
            'capturalead_empresa'          => $empresa,
            'capturalead_newsletters'      => $opt_in ? 'SIM' : 'NAO',
            'capturalead_origem'           => 'DOWNLOAD CHECKLIST ANCORAGEM'
        ], 4);

        // Form 2 (Newsletter)
        if ($opt_in) {
            $submissionHandler->handleSubmission([
                '__fluent_form_embded_post_id' => $embedded_post_id,
                '_wp_http_referer'             => $referer_path,
                'newsletters_email'            => $email,
                'newsletters_termo'            => 'on',
                'newsletters_origem'           => 'DOWNLOAD CHECKLIST ANCORAGEM',
            ], 2);
        }

        wp_send_json_success(['message' => 'Download iniciado.']);

    } catch (\Exception $e) {
        error_log('Uônix AJAX Sync Error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Erro interno do servidor. Tente novamente.']);
    }
}

