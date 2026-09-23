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
     * Nomes textuais de categoria (ex: 'Statistics', 'Desempenho') NÃO são IDs, e nenhuma regra
     * de formato os separa com segurança: uma palavra de 10 caracteres é indistinguível de um
     * token aleatório de 10 caracteres. A informação não está na string.
     *
     * O que torna isso aceitável é a consequência: a AdOpt só entrega `id` de tag em
     * optInTags/optOutTags, nunca nome de categoria, então um valor indevido aqui jamais casa e
     * o módulo simplesmente não arma. O erro cai para o lado seguro. Esta validação é, portanto,
     * um **detector de engano humano**, não uma fronteira de segurança.
     *
     * O engano plausível é colar um rótulo do painel, e esse conjunto é pequeno e enumerável —
     * cinco categorias em dois idiomas.
     *
     * O que esta validação NÃO faz, e não pode: separar palavra arbitrária de token. `Habilitado`
     * tem exatamente o mesmo formato de `Lc-8ztRDYp` — 10 caracteres com maiúscula. Aceitar isso
     * é consequência de a informação não existir na string, e é inofensivo pelo motivo acima.
     *
     * Daí as duas barreiras, nesta ordem de importância:
     *
     *   1. Lista explícita de rótulos conhecidos, comparada em minúsculas (o painel os exibe
     *      capitalizados: `Statistics`, `Desempenho`).
     *   2. Comprimento exato de 10 caracteres, medido nas cinco tags da conta em 2026-09-23.
     *      Isso elimina de uma vez a família de strings inventadas mais longas
     *      (`uonix_funcional`, `preferences_v2`, `Estatisticas`, `Preferencias`).
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

            // Primeira barreira, e a principal: rótulos do painel da AdOpt.
            //
            // A comparação é em minúsculas porque o painel os exibe CAPITALIZADOS — foi
            // exatamente essa dimensão que uma versão anterior deste guard ignorou, deixando
            // `Statistics` e `Performance` passarem enquanto `statistics` caía.
            //
            // O conjunto é o namespace real da conta, em inglês (`Required`, `Marketing`,
            // `Statistics`, `Performance`, `Functional`) e nos rótulos em português que o banner
            // renderiza, mais variações de grafia sem acento. Entradas mais curtas ou mais longas
            // que 10 caracteres já morreriam no comprimento; ficam aqui como defesa em
            // profundidade, para o caso de o comprimento ser afrouxado no futuro.
            $rotulos_de_categoria = array(
                // inglês
                'required', 'marketing', 'statistics', 'performance', 'functional',
                'necessary', 'essential', 'preferences', 'analytics',
                // português
                'necessario', 'necessarios', 'necessarias', 'estatisticas', 'funcional',
                'funcionais', 'desempenho', 'preferencias', 'publicidade', 'essenciais',
                'analiticos',
                // nome interno herdado
                'uonix_cookies',
            );
            if ( in_array( strtolower( $id ), $rotulos_de_categoria, true ) ) {
                continue;
            }

            // Segunda barreira: comprimento. UUID de 36 caracteres, formato usado por outras
            // contas AdOpt, ou o identificador curto desta conta, de EXATAMENTE 10 caracteres em
            // [A-Za-z0-9_-]. Os cinco IDs da conta foram medidos em 2026-09-23 e todos têm 10.
            //
            // A exatidão é o que elimina, sem enumerar, toda a família de strings inventadas de
            // outro tamanho: 'uonix_funcional' (15), 'preferences_v2' (14), 'Estatisticas' (12),
            // 'Preferencias' (12), 'Necessarios' (11), 'Performance' (11), 'funcional_1' (11).
            //
            // O que ela NÃO faz é separar palavra de token: 'Statistics' e 'Desempenho' também
            // têm 10 caracteres. Para esses, a barreira é a lista de rótulos acima — e é por isso
            // que ela vem primeiro e é a principal.
            //
            // Custo assumido: se a AdOpt passar a emitir identificador de outro comprimento, o
            // módulo fica inativo até alguém ajustar aqui. É o lado seguro do erro, e o teste com
            // os IDs reais falha alto se o comprimento medido deixar de valer.
            $uuid_valido  = (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id );
            $curto_valido = (bool) preg_match( '/^[a-zA-Z0-9_-]{10}$/', $id );

            if ( $uuid_valido || $curto_valido ) {
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
            // Marca a IDENTIDADE do stub, não apenas "alguém registrou". Se um terceiro
            // sobrescrever window.adoptCB depois daqui, o rodapé precisa detectar e voltar a
            // registrar encadeando — senão o autopreenchimento morreria em silêncio.
            window.adoptCB.__uonixStub = true;
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

        // Rede de segurança: registra aqui se o stub de wp_head não rodou OU se um terceiro
        // sobrescreveu window.adoptCB depois dele. Testar a identidade do stub, e não um
        // booleano "alguém registrou", é o que impede a morte silenciosa nesse segundo caso.
        const cbAtual = window.adoptCB;
        const stubIntacto = typeof cbAtual === 'function' && true === cbAtual.__uonixStub;
        if (!stubIntacto) {
            window.adoptCB = function(consent) {
                if (typeof cbAtual === 'function') {
                    try { cbAtual(consent); } catch (e) {}
                }
                evaluateAdoptConsent(consent);
            };
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
