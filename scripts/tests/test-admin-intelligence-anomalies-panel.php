<?php
/**
 * Testes do PAINEL de anomalias: render, escape, aba e badge.
 *
 * Existe separado de test-admin-intelligence-anomalies.php por um motivo medido: a
 * revisão do PR #293 mostrou que **nenhum teste carregava o 58 junto do 56 e do
 * 52**, e por isso cinco mutações sobreviveram às 99 suítes — remover a aba da
 * allowlist, remover a chamada de render, remover o badge, imprimir `headline` sem
 * `esc_html`, e tirar a opção da proteção de clone. Tudo isso podia sair com a suíte
 * inteira verde.
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
$GLOBALS['uox_cron_rec']     = array();
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

class Uox_Die_Exception extends RuntimeException {}
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
function wp_die( $m = '', $t = '', $a = array() ) { throw new Uox_Die_Exception( (string) $m ); }
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
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook ) { $GLOBALS['uox_cron'][ $hook ] = $ts; $GLOBALS['uox_cron_rec'][ $hook ] = $rec; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['uox_cron'][ $hook ] ); }
function wp_get_schedules() { return $GLOBALS['uox_schedules']; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['uox_timezone'] ); }
function wp_date( $format, $ts = null ) { return gmdate( $format, null === $ts ? time() : (int) $ts ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }

$RAIZ = dirname( __DIR__, 2 );
require_once $RAIZ . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php';

/** Captura o HTML de um render. */
function uox_render( $tab = 'anomalies' ) {
	ob_start();
	uonix_intelligence_render_anomalies_panel( $tab );
	return (string) ob_get_clean();
}

function uox_finding( array $extra = array() ) {
	return array_merge(
		array(
			'trigger'      => 'lead_silence',
			'available'    => true,
			'anomalous'    => true,
			'reason'       => '',
			'headline'     => 'Nenhum orçamento recebido há 12 dias.',
			'started_at'   => '2026-09-12',
			'likely_cause' => 'Causa provável de teste.',
			'action'       => 'Ação recomendada de teste.',
			'observed_on'  => '2026-09-23',
		),
		$extra
	);
}

function uox_semear( array $findings, $anomalous, $unavailable, array $meta = array() ) {
	$GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] = array(
		'triggers' => array(),
		'meta'     => $meta,
		'summary'  => array(
			'findings'    => $findings,
			'anomalous'   => $anomalous,
			'unavailable' => $unavailable,
			'checked_at'  => '2026-09-23T19:20:00+00:00',
		),
	);
}

// ---------------------------------------------------------------------------
// 1. A função de render existe e é chamada pelo dashboard.
// ---------------------------------------------------------------------------

uox_assert( function_exists( 'uonix_intelligence_render_anomalies_panel' ), 'o painel de anomalias precisa existir como função de render' );

/**
 * As asserções sobre o `52` são ESTRUTURAIS, e a limitação é declarada.
 *
 * O ideal seria executar `uonix_render_analytics_dashboard_page()` e afirmar sobre o
 * HTML. Medido: aquela função exige `get_posts()`, taxonomias, permalinks e a
 * maquinaria de gráficos — cerca de trinta stubs de partes do painel que nada têm a
 * ver com anomalias. O teste passaria a reprovar a cada mexida no catálogo, e um
 * teste que falha por motivo alheio é abandonado ou afrouxado.
 *
 * O que estas asserções cobrem: a chamada existe COM a guarda, o link da aba existe,
 * e o badge é calculado do resumo persistido e impresso condicionalmente.
 *
 * O que elas NÃO cobrem: que a página inteira renderize sem erro fatal. Isso fica
 * para a verificação em produção depois do deploy.
 *
 * Os trechos são casados por par guarda-mais-chamada, e não por nome solto. Um grep
 * pelo nome da função sobrevive a trocar a guarda por `if ( false )`, porque o nome
 * continua na linha seguinte — foi exatamente o que a verificação por mutação pegou
 * neste arquivo.
 */
$DASH = (string) file_get_contents( $RAIZ . '/mu-plugins/uonix-admin/52-admin-analytics-dashboard.php' );

$par_render = "if ( function_exists( 'uonix_intelligence_render_anomalies_panel' ) ) {\n\t\t\tuonix_intelligence_render_anomalies_panel( \$active_dashboard_tab );";
uox_assert( false !== strpos( $DASH, $par_render ), 'o dashboard precisa chamar o render do painel de anomalias, COM a guarda de function_exists imediatamente antes; sem isso a aba fica vazia com a suíte verde' );

$par_badge = "\$anomaly_badge = function_exists( 'uonix_intelligence_anomaly_badge' )";
uox_assert( false !== strpos( $DASH, $par_badge ), 'o dashboard precisa atribuir o badge a $anomaly_badge; sem isso o marcador do rótulo não tem o que imprimir e o aviso só aparece para quem já abriu a aba' );
uox_assert( false !== strpos( $DASH, "\$anomaly_summary = function_exists( 'uonix_intelligence_anomaly_get_summary' )" ), 'o badge do rótulo precisa ler o resumo PERSISTIDO, não recomputar por pageview' );

uox_assert( false !== strpos( $DASH, 'uonix-tab-anomalies' ), 'o dashboard precisa ter o link da aba de anomalias' );
uox_assert( false !== strpos( $DASH, 'uonix-panel-anomalies' ), 'o link da aba precisa apontar para o painel de anomalias' );

// O marcador no rótulo tem de ser CONDICIONAL ao estado: impresso sempre, deixaria a
// aba com alarme permanente e o operador aprenderia a ignorá-lo.
uox_assert( 1 === preg_match( "/'normal'\s*!==\s*\\\$anomaly_badge\['state'\]/", $DASH ), 'o marcador no rótulo da aba deve aparecer só quando o estado não é normal' );

// ---------------------------------------------------------------------------
// 2. A aba precisa ser ALCANÇÁVEL: sem a allowlist, o link cai em "metrics".
// ---------------------------------------------------------------------------

$estado = uonix_analytics_metrics_requested_dashboard_state( array( 'tab' => 'anomalies' ) );
uox_assert( 'anomalies' === ( $estado['tab'] ?? '' ), 'a aba anomalies precisa estar na allowlist de abas, senão o link redireciona para métricas e o painel nunca é exibido' );

// ---------------------------------------------------------------------------
// 3. ESCAPE. Hoje está correto; sem asserção, removê-lo não reprova nada.
// ---------------------------------------------------------------------------

$veneno = '<script>alert(1)</script>';
uox_semear( array( uox_finding( array( 'headline' => $veneno, 'likely_cause' => $veneno, 'action' => $veneno ) ) ), 1, 0 );
$html = uox_render();
uox_assert( false === strpos( $html, '<script>' ), 'valor vindo do banco NÃO pode sair cru no painel' );
uox_assert( false !== strpos( $html, '&lt;script&gt;' ), 'o valor deve sair escapado' );

// ---------------------------------------------------------------------------
// 4. Os três estados do badge aparecem na tela.
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding() ), 1, 0 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'anomalia crítica detectada' ), 'o painel deve exibir o rótulo crítico' );
foreach ( array( 'Quando começou', 'Causa provável', 'Ação recomendada' ) as $secao ) {
	uox_assert( false !== strpos( $html, $secao ), "o detalhe do incidente deve trazer a seção \"{$secao}\"" );
}

uox_semear( array( uox_finding( array( 'anomalous' => false, 'headline' => 'Último orçamento há 1 dia(s), dentro do normal.' ) ) ), 0, 0 );
uox_assert( false !== strpos( uox_render(), 'Sistema normal' ), 'sem anomalia o painel deve dizer que o sistema está normal' );

uox_semear( array( uox_finding( array( 'available' => false, 'anomalous' => false, 'reason' => 'baseline_too_small' ) ) ), 0, 1 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'verificação indisponível' ), 'gatilho indisponível não pode aparecer como sistema saudável' );
uox_assert( false !== strpos( $html, 'ruído, não sinal' ), 'o motivo deve ser explicado em linguagem de operador' );

// Todo motivo que o `58` produz precisa de tradução no `56`. Sem esta guarda, um
// motivo novo cai no texto genérico e o operador perde a explicação exatamente quando
// precisa dela.
$MOD58 = (string) file_get_contents( $RAIZ . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php' );
preg_match_all( "/anomaly_unavailable\(\s*'[a-z_]+',\s*'([a-z_]+)'/", $MOD58, $m );
preg_match_all( "/\\\$base\['reason'\] = '([a-z_]+)'/", $MOD58, $m2 );
$motivos = array_unique( array_merge( $m[1], $m2[1] ) );
uox_assert( count( $motivos ) >= 5, 'a extração de motivos do 58 não pode vir vazia, senão o laço abaixo é vácuo; achou ' . count( $motivos ) );
foreach ( $motivos as $motivo ) {
	uox_assert(
		'Este gatilho não pôde ser verificado nesta rodada.' !== uonix_intelligence_anomaly_reason_message( $motivo ),
		"o motivo '{$motivo}' é produzido pelo 58 e cai no texto genérico do 56"
	);
}

// ---------------------------------------------------------------------------
// 5. Anomalia não reverificada: o painel mostra, em vez de apagar (MÉDIO 2).
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding( array( 'available' => false, 'anomalous' => false, 'reason' => 'series_fetch_failed', 'stale_anomaly' => true, 'started_at' => '2026-09-10' ) ) ), 1, 0 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'ainda não resolvida' ), 'anomalia detectada antes e não reverificada deve continuar visível' );
uox_assert( false !== strpos( $html, '2026-09-10' ), 'e deve nomear desde quando' );

// ---------------------------------------------------------------------------
// 6. Aviso não entregue: a ausência de e-mail não pode passar por ausência de
//    problema (ALTO 3).
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding() ), 1, 0, array( 'lead_silence' => array( 'since' => '2026-09-12', 'attempts' => 3, 'undelivered' => true ) ) );
$html = uox_render();
uox_assert( false !== strpos( $html, 'NÃO foi entregue' ), 'o painel deve avisar quando o e-mail do alerta não foi entregue' );

// ---------------------------------------------------------------------------
// 6b. Anomalia expirada: a tela declara a idade em vez de afirmar o presente.
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding( array( 'available' => false, 'anomalous' => false, 'reason' => 'config_missing', 'stale_expired' => true, 'last_observed' => '2026-08-01', 'stale_days' => 53 ) ) ), 0, 1 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'Última observação anômala em 2026-08-01' ), 'anomalia expirada deve nomear a última observação, não afirmar o presente' );
uox_assert( false !== strpos( $html, '53 dias' ), 'e declarar há quantos dias não foi reverificada' );
uox_assert( false === strpos( $html, 'ainda não resolvida' ), 'expirada NÃO pode usar o texto de anomalia ativa' );

// ---------------------------------------------------------------------------
// 6c. Intervalo medido da borda da janela é declarado como PISO, não como exato.
// ---------------------------------------------------------------------------

$GLOBALS['uox_table_exists'] = true;
$GLOBALS['uox_lead_rows']    = array( '2026-09-22' => 1, '2026-09-21' => 1 );
$GLOBALS['uox_lead_before']  = '2026-06-20 10:00:00';
uox_semear( array( uox_finding( array( 'anomalous' => false ) ) ), 0, 0 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'ou MAIS' ), 'quando a medição começa na borda da janela, a tela deve declarar o valor como piso, não como exato' );
$GLOBALS['uox_lead_before'] = null;

// ---------------------------------------------------------------------------
// 6d. O aviso de limiar inseguro, e os NÚMEROS do card.
// ---------------------------------------------------------------------------

// Limiar INSEGURO: o gatilho vai descrever normalidade.
$GLOBALS['uox_lead_rows'] = array();
$cursor = new DateTimeImmutable( '2026-09-23', new DateTimeZone( 'UTC' ) );
for ( $i = 0; $i < 90; $i++ ) {
	if ( 0 === $i || $i > 40 ) { $GLOBALS['uox_lead_rows'][ $cursor->format( 'Y-m-d' ) ] = 1; }
	$cursor = $cursor->modify( '-1 day' );
}
uox_semear( array( uox_finding( array( 'anomalous' => false ) ) ), 0, 0 );
$html = uox_render();
uox_assert( false !== strpos( $html, 'não é maior que o maior silêncio observado' ), 'limiar inseguro precisa ser avisado na tela' );

// Os NÚMEROS do card, não só a presença das frases.
//
// Lacuna apontada na revisão do PR #298: o teste procurava a frase e nunca os valores,
// então trocar `longest_gap` por `threshold_days` na impressão sobrevivia — a tela podia
// dizer "21 dias contra um silêncio normal de no máximo 21" e passar verde. O valor
// inteiro deste bloco é a folga medida, então é ela que precisa de asserção.
$base  = uonix_intelligence_anomaly_lead_baseline();
$gap   = (int) $base['longest_gap'];
$lim   = (int) $base['threshold_days'];
uox_assert( $gap !== $lim, 'o fixture precisa ter gap diferente do limiar, senão a asserção abaixo não distingue os dois campos' );
// `/u` é obrigatório por causa do "ê": sem a flag, uma classe como `[êe]` vira classe de
// BYTES e não casa o par de bytes do UTF-8. Errei nisso ao escrever a asserção.
uox_assert( 1 === preg_match( '/Maior silêncio encerrado.*?uonix-kpi-value">\s*' . $gap . '\s*</su', $html ), 'o card do maior silêncio deve imprimir o gap medido (' . $gap . '), não outro número' );
uox_assert( 1 === preg_match( '/Limiar em uso.*?uonix-kpi-value">\s*' . $lim . '\s*</su', $html ), 'o card do limiar deve imprimir o limiar em uso (' . $lim . ')' );
$GLOBALS['uox_lead_rows'] = array();

// ---------------------------------------------------------------------------
// 7. Nunca verificado é estado distinto de verificado e normal.
// ---------------------------------------------------------------------------

unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$html = uox_render();
uox_assert( false !== strpos( $html, 'Nenhuma verificação registrada' ), 'sem verificação alguma o painel não pode afirmar que está tudo normal' );

// ---------------------------------------------------------------------------
// 8. O painel não pode chamar a rede nem recomputar.
// ---------------------------------------------------------------------------

/**
 * Código do arquivo SEM comentários nem docblocks.
 *
 * Um `strpos` no fonte cru daria falso positivo no próprio comentário que explica
 * por que a função não deve ser chamada — aconteceu ao escrever este teste. Sem
 * tokenizar, a asserção reprovaria o código correto e passaria a ser removida ou
 * afrouxada, que é o pior desfecho possível para uma guarda.
 */
function uox_codigo_sem_comentarios( $caminho ) {
	$fonte  = (string) file_get_contents( $caminho );
	$saida  = '';
	foreach ( token_get_all( $fonte ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$saida .= is_array( $token ) ? $token[1] : $token;
	}
	return $saida;
}

$RENDER = uox_codigo_sem_comentarios( $RAIZ . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php' );
uox_assert( false === strpos( $RENDER, 'uonix_intelligence_anomaly_detect' ), 'o render NÃO pode chamar detect(): seria uma chamada à API da Search Console por carga de tela' );
uox_assert( false === strpos( $RENDER, 'uonix_intelligence_anomaly_organic_drop' ), 'o render NÃO pode chamar o gatilho de tráfego diretamente' );
// Contraprova de que o tokenizador não esvaziou a asserção: uma função que o render
// REALMENTE chama tem de ser encontrada no mesmo texto.
uox_assert( false !== strpos( $RENDER, 'uonix_intelligence_anomaly_get_summary' ), 'o texto sem comentários deve conter as chamadas reais; se não contiver, as duas asserções acima são vácuas' );

// ---------------------------------------------------------------------------
// 9. A aba aparece só quando escolhida.
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding() ), 1, 0 );
uox_assert( false !== strpos( uox_render( 'anomalies' ), '<section id="uonix-panel-anomalies"' ), 'o painel deve ter o id que o link da aba referencia' );
uox_assert( false !== strpos( uox_render( 'metrics' ), 'hidden' ), 'em outra aba o painel deve ser renderizado escondido, como os irmãos' );

// O PAR da asserção acima, e ele custa uma linha. Sem ele, `$is_active = false`
// sobrevive à suíte e a aba "Anomalias" abre EM BRANCO em produção: o `<section>`
// sai sempre com `hidden` e o módulo inteiro fica invisível. Propriedade afirmada em
// prosa e asserida pela metade — mesma classe do grep por nome solto no 52.
uox_assert( false === strpos( uox_render( 'anomalies' ), ' hidden>' ), 'na aba DELE o painel não pode sair escondido, senão a aba abre em branco' );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: painel de anomalias (Módulo 5).\n" );
