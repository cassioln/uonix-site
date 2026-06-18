<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Admin - limpeza de dados de teste Fluent Forms.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 17964-18229 do export original.
// -----------------------------------------------------------------------------
/**
 *  UÔNIX: Limpador de dados de teste do Fluent Forms
 */
/**
 * UÔNIX: Limpador de dados de teste do Fluent Forms
 * - Apaga submissões dos Forms 2, 3 e 4
 * - Remove também metadados, detalhes e logs relacionados quando existirem
 * - Acesso: Ferramentas > Limpar Testes Fluent Forms
 */

add_action('admin_menu', function () {
    add_management_page(
        'Limpar Testes Fluent Forms',
        'Limpar Testes Fluent Forms',
        'manage_options',
        'uox-limpar-testes-fluentforms',
        'uox_ff_render_limpar_testes_page'
    );
});

function uox_ff_table_exists($table_name) {
    global $wpdb;

    return $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
    ) === $table_name;
}

function uox_ff_column_exists($table_name, $column_name) {
    global $wpdb;

    $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', $table_name);

    if ($safe_table !== $table_name) {
        return false;
    }

    return (bool) $wpdb->get_var(
        $wpdb->prepare("SHOW COLUMNS FROM `{$safe_table}` LIKE %s", $column_name)
    );
}

function uox_ff_delete_ids_in_table($table_name, $column_name, $ids) {
    global $wpdb;

    if (empty($ids)) {
        return 0;
    }

    $safe_table  = preg_replace('/[^A-Za-z0-9_]/', '', $table_name);
    $safe_column = preg_replace('/[^A-Za-z0-9_]/', '', $column_name);

    if ($safe_table !== $table_name || $safe_column !== $column_name) {
        return 0;
    }

    if (!uox_ff_table_exists($table_name) || !uox_ff_column_exists($table_name, $column_name)) {
        return 0;
    }

    $deleted = 0;
    $chunks = array_chunk(array_map('absint', $ids), 500);

    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$safe_table}` WHERE `{$safe_column}` IN ({$placeholders})",
                $chunk
            )
        );

        if ($result !== false) {
            $deleted += (int) $result;
        }
    }

    return $deleted;
}

function uox_ff_limpar_testes_forms_2_3_4() {
    global $wpdb;

    $forms_para_limpar = array(2, 3, 4);

    $tabela_submissions = $wpdb->prefix . 'fluentform_submissions';
    $tabela_meta        = $wpdb->prefix . 'fluentform_submission_meta';
    $tabela_details     = $wpdb->prefix . 'fluentform_entry_details';
    $tabela_logs        = $wpdb->prefix . 'fluentform_logs';

    if (!uox_ff_table_exists($tabela_submissions)) {
        return array(
            'success' => false,
            'message' => 'Tabela fluentform_submissions não encontrada.',
        );
    }

    $placeholders = implode(',', array_fill(0, count($forms_para_limpar), '%d'));

    $submission_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM `{$tabela_submissions}` WHERE form_id IN ({$placeholders})",
            $forms_para_limpar
        )
    );

    $submission_ids = array_map('absint', $submission_ids);

    if (empty($submission_ids)) {
        return array(
            'success' => true,
            'message' => 'Nenhuma entrada encontrada nos Forms 2, 3 e 4. Nada foi apagado.',
            'deleted' => array(
                'submissions' => 0,
                'meta'        => 0,
                'details'     => 0,
                'logs'        => 0,
            ),
        );
    }

    /*
     * Apaga tabelas relacionadas primeiro.
     * Depois apaga a tabela principal de submissões.
     */
    $deleted_meta = uox_ff_delete_ids_in_table(
        $tabela_meta,
        'response_id',
        $submission_ids
    );

    $deleted_details = uox_ff_delete_ids_in_table(
        $tabela_details,
        'submission_id',
        $submission_ids
    );

    $deleted_logs = uox_ff_delete_ids_in_table(
        $tabela_logs,
        'source_id',
        $submission_ids
    );

    $deleted_submissions = uox_ff_delete_ids_in_table(
        $tabela_submissions,
        'id',
        $submission_ids
    );

    return array(
        'success' => true,
        'message' => 'Limpeza concluída com sucesso.',
        'deleted' => array(
            'submissions' => $deleted_submissions,
            'meta'        => $deleted_meta,
            'details'     => $deleted_details,
            'logs'        => $deleted_logs,
        ),
    );
}

function uox_ff_render_limpar_testes_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Você não tem permissão para acessar esta página.');
    }

    $resultado = null;

    if (
        isset($_POST['uox_ff_limpar_action']) &&
        $_POST['uox_ff_limpar_action'] === 'limpar'
    ) {
        check_admin_referer('uox_ff_limpar_testes_nonce');

        $confirmacao = isset($_POST['uox_confirmacao'])
            ? sanitize_text_field(wp_unslash($_POST['uox_confirmacao']))
            : '';

        if ($confirmacao !== 'LIMPAR') {
            $resultado = array(
                'success' => false,
                'message' => 'Confirmação inválida. Digite LIMPAR exatamente como indicado.',
            );
        } else {
            $resultado = uox_ff_limpar_testes_forms_2_3_4();
        }
    }

    ?>
    <div class="wrap">
        <h1>Limpar Testes Fluent Forms</h1>

        <p>
            Esta ferramenta apaga definitivamente as entradas de teste dos seguintes formulários:
        </p>

        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>Form 2</strong> — Newsletters</li>
            <li><strong>Form 3</strong> — Contato / Trabalhe Conosco</li>
            <li><strong>Form 4</strong> — Captura de Leads</li>
        </ul>

        <p>
            Ela também tenta limpar metadados, detalhes e logs relacionados às submissões apagadas.
        </p>

        <div class="notice notice-warning" style="padding:12px;">
            <p>
                <strong>Atenção:</strong> esta ação é irreversível. Faça backup do banco antes de executar.
            </p>
            <p>
                Depois de usar, desative ou remova este snippet.
            </p>
        </div>

        <?php if (is_array($resultado)) : ?>
            <div class="<?php echo !empty($resultado['success']) ? 'notice notice-success' : 'notice notice-error'; ?>" style="padding:12px;">
                <p><strong><?php echo esc_html($resultado['message']); ?></strong></p>

                <?php if (!empty($resultado['deleted'])) : ?>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li>Submissões apagadas: <?php echo esc_html($resultado['deleted']['submissions']); ?></li>
                        <li>Metadados apagados: <?php echo esc_html($resultado['deleted']['meta']); ?></li>
                        <li>Detalhes apagados: <?php echo esc_html($resultado['deleted']['details']); ?></li>
                        <li>Logs apagados: <?php echo esc_html($resultado['deleted']['logs']); ?></li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" style="margin-top: 24px; max-width: 560px; background:#fff; padding:24px; border:1px solid #dcdcde; border-radius:8px;">
            <?php wp_nonce_field('uox_ff_limpar_testes_nonce'); ?>

            <input type="hidden" name="uox_ff_limpar_action" value="limpar">

            <p>
                Para confirmar a limpeza dos Forms <strong>2, 3 e 4</strong>, digite:
                <strong>LIMPAR</strong>
            </p>

            <p>
                <input
                    type="text"
                    name="uox_confirmacao"
                    value=""
                    placeholder="Digite LIMPAR"
                    style="width:100%; max-width:300px;"
                    required
                >
            </p>

            <p>
                <button
                    type="submit"
                    class="button button-primary"
                    onclick="return confirm('Tem certeza que deseja apagar definitivamente as entradas dos Forms 2, 3 e 4?');"
                >
                    Limpar dados de teste agora
                </button>
            </p>
        </form>
    </div>
    <?php
}


