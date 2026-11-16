<?php
/**
 * Plugin Name: Uônix Jornada — fixture de falhas locais
 * Não instalar em produção. Usado apenas para provar os gates por mutação real.
 */
if (!defined('ABSPATH') || !defined('UONIX_ENV') || 'local' !== UONIX_ENV) { return; }
$host = parse_url(home_url(), PHP_URL_HOST);
if (!in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true)) { return; }
$jornada_propria = function () {
    $cfg = get_option('uonix_jornada_execucao');
    return $cfg && hash_equals($cfg['run_id'], $_SERVER['HTTP_X_UONIX_JORNADA'] ?? '');
};
add_action('wp_loaded', function () use ($jornada_propria) {
    if (!$jornada_propria() || 'lead' !== get_option('uonix_jornada_falha_fixture')) { return; }
    global $wp_filter;
    foreach ($wp_filter['woocommerce_checkout_order_processed']->callbacks ?? array() as $prioridade => $callbacks) {
        foreach ($callbacks as $callback) {
            if ($callback['function'] instanceof Closure) {
                $origem = (new ReflectionFunction($callback['function']))->getFileName();
                if (str_ends_with($origem ?: '', '/08-fluentforms-sync-woocommerce.php')) {
                    remove_action('woocommerce_checkout_order_processed', $callback['function'], $prioridade);
                }
            }
        }
    }
}, 1);
add_filter('pre_wp_mail', function ($pre, $args) use ($jornada_propria) {
    $headers = implode("\n", (array) ($args['headers'] ?? array()));
    if ($jornada_propria() && 'notificacao' === get_option('uonix_jornada_falha_fixture')
        && false !== stripos($headers, 'X-Uonix-Jornada-Email: new_rfq')) {
        return false; // Falha real de geração/captura, sem mudar o enabled esperado.
    }
    return $pre;
}, PHP_INT_MAX, 2);
