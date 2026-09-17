<?php
/**
 * Testes da Fase 2 do Uônix Insights: métricas agregadas GA4 e Search Console.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$failures = 0;
$GLOBALS['uonix_metrics_options'] = array();
$GLOBALS['uonix_metrics_transients'] = array();
$GLOBALS['uonix_metrics_http_capture'] = null;
function uonix_metrics_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uonix_metrics_options'] ) ? $GLOBALS['uonix_metrics_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['uonix_metrics_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['uonix_metrics_options'] ) ) return false; $GLOBALS['uonix_metrics_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uonix_metrics_options'][ $key ] ); return true; }
function get_transient( $key ) { return $GLOBALS['uonix_metrics_transients'][ $key ] ?? false; }
function set_transient( $key, $value ) { $GLOBALS['uonix_metrics_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['uonix_metrics_transients'][ $key ] ); return true; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function wp_remote_post( $url, $args ) {
	if ( null !== $GLOBALS['uonix_metrics_http_capture'] && str_contains( $url, 'analyticsdata.googleapis.com' ) ) {
		$GLOBALS['uonix_metrics_http_capture'] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"0"}' );
	}
	throw new RuntimeException( 'Unexpected network request' );
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['uonix_metrics_actions'][] = array( $hook, $callback, $priority, $accepted_args ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uonix_metrics_cron'][ $hook ] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['uonix_metrics_cron'][ $hook ] = $timestamp; return true; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function check_admin_referer() { return true; }
function esc_html__( $text ) { return $text; }
function wp_die( $text ) { throw new RuntimeException( $text ); }
function wp_safe_redirect() { return true; }
function admin_url( $path ) { return 'https://uonix.com.br/wp-admin/' . $path; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';

$metrics_module_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php' );
uonix_metrics_assert( false !== strpos( $metrics_module_source, "array( array( 'name' => 'landingPagePlusQueryString' ) ), 10000" ), 'Coleta de landing pages lê até 10.000 variantes antes de agregá-las por caminho' );

uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_compare' ), 'Helper de comparação existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_sanitize_query' ), 'Helper de sanitização de consulta existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_normalize_path' ), 'Helper de normalização de URL existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_get_config' ), 'Resolvedor de configuração existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_decode_ga4_report' ), 'Decoder bruto GA4 existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_decode_search_console_report' ), 'Decoder bruto Search Console existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_assemble_google_data' ), 'Montador de dados brutos Google existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_finite_number' ), 'Validador numérico finito existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_allowed_period_days' ), 'Allowlist de períodos existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_sanitize_period_days' ), 'Sanitizador de período existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' ), 'Validador de frescor do snapshot existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_decode_ga4_page_views_report' ), 'Decoder GA4 de visualizações por página existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_ga4_page_views_report' ), 'Construtor da requisição GA4 de visualizações existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_fetch_ga4_page_views' ), 'Paginador GA4 de visualizações por página existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_normalize_page_views' ), 'Agregador de visualizações por caminho existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_requested_period' ), 'Leitor seguro do período solicitado existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_refresh_redirect_url' ), 'Construtor do retorno ao período atualizado existe' );

if ( function_exists( 'uonix_analytics_metrics_allowed_period_days' ) && function_exists( 'uonix_analytics_metrics_sanitize_period_days' ) ) {
	uonix_metrics_assert( array( 7, 30, 90, 365 ) === uonix_analytics_metrics_allowed_period_days(), 'Períodos permitidos permanecem fechados em 7, 30, 90 e 365 dias' );
	uonix_metrics_assert( 90 === uonix_analytics_metrics_sanitize_period_days( '90' ), 'Período permitido é aceito' );
	uonix_metrics_assert( 30 === uonix_analytics_metrics_sanitize_period_days( '13' ), 'Período arbitrário volta ao padrão' );
	uonix_metrics_assert( 30 === uonix_analytics_metrics_sanitize_period_days( '7.5' ), 'Período fracionário não é truncado para um valor permitido' );
	uonix_metrics_assert( 30 === uonix_analytics_metrics_sanitize_period_days( '7e0' ), 'Notação numérica alternativa não contorna a allowlist literal' );
}

if ( function_exists( 'uonix_analytics_metrics_periods' ) ) {
	$seven_day_periods = uonix_analytics_metrics_periods( '2026-09-13', 7 );
	uonix_metrics_assert(
		array( 'start' => '2026-09-06', 'end' => '2026-09-12' ) === $seven_day_periods['current']
		&& array( 'start' => '2026-08-30', 'end' => '2026-09-05' ) === $seven_day_periods['previous'],
		'Período de sete dias termina ontem e compara sete dias anteriores'
	);
}

if ( function_exists( 'uonix_analytics_metrics_get_snapshot' ) ) {
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v2_7' => array(
			'version' => 2,
			'period_days' => 7,
			'status' => 'updated',
			'updated_at' => '2026-09-13T00:00:00+00:00',
		),
		'uonix_analytics_metrics_snapshot_v1' => array(
			'version' => 1,
			'status' => 'updated',
			'updated_at' => '2026-09-12T00:00:00+00:00',
		),
	);
	$seven_day_snapshot = uonix_analytics_metrics_get_snapshot( 7 );
	$legacy_snapshot = uonix_analytics_metrics_get_snapshot( 30 );
	uonix_metrics_assert( is_array( $seven_day_snapshot ) && isset( $seven_day_snapshot['period_days'] ) && 7 === $seven_day_snapshot['period_days'], 'Snapshot de 7 dias usa chave própria' );
	uonix_metrics_assert( is_array( $legacy_snapshot ) && 1 === $legacy_snapshot['version'], 'Snapshot legado é fallback somente de 30 dias' );
	uonix_metrics_assert( false === uonix_analytics_metrics_get_snapshot( 90 ), 'Período sem cache não herda snapshot de 30 dias' );
	$GLOBALS['uonix_metrics_options'] = array();
}

if ( function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' ) ) {
	uonix_metrics_assert(
		uonix_analytics_metrics_snapshot_is_fresh(
			array( 'status' => 'updated', 'updated_at' => '2026-09-13T00:00:00+00:00' ),
			strtotime( '2026-09-13T23:59:59+00:00' )
		),
		'Snapshot updated com menos de 24 horas é fresco'
	);
	uonix_metrics_assert(
		! uonix_analytics_metrics_snapshot_is_fresh(
			array( 'status' => 'updated', 'updated_at' => '2026-09-13T00:00:00+00:00' ),
			strtotime( '2026-09-14T00:00:01+00:00' )
		),
		'Snapshot updated com mais de 24 horas é vencido'
	);
	uonix_metrics_assert(
		! uonix_analytics_metrics_snapshot_is_fresh(
			array( 'status' => 'stale', 'updated_at' => '2026-09-13T23:00:00+00:00' ),
			strtotime( '2026-09-13T23:30:00+00:00' )
		),
		'Snapshot stale nunca é classificado como fresco'
	);
}

if ( function_exists( 'uonix_analytics_metrics_requested_period' ) && function_exists( 'uonix_analytics_metrics_refresh_redirect_url' ) ) {
	uonix_metrics_assert( 90 === uonix_analytics_metrics_requested_period( array( 'uonix_period' => '90' ) ), 'POST preserva o período permitido escolhido' );
	uonix_metrics_assert( 30 === uonix_analytics_metrics_requested_period( array( 'uonix_period' => '13' ) ), 'POST inválido volta ao período seguro de 30 dias' );
	uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_requested_dashboard_state' ), 'Estado de abas seguro existe no módulo de métricas' );
	$tab_state = uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'metrics', 'subtab' => 'catalog', 'catalog_tab' => 'blog' ) );
	uonix_metrics_assert( array( 'tab' => 'metrics', 'subtab' => 'catalog', 'catalog_tab' => 'blog' ) === $tab_state, 'Estado permitido de abas é preservado no POST de sincronização' );
	$invalid_tab_state = uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'qualquer', 'subtab' => 'qualquer', 'catalog_tab' => 'qualquer' ) );
	uonix_metrics_assert( array( 'tab' => 'metrics', 'subtab' => 'aggregate', 'catalog_tab' => 'products' ) === $invalid_tab_state, 'Estado de abas fora da allowlist volta ao padrão seguro' );
	$non_scalar_warning = false;
	set_error_handler(
		static function () use ( &$non_scalar_warning ) {
			$non_scalar_warning = true;
			return true;
		}
	);
	$non_scalar_tab_state = uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => array( 'destinations' ), 'subtab' => array( 'catalog' ), 'catalog_tab' => array( 'blog' ) ) );
	restore_error_handler();
	uonix_metrics_assert( ! $non_scalar_warning && array( 'tab' => 'metrics', 'subtab' => 'aggregate', 'catalog_tab' => 'products' ) === $non_scalar_tab_state, 'Estado de abas não aceita parâmetros não escalares sem gerar warning' );
	uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_dashboard_url' ), 'Construtor de URL estável do dashboard existe' );
	uonix_metrics_assert( 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics&uonix_period=90&tab=destinations&subtab=aggregate&catalog_tab=products' === uonix_analytics_metrics_dashboard_url( 90, array( 'tab' => 'destinations', 'subtab' => 'aggregate', 'catalog_tab' => 'products' ) ), 'URL de navegação preserva período e não propaga marcador de sincronização' );
	uonix_metrics_assert( 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics&uonix_period=365&tab=metrics&subtab=catalog&catalog_tab=blog&uonix_metrics_refresh=1' === uonix_analytics_metrics_refresh_redirect_url( 365, $tab_state ), 'Redirect preserva período e estado de abas após a sincronização' );
}

if ( function_exists( 'uonix_analytics_metrics_finite_number' ) ) {
	uonix_metrics_assert( null !== uonix_analytics_metrics_finite_number( '1.25' ), 'Número decimal finito é aceito' );
	uonix_metrics_assert( null === uonix_analytics_metrics_finite_number( '1e309' ), 'Número infinito é rejeitado' );
}

if ( function_exists( 'uonix_analytics_metrics_compare' ) ) {
	$overflow_compare = uonix_analytics_metrics_compare( '1e308', '1e-308' );
	uonix_metrics_assert( is_wp_error( $overflow_compare ), 'Comparação rejeita variação percentual infinita' );
}

if ( function_exists( 'uonix_analytics_metrics_decode_ga4_report' ) && function_exists( 'uonix_analytics_metrics_decode_search_console_report' ) ) {
	$ga4_empty_raw = '{"kind":"analyticsData#runReport","metadata":{}}';
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_ga4_report( $ga4_empty_raw ) ), 'Decoder GA4 aceita resposta vazia real em JSON bruto' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"1"}' ) ), 'Decoder GA4 rejeita rowCount sem rows diferente de zero' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"9223372036854775808"}' ) ), 'Decoder GA4 rejeita rowCount fora de faixa inteira' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":{}}' ) ), 'Decoder GA4 rejeita objeto JSON onde contrato exige array' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":{"0":{"metricValues":[{"value":"1"},{"value":"1"}]}}}' ) ), 'Decoder GA4 rejeita objeto JSON com chaves numéricas em rows' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":[{"metricValues":{"0":{"value":"1"},"1":{"value":"1"}}}]}' ) ), 'Decoder GA4 rejeita objeto JSON com chaves numéricas em metricValues' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":[{"metricValues":[{"value":"1"},{"value":"1"}],"dimensionValues":[]}]}' , true ) ), 'Decoder GA4 dimensional rejeita dimensionValues vazio' );
	$blank_landing_page = uonix_analytics_metrics_decode_ga4_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"1","rows":[{"metricValues":[{"value":"1"},{"value":"2"}],"dimensionValues":[{"value":""}]}]}' , true );
	uonix_metrics_assert( is_array( $blank_landing_page ) && '' === $blank_landing_page['rows'][0]['dimensionValues'][0]['value'], 'Decoder preserva bucket GA4 de landing page sem nome para filtragem posterior' );
	$gsc_empty_raw = '{"responseAggregationType":"byProperty","rows":[]}';
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_raw, false ) ), 'Decoder Search Console aceita resposta vazia real em JSON bruto' );
	$gsc_empty_without_rows_raw = '{"responseAggregationType":"byProperty"}';
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_without_rows_raw, false ) ), 'Decoder Search Console aceita resposta vazia da API sem propriedade rows' );
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_without_rows_raw, true ) ), 'Decoder Search Console aceita ranking vazio da API sem propriedade rows' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":{}}', false ) ), 'Decoder Search Console rejeita objeto JSON onde contrato exige array' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":[{"keys":{"0":"consulta"},"clicks":1,"impressions":2,"ctr":0.5,"position":3}]}' , true ) ), 'Decoder Search Console rejeita objeto JSON com chaves numéricas em keys' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":[{"clicks":"1e309","impressions":2,"ctr":0.5,"position":3}]}' , false ) ), 'Decoder Search Console rejeita métrica infinita' );
}

if ( function_exists( 'uonix_analytics_metrics_decode_ga4_page_views_report' ) ) {
	$page_views_raw = '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"2","rows":[{"dimensionValues":[{"value":"/produto-a/"}],"metricValues":[{"value":"12"}]},{"dimensionValues":[{"value":"/produto-b/"}],"metricValues":[{"value":"3"}]}]}';
	$page_views_report = uonix_analytics_metrics_decode_ga4_page_views_report( $page_views_raw );
	uonix_metrics_assert( is_array( $page_views_report ) && 2 === $page_views_report['row_count'] && 12.0 === $page_views_report['rows'][0]['views'], 'Decoder aceita pagePath e screenPageViews finitos' );

	$page_views_empty = uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"0"}' );
	uonix_metrics_assert( is_array( $page_views_empty ) && 0 === $page_views_empty['row_count'] && array() === $page_views_empty['rows'], 'Decoder aceita relatório de visualizações vazio explícito' );
	uonix_metrics_assert(
		is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"inválido"}' ) ),
		'Decoder rejeita rowCount inválido quando rows está ausente'
	);
	uonix_metrics_assert(
		is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"9223372036854775808"}' ) ),
		'Decoder rejeita rowCount fora da faixa quando rows está ausente'
	);

	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":{}}' ) ), 'Decoder de visualizações rejeita objeto JSON em rows' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":[{"dimensionValues":[],"metricValues":[{"value":"1"}]}]}' ) ), 'Decoder de visualizações rejeita pagePath ausente' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rows":[{"dimensionValues":[{"value":"/a/"}],"metricValues":[{"value":"1e309"}]}]}' ) ), 'Decoder de visualizações rejeita screenPageViews não finito' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_ga4_page_views_report( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"0","rows":[{"dimensionValues":[{"value":"/a/"}],"metricValues":[{"value":"1"}]}]}' ) ), 'Decoder de visualizações rejeita rowCount menor que as linhas retornadas' );
}

if ( function_exists( 'uonix_analytics_metrics_fetch_ga4_page_views' ) ) {
	$page_view_requests = array();
	$page_view_requester = static function ( $property_id, $token, $period, $limit, $offset ) use ( &$page_view_requests ) {
		$page_view_requests[] = array( 'property_id' => $property_id, 'period' => $period, 'limit' => $limit, 'offset' => $offset );
		if ( 0 === $offset ) {
			return '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"3","rows":[{"dimensionValues":[{"value":"/a/?x=1"}],"metricValues":[{"value":"5"}]},{"dimensionValues":[{"value":"/a/?x=2"}],"metricValues":[{"value":"3"}]}]}';
		}
		return '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"3","rows":[{"dimensionValues":[{"value":"/b/"}],"metricValues":[{"value":"2"}]}]}';
	};
	$page_view_pages = uonix_analytics_metrics_fetch_ga4_page_views( '445033830', 'token-fixture', array( 'start' => '2026-09-01', 'end' => '2026-09-07' ), $page_view_requester, 2, 3 );
	uonix_metrics_assert( is_array( $page_view_pages ) && true === $page_view_pages['complete'] && 3 === count( $page_view_pages['rows'] ), 'Paginador reúne todas as linhas e confirma cobertura' );
	uonix_metrics_assert( array( 0, 2 ) === array_column( $page_view_requests, 'offset' ), 'Paginador avança pelo número real de linhas' );
	uonix_metrics_assert( array( 2, 2 ) === array_column( $page_view_requests, 'limit' ), 'Paginador preserva o limite validado' );

	$incomplete_requester = static function ( $property_id, $token, $period, $limit, $offset ) {
		if ( 0 === $offset ) return '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"3","rows":[{"dimensionValues":[{"value":"/a/"}],"metricValues":[{"value":"1"}]}]}';
		return '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"3","rows":[]}';
	};
	$incomplete_page_views = uonix_analytics_metrics_fetch_ga4_page_views( '445033830', 'token-fixture', array( 'start' => '2026-09-01', 'end' => '2026-09-07' ), $incomplete_requester, 2, 3 );
	uonix_metrics_assert( is_array( $incomplete_page_views ) && false === $incomplete_page_views['complete'] && 1 === count( $incomplete_page_views['rows'] ), 'Paginador não inventa cobertura ao receber página vazia antes de rowCount' );
}

if ( function_exists( 'uonix_analytics_metrics_ga4_page_views_report' ) ) {
	$GLOBALS['uonix_metrics_http_capture'] = array();
	$page_views_raw_response = uonix_analytics_metrics_ga4_page_views_report(
		'445033830',
		'token-fixture',
		array( 'start' => '2026-09-01', 'end' => '2026-09-07' ),
		250,
		500
	);
	$page_views_request = json_decode( $GLOBALS['uonix_metrics_http_capture']['args']['body'], true );
	uonix_metrics_assert( is_string( $page_views_raw_response ) && 'pagePath' === $page_views_request['dimensions'][0]['name'], 'Requisição de visualizações usa a dimensão pagePath' );
	uonix_metrics_assert( 'screenPageViews' === $page_views_request['metrics'][0]['name'], 'Requisição de visualizações usa a métrica screenPageViews' );
	uonix_metrics_assert( '250' === $page_views_request['limit'] && '500' === $page_views_request['offset'], 'Requisição de visualizações envia paginação decimal' );
	uonix_metrics_assert(
		isset( $page_views_request['orderBys'][0]['dimension']['dimensionName'] )
		&& 'pagePath' === $page_views_request['orderBys'][0]['dimension']['dimensionName'],
		'Requisição paginada ordena deterministicamente por pagePath'
	);
	$GLOBALS['uonix_metrics_http_capture'] = null;
}

if ( function_exists( 'uonix_analytics_metrics_normalize_page_views' ) ) {
	$normalized_page_views = uonix_analytics_metrics_normalize_page_views(
		array(
			array( 'path' => '/a/?x=1', 'views' => 5 ),
			array( 'path' => '/a/?x=2', 'views' => 3 ),
			array( 'path' => '/b/', 'views' => 2 ),
			array( 'path' => 'https://externo.example/pagina/', 'views' => 99 ),
		)
	);
	uonix_metrics_assert( array( '/a/' => 8.0, '/b/' => 2.0 ) === $normalized_page_views, 'Agregador soma caminhos normalizados e rejeita domínio externo' );
}

if ( function_exists( 'uonix_analytics_metrics_assemble_google_data' ) ) {
	$ga4_empty_raw = '{"kind":"analyticsData#runReport","metadata":{}}';
	$gsc_empty_raw = '{"responseAggregationType":"byProperty","rows":[]}';
	$assembled = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw );
	uonix_metrics_assert( is_array( $assembled ) && isset( $assembled['ga4'], $assembled['search_console'] ), 'Bundle JSON bruto vazio monta dados agregados para sincronização' );
	$ga4_landing_pages_with_blank_bucket = '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"2","rows":[{"metricValues":[{"value":"1"},{"value":"2"}],"dimensionValues":[{"value":""}]},{"metricValues":[{"value":"3"},{"value":"4"}],"dimensionValues":[{"value":"/entrada/"}]}]}';
	$assembled_with_blank_landing_bucket = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_landing_pages_with_blank_bucket, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw );
	$normalized_with_blank_landing_bucket = is_array( $assembled_with_blank_landing_bucket ) ? uonix_analytics_metrics_normalize_ga4( $assembled_with_blank_landing_bucket['ga4'] ) : null;
	uonix_metrics_assert( is_array( $normalized_with_blank_landing_bucket ) && array( array( 'path' => '/entrada/', 'sessions' => 4.0 ) ) === $normalized_with_blank_landing_bucket['landing_pages'], 'Bucket GA4 de landing page sem nome não impede snapshot e não vira ranking' );
	$page_views_fixture = array(
		'complete' => true,
		'rows' => array(
			array( 'path' => '/produto-a/?utm_source=teste', 'views' => 9 ),
			array( 'path' => '/produto-a/', 'views' => 3 ),
			array( 'path' => '/produto-b/', 'views' => 4 ),
		),
	);
	$assembled_with_page_views = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $page_views_fixture );
	uonix_metrics_assert( isset( $assembled_with_page_views['ga4']['page_views'], $assembled_with_page_views['ga4']['page_views_complete'] ), 'Montador preserva visualizações e cobertura para normalização' );
	$page_views_sync = uonix_analytics_metrics_sync( static function () use ( $assembled_with_page_views ) { return $assembled_with_page_views; }, array( 'ga4_property_id' => '445033830', 'search_console_site_url' => 'sc-domain:uonix.com.br', 'credentials' => array() ), 365 );
	uonix_metrics_assert( 12.0 === ( $page_views_sync['ga4']['page_views']['/produto-a/'] ?? null ), 'Sincronização agrega visualizações do mesmo caminho sem query string' );
	uonix_metrics_assert( true === ( $page_views_sync['ga4']['page_views_complete'] ?? null ), 'Snapshot registra cobertura completa das visualizações' );
	$raw_fixture_fetcher = static function () use ( $ga4_empty_raw, $gsc_empty_raw ) {
		return uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw );
	};
	$raw_fixture_config = array( 'ga4_property_id' => '445033830', 'search_console_site_url' => 'sc-domain:uonix.com.br', 'credentials' => array() );
	$raw_fixture_sync = uonix_analytics_metrics_sync( $raw_fixture_fetcher, $raw_fixture_config );
	uonix_metrics_assert( is_array( $raw_fixture_sync ) && 'updated' === $raw_fixture_sync['status'], 'JSON bruto válido percorre montador e sincronização até o snapshot' );
	$raw_invalid_sync = uonix_analytics_metrics_sync( static function () use ( $ga4_empty_raw, $gsc_empty_raw ) { return uonix_analytics_metrics_assemble_google_data( '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"1"}', $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw ); }, $raw_fixture_config );
	uonix_metrics_assert( is_array( $raw_invalid_sync ) && 'stale' === $raw_invalid_sync['status'] && 'sync_failed' === $raw_invalid_sync['error'], 'JSON bruto inválido não persiste updated e preserva snapshot stale' );
}


if ( function_exists( 'uonix_analytics_metrics_empty_summary' ) ) {
	$empty_ga4_summary = uonix_analytics_metrics_empty_summary( array( 'activeUsers', 'sessions' ) );
	uonix_metrics_assert( array( 'activeUsers' => 0, 'sessions' => 0 ) === $empty_ga4_summary, 'Resposta GA4 sem linhas vira estado sem dados explícito, não métrica ausente' );
	$empty_gsc_summary = uonix_analytics_metrics_empty_summary( array( 'clicks', 'impressions', 'ctr', 'position' ) );
	uonix_metrics_assert( array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ) === $empty_gsc_summary, 'Resposta Search Console sem linhas vira estado sem dados explícito, não métrica ausente' );
}

if ( function_exists( 'uonix_analytics_metrics_ga4_rows' ) ) {
	$malformed_ga4_rows = uonix_analytics_metrics_ga4_rows( array( 'rows' => array( array( 'metricValues' => array( array( 'value' => '3' ) ) ) ) ) );
	uonix_metrics_assert( ! isset( $malformed_ga4_rows[0]['sessions'] ), 'Conversor GA4 não transforma métrica ausente em zero' );
}

if ( function_exists( 'uonix_analytics_metrics_get_config' ) ) {
	$temp_dir = sys_get_temp_dir() . '/uonix-analytics-metrics-' . uniqid( '', true );
	mkdir( $temp_dir, 0700, true );
	$key_path = $temp_dir . '/service-account.json';
	file_put_contents( $key_path, json_encode( array(
		'type'         => 'service_account',
		'client_email' => 'analytics-reader@example.test',
		'private_key'  => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----\n",
		'token_uri'    => 'https://oauth2.googleapis.com/token',
	) ) );

	$config = uonix_analytics_metrics_get_config( $key_path, WP_CONTENT_DIR, '445033830', 'sc-domain:uonix.com.br' );
	uonix_metrics_assert( is_array( $config ) && '445033830' === $config['ga4_property_id'] && 'sc-domain:uonix.com.br' === $config['search_console_site_url'], 'Configuração aceita chave externa e identificadores auditados' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( '', WP_CONTENT_DIR, '445033830', 'sc-domain:uonix.com.br' ) ), 'Configuração rejeita caminho de chave ausente' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( $key_path, $temp_dir, '445033830', 'sc-domain:uonix.com.br' ) ), 'Configuração rejeita chave dentro do document root' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( $key_path, WP_CONTENT_DIR, '0', 'sc-domain:uonix.com.br' ) ), 'Configuração rejeita propriedade GA4 inválida' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( $key_path, WP_CONTENT_DIR, '123456789', 'sc-domain:uonix.com.br' ) ), 'Configuração rejeita propriedade GA4 não auditada' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( $key_path, WP_CONTENT_DIR, '445033830', 'https://uonix.com.br' ) ), 'Configuração rejeita propriedade Search Console fora do formato domain' );
	$invalid_key_path = $temp_dir . '/invalid-service-account.json';
	file_put_contents( $invalid_key_path, json_encode( array( 'type' => 'service_account', 'client_email' => array( 'invalid' ), 'private_key' => '[REDACTED]', 'token_uri' => 'https://oauth2.googleapis.com/token' ) ) );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_get_config( $invalid_key_path, WP_CONTENT_DIR, '445033830', 'sc-domain:uonix.com.br' ) ), 'Configuração rejeita campos de credencial com tipo inválido antes de autenticar' );
	unlink( $key_path );
	unlink( $invalid_key_path );
	rmdir( $temp_dir );
}

if ( function_exists( 'uonix_analytics_metrics_compare' ) ) {
	$increase = uonix_analytics_metrics_compare( 120.0, 100.0 );
	uonix_metrics_assert( 20.0 === $increase['delta_absolute'] && 20.0 === $increase['delta_percent'] && 'comparable' === $increase['state'], 'Comparação calcula aumento percentual com base positiva' );

	$new_period = uonix_analytics_metrics_compare( 8.0, 0.0 );
	uonix_metrics_assert( 8.0 === $new_period['delta_absolute'] && null === $new_period['delta_percent'] && 'new' === $new_period['state'], 'Comparação trata base zero como novo período' );

	$empty = uonix_analytics_metrics_compare( 0.0, 0.0 );
	uonix_metrics_assert( 0.0 === $empty['delta_absolute'] && null === $empty['delta_percent'] && 'empty' === $empty['state'], 'Comparação trata dois períodos vazios sem inventar percentual' );
}

if ( function_exists( 'uonix_analytics_metrics_normalize_path' ) ) {
	uonix_metrics_assert( '/servicos/ancoragem/' === uonix_analytics_metrics_normalize_path( '/servicos/ancoragem/?utm_source=ads' ), 'Normalizador remove query string de landing page' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_normalize_path( 'https://externo.example/?q=1' ), 'Normalizador rejeita URL absoluta externa' );
}

if ( function_exists( 'uonix_analytics_metrics_sanitize_query' ) ) {
	uonix_metrics_assert( 'linha de vida' === uonix_analytics_metrics_sanitize_query( 'linha de vida' ), 'Consulta regular é preservada' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'contato@empresa.test' ), 'Consulta com e-mail é removida' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'contato%40empresa.test' ), 'Consulta com e-mail percent-encoded é removida' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'contato&#64;empresa.test' ), 'Consulta com e-mail em entidade HTML é removida' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'contato @ empresa.test' ), 'Consulta com e-mail separado por espaços é removida' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'ligue 11987654321' ), 'Consulta com telefone é removida' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'https://example.test/caminho' ), 'Consulta URL é removida' );
}

if ( function_exists( 'uonix_analytics_metrics_normalize_ga4' ) ) {
	$ga4 = uonix_analytics_metrics_normalize_ga4(
		array(
			'summary_current'  => array( 'activeUsers' => '12', 'sessions' => '20' ),
			'summary_previous' => array( 'activeUsers' => '10', 'sessions' => '0' ),
			'landing_pages'    => array(
				array( 'path' => '/?x=1', 'sessions' => '5' ),
				array( 'path' => '/?x=2', 'sessions' => '3' ),
				array( 'path' => '/b/', 'sessions' => '3' ),
			),
		)
	);
	uonix_metrics_assert( 12.0 === $ga4['summary']['active_users']['current'] && 20.0 === $ga4['summary']['sessions']['current'], 'Normalizador GA4 mantém métricas agregadas' );
	uonix_metrics_assert( 'new' === $ga4['summary']['sessions']['state'], 'Normalizador GA4 aplica comparação segura para base zero' );
	uonix_metrics_assert( array( array( 'path' => '/', 'sessions' => 8.0 ), array( 'path' => '/b/', 'sessions' => 3.0 ) ) === $ga4['landing_pages'], 'Normalizador GA4 soma landing pages normalizadas e remove repetição da raiz' );
	$valid_after_invalid = array();
	for ( $i = 0; $i < 10; ++$i ) { $valid_after_invalid[] = array( 'path' => 'https://externo.example/' . $i, 'sessions' => 1 ); }
	$valid_after_invalid[] = array( 'path' => '/valida/', 'sessions' => 9 );
	$ga4_filtered = uonix_analytics_metrics_normalize_ga4( array( 'summary_current' => array( 'activeUsers' => 1, 'sessions' => 1 ), 'summary_previous' => array( 'activeUsers' => 1, 'sessions' => 1 ), 'landing_pages' => $valid_after_invalid ) );
	uonix_metrics_assert( 1 === count( $ga4_filtered['landing_pages'] ) && '/valida/' === $ga4_filtered['landing_pages'][0]['path'], 'GA4 coleta item válido após linhas inválidas iniciais' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_normalize_ga4( array( 'summary_current' => array( 'activeUsers' => 1 ), 'summary_previous' => array( 'activeUsers' => 1, 'sessions' => 1 ) ) ) ), 'GA4 falha fechado quando métrica obrigatória está ausente' );
}

if ( function_exists( 'uonix_analytics_metrics_normalize_search_console' ) ) {
	$gsc = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current'  => array( 'clicks' => 15, 'impressions' => 100, 'ctr' => 0.15, 'position' => 8.2 ),
			'summary_previous' => array( 'clicks' => 10, 'impressions' => 80, 'ctr' => 0.125, 'position' => 9.1 ),
			'queries'          => array(
				array( 'query' => 'linha de vida', 'clicks' => 4, 'impressions' => 20, 'ctr' => 0.2, 'position' => 4.0 ),
				array( 'query' => 'email@empresa.test', 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1 ),
			),
			'pages'            => array(
				array( 'page' => 'https://uonix.com.br/servicos/?utm=x', 'clicks' => 5, 'impressions' => 30, 'ctr' => 0.16, 'position' => 7.0 ),
			),
		)
	);
	uonix_metrics_assert( 15.0 === $gsc['summary']['clicks']['current'] && 100.0 === $gsc['summary']['impressions']['current'], 'Normalizador Search Console mantém resumo agregado' );
	uonix_metrics_assert( 1 === count( $gsc['queries'] ) && 'linha de vida' === $gsc['queries'][0]['query'], 'Normalizador Search Console remove consultas potencialmente pessoais' );
	uonix_metrics_assert( '/servicos/' === $gsc['pages'][0]['page'], 'Normalizador Search Console remove domínio e query da página' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_normalize_search_console( array( 'summary_current' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5 ), 'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ) ) ) ), 'Search Console falha fechado quando métrica obrigatória está ausente' );
}

if ( function_exists( 'uonix_analytics_metrics_sync' ) ) {
	$test_config = array( 'ga4_property_id' => '445033830', 'search_console_site_url' => 'sc-domain:uonix.com.br', 'credentials' => array() );
	$fixture_fetcher = static function () {
		return array(
			'ga4' => array( 'summary_current' => array( 'activeUsers' => 3, 'sessions' => 4 ), 'summary_previous' => array( 'activeUsers' => 2, 'sessions' => 2 ), 'landing_pages' => array() ),
			'search_console' => array( 'summary_current' => array( 'clicks' => 2, 'impressions' => 10, 'ctr' => .2, 'position' => 5 ), 'summary_previous' => array( 'clicks' => 1, 'impressions' => 8, 'ctr' => .125, 'position' => 6 ), 'queries' => array(), 'pages' => array() ),
		);
	};
	$sync = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config );
	uonix_metrics_assert( is_array( $sync ) && 2 === $sync['version'] && 30 === $sync['period_days'] && 'updated' === $sync['status'] && 3.0 === $sync['ga4']['summary']['active_users']['current'], 'Sincronização armazena snapshot agregado de 30 dias com transport injetado' );
	$stale = uonix_analytics_metrics_sync( static function () { throw new RuntimeException( 'transport failure' ); }, $test_config );
	uonix_metrics_assert( is_array( $stale ) && 'stale' === $stale['status'] && 3.0 === $stale['ga4']['summary']['active_users']['current'], 'Falha posterior preserva último snapshot como desatualizado' );
	$secret_error = uonix_analytics_metrics_sync( static function () { throw new RuntimeException( 'token email@example.test secret-marker' ); }, $test_config );
	uonix_metrics_assert( is_array( $secret_error ) && 'stale' === $secret_error['status'] && 'sync_failed' === $secret_error['error'], 'Falha não persiste texto de exceção ou possível PII' );
	$invalid_snapshot = uonix_analytics_metrics_sync(
		static function () {
			return array(
				'ga4' => array( 'summary_current' => array( 'activeUsers' => 3 ), 'summary_previous' => array( 'activeUsers' => 2, 'sessions' => 2 ), 'landing_pages' => array() ),
				'search_console' => array( 'summary_current' => array( 'clicks' => 2, 'impressions' => 10, 'ctr' => .2, 'position' => 5 ), 'summary_previous' => array( 'clicks' => 1, 'impressions' => 8, 'ctr' => .125, 'position' => 6 ), 'queries' => array(), 'pages' => array() ),
			);
		},
		$test_config
	);
	uonix_metrics_assert( is_array( $invalid_snapshot ) && 'stale' === $invalid_snapshot['status'] && 3.0 === $invalid_snapshot['ga4']['summary']['active_users']['current'], 'Métrica ausente não persiste snapshot updated inválido e preserva o anterior' );
	$GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_30'] = time();
	$locked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config );
	uonix_metrics_assert( is_wp_error( $locked ) && 'sync_locked' === $locked->get_error_code(), 'Trava impede sincronização concorrente' );
	unset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_30'] );

	$seven_sync = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 7 );
	$ninety_sync = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 90 );
	uonix_metrics_assert( 7 === $seven_sync['period_days'] && 90 === $ninety_sync['period_days'], 'Sincronização persiste o período selecionado' );
	uonix_metrics_assert( $seven_sync['periods'] !== $ninety_sync['periods'], 'Snapshots usam intervalos diferentes por período' );
	uonix_metrics_assert( isset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_snapshot_v2_7'], $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_snapshot_v2_90'] ), 'Snapshots de 7 e 90 dias usam opções distintas' );

	$seven_stale = uonix_analytics_metrics_sync( static function () { throw new RuntimeException( 'transport failure' ); }, $test_config, 7 );
	uonix_metrics_assert( 'stale' === $seven_stale['status'], 'Falha marca somente o período solicitado como stale' );
	uonix_metrics_assert( 'updated' === uonix_analytics_metrics_get_snapshot( 90 )['status'], 'Falha de 7 dias não marca snapshot de 90 dias como stale' );

	$GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_7'] = time();
	$seven_locked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 7 );
	$ninety_unlocked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 90 );
	uonix_metrics_assert( is_wp_error( $seven_locked ) && 'sync_locked' === $seven_locked->get_error_code(), 'Trava de 7 dias bloqueia o mesmo período' );
	uonix_metrics_assert( is_array( $ninety_unlocked ) && 'updated' === $ninety_unlocked['status'], 'Trava de 7 dias não bloqueia 90 dias' );
	unset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_7'] );
}

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: métricas GA4/Search Console normalizadas sem PII e com comparação segura.\n";
