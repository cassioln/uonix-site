<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Comentarios - formulario, Turnstile, Fluent Forms e feedback.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1992-3254 do export original.
// -----------------------------------------------------------------------------
/**
 *  Sincronizar Form Comentario com Leads e Newsletters
 */
/**
 * UÔNIX: Master Comentários V30
 * - Botão customizado com spinner
 * - Campo Empresa para visitantes
 * - Campo Empresa oculto para usuários logados via billing_company
 * - Checkboxes premium
 * - Usuário logado não vê newsletter e não vira lead
 * - Integração Fluent Forms apenas para visitantes
 * - Cloudflare Turnstile acima do botão
 * - Correção do Turnstile ao clicar em Responder/Cancelar resposta
 * - Erro inline elegante no CAPTCHA
 * - Modal elegante para comentário duplicado
 * - Feedback após envio do comentário
 * - Mantém prévia nativa de comentário aguardando moderação
 * - Troca Reply por Responder
 */

if (!defined('UONIX_FLUENT_FORM_LEAD_ID')) {
    define('UONIX_FLUENT_FORM_LEAD_ID', 4);
}

if (!defined('UONIX_FLUENT_FORM_NEWSLETTER_ID')) {
    define('UONIX_FLUENT_FORM_NEWSLETTER_ID', 3);
}

if ( ! function_exists( 'uonix_comment_sync_upper_text' ) ) {
    function uonix_comment_sync_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_comment_turnstile_widget_html' ) ) {
    function uonix_comment_turnstile_widget_html() {
        if ( ! function_exists( 'uonix_turnstile_render_widget' ) ) {
            return '';
        }

        if ( ! apply_filters( 'uonix_turnstile_protect_comment_form', true ) ) {
            return '';
        }

        $widget = uonix_turnstile_render_widget(
            'comment_post',
            array(
                'theme'      => 'light',
                'appearance' => 'interaction-only',
                'size'       => 'flexible',
            )
        );

        if ( '' === $widget ) {
            return '';
        }

        return '<div class="uonix-comment-turnstile" aria-label="Verificação de segurança">' . $widget . '</div>';
    }
}

// ==============================================================================
// 1. FRONT-END: DEFINIÇÃO DOS CAMPOS E REORGANIZAÇÃO DO LAYOUT
// ==============================================================================

// Transforma o botão input em button.
add_filter('comment_form_submit_button', function($submit_button, $args) {
    return '<button name="submit" type="submit" id="submit" class="submit uonix-btn-submit-news">Publicar comentário</button>';
}, 10, 2);

// Troca texto Reply por Responder nos links de resposta.
add_filter('comment_reply_link', function($link) {
    $link = str_replace('>Reply</a>', '>Responder</a>', $link);
    $link = str_replace('>Reply<', '>Responder<', $link);
    return $link;
}, 20);

// Reorganiza a grade de inputs: Nome, Email, Empresa.
add_filter('comment_form_default_fields', function($fields) {
    global $uonix_cookies_field;

    // Remove o campo site.
    if (isset($fields['url'])) {
        unset($fields['url']);
    }

    // Move o checkbox nativo de cookies para o fim.
    if (isset($fields['cookies'])) {
        $uonix_cookies_field = $fields['cookies'];
        unset($fields['cookies']);
    }

    // Campo Empresa aparece somente para visitantes.
    if (!is_user_logged_in()) {
        $fields['company'] = '<p class="comment-form-company comment-form-float-label"><input id="company" name="company" type="text" placeholder="Nome da sua empresa" value="" size="30" /><label class="float-label" for="company">Empresa</label></p>';
    }

    return $fields;
});

add_action('comment_form_after_fields', 'uonix_bottom_checkboxes_injection');

add_filter('comment_form_submit_field', function($submit_field) {
    $turnstile = uonix_comment_turnstile_widget_html();

    if ( '' === $turnstile ) {
        return $submit_field;
    }

    return $turnstile . $submit_field;
}, 20);

function uonix_get_logged_user_billing_company() {
    if (!is_user_logged_in()) {
        return '';
    }

    return sanitize_text_field(get_user_meta(get_current_user_id(), 'billing_company', true));
}

function uonix_bottom_checkboxes_html($include_cookies = true, $include_newsletter = true) {
    global $uonix_cookies_field;

    $checkboxes_html = '';

    if ($include_cookies && !empty($uonix_cookies_field) && !is_user_logged_in()) {
        $cookies_field = str_replace(
            'class="comment-form-cookies-consent"',
            'class="comment-form-cookies-consent uonix-custom-checkbox"',
            $uonix_cookies_field
        );

        $checkboxes_html .= $cookies_field;
    }

    // Newsletter aparece somente para visitantes/deslogados.
    if ($include_newsletter && !is_user_logged_in()) {
        $texto_news = 'Quero receber novidades e conteúdos da Uônix por e-mail (opcional)';

        $checkboxes_html .= '<p class="comment-form-newsletter uonix-custom-checkbox">
            <input id="uonix_comment_newsletter" name="uonix_comment_newsletter" type="checkbox" value="sim" />
            <label for="uonix_comment_newsletter">' . esc_html($texto_news) . '</label>
        </p>';
    }

    $html = '';

    if ($checkboxes_html !== '') {
        $html .= '<div class="uonix-bottom-checkboxes-wrapper">';
        $html .= $checkboxes_html;
        $html .= '</div>';
    }

    $html .= '<p class="uonix-lgpd-disclaimer" style="font-size: 14px; color: #64748b; margin-bottom: 15px; margin-left: 5px; line-height: 1.4;">
        Ao publicar um comentário, você concorda com nossa <a class="policy-privacy-link" href="/politica-de-privacidade/" target="_blank" rel="noopener">Política de Privacidade</a>.
    </p>';

    return $html;
}

function uonix_bottom_checkboxes_injection() {
    echo uonix_bottom_checkboxes_html(true, true);
}

// Para usuário logado, injeta Empresa oculta e LGPD depois do campo comentário.
// Não injeta newsletter para usuário logado.
add_filter('comment_form_field_comment', function($field) {
    if (!is_user_logged_in()) {
        return $field;
    }

    $empresa = uonix_get_logged_user_billing_company();

    $hidden_company = '<input type="hidden" id="uonix_company_hidden" name="company" value="' . esc_attr($empresa) . '" />';

    return $field . $hidden_company . uonix_bottom_checkboxes_html(false, false);
});

// ==============================================================================
// 2. BACK-END: PROCESSAMENTO E INTEGRAÇÃO FLUENT FORMS
// ==============================================================================

add_filter('preprocess_comment', function($commentdata) {
    if ( ! apply_filters( 'uonix_turnstile_protect_comment_form', true ) ) {
        return $commentdata;
    }

    if ( ! function_exists( 'uonix_turnstile_validate_request' ) || ! function_exists( 'uonix_turnstile_is_enabled' ) || ! uonix_turnstile_is_enabled() ) {
        return $commentdata;
    }

    $script_name = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';

    if ( 'wp-comments-post.php' !== $script_name ) {
        return $commentdata;
    }

    $comment_type = isset($commentdata['comment_type']) ? (string) $commentdata['comment_type'] : '';

    if ( '' !== $comment_type && 'comment' !== $comment_type ) {
        return $commentdata;
    }

    $validation = uonix_turnstile_validate_request( 'comment_post' );

    if ( is_wp_error( $validation ) ) {
        wp_die(
            new WP_Error(
                'uonix_turnstile_comment_failed',
                'Turnstile: ' . $validation->get_error_message()
            ),
            'Falha na verificação de segurança',
            array( 'response' => 403 )
        );
    }

    return $commentdata;
}, 1);

add_action('comment_post', function($comment_id) {
    $empresa_postada = isset($_POST['company']) ? uonix_comment_sync_upper_text(sanitize_text_field(wp_unslash($_POST['company']))) : '';

    if ($empresa_postada === '' && is_user_logged_in()) {
        $empresa_postada = uonix_get_logged_user_billing_company();
    }

    $empresa_postada = uonix_comment_sync_upper_text($empresa_postada);

    if ($empresa_postada !== '') {
        add_comment_meta($comment_id, 'company', $empresa_postada);
    }

    // Usuário logado não deve virar lead e não deve ser enviado para Fluent Forms.
    if (is_user_logged_in()) {
        return;
    }

    if (function_exists('wpFluentForm')) {
        try {
            $comment = get_comment($comment_id);

            if (!$comment) {
                return;
            }

            $link = get_permalink($comment->comment_post_ID);
            $path = $link ? wp_make_link_relative($link) : '/blog/';

            $optin = isset($_POST['uonix_comment_newsletter']) && $_POST['uonix_comment_newsletter'] === 'sim';

            $nome = uonix_comment_sync_upper_text(sanitize_text_field($comment->comment_author ?? ''));
            $email = strtolower(sanitize_email($comment->comment_author_email ?? ''));
            $empresa = uonix_comment_sync_upper_text($empresa_postada);

            $partes_nome = preg_split('/\s+/', trim($nome));
            $primeiro_nome = $partes_nome[0] ?? '';
            $ultimo_nome = count($partes_nome) > 1 ? end($partes_nome) : '';

            $handler = wpFluentForm()->make('\FluentForm\App\Services\Form\SubmissionHandlerService');
            $home_id = (int) get_option('page_on_front');

            // Formulário ID 4: Captura de Lead Geral.
            $handler->handleSubmission([
                '__fluent_form_embded_post_id' => $home_id,
                '_wp_http_referer'             => $path,
                'capturalead_nome'             => $nome,
                'capturalead_email'            => $email,
                'capturalead_newsletters'      => $optin ? 'SIM' : 'NAO',
                'capturalead_origem'           => 'COMENTÁRIOS NO BLOG',
                'capturalead_empresa'          => $empresa
            ], UONIX_FLUENT_FORM_LEAD_ID);

            if ($optin) {
                // Formulário ID 3: Newsletter.
                $handler->handleSubmission([
                    '__fluent_form_embded_post_id' => $home_id,
                    '_wp_http_referer'             => $path,
                    'newsletters_email'            => $email,
                    'newsletters_nome'             => $primeiro_nome,
                    'newsletters_sobrenome'        => $ultimo_nome,
                    'newsletters_empresa'          => $empresa,
                    'newsletters_termo'            => 'on',
                    'newsletters_origem'           => 'COMENTÁRIOS NO BLOG'
                ], UONIX_FLUENT_FORM_NEWSLETTER_ID);
            }
        } catch (Exception $e) {
            // Silencioso para não quebrar o envio do comentário.
        }
    }
});

// Coluna Empresa na listagem de comentários.
add_filter('manage_edit-comments_columns', function($cols) {
    $cols['company'] = 'Empresa';
    return $cols;
});

add_action('manage_comments_custom_column', function($col, $id) {
    if ($col === 'company') {
        echo esc_html(get_comment_meta($id, 'company', true) ?: '-');
    }
}, 10, 2);

// ==============================================================================
// 2.5. CAPTURA ERROS DO WP-COMMENTS-POST
// CAPTCHA continua inline; comentário duplicado abre modal.
// ==============================================================================

/**
 * Guarda o rascunho do comentário quando a validação falha.
 *
 * O fluxo de erro do WordPress é wp_die -> redirect, e o redirect descarta o $_POST.
 * Sem isto, quem escreveu um comentário longo e errou o captcha perdia tudo.
 *
 * O conteúdo fica num transient de 15 minutos, identificado por uma chave aleatória
 * de uso único que viaja na URL. O texto NUNCA vai para a query string: apareceria
 * em logs de acesso, no header Referer e no histórico do navegador, e comentários
 * longos estourariam o limite prático de tamanho da URL.
 */
if ( ! function_exists( 'uonix_comment_chave_rascunho' ) ) {
	function uonix_comment_chave_rascunho() {
		static $chave = null;

		if ( null === $chave ) {
			/*
			 * SÓ minúsculas e dígitos, de propósito.
			 *
			 * A leitura passa por sanitize_key(), que aplica strtolower(). Com
			 * wp_generate_password( 20, false, false ) — que inclui MAIÚSCULAS — a chave
			 * gravada divergia da chave lida em 99,99% dos casos e o transient nunca era
			 * encontrado: a preservação do rascunho não funcionava.
			 *
			 * Medido: (36/62)^20 = 1 acerto a cada ~52.700 tentativas.
			 *
			 * 20 caracteres em [a-z0-9] = 36^20 ≈ 1,3e31 combinações. Espaço de chave
			 * mais que suficiente para um identificador efêmero de 15 minutos.
			 */
			$chave = strtolower( wp_generate_password( 20, false, false ) );
		}

		return $chave;
	}
}

if ( ! function_exists( 'uonix_comment_guardar_rascunho' ) ) {
	function uonix_comment_guardar_rascunho() {
		if ( empty( $_POST['comment'] ) ) {
			return;
		}

		$rascunho = array(
			'comment' => wp_unslash( (string) $_POST['comment'] ),
			'author'  => isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['author'] ) ) : '',
			'email'   => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '',
			'url'     => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '',
		);

		// 15 min cobre com folga um novo desafio sem acumular lixo no banco.
		set_transient( 'uonix_cmt_draft_' . uonix_comment_chave_rascunho(), $rascunho, 15 * MINUTE_IN_SECONDS );
	}
}

if ( ! function_exists( 'uonix_comment_ler_rascunho' ) ) {
	function uonix_comment_ler_rascunho() {
		/*
		 * Cache por request: a leitura acontece em DOIS filtros
		 * (comment_form_field_comment e comment_form_defaults). Sem o cache, o segundo
		 * filtro não encontraria mais nada depois de o primeiro consumir o transient.
		 */
		static $memo = null;

		if ( null !== $memo ) {
			return $memo;
		}

		$chave = isset( $_GET['comment_draft'] ) ? sanitize_key( wp_unslash( (string) $_GET['comment_draft'] ) ) : '';

		if ( '' === $chave ) {
			$memo = array();
			return $memo;
		}

		$rascunho = get_transient( 'uonix_cmt_draft_' . $chave );
		$memo     = is_array( $rascunho ) ? $rascunho : array();

		/*
		 * Consumo de uso único.
		 *
		 * Sem isto o rascunho ficava até 15 min no banco e a chave, que viaja na URL,
		 * continuava válida — quem recebesse o link veria o que a outra pessoa digitou.
		 * Levantado na revisão do PR #109.
		 *
		 * O delete vem DEPOIS de carregar em $memo, então os dois filtros ainda recebem o
		 * conteúdo neste request; só um recarregamento da mesma URL vem vazio.
		 */
		if ( array() !== $memo ) {
			delete_transient( 'uonix_cmt_draft_' . $chave );
		}

		return $memo;
	}
}

/**
 * Devolve o texto ao formulário depois do redirect.
 *
 * Usa os filtros oficiais do WordPress em vez de reescrever o template do
 * formulário de comentários.
 */
add_filter( 'comment_form_field_comment', function ( $campo ) {
	$rascunho = uonix_comment_ler_rascunho();

	if ( empty( $rascunho['comment'] ) ) {
		return $campo;
	}

	// O textarea do core vem vazio (`></textarea>`); injeta o conteúdo preservado.
	return preg_replace(
		'/(<textarea[^>]*)>(\s*)<\/textarea>/',
		'$1>' . esc_textarea( $rascunho['comment'] ) . '</textarea>',
		$campo,
		1
	);
}, 20 );

add_filter( 'comment_form_defaults', function ( $defaults ) {
	$rascunho = uonix_comment_ler_rascunho();

	if ( ! $rascunho ) {
		return $defaults;
	}

	foreach ( array( 'author', 'email', 'url' ) as $campo ) {
		if ( empty( $rascunho[ $campo ] ) || empty( $defaults['fields'][ $campo ] ) ) {
			continue;
		}

		$defaults['fields'][ $campo ] = preg_replace(
			'/(name=["\']' . $campo . '["\'][^>]*?)value=["\'][^"\']*["\']/',
			'$1value="' . esc_attr( $rascunho[ $campo ] ) . '"',
			$defaults['fields'][ $campo ],
			1
		);
	}

	return $defaults;
}, 20 );

add_filter('wp_die_handler', function($handler) {
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';

    if (strpos($script, 'wp-comments-post.php') !== false) {
        return 'uonix_comment_custom_wp_die_handler';
    }

    return $handler;
});

function uonix_comment_custom_wp_die_handler($message, $title = '', $args = array()) {
    if (is_wp_error($message)) {
        $plain_message = $message->get_error_message();
    } else {
        $plain_message = wp_strip_all_tags((string) $message);
    }

    $post_id = isset($_POST['comment_post_ID']) ? absint($_POST['comment_post_ID']) : 0;

    if ($post_id) {
        $url = get_permalink($post_id);
    } else {
        $url = wp_get_referer();
    }

    if (!$url) {
        $url = home_url('/');
    }

    $is_captcha_error =
        stripos($plain_message, 'captcha') !== false ||
        stripos($plain_message, 'turnstile') !== false ||
        stripos($plain_message, 'verification failed') !== false ||
        stripos($plain_message, 'CAPTCHA verification failed') !== false;

    $is_duplicate_comment =
        stripos($plain_message, 'comentário repetido') !== false ||
        stripos($plain_message, 'comentario repetido') !== false ||
        stripos($plain_message, 'duplicate comment') !== false ||
        stripos($plain_message, 'already said that') !== false ||
        stripos($plain_message, 'você já disse isso') !== false ||
        stripos($plain_message, 'voce ja disse isso') !== false;

    $is_flood_comment =
        stripos($plain_message, 'rápido demais') !== false ||
        stripos($plain_message, 'rapido demais') !== false ||
        stripos($plain_message, 'comments too quickly') !== false ||
        stripos($plain_message, 'slow down') !== false ||
        stripos($plain_message, 'calma aí') !== false ||
        stripos($plain_message, 'calma ai') !== false;

    if ($is_captcha_error) {
        // Preserva o que a pessoa digitou. O redirect descarta o $_POST, então sem
        // isto um comentário longo era perdido só porque o captcha falhou.
        //
        // O conteúdo vai para um transient (NÃO para a query string): texto de
        // comentário na URL vazaria em logs de acesso, Referer e histórico, além de
        // estourar o limite prático de tamanho. Na URL viaja apenas uma chave
        // aleatória de uso único.
        uonix_comment_guardar_rascunho();

        $url = remove_query_arg('comment_error', $url);
        $url = add_query_arg('comment_error', 'captcha', $url);

        $chave = uonix_comment_chave_rascunho();
        if ($chave) {
            $url = add_query_arg('comment_draft', $chave, $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    if ($is_duplicate_comment) {
        $url = remove_query_arg('comment_error', $url);
        $url = add_query_arg('comment_error', 'duplicate', $url);

        wp_safe_redirect($url);
        exit;
    }

    if ($is_flood_comment) {
        $url = remove_query_arg('comment_error', $url);
        $url = add_query_arg('comment_error', 'flood', $url);

        wp_safe_redirect($url);
        exit;
    }

    _default_wp_die_handler($message, $title, $args);
}

// ==============================================================================
// 2.6. FEEDBACK ELEGANTE APÓS ENVIO DO COMENTÁRIO
// Mantém o comportamento nativo do WordPress.
// ==============================================================================

add_filter('comment_post_redirect', function($location, $comment) {
    if (!$comment) {
        return $location;
    }

    $status = isset($comment->comment_approved) ? (string) $comment->comment_approved : '0';

    if ($status === '1') {
        $feedback = 'approved';
    } else {
        $feedback = 'moderation';
    }

    $location = remove_query_arg('uonix_comment_status', $location);
    $location = add_query_arg('uonix_comment_status', $feedback, $location);

    return $location;
}, 10, 2);

// ==============================================================================
// 3. UI/UX: CSS, JS, TURNSTILE, MODAL E FEEDBACK
// ==============================================================================

add_action('wp_footer', function() {
    if (!is_singular() || !comments_open()) {
        return;
    }
    ?>

    <style type="text/css">
        /* ---------------------------------------------------------
           USUÁRIO LOGADO
           --------------------------------------------------------- */

        body.logged-in #commentform .comment-form-company {
            display: none !important;
        }

        body.logged-in #commentform .uonix-bottom-checkboxes-wrapper {
            margin-top: 20px !important;
        }

        /* ---------------------------------------------------------
           FEEDBACK APÓS ENVIO DO COMENTÁRIO
           --------------------------------------------------------- */

        .uonix-comment-feedback {
            display: none;
            margin: 0 0 22px 0;
            padding: 15px 17px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.5;
        }

        .uonix-comment-feedback-success {
            border: 1px solid #a7f3d0;
            border-left: 4px solid #12b76a;
            background: #ecfdf3;
            color: #077f47;
        }

        .uonix-comment-feedback-moderation {
            border: 1px solid #a7f3d0;
            border-left: 4px solid #12b76a;
            background: #ecfdf3;
            color: #077f47;
        }

        /* ---------------------------------------------------------
           MODAIS (DUPLICADO / FLOOD)
           --------------------------------------------------------- */

        #uonix-comment-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.58);
            z-index: 99998;
        }

        .uonix-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            width: calc(100% - 32px);
            max-width: 460px;
            transform: translate(-50%, -50%);
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
            z-index: 99999;
            overflow: hidden;
        }

        .uonix-modal .uonix-comment-modal-header {
            padding: 22px 24px 0 24px;
        }

        .uonix-modal .uonix-comment-modal-title {
            margin: 0;
            color: #111827;
            font-size: 20px;
            font-weight: 800;
            line-height: 1.3;
        }

        .uonix-modal .uonix-comment-modal-body {
            padding: 14px 24px 22px 24px;
        }

        .uonix-modal .uonix-comment-modal-body p {
            margin: 0;
            color: #475569;
            font-size: 15px;
            line-height: 1.55;
        }

        .uonix-modal .uonix-comment-modal-actions {
            padding: 0 24px 24px 24px;
        }

        .uonix-modal .uonix-comment-modal-close {
            width: 100%;
            border: none;
            border-radius: 8px;
            background: #0e3780;
            color: #ffffff;
            padding: 13px 18px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 800;
            text-transform: uppercase;
            transition: all 0.2s ease;
        }

        .uonix-modal .uonix-comment-modal-close:hover {
            background: #0b2d68;
        }

        /* ---------------------------------------------------------
           GRID 3 COLUNAS
           --------------------------------------------------------- */

        .comment-form-url {
            display: none !important;
        }

        @media (min-width: 768px) {
            .comment-input-wrap {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 20px !important;
            }
        }

        .comment-input-wrap p {
            margin: 0 !important;
            width: 100% !important;
            position: relative !important;
        }

        #author,
        #email,
        #company {
            width: 100% !important;
            box-sizing: border-box !important;
            background-color: var(--global-palette9, #f7f9f9) !important;
            border: 1px solid #cccccc !important;
            border-radius: 4px !important;
            color: var(--global-palette3, #334155) !important;
            transition: all 0.2s ease !important;
            height: 52px !important;
            padding: 22px 15px 4px !important;
        }

        #author:focus,
        #email:focus,
        #company:focus {
            background-color: #ffffff !important;
            border-color: #0e3780 !important;
            box-shadow: 0 0 0 1px #0e3780 !important;
            outline: none !important;
        }

        .comment-input-wrap label.float-label {
            position: absolute !important;
            left: 15px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            font-size: 15px !important;
            color: #64748b !important;
            transition: all 0.2s ease !important;
            pointer-events: none !important;
            margin: 0 !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .comment-input-wrap input:focus ~ label.float-label,
        .comment-input-wrap input:not(:placeholder-shown) ~ label.float-label {
            top: 14px !important;
            font-size: 11px !important;
            color: #0e3780 !important;
        }

        /* ---------------------------------------------------------
           CHECKBOXES PREMIUM
           --------------------------------------------------------- */

        .uonix-bottom-checkboxes-wrapper {
            margin: 20px 0 30px 0;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .uonix-custom-checkbox {
            display: block !important;
            margin: 0 !important;
        }

        .uonix-custom-checkbox input[type="checkbox"] {
            display: none !important;
        }

        .uonix-custom-checkbox label {
            display: flex !important;
            align-items: flex-start !important;
            cursor: pointer;
            font-size: 14px;
            color: #555;
            line-height: 1.5 !important;
            white-space: normal !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .uonix-custom-checkbox label::before {
            content: '';
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border: 2px solid #cbd5e1;
            border-radius: 4px;
            background-color: #fff;
            margin-right: 12px;
            margin-top: 0;
            transition: all 0.2s ease;
        }

        .uonix-custom-checkbox label:hover::before {
            border-color: #f76a0c;
        }

        .comment-form input[type=checkbox] + label {
            font-size: 90%;
        }

        .uonix-custom-checkbox input[type="checkbox"]:checked + label::before {
            background-color: #f76a0c;
            border-color: #f76a0c;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'%3E%3C/polyline%3E%3C/svg%3E");
            background-size: 14px;
            background-position: center;
            background-repeat: no-repeat;
        }

        /* ---------------------------------------------------------
           CLOUDFLARE TURNSTILE
           --------------------------------------------------------- */

        #commentform .uonix-comment-turnstile {
            display: block !important;
            width: 100% !important;
            min-height: 0 !important;
            margin: 0 !important;
        }

        #commentform .cf-turnstile {
            display: block !important;
            width: 100% !important;
            min-height: 65px !important;
            margin: 10px 0 18px 0 !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
        }

        #commentform .uonix-turnstile-widget {
            display: block !important;
            width: 100% !important;
            min-height: 0 !important;
            margin: 0 !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
        }

        #commentform .uonix-comment-turnstile.uonix-turnstile-visible .uonix-turnstile-widget,
        #commentform .uonix-turnstile-widget.uonix-turnstile-error {
            min-height: 65px !important;
            margin: 10px 0 18px 0 !important;
        }

        #commentform .cf-turnstile iframe,
        #commentform .uonix-turnstile-widget iframe {
            display: block !important;
        }

        #commentform .form-submit {
            margin-top: 0 !important;
        }

        .uonix-turnstile-error-message {
            display: none;
            margin: 0 0 12px 0;
            padding: 12px 14px;
            border: 1px solid #f5b5ae;
            border-left: 4px solid #d92d20;
            border-radius: 6px;
            background: #fff5f4;
            color: #b42318;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.45;
        }

        .uonix-turnstile-error-message::before {
            content: "⚠ ";
            font-weight: 800;
        }

        #commentform .cf-turnstile.uonix-turnstile-error,
        #commentform .uonix-turnstile-widget.uonix-turnstile-error {
            padding: 8px !important;
            border: 2px solid #d92d20 !important;
            background: #fff5f4 !important;
            box-shadow: 0 0 0 4px rgba(217, 45, 32, 0.12) !important;
        }

        /* ---------------------------------------------------------
           SPINNER NO BOTÃO
           --------------------------------------------------------- */

        .uonix-loading {
            position: relative !important;
            color: transparent !important;
            pointer-events: none !important;
        }

        .uonix-loading::after {
            content: "" !important;
            position: absolute !important;
            width: 20px !important;
            height: 20px !important;
            top: 50% !important;
            left: 50% !important;
            margin-top: -10px !important;
            margin-left: -10px !important;
            border: 3px solid rgba(255, 255, 255, 0.3) !important;
            border-top-color: #ffffff !important;
            border-radius: 50% !important;
            animation: uonix-button-spin 0.8s linear infinite !important;
        }

        @keyframes uonix-button-spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <div id="uonix-comment-modal-overlay"></div>

    <div id="uonix-comment-modal" class="uonix-modal" role="dialog" aria-modal="true" aria-labelledby="uonix-comment-modal-title">
        <div class="uonix-comment-modal-header">
            <h4 id="uonix-comment-modal-title" class="uonix-comment-modal-title">Comentário já enviado</h4>
        </div>

        <div class="uonix-comment-modal-body">
            <p>Identificamos que este comentário já foi enviado anteriormente. Para evitar duplicidade, ele não foi publicado novamente.</p>
        </div>

        <div class="uonix-comment-modal-actions">
            <button type="button" class="uonix-comment-modal-close">Entendi</button>
        </div>
    </div>

    <div id="uonix-flood-modal" class="uonix-modal" role="dialog" aria-modal="true" aria-labelledby="uonix-flood-modal-title">
        <div class="uonix-comment-modal-header">
            <h4 id="uonix-flood-modal-title" class="uonix-comment-modal-title">Aguarde um instante</h4>
        </div>

        <div class="uonix-comment-modal-body">
            <p>Por motivos de segurança, há um intervalo mínimo entre os envios de comentários. Aguarde alguns segundos e tente publicar novamente.</p>
        </div>

        <div class="uonix-comment-modal-actions">
            <button type="button" class="uonix-comment-modal-close">Entendi</button>
        </div>
    </div>


    <script type="text/javascript">
        window.uonixCommentData = <?php echo wp_json_encode([
            'isLoggedIn'     => is_user_logged_in(),
            'billingCompany' => uonix_get_logged_user_billing_company(),
        ]); ?>;
    </script>

    <script type="text/javascript">
    (function($) {
        $(document).ready(function() {

            function uonixTranslateReplyLinks() {
                $('.comment-reply-link').each(function() {
                    var $link = $(this);

                    if ($.trim($link.text()) === 'Reply') {
                        $link.text('Responder');
                    }
                });
            }

            function uonixEnsureTurnstileErrorElement() {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();

                if (!$turnstile.length) {
                    return $();
                }

                var $error = $form.find('.uonix-turnstile-error-message').first();

                if (!$error.length) {
                    $error = $('<div class="uonix-turnstile-error-message" role="alert" aria-live="polite"></div>');
                    $error.insertBefore($turnstile);
                }

                return $error;
            }

            function uonixMoveTurnstileBeforeSubmit() {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();
                var $submitWrap = $form.find('.form-submit').first();

                if (!$turnstile.length || !$submitWrap.length) {
                    return;
                }

                $turnstile.prev('br').remove();

                if (!$turnstile.next().is($submitWrap)) {
                    $turnstile.insertBefore($submitWrap);
                }

                uonixEnsureTurnstileErrorElement();
            }

            function uonixPrepareLoggedInCompany() {
                var $form = $('#commentform');

                if (!$form.length || !$form.find('.logged-in-as').length) {
                    return;
                }

                var billingCompany = window.uonixCommentData && window.uonixCommentData.billingCompany
                    ? window.uonixCommentData.billingCompany
                    : '';

                $form.find('.comment-form-company').hide();
                $form.find('input[name="company"]').val(billingCompany);

                if (!$form.find('input[name="company"]').length) {
                    $('<input>', {
                        type: 'hidden',
                        id: 'uonix_company_hidden_js',
                        name: 'company',
                        value: billingCompany
                    }).insertAfter($form.find('#comment'));
                }
            }

            function uonixNormalizeCommentFormOrder() {
                var $form = $('#commentform');

                if (!$form.length) {
                    return;
                }

                var $bottom = $form.find('.uonix-bottom-checkboxes-wrapper').first();
                var $lgpd = $form.find('.uonix-lgpd-disclaimer').first();
                var $error = $form.find('.uonix-turnstile-error-message').first();
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();
                var $submitWrap = $form.find('.form-submit').first();

                var $anchor = $error.length ? $error : ($turnstile.length ? $turnstile : $submitWrap);

                if (!$anchor.length) {
                    return;
                }

                if ($bottom.length && $lgpd.length) {
                    if (!$bottom.next().is($lgpd) || !$lgpd.next().is($anchor)) {
                        $bottom.insertBefore($anchor);
                        $lgpd.insertBefore($anchor);
                    }
                } else if ($bottom.length && !$bottom.next().is($anchor)) {
                    $bottom.insertBefore($anchor);
                } else if ($lgpd.length && !$lgpd.next().is($anchor)) {
                    $lgpd.insertBefore($anchor);
                }
            }

            function uonixClearTurnstileToken($form) {
                $form.find('[name="cf-turnstile-response"]').val('');
            }

            function uonixSyncTurnstileVisibility() {
                var $form = $('#commentform');

                $form.find('.cf-turnstile, .uonix-turnstile-widget').each(function() {
                    var widget = this;
                    var iframe = widget.querySelector('iframe');
                    var hasVisibleFrame = false;
                    var hasError = widget.classList.contains('uonix-turnstile-error');
                    var wrapper = widget.closest('.uonix-comment-turnstile');

                    if (iframe) {
                        hasVisibleFrame = iframe.offsetWidth > 0 && iframe.offsetHeight > 0;
                    }

                    widget.classList.toggle('uonix-turnstile-visible', hasVisibleFrame || hasError);

                    if (wrapper) {
                        wrapper.classList.toggle('uonix-turnstile-visible', hasVisibleFrame || hasError);
                    }
                });
            }

            function uonixForceTurnstileRenderIfBlank() {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();

                if (!$turnstile.length) {
                    return;
                }

                if ($turnstile.find('iframe').length) {
                    return;
                }

                var element = $turnstile.get(0);
                var sitekey = $turnstile.attr('data-sitekey');

                if (!sitekey) {
                    return;
                }

                if (element.getAttribute('data-uonix-rendering') === '1') {
                    return;
                }

                function renderNow() {
                    if (!window.turnstile || typeof window.turnstile.render !== 'function') {
                        return false;
                    }

                    if ($turnstile.find('iframe').length) {
                        return true;
                    }

                    element.setAttribute('data-uonix-rendering', '1');

                    try {
                        var oldWidgetId = element.getAttribute('data-uonix-widget-id');

                        if (oldWidgetId && typeof window.turnstile.remove === 'function') {
                            try {
                                window.turnstile.remove(oldWidgetId);
                            } catch (removeError) {}
                        }

                        element.removeAttribute('data-uonix-widget-id');
                        element.removeAttribute('data-uonix-rendered');

                        uonixClearTurnstileToken($form);
                        $turnstile.empty();

                        var widgetId = window.turnstile.render(element, {
                            sitekey: sitekey,
                            action: $turnstile.attr('data-action') || 'comment_post',
                            theme: $turnstile.attr('data-theme') || 'light',
                            language: $turnstile.attr('data-language') || 'pt-BR',
                            size: $turnstile.attr('data-size') || 'flexible',
                            appearance: $turnstile.attr('data-appearance') || 'interaction-only',
                            'response-field': false,
                            callback: function(token) {
                                var $tokenInput = $form.find('input[name="cf-turnstile-response"]').last();

                                if (!$tokenInput.length) {
                                    $tokenInput = $('<input>', {
                                        type: 'hidden',
                                        name: 'cf-turnstile-response'
                                    }).appendTo($form);
                                }

                                $tokenInput.val(token);
                                uonixClearTurnstileError();
                            },
                            'expired-callback': function() {
                                uonixClearTurnstileToken($form);
                            },
                            'error-callback': function() {
                                uonixClearTurnstileToken($form);
                            }
                        });

                        if (widgetId) {
                            element.setAttribute('data-uonix-widget-id', widgetId);
                        }

                        element.setAttribute('data-uonix-rendered', '1');
                        element.removeAttribute('data-uonix-rendering');
                        uonixSyncTurnstileVisibility();
                        setTimeout(uonixSyncTurnstileVisibility, 250);
                        setTimeout(uonixSyncTurnstileVisibility, 900);
                        setTimeout(uonixSyncTurnstileVisibility, 1800);

                        return true;
                    } catch (e) {
                        element.removeAttribute('data-uonix-rendering');
                        uonixSyncTurnstileVisibility();
                        return false;
                    }
                }

                if (renderNow()) {
                    return;
                }

                if (!document.querySelector('script[src*="challenges.cloudflare.com/turnstile/v0/api.js"]')) {
                    var script = document.createElement('script');
                    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                    script.async = true;
                    script.defer = true;
                    script.onload = function() {
                        setTimeout(renderNow, 200);
                    };
                    document.head.appendChild(script);
                } else {
                    setTimeout(renderNow, 300);
                    setTimeout(renderNow, 900);
                    setTimeout(renderNow, 1800);
                }
            }

            function uonixResetTurnstileWidget() {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();

                if (!$turnstile.length) {
                    return;
                }

                var element = $turnstile.get(0);
                var widgetId = element.getAttribute('data-uonix-widget-id');

                if (widgetId && window.turnstile && typeof window.turnstile.remove === 'function') {
                    try {
                        window.turnstile.remove(widgetId);
                    } catch (e) {}
                }

                element.removeAttribute('data-uonix-widget-id');
                element.removeAttribute('data-uonix-rendered');
                element.removeAttribute('data-uonix-rendering');

                uonixClearTurnstileToken($form);
                $turnstile.empty();

                uonixForceTurnstileRenderIfBlank();
            }

            function uonixHasTurnstileToken($form) {
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();

                if (!$turnstile.length) {
                    return true;
                }

                var tokenValue = '';

                $form.find('[name="cf-turnstile-response"]').each(function() {
                    var value = $.trim($(this).val());

                    if (value !== '') {
                        tokenValue = value;
                    }
                });

                return tokenValue !== '';
            }

            function uonixShowTurnstileError(message) {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();
                var $error = uonixEnsureTurnstileErrorElement();

                if (!$turnstile.length || !$error.length) {
                    return;
                }

                $error.text(message).slideDown(180);
                $turnstile.addClass('uonix-turnstile-error');
                $turnstile.attr('aria-invalid', 'true');
                uonixSyncTurnstileVisibility();
            }

            function uonixClearTurnstileError() {
                var $form = $('#commentform');
                var $turnstile = $form.find('.cf-turnstile, .uonix-turnstile-widget').first();
                var $error = $form.find('.uonix-turnstile-error-message').first();

                if ($error.length) {
                    $error.slideUp(150);
                }

                if ($turnstile.length) {
                    $turnstile.removeClass('uonix-turnstile-error');
                    $turnstile.removeAttr('aria-invalid');
                }

                uonixSyncTurnstileVisibility();
            }

            var uonixCommentObserver = null;
            var uonixRefreshScheduled = false;
            var uonixRefreshing = false;

            function uonixObserveCommentArea() {
                var commentsArea = document.getElementById('comments');

                if (!commentsArea || uonixCommentObserver) {
                    return;
                }

                uonixCommentObserver = new MutationObserver(function() {
                    // Ignora mutações geradas pelo próprio refresh.
                    if (uonixRefreshing || uonixRefreshScheduled) {
                        return;
                    }

                    uonixRefreshScheduled = true;

                    setTimeout(function() {
                        uonixRefreshScheduled = false;
                        uonixRefreshCommentForm();
                    }, 250);
                });

                uonixCommentObserver.observe(commentsArea, {
                    childList: true,
                    subtree: true
                });
            }

            function uonixRunRefresh() {
                // Desliga o observer enquanto manipula o DOM para evitar loop de mutações.
                uonixRefreshing = true;

                if (uonixCommentObserver) {
                    uonixCommentObserver.disconnect();
                }

                try {
                    uonixTranslateReplyLinks();
                    uonixMoveTurnstileBeforeSubmit();
                    uonixPrepareLoggedInCompany();
                    uonixNormalizeCommentFormOrder();
                    uonixForceTurnstileRenderIfBlank();
                    uonixSyncTurnstileVisibility();
                    setTimeout(uonixSyncTurnstileVisibility, 600);
                } finally {
                    var commentsArea = document.getElementById('comments');

                    if (uonixCommentObserver && commentsArea) {
                        uonixCommentObserver.observe(commentsArea, {
                            childList: true,
                            subtree: true
                        });
                    }

                    uonixRefreshing = false;
                }
            }

            function uonixRefreshCommentForm() {
                uonixRunRefresh();
            }

            uonixObserveCommentArea();
            uonixRefreshCommentForm();

            setTimeout(uonixRefreshCommentForm, 500);
            setTimeout(uonixRefreshCommentForm, 1500);
            setTimeout(uonixRefreshCommentForm, 3000);

            $(document).on('click', '.comment-reply-link, #cancel-comment-reply-link', function() {
                setTimeout(uonixRefreshCommentForm, 80);
                setTimeout(function() {
                    uonixResetTurnstileWidget();
                    uonixRefreshCommentForm();
                }, 260);
                setTimeout(uonixRefreshCommentForm, 800);
                setTimeout(uonixRefreshCommentForm, 1600);
            });

            $(document).on('click', '.uonix-custom-checkbox label', function(e) {
                var checkbox = $(this).prev('input[type="checkbox"]');

                if ($(e.target).is('a')) {
                    return;
                }

                checkbox.prop('checked', !checkbox.prop('checked'));
                e.preventDefault();
            });

            $('#commentform').on('submit', function(e) {
                var $form = $(this);
                var $submitBtn = $form.find('#submit');

                if (!this.checkValidity()) {
                    return true;
                }

                if (!uonixHasTurnstileToken($form)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    $submitBtn.removeClass('uonix-loading');
                    $submitBtn.prop('disabled', false);

                    uonixShowTurnstileError(
                        'Confirme a verificação de segurança do Cloudflare antes de publicar seu comentário.'
                    );

                    uonixForceTurnstileRenderIfBlank();

                    return false;
                }

                uonixClearTurnstileError();

                $submitBtn.addClass('uonix-loading');
                $submitBtn.prop('disabled', true);

                return true;
            });

            // O erro do Turnstile é limpo diretamente pelo callback de sucesso
            // do widget (uonixClearTurnstileError), sem necessidade de polling.

            function uonixEnsureCommentFeedbackElement() {
                var $form = $('#commentform');

                if (!$form.length) {
                    return $();
                }

                var $feedback = $('#uonix-comment-feedback');

                if (!$feedback.length) {
                    $feedback = $('<div id="uonix-comment-feedback" class="uonix-comment-feedback" role="status" aria-live="polite"></div>');
                    $feedback.insertBefore($form);
                }

                return $feedback;
            }

            function uonixShowCommentFeedback(status) {
                var $feedback = uonixEnsureCommentFeedbackElement();

                if (!$feedback.length) {
                    return;
                }

                var message = '';
                var feedbackClass = '';

                if (status === 'approved') {
                    message = 'Comentário publicado com sucesso. Obrigado por participar da conversa!';
                    feedbackClass = 'uonix-comment-feedback-success';
                } else {
                    message = 'Recebemos seu comentário com sucesso. Obrigado por participar! Após a aprovação, sua publicação ficará visível para todos.';
                    feedbackClass = 'uonix-comment-feedback-moderation';
                }

                $feedback
                    .removeClass('uonix-comment-feedback-success uonix-comment-feedback-moderation')
                    .addClass(feedbackClass)
                    .text(message)
                    .slideDown(180);
            }

            function uonixOpenModal(modalId) {
                $('#uonix-comment-modal-overlay, #' + modalId).fadeIn(180);
            }

            function uonixCloseModal() {
                $('#uonix-comment-modal-overlay, .uonix-modal').fadeOut(160);
            }

            $('.uonix-comment-modal-close, #uonix-comment-modal-overlay').on('click', function() {
                uonixCloseModal();
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    uonixCloseModal();
                }
            });

            var successUrlParams = new URLSearchParams(window.location.search);
            var commentStatus = successUrlParams.get('uonix_comment_status');

            if (commentStatus) {
                uonixShowCommentFeedback(commentStatus);

                successUrlParams.delete('uonix_comment_status');

                var successCleanUrl = window.location.pathname;

                if (successUrlParams.toString()) {
                    successCleanUrl += '?' + successUrlParams.toString();
                }

                successCleanUrl += window.location.hash;

                window.history.replaceState({}, document.title, successCleanUrl);
            }

            var urlParams = new URLSearchParams(window.location.search);
            var commentError = urlParams.get('comment_error');

            if (commentError === 'captcha') {
                uonixRefreshCommentForm();

                setTimeout(function() {
                    uonixShowTurnstileError(
                        'A verificação de segurança do Cloudflare falhou ou expirou. Confirme novamente e tente publicar o comentário.'
                    );
                }, 300);

                urlParams.delete('comment_error');

                var cleanUrl = window.location.pathname;

                if (urlParams.toString()) {
                    cleanUrl += '?' + urlParams.toString();
                }

                cleanUrl += window.location.hash;

                window.history.replaceState({}, document.title, cleanUrl);
            }

            if (commentError === 'duplicate') {
                setTimeout(function() {
                    uonixOpenModal('uonix-comment-modal');
                }, 250);

                urlParams.delete('comment_error');

                var duplicateCleanUrl = window.location.pathname;

                if (urlParams.toString()) {
                    duplicateCleanUrl += '?' + urlParams.toString();
                }

                duplicateCleanUrl += window.location.hash;

                window.history.replaceState({}, document.title, duplicateCleanUrl);
            }

            if (commentError === 'flood') {
                setTimeout(function() {
                    uonixOpenModal('uonix-flood-modal');
                }, 250);

                urlParams.delete('comment_error');

                var floodCleanUrl = window.location.pathname;

                if (urlParams.toString()) {
                    floodCleanUrl += '?' + urlParams.toString();
                }

                floodCleanUrl += window.location.hash;

                window.history.replaceState({}, document.title, floodCleanUrl);
            }

        });
    })(jQuery);
    </script>

    <?php
}, 100);
