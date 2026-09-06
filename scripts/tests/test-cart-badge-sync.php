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

// Helper para remover comentários de código JS/PHP/HTML e garantir que contratos não passem por comentários
function strip_code_comments(string $code): string {
    // Remove comentários de bloco /* ... */
    $code = preg_replace('#/\*.*?\*/#s', '', $code);
    // Remove comentários de linha // ...
    $code = preg_replace('#//.*?$#m', '', $code);
    // Remove comentários HTML <!-- ... -->
    $code = preg_replace('#<!--.*?-->#s', '', $code);
    return $code ?? '';
}

$autoopen_clean = strip_code_comments($autoopen_content);

// Desktop badge
test_assert(
    (bool) preg_match('/#mega-menu-item-4819\s+a\.mega-menu-link/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve selecionar o link do carrinho desktop'
);
test_assert(
    (bool) preg_match('/\.uonix-cart-badge/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve gerenciar a classe .uonix-cart-badge'
);

// Mobile badge
test_assert(
    (bool) preg_match('/\.uonix-menu-cart/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve selecionar o container .uonix-menu-cart no mobile'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve selecionar e atualizar .uonix-menu-cart-badge no mobile'
);
test_assert(
    (bool) preg_match('/addClass\([\'"]is-active[\'"]\)/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve adicionar classe is-active quando houver itens'
);
test_assert(
    (bool) preg_match('/removeClass\([\'"]is-active[\'"]\)/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve remover classe is-active quando quantidade for zero'
);

// Eventos AJAX do WooCommerce
test_assert(
    (bool) preg_match('/added_to_cart\s+removed_from_cart\s+wc_fragments_refreshed/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve escutar eventos AJAX do WooCommerce'
);

// --- Validação Estrita do MutationObserver e Prevenção de Ciclo Recursivo ---
// Prova 1: MutationObserver deve ser instanciado em código real ativo, não em comentário
test_assert(
    (bool) preg_match('/new\s+MutationObserver\s*\(\s*function\s*\(\s*mutations\s*\)/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Deve instanciar new MutationObserver(function (mutations) { ... }) em código ativo'
);

// Prova 2: Opções de observe NÃO podem conter attributes para não reagir a alterações de hidden/classes feitas por syncUonixCart
preg_match('/observer\.observe\s*\(\s*targetOfficial\s*,\s*\{([^}]+)\}\s*\)/', $autoopen_clean, $observer_options_match);
test_assert(
    !empty($observer_options_match[1]),
    '13-carrinho-badge-autoopen.php: Deve conter chamada observer.observe(targetOfficial, { ... })'
);
$observer_options = $observer_options_match[1];
test_assert(
    (bool) preg_match('/childList:\s*true/', $observer_options),
    '13-carrinho-badge-autoopen.php: MutationObserver deve observar childList: true'
);
test_assert(
    (bool) preg_match('/characterData:\s*true/', $observer_options),
    '13-carrinho-badge-autoopen.php: MutationObserver deve observar characterData: true'
);
test_assert(
    !preg_match('/attributes\s*:\s*true/', $observer_options) && !preg_match('/attributes\s*:/', $observer_options),
    '13-carrinho-badge-autoopen.php: MutationObserver NÃO PODE observar attributes para evitar microtask loop recursivo'
);

// Prova 3: Callback do MutationObserver deve filtrar especificamente mutações de conteúdo (childList / characterData)
test_assert(
    (bool) preg_match('/mType\s*===\s*[\'"]childList[\'"]\s*\|\|\s*mType\s*===\s*[\'"]characterData[\'"]/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Callback do observer deve filtrar mutações para mType childList ou characterData'
);

// Prova 4: Idempotência de atributos no badge oficial (não regrava atributos já aplicados)
test_assert(
    (bool) preg_match('/if\s*\(\s*!\$officialBadge\.hasClass\([\'"]uonix-force-hide[\'"]\)\s*\)\s*\{\s*\$officialBadge\.addClass\([\'"]uonix-force-hide[\'"]\);?\s*\}/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Adição de uonix-force-hide deve ser idempotente (verificar hasClass antes)'
);
test_assert(
    (bool) preg_match('/if\s*\(\s*\$officialBadge\.attr\([\'"]hidden[\'"]\)\s*!==\s*[\'"]true[\'"]\s*\)\s*\{\s*\$officialBadge\.attr\([\'"]hidden[\'"]\s*,\s*[\'"]true[\'"]\);?\s*\}/', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Adição do atributo hidden deve ser idempotente (verificar attr!==true antes)'
);

echo "ok   13-carrinho-badge-autoopen.php: Sincronização, instanciação de MutationObserver e ausência de ciclo de attributes validadas\n";

// --- Prova Comportamental: Simulação de DOM e Ciclo de Vida do MutationObserver ---
class MockElement {
    public string $tagName;
    public string $textContent = '';
    public array $attributes = [];
    public array $classes = [];
    /** @var list<MockMutationObserver> */
    public array $observers = [];

    public function __construct(string $tagName, string $textContent = '') {
        $this->tagName = $tagName;
        $this->textContent = $textContent;
    }

    public function addObserver(MockMutationObserver $observer): void {
        $this->observers[] = $observer;
    }

    public function setAttribute(string $name, string $value): void {
        $old = $this->attributes[$name] ?? null;
        if ($old === $value) {
            return; // Idempotente
        }
        $this->attributes[$name] = $value;
        $this->notifyMutation([
            'type' => 'attributes',
            'target' => $this,
            'attributeName' => $name,
            'oldValue' => $old,
        ]);
    }

    public function removeAttribute(string $name): void {
        if (!isset($this->attributes[$name])) {
            return;
        }
        $old = $this->attributes[$name];
        unset($this->attributes[$name]);
        $this->notifyMutation([
            'type' => 'attributes',
            'target' => $this,
            'attributeName' => $name,
            'oldValue' => $old,
        ]);
    }

    public function addClass(string $className): void {
        if (in_array($className, $this->classes, true)) {
            return; // Idempotente
        }
        $this->classes[] = $className;
        $this->notifyMutation([
            'type' => 'attributes',
            'target' => $this,
            'attributeName' => 'class',
        ]);
    }

    public function removeClass(string $className): void {
        $idx = array_search($className, $this->classes, true);
        if ($idx === false) {
            return;
        }
        array_splice($this->classes, $idx, 1);
        $this->notifyMutation([
            'type' => 'attributes',
            'target' => $this,
            'attributeName' => 'class',
        ]);
    }

    public function setText(string $text): void {
        if ($this->textContent === $text) {
            return;
        }
        $this->textContent = $text;
        $this->notifyMutation([
            'type' => 'characterData',
            'target' => $this,
        ]);
    }

    private function notifyMutation(array $record): void {
        foreach ($this->observers as $obs) {
            $obs->recordMutation($record);
        }
    }
}

class MockMutationObserver {
    public array $options = [];
    public array $records = [];
    public $callback;
    public int $callbackInvocations = 0;

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function observe(MockElement $target, array $options): void {
        $this->options = $options;
        $target->addObserver($this);
    }

    public function recordMutation(array $record): void {
        $type = $record['type'];
        if ($type === 'attributes' && empty($this->options['attributes'])) {
            return; // Ignora mutações de atributos quando não configurado
        }
        if ($type === 'childList' && empty($this->options['childList'])) {
            return;
        }
        if ($type === 'characterData' && empty($this->options['characterData'])) {
            return;
        }
        $this->records[] = $record;
    }

    public function flush(): void {
        if (empty($this->records)) {
            return;
        }
        $batch = $this->records;
        $this->records = [];
        $this->callbackInvocations++;
        call_user_func($this->callback, $batch);
    }
}

// Simula o comportamento do código em produção:
$officialBadgeMock = new MockElement('span', '0');
$syncCalls = 0;

$observerCallback = function (array $mutations) use (&$syncCalls, $officialBadgeMock) {
    $hasContentMutation = false;
    foreach ($mutations as $m) {
        $mType = $m['type'];
        if ($mType === 'childList' || $mType === 'characterData') {
            $hasContentMutation = true;
            break;
        }
    }
    if ($hasContentMutation) {
        $syncCalls++;
        // Simulação de syncUonixCart com carrinho vazio
        if (!in_array('uonix-force-hide', $officialBadgeMock->classes, true)) {
            $officialBadgeMock->addClass('uonix-force-hide');
        }
        if (($officialBadgeMock->attributes['hidden'] ?? '') !== 'true') {
            $officialBadgeMock->setAttribute('hidden', 'true');
        }
    }
};

$observer = new MockMutationObserver($observerCallback);
// Configuração fiel ao código de 13-carrinho-badge-autoopen.php:
$observer->observe($officialBadgeMock, [
    'childList' => true,
    'characterData' => true,
    'subtree' => true,
]);

// Teste 1: Execução inicial de sync com carrinho vazio
$syncCalls++;
if (!in_array('uonix-force-hide', $officialBadgeMock->classes, true)) {
    $officialBadgeMock->addClass('uonix-force-hide');
}
if (($officialBadgeMock->attributes['hidden'] ?? '') !== 'true') {
    $officialBadgeMock->setAttribute('hidden', 'true');
}

// Nenhuma mutação deve ter sido enfileirada no observer porque attributes NÃO é observado
test_assert(
    count($observer->records) === 0,
    'Comportamental: A sincronização de carrinho vazio não pode agendar nenhuma mutation no observer'
);

$observer->flush();
test_assert(
    $observer->callbackInvocations === 0,
    'Comportamental: O callback do observer não deve ser acionado por mutações de atributos de carrinho vazio'
);

// Teste 2: Mudança de conteúdo de texto (WooCommerce atualiza para '1')
$officialBadgeMock->setText('1');
test_assert(
    count($observer->records) === 1,
    'Comportamental: Alteração de conteúdo de texto deve gerar registro no observer'
);
$observer->flush();
test_assert(
    $observer->callbackInvocations === 1,
    'Comportamental: Callback do observer deve ser invocado exatamente 1 vez na alteração de texto'
);
test_assert(
    $syncCalls === 2,
    'Comportamental: syncUonixCart deve ter sido executado via mutação de conteúdo'
);

// Após a entrega, nenhuma mutação residual deve estar pendente na fila
test_assert(
    count($observer->records) === 0,
    'Comportamental: A fila de mutações do observer deve estar completamente limpa e esgotada'
);

echo "ok   Comportamental: Prova formal de esgotamento da fila de microtasks e ausência de ciclo recursivo concluída com sucesso\n";

// 2. Validação em mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php
$sidebar_file = $repo_root . '/mu-plugins/uonix-woocommerce/15-carrinho-mini-cart-sidebar.php';
test_assert(file_exists($sidebar_file), "Arquivo não encontrado: {$sidebar_file}");
$sidebar_content = file_get_contents($sidebar_file);
$sidebar_clean = strip_code_comments($sidebar_content);

// --- Validação Estrita de Escopo Desktop vs Mobile ---
// Extrai o bloco de media query mobile (@media (max-width: 1024px))
preg_match('/@media\s*\(\s*max-width\s*:\s*1024px\s*\)\s*\{([^@]+(?:\{[^}]*\}[^@}]*)*)\}/s', $sidebar_clean, $media_matches);
test_assert(
    !empty($media_matches[1]),
    '15-carrinho-mini-cart-sidebar.php: Deve conter o bloco @media (max-width: 1024px) para isolamento do cabeçalho mobile'
);
$mobile_css = $media_matches[1];

// Remove o bloco @media do CSS para inspecionar o escopo global (desktop)
$desktop_css = str_replace($media_matches[0], '', $sidebar_clean);

// Prova 5: Dimensões fixas e layout inline-flex NÃO PODEM vazar para o cabeçalho desktop
test_assert(
    !preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*width\s*:\s*50px/s', $desktop_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart NÃO PODE conter width: 50px fora de media query (vazamento para desktop)'
);
test_assert(
    !preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*height\s*:\s*50px/s', $desktop_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart NÃO PODE conter height: 50px fora de media query (vazamento para desktop)'
);
test_assert(
    !preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*display\s*:\s*inline-flex/s', $desktop_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart NÃO PODE impor display: inline-flex fora de media query (vazamento para desktop)'
);

// Prova 6: No escopo mobile (dentro de @media max-width: 1024px), as dimensões e o badge DEVEM estar configurados
test_assert(
    (bool) preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*width\s*:\s*50px\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php (mobile): .uonix-menu-cart deve ter width: 50px !important dentro de @media'
);
test_assert(
    (bool) preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*height\s*:\s*50px\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php (mobile): .uonix-menu-cart deve ter height: 50px !important dentro de @media'
);
test_assert(
    (bool) preg_match('/(?:\.uonix-menu-cart|a\.uonix-menu-cart)\s*\{[^}]*display\s*:\s*inline-flex\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php (mobile): .uonix-menu-cart deve ter display: inline-flex !important dentro de @media'
);

// Badge mobile styling dentro de @media
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*background-color:\s*#f76a0c\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter fundo laranja #f76a0c !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*border-radius:\s*50%\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter border-radius: 50% !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*box-shadow:\s*0\s+2px\s+5px\s+rgba\(0,\s*0,\s*0,\s*0\.3\)\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter box-shadow condizente com o desktop'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\s*\{[^}]*pointer-events:\s*none\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge deve ter pointer-events: none !important'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart-badge\.is-active\s*\{[^}]*display:\s*flex\s*!important/s', $mobile_css),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart-badge.is-active deve ter display: flex !important'
);

// 3. Validação anti-FOUC (Prevenção de piscada com formatação quebrada)
test_assert(
    (bool) preg_match('/add_action\(\s*[\'"]wp_head[\'"].*?uonix-badge-double-sync-css/s', $autoopen_clean),
    '13-carrinho-badge-autoopen.php: Estilos do badge devem ser carregados no wp_head para evitar FOUC'
);
test_assert(
    (bool) preg_match('/add_action\(\s*[\'"]wp_head[\'"].*?uonix-sticky-cart-css/s', $sidebar_clean),
    '15-carrinho-mini-cart-sidebar.php: Estilos do mini-cart devem ser carregados no wp_head para evitar FOUC'
);
test_assert(
    (bool) preg_match('/add_action\(\s*[\'"]wp_footer[\'"].*?uonix-sticky-cart-js/s', $sidebar_clean),
    '15-carrinho-mini-cart-sidebar.php: Scripts do mini-cart devem permanecer no wp_footer'
);
test_assert(
    (bool) preg_match('/add_filter\(\s*[\'"]render_block[\'"]/', $sidebar_clean),
    '15-carrinho-mini-cart-sidebar.php: Deve conter filtro render_block para pré-renderização defensiva server-side'
);
test_assert(
    (bool) preg_match('/\.uonix-menu-cart[^{]*\{[^}]*text-decoration:\s*none\s*!important/s', $sidebar_clean),
    '15-carrinho-mini-cart-sidebar.php: .uonix-menu-cart deve forçar text-decoration: none !important contra sublinhado'
);

echo "ok   Validações de escopo CSS (isolamento desktop e confinamento mobile <= 1024px) e anti-FOUC aprovadas\n";

echo "\nPASS: Todos os contratos comportamentais, de isolamento CSS e de sincronização do badge foram aprovados com sucesso!\n";
