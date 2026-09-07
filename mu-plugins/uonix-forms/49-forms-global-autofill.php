<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulários - Persistência global e autopreenchimento de dados (First-Party)
 * com conformidade LGPD via AdOpt.
 *
 * Sincroniza dados de contato (Nome, Empresa, E-mail, Telefone, Cidade e Estado) entre:
 * - Formulário de Contato (/#contato - Fluent Forms ID 3)
 * - Checklist Técnico ([uonix_sticky_lead] / [uonix_form_captura])
 * - Trabalhe Conosco (/trabalhe-conosco/#curriculo - [uonix_form_trabalhe])
 * - Comentários do Blog (#commentform)
 * - Finalizar Orçamento (/finalizar-orcamento/ - WooCommerce Checkout)
 * - Newsletter ([uonix_form_newsletter])
 *
 * Em conformidade com a LGPD (Princípio da Minimização e Segurança), documentos fiscais
 * (CPF/CNPJ) e endereço residencial completo NÃO são persistidos no navegador.
 */

add_action('wp_footer', function() {
    if (is_admin()) {
        return;
    }
    ?>
    <script id="uonix-global-autofill-js">
    (function() {
        const STORAGE_KEY = 'uonix_user_lead';
        const COOKIE_KEY = 'uonix_lead_profile';

        // 1. Verificação de Consentimento AdOpt
        function isAdoptRejected() {
            try {
                if (document.cookie.indexOf('_adoptReject=') !== -1) {
                    return true;
                }
                if (window.localStorage && localStorage.getItem('_adoptReject')) {
                    return true;
                }
            } catch (e) {}
            return false;
        }

        function clearSavedData() {
            try {
                if (window.localStorage) {
                    localStorage.removeItem(STORAGE_KEY);
                }
                document.cookie = COOKIE_KEY + '=; path=/; max-age=0; SameSite=Lax';
            } catch (e) {}
        }

        // 2. Leitura dos Dados Salvos
        function getSavedLeadData() {
            if (isAdoptRejected()) {
                clearSavedData();
                return null;
            }

            let data = null;
            try {
                if (window.localStorage) {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (raw) {
                        data = JSON.parse(raw);
                    }
                }
            } catch (e) {}

            if (!data) {
                // Tenta ler do cookie fallback
                const match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE_KEY + '=([^;]*)'));
                if (match && match[1]) {
                    try {
                        data = JSON.parse(decodeURIComponent(match[1]));
                    } catch (e) {}
                }
            }

            return data && typeof data === 'object' ? data : null;
        }

        // 3. Gravação dos Dados (Estritamente dados de contato permitidos pela LGPD)
        function saveLeadData(fields) {
            if (isAdoptRejected() || !fields || typeof fields !== 'object') {
                return;
            }

            const current = getSavedLeadData() || {};
            const merged = {
                nome: (fields.nome || current.nome || '').trim(),
                empresa: (fields.empresa || current.empresa || '').trim(),
                email: (fields.email || current.email || '').trim().toLowerCase(),
                telefone: (fields.telefone || current.telefone || '').trim(),
                cidade: (fields.cidade || current.cidade || '').trim(),
                estado: (fields.estado || current.estado || '').trim(),
                updatedAt: Date.now()
            };

            // Se não tem dados mínimos, não grava
            if (!merged.nome && !merged.email && !merged.telefone && !merged.empresa) {
                return;
            }

            try {
                if (window.localStorage) {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(merged));
                }
                const serialized = encodeURIComponent(JSON.stringify(merged));
                const maxAge = 365 * 24 * 60 * 60; // 1 ano
                const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = COOKIE_KEY + '=' + serialized + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
            } catch (e) {}
        }

        // 4. Injeção nos Campos com disparo de eventos (ativação de Floating Labels e máscaras)
        function setInputValue(input, value) {
            if (!input || !value) return;
            if (input.value && input.value.trim() !== '') return; // Não sobrescreve se já preenchido

            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));

            // Suporte a selects customizados do WooCommerce (Select2 / jQuery)
            if (input.tagName === 'SELECT' && window.jQuery) {
                try {
                    window.jQuery(input).trigger('change');
                } catch (e) {}
            }
        }

        function autofillForms() {
            const data = getSavedLeadData();
            if (!data) return;

            // NOME
            if (data.nome) {
                const nomeInputs = document.querySelectorAll(
                    'form[data-form_id="3"] input[name="form_nome"], #fluentform_3 input[name="form_nome"], #ucf_nome, #trab_nome, #author, #billing_complete_name, #billing_first_name'
                );
                nomeInputs.forEach(input => setInputValue(input, data.nome));
            }

            // EMPRESA
            if (data.empresa) {
                const empresaInputs = document.querySelectorAll(
                    'form[data-form_id="3"] input[name="form_empresa"], #fluentform_3 input[name="form_empresa"], #ucf_empresa, #company, #billing_company'
                );
                empresaInputs.forEach(input => setInputValue(input, data.empresa));
            }

            // E-MAIL
            if (data.email) {
                const emailInputs = document.querySelectorAll(
                    'form[data-form_id="3"] input[name="form_email"], #fluentform_3 input[name="form_email"], #ucf_email, #trab_email, #email, .uonix-news-wrapper input[type="email"], #billing_email'
                );
                emailInputs.forEach(input => setInputValue(input, data.email));
            }

            // TELEFONE
            if (data.telefone) {
                const telInputs = document.querySelectorAll(
                    'form[data-form_id="3"] input[name="form_telefone"], #fluentform_3 input[name="form_telefone"], #ucf_telefone, #trab_tel, #billing_phone'
                );
                telInputs.forEach(input => setInputValue(input, data.telefone));
            }

            // CIDADE
            if (data.cidade) {
                const cityInputs = document.querySelectorAll('#billing_city');
                cityInputs.forEach(input => setInputValue(input, data.cidade));
            }

            // ESTADO
            if (data.estado) {
                const stateInputs = document.querySelectorAll('#billing_state');
                stateInputs.forEach(input => setInputValue(input, data.estado));
            }
        }

        // 5. Captura em Tempo Real ao Submeter qualquer formulário
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || !(form instanceof HTMLFormElement)) return;

            // Se rejeitou AdOpt, garante que comentários não setem consentimento
            if (isAdoptRejected()) {
                clearSavedData();
                const consentInput = form.querySelector('input[name="wp-comment-cookies-consent"]');
                if (consentInput) consentInput.remove();
                return;
            }

            // Extrai dados do formulário submetido (estritamente permitidos pela LGPD)
            const nomeEl = form.querySelector('input[name="form_nome"], input[name="nome"], input[name="author"], input[name="billing_complete_name"], input[name="billing_first_name"]');
            const empresaEl = form.querySelector('input[name="form_empresa"], input[name="empresa"], input[name="company"], input[name="billing_company"]');
            const emailEl = form.querySelector('input[name="form_email"], input[name="email"], input[name="billing_email"], input[type="email"]');
            const telEl = form.querySelector('input[name="form_telefone"], input[name="telefone"], input[name="billing_phone"]');
            const cityEl = form.querySelector('input[name="billing_city"]');
            const stateEl = form.querySelector('select[name="billing_state"], input[name="billing_state"]');

            const payload = {};
            if (nomeEl && nomeEl.value) payload.nome = nomeEl.value;
            if (empresaEl && empresaEl.value) payload.empresa = empresaEl.value;
            if (emailEl && emailEl.value) payload.email = emailEl.value;
            if (telEl && telEl.value) payload.telefone = telEl.value;
            if (cityEl && cityEl.value) payload.cidade = cityEl.value;
            if (stateEl && stateEl.value) payload.estado = stateEl.value;

            // Se for comentário do blog, injeta o consentimento nativo se AdOpt não rejeitou
            if (form.id === 'commentform' || form.classList.contains('comment-form')) {
                let consentInput = form.querySelector('input[name="wp-comment-cookies-consent"]');
                if (!consentInput) {
                    consentInput = document.createElement('input');
                    consentInput.type = 'hidden';
                    consentInput.name = 'wp-comment-cookies-consent';
                    consentInput.value = 'yes';
                    form.appendChild(consentInput);
                }
            }

            saveLeadData(payload);
        }, true);

        // 6. Monitora cliques de rejeição do AdOpt
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('button, a');
            if (btn && btn.textContent && /rejeitar|recusar/i.test(btn.textContent)) {
                clearSavedData();
            }
        });

        // 7. Dispara preenchimento na inicialização e quando o modal técnico abrir
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', autofillForms);
        } else {
            autofillForms();
        }

        window.addEventListener('load', autofillForms);

        // Observa abertura do modal de checklist técnico (#uonix-sticky-modal)
        const observer = new MutationObserver(function(mutations) {
            for (let i = 0; i < mutations.length; i++) {
                const target = mutations[i].target;
                if (target && target.id === 'uonix-sticky-modal' && target.classList.contains('active')) {
                    autofillForms();
                    break;
                }
            }
        });
        observer.observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['class'] });

        // Expõe globalmente
        window.uonixSaveLeadData = saveLeadData;
        window.uonixGetSavedLeadData = getSavedLeadData;
        window.uonixAutofillForms = autofillForms;
    })();
    </script>
    <?php
}, 99);
