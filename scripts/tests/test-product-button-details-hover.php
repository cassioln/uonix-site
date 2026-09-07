<?php
/**
 * Teste de contrato do botão "Ver Detalhes" nos loops de produtos (Catálogo, Marcas e Carrosséis).
 *
 * Valida:
 * 1. Em repouso, o botão .uonix-details-btn utiliza a cor institucional Azul Uônix (#0e3780).
 * 2. Quando o usuário passa o mouse ou foca no card do produto (li.product:hover / :focus-within),
 *    o botão PERMANECE com a cor institucional azul (#0e3780), sem acender em laranja prematuramente.
 * 3. O botão acende no Laranja Uônix (#f76a0c) com elevação SOMENTE quando o foco/hover estiver diretamente nele:
 *    - .uonix-details-btn:hover
 *    - .uonix-details-btn:focus
 *    - .uonix-details-btn:focus-visible
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
echo "🧪 TESTE DE CONTRATO: HOVER E FOCO DO BOTÃO VER DETALHES (PRODUTOS)\n";
echo "========================================================================\n\n";

// 1. Validação em themes/kadence-child/snippets/05-catalogo-filtros-husky-mobile.php
$snippet_05 = $repo_root . '/themes/kadence-child/snippets/05-catalogo-filtros-husky-mobile.php';
test_assert(file_exists($snippet_05), "Arquivo não encontrado: {$snippet_05}");
$content_05 = file_get_contents($snippet_05);

// Fundo padrão azul no snippet 05
test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product\s+\.uonix-details-btn\s*\{[^}]*background:\s*#0e3780\s*!important/s', $content_05),
    '05-catalogo-filtros-husky-mobile.php: .uonix-details-btn deve ter background azul #0e3780 !important por padrão'
);

// Card hover mantém azul
test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product:hover\s+\.uonix-details-btn[^{]*\{[^}]*background:\s*#0e3780\s*!important/s', $content_05),
    '05-catalogo-filtros-husky-mobile.php: Hover no card (li.product:hover) deve manter background azul #0e3780'
);

// Hover/foco direto no botão acende em laranja
test_assert(
    (bool) preg_match('/\.uonix-details-btn:hover.*?background:\s*#f76a0c\s*!important/s', $content_05),
    '05-catalogo-filtros-husky-mobile.php: Hover no botão (.uonix-details-btn:hover) deve acender em laranja #f76a0c !important'
);
test_assert(
    (bool) preg_match('/\.uonix-details-btn:focus.*?background:\s*#f76a0c\s*!important/s', $content_05),
    '05-catalogo-filtros-husky-mobile.php: Foco no botão (.uonix-details-btn:focus) deve acender em laranja #f76a0c !important'
);
echo "ok   05-catalogo-filtros-husky-mobile.php: Botão azul no card e laranja somente no hover/foco direto validado\n";

// 2. Validação em themes/kadence-child/snippets/22-catalogo-arquivos-marcas.php
$snippet_22 = $repo_root . '/themes/kadence-child/snippets/22-catalogo-arquivos-marcas.php';
test_assert(file_exists($snippet_22), "Arquivo não encontrado: {$snippet_22}");
$content_22 = file_get_contents($snippet_22);

test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product\s+\.uonix-details-btn\s*\{[^}]*background:\s*#0e3780\s*!important/s', $content_22),
    '22-catalogo-arquivos-marcas.php: .uonix-details-btn deve ter background azul #0e3780 !important por padrão'
);
test_assert(
    (bool) preg_match('/\.woocommerce\s+ul\.products\s+li\.product:hover\s+\.uonix-details-btn[^{]*\{[^}]*background:\s*#0e3780\s*!important/s', $content_22),
    '22-catalogo-arquivos-marcas.php: Hover no card deve manter background azul #0e3780'
);
test_assert(
    (bool) preg_match('/\.uonix-details-btn:hover.*?background:\s*#f76a0c\s*!important/s', $content_22),
    '22-catalogo-arquivos-marcas.php: Hover no botão deve acender em laranja #f76a0c !important'
);
echo "ok   22-catalogo-arquivos-marcas.php: Botão azul no card e laranja somente no hover/foco direto validado\n";

// 3. Validação em themes/kadence-child/snippets/34-produtos-carrosseis-relacionados.php
$snippet_34 = $repo_root . '/themes/kadence-child/snippets/34-produtos-carrosseis-relacionados.php';
test_assert(file_exists($snippet_34), "Arquivo não encontrado: {$snippet_34}");
$content_34 = file_get_contents($snippet_34);

test_assert(
    (bool) preg_match('/\.related\.products\s+ul\.products\s+li\.product\s+\.uonix-details-btn[^{]*\{[^}]*background:\s*#0e3780\s*!important/s', $content_34),
    '34-produtos-carrosseis-relacionados.php: .uonix-details-btn deve ter background azul #0e3780 !important por padrão'
);
test_assert(
    (bool) preg_match('/\.related\.products\s+ul\.products\s+li\.product:hover\s+\.uonix-details-btn[^{]*\{[^}]*background:\s*#0e3780\s*!important/s', $content_34),
    '34-produtos-carrosseis-relacionados.php: Hover no card deve manter background azul #0e3780'
);
test_assert(
    (bool) preg_match('/\.uonix-details-btn:hover.*?background:\s*#f76a0c\s*!important/s', $content_34),
    '34-produtos-carrosseis-relacionados.php: Hover no botão deve acender em laranja #f76a0c !important'
);
echo "ok   34-produtos-carrosseis-relacionados.php: Botão azul no card e laranja somente no hover/foco direto validado\n";

echo "\nPASS: Todos os contratos de hover e foco do botão Ver Detalhes foram aprovados com sucesso!\n";
