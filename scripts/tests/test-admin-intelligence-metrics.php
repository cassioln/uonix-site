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
// Limiares: posição 4 a 12, mais de 100 impressões, CTR abaixo de 3%.
// ---------------------------------------------------------------------------
$rules = uonix_intelligence_seo_rules();
uonix_intel_assert(
	4.0 === $rules['min_position'] && 12.0 === $rules['max_position'] && 100 === $rules['min_impressions'] && 0.03 === $rules['max_ctr'] && 30 === $rules['period_days'],
	'Limiares seguem o contrato: posição 4 a 12, mais de 100 impressões, CTR abaixo de 3%, janela de 30 dias'
);

$boundaries = uonix_intelligence_seo_opportunities(
	uonix_intel_snapshot(
		array(
			uonix_intel_query( 'posicao acima da faixa', 3.9, 500, .01 ),
			uonix_intel_query( 'posicao no limite inferior', 4.0, 400, .01 ),
			uonix_intel_query( 'posicao no limite superior', 12.0, 300, .01 ),
			uonix_intel_query( 'posicao abaixo da faixa', 12.1, 600, .01 ),
			uonix_intel_query( 'impressoes no limite', 6.0, 100, .01 ),
			uonix_intel_query( 'impressoes acima do limite', 6.0, 101, .01 ),
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
uonix_intel_assert( ! in_array( 'impressoes no limite', $terms, true ), 'Exatamente 100 impressões não satisfaz "mais de 100"' );
uonix_intel_assert( in_array( 'impressoes acima do limite', $terms, true ), '101 impressões satisfaz o volume mínimo' );
uonix_intel_assert( ! in_array( 'ctr no limite', $terms, true ), 'CTR exatamente 3% não satisfaz "abaixo de 3%"' );
uonix_intel_assert( in_array( 'ctr abaixo do limite', $terms, true ), 'CTR de 2,9% entra' );

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
// Sugestão determinística de Title.
// ---------------------------------------------------------------------------
$plain = uonix_intelligence_title_suggestion( 'olhal de ancoragem' );
uonix_intel_assert( array( 'Aço Inox 304/316', 'Laudo com ART' ) === $plain, 'Consulta sem diferencial recebe os dois primeiros da lista, em ordem fixa' );
uonix_intel_assert( $plain === uonix_intelligence_title_suggestion( 'olhal de ancoragem' ), 'Mesma consulta produz sempre a mesma sugestão' );

$has_inox = uonix_intelligence_title_suggestion( 'olhal de ancoragem inox' );
uonix_intel_assert( ! in_array( 'Aço Inox 304/316', $has_inox, true ), 'Diferencial já presente na consulta não é sugerido' );

$accented = uonix_intelligence_title_suggestion( 'OLHAL AÇO INOX 304' );
uonix_intel_assert( ! in_array( 'Aço Inox 304/316', $accented, true ), 'Comparação ignora caixa e acento' );

$covered = uonix_intelligence_title_suggestion( 'olhal inox com laudo art conforme nbr ensaio de arrancamento e prazo de entrega' );
uonix_intel_assert( array() === $covered, 'Consulta que cobre todos os diferenciais não recebe sugestão inventada' );
uonix_intel_assert( array() === uonix_intelligence_title_suggestion( '' ), 'Consulta vazia não gera sugestão' );

$row_suggestion = $fresh['rows'][0]['suggestion'];
uonix_intel_assert( is_array( $row_suggestion ) && array() !== $row_suggestion, 'Cada linha devolvida carrega sua sugestão determinística' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: oportunidades de SEO filtradas por distância de salto, com procedência e indisponibilidade explícitas.\n";
