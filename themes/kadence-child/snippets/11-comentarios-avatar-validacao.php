<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Comentarios - avatar por iniciais e validacao de e-mail.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 3255-3347 do export original.
// -----------------------------------------------------------------------------
/**
 *  Avatar com Iniciais 
 */
/**
 * UÔNIX: Avatar com Iniciais (Estilo Gmail)
 * Substitui o Gravatar padrão por iniciais usando UI Avatars
 */
add_filter('get_avatar_url', function ($url, $id_or_email, $args) {
    // 1. Tenta descobrir o nome do autor do comentário
    $name = '';
    
    if (is_object($id_or_email) && isset($id_or_email->comment_ID)) {
        // Se for um objeto de comentário
        $name = $id_or_email->comment_author;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        // Se for um email, tenta pegar o usuário (se existir)
        $user = get_user_by('email', $id_or_email);
        if ($user) {
            $name = $user->display_name;
        }
    } elseif (is_numeric($id_or_email)) {
        // Se for ID de usuário
        $user = get_user_by('id', $id_or_email);
        if ($user) {
            $name = $user->display_name;
        }
    }

    // 2. Se não achou nome nenhum, mantém o padrão
    if (empty($name)) {
        return $url;
    }

    // 3. Verifica se a URL atual é o padrão do Gravatar (Mystery Man)
    // Se você quiser substituir TUDO (mesmo quem tem foto), remova este 'if'.
    // Mas geralmente queremos manter a foto de quem tem Gravatar real.
    if (strpos($url, 'gravatar.com/avatar') !== false && strpos($url, 'd=mm') !== false) {
        
        // CONFIGURAÇÃO DE CORES (Laranja Uônix)
        $background = 'e65100'; // Cor de fundo (Hex sem #)
        $color      = 'ffffff'; // Cor da letra
        
        // Gera a nova URL
        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=' . $background . '&color=' . $color . '&size=128&font-size=0.5';
    }

    return $url;

}, 10, 3);

/**
 * Estrutura Email com Validação de comentarios
 */
add_action('wp_footer', function() {
    if (!is_singular() || !comments_open()) return;
    ?>
    <style type="text/css">
        #email.uonix-email-error {
            border: 2px solid #dc3545 !important;
            background-color: #fff8f8 !important;
        }
    </style>

    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var commentForm = document.getElementById('commentform');
            var emailField = document.getElementById('email');

            if (!commentForm || !emailField) return;

            commentForm.addEventListener('submit', function(e) {
                var emailValue = emailField.value.trim();
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                if (!emailRegex.test(emailValue)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    emailField.classList.add('uonix-email-error');
                    emailField.focus();

                    return false;
                }
            });

            emailField.addEventListener('input', function() {
                this.classList.remove('uonix-email-error');
            });
        });
    </script>
    <?php
}, 999);


