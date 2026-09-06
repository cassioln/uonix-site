<?php
/**
 * Teste de contrato da sincronização e estilização do badge de itens do carrinho (Desktop e Mobile).
 *
 * Valida:
 * 1. Em 13-carrinho-badge-autoopen.php:
 *    - Sincronização do badge desktop (.uonix-cart-badge) e mobile (.uonix-menu-cart-badge).
 *    - Criação defensiva de <span class="uonix-menu-cart-badge"> caso não exista no DOM.
 *    - Controle de visibilidade (.is-active / display: flex se > 0, remoção / display: none se <= 0).
 *    - Presença de MutationObserver para reatividade imediata a alterações de quantidade.
 *    - Escuta a eventos AJAX do WooCommerce (added_to_cart, removed_from_cart, etc.).
 * 2. Em 15-carrinho-mini-cart-sidebar.php:
 *    - Regras CSS do badge mobile (.uonix-menu-cart-badge): cor de fundo #f76a0c, raio 50%,
 *      sombra box-shadow, clique passante (pointer-events: none) e ativação via .is-active.
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
echo "🧪 TESTE DE CONTRATO: SINCRONIZAÇÃO E ESTILO DO BADGE (DESKTOP & MOBILE)\n";
echo "========================================================================\n\n";

// 1. Validação em mu-plugins/uonix-woocommerce/13-carrinho-badge-autoopen.php
$autoopen_file = $repo_root . '/mu-plugins/uonix-woocommerce/13-carrinho-badge-autoopen.php';
test_assert(file_exists($autoopen_file), "Arquivo não encontrado: {$autoopen_file}");
$autoopen_content = file_get_contents($autoopen_file);

// Desktop badge
test_assert(
    (bool) preg_match('/#mega-menu-item-4819\s+a\.mega-menu-link/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve selecionar o link do carrinho desktop'
);
test_assert(
    (bool) preg_match('/\.uonix-cart-badge/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve gerenciar a classe .uonix-cart-badge'
);

// Mobile badge
test_assert(
    (bool) preg_match('/\.uonix-menu-cart/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve selecionar o container .uonix-menu-cart no mobile'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve selecionar e atualizar .uonix-menu-cart-badge no mobile'
);
test_assert(
    (bool) preg_match('/addClass\([\'"]is-active[\'"]\)/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve adicionar classe is-active quando houver itens'
);
test_assert(
    (bool) preg_match('/removeClass\([\'"]is-active[\'"]\)/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve remover classe is-active quando quantidade for zero'
);

// MutationObserver e Eventos AJAX
test_assert(
    (bool) preg_match('/MutationObserver/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve registrar MutationObserver para reatividade imediata'
);
test_assert(
    (bool) preg_match('/added_to_cart\s+removed_from_cart\s+wc_fragments_refreshed/', $autoopen_content),
    '13-carrinho-badge-autoopen.php: Deve escutar eventos AJAX do WooCommerce'
);

echo "ok   13-carrinho-badge-autoopen.php: Sincronização desktop e mobile, MutationObserver e eventos validados\n";

// 2. Validação em mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php
$sidebar_file = $repo_root . '/mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php';
test_assert(file_exists($sidebar_file), "Arquivo não encontrado: {$sidebar_file}");
$sidebar_content = file_get_contents($sidebar_file);

test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*background-color:\s*#f76a0c\s*!important/s', $sidebar_content),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter fundo laranja #f76a0c !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*border-radius:\s*50%\s*!important/s', $sidebar_content),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter border-radius: 50% !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*box-shadow:\s*0\s+2px\s+5px\s+rgba\(0,\s*0,\s*0,\s*0\.3\)\s*!important/s', $sidebar_content),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter box-shadow condizente com o desktop'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*pointer-events:\s*none\s*!important/s', $sidebar_content),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter pointer-events: none !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\.is-active\s*\{[^}]*display:\s*flex\s*!important/s', $sidebar_content),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge.is-active deve ter display: flex !important'
);

echo "ok   15-carrinho-mini-cart-sidebar.php: Estilos do badge mobile (geometria, cores, sombra e estados) validados\n";

echo "\nPASS: Todos os contratos de sincronização e exibição dos badges do carrinho foram aprovados com sucesso!\n";
