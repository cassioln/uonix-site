<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Footer - copyright customizado.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 16763-16818 do export original.
// -----------------------------------------------------------------------------
/**
 * copyright shortcode
 */
/**
 * Rodapé personalizado com copyright.
 * Usa classes próprias para não conflitar com o bloco "Powered by ksio.dev".
 */

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }

    $ano_atual   = date_i18n('Y');
    $titulo_site = get_bloginfo('name');
    ?>
    <div class="uonix-copyright-footer">
        <div class="uonix-copyright-inner">
            <nav class="uonix-copyright-nav" aria-label="Informações legais e privacidade">
                <ul class="uonix-copyright-links">
                    <li class="uonix-copyright-item">
                        <a href="<?php echo esc_url(home_url('/politica-de-privacidade/')); ?>" class="uonix-copyright-link">Política de Privacidade</a>
                    </li>
                    <li class="uonix-copyright-separator uonix-sep-1" aria-hidden="true">•</li>
                    <li class="uonix-copyright-item">
                        <a href="<?php echo esc_url(home_url('/termos-de-uso/')); ?>" class="uonix-copyright-link">Termos de Uso</a>
                    </li>
                    <li class="uonix-copyright-break" aria-hidden="true"></li>
                    <li class="uonix-copyright-separator uonix-sep-2" aria-hidden="true">•</li>
                    <li class="uonix-copyright-item">
                        <a href="<?php echo esc_url(home_url('/politica-de-cookies/')); ?>" class="uonix-copyright-link">Política de Cookies</a>
                    </li>
                    <li class="uonix-copyright-separator uonix-sep-3" aria-hidden="true">•</li>
                    <li class="uonix-copyright-item open-adopt-modal">
                        <a href="#" class="uonix-copyright-link open-adopt-modal" role="button" data-adopt-trigger="true" title="Revisar preferências de cookies">
                            <svg class="uonix-cookie-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"></path><path d="M8.5 8.5v.01"></path><path d="M16 15.5v.01"></path><path d="M12 12v.01"></path><path d="M11 17v.01"></path><path d="M7 14v.01"></path></svg>
                            Revisar preferências de cookies
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="uonix-copyright-text">
                © <?php echo esc_html($ano_atual); ?> <?php echo esc_html($titulo_site); ?>. Todos os direitos reservados.
            </div>
        </div>
    </div>
    <script>
    (function() {
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.open-adopt-modal, [data-adopt-trigger="true"]');
            if (!trigger) {
                return;
            }

            // Apenas intercepta se for o link de ação (href="#" ou role="button")
            if (trigger.tagName === 'A' && trigger.getAttribute('href') === '#') {
                e.preventDefault();
                e.stopPropagation();

                var adoptBtn = document.getElementById('adopt-controller-button')
                            || document.querySelector('.adopt-c-button')
                            || document.querySelector('[data-adopt-controller]');

                if (adoptBtn) {
                    adoptBtn.click();
                } else if (window.AdoptVisitor && typeof window.AdoptVisitor.openPreferences === 'function') {
                    window.AdoptVisitor.openPreferences();
                } else {
                    console.info('[Uonix AdOpt] Botão do AdOpt não detectado nesta página.');
                }
            }
        }, true);
    })();
    </script>
    <?php
}, 98);

add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <style>
        .uonix-copyright-footer {
            width: 100%;
            padding: 14px 20px;
            font-size: 13px;
            line-height: 1.6;
            color: #cbd5e1;
            background: #0d1117;
            box-sizing: border-box;
            border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
            box-shadow: none !important;
        }

        .uonix-copyright-inner {
            width: 100%;
            margin: 0 !important;
            padding: 0 !important;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            box-sizing: border-box;
        }

        .uonix-copyright-nav {
            margin: 0 !important;
            margin-left: 0 !important;
            padding: 0 !important;
            display: flex;
            align-items: center;
            z-index: 2;
        }

        .uonix-copyright-links {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 12px;
        }

        .uonix-copyright-break {
            display: none;
        }

        .uonix-copyright-item {
            display: inline-flex;
            align-items: center;
            margin: 0 !important;
            padding: 0 !important;
            white-space: nowrap !important;
        }

        .uonix-copyright-link {
            color: #cbd5e1 !important;
            text-decoration: none !important;
            font-size: 12.5px;
            font-weight: 500;
            transition: color 0.18s ease, text-decoration 0.18s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap !important;
        }

        .uonix-cookie-icon {
            display: inline-block;
            vertical-align: middle;
            opacity: 0.85;
            transition: transform 0.2s ease, opacity 0.2s ease;
            flex-shrink: 0;
        }

        .uonix-copyright-link:hover,
        .uonix-copyright-link:focus-visible {
            color: #f76a0c !important;
            text-decoration: underline !important;
            text-underline-offset: 3px;
            outline: none;
        }

        .uonix-copyright-link:hover .uonix-cookie-icon {
            opacity: 1;
            transform: rotate(15deg);
        }

        .uonix-copyright-separator {
            color: rgba(255, 255, 255, 0.2);
            font-size: 10px;
            user-select: none;
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
        }

        .uonix-copyright-text {
            color: #94a3b8;
            font-size: 12.5px;
            margin: 0;
            white-space: nowrap !important;
        }

        /* Desktop amplo (>= 1650px): Links à esquerda e copyright perfeitamente centralizado */
        @media (min-width: 1650px) {
            .uonix-copyright-text {
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
                text-align: center;
                pointer-events: auto;
            }
        }

        /* Tablet (< 1650px até 950px): Links no lado esquerdo, Copyright no lado direito */
        @media (max-width: 1649px) and (min-width: 950px) {
            .uonix-copyright-inner {
                justify-content: space-between;
                gap: 16px;
            }

            .uonix-copyright-nav {
                order: 1;
                margin-left: 0 !important;
                justify-content: flex-start;
                flex: 1 1 auto;
            }

            .uonix-copyright-text {
                order: 2;
                text-align: right;
                font-size: 12px;
                position: static;
                transform: none;
                margin-left: auto;
                flex-shrink: 0;
            }
        }

        /* Mobile (< 950px): Design harmonioso, simétrico e centralizado */
        @media (max-width: 949px) {
            .uonix-copyright-footer {
                padding: 18px 16px 16px;
            }

            .uonix-copyright-inner {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 12px;
            }

            .uonix-copyright-nav {
                width: 100%;
                justify-content: center;
                overflow: visible !important;
            }

            .uonix-copyright-links {
                justify-content: center;
                align-items: center;
                flex-wrap: wrap !important;
                gap: 8px 14px;
                max-width: 480px;
                margin: 0 auto !important;
            }

            .uonix-copyright-break {
                display: block;
                flex-basis: 100%;
                height: 0;
                margin: 0;
            }

            .uonix-sep-2 {
                display: none !important;
            }

            .uonix-copyright-item {
                display: inline-flex;
                align-items: center;
            }

            .uonix-copyright-link {
                color: #cbd5e1 !important;
                font-size: 12.5px;
                padding: 4px 6px;
                background: transparent !important;
            }

            .uonix-copyright-separator {
                color: rgba(255, 255, 255, 0.25);
                font-size: 11px;
                display: inline-flex;
            }

            .uonix-copyright-text {
                position: static;
                transform: none;
                width: 100%;
                max-width: 480px;
                text-align: center;
                color: #8b949e;
                font-size: 12px;
                line-height: 1.5;
                padding-top: 10px;
                border-top: 1px solid rgba(255, 255, 255, 0.06);
                margin: 0 auto;
                white-space: normal !important;
            }
        }
    </style>
    <?php
}, 99);


