<?php
/**
 * Páginas de fluxo saem do índice — sem apagar as diretivas de ambiente.
 *
 * O RISCO QUE ESTE TESTE TRAVA
 *
 * A tentação ao escrever um filtro wp_robots é devolver um array novo:
 *
 *     return array( 'noindex' => true );
 *
 * Isso APAGA o que já estava lá. E o que já estava lá inclui o noindex de ambiente do
 * 06-environment-indexing.php, que mantém dev e QA fora do Google. Um filtro que sobrescreve
 * faria o ambiente de QA passar a ser indexado — muito pior que o problema original.
 *
 * Por isso o teste verifica as duas pontas: que o noindex É acrescentado nas páginas de
 * fluxo, e que as diretivas preexistentes SOBREVIVEM.
 *
 * Como não há WordPress carregado, defino stubs das funções condicionais do WooCommerce
 * antes de incluir o arquivo, e uso o parâmetro $forcar para exercitar os dois lados.
 */

define( 'ABSPATH', __DIR__ );

$alvo = __DIR__ . '/../../mu-plugins/uonix-security/08-noindex-paginas-de-fluxo.php';

if ( ! file_exists( $alvo ) ) {
	fwrite( STDERR, "FALHOU: arquivo não encontrado: {$alvo}\n" );
	exit( 1 );
}

// add_filter não existe fora do WordPress; registra a chamada para verificar depois.
$GLOBALS['filtros_registrados'] = array();
function add_filter( $hook, $callback, $prioridade = 10, $args = 1 ) {
	$GLOBALS['filtros_registrados'][] = array(
		'hook'       => $hook,
		'callback'   => $callback,
		'prioridade' => $prioridade,
	);
}

require $alvo;

$asserts = 0;
$falhas  = array();

function verifica( $condicao, $mensagem ) {
	global $asserts, $falhas;
	$asserts++;
	if ( ! $condicao ) {
		$falhas[] = $mensagem;
	}
}

// ---------------------------------------------------------------------------
// 1. o filtro é registrado no hook certo
$registrado = null;
foreach ( $GLOBALS['filtros_registrados'] as $f ) {
	if ( 'wp_robots' === $f['hook'] ) {
		$registrado = $f;
		break;
	}
}

verifica(
	null !== $registrado,
	'o arquivo não registra nada em wp_robots. Sem isso o noindex nunca é aplicado e a ' .
	'página de checkout continua no índice do Google.'
);

verifica(
	$registrado && 'uonix_noindex_paginas_de_fluxo' === $registrado['callback'],
	'o callback registrado em wp_robots não é uonix_noindex_paginas_de_fluxo.'
);

// A prioridade precisa ser MAIOR que a do filtro de ambiente (que usa a padrão, 10), para
// rodar depois dele e poder acrescentar sem competir.
verifica(
	$registrado && $registrado['prioridade'] > 10,
	'a prioridade do filtro é ' . ( $registrado ? $registrado['prioridade'] : '?' ) .
	'. Precisa ser maior que 10 para rodar DEPOIS do filtro de ambiente ' .
	'(06-environment-indexing.php), garantindo que apenas acrescenta.'
);

// ---------------------------------------------------------------------------
// 2. em página de fluxo, ACRESCENTA noindex
$saida = uonix_noindex_paginas_de_fluxo( array(), true );

verifica(
	isset( $saida['noindex'] ) && true === $saida['noindex'],
	'em página de fluxo o filtro não acrescentou noindex. A página de checkout continuaria ' .
	'indexável e seguiria aparecendo como erro no Search Console.'
);

// ---------------------------------------------------------------------------
// 3. NÃO mexe em follow
//
// Os links do carrinho para produtos devem continuar passando autoridade. Marcar nofollow
// aqui desperdiçaria link interno legítimo.
verifica(
	! isset( $saida['nofollow'] ),
	'o filtro marcou nofollow na página de fluxo. Só noindex é desejado: os links do ' .
	'carrinho para os produtos devem continuar passando autoridade.'
);

// ---------------------------------------------------------------------------
// 4. FORA de página de fluxo, não altera nada
$antes  = array( 'max-image-preview' => 'large' );
$depois = uonix_noindex_paginas_de_fluxo( $antes, false );

verifica(
	$depois === $antes,
	'fora de página de fluxo o filtro alterou as diretivas. Esperado: devolver o array ' .
	'intacto. Obtido: ' . json_encode( $depois )
);

// ---------------------------------------------------------------------------
// 5. PRESERVA diretivas preexistentes — a asserção mais importante
//
// Se o filtro devolvesse array( 'noindex' => true ) em vez de acrescentar, este caso falha.
// É exatamente o bug que apagaria o noindex de ambiente e exporia o QA ao Google.
$ambiente = array(
	'noindex'   => true,
	'nofollow'  => true,
	'noarchive' => true,
);
$resultado = uonix_noindex_paginas_de_fluxo( $ambiente, true );

foreach ( array( 'noindex', 'nofollow', 'noarchive' ) as $diretiva ) {
	verifica(
		isset( $resultado[ $diretiva ] ) && true === $resultado[ $diretiva ],
		"o filtro APAGOU a diretiva '{$diretiva}' que vinha do filtro de ambiente. " .
		'Um filtro wp_robots tem de ACRESCENTAR, nunca substituir o array — sobrescrever ' .
		'faria dev e QA voltarem a ser indexados pelo Google.'
	);
}

// ---------------------------------------------------------------------------
// 6. entrada inválida não quebra o site
//
// Outro plugin mal-comportado pode passar valor não-array. Sem a guarda `is_array`, o
// resultado depende do TIPO — medido no PHP 8.3:
//
//   null    -> auto-vivifica em array, funciona por acidente
//   string  -> TypeError: Cannot access offset of type string on string
//   int     -> Error: Cannot use a scalar value as an array
//   bool    -> Error: Cannot use a scalar value as an array
//
// Ou seja: testar só com `null` NÃO prova nada, porque é justamente o único caso que
// sobrevive sem a guarda. Foi o que descobri na prova de mutação — remover a guarda passava
// verde. Os tipos escalares causam ERRO FATAL no `wp_head` de todas as páginas do site.
foreach ( array( 'null' => null, 'string' => 'abc', 'int' => 42, 'bool' => true ) as $tipo => $valor ) {
	$asserts++;

	$erro = null;
	try {
		$saida_invalida = uonix_noindex_paginas_de_fluxo( $valor, true );
	} catch ( \Throwable $e ) {
		$erro           = $e;
		$saida_invalida = null;
	}

	if ( null !== $erro ) {
		$falhas[] = "com entrada do tipo {$tipo} o filtro lançou " . get_class( $erro ) .
			'. Um plugin terceiro que passe valor não-array causaria ERRO FATAL no head de ' .
			'todas as páginas. Falta a guarda `if ( ! is_array( $robots ) )`.';
		continue;
	}

	if ( ! is_array( $saida_invalida ) || ! isset( $saida_invalida['noindex'] ) ) {
		$falhas[] = "com entrada do tipo {$tipo} o filtro não devolveu array com noindex. " .
			'Obtido: ' . var_export( $saida_invalida, true );
	}
}

// ---------------------------------------------------------------------------
// 7. a detecção usa FUNÇÃO do WooCommerce, não ID de página
//
// ID muda entre ambientes: um clone de produção para QA quebraria a regra em silêncio.
$fonte = file_get_contents( $alvo );

verifica(
	strpos( $fonte, 'is_checkout' ) !== false && strpos( $fonte, 'is_cart' ) !== false,
	'a detecção não usa is_cart()/is_checkout(). Identificar a página por ID a quebraria ' .
	'em qualquer ambiente com IDs diferentes.'
);

verifica(
	! preg_match( '/get_the_ID\(\)\s*===?\s*\d+|is_page\(\s*\d+/', $fonte ),
	'a detecção compara ID numérico de página. IDs diferem entre produção, QA e dev — ' .
	'use as condicionais do WooCommerce.'
);

// ---------------------------------------------------------------------------
// 8. degrada com elegância sem WooCommerce
//
// Em mu-plugin o arquivo carrega ANTES dos plugins. Se as funções não existirem ainda e o
// código chamar direto, é erro fatal no site inteiro.
verifica(
	strpos( $fonte, "function_exists( 'is_cart' )" ) !== false ||
	strpos( $fonte, "function_exists('is_cart')" ) !== false,
	'o arquivo chama is_cart() sem verificar function_exists. Como mu-plugin carrega antes ' .
	'dos plugins, isso derruba o site quando o WooCommerce está desativado.'
);

// ---------------------------------------------------------------------------
if ( $falhas ) {
	foreach ( $falhas as $f ) {
		fwrite( STDERR, "FAIL: {$f}\n" );
	}
	fwrite( STDERR, sprintf( "\nFALHOU: %d asserções, %d falha(s)\n", $asserts, count( $falhas ) ) );
	exit( 1 );
}

printf( "PASS: noindex em páginas de fluxo, preservando diretivas de ambiente (%d asserções)\n", $asserts );
