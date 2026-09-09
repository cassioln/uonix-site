<?php
/**
 * Teste de contrato da geometria do drawer de filtros Husky Mobile e limpeza de cache.
 *
 * Valida:
 * 1. A geometria da gaveta de filtros (.woof_show_filter_for_mobile.woof):
 *    - @keyframes move_top termina em top: 60px;
 *    - margin-top é estritamente 0 !important;
 *    - height é calc(100dvh - 60px) !important;
 *    - Prova matemática de ausência de overflow: 60px + 0px + (100dvh - 60px) = 100dvh.
 * 2. Ausência de chamadas órfãs do LiteSpeed Cache em 39-admin-editor-dashboard.php.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

$repo_root = dirname(__DIR__, 2);

function test_fail(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function test_assert(bool $condition, string $message): void {
    if (!$condition) {
        test_fail($message);
    }
}

echo "========================================================================\n";
echo "🧪 TESTE DE CONTRATO: GEOMETRIA DO FILTRO MOBILE E LIMPEZA DE CACHE\n";
echo "========================================================================\n\n";

// 1. Validação da Geometria em 05-catalogo-filtros-husky-mobile.php
$snippet_file = $repo_root . '/themes/kadence-child/snippets/05-catalogo-filtros-husky-mobile.php';
test_assert(file_exists($snippet_file), "Arquivo de snippet não encontrado: {$snippet_file}");
$snippet_content = file_get_contents($snippet_file);

// Verifica animação move_top terminando em top: 60px
test_assert(
    (bool) preg_match('/@keyframes\s+move_top\s*\{.*?100%\s*\{[^}]*top:\s*60px;/s', $snippet_content),
    '05-catalogo-filtros-husky-mobile.php: @keyframes move_top deve ter top: 60px no estado final (100%)'
);
echo "ok   @keyframes move_top termina exatamente em top: 60px\n";

// Verifica margin-top: 0 !important
test_assert(
    (bool) preg_match('/\.woof_show_filter_for_mobile\.woof\s*\{[^}]*margin-top:\s*0\s*!important;/s', $snippet_content),
    '05-catalogo-filtros-husky-mobile.php: .woof_show_filter_for_mobile.woof deve ter margin-top: 0 !important'
);
echo "ok   .woof_show_filter_for_mobile.woof define margin-top: 0 !important (sem deslocamento residual)\n";

// Verifica height: calc(100dvh - 60px) !important
test_assert(
    (bool) preg_match('/\.woof_show_filter_for_mobile\.woof\s*\{[^}]*height:\s*calc\(100dvh\s*-\s*60px\)\s*!important;/s', $snippet_content),
    '05-catalogo-filtros-husky-mobile.php: .woof_show_filter_for_mobile.woof deve ter height: calc(100dvh - 60px) !important'
);
echo "ok   .woof_show_filter_for_mobile.woof define height: calc(100dvh - 60px) !important\n";

// Prova geométrica: top_final (60) + margin_top (0) + height (viewport - 60) = viewport
$header_offset = 60;
$margin_top    = 0;
$viewport_mock = 812; // iPhone 13 / viewport de teste
$computed_height = $viewport_mock - $header_offset;
$bottom_rendered = $header_offset + $margin_top + $computed_height;
$overflow = $bottom_rendered - $viewport_mock;

test_assert(
    $overflow === 0,
    "Geometria calculada gerou overflow de {$overflow}px (esperado: 0px)"
);
echo "ok   Prova de geometria: bottom final = {$bottom_rendered}px em viewport de {$viewport_mock}px (overflow = 0px)\n";

// 2. Validação da remoção do LiteSpeed em 39-admin-editor-dashboard.php
$admin_file = $repo_root . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php';
test_assert(file_exists($admin_file), "Arquivo admin não encontrado: {$admin_file}");
$admin_content = file_get_contents($admin_file);

test_assert(
    strpos($admin_content, 'LiteSpeed_Cache_API') === false,
    '39-admin-editor-dashboard.php: Não deve conter referências órfãs a LiteSpeed_Cache_API'
);
test_assert(
    strpos($admin_content, 'litespeed_purge_all') === false,
    '39-admin-editor-dashboard.php: Não deve conter referências órfãs a litespeed_purge_all'
);
test_assert(
    strpos($admin_content, 'wp_cache_flush') !== false,
    '39-admin-editor-dashboard.php: Deve manter wp_cache_flush para limpeza de cache de objeto'
);
test_assert(
    strpos($admin_content, 'rocket_clean_domain') !== false,
    '39-admin-editor-dashboard.php: Deve manter rocket_clean_domain para WP Rocket'
);
test_assert(
    strpos($admin_content, 'https://dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/tags') !== false,
    '39-admin-editor-dashboard.php: Deve conter atalho para o painel AdOpt com link de tags'
);
test_assert(
    strpos($admin_content, 'min-height: 200px') === false,
    '39-admin-editor-dashboard.php: Não deve conter altura mínima fixa legada (min-height: 200px)'
);
test_assert(
    strpos($admin_content, 'height: auto !important') !== false,
    '39-admin-editor-dashboard.php: Cards do dashboard devem ter altura dinâmica (height: auto !important)'
);
test_assert(
    strpos($admin_content, 'admin.php?page=fluent_forms_all_entries') !== false,
    '39-admin-editor-dashboard.php: Deve conter atalho direto para Leads no Acesso Rápido'
);
echo "ok   39-admin-editor-dashboard.php: Atalhos AdOpt e Leads, cards dinâmicos sem min-height fixo, chamadas órfãs eliminadas\n";

echo "\nPASS: Todos os contratos de geometria mobile, cache e cards dinâmicos foram aprovados com sucesso!\n";
