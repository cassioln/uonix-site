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

// Fiel ao `wp_strip_all_tags()` do WordPress 7.1.4 (wp-includes/formatting.php): `<script>` e `<style>` saem COM o conteúdo e
// depois vem o `strip_tags()`. O stub antigo, só `strip_tags()`, divergia do real em 6 de 14 entradas medidas (script, style,
// comentário, `<img>` e `<3`), e a #382 depende justamente dessas diferenças.
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( (string) $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
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
	// #336: o token da conta de serviço, só quando o teste liga (as demais chamadas seguem recusando a rede).
	if ( ! empty( $GLOBALS['uonix_metrics_token_ok'] ) && str_contains( $url, 'oauth2.googleapis.com' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"token-fixture"}' );
	}
	if ( isset( $GLOBALS['uonix_metrics_gsc_log'] ) && str_contains( $url, 'searchAnalytics/query' ) ) {
		$GLOBALS['uonix_metrics_gsc_log'][] = json_decode( (string) ( $args['body'] ?? '' ), true );
	}
	// #300: o corpo de cada pedido ao GA4, e uma resposta programável da Search Console (devolve texto JSON, ou null para seguir o padrão).
	if ( isset( $GLOBALS['uonix_metrics_ga4_log'] ) && str_contains( $url, 'analyticsdata.googleapis.com' ) ) {
		$GLOBALS['uonix_metrics_ga4_log'][] = json_decode( (string) ( $args['body'] ?? '' ), true );
	}
	if ( is_callable( $GLOBALS['uonix_metrics_gsc_respond'] ?? null ) && str_contains( $url, 'searchAnalytics/query' ) ) {
		$corpo_programado = call_user_func( $GLOBALS['uonix_metrics_gsc_respond'], json_decode( (string) ( $args['body'] ?? '' ), true ) );
		if ( is_string( $corpo_programado ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => $corpo_programado );
		}
	}
	if ( null !== $GLOBALS['uonix_metrics_http_capture'] && str_contains( $url, 'analyticsdata.googleapis.com' ) ) {
		$GLOBALS['uonix_metrics_http_capture'] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"kind":"analyticsData#runReport","metadata":{},"rowCount":"0"}' );
	}
	if ( null !== $GLOBALS['uonix_metrics_http_capture'] && str_contains( $url, 'searchAnalytics/query' ) ) {
		$GLOBALS['uonix_metrics_http_capture'] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"responseAggregationType":"byProperty","rows":[]}' );
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
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_query_text_retention' ), 'Faixa de retenção de texto de consulta existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_query_text_is_retained' ), 'Predicado de retenção de texto existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_minimize_query_row' ), 'Minimizador de linha do universo existe' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_snapshot_option_cascade' ), 'Cascata de leitura do snapshot existe como fonte única' );
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_collect_legacy_snapshots' ), 'Coletor de gerações mortas de snapshot existe' );

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

// ---------------------------------------------------------------------------------------------------------------------------
// #300: a Search Console do painel compara janelas ASSENTADAS, e declara quantos dias cada uma tem. O painel comparava
// [hoje-N, ontem] contra a anterior, e a Search Console publica a série final com 2 a 3 dias de atraso: a janela atual somava N-1 a N-2 dias
// contra N (L-1, com L a defasagem de publicação). Medido em 2026-11-18, dado final: 7 dias, 6 de 7 na atual, -7,4% mostrado; com o dia
// provisório (`dataState: all`) a MESMA janela dá +19,0%. A semana assentada (outra semana) dá +23,2%.
// Datas esperadas calculadas por um oráculo independente (Python `datetime`), e não pela função testada.
// ---------------------------------------------------------------------------------------------------------------------------
uonix_metrics_assert( function_exists( 'uonix_analytics_metrics_search_console_settle_lag' ) && function_exists( 'uonix_analytics_metrics_search_console_periods' ) && function_exists( 'uonix_analytics_metrics_search_console_windows' ) && function_exists( 'uonix_analytics_metrics_snapshot_search_console_window' ), '#300: o 53 expõe a defasagem, as janelas assentadas, a contagem de dias presentes e o leitor da janela do snapshot' );
if ( function_exists( 'uonix_analytics_metrics_search_console_periods' ) && function_exists( 'uonix_analytics_metrics_search_console_settle_lag' ) ) {
	uonix_metrics_assert( 4 === uonix_analytics_metrics_search_console_settle_lag(), '#300: a defasagem é de 4 dias (3 medidos na Search Console, mais um de margem)' );
	$assentadas = array(
		// hoje, dias => atual (início, fim), anterior (início, fim)
		array( '2026-11-18', 7,   '2026-11-08', '2026-11-14', '2026-11-01', '2026-11-07' ),
		array( '2026-11-18', 30,  '2026-10-16', '2026-11-14', '2026-09-16', '2026-10-15' ),
		array( '2026-11-18', 90,  '2026-08-17', '2026-11-14', '2026-05-19', '2026-08-16' ),
		array( '2026-11-18', 365, '2025-11-15', '2026-11-14', '2024-11-15', '2025-11-14' ),
		array( '2027-01-02', 7,   '2026-12-23', '2026-12-29', '2026-12-16', '2026-12-22' ),
		array( '2028-03-03', 30,  '2028-01-30', '2028-02-28', '2027-12-31', '2028-01-29' ),
		array( '2026-10-01', 30,  '2026-08-29', '2026-09-27', '2026-07-30', '2026-08-28' ),
	);
	// A janela do GA4 (termina ONTEM), do mesmo oráculo: início e fim da atual e da anterior. Compara com LITERAIS, e não a função com ela
	// mesma: foi assim que a #300 deixaria passar uma defasagem que vazasse para o GA4.
	$ga4_esperado = array(
		'2026-11-18|7'   => array( '2026-11-11', '2026-11-17', '2026-11-04', '2026-11-10' ),
		'2026-11-18|30'  => array( '2026-10-19', '2026-11-17', '2026-09-19', '2026-10-18' ),
		'2026-11-18|90'  => array( '2026-08-20', '2026-11-17', '2026-05-22', '2026-08-19' ),
		'2026-11-18|365' => array( '2025-11-18', '2026-11-17', '2024-11-18', '2025-11-17' ),
		'2027-01-02|7'   => array( '2026-12-26', '2027-01-01', '2026-12-19', '2026-12-25' ),
		'2028-03-03|30'  => array( '2028-02-02', '2028-03-02', '2028-01-03', '2028-02-01' ),
		'2026-10-01|30'  => array( '2026-09-01', '2026-09-30', '2026-08-02', '2026-08-31' ),
	);
	foreach ( $assentadas as $caso ) {
		$p = uonix_analytics_metrics_search_console_periods( $caso[0], $caso[1] );
		uonix_metrics_assert(
			array( 'start' => $caso[2], 'end' => $caso[3] ) === $p['current'] && array( 'start' => $caso[4], 'end' => $caso[5] ) === $p['previous'],
			"#300: janelas assentadas de {$caso[1]} dias em {$caso[0]}; obteve " . var_export( $p, true )
		);
		$ga = uonix_analytics_metrics_periods( $caso[0], $caso[1] );
		uonix_metrics_assert( $p['current']['end'] < $ga['current']['end'], "#300: a janela da Search Console termina ANTES da do GA4 em {$caso[0]} (o GA4 termina ontem)" );
		$g = $ga4_esperado[ $caso[0] . '|' . $caso[1] ];
		uonix_metrics_assert(
			array( 'start' => $g[0], 'end' => $g[1] ) === $ga['current'] && array( 'start' => $g[2], 'end' => $g[3] ) === $ga['previous'],
			"#300: a janela do GA4 (uonix_analytics_metrics_periods) segue terminando ONTEM em {$caso[0]} ({$caso[1]} dias), sem a defasagem; obteve " . var_export( $ga, true )
		);
	}
	// A asserção anti-regressão central (a da issue): com a série terminando em HOJE - 3 (o que a Search Console publica), a janela do
	// painel antigo, que termina ontem, tem 5 de 7 dias, e a assentada tem 7 de 7. É o defeito e a correção sobre a MESMA série.
	if ( function_exists( 'uonix_analytics_metrics_search_console_windows' ) ) {
		$serie_hoje_menos_3 = array();
		for ( $d = new DateTimeImmutable( '2026-11-01', new DateTimeZone( 'UTC' ) ); $d->format( 'Y-m-d' ) <= '2026-11-15'; $d = $d->modify( '+1 day' ) ) {
			$serie_hoje_menos_3[] = array( 'keys' => array( $d->format( 'Y-m-d' ) ), 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0 );
		}
		$antigo   = uonix_analytics_metrics_search_console_windows( $serie_hoje_menos_3, uonix_analytics_metrics_periods( '2026-11-18', 7 ) );
		$assentado = uonix_analytics_metrics_search_console_windows( $serie_hoje_menos_3, uonix_analytics_metrics_search_console_periods( '2026-11-18', 7 ) );
		uonix_metrics_assert( 5 === $antigo['current']['days_present'] && 7 === $antigo['current']['days'], '#300: SEM a correção (janela até ontem) a série até hoje-3 dá 5 de 7 dias na janela atual; obteve ' . var_export( $antigo['current'], true ) );
		uonix_metrics_assert( 7 === $assentado['current']['days_present'] && 7 === $assentado['previous']['days_present'], '#300: COM a janela assentada a mesma série dá 7 de 7 nas duas janelas; obteve ' . var_export( $assentado, true ) );
	}
	// Período inválido volta para 30, como no resto do módulo.
	$invalido = uonix_analytics_metrics_search_console_periods( '2026-11-18', 13 );
	uonix_metrics_assert( array( 'start' => '2026-10-16', 'end' => '2026-11-14' ) === $invalido['current'], '#300: período fora da allowlist volta para 30 dias' );
}

if ( function_exists( 'uonix_analytics_metrics_search_console_windows' ) ) {
	$linhas_serie = static function ( array $datas ) {
		return array_map( static function ( $d ) { return array( 'keys' => array( $d ), 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0 ); }, $datas );
	};
	$dias = static function ( $inicio, $fim, array $menos = array() ) {
		$out = array();
		for ( $d = new DateTimeImmutable( $inicio, new DateTimeZone( 'UTC' ) ); $d->format( 'Y-m-d' ) <= $fim; $d = $d->modify( '+1 day' ) ) {
			if ( ! in_array( $d->format( 'Y-m-d' ), $menos, true ) ) { $out[] = $d->format( 'Y-m-d' ); }
		}
		return $out;
	};
	$p7 = uonix_analytics_metrics_search_console_periods( '2026-11-18', 7 );
	$completa = uonix_analytics_metrics_search_console_windows( $linhas_serie( $dias( '2026-11-01', '2026-11-14' ) ), $p7 );
	uonix_metrics_assert( 7 === $completa['current']['days_present'] && 7 === $completa['previous']['days_present'] && 7 === $completa['current']['days'] && 4 === $completa['settle_lag'], '#300: 14 dias presentes: 7 em cada janela; obteve ' . var_export( $completa, true ) );
	// Buraco no INTERIOR da janela atual: a contagem diz 6 de 7. É o que separa "zero impressões" de "dia ausente" (a API omite os dois).
	$furada = uonix_analytics_metrics_search_console_windows( $linhas_serie( $dias( '2026-11-01', '2026-11-14', array( '2026-11-11' ) ) ), $p7 );
	uonix_metrics_assert( 6 === $furada['current']['days_present'] && 7 === $furada['previous']['days_present'], '#300: o dia ausente no meio da janela atual conta: 6 de 7; obteve ' . var_export( $furada, true ) );
	// Dia FORA da janela não conta (a série pedida cobre as duas janelas, mas uma linha fora delas não pode inflar a contagem).
	$fora = uonix_analytics_metrics_search_console_windows( $linhas_serie( array_merge( $dias( '2026-11-01', '2026-11-14' ), array( '2026-11-15', '2026-11-16', '2026-10-30' ) ) ), $p7 );
	uonix_metrics_assert( 7 === $fora['current']['days_present'] && 7 === $fora['previous']['days_present'], '#300: linha fora das duas janelas não entra na contagem; obteve ' . var_export( $fora, true ) );
	// Fronteiras: o primeiro e o último dia de cada janela entram (inclusivo), e um dia a mais de cada lado não.
	$bordas = uonix_analytics_metrics_search_console_windows( $linhas_serie( array( '2026-11-01', '2026-11-07', '2026-11-08', '2026-11-14', '2026-10-31', '2026-11-15' ) ), $p7 );
	uonix_metrics_assert( 2 === $bordas['current']['days_present'] && 2 === $bordas['previous']['days_present'], '#300: início e fim de cada janela entram, e o dia anterior e o seguinte não; obteve ' . var_export( $bordas, true ) );
	// Série vazia: zero dias nas duas (colapso ou nada publicado: quem lê decide, a contagem só diz o que há).
	$vazia = uonix_analytics_metrics_search_console_windows( array(), $p7 );
	uonix_metrics_assert( 0 === $vazia['current']['days_present'] && 0 === $vazia['previous']['days_present'], '#300: série vazia: 0 dias nas duas janelas' );
	// Duplicata da mesma data conta uma vez; chave que não é data é ignorada.
	$duplicada = uonix_analytics_metrics_search_console_windows( array_merge( $linhas_serie( array( '2026-11-08', '2026-11-08' ) ), array( array( 'keys' => array( 'nao-e-data' ), 'impressions' => 1 ), array( 'keys' => array(), 'impressions' => 1 ), 'lixo' ) ), $p7 );
	uonix_metrics_assert( 1 === $duplicada['current']['days_present'], '#300: a mesma data duas vezes conta um dia, e linha sem data válida é ignorada; obteve ' . var_export( $duplicada, true ) );
	// Só a data EXATA conta: uma chave com hora ou sufixo (`2026-11-08T00:00:00Z`, `2026-11-08x`) não é o dia 2026-11-08. A Search Console devolve
	// `YYYY-MM-DD` para a dimensão `date`, e aceitar outra forma contaria como presente um dia que ela não declarou.
	foreach ( array( '2026-11-08T00:00:00Z', '2026-11-08 ', '2026-11-08x' ) as $chave_suja ) {
		$suja = uonix_analytics_metrics_search_console_windows( array( array( 'keys' => array( $chave_suja ), 'impressions' => 1 ) ), $p7 );
		uonix_metrics_assert( 0 === $suja['current']['days_present'], '#300: a chave ' . var_export( $chave_suja, true ) . ' não conta como o dia 2026-11-08; obteve ' . $suja['current']['days_present'] );
	}
}

if ( function_exists( 'uonix_analytics_metrics_get_snapshot' ) ) {
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v3_7' => array(
			'version' => 3,
			'period_days' => 7,
			'status' => 'updated',
			'updated_at' => '2026-09-14T00:00:00+00:00',
		),
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
	uonix_metrics_assert( is_array( $seven_day_snapshot ) && isset( $seven_day_snapshot['period_days'] ) && 7 === $seven_day_snapshot['period_days'], 'Snapshot de 7 dias usa chave própria' );
	uonix_metrics_assert( is_array( $seven_day_snapshot ) && 3 === $seven_day_snapshot['version'], 'Snapshot v3 tem precedência sobre v2 no mesmo período' );

	unset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_snapshot_v3_7'] );
	$fallback_snapshot = uonix_analytics_metrics_get_snapshot( 7 );
	uonix_metrics_assert( is_array( $fallback_snapshot ) && 2 === $fallback_snapshot['version'] && 7 === $fallback_snapshot['period_days'], 'Ausência de v3 cai para v2 do mesmo período' );

	$legacy_snapshot = uonix_analytics_metrics_get_snapshot( 30 );
	uonix_metrics_assert( is_array( $legacy_snapshot ) && 1 === $legacy_snapshot['version'], 'Snapshot legado é fallback somente de 30 dias' );
	uonix_metrics_assert( false === uonix_analytics_metrics_get_snapshot( 90 ), 'Período sem cache não herda snapshot de 30 dias' );
	$GLOBALS['uonix_metrics_options'] = array();
}

// ---------------------------------------------------------------------------
// Coleta das gerações mortas de snapshot (issue #253).
//
// O critério não é padrão de nome: é a posição na cascata de leitura. Apaga-se o
// que vem DEPOIS da primeira entrada presente, porque `get_snapshot()` para na
// primeira presente e nunca alcança o resto. Por isso a coleta é no-op de LEITURA
// por construção, e é exatamente isso que o laço exaustivo abaixo afere.
// ---------------------------------------------------------------------------

if ( function_exists( 'uonix_analytics_metrics_collect_legacy_snapshots' ) && function_exists( 'uonix_analytics_metrics_snapshot_option_cascade' ) ) {
	uonix_metrics_assert(
		array( 'uonix_analytics_metrics_snapshot_v3_30', 'uonix_analytics_metrics_snapshot_v2_30', 'uonix_analytics_metrics_snapshot_v1' ) === uonix_analytics_metrics_snapshot_option_cascade( 30 ),
		'Cascata de 30 dias vai de v3 a v1, na ordem de leitura'
	);
	uonix_metrics_assert(
		array( 'uonix_analytics_metrics_snapshot_v3_7', 'uonix_analytics_metrics_snapshot_v2_7' ) === uonix_analytics_metrics_snapshot_option_cascade( 7 ),
		'Cascata de 7 dias não inclui o v1, que só existiu para 30 dias'
	);

	$uonix_gen = static function ( $version, $days = 30 ) {
		return array( 'version' => $version, 'period_days' => $days, 'status' => 'updated', 'updated_at' => '2026-09-20T00:00:00+00:00' );
	};

	// CASO DE PRODUÇÃO 1 — a geração corrente existe: o legado dela é inalcançável e
	// vai embora. É este caso que remove o dado pré-minimização do banco.
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v3_30' => $uonix_gen( 3 ),
		'uonix_analytics_metrics_snapshot_v2_30' => $uonix_gen( 2 ),
		'uonix_analytics_metrics_snapshot_v1' => $uonix_gen( 1 ),
	);
	$coletado = uonix_analytics_metrics_collect_legacy_snapshots( 30 );
	uonix_metrics_assert(
		array( 'uonix_analytics_metrics_snapshot_v2_30', 'uonix_analytics_metrics_snapshot_v1' ) === $coletado,
		'Com o v3 presente, a coleta apaga v2 e v1 da mesma janela'
	);
	uonix_metrics_assert(
		array_key_exists( 'uonix_analytics_metrics_snapshot_v3_30', $GLOBALS['uonix_metrics_options'] ),
		'A coleta NUNCA apaga o snapshot corrente: o módulo exibe dado velho com aviso de desatualizado, e trocar isso por indisponível seria regressão'
	);

	// CASO DE PRODUÇÃO 2 — a geração corrente da janela de 7 dias ainda não existe, e
	// medido em 2026-09-22 é exatamente o estado do banco. O v2_7 é o caminho de
	// leitura VÁLIDO de hoje; apagá-lo trocaria "dado velho com aviso" por "sem dado".
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v2_7' => $uonix_gen( 2, 7 ),
	);
	$coletado_sete = uonix_analytics_metrics_collect_legacy_snapshots( 7 );
	uonix_metrics_assert( array() === $coletado_sete, 'Sem a geração corrente da janela, a coleta não apaga o legado de que o fallback depende' );
	uonix_metrics_assert(
		array_key_exists( 'uonix_analytics_metrics_snapshot_v2_7', $GLOBALS['uonix_metrics_options'] )
		&& is_array( uonix_analytics_metrics_get_snapshot( 7 ) )
		&& 2 === uonix_analytics_metrics_get_snapshot( 7 )['version'],
		'A janela de 7 dias continua lendo o v2 depois da coleta'
	);

	// CASO INTERMEDIÁRIO — sem v3, o v2 assume o topo da cascata e só o v1 cai.
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v2_30' => $uonix_gen( 2 ),
		'uonix_analytics_metrics_snapshot_v1' => $uonix_gen( 1 ),
	);
	$coletado_intermediario = uonix_analytics_metrics_collect_legacy_snapshots( 30 );
	uonix_metrics_assert(
		array( 'uonix_analytics_metrics_snapshot_v1' ) === $coletado_intermediario
		&& array_key_exists( 'uonix_analytics_metrics_snapshot_v2_30', $GLOBALS['uonix_metrics_options'] ),
		'Sem o v3, a coleta remove só o que está estritamente abaixo da geração de que o fallback depende'
	);

	// INVARIANTE UNIVERSAL — para TODA combinação de presença das três gerações de 30
	// dias, a coleta não muda o que a leitura devolve. Enumerado, e não amostrado: uma
	// asserção sobre um estado só provaria aquele estado.
	$combinacoes_verificadas = 0;
	$leitura_alterada = array();
	$corrente_apagada = array();
	for ( $mascara = 0; $mascara < 8; ++$mascara ) {
		$estado = array();
		if ( $mascara & 1 ) $estado['uonix_analytics_metrics_snapshot_v3_30'] = $uonix_gen( 3 );
		if ( $mascara & 2 ) $estado['uonix_analytics_metrics_snapshot_v2_30'] = $uonix_gen( 2 );
		if ( $mascara & 4 ) $estado['uonix_analytics_metrics_snapshot_v1'] = $uonix_gen( 1 );

		$GLOBALS['uonix_metrics_options'] = $estado;
		$antes_da_coleta = uonix_analytics_metrics_get_snapshot( 30 );
		uonix_analytics_metrics_collect_legacy_snapshots( 30 );
		$depois_da_coleta = uonix_analytics_metrics_get_snapshot( 30 );

		if ( $antes_da_coleta !== $depois_da_coleta ) {
			$leitura_alterada[] = (string) $mascara;
		}
		if ( ( $mascara & 1 ) && ! array_key_exists( 'uonix_analytics_metrics_snapshot_v3_30', $GLOBALS['uonix_metrics_options'] ) ) {
			$corrente_apagada[] = (string) $mascara;
		}
		++$combinacoes_verificadas;
	}
	uonix_metrics_assert( 8 === $combinacoes_verificadas, 'As oito combinações de presença das gerações de 30 dias foram enumeradas' );
	uonix_metrics_assert( array() === $leitura_alterada, 'A coleta é no-op de leitura em toda combinação de presença (falhou em: ' . implode( ',', $leitura_alterada ) . ')' );
	uonix_metrics_assert( array() === $corrente_apagada, 'A coleta nunca apaga a geração corrente em nenhuma combinação (falhou em: ' . implode( ',', $corrente_apagada ) . ')' );

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

// #336: a chamada `query` + `page` pede o teto da API (25000 linhas), e não o universo de consultas (1000). A Search
// Console ordena por cliques, e as oportunidades têm CTR baixo, quase sempre zero clique: são as primeiras a ficar de
// fora do corte quando os pares consulta × página passam do limite. As demais chamadas não mudam. O teste roda o
// `fetch_google_data` inteiro, com a rede simulada, e lê o corpo que a Search Console receberia. Assina um JWT de
// verdade, então exige `openssl`: sem ela o teste FALHA, e não pula.
if ( function_exists( 'uonix_analytics_metrics_fetch_google_data' ) ) {
	uonix_metrics_assert( extension_loaded( 'openssl' ) && function_exists( 'openssl_pkey_new' ), '#336: este teste assina um JWT e exige a extensão openssl' );
	if ( function_exists( 'openssl_pkey_new' ) ) {
		$chave_rsa = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$chave_pem = '';
		openssl_pkey_export( $chave_rsa, $chave_pem );
		$config_fetch   = array(
			'ga4_property_id'         => '445033830',
			'search_console_site_url' => 'sc-domain:uonix.com.br',
			'credentials'             => array( 'client_email' => 'conta@projeto.iam.gserviceaccount.com', 'private_key' => $chave_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ),
		);
		// Janelas reais de 30 dias (hoje = 2026-10-01): atual 09-01..09-30 e anterior 08-02..08-31. As assentadas (defasagem 4) são
		// atual 08-29..09-27 e anterior 07-30..08-28 (#300).
		$periodos_fetch = uonix_analytics_metrics_periods( '2026-10-01', 30 );
		if ( function_exists( 'uonix_analytics_metrics_search_console_periods' ) ) {
			$periodos_fetch['search_console'] = uonix_analytics_metrics_search_console_periods( '2026-10-01', 30 );
		}
		$GLOBALS['uonix_metrics_token_ok']   = true;
		$GLOBALS['uonix_metrics_gsc_log']    = array();
		$GLOBALS['uonix_metrics_ga4_log']    = array();
		$GLOBALS['uonix_metrics_http_capture'] = array();
		// Resposta programável: a série POR DATA (a chamada que conta os dias presentes) devolve uma linha por dia pedido, menos os
		// dias de `$GLOBALS['uonix_metrics_gsc_buracos']`. As demais chamadas seguem vazias, como antes.
		$GLOBALS['uonix_metrics_gsc_buracos']  = array( '2026-08-10', '2026-09-20', '2026-09-21' );
		$GLOBALS['uonix_metrics_gsc_respond']  = static function ( $corpo ) {
			if ( array( 'date' ) !== ( $corpo['dimensions'] ?? null ) ) {
				return null;
			}
			$linhas = array();
			for ( $d = new DateTimeImmutable( $corpo['startDate'], new DateTimeZone( 'UTC' ) ); $d->format( 'Y-m-d' ) <= $corpo['endDate']; $d = $d->modify( '+1 day' ) ) {
				if ( ! in_array( $d->format( 'Y-m-d' ), $GLOBALS['uonix_metrics_gsc_buracos'], true ) ) {
					$linhas[] = array( 'keys' => array( $d->format( 'Y-m-d' ) ), 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5 );
				}
			}
			return json_encode( array( 'responseAggregationType' => 'byProperty', 'rows' => $linhas ) );
		};
		$dados_fetch = uonix_analytics_metrics_fetch_google_data( $config_fetch, $periodos_fetch );
		$gsc_corpos  = $GLOBALS['uonix_metrics_gsc_log'];
		$ga4_corpos  = $GLOBALS['uonix_metrics_ga4_log'];
		unset( $GLOBALS['uonix_metrics_gsc_log'], $GLOBALS['uonix_metrics_ga4_log'], $GLOBALS['uonix_metrics_gsc_respond'] );
		$GLOBALS['uonix_metrics_token_ok']     = false;
		$GLOBALS['uonix_metrics_http_capture'] = null;
		uonix_metrics_assert( is_array( $dados_fetch ) && isset( $dados_fetch['search_console'] ), '#336: o fetch de ponta a ponta devolve os dados, e não um erro; obteve ' . var_export( is_wp_error( $dados_fetch ) ? $dados_fetch->get_error_code() : $dados_fetch, true ) );
		$por_dimensao = array();
		foreach ( $gsc_corpos as $corpo_gsc ) {
			$por_dimensao[ implode( '+', (array) ( $corpo_gsc['dimensions'] ?? array( '(resumo)' ) ) ) ][] = $corpo_gsc['rowLimit'] ?? null;
		}
		uonix_metrics_assert( 6 === count( $gsc_corpos ), '#336/#300: o fetch faz as seis chamadas à Search Console (as cinco de antes e a série por data); obteve ' . count( $gsc_corpos ) );
		uonix_metrics_assert( array( 25000 ) === ( $por_dimensao['query+page'] ?? null ), '#336: a chamada query+page pede o teto da API, 25000 linhas; obteve ' . var_export( $por_dimensao['query+page'] ?? null, true ) );
		uonix_metrics_assert( array( 1000 ) === ( $por_dimensao['query'] ?? null ), '#336: a chamada query segue pedindo o universo de consultas (1000); obteve ' . var_export( $por_dimensao['query'] ?? null, true ) );
		uonix_metrics_assert( array( 10 ) === ( $por_dimensao['page'] ?? null ), '#336: a chamada page segue pedindo 10; obteve ' . var_export( $por_dimensao['page'] ?? null, true ) );
		uonix_metrics_assert( array( 1, 1 ) === ( $por_dimensao['(resumo)'] ?? null ), '#336: os dois resumos seguem pedindo 1 linha; obteve ' . var_export( $por_dimensao['(resumo)'] ?? null, true ) );
		// #300: TODA a leitura da Search Console usa a janela assentada, e o GA4 segue na dele (termina ontem). O corpo de cada pedido é lido
		// da rede simulada, e não deduzido do código.
		$janelas_gsc = array();
		foreach ( $gsc_corpos as $corpo_gsc ) {
			$janelas_gsc[ implode( '+', (array) ( $corpo_gsc['dimensions'] ?? array( '(resumo)' ) ) ) ][] = ( $corpo_gsc['startDate'] ?? '?' ) . '..' . ( $corpo_gsc['endDate'] ?? '?' );
		}
		uonix_metrics_assert( array( '2026-08-29..2026-09-27', '2026-07-30..2026-08-28' ) === ( $janelas_gsc['(resumo)'] ?? null ), '#300: os dois resumos da Search Console usam a janela assentada (atual e anterior); obteve ' . var_export( $janelas_gsc['(resumo)'] ?? null, true ) );
		uonix_metrics_assert( array( '2026-08-29..2026-09-27' ) === ( $janelas_gsc['query'] ?? null ) && array( '2026-08-29..2026-09-27' ) === ( $janelas_gsc['page'] ?? null ) && array( '2026-08-29..2026-09-27' ) === ( $janelas_gsc['query+page'] ?? null ), '#300: consultas, páginas e consulta+página usam a MESMA janela assentada do resumo; obteve ' . var_export( array( $janelas_gsc['query'] ?? null, $janelas_gsc['page'] ?? null, $janelas_gsc['query+page'] ?? null ), true ) );
		uonix_metrics_assert( array( '2026-07-30..2026-09-27' ) === ( $janelas_gsc['date'] ?? null ) && array( 25000 ) === ( $por_dimensao['date'] ?? null ), '#300: a série por data cobre as duas janelas (anterior e atual) em uma chamada só, pedindo o teto da API (nenhum corte silencioso); obteve ' . var_export( array( $janelas_gsc['date'] ?? null, $por_dimensao['date'] ?? null ), true ) );
		$janelas_ga4 = array();
		foreach ( $ga4_corpos as $corpo_ga4 ) {
			$janelas_ga4[] = ( $corpo_ga4['dateRanges'][0]['startDate'] ?? '?' ) . '..' . ( $corpo_ga4['dateRanges'][0]['endDate'] ?? '?' );
		}
		uonix_metrics_assert( 4 === count( $ga4_corpos ), '#300: o GA4 faz 4 chamadas por sincronização (atual, anterior, páginas de entrada e uma página de visualizações; o fixture não pagina) e a Search Console 6: 10, contra 9 antes e os 8 que o contrato dizia; obteve ' . count( $ga4_corpos ) );
		uonix_metrics_assert( array() !== $janelas_ga4 && array() === array_diff( array_unique( $janelas_ga4 ), array( '2026-09-01..2026-09-30', '2026-08-02..2026-08-31' ) ), '#300: o GA4 NÃO muda de janela (termina ontem: a defasagem dele é de horas); obteve ' . var_export( array_unique( $janelas_ga4 ), true ) );
		uonix_metrics_assert( isset( $dados_fetch['search_console']['windows'] ) && array( 'settle_lag' => 4, 'current' => array( 'start' => '2026-08-29', 'end' => '2026-09-27', 'days' => 30, 'days_present' => 28 ), 'previous' => array( 'start' => '2026-07-30', 'end' => '2026-08-28', 'days' => 30, 'days_present' => 29 ) ) === $dados_fetch['search_console']['windows'], '#300: o fetch declara quantos dias de cada janela têm dado (28 de 30 na atual, 29 de 30 na anterior); obteve ' . var_export( $dados_fetch['search_console']['windows'] ?? null, true ) );
		uonix_metrics_assert( 25000 === uonix_analytics_metrics_query_page_limit() && uonix_analytics_metrics_query_page_limit() > uonix_analytics_metrics_extended_query_limit(), '#336: o limite da chamada query+page é o teto da API, acima do universo de consultas' );
	}
}

// ---------------------------------------------------------------------------------------------------------------------------
// #300: o leitor da janela da Search Console no snapshot, a normalização com dia faltando, o montador e a sincronização.
// ---------------------------------------------------------------------------------------------------------------------------
if ( function_exists( 'uonix_analytics_metrics_snapshot_search_console_window' ) ) {
	$w_ok = array(
		'settle_lag' => 4,
		'current'    => array( 'start' => '2026-11-08', 'end' => '2026-11-14', 'days' => 7, 'days_present' => 7 ),
		'previous'   => array( 'start' => '2026-11-01', 'end' => '2026-11-07', 'days' => 7, 'days_present' => 6 ),
	);
	$com = static function ( $w ) { return array( 'search_console' => array( 'windows' => $w ) ); };
	uonix_metrics_assert( $w_ok === uonix_analytics_metrics_snapshot_search_console_window( $com( $w_ok ) ), '#300: o leitor devolve a janela declarada pelo snapshot, validada' );
	// Snapshot legado (gerado antes da #300), ausente ou lixo: NULL, e quem lê não afirma janela assentada.
	foreach ( array( false, null, 'x', array(), array( 'search_console' => array() ), array( 'search_console' => array( 'windows' => 'x' ) ), array( 'search_console' => array( 'windows' => array() ) ) ) as $legado ) {
		uonix_metrics_assert( null === uonix_analytics_metrics_snapshot_search_console_window( $legado ), '#300: snapshot sem janela válida devolve null: ' . var_export( $legado, true ) );
	}
	$mut = static function ( callable $f ) use ( $w_ok ) { $w = $w_ok; $f( $w ); return $w; };
	$invalidas = array(
		'dias da janela não bate com as datas'          => $mut( static function ( &$w ) { $w['current']['days'] = 8; } ),
		'dias presentes acima dos dias da janela'       => $mut( static function ( &$w ) { $w['current']['days_present'] = 8; } ),
		'dias presentes negativo'                       => $mut( static function ( &$w ) { $w['previous']['days_present'] = -1; } ),
		'início depois do fim'                          => $mut( static function ( &$w ) { $w['current']['start'] = '2026-11-15'; $w['current']['days'] = 7; } ),
		'data que não existe (30/02)'                   => $mut( static function ( &$w ) { $w['previous']['start'] = '2026-02-30'; } ),
		'data com formato errado'                       => $mut( static function ( &$w ) { $w['current']['end'] = '14/11/2026'; } ),
		'janelas sobrepostas'                           => $mut( static function ( &$w ) { $w['previous']['end'] = '2026-11-08'; $w['previous']['days'] = 8; } ),
		'as duas janelas declaram 8 dias, e as datas têm 7' => array( 'settle_lag' => 4, 'current' => array( 'start' => '2026-11-08', 'end' => '2026-11-14', 'days' => 8, 'days_present' => 7 ), 'previous' => array( 'start' => '2026-11-01', 'end' => '2026-11-07', 'days' => 8, 'days_present' => 7 ) ),
		'janelas de tamanhos diferentes (6 e 7)'        => $mut( static function ( &$w ) { $w['previous']['start'] = '2026-11-02'; $w['previous']['days'] = 6; $w['previous']['days_present'] = 6; } ),
		'janelas SEPARADAS por um dia (não adjacentes)' => $mut( static function ( &$w ) { $w['previous'] = array( 'start' => '2026-10-31', 'end' => '2026-11-06', 'days' => 7, 'days_present' => 7 ); } ),
		'janelas coladas mas a anterior ENCOSTA na atual' => $mut( static function ( &$w ) { $w['previous'] = array( 'start' => '2026-11-02', 'end' => '2026-11-08', 'days' => 7, 'days_present' => 7 ); } ),
		'janela de 366 dias (acima do maior período)'   => array( 'settle_lag' => 4, 'current' => array( 'start' => '2025-11-14', 'end' => '2026-11-14', 'days' => 366, 'days_present' => 366 ), 'previous' => array( 'start' => '2024-11-13', 'end' => '2025-11-13', 'days' => 366, 'days_present' => 366 ) ),
		'data que não existe numa ponta (31/11)'        => $mut( static function ( &$w ) { $w['current']['end'] = '2026-11-31'; } ),
		'31/11 que o PHP rola para 01/12 e fecha a conta' => array( 'settle_lag' => 4, 'current' => array( 'start' => '2026-11-31', 'end' => '2026-12-07', 'days' => 7, 'days_present' => 7 ), 'previous' => array( 'start' => '2026-11-24', 'end' => '2026-11-30', 'days' => 7, 'days_present' => 7 ) ),
		'dia 00'                                        => $mut( static function ( &$w ) { $w['current']['start'] = '2026-11-00'; } ),
		'anterior DEPOIS da atual'                      => array( 'settle_lag' => 4, 'current' => $w_ok['previous'], 'previous' => $w_ok['current'] ),
		'defasagem negativa'                            => $mut( static function ( &$w ) { $w['settle_lag'] = -1; } ),
		'defasagem como texto'                          => $mut( static function ( &$w ) { $w['settle_lag'] = '4'; } ),
		'dias como texto'                               => $mut( static function ( &$w ) { $w['current']['days'] = '7'; } ),
		'dias presentes como decimal'                   => $mut( static function ( &$w ) { $w['current']['days_present'] = 6.0; } ),
		'sem a janela anterior'                         => array( 'settle_lag' => 4, 'current' => $w_ok['current'] ),
	);
	foreach ( $invalidas as $rotulo => $w ) {
		uonix_metrics_assert( null === uonix_analytics_metrics_snapshot_search_console_window( $com( $w ) ), "#300: janela inválida é recusada ({$rotulo})" );
	}
	// O leitor devolve o `settle_lag` GRAVADO, e não a constante (revisão do PR): um fixture com 3, 5 e as fronteiras 0 e 30 o diferencia, e 31 é recusado.
	foreach ( array( 0, 3, 5, 30 ) as $lag_gravado ) {
		$w_lag = $w_ok; $w_lag['settle_lag'] = $lag_gravado;
		$lido = uonix_analytics_metrics_snapshot_search_console_window( $com( $w_lag ) );
		uonix_metrics_assert( is_array( $lido ) && $lag_gravado === $lido['settle_lag'], "#300: o leitor devolve o settle_lag gravado ({$lag_gravado}), e não a constante; obteve " . var_export( $lido['settle_lag'] ?? null, true ) );
	}
	$w_lag31 = $w_ok; $w_lag31['settle_lag'] = 31;
	uonix_metrics_assert( null === uonix_analytics_metrics_snapshot_search_console_window( $com( $w_lag31 ) ), '#300: settle_lag 31 (acima do limite de 30) é recusado' );
	// A fronteira de cima: 365 dias, o maior período permitido, é aceito.
	$w_365 = array( 'settle_lag' => 4, 'current' => array( 'start' => '2025-11-15', 'end' => '2026-11-14', 'days' => 365, 'days_present' => 94 ), 'previous' => array( 'start' => '2024-11-15', 'end' => '2025-11-14', 'days' => 365, 'days_present' => 0 ) );
	uonix_metrics_assert( $w_365 === uonix_analytics_metrics_snapshot_search_console_window( $com( $w_365 ) ), '#300: a janela de 365 dias (o maior período) é aceita, mesmo com poucos dias presentes: a contagem é dado, e a recusa da comparação é de quem lê' );
	// Chave extra e ordem diferente: o leitor devolve a forma canônica, e não repassa lixo.
	$extra = $w_ok; $extra['lixo'] = '<script>'; $extra['current']['x'] = 1;
	uonix_metrics_assert( $w_ok === uonix_analytics_metrics_snapshot_search_console_window( $com( $extra ) ), '#300: chave extra não atravessa o leitor' );
}

if ( function_exists( 'uonix_analytics_metrics_normalize_search_console' ) ) {
	$base_gsc = array(
		'summary_current'  => array( 'clicks' => 15, 'impressions' => 100, 'ctr' => 0.15, 'position' => 8.2 ),
		'summary_previous' => array( 'clicks' => 10, 'impressions' => 80, 'ctr' => 0.125, 'position' => 9.1 ),
		'queries'          => array(),
		'pages'            => array(),
	);
	$janela = static function ( $atual_presentes, $anterior_presentes ) {
		return array(
			'settle_lag' => 4,
			'current'    => array( 'start' => '2026-11-08', 'end' => '2026-11-14', 'days' => 7, 'days_present' => $atual_presentes ),
			'previous'   => array( 'start' => '2026-11-01', 'end' => '2026-11-07', 'days' => 7, 'days_present' => $anterior_presentes ),
		);
	};
	$completo = uonix_analytics_metrics_normalize_search_console( $base_gsc + array( 'windows' => $janela( 7, 7 ) ) );
	uonix_metrics_assert( is_array( $completo ) && $janela( 7, 7 ) === ( $completo['windows'] ?? null ), '#300: a normalização preserva a janela declarada' );
	uonix_metrics_assert( is_array( $completo ) && 'comparable' === $completo['summary']['impressions']['state'] && 25.0 === $completo['summary']['impressions']['delta_percent'], '#300: com as duas janelas completas a comparação segue comparável (+25,0%)' );
	// Dia faltando em QUALQUER das janelas: as quatro métricas viram `incomplete`, e a variação não é calculada.
	foreach ( array( 'atual 6/7' => $janela( 6, 7 ), 'anterior 6/7' => $janela( 7, 6 ), 'atual 0/7' => $janela( 0, 7 ), 'anterior 0/7' => $janela( 7, 0 ), 'as duas' => $janela( 3, 4 ) ) as $rotulo => $w ) {
		$n = uonix_analytics_metrics_normalize_search_console( $base_gsc + array( 'windows' => $w ) );
		$estados = is_array( $n ) ? array_values( array_unique( array_column( $n['summary'], 'state' ) ) ) : array( 'erro' );
		uonix_metrics_assert( array( 'incomplete' ) === $estados, "#300: janela incompleta ({$rotulo}) recusa a comparação das quatro métricas; obteve " . var_export( $estados, true ) );
		uonix_metrics_assert( is_array( $n ) && null === $n['summary']['impressions']['delta_percent'] && null === $n['summary']['clicks']['delta_absolute'], "#300: janela incompleta ({$rotulo}) não deixa variação nem diferença calculadas" );
		uonix_metrics_assert( is_array( $n ) && 100.0 === $n['summary']['impressions']['current'] && 80.0 === $n['summary']['impressions']['previous'], "#300: janela incompleta ({$rotulo}) segue mostrando a soma dos dias que têm dado" );
	}
	// O estado `incomplete` vem do normalizador, e não do dado de entrada: um resumo que já traga percentual não escapa da recusa.
	$com_delta = $base_gsc; $com_delta['summary_current']['delta_percent'] = 99.0; $com_delta['summary_previous']['delta_percent'] = 1.0;
	$n_delta = uonix_analytics_metrics_normalize_search_console( $com_delta + array( 'windows' => $janela( 6, 7 ) ) );
	uonix_metrics_assert( is_array( $n_delta ) && null === $n_delta['summary']['impressions']['delta_percent'] && 'incomplete' === $n_delta['summary']['impressions']['state'], '#300: um percentual que já viesse no dado de entrada não atravessa a recusa da janela incompleta' );
	// `windows` PRESENTE e nulo é janela declarada e inválida, e falha fechado: `isset()` o trataria como ausente e cairia no ramo legado,
	// comparável e sem verificação (revisão do PR).
	$com_nulo = uonix_analytics_metrics_normalize_search_console( $base_gsc + array( 'windows' => null ) );
	uonix_metrics_assert( is_wp_error( $com_nulo ) && 'search_console_windows_invalid' === $com_nulo->get_error_code(), '#300: `windows` presente e nulo falha FECHADO (não é o legado); obteve ' . var_export( is_wp_error( $com_nulo ) ? $com_nulo->get_error_code() : ( $com_nulo['summary']['impressions']['state'] ?? '?' ), true ) );
	$invalido = uonix_analytics_metrics_normalize_search_console( $base_gsc + array( 'windows' => array( 'settle_lag' => 4 ) ) );
	uonix_metrics_assert( is_wp_error( $invalido ) && 'search_console_windows_invalid' === $invalido->get_error_code(), '#300: janela declarada e inválida falha FECHADO, e não vira comparação sem verificação' );
	$legado = uonix_analytics_metrics_normalize_search_console( $base_gsc );
	uonix_metrics_assert( is_array( $legado ) && ! array_key_exists( 'windows', $legado ) && 'comparable' === $legado['summary']['impressions']['state'], '#300: sem janela declarada (legado e fetchers antigos) a normalização é a de antes' );
}

if ( function_exists( 'uonix_analytics_metrics_assemble_google_data' ) && function_exists( 'uonix_analytics_metrics_search_console_periods' ) ) {
	$sc_assemble = uonix_analytics_metrics_search_console_periods( '2026-11-18', 7 );
	$ga_vazio    = '{"kind":"analyticsData#runReport","metadata":{}}';
	$gsc_vazio   = '{"responseAggregationType":"byProperty","rows":[]}';
	$datas_raw   = json_encode( array( 'responseAggregationType' => 'byProperty', 'rows' => array_map( static function ( $d ) { return array( 'keys' => array( $d ), 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5 ); }, array( '2026-11-01', '2026-11-02', '2026-11-08', '2026-11-09', '2026-11-10' ) ) ) );
	$montado     = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, $datas_raw, $sc_assemble );
	uonix_metrics_assert( is_array( $montado ) && 2 === ( $montado['search_console']['windows']['previous']['days_present'] ?? null ) && 3 === ( $montado['search_console']['windows']['current']['days_present'] ?? null ), '#300: o montador conta os dias presentes de cada janela a partir da série por data; obteve ' . var_export( $montado['search_console']['windows'] ?? null, true ) );
	$sem_serie = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio );
	uonix_metrics_assert( is_array( $sem_serie ) && ! array_key_exists( 'windows', $sem_serie['search_console'] ), '#300: sem a série por data o montador não inventa janela (chamadores antigos seguem como antes)' );
	$serie_ruim = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, '{"responseAggregationType":"byProperty","rows":[{"keys":[],"clicks":1,"impressions":1,"ctr":1,"position":1}]}', $sc_assemble );
	uonix_metrics_assert( is_wp_error( $serie_ruim ), '#300: série por data inválida derruba a montagem (a contagem de dias é o que torna a comparação honesta): ' . var_export( is_wp_error( $serie_ruim ) ? $serie_ruim->get_error_code() : $serie_ruim, true ) );
	try {
		$erro_http = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, uonix_analytics_metrics_error( 'google_http_503' ), $sc_assemble );
	} catch ( Throwable $falha_montagem ) {
		$erro_http = $falha_montagem;
	}
	uonix_metrics_assert( is_wp_error( $erro_http ) && 'google_http_503' === $erro_http->get_error_code(), '#300: a série por data que falhou na rede derruba a montagem, com a causa original (e não com uma exceção do decodificador); obteve ' . var_export( is_wp_error( $erro_http ) ? $erro_http->get_error_code() : ( $erro_http instanceof Throwable ? get_class( $erro_http ) : $erro_http ), true ) );
	// A série por data presente e as janelas com LIMITES inválidos (data que não existe, início depois do fim, janela anterior ausente): erro com
	// nome próprio, e não a causa 'janelas ausentes' (são problemas diferentes para quem for investigar).
	foreach ( array(
		'data que não existe (31/11)'   => array( 'current' => array( 'start' => '2026-11-31', 'end' => '2026-12-07' ), 'previous' => array( 'start' => '2026-11-24', 'end' => '2026-11-30' ) ),
		'início depois do fim'          => array( 'current' => array( 'start' => '2026-11-14', 'end' => '2026-11-08' ), 'previous' => array( 'start' => '2026-11-01', 'end' => '2026-11-07' ) ),
		'sem a janela anterior'         => array( 'current' => array( 'start' => '2026-11-08', 'end' => '2026-11-14' ) ),
	) as $rotulo_limite => $periodos_ruins ) {
		$limite_ruim = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, $datas_raw, $periodos_ruins );
		uonix_metrics_assert( is_wp_error( $limite_ruim ) && 'search_console_periods_invalid' === $limite_ruim->get_error_code(), "#300: janelas com limites inválidos ({$rotulo_limite}) são erro `search_console_periods_invalid`; obteve " . var_export( is_wp_error( $limite_ruim ) ? $limite_ruim->get_error_code() : 'array sem erro', true ) );
	}
	// As janelas SEM a série por data (o esquecimento inverso): erro, e não um snapshot sem `windows` que o painel rotularia de legado.
	$sem_serie_com_periodos = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, null, $sc_assemble );
	uonix_metrics_assert( is_wp_error( $sem_serie_com_periodos ) && 'search_console_dates_missing' === $sem_serie_com_periodos->get_error_code(), '#300: as janelas assentadas sem a série por data são erro (um sem o outro, nas DUAS direções); obteve ' . var_export( is_wp_error( $sem_serie_com_periodos ) ? $sem_serie_com_periodos->get_error_code() : 'array sem erro', true ) );
	$sem_periodos = uonix_analytics_metrics_assemble_google_data( $ga_vazio, $ga_vazio, $ga_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, $gsc_vazio, null, null, $datas_raw, null );
	uonix_metrics_assert( is_wp_error( $sem_periodos ) && 'search_console_periods_missing' === $sem_periodos->get_error_code(), '#300: série por data sem as janelas é erro de programação e falha fechado' );
}

if ( function_exists( 'uonix_analytics_metrics_fetch_google_data' ) ) {
	$GLOBALS['uonix_metrics_token_ok']     = true;
	$GLOBALS['uonix_metrics_gsc_log']      = array();
	$GLOBALS['uonix_metrics_ga4_log']      = array();
	$GLOBALS['uonix_metrics_http_capture'] = array();
	$sem_sc = uonix_analytics_metrics_fetch_google_data(
		array( 'ga4_property_id' => '445033830', 'search_console_site_url' => 'sc-domain:uonix.com.br', 'credentials' => array( 'client_email' => 'c@p.iam.gserviceaccount.com', 'private_key' => ( function () { $k = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) ); openssl_pkey_export( $k, $pem ); return $pem; } )(), 'token_uri' => 'https://oauth2.googleapis.com/token' ) ),
		uonix_analytics_metrics_periods( '2026-10-01', 30 )
	);
	$chamadas_sem_sc = count( $GLOBALS['uonix_metrics_gsc_log'] ) + count( $GLOBALS['uonix_metrics_ga4_log'] );
	unset( $GLOBALS['uonix_metrics_gsc_log'], $GLOBALS['uonix_metrics_ga4_log'] );
	$GLOBALS['uonix_metrics_token_ok']     = false;
	$GLOBALS['uonix_metrics_http_capture'] = null;
	uonix_metrics_assert( is_wp_error( $sem_sc ) && 'search_console_periods_missing' === $sem_sc->get_error_code(), '#300: o fetch sem as janelas assentadas falha fechado, e não volta em silêncio à janela com viés; obteve ' . var_export( is_wp_error( $sem_sc ) ? $sem_sc->get_error_code() : 'array', true ) );
	uonix_metrics_assert( 0 === $chamadas_sem_sc, '#300: o fetch sem as janelas falha ANTES de qualquer chamada de rede (nenhuma ao GA4 nem à Search Console); fez ' . $chamadas_sem_sc );
}

if ( function_exists( 'uonix_analytics_metrics_sync' ) && function_exists( 'uonix_analytics_metrics_search_console_periods' ) ) {
	$GLOBALS['uonix_metrics_options'] = array();
	$config_300  = array( 'ga4_property_id' => '445033830', 'search_console_site_url' => 'sc-domain:uonix.com.br', 'credentials' => array() );
	$recebido_300 = null;
	$fetcher_300 = static function ( $config, $periods ) use ( &$recebido_300 ) {
		$recebido_300 = $periods;
		return array(
			'ga4'            => array( 'summary_current' => array( 'activeUsers' => 3, 'sessions' => 4 ), 'summary_previous' => array( 'activeUsers' => 2, 'sessions' => 2 ), 'landing_pages' => array() ),
			'search_console' => array(
				'summary_current'  => array( 'clicks' => 15, 'impressions' => 100, 'ctr' => 0.15, 'position' => 8.2 ),
				'summary_previous' => array( 'clicks' => 10, 'impressions' => 80, 'ctr' => 0.125, 'position' => 9.1 ),
				'queries'          => array(),
				'pages'            => array(),
				'windows'          => array(
					'settle_lag' => 4,
					'current'    => array( 'start' => $periods['search_console']['current']['start'], 'end' => $periods['search_console']['current']['end'], 'days' => 7, 'days_present' => 6 ),
					'previous'   => array( 'start' => $periods['search_console']['previous']['start'], 'end' => $periods['search_console']['previous']['end'], 'days' => 7, 'days_present' => 7 ),
				),
			),
		);
	};
	$snap_300 = uonix_analytics_metrics_sync( $fetcher_300, $config_300, 7 );
	uonix_metrics_assert( is_array( $snap_300 ) && 'updated' === ( $snap_300['status'] ?? '' ), '#300: a sincronização com a janela da Search Console grava o snapshot; obteve ' . var_export( is_wp_error( $snap_300 ) ? $snap_300->get_error_code() : ( $snap_300['error'] ?? 'ok' ), true ) );
	$ga_fim = is_array( $recebido_300 ) ? $recebido_300['current']['end'] : '';
	uonix_metrics_assert( is_array( $recebido_300 ) && isset( $recebido_300['search_console'] ), '#300: o fetcher recebe as janelas assentadas junto das do GA4' );
	// hoje = fim do GA4 + 1 dia: a relação não depende do relógio do teste.
	$hoje_300 = ( new DateTimeImmutable( $ga_fim, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
	uonix_metrics_assert( is_array( $recebido_300 ) && uonix_analytics_metrics_search_console_periods( $hoje_300, 7 ) === $recebido_300['search_console'], '#300: as janelas entregues ao fetcher são as assentadas de hoje (GA4 termina em ontem); obteve ' . var_export( $recebido_300['search_console'] ?? null, true ) );
	uonix_metrics_assert( is_array( $snap_300 ) && array( 'current', 'previous' ) === array_keys( $snap_300['periods'] ) && uonix_analytics_metrics_periods( $hoje_300, 7 ) === $snap_300['periods'], '#300: o snapshot segue guardando em `periods` só as janelas do GA4 (o resto do sistema lê dali)' );
	uonix_metrics_assert( is_array( $snap_300 ) && 6 === ( $snap_300['search_console']['windows']['current']['days_present'] ?? null ), '#300: o snapshot guarda a janela da Search Console com os dias presentes' );
	uonix_metrics_assert( is_array( $snap_300 ) && 'incomplete' === ( $snap_300['search_console']['summary']['impressions']['state'] ?? '' ) && 'comparable' === ( $snap_300['ga4']['summary']['sessions']['state'] ?? '' ), '#300: a Search Console recusa a comparação (6 de 7 dias) e o GA4 segue comparável (4 contra 2); obteve ' . var_export( array( $snap_300['search_console']['summary']['impressions']['state'] ?? null, $snap_300['ga4']['summary']['sessions']['state'] ?? null ), true ) );
	$ga4_estados = is_array( $snap_300 ) ? array_column( $snap_300['ga4']['summary'], 'state' ) : array();
	uonix_metrics_assert( array() === array_diff( $ga4_estados, array( 'comparable', 'new', 'empty' ) ), '#300: nenhum KPI do GA4 ganha o estado incomplete: ' . var_export( $ga4_estados, true ) );
	$GLOBALS['uonix_metrics_options'] = array();
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

	// A chamada query+page falhando não derruba a sincronização: o snapshot sai sem query_pages.
	$assembled_sem_query_pages = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, null, uonix_analytics_metrics_error( 'google_http_500' ) );
	uonix_metrics_assert( is_array( $assembled_sem_query_pages ) && ! array_key_exists( 'query_pages', $assembled_sem_query_pages['search_console'] ), 'Falha da chamada query+page não vira erro da sincronização' );
	$gsc_qp_raw = '{"responseAggregationType":"byProperty","rows":[{"keys":["olhal inox","https://uonix.com.br/produtos/olhal-a/"],"clicks":0,"impressions":30,"ctr":0,"position":6}]}';
	$assembled_com_query_pages = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, null, $gsc_qp_raw );
	uonix_metrics_assert( is_array( $assembled_com_query_pages ) && array( array( 'query' => 'olhal inox', 'page' => 'https://uonix.com.br/produtos/olhal-a/', 'impressions' => 30 ) ) === ( $assembled_com_query_pages['search_console']['query_pages'] ?? null ), 'assemble repassa as linhas de duas dimensões como query/page/impressions' );
	$assembled_qp_uma_chave = uonix_analytics_metrics_assemble_google_data( $ga4_empty_raw, $ga4_empty_raw, $ga4_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, $gsc_empty_raw, null, '{"responseAggregationType":"byProperty","rows":[{"keys":["so consulta"],"clicks":0,"impressions":3,"ctr":0,"position":6}]}' );
	uonix_metrics_assert( is_array( $assembled_qp_uma_chave ) && array() === ( $assembled_qp_uma_chave['search_console']['query_pages'] ?? null ), 'Linha de query_pages sem a segunda chave é pulada' );

	// O teto da API é 25000 linhas por requisição: o pedido de 25000 passa intacto, e acima disso a função corta em 25000 (#336).
	foreach ( array( 24999 => 24999, 25000 => 25000, 25001 => 25000, 30000 => 25000, PHP_INT_MAX => 25000, 0 => 10, -5 => 10 ) as $pedido_rl => $esperado_rl ) {
		$GLOBALS['uonix_metrics_http_capture'] = array();
		uonix_analytics_metrics_search_console_rows( 'tok', 'sc-domain:uonix.com.br', array( 'start' => '2026-09-01', 'end' => '2026-09-30' ), array( 'query', 'page' ), $pedido_rl );
		$corpo_rl = json_decode( (string) ( $GLOBALS['uonix_metrics_http_capture']['args']['body'] ?? '' ), true );
		uonix_metrics_assert( is_array( $corpo_rl ) && $esperado_rl === ( $corpo_rl['rowLimit'] ?? null ), "#336: o clamp de search_console_rows: pedido {$pedido_rl} sai como {$esperado_rl}; obteve " . var_export( $corpo_rl['rowLimit'] ?? null, true ) );
	}
	$GLOBALS['uonix_metrics_http_capture'] = array();
	uonix_analytics_metrics_search_console_rows( 'tok', 'sc-domain:uonix.com.br', array( 'start' => '2026-09-01', 'end' => '2026-09-30' ), array( 'query', 'page' ), 1000 );
	$corpo_qp = json_decode( (string) ( $GLOBALS['uonix_metrics_http_capture']['args']['body'] ?? '' ), true );
	$GLOBALS['uonix_metrics_http_capture'] = null;
	uonix_metrics_assert( is_array( $corpo_qp ) && array( 'query', 'page' ) === ( $corpo_qp['dimensions'] ?? null ) && 1000 === ( $corpo_qp['rowLimit'] ?? null ), 'search_console_rows aceita duas dimensões; obteve ' . var_export( $corpo_qp, true ) );
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

	// #382: o HTML que só aparece DEPOIS de decodificar não pode voltar. Tirar as tags antes da decodificação deixava passar
	// `%3Cb%3E`, `&lt;b&gt;`, `&amp;lt;b&amp;gt;`, `&#60;b&#62;`, e o texto com `<b>` ia para o `wp_options`, para o painel,
	// para o e-mail do Módulo 3 e para o pedido ao Gemini. Medido em 2026-11-17 com o `wp_strip_all_tags` real: 7 codificações
	// devolviam a tag, e a tripla devolvia a entidade.
	$html_codificado = array(
		'%3Cb%3Ex%3C/b%3E'                       => 'percent',
		'%3Cimg src=x%3E'                        => 'percent com atributo',
		'&lt;b&gt;x&lt;/b&gt;'                   => 'entidade nomeada',
		'&amp;lt;b&amp;gt;x&amp;lt;/b&amp;gt;'   => 'entidade nomeada em dobro',
		'&amp;amp;lt;b&amp;amp;gt;x'             => 'entidade nomeada em triplo',
		'&amp;amp;amp;lt;b&amp;amp;amp;gt;x'     => 'entidade nomeada em quádruplo: sobra entidade depois das 3 voltas',
		'&#60;b&#62;x&#60;/b&#62;'               => 'entidade numérica',
		'&#x3C;b&#x3E;x'                         => 'entidade hexadecimal',
		'%26lt;b%26gt;x'                         => 'percent de entidade',
		'%253Cb%253Ex'                           => 'percent em dobro',
		'<b>%3Cimg%3E</b>'                       => 'tag literal que ENVOLVE uma tag codificada',
		'&lt;<b></b>script&gt;'                  => 'tag literal vazia que parte uma tag codificada',
		'%26lt;a%26gt;contato@empresa.test'      => 'e-mail dentro de HTML codificado',
		'laudo %3C/b%3E fim'                     => 'tag de FECHAMENTO só codificada (a classe `/` da marcação)',
		'%3CB%3Ex%3C/B%3E'                       => 'tag em CAIXA ALTA só codificada: a flag `i` do ramo da tag',
		'&LT;B&GT;x'                             => 'entidade em caixa alta (`&LT;`) de tag em caixa alta',
		'&lt;SCRIPT&gt;x'                        => 'tag SCRIPT em caixa alta e entidade',
		'%3CIMG src=x%3E'                        => 'tag IMG em caixa alta só codificada',
		'laudo &lt;/b&gt; fim'                   => 'tag de fechamento em entidade',
		'laudo %3C!-- c --%3E fim'               => 'comentário só codificado (a classe `!`)',
		'laudo &lt;?php x ?&gt; fim'             => 'instrução de processamento em entidade (a classe `?`)',
		'%2525253Cb%2525253Ex'                   => 'percent em QUATRO níveis: com 3 voltas sobrava `%3Cb%3E`, que ainda decodifica',
		'%25252525253Cb'                         => 'percent em seis níveis',
		'x &#128; y'                             => 'entidade numérica DECIMAL que o decodificador não resolve (`&#128;`): sobra, e é recusada',
		'x &#x80; y'                             => 'entidade HEXADECIMAL que o decodificador não resolve (`&#x80;`)',
		'x &foo1; y'                             => 'entidade NOMEADA com dígito desconhecida (`&foo1;`)',
		'x &foo; y'                              => 'entidade nomeada desconhecida (`&foo;`)',
		'&amp;amp;amp;amp;amp;amp;amp;amp;amp;lt;b' => 'entidade em nove níveis: não estabiliza em 8 voltas',
	);
	foreach ( $html_codificado as $entrada => $rotulo ) {
		$saida = uonix_analytics_metrics_sanitize_query( $entrada );
		uonix_metrics_assert( '' === $saida, "#382: consulta com HTML codificado ({$rotulo}) é recusada; obteve " . var_export( $saida, true ) );
	}
	// A tag LITERAL na entrada crua segue sendo tirada, como sempre foi (o texto de dentro fica). A #382 não muda isso.
	$tag_literal = array(
		'<b>x</b> teste'                      => 'x teste',
		'<script>alert(1)</script> andaime'   => 'andaime',
		'<img src=x> laudo'                   => 'laudo',
		'x<br/>y'                             => 'xy',
		'<!-- c --> laudo'                    => 'laudo',
	);
	foreach ( $tag_literal as $entrada => $esperada ) {
		uonix_metrics_assert( $esperada === uonix_analytics_metrics_sanitize_query( $entrada ), "#382: a tag literal na entrada continua sendo tirada: {$entrada}; obteve " . var_export( uonix_analytics_metrics_sanitize_query( $entrada ), true ) );
	}
	// O `<` colado numa letra é cortado pelo `wp_strip_all_tags` sobre a entrada crua, como sempre foi, e a #382 não muda isso
	// (medido: idêntico antes e depois). O teste o prende para que ninguém o confunda com um efeito do saneamento novo.
	foreach ( array( 'a<b' => 'a', 'a<3' => 'a', 'a <= b' => 'a', 'x <- y' => 'x', 'eu <3 ancoragem' => 'eu', 'a <b c> d' => 'a  d' ) as $entrada => $esperada ) {
		uonix_metrics_assert( $esperada === uonix_analytics_metrics_sanitize_query( $entrada ), "#382: `<` colado numa letra segue cortado pelo wp_strip_all_tags (inalterado): {$entrada}; obteve " . var_export( uonix_analytics_metrics_sanitize_query( $entrada ), true ) );
	}
	// O que NÃO pode sair: texto legítimo com `<`, `>` ou `&`.
	$legitimas = array(
		'resistência a < 5 kn'             => 'resistência a < 5 kn',
		'carga > 12 kn'                    => 'carga > 12 kn',
		'viga a < b e c > d'               => 'viga a < b e c > d',
		'a < b'                            => 'a < b',
		'5 > 3'                            => '5 > 3',
		'ancoragem &amp; linha de vida'    => 'ancoragem & linha de vida',
		'ancoragem &amp;amp; linha'        => 'ancoragem & linha',
		'NBR 16325 &#x26; EPI'             => 'NBR 16325 & EPI',
		'R&D'                              => 'R&D',
		'ancoragem &amp;amp;amp; linha'    => 'ancoragem & linha',
		'ancoragem &amp;amp;amp;amp; linha' => 'ancoragem & linha',
		'carga %25 de 100'                 => 'carga % de 100',
		'desconto 50%'                     => 'desconto 50%',
		'a%252520b'                        => 'a b',
		'caf%25C3%25A9'                    => 'café',
		'a &apos;b&apos; c'                => "a 'b' c",
		'ancoragem+telhado'                => 'ancoragem+telhado',
		'c++'                              => 'c++',
		'%2B'                              => '+',
		'a%20'                             => 'a',
		'%20ancoragem%20'                  => 'ancoragem',
		'a&Tab;b'                          => "a\tb",
		'linha de vida'                    => 'linha de vida',
		'%41%42'                           => 'AB',
	);
	foreach ( $legitimas as $entrada => $esperada ) {
		uonix_metrics_assert( $esperada === uonix_analytics_metrics_sanitize_query( $entrada ), "#382: consulta legítima preservada: {$entrada}; obteve " . var_export( uonix_analytics_metrics_sanitize_query( $entrada ), true ) );
	}
	// O trim vale DEPOIS de decodificar: `%20` sozinho vira vazio (e é recusado), e não um espaço que passaria como consulta.
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( '%20' ), '#382: `%20` sozinho decodifica para espaço e o trim o deixa vazio: recusada' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( '%20%20' ), '#382: dois `%20` sozinhos: recusada' );
	// Uma entidade sem sentido que sobra depois de decodificar é recusada (`AT&T;` não é entidade conhecida do HTML5, mas tem a forma).
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( 'AT&T;' ), '#382: `&nome;` que sobra depois das voltas é recusado, mesmo sem ser entidade conhecida' );
	// O teto de 120 vale DEPOIS de decodificar: 121 `%41` são 363 caracteres crus e 121 `A` decodificados.
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( str_repeat( '%41', 121 ) ), '#382: o teto de 120 vale DEPOIS de decodificar (121 `%41` viram 121 `A`)' );
	uonix_metrics_assert( str_repeat( 'A', 120 ) === uonix_analytics_metrics_sanitize_query( str_repeat( '%41', 120 ) ), '#382: 120 caracteres depois de decodificar passam, embora tenham 360 antes' );
	uonix_metrics_assert( str_repeat( 'A', 50 ) === uonix_analytics_metrics_sanitize_query( str_repeat( '%41', 50 ) ), '#382: 50 caracteres decodificados passam, com 150 crus' );
	// A consulta que NÃO estabiliza em 8 voltas é recusada, e a de 8 níveis que estabiliza no oitavo passa.
	// `%` + `25` repetido n vezes + `41` decodifica em n + 1 voltas e precisa de MAIS UMA para confirmar que estabilizou (a que não
	// muda nada). Com o teto de 8 voltas, n = 6 (7 de decodificação + 1 de confirmação) passa, e n = 7 (8 + 1) é recusada.
	uonix_metrics_assert( 'A' === uonix_analytics_metrics_sanitize_query( '%' . str_repeat( '25', 6 ) . '41' ), '#382: a consulta que decodifica em 7 voltas e confirma na 8.ª passa' );
	uonix_metrics_assert( '' === uonix_analytics_metrics_sanitize_query( '%' . str_repeat( '25', 7 ) . '41' ), '#382: a que precisaria de 9 voltas não estabiliza dentro do teto de 8 e é recusada' );
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

	$many_queries = array();
	for ( $i = 1; $i <= 12; ++$i ) {
		$many_queries[] = array( 'query' => 'consulta ' . $i, 'clicks' => 1, 'impressions' => 100 + $i, 'ctr' => .01, 'position' => 5 );
	}
	// Linha com PII no meio do universo ampliado: a sanitização é por linha, então
	// precisa derrubar igual, esteja a linha entre as 10 exibidas ou no fim das mil.
	array_splice( $many_queries, 3, 0, array( array( 'query' => 'orcamento cliente@example.test', 'clicks' => 9, 'impressions' => 999, 'ctr' => .01, 'position' => 5 ) ) );
	$gsc_many = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'queries' => $many_queries,
			'pages' => array(),
		)
	);
	uonix_metrics_assert( is_array( $gsc_many ) && 10 === count( $gsc_many['queries'] ), 'Lista de exibição do Search Console continua limitada a 10 consultas' );
	uonix_metrics_assert( is_array( $gsc_many ) && 12 === count( $gsc_many['queries_extended'] ), 'Universo de mineração preserva as consultas válidas além das 10 exibidas' );
	// Continua valendo, mas por uma razão que precisa estar escrita: TODA linha desta
	// fixture está dentro da faixa de retenção de texto (posição 5, mais de 3
	// impressões), então nenhuma sofre minimização e o prefixo é idêntico. A divergência
	// legítima entre os dois campos é exercitada logo abaixo, com fixture própria.
	uonix_metrics_assert( is_array( $gsc_many ) && $gsc_many['queries'] === array_slice( $gsc_many['queries_extended'], 0, 10 ), 'Lista de exibição é prefixo do universo de mineração quando toda linha está na faixa de retenção' );
	$extended_terms = implode( ' ', array_column( $gsc_many['queries_extended'], 'query' ) );
	uonix_metrics_assert( false === strpos( $extended_terms, '@' ), 'Universo ampliado aplica a mesma remoção de PII da lista curta' );

	// Página líder de cada consulta (sugestão por IA, #193). Só entra consulta cujo
	// TEXTO o universo retém (#253), e a página de mais impressões vence; empate pelo caminho.
	$gsc_paginas = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current'  => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'queries'          => array(
				array( 'query' => 'olhal inox', 'clicks' => 0, 'impressions' => 40, 'ctr' => 0, 'position' => 6 ),
				array( 'query' => 'cauda longa', 'clicks' => 0, 'impressions' => 1, 'ctr' => 0, 'position' => 40 ),
			),
			'pages'            => array(),
			'query_pages'      => array(
				array( 'query' => 'olhal inox', 'page' => 'https://uonix.com.br/produtos/olhal-b/', 'impressions' => 10 ),
				array( 'query' => 'olhal inox', 'page' => 'https://uonix.com.br/produtos/olhal-a/', 'impressions' => 30 ),
				array( 'query' => 'olhal inox', 'page' => 'https://uonix.com.br/produtos/olhal-c/', 'impressions' => 30 ),
				array( 'query' => 'cauda longa', 'page' => 'https://uonix.com.br/x/', 'impressions' => 1 ),
				array( 'query' => 'olhal inox', 'page' => 'https://outro-site.example/y/', 'impressions' => 99 ),
				array( 'query' => 'orcamento cliente@example.test', 'page' => 'https://uonix.com.br/z/', 'impressions' => 99 ),
			),
		)
	);
	uonix_metrics_assert( is_array( $gsc_paginas ) && array( 'olhal inox' => '/produtos/olhal-a/' ) === ( $gsc_paginas['query_pages'] ?? null ), 'query_pages: página de mais impressões vence, empate pelo caminho, fora do domínio e PII descartados, e só consulta com texto retido entra; obteve ' . var_export( $gsc_paginas['query_pages'] ?? null, true ) );
	$gsc_sem_paginas = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current'  => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'queries'          => array(),
			'pages'            => array(),
		)
	);
	uonix_metrics_assert( is_array( $gsc_sem_paginas ) && ! array_key_exists( 'query_pages', $gsc_sem_paginas ), 'Sem o dado de página por consulta, a chave query_pages fica ausente, e não vazia' );

	// -----------------------------------------------------------------------
	// Minimização de PII no universo de mineração (issue #253).
	//
	// Regra: MÉTRICA de toda linha fica; TEXTO só das linhas que podem qualificar
	// como oportunidade. A relação entre a faixa de retenção de 53 e a regra de
	// seleção de 55 é verificada em scripts/tests/test-query-text-retention-superset.php.
	//
	// A fixture cobre as duas causas de descarte e as duas de retenção, para que
	// nenhuma asserção fique de pé por ordem de aparição no array.
	// -----------------------------------------------------------------------
	$mixed_queries = array(
		// Fora da faixa por POSIÇÃO (topo absoluto: nunca é distância de salto).
		array( 'query' => 'linha de vida', 'clicks' => 30, 'impressions' => 400, 'ctr' => .075, 'position' => 1.4 ),
		// Dentro da faixa: é candidata a oportunidade e mantém o texto.
		array( 'query' => 'olhal de ancoragem', 'clicks' => 0, 'impressions' => 53, 'ctr' => .0, 'position' => 11.4 ),
		// Fora da faixa por IMPRESSÕES: a cauda de 1 impressão é onde a consulta
		// identificável mora, e é justamente ela que perde o texto.
		array( 'query' => 'joao carlos da silva pereira', 'clicks' => 0, 'impressions' => 1, 'ctr' => .0, 'position' => 9.0 ),
		// Dentro da faixa, com a menor impressão que a retenção admite.
		array( 'query' => 'barra roscada aco inox', 'clicks' => 0, 'impressions' => 3, 'ctr' => .0, 'position' => 10.3 ),
		// Fora da faixa por posição, na outra ponta (posição irrecuperável).
		array( 'query' => 'guincho de coluna', 'clicks' => 0, 'impressions' => 90, 'ctr' => .0, 'position' => 31.0 ),
	);
	$gsc_mixed = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'queries' => $mixed_queries,
			'pages' => array(),
		)
	);
	$mixed_extended = is_array( $gsc_mixed ) ? $gsc_mixed['queries_extended'] : array();

	uonix_metrics_assert( 5 === count( $mixed_extended ), 'Minimização de texto não remove linha do universo: as métricas das 5 linhas continuam lá' );
	uonix_metrics_assert(
		array( 400.0, 53.0, 1.0, 3.0, 90.0 ) === array_column( $mixed_extended, 'impressions' )
		&& array( 1.4, 11.4, 9.0, 10.3, 31.0 ) === array_column( $mixed_extended, 'position' )
		&& array( 30.0, 0.0, 0.0, 0.0, 0.0 ) === array_column( $mixed_extended, 'clicks' )
		&& array( .075, .0, .0, .0, .0 ) === array_column( $mixed_extended, 'ctr' ),
		'Universo preserva clicks, impressions, ctr e position de TODA linha, na ordem — é essa distribuição que a recalibração do piso usa'
	);
	// `?? array()` em vez de indexação direta: uma regressão que REMOVA linha do
	// universo faria a indexação estourar aviso e erro fatal, e o build reprovaria
	// pelo motivo errado, com mensagem que não ajuda quem depura.
	uonix_metrics_assert(
		! array_key_exists( 'query', $mixed_extended[0] ?? array() )
		&& ! array_key_exists( 'query', $mixed_extended[2] ?? array() )
		&& ! array_key_exists( 'query', $mixed_extended[4] ?? array() ),
		'Linha fora da faixa de retenção perde o texto da consulta, por posição no topo, por cauda de impressão e por posição irrecuperável'
	);
	uonix_metrics_assert(
		'olhal de ancoragem' === ( $mixed_extended[1]['query'] ?? null )
		&& 'barra roscada aco inox' === ( $mixed_extended[3]['query'] ?? null ),
		'Linha que pode qualificar como oportunidade mantém o texto íntegro'
	);
	// A forma escolhida para "texto ausente" é OMITIR a chave, não gravá-la vazia. A
	// asserção fixa a escolha: `isset()` em 55 já trata ausência como linha a pular,
	// e string vazia manteria `isset()` verdadeiro, abrindo caminho para linha
	// fantasma num consumidor futuro.
	uonix_metrics_assert(
		array( 'clicks', 'impressions', 'ctr', 'position' ) === array_keys( $mixed_extended[2] ?? array() ),
		'Texto ausente é chave OMITIDA, não string vazia'
	);
	// A lista curta de exibição é outro campo e outro propósito: o painel renderiza o
	// termo, então ela não é minimizada. Sem esta asserção, minimizar `queries` por
	// engano apagaria o gráfico de consultas e nada reprovaria.
	uonix_metrics_assert(
		array( 'linha de vida', 'olhal de ancoragem', 'joao carlos da silva pereira', 'barra roscada aco inox', 'guincho de coluna' ) === array_column( is_array( $gsc_mixed ) ? $gsc_mixed['queries'] : array(), 'query' ),
		'Lista curta de exibição mantém o texto de todas as suas linhas: ela alimenta o gráfico do painel'
	);

	// O teto do universo precisa ser exercitado de fato: sem isso, o `break` que o
	// impõe nunca roda em teste e um limite quebrado passaria despercebido.
	$overflow = array();
	$limit = uonix_analytics_metrics_extended_query_limit();
	for ( $i = 1; $i <= $limit + 5; ++$i ) {
		$overflow[] = array( 'query' => 'termo ' . $i, 'clicks' => 1, 'impressions' => 200, 'ctr' => .01, 'position' => 5 );
	}
	$gsc_overflow = uonix_analytics_metrics_normalize_search_console(
		array(
			'summary_current' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'summary_previous' => array( 'clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3 ),
			'queries' => $overflow,
			'pages' => array(),
		)
	);
	uonix_metrics_assert( is_array( $gsc_overflow ) && $limit === count( $gsc_overflow['queries_extended'] ), 'Universo de mineração respeita o teto configurado' );
	uonix_metrics_assert( is_array( $gsc_overflow ) && 10 === count( $gsc_overflow['queries'] ), 'Teto do universo não afeta o tamanho da lista de exibição' );
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
	uonix_metrics_assert( is_array( $sync ) && 3 === $sync['version'] && 30 === $sync['period_days'] && 'updated' === $sync['status'] && 3.0 === $sync['ga4']['summary']['active_users']['current'], 'Sincronização armazena snapshot agregado de 30 dias com transport injetado' );
	uonix_metrics_assert( is_array( $sync ) && isset( $sync['search_console']['queries_extended'] ) && is_array( $sync['search_console']['queries_extended'] ), 'Snapshot v3 persiste o universo de mineração de consultas' );
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
	uonix_metrics_assert( isset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_snapshot_v3_7'], $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_snapshot_v3_90'] ), 'Snapshots de 7 e 90 dias usam opções distintas' );

	$seven_stale = uonix_analytics_metrics_sync( static function () { throw new RuntimeException( 'transport failure' ); }, $test_config, 7 );
	uonix_metrics_assert( 'stale' === $seven_stale['status'], 'Falha marca somente o período solicitado como stale' );
	uonix_metrics_assert( 'updated' === uonix_analytics_metrics_get_snapshot( 90 )['status'], 'Falha de 7 dias não marca snapshot de 90 dias como stale' );

	$GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_7'] = time();
	$seven_locked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 7 );
	$ninety_unlocked = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config, 90 );
	uonix_metrics_assert( is_wp_error( $seven_locked ) && 'sync_locked' === $seven_locked->get_error_code(), 'Trava de 7 dias bloqueia o mesmo período' );
	uonix_metrics_assert( is_array( $ninety_unlocked ) && 'updated' === $ninety_unlocked['status'], 'Trava de 7 dias não bloqueia 90 dias' );
	unset( $GLOBALS['uonix_metrics_options']['uonix_analytics_metrics_sync_lock_7'] );

	// A coleta está LIGADA ao caminho de sucesso da sincronização. Sem esta asserção o
	// coletor poderia existir, ter teste próprio e nunca ser chamado em produção.
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v2_30' => array( 'version' => 2, 'period_days' => 30, 'status' => 'updated', 'updated_at' => '2026-09-20T00:00:00+00:00' ),
		'uonix_analytics_metrics_snapshot_v1' => array( 'version' => 1, 'status' => 'updated', 'updated_at' => '2026-09-19T00:00:00+00:00' ),
	);
	$sync_coleta = uonix_analytics_metrics_sync( $fixture_fetcher, $test_config );
	uonix_metrics_assert( is_array( $sync_coleta ) && 'updated' === $sync_coleta['status'], 'Sincronização de controle da coleta concluiu com sucesso' );
	uonix_metrics_assert(
		! array_key_exists( 'uonix_analytics_metrics_snapshot_v2_30', $GLOBALS['uonix_metrics_options'] )
		&& ! array_key_exists( 'uonix_analytics_metrics_snapshot_v1', $GLOBALS['uonix_metrics_options'] ),
		'Sincronização bem-sucedida coleta as gerações mortas da janela sincronizada'
	);
	uonix_metrics_assert( array_key_exists( 'uonix_analytics_metrics_snapshot_v3_30', $GLOBALS['uonix_metrics_options'] ), 'Sincronização com coleta preserva o snapshot corrente' );

	// E está DESLIGADA do caminho de falha: `mark_stale()` promove o payload legado
	// para a chave corrente, e apagar dado numa sincronização que falhou seria trocar
	// "dado velho com aviso" por "sem dado" na primeira falha de rede.
	$GLOBALS['uonix_metrics_options'] = array(
		'uonix_analytics_metrics_snapshot_v2_30' => array( 'version' => 2, 'period_days' => 30, 'status' => 'updated', 'updated_at' => '2026-09-20T00:00:00+00:00' ),
	);
	$sync_falha = uonix_analytics_metrics_sync( static function () { throw new RuntimeException( 'transport failure' ); }, $test_config );
	uonix_metrics_assert( is_array( $sync_falha ) && 'stale' === $sync_falha['status'], 'Sincronização de controle da falha marcou o snapshot como desatualizado' );
	uonix_metrics_assert(
		array_key_exists( 'uonix_analytics_metrics_snapshot_v2_30', $GLOBALS['uonix_metrics_options'] ),
		'Sincronização que falhou NÃO coleta: o legado promovido continua no banco'
	);
	$GLOBALS['uonix_metrics_options'] = array();
}

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: métricas GA4/Search Console normalizadas sem PII e com comparação segura.\n";
