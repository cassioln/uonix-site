<?php
/**
 * Contrato: versões de assets do RFQ precisam ser estáveis entre requisições.
 *
 * O plugin enfileira CSS/JS com wp_rand(10, 100000) como versão (por exemplo
 * woo-rfq-for-woocommerce.php:878). Isso muda o HTML a cada requisição, o
 * WP Super Cache regrava o arquivo em vez de servi-lo, e categoria/produto
 * nunca são entregues do cache — medido em produção: p95 ~2,1 s e ~2,8 s,
 * contra 260 ms na home.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = $root . '/mu-plugins/uonix-woocommerce/32-rfq-stable-asset-version.php';
$failures = array();

function uonix_test_fail(string $message): void {
    global $failures;
    $failures[] = $message;
}

// ---- Ambiente mínimo do WordPress ----------------------------------------
define('ABSPATH', '/tmp/uonix-wp/');

$GLOBALS['uonix_filters'] = array();
$GLOBALS['uonix_registered_styles'] = array();
$GLOBALS['uonix_registered_scripts'] = array();

function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool {
    $GLOBALS['uonix_filters'][$hook][$priority][] = $callback;
    return true;
}

function apply_filters(string $hook, $value, ...$rest) {
    if (empty($GLOBALS['uonix_filters'][$hook])) {
        return $value;
    }
    $priorities = $GLOBALS['uonix_filters'][$hook];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$rest);
        }
    }
    return $value;
}

// ---- Carrega o módulo ----------------------------------------------------
if (!file_exists($module)) {
    fwrite(STDERR, "FAIL: módulo ausente: {$module}\n");
    exit(1);
}
require $module;

// ---- O filtro precisa estar registrado ----------------------------------
foreach (array('style_loader_src', 'script_loader_src') as $hook) {
    if (empty($GLOBALS['uonix_filters'][$hook])) {
        uonix_test_fail("filtro obrigatório não registrado: {$hook}");
    }
}

// ---- Handles do RFQ: versão precisa ser estável e idêntica entre chamadas -
$rfq_handles = array(
    'gpls_woo_rfq_css',
    'url_gpls_wh_css',
    'url_gpls_wh_css2',
    'gpls_woo_rfq_js',
    'url_gpls_wh_js',
    'gpls_woo_password_js',
    'rfq_dummy_js',
);
foreach ($rfq_handles as $handle) {
    $random_a = 'https://uonix.com.br/wp-content/plugins/woo-rfq-for-woocommerce/a.css?ver=18224';
    $random_b = 'https://uonix.com.br/wp-content/plugins/woo-rfq-for-woocommerce/a.css?ver=51545';

    $first = apply_filters('style_loader_src', $random_a, $handle);
    $second = apply_filters('style_loader_src', $random_b, $handle);

    if ($first !== $second) {
        uonix_test_fail("versão instável para {$handle}: '{$first}' != '{$second}'");
    }
    if (false !== strpos($first, '18224') || false !== strpos($first, '51545')) {
        uonix_test_fail("versão aleatória preservada em {$handle}: {$first}");
    }
    // A URL do asset precisa continuar apontando para o mesmo arquivo.
    if (false === strpos($first, '/woo-rfq-for-woocommerce/a.css')) {
        uonix_test_fail("URL do asset foi corrompida em {$handle}: {$first}");
    }
}

// ---- Handles de terceiros NÃO podem ser alterados ------------------------
$foreign = array(
    'woocommerce-general' => 'https://uonix.com.br/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=9.4.2',
    'kadence-blocks-global' => 'https://uonix.com.br/wp-content/plugins/kadence-blocks/dist/style.css?ver=3.2.1',
    'jquery-core' => 'https://uonix.com.br/wp-includes/js/jquery/jquery.min.js?ver=3.7.1',
);
foreach ($foreign as $handle => $src) {
    if (apply_filters('style_loader_src', $src, $handle) !== $src) {
        uonix_test_fail("asset de terceiro foi alterado: {$handle}");
    }
    if (apply_filters('script_loader_src', $src, $handle) !== $src) {
        uonix_test_fail("script de terceiro foi alterado: {$handle}");
    }
}

// ---- Assets do RFQ sem ?ver= não podem ganhar versão inválida ------------
$no_version = 'https://uonix.com.br/wp-content/plugins/woo-rfq-for-woocommerce/b.css';
$result = apply_filters('style_loader_src', $no_version, 'gpls_woo_rfq_css');
if ('' === $result || null === $result) {
    uonix_test_fail('asset sem ?ver= retornou vazio');
}

// ---- A versão aplicada precisa ser determinística e não vazia ------------
$applied = apply_filters('style_loader_src', 'https://uonix.com.br/a.css?ver=999', 'gpls_woo_rfq_css');
if (!preg_match('/[?&]ver=([^&]+)/', $applied, $match)) {
    uonix_test_fail("versão estável ausente no resultado: {$applied}");
} elseif ('' === trim($match[1])) {
    uonix_test_fail('versão estável ficou vazia');
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
printf("PASS: versões de assets do RFQ são estáveis e não afetam terceiros.\n");
