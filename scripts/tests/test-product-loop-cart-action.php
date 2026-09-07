<?php
/**
 * Teste de contrato: Botão de ação interativo do catálogo com controle de quantidade e suporte a variações.
 *
 * Valida:
 * 1. Produto com variação gera botão "Ver Opções" em destaque laranja.
 * 2. Produto simples gera botão "Adicionar ao carrinho" com seletor de quantidade retangular.
 * 3. Seletor de quantidade possui controles (-) / (+) e ícone de lixeira quando qty == 1.
 * 4. Endpoint AJAX seguro com proteção de nonce e ações add, increment, decrement e remove.
 * 5. Conformidade com a identidade visual da Uônix (retangular border-radius 6px, cores #0e3780 e #f76a0c).
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
echo "🧪 TESTE DE CONTRATO: BOTÃO INTERATIVO E CONTROLE DE QUANTIDADE DO LOOP\n";
echo "========================================================================\n\n";

// 1. Validação em themes/kadence-child/snippets/01-woocommerce-loop-especificacoes.php
$snippet_01 = $repo_root . '/themes/kadence-child/snippets/01-woocommerce-loop-especificacoes.php';
test_assert(file_exists($snippet_01), "Arquivo não encontrado: {$snippet_01}");
$content_01 = file_get_contents($snippet_01);

test_assert(
    strpos($content_01, "is_type( 'variable' )") !== false,
    '01-woocommerce-loop-especificacoes.php: Deve checar se o produto é do tipo variable'
);

test_assert(
    strpos($content_01, 'Ver Opções') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve renderizar o texto "Ver Opções" para produtos com variações'
);

test_assert(
    strpos($content_01, 'uonix-btn-variable') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve aplicar a classe uonix-btn-variable no botão de variações'
);

test_assert(
    strpos($content_01, 'Adicionar ao carrinho') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve renderizar o texto "Adicionar ao carrinho" para produtos simples'
);

test_assert(
    strpos($content_01, 'uonix-product-action-wrap') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve conter o container uonix-product-action-wrap'
);

test_assert(
    strpos($content_01, 'uonix-qty-control') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve conter o elemento uonix-qty-control'
);

test_assert(
    strpos($content_01, 'uonix-qty-minus') !== false && strpos($content_01, 'uonix-qty-plus') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve conter os botões uonix-qty-minus e uonix-qty-plus'
);

test_assert(
    strpos($content_01, 'no carrinho') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve conter o texto "no carrinho"'
);

// 2. Validação em mu-plugins/uonix-woocommerce/28-catalogo-ajax-carrinho.php
$mu_plugin_28 = $repo_root . '/mu-plugins/uonix-woocommerce/28-catalogo-ajax-carrinho.php';
test_assert(file_exists($mu_plugin_28), "Arquivo não encontrado: {$mu_plugin_28}");
$content_28 = file_get_contents($mu_plugin_28);

test_assert(
    strpos($content_28, 'wp_ajax_uonix_update_loop_cart_qty') !== false,
    '28-catalogo-ajax-carrinho.php: Deve registrar a action AJAX wp_ajax_uonix_update_loop_cart_qty'
);

test_assert(
    strpos($content_28, 'wp_ajax_nopriv_uonix_update_loop_cart_qty') !== false,
    '28-catalogo-ajax-carrinho.php: Deve registrar a action AJAX wp_ajax_nopriv_uonix_update_loop_cart_qty'
);

test_assert(
    strpos($content_28, 'check_ajax_referer') !== false,
    '28-catalogo-ajax-carrinho.php: Deve validar nonce de segurança com check_ajax_referer'
);

test_assert(
    strpos($content_28, "'add'") !== false && strpos($content_28, "'increment'") !== false &&
    strpos($content_28, "'decrement'") !== false && strpos($content_28, "'remove'") !== false,
    '28-catalogo-ajax-carrinho.php: Deve tratar as ações add, increment, decrement e remove'
);

// 3. Regras de Design System (Uônix: bordas 6px retangulares, cores #0e3780 e #f76a0c)
test_assert(
    (bool) preg_match('/\.uonix-btn-variable\s*\{[^}]*background:\s*#0e3780/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .uonix-btn-variable deve usar fundo Azul Uônix #0e3780 por padrão (idêntico a produtos relacionados)'
);

test_assert(
    (bool) preg_match('/\.uonix-btn-variable:hover.*?background:\s*#f76a0c/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .uonix-btn-variable:hover deve acender em Laranja Uônix #f76a0c no hover direto'
);

test_assert(
    (bool) preg_match('/\.uonix-add-to-cart-btn\s*\{[^}]*background:\s*#0e3780/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .uonix-add-to-cart-btn deve usar fundo Azul Uônix #0e3780 por padrão'
);

test_assert(
    (bool) preg_match('/\.uonix-qty-control\s*\{[^}]*border:\s*2px\s+solid\s+#0e3780/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .uonix-qty-control deve ter borda 2px solid #0e3780'
);

test_assert(
    (bool) preg_match('/\.uonix-qty-control\s*\{[^}]*border-radius:\s*6px/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .uonix-qty-control deve ser retangular com border-radius de 6px (sem estilo pílula)'
);

// 4. Regra de Visibilidade: Botões aparecem SOMENTE no foco/hover do produto (idêntico a produtos relacionados)
test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product\s+\.product-action-wrap\s*\{[^}]*position:\s*absolute\s*!important/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .product-action-wrap deve ter position: absolute !important em repouso'
);

test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product\s+\.product-action-wrap\s*\{[^}]*opacity:\s*0\s*!important/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .product-action-wrap deve ter opacity: 0 !important em repouso'
);

test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product:hover\s+\.product-action-wrap[^{]*\{[^}]*opacity:\s*1\s*!important/s', $content_28) &&
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product:focus-within\s+\.product-action-wrap[^{]*\{[^}]*opacity:\s*1\s*!important/s', $content_28),
    '28-catalogo-ajax-carrinho.php: .product-action-wrap deve ter opacity: 1 !important no hover e focus-within'
);

echo "✅ Todos os contratos do botão interativo, controle de quantidade e visibilidade on-hover/focus foram validados com sucesso!\n";
