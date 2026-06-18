<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Fluent Forms - sincronizacao do formulario de contato.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1891-1991 do export original.
// -----------------------------------------------------------------------------
/**
 * Sincronizar Form Contato com Leads e Newsletters
 */
/**
 * UÔNIX: Distribuidor de Fluxo Master (Form 3 -> Form 4 & Form 2)
 * Utiliza o SubmissionHandlerService para garantir gatilhos de integração.
 */
if ( ! function_exists( 'uonix_ff_sync_upper_text' ) ) {
    function uonix_ff_sync_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_ff_sync_format_phone' ) ) {
    function uonix_ff_sync_format_phone( $value ) {
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

add_action('fluentform_submission_inserted', function ($entryId, $formData, $form) {
    // 1. Filtros de Segurança e Contexto
    if ((int) $form->id !== 3) return;
    if (!function_exists('wpFluentForm')) return;

    // 2. Extração de Dados
    $email    = strtolower(sanitize_email($formData['form_email'] ?? ''));
    $nome     = uonix_ff_sync_upper_text(sanitize_text_field($formData['form_nome'] ?? ''));
    $empresa  = uonix_ff_sync_upper_text(sanitize_text_field($formData['form_empresa'] ?? ''));
    $telefone = uonix_ff_sync_format_phone(sanitize_text_field($formData['form_telefone'] ?? ''));

    $partes_nome   = preg_split('/\s+/', trim($nome));
    $primeiro_nome = $partes_nome[0] ?? '';
    $ultimo_nome   = count($partes_nome) > 1 ? end($partes_nome) : '';

    $opt_in = false;

    foreach ((array) ($formData['form_newsletters'] ?? []) as $newsletter_value) {
        if ('sim' === strtolower(trim((string) $newsletter_value))) {
            $opt_in = true;
            break;
        }
    }

    /**
     * 3. Identifica a origem real da submissão
     *
     * Se o Form 3 veio da página /trabalhe-conosco/,
     * a origem enviada para Form 4 e Form 2 será "Formulário de Recrutamento".
     */
    $referer_original = sanitize_text_field($formData['_wp_http_referer'] ?? '');
    $referer_path_detectado = '';

    if (!empty($referer_original)) {
        $parsed_path = wp_parse_url($referer_original, PHP_URL_PATH);
        $referer_path_detectado = $parsed_path ? $parsed_path : $referer_original;
    }

    $referer_path_detectado = trim($referer_path_detectado, '/');

    $eh_trabalhe_conosco = (
        $referer_path_detectado === 'trabalhe-conosco' ||
        strpos($referer_path_detectado, 'trabalhe-conosco') !== false
    );

    $origem_fluxo = $eh_trabalhe_conosco
        ? 'FORMULÁRIO DE RECRUTAMENTO'
        : 'FORMULÁRIO DE CONTATO';

    /** @var \FluentForm\App\Services\Form\SubmissionHandlerService $submissionHandler */
    $submissionHandler = wpFluentForm()->make('\FluentForm\App\Services\Form\SubmissionHandlerService');

    // 4. Resolução Dinâmica do ID da Home (Onde o Lead "nasce")
    $embedded_post_id = (int) get_option('page_on_front');

    if ($embedded_post_id <= 0) {
        $embedded_post_id = (int) url_to_postid(home_url('/'));
    }

    $referer_path = '/';

    // 5. Execução das Submissões Secundárias
    try {
        // --- FORM 4 (LEADS) ---
        $payloadForm4 = [
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer_path,
            'capturalead_nome'             => $nome,
            'capturalead_email'            => $email,
            'capturalead_telefone'         => $telefone,
            'capturalead_empresa'          => $empresa,
            'capturalead_origem'           => $origem_fluxo,
        ];

        if ($opt_in) {
            $payloadForm4['capturalead_newsletters'] = ['SIM'];
        }

        $submissionHandler->handleSubmission($payloadForm4, 4);

        // --- FORM 2 (NEWSLETTER) ---
        if ($opt_in) {
            $submissionHandler->handleSubmission([
                '__fluent_form_embded_post_id' => $embedded_post_id,
                '_wp_http_referer'             => $referer_path,
                'newsletters_email'            => $email,
                'newsletters_nome'             => $primeiro_nome,
                'newsletters_sobrenome'        => $ultimo_nome,
                'newsletters_empresa'          => $empresa,
                'newsletters_telefone'         => $telefone,
                'newsletters_termo'            => 'on',
                'newsletters_origem'           => $origem_fluxo,
            ], 2);
        }
    } catch (\Exception $e) {
        error_log('Uônix Sync Error: ' . $e->getMessage());
    }

}, 20, 3);

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function formatUonixPhone(value) {
            const phone = (value || '').replace(/\D/g, '').slice(0, 11);

            if (phone.length > 10) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 7) + '-' + phone.substring(7, 11);
            }

            if (phone.length > 6) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2, 6) + '-' + phone.substring(6);
            }

            if (phone.length > 2) {
                return '(' + phone.substring(0, 2) + ') ' + phone.substring(2);
            }

            return phone;
        }

        document.querySelectorAll('#fluentform_3 input[name="form_telefone"], form[data-form_id="3"] input[name="form_telefone"], input[name="form_telefone"]').forEach(function(input) {
            if (input.dataset.uonixPhoneMask === '1') {
                return;
            }

            input.dataset.uonixPhoneMask = '1';
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('autocomplete', 'tel');
            input.setAttribute('maxlength', '15');

            input.addEventListener('input', function() {
                this.value = formatUonixPhone(this.value);
            });

            input.value = formatUonixPhone(input.value);
        });
    });
    </script>
    <?php
}, 99);
