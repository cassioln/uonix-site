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
$GLOBALS['uox_lead_before']   = null;

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
		// `has_lead_before()`: existe orçamento antes da janela medida?
		if ( false !== strpos( $query, 'MAX( created_at )' ) ) {
			$GLOBALS['uox_last_sql'] = $query;
			return $GLOBALS['uox_lead_before'];
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

/**
 * Série com valores explícitos por dia, terminando em `$fim`.
 *
 * Necessária para as fronteiras exatas: o revisor do PR #293 mostrou que os
 * fixtures de −60% e −5% não distinguem `<=` de `<` no limiar, nem `<` de `<=` no
 * piso de ruído. Com impressões fixas por dia não dá para montar somas como 455 ou
 * 50, que são justamente os valores de fronteira.
 *
 * `$valores` é lido do dia mais ANTIGO para o mais recente.
 */
function uox_serie_valores( $fim, array $valores ) {
	$cursor = ( new DateTimeImmutable( $fim, new DateTimeZone( 'UTC' ) ) )->modify( '-' . ( count( $valores ) - 1 ) . ' days' );
	$linhas = array();
	foreach ( $valores as $v ) {
		$linhas[] = uox_dia( $cursor->format( 'Y-m-d' ), $v );
		$cursor   = $cursor->modify( '+1 day' );
	}
	return $linhas;
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

$hoje   = '2026-09-23';
$ATRASO = (int) $regras['organic_settle_lag_days'];
$fuso   = new DateTimeZone( 'UTC' );

// As janelas são de CALENDÁRIO FIXO, descontando a defasagem medida. Com hoje em
// 23/09 e defasagem 4: atual 13–19/09, anterior 06–12/09.
$linhas = uox_serie( '2026-09-19', 21, 100 );
$j      = uonix_intelligence_anomaly_organic_windows( uox_datas( $linhas ), $JANELA, '2026-09-01', $hoje );

uox_assert( ! isset( $j['reason'] ), 'série cobrindo as duas janelas deveria produzir janelas, obteve motivo ' . ( $j['reason'] ?? '' ) );
$esperado_fim = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-' . $ATRASO . ' days' )->format( 'Y-m-d' );
uox_assert( $esperado_fim === ( $j['current']['end'] ?? '' ), 'a janela atual deve terminar em hoje menos a defasagem medida (' . $esperado_fim . '), obteve ' . ( $j['current']['end'] ?? '(nada)' ) );

// A asserção anti-regressão central: se alguém trocar esta matemática pela de
// `uonix_analytics_metrics_periods()`, a janela passa a terminar ONTEM, e volta o
// falso positivo de −51,8% medido em 2026-09-23.
$ontem = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-1 day' )->format( 'Y-m-d' );
uox_assert( ( $j['current']['end'] ?? '' ) !== $ontem, 'a janela atual NÃO pode terminar em ontem: é o defeito que este módulo existe para evitar' );
uox_assert( $ATRASO >= 3, 'a defasagem configurada deve cobrir a medição de 3 dias de 2026-09-23' );

// Tamanho e distância exatos: sem isso, comparar 7 dias com dois fins de semana
// contra 7 com um já acusaria queda sem nada ter acontecido.
$ci = new DateTimeImmutable( $j['current']['start'], $fuso );
$cf = new DateTimeImmutable( $j['current']['end'], $fuso );
$pi = new DateTimeImmutable( $j['previous']['start'], $fuso );
$pf = new DateTimeImmutable( $j['previous']['end'], $fuso );
uox_assert( $JANELA - 1 === (int) $ci->diff( $cf )->days, 'a janela atual deve ter exatamente ' . $JANELA . ' dias' );
uox_assert( $JANELA - 1 === (int) $pi->diff( $pf )->days, 'a janela anterior deve ter exatamente ' . $JANELA . ' dias' );
uox_assert( 1 === (int) $pf->diff( $ci )->days, 'a janela anterior deve terminar no dia anterior ao início da atual' );
uox_assert( $JANELA === (int) $pi->diff( $ci )->days, 'as duas janelas devem distar exatamente ' . $JANELA . ' dias' );
uox_assert( $ci->format( 'N' ) === $pi->format( 'N' ), 'as duas janelas devem começar no mesmo dia da semana, senão a sazonalidade sozinha acusa queda' );

// A COMPLETUDE é o sinal que separa "ainda não publicado" de "zero impressões" —
// a API omite linha nos dois casos, então contar dias é a única saída.
uox_assert( $JANELA === ( $j['current_days'] ?? -1 ), 'série contínua deveria dar ' . $JANELA . ' dias na janela atual, obteve ' . ( $j['current_days'] ?? -1 ) );
uox_assert( $JANELA === ( $j['previous_days'] ?? -1 ), 'série contínua deveria dar ' . $JANELA . ' dias na janela anterior' );

// Buraco no interior REDUZ a contagem, e é isso que permite recusar. A versão
// anterior tratava ausência interior como zero legítimo e incondicionalmente, o que
// reproduzia o falso positivo de −42,9% medido na revisão do PR #293.
$comBuraco = array_values( array_filter( uox_datas( $linhas ), static function ( $d ) { return '2026-09-17' !== $d; } ) );
$jb = uonix_intelligence_anomaly_organic_windows( $comBuraco, $JANELA, '2026-09-01', $hoje );
uox_assert( $JANELA - 1 === ( $jb['current_days'] ?? -1 ), 'dia ausente no interior deve REDUZIR a contagem de dias presentes, obteve ' . ( $jb['current_days'] ?? -1 ) );
uox_assert( $esperado_fim === ( $jb['current']['end'] ?? '' ), 'buraco no interior não deve mover a janela: ela é de calendário fixo' );

// Janela vazia é contagem zero, não recusa: quem decide que isso é colapso é o
// gatilho, porque só ele sabe se a janela anterior tinha dado.
$jv = uonix_intelligence_anomaly_organic_windows( uox_datas( uox_serie( '2026-09-12', 14, 100 ) ), $JANELA, '2026-09-01', $hoje );
uox_assert( 0 === ( $jv['current_days'] ?? -1 ), 'série que para antes da janela atual deve dar zero dias presentes' );
uox_assert( $JANELA === ( $jv['previous_days'] ?? -1 ), 'e a janela anterior deve seguir completa' );

// Recusas, cada uma com motivo próprio.
uox_assert( 0 === ( uonix_intelligence_anomaly_organic_windows( array(), $JANELA, '2026-09-01', $hoje )['current_days'] ?? -1 ), 'série vazia deveria dar zero dias, não recusar' );
uox_assert( 'series_dates_invalid' === ( uonix_intelligence_anomaly_organic_windows( array( '2026-09-19' ), $JANELA, 'nao-e-data', $hoje )['reason'] ?? '' ), 'data pedida inválida deveria recusar com series_dates_invalid' );
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

// Janelas, com hoje em 23/09 e defasagem 4: anterior 06–12/09, atual 13–19/09.
$CFG = array( 'search_console_site_url' => 'sc-domain:x' );

/** 700 impressões na semana anterior, 280 na atual → −60%. */
function uox_fetcher_queda( $config, $period ) {
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie( '2026-09-19', 7, 40 ) );
}
$resultado = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_queda', $CFG, $hoje );
uox_assert( ! empty( $resultado['available'] ), 'queda de 60% deveria ser verificável, motivo: ' . ( $resultado['reason'] ?? '' ) );
uox_assert( ! empty( $resultado['anomalous'] ), 'queda de 60% deveria ser anômala' );
uox_assert( -60.0 === ( $resultado['measured']['delta_percent'] ?? 0.0 ), 'a variação medida deveria ser −60,0%, obteve ' . ( $resultado['measured']['delta_percent'] ?? 'nada' ) );
uox_assert( '2026-09-13' === ( $resultado['started_at'] ?? '' ), 'o início declarado deve ser o começo da janela atual' );

function uox_fetcher_estavel( $config, $period ) {
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie( '2026-09-19', 7, 95 ) );
}
$estavel = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_estavel', $CFG, $hoje );
uox_assert( ! empty( $estavel['available'] ) && empty( $estavel['anomalous'] ), 'queda de 5% não deveria ser anômala' );

// FRONTEIRA EXATA do limiar de queda. Os fixtures de −60% e −5% não distinguem
// `<=` de `<`; achado da revisão do PR #293, mesma classe do buraco que eu já havia
// fechado no gatilho 1.
function uox_fetcher_exato( $config, $period ) {
	// 700 → 455 = exatamente −35,0%.
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie( '2026-09-19', 7, 65 ) );
}
$exatoQueda = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_exato', $CFG, $hoje );
uox_assert( -35.0 === ( $exatoQueda['measured']['delta_percent'] ?? 0.0 ), 'o fixture de fronteira deveria medir exatamente −35,0%, obteve ' . ( $exatoQueda['measured']['delta_percent'] ?? 'nada' ) );
uox_assert( ! empty( $exatoQueda['anomalous'] ), 'queda de exatamente −35,0% DEVE disparar: o limiar é inclusivo' );

function uox_fetcher_quase( $config, $period ) {
	// 700 → 456 = −34,9%, um décimo abaixo do limiar.
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie_valores( '2026-09-19', array( 65, 65, 65, 65, 65, 65, 66 ) ) );
}
$quaseQueda = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_quase', $CFG, $hoje );
uox_assert( -34.9 === ( $quaseQueda['measured']['delta_percent'] ?? 0.0 ), 'o fixture vizinho deveria medir −34,9%, obteve ' . ( $quaseQueda['measured']['delta_percent'] ?? 'nada' ) );
uox_assert( empty( $quaseQueda['anomalous'] ), 'queda de −34,9% não deve disparar' );

// COLAPSO A ZERO: a semana inteira sem uma linha, contra semana anterior cheia.
// A versão anterior deste módulo afirmava "dentro do normal" com variação 0,0%
// durante um apagão total de tráfego orgânico — achado ALTO 1 da revisão.
function uox_fetcher_colapso( $config, $period ) {
	return uox_serie( '2026-09-12', 7, 100 );
}
$colapso = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_colapso', $CFG, $hoje );
uox_assert( ! empty( $colapso['available'] ), 'colapso a zero deve ser VERIFICÁVEL, não indisponível' );
uox_assert( ! empty( $colapso['anomalous'] ), 'semana sem nenhuma impressão contra semana cheia é anomalia, não normalidade' );
uox_assert( false !== strpos( (string) ( $colapso['headline'] ?? '' ), 'parou de aparecer' ), 'a manchete do colapso deve nomear o desaparecimento da busca, obteve: ' . ( $colapso['headline'] ?? '' ) );
uox_assert( false === strpos( (string) ( $colapso['headline'] ?? '' ), 'dentro do normal' ), 'colapso NUNCA pode ser descrito como dentro do normal' );

// IMPUTAÇÃO OTIMISTA em vez de recusa.
//
// Recusar matava o falso positivo e criava dois pontos cegos, medidos na segunda
// revisão do PR #293. Dia ausente é ≥ 0, então imputa-se o valor mais favorável a
// "nada aconteceu" e só se conclui se a conclusão sobrevive.

// Caso A — o falso positivo que motivou tudo: 3 dias ausentes, tráfego NORMAL.
// Tratando ausente como zero dava −42,9% e alerta errado. Com imputação: 0,0%.
function uox_fetcher_buraco_normal( $config, $period ) {
	$atual = array_values( array_filter( uox_serie( '2026-09-19', 7, 147 ), static function ( $l ) {
		return ! in_array( $l['keys'][0], array( '2026-09-15', '2026-09-16', '2026-09-17' ), true );
	} ) );
	return array_merge( uox_serie( '2026-09-12', 7, 147 ), $atual );
}
$buracoNormal = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_buraco_normal', $CFG, $hoje );
uox_assert( ! empty( $buracoNormal['available'] ), 'janela com buraco deve ser verificável por imputação, não recusada' );
uox_assert( 0.0 === ( $buracoNormal['measured']['delta_percent'] ?? -1.0 ), 'tráfego normal com 3 dias ausentes deve dar 0,0%, obteve ' . ( $buracoNormal['measured']['delta_percent'] ?? 'nada' ) );
uox_assert( empty( $buracoNormal['anomalous'] ), 'buraco em tráfego normal NÃO pode gerar alerta: é o falso positivo de −42,9% que este desenho existe para matar' );
uox_assert( 3 === ( $buracoNormal['measured']['imputed_days'] ?? -1 ), 'os três dias ausentes devem ser declarados como imputados' );

// Caso B — colapso PARCIAL severo. Antes ficava calado, e o detector era
// não-monotônico: aumentar a severidade DESLIGAVA o alerta.
function uox_fetcher_colapso_parcial( $config, $period ) {
	// Atual: 4 dias somando 40, 3 dias em zero absoluto (linha omitida). Anterior: 700.
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie_valores( '2026-09-16', array( 10, 10, 10, 10 ) ) );
}
$parcial = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_colapso_parcial', $CFG, $hoje );
uox_assert( ! empty( $parcial['available'] ), 'colapso parcial deve ser verificável' );
uox_assert( ! empty( $parcial['anomalous'] ), 'colapso parcial severo DEVE alertar: antes ficava calado porque os dias fracos chegavam a zero e somiam da resposta' );
uox_assert( -51.4 === ( $parcial['measured']['delta_percent'] ?? 0.0 ), 'a imputação otimista deveria dar −51,4%, obteve ' . ( $parcial['measured']['delta_percent'] ?? 'nada' ) );

// MONOTONICIDADE: a mesma queda com 1 impressão nos dias fracos, em vez de zero,
// também alerta — e mais forte. Aumentar a severidade não pode reduzir o alarme.
function uox_fetcher_colapso_parcial_um( $config, $period ) {
	return array_merge( uox_serie( '2026-09-12', 7, 100 ), uox_serie_valores( '2026-09-19', array( 10, 10, 10, 10, 1, 1, 1 ) ) );
}
$parcialUm = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_colapso_parcial_um', $CFG, $hoje );
uox_assert( ! empty( $parcialUm['anomalous'] ), 'a variante com 1 impressão também deve alertar' );
uox_assert( (float) $parcialUm['measured']['delta_percent'] <= (float) $parcial['measured']['delta_percent'], 'severidade MAIOR (zeros) não pode produzir queda medida menor que a variante com 1 impressão: o detector tem de ser monotônico' );

// Janela ANTERIOR incompleta: o dia ausente é imputado como zero, o que reduz a base
// e portanto a queda. Conservador, e segue comparável em vez de recusar.
function uox_fetcher_base_incompleta( $config, $period ) {
	$anterior = array_values( array_filter( uox_serie( '2026-09-12', 7, 100 ), static function ( $l ) {
		return '2026-09-09' !== $l['keys'][0];
	} ) );
	return array_merge( $anterior, uox_serie( '2026-09-19', 7, 100 ) );
}
$baseIncompleta = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_base_incompleta', $CFG, $hoje );
uox_assert( ! empty( $baseIncompleta['available'] ), 'janela anterior incompleta segue comparável: o dia ausente vale zero e isso é conservador' );
uox_assert( empty( $baseIncompleta['anomalous'] ), 'base menor produz queda menor, então não pode alertar aqui' );
uox_assert( 6 === ( $baseIncompleta['measured']['windows']['previous_days'] ?? -1 ), 'a contagem de dias da janela anterior deve refletir a ausência' );

// PRECEDÊNCIA: colapso é avaliado ANTES dos portões de linha de base.
//
// A segunda revisão do PR #293 mediu que, com o colapso depois do portão de janela
// anterior incompleta, um apagão total era detectável em UM único dia de 26 — e que
// mover o bloco não reprovava nenhuma asserção. Este é o caso que fixa a ordem:
// atual vazia E anterior incompleta, mas com volume observado suficiente.
function uox_fetcher_colapso_base_parcial( $config, $period ) {
	$anterior = array_values( array_filter( uox_serie( '2026-09-12', 7, 100 ), static function ( $l ) {
		return '2026-09-12' !== $l['keys'][0];
	} ) );
	return $anterior; // nada na janela atual
}
$colapsoBaseParcial = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_colapso_base_parcial', $CFG, $hoje );
uox_assert( ! empty( $colapsoBaseParcial['available'] ), 'colapso com base parcial deve ser verificável' );
uox_assert( ! empty( $colapsoBaseParcial['anomalous'] ), 'colapso DEVE ser avaliado antes dos portões de completude da base: senão o apagão total é detectável em um único dia' );
uox_assert( false !== strpos( (string) ( $colapsoBaseParcial['headline'] ?? '' ), 'parou de aparecer' ), 'e deve usar a manchete de colapso' );

// O piso de ruído CONTINUA vindo antes do colapso: sem volume de referência, nem o
// colapso tem significado.
function uox_fetcher_colapso_sem_base( $config, $period ) {
	return uox_serie( '2026-09-12', 7, 5 ); // 35 impressões, abaixo do piso de 50
}
$colapsoSemBase = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_colapso_sem_base', $CFG, $hoje );
uox_assert( empty( $colapsoSemBase['available'] ), 'sem volume de referência o colapso não pode ser afirmado' );
uox_assert( 'baseline_too_small' === ( $colapsoSemBase['reason'] ?? '' ), 'o motivo deveria ser baseline_too_small, obteve ' . ( $colapsoSemBase['reason'] ?? '' ) );

// FRONTEIRA EXATA do piso de ruído: `$anterior < 50` recusa, então 50 passa e 49 não.
function uox_fetcher_piso_exato( $config, $period ) {
	// Soma 50 na janela anterior.
	return array_merge( uox_serie_valores( '2026-09-12', array( 7, 7, 7, 7, 7, 7, 8 ) ), uox_serie( '2026-09-19', 7, 7 ) );
}
$pisoExato = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_piso_exato', $CFG, $hoje );
uox_assert( 50.0 === ( $pisoExato['measured']['previous'] ?? 0.0 ), 'o fixture deveria somar exatamente 50 na janela anterior, obteve ' . ( $pisoExato['measured']['previous'] ?? 'nada' ) );
uox_assert( ! empty( $pisoExato['available'] ), 'exatamente 50 impressões NÃO está abaixo do piso: o piso é exclusivo' );

function uox_fetcher_piso_abaixo( $config, $period ) {
	return array_merge( uox_serie( '2026-09-12', 7, 7 ), uox_serie( '2026-09-19', 7, 7 ) );
}
$pisoAbaixo = uonix_intelligence_anomaly_organic_drop( 'uox_fetcher_piso_abaixo', $CFG, $hoje );
uox_assert( 49.0 === ( $pisoAbaixo['measured']['previous'] ?? 0.0 ), 'o fixture vizinho deveria somar 49' );
uox_assert( empty( $pisoAbaixo['available'] ), 'volume abaixo do piso de ruído deveria ser indisponível' );
uox_assert( 'baseline_too_small' === ( $pisoAbaixo['reason'] ?? '' ), 'o motivo deveria ser baseline_too_small, obteve ' . ( $pisoAbaixo['reason'] ?? '' ) );
uox_assert( empty( $pisoAbaixo['anomalous'] ), 'indisponível nunca é anômalo' );

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
uox_assert( $limiar - 1 === ( $naFronteira['measured']['silent_days'] ?? -1 ), 'o fixture de véspera deveria medir ' . ( $limiar - 1 ) . ' dias de silêncio, obteve ' . ( $naFronteira['measured']['silent_days'] ?? -1 ) );
uox_assert( empty( $naFronteira['anomalous'] ), 'silêncio de ' . ( $limiar - 1 ) . ' dias não deveria disparar (fronteira do limiar)' );

// O caso EXATO, que é o único que distingue `>=` de `>` no limiar.
//
// Descoberto ao planejar as mutações: com silêncio total o laço para em limiar+1,
// e no caso da véspera para em limiar−1. Nos dois, `>=` e `>` concordam. Sem esta
// asserção, trocar o operador passaria despercebido e o alerta atrasaria um dia
// inteiro — o que num limiar medido em dias é um erro de 10%.
$noPonto = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-' . $limiar . ' days' )->format( 'Y-m-d' );
$GLOBALS['uox_lead_rows'] = array( $noPonto => 1 );
$exato = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( $limiar === ( $exato['measured']['silent_days'] ?? -1 ), 'o fixture exato deveria medir ' . $limiar . ' dias de silêncio, obteve ' . ( $exato['measured']['silent_days'] ?? -1 ) );
uox_assert( ! empty( $exato['anomalous'] ), 'silêncio de exatamente ' . $limiar . ' dias DEVE disparar: o limiar é inclusivo' );

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

// Sem nenhum lead no período não há normalidade a medir, e a resposta é
// INDISPONÍVEL. A versão anterior devolvia `longest_gap` igual à janela inteira, o
// que produzia o aviso "o limiar descreve o normal" a partir de dado nenhum.
$GLOBALS['uox_lead_rows'] = array();
$base = uonix_intelligence_anomaly_lead_baseline( 30, $hoje );
uox_assert( empty( $base['available'] ), 'sem nenhum lead no período a linha de base deve ser indisponível' );
uox_assert( 'no_leads_in_window' === ( $base['reason'] ?? '' ), 'o motivo deveria ser no_leads_in_window, obteve ' . ( $base['reason'] ?? '' ) );

$GLOBALS['uox_lead_rows'] = array();
$cursor = new DateTimeImmutable( $hoje, $fuso );
for ( $i = 0; $i < 30; $i++ ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; $cursor = $cursor->modify( '-1 day' ); }
$baseDensa = uonix_intelligence_anomaly_lead_baseline( 30, $hoje );
uox_assert( ! empty( $baseDensa['available'] ), 'com lead todo dia a linha de base deve estar disponível' );
uox_assert( 0 === (int) $baseDensa['longest_gap'], 'com lead todo dia o maior silêncio encerrado é 0' );
uox_assert( 0 === (int) $baseDensa['current_silence'], 'com lead hoje o silêncio em curso é 0' );
uox_assert( ! empty( $baseDensa['threshold_is_safe'] ), 'limiar de ' . $limiar . ' dias é seguro contra silêncio encerrado de 0' );
uox_assert( 1.0 === (float) $baseDensa['per_day'], 'trinta leads em trinta dias é 1,00 por dia' );

// ESTA é a asserção que prova a correção do ALTO 4 da revisão do PR #293.
//
// Histórico denso truncado há exatamente `limiar` dias: o gatilho DISPARA, e a
// linha de base tem de continuar dizendo que o limiar é seguro, porque os
// intervalos ENCERRADOS do histórico são todos de zero dia.
//
// Antes, `longest_gap` incluía o silêncio em curso, então era necessariamente
// maior ou igual ao limiar sempre que o gatilho disparava — por identidade, não por
// acidente de fixture. O painel exibia "1 anomalia crítica detectada" e, logo
// abaixo, instruía o operador a desconsiderá-la.
$GLOBALS['uox_lead_rows'] = array();
$cursor = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-' . $limiar . ' days' );
for ( $i = 0; $i < 30; $i++ ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; $cursor = $cursor->modify( '-1 day' ); }

$emAnomalia = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( ! empty( $emAnomalia['anomalous'] ), 'o fixture deveria estar em anomalia (silêncio de ' . $limiar . ' dias)' );

$baseEmAnomalia = uonix_intelligence_anomaly_lead_baseline( 60, $hoje );
uox_assert( ! empty( $baseEmAnomalia['available'] ), 'com anomalia em curso a linha de base ainda deve estar disponível' );
uox_assert( 0 === (int) $baseEmAnomalia['longest_gap'], 'o silêncio EM CURSO não pode entrar na medição de normalidade, obteve ' . $baseEmAnomalia['longest_gap'] );
uox_assert( $limiar === (int) $baseEmAnomalia['current_silence'], 'o silêncio em curso deve ser reportado separado, obteve ' . $baseEmAnomalia['current_silence'] );
uox_assert( ! empty( $baseEmAnomalia['threshold_is_safe'] ), 'com o gatilho DISPARANDO, o limiar deve continuar sendo declarado seguro: o painel não pode desmentir o alerta correto' );

// E o aviso segue aparecendo quando deve: um silêncio ENCERRADO maior ou igual ao
// limiar é evidência de que o limiar descreve o normal.
$GLOBALS['uox_lead_rows'] = array();
$cursor = new DateTimeImmutable( $hoje, $fuso );
for ( $i = 0; $i < 60; $i++ ) {
	// Lead hoje e há mais de `limiar` dias, com um vazio de `limiar` dias no meio.
	if ( 0 === $i || $i > $limiar ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; }
	$cursor = $cursor->modify( '-1 day' );
}
$baseInsegura = uonix_intelligence_anomaly_lead_baseline( 60, $hoje );
uox_assert( $limiar === (int) $baseInsegura['longest_gap'], 'o fixture deveria produzir silêncio encerrado igual ao limiar, obteve ' . $baseInsegura['longest_gap'] );
uox_assert( empty( $baseInsegura['threshold_is_safe'] ), 'limiar IGUAL a um silêncio ENCERRADO observado não pode ser considerado seguro' );

// O vazio INICIAL da janela não é intervalo entre leads: é a janela de observação
// sendo maior que a história disponível. Descoberto pelo próprio fixture ao corrigir
// o ALTO 4 — num site novo, contá-lo declararia o limiar inseguro sem evidência.
$GLOBALS['uox_lead_rows'] = array();
$cursor = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-1 day' );
for ( $i = 0; $i < 5; $i++ ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; $cursor = $cursor->modify( '-1 day' ); }
$GLOBALS['uox_lead_before'] = null; // nada antes da janela: história curta
$baseNova = uonix_intelligence_anomaly_lead_baseline( 90, $hoje );
uox_assert( ! empty( $baseNova['available'] ), 'cinco dias de histórico numa janela de 90 dias ainda dá linha de base' );
uox_assert( 0 === (int) $baseNova['longest_gap'], 'os 85 dias sem histórico ANTES do primeiro lead não são intervalo entre leads, obteve ' . $baseNova['longest_gap'] );
uox_assert( ! empty( $baseNova['threshold_is_safe'] ), 'histórico curto não pode declarar o limiar inseguro' );
uox_assert( empty( $baseNova['gap_from_edge'] ), 'sem lead antes da janela, a medição não começa na borda' );

// MESMO fixture, mas COM orçamento antes da janela: agora o vazio inicial É um
// intervalo encerrado, e ignorá-lo suprimia o aviso onde ele mais importa.
//
// Medido na segunda revisão do PR #293: site com leads até 20/06, silêncio de três
// meses, retomada em 19/09. Na janela de 90 dias o painel dizia `longest_gap = 0` e
// declarava seguro um limiar de 10 dias — num site que demonstravelmente passa três
// meses sem orçamento. A mesma história em 120 dias dava 90 e `false`: verdictos
// opostos para o mesmo site, porque a contagem dentro da janela não distingue
// "história curta" de "o lead anterior ficou de fora".
$GLOBALS['uox_lead_before'] = '2026-06-20 10:00:00';
$baseComAntes = uonix_intelligence_anomaly_lead_baseline( 90, $hoje );
uox_assert( ! empty( $baseComAntes['gap_from_edge'] ), 'havendo lead antes da janela, a medição deve começar na borda' );
uox_assert( (int) $baseComAntes['longest_gap'] > (int) $baseNova['longest_gap'], 'o vazio inicial deve contar como intervalo quando existe lead antes dele, obteve ' . $baseComAntes['longest_gap'] );
uox_assert( empty( $baseComAntes['threshold_is_safe'] ), 'e aí o limiar de ' . $limiar . ' dias NÃO pode ser declarado seguro' );
$GLOBALS['uox_lead_before'] = null;

// ---------------------------------------------------------------------------
// 5b. Saturação do contador e o dia que "Quando começou" nomeia (MÉDIO 1).
// ---------------------------------------------------------------------------

// Silêncio de 15 dias precisa reportar 15. A versão anterior parava o laço em
// `limiar + 1` e o painel dizia "há 11 dias" no 40º dia de colapso, com `started_at`
// andando um dia por dia — fazendo um incidente único parecer episódios novos.
$GLOBALS['uox_lead_rows'] = array( ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-15 days' )->format( 'Y-m-d' ) => 1 );
$longo = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( 15 === ( $longo['measured']['silent_days'] ?? -1 ), 'silêncio de 15 dias deve reportar 15, não saturar no limiar, obteve ' . ( $longo['measured']['silent_days'] ?? -1 ) );
uox_assert( false !== strpos( (string) ( $longo['headline'] ?? '' ), '15' ), 'a manchete deve trazer o número real de dias' );

// `started_at` nomeia o primeiro dia SILENCIOSO, não o dia em que o último orçamento
// chegou. Com 15 dias de silêncio, o último lead foi em hoje−15 e o primeiro dia
// silencioso é hoje−14.
$esperado_inicio = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-14 days' )->format( 'Y-m-d' );
uox_assert( $esperado_inicio === ( $longo['started_at'] ?? '' ), '"Quando começou" deve ser o primeiro dia silencioso (' . $esperado_inicio . '), não o dia do último orçamento; obteve ' . ( $longo['started_at'] ?? '(nada)' ) );

// E o teto da contagem tem de ser a janela da linha de base, não 30.
//
// Na primeira revisão o teto era `limiar + 1` e a tela dizia "há 11 dias" no 40º dia
// de colapso. A correção levou o teto a 30, e a segunda revisão mediu que o MESMO
// defeito reaparecia no 31º dia: "há 30 dias", com `started_at` andando um dia por
// dia e fazendo um incidente único parecer episódios novos a cada visita.
$GLOBALS['uox_lead_rows'] = array( ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-40 days' )->format( 'Y-m-d' ) => 1 );
$muitoLongo = uonix_intelligence_anomaly_lead_silence( $hoje );
uox_assert( 40 === ( $muitoLongo['measured']['silent_days'] ?? -1 ), 'silêncio de 40 dias deve reportar 40, não saturar em 30, obteve ' . ( $muitoLongo['measured']['silent_days'] ?? -1 ) );
$inicio40 = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '-39 days' )->format( 'Y-m-d' );
uox_assert( $inicio40 === ( $muitoLongo['started_at'] ?? '' ), 'e "Quando começou" deve ficar FIXO no primeiro dia silencioso real (' . $inicio40 . '), obteve ' . ( $muitoLongo['started_at'] ?? '(nada)' ) );

// ---------------------------------------------------------------------------
// 5c. Anomalia ativa não pode desaparecer porque a fonte falhou (MÉDIO 2).
// ---------------------------------------------------------------------------

$estadoAtivo = array( 'organic_drop' => true );
$resumoStale = uonix_intelligence_anomaly_detect(
	array( uox_achado( 'organic_drop', false, false ) ),
	$hoje,
	$estadoAtivo
);
uox_assert( 1 === $resumoStale['anomalous'], 'gatilho indisponível cujo estado anterior era anômalo deve continuar contando como anomalia' );
uox_assert( 0 === $resumoStale['unavailable'], 'e não deve ser contado como simples indisponibilidade' );
uox_assert( ! empty( $resumoStale['findings'][0]['stale_anomaly'] ), 'o achado deve ser marcado como anomalia não reverificada' );
uox_assert( 'critical' === uonix_intelligence_anomaly_badge( $resumoStale )['state'], 'o badge deve seguir crítico: uma queda em curso não pode ser rebaixada a "verificação indisponível"' );

// Sem estado anterior anômalo, indisponível continua sendo só indisponível.
$resumoLimpo = uonix_intelligence_anomaly_detect( array( uox_achado( 'organic_drop', false, false ) ), $hoje, array() );
uox_assert( 0 === $resumoLimpo['anomalous'] && 1 === $resumoLimpo['unavailable'], 'indisponível sem anomalia prévia não pode inventar anomalia' );

// ---------------------------------------------------------------------------
// 5d. A proteção acima NÃO pode depender da entrega do e-mail (ALTO 2 da 2a revisão).
// ---------------------------------------------------------------------------

// `detect()` lê `observed`, não `triggers`. Sem essa separação a proteção ficava
// inerte e PERMANENTEMENTE na configuração sem destinatário — que é justamente a que
// o contrato dedica uma seção a declarar suportada, porque "o badge é útil sem e-mail
// nenhum". Medido antes da correção: dia 1 crítico, dia 2 com a fonte falhando o
// incidente desaparecia e o badge virava "1 verificação indisponível".
$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array();
unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$GLOBALS['uox_mail_calls'] = array();

$d1 = uonix_intelligence_anomaly_run_check( array( uox_achado( 'organic_drop', true, true ) ), $hoje );
uox_assert( 1 === $d1['summary']['anomalous'], 'dia 1 sem destinatário deve contar a anomalia' );
uox_assert( true === ( $d1['observed']['organic_drop'] ?? null ), 'o que foi OBSERVADO avança mesmo sem destinatário' );
uox_assert( false === ( uonix_intelligence_anomaly_get_state()['organic_drop'] ?? null ), 'e "já avisei" corretamente NÃO avança, porque nada foi enviado' );

$amanha = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '+1 day' )->format( 'Y-m-d' );
$d2 = uonix_intelligence_anomaly_run_check( array( uox_achado( 'organic_drop', false, false ) ), $amanha );
uox_assert( 1 === $d2['summary']['anomalous'], 'dia 2 com a fonte falhando: a anomalia NÃO pode desaparecer só porque não há destinatário' );
uox_assert( 0 === $d2['summary']['unavailable'], 'e não pode ser rebaixada a simples indisponibilidade' );
uox_assert( 'critical' === uonix_intelligence_anomaly_badge( $d2['summary'] )['state'], 'o badge deve seguir crítico sem destinatário cadastrado' );

// ---------------------------------------------------------------------------
// 5e. E a anomalia não reverificada tem PRAZO (MÉDIO 2 da 2a revisão).
// ---------------------------------------------------------------------------

// Sem prazo, uma credencial revogada mantinha o badge crítico por 400 dias afirmando
// "detectada em <data> e ainda não resolvida". Alarme permanente treina a pessoa a
// ignorar a tela tão bem quanto alarme semanal.
$prazo    = (int) $regras['stale_anomaly_max_days'];
$noPrazo  = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '+' . $prazo . ' days' )->format( 'Y-m-d' );
$expirado = ( new DateTimeImmutable( $hoje, $fuso ) )->modify( '+' . ( $prazo + 1 ) . ' days' )->format( 'Y-m-d' );

$dPrazo = uonix_intelligence_anomaly_run_check( array( uox_achado( 'organic_drop', false, false ) ), $noPrazo );
uox_assert( 1 === $dPrazo['summary']['anomalous'], 'dentro do prazo a anomalia não reverificada segue visível' );

$dExp = uonix_intelligence_anomaly_run_check( array( uox_achado( 'organic_drop', false, false ) ), $expirado );
uox_assert( 0 === $dExp['summary']['anomalous'], 'passado o prazo, a anomalia não reverificada não pode ser afirmada no presente' );
uox_assert( 1 === $dExp['summary']['unavailable'], 'ela vira indisponibilidade declarada' );
uox_assert( ! empty( $dExp['summary']['findings'][0]['stale_expired'] ), 'e o achado deve dizer que expirou, para o painel nomear a última observação' );
uox_assert( 'unknown' === uonix_intelligence_anomaly_badge( $dExp['summary'] )['state'], 'o badge deve cair para indisponível, não ficar crítico com dado de um ano' );

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

// Falha de envio retenta, mas COM TETO.
//
// A versão anterior retentava sem limite, com o comentário afirmando que isso "não
// gera enxurrada porque nos dois casos nada é entregue". A revisão do PR #293 provou
// a afirmação falsa no fonte do PHPMailer: `smtpSend()` transmite o DATA se houver
// ao menos um destinatário aceito e só DEPOIS lança `recipients_failed`, então um
// endereço com typo faz os bons receberem e `wp_mail()` devolver falso. O resultado
// medido era 14 e-mails idênticos num episódio de 14 dias.
$teto = (int) $regras['alert_max_attempts'];
unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$GLOBALS['uox_mail_calls']  = array();
$GLOBALS['uox_mail_result'] = false;

$r = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'houve tentativa de envio' );
uox_assert( false === ( uonix_intelligence_anomaly_get_state()['lead_silence'] ?? null ), 'na primeira falha o gatilho NÃO pode ser marcado como já avisado' );
uox_assert( 1 === ( $r['meta']['lead_silence']['attempts'] ?? 0 ), 'a tentativa deve ser contabilizada' );
uox_assert( '' !== uonix_intelligence_anomaly_get_summary()['checked_at'], 'o resumo deve ser gravado mesmo quando o envio falha, para o painel mostrar o que foi medido' );

// Retenta até esgotar o teto.
for ( $i = 2; $i <= $teto; $i++ ) {
	$r = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
	uox_assert( $i === count( $GLOBALS['uox_mail_calls'] ), "a tentativa {$i} deveria acontecer" );
}
uox_assert( $teto === ( $r['meta']['lead_silence']['attempts'] ?? 0 ), 'as tentativas deveriam somar o teto de ' . $teto );
uox_assert( ! empty( $r['meta']['lead_silence']['undelivered'] ), 'esgotado o teto, o episódio deve ficar marcado como aviso NÃO entregue' );
uox_assert( true === ( uonix_intelligence_anomaly_get_state()['lead_silence'] ?? null ), 'esgotado o teto, o estado avança para parar a enxurrada' );

// E a partir daí para de tentar: é isto que impede os 14 e-mails.
uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( $teto === count( $GLOBALS['uox_mail_calls'] ), 'depois do teto NÃO pode haver nova tentativa, obteve ' . count( $GLOBALS['uox_mail_calls'] ) . ' envios' );

// Episódio que se resolve limpa a contabilidade, senão um episódio novo nasceria com
// o teto já esgotado e nunca avisaria.
$normal = array( uox_achado( 'lead_silence', true, false ), uox_achado( 'organic_drop', true, false ) );
$rLimpo = uonix_intelligence_anomaly_run_check( $normal, $hoje );
uox_assert( ! isset( $rLimpo['meta']['lead_silence'] ), 'gatilho que voltou ao normal deve perder a contabilidade do episódio' );

$GLOBALS['uox_mail_result'] = true;
$GLOBALS['uox_mail_calls']  = array();
uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'episódio novo depois de um resolvido deve avisar de novo' );

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

// Lista vazia NÃO consome tentativa. Sem esta asserção, tratar o caso como falha de
// envio passava despercebido — e aí um episódio que nasce sem destinatário gastaria o
// teto em dias de espera e nunca avisaria, mesmo depois do cadastro.
// Ausência da chave e zero são a mesma coisa aqui: nada foi tentado.
uox_assert( 0 === (int) ( $semDestino['meta']['lead_silence']['attempts'] ?? 0 ), 'lista vazia não pode contar como tentativa de envio, obteve ' . ( $semDestino['meta']['lead_silence']['attempts'] ?? '(ausente)' ) );
$semDestino2 = uonix_intelligence_anomaly_run_check( $anomalia, $hoje );
uox_assert( 0 === (int) ( $semDestino2['meta']['lead_silence']['attempts'] ?? 0 ), 'nem depois de várias rodadas sem destinatário as tentativas podem acumular, obteve ' . ( $semDestino2['meta']['lead_silence']['attempts'] ?? '(ausente)' ) );
uox_assert( empty( $semDestino2['meta']['lead_silence']['undelivered'] ), 'sem destinatário o episódio nunca pode ser marcado como aviso não entregue: nada foi tentado' );

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
