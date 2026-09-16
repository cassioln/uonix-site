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

// wp-content real em diretório temporário: permite provar mtime e confinamento.
$content_dir = sys_get_temp_dir() . '/uonix-wp-content-' . getmypid();
@mkdir($content_dir . '/plugins/woo-rfq-for-woocommerce/gpls_assets/css', 0700, true);
define('WP_CONTENT_DIR', $content_dir);

function home_url(string $path = ''): string {
    return 'https://uonix.com.br' . $path;
}

function wp_parse_url(string $url) {
    return parse_url($url);
}

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

$asset_rel = '/plugins/woo-rfq-for-woocommerce/gpls_assets/css/gpls_woo_rfq.css';
$asset_path = $content_dir . $asset_rel;
$asset_url = 'https://uonix.com.br/wp-content' . $asset_rel;
file_put_contents($asset_path, "/* v1 */\n");
touch($asset_path, 1600000000);

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

// ---- Cache-busting real: mudar o arquivo precisa mudar a versão ----------
$before = apply_filters('style_loader_src', $asset_url . '?ver=111', 'gpls_woo_rfq_css');
if (false === strpos($before, 'ver=1600000000')) {
    uonix_test_fail("versão não derivou do mtime do asset: {$before}");
}
file_put_contents($asset_path, "/* v2 alterado */\n");
touch($asset_path, 1700000000);
clearstatcache(true, $asset_path);
$after = apply_filters('style_loader_src', $asset_url . '?ver=222', 'gpls_woo_rfq_css');
if ($before === $after) {
    uonix_test_fail('versão não mudou após alteração real do asset: cache-busting quebrado');
}
if (false === strpos($after, 'ver=1700000000')) {
    uonix_test_fail("versão não acompanhou o novo mtime: {$after}");
}

// ---- Múltiplos parâmetros de query precisam ser preservados --------------
$multi = apply_filters('style_loader_src', $asset_url . '?a=1&ver=999&b=2', 'gpls_woo_rfq_css');
foreach (array('a=1', 'b=2') as $pair) {
    if (false === strpos($multi, $pair)) {
        uonix_test_fail("parâmetro legítimo perdido ({$pair}): {$multi}");
    }
}
if (false !== strpos($multi, 'ver=999')) {
    uonix_test_fail("versão aleatória sobreviveu com múltiplos parâmetros: {$multi}");
}

// Um parâmetro com prefixo "ver" NÃO pode ser confundido com a versão.
$prefixed = apply_filters('style_loader_src', $asset_url . '?version=abc&ver=999', 'gpls_woo_rfq_css');
if (false === strpos($prefixed, 'version=abc')) {
    uonix_test_fail("parâmetro 'version' foi removido por engano: {$prefixed}");
}

// ---- Fragmento: ?ver= precisa ficar ANTES do # --------------------------
$fragment = apply_filters('style_loader_src', $asset_url . '?ver=999#bloco', 'gpls_woo_rfq_css');
$hash_at = strpos($fragment, '#');
$ver_at = strpos($fragment, 'ver=');
if (false === $hash_at || false === $ver_at) {
    uonix_test_fail("fragmento ou versão ausente: {$fragment}");
} elseif ($ver_at > $hash_at) {
    uonix_test_fail("versão caiu dentro do fragmento e o asset perde cache-busting: {$fragment}");
}
if (false === strpos($fragment, '#bloco')) {
    uonix_test_fail("fragmento foi descartado: {$fragment}");
}

// ---- URL externa contendo /wp-content/ não pode virar caminho local ------
$external = 'https://cdn.example.com/wp-content/plugins/woo-rfq-for-woocommerce/gpls_assets/css/gpls_woo_rfq.css?ver=999';
$external_result = apply_filters('style_loader_src', $external, 'gpls_woo_rfq_css');
if (false !== strpos($external_result, 'ver=1700000000')) {
    uonix_test_fail("URL externa recebeu mtime de arquivo local homônimo: {$external_result}");
}
if (0 !== strpos($external_result, 'https://cdn.example.com/')) {
    uonix_test_fail("host externo foi alterado: {$external_result}");
}

// ---- Travessia codificada precisa ser recusada ---------------------------
// O alvo é criado FORA de wp-content, com mtime distinto: sem o confinamento
// por realpath, o filtro leria o mtime desse arquivo e vazaria a informação.
$outside_path = dirname($content_dir) . '/uonix-outside-' . getmypid() . '.php';
file_put_contents($outside_path, "<?php\n");
touch($outside_path, 1500000000);
clearstatcache(true, $outside_path);
$traversal = 'https://uonix.com.br/wp-content/plugins/%2e%2e/%2e%2e/' . basename($outside_path) . '?ver=999';
$traversal_result = apply_filters('style_loader_src', $traversal, 'gpls_woo_rfq_css');
if (false !== strpos($traversal_result, 'ver=1500000000')) {
    uonix_test_fail("travessia resolveu mtime de arquivo fora de wp-content: {$traversal_result}");
}
@unlink($outside_path);

// ---- O módulo precisa estar registrado no loader e na CI -----------------
$loader = file_get_contents($root . '/mu-plugins/uonix-woocommerce/module.php');
if (false === strpos((string) $loader, '32-rfq-stable-asset-version.php')) {
    uonix_test_fail('módulo não está registrado em mu-plugins/uonix-woocommerce/module.php');
}
$workflow = file_get_contents($root . '/.github/workflows/validate.yml');
if (false === strpos((string) $workflow, 'test-rfq-stable-asset-version.php')) {
    uonix_test_fail('teste não está registrado em .github/workflows/validate.yml');
}

// Limpeza do fixture temporário.
@unlink($asset_path);

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
printf("PASS: versões de assets do RFQ são estáveis e não afetam terceiros.\n");
