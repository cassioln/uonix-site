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
 * - Implementação direta via código para máxima performance (Sem plugins).
 * - Suporte nativo ao Google Consent Mode v2.
 * - Banner AdOpt movido para um container controlado para blindar CSS.
 * - UI Enterprise (Design System Uônix + Flexbox Grid).
 */

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

if ( ! function_exists( 'uonix_analytics_admin_notice' ) ) {
    /**
     * Avisa sobre configuração incompleta sem exibir qualquer identificador.
     */
    function uonix_analytics_admin_notice() {
        $environment = defined( 'UONIX_ENV' ) ? UONIX_ENV : null;
        $enabled     = defined( 'UONIX_ANALYTICS_ENABLED' ) ? UONIX_ANALYTICS_ENABLED : false;

        if ( 'production' !== $environment || true !== $enabled || false !== uonix_analytics_configuration() ) {
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
    ?>
    
    <meta name="adopt-website-id" content="<?php echo esc_attr( $adopt_website_id ); ?>" />
    <script src="<?php echo esc_url( 'https://tag.goadopt.io/injector.js?website_code=' . rawurlencode( $adopt_website_id ) ); ?>" class="adopt-injector"></script>

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
            max-width: 420px !important;
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
        #uonix-cookie-root #cookie-banner span {
            font-size: 13px !important;
            line-height: 1.5 !important;
            color: #555 !important;
            display: block !important;
            margin-bottom: 8px !important;
        }

		/* Links de Política de Privacidade e Cookies (Lado a Lado e Centralizados) */
        #uonix-cookie-root #cookie-banner span:has(> a[href*="#"]) {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            justify-content: center !important; /* <-- A MÁGICA AQUI: Centraliza no eixo horizontal */
            gap: 15px !important;
            align-items: center !important;
            margin-top: 12px !important;
            margin-bottom: 15px !important;
            width: 100% !important; /* Garante que ocupe a linha toda para o centro ficar exato */
        }
        
        #uonix-cookie-root #cookie-banner a[href*="#"] {
            position: relative !important; 
            display: inline-block !important; 
            color: #f76a0c !important; 
            font-weight: 700 !important; 
            text-decoration: none !important; 
            margin: 0 !important;
		    font-size: 14px !important
        }
		
		/* Controle de margem exclusivo para o 'Desenvolvido por AdOpt' */
        #uonix-cookie-root #cookie-banner small:last-child {
            margin-top: 15px !important;
            margin-bottom: 35px !important;
        }
		   
        #uonix-cookie-root #cookie-banner a[href*="#"]::after {
            content: "" !important; position: absolute !important; left: 0 !important; right: 0 !important; bottom: -2px !important; height: 1.5px !important;
            background: rgba(247, 106, 12, 0.4) !important;
            transform: scaleX(0); transform-origin: left center !important; transition: transform .25s ease !important;
        }
        #uonix-cookie-root #cookie-banner a[href*="#"]:hover::after { transform: scaleX(1) !important; }
        
		   
		   
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

        /* Container dos Botões Principais */
        #uonix-cookie-root #cookie-banner div:has(> #adopt-reject-all-button) {
            display: flex !important; 
            align-items: center !important; 
            justify-content: flex-end !important; 
            gap: 10px !important; 
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

        /* "Não venda" não recebe ID do AdOpt; mantém o mesmo raio do Aceitar. */
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

    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer',<?php echo wp_json_encode( $gtm_container_id ); ?>);</script>
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
    ?>
    
    <div id="uonix-cookie-root" aria-live="polite"></div>

    <noscript><iframe src="<?php echo esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $gtm_container_id ) ); ?>"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

    <script>
    (function() {
        if (window.__uonixCookieRootInit) return;
        window.__uonixCookieRootInit = true;

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


