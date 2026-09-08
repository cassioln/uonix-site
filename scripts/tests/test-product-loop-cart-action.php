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

// 5. Sincronização reativa de quantidades com carrinho lateral / mini-cart drawer
test_assert(
    strpos($content_28, 'wp_ajax_uonix_get_cart_quantities') !== false &&
    strpos($content_28, 'wp_ajax_nopriv_uonix_get_cart_quantities') !== false,
    '28-catalogo-ajax-carrinho.php: Deve registrar endpoint uonix_get_cart_quantities para sincronização rápida'
);

test_assert(
    strpos($content_28, 'applyCartQuantities') !== false,
    '28-catalogo-ajax-carrinho.php: Deve conter função applyCartQuantities para sincronizar os cards'
);

test_assert(
    strpos($content_28, 'fetchCartQuantitiesDebounced') !== false,
    '28-catalogo-ajax-carrinho.php: Deve conter função fetchCartQuantitiesDebounced'
);

test_assert(
    strpos($content_28, 'window.fetch') !== false && strpos($content_28, '/wc/store/') !== false,
    '28-catalogo-ajax-carrinho.php: Deve interceptar requisições Store API do WooCommerce Blocks para resposta imediata'
);

test_assert(
    strpos($content_28, 'wc_fragments_refreshed') !== false && strpos($content_28, 'removed_from_cart') !== false,
    '28-catalogo-ajax-carrinho.php: Deve ouvir eventos de carrinho e mini-cart do WooCommerce'
);

// 6. Contrato de Produtos Vendidos Individualmente (is_sold_individually)
test_assert(
    strpos($content_01, 'is_sold_individually') !== false && strpos($content_01, 'data-sold-individually') !== false,
    '01-woocommerce-loop-especificacoes.php: Deve checar is_sold_individually e renderizar atributo data-sold-individually'
);

test_assert(
    strpos($content_28, 'uonix_process_cart_item_quantity_transition') !== false,
    '28-catalogo-ajax-carrinho.php: Deve conter função de transição uonix_process_cart_item_quantity_transition'
);

test_assert(
    strpos($content_28, 'is_sold_individually') !== false,
    '28-catalogo-ajax-carrinho.php: Deve validar is_sold_individually antes de alterar quantidade'
);

test_assert(
    strpos($content_28, '.uonix-qty-btn:disabled') !== false || strpos($content_28, '.uonix-qty-btn.is-disabled') !== false,
    '28-catalogo-ajax-carrinho.php: Deve conter estilo CSS para botão de quantidade desabilitado'
);

// ========================================================================
// 7. PROVA DE RUNTIME: Execução Real de uonix_process_cart_item_quantity_transition
// ========================================================================

// Stubs do WordPress se ainda não definidos
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/i', '', $k ); }
}

// Mock de Produto WooCommerce
class Test_Mock_WC_Product {
    public $id;
    public $name;
    public $sold_individually;
    public $managing_stock;
    public $backorders_allowed;
    public $stock_quantity;

    public function __construct( $id, $name, $sold_individually = false, $managing_stock = false, $stock_qty = null ) {
        $this->id                 = (int) $id;
        $this->name               = $name;
        $this->sold_individually  = (bool) $sold_individually;
        $this->managing_stock     = (bool) $managing_stock;
        $this->backorders_allowed = false;
        $this->stock_quantity     = $stock_qty;
    }

    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function is_sold_individually() { return $this->sold_individually; }
    public function managing_stock() { return $this->managing_stock; }
    public function backorders_allowed() { return $this->backorders_allowed; }
    public function get_stock_quantity() { return $this->stock_quantity; }
    public function is_purchasable() { return true; }
}

// Mock de Carrinho WooCommerce
class Test_Mock_WC_Cart {
    public $items = array();
    public $set_quantity_calls = array();
    public $remove_cart_item_calls = array();
    public $add_to_cart_calls = array();

    public function add_to_cart( $product_id, $qty = 1 ) {
        $key = 'key_prod_' . $product_id;
        $this->items[ $key ] = array(
            'product_id' => $product_id,
            'quantity'   => $qty,
        );
        $this->add_to_cart_calls[] = array( 'product_id' => $product_id, 'qty' => $qty );
        return $key;
    }

    public function set_quantity( $cart_item_key, $quantity = 1 ) {
        $this->set_quantity_calls[] = array( 'key' => $cart_item_key, 'qty' => $quantity );
        if ( isset( $this->items[ $cart_item_key ] ) ) {
            $this->items[ $cart_item_key ]['quantity'] = $quantity;
        }
    }

    public function remove_cart_item( $cart_item_key ) {
        $this->remove_cart_item_calls[] = $cart_item_key;
        unset( $this->items[ $cart_item_key ] );
    }

    public function get_cart() {
        return $this->items;
    }

    public function calculate_totals() {}
    public function get_cart_contents_count() {
        $c = 0;
        foreach ( $this->items as $i ) { $c += $i['quantity']; }
        return $c;
    }
}

// Carrega o arquivo funcional
require_once $mu_plugin_28;

// --- Cenário 1: Produto vendido individualmente (is_sold_individually = true) ---
$prod_indiv = new Test_Mock_WC_Product( 101, 'Ancoragem Especial Vendida Individualmente', true );
$cart_indiv = new Test_Mock_WC_Cart();

// Passo 1.1: Primeira adição ao carrinho vazio (deve permitir adicionar 1 unidade)
$res_indiv_1 = uonix_process_cart_item_quantity_transition( $prod_indiv, $cart_indiv, 'add', 0, '' );
test_assert( $res_indiv_1['new_qty'] === 1, 'Runtime Indiv: Primeira adição deve definir quantidade = 1' );
test_assert( $res_indiv_1['limit_reached'] === false, 'Runtime Indiv: Primeira adição não deve disparar limite' );
test_assert( count( $cart_indiv->set_quantity_calls ) === 0, 'Runtime Indiv: Primeira adição usa add_to_cart, não set_quantity' );

// Passo 1.2: Segunda adição com item já no carrinho (deve BLOQUEAR em 1 e NÃO chamar set_quantity para 2)
$res_indiv_2 = uonix_process_cart_item_quantity_transition( $prod_indiv, $cart_indiv, 'add', 1, 'key_prod_101' );
test_assert( $res_indiv_2['new_qty'] === 1, 'Runtime Indiv: Segunda adição DEVE manter quantidade em 1 (bloqueante)' );
test_assert( $res_indiv_2['limit_reached'] === true, 'Runtime Indiv: Segunda adição deve marcar limit_reached = true' );
test_assert( count( $cart_indiv->set_quantity_calls ) === 0, 'Runtime Indiv: set_quantity NÃO pode ser chamado para produto vendido individualmente já presente' );
test_assert( ! empty( $res_indiv_2['notice_message'] ), 'Runtime Indiv: Deve retornar mensagem explicativa de limite atingido' );

// Passo 1.3: Tentativa de incremento via botão (+) com item já no carrinho (deve BLOQUEAR em 1)
$res_indiv_3 = uonix_process_cart_item_quantity_transition( $prod_indiv, $cart_indiv, 'increment', 1, 'key_prod_101' );
test_assert( $res_indiv_3['new_qty'] === 1, 'Runtime Indiv: Clique no botão (+) DEVE manter quantidade em 1' );
test_assert( $res_indiv_3['limit_reached'] === true, 'Runtime Indiv: Incremento deve acusar limite atingido' );
test_assert( count( $cart_indiv->set_quantity_calls ) === 0, 'Runtime Indiv: set_quantity NÃO pode ter sido chamado em nenhum incremento' );

// Passo 1.4: Decremento / remoção de 1 para 0
$res_indiv_4 = uonix_process_cart_item_quantity_transition( $prod_indiv, $cart_indiv, 'decrement', 1, 'key_prod_101' );
test_assert( $res_indiv_4['new_qty'] === 0, 'Runtime Indiv: Decremento de 1 deve zerar quantidade' );
test_assert( in_array( 'key_prod_101', $cart_indiv->remove_cart_item_calls, true ), 'Runtime Indiv: remove_cart_item deve ser invocado ao zerar' );

// --- Cenário 2: Produto comum (is_sold_individually = false) ---
$prod_normal = new Test_Mock_WC_Product( 202, 'Limpador de Furos Padrão', false );
$cart_normal = new Test_Mock_WC_Cart();

// Passo 2.1: Primeira adição
$res_norm_1 = uonix_process_cart_item_quantity_transition( $prod_normal, $cart_normal, 'add', 0, '' );
test_assert( $res_norm_1['new_qty'] === 1, 'Runtime Normal: Primeira adição deve resultar em 1' );

// Passo 2.2: Incremento para 2 (deve permitir normalmente)
$res_norm_2 = uonix_process_cart_item_quantity_transition( $prod_normal, $cart_normal, 'increment', 1, 'key_prod_202' );
test_assert( $res_norm_2['new_qty'] === 2, 'Runtime Normal: Incremento deve resultar em 2' );
test_assert( $res_norm_2['limit_reached'] === false, 'Runtime Normal: Não deve acusar limite' );
$last_call = end( $cart_normal->set_quantity_calls );
test_assert( $last_call && $last_call['qty'] === 2, 'Runtime Normal: set_quantity deve ter sido chamado com 2' );

// Passo 2.3: Decremento de 2 para 1
$res_norm_3 = uonix_process_cart_item_quantity_transition( $prod_normal, $cart_normal, 'decrement', 2, 'key_prod_202' );
test_assert( $res_norm_3['new_qty'] === 1, 'Runtime Normal: Decremento deve retornar para 1' );
$last_call = end( $cart_normal->set_quantity_calls );
test_assert( $last_call && $last_call['qty'] === 1, 'Runtime Normal: set_quantity deve ter sido chamado com 1' );

// --- Cenário 3: Produto com limite de estoque gerenciado (ex: estoque = 2) ---
$prod_stock = new Test_Mock_WC_Product( 303, 'Produto com Estoque Limitado', false, true, 2 );
$cart_stock = new Test_Mock_WC_Cart();

// Tentativa de incremento além do estoque disponível (já tem 2 no carrinho)
$res_stock = uonix_process_cart_item_quantity_transition( $prod_stock, $cart_stock, 'increment', 2, 'key_prod_303' );
test_assert( $res_stock['new_qty'] === 2, 'Runtime Estoque: Não pode incrementar além do limite de estoque' );
test_assert( $res_stock['limit_reached'] === true, 'Runtime Estoque: Deve acusar limite atingido' );
test_assert( count( $cart_stock->set_quantity_calls ) === 0, 'Runtime Estoque: set_quantity não deve ser chamado quando excede estoque' );

echo "✅ Todos os contratos do botão interativo, regras de 'is_sold_individually', controle de quantidade e provas de runtime foram validados com 100% de aprovação!\n";
