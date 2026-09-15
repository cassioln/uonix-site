<?php
/**
 * Contrato unitário do configurador WPSC Simple para produção.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/configure-wp-super-cache-simple.php';
$failures = array();
$settings = array();
$events = array();

function wpsc_test_fail(string $message): void {
    global $failures;
    $failures[] = $message;
}

function wp_cache_setting(string $field, $value): bool {
    global $settings;
    $settings[$field] = $value;
    return true;
}

function wp_cache_enable(): void {
    global $events, $settings;
    $events[] = 'enable';
    $settings['cache_enabled'] = true;
    $GLOBALS['cache_enabled'] = true;
}

function wp_super_cache_enable(): void {
    global $events, $settings;
    $events[] = 'super-enable';
    $settings['super_cache_enabled'] = true;
    $GLOBALS['super_cache_enabled'] = true;
}

function prune_super_cache(string $path, bool $all): bool {
    global $events;
    $events[] = 'purge:' . $path . ':' . ($all ? '1' : '0');
    return true;
}

function wp_clear_scheduled_hook(string $hook): void {
    global $events;
    $events[] = 'clear:' . $hook;
}

$GLOBALS['cache_path'] = '/tmp/uonix-wpsc-cache/';
$wp_content_dir = '/tmp/uonix-wp-content';
define('ABSPATH', '/tmp/uonix-wordpress/');
define('WP_CONTENT_DIR', $wp_content_dir);
$wpsc_rejected_cookies = array();
$cache_rejected_uri = array();
$wp_cache_mod_rewrite = 1;
$wp_cache_not_logged_in = 0;
$wp_cache_mobile_enabled = 1;
$wp_cache_make_known_anon = 1;
$wp_cache_object_cache = 1;
$cache_rebuild_files = 0;
$wp_cache_preload_on = 1;
$GLOBALS['cache_enabled'] = false;
$GLOBALS['super_cache_enabled'] = false;

function wpsc_run_configuration_in_eval_file_scope(string $script): string {
    ob_start();
    require $script;
    return ob_get_clean();
}

$output = wpsc_run_configuration_in_eval_file_scope($script);

$expected_cookies = array(
    'PHPSESSID',
    'rfqtk_wp_session_',
    'wp_woocommerce_session_',
    'woocommerce_items_in_cart',
    'woocommerce_cart_hash',
);
$expected_uris = array(
    'wp-.*\\.php',
    'index\\.php',
    '^/cotacao(?:/|$)',
    '^/finalizar-orcamento(?:/|$)',
    '^/(?:minha-conta|conta|account)(?:/|$)',
);

if (($settings['wpsc_rejected_cookies'] ?? null) !== $expected_cookies) {
    wpsc_test_fail('cookies rejeitados não correspondem à matriz RFQ/Woo.');
}
if (($settings['cache_rejected_uri'] ?? null) !== $expected_uris) {
    wpsc_test_fail('URIs rejeitadas não correspondem às rotas sensíveis.');
}
foreach (array(
    'wp_cache_mod_rewrite' => 0,
    'wp_cache_not_logged_in' => 2,
    'wp_cache_mobile_enabled' => 0,
    'wp_cache_make_known_anon' => 0,
    'wp_cache_object_cache' => 0,
    'cache_rebuild_files' => 1,
    'wp_cache_preload_on' => 0,
    'cache_enabled' => true,
    'super_cache_enabled' => true,
) as $key => $expected) {
    if (($settings[$key] ?? null) !== $expected) {
        wpsc_test_fail("configuração ausente/incorreta: {$key}");
    }
}
foreach (array('enable', 'super-enable', 'purge:/tmp/uonix-wp-content/cache/:1', 'clear:wp_cache_preload_hook') as $event) {
    if (!in_array($event, $events, true)) {
        wpsc_test_fail("operação obrigatória ausente: {$event}");
    }
}
if (str_contains($output, 'WPSC_SIMPLE_CONFIGURATION=PASS') === false) {
    wpsc_test_fail('marcador de sucesso ausente.');
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
printf("PASS: configuração WPSC Simple preserva bypass RFQ/Woo e sem Expert.\n");
