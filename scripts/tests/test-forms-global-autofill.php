<?php
/**
 * Test: Validação comportamental da persistência global de formulários e governança fail-closed do AdOpt.
 */

$rootDir = dirname(__DIR__, 2);

// ==============================================================================
// 1. VALIDAÇÃO ESTRUTURAL
// ==============================================================================

$moduleForms = file_get_contents($rootDir . '/mu-plugins/uonix-forms/module.php');
if (strpos($moduleForms, '49-forms-global-autofill.php') === false) {
    echo "ERRO: 49-forms-global-autofill.php não está registrado em module.php\n";
    exit(1);
}

$comentariosMaster = file_get_contents($rootDir . '/mu-plugins/uonix-content/10-comentarios-master.php');
if (strpos($comentariosMaster, "comment_author_company_") === false) {
    echo "ERRO: Cookie de empresa não encontrado em 10-comentarios-master.php\n";
    exit(1);
}

if (strpos($comentariosMaster, "uonix_bottom_checkboxes_html(false, true)") === false) {
    echo "ERRO: Checkbox nativo de cookies não foi omitido em uonix_bottom_checkboxes_injection\n";
    exit(1);
}

$globalAutofill = file_get_contents($rootDir . '/mu-plugins/uonix-forms/49-forms-global-autofill.php');
$requiredSelectors = [
    'form[data-form_id="3"] input[name="form_nome"]',
    'form[data-form_id="3"] input[name="form_empresa"]',
    'form[data-form_id="3"] input[name="form_email"]',
    'form[data-form_id="3"] input[name="form_telefone"]',
    '#ucf_nome',
    '#ucf_empresa',
    '#ucf_email',
    '#ucf_telefone',
    '#trab_nome',
    '#trab_email',
    '#trab_tel',
    '#author',
    '#company',
    '#email',
    '#billing_complete_name',
    '#billing_company',
    '#billing_email',
    '#billing_phone',
    '#billing_city',
    '#billing_state',
    '_adoptReject',
    'uonix_user_lead',
    'uonix_lead_profile',
    'uonix_consent_granted',
];

foreach ($requiredSelectors as $selector) {
    if (strpos($globalAutofill, $selector) === false) {
        echo "ERRO: Seletor/Chave '{$selector}' não encontrado em 49-forms-global-autofill.php\n";
        exit(1);
    }
}

// Minimização LGPD: campos sensíveis ou de endereço completo NÃO devem ser persistidos
$forbiddenFields = [
    'billing_cnpj',
    'billing_address_1',
    'billing_address_3',
    'billing_postcode',
];

foreach ($forbiddenFields as $field) {
    if (strpos($globalAutofill, "payload.{$field}") !== false || strpos($globalAutofill, "'{$field}'") !== false) {
        echo "ERRO DE CONFORMIDADE LGPD: Campo '{$field}' não deveria ser persistido no navegador!\n";
        exit(1);
    }
}

// ==============================================================================
// 2. VALIDAÇÃO COMPORTAMENTAL DE BACKEND (PHP) - POLÍTICA FAIL-CLOSED
// ==============================================================================

if (!defined('ABSPATH')) {
    define('ABSPATH', $rootDir . '/');
}
if (!defined('COOKIEHASH')) {
    define('COOKIEHASH', 'uonix_test_hash');
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('SITECOOKIEPATH')) {
    define('SITECOOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}

$GLOBALS['_mock_filters'] = array();
$GLOBALS['_mock_actions'] = array();
$GLOBALS['_last_cookies_evaluated'] = null;

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['_mock_filters'][$hook][] = $callback;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['_mock_actions'][$hook][] = $callback;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if ($hook === 'uonix_comment_set_cookies_evaluated') {
            $GLOBALS['_last_cookies_evaluated'] = array(
                'has_consent'     => $args[0],
                'cookies_consent' => $args[1] ?? false,
            );
        }
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return false;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl() {
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($val) {
        return trim((string) $val);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($val) {
        return $val;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($val) {
        return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($val) {
        return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
    }
}

// Carrega o arquivo dos comentários sob teste
require_once $rootDir . '/mu-plugins/uonix-content/10-comentarios-master.php';

$preprocessFilter = $GLOBALS['_mock_filters']['preprocess_comment'][0] ?? null;
$setCookiesAction = $GLOBALS['_mock_actions']['set_comment_cookies'][0] ?? null;

if (!is_callable($preprocessFilter)) {
    echo "ERRO: Filtro preprocess_comment não foi registrado em 10-comentarios-master.php\n";
    exit(1);
}

if (!is_callable($setCookiesAction)) {
    echo "ERRO: Action set_comment_cookies não foi registrada em 10-comentarios-master.php\n";
    exit(1);
}

// CENÁRIO B1: Storage-only / Requisição sem consentimento explícito
$_POST = array('comment' => 'Dúvida técnica', 'company' => 'Empresa Sem Cookie');
$_COOKIE = array();
$GLOBALS['_last_cookies_evaluated'] = null;

$preprocessFilter(array('comment_content' => 'Dúvida técnica'));
if (isset($_POST['wp-comment-cookies-consent'])) {
    echo "ERRO FAIL-CLOSED: preprocess_comment inseriu wp-comment-cookies-consent sem que o cliente tivesse enviado!\n";
    exit(1);
}

$setCookiesAction(null, null, false);
if ($GLOBALS['_last_cookies_evaluated']['has_consent'] !== false) {
    echo "ERRO FAIL-CLOSED: set_comment_cookies autorizou gravação sem consentimento do cliente!\n";
    exit(1);
}

// CENÁRIO B2: Tentativa de envio com consentimento falso (sem evidência positiva no servidor)
$_POST = array('comment' => 'Dúvida', 'company' => 'Empresa Fake', 'wp-comment-cookies-consent' => 'yes');
$_COOKIE = array();
$GLOBALS['_last_cookies_evaluated'] = null;

$preprocessFilter(array('comment_content' => 'Dúvida'));
if (isset($_POST['wp-comment-cookies-consent'])) {
    echo "ERRO FAIL-CLOSED: preprocess_comment não anulou wp-comment-cookies-consent quando o servidor não possui evidência positiva de consentimento!\n";
    exit(1);
}

$setCookiesAction(null, null, false);
if ($GLOBALS['_last_cookies_evaluated']['has_consent'] !== false) {
    echo "ERRO FAIL-CLOSED: set_comment_cookies autorizou gravação sem evidência positiva!\n";
    exit(1);
}

// CENÁRIO B3: Rejeição explícita no servidor via _adoptReject
$_POST = array('comment' => 'Dúvida', 'company' => 'Empresa Recusada', 'wp-comment-cookies-consent' => 'yes');
$_COOKIE = array('_adoptReject' => '1', 'uonix_consent_granted' => '1');
$GLOBALS['_last_cookies_evaluated'] = null;

$preprocessFilter(array('comment_content' => 'Dúvida'));
if (isset($_POST['wp-comment-cookies-consent'])) {
    echo "ERRO FAIL-CLOSED: preprocess_comment não anulou consentimento na presença de _adoptReject!\n";
    exit(1);
}

$setCookiesAction(null, null, false);
if ($GLOBALS['_last_cookies_evaluated']['has_consent'] !== false) {
    echo "ERRO FAIL-CLOSED: set_comment_cookies autorizou gravação na presença de _adoptReject!\n";
    exit(1);
}

// CENÁRIO B4: Consentimento positivo legítimo (uonix_consent_granted)
$_POST = array('comment' => 'Dúvida', 'company' => 'Empresa Aprovada', 'wp-comment-cookies-consent' => 'yes');
$_COOKIE = array('uonix_consent_granted' => '1');
$GLOBALS['_last_cookies_evaluated'] = null;

$preprocessFilter(array('comment_content' => 'Dúvida'));
if (!isset($_POST['wp-comment-cookies-consent']) || $_POST['wp-comment-cookies-consent'] !== 'yes') {
    echo "ERRO: preprocess_comment deveria manter o consentimento positivo legítimo!\n";
    exit(1);
}

$setCookiesAction(null, null, true);
if ($GLOBALS['_last_cookies_evaluated']['has_consent'] !== true) {
    echo "ERRO: set_comment_cookies deveria autorizar gravação quando há consentimento positivo válido!\n";
    exit(1);
}

// ==============================================================================
// 3. VALIDAÇÃO COMPORTAMENTAL DO FRONTEND VIA NODE.JS (SIMULAÇÃO DOM & ADOPT)
// ==============================================================================

// Extrai o conteúdo do script JS de 49-forms-global-autofill.php
preg_match('/<script[^>]*>(.*?)<\/script>/s', $globalAutofill, $jsMatch);
if (empty($jsMatch[1])) {
    echo "ERRO: Bloco JavaScript não encontrado em 49-forms-global-autofill.php\n";
    exit(1);
}

$jsCode = $jsMatch[1];
$encodedJs = json_encode($jsCode);

$nodeTestScript = <<<NODE_JS
const vm = require('vm');
const assert = require('assert');
const CODE_PAYLOAD = $encodedJs;

function runTestEnvironment(initialCookie = '', initialStorage = {}) {
    let cookieStr = initialCookie;
    const storage = Object.assign({}, initialStorage);
    const eventListeners = {};

    const doc = {
        get cookie() { return cookieStr; },
        set cookie(val) {
            const parts = val.split(';');
            const pair = parts[0].trim().split('=');
            const k = pair[0];
            const v = pair[1] || '';
            if (val.includes('max-age=0')) {
                cookieStr = cookieStr.split(';').filter(c => !c.trim().startsWith(k + '=')).join('; ');
            } else {
                doc.cookie = k + '=' + v; // remove old
                cookieStr = (cookieStr ? cookieStr + '; ' : '') + k + '=' + v;
            }
        },
        querySelectorAll: function(sel) {
            return [];
        },
        addEventListener: function(evt, handler) {
            eventListeners[evt] = eventListeners[evt] || [];
            eventListeners[evt].push(handler);
        },
        readyState: 'complete',
        documentElement: {}
    };

    const win = {
        document: doc,
        location: { protocol: 'https:' },
        localStorage: {
            getItem: (k) => (k in storage ? storage[k] : null),
            setItem: (k, v) => { storage[k] = String(v); },
            removeItem: (k) => { delete storage[k]; }
        },
        addEventListener: function(evt, handler) {
            eventListeners[evt] = eventListeners[evt] || [];
            eventListeners[evt].push(handler);
        },
        MutationObserver: function() {
            return { observe: () => {} };
        },
        Event: function(name) { this.name = name; }
    };
    win.window = win;

    const context = vm.createContext(win);
    vm.runInContext(CODE_PAYLOAD, context);

    return { win, doc, storage, eventListeners };
}

// F1: Com _adoptReject em localStorage -> isAdoptConsentGranted DEVE ser false
{
    const { win } = runTestEnvironment('', { '_adoptReject': '1' });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F1: isAdoptConsentGranted deveria ser false com recusa em localStorage');
}

// F2: Visita inicial (sem cookie e sem storage) -> isAdoptConsentGranted DEVE ser false (fail-closed)
{
    const { win } = runTestEnvironment('', {});
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F2: isAdoptConsentGranted deveria ser false em visita limpa');
}

// F3: Consentimento positivo em cookie ou storage -> isAdoptConsentGranted DEVE ser true
{
    const { win } = runTestEnvironment('AdoptConsent=abc', {});
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), true, 'F3a: isAdoptConsentGranted deveria ser true com AdoptConsent');
}
{
    const { win } = runTestEnvironment('', { 'uonix_consent_granted': '1' });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), true, 'F3b: isAdoptConsentGranted deveria ser true com uonix_consent_granted no storage');
}

// F4: markConsentRejected limpa os dados
{
    const { win, storage } = runTestEnvironment('uonix_consent_granted=1', { 'uonix_user_lead': '{"nome":"Teste"}' });
    win.uonixMarkConsentRejected();
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F4a: Após markConsentRejected, consentimento deve ser false');
    assert.strictEqual(storage['uonix_user_lead'], undefined, 'F4b: Dados salvos devem ser excluídos');
    assert.strictEqual(storage['_adoptReject'], '1', 'F4c: _adoptReject deve ser gravado');
}

console.log('NODE_JS_PASS');
NODE_JS;

$tempNodeFile = tempnam(sys_get_temp_dir(), 'uonix_node_test_') . '.js';
file_put_contents($tempNodeFile, $nodeTestScript);

$output = shell_exec("node " . escapeshellarg($tempNodeFile));
unlink($tempNodeFile);

if (trim($output) !== 'NODE_JS_PASS') {
    echo "ERRO NO TESTE COMPORTAMENTAL JAVASCRIPT:\n" . $output . "\n";
    exit(1);
}

echo "SUCESSO: Todos os testes comportamentais de backend (fail-closed) e frontend (DOM/AdOpt) passaram com 100% de precisão!\n";
exit(0);
