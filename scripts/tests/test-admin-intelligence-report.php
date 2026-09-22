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

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/57-admin-intelligence-report.php';

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

// Sem destinatário, não agenda — e a lista vazia é o estado inicial.
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
// Corpo HTML com dados.
// ---------------------------------------------------------------------------
$html = uonix_intelligence_report_html( array(
	'analysis' => uox_analysis( array( array( 'query' => 'linha de vida nbr', 'position' => 6.4, 'impressions' => 820, 'ctr' => .012, 'clicks' => 10, 'suggestion' => array( 'Aço Inox 304/316' ) ) ) ),
	'period_label' => '23/08/2026 a 21/09/2026',
	'environment' => 'production',
	'panel_url' => 'https://uonix.com.br/wp-admin/admin.php?page=uonix-analytics',
) );
uox_assert( false !== strpos( $html, '#0b1c2c' ), 'Cabeçalho usa a cor da identidade definida no contrato' );
uox_assert( false !== strpos( $html, '23/08/2026 a 21/09/2026' ), 'Badge de período aparece no corpo' );
uox_assert( false !== strpos( $html, 'linha de vida nbr' ), 'Consulta aparece na tabela do e-mail' );
uox_assert( false !== strpos( $html, 'Aço Inox 304/316' ), 'Sugestão de título aparece no e-mail' );
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
	'analysis' => uox_analysis( array( array( 'query' => 'olhal <script>alert(1)</script> "x"', 'position' => 6.0, 'impressions' => 500, 'ctr' => .01, 'clicks' => 1, 'suggestion' => array() ) ) ),
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
// Handler do envio de teste: capability e nonce.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = false;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_mail_calls'] = array();
try {
	uonix_intelligence_handle_test_send();
	uox_assert( false, 'Envio de teste sem manage_options deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Envio de teste sem manage_options interrompe' );
}
uox_assert( array() === $GLOBALS['uox_mail_calls'], 'Envio de teste sem manage_options não dispara e-mail' );

$GLOBALS['uox_can'] = true;
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
// A ação do nonce do envio de teste tem que ser a mesma nas duas pontas, senão o
// botão recusa todo envio legítimo em silêncio.
uox_assert( in_array( 'uonix_intelligence_send_test', $GLOBALS['uox_nonce_actions'], true ), 'O formulário de teste emite a ação de nonce que o handler verifica' );
uox_assert( 'uonix_intelligence_send_test' === $GLOBALS['uox_referer_action'], 'O handler de envio de teste verifica a própria ação, não a de outro formulário' );

$GLOBALS['uox_can'] = false;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_ro = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_ro, 'uonix_intelligence_send_test' ), 'Sem manage_options o botão de envio não é renderizado' );

// Aviso de falha do envio de teste é exibido a partir da query.
$GLOBALS['uox_can'] = true;
$_GET = array( 'uonix_test_sent' => '0', 'uonix_test_reason' => 'mail_failed' );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_falha = (string) ob_get_clean();
uox_assert( false !== strpos( $cfg_falha, 'UONIX_NONPROD_EMAIL_TO' ), 'Falha do envio de teste é explicada na tela' );
$_GET = array();

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: relatório executivo montado com procedência e enviado apenas com destinatário, capability e nonce.\n";
