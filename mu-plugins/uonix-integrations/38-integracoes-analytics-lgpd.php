<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Integracoes - GTM e AdOpt LGPD.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 13864-14300 do export original.
// -----------------------------------------------------------------------------
/**
 * Integração (GTM + AdOpt LGPD)
 */
/**
 * UÔNIX: Integração Master (GTM + AdOpt LGPD)
 * ---------------------------------------------------------
 * - Ordem de carregamento: AdOpt (Consentimento) -> GTM (Tags).
 * - O Site Kit pode assumir a emissão do GTM; o AdOpt continua neste MU-plugin.
 * - AdOpt via código; GTM próprio apenas como fallback ao Site Kit.
 * - Suporte nativo ao Google Consent Mode v2.
 * - Banner AdOpt movido para um container controlado para blindar CSS.
 * - UI Enterprise (Design System Uônix + Flexbox Grid).
 */

if ( ! function_exists( 'uonix_site_kit_injeta_medicao' ) ) {
    /**
     * O Google Site Kit está injetando GA4/GTM por conta própria?
     *
     * POR QUE ISSO EXISTE
     *
     * Este arquivo injeta o container GTM apenas como fallback. Quando o módulo Tag
     * Manager do Site Kit está ativo, ele passa a ser o responsável pelo container e
     * este MU-plugin preserva somente a camada AdOpt/LGPD. Se Analytics ou Ads do Site
     * Kit emitirem tags diretamente ao mesmo tempo que o GTM, o GA4 pode chegar por dois
     * caminhos e tudo conta em dobro: pageviews, conversões e taxa de rejeição ficam
     * contaminados silenciosamente.
     *
     * Registro histórico de 2026-08-16: o Site Kit estava ativo com `useSnippet = true`
     * nas três options (analytics-4, tagmanager, adsense), mas sem IDs. Esse registro não
     * representa o estado atual e não substitui a leitura das options antes de um corte.
     *
     * Esta função identifica quem está emitindo medição para que o renderer nunca duplique
     * o container; a decisão preserva o AdOpt quando a origem for o módulo Tag Manager.
     *
     * `useSnippet = true` é o DEFAULT do plugin, não uma escolha — por isso ele sozinho
     * não indica nada. O que importa é ter useSnippet TRUE **e** um ID preenchido.
     *
     * @param array|null  $options                     Options do Site Kit (injetável para teste).
     * @param string|null $expected_gtm_container_id   Container que a aplicação configurou.
     * @return string '' quando não injeta; nome do módulo quando injeta; tagmanager-mismatch
     *                quando o Site Kit injeta um container diferente do auditado.
     */
    function uonix_site_kit_injeta_medicao( $options = null, $expected_gtm_container_id = null ) {
        $expected_gtm_container_id = null === $expected_gtm_container_id
            ? null
            : trim( (string) $expected_gtm_container_id );
        /*
         * Módulo => campos cuja presença significa "tem tag para emitir".
         *
         * Lista derivada dos Tag_Guard REAIS do pacote Site Kit 1.186.0 fornecido para
         * esta migração, verificada arquivo por arquivo — não de memória:
         *
         *   Analytics_4/Tag_Guard.php:33
         *     return ! empty( $settings['useSnippet'] ) && ! empty( $settings['measurementID'] );
         *
         *   Tag_Manager/Tag_Guard.php:55
         *     $container_id = $this->is_amp ? $settings['ampContainerID'] : $settings['containerID'];
         *
         * `ampContainerID` é o campo consultado quando a requisição é AMP. Hoje não há
         * plugin AMP instalado, então é inalcançável — mas esta guarda existe justamente
         * para o cenário "alguém reinstala/instala depois".
         *
         * `googleTagID` não entra: embora seja usado na composição posterior da Google tag,
         * o Tag_Guard exige `measurementID` antes de registrar qualquer tag. Tratá-lo
         * sozinho como injeção desligaria o fallback GTM por falso positivo.
         *
         * NÃO inclua `gtagContainerID`: verificado, tem ZERO ocorrências em includes/ do
         * plugin. Era campo inventado. `webDataStreamID` também saiu: existe em Settings
         * mas NÃO participa de nenhuma decisão de emitir tag (nem no Tag_Guard nem no
         * Web_Tag) — cobri-lo dava falsa sensação de completude.
         */
        $modulos = array(
            'analytics-4' => array( 'measurementID' ),
            'tagmanager'  => array( 'containerID', 'ampContainerID' ),
        );

        $tagmanager_detected = false;
        $tagmanager_mismatch = false;

        foreach ( $modulos as $modulo => $campos_id ) {
            $chave = 'googlesitekit_' . $modulo . '_settings';

            if ( null !== $options ) {
                $settings = isset( $options[ $chave ] ) ? $options[ $chave ] : null;
            } else {
                $settings = function_exists( 'get_option' ) ? get_option( $chave ) : null;
            }

            if ( ! is_array( $settings ) ) {
                continue;
            }

            // Sem useSnippet o Site Kit não coloca tag no site, só lê dados.
            if ( empty( $settings['useSnippet'] ) ) {
                continue;
            }

            foreach ( $campos_id as $campo ) {
                $configured_id = isset( $settings[ $campo ] )
                    ? trim( (string) $settings[ $campo ] )
                    : '';

                if ( '' === $configured_id ) {
                    continue;
                }

                if ( 'tagmanager' !== $modulo ) {
                    return $modulo;
                }

                $tagmanager_detected = true;
                if ( null !== $expected_gtm_container_id && $configured_id !== $expected_gtm_container_id ) {
                    $tagmanager_mismatch = true;
                }
            }
        }

        if ( $tagmanager_detected ) {
            return $tagmanager_mismatch ? 'tagmanager-mismatch' : 'tagmanager';
        }

        /*
         * O módulo Ads é tratado SEPARADAMENTE porque NÃO tem gate de `useSnippet`.
         *
         *   Ads.php:337 register_tag() injeta assim que `conversionID` existe — não há
         *   opção de "colocar o snippet no site" para desmarcar.
         *
         * Ele emite gtag.js de conversão (AW-*), que compartilha o mesmo objeto
         * `gtag`/dataLayer do GA4. Se o container GTM também emitir gtag, há dois
         * carregamentos concorrentes do mesmo script — daí o Ads contar, ao contrário do
         * AdSense (que serve anúncio, não medição).
         */
        $chave_ads = 'googlesitekit_ads_settings';

        if ( null !== $options ) {
            $ads = isset( $options[ $chave_ads ] ) ? $options[ $chave_ads ] : null;
        } else {
            $ads = function_exists( 'get_option' ) ? get_option( $chave_ads ) : null;
        }

        if ( is_array( $ads ) ) {
            foreach ( array( 'conversionID', 'paxConversionID' ) as $campo ) {
                if ( ! empty( $ads[ $campo ] ) && '' !== trim( (string) $ads[ $campo ] ) ) {
                    return 'ads';
                }
            }
        }

        return '';
    }
}

if ( ! function_exists( 'uonix_analytics_configuration' ) ) {
    /**
     * Retorna a configuração completa somente para produção explicitamente habilitada.
     *
     * O GA4 é entregue pelo container GTM; não há ID de analytics em QA, DEV ou local.
     *
     * @param string|null $environment      Ambiente explícito ou UONIX_ENV.
     * @param bool|null   $enabled          Flag explícita ou UONIX_ANALYTICS_ENABLED.
     * @param string|null $gtm_container_id ID explícito ou UONIX_GTM_CONTAINER_ID.
     * @param string|null $adopt_website_id ID explícito ou UONIX_ADOPT_WEBSITE_ID.
     * @return array|false
     */
    function uonix_analytics_configuration( $environment = null, $enabled = null, $gtm_container_id = null, $adopt_website_id = null ) {
        $environment = null === $environment && defined( 'UONIX_ENV' ) ? UONIX_ENV : $environment;
        $enabled     = null === $enabled && defined( 'UONIX_ANALYTICS_ENABLED' ) ? UONIX_ANALYTICS_ENABLED : $enabled;

        if ( null === $gtm_container_id && defined( 'UONIX_GTM_CONTAINER_ID' ) ) {
            $gtm_container_id = UONIX_GTM_CONTAINER_ID;
        }

        if ( null === $adopt_website_id && defined( 'UONIX_ADOPT_WEBSITE_ID' ) ) {
            $adopt_website_id = UONIX_ADOPT_WEBSITE_ID;
        }

        $gtm_container_id = trim( (string) $gtm_container_id );
        $adopt_website_id = trim( (string) $adopt_website_id );

        if ( 'production' !== $environment || true !== $enabled || '' === $gtm_container_id || '' === $adopt_website_id ) {
            return false;
        }

        /*
         * O Site Kit deve assumir somente a emissão do container GTM existente. Nesse
         * caso, a configuração continua válida para o AdOpt; o renderer decide, em
         * separado, não duplicar GTM/noscript.
         *
         * Analytics ou Ads emitidos diretamente pelo Site Kit continuam sendo um conflito:
         * compartilham gtag/dataLayer com o GA4 que vive no GTM e poderiam duplicar a
         * coleta. Para esses módulos, mantemos o recuo fail-closed já existente.
         */
        if ( in_array( uonix_site_kit_injeta_medicao( null, $gtm_container_id ), array( 'analytics-4', 'ads', 'tagmanager-mismatch' ), true ) ) {
            return false;
        }

        return array(
            'gtm_container_id' => $gtm_container_id,
            'adopt_website_id' => $adopt_website_id,
        );
    }
}

if ( ! function_exists( 'uonix_analytics_configuration_is_complete' ) ) {
    /**
     * Valida o contrato minimo da config antes de emitir qualquer tag.
     *
     * Fail-closed: exige array com as duas chaves preenchidas. Um array vazio
     * ou parcial NAO habilita injecao parcial.
     *
     * @param mixed $configuration Config candidata.
     * @return bool
     */
    function uonix_analytics_configuration_is_complete( $configuration ) {
        return is_array( $configuration )
            && ! empty( $configuration['gtm_container_id'] )
            && ! empty( $configuration['adopt_website_id'] );
    }
}

if ( ! function_exists( 'uonix_analytics_should_render_gtm' ) ) {
    /**
     * Determina se este MU-plugin ainda é responsável por emitir o container GTM.
     *
     * O Site Kit pode assumir o mesmo container. A presença de qualquer tag de medição
     * emitida pelo Site Kit bloqueia o fallback próprio para nunca haver dois snippets
     * concorrentes. Na rota esperada de Tag Manager, os callbacks mantêm o AdOpt em
     * separado.
     *
     * @param mixed $configuration Configuração candidata.
     * @return bool
     */
    function uonix_analytics_should_render_gtm( $configuration ) {
        return uonix_analytics_configuration_is_complete( $configuration )
            && '' === uonix_site_kit_injeta_medicao( null, $configuration['gtm_container_id'] );
    }
}

if ( ! function_exists( 'uonix_analytics_admin_notice' ) ) {
    /**
     * Avisa sobre configuração incompleta sem exibir qualquer identificador.
     */
    function uonix_analytics_admin_notice() {
        /*
         * Restrito a quem pode agir sobre o aviso. Antes rodava para qualquer usuário
         * logado no admin — um assinante veria a mensagem sem poder fazer nada.
         * Levantado na revisão do PR #110.
         */
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $environment = defined( 'UONIX_ENV' ) ? UONIX_ENV : null;
        $enabled     = defined( 'UONIX_ANALYTICS_ENABLED' ) ? UONIX_ANALYTICS_ENABLED : false;

        if ( 'production' !== $environment || true !== $enabled ) {
            return;
        }

        $modulo       = uonix_site_kit_injeta_medicao();
        $configuration = uonix_analytics_configuration();

        // Caminho esperado da migração: Site Kit emite o container GTM já existente e
        // este MU-plugin preserva exclusivamente o AdOpt/LGPD.
        if ( 'tagmanager' === $modulo && false !== $configuration ) {
            return;
        }

        if ( '' !== $modulo ) {
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html( 'Container GTM suspenso para evitar contagem dupla.' )
                . '</strong> '
                . esc_html( sprintf(
                    'O módulo "%s" do Google Site Kit está injetando medição diretamente. '
                    . 'Para não duplicar pageviews e conversões, o fallback GTM e o AdOpt foram bloqueados de forma fail-closed. '
                    . 'Para preservar GA4 e Meta Pixel no mesmo container, desmarque a colocação de código de Analytics/Ads '
                    . 'e habilite somente o módulo Tag Manager com o container GTM existente.',
                    $modulo
                ) )
                . '</p></div>';
            return;
        }

        if ( false !== $configuration ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html( 'Analytics/consentimento não carregados: configure todos os IDs obrigatórios da produção.' )
            . '</p></div>';
    }
}
add_action( 'admin_notices', 'uonix_analytics_admin_notice' );

// 1. Inserção no Cabeçalho (<head>) - Prioridade Máxima
if ( ! function_exists( 'uonix_render_analytics_head' ) ) {
function uonix_render_analytics_head( $configuration = null ) {
    // Registrado com accepted_args=0, entao do_action() nao passa argumento algum
    // e o default null vale -- a auto-resolucao da config ocorre normalmente.
    $configuration = null === $configuration ? uonix_analytics_configuration() : $configuration;

    if ( is_admin() || ! uonix_analytics_configuration_is_complete( $configuration ) ) return;

    $gtm_container_id = $configuration['gtm_container_id'];
    $adopt_website_id = $configuration['adopt_website_id'];
    $render_gtm       = uonix_analytics_should_render_gtm( $configuration );
    ?>
    
    <meta name="adopt-website-id" content="<?php echo esc_attr( $adopt_website_id ); ?>" />
    <!-- AdOpt: Carregamento e bloqueio de tags centralizados via Google Tag Manager (Tag AdOpt) -->
    <script id="uonix-consent-mode-default">
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function() { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {
        ad_storage: 'denied',
        analytics_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        wait_for_update: 500
    });
    </script>
    <script id="uonix-adopt-categories-bridge">
    (function() {
        function syncCategories() {
            var runtimeConsent = window._adoptConsentOverride;
            var hasExplicitRejectState = typeof window._adoptExplicitlyRejected === 'boolean';
            var consent = null;
            var storageFailed = false;
            var storedReject = false;

            if (!runtimeConsent && !hasExplicitRejectState) {
                try {
                    var raw = localStorage.getItem('adoptConsentMode');
                    consent = raw ? JSON.parse(raw) : null;
                    storedReject = localStorage.getItem('_adoptReject') === '1';
                } catch(e) {
                    storageFailed = true;
                }
            }

            var reject = hasExplicitRejectState
                ? window._adoptExplicitlyRejected
                : storageFailed || storedReject;
            var marketingGranted = false;
            var statisticsGranted = false;

            if (!reject) {
                if (runtimeConsent) {
                    marketingGranted = !!runtimeConsent.marketing;
                    statisticsGranted = !!runtimeConsent.statistics;
                    window._adoptMarketingGranted = marketingGranted;
                    window._adoptStatisticsGranted = statisticsGranted;
                } else if (consent) {
                    marketingGranted = !!(consent.marketing || consent.ad_storage === 'granted');
                    statisticsGranted = !!(consent.statistics || consent.analytics_storage === 'granted');
                    window._adoptMarketingGranted = marketingGranted;
                    window._adoptStatisticsGranted = statisticsGranted;
                } else {
                    if (window._adoptMarketingGranted) marketingGranted = true;
                    if (window._adoptStatisticsGranted) statisticsGranted = true;
                }
            } else {
                window._adoptMarketingGranted = false;
                window._adoptStatisticsGranted = false;
            }

            var currentTags = Array.isArray(window.acceptedTags) ? window.acceptedTags : [];
            var nextTags = [];
            for (var i = 0; i < currentTags.length; i++) {
                var tag = currentTags[i];
                if (tag !== 'marketing' && tag !== 'statistics') {
                    nextTags.push(tag);
                }
            }
            if (marketingGranted) {
                nextTags.push('marketing');
            }
            if (statisticsGranted) {
                nextTags.push('statistics');
            }

            var changed = false;
            if (currentTags.length !== nextTags.length) {
                changed = true;
            } else {
                for (var j = 0; j < nextTags.length; j++) {
                    if (currentTags.indexOf(nextTags[j]) === -1) {
                        changed = true;
                        break;
                    }
                }
            }

            window.acceptedTags = nextTags;

            if (changed) {
                try {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        event: 'adopt_consent_updated',
                        adopt_marketing: marketingGranted,
                        adopt_statistics: statisticsGranted,
                        accepted_tags: nextTags.slice()
                    });
                } catch(e) {}
            }
        }
        syncCategories();
        window.dataLayer = window.dataLayer || [];
        var origPush = window.dataLayer.push;
        window.dataLayer.push = function() {
            var res = origPush.apply(this, arguments);
            for (var i = 0; i < arguments.length; i++) {
                var arg = arguments[i];
                var evtName = (typeof arg === 'string') ? arg : (arg && (arg.event || arg[0]));
                if (evtName === 'adopt-accept-marketing') {
                    var wasRejected = !!window._adoptExplicitlyRejected;
                    try {
                        wasRejected = wasRejected || localStorage.getItem('_adoptReject') === '1';
                    } catch(err) {}
                    window._adoptExplicitlyRejected = false;
                    window._adoptMarketingGranted = true;
                    window._adoptStatisticsGranted = wasRejected ? false : !!window._adoptStatisticsGranted;
                    window._adoptConsentOverride = {
                        marketing: true,
                        statistics: window._adoptStatisticsGranted
                    };
                    try {
                        if (localStorage.getItem('_adoptReject') === '1') localStorage.removeItem('_adoptReject');
                        var cur = localStorage.getItem('adoptConsentMode');
                        var parsed = cur ? JSON.parse(cur) : {};
                        parsed.marketing = true;
                        parsed.ad_storage = 'granted';
                        if (wasRejected) {
                            parsed.statistics = false;
                            parsed.analytics_storage = 'denied';
                        }
                        localStorage.setItem('adoptConsentMode', JSON.stringify(parsed));
                    } catch(err) {}
                    syncCategories();
                } else if (evtName === 'adopt-accept-statistics') {
                    var wasRejected = !!window._adoptExplicitlyRejected;
                    try {
                        wasRejected = wasRejected || localStorage.getItem('_adoptReject') === '1';
                    } catch(err) {}
                    window._adoptExplicitlyRejected = false;
                    window._adoptMarketingGranted = wasRejected ? false : !!window._adoptMarketingGranted;
                    window._adoptStatisticsGranted = true;
                    window._adoptConsentOverride = {
                        marketing: window._adoptMarketingGranted,
                        statistics: true
                    };
                    try {
                        if (localStorage.getItem('_adoptReject') === '1') localStorage.removeItem('_adoptReject');
                        var cur = localStorage.getItem('adoptConsentMode');
                        var parsed = cur ? JSON.parse(cur) : {};
                        parsed.statistics = true;
                        parsed.analytics_storage = 'granted';
                        if (wasRejected) {
                            parsed.marketing = false;
                            parsed.ad_storage = 'denied';
                        }
                        localStorage.setItem('adoptConsentMode', JSON.stringify(parsed));
                    } catch(err) {}
                    syncCategories();
                } else if (evtName === 'adopt-accept-all') {
                    window._adoptExplicitlyRejected = false;
                    window._adoptMarketingGranted = true;
                    window._adoptStatisticsGranted = true;
                    window._adoptConsentOverride = { marketing: true, statistics: true };
                    try {
                        if (localStorage.getItem('_adoptReject') === '1') localStorage.removeItem('_adoptReject');
                        var cur = localStorage.getItem('adoptConsentMode');
                        var parsed = cur ? JSON.parse(cur) : {};
                        parsed.marketing = true;
                        parsed.statistics = true;
                        parsed.ad_storage = 'granted';
                        parsed.analytics_storage = 'granted';
                        localStorage.setItem('adoptConsentMode', JSON.stringify(parsed));
                    } catch(err) {}
                    syncCategories();
                } else if (evtName === 'adopt-reject-all' || evtName === 'adopt-reject') {
                    window._adoptExplicitlyRejected = true;
                    window._adoptMarketingGranted = false;
                    window._adoptStatisticsGranted = false;
                    window._adoptConsentOverride = { marketing: false, statistics: false };
                    try {
                        localStorage.setItem('_adoptReject', '1');
                        var cur = localStorage.getItem('adoptConsentMode');
                        if (cur) {
                            var parsed = JSON.parse(cur);
                            parsed.marketing = false;
                            parsed.statistics = false;
                            parsed.ad_storage = 'denied';
                            parsed.analytics_storage = 'denied';
                            localStorage.setItem('adoptConsentMode', JSON.stringify(parsed));
                        }
                    } catch(err) {}
                    syncCategories();
                } else if (evtName === 'adopt-visitor-consent-ready') {
                    syncCategories();
                }
            }
            return res;
        };
        window.addEventListener('storage', function(e) {
            if (!e || !e.key || e.key === 'adoptConsentMode' || e.key === '_adoptReject') {
                delete window._adoptConsentOverride;
                delete window._adoptExplicitlyRejected;
                syncCategories();
            }
        });
        window.addEventListener('DOMContentLoaded', syncCategories);
        window.addEventListener('load', syncCategories);
    })();
    </script>

    <style id="uonix-cookie-premium-controls">
        /* =========================================================
           1. ROOT E BLINDAGEM ANTI-ADOPT
        ========================================================= */
        #uonix-cookie-root {
            position: fixed !important; 
            left: 0 !important; 
            right: 0 !important; 
            bottom: 0 !important;
            z-index: 2147483646 !important; 
            pointer-events: none !important;
        }
        
        #uonix-cookie-root #cookie-banner, 
        #uonix-cookie-root #preference-banner {
            pointer-events: auto !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 40px rgba(14, 55, 128, 0.08), 0 1px 3px rgba(0,0,0,0.05) !important;
            border: 1px solid rgba(23, 63, 146, 0.08) !important;
            background: #ffffff !important;
            box-sizing: border-box !important;
        }
        
        /* Herança forçada da fonte do tema Kadence */
        #uonix-cookie-root * { 
            font-family: inherit !important; 
            box-sizing: border-box !important;
        }

        /* Animações Globais */
        #uonix-cookie-root button, 
        #uonix-cookie-root a, 
        #uonix-cookie-root div[tabindex="0"] {
            transition: all 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;
        }

        /* =========================================================
           2. BANNER PRINCIPAL (Aviso Inicial)
        ========================================================= */
        #uonix-cookie-root #cookie-banner {
            left: 30px !important; 
            bottom: 25px !important; 
            margin: 0 !important;
            padding: 0px !important;
            width: 90% !important;
            max-width: 440px !important;
        }

        /* Título do Banner */
        #uonix-cookie-root #cookie-banner-title {
            color: #0e3780 !important;
            font-weight: 800 !important;
            font-size: 1.1rem !important;
            margin: 0 0 10px 0 !important;
        }
        
        /* Textos Base do Banner */
        #uonix-cookie-root #cookie-banner small, 
        #uonix-cookie-root #cookie-banner span:not(#adopt-divisor) {
            font-size: 13px !important;
            line-height: 1.5 !important;
            color: #555 !important;
            display: block !important;
            margin-bottom: 8px !important;
        }

        /* Links de Política de Privacidade, Cookies e Termos (Lado a Lado com quebra flexível) */
        #uonix-cookie-root #cookie-banner span:has(> #adopt-divisor),
        #uonix-cookie-root #cookie-banner span:has(> a[href*="politica"]),
        #uonix-cookie-root #cookie-banner span:has(> a[href*="termos"]),
        #uonix-cookie-root #cookie-banner span:has(> a[href*="#"]),
        #uonix-cookie-root #cookie-banner .adopt-c-heVgjB,
        #cookie-banner span:has(> #adopt-divisor),
        #cookie-banner span:has(> a[href*="politica"]),
        #cookie-banner span:has(> a[href*="termos"]),
        #cookie-banner .adopt-c-heVgjB {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            justify-content: center !important;
            align-items: center !important;
            column-gap: 8px !important;
            row-gap: 6px !important;
            margin-top: 14px !important;
            margin-bottom: 14px !important;
            width: 100% !important;
            line-height: 1.4 !important;
            text-align: center !important;
        }
        
        /* Divisores entre os links (inline, centralizados no eixo) */
        #uonix-cookie-root #cookie-banner #adopt-divisor,
        #uonix-cookie-root #cookie-banner span#adopt-divisor,
        #cookie-banner #adopt-divisor,
        #cookie-banner span#adopt-divisor {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #94a3b8 !important;
            font-size: 11px !important;
            font-weight: 500 !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 1px !important;
            width: auto !important;
            height: auto !important;
            user-select: none !important;
        }

        /* Estilização individual dos links legais */
        #uonix-cookie-root #cookie-banner span:has(> #adopt-divisor) a,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="politica"]) a,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="termos"]) a,
        #uonix-cookie-root #cookie-banner a[href*="politica-de-privacidade"],
        #uonix-cookie-root #cookie-banner a[href*="politica-de-cookies"],
        #uonix-cookie-root #cookie-banner a[href*="termos-de-uso"],
        #uonix-cookie-root #cookie-banner a[href*="#"],
        #uonix-cookie-root #cookie-banner a.adopt-c-gtasTX,
        #cookie-banner span:has(> #adopt-divisor) a,
        #cookie-banner a[href*="politica-de-privacidade"],
        #cookie-banner a[href*="politica-de-cookies"],
        #cookie-banner a[href*="termos-de-uso"],
        #cookie-banner a.adopt-c-gtasTX {
            position: relative !important;
            display: inline-flex !important;
            align-items: center !important;
            color: #f76a0c !important;
            font-weight: 700 !important;
            text-decoration: none !important;
            margin: 0 !important;
            padding: 2px 0 !important;
            font-size: 12.5px !important;
            line-height: 1.3 !important;
            white-space: nowrap !important;
        }

        /* Controle de margem para o 'Desenvolvido por AdOpt' */
        #uonix-cookie-root #cookie-banner small:last-child {
            margin-top: 12px !important;
            margin-bottom: 24px !important;
        }

        #uonix-cookie-root #cookie-banner span:has(> #adopt-divisor) a::after,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="politica"]) a::after,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="termos"]) a::after,
        #uonix-cookie-root #cookie-banner a[href*="politica-de-privacidade"]::after,
        #uonix-cookie-root #cookie-banner a[href*="politica-de-cookies"]::after,
        #uonix-cookie-root #cookie-banner a[href*="termos-de-uso"]::after,
        #uonix-cookie-root #cookie-banner a[href*="#"]::after,
        #uonix-cookie-root #cookie-banner a.adopt-c-gtasTX::after,
        #cookie-banner span:has(> #adopt-divisor) a::after,
        #cookie-banner a[href*="politica-de-privacidade"]::after,
        #cookie-banner a[href*="politica-de-cookies"]::after,
        #cookie-banner a[href*="termos-de-uso"]::after,
        #cookie-banner a.adopt-c-gtasTX::after {
            content: "" !important;
            position: absolute !important;
            left: 0 !important;
            right: 0 !important;
            bottom: -1px !important;
            height: 1.5px !important;
            background: rgba(247, 106, 12, 0.4) !important;
            transform: scaleX(0) !important;
            transform-origin: left center !important;
            transition: transform .25s ease !important;
        }

        #uonix-cookie-root #cookie-banner span:has(> #adopt-divisor) a:hover::after,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="politica"]) a:hover::after,
        #uonix-cookie-root #cookie-banner span:has(> a[href*="termos"]) a:hover::after,
        #uonix-cookie-root #cookie-banner a[href*="politica-de-privacidade"]:hover::after,
        #uonix-cookie-root #cookie-banner a[href*="politica-de-cookies"]:hover::after,
        #uonix-cookie-root #cookie-banner a[href*="termos-de-uso"]:hover::after,
        #uonix-cookie-root #cookie-banner a[href*="#"]:hover::after,
        #uonix-cookie-root #cookie-banner a.adopt-c-gtasTX:hover::after,
        #cookie-banner span:has(> #adopt-divisor) a:hover::after,
        #cookie-banner a[href*="politica-de-privacidade"]:hover::after,
        #cookie-banner a[href*="politica-de-cookies"]:hover::after,
        #cookie-banner a[href*="termos-de-uso"]:hover::after,
        #cookie-banner a.adopt-c-gtasTX:hover::after {
            transform: scaleX(1) !important;
        }

        @media (max-width: 480px) {
            #uonix-cookie-root #cookie-banner {
                left: 12px !important;
                right: 12px !important;
                bottom: 12px !important;
                width: calc(100% - 24px) !important;
                max-width: 100% !important;
            }
        }

        @media (max-width: 360px) {
            #uonix-cookie-root #cookie-banner span#adopt-divisor,
            #uonix-cookie-root #cookie-banner #adopt-divisor,
            #cookie-banner span#adopt-divisor,
            #cookie-banner #adopt-divisor {
                display: none !important;
            }
        }
        
		   
		   
		/* =========================================================
           OCULTAÇÃO CIRÚRGICA E CRÉDITOS DA ADOPT
        ========================================================= */
        
        /* Oculta APENAS o selo da AdOpt no cabeçalho (que fica ao lado do <h3>) */
        #uonix-cookie-root h3 + div:has(a[href*="goadopt"]) { 
            display: none !important; 
        }

        /* Estiliza o "Desenvolvido por AdOpt" para ficar elegante no rodapé dos textos */
        #uonix-cookie-root small:has(a[href*="goadopt"]) {
            display: block !important;
            text-align: center !important;
            font-size: 11px !important;
            color: #888 !important;
            margin-top: 16px !important;
            margin-bottom: 4px !important;
            width: 100% !important;
        }
        
        #uonix-cookie-root small:has(a[href*="goadopt"]) a {
            color: #f76a0c !important;
            font-weight: 700 !important;
            text-decoration: none !important;
            display: inline !important; /* Impede o link de quebrar de linha */
        }
        
        #uonix-cookie-root small:has(a[href*="goadopt"]) a:hover {
            color: #d65a0a !important;
            text-decoration: underline !important;
        }

        /* Container dos Botões Principais.
           Mira o container pelos DOIS estados do AdOpt: quando o botão do meio
           vem com id (#adopt-reject-all-button) e quando vem sem id — nesse caso
           o "Aceitar" (#adopt-accept-all-button) sempre existe e ancora o gap,
           evitando que os botões fiquem colados se o AdOpt trocar o markup. */
        #uonix-cookie-root #cookie-banner div:has(> #adopt-reject-all-button),
        #uonix-cookie-root #cookie-banner div:has(> #adopt-accept-all-button) {
            display: flex !important; 
            align-items: center !important; 
            justify-content: flex-end !important; 
            gap: 12px !important; 
            width: 100% !important;
            margin-top: 16px !important;
            flex-wrap: wrap !important;
        }

		   /* --- Estilo do Botão "Minhas Opções" (Ghost Button Limpo) --- */
        #uonix-cookie-root #adopt-preferences-button {
            appearance: none !important; 
            background: transparent !important; 
            border: 0 !important; 
            box-shadow: none !important; 
            padding: 8px 12px !important; /* Área de clique confortável */
            border-radius: 6px !important; /* Arredondamento suave no hover */
            color: #555 !important; /* Cor neutra para dar destaque aos botões principais */
            font-weight: 600 !important; 
            text-decoration: none !important; /* Corta qualquer sublinhado nativo */
            cursor: pointer !important; 
            margin-right: auto !important; /* Empurra os outros botões para a direita */
            white-space: nowrap !important; 
            font-size: 13px !important;
            transition: all 0.2s ease !important;
        }

        /* Garante a morte do sublinhado degradê antigo (caso ainda tenha sobrado) */
        #uonix-cookie-root #adopt-preferences-button::after {
            display: none !important;
        }

        /* O Efeito Mágico no Hover */
        #uonix-cookie-root #adopt-preferences-button:hover { 
            color: #0e3780 !important; /* Acende para o Azul Uônix */
            background: #f4f7fa !important; /* Fundo cinza/azulado super sutil */
            transform: translateY(0) !important; /* Mantém ele estático e sóbrio */
        }
		   
		   

        #uonix-cookie-root #adopt-reject-all-button, 
        #uonix-cookie-root #adopt-accept-all-button,
        #uonix-cookie-root #preference-banner div > button:nth-last-of-type(2),
        #uonix-cookie-root #preference-banner div > button:last-of-type {
            appearance: none !important; border-radius: 8px !important; font-weight: 700 !important; font-size: 13px !important; padding: 10px 18px !important; cursor: pointer !important; border: none !important; white-space: nowrap !important;
        }

        /* Fallback de arredondamento para o botão de recusa quando o AdOpt o
           serve SEM id (estados antigos rotulavam "Não venda"). Mira por exclusão
           — não é "Minhas opções" nem "Aceitar" — para casar com o botão do meio
           mesmo sem id; quando ele vem com #adopt-reject-all-button, a regra
           "Rejeitar" abaixo já o cobre. Mantém o mesmo raio do Aceitar. */
        #uonix-cookie-root #cookie-banner div:has(> #adopt-accept-all-button) > button:not(#adopt-preferences-button):not(#adopt-accept-all-button) {
            border-radius: 8px !important;
        }

        /* Rejeitar */
        #uonix-cookie-root #adopt-reject-all-button,
        #uonix-cookie-root #preference-banner div > button:nth-last-of-type(2) {
            background: #ffffff !important; color: #0e3780 !important; border: 1px solid rgba(14, 55, 128, 0.2) !important; box-shadow: 0 2px 4px rgba(0,0,0,0.02) !important;
        }
        #uonix-cookie-root #adopt-reject-all-button:hover,
        #uonix-cookie-root #preference-banner div > button:nth-last-of-type(2):hover {
            background: #f4f7fa !important; border-color: #0e3780 !important; transform: translateY(-2px) !important; box-shadow: 0 4px 12px rgba(14, 55, 128, 0.08) !important;
        }

        /* Aceitar/Salvar */
        #uonix-cookie-root #adopt-accept-all-button,
        #uonix-cookie-root #preference-banner div > button:last-of-type {
            background: #f76a0c !important; color: #ffffff !important; box-shadow: 0 4px 12px rgba(247, 106, 12, 0.25) !important;
        }
        #uonix-cookie-root #adopt-accept-all-button:hover,
        #uonix-cookie-root #preference-banner div > button:last-of-type:hover {
            background: #e55e05 !important; box-shadow: 0 6px 16px rgba(247, 106, 12, 0.4) !important; transform: translateY(-2px) !important;
        }

        /* =========================================================
           3. PREFERENCES BANNER (MODAL DE OPÇÕES)
        ========================================================= */
        #uonix-cookie-root #preference-banner {
            top: auto !important; right: auto !important; left: 30px !important; bottom: 25px !important; transform: none !important; margin: 0 !important; max-height: calc(100vh - 50px) !important; width: 90% !important; max-width: 420px !important; display: flex !important; flex-direction: column !important; padding: 24px !important; overflow: hidden !important; 
        }
        
        #uonix-cookie-root #preference-banner-title {
            text-align: left !important; margin: 0 40px 15px 0 !important; color: #0e3780 !important; font-weight: 800 !important; font-size: 1.25rem !important; flex-shrink: 0 !important;
        }

        #uonix-cookie-root #preference-banner > button {
            position: absolute !important; top: 24px !important; right: 24px !important; appearance: none !important; background: transparent !important; border: none !important; box-shadow: none !important; width: 32px !important; height: 32px !important; display: flex !important; align-items: center !important; justify-content: center !important; cursor: pointer !important; padding: 0 !important; z-index: 10 !important;
        }
        #uonix-cookie-root #preference-banner > button:hover { transform: scale(1.15) rotate(90deg) !important; }
        #uonix-cookie-root #preference-banner > button svg { width: 14px !important; height: 14px !important; }
        #uonix-cookie-root #preference-banner > button svg path { fill: #0e3780 !important; }

        /* Área de Rolagem */
        #uonix-cookie-root #preference-banner-content {
            overflow-y: auto !important; padding-right: 10px !important; margin-right: -10px !important; flex-grow: 1 !important;
        }
        #uonix-cookie-root #preference-banner-content::-webkit-scrollbar { width: 6px; }
        #uonix-cookie-root #preference-banner-content::-webkit-scrollbar-track { background: transparent; }
        #uonix-cookie-root #preference-banner-content::-webkit-scrollbar-thumb { background: rgba(14, 55, 128, 0.15); border-radius: 10px; }
        #uonix-cookie-root #preference-banner-content::-webkit-scrollbar-thumb:hover { background: rgba(14, 55, 128, 0.3); }

        /* Blocos das Categorias (CSS Grid Robusto) */
        #uonix-cookie-root #preference-banner div[id^="cat$"] {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 15px 0 !important;
            border-bottom: 1px solid rgba(0,0,0,0.06) !important;
        }
        
        /* 1. Título (Alinhado à Esquerda) */
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:first-child {
            display: flex !important; align-items: center !important; gap: 8px !important; color: #0e3780 !important; font-weight: 700 !important; font-size: 14px !important; flex: 1 1 auto !important;
        }
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:first-child svg path { fill: #0e3780 !important; }

        /* 2. Toggle (Alinhado à Direita) */
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:nth-child(2) {
            flex: 0 0 auto !important; display: flex !important; justify-content: flex-end !important;
        }

        /* 3. Descrição (Quebra de Linha - 100% da largura) */
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:nth-child(3) {
            flex: 1 1 100% !important; width: 100% !important; margin-top: 8px !important; font-size: 13px !important; line-height: 1.5 !important; color: #555 !important; font-weight: 400 !important;
        }

        /* 4. "Mostre mais" (Opcional - Abaixo da Descrição) */
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:nth-child(4) {
            flex: 1 1 100% !important; margin-top: 6px !important; color: #f76a0c !important; font-weight: 700 !important; font-size: 13px !important; cursor: pointer !important; display: flex !important; align-items: center !important; gap: 4px !important;
        }
        #uonix-cookie-root #preference-banner div[id^="cat$"] > div:nth-child(4):hover { color: #d65a0a !important; }

        /* A Geometria da Chavinha (Toggle) */
        #uonix-cookie-root #preference-banner div[tabindex="0"] {
            background: #cfd7e0 !important; border: none !important; border-radius: 999px !important; cursor: pointer !important; width: 44px !important; height: 24px !important; display: inline-flex !important; align-items: center !important; box-sizing: border-box !important; position: relative !important; padding: 2px !important;
        }
        #uonix-cookie-root #preference-banner div[tabindex="0"] > div {
            background: #ffffff !important; border-radius: 50% !important; box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important; width: 20px !important; height: 20px !important; transition: transform 0.3s ease !important; position: absolute !important; left: 2px !important;
        }
        #uonix-cookie-root #preference-banner div[tabindex="0"] svg { display: none !important; }

        /* Chavinha Ativa */
        #uonix-cookie-root #preference-banner div[tabindex="0"][class*="active-true"] { background: #f76a0c !important; }
        #uonix-cookie-root #preference-banner div[tabindex="0"][class*="active-true"] > div { transform: translateX(20px) !important; }

        /* --- Links Inferiores de Ajuda (Ícone na Esquerda) --- */
        #uonix-cookie-root #preference-banner > a {
            display: flex !important; align-items: flex-start !important; gap: 10px !important; color: #f76a0c !important; font-weight: 700 !important; text-decoration: none !important; margin-top: 15px !important; font-size: 13px !important; cursor: pointer !important;
        }
        /* Bloqueia o encolhimento do ícone */
        #uonix-cookie-root #preference-banner > a > div:first-child {
            flex-shrink: 0 !important; display: flex !important; align-items: center !important; margin-top: 2px !important;
        }
        #uonix-cookie-root #preference-banner > a > div:first-child svg path { fill: #f76a0c !important; }
        
        /* Texto descritivo da ajuda */
        #uonix-cookie-root #preference-banner > a > div:last-child {
            color: #555 !important; font-weight: 400 !important; line-height: 1.4 !important;
        }
        #uonix-cookie-root #preference-banner > a:hover > div:last-child { text-decoration: underline !important; color: #333 !important; }

        /* Container de Botões do Rodapé (Modal) - Divisão 50/50 */
        #uonix-cookie-root #preference-banner div:has(> button:last-of-type) {
            display: flex !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; gap: 12px !important; margin-top: 20px !important; border-top: 1px solid rgba(0,0,0,0.06) !important; padding-top: 20px !important; flex-shrink: 0 !important;
        }
        #uonix-cookie-root #preference-banner div > button:nth-last-of-type(2),
        #uonix-cookie-root #preference-banner div > button:last-of-type {
            flex: 1 !important; text-align: center !important; justify-content: center !important;
        }

        /* Outline Acessibilidade */
        #uonix-cookie-root button:focus-visible, 
        #uonix-cookie-root a:focus-visible, 
        #uonix-cookie-root div[tabindex="0"]:focus-visible {
            outline: 2px solid rgba(255, 106, 0, 0.50) !important; outline-offset: 3px !important;
        }

        /* =========================================================
           4. ESCONDER CONTROLADOR FLUTUANTE (Padrão Enterprise)
        ========================================================= */
        #adopt-controller-button {
            opacity: 0 !important; pointer-events: none !important; position: fixed !important; bottom: -100px !important; z-index: -1 !important;
        }
    </style>

    <?php if ( $render_gtm ) : ?>
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer',<?php echo wp_json_encode( $gtm_container_id ); ?>);</script>
    <?php endif; ?>
    <?php
}
}
add_action( 'wp_head', 'uonix_render_analytics_head', 1, 0 );

// 2. Inserção logo após a abertura do <body> (GTM Noscript + Root controlado)
if ( ! function_exists( 'uonix_render_analytics_body' ) ) {
function uonix_render_analytics_body( $configuration = null ) {
    // idem: accepted_args=0 preserva o default null.
    $configuration = null === $configuration ? uonix_analytics_configuration() : $configuration;

    if ( is_admin() || ! uonix_analytics_configuration_is_complete( $configuration ) ) return;

    $gtm_container_id = $configuration['gtm_container_id'];
    $render_gtm       = uonix_analytics_should_render_gtm( $configuration );
    ?>
    
    <div id="uonix-cookie-root" aria-live="polite"></div>

    <?php if ( $render_gtm ) : ?>
        <noscript><iframe src="<?php echo esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $gtm_container_id ) ); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <?php endif; ?>

    <script>
    (function() {
        if (window.__uonixCookieRootInit) return;
        window.__uonixCookieRootInit = true;

        function normalizeRejectLabel(cookieBanner) {
            /*
             * O AdOpt, dependendo da configuração/versão servida, rotula o botão
             * de recusa como "Rejeitar" (com id #adopt-reject-all-button) OU como
             * "Não venda"/"Não vender" (sem id). Este site padroniza em "Rejeitar".
             *
             * Mira o botão por EXCLUSÃO — não é "Minhas opções" nem "Aceitar" —,
             * exatamente o mesmo critério que o CSS usa, para funcionar mesmo
             * quando a classe é dinâmica e o id não existe. Só reescreve quando o
             * texto atual é uma variante de "Não venda/vender"; nunca mexe se já
             * estiver correto.
             */
            if (!cookieBanner) return;
            var accept = document.getElementById('adopt-accept-all-button');
            var container = accept ? accept.parentElement : cookieBanner;
            if (!container) return;

            var buttons = container.querySelectorAll('button');
            for (var i = 0; i < buttons.length; i++) {
                var b = buttons[i];
                if (b.id === 'adopt-preferences-button' || b.id === 'adopt-accept-all-button') {
                    continue;
                }
                var txt = (b.textContent || '').trim();
                if (/^n[ãa]o\s+vend/i.test(txt)) {
                    b.textContent = 'Rejeitar';
                }
            }
        }

        function mountCookieElements() {
            var root = document.getElementById('uonix-cookie-root');
            var cookieBanner = document.getElementById('cookie-banner');
            var preferenceBanner = document.getElementById('preference-banner');

            if (!root) return false;

            if (cookieBanner && cookieBanner.parentElement !== root) {
                root.appendChild(cookieBanner);
            }

            if (preferenceBanner && preferenceBanner.parentElement !== root) {
                root.appendChild(preferenceBanner);
            }

            normalizeRejectLabel(cookieBanner);

            return !!(cookieBanner || preferenceBanner);
        }

        function initObserver() {
            var tries = 0;
            var maxTries = 120;

            var timer = setInterval(function() {
                tries++;
                if (mountCookieElements() || tries >= maxTries) {
                    clearInterval(timer);
                }
            }, 250);

            var observer = new MutationObserver(function() {
                mountCookieElements();
            });

            observer.observe(document.documentElement, {
                childList: true,
                subtree: true
            });

            document.addEventListener('DOMContentLoaded', mountCookieElements);
            window.addEventListener('load', mountCookieElements);
            
            // --- Conecta o link do Mega Menu ao botão oculto da AdOpt ---
            setInterval(function() {
                var menuLinks = document.querySelectorAll('.open-adopt-modal a');
                
                for (var i = 0; i < menuLinks.length; i++) {
                    if (!menuLinks[i].dataset.adoptBound) {
                        menuLinks[i].dataset.adoptBound = 'true';
                        
                        menuLinks[i].addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation(); 
                            
                            var adoptBtn = document.getElementById('adopt-controller-button');
                            if (adoptBtn) {
                                var clickEvent = new MouseEvent('click', { 
                                    bubbles: true, 
                                    cancelable: true, 
                                    view: window 
                                });
                                adoptBtn.dispatchEvent(clickEvent);
                            }
                        }, true); 
                    }
                }
            }, 1000); 

        }

        initObserver();
    })();
    </script>
    <?php
}
}
add_action( 'wp_body_open', 'uonix_render_analytics_body', 10, 0 );

/**
 * UONIX: Listener de conversao para Fluent Forms.
 * Dispara apenas quando o campo form_assunto tiver valor "orcamento".
 */
if ( ! function_exists( 'uonix_render_analytics_conversion_footer' ) ) {
function uonix_render_analytics_conversion_footer() {
    ?>
    <script id="uonix-conversao-orcamento-datalayer">
    (function() {
        var conversoesPendentes = {};

        function obterElementoFormulario(formValue) {
            if (!formValue) return null;
            if (formValue.nodeType) return formValue;
            if (formValue[0] && formValue[0].nodeType) return formValue[0];
            return null;
        }

        function obterFormId(explicitFormId, form) {
            var formId = explicitFormId ? String(explicitFormId).trim() : '';
            if (!formId && form) {
                if (typeof form.getAttribute === 'function') {
                    formId = form.getAttribute('data-form_id') || form.getAttribute('data-form-id') || '';
                }
                if (!formId && form.id) formId = form.id;
            }
            var match = String(formId).match(/^fluentform_(\d+)$/);
            if (match) formId = match[1];
            return /^\d+$/.test(String(formId)) && Number(formId) > 0 ? String(formId) : '';
        }

        function dispararConversaoSeOrcamento(formId, assunto) {
            var val = assunto && String(assunto).trim() ? String(assunto).trim() : '';
            if (formId && val === 'orcamento') {
                var chave = String(formId || '') + '|' + val;
                if (conversoesPendentes[chave]) return;
                conversoesPendentes[chave] = true;
                setTimeout(function() {
                    delete conversoesPendentes[chave];
                }, 0);
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    'event': 'uonix_solicitar_orcamento',
                    'origem_conversao': 'fluentform_orcamento',
                    'form_id': formId,
                    'transaction_id': null
                });
            }
        }

        function registrarListener() {
            if (window.jQuery) {
                window.jQuery(document).off('fluentform_submission_success.uonixOrcamento').on('fluentform_submission_success.uonixOrcamento', function(e, data) {
                    try {
                        var form = obterElementoFormulario(data && data.form);
                        var formId = obterFormId(data && (data.formId || data.form_id), form);
                        if (!form && formId) form = document.querySelector('#fluentform_' + formId);
                        var assuntoEl = form ? form.querySelector('select[name="form_assunto"]') : null;
                        var val = (assuntoEl && assuntoEl.value) ? assuntoEl.value : '';
                        dispararConversaoSeOrcamento(formId, val);
                    } catch(err) {}
                });
            }

            document.addEventListener('fluentform_submission_success', function(e) {
                try {
                    var detail = e && e.detail;
                    var form = obterElementoFormulario(detail && detail.form);
                    var formId = obterFormId(detail && (detail.formId || detail.form_id), form);
                    if (!form && formId) form = document.querySelector('#fluentform_' + formId);
                    var assuntoEl = form ? form.querySelector('select[name="form_assunto"]') : null;
                    var val = (assuntoEl && assuntoEl.value) ? assuntoEl.value : '';
                    dispararConversaoSeOrcamento(formId, val);
                } catch(err) {}
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', registrarListener);
        } else {
            registrarListener();
        }
    })();
    </script>
    <?php
}
}
add_action( 'wp_footer', 'uonix_render_analytics_conversion_footer', 99, 0 );

/**
 * Emite a conversao RFQ somente no template thank-you, depois de o WooCommerce
 * validar a chave, o cliente autenticado e a verificacao de e-mail do guest.
 * A chave e o status sao validados novamente como defesa em profundidade.
 */
if ( ! function_exists( 'uonix_render_analytics_rfq_conversion' ) ) {
function uonix_render_analytics_rfq_conversion( $order_id ) {
    if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }

    $uonix_order_id = function_exists( 'absint' ) ? absint( $order_id ) : abs( (int) $order_id );
    if ( $uonix_order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $uonix_order = wc_get_order( $uonix_order_id );
    if (
        ! $uonix_order
        || ! is_object( $uonix_order )
        || ! method_exists( $uonix_order, 'get_status' )
        || ! method_exists( $uonix_order, 'get_order_key' )
    ) {
        return;
    }

    $uonix_order_key_from_url = '';
    if ( isset( $_GET['key'] ) && is_scalar( $_GET['key'] ) ) {
        $uonix_order_key_from_url = (string) $_GET['key'];
        if ( function_exists( 'wp_unslash' ) ) {
            $uonix_order_key_from_url = wp_unslash( $uonix_order_key_from_url );
        }
        $uonix_order_key_from_url = function_exists( 'wc_clean' )
            ? wc_clean( $uonix_order_key_from_url )
            : trim( $uonix_order_key_from_url );
    }

    $uonix_expected_order_key = (string) $uonix_order->get_order_key();
    $uonix_order_key_is_valid = '' !== $uonix_order_key_from_url
        && '' !== $uonix_expected_order_key
        && hash_equals( $uonix_expected_order_key, $uonix_order_key_from_url );
    $uonix_order_status = (string) $uonix_order->get_status();
    if ( 0 === strpos( $uonix_order_status, 'wc-' ) ) {
        $uonix_order_status = substr( $uonix_order_status, 3 );
    }

    if ( ! $uonix_order_key_is_valid || 'gplsquote-req' !== $uonix_order_status ) {
        return;
    }
    $news_optin = '';
    if ( method_exists( $uonix_order, 'get_meta' ) ) {
        $news_optin = $uonix_order->get_meta( 'billing_newsletters' );
        if ( ! $news_optin ) {
            $news_optin = $uonix_order->get_meta( '_billing_newsletters' );
        }
    } elseif ( function_exists( 'get_post_meta' ) ) {
        $news_optin = get_post_meta( $uonix_order_id, 'billing_newsletters', true );
        if ( ! $news_optin ) {
            $news_optin = get_post_meta( $uonix_order_id, '_billing_newsletters', true );
        }
    }
    $assina_news = in_array( strtolower( (string) $news_optin ), array( '1', 'sim', 'yes', 'true' ), true );
    ?>
    <script id="uonix-conversao-carrinho-datalayer">
    (function() {
        var orderId = <?php echo (int) $uonix_order_id; ?>;
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'event': 'uonix_solicitar_orcamento',
            'origem_conversao': 'woocommerce_order_received',
            'order_id': orderId,
            'transaction_id': 'uonix-rfq-order-' + orderId
        });
    })();
    </script>
    <?php if ( $assina_news ) : ?>
    <script id="uonix-conversao-carrinho-newsletter-datalayer">
    (function() {
        var orderId = <?php echo (int) $uonix_order_id; ?>;
        var newsDedupeKey = 'uonix_news_rfq_' + orderId;

        function hasMarketingConsent() {
            if (window._adoptMarketingGranted === true) {
                return true;
            }
            if (Array.isArray(window.acceptedTags) && window.acceptedTags.indexOf('marketing') !== -1) {
                return true;
            }
            try {
                var raw = localStorage.getItem('adoptConsentMode');
                if (raw) {
                    var parsed = JSON.parse(raw);
                    if (parsed && (parsed.marketing === true || parsed.ad_storage === 'granted')) {
                        return true;
                    }
                }
            } catch(e) {}
            return false;
        }

        function emitirConversaoNewsletter() {
            try {
                if (window.sessionStorage && window.sessionStorage.getItem(newsDedupeKey)) {
                    return;
                }
            } catch(e) {}

            // Nao grava no sessionStorage nem consome a conversao antes do consentimento efetivo de marketing
            if (!hasMarketingConsent()) {
                return;
            }

            try {
                if (window.sessionStorage) {
                    window.sessionStorage.setItem(newsDedupeKey, '1');
                }
            } catch(e) {}

            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'event': 'uonix_assinatura_newsletter',
                'origem_conversao': 'woocommerce_order_received',
                'order_id': orderId,
                'transaction_id': 'uonix-rfq-news-' + orderId
            });
        }

        // 1. Emissao imediata se consentimento ja estiver ativo
        emitirConversaoNewsletter();

        // 2. Reemissao na concessao tardia de consentimento
        function onConsentGranted() {
            emitirConversaoNewsletter();
        }

        window.addEventListener('adopt-accept-marketing', onConsentGranted, { passive: true });

        // Escuta atualizacoes de consentimento no dataLayer
        window.dataLayer = window.dataLayer || [];
        var origPush = window.dataLayer.push;
        window.dataLayer.push = function() {
            var res = origPush.apply(this, arguments);
            for (var i = 0; i < arguments.length; i++) {
                var item = arguments[i];
                if (item && (item.event === 'adopt_consent_updated' || item.event === 'adopt-accept-marketing')) {
                    if (item.adopt_marketing === true || (item.accepted_tags && item.accepted_tags.indexOf('marketing') !== -1)) {
                        emitirConversaoNewsletter();
                    }
                }
            }
            return res;
        };
    })();
    </script>
    <?php endif; ?>
    <?php
}
}
add_action( 'woocommerce_thankyou', 'uonix_render_analytics_rfq_conversion', 10, 1 );

/**
 * UONIX: Captura adicoes confirmadas ao carrinho via fluxo padrao (nao-AJAX) do WooCommerce.
 */
if ( ! function_exists( 'uonix_record_standard_add_to_cart' ) ) {
function uonix_record_standard_add_to_cart( $cart_item_key, $product_id, $quantity ) {
    if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
        return;
    }
    if ( function_exists( 'WC' ) && WC()->session ) {
        $pending = WC()->session->get( 'uonix_pending_standard_add_to_cart', array() );
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        $pending[] = array(
            'product_id' => absint( $product_id ),
            'quantity'   => max( 1, absint( $quantity ) ),
            'timestamp'  => time(),
        );
        WC()->session->set( 'uonix_pending_standard_add_to_cart', $pending );
    }
}
}
add_action( 'woocommerce_add_to_cart', 'uonix_record_standard_add_to_cart', 10, 3 );

/**
 * UONIX: Emissao e listeners das micro-conversoes do funil.
 * - Iniciar finalizacao de compra no checkout WooCommerce (/finalizar-orcamento/).
 * - Adicionar ao carrinho / orcamento via evento confirmado added_to_cart (AJAX) ou flag confirmada server-side (nao-AJAX).
 * - Contato via formulario institucional/suporte (assunto != orcamento).
 * - Assinatura de newsletter em formularios Fluent Forms.
 */
if ( ! function_exists( 'uonix_render_analytics_microconversions_footer' ) ) {
function uonix_render_analytics_microconversions_footer() {
    $is_checkout = function_exists( 'is_checkout' ) && is_checkout();
    $is_order_received = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );

    // Se estiver no checkout ativo (antes de submeter)
    if ( $is_checkout && ! $is_order_received ) : ?>
        <script id="uonix-conversao-iniciar-finalizacao-datalayer">
        (function() {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'event': 'uonix_iniciar_finalizacao',
                'origem_conversao': 'woocommerce_checkout'
            });
        })();
        </script>
    <?php endif; ?>

    <?php
    // Adicao ao carrinho nao-AJAX confirmada via hook server-side
    $has_standard_add = false;
    if ( function_exists( 'WC' ) && WC()->session ) {
        $pending_adds = WC()->session->get( 'uonix_pending_standard_add_to_cart', array() );
        if ( ! empty( $pending_adds ) && is_array( $pending_adds ) ) {
            WC()->session->__unset( 'uonix_pending_standard_add_to_cart' );
            $has_standard_add = true;
        }
    }
    if ( $has_standard_add ) : ?>
        <script id="uonix-conversao-adicionar-carrinho-server-datalayer">
        (function() {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'event': 'uonix_adicionar_ao_carrinho',
                'origem_conversao': 'woocommerce_add_to_cart_standard'
            });
        })();
        </script>
    <?php endif; ?>

    <script id="uonix-conversao-adicionar-carrinho-listener">
    (function() {
        var adicionadoPendente = false;

        function dispararAdicionarCarrinho(origem) {
            if (adicionadoPendente) return;
            adicionadoPendente = true;
            setTimeout(function() {
                adicionadoPendente = false;
            }, 1000);

            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'event': 'uonix_adicionar_ao_carrinho',
                'origem_conversao': origem || 'woocommerce_add_to_cart'
            });
        }

        // Listener nativo do WooCommerce: dispara exclusivamente apos confirmacao AJAX da adicao ao carrinho
        if (window.jQuery) {
            window.jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, button, extra) {
                // Se algum argumento explicito trouxer tipo diferente de 'add' (ex: decrement ou remove), ignorar
                var options = (extra && typeof extra === 'object') ? extra : ((button && typeof button === 'object' && button.actionType) ? button : null);
                if (options && options.actionType && options.actionType !== 'add') {
                    return;
                }
                dispararAdicionarCarrinho('ajax_added_to_cart');
            });
        }
    })();
    </script>

    <script id="uonix-conversao-contato-newsletter-listener">
    (function() {
        var pendentes = {};

        function dispararContato(formId, assunto) {
            var val = assunto && String(assunto).trim() ? String(assunto).trim() : '';
            if (formId && val && val !== 'orcamento') {
                var chave = 'contato|' + String(formId || '') + '|' + val;
                if (pendentes[chave]) return;
                pendentes[chave] = true;
                setTimeout(function() { delete pendentes[chave]; }, 1000);
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    'event': 'uonix_contato_formulario',
                    'origem_conversao': 'fluentform_contato',
                    'form_id': formId,
                    'assunto': val
                });
            }
        }

        function dispararNewsletter(formId, form) {
            var fIdStr = String(formId || '');
            var assinou = (fIdStr === '2');
            if (!assinou && form) {
                var newsChecked = form.querySelector('input[name="form_newsletters"]:checked') ||
                                   form.querySelector('input[name*="newsletter"]:checked') ||
                                   form.querySelector('input[name="form_newsletters"][value="sim"]:checked');
                if (newsChecked) assinou = true;
            }
            if (assinou) {
                var chave = 'news|' + fIdStr;
                if (pendentes[chave]) return;
                pendentes[chave] = true;
                setTimeout(function() { delete pendentes[chave]; }, 1000);
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    'event': 'uonix_assinatura_newsletter',
                    'origem_conversao': fIdStr === '2' ? 'fluentform_id_2' : 'fluentform_checkbox',
                    'form_id': fIdStr || null
                });
            }
        }

        function processar(form, formId) {
            if (!form) return;
            var assuntoEl = form.querySelector('select[name="form_assunto"]');
            var val = (assuntoEl && assuntoEl.value) ? assuntoEl.value : '';
            dispararContato(formId, val);
            dispararNewsletter(formId, form);
        }

        if (window.jQuery) {
            window.jQuery(document).on('fluentform_submission_success.uonixContato', function(e, data) {
                try {
                    var form = (data && data.form && data.form[0]) ? data.form[0] : (e.target || null);
                    var formId = (data && (data.formId || data.form_id)) ? (data.formId || data.form_id) : (form ? (form.getAttribute('data-form_id') || form.getAttribute('data-form-id') || (form.id ? form.id.replace('fluentform_', '') : '')) : '');
                    processar(form, formId);
                } catch(err) {}
            });
        }

        document.addEventListener('fluentform_submission_success', function(e) {
            try {
                var form = (e && e.detail && e.detail.form) ? e.detail.form : (e.target || null);
                var formId = (e && e.detail && (e.detail.formId || e.detail.form_id)) ? (e.detail.formId || e.detail.form_id) : (form ? (form.getAttribute('data-form_id') || form.getAttribute('data-form-id') || (form.id ? form.id.replace('fluentform_', '') : '')) : '');
                processar(form, formId);
            } catch(err) {}
        });
    })();
    </script>
    <?php
}
}
add_action( 'wp_footer', 'uonix_render_analytics_microconversions_footer', 98, 0 );
