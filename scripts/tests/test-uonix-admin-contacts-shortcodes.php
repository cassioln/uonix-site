<?php
/**
 * Contrato do Painel de Controle Uônix: contatos responsáveis e shortcodes de link.
 *
 * O teste carrega o mu-plugin real com stubs somente das APIs mínimas do WordPress,
 * verifica os defaults vazios dos nove campos e exerce os callbacks dos shortcodes.
 */

$raiz = dirname(__DIR__, 2);
$alvo = $raiz . '/mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php';

function uonix_contacts_fail($message) {
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

function uonix_contacts_assert($condition, $message) {
    if (!$condition) {
        uonix_contacts_fail($message);
    }
}

uonix_contacts_assert(is_readable($alvo), "mu-plugin não legível: {$alvo}");
$fonte = file_get_contents($alvo);

$campos_responsaveis = array(
    'marketing',
    'lgpd',
    'administrativo_1',
    'administrativo_2',
    'vendedor_1',
    'vendedor_2',
    'vendedor_3',
    'engenheiro_1',
    'engenheiro_2',
);

uonix_contacts_assert(
    strpos($fonte, "'contatos-responsaveis'") !== false,
    'a aba contatos-responsaveis não está declarada.'
);

foreach ($campos_responsaveis as $campo) {
    $padrao = "/'" . preg_quote($campo, '/') . "'\\s*=>\\s*\\['label'[^\\]]*'default'\\s*=>\\s*''/";
    uonix_contacts_assert(
        preg_match($padrao, $fonte) === 1,
        "o campo {$campo} não está configurado com default vazio."
    );
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

$uox_options = array(
    'uox_telefone_2' => '(11) 99999-0000',
    'uox_whatsapp_1' => '+55 (11) 98888-7777',
    'uox_marketing' => 'Pessoa Marketing',
);
$uox_shortcodes = array();

function add_action($tag, $callback) {}
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
function add_shortcode($tag, $callback) {
    global $uox_shortcodes;
    $uox_shortcodes[$tag] = $callback;
}
function get_option($name, $default = false) {
    global $uox_options;
    return array_key_exists($name, $uox_options) ? $uox_options[$name] : $default;
}
function update_option($name, $value) {
    global $uox_options;
    $uox_options[$name] = $value;
    return true;
}
function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}
function esc_attr($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require $alvo;

$aliases = array(
    'uonix_telefone_1_link',
    'uonix_telefone_2_link',
    'uonix_whatsapp_1_link',
    'uonix_whatsapp_2_link',
    'uonix_whatsapp_3_link',
);

foreach ($aliases as $alias) {
    uonix_contacts_assert(isset($uox_shortcodes[$alias]), "alias {$alias} não foi registrado.");
}

$telefone = call_user_func(
    $uox_shortcodes['uonix_telefone_2_link'],
    array(),
    '',
    'uonix_telefone_2_link'
);
uonix_contacts_assert($telefone === '11999990000', 'alias de telefone não normaliza os dígitos esperados.');

$whatsapp = call_user_func(
    $uox_shortcodes['uonix_whatsapp_1_link'],
    array(),
    '',
    'uonix_whatsapp_1_link'
);
uonix_contacts_assert($whatsapp === '5511988887777', 'alias de WhatsApp não normaliza os dígitos esperados.');

$contato = uox_render_shortcode_simples(array('marketing'));
uonix_contacts_assert($contato === 'Pessoa Marketing', 'shortcode genérico do contato não retorna o valor configurado.');

$legado = uox_render_shortcode_simples(array('telefone_2', 'link'));
uonix_contacts_assert($legado === '11999990000', 'shortcode legado com espaços deixou de funcionar.');

$uox_options['uox_telefone_2'] = '';
$vazio = call_user_func(
    $uox_shortcodes['uonix_telefone_2_link'],
    array(),
    '',
    'uonix_telefone_2_link'
);
uonix_contacts_assert($vazio === '', 'alias vazio deveria retornar string vazia.');

printf(
    "PASS: aba com %d campos vazios, %d aliases sem espaços, shortcode legado e campo vazio validados.\n",
    count($campos_responsaveis),
    count($aliases)
);
