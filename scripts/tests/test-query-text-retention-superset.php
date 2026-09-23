<?php
/**
 * Acoplamento VERIFICADO entre a retenção de texto de 53 e a regra de seleção de 55.
 *
 * POR QUE ESTE ARQUIVO EXISTE (issue #253). `53-admin-analytics-metrics.php` passou
 * a persistir o TEXTO da consulta apenas das linhas que podem qualificar como
 * oportunidade; o resto do universo vai só como métrica. Quem decide o que é
 * oportunidade, porém, é `uonix_intelligence_seo_rules()`, em
 * `55-admin-intelligence-metrics.php` — e o 53 NÃO pode chamar o 55, porque carrega
 * antes dele. Inverter essa dependência seria pior que o problema.
 *
 * A consequência é um acoplamento entre dois arquivos que não se falam: a faixa de
 * RETENÇÃO de 53 precisa CONTER a faixa de SELEÇÃO de 55. Se alguém alargar a regra
 * de 55 sem alargar a retenção de 53, a regra passa a selecionar linha cujo texto o
 * 53 já descartou — e a oportunidade aparece sem termo, ou desaparece em silêncio,
 * que é pior. Este teste é o que torna esse acoplamento explícito e verificado em
 * vez de implícito e frágil.
 *
 * As asserções são de quatro tipos, de propósito, porque cada uma pega um erro que
 * as outras não pegam:
 *
 *   1. CONTINÊNCIA — comparação numérica das duas faixas, com mensagem que diz o que
 *      fazer. Pega o caso direto de alargar uma sem alargar a outra.
 *   2. MARGEM — a retenção tem de ser ESTRITAMENTE mais frouxa. Continência sozinha
 *      aceitaria as duas faixas idênticas, e aí qualquer alargamento futuro de 55 já
 *      nasce quebrado.
 *   3. IMPLICAÇÃO — roda a regra REAL de 55 sobre uma grade de linhas e exige que
 *      todo selecionado seja retido pela função REAL de 53. Pega divergência de
 *      OPERADOR, que a comparação numérica não vê.
 *   4. EQUIVALÊNCIA DE PONTA A PONTA — as oportunidades derivadas do universo
 *      minimizado têm de ser IDÊNTICAS às derivadas do universo íntegro, e a
 *      distribuição de posição e impressões tem de sobreviver inteira, porque é
 *      contra ela que o contrato manda recalibrar o piso.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$failures = 0;
$GLOBALS['uonix_metrics_options'] = array();
$GLOBALS['uonix_metrics_actions'] = array();
$GLOBALS['uonix_metrics_cron'] = array();

function uox_ret_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uonix_metrics_options'] ) ? $GLOBALS['uonix_metrics_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['uonix_metrics_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['uonix_metrics_options'] ) ) return false; $GLOBALS['uonix_metrics_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uonix_metrics_options'][ $key ] ); return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value ) { return true; }
function delete_transient( $key ) { return true; }
function wp_remote_retrieve_response_code( $response ) { return 0; }
function wp_remote_retrieve_body( $response ) { return ''; }
function wp_remote_post( $url, $args ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['uonix_metrics_actions'][] = array( $hook, $callback ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uonix_metrics_cron'][ $hook ] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['uonix_metrics_cron'][ $hook ] = $timestamp; return true; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function check_admin_referer() { return true; }
function esc_html__( $text ) { return $text; }
function wp_die( $text ) { throw new RuntimeException( $text ); }
function wp_safe_redirect() { return true; }
function admin_url( $path ) { return 'https://uonix.com.br/wp-admin/' . $path; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';

// ---------------------------------------------------------------------------
// Porta de entrada fail-closed.
//
// Um `if ( function_exists( … ) )` em volta de cada bloco faria um RENOMEIO passar
// verde: o bloco não rodaria e ninguém reprovaria. Aqui a ausência é falha, e o
// arquivo aborta antes de fingir que verificou algo.
// ---------------------------------------------------------------------------

foreach (
	array(
		'uonix_analytics_metrics_query_text_retention',
		'uonix_analytics_metrics_query_text_is_retained',
		'uonix_analytics_metrics_minimize_query_row',
		'uonix_analytics_metrics_normalize_search_console',
		'uonix_intelligence_seo_rules',
		'uonix_intelligence_seo_opportunities',
	) as $obrigatoria
) {
	uox_ret_assert( function_exists( $obrigatoria ), "Função obrigatória do acoplamento ausente: {$obrigatoria}()" );
}
if ( 0 !== $failures ) {
	fwrite( STDERR, "ABORTANDO: sem as funções acima não há acoplamento a verificar, e seguir daria verde por omissão.\n" );
	exit( 1 );
}

$retention = uonix_analytics_metrics_query_text_retention();
$rules     = uonix_intelligence_seo_rules();

/**
 * Snapshot v3 válido com o universo informado.
 */
function uox_ret_snapshot( array $universe ) {
	return array(
		'version'        => 3,
		'period_days'    => 30,
		'status'         => 'updated',
		'updated_at'     => gmdate( 'c' ),
		'periods'        => array(),
		'ga4'            => array(),
		'search_console' => array(
			'summary'          => array(),
			'queries'          => array_slice( $universe, 0, 10 ),
			'queries_extended' => $universe,
			'pages'            => array(),
		),
	);
}

// ---------------------------------------------------------------------------
// 1. CONTINÊNCIA — a faixa de retenção de 53 contém a faixa de seleção de 55.
// ---------------------------------------------------------------------------

$como_consertar = 'Conserte alargando uonix_analytics_metrics_query_text_retention() em '
	. 'mu-plugins/uonix-admin/53-admin-analytics-metrics.php, NUNCA estreitando a regra de 55 para '
	. 'caber na retenção: a regra é o produto, a retenção é a política de dado.';

uox_ret_assert(
	$retention['min_position'] <= $rules['min_position'],
	sprintf(
		'CONTINÊNCIA (posição mínima): a retenção de 53 começa em %s, mas a regra de 55 começa em %s. '
		. 'A regra selecionaria linha em posição %s cujo texto o 53 já descartou. %s',
		(string) $retention['min_position'],
		(string) $rules['min_position'],
		(string) $rules['min_position'],
		$como_consertar
	)
);

uox_ret_assert(
	$retention['max_position'] >= $rules['max_position'],
	sprintf(
		'CONTINÊNCIA (posição máxima): a retenção de 53 termina em %s, mas a regra de 55 termina em %s. '
		. 'A regra selecionaria linha em posição %s cujo texto o 53 já descartou. %s',
		(string) $retention['max_position'],
		(string) $rules['max_position'],
		(string) $rules['max_position'],
		$como_consertar
	)
);

// O piso de 55 é EXCLUSIVO (`$impressions <= min_impressions` descarta) e o de 53 é
// inclusivo, então `<=` aqui já é suficiente: a menor impressão que 55 admite é
// estritamente maior que o piso dele, e a retenção admite a partir do piso dela.
uox_ret_assert(
	$retention['min_impressions'] <= $rules['min_impressions'],
	sprintf(
		'CONTINÊNCIA (impressões): o piso de retenção de 53 é %s, acima do piso da regra de 55 (%s). '
		. 'A regra selecionaria linha com menos impressões do que o 53 guarda texto. %s',
		(string) $retention['min_impressions'],
		(string) $rules['min_impressions'],
		$como_consertar
	)
);

// ---------------------------------------------------------------------------
// 2. MARGEM — a retenção é ESTRITAMENTE mais frouxa, por desenho.
//
// Continência sozinha aceitaria as duas faixas coladas. Coladas, o acoplamento
// existe mas não tem folga: o próximo ajuste de 55 nasce quebrado, e quem o fizer
// descobre no build em vez de no desenho. A margem é o que permite mexer em 55 sem
// tocar em 53.
// ---------------------------------------------------------------------------

uox_ret_assert(
	$retention['min_position'] < $rules['min_position']
	&& $retention['max_position'] > $rules['max_position']
	&& $retention['min_impressions'] < $rules['min_impressions'],
	sprintf(
		'MARGEM: a retenção de 53 (posição %s a %s, %s+ impressões) precisa ser ESTRITAMENTE mais frouxa '
		. 'que a regra de 55 (posição %s a %s, mais de %s impressões), e hoje encosta em pelo menos uma '
		. 'fronteira. Sem folga, qualquer alargamento de 55 passa a exigir alteração em 53. %s',
		(string) $retention['min_position'],
		(string) $retention['max_position'],
		(string) $retention['min_impressions'],
		(string) $rules['min_position'],
		(string) $rules['max_position'],
		(string) $rules['min_impressions'],
		$como_consertar
	)
);

// ---------------------------------------------------------------------------
// 3. IMPLICAÇÃO — roda as DUAS funções reais sobre uma grade que cruza as duas
//    fronteiras. Pega divergência de operador, que comparar números não pega.
//
// A grade varia CINCO eixos, e não três, porque a propriedade só cobre os eixos
// que ela varia. Um critério novo em 55 sobre um eixo mantido constante aqui
// passaria por omissão — e a consequência é a pior possível: a oportunidade
// desaparece **em silêncio**, porque o `isset()` de 55 pula a linha sem texto.
//
// `clicks` e o TEXTO entraram por isso. Uma regra de produto plausível — "consulta
// de marca é sempre oportunidade" ou "quem já tem clique merece atenção" — cria
// caminho disjuntivo nesses eixos, e sem variá-los a suíte inteira fica verde
// enquanto uma oportunidade real se perde.
// Os eixos de `clicks` e de TEXTO entram num conjunto DIRIGIDO, acrescentado à
// grade, e não multiplicados no produto cartesiano: multiplicar estouraria o teto
// de `extended_query_limit()`, e a asserção de tamanho logo abaixo reprovaria por
// motivo errado — o `break` do normalizador cortaria linhas e a equivalência
// compararia conjuntos diferentes.
$grade = array();
$indice = 0;
foreach ( array( 0.5, 2.9, 3.0, 3.1, 3.9, 4.0, 4.1, 8.0, 11.9, 12.0, 12.1, 14.9, 15.0, 15.1, 40.0 ) as $posicao ) {
	foreach ( array( 0, 1, 2, 3, 4, 5, 6, 7, 20, 120 ) as $impressoes ) {
		foreach ( array( 0.0, 0.01, 0.029, 0.03, 0.5 ) as $ctr ) {
			++$indice;
			$grade[] = array(
				'query'       => 'consulta grade ' . $indice,
				'clicks'      => 0,
				'impressions' => $impressoes,
				'ctr'         => $ctr,
				'position'    => $posicao,
			);
		}
	}
}

// Conjunto dirigido: varre `clicks` e TEXTO na região onde uma regra disjuntiva
// faria diferença — dentro e fora das duas faixas. Termos reais do site, porque
// uma regra de produto plausível reconhece marca ou norma, não string sintética.
foreach ( array( 2.0, 3.5, 4.5, 11.0, 13.0, 20.0 ) as $posicao ) {
	foreach ( array( 2, 4, 6, 30, 120 ) as $impressoes ) {
		foreach ( array( 0, 1, 9 ) as $cliques ) {
			foreach ( array( 'linha de vida uonix', 'nbr 16325 ancoragem' ) as $termo ) {
				++$indice;
				$grade[] = array(
					'query'       => $termo . ' ' . $indice,
					'clicks'      => $cliques,
					'impressions' => $impressoes,
					'ctr'         => 0.01,
					'position'    => $posicao,
				);
			}
		}
	}
}

uox_ret_assert(
	count( $grade ) < uonix_analytics_metrics_extended_query_limit(),
	'A grade precisa caber sob o teto do universo, senão o `break` do normalizador corta linhas e a '
	. 'equivalência de ponta a ponta compararia conjuntos diferentes.'
);

$selecionadas = uonix_intelligence_seo_opportunities( uox_ret_snapshot( $grade ), 10000 );

// GUARDA ANTI-VÁCUO: sem esta asserção, a implicação abaixo passaria com zero linhas
// selecionadas — exatamente o modo de falha que este repositório já registrou.
uox_ret_assert(
	true === $selecionadas['available'] && count( $selecionadas['rows'] ) > 0,
	'A grade tem de produzir oportunidades: uma implicação sobre conjunto vazio é verdadeira por vácuo '
	. 'e não prova acoplamento nenhum.'
);

$escapam = array();
foreach ( $selecionadas['rows'] as $linha ) {
	if ( ! uonix_analytics_metrics_query_text_is_retained( $linha['position'], $linha['impressions'] ) ) {
		$escapam[] = sprintf( 'posição %s com %s impressões', (string) $linha['position'], (string) $linha['impressions'] );
	}
}
uox_ret_assert(
	array() === $escapam,
	sprintf(
		'IMPLICAÇÃO: a regra de 55 seleciona %d linha(s) cujo texto a retenção de 53 descarta — %s. '
		. 'Essas oportunidades chegariam ao painel e ao e-mail sem o termo da consulta. %s',
		count( $escapam ),
		implode( ', ', array_slice( $escapam, 0, 5 ) ),
		$como_consertar
	)
);

// ---------------------------------------------------------------------------
// 4. EQUIVALÊNCIA DE PONTA A PONTA — passa a grade pelo normalizador REAL de 53 e
//    compara as oportunidades derivadas do universo minimizado com as derivadas do
//    universo íntegro.
// ---------------------------------------------------------------------------

$normalizado = uonix_analytics_metrics_normalize_search_console(
	array(
		'summary_current'  => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
		'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
		'queries'          => $grade,
		'pages'            => array(),
	)
);
uox_ret_assert( is_array( $normalizado ) && isset( $normalizado['queries_extended'] ), 'Normalizador devolveu o universo de mineração' );
$minimizado = $normalizado['queries_extended'];

// Reconstrução INDEPENDENTE do universo íntegro: os mesmos números da grade com o
// mesmo cast que o normalizador aplica, sem derivar nada do minimizador. Derivar do
// minimizador tornaria a comparação circular.
$integro = array();
foreach ( $grade as $linha ) {
	$integro[] = array(
		'query'       => $linha['query'],
		'clicks'      => (float) $linha['clicks'],
		'impressions' => (float) $linha['impressions'],
		'ctr'         => (float) $linha['ctr'],
		'position'    => (float) $linha['position'],
	);
}

// GUARDAS ANTI-VÁCUO da minimização: as duas direções precisam ter ocorrido de fato.
// Sem elas, uma minimização que não minimiza nada — ou que apaga tudo — satisfaria a
// equivalência de oportunidades por caminhos diferentes.
$sem_texto = 0;
$com_texto = 0;
foreach ( $minimizado as $linha ) {
	if ( array_key_exists( 'query', $linha ) ) {
		++$com_texto;
	} else {
		++$sem_texto;
	}
}
uox_ret_assert( $sem_texto > 0, 'A minimização tem de ter descartado o texto de ALGUMA linha; se descartou zero, ela não está minimizando nada.' );
uox_ret_assert( $com_texto > 0, 'A minimização tem de ter PRESERVADO o texto de alguma linha; se preservou zero, o módulo perdeu o termo de toda oportunidade.' );

// MÉTRICA DE TODA LINHA: o que sai é o texto, nunca o número.
uox_ret_assert(
	count( $minimizado ) === count( $integro ),
	sprintf(
		'O universo minimizado tem %d linha(s) e o íntegro %d: a minimização removeu LINHA, não apenas '
		. 'texto. Isso destrói a distribuição contra a qual o contrato manda recalibrar o piso.',
		count( $minimizado ),
		count( $integro )
	)
);

$metricas_divergentes = array();
foreach ( $integro as $posicao_na_lista => $linha_integra ) {
	$linha_minimizada = $minimizado[ $posicao_na_lista ] ?? array();
	foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $metrica ) {
		if ( ! array_key_exists( $metrica, $linha_minimizada ) || $linha_minimizada[ $metrica ] !== $linha_integra[ $metrica ] ) {
			$metricas_divergentes[] = $posicao_na_lista . ':' . $metrica;
		}
	}
}
uox_ret_assert(
	array() === $metricas_divergentes,
	sprintf(
		'MÉTRICA PERDIDA em %d ponto(s) (%s…): clicks, impressions, ctr e position precisam sobreviver em '
		. 'TODA linha. A recalibração do piso é feita contra a distribuição medida — que é de impressões e '
		. 'posição, não de texto —, então preservá-la é obrigatório e preservar o texto não é.',
		count( $metricas_divergentes ),
		implode( ', ', array_slice( $metricas_divergentes, 0, 5 ) )
	)
);

// DISTRIBUIÇÃO: o par (posição, impressões) de cada linha, como multiconjunto.
$distribuicao_integra = array();
foreach ( $integro as $linha ) { $distribuicao_integra[] = $linha['position'] . '|' . $linha['impressions']; }
$distribuicao_minimizada = array();
foreach ( $minimizado as $linha ) { $distribuicao_minimizada[] = ( $linha['position'] ?? 'AUSENTE' ) . '|' . ( $linha['impressions'] ?? 'AUSENTE' ); }
sort( $distribuicao_integra );
sort( $distribuicao_minimizada );
uox_ret_assert(
	$distribuicao_integra === $distribuicao_minimizada,
	'DISTRIBUIÇÃO alterada: o multiconjunto de (posição, impressões) do universo minimizado tem de ser '
	. 'idêntico ao do universo íntegro. É esse multiconjunto que responde "quantas linhas entrariam se o '
	. 'piso caísse de 5 para 3", e é a única razão pela qual as métricas ficam.'
);

// OPORTUNIDADES IDÊNTICAS: o teste que fecha a Parte A.
$antes  = uonix_intelligence_seo_opportunities( uox_ret_snapshot( $integro ), 10000 );
$depois = uonix_intelligence_seo_opportunities( uox_ret_snapshot( $minimizado ), 10000 );

uox_ret_assert(
	true === $antes['available'] && count( $antes['rows'] ) > 0,
	'O universo íntegro tem de produzir oportunidades, senão a igualdade abaixo é entre dois vazios.'
);
uox_ret_assert(
	$antes['rows'] === $depois['rows'],
	sprintf(
		'EQUIVALÊNCIA rompida: o universo íntegro produz %d oportunidade(s) e o minimizado %d, ou com '
		. 'conteúdo diferente. A minimização de texto não pode alterar nenhuma linha que a regra devolve — '
		. 'nem o termo, nem a sugestão de Title derivada dele. %s',
		count( $antes['rows'] ),
		count( $depois['rows'] ),
		$como_consertar
	)
);
uox_ret_assert(
	$antes['universe'] === $depois['universe'] && count( $grade ) === $depois['universe'],
	sprintf(
		'DENOMINADOR alterado: o universo declarado passou de %d para %d (entrada: %d). O painel publica '
		. '"N de M", e M é a contagem do universo.',
		(int) $antes['universe'],
		(int) $depois['universe'],
		count( $grade )
	)
);

// ---------------------------------------------------------------------------
// 5. FRONTEIRAS DA PRÓPRIA RETENÇÃO — pinam os operadores documentados no docblock
//    de uonix_analytics_metrics_query_text_retention() (pisos inclusivos).
// ---------------------------------------------------------------------------

$fronteiras = array(
	array( 'posicao' => 3.0,  'impressoes' => 3,   'retem' => true,  'nota' => 'piso de posição é INCLUSIVO' ),
	array( 'posicao' => 2.9,  'impressoes' => 3,   'retem' => false, 'nota' => 'abaixo do piso de posição o texto sai' ),
	array( 'posicao' => 15.0, 'impressoes' => 3,   'retem' => true,  'nota' => 'teto de posição é INCLUSIVO' ),
	array( 'posicao' => 15.1, 'impressoes' => 3,   'retem' => false, 'nota' => 'acima do teto de posição o texto sai' ),
	array( 'posicao' => 8.0,  'impressoes' => 2,   'retem' => false, 'nota' => 'abaixo do piso de impressões o texto sai' ),
	array( 'posicao' => 8.0,  'impressoes' => 3,   'retem' => true,  'nota' => 'piso de impressões é INCLUSIVO' ),
);
foreach ( $fronteiras as $caso ) {
	$linha = array( 'query' => 'linha de vida', 'clicks' => 0, 'impressions' => $caso['impressoes'], 'ctr' => 0.0, 'position' => $caso['posicao'] );
	$resultado = uonix_analytics_metrics_minimize_query_row( $linha );
	uox_ret_assert(
		array_key_exists( 'query', $resultado ) === $caso['retem'],
		sprintf( 'FRONTEIRA (posição %s, %s impressões): %s', (string) $caso['posicao'], (string) $caso['impressoes'], $caso['nota'] )
	);
	uox_ret_assert(
		4 === count( array_intersect_key( $resultado, array( 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1 ) ) ),
		sprintf( 'FRONTEIRA (posição %s, %s impressões): as quatro métricas continuam presentes', (string) $caso['posicao'], (string) $caso['impressoes'] )
	);
}

// Métrica não finita: falha fechada, o texto sai.
$nao_finita = uonix_analytics_metrics_minimize_query_row( array( 'query' => 'linha de vida', 'clicks' => 0, 'impressions' => 'x', 'ctr' => 0.0, 'position' => 8.0 ) );
uox_ret_assert( ! array_key_exists( 'query', $nao_finita ), 'Métrica não numérica descarta o texto (falha fechada), porque a regra de 55 nunca selecionaria essa linha.' );

if ( 0 !== $failures ) {
	exit( 1 );
}

printf(
	"PASS: retenção de texto de 53 contém a regra de 55 com margem; %d oportunidades sobrevivem à minimização de %d linhas (%d sem texto, %d com texto).\n",
	count( $depois['rows'] ),
	count( $minimizado ),
	$sem_texto,
	$com_texto
);
