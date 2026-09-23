<?php
/**
 * Testes do Alerta de Anomalias (Módulo 5).
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'UONIX_ENV', 'local' );

$failures = 0;
$GLOBALS['uox_options']       = array();
$GLOBALS['uox_cron']          = array();
$GLOBALS['uox_cron_rec']      = array();
$GLOBALS['uox_cron_calls']    = 0;
$GLOBALS['uox_actions_all']   = array();
$GLOBALS['uox_mail_calls']    = array();
$GLOBALS['uox_mail_result']   = true;
$GLOBALS['uox_schedules']     = array( 'hourly' => array( 'interval' => 3600 ), 'daily' => array( 'interval' => 86400 ), 'weekly' => array( 'interval' => 604800 ) );
$GLOBALS['uox_timezone']      = 'America/Sao_Paulo';
$GLOBALS['uox_table_exists']  = true;
$GLOBALS['uox_lead_rows']     = array();
$GLOBALS['uox_last_sql']      = '';

// Semeia um destinatário ANTES de carregar os módulos, de propósito: a asserção
// "carregar o arquivo não agenda" precisa que nenhum guard de lista vazia possa
// explicar o agendador vazio, senão ela passaria por motivo errado.
$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array( 'semente@ksio.dev' );

function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class Uox_Die_Exception extends RuntimeException {}
class Uox_Redirect_Exception extends RuntimeException {}

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

/**
 * Mock de `$wpdb` que INTERPOLA de verdade.
 *
 * Um `prepare()` que devolve o SQL cru tornaria vazia qualquer asserção sobre o
 * recorte de status ou sobre os IDs de formulário: o teste passaria mesmo se o
 * código montasse a consulta errada. Aqui os placeholders são substituídos, e o
 * SQL final fica observável em `uox_last_sql`.
 */
class Uox_WPDB {
	public $prefix = 'wp_';

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$out = '';
		$i   = 0;
		$len = strlen( $query );
		for ( $p = 0; $p < $len; $p++ ) {
			if ( '%' === $query[ $p ] && $p + 1 < $len && in_array( $query[ $p + 1 ], array( 'd', 's', 'f' ), true ) ) {
				$tipo  = $query[ $p + 1 ];
				$valor = array_key_exists( $i, $args ) ? $args[ $i ] : null;
				++$i;
				++$p;
				if ( 'd' === $tipo ) {
					$out .= (string) (int) $valor;
				} elseif ( 'f' === $tipo ) {
					$out .= (string) (float) $valor;
				} else {
					$out .= "'" . str_replace( "'", "\\'", (string) $valor ) . "'";
				}
				continue;
			}
			$out .= $query[ $p ];
		}
		return $out;
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
			return $GLOBALS['uox_table_exists'] ? 'wp_fluentform_submissions' : '';
		}
		return null;
	}

	public function get_results( $query ) {
		$GLOBALS['uox_last_sql'] = $query;
		$saida = array();
		foreach ( $GLOBALS['uox_lead_rows'] as $dia => $total ) {
			$linha        = new stdClass();
			$linha->dia   = (string) $dia;
			$linha->total = (int) $total;
			$saida[]      = $linha;
		}
		return $saida;
	}
}
$GLOBALS['wpdb'] = new Uox_WPDB();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['uox_actions_all'][ $hook ][] = array( 'callback' => $callback, 'accepted_args' => $args );
}
function uox_hook_args( $hook, $callback ) {
	foreach ( $GLOBALS['uox_actions_all'][ $hook ] ?? array() as $registro ) {
		if ( $callback === $registro['callback'] ) {
			return $registro['accepted_args'];
		}
	}
	return null;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_shortcode( $tag, $callback ) { return true; }
function add_menu_page() { return ''; }

function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['uox_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['uox_options'] ) ) return false; $GLOBALS['uox_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uox_options'][ $key ] ); return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value ) { return true; }
function delete_transient( $key ) { return true; }

function current_user_can( $capability ) { return true; }
function check_admin_referer( $action = -1 ) { return true; }
function wp_die( $message = '', $title = '', $args = array() ) { throw new Uox_Die_Exception( (string) $message ); }
function wp_safe_redirect( $url ) { throw new Uox_Redirect_Exception( (string) $url ); }

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['uox_mail_calls'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers );
	return $GLOBALS['uox_mail_result'];
}

function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
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
// Stub que de fato escapa: passa-tudo tornaria vazia qualquer asserção de escape.
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>' ), array( '&quot;', '&#039;', '&lt;', '&gt;' ), (string) $url ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
// A recorrência é GUARDADA: um stub que ignora o segundo argumento faria qualquer
// asserção sobre "é diário" passar sem verificar nada.
function wp_schedule_event( $ts, $rec, $hook ) {
	++$GLOBALS['uox_cron_calls'];
	$GLOBALS['uox_cron'][ $hook ]     = $ts;
	$GLOBALS['uox_cron_rec'][ $hook ] = $rec;
	return true;
}
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['uox_cron'][ $hook ], $GLOBALS['uox_cron_rec'][ $hook ] ); }
function wp_get_schedules() { return $GLOBALS['uox_schedules']; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['uox_timezone'] ); }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : (int) $ts ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }

$GLOBALS['uox_cron_calls'] = 0;

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php';

// ---------------------------------------------------------------------------
// Auxiliares de fixture.
// ---------------------------------------------------------------------------

/** Linha de série diária no formato que `decode_search_console_report` devolve. */
function uox_dia( $data, $impressoes ) {
	return array( 'keys' => array( $data ), 'clicks' => 0, 'impressions' => $impressoes, 'ctr' => 0.0, 'position' => 10.0 );
}

/** Série contínua de `$dias` dias terminando em `$fim`, com impressões fixas. */
function uox_serie( $fim, $dias, $impressoes ) {
	$cursor = new DateTimeImmutable( $fim, new DateTimeZone( 'UTC' ) );
	$linhas = array();
	for ( $i = 0; $i < $dias; $i++ ) {
		$linhas[] = uox_dia( $cursor->format( 'Y-m-d' ), $impressoes );
		$cursor   = $cursor->modify( '-1 day' );
	}
	return array_reverse( $linhas );
}

function uox_datas( array $linhas ) {
	return array_map( static function ( $l ) { return $l['keys'][0]; }, $linhas );
}

function uox_achado( $gatilho, $disponivel, $anomalo ) {
	return array(
		'trigger'   => $gatilho,
		'available' => $disponivel,
		'anomalous' => $anomalo,
		'reason'    => $disponivel ? '' : 'series_stale',
		'headline'  => 'fixture',
		'started_at' => '2026-09-01',
		'likely_cause' => 'fixture',
		'action'    => 'fixture',
		'observed_on' => '2026-09-20',
	);
}

$regras = uonix_intelligence_anomaly_rules();
$JANELA = (int) $regras['organic_window_days'];

// ---------------------------------------------------------------------------
// 1. A matemática de janela — a parte cujo defeito foi MEDIDO.
// ---------------------------------------------------------------------------

// Cenário real de 2026-09-23: a série termina em 20/09, três dias atrás.
$hoje   = '2026-09-23';
$linhas = uox_serie( '2026-09-20', 21, 100 );
$j      = uonix_intelligence_anomaly_organic_windows( uox_datas( $linhas ), $JANELA, '2026-09-02', $hoje );

uox_assert( ! isset( $j['reason'] ), 'série de 21 dias terminando 3 dias atrás deveria produzir janelas, obteve motivo ' . ( $j['reason'] ?? '' ) );
uox_assert( '2026-09-20' === ( $j['current']['end'] ?? '' ), 'a janela atual deve terminar na última data COM dado (2026-09-20), obteve ' . ( $j['current']['end'] ?? '(nada)' ) );

// A asserção anti-regressão central: se alguém trocar esta matemática pela de
// `uonix_analytics_metrics_periods()`, a janela passa a terminar ONTEM, e volta o
// falso positivo de −51,8% medido em 2026-09-23.
$ontem = ( new DateTimeImmutable( $hoje, new DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
uox_assert( ( $j['current']['end'] ?? '' ) !== $ontem, 'a janela atual NÃO pode terminar em ontem quando há defasagem: é o defeito que este módulo existe para evitar' );
uox_assert( 3 === ( $j['lag_days'] ?? -1 ), 'a defasagem deveria ser derivada do dado (3 dias), obteve ' . ( $j['lag_days'] ?? -1 ) );

// Tamanho e distância exatos: sem isso, comparar 7 dias com dois fins de semana
// contra 7 com um já acusaria queda sem nada ter acontecido.
$fuso = new DateTimeZone( 'UTC' );
$ci = new DateTimeImmutable( $j['current']['start'], $fuso );
$cf = new DateTimeImmutable( $j['current']['end'], $fuso );
$pi = new DateTimeImmutable( $j['previous']['start'], $fuso );
$pf = new DateTimeImmutable( $j['previous']['end'], $fuso );
uox_assert( $JANELA - 1 === (int) $ci->diff( $cf )->days, 'a janela atual deve ter exatamente ' . $JANELA . ' dias' );
uox_assert( $JANELA - 1 === (int) $pi->diff( $pf )->days, 'a janela anterior deve ter exatamente ' . $JANELA . ' dias' );
uox_assert( 1 === (int) $pf->diff( $ci )->days, 'a janela anterior deve terminar no dia anterior ao início da atual' );
uox_assert( $JANELA === (int) $pi->diff( $ci )->days, 'as duas janelas devem distar exatamente ' . $JANELA . ' dias' );
uox_assert( $ci->format( 'N' ) === $pi->format( 'N' ), 'as duas janelas devem começar no mesmo dia da semana, senão a sazonalidade sozinha acusa queda' );

// Falta de dia NO INTERIOR é zero legítimo (a API omite dia sem impressão), e não
// deve recusar: só a cauda é "ainda não publicado".
$comBuraco = array_values( array_filter( uox_datas( $linhas ), static function ( $d ) { return '2026-09-17' !== $d; } ) );
$jb = uonix_intelligence_anomaly_organic_windows( $comBuraco, $JANELA, '2026-09-02', $hoje );
uox_assert( ! isset( $jb['reason'] ), 'dia ausente no interior da janela é zero legítimo e não deve recusar, obteve ' . ( $jb['reason'] ?? '' ) );
uox_assert( '2026-09-20' === ( $jb['current']['end'] ?? '' ), 'buraco no interior não deve mover a ancoragem da janela' );

// Recusas, cada uma com motivo próprio.
uox_assert( 'series_empty' === ( uonix_intelligence_anomaly_organic_windows( array(), $JANELA, '2026-09-02', $hoje )['reason'] ?? '' ), 'série vazia deveria recusar com series_empty' );
uox_assert( 'series_stale' === ( uonix_intelligence_anomaly_organic_windows( uox_datas( uox_serie( '2026-09-01', 21, 100 ) ), $JANELA, '2026-08-01', $hoje )['reason'] ?? '' ), 'série atrasada além do limite deveria recusar com series_stale' );
uox_assert( 'series_dates_invalid' === ( uonix_intelligence_anomaly_organic_windows( array( '2026-09-30' ), $JANELA, '2026-09-02', $hoje )['reason'] ?? '' ), 'data futura deveria recusar com series_dates_invalid' );
uox_assert( 'series_too_short' === ( uonix_intelligence_anomaly_organic_windows( uox_datas( $linhas ), $JANELA, '2026-09-10', $hoje )['reason'] ?? '' ), 'histórico pedido insuficiente deveria recusar com series_too_short' );

// ---------------------------------------------------------------------------
// 2. Agregação por janela.
// ---------------------------------------------------------------------------

$somaLinhas = array( uox_dia( '2026-09-14', 10 ), uox_dia( '2026-09-15', 20 ), uox_dia( '2026-09-16', 40 ) );
uox_assert( 30.0 === uonix_intelligence_anomaly_sum_impressions( $somaLinhas, array( 'start' => '2026-09-14', 'end' => '2026-09-15' ) ), 'a soma deve incluir os dois extremos e excluir o resto' );
uox_assert( 70.0 === uonix_intelligence_anomaly_sum_impressions( $somaLinhas, array( 'start' => '2026-09-14', 'end' => '2026-09-16' ) ), 'a soma deve cobrir a janela inteira' );
uox_assert( 0.0 === uonix_intelligence_anomaly_sum_impressions( $somaLinhas, array( 'start' => '2026-08-01', 'end' => '2026-08-07' ) ), 'janela fora da série deve somar zero' );

// ---------------------------------------------------------------------------
// 3. Gatilho 2 de ponta a ponta, com buscador injetado.
// ---------------------------------------------------------------------------

/** Buscador que devolve 7 dias fracos seguidos de 7 dias fortes, terminando 3 dias atrás. */
function uox_fetcher_queda( $config, $period ) {
	$fortes = uox_serie( '2026-09-13', 7, 100 );  // janela anterior: 700
	$fracos = uox_serie( '2026-09-20', 7, 40 );   // janela atual: 280 → −60%
	return array_merge( $fortes, $fracos );
}
$resultado = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_queda', array( 'search_console_site_url' => 'sc-domain:x' ), $hoje );
uox_assert( ! empty( $resultado['available'] ), 'queda de 60% deveria ser verificável, motivo: ' . ( $resultado['reason'] ?? '' ) );
uox_assert( ! empty( $resultado['anomalous'] ), 'queda de 60% deveria ser anômala' );
uox_assert( -60.0 === ( $resultado['measured']['delta_percent'] ?? 0.0 ), 'a variação medida deveria ser −60,0%, obteve ' . ( $resultado['measured']['delta_percent'] ?? 'nada' ) );
uox_assert( '2026-09-14' === ( $resultado['started_at'] ?? '' ), 'o início declarado deve ser o começo da janela atual' );

function uox_fetcher_estavel( $config, $period ) {
	return array_merge( uox_serie( '2026-09-13', 7, 100 ), uox_serie( '2026-09-20', 7, 95 ) );
}
$estavel = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_estavel', array( 'search_console_site_url' => 'sc-domain:x' ), $hoje );
uox_assert( ! empty( $estavel['available'] ) && empty( $estavel['anomalous'] ), 'queda de 5% não deveria ser anômala' );

// Piso de ruído: volume baixo devolve INDISPONÍVEL, não "sem anomalia". São
// estados diferentes, e confundi-los faria volume irrelevante parecer saúde.
function uox_fetcher_minusculo( $config, $period ) {
	return array_merge( uox_serie( '2026-09-13', 7, 1 ), uox_serie( '2026-09-20', 7, 0 ) );
}
$minusculo = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_minusculo', array( 'search_console_site_url' => 'sc-domain:x' ), $hoje );
uox_assert( empty( $minusculo['available'] ), 'volume abaixo do piso de ruído deveria ser indisponível' );
uox_assert( 'baseline_too_small' === ( $minusculo['reason'] ?? '' ), 'o motivo deveria ser baseline_too_small, obteve ' . ( $minusculo['reason'] ?? '' ) );
uox_assert( empty( $minusculo['anomalous'] ), 'indisponível nunca é anômalo' );

function uox_fetcher_erro( $config, $period ) { return new WP_Error( 'http_500' ); }
$erro = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_erro', array( 'search_console_site_url' => 'sc-domain:x' ), $hoje );
uox_assert( empty( $erro['available'] ) && 'series_fetch_failed' === ( $erro['reason'] ?? '' ), 'falha de busca deveria devolver series_fetch_failed' );

$semConfig = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_estavel', null, $hoje );
uox_assert( empty( $semConfig['available'] ), 'sem configuração o gatilho deve se declarar indisponível' );

// ---------------------------------------------------------------------------
// 4. Gatilho 1: silêncio de conversões.
// ---------------------------------------------------------------------------

$limiar = (int) $regras['lead_silence_days'];

$GLOBALS['uox_table_exists'] = false;
$semTabela = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( empty( $semTabela['available'] ), 'tabela ausente deveria ser indisponível' );
uox_assert( 'submissions_table_missing' === ( $semTabela['reason'] ?? '' ), 'o motivo deveria ser submissions_table_missing' );
uox_assert( empty( $semTabela['anomalous'] ), 'tabela ausente NÃO é "nenhum lead": não pode ser anômalo' );

$GLOBALS['uox_table_exists'] = true;
$GLOBALS['uox_lead_rows']    = array( '2026-09-23' => 2 );
$comLeadHoje = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( ! empty( $comLeadHoje['available'] ) && empty( $comLeadHoje['anomalous'] ), 'lead recebido hoje não deveria ser anomalia' );
uox_assert( 0 === ( $comLeadHoje['measured']['silent_days'] ?? -1 ), 'com lead hoje o silêncio deveria ser 0 dia' );

$GLOBALS['uox_lead_rows'] = array();
$silencio = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( ! empty( $silencio['available'] ) && ! empty( $silencio['anomalous'] ), 'nenhum lead na janela inteira deveria ser anomalia' );
uox_assert( $limiar === ( $silencio['measured']['threshold_days'] ?? -1 ), 'o limiar reportado deve ser o das regras' );

// Silêncio de exatamente limiar−1 dias ainda NÃO é anomalia: a fronteira importa,
// e um erro de um dia aqui antecipa o alerta em toda semana normal.
$vespera = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-' . ( $limiar - 1 ) . ' days' )->format( 'Y-m-d' );
$GLOBALS['uox_lead_rows'] = array( $vespera => 1 );
$naFronteira = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( empty( $naFronteira['anomalous'] ), 'silêncio de ' . ( $limiar - 1 ) . ' dias não deveria disparar (fronteira do limiar)' );

// A consulta precisa excluir spam e cobrir os dois formulários. Sem isso, uma
// rajada de spam contaria como conversão e ESCONDERIA um colapso real.
uox_assert( false !== strpos( $GLOBALS['uox_last_sql'], "'spam'" ), 'a consulta de leads deve excluir spam explicitamente' );
uox_assert( false !== strpos( $GLOBALS['uox_last_sql'], "'trashed'" ), 'a consulta de leads deve excluir a lixeira' );
foreach ( uonix_intelligence_anomaly_lead_form_ids() as $id ) {
	uox_assert( 1 === preg_match( '/form_id IN \([^)]*\b' . $id . '\b/', $GLOBALS['uox_last_sql'] ), "a consulta de leads deve incluir o form_id {$id}" );
}

// ---------------------------------------------------------------------------
// 5. Maior silêncio e auditoria do limiar.
// ---------------------------------------------------------------------------

$porDia = array( '2026-09-01' => 1, '2026-09-05' => 2, '2026-09-06' => 1 );
uox_assert( 3 === uonix_intelligence_anomaly_longest_gap( $porDia, '2026-09-01', '2026-09-06' ), 'o maior intervalo entre 01 e 06 com leads em 01, 05 e 06 é 3 dias' );
uox_assert( 4 === uonix_intelligence_anomaly_longest_gap( $porDia, '2026-09-01', '2026-09-10' ), 'o silêncio em curso até 10/09 é 4 dias e deve ser o maior' );
uox_assert( 0 === uonix_intelligence_anomaly_longest_gap( array( '2026-09-01' => 1 ), '2026-09-01', '2026-09-01' ), 'um único dia com lead não tem intervalo' );
uox_assert( null === uonix_intelligence_anomaly_longest_gap( $porDia, '2026-09-10', '2026-09-01' ), 'janela invertida deveria devolver null' );

$GLOBALS['uox_lead_rows'] = array();
$base = uonix_intelligence_anomaly_lead_baseline( 30, $hoje );
uox_assert( ! empty( $base['available'] ), 'a linha de base deveria estar disponível com a tabela presente' );
uox_assert( 30 === (int) $base['longest_gap'], 'sem nenhum lead em 30 dias o maior silêncio é 30' );
uox_assert( empty( $base['threshold_is_safe'] ), 'limiar de ' . $limiar . ' dias NÃO é seguro contra um silêncio observado de 30 dias' );

$GLOBALS['uox_lead_rows'] = array();
$cursor = new DateTimeImmutable( $hoje, $fuso );
for ( $i = 0; $i < 30; $i++ ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; $cursor = $cursor->modify( '-1 day' ); }
$baseDensa = uonix_intelligence_anomaly_lead_baseline( 30, $hoje );
uox_assert( 0 === (int) $baseDensa['longest_gap'], 'com lead todo dia o maior silêncio é 0' );
uox_assert( ! empty( $baseDensa['threshold_is_safe'] ), 'limiar de ' . $limiar . ' dias é seguro contra silêncio observado de 0' );
uox_assert( 1.0 === (float) $baseDensa['per_day'], 'trinta leads em trinta dias é 1,00 por dia' );

// Limiar IGUAL ao maior silêncio observado não é seguro: o intervalo que já
// aconteceu sem nada de errado voltaria a acontecer e dispararia o alerta.
$GLOBALS['uox_lead_rows'] = array();
$cursor = new DateTimeImmutable( $hoje, $fuso );
for ( $i = 0; $i <= 30; $i++ ) {
	if ( $i >= $limiar ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; }
	$cursor = $cursor->modify( '-1 day' );
}
$baseIgual = uonix_intelligence_anomaly_lead_baseline( 31, $hoje );
uox_assert( $limiar === (int) $baseIgual['longest_gap'], 'o fixture deveria produzir silêncio igual ao limiar, obteve ' . $baseIgual['longest_gap'] );
uox_assert( empty( $baseIgual['threshold_is_safe'] ), 'limiar IGUAL ao maior silêncio observado não pode ser considerado seguro' );

// ---------------------------------------------------------------------------
// 6. Estado e deduplicação (funções puras).
// ---------------------------------------------------------------------------

$anterior = array( 'lead_silence' => true, 'organic_drop' => false );

// Indisponível PRESERVA o estado anterior. Sem isso, uma falha de um dia na
// Search Console limparia o estado e a mesma anomalia geraria segundo e-mail.
$novo = uonix_intelligence_anomaly_state_from_findings(
	array( uox_achado( 'lead_silence', false, false ), uox_achado( 'organic_drop', true, true ) ),
	$anterior
);
uox_assert( true === $novo['lead_silence'], 'gatilho indisponível deve PRESERVAR o estado anterior, não virar normal' );
uox_assert( true === $novo['organic_drop'], 'gatilho disponível e anômalo deve ficar anômalo' );

$limpou = uonix_intelligence_anomaly_state_from_findings( array( uox_achado( 'lead_silence', true, false ) ), $anterior );
uox_assert( false === $limpou['lead_silence'], 'gatilho verificado e normal deve limpar o estado' );

$t = uonix_intelligence_anomaly_transitions( array( uox_achado( 'organic_drop', true, true ) ), $anterior );
uox_assert( 1 === count( $t ), 'normal → anômalo é transição' );
$t = uonix_intelligence_anomaly_transitions( array( uox_achado( 'lead_silence', true, true ) ), $anterior );
uox_assert( 0 === count( $t ), 'anômalo → anômalo NÃO é transição: um e-mail por episódio' );
$t = uonix_intelligence_anomaly_transitions( array( uox_achado( 'organic_drop', false, true ) ), $anterior );
uox_assert( 0 === count( $t ), 'achado indisponível nunca gera transição, mesmo marcado como anômalo' );

// ---------------------------------------------------------------------------
// 7. Badge: três estados, não dois.
// ---------------------------------------------------------------------------

uox_assert( 'normal' === uonix_intelligence_anomaly_badge( array( 'anomalous' => 0, 'unavailable' => 0 ) )['state'], 'zero anomalia e zero indisponível é normal' );
uox_assert( 'critical' === uonix_intelligence_anomaly_badge( array( 'anomalous' => 1, 'unavailable' => 0 ) )['state'], 'uma anomalia é crítico' );
uox_assert( 'unknown' === uonix_intelligence_anomaly_badge( array( 'anomalous' => 0, 'unavailable' => 1 ) )['state'], 'gatilho indisponível não pode aparecer como sistema saudável' );
uox_assert( 'critical' === uonix_intelligence_anomaly_badge( array( 'anomalous' => 1, 'unavailable' => 1 ) )['state'], 'anomalia tem precedência sobre indisponibilidade' );
uox_assert( false !== strpos( uonix_intelligence_anomaly_badge( array( 'anomalous' => 2, 'unavailable' => 0 ) )['label'], '2' ), 'o rótulo crítico deve trazer a contagem' );

// ---------------------------------------------------------------------------
// 8. Verificação periódica: um e-mail por episódio, e retentativa quando falha.
// ---------------------------------------------------------------------------

$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array( 'operador@ksio.dev' );
$anomalia = array( uox_achado( 'lead_silence', true, true ), uox_achado( 'organic_drop', true, false ) );

unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$GLOBALS['uox_mail_calls'] = array();
$r1 = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'a primeira verificação com anomalia deveria enviar um e-mail, enviou ' . count( $GLOBALS['uox_mail_calls'] ) );
uox_assert( 1 === $r1['transitions'], 'deveria haver exatamente uma transição' );
uox_assert( true === ( uonix_intelligence_anomaly_get_state()['lead_silence'] ?? null ), 'o estado deveria registrar a anomalia depois do envio bem-sucedido' );

$r2 = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'a segunda verificação com a MESMA anomalia não deveria enviar outro e-mail' );
uox_assert( 0 === $r2['transitions'], 'anomalia persistente não é transição nova' );

// Falha de envio NÃO deve avançar o estado, senão o episódio é silenciado para
// sempre: o estado diria "já avisei" sobre um aviso que nunca chegou.
unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$GLOBALS['uox_mail_calls']  = array();
$GLOBALS['uox_mail_result'] = false;
uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'houve tentativa de envio' );
uox_assert( false === ( uonix_intelligence_anomaly_get_state()['lead_silence'] ?? null ), 'envio falho NÃO pode marcar o gatilho como já avisado' );
uox_assert( '' !== uonix_intelligence_anomaly_get_summary()['checked_at'], 'o resumo deve ser gravado mesmo quando o envio falha, para o painel mostrar o que foi medido' );

$GLOBALS['uox_mail_result'] = true;
uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 2 === count( $GLOBALS['uox_mail_calls'] ), 'a verificação seguinte deveria RETENTAR o aviso que falhou' );

// Sem destinatário: badge continua funcionando, envio recusa com motivo, e o
// estado não avança — então quem se cadastra depois recebe o aviso.
unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array();
$GLOBALS['uox_mail_calls'] = array();
$semDestino = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 0 === count( $GLOBALS['uox_mail_calls'] ), 'sem destinatário nada deve ser enviado' );
uox_assert( 'no_recipients' === $semDestino['send']['reason'], 'o motivo deveria ser no_recipients' );
uox_assert( 1 === uonix_intelligence_anomaly_get_summary()['anomalous'], 'o badge deve funcionar sem destinatário: o resumo continua contando a anomalia' );
uox_assert( false === ( uonix_intelligence_anomaly_get_state()['lead_silence'] ?? null ), 'sem destinatário o estado não avança, para o aviso sair quando alguém se cadastrar' );

$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array( 'operador@ksio.dev' );
uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'cadastrar destinatário com anomalia em curso deveria disparar o aviso na verificação seguinte' );

// ---------------------------------------------------------------------------
// 9. Corpo e assunto do alerta.
// ---------------------------------------------------------------------------

$corpo = uonix_intelligence_anomaly_alert_html( array( uox_achado( 'lead_silence', true, true ) ) );
foreach ( array( 'Quando começou', 'Causa provável', 'Ação recomendada' ) as $secao ) {
	uox_assert( false !== strpos( $corpo, $secao ), "o detalhe do incidente deve trazer a seção \"{$secao}\"" );
}
// UONIX_ENV é 'local' neste teste, então o aviso de ambiente não produtivo TEM de
// aparecer. É a contraprova de que a ausência dele em produção significa algo.
uox_assert( false !== strpos( $corpo, 'não produtiva' ), 'em ambiente não produtivo o corpo deve trazer o aviso de ambiente' );
uox_assert( false !== strpos( $corpo, 'LOCAL' ), 'o aviso deve nomear o ambiente' );

$assunto = uonix_intelligence_anomaly_alert_subject( array( uox_achado( 'lead_silence', true, true ), uox_achado( 'organic_drop', true, true ) ) );
uox_assert( false !== strpos( $assunto, '2' ), 'com duas anomalias o assunto deve trazer a contagem' );

uox_assert( 'no_transition' === uonix_intelligence_anomaly_send_alert( array() )['reason'], 'lista de transições vazia não deveria tentar enviar' );

// ---------------------------------------------------------------------------
// 10. Agendamento.
// ---------------------------------------------------------------------------

$hook = uonix_intelligence_anomaly_hook();
uox_assert( ! isset( $GLOBALS['uox_cron'][ $hook ] ), 'carregar o arquivo NÃO deve agendar: quem agenda é o callback de init' );
uox_assert( 0 === uox_hook_args( $hook, 'uonix_intelligence_anomaly_run_check' ), 'o handler deve aceitar ZERO argumentos: é o que impede um evento forjado de injetar achados e disparar e-mail' );
uox_assert( 0 === uox_hook_args( 'init', 'uonix_intelligence_anomaly_maybe_schedule' ), 'o agendador em init deve aceitar zero argumentos' );

// Agenda SEMPRE, inclusive sem destinatário: o badge do painel é útil sem e-mail.
// Diferença deliberada em relação ao relatório executivo.
$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array();
uox_assert( true === uonix_intelligence_anomaly_maybe_schedule(), 'a verificação deve ser agendada mesmo SEM destinatário' );
uox_assert( 'daily' === ( $GLOBALS['uox_cron_rec'][ $hook ] ?? '' ), 'a recorrência deve ser diária, obteve ' . ( $GLOBALS['uox_cron_rec'][ $hook ] ?? '(nada)' ) );

$antes = $GLOBALS['uox_cron_calls'];
uox_assert( false === uonix_intelligence_anomaly_maybe_schedule(), 'já agendado não deve reagendar' );
uox_assert( $antes === $GLOBALS['uox_cron_calls'], 'reagendar empurraria o disparo para sempre adiante e a verificação nunca rodaria' );

// Falha fechada: sem a recorrência registrada, agendar criaria um disparo único
// disfarçado de diário.
wp_clear_scheduled_hook( $hook );
$guardadas = $GLOBALS['uox_schedules'];
unset( $GLOBALS['uox_schedules']['daily'] );
uox_assert( false === uonix_intelligence_anomaly_maybe_schedule(), 'sem a recorrência daily o agendamento deve falhar fechado' );
uox_assert( ! isset( $GLOBALS['uox_cron'][ $hook ] ), 'nada deveria ter sido agendado sem a recorrência' );
$GLOBALS['uox_schedules'] = $guardadas;

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: Alerta de Anomalias (Módulo 5).\n" );
