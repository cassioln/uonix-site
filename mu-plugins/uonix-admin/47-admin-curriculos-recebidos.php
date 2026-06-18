<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Admin - painel de curriculos recebidos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 18230-18761 do export original.
// -----------------------------------------------------------------------------
/**
 * Menu Painel - Currículos Recebidos
 */
/**
 * UÔNIX: Tela de Currículos Recebidos
 * - Adiciona submenu em Mídia > Currículos Recebidos
 * - Lista arquivos de /wp-content/uploads/curriculos-recebidos
 * - Mostra nome, e-mail, telefone, prazo de exclusão e ações
 * - Permite baixar pelo link protegido e excluir antecipadamente
 */

if (!defined('UONIX_CURRICULOS_RECEBIDOS_ADMIN_LOADED')) {
    define('UONIX_CURRICULOS_RECEBIDOS_ADMIN_LOADED', true);

    add_action('admin_menu', 'uonix_registrar_menu_curriculos_recebidos');

    function uonix_registrar_menu_curriculos_recebidos() {
        add_media_page(
            'Currículos Recebidos',
            'Currículos Recebidos',
            'upload_files',
            'uonix-curriculos-recebidos',
            'uonix_render_curriculos_recebidos_page'
        );
    }

    function uonix_admin_get_curriculos_subdir() {
        return '/curriculos-recebidos';
    }

    function uonix_admin_get_curriculos_dir_path() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . uonix_admin_get_curriculos_subdir();
    }

    function uonix_admin_get_curriculo_token_from_url($url) {
        if (empty($url)) {
            return '';
        }

        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (empty($query)) {
            return '';
        }

        parse_str($query, $params);

        return !empty($params['token']) ? sanitize_text_field($params['token']) : '';
    }

    function uonix_admin_get_link_download_por_token($token) {
        if (empty($token)) {
            return '';
        }

        return add_query_arg(
            array(
                'action' => 'uonix_baixar_curriculo',
                'token'  => $token,
            ),
            admin_url('admin-post.php')
        );
    }

    function uonix_admin_mapear_tokens_curriculos() {
        global $wpdb;

        $mapa = array();

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_uonix_cv_%'"
        );

        if (empty($rows)) {
            return $mapa;
        }

        foreach ($rows as $row) {
            $token = str_replace('_transient_uonix_cv_', '', $row->option_name);
            $dados = maybe_unserialize($row->option_value);

            if (empty($token) || empty($dados['file'])) {
                continue;
            }

            $filename = basename($dados['file']);

            $mapa[$filename] = array(
                'token'   => $token,
                'created' => !empty($dados['created']) ? absint($dados['created']) : 0,
            );
        }

        return $mapa;
    }

    function uonix_admin_buscar_dados_fluentforms_curriculos() {
        global $wpdb;

        $mapa = array();

        $tabela_ff = $wpdb->prefix . 'fluentform_submissions';

        $tabela_existe = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $tabela_ff)
        );

        if ($tabela_existe !== $tabela_ff) {
            return $mapa;
        }

        $respostas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, response, created_at
                 FROM {$tabela_ff}
                 WHERE form_id = %d
                 ORDER BY id DESC",
                3
            )
        );

        if (empty($respostas)) {
            return $mapa;
        }

        foreach ($respostas as $row) {
            $dados = json_decode($row->response, true);

            if (!is_array($dados)) {
                continue;
            }

            $link_curriculo = $dados['link_curriculo'] ?? '';
            $token = uonix_admin_get_curriculo_token_from_url($link_curriculo);

            if (empty($token)) {
                continue;
            }

            $transient = get_transient('uonix_cv_' . $token);

            if (empty($transient['file'])) {
                continue;
            }

            $filename = basename($transient['file']);

            $mapa[$filename] = array(
                'entry_id'       => absint($row->id),
                'nome'           => sanitize_text_field($dados['form_nome'] ?? ''),
                'email'          => sanitize_email($dados['form_email'] ?? ''),
                'telefone'       => sanitize_text_field($dados['form_telefone'] ?? ''),
                'link_curriculo' => esc_url_raw($link_curriculo),
                'token'          => $token,
                'created_at'     => $row->created_at,
            );
        }

        return $mapa;
    }

    function uonix_admin_extrair_nome_do_arquivo($filename) {
        $nome = preg_replace('/^cv_uonix_/', '', $filename);
        $nome = preg_replace('/_[a-f0-9]{12}_\.[a-z0-9]+$/i', '', $nome);
        $nome = preg_replace('/\.[a-z0-9]+$/i', '', $nome);
        $nome = str_replace('-', ' ', $nome);
        $nome = trim($nome);

        if (empty($nome)) {
            return 'Não identificado';
        }

        return ucwords($nome);
    }

    function uonix_admin_formatar_telefone($telefone) {
        $telefone = preg_replace('/\D+/', '', $telefone);

        if (strlen($telefone) === 11) {
            return sprintf(
                '(%s) %s-%s',
                substr($telefone, 0, 2),
                substr($telefone, 2, 5),
                substr($telefone, 7)
            );
        }

        if (strlen($telefone) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($telefone, 0, 2),
                substr($telefone, 2, 4),
                substr($telefone, 6)
            );
        }

        return $telefone;
    }

    add_action('admin_post_uonix_excluir_curriculo_recebido', 'uonix_admin_excluir_curriculo_recebido');

    function uonix_admin_excluir_curriculo_recebido() {
        if (!current_user_can('upload_files')) {
            wp_die('Você não tem permissão para excluir este arquivo.');
        }

        $filename = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';

        if (empty($filename)) {
            wp_die('Arquivo inválido.');
        }

        check_admin_referer('uonix_excluir_curriculo_' . $filename);

        $base_dir = realpath(uonix_admin_get_curriculos_dir_path());
        $file_path = realpath(trailingslashit(uonix_admin_get_curriculos_dir_path()) . $filename);

        if (!$base_dir || !$file_path || strpos($file_path, $base_dir . DIRECTORY_SEPARATOR) !== 0) {
            wp_die('Caminho inválido.');
        }

        if (file_exists($file_path) && is_file($file_path)) {
            @unlink($file_path);
        }

        $tokens = uonix_admin_mapear_tokens_curriculos();

        if (!empty($tokens[$filename]['token'])) {
            delete_transient('uonix_cv_' . $tokens[$filename]['token']);
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                 => 'uonix-curriculos-recebidos',
                    'uonix_cv_deleted'     => '1',
                ),
                admin_url('upload.php')
            )
        );
        exit;
    }

    function uonix_render_curriculos_recebidos_page() {
        if (!current_user_can('upload_files')) {
            wp_die('Você não tem permissão para acessar esta página.');
        }

        $dir_path = uonix_admin_get_curriculos_dir_path();
        $tokens_map = uonix_admin_mapear_tokens_curriculos();
        $dados_ff_map = uonix_admin_buscar_dados_fluentforms_curriculos();

        $arquivos = array();

        if (is_dir($dir_path)) {
            $iterator = new DirectoryIterator($dir_path);

            foreach ($iterator as $file) {
                if ($file->isDot() || !$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();

                if (strpos(strtolower($filename), 'cv_uonix_') !== 0) {
                    continue;
                }

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (!in_array($ext, array('pdf', 'doc', 'docx'), true)) {
                    continue;
                }

                $arquivos[] = array(
                    'filename' => $filename,
                    'path'     => $file->getPathname(),
                    'mtime'    => $file->getMTime(),
                    'size'     => $file->getSize(),
                );
            }
        }

        usort($arquivos, function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        ?>
        <div class="wrap">
            <h1>Currículos Recebidos</h1>

            <p>
                Esta tela lista os currículos enviados pelo formulário Trabalhe Conosco.
                Os arquivos são apagados automaticamente após <strong>30 dias</strong>.
            </p>

            <?php if (!empty($_GET['uonix_cv_deleted'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Currículo excluído com sucesso.</p>
                </div>
            <?php endif; ?>

            <?php if (!is_dir($dir_path)) : ?>
                <div class="notice notice-warning">
                    <p>A pasta de currículos ainda não existe:</p>
                    <code><?php echo esc_html($dir_path); ?></code>
                </div>
            <?php endif; ?>

            <style>
                .uonix-cv-table-wrapper {
                    margin-top: 18px;
                    background: #ffffff;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    overflow: hidden;
                }

                .uonix-cv-table .column-actions {
                    width: 190px;
                }

                .uonix-cv-table .column-expira {
                    width: 150px;
                }

                .uonix-cv-table .column-data {
                    width: 150px;
                }

                .uonix-cv-status {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 4px 9px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 700;
                    background: #dcfce7;
                    color: #166534;
                }

                .uonix-cv-status.warning {
                    background: #fef3c7;
                    color: #92400e;
                }

                .uonix-cv-status.danger {
                    background: #fee2e2;
                    color: #991b1b;
                }

                .uonix-cv-actions {
                    display: flex;
                    gap: 8px;
                    flex-wrap: wrap;
                }

                .uonix-cv-actions .button {
                    min-height: 30px;
                }
		
				.button-link-delete{
					border: 1px solid #d63638 !important;
					color: #d63638 !important;
				}

                .uonix-cv-empty {
                    padding: 28px;
                    text-align: center;
                    color: #64748b;
                }

                .uonix-cv-muted {
                    color: #64748b;
                }

                .uonix-cv-file {
                    font-size: 12px;
                    color: #64748b;
                    margin-top: 4px;
                    word-break: break-all;
                }
            </style>

            <div class="uonix-cv-table-wrapper">
                <?php if (empty($arquivos)) : ?>
                    <div class="uonix-cv-empty">
                        Nenhum currículo recebido no momento.
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped uonix-cv-table">
                        <thead>
                            <tr>
                                <th>Candidato</th>
                                <th>E-mail</th>
                                <th>Telefone</th>
                                <th class="column-data">Recebido em</th>
                                <th class="column-expira">Exclusão automática</th>
                                <th class="column-actions">Ações</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($arquivos as $arquivo) : ?>
                                <?php
                                $filename = $arquivo['filename'];
                                $dados_ff = $dados_ff_map[$filename] ?? array();
                                $dados_token = $tokens_map[$filename] ?? array();

                                $nome = !empty($dados_ff['nome'])
                                    ? $dados_ff['nome']
                                    : uonix_admin_extrair_nome_do_arquivo($filename);

                                $email = !empty($dados_ff['email'])
                                    ? $dados_ff['email']
                                    : 'Não encontrado';

                                $telefone = !empty($dados_ff['telefone'])
                                    ? uonix_admin_formatar_telefone($dados_ff['telefone'])
                                    : 'Não encontrado';

                                $data_envio_timestamp = !empty($dados_token['created'])
                                    ? absint($dados_token['created'])
                                    : absint($arquivo['mtime']);

                                $data_envio = date_i18n(
                                    'd/m/Y H:i',
                                    $data_envio_timestamp
                                );

                                $idade_segundos = time() - $data_envio_timestamp;
                                $dias_passados = floor($idade_segundos / DAY_IN_SECONDS);
                                $dias_restantes = max(0, 30 - $dias_passados);

                                $status_class = '';
                                $status_text = $dias_restantes . ' dias restantes';

                                if ($dias_restantes <= 0) {
                                    $status_class = 'danger';
                                    $status_text = 'vence hoje';
                                } elseif ($dias_restantes <= 5) {
                                    $status_class = 'warning';
                                }

                                $download_url = '';

                                if (!empty($dados_ff['link_curriculo'])) {
                                    $download_url = $dados_ff['link_curriculo'];
                                } elseif (!empty($dados_token['token'])) {
                                    $download_url = uonix_admin_get_link_download_por_token($dados_token['token']);
                                }

                                $delete_url = wp_nonce_url(
                                    add_query_arg(
                                        array(
                                            'action' => 'uonix_excluir_curriculo_recebido',
                                            'file'   => rawurlencode($filename),
                                        ),
                                        admin_url('admin-post.php')
                                    ),
                                    'uonix_excluir_curriculo_' . $filename
                                );
                                ?>

                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($nome); ?></strong>
                                        <div class="uonix-cv-file">
                                            <?php echo esc_html($filename); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if ($email !== 'Não encontrado') : ?>
                                            <a href="mailto:<?php echo esc_attr($email); ?>">
                                                <?php echo esc_html($email); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="uonix-cv-muted">Não encontrado</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php echo esc_html($telefone); ?>
                                    </td>

                                    <td>
                                        <?php echo esc_html($data_envio); ?>
                                    </td>

                                    <td>
                                        <span class="uonix-cv-status <?php echo esc_attr($status_class); ?>">
                                            <?php echo esc_html($status_text); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="uonix-cv-actions">
                                            <?php if (!empty($download_url)) : ?>
                                                <a class="button button-primary" href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener">
                                                    Baixar
                                                </a>
                                            <?php else : ?>
                                                <button class="button" disabled>
                                                    Link indisponível
                                                </button>
                                            <?php endif; ?>

                                            <a
                                                class="button button-link-delete"
                                                href="<?php echo esc_url($delete_url); ?>"
                                                onclick="return confirm('Tem certeza que deseja excluir este currículo agora? Esta ação antecipa a exclusão automática de 30 dias.');"
                                            >
                                                Excluir
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}


