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
define( 'MINUTE_IN_SECONDS', 60 );
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
// Warning, notice e deprecated de PHP reprovam a suíte, com o arquivo e a linha (#317). `@` suprime de propósito
// (`error_reporting()` zera), e o handler respeita isso. O `E_ALL` é forçado aqui, e não herdado: o `php.ini` do runner pode
// silenciar warning ou deprecated, e aí o handler nunca veria nada e a suíte voltaria a ser decorativa.
error_reporting( E_ALL );
set_error_handler(
	static function ( $nivel, $mensagem, $arquivo, $linha ) {
		global $failures;
		if ( 0 === ( error_reporting() & $nivel ) ) {
			return false;
		}
		++$failures;
		// A sonda de vida (logo abaixo) conta o erro sem imprimir uma linha "FAIL:" numa suíte verde.
		if ( empty( $GLOBALS['uox_sonda_do_guarda'] ) ) {
			fwrite( STDERR, "FAIL: erro de PHP ({$nivel}): {$mensagem} em " . basename( (string) $arquivo ) . ":{$linha}\n" );
		}

		return true;
	}
);
// Sonda de vida do guarda (#317): um erro de PHP de verdade tem de ser CONTADO por ele. Sem isto, tirar o `error_reporting( E_ALL )`
// (com o INI do runner em 0), fazer o handler devolver `false` ou não incrementar `$failures` deixava as seis suítes verdes.
// A sonda dispara um E_USER_NOTICE, confere que `$failures` subiu em um, e desfaz a contagem para a suíte seguir.
$uox_antes_da_sonda = $failures;
@trigger_error( '', E_USER_NOTICE );
uox_assert( $failures === $uox_antes_da_sonda, 'sonda do guarda: o `@` suprime, e o handler respeita (a contagem não muda)' );
$uox_antes_da_sonda = $failures;
$GLOBALS['uox_sonda_do_guarda'] = true;
trigger_error( 'sonda do guarda #317', E_USER_NOTICE );
$GLOBALS['uox_sonda_do_guarda'] = false;
$uox_contou = ( $failures === $uox_antes_da_sonda + 1 );
$failures = $uox_antes_da_sonda;
uox_assert( $uox_contou, 'sonda do guarda: o handler de erros de PHP CONTA um erro de verdade (E_USER_NOTICE)' );

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
function update_option( $key, $value, $autoload = null ) { $GLOBALS['uox_escritas'][] = (string) $key; $GLOBALS['uox_options'][ $key ] = $value; return true; }
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
function wp_date( $format, $ts = null ) { $d = new DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) ); return $d->setTimezone( new DateTimeZone( $GLOBALS['uox_timezone'] ) )->format( $format ); }
function wp_remote_post( $url, $args = array() ) { return array(); }
function wp_remote_get( $url, $args = array() ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
// A guarda das regras de reescrita (#305) grava `uonix_rewrite_rules_repair`; o teste do #367 a executa de verdade.
function flush_rewrite_rules( $hard = true ) {}
class WP_Rewrite {
	public $extra_rules_top     = array();
	public $permalink_structure = '/%postname%/';
	public function using_permalinks() { return '' !== $this->permalink_structure; }
}

$RAIZ = dirname( __DIR__, 2 );
require_once $RAIZ . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php';
require_once $RAIZ . '/mu-plugins/uonix-content/50-seo-regras-reescrita-rank-math.php';

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
// #367: a última regravação automática das regras de reescrita, lida de `uonix_rewrite_rules_repair`.
//
// A guarda do #305 grava `array( 'at' => int, 'missing' => string[] )` quando regrava as regras do
// Rank Math. Em 2026-11-16 ela disparou em produção e o registro ficou 40 horas sem nenhuma tela. A
// aba mostra o que está GRAVADO, sem requisição nova. O formato abaixo é o lido em produção.
// ---------------------------------------------------------------------------

$REPARO = 'uonix_rewrite_rules_repair';
$AT     = 1794879308; // 2026-11-17 01:35:08 UTC = 2026-11-16 22:35:08 em America/Sao_Paulo.
$REGRAS = array( 'sitemap_index\.xml$', '([^/]+?)-sitemap([0-9]+)?\.xml$', '([a-z]+)?-?sitemap\.xsl$' );

uox_semear( array( uox_finding( array( 'anomalous' => false ) ) ), 0, 0 );
unset( $GLOBALS['uox_options'][ $REPARO ] );
$sem_registro = uox_render();
uox_assert( false !== strpos( $sem_registro, 'Regras de reescrita' ), '#367: o painel tem a seção das regras de reescrita' );
uox_assert( false !== strpos( $sem_registro, 'Nenhuma regravação automática registrada' ), '#367: sem registro a tela diz que nenhuma regravação foi registrada' );
uox_assert( false === strpos( $sem_registro, 'Última regravação automática:' ), '#367: sem registro a tela NÃO afirma uma data' );

$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );
$com_registro = uox_render();
uox_assert( false !== strpos( $com_registro, 'Última regravação automática: 16/11/2026 22:35' ), '#367: a data sai no FUSO DO SITE (22:35 de 16/11, e não 01:35 de 17/11, que é UTC)' );
uox_assert( false === strpos( $com_registro, '17/11/2026 01:35' ), '#367: a hora em UTC não aparece' );
uox_assert( false === strpos( $com_registro, 'Nenhuma regravação automática registrada' ), '#367: com registro, a frase de "nenhuma" some' );
foreach ( array( 'sitemap_index', '-sitemap(', 'sitemap\.xsl' ) as $trecho ) {
	uox_assert( false !== strpos( $com_registro, $trecho ), "#367: a tela nomeia a regra que faltava ({$trecho})" );
}
// A tela diz o que NÃO cobre: o registro só existe quando a guarda agiu, e não prova que o reparo "pegou".
// Cada ressalva é uma frase própria, e CADA UMA tem de existir nos TRÊS estados (sem registro, com registro e ilegível):
// "Nenhuma regravação automática registrada" lido como "as regras estão gravadas" é o erro que a ressalva existe para evitar.
$ressalvas = array(
	'a hora é a do AGENDAMENTO, e o registro guarda só o último' => 'a guarda agendou a regravação',
	'não mostra se o reparo funcionou'                           => 'não mostra se o reparo funcionou',
	'a falta de registro não prova que as regras estejam gravadas' => 'a falta de registro não prova que as regras estejam gravadas',
	'a guarda só olha o sitemap do Rank Math'                    => 'a guarda só olha as regras de sitemap do Rank Math',
	'não vê só as categorias sumirem'                            => 'só as das categorias somem',
	'não vê o módulo de sitemap desligado'                       => 'módulo de sitemap estar desligado',
	'um reparo que não resolve renova a hora'                    => 'a hora se renova',
	'como conferir de verdade'                                   => '/sitemap_index.xml</code> e uma página de categoria',
);
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );
$tres = array( 'com registro' => uox_render(), 'sem registro' => '', 'ilegível' => '' );
unset( $GLOBALS['uox_options'][ $REPARO ] );
$tres['sem registro'] = uox_render();
$GLOBALS['uox_options'][ $REPARO ] = 'lixo';
$tres['ilegível'] = uox_render();
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );
foreach ( $tres as $estado => $html_estado ) {
	foreach ( $ressalvas as $nome => $trecho ) {
		uox_assert( false !== strpos( $html_estado, $trecho ), "#367: a ressalva \"{$nome}\" está na tela ({$estado})" );
	}
}
// A frase do cabeçalho da seção promete a guarda SÓ no que ela faz: agenda, com intervalo, e não "regrava a cada requisição".
uox_assert( false !== strpos( $com_registro, 'agenda a regravação das regras (no máximo uma a cada 15 minutos)' ), '#367: a tela diz que a guarda AGENDA a regravação, no máximo uma a cada 15 minutos' );
uox_assert( false === strpos( $com_registro, 'regrava as regras sozinha' ), '#367: a tela não afirma que a guarda "regrava as regras sozinha" (ela agenda, e uma vez a cada 15 minutos)' );
uox_assert( uonix_rewrite_guard_cooldown() === 15 * MINUTE_IN_SECONDS, '#367: os "15 minutos" do texto da tela são o intervalo REAL da guarda (' . uonix_rewrite_guard_cooldown() . ' s)' );
// Somente leitura: o registro é uma opção que o painel NÃO escreve, e a aba não regrava nada. A conferência vem DEPOIS de
// renderizar, e o `update_option` é contado (não só o valor final): escrever o MESMO valor também é escrever.
$GLOBALS['uox_escritas'] = array();
$antes_do_render = $GLOBALS['uox_options'];
uox_render();
uox_assert( array() === $GLOBALS['uox_escritas'], '#367: renderizar a aba não escreve em NENHUMA opção; escreveu ' . implode( ', ', $GLOBALS['uox_escritas'] ) );
uox_assert( $antes_do_render === $GLOBALS['uox_options'], '#367: renderizar a aba não altera nenhuma opção' );

// O nome da opção é o MESMO do escritor: se um dos dois mudar, a tela fica "sem registro" para sempre sem nada reprovar.
uox_assert( uonix_rewrite_guard_repair_option() === uonix_intelligence_rewrite_repair_option(), '#367: o painel lê a mesma opção que a guarda grava' );

// ROUND-TRIP com a guarda REAL: o que ela grava, a tela lê. É o teste que não depende de eu ter copiado o formato certo.
$ini_log = ini_get( 'error_log' );
$log_367 = tempnam( sys_get_temp_dir(), 'uox367' );
ini_set( 'error_log', $log_367 );
$GLOBALS['wp_rewrite']                  = new WP_Rewrite();
$GLOBALS['wp_rewrite']->extra_rules_top = array(
	'sitemap_index\.xml$'             => 'index.php?sitemap=1',
	'([^/]+?)-sitemap([0-9]+)?\.xml$' => 'index.php?sitemap=$matches[1]&sitemap_n=$matches[2]',
	'([a-z]+)?-?sitemap\.xsl$'        => 'index.php?xsl=$matches[1]',
);
$GLOBALS['uox_options']['rewrite_rules'] = array( '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]' );
unset( $GLOBALS['uox_options'][ $REPARO ] );
$_POST = array();
uox_assert( 'scheduled' === uonix_rewrite_guard_check( $AT ), '#367: a guarda real reparou (pré-condição do round-trip)' );
uox_assert( isset( $GLOBALS['uox_options'][ $REPARO ] ), '#367: a guarda real gravou o registro (pré-condição do round-trip)' );
$ida_e_volta = uox_render();
uox_assert( false !== strpos( $ida_e_volta, 'Última regravação automática: 16/11/2026 22:35' ), '#367: round-trip: a data que a guarda gravou é a que a tela mostra' );
uox_assert( false !== strpos( $ida_e_volta, '<code>sitemap_index\.xml$</code>' ) && false !== strpos( $ida_e_volta, '<code>([a-z]+)?-?sitemap\.xsl$</code>' ), '#367: round-trip: as regras que a guarda gravou são as que a tela lista' );
uox_assert( false === strpos( $ida_e_volta, 'Registro de regravação ilegível' ), '#367: round-trip: o registro que a própria guarda grava nunca é "ilegível"' );
ini_set( 'error_log', (string) $ini_log );
@unlink( $log_367 );
unset( $GLOBALS['wp_rewrite'], $GLOBALS['uox_options']['rewrite_rules'] );
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );

// Limites da hora (função pura, com `$now`): inteiro positivo; no máximo um dia no futuro (relógio adiantado e corrigido).
$agora_367 = 1800000000;
foreach ( array(
	array( 1,                       'recorded',   'at = 1 (menor inteiro positivo)' ),
	array( $agora_367 - 86400 * 400, 'recorded',  'at de 400 dias atrás (a guarda não apaga o registro)' ),
	array( $agora_367,              'recorded',   'at = agora' ),
	array( $agora_367 + 86400,      'recorded',   'at = agora + 1 dia (tolera relógio adiantado)' ),
	array( $agora_367 + 86401,      'unreadable', 'at = agora + 1 dia + 1 s' ),
	array( 0,                       'unreadable', 'at = 0' ),
	array( -1,                      'unreadable', 'at = -1' ),
) as $caso ) {
	$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $caso[0], 'missing' => array() );
	uox_assert( $caso[1] === uonix_intelligence_rewrite_repair_info( $agora_367 )['state'], '#367: ' . $caso[2] . ' é ' . $caso[1] );
}
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );

// O fuso é do SITE: com outro fuso, a mesma hora gravada muda de dia.
$GLOBALS['uox_timezone'] = 'UTC';
uox_assert( false !== strpos( uox_render(), 'Última regravação automática: 17/11/2026 01:35' ), '#367: com o site em UTC a mesma hora sai como 17/11/2026 01:35' );
$GLOBALS['uox_timezone'] = 'America/Sao_Paulo';

// Valor vindo do banco NÃO sai cru: a regra é texto livre gravado em `wp_options`.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( '<script>alert(1)</script>' ) );
$veneno = uox_render();
uox_assert( false === strpos( $veneno, '<script>alert(1)' ), '#367: a regra gravada é escapada' );
uox_assert( false !== strpos( $veneno, '&lt;script&gt;alert(1)&lt;/script&gt;' ), '#367: a regra sai como texto' );

// Registro malformado ou de forma inesperada: a tela não quebra e NÃO afirma uma data inventada.
$malformados = array(
	'texto'                      => 'lixo',
	'inteiro'                    => 7,
	'lista vazia'                => array(),
	'sem at'                     => array( 'missing' => $REGRAS ),
	'at texto'                   => array( 'at' => 'ontem', 'missing' => $REGRAS ),
	'at numérico em texto'       => array( 'at' => '1794879308', 'missing' => $REGRAS ),
	'at float'                   => array( 'at' => 1794879308.5, 'missing' => $REGRAS ),
	'at zero'                    => array( 'at' => 0, 'missing' => $REGRAS ),
	'at negativo'                => array( 'at' => -5, 'missing' => $REGRAS ),
	'at booleano'                => array( 'at' => true, 'missing' => $REGRAS ),
	'at no futuro distante'      => array( 'at' => PHP_INT_MAX, 'missing' => $REGRAS ),
	// Um objeto no lugar do array: `isset( $objeto['at'] )` LANÇA `Error` num objeto sem `ArrayAccess`, e a aba inteira cairia.
	'objeto'                     => new stdClass(),
	'objeto com at'              => (object) array( 'at' => $AT, 'missing' => $REGRAS ),
);
foreach ( $malformados as $nome => $valor ) {
	$GLOBALS['uox_options'][ $REPARO ] = $valor;
	try {
		$html = uox_render();
	} catch ( Throwable $e ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		uox_assert( false, "#367: registro malformado ({$nome}) derrubou a aba com " . get_class( $e ) );
		continue;
	}
	uox_assert( false === strpos( $html, 'Última regravação automática:' ), "#367: registro malformado ({$nome}) NÃO vira uma data na tela" );
	uox_assert( false !== strpos( $html, 'Registro de regravação ilegível' ), "#367: registro malformado ({$nome}) é dito ilegível, e não passa por ausência" );
}

// `missing` malformado com `at` válido: a data é verdadeira e continua aparecendo; só a lista de regras some.
$parciais = array(
	'missing ausente'     => array( 'at' => $AT ),
	'missing texto'       => array( 'at' => $AT, 'missing' => 'sitemap' ),
	'missing com número'  => array( 'at' => $AT, 'missing' => array( 5, null, array( 'x' ) ) ),
	'missing vazio'       => array( 'at' => $AT, 'missing' => array() ),
);
foreach ( $parciais as $nome => $valor ) {
	$GLOBALS['uox_options'][ $REPARO ] = $valor;
	$html = uox_render();
	uox_assert( false !== strpos( $html, 'Última regravação automática: 16/11/2026 22:35' ), "#367: {$nome}: a data válida continua aparecendo" );
	uox_assert( false === strpos( $html, 'Registro de regravação ilegível' ), "#367: {$nome}: com `at` válido o registro não é ilegível" );
}

// A guarda grava no máximo 5 regras, e a tela também mostra no máximo 5, mesmo se o registro vier maior.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( 'r1', 'r2', 'r3', 'r4', 'r5', 'r6-nao-mostrar', 'r7-nao-mostrar' ) );
$grande = uox_render();
uox_assert( false !== strpos( $grande, 'r5' ) && false === strpos( $grande, 'r6-nao-mostrar' ), '#367: a tela mostra no máximo 5 regras' );

// Texto longo de uma regra é cortado em 120 caracteres (a regra vem de `wp_options` e a tela não pode crescer sem limite).
// A fronteira é exata: 120 sai inteira e SEM reticências; 121 sai com 120 e as reticências.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( str_repeat( 'x', 120 ) ) );
uox_assert( false !== strpos( uox_render(), '<code>' . str_repeat( 'x', 120 ) . '</code>' ), '#367: regra de exatamente 120 caracteres sai inteira e sem reticências' );
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( str_repeat( 'x', 121 ) ) );
$cortada = uox_render();
uox_assert( false !== strpos( $cortada, '<code>' . str_repeat( 'x', 120 ) . '…</code>' ) && false === strpos( $cortada, str_repeat( 'x', 121 ) ), '#367: regra de 121 caracteres sai com 120 e reticências' );
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( str_repeat( 'x', 5000 ) ) );
uox_assert( false === strpos( uox_render(), str_repeat( 'x', 121 ) ), '#367: regra de 5000 caracteres não passa de 120 na tela' );
// O limite é em CARACTERES, e não em bytes: 120 letras acentuadas são 240 bytes e saem inteiras, sem reticências.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( str_repeat( 'é', 120 ) ) );
uox_assert( false !== strpos( uox_render(), '<code>' . str_repeat( 'é', 120 ) . '</code>' ), '#367: regra de 120 caracteres acentuados (240 bytes) sai inteira e sem reticências' );
// O corte é por caractere, e não por byte: um acento no limite não pode ser partido ao meio (HTML com UTF-8 inválido).
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( str_repeat( 'é', 121 ) ) );
$acento = uox_render();
uox_assert( false !== strpos( $acento, '<code>' . str_repeat( 'é', 120 ) . '…</code>' ) && 1 === preg_match( '//u', $acento ), '#367: o corte por caractere preserva o UTF-8' );

// UTF-8 inválido numa regra: a regra é DESCARTADA. `mb_substr` a trocaria por `?` (a tela mostraria uma regra diferente da
// gravada) e o `esc_html` do core, que esvazia a string inteira, deixaria um `<code></code>` vazio. O que o stub do teste faz
// com UTF-8 inválido NÃO é o que o core faz, então o produto precisa decidir antes de escapar. O HTML segue UTF-8 válido e a data fica.
foreach ( array( 'byte solto' => "regra\xC3", 'byte solto no fim de uma regra longa' => str_repeat( 'a', 200 ) . "\xC3", 'sequência truncada' => "ab\xE2\x82", 'overlong' => "\xC0\xAF" ) as $nome => $ruim ) {
	$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( $ruim, 'boa' ) );
	$invalida = uox_render();
	uox_assert( 1 === preg_match( '//u', $invalida ), "#367: regra com UTF-8 inválido ({$nome}) não quebra o UTF-8 do HTML da aba" );
	// A lista mostrada é EXATAMENTE a regra boa. Procurar só um texto específico (como "regra?") deixaria passar qualquer
	// outra troca do byte inválido: `mb_substr` põe "?" e o resultado depende da sequência, então a comparação é pela lista inteira.
	$mostradas = 1 === preg_match( '/Regras que faltavam: (.*?)<\/p>/su', $invalida, $mp ) && preg_match_all( '/<code>(.*?)<\/code>/su', $mp[1], $mc ) ? $mc[1] : null;
	uox_assert( array( 'boa' ) === $mostradas, "#367: com UTF-8 inválido ({$nome}) a tela mostra EXATAMENTE a regra boa; mostrou " . var_export( $mostradas, true ) );
	uox_assert( false === strpos( $invalida, '…' ) && false === strpos( $invalida, '<code></code>' ), "#367: regra com UTF-8 inválido ({$nome}) não deixa reticências nem <code> vazio" );
	uox_assert( false !== strpos( $invalida, 'Última regravação automática: 16/11/2026 22:35' ), "#367: a data continua com UTF-8 inválido ({$nome})" );
}
// A única regra é inválida: a data fica e a frase "Regras que faltavam" não aparece.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( "so\xC3" ) );
$so_ruim = uox_render();
uox_assert( false !== strpos( $so_ruim, 'Última regravação automática: 16/11/2026 22:35' ) && false === strpos( $so_ruim, 'Regras que faltavam' ), '#367: com a única regra inválida, a data fica e "Regras que faltavam" não aparece' );
// NUL e quebra de linha são UTF-8 válido: a regra aparece, e o HTML continua válido.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( "a\0b", "c\nd" ) );
$nul = uox_render();
uox_assert( 1 === preg_match( '//u', $nul ) && false !== strpos( $nul, "<code>a\0b</code>" ), '#367: regra com byte NUL (UTF-8 válido) aparece, e o HTML segue válido' );

// Regra em branco não vira um `<code></code>` vazio, e a vírgula só vem ENTRE regras.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( '', '   ', 'a-regra', 'b-regra' ) );
$branco = uox_render();
uox_assert( false === strpos( $branco, '<code></code>' ) && false === strpos( $branco, '<code>   </code>' ), '#367: regra em branco é descartada' );
uox_assert( 1 === preg_match( '/Regras que faltavam: <code>a-regra<\/code>, <code>b-regra<\/code><\/p>/', $branco ), '#367: a vírgula separa as regras (sem espaço antes dela) e não precede a primeira nem sucede a última' );
// Só regras inválidas: a data continua e a frase "Regras que faltavam" não aparece com a lista vazia.
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => array( '', null, 5 ) );
$so_invalidas = uox_render();
uox_assert( false !== strpos( $so_invalidas, 'Última regravação automática: 16/11/2026 22:35' ) && false === strpos( $so_invalidas, 'Regras que faltavam' ), '#367: sem nenhuma regra válida a frase "Regras que faltavam" não aparece' );

// A seção aparece também quando outra aba está ativa (o painel é renderizado escondido, como os irmãos).
$GLOBALS['uox_options'][ $REPARO ] = array( 'at' => $AT, 'missing' => $REGRAS );
$outra_aba = uox_render( 'metrics' );
uox_assert( false !== strpos( $outra_aba, 'Última regravação automática: 16/11/2026 22:35' ), '#367: a seção é renderizada mesmo com outra aba ativa' );
uox_assert( false !== strpos( $outra_aba, '<h3>Regras de reescrita do sitemap e das categorias</h3>' ), '#367: o título da seção também é renderizado com outra aba ativa (a seção inteira fica escondida com o painel, como os irmãos)' );
unset( $GLOBALS['uox_options'][ $REPARO ] );

// Sem `wp_date`: a hora cai em UTC e a tela DIZ "(UTC)" ao lado da data (M3 da revisão). A suíte define `wp_date` no escopo
// global, então o ramo só se alcança em subprocesso, com o 56 carregado sozinho (ele só declara funções), como no teste do
// dashboard (#456). O helper `uonix_intelligence_radar_time` já é testado lá; aqui se prende que A SEÇÃO NOVA o chama COM o rótulo.
$sub_sem_wp_date = static function ( $registro ) {
	$codigo = 'define( "ABSPATH", "/" ); function get_option( $k, $d = false ) { return json_decode( getenv( "UOX_R" ), true ); } function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" ); } function esc_attr( $t ) { return esc_html( $t ); } require getenv( "UOX_F" ); echo function_exists( "wp_date" ) ? "WP_DATE_EXISTE" : "sem wp_date", "\n"; ob_start(); uonix_intelligence_render_anomalies_panel( "anomalies" ); echo ob_get_clean();';
	$proc   = proc_open( array( PHP_BINARY, '-n', '-d', 'date.timezone=America/Sao_Paulo', '-r', $codigo ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array( 'UOX_F' => dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php', 'UOX_R' => (string) json_encode( $registro ) ) );
	$saida  = is_resource( $proc ) ? stream_get_contents( $pipes[1] ) : '';
	if ( is_resource( $proc ) ) {
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
	}
	return (string) $saida;
};
$html_sem = $sub_sem_wp_date( array( 'at' => $AT, 'missing' => $REGRAS ) );
uox_assert( 0 === strpos( $html_sem, 'sem wp_date' ), '#367: o subprocesso do teste NÃO tem wp_date (pré-condição); obteve ' . substr( $html_sem, 0, 40 ) );
uox_assert( false !== strpos( $html_sem, 'Última regravação automática: 17/11/2026 01:35 (UTC)' ), '#367: sem wp_date, a hora da seção sai em UTC e DIZ "(UTC)" (date.timezone do processo é São Paulo, e a hora não vira 22:35)' );

// ---------------------------------------------------------------------------
// #351: o aviso de "primeira verificação" só fala em tráfego com WP-Cron por visita.
// Fica no fim porque constante não se desfaz.
// ---------------------------------------------------------------------------
unset( $GLOBALS['uox_options']['uonix_intelligence_anomaly_state'] );
$sem_verificacao = uox_render( 'anomalies' );
uox_assert( false !== strpos( $sem_verificacao, 'A primeira verificação ainda não rodou.' ) && false !== strpos( $sem_verificacao, 'depende de tráfego no site' ), '#351: sem DISABLE_WP_CRON, o aviso da primeira verificação fala do tráfego' );
define( 'DISABLE_WP_CRON', true );
$sem_verificacao = uox_render( 'anomalies' );
uox_assert( false !== strpos( $sem_verificacao, 'A primeira verificação ainda não rodou.' ) && false === strpos( $sem_verificacao, 'depende de tráfego' ) && false !== strpos( $sem_verificacao, 'agendador do servidor' ), '#351: com DISABLE_WP_CRON, o aviso diz que a verificação depende do agendador do servidor' );

// ---------------------------------------------------------------------------
// 10. Aviso de licença: só com o envio pausado. Fica no fim porque constante não se desfaz.
// ---------------------------------------------------------------------------

uox_semear( array( uox_finding() ), 1, 0 );
uox_assert( false === strpos( uox_render( 'anomalies' ), 'uonix-license-notice' ), 'sem constante de licença o painel não mostra aviso de licença' );

define( 'KSIODEV_INTELLIGENCE_STATUS', 'active' );
define( 'KSIODEV_INTELLIGENCE_VALID_UNTIL', '2000-01-01' );
$vencido = uox_render( 'anomalies' );
uox_assert( false !== strpos( $vencido, 'uonix-license-notice' ) && false !== strpos( $vencido, 'venceu em 01/01/2000' ), 'com a licença vencida o painel de anomalias mostra o aviso com a data' );
// A detecção não para: o painel continua mostrando o achado.
uox_assert( false !== strpos( $vencido, 'Nenhum orçamento recebido há 12 dias.' ), 'com a licença vencida o painel continua mostrando as anomalias' );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: painel de anomalias (Módulo 5).\n" );
