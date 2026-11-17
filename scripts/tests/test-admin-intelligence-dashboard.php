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

// Registra capacidade e objeto: o link do editor do termo exige `edit_term` sobre o termo.
$GLOBALS['uox_caps'] = array();
function current_user_can( $capability, ...$args ) { $GLOBALS['uox_caps'][] = array( $capability, $args[0] ?? null ); return (bool) $GLOBALS['uox_can']; }
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
// `wp_date` real usa o fuso do site (America/Sao_Paulo em produção); o teste faz o mesmo, para a tela do
// Radar poder ser conferida contra o Módulo 3 (#386). O fuso é de teste e se troca em $GLOBALS['uox_fuso'].
$GLOBALS['uox_fuso'] = 'America/Sao_Paulo';
function wp_date( $format, $ts = null ) {
	$momento = new DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) );
	return $momento->setTimezone( new DateTimeZone( $GLOBALS['uox_fuso'] ) )->format( $format );
}
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
// O stub devolve o que o painel entregou ao texto do estado, com os campos da cota (#403): o teste do fio
// confere `reason` e `quota_source`, e não só a hora.
function uonix_intelligence_ai_state_message( $status, $detalhe = array(), $now = null ) {
	$extra = '';
	foreach ( array( 'reason', 'quota_source' ) as $campo ) {
		$extra .= isset( $detalhe[ $campo ] ) ? '#' . $campo . '=' . $detalhe[ $campo ] : '';
	}
	return 'ESTADO-IA:' . $status . ( isset( $detalhe['retry_at'] ) ? '@' . $detalhe['retry_at'] : '' ) . $extra;
}
function uonix_intelligence_ai_page_post_id( $path ) { return '/produtos/olhal/' === $path ? 10 : 0; }
function home_url( $p = '' ) { return 'https://uonix.com.br' . $p; }
function get_edit_post_link( $id ) { return 'https://uonix.com.br/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
// #344: a página líder pode ser um termo. O 54 tem teste próprio do resolvedor; aqui
// importa que o painel leve ao editor certo.
function uonix_intelligence_ai_page_object( $path ) {
	if ( '/produtos/olhal/' === $path ) {
		return array( 'type' => 'post', 'id' => 10 );
	}
	if ( '/servico/ensaios-de-arrancamento/' === $path ) {
		return array( 'type' => 'post', 'id' => 15 );
	}
	if ( '/servico/projeto-andaime-fachadeiro/' === $path ) {
		return array( 'type' => 'post', 'id' => 16 );
	}
	return '/olhal-de-ancoragem/' === $path ? array( 'type' => 'term', 'taxonomy' => 'product_cat', 'id' => 34 ) : null;
}
function uonix_intelligence_ai_term_kind( $taxonomy ) { return 'product_cat' === $taxonomy ? 'categoria de produtos' : 'arquivo de taxonomia'; }
function get_edit_term_link( $id, $taxonomy = '' ) { return 'https://uonix.com.br/wp-admin/term.php?taxonomy=' . $taxonomy . '&tag_ID=' . (int) $id; }

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/63-admin-intelligence-content-radar.php';

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
$snap_ia = uox_snapshot( array( uox_q( 'olhal inox', 6.0, 50, .0 ), uox_q( 'sem pagina', 7.0, 40, .0 ), uox_q( 'categoria olhal', 8.0, 30, .0 ), uox_q( 'teste predial', 9.0, 20, .0 ), uox_q( 'andaime antigo', 9.5, 15, .0 ) ) );
$snap_ia['search_console']['query_pages'] = array( 'olhal inox' => '/produtos/olhal/', 'categoria olhal' => '/olhal-de-ancoragem/', 'teste predial' => '/teste-de-arrancamento', 'andaime antigo' => '/projeto-de-andaime-fachadeiro' );
$GLOBALS['uox_ia'] = array(
	'olhal inox' => array( 'status' => 'ok', 'title' => 'Olhal <script>x</script>', 'description' => 'Descrição sugerida', 'current_title' => 'Título atual', 'current_description' => '', 'generated_at' => '2026-10-01T09:00:00+00:00', 'post_id' => 10 ),
	'sem pagina' => array( 'status' => 'no_page' ),
	'categoria olhal' => array( 'status' => 'quota_exhausted', 'retry_at' => '2026-10-04T00:00:15+00:00', 'quota_source' => 'quota_id_daily' ),
	'andaime antigo' => array( 'status' => 'pending', 'object' => array( 'type' => 'post', 'id' => 16 ), 'redirected_to' => '/servico/projeto-andaime-fachadeiro/' ),
	'teste predial' => array( 'status' => 'ok', 'title' => 'Ensaio de Arrancamento | Uônix', 'description' => 'd', 'current_title' => 'Ensaio de Arrancamento com Laudo | Uônix', 'current_description' => '', 'generated_at' => '', 'post_id' => 15, 'object' => array( 'type' => 'post', 'id' => 15 ), 'redirected_to' => '/servico/ensaios-de-arrancamento/' ),
);
$html_ia = uox_render_intelligence( 'intelligence', $snap_ia );
uox_assert( false !== strpos( $html_ia, '>Página Alvo<' ) && false !== strpos( $html_ia, '>Sugestão (IA)<' ), 'Tabela ganha as colunas Página Alvo e Sugestão (IA)' );
// O stub de esc_url deste teste não converte `&`; o que importa aqui é o link existir.
uox_assert( false !== strpos( $html_ia, 'href="https://uonix.com.br/produtos/olhal/"' ) && false !== strpos( $html_ia, 'post.php?post=10&action=edit' ), 'Página Alvo traz link para a página e para editar' );
uox_assert( false !== strpos( $html_ia, 'Título atual' ) && false !== strpos( $html_ia, 'Descrição sugerida' ) && false !== strpos( $html_ia, 'revise antes de publicar' ), 'Sugestão mostra atual e sugerido, com o aviso de revisão' );
uox_assert( false === strpos( $html_ia, '<script>x' ) && false !== strpos( $html_ia, '&lt;script&gt;' ), 'Texto vindo da IA sai escapado' );
uox_assert( false !== strpos( $html_ia, 'ESTADO-IA:no_page' ) && false !== strpos( $html_ia, 'Não identificada' ), 'Sem página: estado da IA e Página Alvo não identificada' );
uox_assert( false !== strpos( $html_ia, 'ESTADO-IA:quota_exhausted@2026-10-04T00:00:15+00:00' ), '#384: o painel passa ao texto do estado o que o leitor devolveu, com a hora da renovação' );
uox_assert( false !== strpos( $html_ia, 'ESTADO-IA:quota_exhausted@2026-10-04T00:00:15+00:00#quota_source=quota_id_daily' ), '#403: o painel do Módulo 3 passa ao texto do estado o que o leitor devolveu, inclusive a origem da classificação; obteve ' . substr( $html_ia, (int) strpos( $html_ia, 'ESTADO-IA:quota_exhausted' ), 90 ) );
// A adiada pela cota (status deferred) leva ao texto do estado o motivo e a origem, e não só a hora.
$GLOBALS['uox_ia']['adiada cota'] = array( 'status' => 'deferred', 'reason' => 'quota_exhausted', 'quota_source' => 'retry_delay', 'retry_at' => '2026-10-04T00:00:15+00:00' );
$html_adiada = uox_render_intelligence( 'intelligence', uox_snapshot( array( uox_q( 'adiada cota', 6.0, 50, .0 ) ) ) );
uox_assert( false !== strpos( $html_adiada, 'ESTADO-IA:deferred@2026-10-04T00:00:15+00:00#reason=quota_exhausted#quota_source=retry_delay' ), '#403: o painel do Módulo 3 passa à adiada pela cota o motivo e a origem; obteve ' . substr( $html_adiada, (int) strpos( $html_adiada, 'ESTADO-IA:' ), 110 ) );
uox_assert( false !== strpos( $html_ia, 'term.php?taxonomy=product_cat&tag_ID=34' ) && false !== strpos( $html_ia, '>Editar categoria de produtos<' ), '#344: página de categoria leva ao editor do termo' );
uox_assert( in_array( array( 'edit_term', 34 ), $GLOBALS['uox_caps'], true ), '#344: o link do editor do termo confere edit_term sobre o termo 34 (BAIXO 3 da revisão do PR #372)' );
uox_assert( false !== strpos( $html_ia, '→ <a href="https://uonix.com.br/servico/projeto-andaime-fachadeiro/">' ) && false !== strpos( $html_ia, 'post.php?post=16&action=edit' ), '#343: fora do estado ok (pending), o painel também mostra o destino e o link de edição (BAIXO 3 da revisão do PR #373)' );
uox_assert( false !== strpos( $html_ia, '→ <a href="https://uonix.com.br/servico/ensaios-de-arrancamento/">/servico/ensaios-de-arrancamento/</a>' ) && false !== strpos( $html_ia, 'post.php?post=15&action=edit' ), '#343: endereço antigo mostra o destino do 301 e leva ao editor da página real' );
uox_assert( false === strpos( $html_ia, 'Diferenciais a acrescentar' ), 'A coluna determinística saiu (#309)' );
// #291 (MÉDIO 1 da revisão do PR #370): o painel CONSOME panel_limit. Com 7 oportunidades,
// a tabela sai com exatamente 5 linhas, e é isso que o "Mais N no painel" do e-mail promete.
$sete = array();
foreach ( range( 1, 7 ) as $i ) {
	$sete[] = uox_q( 'consulta ' . $i, 8.0, 100 - $i, .0 );
}
$html_sete = uox_render_intelligence( 'intelligence', uox_snapshot( $sete ) );
// A conta da caixa, função pura (#371), nas entradas que o 55 nunca produz mas que uma regra futura ou um
// chamador de fora poderia trazer. A caixa nunca diz menos do que a tabela mostra, e a legenda de corte só
// aparece quando há corte E a tabela tem linhas. Colunas: analysis, linhas na tabela, total e legenda esperados.
foreach ( array(
	'sem corte, 5 de 5'                        => array( array( 'matched' => 5, 'universe' => 113 ), 5, 5, 'de 113 consultas analisadas' ),
	'corte: 12 oportunidades e 5 linhas'       => array( array( 'matched' => 12, 'universe' => 113 ), 5, 12, 'de 113 consultas analisadas · a tabela mostra as 5 de maior volume' ),
	'matched menor que as linhas: não encolhe' => array( array( 'matched' => 3, 'universe' => 113 ), 5, 5, 'de 113 consultas analisadas' ),
	'matched ausente: conta as linhas'         => array( array( 'universe' => 40 ), 4, 4, 'de 40 consultas analisadas' ),
	'universe ausente'                         => array( array( 'matched' => 2 ), 2, 2, 'de 0 consultas analisadas' ),
	'matched em texto numérico'                => array( array( 'matched' => '9', 'universe' => 10 ), 5, 9, 'de 10 consultas analisadas · a tabela mostra as 5 de maior volume' ),
	'matched negativo: não encolhe'            => array( array( 'matched' => -4, 'universe' => 10 ), 5, 5, 'de 10 consultas analisadas' ),
	'matched lixo e sem linhas: zero'          => array( array( 'matched' => array( 'x' ), 'universe' => 10 ), 0, 0, 'de 10 consultas analisadas' ),
	'universe lixo: zero'                      => array( array( 'matched' => 5, 'universe' => array( 'x' ) ), 5, 5, 'de 0 consultas analisadas' ),
	'sem linhas e com matched: sem legenda'    => array( array( 'matched' => 7, 'universe' => 10 ), 0, 7, 'de 10 consultas analisadas' ),
) as $caso => $c ) {
	$caixa = uonix_intelligence_opportunity_totals( $c[0], array_fill( 0, $c[1], array( 'query' => 'x' ) ) );
	uox_assert( is_array( $caixa ) && $c[2] === ( $caixa['total'] ?? null ) && $c[3] === ( $caixa['sub'] ?? null ), "#371: {$caso}; obteve " . var_export( $caixa, true ) );
}
uox_assert( array( 'total' => 0, 'sub' => 'de 0 consultas analisadas' ) === uonix_intelligence_opportunity_totals( null, array() ), '#371: analysis que não é array não quebra' );
uox_assert( 5 === substr_count( $html_sete, '<td><strong>consulta ' ) && false !== strpos( $html_sete, '<td><strong>consulta 5</strong>' ) && false === strpos( $html_sete, '<td><strong>consulta 6</strong>' ), '#291: com 7 oportunidades o painel lista panel_limit (5); obteve ' . substr_count( $html_sete, '<td><strong>consulta ' ) );
// #371: a caixa "Oportunidades encontradas" mostra o TOTAL (`matched`), e não as linhas já cortadas
// em panel_limit. Com 7, ela diz 7 e explica que a tabela mostra as 5 de maior volume.
uox_assert( 1 === preg_match( '#uonix-kpi-title">Oportunidades encontradas</div>\s*<div class="uonix-kpi-value">7</div>#', $html_sete ), '#371: com 7 oportunidades a caixa diz 7, e não 5' );
uox_assert( false !== strpos( $html_sete, 'a tabela mostra as 5 de maior volume' ), '#371: acima do limite, a caixa diz que a tabela mostra as 5 de maior volume' );
uox_assert( false === strpos( $html, 'a tabela mostra as' ), '#371: com poucas oportunidades (1), a caixa não fala em corte' );
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

// #316: sem o 50 carregado a licença conta como inativa, então o próximo disparo diz que o envio está pausado.
$GLOBALS['uox_cron']['uonix_intelligence_weekly_report'] = time() + 3600;
ob_start();
uonix_intelligence_render_settings_panel( 'settings' );
uox_assert( false !== strpos( (string) ob_get_clean(), '(envio pausado pela licença)' ), '#316: sem o módulo da licença carregado, o próximo disparo diz que o envio está pausado' );

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

// #316: com o módulo da licença carregado, a linha do próximo disparo depende do estado da licença.
$GLOBALS['uox_dono'] = true;
$GLOBALS['uox_cron']['uonix_intelligence_weekly_report'] = time() + 3600;
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => array( 'status' => 'suspended', 'valid_until' => '' ) );
uox_assert( false !== strpos( uox_render_settings(), '(envio pausado pela licença)' ), '#316: com a licença suspensa, o próximo disparo diz que o envio está pausado' );
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => array( 'status' => 'trial', 'valid_until' => '2099-10-31' ) );
uox_assert( false === strpos( uox_render_settings(), '(envio pausado pela licença)' ), '#316: com a licença ativa, a linha não fala em pausa' );

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

// ---------------------------------------------------------------------------
// Módulo 8: seção "Radar de Pautas", no fim da aba de oportunidades.
// ---------------------------------------------------------------------------
function uox_render_radar( array $opcoes, array $get = array(), $agora = null ) {
	$GLOBALS['uox_options'] = $opcoes;
	$_GET                   = $get;
	ob_start();
	uonix_intelligence_render_radar_section( $agora );
	return (string) ob_get_clean();
}
// 2026-10-03T12:00:00Z: antes da renovação da cota dos fixtures (04/10 00:00:15 UTC).
const UOX_ANTES_DA_RENOVACAO = 1791028800;
// A célula da pauta com o relógio fixo (#403): a hora da renovação só aparece enquanto é futura.
function uox_pauta_cell( array $ai, $agora = null ) {
	ob_start();
	uonix_intelligence_render_radar_pauta_cell( array( 'ai' => $ai ), $agora );
	return (string) ob_get_clean();
}
function uox_dash_radar_cand( $consulta, array $ai, array $pagina = array() ) {
	return array(
		'key'         => uonix_intelligence_radar_query_key( $consulta ),
		'query'       => $consulta,
		'impressions' => 106,
		'clicks'      => 1,
		'position'    => 59.1,
		'page'        => $pagina + array( 'path' => '/norma-ancoragem-predial', 'kind' => 'página', 'title' => 'Norma de Ancoragem Predial', 'redirected_to' => '' ),
		'ai'          => $ai,
	);
}
$opcao_radar  = uonix_intelligence_radar_option();
$k_ap         = uonix_intelligence_radar_query_key( 'ancoragem predial' );
$ok_nova      = array( 'status' => 'ok', 'generated_at' => '2026-10-05T03:00:00+00:00', 'suggestion' => array( 'caminho' => 'nova', 'titulo' => 'Ancoragem predial: guia', 'angulo' => 'Do projeto à inspeção.', 'intencao' => 'informacional' ) );
$ok_reforco   = array( 'status' => 'ok', 'suggestion' => array( 'caminho' => 'reforcar', 'titulo' => 'Teste de arrancamento <script>', 'angulo' => 'Aprofundar o laudo & a norma.', 'intencao' => 'comercial' ) );
$estado_radar = static function ( array $cands, array $extra = array() ) use ( $opcao_radar ) {
	return array( $opcao_radar => $extra + array( 'status' => 'ok', 'updated_at' => '2026-10-05T03:00:00+00:00', 'list_updated_at' => '2026-10-05T03:00:00+00:00', 'window' => array( 'start' => '2026-07-05', 'end' => '2026-10-02' ), 'truncated' => false, 'candidates' => $cands ) );
};
// #404: a causa do erro de transporte é diagnóstico do cache; o painel não a mostra.
$cand_err_painel                = uox_dash_radar_cand( 'ancoragem estrutural', array( 'status' => 'unavailable', 'http' => 0, 'attempts' => 2, 'error' => 'http_request_failed: CAUSA-SECRETA-404 cURL error 28' ) );
$cand_ok_painel                 = uox_dash_radar_cand( 'ancoragem predial', array( 'status' => 'ok', 'generated_at' => '2026-10-05T03:00:00+00:00', 'error' => 'http_request_failed: CAUSA-SECRETA-404 de ontem', 'suggestion' => array( 'caminho' => 'nova', 'titulo' => 'Ancoragem predial: guia', 'angulo' => 'Do projeto à inspeção.', 'intencao' => 'informacional' ) ) );
$html_err_painel                = uox_render_radar( $estado_radar( array( $cand_ok_painel, $cand_err_painel ) ) );
uox_assert( false !== strpos( $html_err_painel, 'Ancoragem predial: guia' ) && false !== strpos( $html_err_painel, 'Pauta não gerada' ) && false === strpos( $html_err_painel, 'CAUSA-SECRETA-404' ) && false === strpos( $html_err_painel, 'cURL error' ), '#404: o painel do Radar não mostra a causa do erro de transporte, nem na candidata ok nem na que falhou' );
$GLOBALS['uox_can']           = true;
$GLOBALS['uox_ksio_pode']     = true;
$GLOBALS['uox_nonce_actions'] = array();
uox_assert( false !== strpos( uox_render_radar( array() ), 'O Radar ainda não rodou. Ele roda uma vez por dia.' ), 'Radar: sem execução, o painel diz que ainda não rodou' );
uox_assert( false !== strpos( uox_render_radar( $estado_radar( array() ) ), 'Nenhuma consulta nesta janela passa na regra do Radar' ), 'Radar: sem candidata, diz que é resultado' );
$html_r = uox_render_radar( $estado_radar( array(
	uox_dash_radar_cand( 'ancoragem predial', $ok_nova ),
	uox_dash_radar_cand( 'teste de arrancamento', $ok_reforco, array( 'path' => '/teste-de-arrancamento', 'kind' => 'página de serviço', 'title' => 'Ensaios de Arrancamento', 'redirected_to' => '/servico/ensaios-de-arrancamento/' ) ),
	uox_dash_radar_cand( 'barra <b>roscada</b>', array( 'status' => 'not_configured' ) ),
	uox_dash_radar_cand( 'ancoragem estrutural', array( 'status' => 'deferred' ), array( 'path' => '', 'kind' => '', 'title' => '', 'redirected_to' => '' ) ),
) ) );
uox_assert( false !== strpos( $html_r, 'id="uonix-radar-title"' ) && false !== strpos( $html_r, 'de 05/07 a 02/10' ) && false !== strpos( $html_r, 'atualizado em 05/10/2026 00:00' ) && false === strpos( $html_r, '(UTC)' ), 'Radar: título, janela e hora da lista, no fuso do site (#386: 03:00 UTC é 00:00 em São Paulo)' );
uox_assert( false !== strpos( $html_r, 'Post novo' ) && false !== strpos( $html_r, 'Ancoragem predial: guia' ) && false !== strpos( $html_r, 'Intenção: informacional. Gerada por IA em 05/10' ), 'Radar: pauta nova com título, intenção e data' );
uox_assert( false !== strpos( $html_r, 'Reforçar esta página' ) && false !== strpos( $html_r, 'Teste de arrancamento &lt;script&gt;' ) && false === strpos( $html_r, '<script>' ) && false !== strpos( $html_r, 'Aprofundar o laudo &amp; a norma.' ), 'Radar: reforço com o texto escapado' );
uox_assert( false !== strpos( $html_r, 'barra &lt;b&gt;roscada&lt;/b&gt;' ) && false === strpos( $html_r, 'barra <b>roscada</b>' ), 'Radar: consulta escapada em todo lugar, inclusive no rótulo da caixa' );
uox_assert( false !== strpos( $html_r, 'Sem a chave do Gemini: a candidata aparece sem pauta.' ) && false !== strpos( $html_r, 'Pauta não gerada. O Radar tenta de novo amanhã.' ), 'Radar: mensagem de cada estado da pauta' );
// #384: a cota esgotada tem texto próprio, com a hora da renovação; a adiada diz por que parou.
$html_cota = uox_render_radar( $estado_radar( array(
	uox_dash_radar_cand( 'cota um', array( 'status' => 'quota_exhausted', 'http' => 429, 'quota_source' => 'quota_id_daily', 'retry_at' => '2026-10-04T00:00:15+00:00' ) ),
	uox_dash_radar_cand( 'cota dois', array( 'status' => 'deferred', 'reason' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => '2026-10-04T00:00:15+00:00' ) ),
	uox_dash_radar_cand( 'falhas', array( 'status' => 'deferred', 'reason' => 'failures' ) ),
	uox_dash_radar_cand( 'cota sem hora', array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => 'não é data' ) ),
) ), array(), UOX_ANTES_DA_RENOVACAO );
uox_assert( 2 === substr_count( $html_cota, 'Cota diária do Gemini esgotada. O Radar tenta de novo amanhã.' ) && 1 === substr_count( $html_cota, 'Cota diária do Gemini esgotada. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' ), '#384 Radar: a cota esgotada diz o motivo, e a hora (no fuso do site, #386) só quando ela é válida' );
uox_assert( false !== strpos( $html_cota, 'Não pedida: a cota diária do Gemini acabou nesta execução. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' ), '#384 Radar: a adiada pela cota diz o motivo e a hora, no fuso do site (#386)' );
uox_assert( false !== strpos( $html_cota, 'Não pedida: falhas seguidas do Gemini nesta execução. O Radar tenta de novo amanhã.' ), '#384 Radar: a adiada pelas falhas diz o motivo' );
uox_assert( false === strpos( $html_cota, 'Pauta não gerada.' ), '#384 Radar: nenhum dos quatro cai no texto genérico' );
uox_assert( 2 === substr_count( $html_cota, 'A cota renova por volta de' ), '#384 Radar: hora inválida no cache não vira hora no painel' );

// #403 (B2): "diária" só com a prova do `quotaId`. A cota inferida do `retryDelay`, a entrada gravada
// antes da #403 (sem origem) e a origem malformada ficam no texto neutro, e a hora continua valendo.
$volta_cota = array( 'retry_at' => '2026-10-04T00:00:15+00:00' );
$t_prova    = uox_pauta_cell( array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily' ) + $volta_cota, UOX_ANTES_DA_RENOVACAO );
uox_assert( 'Cota diária do Gemini esgotada. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' === strip_tags( $t_prova ), '#403 Radar: com a prova do quotaId, "diária"; obteve ' . $t_prova );
foreach ( array( 'retry_delay' => array( 'quota_source' => 'retry_delay' ), 'sem origem' => array(), 'origem malformada' => array( 'quota_source' => array( 'quota_id_daily' ) ), 'origem desconhecida' => array( 'quota_source' => 'qualquer' ), 'origem vazia' => array( 'quota_source' => '' ) ) as $rotulo => $origem ) {
	$t_neutro = uox_pauta_cell( array( 'status' => 'quota_exhausted' ) + $origem + $volta_cota, UOX_ANTES_DA_RENOVACAO );
	uox_assert( 'Cota do Gemini esgotada. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' === strip_tags( $t_neutro ) && false === strpos( $t_neutro, 'diária' ), "#403 Radar: {$rotulo}: sem prova, o texto é neutro e a hora fica; obteve " . $t_neutro );
	$t_adiada_neutra = uox_pauta_cell( array( 'status' => 'deferred', 'reason' => 'quota_exhausted' ) + $origem + $volta_cota, UOX_ANTES_DA_RENOVACAO );
	uox_assert( 'Não pedida: a cota do Gemini acabou nesta execução. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' === strip_tags( $t_adiada_neutra ) && false === strpos( $t_adiada_neutra, 'diária' ), "#403 Radar: {$rotulo}: a adiada também é neutra; obteve " . $t_adiada_neutra );
}
$t_adiada_prova = uox_pauta_cell( array( 'status' => 'deferred', 'reason' => 'quota_exhausted', 'quota_source' => 'quota_id_daily' ) + $volta_cota, UOX_ANTES_DA_RENOVACAO );
uox_assert( 'Não pedida: a cota diária do Gemini acabou nesta execução. O Radar tenta de novo amanhã. A cota renova por volta de 03/10 21:00.' === strip_tags( $t_adiada_prova ), '#403 Radar: a adiada com a prova diz "diária"; obteve ' . $t_adiada_prova );
uox_assert( 'Pauta não gerada. O Radar tenta de novo amanhã.' === strip_tags( uox_pauta_cell( array( 'status' => 'unavailable', 'quota_source' => 'quota_id_daily' ) + $volta_cota, UOX_ANTES_DA_RENOVACAO ) ), '#403 Radar: a origem e a hora só valem na cota' );

// #403 (B4): depois da hora, o painel não anuncia uma renovação que já passou; na hora exata, também não.
$depois_da_renovacao = 1791115200; // 2026-10-04T12:00:00Z
$na_hora             = strtotime( '2026-10-04T00:00:15+00:00' );
foreach ( array( 'diária' => array( 'quota_source' => 'quota_id_daily' ), 'inferida' => array( 'quota_source' => 'retry_delay' ), 'sem origem' => array() ) as $rotulo => $origem ) {
	foreach ( array( 'depois' => $depois_da_renovacao, 'na hora exata' => $na_hora ) as $quando => $t_agora ) {
		$t_v1 = uox_pauta_cell( array( 'status' => 'quota_exhausted' ) + $origem + $volta_cota, $t_agora );
		$t_v2 = uox_pauta_cell( array( 'status' => 'deferred', 'reason' => 'quota_exhausted' ) + $origem + $volta_cota, $t_agora );
		uox_assert( false === strpos( $t_v1, 'renova' ) && false === strpos( $t_v1, '03/10' ) && false === strpos( $t_v2, 'renova' ) && false === strpos( $t_v2, '03/10' ), "#403 Radar: cota {$rotulo}, {$quando} da renovação: a hora sai; obteve " . $t_v1 . ' / ' . $t_v2 );
	}
}
$t_vencida = uox_pauta_cell( array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily' ) + $volta_cota, $depois_da_renovacao );
uox_assert( 'Cota diária do Gemini esgotada. O Radar tenta de novo amanhã.' === strip_tags( $t_vencida ), '#403 Radar: vencida, só a hora sai do texto; obteve ' . $t_vencida );
// Sem o parâmetro, vale o relógio do sistema: uma renovação a 1 h daqui aparece, e uma de ontem não.
$t_futura  = uox_pauta_cell( array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => gmdate( 'c', time() + 3600 ) ) );
$t_passada = uox_pauta_cell( array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => gmdate( 'c', time() - 86400 ) ) );
uox_assert( false !== strpos( $t_futura, 'A cota renova por volta de' ) && false === strpos( $t_passada, 'renova' ), '#403 Radar: sem o parâmetro, vale o relógio do sistema' );
uox_assert( false !== strpos( uox_pauta_cell( array( 'status' => 'quota_exhausted', 'retry_at' => gmdate( 'c', time() + 3600 ) ), 'amanhã' ), 'A cota renova por volta de' ), '#403 Radar: relógio que não é inteiro cai no do sistema' );
// Texto numérico e float não são relógio: o `is_int` os recusa. Com um "agora" absurdo (depois da hora), `is_numeric`
// esconderia a hora de uma renovação a 1 h daqui.
foreach ( array( '9999999999', 9999999999.0, 1.5 ) as $relogio_ruim ) {
	uox_assert( false !== strpos( uox_pauta_cell( array( 'status' => 'quota_exhausted', 'retry_at' => gmdate( 'c', time() + 3600 ) ), $relogio_ruim ), 'A cota renova por volta de' ), '#403 Radar: relógio que não é inteiro (' . var_export( $relogio_ruim, true ) . ') cai no do sistema' );
}
// A seção inteira repassa o relógio à célula: com o instante depois da renovação, nenhuma linha anuncia a hora.
$html_vencida = uox_render_radar( $estado_radar( array(
	uox_dash_radar_cand( 'cota um', array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => '2026-10-04T00:00:15+00:00' ) ),
	uox_dash_radar_cand( 'cota dois', array( 'status' => 'deferred', 'reason' => 'quota_exhausted', 'quota_source' => 'retry_delay', 'retry_at' => '2026-10-04T00:00:15+00:00' ) ),
) ), array(), $depois_da_renovacao );
uox_assert( false !== strpos( $html_vencida, 'Cota diária do Gemini esgotada.' ) && false !== strpos( $html_vencida, 'Não pedida: a cota do Gemini acabou' ) && false === strpos( $html_vencida, 'renova' ), '#403 Radar: a seção repassa o relógio, e nenhuma linha anuncia uma renovação vencida' );
uox_assert( false !== strpos( $html_r, 'Endereço antigo /teste-de-arrancamento redireciona para cá.' ) && false !== strpos( $html_r, 'post.php?post=15&action=edit' ), 'Radar: 301 mostra o destino e leva ao editor dele' );
uox_assert( false !== strpos( $html_r, 'Sem página do site para esta consulta.' ), 'Radar: sem página líder' );
uox_assert( 1 === substr_count( $html_r, 'value="uonix_intelligence_radar_dismiss"' ) && 4 === substr_count( $html_r, 'name="uonix_radar_keys[]"' ) && 4 === substr_count( $html_r, 'name="uonix_radar_key"' ) && false !== strpos( $html_r, 'Descartar selecionadas' ) && false !== strpos( $html_r, 'id="uonix-radar-select-all"' ) && in_array( 'uonix_intelligence_radar_dismiss', $GLOBALS['uox_nonce_actions'], true ), 'Radar: um formulário, uma caixa por linha, selecionar todas, Descartar selecionadas e o Descartar de cada linha' );
uox_assert( 2 === substr_count( $html_r, 'value="' . $k_ap . '"' ), 'Radar: a caixa e o botão da linha levam a chave da pauta' );
$GLOBALS['uox_ksio_pode'] = false;
uox_assert( false === strpos( uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'ancoragem predial', $ok_nova ) ) ) ), 'uonix_intelligence_radar_dismiss' ), 'Radar: sem acesso à ferramenta, sem botão' );
$GLOBALS['uox_ksio_pode'] = true;
$GLOBALS['uox_can']       = false;
$sem_cap                  = uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'teste de arrancamento', $ok_reforco, array( 'path' => '/teste-de-arrancamento', 'kind' => 'página de serviço', 'title' => 'Ensaios de Arrancamento', 'redirected_to' => '/servico/ensaios-de-arrancamento/' ) ) ) ) );
uox_assert( false === strpos( $sem_cap, 'uonix_intelligence_radar_dismiss' ) && false === strpos( $sem_cap, 'Editar página' ), 'Radar: sem permissão, nem botão nem link de edição' );
$GLOBALS['uox_can'] = true;
$html_d             = uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'ancoragem predial', $ok_nova ), uox_dash_radar_cand( 'teste de ancoragem', $ok_nova ) ) ) + array( uonix_intelligence_radar_dismissed_option() => array( $k_ap => '2026-10-05T10:00:00+00:00' ) ) );
uox_assert( false !== strpos( $html_d, 'Descartadas (1)' ) && false !== strpos( $html_d, 'value="uonix_intelligence_radar_restore"' ) && false !== strpos( $html_d, 'Restaurar selecionadas' ) && 2 === substr_count( $html_d, 'name="uonix_radar_keys[]"' ) && 2 === substr_count( $html_d, 'class="wp-list-table widefat striped' ) && in_array( 'uonix_intelligence_radar_restore', $GLOBALS['uox_nonce_actions'], true ), 'Radar: a descartada sai da tabela e vai para uma tabela de "Descartadas", com caixa, Restaurar selecionadas e Restaurar' );
$html_f = uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'ancoragem predial', $ok_nova ) ), array( 'status' => 'gsc_failed', 'updated_at' => '2026-10-06T03:00:00+00:00' ) ) );
uox_assert( false !== strpos( $html_f, 'Search Console indisponível na última execução. Mostrando a lista de 05/10.' ) && false !== strpos( $html_f, 'Ancoragem predial: guia' ), 'Radar: falha do Search Console mostra a lista anterior, com a data dela' );
uox_assert( false !== strpos( uox_render_radar( array( $opcao_radar => array( 'status' => 'config_missing', 'updated_at' => '2026-10-06T03:00:00+00:00' ) ) ), 'Search Console indisponível. O Radar tenta de novo amanhã.' ), 'Radar: falha sem lista anterior' );
uox_assert( false !== strpos( uox_render_radar( $estado_radar( array(), array( 'truncated' => true ) ) ), 'veio no limite de linhas' ), 'Radar: corte de linhas avisado' );
uox_assert( false !== strpos( uox_render_radar( $estado_radar( array() ), array( 'uonix_radar' => 'dismiss' ) ), '1 pauta descartada.' ) && false !== strpos( uox_render_radar( $estado_radar( array() ), array( 'uonix_radar' => 'dismiss', 'uonix_radar_n' => '3' ) ), '3 pautas descartadas.' ) && false !== strpos( uox_render_radar( $estado_radar( array() ), array( 'uonix_radar' => 'restore', 'uonix_radar_n' => '2' ) ), '2 pautas restauradas.' ) && false === strpos( uox_render_radar( $estado_radar( array() ), array( 'uonix_radar' => array( 'x' ) ) ), 'descartada' ), 'Radar: aviso com o número depois de descartar ou restaurar, e marcador malformado ignorado' );
// #386: a tela inteira usa o fuso do site; o Radar não mostra mais UTC ao lado do horário local do Módulo 3.
$ok_fuso   = array( 'status' => 'ok', 'generated_at' => '2026-10-02T16:25:00+00:00', 'suggestion' => array( 'caminho' => 'nova', 'titulo' => 'Pauta do fuso', 'angulo' => 'Ângulo.', 'intencao' => 'informacional' ) );
$estado_fz = $estado_radar( array( uox_dash_radar_cand( 'consulta do fuso', $ok_fuso ) ), array( 'updated_at' => '2026-10-02T16:25:00+00:00', 'list_updated_at' => '2026-10-02T16:25:00+00:00' ) );
$html_fz   = uox_render_radar( $estado_fz );
uox_assert( false !== strpos( $html_fz, 'atualizado em 02/10/2026 13:25' ) && false === strpos( $html_fz, '(UTC)' ), '#386: 16:25 UTC aparece como 13:25 no fuso do site, sem "(UTC)"; obteve ' . substr( $html_fz, (int) strpos( $html_fz, 'atualizado em' ), 60 ) );
uox_assert( false !== strpos( $html_fz, 'Gerada por IA em 02/10' ), '#386: a data da pauta gerada vem do fuso do site' );
$GLOBALS['uox_fuso'] = 'UTC';
uox_assert( false !== strpos( uox_render_radar( $estado_fz ), 'atualizado em 02/10/2026 16:25' ), '#386: com o site em UTC, a mesma lista mostra 16:25 (o fuso vem do site, e não está fixo no código)' );
$GLOBALS['uox_fuso'] = 'America/Sao_Paulo';
// A virada do dia: 01:30 UTC de 03/10 ainda é 02/10 às 22:30 no fuso do site; a data muda junto.
$estado_vd = $estado_radar( array( uox_dash_radar_cand( 'consulta da virada', array( 'status' => 'ok', 'generated_at' => '2026-10-03T01:30:00+00:00', 'suggestion' => array( 'caminho' => 'nova', 'titulo' => 'Pauta da virada', 'angulo' => 'Ângulo.', 'intencao' => 'informacional' ) ) ) ), array( 'updated_at' => '2026-10-03T01:30:00+00:00', 'list_updated_at' => '2026-10-03T01:30:00+00:00' ) );
$html_vd   = uox_render_radar( $estado_vd );
uox_assert( false !== strpos( $html_vd, 'atualizado em 02/10/2026 22:30' ) && false !== strpos( $html_vd, 'Gerada por IA em 02/10' ), '#386: 01:30 UTC de 03/10 é 22:30 de 02/10 no fuso do site, na hora e na data da pauta' );
// A falha do Search Console mostra a data da lista no fuso do site.
$html_ff = uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'consulta do fuso', $ok_fuso ) ), array( 'status' => 'gsc_failed', 'updated_at' => '2026-10-04T03:00:00+00:00', 'list_updated_at' => '2026-10-03T01:30:00+00:00' ) ) );
uox_assert( false !== strpos( $html_ff, 'Mostrando a lista de 02/10.' ), '#386: "Mostrando a lista de" também usa o fuso do site' );
// A hora de renovação da cota: 00:00:15 UTC de 04/10 é 21:00 de 03/10 no fuso do site.
$html_ct = uox_render_radar( $estado_radar( array( uox_dash_radar_cand( 'cota fuso', array( 'status' => 'quota_exhausted', 'quota_source' => 'quota_id_daily', 'retry_at' => '2026-10-04T00:00:15+00:00' ) ) ) ), array(), UOX_ANTES_DA_RENOVACAO );
uox_assert( false !== strpos( $html_ct, 'A cota renova por volta de 03/10 21:00.' ) && false === strpos( $html_ct, '(UTC)' ), '#386: a renovação da cota (00:00 UTC) aparece como 21:00 do dia anterior, sem "(UTC)"; obteve ' . substr( $html_ct, (int) strpos( $html_ct, 'A cota renova' ), 60 ) );

// #456: o ramo SEM `wp_date` do 56. A suíte define `wp_date` no escopo global, então o fallback só se alcança em
// subprocesso, com o 56 carregado sozinho (ele só declara funções, todas guardadas por `function_exists`). Sem o fuso
// do site, a hora cai em UTC e diz "(UTC)"; quem formata só a data passa `$com_fuso` falso e não leva o rótulo.
$sub_sem_wp_date = static function ( $chamadas ) {
	$codigo = 'define( "ABSPATH", "/" ); require getenv( "UOX_F" ); echo json_encode( array_map( static function ( $c ) { return uonix_intelligence_radar_time( ...$c ); }, json_decode( getenv( "UOX_C" ), true ) ) );';
	// O PHP do subprocesso roda com outro fuso (`date.timezone`): se o fallback usasse `date()` em vez de `gmdate()`, a
	// hora sairia em São Paulo e o rótulo "(UTC)" mentiria. Com `-n` o padrão seria UTC, e os dois seriam iguais.
	$proc   = proc_open( array( PHP_BINARY, '-n', '-d', 'date.timezone=America/Sao_Paulo', '-r', $codigo ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array( 'UOX_F' => dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php', 'UOX_C' => (string) json_encode( $chamadas ), 'PATH' => (string) getenv( 'PATH' ) ) );
	$saida  = is_resource( $proc ) ? stream_get_contents( $pipes[1] ) : '';
	if ( is_resource( $proc ) ) {
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
	}

	return json_decode( (string) $saida, true );
};
// 2026-10-04T00:00:15Z: a hora e a data em UTC.
$sem_fuso = $sub_sem_wp_date( array(
	array( 1791072015, 'd/m H:i' ),
	array( 1791072015, 'd/m/Y H:i' ),
	array( 1791072015, 'd/m', false ),
	array( 1791072015, 'd/m H:i', false ),
	array( 1791072015, 'd/m', true ),
) );
uox_assert( array( '04/10 00:00 (UTC)', '04/10/2026 00:00 (UTC)', '04/10', '04/10 00:00', '04/10 (UTC)' ) === $sem_fuso, '#456: sem wp_date, a hora cai em UTC e diz "(UTC)"; a data sem hora (`$com_fuso` falso) não leva o rótulo, e `$com_fuso` é o que decide, não o formato; obteve ' . var_export( $sem_fuso, true ) );
// O contraste: com `wp_date` (o escopo desta suíte), nunca há rótulo, com ou sem `$com_fuso`.
uox_assert( '03/10 21:00' === uonix_intelligence_radar_time( 1791072015, 'd/m H:i' ) && '03/10' === uonix_intelligence_radar_time( 1791072015, 'd/m', false ) && '03/10' === uonix_intelligence_radar_time( 1791072015, 'd/m', true ), '#456: com `wp_date`, o fuso do site vale e nenhum rótulo aparece' );

// #457: são dois helpers de fuso de propósito (o 56 trata o 54 como opcional), então o que os mantém iguais é um teste, e
// não um comentário. Os dois rodam no MESMO processo, com os módulos reais, e a hora tem de sair igual: com `wp_date` em
// dois fusos do site e sem `wp_date` (o fallback em UTC, com o rótulo). A diferença documentada é só o `$com_fuso` do 56.
$sub_irmaos = static function ( $com_wp_date, $fuso ) {
	$codigo = 'define( "ABSPATH", "/" ); '
		. ( $com_wp_date ? 'function wp_date( $f, $t = null ) { return ( new DateTimeImmutable( "@" . (int) $t ) )->setTimezone( new DateTimeZone( getenv( "UOX_TZ" ) ) )->format( $f ); } ' : '' )
		. 'function add_action( ...$a ) {} function add_filter( ...$a ) {} require getenv( "UOX_54" ); require getenv( "UOX_56" ); '
		. '$s = array(); foreach ( array( 1791072015, 1791028800, 1767225600, 1782864000 ) as $t ) { foreach ( array( "d/m H:i", "d/m/Y H:i", "H:i" ) as $f ) { $s[] = array( uonix_intelligence_site_time( $t, $f ), uonix_intelligence_radar_time( $t, $f ) ); } } echo json_encode( $s );';
	$raiz = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/';
	$proc = proc_open( array( PHP_BINARY, '-n', '-d', 'date.timezone=America/Sao_Paulo', '-r', $codigo ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array( 'UOX_TZ' => $fuso, 'UOX_54' => $raiz . '54-admin-intelligence-ai.php', 'UOX_56' => $raiz . '56-admin-intelligence-dashboard.php', 'PATH' => (string) getenv( 'PATH' ) ) );
	$saida = is_resource( $proc ) ? stream_get_contents( $pipes[1] ) : '';
	if ( is_resource( $proc ) ) {
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
	}

	return json_decode( (string) $saida, true );
};
foreach ( array( 'wp_date em São Paulo' => array( true, 'America/Sao_Paulo' ), 'wp_date em UTC' => array( true, 'UTC' ), 'sem wp_date (UTC com rótulo)' => array( false, 'UTC' ) ) as $rotulo_i => $cfg ) {
	$pares_i = $sub_irmaos( $cfg[0], $cfg[1] );
	$iguais  = is_array( $pares_i ) && 12 === count( $pares_i );
	foreach ( (array) $pares_i as $par_i ) {
		$iguais = $iguais && is_array( $par_i ) && 2 === count( $par_i ) && is_string( $par_i[0] ) && '' !== $par_i[0] && $par_i[0] === $par_i[1];
	}
	uox_assert( $iguais, "#457: os dois helpers de fuso dizem a mesma coisa ({$rotulo_i}); obteve " . var_export( $pares_i, true ) );
}
$exemplo_i = $sub_irmaos( true, 'America/Sao_Paulo' );
uox_assert( is_array( $exemplo_i ) && '03/10 21:00' === ( $exemplo_i[0][0] ?? null ) && '03/10/2026 21:00' === ( $exemplo_i[1][0] ?? null ), '#457: o teste dos irmãos mede de verdade (00:00:15 UTC de 04/10 é 21:00 de 03/10 em São Paulo); obteve ' . var_export( $exemplo_i[0] ?? null, true ) );
$exemplo_u = $sub_irmaos( false, 'UTC' );
uox_assert( is_array( $exemplo_u ) && '04/10 00:00 (UTC)' === ( $exemplo_u[0][0] ?? null ) && '04/10 00:00 (UTC)' === ( $exemplo_u[0][1] ?? null ), '#457: sem wp_date, os dois dizem UTC com o rótulo; obteve ' . var_export( $exemplo_u[0] ?? null, true ) );

// #379: o aviso de restaurar não promete o que a lista cheia não entrega.
$cheia = array();
for ( $i = 0; $i < 11; $i++ ) {
	$cheia[] = uox_dash_radar_cand( 'consulta ' . $i, array( 'status' => 'pending' ) );
	$cheia[ $i ]['impressions'] = 200 - $i;
}
$html_cheia = uox_render_radar( $estado_radar( $cheia ), array( 'uonix_radar' => 'restore', 'uonix_radar_n' => '1' ) );
uox_assert( false !== strpos( $html_cheia, '1 pauta restaurada. A lista só mostra as de mais impressões, e 1 pauta ficou de fora. A pauta da IA que ainda faltar é gerada na próxima execução do Radar.' ), '#379: lista cheia: o aviso diz quantas ficaram de fora, sem apontar qual; obteve ' . substr( $html_cheia, (int) strpos( $html_cheia, 'restaurad' ) - 12, 260 ) );
$cheia13 = $cheia;
$cheia13[] = uox_dash_radar_cand( 'consulta 11', array( 'status' => 'pending' ) );
$cheia13[] = uox_dash_radar_cand( 'consulta 12', array( 'status' => 'pending' ) );
$html_cheia13 = uox_render_radar( $estado_radar( $cheia13 ), array( 'uonix_radar' => 'restore', 'uonix_radar_n' => '3' ) );
uox_assert( false !== strpos( $html_cheia13, '3 pautas restauradas. A lista só mostra as de mais impressões, e 3 pautas ficaram de fora.' ), '#379: três ficaram de fora: o aviso usa o plural' );
$html_folga = uox_render_radar( $estado_radar( array_slice( $cheia, 0, 9 ) ), array( 'uonix_radar' => 'restore', 'uonix_radar_n' => '1' ) );
uox_assert( false !== strpos( $html_folga, '1 pauta restaurada. A pauta da IA que ainda faltar' ) && false === strpos( $html_folga, 'A lista só mostra as de mais impressões' ), '#379: lista com folga: o aviso não fala de lista cheia' );
$html_desc = uox_render_radar( $estado_radar( $cheia ), array( 'uonix_radar' => 'dismiss', 'uonix_radar_n' => '1' ) );
uox_assert( false !== strpos( $html_desc, '1 pauta descartada.' ) && false === strpos( $html_desc, 'A lista só mostra as de mais impressões' ), '#379: o aviso de descartar nunca fala de lista cheia' );
uox_assert( false === strpos( uox_render_radar( $estado_radar( $cheia ), array() ), 'A lista só mostra as de mais impressões' ), '#379: sem marcador na URL, nenhum aviso' );

uox_assert( false !== strpos( uox_render_radar( array( $opcao_radar => 'lixo' ) ), 'O Radar ainda não rodou' ), 'Radar: opção malformada não quebra o painel (foco de revisão 4)' );
$GLOBALS['uox_options'] = array();
$_GET                   = array();
ob_start();
uonix_intelligence_render_panel( 'intelligence' );
$aba = (string) ob_get_clean();
uox_assert( false !== strpos( $aba, 'id="uonix-radar-title"' ) && strpos( $aba, 'uonix-radar-title' ) > strpos( $aba, 'uonix-intelligence-title' ) && strpos( $aba, 'uonix-radar-title' ) < strpos( $aba, '</section>' ), 'Radar: a seção fica no fim da aba de oportunidades, dentro dela' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}

echo "PASS: abas da Central de Inteligência renderizadas com procedência, escape, e configurações e licença só para o dono.\n";
