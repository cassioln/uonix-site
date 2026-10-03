<?php
/**
 * Testes do relatório executivo por e-mail da Central de Inteligência.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'UONIX_ENV', 'local' );

$failures = 0;
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_cron'] = array();
$GLOBALS['uox_actions'] = array();
$GLOBALS['uox_mail_calls'] = array();
$GLOBALS['uox_mail_result'] = true;
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_nonce_actions'] = array();
$GLOBALS['uox_cron_rec'] = array();
$GLOBALS['uox_cron_cleared'] = array();
$GLOBALS['uox_actions_all'] = array();
$GLOBALS['uox_schedules'] = array( 'hourly' => array( 'interval' => 3600 ), 'weekly' => array( 'interval' => 604800 ) );
$GLOBALS['uox_timezone'] = 'America/Sao_Paulo';

// Semeia um destinatário ANTES de carregar os módulos, de propósito.
//
// Sem isso, a asserção "carregar o módulo não agenda" passa por motivo errado: o
// callback sairia no guard de lista vazia antes de tocar no agendador, e uma
// chamada indevida no carregamento do arquivo não seria detectada. Com a
// semente, só o registro do hook explica o agendador vazio.
$GLOBALS['uox_options']['uonix_executive_report_recipients'] = array( 'semente@ksio.dev' );

function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class Uox_Redirect_Exception extends RuntimeException {
	public $url;
	public function __construct( $url ) { $this->url = $url; parent::__construct( 'redirect' ); }
}
class Uox_Die_Exception extends RuntimeException {}

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

// Registra também o accepted_args: é ele que impede um evento de cron agendado
// com argumentos de injetar uma lista de destinatários arbitrária no envio.
//
// `uox_actions` guarda UM callback por hook, então um hook registrado por dois
// arquivos perde o primeiro. `uox_actions_all` acumula todos: sem ele, uma
// asserção sobre `init` passaria por causa do registro de 53, que também usa
// `init`, e não do registro que se quer verificar.
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['uox_actions'][ $hook ] = array( 'callback' => $callback, 'accepted_args' => $args );
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
function update_option( $key, $value ) { $GLOBALS['uox_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['uox_options'] ) ) return false; $GLOBALS['uox_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uox_options'][ $key ] ); return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value ) { return true; }
function delete_transient( $key ) { return true; }

function current_user_can( $capability ) { return (bool) $GLOBALS['uox_can']; }
function check_admin_referer( $action = -1 ) { $GLOBALS['uox_referer_action'] = $action; if ( ! $GLOBALS['uox_referer_ok'] ) { throw new Uox_Die_Exception( 'nonce' ); } return true; }
function wp_die( $message = '', $title = '', $args = array() ) { throw new Uox_Die_Exception( (string) $message ); }
function wp_safe_redirect( $url ) { throw new Uox_Redirect_Exception( $url ); }

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['uox_mail_calls'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers );
	return $GLOBALS['uox_mail_result'];
}

function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return (string) filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL ); }
function is_email( $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function admin_url( $path = '' ) { return 'https://uonix.com.br/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
// Stub que de fato escapa. Passa-tudo tornaria vazia qualquer asserção sobre
// escape de URL: o teste passaria mesmo se o código concatenasse cru.
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>' ), array( '&quot;', '&#039;', '&lt;', '&gt;' ), (string) $url ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
// Coleta TODAS as ações emitidas: o painel tem dois formulários, e guardar só a
// última compararia formulários diferentes.
function wp_nonce_field( $action = -1 ) { $GLOBALS['uox_nonce_actions'][] = $action; echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
// A recorrência é GUARDADA, e não descartada: um stub que ignora o segundo
// argumento faz qualquer asserção sobre "é semanal" passar sem verificar nada.
function wp_schedule_event( $ts, $rec, $hook ) {
	$GLOBALS['uox_cron'][ $hook ] = $ts;
	$GLOBALS['uox_cron_rec'][ $hook ] = $rec;
	return true;
}
// Conta as chamadas para distinguir "não reagendou" de "reagendou com a mesma data".
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['uox_cron_cleared'][] = $hook;
	unset( $GLOBALS['uox_cron'][ $hook ], $GLOBALS['uox_cron_rec'][ $hook ] );
}
function wp_get_schedules() { return $GLOBALS['uox_schedules']; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['uox_timezone'] ); }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : (int) $ts ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }

// Governança do ksio.dev (49), substituída por um interruptor: o 49 tem teste próprio,
// e aqui importa que o handler CONSULTE a regra, com a chave certa.
$GLOBALS['uox_ksio_pode']   = true;
$GLOBALS['uox_ksio_chaves'] = array();
function uonix_ksio_can_access_tool( $chave ) {
	$GLOBALS['uox_ksio_chaves'][] = $chave;
	return (bool) $GLOBALS['uox_ksio_pode'];
}
// Quem altera as Configurações, inclusive o envio de teste: só o dono (49).
$GLOBALS['uox_dono']           = true;
$GLOBALS['uox_dono_consultas'] = 0;
function uonix_ksio_can_configure_insights() {
	++$GLOBALS['uox_dono_consultas'];
	return (bool) $GLOBALS['uox_dono'];
}

$GLOBALS['uox_ia'] = array();
function uonix_intelligence_ai_suggestion_for( $row ) { return $GLOBALS['uox_ia'][ $row['query'] ?? '' ] ?? array( 'status' => 'pending' ); }
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/57-admin-intelligence-report.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/63-admin-intelligence-content-radar.php';

// Sem o 50 a licença conta como inativa: nada sai. Só dá para provar antes de carregá-lo.
$GLOBALS['uox_mail_result'] = true;
$GLOBALS['uox_mail_calls']  = array();
$sem_licenca = uonix_intelligence_send_report( array( 'cassio@uonix.com.br' ) );
uox_assert( 'license_inactive' === $sem_licenca['reason'] && array() === $GLOBALS['uox_mail_calls'], 'Sem o arquivo da licença o relatório não é enviado; obteve ' . var_export( $sem_licenca, true ) );
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';

function uox_q( $query, $position, $impressions, $ctr ) {
	return array( 'query' => $query, 'clicks' => 1, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position );
}
function uox_snapshot( array $universe, $com_periodos = true ) {
	$snapshot = array(
		'version' => 3,
		'period_days' => 30,
		'status' => 'updated',
		'updated_at' => gmdate( 'c' ),
		'ga4' => array(),
		'search_console' => array( 'queries' => array(), 'queries_extended' => $universe, 'pages' => array() ),
	);
	if ( $com_periodos ) {
		$snapshot['periods'] = array(
			'current'  => array( 'start' => '2026-08-23', 'end' => '2026-09-21' ),
			'previous' => array( 'start' => '2026-07-24', 'end' => '2026-08-22' ),
		);
	}
	return $snapshot;
}
function uox_analysis( array $rows, $available = true, $reason = '', $stale = false ) {
	return array(
		'available' => $available,
		'reason' => $reason,
		'source' => 'search_console',
		'synced_at' => gmdate( 'c' ),
		'stale' => $stale,
		'period_days' => 30,
		'universe' => count( $rows ),
		'rows' => $rows,
	);
}

// ---------------------------------------------------------------------------
// O evento semanal tem handler registrado, mas NÃO é agendado.
// ---------------------------------------------------------------------------
uox_assert( isset( $GLOBALS['uox_actions'][ uonix_intelligence_report_hook() ] ), 'Handler do evento semanal está registrado' );
uox_assert( 0 === $GLOBALS['uox_actions'][ uonix_intelligence_report_hook() ]['accepted_args'], 'Handler do cron não aceita argumentos: um evento agendado à mão não pode injetar destinatários' );
uox_assert( false === wp_next_scheduled( uonix_intelligence_report_hook() ), 'Carregar o módulo não agenda o envio automático' );
uox_assert( array() === $GLOBALS['uox_cron'], 'Nenhum evento de cron é criado no carregamento' );
uox_assert( isset( $GLOBALS['uox_actions']['admin_post_uonix_intelligence_send_test'] ), 'Handler do envio de teste está registrado' );
// Verificado pela coleta acumulada, e não por `uox_actions['init']`: 53 também
// registra em `init`, então a checagem simples passaria sem este registro existir.
uox_assert(
	null !== uox_hook_args( 'init', 'uonix_intelligence_maybe_schedule_report' ),
	'Quem agenda é um callback de init, e ele está registrado — verificado entre TODOS os registros de init, não só o último'
);
uox_assert(
	0 === uox_hook_args( 'init', 'uonix_intelligence_maybe_schedule_report' ),
	'O callback de agendamento declara accepted_args=0, como o irmão de 53'
);

// ---------------------------------------------------------------------------
// Invariante: existe evento agendado se, e somente se, existe destinatário.
// ---------------------------------------------------------------------------

// Esvazia a lista aqui: o estado inicial do arquivo NÃO é vazio, porque a
// semente lá no topo é o que dá dentes à asserção de carregamento.
$GLOBALS['uox_options'][ uonix_intelligence_recipients_option() ] = array();
uox_assert( false === uonix_intelligence_maybe_schedule_report(), 'Sem destinatário, não agenda' );
uox_assert( false === wp_next_scheduled( uonix_intelligence_report_hook() ), 'Sem destinatário, o agendador segue vazio' );
uox_assert( array() === $GLOBALS['uox_cron_cleared'], 'Sem destinatário e sem evento, não chama limpeza à toa' );

// Com destinatário, agenda uma vez, semanal, no futuro.
$GLOBALS['uox_options'][ uonix_intelligence_recipients_option() ] = array( 'operador@ksio.dev' );
uox_assert( true === uonix_intelligence_maybe_schedule_report(), 'Com destinatário, agenda' );
$primeiro = wp_next_scheduled( uonix_intelligence_report_hook() );
uox_assert( is_int( $primeiro ) && $primeiro > time(), 'O primeiro disparo é no futuro, não no passado' );
uox_assert(
	'weekly' === ( $GLOBALS['uox_cron_rec'][ uonix_intelligence_report_hook() ] ?? null ),
	'A recorrência gravada é weekly: disparo único disfarçado de semanal enviaria o relatório uma vez e nunca mais'
);
// Dia E hora, no fuso do site, na mesma asserção: verificar só o dia deixaria
// passar tanto uma troca de horário quanto o uso de UTC em vez do fuso do site,
// porque segunda 08:00 UTC continua sendo segunda em São Paulo.
uox_assert(
	'Mon 08:00' === ( new DateTimeImmutable( '@' . $primeiro ) )->setTimezone( wp_timezone() )->format( 'D H:i' ),
	'O primeiro disparo é segunda-feira às 08:00 no fuso do site, e não em UTC'
);

// Idempotência: chamar de novo não duplica nem move a data.
uox_assert( false === uonix_intelligence_maybe_schedule_report(), 'Segunda chamada não reagenda' );
uox_assert(
	$primeiro === wp_next_scheduled( uonix_intelligence_report_hook() ),
	'A data do disparo não se move a cada requisição: reagendar sempre empurraria o envio para nunca'
);

// Destinatário removido: o evento sai, para o painel não prometer envio que não ocorre.
$GLOBALS['uox_options'][ uonix_intelligence_recipients_option() ] = array();
uox_assert( false === uonix_intelligence_maybe_schedule_report(), 'Sem destinatário, a função não agenda' );
uox_assert( false === wp_next_scheduled( uonix_intelligence_report_hook() ), 'Remover o último destinatário desagenda o evento' );
uox_assert(
	array( uonix_intelligence_report_hook() ) === $GLOBALS['uox_cron_cleared'],
	'A limpeza usa wp_clear_scheduled_hook, que remove ocorrências duplicadas, e é chamada uma única vez'
);

// Falha fechada: sem a recorrência weekly registrada, não agenda nada.
$GLOBALS['uox_options'][ uonix_intelligence_recipients_option() ] = array( 'operador@ksio.dev' );
$GLOBALS['uox_schedules'] = array( 'hourly' => array( 'interval' => 3600 ) );
uox_assert( false === uonix_intelligence_maybe_schedule_report(), 'Sem a recorrência weekly, não agenda' );
uox_assert(
	false === wp_next_scheduled( uonix_intelligence_report_hook() ),
	'Falha fechada: é melhor o painel dizer "não agendado" que criar um disparo único achando que é semanal'
);
$GLOBALS['uox_schedules'] = array( 'hourly' => array( 'interval' => 3600 ), 'weekly' => array( 'interval' => 604800 ) );

// Volta ao estado limpo para os testes seguintes deste arquivo.
$GLOBALS['uox_options'][ uonix_intelligence_recipients_option() ] = array();
$GLOBALS['uox_cron'] = array();
$GLOBALS['uox_cron_rec'] = array();
$GLOBALS['uox_cron_cleared'] = array();

// ---------------------------------------------------------------------------
// Rótulo de período: lido do snapshot, vazio quando não há como saber.
// ---------------------------------------------------------------------------
uox_assert( '23/08/2026 a 21/09/2026' === uonix_intelligence_report_period_label( uox_snapshot( array() ) ), 'Período é lido do snapshot' );
uox_assert( '' === uonix_intelligence_report_period_label( uox_snapshot( array(), false ) ), 'Snapshot sem intervalo não gera período inventado' );
uox_assert( '' === uonix_intelligence_report_period_label( false ), 'Ausência de snapshot não gera período inventado' );

// Snapshot COM o campo de período, mas com data que não parseia: é o segundo
// retorno antecipado, e sem este caso ele nunca roda em teste.
$periodo_corrompido = uox_snapshot( array() );
$periodo_corrompido['periods']['current']['start'] = 'nao-e-data';
uox_assert( '' === uonix_intelligence_report_period_label( $periodo_corrompido ), 'Data ilegível no snapshot não gera período inventado' );
$periodo_vazio = uox_snapshot( array() );
$periodo_vazio['periods']['current']['end'] = '';
uox_assert( '' === uonix_intelligence_report_period_label( $periodo_vazio ), 'Data vazia no snapshot não gera período inventado' );

$assunto_com = uonix_intelligence_report_subject( array( 'period_label' => '23/08/2026 a 21/09/2026' ) );
$assunto_sem = uonix_intelligence_report_subject( array( 'period_label' => '' ) );
uox_assert( false !== strpos( $assunto_com, '23/08/2026 a 21/09/2026' ), 'Assunto inclui o período quando conhecido' );
uox_assert( false === strpos( $assunto_sem, '(' ), 'Assunto sem período não deixa parêntese vazio' );

// ---------------------------------------------------------------------------
// #308: selo e assunto com a SEMANA do relatório; o período do snapshot vai para a
// procedência do bloco de SEO. Sem o contexto executivo, o selo antigo continua certo.
// ---------------------------------------------------------------------------
$exec_semana = array( 'scorecard' => array( 'windows' => array( 'week' => array( 'start' => '2026-09-24', 'end' => '2026-09-30', 'days' => 7 ) ) ) );
uox_assert( 'Semana de 24/09 a 30/09/2026' === uonix_intelligence_report_badge_label( $exec_semana, '01/09/2026 a 30/09/2026' ), '#308: com o contexto executivo, o selo é a semana do relatório; obteve ' . var_export( uonix_intelligence_report_badge_label( $exec_semana, 'x' ), true ) );
uox_assert( '01/09/2026 a 30/09/2026' === uonix_intelligence_report_badge_label( null, '01/09/2026 a 30/09/2026' ), '#308: sem o contexto executivo, o selo antigo (período do SEO)' );
$exec_virada = array( 'scorecard' => array( 'windows' => array( 'week' => array( 'start' => '2026-12-29', 'end' => '2027-01-04', 'days' => 7 ) ) ) );
uox_assert( 'Semana de 29/12/2026 a 04/01/2027' === uonix_intelligence_report_badge_label( $exec_virada, 'x' ), '#308: na virada de ano o selo mostra os dois anos (BAIXO 4 da revisão do PR #370); obteve ' . var_export( uonix_intelligence_report_badge_label( $exec_virada, 'x' ), true ) );
uox_assert( '01/09/2026 a 30/09/2026' === uonix_intelligence_report_badge_label( array( 'scorecard' => array( 'windows' => array() ) ), '01/09/2026 a 30/09/2026' ), '#308: janelas inválidas caem no selo antigo' );
uox_assert( '01/09/2026 a 30/09/2026' === uonix_intelligence_report_badge_label( array( 'scorecard' => array( 'windows' => array( 'week' => array( 'start' => 'x', 'end' => '2026-09-30' ) ) ) ), '01/09/2026 a 30/09/2026' ), '#308: semana com data ilegível cai no selo antigo' );
uox_assert( '23/08 a 21/09 (30 dias)' === uonix_intelligence_report_seo_window_label( uox_snapshot( array() ) ), '#308: o período do snapshot de SEO, com os dias; obteve ' . var_export( uonix_intelligence_report_seo_window_label( uox_snapshot( array() ) ), true ) );
uox_assert( '' === uonix_intelligence_report_seo_window_label( uox_snapshot( array(), false ) ) && '' === uonix_intelligence_report_seo_window_label( false ), '#308: sem período no snapshot, nada inventado' );
$invertido = uox_snapshot( array() );
$invertido['periods']['current'] = array( 'start' => '2026-09-21', 'end' => '2026-08-23' );
uox_assert( '' === uonix_intelligence_report_seo_window_label( $invertido ), '#308: janela com o fim antes do início não vira "(-28 dias)"' );
$assunto_semana = uonix_intelligence_report_subject( array( 'period_label' => uonix_intelligence_report_badge_label( $exec_semana, '01/09/2026 a 30/09/2026' ) ) );
uox_assert( false !== strpos( $assunto_semana, '(Semana de 24/09 a 30/09/2026)' ) && false === strpos( $assunto_semana, '01/09/2026' ), '#308: o assunto leva a semana, não o período do SEO; obteve ' . $assunto_semana );
// A ligação: o contexto monta o selo pela semana e guarda o período do SEO à parte.
$ctx_semana = uonix_intelligence_report_context( array( 'snapshot' => uox_snapshot( array() ), 'executive' => $exec_semana ) );
uox_assert( 'Semana de 24/09 a 30/09/2026' === ( $ctx_semana['period_label'] ?? '' ) && '23/08 a 21/09 (30 dias)' === ( $ctx_semana['seo_period_label'] ?? '' ), '#308: o contexto usa a semana no selo e guarda o período do SEO; obteve ' . var_export( array( $ctx_semana['period_label'] ?? null, $ctx_semana['seo_period_label'] ?? null ), true ) );
$ctx_antigo = uonix_intelligence_report_context( array( 'snapshot' => uox_snapshot( array() ), 'executive' => null ) );
uox_assert( '23/08/2026 a 21/09/2026' === ( $ctx_antigo['period_label'] ?? '' ), '#308: sem o contexto executivo, o contexto mantém o selo antigo' );
$html_seo = uonix_intelligence_report_html( array( 'analysis' => uox_analysis( array() ), 'period_label' => 'Semana de 24/09 a 30/09/2026', 'seo_period_label' => '23/08 a 21/09 (30 dias)', 'environment' => 'production', 'panel_url' => '' ) );
uox_assert( false !== strpos( $html_seo, 'Fonte: Search Console · 23/08 a 21/09 (30 dias) · sincronizado em' ), '#308: a procedência do bloco de SEO declara o período dele' );
uox_assert( false !== strpos( $html_seo, '>Semana de 24/09 a 30/09/2026<' ), '#308: o selo do cabeçalho mostra a semana' );

// ---------------------------------------------------------------------------
// #291: o e-mail diz quantas oportunidades ficaram no painel, e só quando há resto.
// O número é o que o painel mostra a mais (ele lista até `panel_limit`), nunca o universo.
// ---------------------------------------------------------------------------
$tres = array(
	array( 'query' => 'um', 'position' => 9.0, 'impressions' => 90, 'ctr' => 0, 'clicks' => 0 ),
	array( 'query' => 'dois', 'position' => 9.0, 'impressions' => 80, 'ctr' => 0, 'clicks' => 0 ),
	array( 'query' => 'tres', 'position' => 9.0, 'impressions' => 70, 'ctr' => 0, 'clicks' => 0 ),
);
$resto = static function ( $rows, $matched, $universe = null, $painel = 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics&tab=intelligence', $limite = 5 ) {
	$a = uox_analysis( $rows );
	if ( null !== $matched ) {
		$a['matched'] = $matched;
	}
	if ( null !== $universe ) {
		$a['universe'] = $universe;
	}
	return uonix_intelligence_report_html( array( 'analysis' => $a, 'period_label' => '', 'environment' => 'production', 'panel_url' => $painel, 'panel_limit' => $limite ) );
};
$h = $resto( $tres, 5 );
uox_assert( false !== strpos( $h, 'Mais 2 oportunidades no painel.' ), '#291: 5 oportunidades e 3 no e-mail: "Mais 2 oportunidades no painel."' );
uox_assert( 1 === substr_count( $h, 'Mais 2 oportunidades no painel.' ) && 1 === preg_match( '#<a href="https://uonix\.com\.br/wp-admin/admin\.php\?page=uonix-analytics&tab=intelligence"[^>]*>Mais 2 oportunidades no painel\.</a>#', $h ), '#291: a frase leva ao painel' );
uox_assert( false !== strpos( $resto( $tres, 4 ), 'Mais 1 oportunidade no painel.' ), '#291: singular com 1 a mais' );
$sem_resto = $resto( $tres, 3 );
uox_assert( false === strpos( $sem_resto, 'Mais ' ) && false === strpos( $sem_resto, 'oportunidades no painel' ), '#291: sem resto, a frase não aparece' );
$muitas = $resto( $tres, 12, 113 );
uox_assert( false !== strpos( $muitas, 'Mais 2 oportunidades no painel.' ) && false === strpos( $muitas, 'Mais 9' ) && false === strpos( $muitas, 'Mais 110' ), '#291: com 12 oportunidades o painel mostra 5, então "Mais 2", nunca o resto bruto nem o universo' );
uox_assert( false !== strpos( $resto( $tres, 5, null, '' ), 'Mais 2 oportunidades no painel.' ) && false === strpos( $resto( $tres, 5, null, '' ), 'href=""' ), '#291: sem URL do painel, a frase sai sem link quebrado' );
uox_assert( false === strpos( $resto( $tres, null ), 'Mais ' ), '#291: análise sem `matched` não inventa resto' );
$maliciosa = $resto( $tres, 5, null, 'https://uonix.com.br/wp-admin/admin.php?page=x"><script>alert(1)</script>' );
uox_assert( false !== strpos( $maliciosa, 'Mais 2 oportunidades no painel.' ) && false === strpos( $maliciosa, '<script>' ), '#291: a URL do painel no link da frase é escapada' );
uox_assert( false === strpos( $resto( $tres, 5, null, 'https://x', 0 ), 'Mais ' ), '#291: sem o limite do painel no contexto, não inventa resto' );
$vazia = $resto( array(), 0 );
uox_assert( false !== strpos( $vazia, 'Nenhuma consulta atendeu aos critérios' ) && false === strpos( $vazia, 'Mais ' ), '#291: sem oportunidade, a mensagem de vazio continua e a frase não aparece' );
// MÉDIO 1 da revisão do PR #370: o contexto CONSOME email_limit, e a frase sai de um contexto
// real, não de uma análise montada à mão.
$cinco = array();
foreach ( range( 1, 5 ) as $i ) {
	$cinco[] = uox_q( 'consulta ' . $i, 8.0, 100 - $i, .0 );
}
$ctx_cinco = uonix_intelligence_report_context( array( 'snapshot' => uox_snapshot( $cinco ), 'executive' => null ) );
uox_assert( 3 === count( $ctx_cinco['analysis']['rows'] ?? array() ) && 5 === ( $ctx_cinco['analysis']['matched'] ?? null ), '#291: o contexto corta em email_limit (3) e guarda matched (5); obteve ' . count( $ctx_cinco['analysis']['rows'] ?? array() ) );
uox_assert( false !== strpos( uonix_intelligence_report_html( $ctx_cinco ), 'Mais 2 oportunidades no painel.' ), '#291: o e-mail de um contexto real com 5 oportunidades diz "Mais 2"' );
$ctx_limite = uonix_intelligence_report_context( array( 'snapshot' => uox_snapshot( array() ), 'executive' => null ) );
uox_assert( 5 === ( $ctx_limite['panel_limit'] ?? null ), '#291: o contexto leva o limite do painel das regras do 55' );

// ---------------------------------------------------------------------------
// #351: o rodapé só diz que o envio depende de tráfego quando o WP-Cron roda por visita.
// ---------------------------------------------------------------------------
$rod_visita   = uonix_intelligence_report_html( array( 'analysis' => uox_analysis( array() ), 'period_label' => '', 'environment' => 'production', 'panel_url' => '', 'cron_by_visit' => true ) );
$rod_servidor = uonix_intelligence_report_html( array( 'analysis' => uox_analysis( array() ), 'period_label' => '', 'environment' => 'production', 'panel_url' => '', 'cron_by_visit' => false ) );
uox_assert( false !== strpos( $rod_visita, 'depende de tráfego no site' ), '#351: com WP-Cron por visita, o rodapé mantém a frase do tráfego' );
uox_assert( false === strpos( $rod_servidor, 'tráfego' ) && false !== strpos( $rod_servidor, 'não depende de visitas ao site' ), '#351: sem WP-Cron por visita, o rodapé diz que o envio não depende de visitas, e não fala em tráfego' );
uox_assert( false === strpos( $rod_servidor, 'servidor roda' ) && false === strpos( $rod_servidor, 'pontual' ), '#351: o rodapé não promete um agendador que o PHP não enxerga' );
uox_assert( true === ( uonix_intelligence_report_context( array( 'snapshot' => false, 'executive' => null ) )['cron_by_visit'] ?? null ), '#351: o contexto lê o modo do cron (sem a constante, por visita)' );

// ---------------------------------------------------------------------------
// Corpo HTML com dados.
// ---------------------------------------------------------------------------
$html = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'linha de vida nbr', 'position' => 6.4, 'impressions' => 820, 'ctr' => .012, 'clicks' => 10 ) ) ),
	'period_label' => '23/08/2026 a 21/09/2026',
	'environment' => 'production',
	'panel_url' => 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics',
) );
uox_assert( false !== strpos( $html, '#0b1c2c' ), 'Cabeçalho usa a cor da identidade definida no contrato' );
uox_assert( false !== strpos( $html, '23/08/2026 a 21/09/2026' ), 'Badge de período aparece no corpo' );
uox_assert( false !== strpos( $html, 'linha de vida nbr' ), 'Consulta aparece na tabela do e-mail' );
uox_assert( false === strpos( $html, 'Acrescentar ao título' ), 'A sugestão determinística não aparece mais no e-mail (#309)' );
$GLOBALS['uox_ia'] = array( 'linha de vida nbr' => array( 'status' => 'ok', 'title' => 'Linha de Vida <b>NBR</b>', 'description' => 'x', 'current_title' => 'y', 'current_description' => '', 'generated_at' => '', 'post_id' => 1 ) );
$html_ia = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'linha de vida nbr', 'position' => 6.4, 'impressions' => 820, 'ctr' => .012, 'clicks' => 10, 'target_page' => '/servico/linha-de-vida/' ) ) ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics',
) );
uox_assert( false !== strpos( $html_ia, 'Página: /servico/linha-de-vida/' ), 'E-mail mostra a página líder' );
// #343: endereço antigo que redireciona. O título sugerido é da página real, então o e-mail
// mostra o destino ao lado do endereço que a Search Console reportou.
$GLOBALS['uox_ia'] = array( 'teste predial' => array( 'status' => 'ok', 'title' => 'Ensaio de Arrancamento | Uônix', 'description' => 'x', 'current_title' => 'y', 'current_description' => '', 'generated_at' => '', 'post_id' => 15, 'redirected_to' => '/servico/ensaios-de-arrancamento/' ) );
$html_301 = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'teste predial', 'position' => 7.4, 'impressions' => 23, 'ctr' => 0, 'clicks' => 0, 'target_page' => '/teste-de-arrancamento' ) ) ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => '',
) );
uox_assert( false !== strpos( $html_301, 'Página: /teste-de-arrancamento → /servico/ensaios-de-arrancamento/' ), '#343: o e-mail mostra o destino do 301 ao lado do endereço antigo' );
$GLOBALS['uox_ia'] = array( 'linha de vida nbr' => array( 'status' => 'ok', 'title' => 'Linha de Vida <b>NBR</b>', 'description' => 'x', 'current_title' => 'y', 'current_description' => '', 'generated_at' => '', 'post_id' => 1 ) );
uox_assert( false !== strpos( $html_ia, 'Título sugerido (IA): Linha de Vida &lt;b&gt;NBR&lt;/b&gt;' ), 'E-mail mostra o título sugerido, escapado' );
$GLOBALS['uox_ia'] = array( 'linha de vida nbr' => array( 'status' => 'unavailable' ) );
$html_sem = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'linha de vida nbr', 'position' => 6.4, 'impressions' => 820, 'ctr' => .012, 'clicks' => 10, 'target_page' => null ) ) ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => '',
) );
uox_assert( false === strpos( $html_sem, 'Título sugerido' ) && false === strpos( $html_sem, 'Página:' ) && false === strpos( $html_sem, 'unavailable' ), 'Sem sugestão nem página, o e-mail omite as linhas, sem motivo técnico' );
$GLOBALS['uox_ia'] = array();
uox_assert( false !== strpos( $html, 'Fonte: Search Console' ), 'Bloco do e-mail declara a fonte' );
uox_assert( false !== strpos( $html, 'sincronizado em' ), 'Bloco do e-mail declara o horário de sincronização' );
uox_assert( false !== strpos( $html, '<table' ) && false !== strpos( $html, 'role="presentation"' ), 'Layout usa tabela, necessário para Outlook' );
uox_assert( false === strpos( $html, 'display:flex' ), 'Layout não depende de flexbox, que Outlook ignora' );
uox_assert( false === strpos( $html, 'Ambiente:' ), 'Em produção o e-mail não carrega aviso de ambiente' );

// A URL do painel vai para um atributo href. Ela vem de admin_url(), mas o escape
// precisa existir e ser exercitado, senão trocá-lo por concatenação crua fica verde.
$html_url = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array() ),
	'period_label' => '',
	'environment' => 'production',
	'panel_url' => 'https://uonix.com.br/wp-admin/admin.php?page=x"><script>alert(1)</script>',
) );
uox_assert( false === strpos( $html_url, '"><script>' ), 'URL do painel não escapa do atributo href' );
uox_assert( false !== strpos( $html_url, 'href=' ), 'Link do painel continua sendo renderizado' );

// Ambiente não produtivo é declarado no rodapé.
$html_qa = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array() ),
	'period_label' => '',
	'environment' => 'staging',
	'panel_url' => '',
) );
uox_assert( false !== strpos( $html_qa, 'Ambiente: STAGING' ), 'Fora de produção o e-mail declara o ambiente' );
uox_assert( false !== strpos( $html_qa, 'não produtiva' ), 'Fora de produção o e-mail avisa que a mensagem não é produtiva' );

// ---------------------------------------------------------------------------
// Escape: a consulta vem do Search Console.
// ---------------------------------------------------------------------------
$html_xss = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'olhal <script>alert(1)</script> "x"', 'position' => 6.0, 'impressions' => 500, 'ctr' => .01, 'clicks' => 1 ) ) ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => '',
) );
uox_assert( false === strpos( $html_xss, '<script>alert(1)</script>' ), 'Consulta com tag não vai crua para o e-mail' );
uox_assert( false !== strpos( $html_xss, '&lt;script&gt;' ), 'Tag da consulta é escapada no e-mail' );

// ---------------------------------------------------------------------------
// Indisponível e vazio: mensagens distintas, sem vazar código interno.
// ---------------------------------------------------------------------------
$html_indisp = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array(), false, 'queries_extended_missing', true ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => '',
) );
uox_assert( false !== strpos( $html_indisp, 'universo ampliado de consultas' ), 'E-mail indisponível explica o motivo em linguagem de operador' );
uox_assert( false === strpos( $html_indisp, 'queries_extended_missing' ), 'E-mail não vaza o código interno do motivo' );
uox_assert( false !== strpos( $html_indisp, 'dado desatualizado' ), 'E-mail declara quando o dado está desatualizado' );

$html_vazio = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array() ),
	'period_label' => '', 'environment' => 'production', 'panel_url' => '',
) );
uox_assert( false !== strpos( $html_vazio, 'não há ganho fácil' ), 'Zero oportunidades é apresentado como resultado no e-mail' );
uox_assert( false === strpos( $html_vazio, 'universo ampliado' ), 'Zero oportunidades não é confundido com indisponibilidade' );

// ---------------------------------------------------------------------------
// Envio: sem destinatário não envia, e diz por quê.
// ---------------------------------------------------------------------------
$GLOBALS['uox_mail_calls'] = array();
$GLOBALS['uox_options'] = array();
$sem = uonix_intelligence_send_report();
uox_assert( false === $sem['sent'] && 'no_recipients' === $sem['reason'] && 0 === $sem['recipients'], 'Sem destinatário o envio é recusado com motivo' );
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Sem destinatário wp_mail não é chamado' );

$GLOBALS['uox_options'] = array( uonix_intelligence_recipients_option() => array( 'cassio@uonix.com.br' ) );
$GLOBALS['uox_mail_calls'] = array();
$GLOBALS['uox_mail_result'] = true;
$ok = uonix_intelligence_send_report();
uox_assert( true === $ok['sent'] && 1 === $ok['recipients'], 'Com destinatário o envio é reportado como concluído' );
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'wp_mail é chamado uma vez' );
uox_assert( array( 'cassio@uonix.com.br' ) === $GLOBALS['uox_mail_calls'][0]['to'], 'Destinatários vêm da lista salva' );
uox_assert( in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['uox_mail_calls'][0]['headers'], true ), 'Envio declara corpo HTML' );
uox_assert( false !== strpos( $GLOBALS['uox_mail_calls'][0]['message'], '<html' ), 'Corpo enviado é o HTML do relatório' );

$GLOBALS['uox_mail_result'] = false;
$GLOBALS['uox_mail_calls'] = array();
$falhou = uonix_intelligence_send_report();
uox_assert( false === $falhou['sent'] && 'mail_failed' === $falhou['reason'], 'Falha de transporte é reportada como mail_failed' );
$GLOBALS['uox_mail_result'] = true;

// Lista explícita tem precedência sobre a salva.
$GLOBALS['uox_mail_calls'] = array();
uonix_intelligence_send_report( array( 'outro@uonix.com.br' ) );
uox_assert( array( 'outro@uonix.com.br' ) === $GLOBALS['uox_mail_calls'][0]['to'], 'Lista informada explicitamente é usada' );

// ---------------------------------------------------------------------------
// Handler do envio de teste: só o dono do ksio.dev, e nonce.
// ---------------------------------------------------------------------------
// Administrador com o Insights liberado, mas que não é o dono.
$GLOBALS['uox_can']            = true;
$GLOBALS['uox_ksio_pode']      = true;
$GLOBALS['uox_dono']           = false;
$GLOBALS['uox_dono_consultas'] = 0;
$GLOBALS['uox_referer_ok']     = true;
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_mail_calls']     = array();
// Sem a guarda o handler enviaria e redirecionaria; o teste precisa reprovar pela
// asserção, não por exceção não capturada.
$interrompeu = false;
try {
	uonix_intelligence_handle_test_send();
} catch ( Uox_Die_Exception $e ) {
	$interrompeu = true;
} catch ( Uox_Redirect_Exception $e ) {
	$interrompeu = false;
}
uox_assert( $interrompeu, 'Envio de teste de quem não é o dono deveria interromper' );
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Envio de teste de quem não é o dono não dispara e-mail' );
uox_assert( $GLOBALS['uox_dono_consultas'] > 0, 'Envio de teste consulta a regra do dono' );
uox_assert( null === $GLOBALS['uox_referer_action'], 'A guarda do dono vem antes do nonce' );
$GLOBALS['uox_dono'] = true;

$GLOBALS['uox_referer_ok'] = false;
$GLOBALS['uox_mail_calls'] = array();
try {
	uonix_intelligence_handle_test_send();
	uox_assert( false, 'Envio de teste sem nonce deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Envio de teste sem nonce interrompe' );
}
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Envio de teste sem nonce não dispara e-mail' );

$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_mail_calls'] = array();
$redirect = '';
try {
	uonix_intelligence_handle_test_send();
} catch ( Uox_Redirect_Exception $e ) {
	$redirect = (string) $e->url;
}
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'Envio de teste válido dispara um e-mail' );
uox_assert( false !== strpos( $redirect, 'uonix_test_sent=1' ), 'Redirect informa que o envio saiu' );
uox_assert( false !== strpos( $redirect, 'tab=settings' ), 'Redirect volta para a aba de configurações' );

// ---------------------------------------------------------------------------
// Módulo 8: "Pautas novas para o blog", e a marcação só no envio agendado real.
// ---------------------------------------------------------------------------
function uox_radar_cand( $consulta, $caminho = 'nova', $status = 'ok' ) {
	$ai = 'ok' === $status
		? array( 'status' => 'ok', 'input_hash' => str_repeat( 'a', 64 ), 'suggestion' => array( 'caminho' => $caminho, 'titulo' => 'Pauta <' . $consulta . '>', 'angulo' => 'Ângulo & ' . $consulta . '.', 'intencao' => 'informacional' ) )
		: array( 'status' => $status );
	return array(
		'key'         => uonix_intelligence_radar_query_key( $consulta ),
		'query'       => $consulta,
		'impressions' => 106,
		'clicks'      => 1,
		'position'    => 59.1,
		'page'        => array( 'path' => '/norma-ancoragem-predial', 'kind' => 'página', 'title' => 'Norma de Ancoragem Predial', 'redirected_to' => '' ),
		'ai'          => $ai,
	);
}
function uox_radar_opcoes( array $cands ) {
	return array(
		uonix_intelligence_recipients_option() => array( 'cassio@uonix.com.br' ),
		uonix_intelligence_radar_option()       => array( 'status' => 'ok', 'updated_at' => '2026-10-05T03:00:00+00:00', 'list_updated_at' => '2026-10-05T03:00:00+00:00', 'window' => array( 'start' => '2026-07-05', 'end' => '2026-10-02' ), 'truncated' => false, 'candidates' => $cands ),
	);
}
$GLOBALS['uox_options'] = uox_radar_opcoes( array( uox_radar_cand( 'ancoragem predial', 'reforcar' ), uox_radar_cand( 'teste de ancoragem' ), uox_radar_cand( 'ancoragem estrutural' ), uox_radar_cand( 'barra roscada' ) ) );
$html_radar = uonix_intelligence_report_html( uonix_intelligence_report_context( array( 'snapshot' => false, 'executive' => null ) ) );
uox_assert( false !== strpos( $html_radar, 'Pautas novas para o blog' ), 'Com pauta nova, o e-mail traz o bloco do Radar' );
uox_assert( 3 === substr_count( $html_radar, 'Pauta &lt;' ) && false === strpos( $html_radar, 'Pauta <' ), 'No máximo 3 pautas, com o título escapado' );
uox_assert( false !== strpos( $html_radar, 'Reforçar: Norma de Ancoragem Predial' ) && false !== strpos( $html_radar, 'Post novo' ), 'O caminho aparece: reforço com o título da página, ou post novo' );
uox_assert( false !== strpos( $html_radar, 'Ângulo &amp; ancoragem predial.' ) && false !== strpos( $html_radar, 'Consulta: ancoragem predial · posição 59,1 · 106 impressões em 90 dias' ), 'Ângulo e números aparecem, escapados' );
uox_assert( false !== strpos( $html_radar, 'Mais 1 pauta no painel.' ), 'Diz quantas ficaram no painel' );
uox_assert( strpos( $html_radar, 'Pautas novas para o blog' ) > strpos( $html_radar, 'Oportunidades de busca a um passo do topo' ), 'O bloco vem depois das oportunidades' );
$GLOBALS['uox_options'] = uox_radar_opcoes( array( uox_radar_cand( 'ancoragem predial', 'nova', 'unavailable' ) ) );
uox_assert( false === strpos( uonix_intelligence_report_html( uonix_intelligence_report_context( array( 'snapshot' => false, 'executive' => null ) ) ), 'Pautas novas' ), 'Sem pauta pronta, o bloco não aparece' );
uox_assert( '' === uonix_intelligence_report_radar_html( array( 'content_radar' => 'lixo' ) ) && '' === uonix_intelligence_report_radar_html( array( 'content_radar' => array( 'items' => 'lixo' ) ) ) && '' === uonix_intelligence_report_radar_html( array() ), 'Contexto malformado não produz bloco (foco de revisão 4)' );
$html_lixo = uonix_intelligence_report_radar_html( array( 'content_radar' => array( 'items' => array( array( 'key' => str_repeat( 'a', 64 ), 'query' => 'x y', 'impressions' => 9, 'position' => 20.0, 'page' => 'lixo', 'ai' => array( 'status' => 'ok', 'suggestion' => 'lixo' ) ) ), 'panel_count' => 1 ) ) );
uox_assert( false !== strpos( $html_lixo, 'Consulta: x y' ) && false === strpos( $html_lixo, 'Post novo' ), 'Item com sugestão malformada sai só com a consulta e os números' );

$GLOBALS['uox_options']     = uox_radar_opcoes( array( uox_radar_cand( 'ancoragem predial' ), uox_radar_cand( 'teste de ancoragem' ) ) );
$chaves_radar               = array( uonix_intelligence_radar_query_key( 'ancoragem predial' ), uonix_intelligence_radar_query_key( 'teste de ancoragem' ) );
$GLOBALS['uox_mail_calls']  = array();
$GLOBALS['uox_mail_result'] = true;
$rr                         = uonix_intelligence_send_report();
uox_assert( true === $rr['sent'] && $chaves_radar === $rr['radar_keys'], 'O envio devolve as chaves das pautas que foram no corpo' );
uox_assert( array() === get_option( uonix_intelligence_radar_emailed_option(), array() ), 'send_report sozinho não marca: o envio de teste passa por ele' );
$GLOBALS['uox_mail_calls'] = array();
try {
	uonix_intelligence_handle_test_send();
} catch ( Uox_Redirect_Exception $e ) {
	unset( $e );
}
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ) && false !== strpos( $GLOBALS['uox_mail_calls'][0]['message'], 'Pautas novas para o blog' ), 'O envio de teste mostra o bloco' );
uox_assert( array() === get_option( uonix_intelligence_radar_emailed_option(), array() ), 'O envio de teste não gasta a novidade' );
$GLOBALS['uox_mail_result'] = false;
uonix_intelligence_send_scheduled_report();
uox_assert( array() === get_option( uonix_intelligence_radar_emailed_option(), array() ), 'Envio agendado que falha não marca' );
$GLOBALS['uox_mail_result'] = true;
$ra                         = uonix_intelligence_send_scheduled_report();
uox_assert( true === $ra['sent'] && $chaves_radar === array_keys( get_option( uonix_intelligence_radar_emailed_option(), array() ) ), 'Envio agendado real marca exatamente as pautas enviadas' );
$GLOBALS['uox_mail_calls'] = array();
uonix_intelligence_send_scheduled_report();
uox_assert( isset( $GLOBALS['uox_mail_calls'][0] ) && false === strpos( $GLOBALS['uox_mail_calls'][0]['message'], 'Pautas novas para o blog' ), 'Na semana seguinte, as mesmas pautas não voltam ao e-mail' );
uox_assert( 'uonix_intelligence_send_scheduled_report' === $GLOBALS['uox_actions'][ uonix_intelligence_report_hook() ]['callback'] && 0 === $GLOBALS['uox_actions'][ uonix_intelligence_report_hook() ]['accepted_args'], 'O evento semanal chama o envio agendado, sem argumentos' );
$GLOBALS['uox_options'] = array( uonix_intelligence_radar_option() => uox_radar_opcoes( array( uox_radar_cand( 'barra roscada' ) ) )[ uonix_intelligence_radar_option() ] );
$sem_dest               = uonix_intelligence_send_scheduled_report();
uox_assert( false === $sem_dest['sent'] && array() === $sem_dest['radar_keys'] && array() === get_option( uonix_intelligence_radar_emailed_option(), array() ), 'Sem destinatário: radar_keys vazio e nada marcado' );
$GLOBALS['uox_options']    = array( uonix_intelligence_recipients_option() => array( 'cassio@uonix.com.br' ) );
$GLOBALS['uox_mail_calls'] = array();

// ---------------------------------------------------------------------------
// Mensagens de falha explicam o caso mais provável em vez de culpar o operador.
// ---------------------------------------------------------------------------
uox_assert( false !== strpos( uonix_intelligence_test_send_message( 'no_recipients' ), 'Nenhum destinatário' ), 'Motivo no_recipients é explicado' );
uox_assert( false !== strpos( uonix_intelligence_test_send_message( 'mail_failed' ), 'UONIX_NONPROD_EMAIL_TO' ), 'Motivo mail_failed aponta o guard de ambiente, que é a causa provável em QA e DEV' );
uox_assert( 'O envio não foi concluído.' === uonix_intelligence_test_send_message( 'motivo_desconhecido' ), 'Motivo desconhecido não inventa explicação' );

// ---------------------------------------------------------------------------
// Botão de teste só aparece para quem pode enviar.
// ---------------------------------------------------------------------------
$_GET = array();
$GLOBALS['uox_can'] = true;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg = (string) ob_get_clean();
uox_assert( false !== strpos( $cfg, 'name="action" value="uonix_intelligence_send_test"' ), 'Aba de configurações traz o botão de envio de teste' );
uox_assert( false !== strpos( $cfg, 'Enviar Teste Agora' ), 'Botão usa o rótulo definido no plano' );
uox_assert( false === strpos( $cfg, 'uonix-license-notice' ), 'Sem constante de licença a aba de configurações não mostra aviso de licença' );
// A ação do nonce do envio de teste tem que ser a mesma nas duas pontas, senão o
// botão recusa todo envio legítimo em silêncio.
uox_assert( in_array( 'uonix_intelligence_send_test', $GLOBALS['uox_nonce_actions'], true ), 'O formulário de teste emite a ação de nonce que o handler verifica' );
uox_assert( 'uonix_intelligence_send_test' === $GLOBALS['uox_referer_action'], 'O handler de envio de teste verifica a própria ação, não a de outro formulário' );

$GLOBALS['uox_can']  = true;
$GLOBALS['uox_dono'] = false;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_ro = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_ro, 'uonix_intelligence_send_test' ), 'Administrador que não é o dono não vê o botão de envio' );
$GLOBALS['uox_dono'] = true;

// Aviso de falha do envio de teste é exibido a partir da query.
$GLOBALS['uox_can'] = true;
$_GET = array( 'uonix_test_sent' => '0', 'uonix_test_reason' => 'mail_failed' );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_falha = (string) ob_get_clean();
uox_assert( false !== strpos( $cfg_falha, 'UONIX_NONPROD_EMAIL_TO' ), 'Falha do envio de teste é explicada na tela' );
$_GET = array();

// ---------------------------------------------------------------------------
// Licença suspensa no painel: a opção basta para nada sair, nem o envio de teste.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options']     = array(
	uonix_intelligence_recipients_option() => array( 'cassio@uonix.com.br' ),
	'uonix_intelligence_license'           => array( 'status' => 'suspended', 'valid_until' => '' ),
);
$GLOBALS['uox_mail_result'] = true;
$GLOBALS['uox_mail_calls']  = array();
$suspenso_painel = uonix_intelligence_send_report();
uox_assert( 'license_inactive' === $suspenso_painel['reason'] && array() === $GLOBALS['uox_mail_calls'], 'Com a licença suspensa no painel o relatório não é enviado; obteve ' . var_export( $suspenso_painel, true ) );
$redirect = '';
try {
	uonix_intelligence_handle_test_send();
} catch ( Uox_Redirect_Exception $e ) {
	$redirect = (string) $e->url;
}
uox_assert( array() === $GLOBALS['uox_mail_calls'] && false !== strpos( $redirect, 'uonix_test_reason=license_inactive' ), 'Com a licença suspensa no painel o envio de teste também não sai; redirect: ' . $redirect );
unset( $GLOBALS['uox_options']['uonix_intelligence_license'] );
uonix_intelligence_send_report();
uox_assert( 1 === count( $GLOBALS['uox_mail_calls'] ), 'Sem a opção do painel o relatório volta a sair' );

// ---------------------------------------------------------------------------
// Licença suspensa: nada sai, nem o envio de teste, e a tela diz por quê.
// Fica no fim porque constante não se desfaz.
// ---------------------------------------------------------------------------
define( 'KSIODEV_INTELLIGENCE_STATUS', 'suspended' );
$GLOBALS['uox_options']     = array( uonix_intelligence_recipients_option() => array( 'cassio@uonix.com.br' ) );
$GLOBALS['uox_mail_result'] = true;
$GLOBALS['uox_mail_calls']  = array();
$suspenso = uonix_intelligence_send_report();
uox_assert( false === $suspenso['sent'] && 'license_inactive' === $suspenso['reason'], 'Com a licença suspensa o relatório não é enviado; obteve ' . var_export( $suspenso, true ) );
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Com a licença suspensa wp_mail não é chamado' );

$GLOBALS['uox_can']        = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_ksio_pode']  = true;
$GLOBALS['uox_mail_calls'] = array();
$redirect                  = '';
try {
	uonix_intelligence_handle_test_send();
} catch ( Uox_Redirect_Exception $e ) {
	$redirect = (string) $e->url;
}
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Com a licença suspensa o envio de teste também não dispara e-mail' );
uox_assert( false !== strpos( $redirect, 'uonix_test_sent=0' ) && false !== strpos( $redirect, 'uonix_test_reason=license_inactive' ), 'O envio de teste volta com o motivo license_inactive; redirect: ' . $redirect );

$_GET = array( 'uonix_test_sent' => '0', 'uonix_test_reason' => 'license_inactive' );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_suspenso = (string) ob_get_clean();
$_GET         = array();
uox_assert( false !== strpos( $cfg_suspenso, 'uonix-license-notice' ), 'Com a licença suspensa a aba de configurações mostra o aviso' );
uox_assert( false !== strpos( $cfg_suspenso, 'fale com a ksio.dev' ), 'O aviso manda falar com a ksio.dev' );
uox_assert( false !== strpos( $cfg_suspenso, 'a licença da Central de Inteligência está inativa' ), 'O resultado do envio de teste explica que a licença está inativa' );

// ---------------------------------------------------------------------------
// #351 no painel de Configurações. Fica no fim porque constante não se desfaz.
// ---------------------------------------------------------------------------
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_visita = (string) ob_get_clean();
uox_assert( false !== strpos( $cfg_visita, 'depende de tráfego no site' ), '#351: sem DISABLE_WP_CRON, as Configurações mantêm a frase do tráfego' );
define( 'DISABLE_WP_CRON', true );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_servidor = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_servidor, 'depende de tráfego' ) && false !== strpos( $cfg_servidor, 'DISABLE_WP_CRON' ) && false !== strpos( $cfg_servidor, 'agendador do servidor' ), '#351: com DISABLE_WP_CRON, as Configurações dizem que o envio depende do agendador do servidor' );
uox_assert( false === uonix_intelligence_report_context( array( 'snapshot' => false, 'executive' => null ) )['cron_by_visit'], '#351: com DISABLE_WP_CRON, o contexto do e-mail sai sem WP-Cron por visita' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: relatório executivo montado com procedência e enviado apenas com destinatário, licença, dono e nonce.\n";
