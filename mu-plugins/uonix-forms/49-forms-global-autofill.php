<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulários - Persistência global e autopreenchimento de dados (First-Party)
 * com conformidade LGPD via AdOpt (Fail-Closed).
 *
 * Sincroniza dados de contato (Nome, Empresa, E-mail, Telefone, Cidade e Estado) entre:
 * - Formulário de Contato (/#contato - Fluent Forms ID 3)
 * - Checklist Técnico ([uonix_sticky_lead] / [uonix_form_captura])
 * - Trabalhe Conosco (/trabalhe-conosco/#curriculo - [uonix_form_trabalhe])
 * - Comentários do Blog (#commentform)
 * - Finalizar Orçamento (/finalizar-orcamento/ - WooCommerce Checkout)
 * - Newsletter ([uonix_form_newsletter])
 *
 * Em conformidade com a LGPD (Princípio da Minimização e Segurança):
 * 1. Documentos fiscais (CPF/CNPJ) e endereço residencial completo NÃO são persistidos no navegador.
 * 2. Política Fail-Closed: sem evidência positiva e explícita de consentimento no AdOpt,
 *    nenhum dado é salvo no navegador e nenhum cookie de comentário é autorizado.
 */

if ( ! defined( 'UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO' ) ) {
    /**
     * Tag da AdOpt que autoriza a persistência: `uonix.com.br`, na categoria Funcional.
     *
     * É ela que declara `uonix_lead_profile`, `uonix_consent_granted` e `_adoptReject` —
     * exatamente o armazenamento que este módulo usa.
     *
     * Fica embutido, e não em `wp-config.php`, por três razões medidas em 2026-09-23:
     * o ID não é segredo (a AdOpt o serve na configuração pública de qualquer visitante),
     * existe uma única conta AdOpt, e a ausência silenciosa da constante manteve este
     * módulo inativo em produção por meses sem que nada reclamasse (issue #264).
     *
     * A constante `UONIX_ADOPT_CONSENT_TAG_IDS` continua tendo precedência, para permitir
     * rotação emergencial por `wp-config.php` sem depender de um deploy.
     */
    define( 'UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO', '9BxuTvI1_q' );
}

if ( ! function_exists( 'uonix_adopt_get_consent_tag_ids' ) ) {
    /**
     * Obtém e valida a lista de IDs de tags da AdOpt que autorizam a persistência de formulários.
     *
     * A AdOpt entrega em optInTags/optOutTags o `id` de cada tag, que é um identificador
     * curto de 10 caracteres no alfabeto [A-Za-z0-9_-] (ex: `9BxuTvI1_q`) — e NÃO um UUID.
     * Medido em 2026-09-23 na configuração pública da conta: as cinco tags têm 10 caracteres.
     * O mínimo histórico de 12 rejeitava todas elas, o que mantinha o módulo inativo (#264).
     *
     * Nomes textuais de categoria (ex: 'funcional', 'preferences') NÃO são IDs. Como quatro
     * deles têm 10 caracteres ou mais, o comprimento não os separa: quem os rejeita é a lista
     * explícita abaixo, que por isso precisa ser verificada nome a nome em teste.
     *
     * Se nenhum ID válido restar, o sistema opera estritamente fail-closed.
     *
     * @return array
     */
    function uonix_adopt_get_consent_tag_ids() {
        $raw_ids = defined( 'UONIX_ADOPT_CONSENT_TAG_IDS' )
            ? UONIX_ADOPT_CONSENT_TAG_IDS
            : UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO;
        if ( is_string( $raw_ids ) ) {
            $raw_ids = array_map( 'trim', explode( ',', $raw_ids ) );
        } elseif ( ! is_array( $raw_ids ) ) {
            $raw_ids = array();
        }

        $raw_ids = apply_filters( 'uonix_adopt_consent_tag_ids', $raw_ids );
        if ( ! is_array( $raw_ids ) ) {
            return array();
        }

        $valid_ids = array();
        foreach ( $raw_ids as $id ) {
            $id = trim( (string) $id );
            if ( '' === $id ) {
                continue;
            }

            // Descarta explicitamente nomes de categoria genéricos.
            // Esta é a defesa real contra nomes de categoria: 'functional' (10), 'necessario' (10),
            // 'preferences' (11) e 'uonix_cookies' (13) passariam pelo teste de comprimento abaixo.
            if ( in_array( strtolower( $id ), array( 'funcional', 'preferences', 'functional', 'uonix_cookies', 'marketing', 'analytics', 'necessario', 'essential' ), true ) ) {
                continue;
            }

            // Exige formato de ID de tag realista: UUID de 36 caracteres (usado por outras contas
            // AdOpt) ou o identificador curto desta conta, de 10 caracteres ou mais.
            if ( preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id ) || preg_match( '/^[a-zA-Z0-9_-]{10,}$/', $id ) ) {
                $valid_ids[] = strtolower( $id );
            }
        }

        return array_values( array_unique( $valid_ids ) );
    }
}

if ( ! function_exists( 'uonix_autofill_registra_adopt_cb_antecipado' ) ) {
    /**
     * Registra `window.adoptCB` antes do GTM, para que a AdOpt não capture um no-op.
     *
     * A AdOpt lê o callback UMA vez, na montagem do componente:
     *
     *     barCallback: window?.top?.adoptCB ?? function(e){}
     *
     * O `??` é avaliado naquele instante. Como a AdOpt é injetada pelo container do GTM
     * (`wp_head` prioridade 1) e este módulo escreve no rodapé, existe uma corrida: se a
     * AdOpt montar primeiro, ela guarda o no-op em definitivo e o consentimento nunca
     * chega ao avaliador — o autopreenchimento ficaria morto de forma intermitente.
     *
     * Este stub roda na prioridade 0, antes do GTM, e apenas guarda o payload. O rodapé
     * publica `window.__uonixAvaliaConsentAdopt` e consome o que já tiver chegado, então a
     * ordem deixa de importar nos dois sentidos.
     */
    function uonix_autofill_registra_adopt_cb_antecipado() {
        if ( is_admin() ) {
            return;
        }
        ?>
        <script id="uonix-autofill-adopt-cb-antecipado">
        (function() {
            var anterior = window.adoptCB;
            window.__uonixAdoptConsentPendente = window.__uonixAdoptConsentPendente || null;
            window.adoptCB = function(consent) {
                // Preserva qualquer outro consumidor do global.
                if (typeof anterior === 'function') {
                    try { anterior(consent); } catch (e) {}
                }
                window.__uonixAdoptConsentPendente = consent;
                if (typeof window.__uonixAvaliaConsentAdopt === 'function') {
                    try { window.__uonixAvaliaConsentAdopt(consent); } catch (e) {}
                }
            };
            window.__uonixAdoptCBRegistrado = true;
        })();
        </script>
        <?php
    }
}

add_action( 'wp_head', 'uonix_autofill_registra_adopt_cb_antecipado', 0, 0 );

add_action('wp_footer', function() {
    if (is_admin()) {
        return;
    }

    $allowed_tag_ids = uonix_adopt_get_consent_tag_ids();
    ?>
    <script id="uonix-global-autofill-js">
    (function() {
        const STORAGE_KEY = 'uonix_user_lead';
        const COOKIE_KEY = 'uonix_lead_profile';
        const CONSENT_COOKIE = 'uonix_consent_granted';
        const ALLOWED_TAG_IDS = <?php echo json_encode( $allowed_tag_ids ); ?>;

        // 1. Verificação Positiva de Consentimento AdOpt (Fail-Closed)
        function isAdoptConsentGranted() {
            try {
                // Se não houver IDs válidos configurados no ambiente, nega (fail-closed)
                if (!Array.isArray(ALLOWED_TAG_IDS) || ALLOWED_TAG_IDS.length === 0) {
                    return false;
                }

                // Bloqueio absoluto se houver sinal de recusa em cookie ou localStorage
                if (document.cookie.indexOf('_adoptReject=') !== -1) {
                    return false;
                }
                if (window.localStorage && localStorage.getItem('_adoptReject')) {
                    return false;
                }

                // Evidência positiva First-Party gerada exclusivamente após validação dos IDs autorizados
                // no callback oficial da AdOpt (window.adoptCB).
                // O cookie AdoptConsent NUNCA é aceito como autorização pois apenas registra preferências.
                const hasCookieConsent = document.cookie.indexOf(CONSENT_COOKIE + '=1') !== -1;
                const hasStorageConsent = !!(window.localStorage && localStorage.getItem(CONSENT_COOKIE) === '1');

                if (hasCookieConsent || hasStorageConsent) {
                    return true;
                }
            } catch (e) {}

            // Padrão seguro fail-closed: silêncio ou ausência de ação NÃO é consentimento
            return false;
        }

        function markConsentGranted() {
            try {
                if (window.localStorage) {
                    localStorage.setItem(CONSENT_COOKIE, '1');
                    localStorage.removeItem('_adoptReject');
                }
                const maxAge = 365 * 24 * 60 * 60;
                const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = CONSENT_COOKIE + '=1; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
                document.cookie = '_adoptReject=; path=/; max-age=0; SameSite=Lax';
            } catch (e) {}
        }

        function markConsentRejected() {
            try {
                if (window.localStorage) {
                    localStorage.setItem('_adoptReject', '1');
                    localStorage.removeItem(CONSENT_COOKIE);
                }
                const maxAge = 365 * 24 * 60 * 60;
                const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = '_adoptReject=1; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
                document.cookie = CONSENT_COOKIE + '=; path=/; max-age=0; SameSite=Lax';
            } catch (e) {}
            clearSavedData();
        }

        function clearSavedData() {
            try {
                if (window.localStorage) {
                    localStorage.removeItem(STORAGE_KEY);
                    localStorage.removeItem(CONSENT_COOKIE);
                }
                document.cookie = COOKIE_KEY + '=; path=/; max-age=0; SameSite=Lax';
                document.cookie = CONSENT_COOKIE + '=; path=/; max-age=0; SameSite=Lax';

                // Garante que inputs de consentimento sejam removidos do DOM
                const consentInputs = document.querySelectorAll('input[name="wp-comment-cookies-consent"]');
                consentInputs.forEach(el => el.remove());
            } catch (e) {}
        }

        // 2. Leitura dos Dados Salvos
        function getSavedLeadData() {
            if (!isAdoptConsentGranted()) {
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
            if (!isAdoptConsentGranted() || !fields || typeof fields !== 'object') {
                return;
            }

            // Sincroniza cookie de consentimento para o servidor
            markConsentGranted();

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
            if (!isAdoptConsentGranted()) return;

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

            // Fail-closed: se NÃO há consentimento positivo do AdOpt, garante que comentários
            // não setem consentimento e não salva os dados do lead
            if (!isAdoptConsentGranted()) {
                clearSavedData();
                const consentInput = form.querySelector('input[name="wp-comment-cookies-consent"]');
                if (consentInput) consentInput.remove();
                return;
            }

            // Garante que o cookie de consentimento positivo esteja ativo no envio
            markConsentGranted();

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

            // Se for comentário do blog E temos consentimento positivo garantido, injeta consentimento
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

        // 6. Integração oficial com a API da AdOpt via window.adoptCB (optInTags / optOutTags)
        function evaluateAdoptConsent(consent) {
            if (!consent || typeof consent !== 'object' || !Array.isArray(ALLOWED_TAG_IDS) || ALLOWED_TAG_IDS.length === 0) {
                markConsentRejected();
                return false;
            }

            const optIn = Array.isArray(consent.optInTags) ? consent.optInTags.map(t => String(t).toLowerCase()) : [];
            const optOut = Array.isArray(consent.optOutTags) ? consent.optOutTags.map(t => String(t).toLowerCase()) : [];

            // Se qualquer ID de tag autorizada foi explicitamente rejeitado no optOutTags -> REJEITA
            const isExplicitOptOut = optOut.some(id => ALLOWED_TAG_IDS.includes(id));
            if (isExplicitOptOut) {
                markConsentRejected();
                return false;
            }

            // Exige que ao menos um ID de tag autorizada esteja presente no optInTags
            const isExplicitOptIn = optIn.some(id => ALLOWED_TAG_IDS.includes(id));
            if (isExplicitOptIn) {
                markConsentGranted();
                autofillForms();
                return true;
            }

            // Fail-closed: se nenhum ID autorizado foi concedido, nega
            markConsentRejected();
            return false;
        }

        // Publica o avaliador para o stub registrado em wp_head (antes do GTM).
        window.__uonixAvaliaConsentAdopt = evaluateAdoptConsent;

        // Rede de segurança: se o stub de wp_head não rodou, registra aqui, encadeando.
        if (!window.__uonixAdoptCBRegistrado) {
            const previousAdoptCB = window.adoptCB;
            window.adoptCB = function(consent) {
                if (typeof previousAdoptCB === 'function') {
                    try { previousAdoptCB(consent); } catch (e) {}
                }
                evaluateAdoptConsent(consent);
            };
            window.__uonixAdoptCBRegistrado = true;
        }

        // Consome um consentimento que a AdOpt já tenha entregue antes deste script rodar.
        if (window.__uonixAdoptConsentPendente) {
            evaluateAdoptConsent(window.__uonixAdoptConsentPendente);
        }

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
        window.uonixIsAdoptConsentGranted = isAdoptConsentGranted;
        window.uonixEvaluateAdoptConsent = evaluateAdoptConsent;
        window.uonixMarkConsentGranted = markConsentGranted;
        window.uonixMarkConsentRejected = markConsentRejected;
    })();
    </script>
    <?php
}, 99);
