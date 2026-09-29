<?php
/**
 * Testes da Central de Inteligência — camada de dados (Módulo 3, Oportunidades SEO).
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
$GLOBALS['uonix_metrics_filtros'] = array();

// Quem grava a opção dos destinatários: só o dono (49). Interruptor para os testes
// da trava de gravação.
$GLOBALS['uox_dono'] = false;
function uonix_ksio_can_configure_insights() { return $GLOBALS['uox_dono']; }

function uonix_intel_assert( $condition, $message ) {
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
// Segue o core: aplica `pre_update_option_{$nome}` e desiste se o valor voltar igual
// ao antigo, inclusive na primeira gravação, em que o antigo é `false`.
function update_option( $key, $value, $autoload = null ) {
	$antigo = get_option( $key );
	foreach ( $GLOBALS['uonix_metrics_filtros'][ 'pre_update_option_' . $key ] ?? array() as $filtro ) {
		$value = call_user_func( $filtro[0], $value, $antigo, $key );
	}
	if ( $value === $antigo || serialize( $value ) === serialize( $antigo ) ) {
		return false;
	}
	$GLOBALS['uonix_metrics_options'][ $key ] = $value;
	return true;
}
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
function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['uonix_metrics_filtros'][ $hook ][] = array( $callback, $priority, $args );
	return true;
}
function wp_next_scheduled( $hook ) { return $GLOBALS['uonix_metrics_cron'][ $hook ] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['uonix_metrics_cron'][ $hook ] = $timestamp; return true; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function check_admin_referer() { return true; }
function esc_html__( $text ) { return $text; }
function wp_die( $text ) { throw new RuntimeException( $text ); }
// Lança, em vez de só devolver true, para o handler de destinatários não chegar ao
// `exit;` que vem depois do redirect e derrubar o próprio processo do teste.
class Uox_Metrics_Redirect_Exception extends RuntimeException {}
function wp_safe_redirect( $url ) { throw new Uox_Metrics_Redirect_Exception( (string) $url ); }
function admin_url( $path ) { return 'https://uonix.com.br/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';

/**
 * Monta um snapshot v3 válido com o universo de consultas informado.
 */
function uonix_intel_snapshot( array $queries_extended, $updated_at = null, $period_days = 30, $status = 'updated' ) {
	return array(
		'version' => 3,
		'period_days' => $period_days,
		'status' => $status,
		'updated_at' => null === $updated_at ? gmdate( 'c' ) : $updated_at,
		'periods' => array(),
		'ga4' => array(),
		'search_console' => array(
			'summary' => array(),
			'queries' => array_slice( $queries_extended, 0, 10 ),
			'queries_extended' => $queries_extended,
			'pages' => array(),
		),
	);
}

function uonix_intel_query( $query, $position, $impressions, $ctr, $clicks = 1 ) {
	return array( 'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position );
}

// ---------------------------------------------------------------------------
// Limiares: posição 4 a 12, CTR abaixo de 3%, e piso de ruído de 5 impressões.
// O piso NÃO é critério de relevância — quem prioriza é a ordenação por volume.
// ---------------------------------------------------------------------------
$rules = uonix_intelligence_seo_rules();
uonix_intel_assert(
	4.0 === $rules['min_position'] && 12.0 === $rules['max_position'] && 5 === $rules['min_impressions'] && 0.03 === $rules['max_ctr'] && 30 === $rules['period_days'],
	'Limiares seguem o contrato: posição 4 a 12, CTR abaixo de 3%, piso de 5 impressões, janela de 30 dias'
);

$boundaries = uonix_intelligence_seo_opportunities(
	uonix_intel_snapshot(
		array(
			uonix_intel_query( 'posicao acima da faixa', 3.9, 500, .01 ),
			uonix_intel_query( 'posicao no limite inferior', 4.0, 400, .01 ),
			uonix_intel_query( 'posicao no limite superior', 12.0, 300, .01 ),
			uonix_intel_query( 'posicao abaixo da faixa', 12.1, 600, .01 ),
			uonix_intel_query( 'impressoes no piso', 6.0, 5, .01 ),
			uonix_intel_query( 'impressoes acima do piso', 6.0, 6, .01 ),
			uonix_intel_query( 'ctr no limite', 6.0, 200, .03 ),
			uonix_intel_query( 'ctr abaixo do limite', 6.0, 199, .029 ),
		)
	),
	20
);
$terms = array_column( $boundaries['rows'], 'query' );
uonix_intel_assert( true === $boundaries['available'], 'Snapshot v3 completo produz resposta disponível' );
uonix_intel_assert( ! in_array( 'posicao acima da faixa', $terms, true ), 'Posição melhor que 4 fica fora: não é distância de salto' );
uonix_intel_assert( in_array( 'posicao no limite inferior', $terms, true ), 'Posição 4 entra na faixa' );
uonix_intel_assert( in_array( 'posicao no limite superior', $terms, true ), 'Posição 12 entra na faixa' );
uonix_intel_assert( ! in_array( 'posicao abaixo da faixa', $terms, true ), 'Posição pior que 12 fica fora' );
uonix_intel_assert( ! in_array( 'impressoes no piso', $terms, true ), 'Exatamente 5 impressões não passa do piso de ruído' );
uonix_intel_assert( in_array( 'impressoes acima do piso', $terms, true ), '6 impressões passa do piso' );
uonix_intel_assert( ! in_array( 'ctr no limite', $terms, true ), 'CTR exatamente 3% não satisfaz "abaixo de 3%"' );
uonix_intel_assert( in_array( 'ctr abaixo do limite', $terms, true ), 'CTR de 2,9% entra' );

// ---------------------------------------------------------------------------
// Regressão que os testes anteriores NÃO pegavam.
//
// Todas as fixtures acima usam centenas de impressões, volume que este site não
// tem. Medido em produção em 2026-09-22: a consulta de maior volume do site
// inteiro tem 106 impressões em 30 dias, e as que estão na faixa de posição têm
// 53, 30, 28, 22, 10, 8... Com o limiar antigo de 100, o módulo devolvia zero por
// construção — e passava em mais de cem testes, porque todos os dados sintéticos
// eram de um site grande.
//
// Este caso fixa a escala real: um universo assim TEM que produzir resultado.
// ---------------------------------------------------------------------------
$escala_real = uonix_intelligence_seo_opportunities(
	uonix_intel_snapshot(
		array(
			uonix_intel_query( 'linha de vida nbr 16325', 6.4, 53, .012 ),
			uonix_intel_query( 'ensaio de arrancamento', 8.1, 30, .008 ),
			uonix_intel_query( 'olhal de ancoragem inox', 5.2, 28, .015 ),
			uonix_intel_query( 'laudo de ancoragem predial', 11.7, 22, .004 ),
			uonix_intel_query( 'ancoragem nr 35', 9.3, 10, .020 ),
			uonix_intel_query( 'termo de volume irrelevante', 7.0, 3, .010 ),
		)
	),
	5
);
uonix_intel_assert( 5 === count( $escala_real['rows'] ), 'Universo na escala real do site produz cinco oportunidades, não zero' );
uonix_intel_assert( 'linha de vida nbr 16325' === $escala_real['rows'][0]['query'], 'A oportunidade de maior volume vem primeiro' );
uonix_intel_assert( ! in_array( 'termo de volume irrelevante', array_column( $escala_real['rows'], 'query' ), true ), 'Consulta abaixo do piso de ruído fica fora mesmo com vaga sobrando' );

// ---------------------------------------------------------------------------
// Ordenação por volume, com desempate estável, e respeito ao limite.
// ---------------------------------------------------------------------------
$ordering = uonix_intelligence_seo_opportunities(
	uonix_intel_snapshot(
		array(
			uonix_intel_query( 'volume medio', 5.0, 300, .01 ),
			uonix_intel_query( 'volume alto', 5.0, 900, .01 ),
			uonix_intel_query( 'b empate', 5.0, 500, .01 ),
			uonix_intel_query( 'a empate', 5.0, 500, .01 ),
		)
	),
	10
);
$ordered = array_column( $ordering['rows'], 'query' );
uonix_intel_assert( array( 'volume alto', 'a empate', 'b empate', 'volume medio' ) === $ordered, 'Ordena por impressões desc com desempate alfabético estável' );

$limited = uonix_intelligence_seo_opportunities(
	uonix_intel_snapshot(
		array(
			uonix_intel_query( 'um', 5.0, 900, .01 ),
			uonix_intel_query( 'dois', 5.0, 800, .01 ),
			uonix_intel_query( 'tres', 5.0, 700, .01 ),
		)
	),
	2
);
uonix_intel_assert( 2 === count( $limited['rows'] ), 'Limite de linhas é respeitado' );
uonix_intel_assert( 3 === $limited['universe'], 'Universo reporta o total peneirado, não o total devolvido' );

// ---------------------------------------------------------------------------
// Procedência: fonte, horário de sincronização e estado de frescor por bloco.
// ---------------------------------------------------------------------------
$fresh = uonix_intelligence_seo_opportunities( uonix_intel_snapshot( array( uonix_intel_query( 'olhal de ancoragem', 5.0, 500, .01 ) ) ) );
uonix_intel_assert( 'search_console' === $fresh['source'] && '' !== $fresh['synced_at'], 'Resposta declara fonte e horário de sincronização' );
uonix_intel_assert( false === $fresh['stale'], 'Snapshot recente não é marcado como desatualizado' );

$old = uonix_intelligence_seo_opportunities( uonix_intel_snapshot( array( uonix_intel_query( 'olhal de ancoragem', 5.0, 500, .01 ) ), gmdate( 'c', time() - ( 3 * DAY_IN_SECONDS ) ) ) );
uonix_intel_assert( true === $old['available'] && true === $old['stale'], 'Snapshot vencido continua utilizável, mas se declara desatualizado' );

$marked_stale = uonix_intelligence_seo_opportunities( uonix_intel_snapshot( array( uonix_intel_query( 'olhal de ancoragem', 5.0, 500, .01 ) ), null, 30, 'stale' ) );
uonix_intel_assert( true === $marked_stale['stale'], 'Status stale do snapshot é propagado ao bloco' );

// ---------------------------------------------------------------------------
// Indisponibilidade é estado próprio, distinto de "nenhuma oportunidade".
// ---------------------------------------------------------------------------
$v2 = uonix_intel_snapshot( array( uonix_intel_query( 'olhal de ancoragem', 5.0, 500, .01 ) ) );
unset( $v2['search_console']['queries_extended'] );
$v2['version'] = 2;
$missing = uonix_intelligence_seo_opportunities( $v2 );
uonix_intel_assert( false === $missing['available'] && 'queries_extended_missing' === $missing['reason'], 'Snapshot v2 sem universo de mineração é indisponível, não vazio' );
uonix_intel_assert( array() === $missing['rows'], 'Indisponível não inventa linhas' );

$mismatch = uonix_intelligence_seo_opportunities( uonix_intel_snapshot( array( uonix_intel_query( 'olhal de ancoragem', 5.0, 500, .01 ) ), null, 7 ) );
uonix_intel_assert( false === $mismatch['available'] && 'period_mismatch' === $mismatch['reason'], 'Limiar de 30 dias não é aplicado a snapshot de outro período' );

$GLOBALS['uonix_metrics_options'] = array();
$absent = uonix_intelligence_seo_opportunities();
uonix_intel_assert( false === $absent['available'] && 'snapshot_missing' === $absent['reason'], 'Ausência de snapshot é indisponibilidade declarada' );

$empty = uonix_intelligence_seo_opportunities( uonix_intel_snapshot( array( uonix_intel_query( 'termo no topo', 1.5, 900, .40 ) ) ) );
uonix_intel_assert( true === $empty['available'] && array() === $empty['rows'], 'Universo sem candidato é disponível com zero linhas, estado distinto de indisponível' );

// ---------------------------------------------------------------------------
// Migração real: mark_stale() lê em cascata e grava na chave corrente, então um
// payload antigo pode acabar sob a chave nova. A camada tem que dizer a verdade
// sobre o que encontrou, em vez de culpar o período.
// ---------------------------------------------------------------------------
$GLOBALS['uonix_metrics_options'] = array(
	'uonix_analytics_metrics_snapshot_v2_30' => array(
		'version' => 2,
		'period_days' => 30,
		'status' => 'updated',
		'updated_at' => gmdate( 'c' ),
		'ga4' => array(),
		'search_console' => array( 'queries' => array(), 'pages' => array() ),
	),
);
uonix_analytics_metrics_mark_stale( 30 );
$promoted = uonix_intelligence_seo_opportunities();
uonix_intel_assert( false === $promoted['available'] && 'queries_extended_missing' === $promoted['reason'], 'Payload v2 promovido à chave corrente é reconhecido como universo ausente' );

$GLOBALS['uonix_metrics_options'] = array(
	'uonix_analytics_metrics_snapshot_v1' => array(
		'version' => 1,
		'status' => 'updated',
		'updated_at' => gmdate( 'c' ),
	),
);
uonix_analytics_metrics_mark_stale( 30 );
$legacy = uonix_intelligence_seo_opportunities();
uonix_intel_assert( false === $legacy['available'] && 'snapshot_legacy' === $legacy['reason'], 'Snapshot legado sem period_days é reportado como legado, nunca como período divergente' );
$GLOBALS['uonix_metrics_options'] = array();

// ---------------------------------------------------------------------------
// Sugestão determinística de Title.
// ---------------------------------------------------------------------------
$plain = uonix_intelligence_title_suggestion( 'olhal de ancoragem' );
uonix_intel_assert( array( 'Aço Inox 304/316', 'Laudo com ART' ) === $plain, 'Consulta sem diferencial recebe os dois primeiros da lista, em ordem fixa' );
uonix_intel_assert( $plain === uonix_intelligence_title_suggestion( 'linha de vida' ), 'Sugestão depende da cobertura de diferenciais, não do texto da consulta' );

$has_inox = uonix_intelligence_title_suggestion( 'olhal de ancoragem inox' );
uonix_intel_assert( ! in_array( 'Aço Inox 304/316', $has_inox, true ), 'Diferencial já presente na consulta não é sugerido' );

$accented = uonix_intelligence_title_suggestion( 'OLHAL AÇO INOX 304' );
uonix_intel_assert( ! in_array( 'Aço Inox 304/316', $accented, true ), 'Comparação ignora caixa e acento' );

$covered = uonix_intelligence_title_suggestion( 'olhal inox com laudo art conforme nbr ensaio de arrancamento e prazo de entrega' );
uonix_intel_assert( array() === $covered, 'Consulta que cobre todos os diferenciais não recebe sugestão inventada' );
uonix_intel_assert( array() === uonix_intelligence_title_suggestion( '' ), 'Consulta vazia não gera sugestão' );

$row_suggestion = $fresh['rows'][0]['suggestion'];
uonix_intel_assert( is_array( $row_suggestion ) && array() !== $row_suggestion, 'Cada linha devolvida carrega sua sugestão determinística' );

// ---------------------------------------------------------------------------
// Trava de gravação dos destinatários (#318): só o dono muda a opção, por
// qualquer caminho que passe por update_option(), inclusive /wp-admin/options.php.
// ---------------------------------------------------------------------------
$filtro_destinatarios = $GLOBALS['uonix_metrics_filtros'][ 'pre_update_option_' . uonix_intelligence_recipients_option() ][0] ?? array( null, 0, 0 );
uonix_intel_assert(
	'uonix_intelligence_recipients_guard_write' === $filtro_destinatarios[0] && PHP_INT_MAX === $filtro_destinatarios[1] && 2 === $filtro_destinatarios[2],
	'a trava dos destinatários roda por último no filtro e recebe valor novo e antigo; obteve ' . var_export( $filtro_destinatarios, true )
);

$GLOBALS['uonix_metrics_options'] = array();
$GLOBALS['uox_dono'] = false;
uonix_intel_assert( false === update_option( uonix_intelligence_recipients_option(), array( 'a@uonix.test' ) ), 'quem não é o dono não cria a opção dos destinatários' );
uonix_intel_assert( ! array_key_exists( uonix_intelligence_recipients_option(), $GLOBALS['uonix_metrics_options'] ), 'a primeira gravação também é barrada' );

$GLOBALS['uonix_metrics_options'][ uonix_intelligence_recipients_option() ] = array( 'antigo@uonix.test' );
update_option( uonix_intelligence_recipients_option(), array( 'novo@uonix.test' ) );
uonix_intel_assert( array( 'antigo@uonix.test' ) === get_option( uonix_intelligence_recipients_option() ), 'quem não é o dono não muda a lista existente pelo update_option (caminho do options.php)' );

$GLOBALS['uox_dono'] = true;
update_option( uonix_intelligence_recipients_option(), array( 'novo@uonix.test' ) );
uonix_intel_assert( array( 'novo@uonix.test' ) === get_option( uonix_intelligence_recipients_option() ), 'o dono muda a lista dos destinatários pelo update_option' );
$GLOBALS['uox_dono'] = false;
$GLOBALS['uonix_metrics_options'] = array();

// O handler do dono continua gravando a lista, com a trava de update_option ativa:
// a checagem do handler e a trava do filtro não podem se atropelar.
$GLOBALS['uox_dono'] = true;
$_POST = array( 'uonix_recipients' => 'dono@uonix.test' );
try {
	uonix_intelligence_save_recipients();
	uonix_intel_assert( false, 'o handler do dono deveria redirecionar' );
} catch ( Uox_Metrics_Redirect_Exception $e ) {
	uonix_intel_assert( false !== strpos( $e->getMessage(), 'uonix_recipients_saved=1' ), 'o redirect do dono informa que salvou; obteve ' . $e->getMessage() );
}
uonix_intel_assert( array( 'dono@uonix.test' ) === get_option( uonix_intelligence_recipients_option() ), 'o handler do dono grava a lista com a trava de update_option ativa' );
$GLOBALS['uox_dono'] = false;
$GLOBALS['uonix_metrics_options'] = array();

// Em processo separado, a trava carregada sozinha, com o arquivo real do 55: sem o
// 49 (e fora do WP-CLI) ela recusa; no WP-CLI ela passa.
function uonix_intel_sub( $prefixo, $expressao, $arquivo ) {
	$codigo = 'define("ABSPATH", 1); function add_action() {} function add_filter() {} ' . $prefixo
		. ' require ' . var_export( $arquivo, true ) . '; echo json_encode(' . $expressao . ');';
	$saida = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $codigo ) . ' 2>&1' );
	return json_decode( (string) $saida, true );
}
$arquivo_55 = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';

$r = uonix_intel_sub( '', 'uonix_intelligence_recipients_guard_write("novo", "velho")', $arquivo_55 );
uonix_intel_assert( 'velho' === $r, 'sem o 49 a trava dos destinatários mantém o valor antigo; obteve ' . var_export( $r, true ) );
$r = uonix_intel_sub( 'define("WP_CLI", true);', 'uonix_intelligence_recipients_guard_write("novo", "velho")', $arquivo_55 );
uonix_intel_assert( 'novo' === $r, 'no WP-CLI a trava dos destinatários deixa gravar; obteve ' . var_export( $r, true ) );
$r = uonix_intel_sub( 'define("WP_CLI", false);', 'uonix_intelligence_recipients_guard_write("novo", "velho")', $arquivo_55 );
uonix_intel_assert( 'velho' === $r, 'WP_CLI definida como false não libera a trava dos destinatários; obteve ' . var_export( $r, true ) );
$r = uonix_intel_sub( 'function uonix_ksio_can_configure_insights() { return false; }', 'uonix_intelligence_recipients_guard_write("novo", "velho")', $arquivo_55 );
uonix_intel_assert( 'velho' === $r, 'o 49 dizendo que não é o dono mantém o valor antigo na trava dos destinatários; obteve ' . var_export( $r, true ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: oportunidades de SEO filtradas por distância de salto, com procedência e indisponibilidade explícitas.\n";
