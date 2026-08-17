<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Formulario - Trabalhe Conosco e upload de curriculo.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 10877-11759 do export original.
// -----------------------------------------------------------------------------
/**
 * Formulário Trabalhe Conosco
 */
/**
 * UÔNIX: Formulário Trabalhe Conosco Customizado
 * - Interface limpa com campo de upload (PDF, DOC, DOCX até 3MB)
 * - Integração com o Fluent Forms ID 3 (Contato)
 * - Currículos salvos em /wp-content/uploads/curriculos-recebidos
 * - Link protegido por token, sem exigir login no WordPress
 * - Auto-Limpeza LGPD: exclui currículos após 30 dias
 * - Uso: [uonix_form_trabalhe]
 */

// ==============================================================================
// 0. CONFIGURAÇÕES E FUNÇÕES AUXILIARES
// ==============================================================================

function uonix_get_curriculos_subdir() {
    return '/curriculos-recebidos';
}

function uonix_get_curriculos_dir_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . uonix_get_curriculos_subdir();
}

function uonix_get_curriculos_dir_url() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'] . uonix_get_curriculos_subdir();
}

/**
 * Cria a pasta dos currículos e adiciona proteções básicas.
 * O .htaccess bloqueia acesso direto em servidores Apache/LiteSpeed.
 */
function uonix_garantir_pasta_curriculos_protegida() {
    $dir_path = uonix_get_curriculos_dir_path();

    if (!is_dir($dir_path)) {
        if (!wp_mkdir_p($dir_path)) {
            return false;
        }
    }

    if (!is_writable($dir_path)) {
        return false;
    }

    $htaccess_path = trailingslashit($dir_path) . '.htaccess';

    $htaccess_content = <<<HTACCESS
# UONIX - Bloqueia acesso direto aos currículos
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

    if (!file_exists($htaccess_path)) {
        if (false === @file_put_contents($htaccess_path, $htaccess_content)) {
            return false;
        }
    }

    $index_path = trailingslashit($dir_path) . 'index.php';

    if (!file_exists($index_path)) {
        @file_put_contents($index_path, "<?php\n// Silence is golden.\n");
    }

    return true;
}

/**
 * Gera token seguro para o link protegido.
 */
function uonix_gerar_token_curriculo() {
    try {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }
    } catch (\Exception $e) {
        // Fallback abaixo.
    }

    return hash('sha256', uniqid('', true) . wp_rand() . microtime(true));
}

/**
 * Gera URL protegida por token.
 * O token expira em 30 dias, igual ao prazo de exclusão do currículo.
 */
function uonix_gerar_link_protegido_curriculo($filename) {
    $token = uonix_gerar_token_curriculo();

    set_transient(
        'uonix_cv_' . $token,
        array(
            'file'    => basename($filename),
            'created' => time(),
        ),
        30 * DAY_IN_SECONDS
    );

    $url = add_query_arg(
        array(
            'action' => 'uonix_baixar_curriculo',
            'token'  => $token,
        ),
        admin_url('admin-post.php')
    );

    return array(
        'url'   => $url,
        'token' => $token,
    );
}

/**
 * Gera o nome final do arquivo no padrão:
 * cv_uonix_xxxxxxxxxxxx_cassio-lima.pdf
 */
function uonix_gerar_nome_arquivo_curriculo($nome_candidato, $nome_arquivo_original) {
    $ext = strtolower(pathinfo($nome_arquivo_original, PATHINFO_EXTENSION));

    // Limpa espaços extras
    $nome_candidato = trim(preg_replace('/\s+/', ' ', $nome_candidato));

    // Remove acentos e caracteres especiais, mantendo o nome em formato slug
    $nome_slug_completo = sanitize_title(remove_accents($nome_candidato));

    if (empty($nome_slug_completo)) {
        $nome_slug = 'candidato';
    } else {
        $partes = explode('-', $nome_slug_completo);

        if (count($partes) === 1) {
            // Caso a pessoa informe apenas um nome
            $nome_slug = $partes[0];
        } else {
            // Primeiro nome + último nome
            $primeiro_nome = reset($partes);
            $ultimo_nome   = end($partes);

            $nome_slug = $primeiro_nome . '-' . $ultimo_nome;
        }
    }

    // Código único curto para evitar nomes repetidos
    $codigo_unico = substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);

    // Resultado:
    // cv_uonix_cassio-nascimento_525236778e7b_.docx
    return 'cv_uonix_' . $nome_slug . '_' . $codigo_unico . '_.' . $ext;
}

// ==============================================================================
// 1. DOWNLOAD/VISUALIZAÇÃO PROTEGIDA POR TOKEN
// ==============================================================================

add_action('admin_post_uonix_baixar_curriculo', 'uonix_baixar_curriculo_handler');
add_action('admin_post_nopriv_uonix_baixar_curriculo', 'uonix_baixar_curriculo_handler');

function uonix_baixar_curriculo_handler() {
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

    if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        wp_die('Link inválido.', 'Arquivo indisponível', array('response' => 403));
    }

    $dados = get_transient('uonix_cv_' . $token);

    if (empty($dados) || empty($dados['file'])) {
        wp_die('Link expirado ou arquivo indisponível.', 'Arquivo indisponível', array('response' => 404));
    }

    $filename = sanitize_file_name($dados['file']);
    $base_dir = realpath(uonix_get_curriculos_dir_path());
    $file_path = realpath(trailingslashit(uonix_get_curriculos_dir_path()) . $filename);

    if (!$base_dir || !$file_path || strpos($file_path, $base_dir . DIRECTORY_SEPARATOR) !== 0) {
        wp_die('Arquivo inválido.', 'Arquivo indisponível', array('response' => 403));
    }

    if (!file_exists($file_path) || !is_readable($file_path)) {
        wp_die('Arquivo não encontrado.', 'Arquivo indisponível', array('response' => 404));
    }

    $filetype = wp_check_filetype($file_path);
    $mime_type = !empty($filetype['type']) ? $filetype['type'] : 'application/octet-stream';
    $download_name = basename($file_path);
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    // PDF abre no navegador; DOC/DOCX força download.
    $disposition = ($ext === 'pdf') ? 'inline' : 'attachment';

    while (ob_get_level()) {
        ob_end_clean();
    }

    nocache_headers();

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $download_name) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('X-Content-Type-Options: nosniff');

    readfile($file_path);
    exit;
}

// ==============================================================================
// 2. FRONTEND: VISUAL DO FORMULÁRIO (SHORTCODE)
// ==============================================================================

add_shortcode('uonix_form_trabalhe', 'uonix_gerar_form_trabalhe_html');

function uonix_gerar_form_trabalhe_html() {
    ob_start();
    ?>
    <style>
        .uonix-trab-wrapper { display: flex; flex-direction: column; width: 100%; font-family: inherit; position: relative; max-width: 600px; margin: 0 !important; }
        .uonix-trab-wrapper .unf_form_fields { display: flex; flex-direction: column; gap: 16px; width: 100%; transition: opacity 0.3s ease; }
        .uonix-trab-states { min-height: 620px; width: 100%; }
        .uonix-trab-group { position: relative; width: 100%; display: flex; flex-direction: column; }

        .uonix-trab-group input,
        .uonix-trab-group textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px 4px !important;
            font-size: 17px;
            color: #1a202c;
            background: #ffffff;
            transition: all 0.3s ease;
            outline: none !important;
            box-sizing: border-box;
            box-shadow: none !important;
        }

        .uonix-trab-group input { height: 50px; }
        .uonix-trab-group textarea { min-height: 100px; resize: vertical; padding-top: 22px !important; }

        .uonix-trab-group input:focus,
        .uonix-trab-group textarea:focus {
            border-color: #0e3780;
            box-shadow: 0 0 0 2px rgba(14, 55, 128, 0.1) !important;
        }

        .uonix-trab-label {
            position: absolute;
            left: 14px;
            top: 16px;
            color: #64748b;
            font-size: 15px;
            pointer-events: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0;
            line-height: 1;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            text-shadow: none !important;
            background: transparent !important;
        }

        #uonix-custom-trab-form .uonix-trab-group input:focus ~ .uonix-trab-label,
        #uonix-custom-trab-form .uonix-trab-group input:not(:placeholder-shown) ~ .uonix-trab-label,
        #uonix-custom-trab-form .uonix-trab-group textarea:focus ~ .uonix-trab-label,
        #uonix-custom-trab-form .uonix-trab-group textarea:not(:placeholder-shown) ~ .uonix-trab-label {
            top: -2px;
            line-height: 0.3;
            padding-left: 2px;
            padding-right: 2px;
            font-size: 13px;
            color: #f76a0c;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            text-shadow: none !important;
            background: #FFFFFF !important;
        }

        .uonix-file-upload-box {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 90px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #475569;
            text-align: center;
        }

        .uonix-file-upload-box:hover { border-color: #0e3780; background: #f1f5f9; }

        .uonix-file-upload-box input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            top: 0;
            left: 0;
        }

        .uonix-file-icon { color: #0e3780; margin-bottom: 4px; }
        .uonix-file-text { font-size: 14px; font-weight: 700; }
        .uonix-file-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .uonix-file-upload-box.has-file { border-color: #22c55e; background: #f0fdf4; border-style: solid; }
        .uonix-file-upload-box.has-error { border-color: #dc2626; background: #fef2f2; }

        .uonix-trab-checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            position: relative;
            user-select: none;
            margin-top: 4px;
        }

        .uonix-trab-checkbox-wrapper input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            margin: 0;
        }

        .uonix-trab-box {
            width: 20px;
            height: 20px;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            transition: all 0.2s;
            flex-shrink: 0;
            background: #fff;
            margin-top: 1px;
        }

        .uonix-trab-checkbox-wrapper input:checked ~ .uonix-trab-box {
            background: #0e3780;
            border-color: #0e3780;
        }

        .uonix-trab-box::after {
            content: "";
            width: 4px;
            height: 9px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
            opacity: 0;
            margin-bottom: 2px;
        }

        .uonix-trab-checkbox-wrapper input:checked ~ .uonix-trab-box::after { opacity: 1; }

        .uonix-trab-check-text {
            font-size: 14px;
            color: #475569;
            line-height: 1.45;
            margin: 0;
        }

        .uonix-trab-aviso-text {
            font-size: 13px;
            color: #475569;
            line-height: 1.45;
            margin-left: 2px;
        }

        .uonix-btn-submit-trab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin-top: 8px;
            height: 50px;
            padding: 0 24px;
            background: #f76a0c;
            color: #ffffff !important;
            font-size: 16px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(247, 106, 12, 0.2);
        }

        .uonix-btn-submit-trab:hover:not(:disabled) {
            background: #e05e07;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(247, 106, 12, 0.3);
        }

        .uonix-btn-submit-trab:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .uonix-feedback-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            display: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }

        .uonix-success-wrapper {
            display: none;
            width: 100%;
            min-height: 620px;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 40px 20px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            animation: uonixFadeInUp 0.5s ease forwards;
            box-sizing: border-box;
        }

        .uonix-success-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #22c55e;
            color: #ffffff;
            font-size: 24px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .uonix-success-wrapper h3 {
            color: #166534;
            margin: 0 0 8px 0;
            font-size: 22px;
            font-weight: 800;
        }

        .uonix-success-wrapper p {
            color: #15803d;
            margin: 0;
            font-size: 15px;
        }

        .uonix-input-error { border-color: #dc2626 !important; background: #fffbfa !important; }
        .uonix-trab-box.uonix-error-box { border-color: #dc2626 !important; background: #fef2f2; }
        #uonix-custom-trab-form .uonix-turnstile-widget { max-width: 100%; margin: 0; overflow: hidden; }
        #uonix-custom-trab-form .uonix-turnstile-widget iframe { max-width: 100% !important; }
    </style>

    <form id="uonix-custom-trab-form" class="uonix-trab-wrapper" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="uonix_trab_nonce" value="<?php echo esc_attr(wp_create_nonce('uonix_trab_nonce_action')); ?>">

        <div class="uonix-trab-states">
            <div class="unf_form_fields" id="trab_form_fields">
                <div class="uonix-trab-group">
                    <input type="text" name="nome" id="trab_nome" placeholder=" ">
                    <label for="trab_nome" class="uonix-trab-label">Nome Completo *</label>
                </div>

                <div class="uonix-trab-group">
                    <input type="email" name="email" id="trab_email" placeholder=" ">
                    <label for="trab_email" class="uonix-trab-label">E-mail *</label>
                </div>

                <div class="uonix-trab-group">
                    <input type="tel" name="telefone" id="trab_tel" placeholder=" " inputmode="numeric" autocomplete="tel" maxlength="15">
                    <label for="trab_tel" class="uonix-trab-label">Telefone com DDD *</label>
                </div>

                <div class="uonix-trab-group">
                    <textarea name="mensagem" id="trab_msg" placeholder=" "></textarea>
                    <label for="trab_msg" class="uonix-trab-label">Nos conte sobre você</label>
                </div>

                <div class="uonix-file-upload-box" id="trab_upload_box">
                    <svg class="uonix-file-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                    </svg>
                    <span class="uonix-file-text" id="trab_file_text">Anexar Currículo *</span>
                    <span class="uonix-file-hint" id="trab_file_hint">Formatos: PDF, DOC, DOCX (Máx 3MB)</span>
                    <input type="file" name="curriculo" id="trab_curriculo" accept=".pdf,.doc,.docx">
                </div>

                <label class="uonix-trab-checkbox-wrapper" style="margin-top: 8px;">
                    <input type="checkbox" name="newsletters" id="trab_news" value="sim">
                    <span class="uonix-trab-box"></span>
                    <span class="uonix-trab-check-text">Desejo receber novidades e conteúdos da Uônix.</span>
                </label>

                <label class="uonix-trab-checkbox-wrapper">
                    <input type="checkbox" name="termo" id="trab_termo" value="sim">
                    <span class="uonix-trab-box" id="box_trab_termo"></span>
                    <span class="uonix-trab-check-text">Declaro que li e concordo com a <a href="/politica-de-privacidade/" target="_blank" style="color:#0e3780; text-decoration:underline;">Política de Privacidade</a> *</span>
                </label>

                <?php
                if ( function_exists( 'uonix_turnstile_render_widget' ) ) {
                    echo uonix_turnstile_render_widget(
                        'trabalhe_conosco',
                        array(
                            'theme'      => 'light',
                            'appearance' => 'interaction-only',
                        )
                    );
                }
                ?>

                <div id="trab_feedback_error" class="uonix-feedback-error"></div>

                <button type="submit" id="trab_submit_btn" class="uonix-btn-submit-trab">
                    <span>Enviar Candidatura</span>
                </button>

                <span class="uonix-trab-aviso-text">Os campos marcados com * são obrigatórios.</span>
            </div>

            <div id="trab_success_state" class="uonix-success-wrapper">
                <div class="uonix-success-icon">✔</div>
                <h3>Currículo Enviado!</h3>
                <p>O seu currículo foi recebido e será analisado pela nossa equipe.</p>
                <p>Entraremos em contato caso seu perfil seja compatível com futuras oportunidades.</p>
            </div>
        </div>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('uonix-custom-trab-form');
        if (!form) return;

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const fileInput = document.getElementById('trab_curriculo');
        const uploadBox = document.getElementById('trab_upload_box');
        const fileText = document.getElementById('trab_file_text');
        const fileHint = document.getElementById('trab_file_hint');
        const feedbackError = document.getElementById('trab_feedback_error');
        const telefoneInput = document.getElementById('trab_tel');

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

        if (telefoneInput) {
            telefoneInput.addEventListener('input', function() {
                this.value = formatUonixPhone(this.value);
            });
        }

        function resetUonixTurnstile() {
            if (window.uonixTurnstile) {
                window.uonixTurnstile.reset(form);
            }
        }

        fileInput.addEventListener('change', function(e) {
            feedbackError.style.display = 'none';
            uploadBox.classList.remove('has-error');

            if (e.target.files.length > 0) {
                const file = e.target.files[0];
                const allowedExtensions = ['pdf', 'doc', 'docx'];
                const extension = file.name.split('.').pop().toLowerCase();

                if (!allowedExtensions.includes(extension)) {
                    uploadBox.classList.remove('has-file');
                    uploadBox.classList.add('has-error');
                    fileText.innerText = 'Formato inválido!';
                    fileHint.innerText = 'Envie apenas PDF, DOC ou DOCX.';
                    fileInput.value = '';
                    return;
                }

                if (file.size > 3145728) {
                    uploadBox.classList.remove('has-file');
                    uploadBox.classList.add('has-error');
                    fileText.innerText = 'Arquivo muito grande!';
                    fileHint.innerText = 'Por favor, envie um arquivo com menos de 3MB.';
                    fileInput.value = '';
                    return;
                }

                uploadBox.classList.add('has-file');
                uploadBox.classList.remove('has-error');
                fileText.innerText = file.name;
                fileHint.innerText = 'Arquivo anexado com sucesso.';
            } else {
                uploadBox.classList.remove('has-file', 'has-error');
                fileText.innerText = 'Anexar Currículo *';
                fileHint.innerText = 'Formatos: PDF, DOC, DOCX (Máx 3MB)';
            }
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            let hasError = false;
            feedbackError.style.display = 'none';

            const nome = document.getElementById('trab_nome');
            const email = document.getElementById('trab_email');
            const tel = telefoneInput;
            const termo = document.getElementById('trab_termo');
            const boxTermo = document.getElementById('box_trab_termo');

            [nome, email, tel].forEach(el => el.classList.remove('uonix-input-error'));
            boxTermo.classList.remove('uonix-error-box');
            uploadBox.classList.remove('has-error');

            if (!nome.value.trim()) {
                nome.classList.add('uonix-input-error');
                hasError = true;
            }

            if (!tel.value.trim()) {
                tel.classList.add('uonix-input-error');
                hasError = true;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!email.value || !emailRegex.test(email.value)) {
                email.classList.add('uonix-input-error');
                hasError = true;
            }

            if (!termo.checked) {
                boxTermo.classList.add('uonix-error-box');
                hasError = true;
            }

            if (fileInput.files.length === 0) {
                uploadBox.classList.add('has-error');
                hasError = true;
            }

            if (hasError) {
                feedbackError.innerHTML = '* Por favor, preencha os campos obrigatórios e anexe o currículo.';
                feedbackError.style.display = 'block';
                return;
            }

            const btn = document.getElementById('trab_submit_btn');

            // Bloqueio preventivo do Turnstile. Vem depois da validação de campos para
            // não competir com ela: a pessoa corrige os campos primeiro e só então é
            // cobrada pelo desafio. Se o campo não existir no DOM, NÃO bloqueia — a
            // validação do servidor continua sendo a barreira real.
            const tsCampo = form.querySelector('[name="cf-turnstile-response"]');
            if (tsCampo && !(tsCampo.value || '').trim()) {
                feedbackError.textContent = 'Confirme a verificação de segurança para continuar.';
                feedbackError.style.display = 'block';
                const tsWrap = form.querySelector('.cf-turnstile, .uonix-turnstile-widget');
                if (tsWrap && typeof tsWrap.scrollIntoView === 'function') {
                    tsWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span>Enviando...</span>';

            const formData = new FormData(form);
            formData.append('action', 'uonix_processar_trabalhe');

            // Watchdog: conexão que abre e nunca responde não dispara `.catch()`. Aqui
            // o risco é maior que nos outros formulários porque há upload de currículo.
            //
            // 90s, não 30s. O limite aceito é 3 MB (validado no cliente na linha ~637 e
            // no servidor na ~886). Em 3G lento (~0,4 Mbps de upload) 3 MB levam ~60s —
            // um watchdog de 30s abortaria o envio de um candidato legítimo usando o
            // celular em área de sinal ruim. 90s cobre esse pior caso com folga e ainda
            // devolve o controle do botão em tempo humano.
            //
            // Abortar não deixa arquivo parcial no servidor: o PHP recebe
            // UPLOAD_ERR_PARTIAL e o handler exige UPLOAD_ERR_OK, então rejeita.
            const tsAbort = new AbortController();
            const tsTimer = setTimeout(function () { tsAbort.abort(); }, 90000);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                signal: tsAbort.signal
            })
            .then(response => response.json())
            .then(data => {
                clearTimeout(tsTimer);
                if (data.success) {
                    document.getElementById('trab_form_fields').style.display = 'none';
                    document.getElementById('trab_success_state').style.display = 'flex';
                } else {
                    feedbackError.style.display = 'block';
                    feedbackError.innerHTML = '⚠ ' + (data.data && data.data.message ? data.data.message : 'Erro ao enviar. Tente novamente.');
                    resetUonixTurnstile();
                    btn.disabled = false;
                    btn.innerHTML = '<span>Enviar Candidatura</span>';
                }
            })
	            .catch(error => {
	                clearTimeout(tsTimer);
	                feedbackError.style.display = 'block';
	                feedbackError.innerHTML = error && error.name === 'AbortError'
	                    ? '⚠ O envio demorou demais. Verifique sua conexão e tente novamente.'
	                    : '⚠ Falha na conexão. Tente novamente.';
	                resetUonixTurnstile();
	                btn.disabled = false;
	                btn.innerHTML = '<span>Enviar Candidatura</span>';
	            });
	        });

        ['trab_nome', 'trab_email', 'trab_tel'].forEach(id => {
            document.getElementById(id).addEventListener('input', function() {
                this.classList.remove('uonix-input-error');
            });
        });

        document.getElementById('trab_termo').addEventListener('change', function() {
            document.getElementById('box_trab_termo').classList.remove('uonix-error-box');
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// ==============================================================================
// 3. BACKEND: UPLOAD, MAPEAMENTO E PROTEÇÃO LGPD
// ==============================================================================

add_action('wp_ajax_uonix_processar_trabalhe', 'uonix_processar_trabalhe_handler');
add_action('wp_ajax_nopriv_uonix_processar_trabalhe', 'uonix_processar_trabalhe_handler');

if ( ! function_exists( 'uonix_trabalhe_upper_text' ) ) {
    function uonix_trabalhe_upper_text( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_trabalhe_upper_textarea' ) ) {
    function uonix_trabalhe_upper_textarea( $value ) {
        $value = trim( (string) $value );

        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }
}

if ( ! function_exists( 'uonix_trabalhe_format_phone' ) ) {
    function uonix_trabalhe_format_phone( $value ) {
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

if ( ! function_exists( 'uonix_trabalhe_phone_digits' ) ) {
    function uonix_trabalhe_phone_digits( $value ) {
        return preg_replace( '/\D+/', '', (string) $value );
    }
}

function uonix_processar_trabalhe_handler() {
    if (!function_exists('wpFluentForm')) {
        wp_send_json_error(array('message' => 'Sistema indisponível.'));
    }

    if (
        empty($_POST['uonix_trab_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['uonix_trab_nonce'])), 'uonix_trab_nonce_action')
    ) {
        // Diferente do Turnstile, aqui recarregar É necessário: o nonce expirou e só um
        // novo carregamento gera outro válido. A mensagem explica o motivo em vez de dar
        // só a ordem, e avisa que o preenchimento será perdido.
        wp_send_json_error(array('message' => 'Sua sessão expirou por inatividade. Recarregue a página para enviar novamente (o preenchimento será perdido).'));
    }

    if (!uonix_garantir_pasta_curriculos_protegida()) {
        wp_send_json_error(array('message' => 'Não foi possível preparar a pasta segura de currículos.'));
    }

    $nome              = uonix_trabalhe_upper_text(sanitize_text_field(wp_unslash($_POST['nome'] ?? '')));
    $email             = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    $telefone_raw      = sanitize_text_field(wp_unslash($_POST['telefone'] ?? ''));
    $telefone_digits   = uonix_trabalhe_phone_digits($telefone_raw);
    $telefone          = uonix_trabalhe_format_phone($telefone_raw);
    $mensagem          = uonix_trabalhe_upper_textarea(sanitize_textarea_field(wp_unslash($_POST['mensagem'] ?? '')));
    $news              = (isset($_POST['newsletters']) && $_POST['newsletters'] === 'sim') ? 'SIM' : '';
    $termo             = isset($_POST['termo']) && $_POST['termo'] === 'sim';

    if (empty($mensagem)) {
        $mensagem = 'NÃO PREENCHIDO';
    }

    if (empty($nome) || empty($email) || empty($telefone_digits) || !$termo || empty($_FILES['curriculo']['name'])) {
        wp_send_json_error(array('message' => 'Campos obrigatórios ausentes.'));
    }

    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'E-mail inválido.'));
    }

    if ( function_exists( 'uonix_turnstile_send_json_error_if_invalid' ) ) {
        uonix_turnstile_send_json_error_if_invalid( 'trabalhe_conosco' );
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $uploadedfile = $_FILES['curriculo'];

    if (!isset($uploadedfile['error']) || $uploadedfile['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(array('message' => 'Erro ao receber o arquivo. Tente novamente.'));
    }

    if ($uploadedfile['size'] > 3145728) {
        wp_send_json_error(array('message' => 'O arquivo excede o limite de 3MB.'));
    }

    $original_name = isset($uploadedfile['name']) ? $uploadedfile['name'] : '';
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_exts = array('pdf', 'doc', 'docx');

    if (!in_array($file_ext, $allowed_exts, true)) {
        wp_send_json_error(array('message' => 'Formato inválido. Envie apenas PDF, DOC ou DOCX.'));
    }

    $rename_filter = function($file) use ($nome) {
        $file['name'] = uonix_gerar_nome_arquivo_curriculo($nome, $file['name']);
        return $file;
    };

    $curriculos_dir_filter = function($dirs) {
        $subdir = uonix_get_curriculos_subdir();

        $dirs['subdir'] = $subdir;
        $dirs['path']   = $dirs['basedir'] . $subdir;
        $dirs['url']    = $dirs['baseurl'] . $subdir;

        return $dirs;
    };

    add_filter('wp_handle_upload_prefilter', $rename_filter);
    add_filter('upload_dir', $curriculos_dir_filter);

    $upload_overrides = array(
        'test_form' => false,
        'mimes' => array(
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ),
    );

    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    remove_filter('upload_dir', $curriculos_dir_filter);
    remove_filter('wp_handle_upload_prefilter', $rename_filter);

    if (!$movefile || isset($movefile['error'])) {
        wp_send_json_error(array('message' => 'Erro no upload. Apenas PDF, DOC ou DOCX.'));
    }

    $uploaded_path = isset($movefile['file']) ? $movefile['file'] : '';
    $uploaded_name = basename($uploaded_path);

    if (empty($uploaded_path) || empty($uploaded_name) || !file_exists($uploaded_path)) {
        wp_send_json_error(array('message' => 'Arquivo enviado, mas não foi possível localizá-lo no servidor.'));
    }

    $link_info = uonix_gerar_link_protegido_curriculo($uploaded_name);
    $link_curriculo = $link_info['url'];
    $download_token = $link_info['token'];

    $_POST['link_curriculo_temp'] = $link_curriculo;
    $_POST['telefone_formatado_temp'] = $telefone;

    $embedded_post_id = (int) get_option('page_on_front');

    if ($embedded_post_id <= 0) {
        $embedded_post_id = (int) url_to_postid(home_url('/'));
    }

    $referer_path = wp_get_referer() ?: '/';

    try {
        $submissionHandler = wpFluentForm()->make('\FluentForm\App\Services\Form\SubmissionHandlerService');

        $dados_form = array(
            '__fluent_form_embded_post_id' => $embedded_post_id,
            '_wp_http_referer'             => $referer_path,
            'form_nome'                    => $nome,
            'form_email'                   => $email,
            // O Form 3 usa campo numérico; a formatação visual é aplicada no filtro de gravação.
            'form_telefone'                => $telefone_digits,
            'form_empresa'                 => 'N/A - CANDIDATO',
            'form_assunto'                 => 'rh',
            'form_mensagem'                => $mensagem,
            'form_privacy'                 => 'on',
            'link_curriculo'               => $link_curriculo,
        );

        if ($news === 'SIM') {
            $dados_form['form_newsletters'] = array('sim');
        }

        $submissionHandler->handleSubmission($dados_form, 3);

        wp_send_json_success(array('message' => 'Candidatura enviada.'));
    } catch (\Exception $e) {
        error_log('Uônix Trabalhe Error: ' . $e->getMessage());

        if (!empty($uploaded_path) && file_exists($uploaded_path)) {
            @unlink($uploaded_path);
        }

        if (!empty($download_token)) {
            delete_transient('uonix_cv_' . $download_token);
        }

        wp_send_json_error(array('message' => 'Erro ao processar candidatura.'));
    }
}

// ==============================================================================
// 4. CRON JOB: AUTO-LIMPEZA DE CURRÍCULOS (LGPD) - 30 DIAS
// ==============================================================================

if (!wp_next_scheduled('uonix_limpar_curriculos_evento')) {
    wp_schedule_event(time(), 'daily', 'uonix_limpar_curriculos_evento');
}

add_action('uonix_limpar_curriculos_evento', 'uonix_apagar_curriculos_antigos');

function uonix_apagar_curriculos_antigos() {
    $dir_path = uonix_get_curriculos_dir_path();

    if (!is_dir($dir_path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir_path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filename = strtolower($file->getFilename());
            $filepath = $file->getPathname();

            if (strpos($filename, 'cv_uonix_') === 0) {
                if (filemtime($filepath) < (time() - 30 * DAY_IN_SECONDS)) {
                    @unlink($filepath);
                }
            }
        }
    }
}
