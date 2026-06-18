<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Login - tela personalizada UONIX.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 16819-17963 do export original.
// -----------------------------------------------------------------------------
/**
 * Tela login
 */
/**
 * Tela de login personalizada UONIX.
 * Inclui login normal, senha perdida e login intermediário do painel.
 */

if ( ! defined('UONIX_CUSTOM_LOGIN_SNIPPET_LOADED') ) {
    define('UONIX_CUSTOM_LOGIN_SNIPPET_LOADED', true);

    add_filter('login_headerurl', function () {
        return home_url('/');
    });

    add_filter('login_headertext', function () {
        return get_bloginfo('name');
    });

    if ( ! function_exists('uonix_is_interim_login_screen') ) {
        function uonix_is_interim_login_screen() {
            global $interim_login;

            return (
                ! empty($interim_login)
                || isset($_GET['interim-login'])
                || isset($_REQUEST['interim-login'])
            );
        }
    }

    add_action('login_footer', function () {
        $is_interim_login = uonix_is_interim_login_screen();

        if ($is_interim_login) {
            ?>
            <div class="ksio-interim-powered">
                Powered by
                <a href="https://ksio.dev" target="_blank" rel="noopener noreferrer">ksio.dev</a>
            </div>
            <?php
            return;
        }

        $ano_atual   = date_i18n('Y');
        $titulo_site = get_bloginfo('name');
        ?>

        <div class="uonix-login-copyright">
            © <?php echo esc_html($ano_atual); ?> <?php echo esc_html($titulo_site); ?>. Todos os direitos reservados.
        </div>

        <div class="ksio-powered-footer">
            <div class="ksio-powered-line">
                Powered by
                <a href="https://ksio.dev" target="_blank" rel="noopener noreferrer">ksio.dev</a>
            </div>
        </div>

        <script>
            (function () {
                function keepOnlyLast(selector) {
                    var items = document.querySelectorAll(selector);

                    if (!items || items.length <= 1) {
                        return;
                    }

                    items.forEach(function (item, index) {
                        if (index < items.length - 1) {
                            item.remove();
                        }
                    });
                }

                function cleanDuplicatedLoginFooter() {
                    keepOnlyLast('.uonix-login-copyright');
                    keepOnlyLast('.ksio-powered-footer');
                    keepOnlyLast('.ksio-interim-powered');
                }

                cleanDuplicatedLoginFooter();
                document.addEventListener('DOMContentLoaded', cleanDuplicatedLoginFooter);
                window.addEventListener('load', cleanDuplicatedLoginFooter);
            })();
        </script>

        <?php
    }, 999);

    add_action('login_enqueue_scripts', function () {
        $logo_url = '/wp-content/uploads/2026/01/logo-uonix-branco.png';
        ?>
        <style>
            :root {
                --uonix-bg-1: #070a12;
                --uonix-bg-2: #111827;
                --uonix-bg-3: #05070d;

                --uonix-blue: #0e3780;
                --uonix-blue-dark: #0a2a63;
                --uonix-blue-hover: #0b315f;
                --uonix-indigo: #152f6f;
                --uonix-cyan: #0b5c78;

                --uonix-orange: #f76a0b;

                --uonix-text-dark: #0f172a;
                --uonix-text-muted: #64748b;
                --uonix-border: #cbd5e1;
            }

            body.login {
                min-height: 100vh;
                background:
                    radial-gradient(circle at 15% 10%, rgba(74, 108, 247, 0.35), transparent 28%),
                    radial-gradient(circle at 85% 0%, rgba(0, 209, 255, 0.18), transparent 30%),
                    linear-gradient(135deg, var(--uonix-bg-1) 0%, var(--uonix-bg-2) 45%, var(--uonix-bg-3) 100%);
                color: #f8fafc;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            }

            body.login:not(.interim-login) {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
            }

            body.login::before {
                content: "";
                position: fixed;
                inset: 0;
                pointer-events: none;
                background:
                    linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
                background-size: 42px 42px;
                mask-image: linear-gradient(to bottom, rgba(0,0,0,0.75), transparent 75%);
                -webkit-mask-image: linear-gradient(to bottom, rgba(0,0,0,0.75), transparent 75%);
            }

            #login {
                width: 400px !important;
                max-width: calc(100% - 40px);
                padding: 0 !important;
                margin: 0 auto !important;
                position: relative;
                z-index: 2;
            }

            /*
             * Logo UONIX - mesma estratégia do snippet que não cortava.
             */
            .login h1 {
                width: 100% !important;
                margin: 0 0 25px 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            .login h1 a {
                background-image: url('<?php echo esc_url($logo_url); ?>') !important;
                background-size: contain !important;
                background-position: center bottom !important;
                background-repeat: no-repeat !important;
                width: 100% !important;
                height: 82px !important;
                margin: 0 auto 18px !important;
                padding: 0 !important;
                display: block !important;
                text-indent: -9999px;
                overflow: hidden !important;
                box-shadow: none !important;
            }

            .login h1::after {
                content: "Painel de controle";
                display: block;
                margin-top: 0;
                color: rgba(226, 232, 240, 0.78);
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.14em;
                text-align: center;
                text-transform: uppercase;
            }

            /*
             * Card do formulário.
             */
            body.login div#login form#loginform,
            body.login div#login form#lostpasswordform,
            body.login div#login form#registerform,
            body.login div#login form#resetpassform {
                margin-top: 0;
                padding: 34px;
                border: 1px solid rgba(255, 255, 255, 0.58) !important;
                border-radius: 26px;
                background: rgba(255, 255, 255, 0.94) !important;
                box-shadow:
                    0 30px 90px rgba(0, 0, 0, 0.42),
                    inset 0 1px 0 rgba(255, 255, 255, 0.85);
                backdrop-filter: blur(18px);
                -webkit-backdrop-filter: blur(18px);
            }

            body.login label {
                color: #334155 !important;
                font-size: 13px;
                font-weight: 700;
            }

            body.login .forgetmenot label {
                color: var(--uonix-text-muted) !important;
                font-weight: 600;
            }

            body.login form .input,
            body.login input[type="text"],
            body.login input[type="password"],
            body.login input[type="email"] {
                min-height: 48px;
                margin-top: 8px;
                padding: 10px 14px;
                color: var(--uonix-text-dark) !important;
                border: 1px solid var(--uonix-border) !important;
                border-radius: 14px;
                background: #f8fafc !important;
                box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06);
                outline: none;
            }

            body.login form .input:focus,
            body.login input[type="text"]:focus,
            body.login input[type="password"]:focus,
            body.login input[type="email"]:focus {
                border-color: var(--uonix-blue) !important;
                background: #ffffff !important;
                box-shadow:
                    0 0 0 4px rgba(14, 55, 128, 0.17),
                    inset 0 1px 2px rgba(15, 23, 42, 0.04);
            }

            body.login .dashicons-visibility,
            body.login .dashicons-hidden {
                color: var(--uonix-blue);
            }

            body.login .button.wp-hide-pw {
                color: var(--uonix-blue) !important;
                border: 0 !important;
                box-shadow: none !important;
                background: transparent !important;
            }

            /*
             * Botão principal - azul mais escuro.
             */
            .wp-core-ui .button-primary {
                min-height: 46px;
                padding: 0 22px;
                border: 0 !important;
                border-radius: 14px !important;
                background: linear-gradient(135deg, var(--uonix-blue-dark) 0%, var(--uonix-blue) 58%, var(--uonix-cyan) 100%) !important;
                color: #ffffff !important;
                font-weight: 800;
                letter-spacing: -0.01em;
                box-shadow: 0 16px 36px rgba(10, 42, 99, 0.36);
                transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
            }

            .wp-core-ui .button-primary:hover,
            .wp-core-ui .button-primary:focus {
                background: linear-gradient(135deg, #081f4d 0%, var(--uonix-blue-hover) 58%, #084d68 100%) !important;
                transform: translateY(-1px);
                filter: brightness(1.04);
                box-shadow: 0 20px 44px rgba(10, 42, 99, 0.46);
            }

            /*
             * Links abaixo do card.
             */
            .login #nav,
            .login #backtoblog {
                margin-top: 22px;
                text-align: center;
            }

            .login #nav a,
            .login #backtoblog a,
            .login .privacy-policy-page-link a {
                color: rgba(226, 232, 240, 0.88) !important;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                text-shadow: 0 1px 10px rgba(0, 0, 0, 0.35);
            }

            .login #nav a:hover,
            .login #backtoblog a:hover,
            .login .privacy-policy-page-link a:hover {
                color: #ffffff !important;
                text-decoration: underline;
            }

            /*
             * Mensagens.
             */
            .login .message,
            .login .notice,
            .login .success {
                border-left: 4px solid var(--uonix-blue) !important;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.96) !important;
                color: #334155 !important;
                box-shadow: 0 18px 45px rgba(0, 0, 0, 0.24);
            }

            .login #login_error {
                border-left: 4px solid #f43f5e !important;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.96) !important;
                color: #7f1d1d !important;
                box-shadow: 0 18px 45px rgba(0, 0, 0, 0.24);
            }

            .login .message a,
            .login .notice a,
            .login .success a,
            .login #login_error a {
                color: var(--uonix-blue) !important;
                font-weight: 700;
            }

            /*
             * Oculta seletor/card de idioma.
             */
            .login .language-switcher,
            #language-switcher {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                overflow: hidden !important;
            }

            /*
             * Rodapé da tela de login normal.
             */
            .uonix-login-copyright {
                position: fixed;
                left: 24px;
                bottom: 22px;
                z-index: 5;
                color: rgba(226, 232, 240, 0.76);
                font-size: 13px;
                line-height: 1.5;
                white-space: nowrap;
                text-shadow: 0 1px 14px rgba(0, 0, 0, 0.45);
            }

            .ksio-powered-footer {
                position: fixed;
                right: 24px;
                bottom: 18px;
                z-index: 5;
                color: rgba(226, 232, 240, 0.78);
                font-size: 13px;
                line-height: 1.6;
                background: transparent;
                box-sizing: border-box;
                text-shadow: 0 1px 14px rgba(0, 0, 0, 0.45);
            }

            .ksio-powered-line {
                text-align: right;
                opacity: 0.96;
                white-space: nowrap;
            }

            .ksio-powered-line a {
                color: #ffffff;
                font-size: 18px;
                font-weight: 800;
                letter-spacing: -0.03em;
                text-decoration: none;
                transition: color 0.18s ease;
            }

            .ksio-powered-line a:hover {
                color: var(--uonix-orange) !important;
                text-decoration: none;
            }

            /*
             * Login intermediário dentro do modal de sessão expirada.
             */
            body.login.interim-login {
                min-height: 100vh;
                overflow: hidden !important;
                background:
                    radial-gradient(circle at 20% 0%, rgba(74, 108, 247, 0.26), transparent 34%),
                    radial-gradient(circle at 90% 10%, rgba(0, 209, 255, 0.16), transparent 32%),
                    linear-gradient(135deg, #080b14 0%, #101827 55%, #05070d 100%) !important;
            }

            body.login.interim-login::before {
                background-size: 34px 34px;
                opacity: 0.65;
            }

            body.login.interim-login #login {
                width: 440px !important;
                max-width: calc(100% - 36px);
                padding: 34px 0 18px !important;
            }

            body.login.interim-login h1 {
                width: 100% !important;
                margin: 0 0 22px 0 !important;
                padding: 0 !important;
                position: static;
                left: auto;
                transform: none;
                overflow: visible !important;
            }

            body.login.interim-login h1 a {
                width: 100% !important;
                height: 82px !important;
                background-size: contain !important;
                background-position: center bottom !important;
                margin-bottom: 18px !important;
                overflow: hidden !important;
            }

            body.login.interim-login h1::after {
                content: "Sessão expirada";
                margin-top: 0;
                color: rgba(226, 232, 240, 0.82);
                font-size: 12px;
                letter-spacing: 0.16em;
            }

            body.login.interim-login .message,
            body.login.interim-login .notice,
            body.login.interim-login #login_error {
                margin: 0 0 16px;
                padding: 16px 20px;
                font-size: 14px;
                line-height: 1.5;
            }

            body.login.interim-login div#login form#loginform {
                padding: 30px 34px !important;
                border-radius: 26px !important;
                box-shadow:
                    0 26px 70px rgba(0, 0, 0, 0.38),
                    inset 0 1px 0 rgba(255, 255, 255, 0.85) !important;
            }

            body.login.interim-login form p {
                margin-bottom: 16px;
            }

            body.login.interim-login form .input,
            body.login.interim-login input[type="text"],
            body.login.interim-login input[type="password"],
            body.login.interim-login input[type="email"] {
                min-height: 46px;
                font-size: 18px;
            }

            body.login.interim-login .forgetmenot {
                margin-top: 2px;
            }

            body.login.interim-login .submit {
                margin-top: 0;
            }

            body.login.interim-login #nav,
            body.login.interim-login #backtoblog,
            body.login.interim-login .privacy-policy-page-link,
            body.login.interim-login .uonix-login-copyright,
            body.login.interim-login .ksio-powered-footer {
                display: none !important;
            }

            body.login.interim-login .ksio-interim-powered {
                position: relative;
                z-index: 5;
                margin: 14px auto 0;
                width: 440px;
                max-width: calc(100% - 36px);
                color: rgba(226, 232, 240, 0.72);
                font-size: 12px;
                line-height: 1.5;
                text-align: center;
                text-shadow: 0 1px 12px rgba(0, 0, 0, 0.45);
            }

            body.login.interim-login .ksio-interim-powered a {
                color: #ffffff;
                font-size: 15px;
                font-weight: 800;
                letter-spacing: -0.03em;
                text-decoration: none;
                transition: color 0.18s ease;
            }

            body.login.interim-login .ksio-interim-powered a:hover {
                color: var(--uonix-orange) !important;
                text-decoration: none;
            }

            @media (max-width: 768px) {
                body.login:not(.interim-login) {
                    justify-content: flex-start;
                    padding-top: 4vh;
                    box-sizing: border-box;
                }

                #login {
                    width: 400px !important;
                    max-width: calc(100% - 40px);
                    padding: 0 !important;
                }

                .login h1 a {
                    height: 78px !important;
                }

                body.login div#login form#loginform,
                body.login div#login form#lostpasswordform,
                body.login div#login form#registerform,
                body.login div#login form#resetpassform {
                    padding: 28px 24px;
                    border-radius: 22px;
                }

                .uonix-login-copyright,
                .ksio-powered-footer {
                    position: static;
                    width: 100%;
                    padding: 6px 20px;
                    text-align: center;
                    white-space: normal;
                }

                .uonix-login-copyright {
                    margin-top: 28px;
                }

                .ksio-powered-line {
                    text-align: center;
                    white-space: normal;
                }

                body.login.interim-login {
                    overflow: auto !important;
                }

                body.login.interim-login #login {
                    width: 400px !important;
                    padding-top: 24px !important;
                }

                body.login.interim-login h1 a {
                    height: 76px !important;
                    background-size: contain !important;
                }

                body.login.interim-login div#login form#loginform {
                    padding: 26px 24px !important;
                }
            }
        </style>
        <?php
    }, 99);

    add_action('admin_head', function () {
		return;
        ?>
        <style>
    :root {
        --uonix-bg-1: #070a12;
        --uonix-bg-2: #111827;
        --uonix-bg-3: #05070d;

        --uonix-blue: #0e3780;
        --uonix-blue-dark: #0a2a63;
        --uonix-blue-hover: #0b315f;
        --uonix-indigo: #152f6f;
        --uonix-cyan: #0b5c78;

        --uonix-orange: #f76a0b;

        --uonix-text-dark: #0f172a;
        --uonix-text-muted: #64748b;
        --uonix-border: #cbd5e1;
    }

    html {
        min-height: 100%;
        background: #05070d !important;
    }

    body.login {
        min-height: 100vh;
        background:
            radial-gradient(circle at 15% 10%, rgba(74, 108, 247, 0.35), transparent 28%),
            radial-gradient(circle at 85% 0%, rgba(0, 209, 255, 0.18), transparent 30%),
            linear-gradient(135deg, var(--uonix-bg-1) 0%, var(--uonix-bg-2) 45%, var(--uonix-bg-3) 100%);
        background-color: #05070d !important;
        background-repeat: no-repeat !important;
        background-size: cover !important;
        color: #f8fafc;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }

    body.login:not(.interim-login) {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
    }

    body.login::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
        background-size: 42px 42px;
        mask-image: linear-gradient(to bottom, rgba(0,0,0,0.75), transparent 75%);
        -webkit-mask-image: linear-gradient(to bottom, rgba(0,0,0,0.75), transparent 75%);
    }

    #login {
        width: 400px !important;
        max-width: calc(100% - 40px);
        padding: 0 !important;
        margin: 0 auto !important;
        position: relative;
        z-index: 2;
    }

    /*
     * Logo UONIX
     */
    .login h1 {
        width: 100% !important;
        margin: 0 0 25px 0 !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    .login h1 a {
        background-image: url('<?php echo esc_url($logo_url); ?>') !important;
        background-size: contain !important;
        background-position: center bottom !important;
        background-repeat: no-repeat !important;
        width: 100% !important;
        height: 82px !important;
        margin: 0 auto 18px !important;
        padding: 0 !important;
        display: block !important;
        text-indent: -9999px;
        overflow: hidden !important;
        box-shadow: none !important;
    }

    .login h1::after {
        content: "Acesso seguro";
        display: block;
        margin-top: 0;
        color: rgba(226, 232, 240, 0.78);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.14em;
        text-align: center;
        text-transform: uppercase;
    }

    /*
     * Card do formulário
     */
    body.login div#login form#loginform,
    body.login div#login form#lostpasswordform,
    body.login div#login form#registerform,
    body.login div#login form#resetpassform {
        margin-top: 0;
        padding: 34px;
        border: 1px solid rgba(255, 255, 255, 0.58) !important;
        border-radius: 26px;
        background: rgba(255, 255, 255, 0.94) !important;
        box-shadow:
            0 30px 90px rgba(0, 0, 0, 0.42),
            inset 0 1px 0 rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    body.login label {
        color: #334155 !important;
        font-size: 13px;
        font-weight: 700;
    }

    body.login .forgetmenot label {
        color: var(--uonix-text-muted) !important;
        font-weight: 600;
    }

    body.login form .input,
    body.login input[type="text"],
    body.login input[type="password"],
    body.login input[type="email"] {
        min-height: 48px;
        margin-top: 8px;
        padding: 10px 14px;
        color: var(--uonix-text-dark) !important;
        border: 1px solid var(--uonix-border) !important;
        border-radius: 14px;
        background: #f8fafc !important;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06);
        outline: none;
    }

    body.login form .input:focus,
    body.login input[type="text"]:focus,
    body.login input[type="password"]:focus,
    body.login input[type="email"]:focus {
        border-color: var(--uonix-blue) !important;
        background: #ffffff !important;
        box-shadow:
            0 0 0 4px rgba(14, 55, 128, 0.17),
            inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    body.login .dashicons-visibility,
    body.login .dashicons-hidden {
        color: var(--uonix-blue);
    }

    body.login .button.wp-hide-pw {
        color: var(--uonix-blue) !important;
        border: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    /*
     * Botão principal
     */
    .wp-core-ui .button-primary {
        min-height: 46px;
        padding: 0 22px;
        border: 0 !important;
        border-radius: 14px !important;
        background: linear-gradient(135deg, var(--uonix-blue-dark) 0%, var(--uonix-blue) 58%, var(--uonix-cyan) 100%) !important;
        color: #ffffff !important;
        font-weight: 800;
        letter-spacing: -0.01em;
        box-shadow: 0 16px 36px rgba(10, 42, 99, 0.36);
        transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
    }

    .wp-core-ui .button-primary:hover,
    .wp-core-ui .button-primary:focus {
        background: linear-gradient(135deg, #081f4d 0%, var(--uonix-blue-hover) 58%, #084d68 100%) !important;
        transform: translateY(-1px);
        filter: brightness(1.04);
        box-shadow: 0 20px 44px rgba(10, 42, 99, 0.46);
    }

    /*
     * Links abaixo do card
     */
    .login #nav,
    .login #backtoblog {
        margin-top: 22px;
        text-align: center;
    }

    .login #nav a,
    .login #backtoblog a,
    .login .privacy-policy-page-link a {
        color: rgba(226, 232, 240, 0.88) !important;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        text-shadow: 0 1px 10px rgba(0, 0, 0, 0.35);
    }

    .login #nav a:hover,
    .login #backtoblog a:hover,
    .login .privacy-policy-page-link a:hover {
        color: #ffffff !important;
        text-decoration: underline;
    }

    /*
     * Mensagens
     */
    .login .message,
    .login .notice,
    .login .success {
        border-left: 4px solid var(--uonix-blue) !important;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.96) !important;
        color: #334155 !important;
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.24);
    }

    .login #login_error {
        border-left: 4px solid #f43f5e !important;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.96) !important;
        color: #7f1d1d !important;
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.24);
    }

    .login .message a,
    .login .notice a,
    .login .success a,
    .login #login_error a {
        color: var(--uonix-blue) !important;
        font-weight: 700;
    }

    /*
     * Oculta seletor/card de idioma
     */
    .login .language-switcher,
    #language-switcher {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        overflow: hidden !important;
    }

    /*
     * Rodapé da tela de login normal
     */
    .uonix-login-copyright {
        position: fixed;
        left: 24px;
        bottom: 22px;
        z-index: 5;
        color: rgba(226, 232, 240, 0.76);
        font-size: 13px;
        line-height: 1.5;
        white-space: nowrap;
        text-shadow: 0 1px 14px rgba(0, 0, 0, 0.45);
    }

    .ksio-powered-footer {
        position: fixed;
        right: 24px;
        bottom: 18px;
        z-index: 5;
        color: rgba(226, 232, 240, 0.78);
        font-size: 13px;
        line-height: 1.6;
        background: transparent;
        box-sizing: border-box;
        text-shadow: 0 1px 14px rgba(0, 0, 0, 0.45);
    }

    .ksio-powered-line {
        text-align: right;
        opacity: 0.96;
        white-space: nowrap;
    }

    .ksio-powered-line a {
        color: #ffffff;
        font-size: 18px;
        font-weight: 800;
        letter-spacing: -0.03em;
        text-decoration: none;
        transition: color 0.18s ease;
    }

    .ksio-powered-line a:hover {
        color: var(--uonix-orange) !important;
        text-decoration: none;
    }

    /*
     * Login intermediário dentro do modal de sessão expirada
     */
    body.login.interim-login {
        min-height: 100vh;
        overflow: hidden !important;
        background:
            radial-gradient(circle at 20% 0%, rgba(74, 108, 247, 0.26), transparent 34%),
            radial-gradient(circle at 90% 10%, rgba(0, 209, 255, 0.16), transparent 32%),
            linear-gradient(135deg, #080b14 0%, #101827 55%, #05070d 100%) !important;
    }

    body.login.interim-login::before {
        background-size: 34px 34px;
        opacity: 0.65;
    }

    body.login.interim-login #login {
        width: 440px !important;
        max-width: calc(100% - 36px);
        padding: 34px 0 18px !important;
    }

    body.login.interim-login h1 {
        width: 100% !important;
        margin: 0 0 22px 0 !important;
        padding: 0 !important;
        position: static;
        left: auto;
        transform: none;
        overflow: visible !important;
    }

    body.login.interim-login h1 a {
        width: 100% !important;
        height: 82px !important;
        background-size: contain !important;
        background-position: center bottom !important;
        margin-bottom: 18px !important;
        overflow: hidden !important;
    }

    body.login.interim-login h1::after {
        content: "Sessão expirada";
        margin-top: 0;
        color: rgba(226, 232, 240, 0.82);
        font-size: 12px;
        letter-spacing: 0.16em;
    }

    body.login.interim-login .message,
    body.login.interim-login .notice,
    body.login.interim-login #login_error {
        margin: 0 0 16px;
        padding: 16px 20px;
        font-size: 14px;
        line-height: 1.5;
    }

    body.login.interim-login div#login form#loginform {
        padding: 30px 34px !important;
        border-radius: 26px !important;
        box-shadow:
            0 26px 70px rgba(0, 0, 0, 0.38),
            inset 0 1px 0 rgba(255, 255, 255, 0.85) !important;
    }

    body.login.interim-login form p {
        margin-bottom: 16px;
    }

    body.login.interim-login form .input,
    body.login.interim-login input[type="text"],
    body.login.interim-login input[type="password"],
    body.login.interim-login input[type="email"] {
        min-height: 46px;
        font-size: 18px;
    }

    body.login.interim-login .forgetmenot {
        margin-top: 2px;
    }

    body.login.interim-login .submit {
        margin-top: 0;
    }

    body.login.interim-login #nav,
    body.login.interim-login #backtoblog,
    body.login.interim-login .privacy-policy-page-link,
    body.login.interim-login .uonix-login-copyright,
    body.login.interim-login .ksio-powered-footer {
        display: none !important;
    }

    body.login.interim-login .ksio-interim-powered {
        position: relative;
        z-index: 5;
        margin: 14px auto 0;
        width: 440px;
        max-width: calc(100% - 36px);
        color: rgba(226, 232, 240, 0.72);
        font-size: 12px;
        line-height: 1.5;
        text-align: center;
        text-shadow: 0 1px 12px rgba(0, 0, 0, 0.45);
    }

    body.login.interim-login .ksio-interim-powered a {
        color: #ffffff;
        font-size: 15px;
        font-weight: 800;
        letter-spacing: -0.03em;
        text-decoration: none;
        transition: color 0.18s ease;
    }

    body.login.interim-login .ksio-interim-powered a:hover {
        color: var(--uonix-orange) !important;
        text-decoration: none;
    }

    /*
     * Mobile
     */
    @media (max-width: 768px) {
        body.login:not(.interim-login) {
            min-height: 100svh !important;
            height: 100svh !important;
            overflow: hidden !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex-direction: column !important;
            padding: 14px 0 72px !important;
            box-sizing: border-box !important;
            background:
                radial-gradient(circle at 15% 10%, rgba(74, 108, 247, 0.35), transparent 28%),
                radial-gradient(circle at 85% 0%, rgba(0, 209, 255, 0.18), transparent 30%),
                linear-gradient(135deg, #070a12 0%, #111827 45%, #05070d 100%) !important;
            background-size: cover !important;
            background-repeat: no-repeat !important;
        }

        @supports (height: 100dvh) {
            body.login:not(.interim-login) {
                min-height: 100dvh !important;
                height: 100dvh !important;
            }
        }

        body.login:not(.interim-login) #login {
            width: 400px !important;
            max-width: calc(100vw - 36px) !important;
            padding: 0 !important;
            margin: 0 auto !important;
            transform: translateY(-8px);
        }

        body.login:not(.interim-login) h1 {
            margin-bottom: 16px !important;
        }

        body.login:not(.interim-login) h1 a {
            height: 66px !important;
            margin-bottom: 12px !important;
        }

        body.login:not(.interim-login) h1::after {
            font-size: 11px !important;
            margin-top: 0 !important;
        }

        body.login:not(.interim-login) div#login form#loginform,
        body.login:not(.interim-login) div#login form#lostpasswordform,
        body.login:not(.interim-login) div#login form#registerform,
        body.login:not(.interim-login) div#login form#resetpassform {
            padding: 24px 24px !important;
            border-radius: 24px !important;
        }

        body.login:not(.interim-login) form .input,
        body.login:not(.interim-login) input[type="text"],
        body.login:not(.interim-login) input[type="password"],
        body.login:not(.interim-login) input[type="email"] {
            min-height: 44px !important;
        }

        body.login:not(.interim-login) #nav,
        body.login:not(.interim-login) #backtoblog {
            margin-top: 14px !important;
        }

        body.login:not(.interim-login) .uonix-login-copyright {
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 44px !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 18px !important;
            font-size: 12px !important;
            line-height: 1.4 !important;
            text-align: center !important;
            white-space: normal !important;
            box-sizing: border-box !important;
        }

        body.login:not(.interim-login) .ksio-powered-footer {
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 16px !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 18px !important;
            text-align: center !important;
            box-sizing: border-box !important;
        }

        body.login:not(.interim-login) .ksio-powered-line {
            text-align: center !important;
            white-space: normal !important;
        }

        body.login:not(.interim-login) .ksio-powered-line a {
            font-size: 17px !important;
        }

        body.login.interim-login {
            overflow: auto !important;
        }

        body.login.interim-login #login {
            width: 400px !important;
            padding-top: 24px !important;
        }

        body.login.interim-login h1 a {
            height: 76px !important;
            background-size: contain !important;
        }

        body.login.interim-login div#login form#loginform {
            padding: 26px 24px !important;
        }
    }
</style>
        <?php
    }, 99);
}


