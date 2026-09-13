<?php
/**
 * Testes da Fase 2 do Uônix Insights: métricas agregadas GA4 e Search Console.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );

$failures = 0;
$GLOBALS['uonix_metrics_options'] = array();
$GLOBALS['uonix_metrics_transients'] = array();
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

uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_compare' ), 'Helper de comparação existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_sanitize_query' ), 'Helper de sanitização de consulta existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_normalize_path' ), 'Helper de normalização de URL existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_get_config' ), 'Resolvedor de configuração existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_decode_ga4_report' ), 'Decoder bruto GA4 existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_decode_search_console_report' ), 'Decoder bruto Search Console existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_assemble_google_data' ), 'Montador de dados brutos Google existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_finite_number' ), 'Validador numérico finito existe' );

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
	$gsc_empty_raw = '{"responseAggregationType":"byProperty","rows":[]}';
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_raw, false ) ), 'Decoder Search Console aceita resposta vazia real em JSON bruto' );
	$gsc_empty_without_rows_raw = '{"responseAggregationType":"byProperty"}';
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_without_rows_raw, false ) ), 'Decoder Search Console aceita resposta vazia da API sem propriedade rows' );
	uonix_metrics_assert( is_array( uonix_analytics_metrics_decode_search_console_report( $gsc_empty_without_rows_raw, true ) ), 'Decoder Search Console aceita ranking vazio da API sem propriedade rows' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":{}}', false ) ), 'Decoder Search Console rejeita objeto JSON onde contrato exige array' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":[{"keys":{"0":"consulta"},"clicks":1,"impressions":2,"ctr":0.5,"position":3}]}' , true ) ), 'Decoder Search Console rejeita objeto JSON com chaves numéricas em keys' );
	uonix_metrics_assert( is_wp_error( uonix_analytics_metrics_decode_search_console_report( '{"responseAggregationType":"byProperty","rows":[{"clicks":"1e309","impressions":2,"ctr":0.5,"position":3}]}' , false ) ), 'Decoder Search Console rejeita métrica infinita' );
}

if ( function_exists( 'uonix_analytics_metrics_assemble_google_data' ) ) {
	$ga4_empty_raw = '{"kind":"analyticsData#runReport","metadata":{}}';
	$gsc_empty_raw = '{"responseAggregationType":"byProperty","rows":[]}';
	$assembled = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw );
	uonix_metrics_assert( is_array( $assembled ) && isset( $assembled['ga4'], $assembled['search_console'] ), 'Bundle JSON bruto vazio monta dados agregados para sincronização' );
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
				array( 'path' => '/a/?x=1', 'sessions' => '5' ),
				array( 'path' => '/b/', 'sessions' => '3' ),
			),
		)
	);
	uonix_metrics_assert( 12.0 === $ga4['summary']['active_users']['current'] && 20.0 === $ga4['summary']['sessions']['current'], 'Normalizador GA4 mantém métricas agregadas' );
	uonix_metrics_assert( 'new' === $ga4['summary']['sessions']['state'], 'Normalizador GA4 aplica comparação segura para base zero' );
	uonix_metrics_assert( '/a/' === $ga4['landing_pages'][0]['path'] && 2 === count( $ga4['landing_pages'] ), 'Normalizador GA4 remove query e preserva top páginas' );
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
	uonix_metrics_assert( is_array( $sync ) && 'updated' === $sync['status'] && 3.0 === $sync['ga4']['summary']['active_users']['current'], 'Sincronização armazena snapshot agregado com transport injetado' );
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
	$GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock'] = time();
	$locked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config );
	uonix_metrics_assert( is_wp_error( $locked ) && 'sync_locked' === $locked->get_error_code(), 'Trava impede sincronização concorrente' );
	unset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock'] );
}

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: métricas GA4/Search Console normalizadas sem PII e com comparação segura.\n";
