<?php
/**
 * Test: Validação da persistência global de formulários e integração de comentários/AdOpt com LGPD.
 */

$rootDir = dirname(__DIR__, 2);

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

if (strpos($comentariosMaster, "_adoptReject") === false) {
    echo "ERRO: Verificação de AdOpt não encontrada em 10-comentarios-master.php\n";
    exit(1);
}

$globalAutofill = file_get_contents($rootDir . '/mu-plugins/uonix-forms/49-forms-global-autofill.php');
$requiredSelectors = [
    // Contato
    'form[data-form_id="3"] input[name="form_nome"]',
    'form[data-form_id="3"] input[name="form_empresa"]',
    'form[data-form_id="3"] input[name="form_email"]',
    'form[data-form_id="3"] input[name="form_telefone"]',
    // Checklist Lead
    '#ucf_nome',
    '#ucf_empresa',
    '#ucf_email',
    '#ucf_telefone',
    // Trabalhe Conosco
    '#trab_nome',
    '#trab_email',
    '#trab_tel',
    // Comentários
    '#author',
    '#company',
    '#email',
    // WooCommerce Orçamento
    '#billing_complete_name',
    '#billing_company',
    '#billing_email',
    '#billing_phone',
    '#billing_city',
    '#billing_state',
    // AdOpt e Storage
    '_adoptReject',
    'uonix_user_lead',
    'uonix_lead_profile',
];

foreach ($requiredSelectors as $selector) {
    if (strpos($globalAutofill, $selector) === false) {
        echo "ERRO: Seletor/Chave '{$selector}' não encontrado em 49-forms-global-autofill.php\n";
        exit(1);
    }
}

// Validação de Minimização LGPD: campos sensíveis/fiscais NÃO devem ser persistidos
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

echo "SUCESSO: Todos os requisitos de persistência global, comentários, checkout WooCommerce e conformidade LGPD foram validados com êxito!\n";
exit(0);
