<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Fluent Forms - sincronizacao WooCommerce para Forms 4 e 2.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1665-1890 do export original.
// -----------------------------------------------------------------------------
/**
 * Sincronizar Form Orçamentos com Leads e Newsletters
 */
/**
 * UÔNIX: Sincronização de Checkout (WooCommerce -> Form 4 & Form 2)
 * Utiliza o SubmissionHandler do Fluent Forms para manter integrações como Mailchimp.
 */

if ( ! function_exists( 'uonix_woo_sync_posted_field' ) ) {
    function uonix_woo_sync_posted_field( $field ) {
        if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return '';
        }

        return sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }
}

if ( ! function_exists( 'uonix_woo_sync_order_meta' ) ) {
    function uonix_woo_sync_order_meta( $order, $keys ) {
        foreach ( (array) $keys as $key ) {
            $value = $order->get_meta( $key );

            if ( '' !== trim( (string) $value ) ) {
                return sanitize_text_field( $value );
            }
        }

        return '';
    }
}

if ( ! function_exists( 'uonix_woo_sync_first_value' ) ) {
    function uonix_woo_sync_first_value( $values ) {
        foreach ( (array) $values as $value ) {
            $value = trim( (string) $value );

            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}

if ( ! function_exists( 'uonix_woo_sync_upper_text' ) ) {
    function uonix_woo_sync_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_woo_sync_format_phone' ) ) {
    function uonix_woo_sync_format_phone( $value ) {
        $digits = preg_replace( '/\D+/', '', (string) $value );

        if ( 11 === strlen( $digits ) ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 5 ), substr( $digits, 7, 4 ) );
        }

        if ( 10 === strlen( $digits ) ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 2 ), substr( $digits, 2, 4 ), substr( $digits, 6, 4 ) );
        }

        return $digits;
    }
}

if ( ! function_exists( 'uonix_woo_sync_is_yes' ) ) {
    function uonix_woo_sync_is_yes( $value ) {
        return in_array( strtolower( trim( (string) $value ) ), array( 'sim', 'yes', '1', 'on', 'true' ), true );
    }
}

if ( ! function_exists( 'uonix_woo_sync_address_has_number' ) ) {
    function uonix_woo_sync_address_has_number( $address, $number ) {
        $address = trim( (string) $address );
        $number  = trim( (string) $number );

        if ( '' === $address || '' === $number ) {
            return false;
        }

        return (bool) preg_match( '/(^|[\s,.-])' . preg_quote( $number, '/' ) . '($|[\s,.-])/i', $address );
    }
}

if ( ! function_exists( 'uonix_woo_sync_join_address_number' ) ) {
    function uonix_woo_sync_join_address_number( $address, $number ) {
        $address = trim( preg_replace( '/\s+/', ' ', (string) $address ) );
        $number  = trim( preg_replace( '/\s+/', ' ', (string) $number ) );

        if ( '' === $address || '' === $number ) {
            return $address;
        }

        if ( uonix_woo_sync_address_has_number( $address, $number ) ) {
            $address = preg_replace( '/,\s*(' . preg_quote( $number, '/' ) . ')(?=$|[\s,.-])/i', ' $1', $address );
            return trim( preg_replace( '/\s+/', ' ', $address ) );
        }

        return $address . ' ' . $number;
    }
}

if ( ! function_exists( 'uonix_woo_sync_format_address' ) ) {
    function uonix_woo_sync_format_address( $logradouro, $numero, $complemento, $cidade, $estado, $cep ) {
        $linha_principal = uonix_woo_sync_join_address_number( $logradouro, $numero );
        $complemento     = trim( (string) $complemento );
        $cidade          = trim( (string) $cidade );
        $estado          = trim( (string) $estado );
        $cep             = trim( (string) $cep );

        if ( '' !== $complemento ) {
            $linha_principal = '' !== $linha_principal ? $linha_principal . ', ' . $complemento : $complemento;
        }

        $cidade_estado = trim( $cidade . ' ' . $estado );
        $localizacao   = implode( ', ', array_filter( array( $cidade_estado, $cep ), function( $value ) {
            return '' !== trim( (string) $value );
        } ) );

        return uonix_woo_sync_upper_text( implode( ', ', array_filter( array( $linha_principal, $localizacao ), function( $value ) {
            return '' !== trim( (string) $value );
        } ) ) );
    }
}

add_action( 'woocommerce_checkout_order_processed', function( $order_id ) {
    if ( ! function_exists( 'wpFluentForm' ) || ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $canonical_path = '/finalizar-orcamento/';
    $referer        = wp_get_referer();

    if ( $referer ) {
        $parsed  = wp_parse_url( $referer );
        $referer = ! empty( $parsed['path'] ) ? $parsed['path'] : '';

        if ( ! empty( $parsed['query'] ) ) {
            $referer .= '?' . $parsed['query'];
        }
    }

    if ( ! $referer || false !== strpos( $referer, 'wc-ajax=' ) || false !== strpos( $referer, 'admin-ajax.php' ) ) {
        $referer = $canonical_path;
    }

    $embedded_post_id = (int) wc_get_page_id( 'checkout' );

    if ( $embedded_post_id <= 0 ) {
        $embedded_post_id = (int) url_to_postid( home_url( $canonical_path ) );
    }

    $email       = strtolower( sanitize_email( $order->get_billing_email() ) );
    $empresa     = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_company() ) );
    $telefone    = uonix_woo_sync_format_phone( sanitize_text_field( $order->get_billing_phone() ) );
    $logradouro  = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_address_1() ) );
    $complemento = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_address_2() ) );
    $cidade      = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_city() ) );
    $estado      = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_state() ) );
    $cep         = uonix_woo_sync_upper_text( sanitize_text_field( $order->get_billing_postcode() ) );

    $numero = uonix_woo_sync_first_value( array(
        uonix_woo_sync_posted_field( 'billing_address_3' ),
        uonix_woo_sync_order_meta( $order, array( 'billing_address_3', '_billing_address_3' ) ),
    ) );
    $numero = uonix_woo_sync_upper_text( $numero );

    $nome = uonix_woo_sync_first_value( array(
        uonix_woo_sync_posted_field( 'billing_complete_name' ),
        uonix_woo_sync_order_meta( $order, array( 'billing_complete_name', '_billing_complete_name' ) ),
        trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
    ) );
    $nome = uonix_woo_sync_upper_text( $nome );

    $partes_nome   = preg_split( '/\s+/', trim( $nome ) );
    $primeiro_nome = $partes_nome[0] ?? '';
    $ultimo_nome   = count( $partes_nome ) > 1 ? end( $partes_nome ) : '';
    $opt_in        = uonix_woo_sync_is_yes( uonix_woo_sync_first_value( array(
        uonix_woo_sync_posted_field( 'billing_newsletters' ),
        uonix_woo_sync_order_meta( $order, array( 'billing_newsletters', '_billing_newsletters' ) ),
    ) ) );

    $endereco = uonix_woo_sync_format_address( $logradouro, $numero, $complemento, $cidade, $estado, $cep );

    /** @var \FluentForm\App\Services\Form\SubmissionHandlerService $submissionHandler */
    try {
        $submissionHandler = wpFluentForm()->make( '\FluentForm\App\Services\Form\SubmissionHandlerService' );
    } catch ( \Throwable $e ) {
        error_log( 'Uônix Woo-Sync Handler Error: ' . $e->getMessage() );
        return;
    }

    try {
        $submissionHandler->handleSubmission( array(
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer,
            'capturalead_nome'             => $nome,
            'capturalead_empresa'          => $empresa,
            'capturalead_telefone'         => $telefone,
            'capturalead_email'            => $email,
            'capturalead_newsletters'      => $opt_in ? 'SIM' : 'NAO',
            'capturalead_origem'           => 'FORMULÁRIO DE SOLICITAÇÃO DE ORÇAMENTO',
        ), 4 );
    } catch ( \Throwable $e ) {
        error_log( 'Uônix Woo-Sync Form 4 Error: ' . $e->getMessage() );
    }

    if ( $opt_in ) {
        try {
            $submissionHandler->handleSubmission( array(
                '__fluent_form_embded_post_id' => $embedded_post_id,
                '_wp_http_referer'             => $referer,
                'newsletters_email'            => $email,
                'newsletters_nome'             => $primeiro_nome,
                'newsletters_sobrenome'        => $ultimo_nome,
                'newsletters_endereco'         => $endereco,
                'newsletters_empresa'          => $empresa,
                'newsletters_telefone'         => $telefone,
                'newsletters_termo'            => 'on',
                'newsletters_origem'           => 'FORMULÁRIO DE SOLICITAÇÃO DE ORÇAMENTO',
            ), 2 );
        } catch ( \Throwable $e ) {
            error_log( 'Uônix Woo-Sync Form 2 Error: ' . $e->getMessage() );
        }
    }
}, 20, 1 );

