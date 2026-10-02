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
$GLOBALS['uox_nonce_actions'] = array();

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
// Stub que de fato escapa. Passa-tudo tornaria vazia qualquer asserção sobre
// escape de URL.
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>' ), array( '&quot;', '&#039;', '&lt;', '&gt;' ), (string) $url ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
// Coleta TODAS as ações de nonce emitidas: o painel tem mais de um formulário, e
// guardar só a última faria a comparação com o handler comparar formulários
// diferentes.
function wp_nonce_field( $action = -1 ) { $GLOBALS['uox_nonce_actions'][] = $action; echo '<input type="hidden" name="_wpnonce" value="stub">'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['uox_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook ) { $GLOBALS['uox_cron'][ $hook ] = $ts; return true; }
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
// Quem altera as Configurações: só o dono (49). Aqui, outro interruptor, com a contagem
// de consultas para provar que handler e painel perguntam a regra.
$GLOBALS['uox_dono']           = true;
$GLOBALS['uox_dono_consultas'] = 0;
function uonix_ksio_can_configure_insights() {
	++$GLOBALS['uox_dono_consultas'];
	return (bool) $GLOBALS['uox_dono'];
}

// Sugestão por IA (54) substituída por interruptores: o 54 tem teste próprio, e aqui
// importa que o painel exiba cada estado e escape o texto.
$GLOBALS['uox_ia'] = array();
function uonix_intelligence_ai_suggestion_for( $row ) { return $GLOBALS['uox_ia'][ $row['query'] ?? '' ] ?? array( 'status' => 'pending' ); }
function uonix_intelligence_ai_state_message( $status ) { return 'ESTADO-IA:' . $status; }
function uonix_intelligence_ai_page_post_id( $path ) { return '/produtos/olhal/' === $path ? 10 : 0; }
function home_url( $p = '' ) { return 'https://uonix.com.br' . $p; }
function get_edit_post_link( $id ) { return 'https://uonix.com.br/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
// #344: a página líder pode ser um termo. O 54 tem teste próprio do resolvedor; aqui
// importa que o painel leve ao editor certo.
function uonix_intelligence_ai_page_object( $path ) {
	if ( '/produtos/olhal/' === $path ) {
		return array( 'type' => 'post', 'id' => 10 );
	}
	return '/olhal-de-ancoragem/' === $path ? array( 'type' => 'term', 'taxonomy' => 'product_cat', 'id' => 34 ) : null;
}
function uonix_intelligence_ai_term_kind( $taxonomy ) { return 'product_cat' === $taxonomy ? 'categoria de produtos' : 'arquivo de taxonomia'; }
function get_edit_term_link( $id, $taxonomy = '' ) { return 'https://uonix.com.br/wp-admin/term.php?taxonomy=' . $taxonomy . '&tag_ID=' . (int) $id; }

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
// Página Alvo e Sugestão (IA).
// ---------------------------------------------------------------------------
$snap_ia = uox_snapshot( array( uox_q( 'olhal inox', 6.0, 50, .0 ), uox_q( 'sem pagina', 7.0, 40, .0 ), uox_q( 'categoria olhal', 8.0, 30, .0 ) ) );
$snap_ia['search_console']['query_pages'] = array( 'olhal inox' => '/produtos/olhal/', 'categoria olhal' => '/olhal-de-ancoragem/' );
$GLOBALS['uox_ia'] = array(
	'olhal inox' => array( 'status' => 'ok', 'title' => 'Olhal <script>x</script>', 'description' => 'Descrição sugerida', 'current_title' => 'Título atual', 'current_description' => '', 'generated_at' => '2026-10-01T09:00:00+00:00', 'post_id' => 10 ),
	'sem pagina' => array( 'status' => 'no_page' ),
);
$html_ia = uox_render_intelligence( 'intelligence', $snap_ia );
uox_assert( false !== strpos( $html_ia, '>Página Alvo<' ) && false !== strpos( $html_ia, '>Sugestão (IA)<' ), 'Tabela ganha as colunas Página Alvo e Sugestão (IA)' );
// O stub de esc_url deste teste não converte `&`; o que importa aqui é o link existir.
uox_assert( false !== strpos( $html_ia, 'href="https://uonix.com.br/produtos/olhal/"' ) && false !== strpos( $html_ia, 'post.php?post=10&action=edit' ), 'Página Alvo traz link para a página e para editar' );
uox_assert( false !== strpos( $html_ia, 'Título atual' ) && false !== strpos( $html_ia, 'Descrição sugerida' ) && false !== strpos( $html_ia, 'revise antes de publicar' ), 'Sugestão mostra atual e sugerido, com o aviso de revisão' );
uox_assert( false === strpos( $html_ia, '<script>x' ) && false !== strpos( $html_ia, '&lt;script&gt;' ), 'Texto vindo da IA sai escapado' );
uox_assert( false !== strpos( $html_ia, 'ESTADO-IA:no_page' ) && false !== strpos( $html_ia, 'Não identificada' ), 'Sem página: estado da IA e Página Alvo não identificada' );
uox_assert( false !== strpos( $html_ia, 'term.php?taxonomy=product_cat&tag_ID=34' ) && false !== strpos( $html_ia, '>Editar categoria de produtos<' ), '#344: página de categoria leva ao editor do termo' );
uox_assert( false === strpos( $html_ia, 'Diferenciais a acrescentar' ), 'A coluna determinística saiu (#309)' );
$html_sem_mapa = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'olhal inox', 6.0, 50, .0 ) ) ) );
uox_assert( false !== strpos( $html_sem_mapa, 'Aguardando a próxima sincronização' ), 'Snapshot sem query_pages: Página Alvo aguardando a sincronização' );
$GLOBALS['uox_ia'] = array();

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
// Handler de destinatários: só o dono do ksio.dev, nonce, persistência.
// ---------------------------------------------------------------------------
// Administrador com o Insights liberado, mas que não é o dono: esconder o formulário
// não bloqueia um POST direto, então o handler recusa.
$GLOBALS['uox_can']            = true;
$GLOBALS['uox_ksio_pode']      = true;
$GLOBALS['uox_dono']           = false;
$GLOBALS['uox_dono_consultas'] = 0;
$GLOBALS['uox_referer_ok']     = true;
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_options']        = array();
$_POST = array( 'uonix_recipients' => 'intruso@example.test' );
// Sem a guarda o handler seguiria até o redirect; o teste precisa reprovar pela
// asserção, não por exceção não capturada.
$interrompeu = false;
try {
	uonix_intelligence_save_recipients();
} catch ( Uox_Die_Exception $e ) {
	$interrompeu = true;
} catch ( Uox_Redirect_Exception $e ) {
	$interrompeu = false;
}
uox_assert( $interrompeu, 'Handler de quem não é o dono deveria interromper' );
uox_assert( array() === $GLOBALS['uox_options'], 'Handler de quem não é o dono não grava' );
uox_assert( $GLOBALS['uox_dono_consultas'] > 0, 'Handler consulta a regra do dono' );
uox_assert( null === $GLOBALS['uox_referer_action'], 'A guarda do dono vem antes do nonce' );
$GLOBALS['uox_dono'] = true;

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
// Painel de configurações: formulário apenas para o dono.
// ---------------------------------------------------------------------------
$GLOBALS['uox_can']  = true;
$GLOBALS['uox_dono'] = true;
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
// Este teste não carrega o 50, e 57 e 58 não enviam sem ele: a tela tem de dizer isso,
// sem erro de PHP.
uox_assert( false !== strpos( $cfg, 'uonix-license-notice' ) && false !== strpos( $cfg, 'não carregou' ), 'Sem o arquivo da licença o painel avisa que o envio está pausado' );
// Sem o 50 não há regra nem handler: o bloco da licença some, mesmo para o dono.
uox_assert( false === strpos( $cfg, 'uonix_intelligence_save_license' ) && false === strpos( $cfg, 'uonix-license-settings' ), 'Sem o arquivo da licença o bloco da licença não é renderizado' );
uox_assert( false === strpos( $cfg, 'Somente leitura' ), 'O dono não vê o aviso de somente leitura' );

// A ação do nonce tem que ser a mesma nas duas pontas, senão nenhuma gravação
// legítima passa e a tela recusa tudo em silêncio.
uox_assert( array() !== $GLOBALS['uox_nonce_actions'], 'O painel emitiu pelo menos uma ação de nonce' );
uox_assert( null !== $GLOBALS['uox_referer_action'], 'O handler verificou o nonce com uma ação' );
uox_assert( in_array( $GLOBALS['uox_referer_action'], $GLOBALS['uox_nonce_actions'], true ), 'A ação verificada pelo handler de destinatários é uma das emitidas pelo painel' );

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

// Administrador que não é o dono lê a aba como o editor: sem formulário nenhum.
$GLOBALS['uox_can']  = true;
$GLOBALS['uox_dono'] = false;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
$cfg_ro = (string) ob_get_clean();
uox_assert( false === strpos( $cfg_ro, '<form' ), 'Administrador que não é o dono não vê formulário' );
uox_assert( false === strpos( $cfg_ro, 'Enviar Teste Agora' ), 'Administrador que não é o dono não vê o envio de teste' );
uox_assert( false !== strpos( $cfg_ro, 'Somente leitura' ), 'Quem não é o dono vê que a aba é somente leitura' );
// Os endereços não são segredo: quem só pode VISUALIZAR o painel (a governança do
// ksio.dev já decide quem chega até aqui) vê a lista completa, sem máscara.
uox_assert( false !== strpos( $cfg_ro, 'cassio@uonix.com.br' ), 'Quem não é o dono continua vendo o endereço completo: não é segredo' );
uox_assert( false !== strpos( $cfg_ro, 'Agendamento' ), 'Quem não é o dono continua vendo o agendamento' );
$GLOBALS['uox_dono'] = true;

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

// ---------------------------------------------------------------------------
// Bloco da licença, com o 50 carregado: só o dono vê, e o formulário casa com o handler.
// ---------------------------------------------------------------------------
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';

function uox_render_settings() {
	ob_start();
	uonix_intelligence_render_settings_panel( 'settings' );
	return (string) ob_get_clean();
}

$GLOBALS['uox_options']       = array( 'uonix_intelligence_license' => array( 'status' => 'trial', 'valid_until' => '2099-10-31' ) );
$GLOBALS['uox_nonce_actions'] = array();
$GLOBALS['uox_dono']          = true;
$_GET                         = array();
$lic = uox_render_settings();
uox_assert( false !== strpos( $lic, 'id="uonix-license-settings"' ), 'O dono vê o bloco da licença' );
uox_assert( false !== strpos( $lic, 'name="action" value="uonix_intelligence_save_license"' ), 'O formulário da licença declara a ação do handler' );
uox_assert( in_array( 'uonix_intelligence_save_license', $GLOBALS['uox_nonce_actions'], true ), 'O formulário da licença emite o nonce da ação' );
uox_assert( 1 === preg_match( '#<option value="trial" selected>#', $lic ), 'O status gravado vem selecionado' );
uox_assert( 1 === substr_count( $lic, ' selected>' ), 'Só um status vem selecionado' );
uox_assert( 4 === preg_match_all( '#<option value="(none|active|trial|suspended)"#', $lic ), 'O seletor oferece sem controle (none), active, trial e suspended' );
uox_assert( false === strpos( $lic, '<option value=""' ), 'Com a opção válida, não há opção de status vazio' );
uox_assert( false === strpos( $lic, ' required' ), 'Com a opção válida, sempre há um status real marcado, e o seletor não precisa de required' );
uox_assert( false === strpos( $lic, 'está malformada' ), 'Com a opção válida, não há aviso de opção malformada' );
uox_assert( false !== strpos( $lic, 'type="date" id="uonix-license-valid-until" name="uonix_license_valid_until" value="2099-10-31"' ), 'A data gravada vem no campo de data' );
uox_assert( false !== strpos( $lic, 'Cortesia (trial) até 31/10/2099, pelo painel do Uônix Insights.' ), 'O estado que vale diz de onde vem' );
uox_assert( false !== strpos( $lic, '<th scope="row">wp-config.php</th>' ) && false !== strpos( $lic, 'Sem controle.' ), 'A linha da constante aparece separada da do painel' );
uox_assert( false !== strpos( $lic, 'O painel só restringe.' ), 'O bloco explica que o painel só restringe' );
uox_assert( false === strpos( $lic, 'não carregou' ), 'Com o 50 carregado, o aviso de controle ausente some' );

// O handler verifica o mesmo nonce que o formulário emite.
$GLOBALS['uox_referer_action'] = null;
$GLOBALS['uox_referer_ok']     = true;
$_POST = array( 'uonix_license_status' => 'active', 'uonix_license_valid_until' => '' );
try {
	uonix_intelligence_save_license();
} catch ( Uox_Redirect_Exception $e ) {
	// esperado
}
uox_assert( in_array( $GLOBALS['uox_referer_action'], $GLOBALS['uox_nonce_actions'], true ), 'A ação verificada pelo handler da licença é uma das emitidas pelo painel' );

// Avisos vêm de mapa fixo; o valor da URL nunca é impresso.
$avisos = array(
	'salvo'        => array( array( 'uonix_license_saved' => '1' ), 'Licença do painel salva.' ),
	'removido'     => array( array( 'uonix_license_saved' => 'cleared' ), 'Licença do painel removida' ),
	'erro status'  => array( array( 'uonix_license_error' => 'status' ), 'status desconhecido' ),
	'erro data'    => array( array( 'uonix_license_error' => 'date' ), 'formato AAAA-MM-DD' ),
	'erro trial'   => array( array( 'uonix_license_error' => 'trial' ), 'cortesia (trial) exige data-limite' ),
	'erro ausente' => array( array( 'uonix_license_error' => 'missing' ), 'escolha o status do painel' ),
);
foreach ( $avisos as $caso => $par ) {
	$_GET = $par[0];
	uox_assert( false !== strpos( uox_render_settings(), $par[1] ), "Aviso da licença, {$caso}" );
}
$_GET = array( 'uonix_license_saved' => '<script>x</script>', 'uonix_license_error' => '<b>y</b>' );
$lic_xss = uox_render_settings();
uox_assert( false === strpos( $lic_xss, '<script>x' ) && false === strpos( $lic_xss, '<b>y' ) && false === strpos( $lic_xss, 'Nada foi salvo' ) && false === strpos( $lic_xss, 'Licença do painel' ), 'Chave desconhecida na URL não gera aviso nem é impressa' );

// Aviso do envio de teste: sucesso e falha vêm dos textos fixos.
$_GET = array( 'uonix_test_sent' => '1', 'uonix_test_recipients' => '2' );
uox_assert( false !== strpos( uox_render_settings(), 'Relatório de teste enviado para 2 destinatário(s).' ), 'Aviso do envio de teste, sucesso' );
$_GET = array( 'uonix_test_sent' => '0', 'uonix_test_reason' => 'no_recipients' );
uox_assert( false !== strpos( uox_render_settings(), 'Nenhum destinatário cadastrado, então nada foi enviado.' ), 'Aviso do envio de teste, falha com motivo conhecido' );

// Marcador em array na URL (`?uonix_license_saved[]=1`): nenhum Warning e nenhum
// aviso escolhido (#321). O handler captura tudo, para não depender do error_reporting.
$uox_erros_php = array();
set_error_handler(
	static function ( $errno, $errstr ) use ( &$uox_erros_php ) {
		$uox_erros_php[] = $errstr;
		return true;
	}
);
$_GET = array(
	'uonix_license_saved' => array( '1' ),
	'uonix_license_error' => array( 'date' ),
	'uonix_test_sent'     => array( '1' ),
	'uonix_test_reason'   => array( 'no_recipients' ),
);
$lic_array = uox_render_settings();
restore_error_handler();
uox_assert( array() === $uox_erros_php, 'Marcador em array na URL não emite aviso do PHP: ' . implode( ' | ', $uox_erros_php ) );
uox_assert( false === strpos( $lic_array, 'Licença do painel salva.' ) && false === strpos( $lic_array, 'formato AAAA-MM-DD' ), 'Marcador de licença em array não escolhe aviso' );
uox_assert( false === strpos( $lic_array, 'Relatório de teste enviado' ) && false === strpos( $lic_array, 'Nenhum destinatário cadastrado, então' ), 'Marcador de envio de teste em array não vale como sucesso nem escolhe motivo' );

// Opção malformada (#322): aviso acima do formulário, e o seletor não vem
// pré-marcado. Salvar com "sem controle" ou com o status aparente tiraria a pausa.
$malformadas = array(
	'não é array'         => 'lixo',
	'active sem a data'   => array( 'status' => 'active' ),
	'status desconhecido' => array( 'status' => 'ativo', 'valid_until' => '' ),
	'trial sem data'      => array( 'status' => 'trial', 'valid_until' => '' ),
);
foreach ( $malformadas as $caso => $gravada ) {
	$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $gravada );
	$_GET = array();
	$lic_ruim = uox_render_settings();
	uox_assert( false !== strpos( $lic_ruim, 'A licença gravada no painel está malformada e pausa o envio.' ), "Opção malformada ({$caso}): aviso acima do formulário" );
	uox_assert( 1 === preg_match( '#<option value="" disabled selected>#', $lic_ruim ) && 1 === substr_count( $lic_ruim, ' selected>' ), "Opção malformada ({$caso}): só o marcador vazio vem selecionado" );
	uox_assert( false !== strpos( $lic_ruim, 'name="uonix_license_status" required' ), "Opção malformada ({$caso}): o seletor exige escolher o status" );
	uox_assert( false !== strpos( $lic_ruim, 'Configuração inválida. Pausa o envio.' ), "Opção malformada ({$caso}): a linha do painel diz que pausa" );
}

// Opção válida que pausa (vencida ou suspensa) NÃO é malformada: sem aviso, e o
// status gravado vem marcado. O detector não pode tratar toda pausa como defeito.
$pausadas = array(
	'vencida'  => array( array( 'status' => 'active', 'valid_until' => '2020-01-31' ), 'active' ),
	'suspensa' => array( array( 'status' => 'suspended', 'valid_until' => '' ), 'suspended' ),
);
foreach ( $pausadas as $caso => $par ) {
	$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $par[0] );
	$_GET = array();
	$lic_pausa = uox_render_settings();
	uox_assert( false === strpos( $lic_pausa, 'está malformada' ) && false === strpos( $lic_pausa, '<option value=""' ), "Licença {$caso}: sem aviso de malformada e sem marcador vazio" );
	uox_assert( 1 === preg_match( '#<option value="' . $par[1] . '" selected>#', $lic_pausa ) && 1 === substr_count( $lic_pausa, ' selected>' ), "Licença {$caso}: o status gravado vem marcado" );
}

// Sem opção gravada: "sem controle" vem marcado, sem aviso.
$GLOBALS['uox_options'] = array();
$_GET = array();
$lic_sem = uox_render_settings();
uox_assert( 1 === preg_match( '#<option value="none" selected>#', $lic_sem ) && 1 === substr_count( $lic_sem, ' selected>' ), 'Sem opção gravada: sem controle vem selecionado' );
uox_assert( false === strpos( $lic_sem, 'está malformada' ) && false === strpos( $lic_sem, '<option value=""' ), 'Sem opção gravada: sem aviso e sem marcador vazio' );

// Quem não é o dono não vê o bloco, com ou sem manage_options.
foreach ( array( true, false ) as $pode ) {
	$GLOBALS['uox_can']  = $pode;
	$GLOBALS['uox_dono'] = false;
	$lic_ro = uox_render_settings();
	uox_assert( false === strpos( $lic_ro, 'uonix-license-settings' ) && false === strpos( $lic_ro, 'uonix_intelligence_save_license' ), 'Quem não é o dono não vê o bloco da licença (manage_options: ' . var_export( $pode, true ) . ')' );
}
$GLOBALS['uox_can']  = true;
$GLOBALS['uox_dono'] = true;

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: abas da Central de Inteligência renderizadas com procedência, escape, e configurações e licença só para o dono.\n";
