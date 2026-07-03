<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - WooCommerce - design premium do checkout.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 4726-5065 do export original.
// -----------------------------------------------------------------------------
/**
 * Formulario Finalizacao Orcamento
 */
/**
 * UÔNIX: Checkout Design Premium V2.4
 * ---------------------------------------------------------
 * - Transforma seções no Padrão Premium (Barra Laranja e Texto Azul).
 * - Reestrutura tabela de pedido com coluna Qtd.
 * - Incorpora Checkboxes Premium Laranja.
 * - Atualiza botões e inputs para o novo padrão de bordas arredondadas (6px).
 * - Validação de erro com Borda Vermelha no campo (Inputs).
 * - Caixa de Alerta de Erros Premium (Minimalista).
 * - Topo dinâmico: Muda para vermelho com ícone de warning ao falhar.
 * - Adiciona Loading Spinner ao botão de enviar.
 * - Limpa nomes de produtos (<br> por -).
 */

if ( ! function_exists( 'uonix_checkout_turnstile_widget_html' ) ) {
    function uonix_checkout_turnstile_widget_html() {
        if ( ! function_exists( 'uonix_turnstile_render_widget' ) ) {
            return '';
        }

        if ( ! apply_filters( 'uonix_turnstile_protect_woocommerce_checkout', true ) ) {
            return '';
        }

        $widget = uonix_turnstile_render_widget(
            'woocommerce_checkout',
            array(
                'theme'      => 'light',
                'appearance' => 'interaction-only',
                'size'       => 'flexible',
            )
        );

        if ( '' === $widget ) {
            return '';
        }

        return '<div class="uonix-checkout-turnstile" aria-label="Verificação de segurança">' . $widget . '</div>';
    }
}

add_action( 'woocommerce_review_order_before_submit', function() {
    if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }

    echo uonix_checkout_turnstile_widget_html();
}, 8 );

add_action( 'woocommerce_after_checkout_validation', function( $data, $errors ) {
    if ( ! apply_filters( 'uonix_turnstile_protect_woocommerce_checkout', true ) ) {
        return;
    }

    if ( ! function_exists( 'uonix_turnstile_validate_request' ) || ! function_exists( 'uonix_turnstile_is_enabled' ) || ! uonix_turnstile_is_enabled() ) {
        return;
    }

    $validation = uonix_turnstile_validate_request( 'woocommerce_checkout' );

    if ( is_wp_error( $validation ) ) {
        $errors->add( 'uonix_turnstile_checkout_failed', $validation->get_error_message() );
    }
}, 10, 2 );

add_action('wp_footer', function () {
    if (!is_checkout() || is_wc_endpoint_url('order-received')) {
        return;
    }
    ?>
    <style id="uonix-checkout-premium-css">
        /* ==========================================================================
           1. ESTILO DOS TÍTULOS DE SEÇÃO E MENSAGEM DO TOPO
           ========================================================================== */
        
        /* Mensagem de topo (Padrão: Laranja) */
        .woo-orcamento .woocommerce::before {
            content: "Informe os dados para orçamento";
            display: block;
            color: #f76a0c; /* Laranja Uônix */
            text-align: left;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
            transition: all 0.3s ease; /* Transição suave de cor */
        }

        /* Mensagem de topo (Com Erro: Vermelho + Ícone SVG) */
        .woocommerce:has(.woocommerce-error)::before,
        body.uonix-has-error .woo-orcamento .woocommerce::before {
            color: #dc2626 !important; /* Vermelho moderno */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512' fill='%23dc2626'%3E%3Cpath d='M256 32c14.2 0 27.3 7.5 34.5 19.8l216 368c7.3 12.4 7.3 27.7 .2 40.1S486.3 480 472 480H40c-14.3 0-27.6-7.7-34.7-20.1s-7-27.8 .2-40.1l216-368C228.7 39.5 241.8 32 256 32zm0 128c-13.3 0-24 10.7-24 24V296c0 13.3 10.7 24 24 24s24-10.7 24-24V184c0-13.3-10.7-24-24-24zm32 224a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: left center;
            background-size: 16px; /* Tamanho do ícone */
            padding-left: 24px; /* Empurra o texto para a direita para caber o ícone */
			margin-bottom: 20px !important; 
        }

        /* Títulos (Informações para contato, Seu pedido, etc) */
        .woocommerce-checkout h3 {
            background: transparent !important;
            color: #0e3780 !important; /* Azul Corporativo */
            padding: 0 0 0 15px !important;
            border-left: 6px solid #f76a0c !important; /* Barra Laranja Premium */
            font-size: 24px !important;
            font-weight: 900 !important;
            text-transform: uppercase !important;
            letter-spacing: -0.5px !important;
            margin: 40px 0 25px 0 !important;
            line-height: 1.2 !important;
            width: 100%;
            box-sizing: border-box;
        }

        /* Limpeza de espaçamentos nativos */
        .single-content h3 + * { padding-left: 10px; }
        .woocommerce-checkout-review-order { padding-left: 0 !important; }
        .woocommerce-terms-and-conditions-wrapper { margin-top: 0 !important; padding-left: 10px; }

        /* Mensagens de Erro Inline (Abaixo do campo) */
        .checkout-inline-error-message {
            color: #dc2626 !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            margin-top: 4px !important;
        }

        /* Textos de Termos de Uso */
        .woocommerce-terms-and-conditions-checkbox-text {
            font-size: 15px !important;
            color: #475569 !important;
        }

        /* ==========================================================================
           2. TABELA DE REVISÃO DO PEDIDO
           ========================================================================== */
        .woocommerce-checkout-review-order-table {
            border-collapse: collapse !important;
            width: 100% !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            border: 1px solid #e2e8f0 !important;
        }

        .woocommerce-checkout-review-order-table thead th {
            background: #f8fafc !important;
            color: #0e3780 !important;
            text-transform: uppercase;
            font-weight: 800 !important;
            font-size: 13px;
            padding: 15px !important;
            border: none !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }

        .woocommerce-checkout-review-order-table tbody td {
            padding: 15px !important;
            border-bottom: 1px solid #e2e8f0 !important;
            vertical-align: middle;
            color: #1a2b3c;
        }

        .woocommerce-checkout-review-order-table .product-name {
            font-weight: 700;
            font-size: 15px;
        }

        .variation {
            margin-top: 5px !important;
            font-size: 12px !important;
            color: #64748b !important;
            font-weight: 600;
        }

        .variation dt { float: left; margin-right: 5px; color: #475569; }
        .variation dd { margin: 0; }
        .variation dd p { margin: 0; }

        /* Nova Coluna Qtd */
        .uonix-qtd-header { width: 80px; text-align: center !important; }
        .uonix-qtd-cell {
            width: 80px;
            text-align: center !important;
            font-weight: 800;
            color: #0e3780 !important;
            font-size: 16px;
            background: #f8fafc !important;
        }

        /* Oculta Totais e Colunas Vazias */
        .cart-subtotal, .order-total, .product-total,
        .woocommerce-checkout-review-order-table thead th:nth-child(2) {
            display: none !important;
        }

        /* ==========================================================================
           3. CHECKBOXES PREMIUM
           ========================================================================== */
        .woocommerce-checkout input[type="checkbox"] {
            -webkit-appearance: none !important;
            appearance: none !important;
            width: 21px !important;
            height: 21px !important;
            flex-shrink: 0 !important;
            border: 2px solid #cbd5e1 !important;
            border-radius: 4px !important;
            background: #fff !important;
            position: relative !important;
            cursor: pointer;
            margin: 0 !important;
            display: inline-block;
            vertical-align: middle;
            transition: all 0.2s ease;
        }

        .woocommerce-checkout input[type="checkbox"]:checked {
            background-color: #f76a0c !important;
            border-color: #f76a0c !important;
        }

        .woocommerce-checkout input[type="checkbox"]:checked::after {
            content: "" !important;
            position: absolute !important;
            left: 50% !important;
            top: 45% !important;
            transform: translate(-50%, -50%) rotate(45deg) !important;
            width: 5px !important;
            height: 10px !important;
            border: solid white !important;
            border-width: 0 3px 3px 0 !important;
        }

        .woocommerce-form__label-for-checkbox span { margin-left: 10px; font-size: 14px; color: #475569; }

        /* ==========================================================================
           4. CAMPOS DO FORMULÁRIO (INPUTS E SELECTS)
           ========================================================================== */
        .form-row input.input-text, .form-row select, .form-row textarea {
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 12px 15px !important;
            background-color: #ffffff !important;
            color: #1a2b3c !important;
            font-size: 15px !important;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            width: 100%;
        }
        
        .form-row input.input-text:focus, .form-row select:focus, .form-row textarea:focus {
            border-color: #0e3780 !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(14, 55, 128, 0.1) !important;
        }

        /* Validação de Erro Visual */
        .woocommerce-checkout .form-row.woocommerce-invalid label { color: inherit !important; }
        .woocommerce-checkout .form-row.woocommerce-invalid input.input-text,
        .woocommerce-checkout .form-row.woocommerce-invalid select,
        .woocommerce-checkout .form-row.woocommerce-invalid textarea {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 1px #dc2626 !important; 
        }

        /* ==========================================================================
           5. BOTÃO DE ENVIO COM LOADING SPINNER
           ========================================================================== */
        #place_order {
            background-color: #0e3780 !important;
            color: #ffffff !important;
            padding: 18px 30px !important;
            font-size: 16px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border-radius: 6px !important;
            width: 100% !important;
            border: none !important;
            transition: all 0.3s ease !important;
            position: relative;
            box-shadow: 0 4px 12px rgba(14, 55, 128, 0.15) !important;
            margin-top: 15px !important;
        }

        #place_order:hover {
            background-color: #f76a0c !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 15px rgba(247, 106, 12, 0.3) !important;
        }

        #place_order.uonix-loading { color: transparent !important; pointer-events: none !important; }
        #place_order.uonix-loading::after {
            content: "" !important; position: absolute !important; left: 50% !important; top: 50% !important;
            width: 20px !important; height: 20px !important; margin: -10px 0 0 -10px !important;
            border: 3px solid rgba(255, 255, 255, 0.3) !important; border-top-color: #ffffff !important;
            border-radius: 50% !important; animation: uonixRotation 0.8s linear infinite !important;
        }

        @keyframes uonixRotation { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* ==========================================================================
           6. CAIXA DE ALERTA DE ERROS (MINIMALISTA)
           ========================================================================== */
        .woocommerce-NoticeGroup { margin-bottom: 0px !important; width: 100%; }

        ul.woocommerce-error {
            background-color: #ffffff !important;
            border: none !important;
            border-left: 6px solid #dc2626 !important;
            border-radius: 8px !important;
            padding: 20px 24px !important; /* Retirado o padding extra do ícone */
            color: #1a2b3c !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05) !important;
            position: relative;
            list-style: none !important;
            margin: 0 !important;
        }

        ul.woocommerce-error li { margin-bottom: 8px !important; display: block; line-height: 1.5 !important; }
        ul.woocommerce-error li:last-child { margin-bottom: 0 !important; }
        ul.woocommerce-error li a { color: #1a2b3c !important; text-decoration: none !important; }
        ul.woocommerce-error li a:hover { color: #dc2626 !important; }
        ul.woocommerce-error li strong { font-weight: 800 !important; color: #0e3780 !important; }

        .uonix-checkout-turnstile {
            margin: 18px 0 16px;
            padding-left: 10px;
        }

        .uonix-checkout-turnstile .uonix-turnstile-widget {
            display: block !important;
            width: 100% !important;
            min-height: 65px !important;
        }

        .uonix-checkout-turnstile .uonix-turnstile-widget iframe {
            display: block !important;
            max-width: 100% !important;
        }

        @media (max-width: 768px) { .woocommerce-checkout h3 { font-size: 20px !important; } }
    </style>

    <script id="uonix-checkout-js">
        (function ($) {
            function reformatCheckoutTable() {
                var $table = $('.woocommerce-checkout-review-order-table');
                if (!$table.length) return;

                if ($table.find('.uonix-qtd-header').length === 0) {
                    $table.find('thead tr').append('<th class="uonix-qtd-header">Qtd.</th>');
                }

                $table.find('tbody tr.cart_item').each(function () {
                    var $row = $(this);
                    if ($row.find('.uonix-qtd-cell').length > 0) return;

                    var $nameCell = $row.find('.product-name');
                    if (!$nameCell.length) return;

                    var $qtyStrong = $nameCell.find('.product-quantity');
                    var qtyText = ($qtyStrong.text().replace(/[^\d]/g, '') || '1');

                    $qtyStrong.remove();

                    var html = $nameCell.html();
                    if (html) {
                        $nameCell.html(html.replace(/<br\s*\/?>/gi, ' - '));
                    }

                    $row.append('<td class="uonix-qtd-cell">' + qtyText + '</td>');
                });
            }

            var turnstileResetTimer = null;

            function clearTurnstileResponse() {
                $('form.checkout [name="cf-turnstile-response"], .woocommerce-checkout [name="cf-turnstile-response"]').val('');
            }

            function getTurnstileWidgets() {
                return $('form.checkout .cf-turnstile, form.checkout .uonix-turnstile-widget, .woocommerce-checkout .cf-turnstile, .woocommerce-checkout .uonix-turnstile-widget');
            }

            function resetTurnstileCaptcha() {
                var $widgets = getTurnstileWidgets();

                if (!$widgets.length && !$('form.checkout [name="cf-turnstile-response"]').length) {
                    return;
                }

                // O Turnstile gera token de uso unico; apos erro no checkout, precisa ser validado novamente.
                clearTurnstileResponse();

                if (window.uonixTurnstile) {
                    $('form.checkout').each(function () {
                        window.uonixTurnstile.reset(this);
                    });
                }

                if (!window.turnstile || typeof window.turnstile.reset !== 'function') {
                    return;
                }

                if (!$widgets.length) {
                    try {
                        window.turnstile.reset();
                    } catch (error) {}
                    return;
                }

                var didReset = false;

                $widgets.each(function () {
                    var widgetId = $(this).attr('data-widget-id')
                        || $(this).data('widgetId')
                        || $(this).attr('data-uonix-widget-id')
                        || $(this).data('uonixWidgetId')
                        || $(this).attr('data-turnstile-widget-id')
                        || $(this).data('turnstileWidgetId')
                        || $(this).attr('data-cf-turnstile-widget-id')
                        || $(this).data('cfTurnstileWidgetId');

                    if (!widgetId) {
                        return;
                    }

                    try {
                        window.turnstile.reset(widgetId);
                        didReset = true;
                    } catch (error) {
                        didReset = false;
                    }
                });

                if (!didReset) {
                    try {
                        window.turnstile.reset();
                    } catch (error) {}
                }
            }

            function scheduleTurnstileReset() {
                if (turnstileResetTimer) {
                    clearTimeout(turnstileResetTimer);
                }

                turnstileResetTimer = setTimeout(function () {
                    resetTurnstileCaptcha();
                    turnstileResetTimer = setTimeout(resetTurnstileCaptcha, 650);
                }, 80);
            }

            $(document).ready(function () {
                reformatCheckoutTable();

                // Interações de clique no Enviar
                $('form.checkout').on('checkout_place_order', function () {
                    $('#place_order').addClass('uonix-loading');
                    // Remove o vermelho do topo quando o usuário tenta de novo
                    $('body').removeClass('uonix-has-error'); 
                });

                // Trata o erro (Adiciona loading de volta e pinta o topo de vermelho)
                $(document.body).on('checkout_error', function () {
                    $('#place_order').removeClass('uonix-loading');
                    $('body').addClass('uonix-has-error'); 
                    scheduleTurnstileReset();
                });
            });

            // Monitora atualizações de Ajax do WooCommerce
            $(document.body).on('updated_checkout', function () {
                reformatCheckoutTable();
            });
        })(jQuery);
    </script>
    <?php
}, 100);
