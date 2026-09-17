<?php
/**
 * Contrato server-side de atribuição consentida (Issue #189).
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

$GLOBALS['uonix_attribution_actions'] = array();
$GLOBALS['uonix_attribution_filters'] = array();

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['uonix_attribution_actions'][] = array($hook, $callback, $priority, $accepted_args);
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['uonix_attribution_filters'][] = array($hook, $callback, $priority, $accepted_args);
}
function is_admin() { return false; }
function is_feed() { return false; }
function is_embed() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }
function sanitize_key($value) { return is_scalar($value) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) : ''; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function home_url($path = '') { return 'https://uonix.com.br' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function add_query_arg($args, $url) {
    $parts = parse_url($url);
    $query = array();
    parse_str($parts['query'] ?? '', $query);
    foreach ($args as $key => $value) $query[$key] = $value;
    $prefix = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'uonix.com.br') . ($parts['path'] ?? '/');
    return $prefix . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

final class UonixAttributionFakeDb {
    public string $prefix = 'wp_';
    public array $rows = array();
    public function get_var($query) { return str_contains((string) $query, 'fluentform_submission_meta') ? $this->prefix . 'fluentform_submission_meta' : null; }
    public function delete($table, $where) {
        $this->rows = array_values(array_filter($this->rows, static fn($row) => !($row['response_id'] === ($where['response_id'] ?? null) && $row['meta_key'] === ($where['meta_key'] ?? null))));
        return 1;
    }
    public function insert($table, $data) { $this->rows[] = $data; return 1; }
}

final class UonixAttributionFakeOrder {
    public array $meta = array();
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
}

$GLOBALS['wpdb'] = new UonixAttributionFakeDb();

$module = dirname(__DIR__, 2) . '/mu-plugins/uonix-integrations/39-rastreamento-utm-atribuicao.php';
if (!is_file($module)) {
    fwrite(STDERR, "FAIL: módulo de atribuição não encontrado\n");
    exit(1);
}
require_once $module;

$failures = 0;
function attribution_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

attribution_assert(function_exists('uonix_attribution_sanitize_value'), 'Sanitizador consent-aware existe');
attribution_assert(function_exists('uonix_attribution_filter_internal_redirect'), 'Filtro de redirect interno existe');
attribution_assert(function_exists('uonix_attribution_save_order_meta'), 'Persistência de pedido existe');
attribution_assert(function_exists('uonix_attribution_save_fluentform_meta'), 'Persistência Fluent Forms existe');

if (function_exists('uonix_attribution_sanitize_value')) {
    attribution_assert('meta campanha-01' === uonix_attribution_sanitize_value('utm_campaign', 'meta campanha-01'), 'Valor permitido de campanha é preservado');
    attribution_assert('' === uonix_attribution_sanitize_value('utm_source', '<script>alert(1)</script>'), 'HTML e caracteres proibidos são rejeitados');
    attribution_assert('' === uonix_attribution_sanitize_value('fbclid', str_repeat('a', 256)), 'Valores acima do limite são rejeitados');
    attribution_assert('' === uonix_attribution_sanitize_value('nao_permitido', 'meta'), 'Chaves fora da allowlist são rejeitadas');
    attribution_assert('' === uonix_attribution_sanitize_value('utm_source', array('meta')), 'Valores não escalares são rejeitados');
}

$_GET = array('utm_source' => 'meta', 'utm_medium' => 'cpc', 'fbclid' => 'fb_test-01');
$_SERVER['QUERY_STRING'] = 'utm_source=meta&utm_medium=cpc&fbclid=fb_test-01';
if (function_exists('uonix_attribution_filter_internal_redirect')) {
    $internal = uonix_attribution_filter_internal_redirect('https://uonix.com.br/produtos/', 301);
    attribution_assert(false !== strpos($internal, 'utm_source=meta') && false !== strpos($internal, 'fbclid=fb_test-01'), 'Redirect interno preserva atribuição validada');
    attribution_assert('https://externo.example/oferta' === uonix_attribution_filter_internal_redirect('https://externo.example/oferta', 302), 'Redirect externo não recebe identificadores de atribuição');
    $already = 'https://uonix.com.br/produtos/?utm_source=meta&utm_medium=cpc&fbclid=fb_test-01';
    attribution_assert($already === uonix_attribution_filter_internal_redirect($already, 301), 'Redirect já normalizado não duplica parâmetros');
}

$order = new UonixAttributionFakeOrder();
$_POST = array('uonix_attribution_utm_source' => 'meta', 'uonix_attribution_fbclid' => 'fb_test-01');
$_COOKIE = array();
if (function_exists('uonix_attribution_save_order_meta')) {
    uonix_attribution_save_order_meta($order);
    attribution_assert(array() === $order->meta, 'Pedido não recebe atribuição sem marcador de consentimento marketing');

    $_COOKIE['uonix_attribution_marketing'] = '1';
    uonix_attribution_save_order_meta($order);
    attribution_assert('meta' === ($order->meta['_uonix_utm_source'] ?? ''), 'Pedido recebe UTM somente após consentimento marketing');
    attribution_assert('fb_test-01' === ($order->meta['_uonix_fbclid'] ?? ''), 'Pedido recebe fbclid validado somente após consentimento marketing');
}

$form = (object) array('id' => 3);
$GLOBALS['wpdb']->rows = array();
if (function_exists('uonix_attribution_save_fluentform_meta')) {
    $_COOKIE = array();
    uonix_attribution_save_fluentform_meta(99, array('uonix_attribution_utm_source' => 'meta'), $form);
    attribution_assert(array() === $GLOBALS['wpdb']->rows, 'Fluent Forms não recebe metadado sem marcador de consentimento marketing');

    $_COOKIE['uonix_attribution_marketing'] = '1';
    uonix_attribution_save_fluentform_meta(99, array('uonix_attribution_utm_source' => 'meta', 'uonix_attribution_fbclid' => 'fb_test-01'), $form);
    attribution_assert(1 === count($GLOBALS['wpdb']->rows), 'Fluent Forms recebe um metadado de atribuição após consentimento marketing');
    $payload = json_decode((string) ($GLOBALS['wpdb']->rows[0]['value'] ?? ''), true);
    attribution_assert(is_array($payload) && 'meta' === ($payload['utm_source'] ?? '') && 'fb_test-01' === ($payload['fbclid'] ?? ''), 'Metadado Fluent Forms contém apenas atribuição validada');

    // Form 4 não é exibido diretamente: ele recebe leads espelhados do Form 3
    // e do fluxo WooCommerce. Por isso, deve usar o fallback dos cookies já
    // consentidos quando o payload espelhado não contém os campos ocultos.
    $GLOBALS['wpdb']->rows = array();
    $_COOKIE = array(
        'uonix_attribution_marketing'  => '1',
        'uonix_attribution_utm_source' => 'meta',
        'uonix_attribution_gclid'      => 'gclid-test-01',
    );
    $lead_form = (object) array('id' => 4);
    uonix_attribution_save_fluentform_meta(100, array(), $lead_form);
    attribution_assert(1 === count($GLOBALS['wpdb']->rows), 'Captura de Leads (Form 4) recebe atribuição de fluxos espelhados após consentimento');
    $lead_payload = json_decode((string) ($GLOBALS['wpdb']->rows[0]['value'] ?? ''), true);
    attribution_assert(is_array($lead_payload) && 'meta' === ($lead_payload['utm_source'] ?? '') && 'gclid-test-01' === ($lead_payload['gclid'] ?? ''), 'Captura de Leads grava somente atribuição validada do cookie consentido');
}

if ($failures > 0) {
    exit(1);
}

echo "PASS: atribuição server-side respeita consentimento, limites e redirects internos.\n";
