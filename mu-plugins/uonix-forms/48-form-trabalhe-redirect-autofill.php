<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - redirecionamento e autopreenchimento Trabalhe Conosco.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 18762-19159 do export original.
// -----------------------------------------------------------------------------
/**
 * Redirecionamento opcional para Trabalhe Conosco
 */
/**
 * UÔNIX: Redirecionamento opcional para Trabalhe Conosco com reaproveitamento de dados
 * - Monitora o select Assunto do Fluent Forms ID 3
 * - Se escolher "Trabalhe conosco", abre popup
 * - Sim: salva dados temporariamente e redireciona para /trabalhe-conosco/#curriculo
 * - Na página Trabalhe Conosco, preenche automaticamente nome, e-mail, telefone, mensagem e newsletter
 * - Não: permanece na página e reseta o select para "Selecione o assunto de interesse"
 */

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }

    $url_trabalhe = '/trabalhe-conosco/#curriculo';
    ?>
    <style>
        .uonix-rh-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.62);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .uonix-rh-modal-overlay.is-active {
            display: flex;
        }

        .uonix-rh-modal {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
            text-align: center;
            animation: uonixRhModalIn 0.22s ease forwards;
        }

        .uonix-rh-modal-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e0f2fe;
            color: #0e3780;
            font-size: 26px;
            font-weight: 800;
        }

        .uonix-rh-modal h3 {
            margin: 0 0 10px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 800;
            line-height: 1.25;
        }

        .uonix-rh-modal p {
            margin: 0;
            color: #475569;
            font-size: 15px;
            line-height: 1.55;
        }

        .uonix-rh-modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .uonix-rh-modal-btn {
            flex: 1;
            min-height: 46px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .uonix-rh-modal-btn-secondary {
            background: #ffffff;
            color: #0e3780 !important;
        }

        .uonix-rh-modal-btn-secondary:hover,
        .uonix-rh-modal-btn-secondary:focus,
        .uonix-rh-modal-btn-secondary:active {
            background: #f8fafc;
            color: #0e3780 !important;
            border-color: #94a3b8;
        }

        .uonix-rh-modal-btn-primary {
            background: #f76a0c;
            border-color: #f76a0c;
            color: #ffffff !important;
        }

        .uonix-rh-modal-btn-primary:hover,
        .uonix-rh-modal-btn-primary:focus,
        .uonix-rh-modal-btn-primary:active {
            background: #e05e07;
            border-color: #e05e07;
            color: #ffffff !important;
        }

        @keyframes uonixRhModalIn {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @media (max-width: 480px) {
            .uonix-rh-modal {
                padding: 24px 20px;
            }

            .uonix-rh-modal-actions {
                flex-direction: column-reverse;
            }
        }
    </style>

    <div class="uonix-rh-modal-overlay" id="uonixRhModal" aria-hidden="true">
        <div class="uonix-rh-modal" role="dialog" aria-modal="true" aria-labelledby="uonixRhModalTitle">
            <div class="uonix-rh-modal-icon">?</div>

            <h3 id="uonixRhModalTitle">
                Ir para o Formulário de Recrutamento?
            </h3>

            <p>
                Para enviar seu currículo, recomendamos acessar a página específica de Trabalhe Conosco.
            </p>

            <div class="uonix-rh-modal-actions">
                <button type="button" class="uonix-rh-modal-btn uonix-rh-modal-btn-secondary" id="uonixRhModalNo">
                    Não, permanecer aqui
                </button>

                <button type="button" class="uonix-rh-modal-btn uonix-rh-modal-btn-primary" id="uonixRhModalYes">
                    Sim, ir para o formulário
                </button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const targetUrl = <?php echo wp_json_encode($url_trabalhe); ?>;
            const storageKey = 'uonix_trabalhe_pre_fill';

            const modal = document.getElementById('uonixRhModal');
            const btnYes = document.getElementById('uonixRhModalYes');
            const btnNo = document.getElementById('uonixRhModalNo');

            let currentSelect = null;
            let currentForm = null;
            let isResetting = false;

            function getFieldValue(form, selector) {
                if (!form) return '';

                const field = form.querySelector(selector);

                if (!field) return '';

                if (field.type === 'checkbox') {
                    return field.checked ? field.value : '';
                }

                return field.value ? field.value.trim() : '';
            }

            function saveContactFormData(form) {
                if (!form) return;

                const newsletterField = form.querySelector('input[name="form_newsletters[]"], input[name="form_newsletters"]');

                const payload = {
                    nome: getFieldValue(form, 'input[name="form_nome"]'),
                    email: getFieldValue(form, 'input[name="form_email"]'),
                    telefone: getFieldValue(form, 'input[name="form_telefone"]'),
                    mensagem: getFieldValue(form, 'textarea[name="form_mensagem"]'),
                    newsletter: newsletterField && newsletterField.checked ? 'sim' : '',
                    savedAt: Date.now()
                };

                try {
                    sessionStorage.setItem(storageKey, JSON.stringify(payload));
                } catch (error) {
                    console.warn('UÔNIX: não foi possível salvar os dados temporários.', error);
                }
            }

            function getSavedContactFormData() {
                try {
                    const raw = sessionStorage.getItem(storageKey);

                    if (!raw) return null;

                    const data = JSON.parse(raw);

                    if (!data || !data.savedAt) return null;

                    const maxAge = 30 * 60 * 1000;
                    const expired = Date.now() - data.savedAt > maxAge;

                    if (expired) {
                        sessionStorage.removeItem(storageKey);
                        return null;
                    }

                    return data;
                } catch (error) {
                    return null;
                }
            }

            function fillField(selector, value) {
                if (!value) return;

                const field = document.querySelector(selector);

                if (!field) return;

                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function fillCheckbox(selector, shouldCheck) {
                const field = document.querySelector(selector);

                if (!field) return;

                field.checked = !!shouldCheck;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function autofillTrabalheConoscoForm() {
                const trabalheForm = document.getElementById('uonix-custom-trab-form');

                if (!trabalheForm) return;

                const data = getSavedContactFormData();

                if (!data) return;

                fillField('#trab_nome', data.nome);
                fillField('#trab_email', data.email);
                fillField('#trab_tel', data.telefone);
                fillField('#trab_msg', data.mensagem);

                if (data.newsletter === 'sim') {
                    fillCheckbox('#trab_news', true);
                }

                sessionStorage.removeItem(storageKey);
            }

            function resetSelect(select) {
                if (!select) return;

                isResetting = true;

                select.value = '';

                if (select.selectedIndex !== 0) {
                    select.selectedIndex = 0;
                }

                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));

                setTimeout(function () {
                    isResetting = false;
                }, 50);
            }

            function openModal(select) {
                currentSelect = select;
                currentForm = select.closest('form');

                modal.classList.add('is-active');
                modal.setAttribute('aria-hidden', 'false');

                setTimeout(function () {
                    btnYes.focus();
                }, 50);
            }

            function closeModal() {
                modal.classList.remove('is-active');
                modal.setAttribute('aria-hidden', 'true');
            }

            function handleSubjectChange(event) {
                const select = event.target;

                if (isResetting) return;

                const selectedValue = select.value;
                const selectedText = select.options[select.selectedIndex]
                    ? select.options[select.selectedIndex].text.trim().toLowerCase()
                    : '';

                const isTrabalheConosco =
                    selectedValue === 'rh' ||
                    selectedText === 'trabalhe conosco';

                if (isTrabalheConosco) {
                    openModal(select);
                }
            }

            function bindSelects() {
                const selects = document.querySelectorAll(
                    'form[data-form_id="3"] select[name="form_assunto"], #fluentform_3 select[name="form_assunto"], select[name="form_assunto"]'
                );

                selects.forEach(function (select) {
                    if (select.dataset.uonixRhBound === '1') return;

                    select.dataset.uonixRhBound = '1';
                    select.addEventListener('change', handleSubjectChange);
                });
            }

            btnYes.addEventListener('click', function () {
                saveContactFormData(currentForm);
                window.location.href = targetUrl;
            });

            btnNo.addEventListener('click', function () {
                closeModal();
                resetSelect(currentSelect);
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                    resetSelect(currentSelect);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal.classList.contains('is-active')) {
                    closeModal();
                    resetSelect(currentSelect);
                }
            });

            document.addEventListener('DOMContentLoaded', function () {
                bindSelects();
                autofillTrabalheConoscoForm();
            });

            window.addEventListener('load', function () {
                bindSelects();
                autofillTrabalheConoscoForm();
            });

            const observer = new MutationObserver(function () {
                bindSelects();
            });

            observer.observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        })();
    </script>
    <?php
}, 99);

