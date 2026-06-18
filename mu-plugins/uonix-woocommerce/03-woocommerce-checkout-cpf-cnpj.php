<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - WooCommerce - checkout, CPF/CNPJ e textos RFQ.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 333-369 do export original.
// -----------------------------------------------------------------------------
/**
 * textos relacionados ao WooCommerce/Tema
 */
add_filter( 'gettext', 'uonix_custom_rfq_translate', 20, 3 );

function uonix_custom_rfq_translate( $translated_text, $text, $domain ) {
    // Só age no frontend e para textos relacionados ao WooCommerce/Tema
    if ( is_admin() ) return $translated_text;

    // Mapa de trocas: 'Texto Original' => 'Novo Texto'
    $strings = array(
        'Carrinho'              => 'Lista de Orçamento',
        'Ver carrinho'          => 'Ver Lista',
        'Finalizar compra'      => 'Solicitar Orçamento',
        'Checkout'              => 'Enviar Orçamento',
        'Revisão do Carrinho'   => 'Itens para Orçamento',
        'Seu carrinho está vazio.' => 'Sua lista de orçamento está vazia.'
    );

    if ( isset( $strings[$text] ) ) {
        $translated_text = $strings[$text];
    }

    return $translated_text;
}

/**
 * Remove o texto "(opcional)" dos campos do checkout da Uônix
 */
add_filter( 'gettext', 'uonix_remove_optional_text', 20, 3 );
function uonix_remove_optional_text( $translated_text, $text, $domain ) {
    if ( $text === '(optional)' || $text === '(opcional)' ) {
        return '';
    }
    return $translated_text;
}


// -----------------------------------------------------------------------------
// Bloco 2 - linhas 370-1157 do export original.
// -----------------------------------------------------------------------------
/**
 * Validacao Formulario Orcamento CNPJ, CEP e CSS
 */
/**
 * UÔNIX: Checkout Master V9.0 - CPF/CNPJ Premium
 * ----------------------------------------------------------------
 * - Aceita CPF e CNPJ no campo billing_cnpj.
 * - Valida CPF/CNPJ no navegador e no servidor WooCommerce.
 * - Salva Empresa como PF para CPF sem empresa e NAO INFORMADO para CNPJ sem razao social.
 * - Busca dados da empresa apenas quando o documento for CNPJ válido.
 * - Mantém CEP, telefone, complemento inteligente e acabamento visual.
 */

if ( ! function_exists( 'uonix_checkout_master_document_normalize' ) ) {
    function uonix_checkout_master_document_normalize( $document ) {
        $document = strtoupper( (string) $document );
        return preg_replace( '/[^0-9A-Z]/', '', $document );
    }
}

if ( ! function_exists( 'uonix_checkout_master_only_digits' ) ) {
    function uonix_checkout_master_only_digits( $value ) {
        return preg_replace( '/\D+/', '', (string) $value );
    }
}

if ( ! function_exists( 'uonix_checkout_master_is_valid_cpf' ) ) {
    function uonix_checkout_master_is_valid_cpf( $cpf ) {
        $cpf = uonix_checkout_master_only_digits( $cpf );

        if ( 11 !== strlen( $cpf ) || preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
            return false;
        }

        $sum = 0;
        for ( $i = 0; $i < 9; $i++ ) {
            $sum += (int) $cpf[ $i ] * ( 10 - $i );
        }
        $digit = 11 - ( $sum % 11 );
        $digit = $digit >= 10 ? 0 : $digit;

        if ( $digit !== (int) $cpf[9] ) {
            return false;
        }

        $sum = 0;
        for ( $i = 0; $i < 10; $i++ ) {
            $sum += (int) $cpf[ $i ] * ( 11 - $i );
        }
        $digit = 11 - ( $sum % 11 );
        $digit = $digit >= 10 ? 0 : $digit;

        return $digit === (int) $cpf[10];
    }
}

if ( ! function_exists( 'uonix_checkout_master_cnpj_char_value' ) ) {
    function uonix_checkout_master_cnpj_char_value( $char ) {
        return ord( $char ) - 48;
    }
}

if ( ! function_exists( 'uonix_checkout_master_cnpj_digit' ) ) {
    function uonix_checkout_master_cnpj_digit( $base, $weights ) {
        $sum = 0;
        $length = strlen( $base );

        for ( $i = 0; $i < $length; $i++ ) {
            $sum += uonix_checkout_master_cnpj_char_value( $base[ $i ] ) * $weights[ $i ];
        }

        $rest = $sum % 11;
        return $rest < 2 ? 0 : 11 - $rest;
    }
}

if ( ! function_exists( 'uonix_checkout_master_is_valid_cnpj' ) ) {
    function uonix_checkout_master_is_valid_cnpj( $cnpj ) {
        $cnpj = uonix_checkout_master_document_normalize( $cnpj );

        if ( 14 !== strlen( $cnpj ) || ! preg_match( '/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj ) ) {
            return false;
        }

        if ( preg_match( '/^([0-9A-Z])\1{13}$/', $cnpj ) ) {
            return false;
        }

        $first_weights  = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
        $second_weights = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
        $base           = substr( $cnpj, 0, 12 );
        $first_digit    = uonix_checkout_master_cnpj_digit( $base, $first_weights );
        $second_digit   = uonix_checkout_master_cnpj_digit( $base . $first_digit, $second_weights );

        return substr( $cnpj, -2 ) === (string) $first_digit . (string) $second_digit;
    }
}

if ( ! function_exists( 'uonix_checkout_master_format_document' ) ) {
    function uonix_checkout_master_format_document( $document ) {
        $document = uonix_checkout_master_document_normalize( $document );

        if ( uonix_checkout_master_is_valid_cpf( $document ) ) {
            $cpf = uonix_checkout_master_only_digits( $document );
            return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
        }

        if ( uonix_checkout_master_is_valid_cnpj( $document ) ) {
            return substr( $document, 0, 2 ) . '.' . substr( $document, 2, 3 ) . '.' . substr( $document, 5, 3 ) . '/' . substr( $document, 8, 4 ) . '-' . substr( $document, 12, 2 );
        }

        return $document;
    }
}

if ( ! function_exists( 'uonix_checkout_master_posted_field' ) ) {
    function uonix_checkout_master_posted_field( $field ) {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return '';
        }

        return sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }
}

if ( ! function_exists( 'uonix_checkout_master_upper_text' ) ) {
    function uonix_checkout_master_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_checkout_master_address_has_number' ) ) {
    function uonix_checkout_master_address_has_number( $address, $number ) {
        $address = trim( (string) $address );
        $number  = trim( (string) $number );

        if ( '' === $address || '' === $number ) {
            return false;
        }

        return (bool) preg_match( '/(^|[\s,.-])' . preg_quote( $number, '/' ) . '($|[\s,.-])/i', $address );
    }
}

if ( ! function_exists( 'uonix_checkout_master_join_address_number' ) ) {
    function uonix_checkout_master_join_address_number( $address, $number ) {
        $address = trim( preg_replace( '/\s+/', ' ', (string) $address ) );
        $number  = trim( preg_replace( '/\s+/', ' ', (string) $number ) );

        if ( '' === $address || '' === $number ) {
            return $address;
        }

        if ( uonix_checkout_master_address_has_number( $address, $number ) ) {
            $address = preg_replace( '/,\s*(' . preg_quote( $number, '/' ) . ')(?=$|[\s,.-])/i', ' $1', $address );
            return trim( preg_replace( '/\s+/', ' ', $address ) );
        }

        return $address . ' ' . $number;
    }
}

add_filter( 'woocommerce_checkout_posted_data', function( $data ) {
    $upper_fields = array(
        'billing_complete_name',
        'billing_first_name',
        'billing_last_name',
        'billing_company',
        'billing_address_1',
        'billing_address_2',
        'billing_address_3',
        'billing_city',
        'billing_state',
        'billing_postcode',
        'billing_phone',
        'order_comments',
    );

    foreach ( $upper_fields as $field ) {
        if ( isset( $data[ $field ] ) ) {
            $data[ $field ] = uonix_checkout_master_upper_text( $data[ $field ] );
        }
    }

    if ( isset( $data['billing_email'] ) ) {
        $data['billing_email'] = strtolower( sanitize_email( $data['billing_email'] ) );
    }

    if ( ! empty( $data['billing_cnpj'] ) ) {
        $document = uonix_checkout_master_document_normalize( $data['billing_cnpj'] );

        if ( uonix_checkout_master_is_valid_cpf( $document ) ) {
            $data['billing_cnpj'] = uonix_checkout_master_format_document( $document );
            $company = isset( $data['billing_company'] ) ? trim( (string) $data['billing_company'] ) : '';

            if ( '' === $company || 'PESSOA FISICA' === $company ) {
                $data['billing_company'] = 'PF';
            }
        } elseif ( uonix_checkout_master_is_valid_cnpj( $document ) ) {
            $data['billing_cnpj'] = uonix_checkout_master_format_document( $document );

            if ( empty( $data['billing_company'] ) || '' === trim( (string) $data['billing_company'] ) ) {
                $data['billing_company'] = 'NAO INFORMADO';
            }
        }
    }

    return $data;
} );

add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    $address    = ! empty( $data['billing_address_1'] ) ? $data['billing_address_1'] : $order->get_billing_address_1();
    $number     = uonix_checkout_master_posted_field( 'billing_address_3' );
    $complement = uonix_checkout_master_posted_field( 'billing_address_2' );
    $full_name  = uonix_checkout_master_posted_field( 'billing_complete_name' );
    $newsletter = uonix_checkout_master_posted_field( 'billing_newsletters' );

    $order->set_billing_email( strtolower( sanitize_email( $order->get_billing_email() ) ) );
    $order->set_billing_company( uonix_checkout_master_upper_text( $order->get_billing_company() ) );
    $order->set_billing_city( uonix_checkout_master_upper_text( $order->get_billing_city() ) );
    $order->set_billing_state( uonix_checkout_master_upper_text( $order->get_billing_state() ) );
    $order->set_billing_postcode( uonix_checkout_master_upper_text( $order->get_billing_postcode() ) );

    if ( '' !== $number ) {
        $number = uonix_checkout_master_upper_text( $number );
        $order->set_billing_address_1( uonix_checkout_master_upper_text( uonix_checkout_master_join_address_number( $address, $number ) ) );
        $order->update_meta_data( 'billing_address_3', $number );
    } else {
        $order->set_billing_address_1( uonix_checkout_master_upper_text( $address ) );
    }

    if ( '' !== $complement ) {
        $order->set_billing_address_2( uonix_checkout_master_upper_text( $complement ) );
    }

    if ( '' !== $full_name ) {
        $order->update_meta_data( 'billing_complete_name', uonix_checkout_master_upper_text( $full_name ) );
    }

    if ( '' !== $newsletter ) {
        $order->update_meta_data( 'billing_newsletters', uonix_checkout_master_upper_text( $newsletter ) );
    }
}, 20, 2 );

add_action( 'woocommerce_after_checkout_validation', function( $data, $errors ) {
    $raw_document = '';

    if ( isset( $data['billing_cnpj'] ) ) {
        $raw_document = $data['billing_cnpj'];
    } elseif ( isset( $_POST['billing_cnpj'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw_document = wc_clean( wp_unslash( $_POST['billing_cnpj'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    $document = uonix_checkout_master_document_normalize( $raw_document );

    if ( '' === $document ) {
        return;
    }

    if ( ! uonix_checkout_master_is_valid_cpf( $document ) && ! uonix_checkout_master_is_valid_cnpj( $document ) ) {
        $errors->add( 'billing_cnpj_invalid', 'Informe um CNPJ ou CPF válido.' );
    }
}, 10, 2 );

add_action( 'wp_enqueue_scripts', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    $sweetalert_path = UONIX_MU_PATH . 'uonix-woocommerce/assets/vendor/sweetalert2/sweetalert2.all.min.js';
    $sweetalert_url  = UONIX_MU_URL . 'uonix-woocommerce/assets/vendor/sweetalert2/sweetalert2.all.min.js';
    $sweetalert_ver  = file_exists( $sweetalert_path ) ? (string) filemtime( $sweetalert_path ) : '11';

    wp_enqueue_script( 'sweetalert2', $sweetalert_url, array(), $sweetalert_ver, true );

    wp_register_style( 'uonix-checkout-master', false, array(), '9.0.0' );
    wp_enqueue_style( 'uonix-checkout-master' );

    $css = <<<'CSS'
.uonix-toggle-complemento {
    appearance: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 4px 0 16px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #1a2b3c;
    cursor: pointer;
    font: 600 13px/1.35 "Segoe UI", Tahoma, sans-serif;
    letter-spacing: .01em;
    transition: color .2s ease, transform .2s ease;
}

.uonix-toggle-complemento:hover,
.uonix-toggle-complemento:focus-visible {
    color: #f76a0c;
	background: transparent;
    transform: translateX(1px);
    outline: none;
}

.uonix-toggle-complemento:focus-visible {
    box-shadow: 0 2px 0 #f76a0c;
}

.uonix-loader-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 0;
}

.uonix-loader-container p {
    margin: 15px 0 0;
    color: #666;
    font-size: 14px;
}

.swal2-loader {
    border-color: #1a2b3c transparent #1a2b3c transparent !important;
}

.uonix-swal-title {
    color: #1a2b3c !important;
    font-weight: 700 !important;
}

#billing_address_2_field {
    display: none;
    clear: both !important;
    width: 100% !important;
}

.woocommerce-checkout .variation dt.uonix-hide-brand-label {
    display: none !important;
}

.woocommerce-checkout .variation dt.uonix-hide-brand-label + dd {
    display: inline-block;
    margin: 0 !important;
    padding: 0 !important;
}

.woocommerce-checkout .variation dt.uonix-hide-brand-label + dd p {
    display: inline;
    margin: 0 !important;
}

@media (min-width: 768px) {
    #billing_address_1_field {
        float: left !important;
        clear: both !important;
        width: 73% !important;
        margin-right: 2% !important;
    }

    #billing_address_3_field {
        float: left !important;
        clear: none !important;
        width: 25% !important;
    }
}
CSS;

    wp_add_inline_style( 'uonix-checkout-master', $css );

    wp_register_script( 'uonix-checkout-master', false, array( 'jquery', 'sweetalert2' ), '9.0.0', true );
    wp_enqueue_script( 'uonix-checkout-master' );

    $js = <<<'JS'
(function ($, window, document) {
    'use strict';

    $(function () {
        const selectors = {
            document: '#billing_cnpj',
            documentField: '#billing_cnpj_field',
            postcode: '#billing_postcode',
            phone: '#billing_phone',
            company: '#billing_company',
            email: '#billing_email',
            address: '#billing_address_1',
            number: '#billing_address_3',
            complement: '#billing_address_2',
            complementField: '#billing_address_2_field',
            complementToggle: '.uonix-toggle-complemento',
            city: '#billing_city',
            state: '#billing_state'
        };

        const hasSwal = typeof window.Swal !== 'undefined';
        const UonixAlert = hasSwal ? window.Swal.mixin({
            confirmButtonColor: '#1a2b3c',
            cancelButtonColor: '#222',
            customClass: { title: 'uonix-swal-title' },
            returnFocus: false
        }) : null;

        let lastCepLookup = '';
        let lastCnpjLookup = '';
        let cepRequest = null;
        let cnpjRequest = null;

        function onlyDigits(value) {
            return String(value || '').replace(/\D/g, '');
        }

        function normalizeDocument(value) {
            return String(value || '').toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
        }

        function isRepeated(value) {
            return /^([0-9A-Z])\1+$/.test(value);
        }

        function formatCPF(value) {
            const cpf = onlyDigits(value).slice(0, 11);

            if (cpf.length <= 3) return cpf;
            if (cpf.length <= 6) return cpf.substring(0, 3) + '.' + cpf.substring(3);
            if (cpf.length <= 9) return cpf.substring(0, 3) + '.' + cpf.substring(3, 6) + '.' + cpf.substring(6);
            return cpf.substring(0, 3) + '.' + cpf.substring(3, 6) + '.' + cpf.substring(6, 9) + '-' + cpf.substring(9, 11);
        }

        function formatCNPJ(value) {
            const cnpj = normalizeDocument(value);

            if (cnpj.length <= 2) return cnpj;
            if (cnpj.length <= 5) return cnpj.substring(0, 2) + '.' + cnpj.substring(2);
            if (cnpj.length <= 8) return cnpj.substring(0, 2) + '.' + cnpj.substring(2, 5) + '.' + cnpj.substring(5);
            if (cnpj.length <= 12) return cnpj.substring(0, 2) + '.' + cnpj.substring(2, 5) + '.' + cnpj.substring(5, 8) + '/' + cnpj.substring(8);
            return cnpj.substring(0, 2) + '.' + cnpj.substring(2, 5) + '.' + cnpj.substring(5, 8) + '/' + cnpj.substring(8, 12) + '-' + cnpj.substring(12, 14);
        }

        function formatDocument(value) {
            const documentValue = normalizeDocument(value);
            const useCnpjMask = /[A-Z]/.test(documentValue) || documentValue.length > 11;
            return useCnpjMask ? formatCNPJ(documentValue) : formatCPF(documentValue);
        }

        function isValidCPF(value) {
            const cpf = onlyDigits(value);
            let sum = 0;
            let digit;

            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;

            for (let i = 0; i < 9; i++) sum += Number(cpf[i]) * (10 - i);
            digit = 11 - (sum % 11);
            digit = digit >= 10 ? 0 : digit;
            if (digit !== Number(cpf[9])) return false;

            sum = 0;
            for (let i = 0; i < 10; i++) sum += Number(cpf[i]) * (11 - i);
            digit = 11 - (sum % 11);
            digit = digit >= 10 ? 0 : digit;

            return digit === Number(cpf[10]);
        }

        function cnpjCharValue(char) {
            return char.charCodeAt(0) - 48;
        }

        function calculateCnpjDigit(base, weights) {
            let sum = 0;

            for (let i = 0; i < base.length; i++) {
                sum += cnpjCharValue(base[i]) * weights[i];
            }

            const rest = sum % 11;
            return rest < 2 ? 0 : 11 - rest;
        }

        function isValidCNPJ(value) {
            const cnpj = normalizeDocument(value);
            const firstWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            const secondWeights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            const base = cnpj.substring(0, 12);
            let firstDigit;
            let secondDigit;

            if (cnpj.length !== 14 || !/^[0-9A-Z]{12}[0-9]{2}$/.test(cnpj) || isRepeated(cnpj)) return false;

            firstDigit = calculateCnpjDigit(base, firstWeights);
            secondDigit = calculateCnpjDigit(base + firstDigit, secondWeights);

            return cnpj.endsWith(String(firstDigit) + String(secondDigit));
        }

        function getDocumentType(value) {
            const documentValue = normalizeDocument(value);

            if (isValidCPF(documentValue)) return 'cpf';
            if (isValidCNPJ(documentValue)) return 'cnpj';
            return null;
        }

        function fireAlert(title, message, icon) {
            if (UonixAlert) {
                return UonixAlert.fire(title, message, icon);
            }

            window.alert(title + (message ? '\n' + message : ''));
            return Promise.resolve();
        }

        function showLoading(title, message) {
            if (!hasSwal) return;

            window.Swal.fire({
                title: title,
                html: '<div class="uonix-loader-container"><p>' + message + '</p></div>',
                allowOutsideClick: false,
                showConfirmButton: false,
                returnFocus: false,
                didOpen: function () {
                    window.Swal.showLoading();
                }
            });
        }

        function closeLoading() {
            if (hasSwal) window.Swal.close();
        }

        function showComplement(animate) {
            const $field = $(selectors.complementField);

            if (!$field.length) return;

            if (animate === false) {
                $field.show();
                $(selectors.complementToggle).hide();
                return;
            }

            $field.stop(true, true).slideDown(250);
            $(selectors.complementToggle).fadeOut(150);
        }

        function injectComplementToggle() {
            const $field = $(selectors.complementField);
            const hasComplement = $.trim($(selectors.complement).val() || '') !== '';

            if (!$field.length) return;

            if (!$(selectors.complementToggle).length) {
                $('<button type="button" class="uonix-toggle-complemento" aria-controls="billing_address_2">+ Adicionar complemento</button>').insertBefore($field);
            }

            if (hasComplement) {
                showComplement(false);
            } else {
                $field.hide();
                $(selectors.complementToggle).show();
            }
        }

        function setupDocumentField() {
            const $field = $(selectors.document);
            const $label = $(selectors.documentField + ' label').first();

            if (!$field.length) return;

            $field.attr({
                maxlength: 18,
                autocomplete: 'off',
                placeholder: 'CNPJ ou CPF'
            });

            if ($label.length && !$label.data('uonixDocumentLabel')) {
                $label.data('uonixDocumentLabel', true);
                $label.html($label.html().replace(/CNPJ/g, 'CNPJ / CPF'));
            }
        }

        function hideBrandVariationLabels() {
            $('.woocommerce-checkout .variation dt').each(function () {
                const label = $(this).text().replace(/\s+/g, ' ').replace(':', '').trim().toLowerCase();

                if (label === 'marca') {
                    $(this).addClass('uonix-hide-brand-label');
                }
            });
        }

        function focusFirstAvailable(selectorsList) {
            for (let i = 0; i < selectorsList.length; i++) {
                const $field = $(selectorsList[i]);

                if ($field.length && $field.is(':visible')) {
                    $field.trigger('focus');
                    return;
                }
            }
        }

        function syncCompanyField(documentType) {
            const $company = $(selectors.company);

            if (!$company.length) return;

            if (documentType === 'cpf') {
                $company.prop('disabled', false);

                if (['', 'PF', 'PESSOA FISICA'].indexOf($.trim($company.val() || '').toUpperCase()) !== -1) {
                    $company.val('PESSOA FISICA').trigger('change');
                }

                return;
            }

            $company.prop('disabled', false);

            if (documentType === 'cnpj' && ['PF', 'PESSOA FISICA'].indexOf($.trim($company.val() || '').toUpperCase()) !== -1) {
                $company.val('').trigger('change');
            }
        }

        function lookupCompany(cnpj) {
            const cleanCnpj = normalizeDocument(cnpj);

            if (!isValidCNPJ(cleanCnpj) || cleanCnpj === lastCnpjLookup || !/^\d{14}$/.test(cleanCnpj)) return;

            lastCnpjLookup = cleanCnpj;

            if (cnpjRequest) cnpjRequest.abort();

            showLoading('Consultando empresa', 'Sincronizando dados corporativos...');

            cnpjRequest = $.ajax({
                url: 'https://brasilapi.com.br/api/cnpj/v1/' + cleanCnpj,
                method: 'GET',
                dataType: 'json',
                timeout: 9000
            }).done(function (data) {
                if (data && data.razao_social) {
                    $(selectors.company).val(data.razao_social).trigger('change');
                    setTimeout(function () {
                        focusFirstAvailable([selectors.email, selectors.phone]);
                    }, 250);
                }
            }).fail(function (_, status) {
                if (status !== 'abort') lastCnpjLookup = '';
            }).always(function () {
                closeLoading();
                cnpjRequest = null;
            });
        }

        function lookupCep(cep) {
            if (cepRequest) cepRequest.abort();

            showLoading('Buscando endereço', 'Localizando CEP...');

            cepRequest = $.ajax({
                url: 'https://viacep.com.br/ws/' + cep + '/json/',
                method: 'GET',
                dataType: 'json',
                timeout: 9000
            }).done(function (data) {
                if (!data || data.erro) {
                    lastCepLookup = '';
                    fireAlert('CEP não encontrado', 'Verifique o número digitado.', 'question');
                    return;
                }

                $(selectors.address).val(data.logradouro || '').trigger('change');
                $(selectors.city).val(data.localidade || '').trigger('change');
                $(selectors.state).val(data.uf || '').trigger('change');

                if (data.complemento && $.trim(data.complemento) !== '') {
                    $(selectors.complement).val(data.complemento).trigger('change');
                    showComplement();
                } else {
                    $(selectors.complement).val('').trigger('change');
                    injectComplementToggle();
                }

                setTimeout(function () {
                    focusFirstAvailable([selectors.number, selectors.address]);
                }, 300);
            }).fail(function (_, status) {
                if (status !== 'abort') {
                    lastCepLookup = '';
                    fireAlert('Consulta de CEP indisponível', 'Preencha o endereço manualmente ou tente novamente.', 'info');
                }
            }).always(function () {
                closeLoading();
                cepRequest = null;
            });
        }

        function setupCheckout() {
            setupDocumentField();
            syncCompanyField(getDocumentType($(selectors.document).val()));
            injectComplementToggle();
            hideBrandVariationLabels();
        }

        setupCheckout();

        $(document).on('click', selectors.complementToggle, function () {
            showComplement();
            setTimeout(function () {
                $(selectors.complement).trigger('focus');
            }, 280);
        });

        $(document).on('input', selectors.postcode, function () {
            const $field = $(this);
            const cep = onlyDigits($field.val()).slice(0, 8);
            const maskedCep = cep.length > 5 ? cep.substring(0, 5) + '-' + cep.substring(5, 8) : cep;

            $field.val(maskedCep);

            if (cep.length === 8 && cep !== lastCepLookup) {
                lastCepLookup = cep;
                $field.trigger('blur');
                lookupCep(cep);
            }
        });

        $(document).on('input', selectors.document, function () {
            const $field = $(this);
            const documentValue = normalizeDocument($field.val());

            $field.val(formatDocument(documentValue));
            syncCompanyField(getDocumentType(documentValue));

            if (documentValue.length === 14 && isValidCNPJ(documentValue)) {
                $field.trigger('blur');
                lookupCompany(documentValue);
            }
        });

        $(document).on('blur', selectors.document, function () {
            const $field = $(this);
            const documentValue = normalizeDocument($field.val());
            const type = getDocumentType(documentValue);

            if (documentValue === '') return;

            if (!type) {
                fireAlert('Documento inválido', 'Informe um CPF ou CNPJ válido.', 'warning').then(function () {
                    $field.val('').trigger('change').trigger('focus');
                });
                return;
            }

            $field.val(type === 'cpf' ? formatCPF(documentValue) : formatCNPJ(documentValue)).trigger('change');
            syncCompanyField(type);

            if (type === 'cnpj') {
                lookupCompany(documentValue);
            }
        });

        $(document).on('input', selectors.phone, function () {
            const $field = $(this);
            const phone = onlyDigits($field.val()).slice(0, 11);
            let maskedPhone = phone;

            if (phone.length > 2 && phone.length <= 6) {
                maskedPhone = '(' + phone.substring(0, 2) + ') ' + phone.substring(2);
            } else if (phone.length > 6 && phone.length <= 10) {
                maskedPhone = '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 6) + '-' + phone.substring(6);
            } else if (phone.length > 10) {
                maskedPhone = '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 7) + '-' + phone.substring(7, 11);
            }

            $field.val(maskedPhone);
        });

        $(document.body).on('updated_checkout', setupCheckout);
    });
})(jQuery, window, document);
JS;

    wp_add_inline_script( 'uonix-checkout-master', $js );
} );


// -----------------------------------------------------------------------------
// Bloco 3 - linhas 1158-1176 do export original.
// -----------------------------------------------------------------------------
/**
 * Remove o termo "de cobrança" das mensagens de erro do checkout
 */
/**
 * UÔNIX: Remove o termo "de cobrança" das mensagens de erro do checkout
 */
add_filter( 'woocommerce_add_error', 'uonix_remove_billing_from_error_messages' );

function uonix_remove_billing_from_error_messages( $error ) {
    // Remove " de cobrança" (com o espaço inicial) da string de erro
    $error = str_ireplace( ' de cobrança', '', $error );
    
    return $error;
}

/**
 * Shortcode para Listagem de Serviços (Grid de Cards)
 */
/**

// -----------------------------------------------------------------------------
// Bloco 4 - linhas 1274-1313 do export original.
// -----------------------------------------------------------------------------
/**
 * Renomeia títulos do Checkout (Cobrança e Informações Adicionais)
 */
/**
 * UÔNIX: Renomeia títulos do Checkout (Cobrança e Informações Adicionais)
 */
add_filter( 'gettext', function( $translated_text, $text, $domain ) {
    if ( 'woocommerce' === $domain ) {
        switch ( $text ) {
            case 'Billing details':
                return 'Informações para contato';
            case 'Additional information':
                return 'Informações Adicionais';
			case 'Marca':
                return 'Fabricante';
        }
    }
    return $translated_text;
}, 20, 3 );

/**
 * Personalizar mensagens de erro do checkout para RFQ
 */
/**
 * UÔNIX: Personalizar mensagens de erro do checkout para RFQ
 */
add_filter( 'gettext', function( $translated_text, $text, $domain ) {
    if ( $domain === 'woocommerce' ) {
        // Altera a mensagem de erro de compra genérica
        if ( strpos($text, 'erro ao processar sua compra') !== false ) {
            return 'Houve um erro ao processar sua solicitação de orçamento. Por favor, revise os dados e tente novamente.';
        }
        // Remove referências a "método de pagamento" e "histórico de compra"
        if ( strpos($text, 'verifique por qualquer cobrança no seu método de pagamento') !== false ) {
            return 'Verifique se todos os campos obrigatórios foram preenchidos corretamente.';
        }
    }
    return $translated_text;
}, 30, 3 );

