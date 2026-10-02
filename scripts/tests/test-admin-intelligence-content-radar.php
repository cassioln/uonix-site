<?php
/**
 * Testes do Radar de Pautas (Módulo 8, 63-admin-intelligence-content-radar.php).
 *
 * Sem rede: o Search Console, o Gemini, o HEAD e a resolução de página são simulados.
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );
define( 'UONIX_GEMINI_API_KEY', 'chave-de-teste' );

$failures = 0;
function uox_rd_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class Uox_Die extends RuntimeException {}
class Uox_Redirect extends RuntimeException {
	public $url;
	public function __construct( $url ) { $this->url = $url; parent::__construct( 'redirect' ); }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://uonix.com.br' . $p; }
function esc_html__( $t, $d = '' ) { return $t; }
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['uox_actions'][] = array( $h, $c, $p, $a ); return true; }
function add_filter( $h, $c = null, $p = 10, $a = 1 ) { return true; }

$GLOBALS['uox_actions']  = array();
$GLOBALS['uox_options']  = array();
$GLOBALS['uox_autoload'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['uox_options'][ $k ] = $v; $GLOBALS['uox_autoload'][ $k ] = $a; return true; }

$GLOBALS['uox_cron']     = array();
$GLOBALS['uox_cron_rec'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['uox_cron'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['uox_cron'][ $h ] = $t; $GLOBALS['uox_cron_rec'][ $h ] = $r; return true; }

// Permissão: quem vê a Central (52) é `edit_posts` + acesso à ferramenta `analytics` (49).
$GLOBALS['uox_caps']           = array( 'edit_posts' );
$GLOBALS['uox_ferramenta']     = true;
$GLOBALS['uox_referer_ok']     = true;
$GLOBALS['uox_referer_action'] = null;
function current_user_can( $cap, ...$args ) { return in_array( $cap, $GLOBALS['uox_caps'], true ); }
function uonix_ksio_can_access_tool( $chave ) { return 'analytics' === $chave && (bool) $GLOBALS['uox_ferramenta']; }
function check_admin_referer( $acao = -1 ) {
	$GLOBALS['uox_referer_action'] = $acao;
	if ( ! $GLOBALS['uox_referer_ok'] ) {
		throw new Uox_Die( 'nonce' );
	}
	return true;
}
function wp_die( $m = '', $t = '', $a = array() ) { throw new Uox_Die( (string) $m ); }
function wp_safe_redirect( $u ) { throw new Uox_Redirect( $u ); }
function wp_unslash( $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://uonix.com.br/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

// Site simulado, para a resolução de página pelo 54 (Tarefa 5).
$GLOBALS['uox_posts'] = array();
function url_to_postid( $url ) {
	foreach ( $GLOBALS['uox_posts'] as $id => $p ) {
		if ( home_url( $p['path'] ) === $url ) {
			return $id;
		}
	}
	return 0;
}
function get_page_by_path( $slug, $output = OBJECT, $type = 'page' ) { return null; }
function get_post_status( $id ) { return $GLOBALS['uox_posts'][ $id ]['status'] ?? false; }
function get_the_title( $id ) { return $GLOBALS['uox_posts'][ $id ]['title'] ?? ''; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['uox_posts'][ $id ]['meta'][ $k ] ?? ''; }
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function get_post_type( $id ) { return $GLOBALS['uox_posts'][ $id ]['type'] ?? false; }
// HEAD simulado, no lugar de `uonix_intelligence_executive_page_status()` (59), para o 301 (#343).
$GLOBALS['uox_status'] = array();
$GLOBALS['uox_heads']  = 0;
function uonix_intelligence_executive_page_status( $path ) {
	++$GLOBALS['uox_heads'];
	return $GLOBALS['uox_status'][ $path ] ?? array( 'state' => 'unknown', 'code' => 0, 'location' => '' );
}

// O carregador do módulo, para provar que o 63 entra no array do module.php.
$GLOBALS['uox_carregou'] = array();
function uonix_mu_require_files( $dir, $files, $module ) { $GLOBALS['uox_carregou'][ $module ] = $files; }

$RAIZ = dirname( __DIR__, 2 );
require $RAIZ . '/mu-plugins/uonix-admin/module.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/54-admin-intelligence-ai.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/63-admin-intelligence-content-radar.php';

// Linhas no formato que o decodificador do 53 devolve.
function uox_rd_linha( $consulta, $impressoes, $posicao, $cliques = 0 ) {
	return array( 'keys' => array( $consulta ), 'clicks' => $cliques, 'impressions' => $impressoes, 'ctr' => 0.0, 'position' => $posicao );
}
function uox_rd_pagina( $consulta, $url, $impressoes ) {
	return array( 'keys' => array( $consulta, $url ), 'clicks' => 0, 'impressions' => $impressoes, 'ctr' => 0.0, 'position' => 30.0 );
}

// ---------------------------------------------------------------------------
// 1. Regras, nomes e carregamento.
// ---------------------------------------------------------------------------
$R = uonix_intelligence_radar_rules();
uox_rd_assert( 90 === $R['window_days'] && 3 === $R['lag_days'] && 15.0 === $R['min_position'] && 5 === $R['min_impressions'] && 10 === $R['max_candidates'] && 3 === $R['email_limit'] && 180 * DAY_IN_SECONDS === $R['emailed_ttl'] && 150 === $R['ai_budget'] && 1000 === $R['rows'] && 10 === $R['head_max'], 'Regras seguem a especificação; obteve ' . var_export( $R, true ) );
uox_rd_assert( 'uonix_intelligence_content_radar' === uonix_intelligence_radar_option() && 'uonix_intelligence_content_radar_dismissed' === uonix_intelligence_radar_dismissed_option() && 'uonix_intelligence_content_radar_emailed' === uonix_intelligence_radar_emailed_option() && 'uonix_intelligence_content_radar_daily' === uonix_intelligence_radar_hook(), 'Nomes das opções e do hook seguem o contrato' );
$lista = $GLOBALS['uox_carregou']['uonix-admin'] ?? array();
$pos63 = array_search( '63-admin-intelligence-content-radar.php', $lista, true );
uox_rd_assert( false !== $pos63, 'O module.php carrega o 63' );
uox_rd_assert( false !== $pos63 && $pos63 > array_search( '54-admin-intelligence-ai.php', $lista, true ) && $pos63 > array_search( '55-admin-intelligence-metrics.php', $lista, true ) && $pos63 > array_search( '59-admin-intelligence-executive.php', $lista, true ), 'O 63 carrega depois do 54, do 55 e do 59' );
uox_rd_assert( false === wp_next_scheduled( uonix_intelligence_radar_hook() ), 'Carregar o 63 não agenda nada' );

// ---------------------------------------------------------------------------
// 2. Janela.
// ---------------------------------------------------------------------------
uox_rd_assert( array( 'start' => '2026-07-02', 'end' => '2026-09-29' ) === uonix_intelligence_radar_window( '2026-10-02' ), 'Janela de 90 dias que termina 3 dias antes de hoje; obteve ' . var_export( uonix_intelligence_radar_window( '2026-10-02' ), true ) );
uox_rd_assert( array() === uonix_intelligence_radar_window( '2026-13-01' ) && array() === uonix_intelligence_radar_window( 'ontem' ) && array() === uonix_intelligence_radar_window( '' ), 'Data inválida não vira janela' );
uox_rd_assert( '02/07 a 29/09' === uonix_intelligence_radar_window_label( array( 'start' => '2026-07-02', 'end' => '2026-09-29' ) ) && '' === uonix_intelligence_radar_window_label( array() ), 'Rótulo da janela' );

// ---------------------------------------------------------------------------
// 3. Chave e ruído.
// ---------------------------------------------------------------------------
uox_rd_assert( uonix_intelligence_radar_query_key( 'Ancoragem  Prédial ' ) === uonix_intelligence_radar_query_key( 'ancoragem predial' ), 'Acento, caixa e espaço não mudam a chave' );
uox_rd_assert( hash( 'sha256', 'ancoragem predial' ) === uonix_intelligence_radar_query_key( 'ancoragem predial' ), 'A chave é sha256 da consulta normalizada' );
uox_rd_assert( uonix_intelligence_radar_is_key( hash( 'sha256', 'x' ) ) && ! uonix_intelligence_radar_is_key( 'abc' ) && ! uonix_intelligence_radar_is_key( strtoupper( hash( 'sha256', 'x' ) ) ) && ! uonix_intelligence_radar_is_key( array() ) && ! uonix_intelligence_radar_is_key( hash( 'sha256', 'x' ) . "\n" ), 'Chave válida: 64 hexadecimais minúsculos, nada mais' );
foreach ( array(
	'"deixe um comentário" "publicar comentário"' => 'aspas retas',
	'“ancoragem” predial'                          => 'aspas curvas',
	'site:uonix.com.br ancoragem'                   => 'site:',
	'ancoragem inurl:blog'                          => 'inurl:',
	'INTITLE:ancoragem'                             => 'intitle: em maiúsculas',
	'ancoragem intext:nbr'                          => 'intext:',
	'allinurl:ancoragem'                            => 'allinurl:',
	'allintitle:ancoragem predial'                  => 'allintitle:',
	'uonix ancoragem'                               => 'marca sem acento',
	'Uônix olhal'                                   => 'marca com acento',
) as $consulta => $caso ) {
	uox_rd_assert( uonix_intelligence_radar_is_noise( $consulta ), "Ruído: {$caso}" );
}
uox_rd_assert( ! uonix_intelligence_radar_is_noise( 'ancoragem predial' ) && ! uonix_intelligence_radar_is_noise( 'norma nbr 16325' ) && ! uonix_intelligence_radar_is_noise( 'ponto de ancoragem: como instalar' ), 'Consulta comum não é ruído, nem com dois-pontos solto' );

// ---------------------------------------------------------------------------
// 4. Seleção das candidatas.
// ---------------------------------------------------------------------------
$consultas = array(
	uox_rd_linha( 'ancoragem predial', 106, 59.1, 1 ),
	uox_rd_linha( 'pontos de ancoragem predial', 12, 49.5 ),
	uox_rd_linha( 'teste de arrancamento', 22, 31.6 ),
	uox_rd_linha( 'ensaio de arrancamento', 132, 15.0 ),
	uox_rd_linha( 'teste de ancoragem', 9, 15.01 ),
	uox_rd_linha( 'andaime fachadeiro medidas', 4, 33.9 ),
	uox_rd_linha( 'barra roscada inox fabricante', 5, 21.4 ),
	uox_rd_linha( '"deixe um comentário" "publicar comentário"', 28, 46.9 ),
	uox_rd_linha( 'uônix ancoragem', 8, 20.0 ),
	uox_rd_linha( 'olhal de ancoragem', 56, 11.2 ),
	uox_rd_linha( 'orcamento joao@example.com', 9, 40.0 ),
	uox_rd_linha( 'ancoragem 11 99999-0000', 9, 40.0 ),
	uox_rd_linha( 'ancoragem <b>predial</b> nova', 7, 40.0 ),
);
$paginas = array(
	uox_rd_pagina( 'ancoragem predial', 'https://uonix.com.br/norma-ancoragem-predial', 70 ),
	uox_rd_pagina( 'ancoragem predial', 'https://uonix.com.br/laudo-ancoragem-predial', 36 ),
	uox_rd_pagina( 'teste de arrancamento', 'https://uonix.com.br/teste-de-arrancamento', 11 ),
	uox_rd_pagina( 'teste de arrancamento', 'https://uonix.com.br/ensaio-de-arrancamento/', 11 ),
	uox_rd_pagina( 'pontos de ancoragem predial', 'https://blog.outro.com/x', 12 ),
);
$sel    = uonix_intelligence_radar_select( $consultas, $paginas );
$textos = array_column( $sel['visible'], 'query' );
uox_rd_assert( array( 'ancoragem predial', 'teste de arrancamento', 'pontos de ancoragem predial', 'teste de ancoragem', 'ancoragem predial nova', 'barra roscada inox fabricante' ) === $textos, 'Seleção: posição > 15, 5+ impressões, sem ruído, sem dado pessoal, por impressões; obteve ' . var_export( $textos, true ) );
$por = array_column( $sel['visible'], null, 'query' );
uox_rd_assert( '/norma-ancoragem-predial' === $por['ancoragem predial']['page_path'], 'Página líder é a de MAIS impressões (70), sem somar com a de 36' );
uox_rd_assert( '/ensaio-de-arrancamento/' === $por['teste de arrancamento']['page_path'], 'Empate de impressões na página: o caminho menor em ordem alfabética' );
uox_rd_assert( '' === $por['pontos de ancoragem predial']['page_path'], 'Página de outro domínio não é página líder' );
uox_rd_assert( 106 === $por['ancoragem predial']['impressions'] && 59.1 === $por['ancoragem predial']['position'] && 1 === $por['ancoragem predial']['clicks'] && uonix_intelligence_radar_query_key( 'ancoragem predial' ) === $por['ancoragem predial']['key'], 'Candidata leva as métricas da linha por consulta e a chave' );
uox_rd_assert( array( 'key', 'query', 'impressions', 'clicks', 'position', 'page_path' ) === array_keys( $sel['visible'][0] ), 'Candidata tem só os campos do contrato' );
uox_rd_assert( false === strpos( serialize( $sel ), 'joao@example.com' ) && false === strpos( serialize( $sel ), '99999' ) && false === strpos( serialize( $sel ), '<b>' ), 'E-mail, telefone e HTML de consulta nunca chegam à seleção (#253, foco de revisão 1)' );
uox_rd_assert( array() === $sel['dismissed'], 'Sem descartes, nada em dismissed' );

$dup = uonix_intelligence_radar_select( array( uox_rd_linha( 'ancoragem prédial', 40, 30.0 ), uox_rd_linha( 'ancoragem  predial', 30, 31.0 ) ), array() );
uox_rd_assert( 1 === count( $dup['visible'] ) && 'ancoragem prédial' === $dup['visible'][0]['query'], 'Duas grafias da mesma consulta viram UMA candidata, a de mais impressões (foco de revisão 2)' );

$muitas = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$muitas[] = uox_rd_linha( 'consulta ' . $i, 100 - $i, 30.0 );
}
$k1       = uonix_intelligence_radar_query_key( 'consulta 1' );
$k2       = uonix_intelligence_radar_query_key( 'consulta 2' );
$com_desc = uonix_intelligence_radar_select( $muitas, array(), array( $k1 => '2026-10-01T00:00:00+00:00', $k2 => '2026-10-01T00:00:00+00:00' ) );
uox_rd_assert( 10 === count( $com_desc['visible'] ) && 'consulta 3' === $com_desc['visible'][0]['query'] && 'consulta 12' === $com_desc['visible'][9]['query'], 'Limite de 10 contado DEPOIS de tirar as descartadas' );
uox_rd_assert( array( 'consulta 1', 'consulta 2' ) === array_column( $com_desc['dismissed'], 'query' ), 'Descartadas que ainda passam na regra ficam à parte' );

$empate = uonix_intelligence_radar_select( array( uox_rd_linha( 'zeta ancoragem', 10, 30.0 ), uox_rd_linha( 'alfa ancoragem', 10, 30.0 ) ), array() );
uox_rd_assert( array( 'alfa ancoragem', 'zeta ancoragem' ) === array_column( $empate['visible'], 'query' ), 'Empate de impressões: ordem alfabética da consulta normalizada' );
uox_rd_assert( array( 'visible' => array(), 'dismissed' => array() ) === uonix_intelligence_radar_select( array( 'lixo', array( 'keys' => array() ), array( 'keys' => array( '' ), 'impressions' => 9, 'position' => 40 ) ), array( 'lixo', array( 'keys' => array( 'a' ) ) ) ), 'Linha malformada é ignorada sem aviso' );

// ---------------------------------------------------------------------------
// 5. Busca no Search Console.
// ---------------------------------------------------------------------------
function uox_rd_resposta( array $linhas ) { return json_encode( array( 'responseAggregationType' => 'byProperty', 'rows' => $linhas ) ); }
$jan          = array( 'start' => '2026-07-02', 'end' => '2026-09-29' );
$chamadas_gsc = array();
$buscador     = static function ( $config, $periodo, $dimensao, $limite ) use ( &$chamadas_gsc, $consultas, $paginas ) {
	$chamadas_gsc[] = array( $periodo, $dimensao, $limite );
	return uox_rd_resposta( 'query' === $dimensao ? $consultas : $paginas );
};
$dados = uonix_intelligence_radar_fetch( array( 'search_console_site_url' => 'sc-domain:uonix.com.br' ), $jan, $buscador );
uox_rd_assert( is_array( $dados ) && count( $consultas ) === count( $dados['queries'] ) && count( $paginas ) === count( $dados['pages'] ) && false === $dados['truncated'], 'Busca devolve as linhas das duas chamadas' );
uox_rd_assert( array( array( $jan, 'query', 1000 ), array( $jan, array( 'query', 'page' ), 1000 ) ) === $chamadas_gsc, 'Duas chamadas: por consulta e por consulta+página, na janela, com 1.000 linhas; obteve ' . var_export( $chamadas_gsc, true ) );
uox_rd_assert( 'ancoragem predial' === $dados['queries'][0]['keys'][0] && 106 === (int) $dados['queries'][0]['impressions'], 'Linhas no formato do decodificador do 53' );
$mil = array();
for ( $i = 0; $i < 1000; $i++ ) {
	$mil[] = uox_rd_linha( 'c' . $i, 1, 1.0 );
}
$cheio = uonix_intelligence_radar_fetch( array(), $jan, static function ( $c, $p, $d, $l ) use ( $mil ) { return uox_rd_resposta( 'query' === $d ? $mil : array() ); } );
uox_rd_assert( true === $cheio['truncated'], 'Resposta com 1.000 linhas marca o corte' );
$so_uma = 0;
$erro   = uonix_intelligence_radar_fetch( array(), $jan, static function () use ( &$so_uma ) { ++$so_uma; return new WP_Error( 'google_http_500' ); } );
uox_rd_assert( is_wp_error( $erro ) && 1 === $so_uma, 'Erro na primeira chamada devolve o erro, sem fazer a segunda' );
uox_rd_assert( is_wp_error( uonix_intelligence_radar_fetch( array(), $jan, static function () { return 'não é json'; } ) ), 'Resposta que não é JSON vira erro' );
$vazia = uonix_intelligence_radar_fetch( array(), $jan, static function () { return '{"responseAggregationType":"byProperty"}'; } );
uox_rd_assert( is_array( $vazia ) && array() === $vazia['queries'] && array() === $vazia['pages'], 'Resposta sem linhas é vazio legítimo' );

// ---------------------------------------------------------------------------
// 6. Pedido e validação da pauta.
// ---------------------------------------------------------------------------
$pag   = array( 'path' => '/norma-ancoragem-predial', 'kind' => 'página', 'title' => 'Norma de Ancoragem Predial: NBR 16325 | Uônix', 'redirected_to' => '' );
$ent   = array( 'query' => 'ancoragem predial', 'impressions' => 106, 'position' => 59.1, 'page' => $pag, 'model' => 'gemini-3.8-flash' );
$corpo = uonix_intelligence_radar_request_body( $ent );
$gc    = $corpo['generationConfig'];
uox_rd_assert( 'application/json' === $gc['responseMimeType'] && 0.2 === $gc['temperature'] && 1024 === $gc['maxOutputTokens'] && array( 'thinkingBudget' => 0 ) === $gc['thinkingConfig'], 'Configuração de geração igual à medida no #329' );
uox_rd_assert( array( 'caminho', 'titulo', 'angulo', 'intencao' ) === $gc['responseSchema']['required'] && ! isset( $gc['responseSchema']['properties']['caminho']['enum'] ), 'Esquema exige as 4 chaves, sem enum: não foi medido no gemini-3.8-flash, e a validação garante' );
$txt = $corpo['contents'][0]['parts'][0]['text'];
foreach ( array( 'português do Brasil', '"reforcar"', '"nova"', 'no máximo 80', 'no máximo 300', 'Não invente norma', 'Só cite NR ou NBR', 'Sem HTML e sem link', 'Sem página atual, responda "nova"' ) as $trecho ) {
	uox_rd_assert( false !== strpos( $txt, $trecho ), "A instrução diz: {$trecho}" );
}
$uox_rd_dados = static function ( array $entrada ) {
	$t = uonix_intelligence_radar_request_body( $entrada )['contents'][0]['parts'][0]['text'];
	return json_decode( substr( $t, (int) strrpos( $t, "\n" ) + 1 ), true );
};
$json = $uox_rd_dados( $ent );
uox_rd_assert( is_array( $json ) && array( 'consulta', 'impressoes_90_dias', 'posicao_media', 'pagina_atual' ) === array_keys( $json ) && array( 'caminho' => '/norma-ancoragem-predial', 'tipo' => 'página', 'titulo' => 'Norma de Ancoragem Predial: NBR 16325 | Uônix' ) === $json['pagina_atual'], 'Os dados enviados têm só as 4 chaves; obteve ' . var_export( $json, true ) );
$com301                          = $ent;
$com301['page']['redirected_to'] = '/servico/ensaios-de-arrancamento/';
uox_rd_assert( '/servico/ensaios-de-arrancamento/' === $uox_rd_dados( $com301 )['pagina_atual']['caminho'], 'Com 301, o caminho enviado é o do destino' );
$sem_pag         = $ent;
$sem_pag['page'] = array( 'path' => '/removida', 'kind' => '', 'title' => '', 'redirected_to' => '' );
uox_rd_assert( null === $uox_rd_dados( $sem_pag )['pagina_atual'], 'Sem página resolvida, pagina_atual vai null' );

uox_rd_assert( uonix_intelligence_radar_input_hash( $ent ) === uonix_intelligence_radar_input_hash( array_merge( $ent, array( 'impressions' => 200, 'position' => 40.0 ) ) ), 'As métricas ficam fora do hash: a janela anda todo dia' );
foreach ( array( 'título' => array( 'title' => 'Outro' ), 'caminho' => array( 'path' => '/x' ), 'tipo' => array( 'kind' => 'produto' ), 'destino' => array( 'redirected_to' => '/y/' ) ) as $campo => $troca ) {
	$outra         = $ent;
	$outra['page'] = array_merge( $ent['page'], $troca );
	uox_rd_assert( uonix_intelligence_radar_input_hash( $ent ) !== uonix_intelligence_radar_input_hash( $outra ), "O hash muda com a página: {$campo}" );
}
uox_rd_assert( uonix_intelligence_radar_input_hash( $ent ) !== uonix_intelligence_radar_input_hash( array_merge( $ent, array( 'query' => 'outra' ) ) ) && uonix_intelligence_radar_input_hash( $ent ) !== uonix_intelligence_radar_input_hash( array_merge( $ent, array( 'model' => 'gemini-9-flash' ) ) ), 'O hash muda com a consulta e com o modelo' );

$boa = array( 'caminho' => 'nova', 'titulo' => 'Ancoragem predial: o que é e como funciona', 'angulo' => 'Explicar o sistema completo, do projeto à inspeção.', 'intencao' => 'informacional' );
uox_rd_assert( $boa === uonix_intelligence_radar_validate( json_encode( $boa ), true ), 'Pauta válida é aceita' );
uox_rd_assert( array_merge( $boa, array( 'titulo' => 'Título' ) ) === uonix_intelligence_radar_validate( json_encode( array_merge( $boa, array( 'titulo' => '  Título  ' ) ) ), true ), 'Espaços nas pontas são aparados' );
foreach ( array(
	'chave a mais'    => $boa + array( 'extra' => 'x' ),
	'chave faltando'  => array_diff_key( $boa, array( 'angulo' => 1 ) ),
	'caminho fora'    => array_merge( $boa, array( 'caminho' => 'reescrever' ) ),
	'intenção fora'   => array_merge( $boa, array( 'intencao' => 'curiosidade' ) ),
	'título vazio'    => array_merge( $boa, array( 'titulo' => '   ' ) ),
	'título de 81'    => array_merge( $boa, array( 'titulo' => str_repeat( 'a', 81 ) ) ),
	'ângulo de 301'   => array_merge( $boa, array( 'angulo' => str_repeat( 'a', 301 ) ) ),
	'HTML'            => array_merge( $boa, array( 'titulo' => 'Ancoragem <b>predial</b>' ) ),
	'link http'       => array_merge( $boa, array( 'angulo' => 'Veja https://exemplo.com' ) ),
	'link www'        => array_merge( $boa, array( 'angulo' => 'Veja www.exemplo.com' ) ),
	'domínio .com.br' => array_merge( $boa, array( 'angulo' => 'Compare com concorrente.com.br' ) ),
	'valor não texto' => array_merge( $boa, array( 'titulo' => 42 ) ),
) as $caso => $dados_ruins ) {
	uox_rd_assert( null === uonix_intelligence_radar_validate( json_encode( $dados_ruins ), true ), "Pauta recusada: {$caso}" );
}
uox_rd_assert( null === uonix_intelligence_radar_validate( 'não é json', true ) && null === uonix_intelligence_radar_validate( null, true ) && null === uonix_intelligence_radar_validate( '[]', true ), 'Texto que não é objeto JSON é recusado' );
uox_rd_assert( is_array( uonix_intelligence_radar_validate( json_encode( array_merge( $boa, array( 'titulo' => str_repeat( 'á', 80 ), 'angulo' => str_repeat( 'ç', 300 ) ) ) ), true ) ), '80 e 300 caracteres acentuados cabem: conta caractere, não byte' );
$reforco = array_merge( $boa, array( 'caminho' => 'reforcar' ) );
uox_rd_assert( is_array( uonix_intelligence_radar_validate( json_encode( $reforco ), true ) ) && null === uonix_intelligence_radar_validate( json_encode( $reforco ), false ), 'Reforço só vale com página resolvida (foco de revisão 3)' );

// ---------------------------------------------------------------------------
// 7. A página que aparece hoje.
// ---------------------------------------------------------------------------
$GLOBALS['uox_posts'] = array(
	20 => array( 'path' => '/norma-ancoragem-predial', 'type' => 'page', 'status' => 'publish', 'title' => 'Norma de Ancoragem Predial', 'meta' => array( 'rank_math_title' => 'Norma de Ancoragem Predial: NBR 16325 | Uônix' ) ),
	21 => array( 'path' => '/blog/zona-livre-de-queda/', 'type' => 'post', 'status' => 'publish', 'title' => 'Zona Livre de Queda', 'meta' => array() ),
	15 => array( 'path' => '/servico/ensaios-de-arrancamento/', 'type' => 'servicos', 'status' => 'publish', 'title' => 'Ensaios de Arrancamento', 'meta' => array() ),
	22 => array( 'path' => '/produtos/olhal-inox/', 'type' => 'product', 'status' => 'publish', 'title' => 'Olhal Inox', 'meta' => array() ),
	23 => array( 'path' => '/rascunho/', 'type' => 'post', 'status' => 'draft', 'title' => 'Rascunho', 'meta' => array() ),
);
$GLOBALS['uox_heads'] = 0;
$orc                  = 10;
uox_rd_assert( array( 'path' => '/norma-ancoragem-predial', 'kind' => 'página', 'title' => 'Norma de Ancoragem Predial: NBR 16325 | Uônix', 'redirected_to' => '' ) === uonix_intelligence_radar_page( 'ancoragem predial', '/norma-ancoragem-predial', $orc ), 'Página: título do Rank Math e tipo "página"' );
uox_rd_assert( 'post do blog' === uonix_intelligence_radar_page( 'x', '/blog/zona-livre-de-queda/', $orc )['kind'] && 'produto' === uonix_intelligence_radar_page( 'x', '/produtos/olhal-inox/', $orc )['kind'] && 'página de serviço' === uonix_intelligence_radar_page( 'x', '/servico/ensaios-de-arrancamento/', $orc )['kind'], 'Tipo de cada post' );
uox_rd_assert( 'Zona Livre de Queda' === uonix_intelligence_radar_page( 'x', '/blog/zona-livre-de-queda/', $orc )['title'], 'Sem meta do Rank Math, o título do post' );
uox_rd_assert( 10 === $orc && 0 === $GLOBALS['uox_heads'], 'Post achado direto não gasta HEAD' );
$GLOBALS['uox_status'] = array(
	'/teste-de-arrancamento'            => array( 'state' => 'redirect', 'code' => 301, 'location' => 'https://uonix.com.br/servico/ensaios-de-arrancamento/' ),
	'/servico/ensaios-de-arrancamento/' => array( 'state' => 'ok', 'code' => 200, 'location' => '' ),
	'/sumiu'                            => array( 'state' => 'not_found', 'code' => 404, 'location' => '' ),
);
$p301 = uonix_intelligence_radar_page( 'teste de arrancamento', '/teste-de-arrancamento', $orc );
uox_rd_assert( array( 'path' => '/teste-de-arrancamento', 'kind' => 'página de serviço', 'title' => 'Ensaios de Arrancamento', 'redirected_to' => '/servico/ensaios-de-arrancamento/' ) === $p301 && 8 === $orc, '301 do próprio domínio: a página é o destino, e 2 HEADs saem do orçamento (#343); obteve ' . var_export( array( $p301, $orc ), true ) );
$vazia = array( 'path' => '/removida', 'kind' => '', 'title' => '', 'redirected_to' => '' );
uox_rd_assert( $vazia === uonix_intelligence_radar_page( 'x', '/removida', $orc ), 'HEAD sem resposta: página não resolvida (foco de revisão 3)' );
uox_rd_assert( '' === uonix_intelligence_radar_page( 'x', '/sumiu', $orc )['title'], '404: página não resolvida' );
uox_rd_assert( '' === uonix_intelligence_radar_page( 'x', '/rascunho/', $orc )['title'], 'Rascunho não é página' );
$zero        = 0;
$heads_antes = $GLOBALS['uox_heads'];
uox_rd_assert( '' === uonix_intelligence_radar_page( 'x', '/teste-de-arrancamento', $zero )['title'] && $heads_antes === $GLOBALS['uox_heads'], 'Orçamento de HEAD esgotado: nenhum HEAD, página não resolvida' );
uox_rd_assert( array( 'path' => '', 'kind' => '', 'title' => '', 'redirected_to' => '' ) === uonix_intelligence_radar_page( 'x', '', $orc ), 'Sem caminho líder: página vazia' );

// FIM DAS SEÇÕES — as seções das tarefas seguintes entram acima desta linha.

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}
echo "PASS: Radar de Pautas (63).\n";
