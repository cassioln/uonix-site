<?php
/**
 * Contrato do indicador de submenu do menu mobile.
 *
 * O ícone precisa ser geometricamente independente do line-height do link,
 * carregar em todas as páginas públicas e não depender do CSS crítico da home.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module_path = $root . '/mu-plugins/uonix-navigation/49-mobile-menu-indicator.php';
$loader_path = $root . '/mu-plugins/uonix-navigation/module.php';
$workflow_path = $root . '/.github/workflows/validate.yml';
$failures = 0;

function mobile_indicator_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

mobile_indicator_assert(is_file($module_path), 'Módulo global do indicador mobile existe');
$module = is_file($module_path) ? (string) file_get_contents($module_path) : '';
$loader = is_file($loader_path) ? (string) file_get_contents($loader_path) : '';
$workflow = is_file($workflow_path) ? (string) file_get_contents($workflow_path) : '';

mobile_indicator_assert(
    false !== strpos($loader, "'49-mobile-menu-indicator.php'"),
    'Loader de navegação registra o módulo do indicador mobile'
);
mobile_indicator_assert(
    false !== strpos($workflow, 'php scripts/tests/test-mobile-menu-indicator.php'),
    'Workflow executa o teste do indicador mobile'
);
mobile_indicator_assert(
    false !== strpos($module, "add_action( 'wp_head', 'uonix_mobile_menu_indicator_styles', 100 );"),
    'Estilos globais do indicador carregam após os estilos do Mega Menu'
);
mobile_indicator_assert(
    false === strpos($module, 'uonix_is_public_front_page'),
    'Módulo do indicador não fica restrito à home'
);

$link_selector = '#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children > a.mega-menu-link';
$indicator_selector = $link_selector . ' > span.mega-indicator';
$after_selector = $indicator_selector . '::after';
$open_selector = '#mega-menu-wrap-mobile #mega-menu-mobile li.mega-menu-item-has-children.mega-toggle-on > a.mega-menu-link > span.mega-indicator::after';

mobile_indicator_assert(false !== strpos($module, $link_selector), 'CSS seleciona exatamente o link mobile com submenu');
mobile_indicator_assert(false !== strpos($module, $indicator_selector), 'CSS seleciona exatamente o indicador do link mobile');
mobile_indicator_assert(false !== strpos($module, $after_selector), 'CSS seleciona o pseudo-elemento do chevron');
mobile_indicator_assert(false !== strpos($module, $open_selector), 'CSS gira o chevron no submenu aberto');

mobile_indicator_assert(
    (bool) preg_match('/a\.mega-menu-link\s*\{[^}]*position:\s*relative\s*!important;[^}]*padding-right:\s*60px\s*!important;/s', $module),
    'Link reserva espaço e vira a referência geométrica do indicador'
);
mobile_indicator_assert(
    (bool) preg_match('/span\.mega-indicator\s*\{[^}]*display:\s*inline-flex\s*!important;[^}]*position:\s*absolute\s*!important;[^}]*top:\s*50%\s*!important;[^}]*right:\s*10px\s*!important;[^}]*width:\s*40px\s*!important;[^}]*height:\s*40px\s*!important;[^}]*line-height:\s*1\s*!important;[^}]*font-size:\s*0\s*!important;[^}]*transform:\s*translateY\(-50%\)\s*!important;/s', $module),
    'Indicador centraliza por geometria absoluta e neutraliza herança tipográfica'
);
mobile_indicator_assert(
    (bool) preg_match('/span\.mega-indicator::after\s*\{[^}]*content:\s*[\'\"]{2}\s*!important;[^}]*display:\s*block\s*!important;[^}]*width:\s*8px\s*!important;[^}]*height:\s*8px\s*!important;[^}]*border-style:\s*solid\s*!important;[^}]*border-width:\s*0\s+2px\s+2px\s+0\s*!important;[^}]*font-size:\s*0\s*!important;[^}]*line-height:\s*1\s*!important;[^}]*margin:\s*0\s*!important;[^}]*transform:\s*rotate\(45deg\)\s*!important;/s', $module),
    'Chevron não herda glifo Dashicons, margem ou line-height'
);
mobile_indicator_assert(
    (bool) preg_match('/mega-toggle-on[^\{]*::after\s*\{[^}]*transform:\s*rotate\(-135deg\)\s*!important;/s', $module),
    'Chevron muda de direção no estado aberto'
);

if ($failures > 0) {
    exit(1);
}

echo "PASS: indicador mobile é global, geometricamente centralizado e rotaciona no estado aberto.\n";
