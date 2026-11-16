<?php
/**
 * Plugin Name: Uônix Jornada — guarda temporária local
 * Description: Instrumentação exclusiva do teste; nunca distribuir para produção.
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('uonix_jornada_validar')) {
function uonix_jornada_validar($cfg, $home, $ambiente) {
    $url = parse_url($home);
    if ('local' !== $ambiente || !$url || 'http' !== ($url['scheme'] ?? '')
        || !in_array($url['host'] ?? '', array('localhost', '127.0.0.1', '::1', '[::1]'), true)
        || isset($url['user']) || isset($url['pass'])
        || !preg_match('/^uonix-jornada-[a-f0-9]{32}$/D', $cfg['run_id'] ?? '')
        || ($cfg['email'] ?? '') !== $cfg['run_id'] . '@example.invalid') {
        throw new RuntimeException('jornada exige ambiente local e identificador sintético válido');
    }
    return true;
}

function uonix_jornada_pertence($email, $empresa, $cfg) {
    return $email === $cfg['email'] && $empresa === $cfg['esperado']['empresa'];
}

function uonix_jornada_lead_pertence($lead, $cfg) {
    $dados = json_decode($lead['response'] ?? '', true);
    return 4 === (int) ($lead['form_id'] ?? 0) && is_array($dados)
        && uonix_jornada_pertence($dados['capturalead_email'] ?? '', $dados['capturalead_empresa'] ?? '', $cfg);
}

function uonix_jornada_smtp_local($mailer) {
    return 'smtp' === $mailer->Mailer && 'mailpit' === $mailer->Host
        && 1025 === (int) $mailer->Port && false === $mailer->SMTPAuth && '' === $mailer->SMTPSecure;
}

function uonix_jornada_requisicao($cfg) {
    return hash_equals($cfg['run_id'], $_SERVER['HTTP_X_UONIX_JORNADA'] ?? '');
}

function uonix_jornada_remover_guarda($diretorio, $guarda) {
    $arquivo = $diretorio . '/guarda.php';
    if (is_link($diretorio) || is_link($arquivo)) { throw new RuntimeException('guarda local é link; não remover'); }
    if (!file_exists($diretorio)) { return true; }
    $entradas = is_dir($diretorio) ? array_values(array_diff(scandir($diretorio), array('.', '..'))) : null;
    if (null === $entradas || array_diff($entradas, array('guarda.php'))
        || (in_array('guarda.php', $entradas, true)
            && (!is_file($arquivo) || !hash_equals(hash('sha256', $guarda), hash_file('sha256', $arquivo))))) {
        throw new RuntimeException('guarda local divergente; não remover');
    }
    if ((file_exists($arquivo) && !unlink($arquivo)) || !rmdir($diretorio)) {
        throw new RuntimeException('limpeza da guarda incompleta');
    }
    return true;
}
}

$uonix_jornada_cfg = get_option('uonix_jornada_execucao');
if (!$uonix_jornada_cfg) { return; }
uonix_jornada_validar($uonix_jornada_cfg, home_url(), defined('UONIX_ENV') ? UONIX_ENV : '');

// Não substituir a configuração: verificar a política local já instalada.
add_action('phpmailer_init', function ($mailer) {
    if (!uonix_jornada_smtp_local($mailer)) {
        throw new RuntimeException('jornada bloqueou transporte fora do Mailpit');
    }
}, PHP_INT_MAX);

// Proteção também no servidor: não deixar integrações alcançarem a internet.
add_filter('pre_http_request', function () {
    return new WP_Error('uonix_jornada_isolada', 'HTTP bloqueado durante a jornada local');
}, PHP_INT_MAX, 3);

// Não enfileirar webhooks para entrega posterior à remoção da guarda.
add_filter('woocommerce_webhook_should_deliver', function ($entregar) use ($uonix_jornada_cfg) {
    return uonix_jornada_requisicao($uonix_jornada_cfg) ? false : $entregar;
}, PHP_INT_MAX);

// Não gerar tarefas de CRM/newsletter para o formulário sintético. E-mails do
// Fluent Forms são síncronos no teste; a fila de produção não é coberta aqui.
add_filter('fluentform/global_notification_active_types', function ($tipos, $form_id) use ($uonix_jornada_cfg) {
    return uonix_jornada_requisicao($uonix_jornada_cfg) && in_array((int) $form_id, array(2, 4), true)
        ? array_intersect_key($tipos, array('notifications' => true)) : $tipos;
}, PHP_INT_MAX, 2);
add_filter('fluentform/notifying_async_email_notifications', function ($async, $form_id) use ($uonix_jornada_cfg) {
    return uonix_jornada_requisicao($uonix_jornada_cfg) && 4 === (int) $form_id ? false : $async;
}, PHP_INT_MAX, 2);

// Cabeçalhos observacionais: não alteram status, destinatário ou conteúdo.
add_filter('woocommerce_email_headers', function ($headers, $email_id, $order) use ($uonix_jornada_cfg) {
    if ($order instanceof WC_Order && uonix_jornada_pertence($order->get_billing_email(), $order->get_billing_company(), $uonix_jornada_cfg)) {
        $headers .= "\r\nX-Uonix-Jornada: " . $uonix_jornada_cfg['run_id'];
        $headers .= "\r\nX-Uonix-Jornada-Email: " . $email_id . "\r\n";
    }
    return $headers;
}, PHP_INT_MAX, 3);
add_filter('wp_mail', function ($args) use ($uonix_jornada_cfg) {
    $texto = implode(',', (array) ($args['to'] ?? array())) . ($args['message'] ?? '');
    if (false !== stripos($texto, $uonix_jornada_cfg['run_id'])) {
        $headers = $args['headers'] ?? '';
        $cabecalho = 'X-Uonix-Jornada: ' . $uonix_jornada_cfg['run_id'];
        if (is_array($headers)) { $headers[] = $cabecalho; }
        else { $headers = rtrim($headers) . "\r\n" . $cabecalho . "\r\n"; }
        $args['headers'] = $headers;
    }
    return $args;
}, PHP_INT_MAX);

// Registrar apenas a sessão nova que adicionou o produto com nosso cabeçalho.
add_action('woocommerce_add_to_cart', function () use ($uonix_jornada_cfg) {
    if (uonix_jornada_requisicao($uonix_jornada_cfg) && WC()->session) {
        $cfg = get_option('uonix_jornada_execucao');
        $cfg['sessoes'][] = WC()->session->get_customer_id();
        $cfg['sessoes'] = array_values(array_unique($cfg['sessoes']));
        update_option('uonix_jornada_execucao', $cfg, false);
    }
}, PHP_INT_MAX);
