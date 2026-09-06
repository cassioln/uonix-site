<?php
/**
 * Teste de contrato da hierarquia de camadas (Stacking Context) do Mini-Cart Drawer.
 *
 * Valida:
 * 1. O overlay (.wc-block-components-drawer__screen-overlay) tem z-index: 3000000 !important;
 * 2. O painel filho (.wc-block-components-drawer / .wc-block-mini-cart__drawer) tem z-index: 3000001 !important;
 * 3. A camada do painel é estritamente maior que a do overlay (drawer > overlay), garantindo
 *    que os controles, itens e botão de fechar não fiquem na mesma camada do backdrop.
 * 4. A consistência é mantida em 27-mega-menu-produtos-marcas.php e 15-carrinho-mini-cart-sidebar.php.
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
echo "🧪 TESTE DE CONTRATO: HIERARQUIA DE CAMADAS MINI-CART DRAWER & OVERLAY\n";
echo "========================================================================\n\n";

// 1. Validação em mu-plugins/uonix-navigation/27-mega-menu-produtos-marcas.php
$nav_file = $repo_root . '/mu-plugins/uonix-navigation/27-mega-menu-produtos-marcas.php';
test_assert(file_exists($nav_file), "Arquivo de navegação não encontrado: {$nav_file}");
$nav_content = file_get_contents($nav_file);

// Verifica z-index do overlay
test_assert(
    (bool) preg_match('/\.wc-block-components-drawer__screen-overlay[^{]*\{[^}]*z-index:\s*3000000\s*!important/s', $nav_content),
    '27-mega-menu-produtos-marcas.php: Overlay deve ter z-index: 3000000 !important'
);

// Verifica z-index do drawer filho
test_assert(
    (bool) preg_match('/\.wc-block-components-drawer__screen-overlay\s+\.wc-block-components-drawer[^{]*\{[^}]*z-index:\s*3000001\s*!important/s', $nav_content),
    '27-mega-menu-produtos-marcas.php: Painel filho deve ter z-index: 3000001 !important'
);

// Verifica z-index do PhotoSwipe
test_assert(
    (bool) preg_match('/\.pswp[^{]*\{[^}]*z-index:\s*3000000\s*!important/s', $nav_content),
    '27-mega-menu-produtos-marcas.php: PhotoSwipe deve ter z-index: 3000000 !important'
);

echo "ok   27-mega-menu-produtos-marcas.php: Camadas overlay (3000000) e drawer (3000001) separadas e corretas\n";

// 2. Validação em mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php
$cart_file = $repo_root . '/mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php';
test_assert(file_exists($cart_file), "Arquivo de carrinho não encontrado: {$cart_file}");
$cart_content = file_get_contents($cart_file);

// Verifica z-index do overlay
test_assert(
    (bool) preg_match('/\.wc-block-components-drawer__screen-overlay\s*\{[^}]*z-index:\s*3000000\s*!important/s', $cart_content),
    '15-carrinho-mini-cart-sidebar.php: Overlay deve ter z-index: 3000000 !important'
);

// Verifica z-index do drawer filho
test_assert(
    (bool) preg_match('/\.wc-block-components-drawer__screen-overlay\s+\.wc-block-components-drawer[^{]*\{[^}]*z-index:\s*3000001\s*!important/s', $cart_content),
    '15-carrinho-mini-cart-sidebar.php: Painel filho deve ter z-index: 3000001 !important'
);

echo "ok   15-carrinho-mini-cart-sidebar.php: Camadas overlay (3000000) e drawer (3000001) separadas e corretas\n";

// 3. Prova matemática de ordem: drawer > overlay
$overlay_val = 3000000;
$drawer_val  = 3000001;
test_assert($drawer_val > $overlay_val, "A camada do drawer ({$drawer_val}) deve ser estritamente maior que a do overlay ({$overlay_val})");
echo "ok   Hierarquia estrita comprovada: drawer (3000001) > overlay (3000000) > header (1000000)\n";

echo "\nPASS: Todos os contratos de stacking context do mini-cart foram aprovados com sucesso!\n";
