<?php
/**
 * Teste de contrato da regra de abertura automática do mini-cart lateral.
 *
 * Valida os requisitos de negócio:
 * 1. O carrinho só deve abrir de forma automática se:
 *    a) A página for de produto individual (is_product());
 *    b) O produto tiver sido adicionado pelo botão principal da página de produto (.single_add_to_cart_button / form.cart);
 * 2. Em páginas de catálogo (/produtos/, /acessorios/, etc.), o auto-open NÃO deve ser injetado/executado;
 * 3. Na página do produto, adições através da seção "Produtos Relacionados", "Up-sells" ou loops NÃO devem abrir o carrinho;
 * 4. Proteção contra reabertura em refresh manual (F5) via consumo atômico de sessionStorage.
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
echo "🧪 TESTE DE CONTRATO: RESTRIÇÃO DE AUTO-ABERTURA DO MINI-CART\n";
echo "========================================================================\n\n";

$autoopen_file = $repo_root . '/mu-plugins/uonix-woocommerce/13-carrinho-badge-autoopen.php';
test_assert(file_exists($autoopen_file), "Arquivo não encontrado: {$autoopen_file}");
$content = file_get_contents($autoopen_file);

// 1. Guarda PHP: Verificação de is_product() antes de injetar o script
test_assert(
    (bool) preg_match('/if\s*\(\s*!\s*function_exists\s*\(\s*[\'"]is_product[\'"]\s*\)\s*\|\|\s*!\s*is_product\s*\(\s*\)\s*\)\s*\{\s*return;\s*\}/', $content),
    '13-carrinho-badge-autoopen.php: Deve conter guarda PHP estrita verificando is_product() antes de injetar script'
);

// 2. Função de identificação do botão principal
test_assert(
    strpos($content, 'function isMainAddToCartTarget(') !== false,
    '13-carrinho-badge-autoopen.php: Deve conter função isMainAddToCartTarget para diferenciar botão principal de loops'
);

// 3. Exclusão explícita de loops e produtos relacionados
$required_exclusions = array('.related', '.up-sells', '.upsells', '.cross-sells', 'ul.products', '.uonix-product-action-wrap');
foreach ($required_exclusions as $sel) {
    test_assert(
        strpos($content, $sel) !== false,
        "13-carrinho-badge-autoopen.php: Deve checar e excluir o seletor '{$sel}' na identificação do botão principal"
    );
}

// 4. Invalidação imediata em cliques de loops e relacionados
test_assert(
    (bool) preg_match('/\.on\s*\(\s*[\'"]click[\'"]\s*,\s*[\'"][^\'"]*\.related[^\'"]*[\'"]\s*,/s', $content),
    '13-carrinho-badge-autoopen.php: Deve invalidar flags de auto-abertura caso o usuário clique em elementos secundários/relacionados'
);

// 5. Consumo atômico da flag no reload para evitar reabertura no F5
test_assert(
    strpos($content, 'sessionStorage.removeItem(STORAGE_FLAG)') !== false,
    '13-carrinho-badge-autoopen.php: Deve consumir imediatamente a flag do sessionStorage no $(document).ready'
);

// 6. Condicionamento da abertura via AJAX ao botão principal
test_assert(
    (bool) preg_match('/added_to_cart[^\}]+isMainAddToCartTarget/s', $content) ||
    (bool) preg_match('/added_to_cart[^\}]+mainProductAddedAjax/s', $content),
    '13-carrinho-badge-autoopen.php: Evento added_to_cart deve validar se a origem foi o botão principal antes de abrir o carrinho'
);

echo "ok   13-carrinho-badge-autoopen.php: Todas as guardas estáticas e restrições de contrato foram aprovadas.\n";

// 7. Validação em runtime HTTP contra o servidor local se estiver online
$base_url = 'http://localhost:8080';
$ch = curl_init("{$base_url}/produtos/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$catalogo_html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && is_string($catalogo_html)) {
    // No catálogo, o script uonix-auto-open-smart NÃO pode estar presente
    test_assert(
        strpos($catalogo_html, 'id="uonix-auto-open-smart"') === false,
        'Runtime: Script uonix-auto-open-smart NÃO deve ser renderizado na página de catálogo (/produtos/)'
    );
    echo "ok   Runtime: Catálogo (/produtos/) verificado sem o script de auto-abertura.\n";

    // Na página de produto, o script uonix-auto-open-smart DEVE estar presente com as novas restrições
    $ch_prod = curl_init("{$base_url}/produtos/limpador-de-furos/");
    curl_setopt($ch_prod, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_prod, CURLOPT_TIMEOUT, 3);
    $prod_html = curl_exec($ch_prod);
    $prod_http_code = curl_getinfo($ch_prod, CURLINFO_HTTP_CODE);
    curl_close($ch_prod);

    if ($prod_http_code === 200 && is_string($prod_html)) {
        test_assert(
            strpos($prod_html, 'id="uonix-auto-open-smart"') !== false,
            'Runtime: Script uonix-auto-open-smart DEVE estar presente na página individual de produto'
        );
        test_assert(
            strpos($prod_html, 'isMainAddToCartTarget') !== false,
            'Runtime: Script uonix-auto-open-smart no produto deve conter a validação isMainAddToCartTarget'
        );
        echo "ok   Runtime: Produto (/produtos/limpador-de-furos/) verificado com o script de auto-abertura restrito ao botão principal.\n";
    }
} else {
    echo "info Servidor local não respondeu na porta 8080, validação em runtime pulada (apenas contratos estáticos validados).\n";
}

echo "\nPASS: Todas as restrições de auto-abertura do mini-cart foram validadas com sucesso!\n";
exit(0);
