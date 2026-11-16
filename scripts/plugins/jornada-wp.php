<?php
/** Controle WP-CLI exclusivamente local; entrada sintética em base64. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
try {
    $modo = $args[0] ?? '';
    $cfg = json_decode(base64_decode($args[1] ?? '', true), true, 32, JSON_THROW_ON_ERROR);
    $guarda = base64_decode($cfg['guarda'] ?? '', true);
    if (!$guarda || !str_starts_with($guarda, '<?php')) { throw new RuntimeException('guarda ausente'); }
    if (!function_exists('uonix_jornada_validar')) { eval('?>' . $guarda); }
    uonix_jornada_validar($cfg, home_url(), defined('UONIX_ENV') ? UONIX_ENV : '');
    if (!function_exists('WC') || !function_exists('wpFluentForm')) { throw new RuntimeException('WooCommerce/Fluent Forms ausente'); }
    $_SERVER['HTTP_X_UONIX_JORNADA'] = $cfg['run_id'];
    global $wpdb;
    $relativo = $cfg['run_id'] . '/guarda.php';
    $diretorio = WP_PLUGIN_DIR . '/' . $cfg['run_id'];
    $arquivo = WP_PLUGIN_DIR . '/' . $relativo;
    $consulta_pedidos = function () use ($cfg) {
        return wc_get_orders(array('billing_email' => $cfg['email'], 'limit' => 10, 'type' => 'shop_order'));
    };
    $consulta_leads = function () use ($wpdb, $cfg) {
        // Não ler entradas do clone: o identificador aleatório é exclusivo do teste.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, form_id, response, source_url FROM {$wpdb->prefix}fluentform_submissions WHERE response LIKE %s",
            '%' . $wpdb->esc_like($cfg['email']) . '%'
        ), ARRAY_A);
    };
    if ('preparar' === $modo) {
        if (get_option('uonix_jornada_execucao') || is_dir($diretorio) || is_link($diretorio)) {
            throw new RuntimeException('outra jornada/trava local existe; conferir antes de repetir');
        }
        if (get_user_by('email', $cfg['email']) || $consulta_pedidos() || $consulta_leads()) {
            throw new RuntimeException('identificador não está vazio');
        }
        if ('yes' !== get_option('woocommerce_enable_guest_checkout')) { throw new RuntimeException('checkout de visitante indisponível'); }
        if (!$wpdb->get_var("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=4")) { throw new RuntimeException('Form 4 ausente'); }
        $produto = wc_get_product((int) ($cfg['produto_id'] ?? 0));
        if (!$produto || !$produto->is_type('simple') || !$produto->is_purchasable() || !$produto->is_in_stock()) {
            throw new RuntimeException('produto simples obrigatório indisponível');
        }
        // Inspecionar o transporte final sem enviar uma mensagem de teste.
        foreach (array('Exception', 'PHPMailer', 'SMTP') as $classe) {
            if (!class_exists('PHPMailer\\PHPMailer\\' . $classe)) {
                require_once ABSPATH . WPINC . '/PHPMailer/' . $classe . '.php';
            }
        }
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        do_action_ref_array('phpmailer_init', array(&$mailer));
        if (!uonix_jornada_smtp_local($mailer)) { throw new RuntimeException('política local não aponta para Mailpit'); }
        $tipos = apply_filters('fluentform/global_notification_active_types', array(), 4);
        if ('email_notifications' !== ($tipos['notifications'] ?? '')) { throw new RuntimeException('contrato de notificações Fluent Forms mudou'); }
        $habilitadas = array();
        foreach (WC()->mailer()->get_emails() as $email) {
            if (in_array($email->id, array('new_rfq', 'customer_rfq'), true) && $email->is_enabled()) { $habilitadas[] = $email->id; }
        }
        if (!$habilitadas) { throw new RuntimeException('nenhuma notificação RFQ habilitada'); }
        $cfg['sessoes'] = array();
        $cfg['inicio'] = current_time('mysql');
        if (!add_option('uonix_jornada_execucao', $cfg, '', false)) { throw new RuntimeException('não adquiriu trava local'); }
        if (!mkdir($diretorio, 0700)) { throw new RuntimeException('não criou pasta da guarda local'); }
        $cfg['guarda_criada'] = true;
        update_option('uonix_jornada_execucao', $cfg, false);
        if (false === file_put_contents($arquivo, $guarda, LOCK_EX)) { throw new RuntimeException('não instalou guarda local'); }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $ativacao = activate_plugin($relativo, '', false, true);
        if (is_wp_error($ativacao) || !is_plugin_active($relativo)) { throw new RuntimeException('guarda não ativada'); }
        $resposta = array('produto_url' => get_permalink($produto->get_id()), 'checkout_url' => wc_get_checkout_url(),
            'habilitadas' => $habilitadas, 'smtp_local' => true, 'trava' => true);
    } else {
        $dona = get_option('uonix_jornada_execucao');
        if (!$dona && 'limpar' === $modo && !is_dir($diretorio)) {
            $resposta = array('limpeza' => true, 'pedidos' => 0, 'leads' => 0, 'sessoes' => 0);
        } else {
            if (!$dona || !hash_equals($dona['run_id'], $cfg['run_id']) || $dona['email'] !== $cfg['email']) {
                throw new RuntimeException('trava pertence a outra execução');
            }
            $pedidos = $consulta_pedidos();
            $leads = $consulta_leads();
            foreach ($pedidos as $pedido) {
                if (!uonix_jornada_pertence($pedido->get_billing_email(), $pedido->get_billing_company(), $cfg)) {
                    throw new RuntimeException('pedido com propriedade divergente; não apagar');
                }
            }
            if ('verificar' === $modo) {
                $pedido = 1 === count($pedidos) ? $pedidos[0] : null;
                $dados_pedido = null;
                $notificacoes = array();
                if ($pedido) {
                    $itens = array();
                    foreach ($pedido->get_items() as $item) {
                        $itens[] = array('produto_id' => $item->get_product_id(), 'quantidade' => (int) $item->get_quantity());
                    }
                    $dados_pedido = array('id' => $pedido->get_id(), 'numero' => (string) $pedido->get_order_number(),
                        'status' => $pedido->get_status(), 'pagamento' => $pedido->get_payment_method(),
                        'email' => $pedido->get_billing_email(), 'itens' => $itens, 'visitante' => 0 === $pedido->get_customer_id());
                    foreach (WC()->mailer()->get_emails() as $email) {
                        if (!in_array($email->id, array('new_rfq', 'customer_rfq'), true) || !$email->is_enabled()) { continue; }
                        $email->object = $pedido;
                        if ('customer_rfq' === $email->id) { $email->recipient = $cfg['email']; }
                        $destino = $email->get_recipient();
                        if ('new_rfq' === $email->id) { $destino = apply_filters('wc_email_new_rfq_recipients', $destino, $pedido); }
                        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $destino, $enderecos);
                        $notificacoes[] = array('id' => $email->id, 'destinatarios' => array_values(array_unique(array_map(
                            fn($valor) => hash('sha256', strtolower(trim($valor))), $enderecos[0]
                        ))));
                    }
                }
                $dados_leads = array();
                foreach ($leads as $lead) {
                    $json = json_decode($lead['response'], true, 32, JSON_THROW_ON_ERROR);
                    $dados_leads[] = array('id' => (int) $lead['id'], 'form_id' => (int) $lead['form_id'],
                        'email' => $json['capturalead_email'] ?? '', 'nome' => $json['capturalead_nome'] ?? '',
                        'empresa' => $json['capturalead_empresa'] ?? '', 'telefone' => $json['capturalead_telefone'] ?? '',
                        'newsletter' => $json['capturalead_newsletters'] ?? '', 'origem' => $json['capturalead_origem'] ?? '',
                        'source_url' => $lead['source_url']);
                }
                $resposta = array('pedido' => $dados_pedido, 'leads' => $dados_leads, 'notificacoes' => $notificacoes);
            } elseif ('limpar' === $modo) {
                foreach ($leads as $lead) {
                    if (!uonix_jornada_lead_pertence($lead, $cfg)) {
                        throw new RuntimeException('lead com propriedade divergente; não apagar');
                    }
                }
                foreach ($pedidos as $pedido) {
                    if (function_exists('wc_release_stock_for_order')) { wc_release_stock_for_order($pedido); }
                    $pedido->delete(true);
                }
                $grupos = array();
                foreach ($leads as $lead) { $grupos[(int) $lead['form_id']][] = (int) $lead['id']; }
                foreach ($grupos as $form_id => $ids) { \FluentForm\App\Models\Submission::remove($ids, $form_id); }
                $usuario = get_user_by('email', $cfg['email']);
                if ($usuario) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    if (!wp_delete_user($usuario->ID)) { throw new RuntimeException('conta sintética não removida'); }
                }
                $sessao = new WC_Session_Handler();
                foreach ($dona['sessoes'] ?? array() as $id) {
                    $sessao->delete_session($id);
                    if (null !== $sessao->get_session($id, null)) { throw new RuntimeException('sessão sintética não removida'); }
                }
                if ($consulta_pedidos() || $consulta_leads() || get_user_by('email', $cfg['email'])) {
                    throw new RuntimeException('limpeza de dados incompleta');
                }
                // Só remover pasta cuja criação foi registrada por esta execução.
                if (file_exists($diretorio) && empty($dona['guarda_criada'])) {
                    throw new RuntimeException('propriedade da pasta da guarda não confirmada');
                }
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                deactivate_plugins($relativo, true);
                if (is_plugin_active($relativo)) { throw new RuntimeException('guarda não desativada'); }
                uonix_jornada_remover_guarda($diretorio, $guarda);
                delete_option('uonix_jornada_execucao');
                if (get_option('uonix_jornada_execucao') || file_exists($diretorio)) { throw new RuntimeException('trava não removida'); }
                $resposta = array('limpeza' => true, 'pedidos' => 0, 'leads' => 0, 'sessoes' => 0);
            } else { throw new RuntimeException('modo de jornada inválido'); }
        }
    }
    echo '@@JORNADA' . wp_json_encode($resposta) . '@@JORNADA';
} catch (Throwable $erro) {
    // Não propagar dumps, argumentos ou configuração SMTP de exceções externas.
    $mensagem = $erro instanceof RuntimeException ? $erro->getMessage() : 'falha no controle WordPress (' . get_class($erro) . ')';
    echo '@@JORNADA' . wp_json_encode(array('erro' => $mensagem)) . '@@JORNADA';
    WP_CLI::halt(1);
}
