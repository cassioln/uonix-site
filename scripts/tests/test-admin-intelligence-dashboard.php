<?php
/**
 * Testes da Central de Inteligência — render das abas e destinatários.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$failures = 0;
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_cron'] = array();
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_nonce_field_action'] = null;

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

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
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
// Registra a ação verificada para o teste confrontá-la com a emitida pelo
// formulário. Stub que ignora o argumento deixaria passar uma divergência que
// recusaria 100% das gravações legítimas em produção, em silêncio.
function check_admin_referer( $action = -1 ) { $GLOBALS['uox_referer_action'] = $action; if ( ! $GLOBALS['uox_referer_ok'] ) { throw new Uox_Die_Exception( 'nonce' ); } return true; }
function wp_die( $message = '', $title = '', $args = array() ) { throw new Uox_Die_Exception( (string) $message ); }
function wp_safe_redirect( $url ) { throw new Uox_Redirect_Exception( $url ); }

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
function esc_url( $url ) { return (string) $url; }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action = -1 ) { $GLOBALS['uox_nonce_field_action'] = $action; echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook ) { $GLOBALS['uox_cron'][ $hook ] = $ts; return true; }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : (int) $ts ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';

function uox_snapshot( array $universe, $updated_at = null, $period = 30 ) {
	return array(
		'version' => 3,
		'period_days' => $period,
		'status' => 'updated',
		'updated_at' => null === $updated_at ? gmdate( 'c' ) : $updated_at,
		'ga4' => array(),
		'search_console' => array( 'queries' => array(), 'queries_extended' => $universe, 'pages' => array() ),
	);
}
function uox_q( $query, $position, $impressions, $ctr ) {
	return array( 'query' => $query, 'clicks' => 1, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position );
}
function uox_render_intelligence( $tab, ?array $snapshot = null ) {
	$GLOBALS['uox_options'] = array();
	if ( null !== $snapshot ) {
		$GLOBALS['uox_options'][ uonix_analytics_metrics_snapshot_option( 30 ) ] = $snapshot;
	}
	ob_start();
	uonix_intelligence_render_panel( $tab );
	return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// Allowlist de abas aceita as duas novas e continua fechada.
// ---------------------------------------------------------------------------
uox_assert( 'intelligence' === uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'intelligence' ) )['tab'], 'Aba intelligence é aceita pela allowlist' );
uox_assert( 'settings' === uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'settings' ) )['tab'], 'Aba settings é aceita pela allowlist' );
uox_assert( 'metrics' === uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'inexistente' ) )['tab'], 'Aba fora da allowlist volta ao padrão' );

// ---------------------------------------------------------------------------
// Painel com dado: tabela, formatação e procedência.
// ---------------------------------------------------------------------------
$_GET = array();
$html = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'linha de vida nbr', 6.4, 820, .012 ) ) ) );
uox_assert( false !== strpos( $html, 'id="uonix-panel-intelligence"' ), 'Painel usa o id que a aba referencia' );
uox_assert( false === strpos( $html, 'id="uonix-panel-intelligence" role="tabpanel" aria-labelledby="uonix-tab-intelligence" hidden' ), 'Painel da aba ativa não vem oculto' );
uox_assert( false !== strpos( $html, '<table' ), 'Painel com oportunidade renderiza tabela' );
uox_assert( false !== strpos( $html, 'linha de vida nbr' ), 'Consulta aparece na tabela' );
uox_assert( false !== strpos( $html, '6,4' ), 'Posição é formatada com uma decimal e vírgula' );
uox_assert( false !== strpos( $html, '820' ), 'Impressões aparecem' );
uox_assert( false !== strpos( $html, '1,20%' ), 'CTR é convertido de fração para percentual' );
uox_assert( false !== strpos( $html, 'Search Console' ), 'Bloco declara a fonte' );
uox_assert( false !== strpos( $html, 'Sincronizado em' ), 'Bloco declara o horário de sincronização' );
uox_assert( false !== strpos( $html, 'Dado atualizado' ), 'Snapshot fresco é rotulado como atualizado' );

// Procedência de bloco vencido: ele se declara sozinho.
$html_stale = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'olhal', 6.0, 500, .01 ) ), gmdate( 'c', time() - ( 4 * DAY_IN_SECONDS ) ) ) );
uox_assert( false !== strpos( $html_stale, 'Dado desatualizado' ), 'Bloco com snapshot vencido se declara desatualizado' );
uox_assert( false !== strpos( $html_stale, '<table' ), 'Bloco desatualizado ainda mostra o dado que tem' );

// ---------------------------------------------------------------------------
// Escape: a consulta vem do Search Console, ou seja, de fora.
// ---------------------------------------------------------------------------
$html_xss = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'olhal <script>alert(1)</script> "aspas"', 6.0, 500, .01 ) ) ) );
uox_assert( false === strpos( $html_xss, '<script>alert(1)</script>' ), 'Consulta com tag não é emitida sem escape' );
uox_assert( false !== strpos( $html_xss, '&lt;script&gt;' ), 'Tag da consulta é escapada' );
uox_assert( false !== strpos( $html_xss, '&quot;aspas&quot;' ), 'Aspas da consulta são escapadas' );

// ---------------------------------------------------------------------------
// Indisponível e vazio são estados distintos, com mensagens distintas.
// ---------------------------------------------------------------------------
$v2 = uox_snapshot( array( uox_q( 'olhal', 6.0, 500, .01 ) ) );
unset( $v2['search_console']['queries_extended'] );
$html_indisp = uox_render_intelligence( 'intelligence', $v2 );
uox_assert( false === strpos( $html_indisp, '<table' ), 'Painel indisponível não renderiza tabela' );
uox_assert( false !== strpos( $html_indisp, 'universo ampliado de consultas' ), 'Painel indisponível explica o motivo em linguagem de operador' );
uox_assert( false === strpos( $html_indisp, 'queries_extended_missing' ), 'Painel não vaza o código interno do motivo' );

$html_vazio = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'termo no topo', 1.2, 900, .40 ) ) ) );
uox_assert( false !== strpos( $html_vazio, 'não há ganho fácil' ), 'Zero oportunidades é apresentado como resultado, não como falha' );
uox_assert( false === strpos( $html_vazio, 'universo ampliado de consultas' ), 'Zero oportunidades não é confundido com indisponibilidade' );

// ---------------------------------------------------------------------------
// Fora da aba ativa, o painel começa oculto (comportamento sem JavaScript).
// ---------------------------------------------------------------------------
$html_oculto = uox_render_intelligence( 'metrics', uox_snapshot( array( uox_q( 'olhal', 6.0, 500, .01 ) ) ) );
uox_assert( 1 === preg_match( '#<section(?=[^>]*id="uonix-panel-intelligence")[^>]*\bhidden\b[^>]*>#', $html_oculto ), 'Painel fora da aba ativa vem com hidden' );

// ---------------------------------------------------------------------------
// Destinatários: normalização.
// ---------------------------------------------------------------------------
$n = uonix_intelligence_sanitize_recipients( "cassio@uonix.com.br\nCASSIO@uonix.com.br\ninvalido\n\ndiretoria@uonix.com.br" );
uox_assert( array( 'cassio@uonix.com.br', 'diretoria@uonix.com.br' ) === $n['recipients'], 'Duplicata sem diferenciar caixa é removida e válidos são preservados na ordem' );
uox_assert( 1 === $n['rejected'], 'Entrada inválida é contabilizada como recusada' );
uox_assert( array( 'recipients' => array(), 'rejected' => 0 ) === uonix_intelligence_sanitize_recipients( '' ), 'Texto vazio não gera destinatário nem recusa' );

$limite = uonix_intelligence_recipients_limit();
$muitos = array();
for ( $i = 1; $i <= $limite + 3; ++$i ) {
	$muitos[] = 'pessoa' . $i . '@uonix.com.br';
}
$n_limite = uonix_intelligence_sanitize_recipients( $muitos );
uox_assert( $limite === count( $n_limite['recipients'] ), 'Teto de destinatários é respeitado' );
uox_assert( 3 === $n_limite['rejected'], 'Excedentes do teto são contabilizados como recusados' );
uox_assert( array() === uonix_intelligence_sanitize_recipients( array( array( 'aninhado' ) ) )['recipients'], 'Valor não escalar é recusado' );

// ---------------------------------------------------------------------------
// Handler de destinatários: capability, nonce, persistência.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = false;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_options'] = array();
$_POST = array( 'uonix_recipients' => 'intruso@example.test' );
try {
	uonix_intelligence_save_recipients();
	uox_assert( false, 'Handler sem manage_options deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Handler sem manage_options interrompe' );
}
uox_assert( array() === $GLOBALS['uox_options'], 'Handler sem manage_options não grava' );

$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = false;
$GLOBALS['uox_options'] = array();
try {
	uonix_intelligence_save_recipients();
	uox_assert( false, 'Handler sem nonce deveria interromper' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, 'Handler sem nonce interrompe' );
}
uox_assert( array() === $GLOBALS['uox_options'], 'Handler sem nonce não grava' );

$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_options'] = array();
$_POST = array( 'uonix_recipients' => "cassio@uonix.com.br\nnaoeemail\ndiretoria@uonix.com.br" );
$redirect = '';
try {
	uonix_intelligence_save_recipients();
	uox_assert( false, 'Handler válido deveria redirecionar' );
} catch ( Uox_Redirect_Exception $e ) {
	$redirect = (string) $e->url;
}
uox_assert( array( 'cassio@uonix.com.br', 'diretoria@uonix.com.br' ) === get_option( uonix_intelligence_recipients_option() ), 'Handler persiste só os e-mails válidos' );
uox_assert( false !== strpos( $redirect, 'uonix_recipients_saved=2' ), 'Redirect informa quantos foram salvos' );
uox_assert( false !== strpos( $redirect, 'uonix_recipients_rejected=1' ), 'Redirect informa quantos foram recusados' );
uox_assert( false !== strpos( $redirect, 'tab=settings' ), 'Redirect volta para a aba de configurações' );

// ---------------------------------------------------------------------------
// Painel de configurações: formulário apenas para quem pode alterar.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = true;
$_GET = array( 'uonix_recipients_saved' => '2', 'uonix_recipients_rejected' => '1' );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg = (string) ob_get_clean();
uox_assert( false !== strpos( $cfg, 'admin-post.php' ), 'Formulário envia para admin-post.php' );
uox_assert( false !== strpos( $cfg, 'name="action" value="uonix_intelligence_save_recipients"' ), 'Formulário declara a ação do handler' );
uox_assert( false !== strpos( $cfg, 'name="_wpnonce"' ), 'Formulário carrega campo de nonce' );
uox_assert( false !== strpos( $cfg, 'cassio@uonix.com.br' ), 'Destinatário salvo aparece na lista e na área de edição' );
uox_assert( false !== strpos( $cfg, '2 destinatário(s) salvo(s).' ), 'Aviso de salvos é exibido a partir da query' );
uox_assert( false !== strpos( $cfg, '1 entrada(s) recusada(s)' ), 'Aviso de recusados é exibido a partir da query' );
uox_assert( false !== strpos( $cfg, 'Não agendado' ), 'Sem cron registrado, o painel diz que não há disparo agendado' );
uox_assert( false !== strpos( $cfg, 'Semanal' ), 'Painel declara a frequência' );
uox_assert( false === strpos( $cfg, 'manhã' ) && false === strpos( $cfg, 'segunda-feira às' ), 'Painel não promete período do dia nem dia fixo, apenas a frequência' );
uox_assert( 1 === preg_match( '#<section(?=[^>]*id="uonix-panel-settings")[^>]*>#', $cfg ), 'Painel de configurações usa o id que a aba referencia' );

// A ação do nonce tem que ser a mesma nas duas pontas, senão nenhuma gravação
// legítima passa e a tela recusa tudo em silêncio.
uox_assert( null !== $GLOBALS['uox_nonce_field_action'] && $GLOBALS['uox_nonce_field_action'] === $GLOBALS['uox_referer_action'], 'A ação do nonce emitida pelo formulário é a mesma verificada pelo handler' );

// Fora da aba ativa, o painel de configurações também precisa vir oculto — senão a
// lista de destinatários aparece visível em qualquer outra aba.
ob_start();
uonix_intelligence_render_settings_panel( 'metrics' );
$cfg_oculto = (string) ob_get_clean();
uox_assert( 1 === preg_match( '#<section(?=[^>]*id="uonix-panel-settings")[^>]*\bhidden\b[^>]*>#', $cfg_oculto ), 'Painel de configurações fora da aba ativa vem com hidden' );

$GLOBALS['uox_cron']['uonix_intelligence_weekly_report'] = time() + 3600;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_cron = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_cron, 'Não agendado' ), 'Com cron registrado, o painel mostra o próximo disparo real' );
uox_assert( false !== strpos( $cfg_cron, '<time datetime=' ), 'Próximo disparo é exibido como horário legível por máquina' );

$GLOBALS['uox_can'] = false;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_ro = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_ro, '<form' ), 'Sem manage_options o formulário não é renderizado' );
uox_assert( false !== strpos( $cfg_ro, 'exige permissão de administrador' ), 'Sem manage_options a tela explica por que não há formulário' );
uox_assert( false === strpos( $cfg_ro, 'cassio@uonix.com.br' ), 'Sem manage_options o endereço completo não vai para o HTML' );
uox_assert( false !== strpos( $cfg_ro, 'c*****@uonix.com.br' ), 'Sem manage_options o endereço é mascarado preservando inicial e domínio' );
uox_assert( false !== strpos( $cfg_ro, 'parcialmente ocultos' ), 'A tela avisa que os endereços estão mascarados' );

// ---------------------------------------------------------------------------
// Nome do evento de cron vem de acessor, não de string solta em dois arquivos.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_cron'] = array( uonix_intelligence_report_hook() => time() + 7200 );
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_hook = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_hook, 'Não agendado' ), 'O painel lê o próximo disparo pelo mesmo acessor que agenda o envio' );

// ---------------------------------------------------------------------------
// POST em array é entrada legítima de campo repetido, não motivo para apagar tudo.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can'] = true;
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_options'] = array( uonix_intelligence_recipients_option() => array( 'antigo@uonix.com.br' ) );
$_POST = array( 'uonix_recipients' => array( 'novo@uonix.com.br', 'invalido' ) );
try {
	uonix_intelligence_save_recipients();
} catch ( Uox_Redirect_Exception $e ) {
	// esperado
}
uox_assert( array( 'novo@uonix.com.br' ) === get_option( uonix_intelligence_recipients_option() ), 'POST em array é aceito e não apaga a lista silenciosamente' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: abas da Central de Inteligência renderizadas com procedência, escape e destinatários protegidos.\n";
