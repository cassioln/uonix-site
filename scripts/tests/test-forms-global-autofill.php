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
        if (!empty($GLOBALS['_mock_filters'][$hook])) {
            foreach ($GLOBALS['_mock_filters'][$hook] as $cb) {
                $value = $cb($value, ...$args);
            }
        }
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

// CENÁRIO B2: Cookie AdoptConsent isolado NÃO prova consentimento (pode conter opt-outs)
$_POST = array('comment' => 'Dúvida', 'company' => 'Empresa Sem Prova', 'wp-comment-cookies-consent' => 'yes');
$_COOKIE = array('AdoptConsent' => 'visitor_choices_recorded_with_opt_outs');
$GLOBALS['_last_cookies_evaluated'] = null;

$preprocessFilter(array('comment_content' => 'Dúvida'));
if (isset($_POST['wp-comment-cookies-consent'])) {
    echo "ERRO FAIL-CLOSED: preprocess_comment aceitou AdoptConsent isolado sem prova positiva uonix_consent_granted!\n";
    exit(1);
}

$setCookiesAction(null, null, false);
if ($GLOBALS['_last_cookies_evaluated']['has_consent'] !== false) {
    echo "ERRO FAIL-CLOSED: set_comment_cookies autorizou gravação com AdoptConsent isolado!\n";
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

// CENÁRIO B4: Consentimento positivo legítimo First-Party (uonix_consent_granted)
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

// CENÁRIO B5: Validação de IDs de tags da AdOpt (Nomes de categorias descartados)
require_once $rootDir . '/mu-plugins/uonix-forms/49-forms-global-autofill.php';

if (!function_exists('uonix_adopt_get_consent_tag_ids')) {
    echo "ERRO: Função uonix_adopt_get_consent_tag_ids não foi declarada em 49-forms-global-autofill.php\n";
    exit(1);
}

// Prova que defaults textuais genéricos NUNCA são tratados como IDs válidos
$fakeFilter = function() { return array('funcional', 'preferences', 'functional', 'uonix_cookies'); };
$GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids'] = array($fakeFilter);
$idsFromText = uonix_adopt_get_consent_tag_ids();
if (!empty($idsFromText)) {
    echo "ERRO FAIL-CLOSED: uonix_adopt_get_consent_tag_ids aceitou nomes genéricos de categorias como se fossem IDs!\n";
    exit(1);
}

// Prova que fixture com UUID realista da AdOpt é aceita e normalizada
$uuidFilter = function() { return array('6332f834-41df-4cc5-a3bf-dffe359112c5', 'funcional'); };
$GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids'] = array($uuidFilter);
$idsFromUuid = uonix_adopt_get_consent_tag_ids();
if ($idsFromUuid !== array('6332f834-41df-4cc5-a3bf-dffe359112c5')) {
    echo "ERRO: uonix_adopt_get_consent_tag_ids deveria validar e retornar exclusivamente o UUID realista configurado!\n";
    exit(1);
}
unset($GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids']);

// CENÁRIO B6: o ID REAL da conta AdOpt é aceito.
// Regressão direta da issue #264: as cinco tags desta conta têm 10 caracteres, e o mínimo
// histórico de 12 rejeitava todas, mantendo o módulo inativo sem emitir sinal nenhum.
// Fixture fictícia não cobre isto — o valor abaixo é o ID real de `uonix.com.br`.
$realFilter = function() { return array('9BxuTvI1_q'); };
$GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids'] = array($realFilter);
$idsReais = uonix_adopt_get_consent_tag_ids();
if ($idsReais !== array('9bxutvi1_q')) {
    echo "ERRO #264: o ID real da AdOpt ('9BxuTvI1_q', 10 caracteres) foi rejeitado pelo validador. "
        . "Retornado: " . var_export($idsReais, true) . "\n";
    exit(1);
}
unset($GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids']);

// CENÁRIO B7: nomes de categoria continuam rejeitados, UM A UM.
// Com o mínimo em 10, quatro deles ('functional', 'necessario', 'preferences',
// 'uonix_cookies') passam pelo teste de comprimento — quem os rejeita é a lista explícita.
// Verificar em bloco esconderia a remoção de um nome isolado da lista.
$nomesDeCategoria = array(
    'funcional', 'preferences', 'functional', 'uonix_cookies',
    'marketing', 'analytics', 'necessario', 'essential',
);
foreach ($nomesDeCategoria as $nomeGenerico) {
    $filtroNome = function() use ($nomeGenerico) { return array($nomeGenerico); };
    $GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids'] = array($filtroNome);
    $resultado = uonix_adopt_get_consent_tag_ids();
    if (!empty($resultado)) {
        echo "ERRO FAIL-CLOSED: o nome de categoria '{$nomeGenerico}' (" . strlen($nomeGenerico)
            . " caracteres) foi aceito como ID de tag. A lista explícita de descarte é a única "
            . "defesa contra ele desde que o mínimo de comprimento caiu para 10.\n";
        exit(1);
    }
    unset($GLOBALS['_mock_filters']['uonix_adopt_consent_tag_ids']);
}

// CENÁRIO B8: sem a constante de ambiente, o padrão embutido já autoriza.
// É o que dispensa provisionamento por SSH e o que impede a inatividade silenciosa de #264.
$idsPadrao = uonix_adopt_get_consent_tag_ids();
if (count($idsPadrao) < 1) {
    echo "ERRO #264: sem UONIX_ADOPT_CONSENT_TAG_IDS definida, o padrão embutido deveria "
        . "autorizar ao menos uma tag. O módulo voltaria a ficar inativo em silêncio.\n";
    exit(1);
}
if (!defined('UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO') || '' === trim((string) UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO)) {
    echo "ERRO: UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO deve existir e não pode ser vazia.\n";
    exit(1);
}

// CENÁRIO B9: a constante de ambiente tem precedência sobre o padrão embutido.
// Subprocesso porque a precedência só é observável se a constante existir ANTES do require,
// e este processo já carregou o módulo sem ela (cenário B8).
$harnessPrecedencia = <<<'PHP_HARNESS'
<?php
define('ABSPATH', __DIR__ . '/');
define('UONIX_ADOPT_CONSENT_TAG_IDS', 'AbCdEfGhIjKl');
function add_action($h, $c, $p = 10, $a = 1) {}
function add_filter($h, $c, $p = 10, $a = 1) {}
function apply_filters($h, $v, ...$rest) { return $v; }
function is_admin() { return false; }
require MODULO;
$ids = uonix_adopt_get_consent_tag_ids();
echo implode(',', $ids);
PHP_HARNESS;

$harnessPrecedencia = str_replace(
    'MODULO',
    var_export($rootDir . '/mu-plugins/uonix-forms/49-forms-global-autofill.php', true),
    $harnessPrecedencia
);
$tempPrecedencia = tempnam(sys_get_temp_dir(), 'uonix_precedencia_') . '.php';
file_put_contents($tempPrecedencia, $harnessPrecedencia);
$saidaPrecedencia = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tempPrecedencia) . ' 2>&1'));
unlink($tempPrecedencia);

if ($saidaPrecedencia !== 'abcdefghijkl') {
    echo "ERRO: UONIX_ADOPT_CONSENT_TAG_IDS deveria ter precedência sobre o padrão embutido "
        . "(rotação emergencial sem deploy). Esperado 'abcdefghijkl', obtido: '{$saidaPrecedencia}'\n";
    exit(1);
}

// CENÁRIO B10: o stub de adoptCB roda ANTES do GTM.
// A AdOpt captura window.adoptCB uma única vez, na montagem, com `??`. Se ela montar antes
// deste módulo registrar o global, guarda um no-op em definitivo e o consentimento nunca
// chega ao avaliador. O GTM (que injeta a AdOpt) entra em wp_head; o stub tem que vir antes.
if (!preg_match('/add_action\(\s*[\'"]wp_head[\'"]\s*,\s*[\'"]uonix_autofill_registra_adopt_cb_antecipado[\'"]\s*,\s*(\d+)/', $globalAutofill, $mStub)) {
    echo "ERRO: o stub antecipado de adoptCB não está registrado em wp_head com prioridade explícita.\n";
    exit(1);
}
$prioridadeStub = (int) $mStub[1];

$analyticsLgpd = file_get_contents($rootDir . '/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php');
if (!preg_match('/add_action\(\s*[\'"]wp_head[\'"]\s*,\s*[\'"]uonix_render_analytics_head[\'"]\s*,\s*(\d+)/', $analyticsLgpd, $mGtm)) {
    echo "ERRO: não foi possível ler a prioridade de wp_head de uonix_render_analytics_head "
        . "(injeção do GTM). A asserção de ordem não pode ser verificada.\n";
    exit(1);
}
$prioridadeGtm = (int) $mGtm[1];

if ($prioridadeStub >= $prioridadeGtm) {
    echo "ERRO DE ORDEM: o stub de adoptCB está na prioridade {$prioridadeStub} e o GTM na "
        . "{$prioridadeGtm}. A AdOpt pode montar antes do stub e capturar um no-op, deixando o "
        . "autopreenchimento morto de forma intermitente.\n";
    exit(1);
}

if (strpos($globalAutofill, '__uonixAvaliaConsentAdopt') === false
    || strpos($globalAutofill, '__uonixAdoptConsentPendente') === false) {
    echo "ERRO: o rodapé precisa publicar __uonixAvaliaConsentAdopt e consumir "
        . "__uonixAdoptConsentPendente, senão o stub antecipado guarda o consentimento e ninguém lê.\n";
    exit(1);
}

// ==============================================================================
// 3. VALIDAÇÃO COMPORTAMENTAL DO FRONTEND VIA NODE.JS (SIMULAÇÃO DOM & ADOPT)
// ==============================================================================

// Extrai o conteúdo do script JS de 49-forms-global-autofill.php.
// Alvo explícito pelo id: o arquivo tem DOIS blocos <script> (o stub antecipado de wp_head
// e este), e uma regex de primeira ocorrência pegaria o bloco errado silenciosamente.
preg_match('/<script id="uonix-global-autofill-js"[^>]*>(.*?)<\/script>/s', $globalAutofill, $jsMatch);
if (empty($jsMatch[1])) {
    echo "ERRO: Bloco JavaScript 'uonix-global-autofill-js' não encontrado em 49-forms-global-autofill.php\n";
    exit(1);
}

$jsCode = $jsMatch[1];
$jsCode = preg_replace('/<\?php.*?\?>/s', '(typeof __TEST_ALLOWED_TAG_IDS__ !== "undefined" ? __TEST_ALLOWED_TAG_IDS__ : ["6332f834-41df-4cc5-a3bf-dffe359112c5"])', $jsCode);
$encodedJs = json_encode($jsCode);

$nodeTestScript = <<<NODE_JS
const vm = require('vm');
const assert = require('assert');
const CODE_PAYLOAD = $encodedJs;
const FIXTURE_TAG_ID = '6332f834-41df-4cc5-a3bf-dffe359112c5';

function runTestEnvironment(initialCookie = '', initialStorage = {}, allowedTagIds = [FIXTURE_TAG_ID]) {
    let cookieStr = initialCookie;
    const storage = Object.assign({}, initialStorage);
    const eventListeners = {};

    function HTMLFormElement() {}
    const commentFormChildren = [];
    const commentForm = Object.create(HTMLFormElement.prototype);
    commentForm.id = 'commentform';
    commentForm.classList = { contains: (c) => c === 'comment-form' };
    commentForm.children = commentFormChildren;
    commentForm.appendChild = function(el) { commentFormChildren.push(el); };
    commentForm.querySelector = function(sel) {
        if (sel.includes('wp-comment-cookies-consent')) {
            return commentFormChildren.find(c => c.name === 'wp-comment-cookies-consent') || null;
        }
        return null;
    };

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
                cookieStr = cookieStr.split(';').filter(c => !c.trim().startsWith(k + '=')).join('; ');
                cookieStr = (cookieStr ? cookieStr + '; ' : '') + k + '=' + v;
            }
        },
        querySelectorAll: function(sel) {
            if (sel.includes('wp-comment-cookies-consent')) {
                return commentFormChildren.filter(c => c.name === 'wp-comment-cookies-consent');
            }
            return [];
        },
        querySelector: function(sel) {
            if (sel === '#commentform') return commentForm;
            return null;
        },
        createElement: function(tag) {
            return { tagName: tag.toUpperCase(), type: '', name: '', value: '' };
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
    win.HTMLFormElement = HTMLFormElement;
    win.__TEST_ALLOWED_TAG_IDS__ = allowedTagIds;

    const context = vm.createContext(win);
    vm.runInContext(CODE_PAYLOAD, context);

    function triggerSubmit(form) {
        const handlers = eventListeners['submit'] || [];
        handlers.forEach(h => h({ target: form, preventDefault: () => {} }));
    }

    return { win, doc, storage, eventListeners, commentForm, triggerSubmit };
}

// F1: Fixture com UUID realista configurado como ID autorizado concede consentimento
{
    const { win, doc, commentForm, triggerSubmit } = runTestEnvironment('', {});
    assert.strictEqual(typeof win.adoptCB, 'function', 'F1: window.adoptCB deve ser uma função registrada');

    win.adoptCB({ optInTags: [FIXTURE_TAG_ID], optOutTags: [] });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), true, 'F1a: isAdoptConsentGranted deve ser true para UUID autorizado em optInTags');
    assert.notStrictEqual(doc.cookie.indexOf('uonix_consent_granted=1'), -1, 'F1b: uonix_consent_granted DEVE ser gravado');

    triggerSubmit(commentForm);
    const consentInput = commentForm.querySelector('input[name="wp-comment-cookies-consent"]');
    assert.notStrictEqual(consentInput, null, 'F1c: wp-comment-cookies-consent DEVE ser injetado no commentform');
    assert.strictEqual(consentInput.value, 'yes', 'F1d: valor do consentimento deve ser yes');
}

// F2: UUID desconhecido em optInTags nega consentimento (fail-closed)
{
    const { win, doc, commentForm, triggerSubmit } = runTestEnvironment('', {});
    win.adoptCB({ optInTags: ['b9a1e041-0000-4000-8000-000000000000'], optOutTags: [] });

    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F2a: UUID desconhecido NÃO deve autorizar');
    assert.strictEqual(doc.cookie.indexOf('uonix_consent_granted=1'), -1, 'F2b: uonix_consent_granted NÃO deve ser emitido');

    triggerSubmit(commentForm);
    assert.strictEqual(commentForm.querySelector('input[name="wp-comment-cookies-consent"]'), null, 'F2c: wp-comment-cookies-consent NÃO deve ser injetado');
}

// F3: Defaults textuais genéricos em optInTags (ex: 'funcional') NÃO são tratados como IDs e são negados
{
    const { win, doc, commentForm, triggerSubmit } = runTestEnvironment('', {});
    win.adoptCB({ optInTags: ['funcional', 'preferences'], optOutTags: [] });

    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F3a: Defaults textuais NÃO são IDs válidos e devem ser negados');
    triggerSubmit(commentForm);
    assert.strictEqual(commentForm.querySelector('input[name="wp-comment-cookies-consent"]'), null, 'F3b: Sem injeção de consentimento');
}

// F4: Ambiente sem IDs autorizados configurados (ALLOWED_TAG_IDS = []) opera estritamente fail-closed
{
    const { win, doc } = runTestEnvironment('', {}, []);
    win.adoptCB({ optInTags: [FIXTURE_TAG_ID], optOutTags: [] });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F4: Sem IDs configurados no ambiente deve sempre negar');
}

// F5: Opt-Out no UUID autorizado expurga dados salvos e cookies
{
    const { win, doc, storage } = runTestEnvironment('uonix_consent_granted=1', { 'uonix_user_lead': '{"nome":"Teste"}' });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), true, 'F5a: Inicialmente autorizado');

    win.adoptCB({ optInTags: [], optOutTags: [FIXTURE_TAG_ID] });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F5b: Após opt-out, isAdoptConsentGranted deve ser false');
    assert.strictEqual(storage['uonix_user_lead'], undefined, 'F5c: Dados de lead devem ser apagados');
    assert.strictEqual(doc.cookie.indexOf('uonix_consent_granted='), -1, 'F5d: Cookie uonix_consent_granted deve ser excluído');
    assert.notStrictEqual(doc.cookie.indexOf('_adoptReject=1'), -1, 'F5e: _adoptReject deve ser gravado');
}

// F6: Mera presença de AdoptConsent em cookie ou storage NÃO autoriza
{
    const { win } = runTestEnvironment('AdoptConsent={"choices":"all"}', { 'AdoptConsent': 'true' });
    assert.strictEqual(win.uonixIsAdoptConsentGranted(), false, 'F6: AdoptConsent isolado NÃO deve autorizar persistência');
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

echo "SUCESSO: Todos os testes comportamentais de backend (fail-closed) e frontend (DOM/AdOpt com UUIDs reais) passaram com 100% de precisão!\n";
exit(0);
