<?php
/**
 * Prova o preço padrão de novas variações sem depender de instalação WordPress.
 *
 * O arquivo sob teste registra um callback em `woocommerce_new_product_variation`.
 * Aqui montamos stubs mínimos de WC_Product_Variation e das funções do WP/Woo, e
 * então disparamos o hook nos cenários que importam.
 *
 * Cobertura pretendida (o que o CI passa a proteger):
 *   1. variação criada SEM preço recebe o padrão
 *   2. variação criada COM preço NÃO é sobrescrita
 *   3. variação com preço promocional não tem `set_price` forçado
 *   4. id inválido (0) não grava nada
 *   5. objeto que não é WC_Product_Variation não grava nada
 *   6. o filtro `uonix_variacao_preco_padrao` altera o valor aplicado
 *   7. o produto pai tem transients limpos e sync deferido
 *   8. o hook é registrado com prioridade 10 e 2 argumentos
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['vpp_assertions'] = 0;
$GLOBALS['vpp_actions']    = array();
$GLOBALS['vpp_filters']    = array();
$GLOBALS['vpp_transients'] = array();
$GLOBALS['vpp_sync']       = array();

function vpp_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function vpp_assert_same( $expected, $actual, $message ) {
	$GLOBALS['vpp_assertions']++;

	if ( $expected !== $actual ) {
		vpp_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

/* ------------------------------------------------------------------ */
/* Stubs do WordPress / WooCommerce                                    */
/* ------------------------------------------------------------------ */

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['vpp_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['vpp_filters'][ $hook ][] = $callback;
}

function apply_filters( $hook, $value ) {
	if ( empty( $GLOBALS['vpp_filters'][ $hook ] ) ) {
		return $value;
	}

	foreach ( $GLOBALS['vpp_filters'][ $hook ] as $cb ) {
		$value = call_user_func( $cb, $value );
	}

	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wc_delete_product_transients( $product_id ) {
	$GLOBALS['vpp_transients'][] = (int) $product_id;
}

function wc_deferred_product_sync( $product_id ) {
	$GLOBALS['vpp_sync'][] = (int) $product_id;
}

/** Variação de produto: só o que o código sob teste usa. */
class WC_Product_Variation {
	public $id;
	public $regular_price;
	public $sale_price;
	public $price       = null;
	public $parent_id;
	public $save_count  = 0;

	public function __construct( $id = 1, $regular = '', $sale = '', $parent = 900 ) {
		$this->id            = $id;
		$this->regular_price = $regular;
		$this->sale_price    = $sale;
		$this->parent_id     = $parent;
	}

	public function get_regular_price( $context = 'view' ) {
		return $this->regular_price;
	}

	public function set_regular_price( $value ) {
		$this->regular_price = $value;
	}

	public function get_sale_price( $context = 'view' ) {
		return $this->sale_price;
	}

	public function set_price( $value ) {
		$this->price = $value;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function save() {
		$this->save_count++;

		return $this->id;
	}
}

/** Objeto que NÃO é variação, para o caso 5. */
class VPP_Nao_Variacao {
	public function get_regular_price( $context = 'view' ) {
		return '';
	}
}

$GLOBALS['vpp_produtos'] = array();

function wc_get_product( $id ) {
	return isset( $GLOBALS['vpp_produtos'][ $id ] ) ? $GLOBALS['vpp_produtos'][ $id ] : null;
}

/* ------------------------------------------------------------------ */
/* Carrega o arquivo sob teste                                         */
/* ------------------------------------------------------------------ */

require __DIR__ . '/../../mu-plugins/uonix-woocommerce/21-admin-variacao-preco-padrao.php';

/* ------------------------------------------------------------------ */
/* Caso 8 primeiro: o hook foi registrado como esperado?               */
/* ------------------------------------------------------------------ */

if ( empty( $GLOBALS['vpp_actions']['woocommerce_new_product_variation'] ) ) {
	vpp_fail( 'hook woocommerce_new_product_variation não foi registrado' );
}

$registro = $GLOBALS['vpp_actions']['woocommerce_new_product_variation'][0];

vpp_assert_same( 10, $registro['priority'], 'prioridade do hook mudou' );
vpp_assert_same(
	2,
	$registro['accepted_args'],
	'hook precisa receber 2 argumentos: sem o segundo, o callback refaz wc_get_product a cada criação'
);

$callback = $registro['callback'];

/* ------------------------------------------------------------------ */
/* Caso 1: variação sem preço recebe o padrão                          */
/* ------------------------------------------------------------------ */

$v = new WC_Product_Variation( 11, '', '', 900 );
call_user_func( $callback, 11, $v );

vpp_assert_same( '0', $v->regular_price, 'variação sem preço deveria receber o padrão 0' );
vpp_assert_same( '0', $v->price, 'preço ativo deveria acompanhar o preço normal' );
vpp_assert_same( 1, $v->save_count, 'variação deveria ser salva exatamente uma vez' );

/* Caso 7: o pai precisa ser sincronizado, senão o aviso "sem preço" persiste. */
vpp_assert_same( array( 900 ), $GLOBALS['vpp_transients'], 'transients do produto pai não foram limpos' );
vpp_assert_same( array( 900 ), $GLOBALS['vpp_sync'], 'sync deferido do produto pai não foi agendado' );

/* ------------------------------------------------------------------ */
/* Caso 2: variação COM preço não é sobrescrita                        */
/* ------------------------------------------------------------------ */

$GLOBALS['vpp_transients'] = array();
$GLOBALS['vpp_sync']       = array();

$v2 = new WC_Product_Variation( 12, '199.90', '', 900 );
call_user_func( $callback, 12, $v2 );

vpp_assert_same(
	'199.90',
	$v2->regular_price,
	'variação criada com preço (duplicação/importação) NÃO deve ser sobrescrita'
);
vpp_assert_same( 0, $v2->save_count, 'variação com preço não deveria ser salva' );
vpp_assert_same( array(), $GLOBALS['vpp_transients'], 'não deveria mexer no pai quando nada muda' );

/* ------------------------------------------------------------------ */
/* Caso 3: com preço promocional, set_price não é forçado              */
/* ------------------------------------------------------------------ */

$v3 = new WC_Product_Variation( 13, '', '49.90', 900 );
call_user_func( $callback, 13, $v3 );

vpp_assert_same( '0', $v3->regular_price, 'preço normal deveria receber o padrão' );
vpp_assert_same(
	null,
	$v3->price,
	'com preço promocional definido, o preço ativo é do WooCommerce — não deve ser forçado'
);

/* ------------------------------------------------------------------ */
/* Caso 4: id inválido não grava                                       */
/* ------------------------------------------------------------------ */

$GLOBALS['vpp_transients'] = array();

$v4 = new WC_Product_Variation( 0, '', '', 900 );
call_user_func( $callback, 0, $v4 );

vpp_assert_same( '', $v4->regular_price, 'id 0 não deveria gravar preço' );
vpp_assert_same( 0, $v4->save_count, 'id 0 não deveria salvar' );

/* ------------------------------------------------------------------ */
/* Caso 5: objeto que não é variação é ignorado                        */
/* ------------------------------------------------------------------ */

$GLOBALS['vpp_produtos'][ 14 ] = new VPP_Nao_Variacao();

call_user_func( $callback, 14, null );

vpp_assert_same(
	array(),
	$GLOBALS['vpp_transients'],
	'objeto que não é WC_Product_Variation não deveria disparar escrita'
);

/* Resolução via wc_get_product quando o segundo argumento não vem. */
$v5                            = new WC_Product_Variation( 15, '', '', 901 );
$GLOBALS['vpp_produtos'][ 15 ] = $v5;

call_user_func( $callback, 15, null );

vpp_assert_same(
	'0',
	$v5->regular_price,
	'sem o segundo argumento, a variação deve ser resolvida por wc_get_product'
);

/* ------------------------------------------------------------------ */
/* Caso 6: o filtro altera o valor aplicado                            */
/* ------------------------------------------------------------------ */

add_filter(
	'uonix_variacao_preco_padrao',
	function () {
		return '7.50';
	}
);

$v6 = new WC_Product_Variation( 16, '', '', 902 );
call_user_func( $callback, 16, $v6 );

vpp_assert_same(
	'7.50',
	$v6->regular_price,
	'o filtro uonix_variacao_preco_padrao precisa alterar o preço aplicado'
);

/* Valor não escalar volta ao default seguro. */
$GLOBALS['vpp_filters'] = array();

add_filter(
	'uonix_variacao_preco_padrao',
	function () {
		return array( 'invalido' );
	}
);

$v7 = new WC_Product_Variation( 17, '', '', 903 );
call_user_func( $callback, 17, $v7 );

vpp_assert_same(
	'0',
	$v7->regular_price,
	'filtro devolvendo não escalar deve cair no default 0, nunca gravar array'
);

printf( "PASS: preço padrão de novas variações (%d asserções)\n", $GLOBALS['vpp_assertions'] );
