<?php
/**
 * Contrato: o checkout da cotação precisa enviar o país de cobrança.
 *
 * O WooCommerce 11.3.1 recusa o pedido sem país ("This store cannot accept your
 * order without a billing country."). A loja só vende para o Brasil, então o
 * WooCommerce oculta o campo e o Woo Checkout Field Editor Pro o mantém
 * desabilitado: nenhum país seria enviado e todo pedido de orçamento falharia.
 * O filtro thwcfd_preserve_hidden_country_field faz o plugin devolver o país
 * base como campo oculto, semeia o cliente e carimba o pedido.
 */
declare(strict_types=1);

$root     = dirname(__DIR__, 2);
$module   = $root . '/mu-plugins/uonix-woocommerce/34-checkout-pais-oculto.php';
$failures = array();

function uonix_test_fail(string $message): void {
    global $failures;
    $failures[] = $message;
}

define('ABSPATH', '/tmp/uonix-wp/');

$GLOBALS['uonix_filters'] = array();

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    // Como o WordPress: o mesmo callback na mesma prioridade não se repete.
    foreach ($GLOBALS['uonix_filters'][$hook] ?? array() as $registrado) {
        if ($registrado[0] === $callback && $registrado[1] === (int) $priority) {
            return true;
        }
    }
    $GLOBALS['uonix_filters'][$hook][] = array($callback, (int) $priority, (int) $accepted_args);
    return true;
}

function apply_filters($hook, $value, ...$args) {
    $callbacks = $GLOBALS['uonix_filters'][$hook] ?? array();
    usort($callbacks, static fn($a, $b) => $a[1] <=> $b[1]);
    foreach ($callbacks as [$callback]) {
        $value = $callback($value, ...$args);
    }
    return $value;
}

function __return_true() {
    return true;
}

function __return_false() {
    return false;
}

if (!is_file($module)) {
    uonix_test_fail('arquivo do módulo não existe: mu-plugins/uonix-woocommerce/34-checkout-pais-oculto.php');
} else {
    // Sem o módulo carregado o padrão do plugin é false: é o defeito.
    if (apply_filters('thwcfd_preserve_hidden_country_field', false) !== false) {
        uonix_test_fail('pré-condição: sem o módulo o filtro deveria seguir false');
    }

    require $module;

    if (apply_filters('thwcfd_preserve_hidden_country_field', false) !== true) {
        uonix_test_fail('o módulo deveria ligar thwcfd_preserve_hidden_country_field');
    }

    // Só liga o que é necessário: nenhum outro filtro do plugin é tocado.
    $outros = array_diff(array_keys($GLOBALS['uonix_filters']), array('thwcfd_preserve_hidden_country_field'));
    if ($outros !== array()) {
        uonix_test_fail('o módulo registrou filtros além do necessário: ' . implode(', ', $outros));
    }

    // Recarregar não pode duplicar o registro.
    $antes = count($GLOBALS['uonix_filters']['thwcfd_preserve_hidden_country_field'] ?? array());
    require $module;
    if (count($GLOBALS['uonix_filters']['thwcfd_preserve_hidden_country_field'] ?? array()) !== $antes) {
        uonix_test_fail('carregar duas vezes não pode duplicar o filtro');
    }
}

// O módulo precisa estar na lista de carga do WooCommerce.
require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
$carregados = uox_loader_arquivos_do_modulo($root . '/mu-plugins/uonix-woocommerce/module.php');
if (!in_array('34-checkout-pais-oculto.php', $carregados, true)) {
    uonix_test_fail('módulo não está registrado em mu-plugins/uonix-woocommerce/module.php');
}

// O plugin que oferece o filtro precisa ter contrato, para o ensaio avisar se uma versão o remover.
$contratos = json_decode((string) file_get_contents($root . '/ops/plugins/contratos.json'), true)['contratos'] ?? array();
$marcadores = $contratos['woo-checkout-field-editor-pro']['marcadores'] ?? array();
if (!in_array('thwcfd_preserve_hidden_country_field', $marcadores, true)) {
    uonix_test_fail('contratos.json não protege thwcfd_preserve_hidden_country_field do woo-checkout-field-editor-pro');
}

if ($failures !== array()) {
    fwrite(STDERR, "FALHOU:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK: país de cobrança oculto, carga e contrato\n";
