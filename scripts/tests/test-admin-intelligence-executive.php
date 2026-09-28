<?php
/**
 * Testes do Relatório Executivo (Módulo 4): camada de dados e renderização no e-mail.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'UONIX_ENV', 'local' );

$failures = 0;
$GLOBALS['uox_options']      = array();
$GLOBALS['uox_cron']         = array();
$GLOBALS['uox_schedules']    = array( 'daily' => array( 'interval' => 86400 ), 'weekly' => array( 'interval' => 604800 ) );
$GLOBALS['uox_timezone']     = 'America/Sao_Paulo';
$GLOBALS['uox_table_exists'] = true;
$GLOBALS['uox_lead_rows']    = array();
$GLOBALS['uox_lead_before']  = null;

function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class Uox_WPDB {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'MAX( created_at )' ) ) {
			return $GLOBALS['uox_lead_before'];
		}
		return ( false !== strpos( $query, 'SHOW TABLES LIKE' ) && $GLOBALS['uox_table_exists'] ) ? 'wp_fluentform_submissions' : '';
	}
	public function get_results( $query ) {
		$saida = array();
		foreach ( $GLOBALS['uox_lead_rows'] as $dia => $total ) {
			$l        = new stdClass();
			$l->dia   = (string) $dia;
			$l->total = (int) $total;
			$saida[]  = $l;
		}
		return $saida;
	}
}
$GLOBALS['wpdb'] = new Uox_WPDB();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_shortcode( $tag, $callback ) { return true; }
function add_menu_page() { return ''; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['uox_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $d = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['uox_options'] ) ) return false; $GLOBALS['uox_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uox_options'][ $key ] ); return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value ) { return true; }
function delete_transient( $key ) { return true; }
function current_user_can( $capability ) { return true; }
function check_admin_referer( $action = -1 ) { return true; }
function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( (string) $m ); }
function wp_safe_redirect( $url ) { return true; }
function wp_mail( $to, $subject, $message, $headers = array() ) { return true; }
function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return (string) filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL ); }
function is_email( $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function admin_url( $path = '' ) { return 'https://uonix.com.br/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_bloginfo( $show = '' ) { return 'Uônix'; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>' ), array( '&quot;', '&#039;', '&lt;', '&gt;' ), (string) $url ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action = -1 ) { echo ''; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook ) { $GLOBALS['uox_cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['uox_cron'][ $hook ] ); }
function wp_get_schedules() { return $GLOBALS['uox_schedules']; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['uox_timezone'] ); }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : (int) $ts ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_header( $r, $name ) { return is_array( $r ) && isset( $r['headers'][ strtolower( $name ) ] ) ? $r['headers'][ strtolower( $name ) ] : ''; }
function home_url( $path = '' ) { return 'https://uonix.com.br' . $path; }
// Respostas HTTP por URL, e registro dos argumentos de cada HEAD.
$GLOBALS['uox_http']      = array();
$GLOBALS['uox_http_args'] = array();
function wp_remote_head( $url, $args = array() ) {
	$GLOBALS['uox_http_args'][] = $args;
	return array_key_exists( $url, $GLOBALS['uox_http'] ) ? $GLOBALS['uox_http'][ $url ] : new WP_Error( 'http_request_failed' );
}

$RAIZ = dirname( __DIR__, 2 );
require_once $RAIZ . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/57-admin-intelligence-report.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/59-admin-intelligence-executive.php';

// ---------------------------------------------------------------------------
// Fixtures.
// ---------------------------------------------------------------------------

$HOJE = '2026-09-28';
$CFG  = array( 'search_console_site_url' => 'sc-domain:x', 'ga4_property_id' => '1' );
$ALFA = (float) uonix_intelligence_executive_rules()['detect_alpha'];
$W    = uonix_intelligence_executive_windows( $HOJE );

/** Linhas diárias no formato de `uonix_analytics_metrics_ga4_rows( $r, 'date' )`. */
function uox_ga4_rows( $de, $ate, $por_dia ) {
	$fuso   = new DateTimeZone( 'UTC' );
	$cursor = new DateTimeImmutable( $de, $fuso );
	$fim    = new DateTimeImmutable( $ate, $fuso );
	$linhas = array();
	while ( $cursor <= $fim ) {
		$dia      = $cursor->format( 'Y-m-d' );
		$sessoes  = is_callable( $por_dia ) ? $por_dia( $dia ) : $por_dia;
		$linhas[] = array( 'activeUsers' => (string) $sessoes, 'sessions' => (string) $sessoes, 'date' => $cursor->format( 'Ymd' ) );
		$cursor   = $cursor->modify( '+1 day' );
	}
	return $linhas;
}

/** Série diária da Search Console no formato decodificado. */
function uox_gsc_serie( $fim, $dias, $impressoes ) {
	$cursor = new DateTimeImmutable( $fim, new DateTimeZone( 'UTC' ) );
	$linhas = array();
	for ( $i = 0; $i < $dias; $i++ ) {
		$linhas[] = array( 'keys' => array( $cursor->format( 'Y-m-d' ) ), 'clicks' => 0, 'impressions' => $impressoes, 'ctr' => 0.0, 'position' => 10.0 );
		$cursor   = $cursor->modify( '-1 day' );
	}
	return array_reverse( $linhas );
}

// ---------------------------------------------------------------------------
// 1. Janelas: terminam ONTEM, e as anteriores vêm imediatamente antes.
// ---------------------------------------------------------------------------

uox_assert( '2026-09-27' === ( $W['week']['end'] ?? '' ), 'a semana deve terminar ontem (27/09), obteve ' . ( $W['week']['end'] ?? '(nada)' ) );
uox_assert( '2026-09-21' === ( $W['week']['start'] ?? '' ), 'a semana deve ter 7 dias: 21/09 a 27/09' );
uox_assert( '2026-09-14' === ( $W['prev_week']['start'] ?? '' ) && '2026-09-20' === ( $W['prev_week']['end'] ?? '' ), 'a semana anterior deve ser 14/09 a 20/09' );
uox_assert( '2026-08-31' === ( $W['month']['start'] ?? '' ) && '2026-09-27' === ( $W['month']['end'] ?? '' ), 'as 4 semanas devem ser 31/08 a 27/09' );
uox_assert( '2026-08-03' === ( $W['prev_month']['start'] ?? '' ) && '2026-08-30' === ( $W['prev_month']['end'] ?? '' ), 'as 4 semanas anteriores devem ser 03/08 a 30/08' );
$fusoUtc = new DateTimeZone( 'UTC' );
uox_assert(
	( new DateTimeImmutable( $W['week']['start'], $fusoUtc ) )->format( 'N' ) === ( new DateTimeImmutable( $W['prev_week']['start'], $fusoUtc ) )->format( 'N' ),
	'as duas semanas devem começar no mesmo dia da semana'
);
uox_assert( array() === uonix_intelligence_executive_windows( 'nao-e-data' ), 'data inválida não pode produzir janelas' );

// ---------------------------------------------------------------------------
// 2. Soma inclusiva nas bordas.
// ---------------------------------------------------------------------------

$porDia = array( '2026-09-20' => 1, '2026-09-21' => 2, '2026-09-27' => 4, '2026-09-28' => 8 );
uox_assert( 6 === uonix_intelligence_executive_sum( $porDia, $W['week'] ), 'a soma deve incluir as duas bordas da semana e excluir o que está fora, obteve ' . uonix_intelligence_executive_sum( $porDia, $W['week'] ) );

// ---------------------------------------------------------------------------
// 3. Teste binomial EXATO, com valores conferidos à mão.
// ---------------------------------------------------------------------------

$quase = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 1e-9; };
uox_assert( $quase( 0.0625, uonix_intelligence_executive_binomial_p( 0, 5 ) ), '0 contra 5 tem p exato = 2/32 = 0,0625' );
uox_assert( $quase( 0.0625, uonix_intelligence_executive_binomial_p( 5, 5 ) ), 'o teste é simétrico: 5 contra 0 também tem p = 0,0625' );
uox_assert( $quase( 22 / 1024, uonix_intelligence_executive_binomial_p( 1, 10 ) ), '1 em 10 tem p exato = 2·11/1024' );
uox_assert( $quase( 1.0, uonix_intelligence_executive_binomial_p( 6, 11 ) ), '6 contra 5 não tem diferença nenhuma: p = 1' );
uox_assert( $quase( 1.0, uonix_intelligence_executive_binomial_p( 0, 0 ) ), 'sem nenhuma contagem não há o que testar: p = 1' );
// p0 diferente de 0,5 é o que a conversão usa. O valor abaixo só sai certo se p0 for
// de fato usado: com p0 = 0,5 daria 0,25.
uox_assert( $quase( 0.016, uonix_intelligence_executive_binomial_p( 3, 3, 0.2 ) ), 'com p0 = 0,2, três em três tem p = 2·0,2³ = 0,016' );

// ---------------------------------------------------------------------------
// 4. Orçamentos: número absoluto e teste de diferença, NUNCA porcentagem.
// ---------------------------------------------------------------------------

uox_assert( 'submissions_table_missing' === ( uonix_intelligence_executive_leads_box( null, $W, $ALFA )['reason'] ?? '' ), 'sem tabela de orçamentos a caixa é indisponível, não zero' );

/** Espalha `$n` orçamentos, um por dia, a partir de `$inicio`. */
function uox_leads( $inicio, $n ) {
	$saida  = array();
	$cursor = new DateTimeImmutable( $inicio, new DateTimeZone( 'UTC' ) );
	for ( $i = 0; $i < $n; $i++ ) {
		$saida[ $cursor->format( 'Y-m-d' ) ] = 1;
		$cursor = $cursor->modify( '+1 day' );
	}
	return $saida;
}

$leadsFlat = array_merge( uox_leads( '2026-09-22', 2 ), uox_leads( '2026-09-05', 2 ), uox_leads( '2026-08-10', 3 ) );
$caixa     = uonix_intelligence_executive_leads_box( $leadsFlat, $W, $ALFA );
uox_assert( 2 === $caixa['current'], 'a semana deve ter 2 orçamentos, obteve ' . $caixa['current'] );
uox_assert( 4 === $caixa['month'] && 3 === $caixa['prev_month'], '4 semanas: 4 contra 3, obteve ' . $caixa['month'] . ' contra ' . $caixa['prev_month'] );
uox_assert( 'flat' === $caixa['direction'], '4 contra 3 não é mudança detectável' );
uox_assert( ! array_key_exists( 'delta_percent', $caixa ), 'a caixa de orçamentos não pode carregar variação percentual: com ~1 por semana, +100% é um orçamento a mais' );

// A FRONTEIRA de alfa, com dois casos vizinhos de p conhecido.
$caixaBaixa = uonix_intelligence_executive_leads_box( array_merge( uox_leads( '2026-09-01', 1 ), uox_leads( '2026-08-05', 9 ) ), $W, $ALFA );
uox_assert( 'down' === $caixaBaixa['direction'], '1 contra 9 (p = 0,0215) é queda detectável' );
$caixaVizinha = uonix_intelligence_executive_leads_box( array_merge( uox_leads( '2026-09-01', 2 ), uox_leads( '2026-08-05', 8 ) ), $W, $ALFA );
uox_assert( 'flat' === $caixaVizinha['direction'], '2 contra 8 (p = 0,109) NÃO é detectável: sem esta fronteira, o nível de significância pode ser afrouxado sem nada reprovar' );
$caixaAlta = uonix_intelligence_executive_leads_box( array_merge( uox_leads( '2026-09-01', 9 ), uox_leads( '2026-08-05', 1 ) ), $W, $ALFA );
uox_assert( 'up' === $caixaAlta['direction'], '9 contra 1 é aumento detectável' );

// O caso que a aproximação normal erraria, e que justifica o teste exato.
$caixaZeroCinco = uonix_intelligence_executive_leads_box( uox_leads( '2026-08-05', 5 ), $W, $ALFA );
uox_assert( 'flat' === $caixaZeroCinco['direction'], '0 contra 5 tem p = 0,0625 e não é detectável — a aproximação |a−b| > 2√(a+b) diria o contrário' );

// ---------------------------------------------------------------------------
// 5. GA4: normalização e cobertura de histórico.
// ---------------------------------------------------------------------------

$ga4 = uonix_intelligence_executive_ga4_per_day( array(
	array( 'date' => '20260829', 'sessions' => '3' ),
	array( 'date' => '20260830', 'sessions' => '4' ),
	array( 'date' => '20260830', 'sessions' => '1' ),
	array( 'date' => 'lixo', 'sessions' => '99' ),
	array( 'date' => '20260831' ),
) );
uox_assert( array( '2026-08-29' => 3, '2026-08-30' => 5 ) === $ga4['per_day'], 'as datas devem virar Y-m-d, repetidas somadas e inválidas descartadas; obteve ' . json_encode( $ga4['per_day'] ) );
uox_assert( '2026-08-29' === $ga4['first_day'], 'o primeiro dia com dado deve ser 29/08' );

uox_assert( ! uonix_intelligence_executive_history_covers( $ga4, $W['prev_month'] ), 'GA4 começando em 29/08 NÃO cobre a janela que começa em 03/08' );
uox_assert( uonix_intelligence_executive_history_covers( $ga4, $W['prev_week'] ), 'GA4 começando em 29/08 cobre a semana que começa em 14/09' );
uox_assert( ! uonix_intelligence_executive_history_covers( $ga4, array( 'start' => '2026-08-29', 'end' => '2026-09-04' ) ), 'começar NO primeiro dia da janela não basta: a cobertura exige dado estritamente antes' );

// ---------------------------------------------------------------------------
// 6. Visitas: semana contra semana, e recusa sem histórico.
// ---------------------------------------------------------------------------

// Como em produção em 2026-09-28: GA4 desde 29/08. 10/dia até 20/09, 12/dia depois.
$ga4Real = uonix_intelligence_executive_ga4_per_day( uox_ga4_rows( '2026-08-29', '2026-09-27', static function ( $d ) { return $d >= '2026-09-21' ? 12 : 10; } ) );
$visitas = uonix_intelligence_executive_visits_box( $ga4Real, $W );
uox_assert( 84 === $visitas['current'] && 70 === $visitas['previous'], 'visitas: 84 contra 70, obteve ' . $visitas['current'] . ' contra ' . var_export( $visitas['previous'], true ) );
uox_assert( 20.0 === $visitas['delta_percent'], '84 contra 70 é +20,0%, obteve ' . var_export( $visitas['delta_percent'], true ) );
uox_assert( ! empty( $visitas['consented_only'] ), 'a caixa precisa declarar que só conta visitas com consentimento' );

$ga4Novo  = uonix_intelligence_executive_ga4_per_day( uox_ga4_rows( '2026-09-18', '2026-09-27', 10 ) );
$visNovas = uonix_intelligence_executive_visits_box( $ga4Novo, $W );
uox_assert( empty( $visNovas['comparable'] ) && 'no_history' === $visNovas['note'], 'GA4 começando dentro da semana anterior deve recusar a comparação' );
uox_assert( null === $visNovas['previous'], 'sem histórico a semana anterior não pode ser exibida como número' );
uox_assert( 'ga4_fetch_failed' === ( uonix_intelligence_executive_visits_box( new WP_Error( 'x' ), $W )['reason'] ?? '' ), 'falha de busca do GA4 deve ser indisponível com motivo' );

// ---------------------------------------------------------------------------
// 7. Impressões: o contrato com o Módulo 5, usando a função DE VERDADE.
// ---------------------------------------------------------------------------

// Hoje 28/09 e defasagem 4: atual 18–24/09, anterior 11–17/09.
$fetcherGsc = static function ( $pctAtual ) {
	return static function ( $config, $period ) use ( $pctAtual ) {
		return array_merge( uox_gsc_serie( '2026-09-17', 7, 100 ), uox_gsc_serie( '2026-09-24', 7, $pctAtual ) );
	};
};
$org = uonix_intelligence_executive_organic_box( uonix_intelligence_anomaly_organic_drop( $fetcherGsc( 130 ), $CFG, $HOJE ) );
uox_assert( ! empty( $org['available'] ), 'impressões com série completa devem estar disponíveis, motivo: ' . ( $org['reason'] ?? '' ) );
uox_assert( 910.0 === $org['current'] && 700.0 === $org['previous'], 'impressões: 910 contra 700, obteve ' . $org['current'] . ' contra ' . $org['previous'] );
uox_assert( 30.0 === $org['delta_percent'], '910 contra 700 é +30,0%, obteve ' . var_export( $org['delta_percent'], true ) );
uox_assert( '2026-09-24' === ( $org['window']['end'] ?? '' ), 'a semana das impressões termina 4 dias antes de hoje, não ontem' );

$semDias = static function ( $config, $period ) {
	$atual = array_values( array_filter( uox_gsc_serie( '2026-09-24', 7, 100 ), static function ( $l ) { return '2026-09-20' !== $l['keys'][0]; } ) );
	return array_merge( uox_gsc_serie( '2026-09-17', 7, 100 ), $atual );
};
$orgImp = uonix_intelligence_executive_organic_box( uonix_intelligence_anomaly_organic_drop( $semDias, $CFG, $HOJE ) );
uox_assert( null === $orgImp['delta_percent'] && 'imputed' === $orgImp['note'], 'com dia imputado a porcentagem é LIMITE do alerta, não medição, e deve ser recusada no relatório' );
uox_assert( 1 === $orgImp['imputed_days'], 'deve declarar quantos dias foram imputados' );

// ALTO 1 da revisão do PR #301: dia ausente na semana ANTERIOR.
//
// `imputed_days` do Módulo 5 conta só a semana atual. Na anterior, dia ausente vale
// zero — conservador para detectar queda, errado para exibir variação. Com tráfego
// CONSTANTE e dois dias ausentes na semana anterior, a caixa exibia "▲ +40%".
$anteriorFurada = static function ( $faltam, $impAtual ) {
	return static function ( $config, $period ) use ( $faltam, $impAtual ) {
		$ant = array_slice( uox_gsc_serie( '2026-09-17', 7, 100 ), $faltam );
		return array_merge( $ant, uox_gsc_serie( '2026-09-24', 7, $impAtual ) );
	};
};
$achadoFurado = uonix_intelligence_anomaly_organic_drop( $anteriorFurada( 2, 100 ), $CFG, $HOJE );
uox_assert( ! empty( $achadoFurado['available'] ) && 40.0 === (float) ( $achadoFurado['measured']['delta_percent'] ?? 0 ), 'contraprova: o Módulo 5 mede +40% nesse cenário, senão o fixture não reproduz o defeito; obteve ' . var_export( $achadoFurado['measured']['delta_percent'] ?? null, true ) );
$orgFurado = uonix_intelligence_executive_organic_box( $achadoFurado );
uox_assert( null === $orgFurado['delta_percent'] && empty( $orgFurado['comparable'] ), 'com dia ausente na semana ANTERIOR a porcentagem deve ser recusada: tráfego constante sairia como +40%' );
uox_assert( 'previous_incomplete' === $orgFurado['note'] && 2 === $orgFurado['missing_prev'], 'a caixa deve dizer que a semana anterior tem 2 dias sem dado' );
uox_assert( 0 === $orgFurado['imputed_days'], 'e a semana atual está completa' );

// O mesmo defeito no sentido perigoso: queda real de −35% com três dias ausentes na
// semana anterior saía como "+13,8%, abaixo do limiar".
$orgQuedaFurada = uonix_intelligence_executive_organic_box( uonix_intelligence_anomaly_organic_drop( $anteriorFurada( 3, 65 ), $CFG, $HOJE ) );
uox_assert( null === $orgQuedaFurada['delta_percent'], 'queda real com semana anterior furada não pode sair como variação nenhuma, muito menos positiva' );

$colapso = static function ( $config, $period ) { return uox_gsc_serie( '2026-09-17', 7, 100 ); };
$orgCol  = uonix_intelligence_executive_organic_box( uonix_intelligence_anomaly_organic_drop( $colapso, $CFG, $HOJE ) );
uox_assert( 'collapse' === $orgCol['note'] && -100.0 === $orgCol['delta_percent'], 'semana sem impressão contra semana cheia é colapso de −100%' );

$orgFalha = uonix_intelligence_executive_organic_box( uonix_intelligence_anomaly_organic_drop( static function () { return new WP_Error( 'x' ); }, $CFG, $HOJE ) );
uox_assert( empty( $orgFalha['available'] ) && 'series_fetch_failed' === $orgFalha['reason'], 'falha da Search Console repassa o motivo do Módulo 5' );

// ---------------------------------------------------------------------------
// 8. Conversão: teto, histórico, e teste com exposição proporcional.
// ---------------------------------------------------------------------------

$conv = uonix_intelligence_executive_conversion_box( $leadsFlat, $ga4Real, $W, $ALFA );
// 4 semanas (31/08–27/09): 14 dias × 10 + 7 × 10 + 7 × 12 = 294 visitas; 4 orçamentos.
uox_assert( 294 === $conv['sessions'] && 4 === $conv['leads'], 'conversão: 4 orçamentos em 294 visitas, obteve ' . $conv['leads'] . ' em ' . $conv['sessions'] );
uox_assert( $quase( 4 / 294, $conv['rate'] ), 'a taxa deve ser 4/294' );
uox_assert( ! empty( $conv['upper_bound'] ), 'a conversão precisa se declarar teto: o denominador só conta visitas com consentimento' );
uox_assert( empty( $conv['comparable'] ) && 'no_history' === $conv['note'], 'GA4 desde 29/08 não cobre as 4 semanas anteriores: comparação recusada' );

$ga4Longo = uonix_intelligence_executive_ga4_per_day( uox_ga4_rows( '2026-07-20', '2026-09-27', 10 ) );
$convCmp  = uonix_intelligence_executive_conversion_box( array_merge( uox_leads( '2026-09-01', 9 ), uox_leads( '2026-08-05', 1 ) ), $ga4Longo, $W, $ALFA );
uox_assert( ! empty( $convCmp['comparable'] ), 'com histórico completo a conversão é comparável' );
uox_assert( 'up' === $convCmp['direction'], '9 contra 1 com visitas iguais é aumento detectável' );

// O DOBRO de orçamentos e o DOBRO de visitas: a taxa é a mesma dos dois lados.
//
// O volume é alto de propósito. A primeira versão deste fixture usava 8 contra 4, e a
// verificação por mutação mostrou que ele não distinguia nada: com contagens tão
// pequenas o teste dá "sem mudança" com ou sem a exposição, e trocar p0 por 0,5
// sobrevivia. Com 40 contra 20 a diferença existe — e a contraprova abaixo prova isso.
$leadsExp = array();
$cursor   = new DateTimeImmutable( '2026-09-01', $fusoUtc );
for ( $i = 0; $i < 20; $i++ ) { $leadsExp[ $cursor->format( 'Y-m-d' ) ] = 2; $cursor = $cursor->modify( '+1 day' ); }
$cursor = new DateTimeImmutable( '2026-08-05', $fusoUtc );
for ( $i = 0; $i < 20; $i++ ) { $leadsExp[ $cursor->format( 'Y-m-d' ) ] = 1; $cursor = $cursor->modify( '+1 day' ); }
$ga4Dobro = uonix_intelligence_executive_ga4_per_day( uox_ga4_rows( '2026-07-20', '2026-09-27', static function ( $d ) { return $d >= '2026-08-31' ? 20 : 10; } ) );
$convExp  = uonix_intelligence_executive_conversion_box( $leadsExp, $ga4Dobro, $W, $ALFA );
uox_assert( 40 === $convExp['leads'] && 560 === $convExp['sessions'], 'o fixture deveria ter 40 orçamentos em 560 visitas, obteve ' . $convExp['leads'] . ' em ' . $convExp['sessions'] );
uox_assert( $quase( $convExp['rate'], $convExp['prev_rate'] ), 'o fixture deveria ter a mesma taxa nos dois períodos' );
// Contraprova: SEM a exposição, 40 contra 20 seria detectável. Se isto falhar, a
// asserção seguinte fica vácua e não prova que a exposição é usada.
uox_assert( uonix_intelligence_executive_binomial_p( 40, 60, 0.5 ) < $ALFA, 'contraprova: 40 contra 20 com exposição igual precisa ser detectável, senão o fixture não discrimina' );
uox_assert( 'flat' === $convExp['direction'], 'taxa igual NÃO pode ser mudança: o teste precisa usar a exposição proporcional às visitas' );

uox_assert( 'no_sessions' === ( uonix_intelligence_executive_conversion_box( $leadsFlat, array( 'per_day' => array(), 'first_day' => '' ), $W, $ALFA )['reason'] ?? '' ), 'sem visitas não há taxa' );

// ---------------------------------------------------------------------------
// 9. Destaques: determinísticos, até 3, e nunca enchimento.
// ---------------------------------------------------------------------------

$seo = array( 'available' => true, 'rows' => array( array( 'query' => 'olhal de ancoragem', 'impressions' => 55, 'position' => 11.2, 'clicks' => 0, 'ctr' => 0.0, 'suggestion' => array( 'Aço Inox 304/316' ) ) ) );

$placar = uonix_intelligence_executive_scorecard( array( 'today' => $HOJE, 'lead_counts' => $leadsFlat, 'ga4' => $ga4Real, 'organic' => uonix_intelligence_anomaly_organic_drop( $fetcherGsc( 130 ), $CFG, $HOJE ) ) );
$dest   = uonix_intelligence_executive_insights( $placar, $seo );
uox_assert( 3 === count( $dest ), 'com os três dados disponíveis devem sair três destaques, saíram ' . count( $dest ) );
uox_assert( false !== strpos( $dest[0]['text'] ?? '', 'sem mudança detectável' ), 'destaque de orçamentos deve dizer que 4 contra 3 não é mudança' );
uox_assert( false !== strpos( $dest[1]['text'] ?? '', '+30,0%' ) && false !== strpos( $dest[1]['text'], 'abaixo do limiar de 35%' ), 'variação abaixo do limiar não é chamada de relevante, obteve: ' . ( $dest[1]['text'] ?? '' ) );
uox_assert( false !== strpos( $dest[2]['text'] ?? '', 'olhal de ancoragem' ) && false !== strpos( $dest[2]['text'], 'nenhum clique' ), 'destaque de SEO deve nomear a consulta e a ausência de clique' );

// FRONTEIRA do limiar de impressões: exatamente +35% é relevante; +34,x% não.
$placar35 = uonix_intelligence_executive_scorecard( array( 'today' => $HOJE, 'lead_counts' => $leadsFlat, 'ga4' => $ga4Real, 'organic' => uonix_intelligence_anomaly_organic_drop( $fetcherGsc( 135 ), $CFG, $HOJE ) ) );
$d35      = uonix_intelligence_executive_insights( $placar35, null );
uox_assert( false !== strpos( $d35[1]['text'] ?? '', 'subiram 35,0%' ), 'exatamente +35% é relevante, obteve: ' . ( $d35[1]['text'] ?? '' ) );
uox_assert( false !== strpos( $d35[1]['text'], 'a visibilidade cresceu mais que a demanda' ), 'impressões subindo sem aumento detectável de orçamentos deve ser dito' );

// MÉDIO 3 da revisão do PR #301: com queda DETECTÁVEL de orçamentos, o destaque de
// impressões não pode dizer "sem aumento detectável" logo depois do que diz "caíram".
$leadsQueda  = array_merge( uox_leads( '2026-09-01', 1 ), uox_leads( '2026-08-05', 9 ) );
$placarQueda = uonix_intelligence_executive_scorecard( array( 'today' => $HOJE, 'lead_counts' => $leadsQueda, 'ga4' => $ga4Real, 'organic' => uonix_intelligence_anomaly_organic_drop( $fetcherGsc( 140 ), $CFG, $HOJE ) ) );
$dQueda      = uonix_intelligence_executive_insights( $placarQueda, null );
uox_assert( false !== strpos( $dQueda[0]['text'] ?? '', 'caíram de forma detectável' ), 'o fixture deveria ter queda detectável de orçamentos' );
uox_assert( false !== strpos( $dQueda[1]['text'] ?? '', 'enquanto os orçamentos caíram' ), 'impressões subindo com orçamentos caindo deve dizer isso, obteve: ' . ( $dQueda[1]['text'] ?? '' ) );
uox_assert( false === strpos( $dQueda[1]['text'] ?? '', 'sem aumento detectável' ), 'e não pode usar a frase que soa como contradição do destaque anterior' );

$placarCai = uonix_intelligence_executive_scorecard( array( 'today' => $HOJE, 'lead_counts' => $leadsFlat, 'ga4' => $ga4Real, 'organic' => uonix_intelligence_anomaly_organic_drop( $fetcherGsc( 60 ), $CFG, $HOJE ) ) );
uox_assert( false !== strpos( uonix_intelligence_executive_insights( $placarCai, null )[1]['text'] ?? '', 'Veja a aba Anomalias' ), 'queda relevante deve remeter ao Alerta de Anomalias' );

$vazio = uonix_intelligence_executive_scorecard( array( 'today' => $HOJE, 'lead_counts' => null, 'ga4' => null, 'organic' => null ) );
uox_assert( array() === uonix_intelligence_executive_insights( $vazio, null ), 'sem dado nenhum não pode sair destaque nenhum: destaque inventado para completar a lista é o texto que o contrato proíbe' );

// ---------------------------------------------------------------------------
// 10. Páginas mais encontradas.
// ---------------------------------------------------------------------------

// O cenário que a revisão do PR #301 encontrou em PRODUÇÃO, conferido por HTTP em
// 2026-09-28: `/olhal-de-ancoragem/` dá 404 com 327 impressões, e `/projeto-de-balancim`,
// `/projeto-de-ancoragem` e `/projeto-de-andaime-fachadeiro` são 301 para `/servico/...`.
// O bloco antigo os apresentava como páginas do site, e os 301 dividiam as impressões
// entre endereço antigo e novo.
//
// A segunda revisão mediu que a primeira versão deste fixture divergia da produção em
// dois pontos: o andaime estava como página que responde 200, e `/projeto-de-ancoragem`
// tinha 150 impressões em vez de 255. Com os valores reais, a quarta posição muda.
$linhasPaginas = array(
	array( 'page' => '/', 'clicks' => 33, 'impressions' => 201 ),
	array( 'page' => '/ensaio-de-arrancamento/', 'clicks' => 10, 'impressions' => 987 ),
	array( 'page' => '/olhal-de-ancoragem/', 'clicks' => 0, 'impressions' => 327 ),
	array( 'page' => '/projeto-de-balancim', 'clicks' => 10, 'impressions' => 332 ),
	array( 'page' => '/servico/projeto-balancim/', 'clicks' => 5, 'impressions' => 171 ),
	array( 'page' => '/projeto-de-ancoragem', 'clicks' => 2, 'impressions' => 255 ),
	array( 'page' => '/projeto-de-andaime-fachadeiro', 'clicks' => 1, 'impressions' => 192 ),
	array( 'page' => '/servico/projeto-ancoragem/', 'clicks' => 1, 'impressions' => 20 ),
	array( 'page' => '/pouco', 'clicks' => 0, 'impressions' => 5 ),
);
$statusFixo = static function ( $p ) {
	$mapa = array(
		'/olhal-de-ancoragem/'           => array( 'state' => 'not_found', 'code' => 404, 'location' => '' ),
		'/projeto-de-balancim'           => array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://uonix.com.br/servico/projeto-balancim/' ),
		'/projeto-de-ancoragem'          => array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://uonix.com.br/servico/projeto-ancoragem/' ),
		'/projeto-de-andaime-fachadeiro' => array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://uonix.com.br/servico/projeto-andaime-fachadeiro/' ),
	);
	return $mapa[ $p ] ?? array( 'state' => 'ok', 'code' => 200, 'location' => '' );
};
$rotulo      = static function ( $p ) { return '/' === $p ? 'Página inicial' : 'Título de ' . $p; };
$janelaPag   = array( 'start' => '2026-08-28', 'end' => '2026-09-24' );
$paginas     = uonix_intelligence_executive_top_pages( $linhasPaginas, $janelaPag, $statusFixo, $rotulo );
$porCaminho  = array();
foreach ( $paginas['rows'] as $r ) { $porCaminho[ $r['path'] ] = $r; }

uox_assert( 5 === count( $paginas['rows'] ), 'o bloco é um digest de 5 páginas, obteve ' . count( $paginas['rows'] ) );
uox_assert( '/ensaio-de-arrancamento/' === $paginas['rows'][0]['path'], 'a ordem é por impressões, não por cliques' );

// O 301 é SOMADO ao destino: 332 + 171 = 503, e com isso ele sobe de posição.
uox_assert( ! isset( $porCaminho['/projeto-de-balancim'] ), 'o endereço antigo que redireciona não pode aparecer como página' );
uox_assert( 503.0 === ( $porCaminho['/servico/projeto-balancim/']['impressions'] ?? 0.0 ), 'o destino deve somar as impressões do endereço antigo: 332 + 171 = 503, obteve ' . var_export( $porCaminho['/servico/projeto-balancim/']['impressions'] ?? null, true ) );
uox_assert( 15.0 === ( $porCaminho['/servico/projeto-balancim/']['clicks'] ?? 0.0 ), 'e os cliques: 10 + 5 = 15' );
uox_assert( 1 === ( $porCaminho['/servico/projeto-balancim/']['merged'] ?? 0 ), 'o destino deve declarar quantos endereços antigos foram somados' );
uox_assert( '/servico/projeto-balancim/' === ( $paginas['rows'][1]['path'] ?? '' ), 'com a soma, o destino passa a ser a segunda página' );
uox_assert( 'Título de /servico/projeto-balancim/' === ( $porCaminho['/servico/projeto-balancim/']['label'] ?? '' ), 'o destino, que responde 200, recebe o título' );

// Destino FORA das candidatas: recebe a soma e tem o status conferido. Com uma
// candidata só, `/antigo` (100) é conferida e redireciona; `/novo` (10) não era
// candidata e é conferida ao seguir a cadeia, uma vez.
$conferidos = array();
$statusConta = static function ( $p ) use ( &$conferidos ) {
	$conferidos[] = $p;
	return '/antigo' === $p ? array( 'state' => 'redirect', 'code' => 301, 'location' => '/novo' ) : array( 'state' => 'ok', 'code' => 200, 'location' => '' );
};
$foraCand = uonix_intelligence_executive_top_pages( array( array( 'page' => '/antigo', 'clicks' => 3, 'impressions' => 100 ), array( 'page' => '/novo', 'clicks' => 1, 'impressions' => 10 ) ), $janelaPag, $statusConta, $rotulo, 5, 1 );
uox_assert( '/novo' === ( $foraCand['rows'][0]['path'] ?? '' ) && 110.0 === ( $foraCand['rows'][0]['impressions'] ?? 0.0 ), 'destino fora das candidatas recebe a soma: 100 + 10 = 110' );
uox_assert( 'ok' === ( $foraCand['rows'][0]['state'] ?? '' ), 'e tem o status conferido ao seguir a cadeia, não fica sem verificação' );
uox_assert( array( '/antigo', '/novo' ) === $conferidos, 'cada caminho deve ser conferido uma vez só, obteve ' . json_encode( $conferidos ) );

// Destino que não está em linha nenhuma é criado com o volume do endereço antigo.
$semDestino = uonix_intelligence_executive_top_pages( array( array( 'page' => '/velho', 'clicks' => 2, 'impressions' => 40 ) ), $janelaPag, static function ( $p ) { return '/velho' === $p ? array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://uonix.com.br/destino/' ) : array( 'state' => 'ok', 'code' => 200, 'location' => '' ); }, $rotulo );
uox_assert( '/destino/' === ( $semDestino['rows'][0]['path'] ?? '' ) && 40.0 === ( $semDestino['rows'][0]['impressions'] ?? 0.0 ), 'destino ausente das linhas é criado com o volume do endereço antigo' );

// O 404 é MARCADO, não escondido e não rotulado como página do site.
uox_assert( 'not_found' === ( $porCaminho['/olhal-de-ancoragem/']['state'] ?? '' ), 'página que dá 404 deve entrar marcada como inexistente' );
uox_assert( '/olhal-de-ancoragem/' === ( $porCaminho['/olhal-de-ancoragem/']['label'] ?? '' ), 'página inexistente não pode receber título: ela não é página do site' );

// A ordem final, com os valores de produção: 987, 503 (balancim somado), 327 (404),
// 275 (ancoragem: 255 + 20) e 201. O andaime (192, somado ao destino) é o sexto.
uox_assert(
	array( '/ensaio-de-arrancamento/', '/servico/projeto-balancim/', '/olhal-de-ancoragem/', '/servico/projeto-ancoragem/', '/' ) === array_column( $paginas['rows'], 'path' ),
	'a ordem das 5 páginas com os valores de produção, obteve ' . json_encode( array_column( $paginas['rows'], 'path' ) )
);
uox_assert( 275.0 === ( $porCaminho['/servico/projeto-ancoragem/']['impressions'] ?? 0.0 ), 'o destino da ancoragem soma 255 + 20 = 275' );
uox_assert( ! isset( $porCaminho['/projeto-de-andaime-fachadeiro'] ), 'o andaime é 301 em produção e não pode aparecer como página' );

// Página de muita impressão e pouco clique entra à frente da de muito clique e pouca
// impressão. Sintética: com os valores de produção, nenhuma página ilustra isso sozinha.
$porImpressao = uonix_intelligence_executive_top_pages( array( array( 'page' => '/clicada', 'clicks' => 50, 'impressions' => 60 ), array( 'page' => '/vista', 'clicks' => 0, 'impressions' => 400 ) ), $janelaPag, $statusFixo, $rotulo );
uox_assert( array( '/vista', '/clicada' ) === array_column( $porImpressao['rows'], 'path' ), 'página de muita impressão e nenhum clique vem antes: a ordem é por impressões' );

// Status desconhecido nunca vira "ok".
$semStatus = uonix_intelligence_executive_top_pages( array( array( 'page' => '/x', 'clicks' => 1, 'impressions' => 10 ) ), $janelaPag, static function () { return array( 'state' => 'unknown', 'code' => 0, 'location' => '' ); }, $rotulo );
uox_assert( 'unknown' === ( $semStatus['rows'][0]['state'] ?? '' ), 'falha ao conferir o status deve ficar como não verificado, nunca como página existente' );
uox_assert( '/x' === ( $semStatus['rows'][0]['label'] ?? '' ), 'e sem status verificado não há título' );

// Redirecionamento para fora do site não é somado a nada.
// Só `/sai` redireciona; qualquer outro endereço responde 200. Se o destino externo fosse
// seguido, ele "existiria" e viraria a linha.
$externo = uonix_intelligence_executive_top_pages( array( array( 'page' => '/sai', 'clicks' => 1, 'impressions' => 10 ) ), $janelaPag, static function ( $p ) { return '/sai' === $p ? array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://outro-site.com/x' ) : array( 'state' => 'ok', 'code' => 200, 'location' => '' ); }, $rotulo );
// O CAMINHO, e não só o estado. A verificação por mutação mostrou que conferir só o
// estado passava mesmo com a linha trocada pelo endereço externo — que também sai como
// "não verificado".
uox_assert( '/sai' === ( $externo['rows'][0]['path'] ?? '' ) && 1 === count( $externo['rows'] ), 'redirecionamento para fora do site não tem destino onde somar: a linha fica no endereço original, obteve ' . json_encode( $externo['rows'] ) );
uox_assert( 'unknown' === ( $externo['rows'][0]['state'] ?? '' ), 'e fica como status não verificado' );

// ---- Cadeias de redirecionamento (MÉDIO 1 e 3 da segunda revisão do PR #301). ----

/**
 * Conferência de status a partir de um mapa `caminho => destino`, registrando cada
 * consulta. Caminho fora do mapa responde 200; destino `404` responde 404.
 */
function uox_status_mapa( array $mapa, array &$consultas ) {
	return static function ( $p ) use ( $mapa, &$consultas ) {
		$consultas[] = $p;
		if ( ! array_key_exists( $p, $mapa ) ) {
			return array( 'state' => 'ok', 'code' => 200, 'location' => '' );
		}
		if ( '404' === $mapa[ $p ] ) {
			return array( 'state' => 'not_found', 'code' => 404, 'location' => '' );
		}
		return array( 'state' => 'redirect', 'code' => 301, 'location' => $mapa[ $p ] );
	};
}
function uox_linhas( array $impressoes ) {
	$linhas = array();
	foreach ( $impressoes as $caminho => $n ) {
		$linhas[] = array( 'page' => $caminho, 'clicks' => 1, 'impressions' => $n );
	}
	return $linhas;
}

// A ordem não pode mudar o resultado. O cenário exato da revisão: `/b` (300) → `/c` e
// `/a` (100) → `/b`. A versão anterior mostrava `/c` com 350 e, embaixo, `/b` com 100 —
// a mesma cadeia partida em duas linhas. E o espelho, com os volumes trocados, para a
// candidata processada primeiro ser a outra ponta da cadeia.
foreach ( array( 'b primeiro' => array( '/b' => 300, '/a' => 100, '/c' => 50 ), 'a primeiro' => array( '/a' => 300, '/b' => 100, '/c' => 50 ) ) as $cenario => $volumes ) {
	$q      = array();
	$cadeia = uonix_intelligence_executive_top_pages( uox_linhas( $volumes ), $janelaPag, uox_status_mapa( array( '/a' => '/b', '/b' => '/c' ), $q ), $rotulo );
	uox_assert( 1 === count( $cadeia['rows'] ), "cadeia a → b → c ({$cenario}) deve virar UMA linha, obteve " . json_encode( array_column( $cadeia['rows'], 'path' ) ) );
	uox_assert( '/c' === ( $cadeia['rows'][0]['path'] ?? '' ) && 450.0 === ( $cadeia['rows'][0]['impressions'] ?? 0.0 ), "e tudo cai no fim da cadeia: 300 + 100 + 50 = 450 ({$cenario})" );
	uox_assert( 2 === ( $cadeia['rows'][0]['merged'] ?? 0 ), "com os dois endereços antigos contados ({$cenario})" );
}

// A barra final é um salto como outro qualquer: `/x` → `/x/` → `/servico/x/`.
$q     = array();
$barra = uonix_intelligence_executive_top_pages( uox_linhas( array( '/x' => 80, '/x/' => 30, '/servico/x/' => 10 ) ), $janelaPag, uox_status_mapa( array( '/x' => 'https://uonix.com.br/x/', '/x/' => '/servico/x/' ), $q ), $rotulo );
uox_assert( array( '/servico/x/' ) === array_column( $barra['rows'], 'path' ) && 120.0 === $barra['rows'][0]['impressions'], 'a cadeia com barra final cai toda no destino: 80 + 30 + 10 = 120' );

// Ciclo: ninguém chega a lugar nenhum. Nada é somado, e nenhum endereço conta a si mesmo.
$q     = array();
$ciclo = uonix_intelligence_executive_top_pages( uox_linhas( array( '/p' => 40, '/q' => 30 ) ), $janelaPag, uox_status_mapa( array( '/p' => '/q', '/q' => '/p' ), $q ), $rotulo );
uox_assert( array( '/p', '/q' ) === array_column( $ciclo['rows'], 'path' ), 'no ciclo cada endereço fica na própria linha, obteve ' . json_encode( array_column( $ciclo['rows'], 'path' ) ) );
uox_assert( array( 'unknown', 'unknown' ) === array_column( $ciclo['rows'], 'state' ) && array( 0, 0 ) === array_column( $ciclo['rows'], 'merged' ), 'como não verificados, e sem soma declarada' );

// Destino que também redireciona, fora das candidatas: a cadeia é seguida até o fim.
$q    = array();
$meio = uonix_intelligence_executive_top_pages( uox_linhas( array( '/antigo' => 100, '/meio' => 10, '/fim' => 5 ) ), $janelaPag, uox_status_mapa( array( '/antigo' => '/meio', '/meio' => '/fim' ), $q ), $rotulo, 5, 1 );
uox_assert( array( '/fim' ) === array_column( $meio['rows'], 'path' ) && 115.0 === $meio['rows'][0]['impressions'] && 'ok' === $meio['rows'][0]['state'], 'destino que redireciona é seguido: tudo em /fim, 115, conferido' );

// Página que chega ao topo sem ter sido candidata, e que redireciona, também é somada.
$q      = array();
$tardia = uonix_intelligence_executive_top_pages( uox_linhas( array( '/a' => 100, '/d' => 60, '/c' => 50 ) ), $janelaPag, uox_status_mapa( array( '/c' => '/d' ), $q ), $rotulo, 5, 1 );
uox_assert( array( '/d', '/a' ) === array_column( $tardia['rows'], 'path' ) && 110.0 === $tardia['rows'][0]['impressions'], 'a não candidata que redireciona é conferida e somada: /d com 60 + 50 = 110, obteve ' . json_encode( $tardia['rows'] ) );

// `Location` na raiz sem caminho normaliza para vazio; ainda assim é a página inicial.
$q    = array();
$raiz = uonix_intelligence_executive_top_pages( uox_linhas( array( '/inicio' => 40, '/' => 10 ) ), $janelaPag, uox_status_mapa( array( '/inicio' => 'https://uonix.com.br' ), $q ), $rotulo );
uox_assert( array( '/' ) === array_column( $raiz['rows'], 'path' ) && 50.0 === $raiz['rows'][0]['impressions'], 'redirecionamento para a raiz é somado à página inicial: 40 + 10 = 50, obteve ' . json_encode( array_column( $raiz['rows'], 'path' ) ) );

// Memória: dois endereços antigos para o mesmo destino conferem o destino UMA vez.
$q    = array();
uonix_intelligence_executive_top_pages( uox_linhas( array( '/v1' => 50, '/v2' => 40, '/novo' => 5 ) ), $janelaPag, uox_status_mapa( array( '/v1' => '/novo', '/v2' => '/novo' ), $q ), $rotulo );
uox_assert( array( '/v1', '/novo', '/v2' ) === $q, 'cada caminho é conferido uma vez só, obteve ' . json_encode( $q ) );

// Saltos demais: o endereço de partida fica sem fim, e só ele. Os intermediários cabem
// no limite partindo deles mesmos, e o último nem foi consultado na cadeia longa.
$q      = array();
$longa  = uonix_intelligence_executive_top_pages( uox_linhas( array( '/h0' => 90, '/h1' => 50, '/h4' => 5 ) ), $janelaPag, uox_status_mapa( array( '/h0' => '/h1', '/h1' => '/h2', '/h2' => '/h3', '/h3' => '/h4' ), $q ), $rotulo );
$pLonga = array();
foreach ( $longa['rows'] as $r ) { $pLonga[ $r['path'] ] = $r; }
uox_assert( 'unknown' === ( $pLonga['/h0']['state'] ?? '' ) && 90.0 === ( $pLonga['/h0']['impressions'] ?? 0.0 ), 'quatro saltos passam do limite de 3: /h0 fica na própria linha, não verificado' );
uox_assert( 'ok' === ( $pLonga['/h4']['state'] ?? '' ) && 55.0 === ( $pLonga['/h4']['impressions'] ?? 0.0 ), 'mas /h1 → /h4 são três saltos e é somado: 50 + 5 = 55, conferido; obteve ' . json_encode( $longa['rows'] ) );
// Refazer a cadeia a partir de /h1 não repete requisição: a memória de status cobre os
// nós que a memória de cadeia não guardou.
uox_assert( array( '/h0', '/h1', '/h2', '/h3', '/h4' ) === $q, 'cada caminho é conferido uma vez só, mesmo quando a cadeia longa é refeita a partir do meio; obteve ' . json_encode( $q ) );

// Teto rígido de consultas: 25 páginas distintas, todas candidatas, e 20 conferências.
$q     = array();
$muitas = array();
for ( $i = 0; $i < 25; $i++ ) { $muitas[ '/p' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) ] = 1000 - $i; }
$teto  = uonix_intelligence_executive_top_pages( uox_linhas( $muitas ), $janelaPag, uox_status_mapa( array(), $q ), $rotulo, 25, 25 );
uox_assert( 20 === count( $q ) && 20 === ( $teto['head_count'] ?? -1 ), 'o teto é 20 requisições por execução, obteve ' . count( $q ) );
uox_assert( array( 'ok' ) === array_values( array_unique( array_column( array_slice( $teto['rows'], 0, 20 ), 'state' ) ) ) && array( 'unknown' ) === array_values( array_unique( array_column( array_slice( $teto['rows'], 20 ), 'state' ) ) ), 'as 20 primeiras são conferidas e as 5 seguintes ficam como não verificadas' );

uox_assert( 'Página inicial' === uonix_intelligence_executive_page_label( '/' ), 'a raiz deve ser rotulada como Página inicial' );
uox_assert( 'pages_fetch_failed' === ( uonix_intelligence_executive_top_pages( new WP_Error( 'x' ), $janelaPag )['reason'] ?? '' ), 'falha de busca deixa o bloco indisponível com motivo' );

// A conferência HTTP de VERDADE, com as respostas que a produção deu em 2026-09-28.
$GLOBALS['uox_http'] = array(
	'https://uonix.com.br/'                     => array( 'response' => array( 'code' => 200 ), 'headers' => array() ),
	'https://uonix.com.br/olhal-de-ancoragem/'  => array( 'response' => array( 'code' => 404 ), 'headers' => array() ),
	'https://uonix.com.br/projeto-de-balancim'  => array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://uonix.com.br/servico/projeto-balancim/' ) ),
	'https://uonix.com.br/sumiu'                => array( 'response' => array( 'code' => 410 ), 'headers' => array() ),
	'https://uonix.com.br/quebrada'             => array( 'response' => array( 'code' => 500 ), 'headers' => array() ),
	'https://uonix.com.br/sem-destino'          => array( 'response' => array( 'code' => 301 ), 'headers' => array() ),
);
uox_assert( 'ok' === uonix_intelligence_executive_page_status( '/' )['state'], '200 é página existente' );
uox_assert( 'not_found' === uonix_intelligence_executive_page_status( '/olhal-de-ancoragem/' )['state'], '404 é página inexistente' );
uox_assert( 'not_found' === uonix_intelligence_executive_page_status( '/sumiu' )['state'], '410 também é página inexistente' );
$st301 = uonix_intelligence_executive_page_status( '/projeto-de-balancim' );
uox_assert( 'redirect' === $st301['state'] && 'https://uonix.com.br/servico/projeto-balancim/' === $st301['location'], '301 com destino é redirecionamento, e o destino é devolvido' );
uox_assert( 'unknown' === uonix_intelligence_executive_page_status( '/sem-destino' )['state'], '301 sem cabeçalho Location não tem destino onde somar' );
uox_assert( 'unknown' === uonix_intelligence_executive_page_status( '/quebrada' )['state'], 'erro 500 não é página existente nem inexistente' );
uox_assert( 'unknown' === uonix_intelligence_executive_page_status( '/nao-responde' )['state'], 'falha de rede nunca vira página existente' );
// Sem seguir redirecionamento — senão o 301 viraria o 200 do destino e nada seria
// somado — e com o limite medido de 8 s.
$argsHead = end( $GLOBALS['uox_http_args'] );
uox_assert( 0 === ( $argsHead['redirection'] ?? -1 ), 'a conferência não pode seguir redirecionamento' );
uox_assert( 8 === ( $argsHead['timeout'] ?? -1 ), 'o limite por requisição é 8 s: o 404 de produção levou 4,2 s, e 5 s deixava margem de 0,8 s' );
// A requisição passa pelo Rank Math e conta no contador do redirecionamento: quem ler o
// contador precisa conseguir separá-la dos visitantes.
uox_assert( 0 === strpos( (string) ( $argsHead['user-agent'] ?? '' ), 'Uonix-Relatorio-Executivo/' ), 'a conferência deve se identificar no User-Agent, obteve ' . var_export( $argsHead['user-agent'] ?? null, true ) );

// ---- O buscador de páginas DE VERDADE, com a consulta injetada (MÉDIO 2). ----
//
// A revisão mostrou que esta função nunca rodava na suíte: voltar o limite para 10 —
// desfazendo a correção do ALTO 2 — ou tirar a normalização passava com tudo verde.
$pedidoGsc = array();
$respostaGsc = static function ( array $linhas ) use ( &$pedidoGsc ) {
	return static function ( $config, $period, $dimensao, $limite ) use ( $linhas, &$pedidoGsc ) {
		$pedidoGsc = array( 'period' => $period, 'dimension' => $dimensao, 'limit' => $limite );
		return json_encode( array( 'responseAggregationType' => 'byPage', 'rows' => $linhas ) );
	};
};
$linhaGsc = static function ( $url, $impressoes, $cliques = 0 ) {
	return array( 'keys' => array( $url ), 'clicks' => $cliques, 'impressions' => $impressoes, 'ctr' => 0.0, 'position' => 10.0 );
};
$buscadas = uonix_intelligence_executive_fetch_gsc_pages(
	$CFG,
	$janelaPag,
	$respostaGsc( array( $linhaGsc( 'https://uonix.com.br/olhal-de-ancoragem/', 327 ), $linhaGsc( 'https://www.uonix.com.br/', 201, 33 ), $linhaGsc( 'https://blog.uonix.com.br/post/', 9 ) ) )
);
uox_assert( 'page' === ( $pedidoGsc['dimension'] ?? '' ) && 1000 === ( $pedidoGsc['limit'] ?? 0 ), 'a Search Console deve ser consultada por página e com até 1000 linhas, obteve ' . json_encode( $pedidoGsc ) );
uox_assert( $janelaPag === ( $pedidoGsc['period'] ?? null ), 'na janela pedida' );
uox_assert(
	array( array( 'page' => '/olhal-de-ancoragem/', 'impressions' => 327.0, 'clicks' => 0.0 ), array( 'page' => '/', 'impressions' => 201.0, 'clicks' => 33.0 ) ) === ( $buscadas['rows'] ?? null ),
	'as URLs viram caminhos, e o subdomínio que a propriedade de domínio devolve é descartado; obteve ' . json_encode( $buscadas )
);
uox_assert( false === ( $buscadas['truncated'] ?? null ), 'com menos linhas que o limite a lista não está cortada' );

// Corte: 1000 linhas CRUAS, uma delas de subdomínio. Contando depois da normalização
// seriam 999, e o corte ficaria escondido.
$milLinhas = array( $linhaGsc( 'https://blog.uonix.com.br/x/', 1 ) );
for ( $i = 1; $i < 1000; $i++ ) { $milLinhas[] = $linhaGsc( 'https://uonix.com.br/p' . $i . '/', 1000 - $i ); }
$cortada = uonix_intelligence_executive_fetch_gsc_pages( $CFG, $janelaPag, $respostaGsc( $milLinhas ) );
uox_assert( true === ( $cortada['truncated'] ?? null ) && 999 === count( $cortada['rows'] ?? array() ), 'resposta com o limite de linhas cruas está cortada, mesmo com uma descartada' );
uox_assert( true === ( uonix_intelligence_executive_top_pages( $cortada, $janelaPag, $statusFixo, $rotulo )['truncated'] ?? null ), 'e o bloco de páginas carrega o corte adiante' );
uox_assert( is_wp_error( uonix_intelligence_executive_fetch_gsc_pages( $CFG, $janelaPag, static function () { return new WP_Error( 'http' ); } ) ), 'falha da consulta chega como erro, não como lista vazia' );

// ---------------------------------------------------------------------------
// 11. collect(): o caminho inteiro, com rede injetada.
// ---------------------------------------------------------------------------

$periodoPedido = null;
$fetcherGa4    = static function ( $config, $period ) use ( &$periodoPedido ) {
	$periodoPedido = $period;
	return uox_ga4_rows( '2026-08-29', '2026-09-27', static function ( $d ) { return $d >= '2026-09-21' ? 12 : 10; } );
};
$periodoPaginas = null;
$fetcherPaginas = static function ( $config, $period ) use ( &$periodoPaginas, $linhasPaginas ) {
	$periodoPaginas = $period;
	return $linhasPaginas;
};
$exec = uonix_intelligence_executive_collect( array(
	'today'          => $HOJE,
	'lead_counts'    => $leadsFlat,
	'config'         => $CFG,
	'ga4_fetcher'    => $fetcherGa4,
	'gsc_fetcher'    => $fetcherGsc( 130 ),
	'pages_fetcher'  => $fetcherPaginas,
	'status_fetcher' => $statusFixo,
	'seo'            => $seo,
	'labeler'        => $rotulo,
) );
uox_assert( '2026-07-27' === ( $periodoPedido['start'] ?? '' ), 'o GA4 deve ser pedido desde 7 dias antes da janela mais antiga (03/08 − 7 = 27/07), para a cobertura ser verificável; obteve ' . ( $periodoPedido['start'] ?? '(nada)' ) );
uox_assert( '2026-09-27' === ( $periodoPedido['end'] ?? '' ), 'e até ontem' );
// As páginas usam a janela ASSENTADA de 28 dias, terminando no mesmo dia que a semana
// das impressões (hoje − 4), e não ontem.
uox_assert( '2026-09-24' === ( $periodoPaginas['end'] ?? '' ), 'as páginas devem terminar 4 dias antes de hoje, como as impressões; obteve ' . ( $periodoPaginas['end'] ?? '(nada)' ) );
uox_assert( '2026-08-28' === ( $periodoPaginas['start'] ?? '' ), 'e ter 28 dias: 28/08 a 24/09' );
uox_assert( '/servico/projeto-balancim/' === ( $exec['top_pages']['rows'][1]['path'] ?? '' ), 'collect deve montar as páginas com a soma dos redirecionamentos' );
uox_assert( 84 === ( $exec['scorecard']['boxes']['visits']['current'] ?? -1 ), 'collect deve montar a caixa de visitas a partir do fetcher' );
uox_assert( 3 === count( $exec['insights'] ), 'collect deve produzir os destaques' );

// Sem `lead_counts` injetado, os orçamentos vêm do banco pelo mesmo leitor do Módulo 5.
$GLOBALS['uox_lead_rows'] = array( '2026-09-22' => 3 );
$execDb = uonix_intelligence_executive_collect( array( 'today' => $HOJE, 'config' => $CFG, 'ga4_fetcher' => $fetcherGa4, 'gsc_fetcher' => $fetcherGsc( 130 ), 'pages_fetcher' => $fetcherPaginas, 'status_fetcher' => $statusFixo ) );
uox_assert( 3 === ( $execDb['scorecard']['boxes']['leads']['current'] ?? -1 ), 'sem injeção, os orçamentos devem vir do banco' );

$execSemCfg = uonix_intelligence_executive_collect( array( 'today' => $HOJE, 'lead_counts' => $leadsFlat, 'config' => null ) );
uox_assert( empty( $execSemCfg['scorecard']['boxes']['visits']['available'] ), 'sem credenciais as visitas são indisponíveis' );
uox_assert( empty( $execSemCfg['top_pages']['available'] ), 'e as páginas também' );
uox_assert( ! empty( $execSemCfg['scorecard']['boxes']['leads']['available'] ), 'mas os orçamentos, que não dependem do Google, continuam disponíveis' );
// Sem credencial, nenhuma consulta foi feita: o motivo não pode dizer que ela falhou.
uox_assert( 'config_missing' === ( $execSemCfg['scorecard']['boxes']['visits']['reason'] ?? '' ) && 'config_missing' === ( $execSemCfg['scorecard']['boxes']['conversion']['reason'] ?? '' ), 'sem credencial o motivo é config_missing, não ga4_fetch_failed; obteve ' . ( $execSemCfg['scorecard']['boxes']['visits']['reason'] ?? '(nada)' ) );

// ---------------------------------------------------------------------------
// 12. Renderização no e-mail semanal.
// ---------------------------------------------------------------------------

$analise = array( 'available' => true, 'reason' => '', 'source' => 'search_console', 'synced_at' => '2026-09-28T15:59:49+00:00', 'stale' => false, 'universe' => 113, 'rows' => $seo['rows'] );
$html    = uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $exec, 'period_label' => '29/08/2026 a 27/09/2026', 'environment' => 'production', 'panel_url' => '' ) );

/** HTML de uma célula do scorecard, identificada pelo título. */
function uox_celula( $html, $titulo ) {
	$i = strpos( $html, '>' . $titulo . '</div>' );
	if ( false === $i ) {
		return '';
	}
	$f = strpos( $html, '</td>', $i );
	return false === $f ? '' : substr( $html, $i, $f - $i );
}

$cLeads = uox_celula( $html, 'Orçamentos' );
$cVis   = uox_celula( $html, 'Visitas' );
$cOrg   = uox_celula( $html, 'Impressões na busca' );
$cConv  = uox_celula( $html, 'Conversão' );

// Os NÚMEROS, não só a presença dos rótulos. Lição do PR #298: um teste que procura a
// frase e nunca o valor deixa a tela imprimir o número errado com a suíte verde.
uox_assert( 1 === preg_match( '/font-size:26px[^>]*>2</u', $cLeads ), 'a caixa de orçamentos deve exibir 2 como valor principal' );
uox_assert( false !== strpos( $cLeads, '4 semanas: 4, contra 3 nas 4 anteriores' ), 'a caixa de orçamentos deve exibir a comparação de 4 semanas em número absoluto' );
uox_assert( false === strpos( $cLeads, '%' ), 'a caixa de orçamentos NÃO pode conter porcentagem' );
uox_assert( false !== strpos( $cLeads, 'sem mudança detectável' ), 'a caixa de orçamentos deve exibir o resultado do teste' );

uox_assert( 1 === preg_match( '/font-size:26px[^>]*>84</u', $cVis ), 'a caixa de visitas deve exibir 84' );
uox_assert( false !== strpos( $cVis, '+20,0%' ), 'a caixa de visitas deve exibir +20,0%' );
uox_assert( false !== strpos( $cVis, 'só visitas com consentimento' ), 'a caixa de visitas deve declarar que só conta visitas com consentimento' );

uox_assert( 1 === preg_match( '/font-size:26px[^>]*>910</u', $cOrg ), 'a caixa de impressões deve exibir 910' );
uox_assert( false !== strpos( $cOrg, '+30,0%' ), 'a caixa de impressões deve exibir +30,0%' );
uox_assert( false !== strpos( $cOrg, '18/09 a 24/09' ), 'a caixa de impressões deve declarar a própria semana, que termina antes das outras' );

uox_assert( false !== strpos( $cConv, 'até 1,4%' ), 'a conversão deve aparecer como teto: até 1,4% (4/294), obteve ' . strip_tags( $cConv ) );
uox_assert( false !== strpos( $cConv, '4 orçamentos em 294 visitas' ), 'a conversão deve exibir numerador e denominador, com o plural flexionado' );
uox_assert( '1 orçamento' === uonix_intelligence_executive_plural( 1, 'orçamento', 'orçamentos' ) && '0 orçamentos' === uonix_intelligence_executive_plural( 0, 'orçamento', 'orçamentos' ), 'o plural deve ser singular só para 1' );
uox_assert( false !== strpos( $cConv, 'sem histórico do GA4 para comparar' ), 'sem histórico a conversão deve dizer que não compara' );

// Ordem dos blocos: o leitor executivo lê o resumo primeiro.
$pResumo = strpos( $html, 'Resumo da semana' );
$pDest   = strpos( $html, 'Destaques' );
$pSeo    = strpos( $html, 'Oportunidades de busca a um passo do topo' );
$pPag    = strpos( $html, 'Páginas mais encontradas na busca' );
uox_assert( false !== $pResumo && false !== $pDest && false !== $pSeo && false !== $pPag, 'os quatro blocos devem estar presentes' );
uox_assert( $pResumo < $pDest && $pDest < $pSeo && $pSeo < $pPag, 'a ordem deve ser: resumo, destaques, oportunidades de SEO, páginas' );
uox_assert( false !== strpos( $html, '/ensaio-de-arrancamento/' ), 'o bloco de páginas deve listar a página de maior impressão' );

// As marcas de status das páginas (ALTO 2 da revisão do PR #301).
uox_assert( false !== strpos( $html, 'Esta página não existe (404)' ), 'a página que dá 404 deve aparecer marcada em vermelho no e-mail' );
uox_assert( false !== strpos( $html, 'Inclui 1 endereço antigo que redireciona para cá.' ), 'o destino deve declarar que somou um endereço antigo' );
uox_assert( false === strpos( $html, 'Título de /olhal-de-ancoragem/' ), 'a página inexistente não pode aparecer com título' );
uox_assert( false !== strpos( $html, 'endereços antigos que redirecionam foram somados ao destino' ), 'a procedência do bloco deve dizer que os redirecionamentos foram somados' );
uox_assert( false === strpos( $html, 'lista pode estar incompleta' ), 'lista que não foi cortada não pode se declarar incompleta' );

// A procedência só afirma a soma quando ela aconteceu (BAIXO da segunda revisão).
$execSemSoma = $exec;
foreach ( $execSemSoma['top_pages']['rows'] as $i => $r ) { $execSemSoma['top_pages']['rows'][ $i ]['merged'] = 0; }
$htmlSemSoma = uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $execSemSoma, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) );
uox_assert( false === strpos( $htmlSemSoma, 'foram somados ao destino' ), 'semana sem endereço antigo somado não pode dizer que somou' );
$execCortado = $exec;
$execCortado['top_pages']['truncated'] = true;
uox_assert( false !== strpos( uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $execCortado, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) ), 'a Search Console devolveu o limite de páginas, e a lista pode estar incompleta' ), 'lista cortada pelo limite deve ser declarada incompleta' );

// MÉDIO 6 da revisão do PR #301: todo texto condicional do e-mail precisa de asserção.
// Sem isto, a lição do PR #298 se repetia — a tela podia dizer o texto errado com a
// suíte verde.
$renderCom = static function ( array $troca ) use ( $exec, $analise ) {
	$e = $exec;
	foreach ( $troca as $chave => $caixa ) { $e['scorecard']['boxes'][ $chave ] = $caixa; }
	return uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $e, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) );
};

$cAnt = uox_celula( $renderCom( array( 'organic' => $orgFurado ) ), 'Impressões na busca' );
uox_assert( false !== strpos( $cAnt, 'sem comparação: a semana anterior tem 2 dias sem dado' ), 'semana anterior incompleta deve ser dita na caixa, obteve: ' . strip_tags( $cAnt ) );
uox_assert( false === strpos( $cAnt, '%' ), 'e a caixa não pode exibir porcentagem nesse caso' );

$cImp = uox_celula( $renderCom( array( 'organic' => $orgImp ) ), 'Impressões na busca' );
uox_assert( false !== strpos( $cImp, 'sem comparação: 1 dia desta semana ainda sem dado' ), 'semana atual incompleta deve ser dita na caixa, obteve: ' . strip_tags( $cImp ) );

$cCol = uox_celula( $renderCom( array( 'organic' => $orgCol ) ), 'Impressões na busca' );
uox_assert( false !== strpos( $cCol, 'nenhuma impressão na semana' ) && false !== strpos( $cCol, 'Anomalias' ), 'o colapso deve ser dito e remeter à aba Anomalias' );

$cUp = uox_celula( $renderCom( array( 'leads' => $caixaAlta ) ), 'Orçamentos' );
uox_assert( false !== strpos( $cUp, '▲ aumento detectável' ), 'aumento detectável de orçamentos deve aparecer na caixa' );
$cDown = uox_celula( $renderCom( array( 'leads' => $caixaBaixa ) ), 'Orçamentos' );
uox_assert( false !== strpos( $cDown, '▼ queda detectável' ), 'queda detectável de orçamentos deve aparecer na caixa' );

$cConvCmp = uox_celula( $renderCom( array( 'conversion' => $convCmp ) ), 'Conversão' );
uox_assert( false !== strpos( $cConvCmp, 'antes: até ' . number_format( $convCmp['prev_rate'] * 100, 1, ',', '.' ) . '%' ), 'conversão comparável deve exibir a taxa anterior como teto, obteve: ' . strip_tags( $cConvCmp ) );
uox_assert( false !== strpos( $cConvCmp, '▲ aumento detectável' ), 'e o resultado do teste' );

$cVisNova = uox_celula( $renderCom( array( 'visits' => $visNovas ) ), 'Visitas' );
uox_assert( false !== strpos( $cVisNova, 'sem histórico do GA4 para comparar' ) && false === strpos( $cVisNova, '%' ), 'visitas sem histórico dizem isso e não exibem porcentagem' );

// Escape: rótulo de página e texto de destaque vêm de dado.
$execXss = $exec;
$execXss['top_pages']['rows'][0]['label'] = '<script>alert(1)</script>';
$execXss['insights'][0]['text']           = '<img src=x onerror=alert(1)>';
$htmlXss = uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $execXss, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) );
uox_assert( false === strpos( $htmlXss, '<script>alert(1)</script>' ), 'rótulo de página não pode sair cru no e-mail' );
uox_assert( false === strpos( $htmlXss, '<img src=x' ), 'texto de destaque não pode sair cru no e-mail' );

// Caixa indisponível mostra o motivo, não um zero.
$execInd = $exec;
$execInd['scorecard']['boxes']['visits'] = uonix_intelligence_executive_unavailable_box( 'visits', 'ga4_fetch_failed' );
$cInd    = uox_celula( uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $execInd, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) ), 'Visitas' );
uox_assert( false !== strpos( $cInd, 'Indisponível' ) && false !== strpos( $cInd, 'GA4 falhou' ), 'caixa indisponível deve dizer o motivo' );
uox_assert( 0 === preg_match( '/font-size:26px/u', $cInd ), 'caixa indisponível não pode exibir número principal' );

// Todo motivo que chega a uma caixa tem texto de LEITOR no 57. A versão anterior delegava
// os motivos da Search Console ao texto da aba Anomalias, e o e-mail imprimia "O gatilho
// tenta de novo na próxima verificação" — frase para o operador, não para o executivo.
$fonte58 = (string) file_get_contents( $RAIZ . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php' );
$fonte59 = (string) file_get_contents( $RAIZ . '/mu-plugins/uonix-admin/59-admin-intelligence-executive.php' );
preg_match_all( "/anomaly_unavailable\(\s*'organic_drop',\s*'([a-z0-9_]+)'/", $fonte58, $mOrg );
preg_match_all( "/return array\( 'reason' => '([a-z0-9_]+)' \)/", $fonte58, $mJan );
preg_match_all( "/unavailable_box\(\s*'[a-z]+',\s*'([a-z0-9_]+)'/", $fonte59, $mExe );
// Com dígito na classe: `ga4_missing` e `ga4_fetch_failed` escapavam de `[a-z_]`.
preg_match_all( "/\? '([a-z0-9_]+)' : '([a-z0-9_]+)';/", $fonte59, $mGa4 );
$motivosCaixa = array_unique( array_merge( $mOrg[1], $mJan[1], $mExe[1], $mGa4[1], $mGa4[2] ) );
uox_assert( count( $mOrg[1] ) >= 4 && count( $mJan[1] ) >= 2 && count( $mExe[1] ) >= 4 && in_array( 'ga4_fetch_failed', $motivosCaixa, true ) && in_array( 'ga4_missing', $motivosCaixa, true ), 'a extração de motivos não pode vir vazia, senão o laço abaixo é vácuo; achou ' . json_encode( array_values( $motivosCaixa ) ) );
foreach ( $motivosCaixa as $motivo ) {
	$texto = uonix_intelligence_report_executive_reason( $motivo );
	uox_assert( 'o dado não está disponível nesta semana.' !== $texto, "o motivo '{$motivo}' chega a uma caixa do e-mail e cai no texto genérico" );
	uox_assert( false === stripos( $texto, 'gatilho' ), "o motivo '{$motivo}' fala de gatilho, que o leitor do e-mail não tem: {$texto}" );
}

// Sem destaques, o bloco de destaques não aparece — nada de título vazio.
$execSemDest = $exec;
$execSemDest['insights'] = array();
uox_assert( false === strpos( uonix_intelligence_report_html( array( 'analysis' => $analise, 'executive' => $execSemDest, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) ), '>Destaques<' ), 'sem destaques o bloco não pode aparecer vazio' );

// Compatibilidade: sem o contexto executivo, o e-mail sai como antes.
$htmlAntigo = uonix_intelligence_report_html( array( 'analysis' => $analise, 'period_label' => '', 'environment' => 'production', 'panel_url' => '' ) );
uox_assert( false === strpos( $htmlAntigo, 'Resumo da semana' ) && false === strpos( $htmlAntigo, 'Páginas mais encontradas' ), 'sem contexto executivo nenhum bloco do Módulo 4 pode aparecer' );
uox_assert( false !== strpos( $htmlAntigo, 'Oportunidades de busca a um passo do topo' ), 'e o bloco de SEO continua lá' );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: Relatório Executivo (Módulo 4).\n" );
