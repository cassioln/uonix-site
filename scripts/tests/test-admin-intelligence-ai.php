<?php
/**
 * Testes da sugestão de Title/Description por IA (54-admin-intelligence-ai.php).
 *
 * Nenhuma chamada real ao Gemini: `wp_remote_post` devolve respostas de uma fila.
 * Contrato: docs/uonix-insights-inteligencia.md
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );
// Espaço e quebra de linha de propósito: a chave colada no wp-config.php vem assim.
define( 'UONIX_GEMINI_API_KEY', " chave-de-teste\n" );

$failures = 0;
function uox_ai_assert( $condition, $message ) {
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
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://uonix.com.br' . $p; }
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['uox_actions'][] = array( $h, $c, $p, $a ); return true; }
function add_filter( $h, $c = null, $p = 10, $a = 1 ) { return true; }

$GLOBALS['uox_options']  = array();
$GLOBALS['uox_autoload'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['uox_options'][ $k ] = $v; $GLOBALS['uox_autoload'][ $k ] = $a; return true; }

// Site simulado: id => caminho, tipo, status, título e metas. `so_por_slug` simula o
// produto que url_to_postid() não acha e get_page_by_path() acha.
$GLOBALS['uox_posts'] = array();
function url_to_postid( $url ) {
	foreach ( $GLOBALS['uox_posts'] as $id => $p ) {
		if ( empty( $p['so_por_slug'] ) && home_url( $p['path'] ) === $url ) {
			return $id;
		}
	}
	return 0;
}
function get_page_by_path( $slug, $output = OBJECT, $type = 'page' ) {
	foreach ( $GLOBALS['uox_posts'] as $id => $p ) {
		if ( $type === $p['type'] && basename( rtrim( $p['path'], '/' ) ) === $slug ) {
			return (object) array( 'ID' => $id );
		}
	}
	return null;
}
function get_post_status( $id ) { return $GLOBALS['uox_posts'][ $id ]['status'] ?? false; }
function get_the_title( $id ) { return $GLOBALS['uox_posts'][ $id ]['title'] ?? ''; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['uox_posts'][ $id ]['meta'][ $k ] ?? ''; }
function get_post( $id ) { return (object) array( 'ID' => $id ); }

// Taxonomias e termos simulados (#344). O slug `olhal-de-ancoragem` existe em TRÊS
// taxonomias, como em produção (product_cat #34, post_tag e product_tag): só a regra de
// reescrita diz qual delas a URL abre.
$GLOBALS['uox_taxonomias'] = array(
	'category'    => array( 'query_var' => 'category_name', 'public' => true ),
	'post_tag'    => array( 'query_var' => 'tag', 'public' => true ),
	'product_cat' => array( 'query_var' => 'product_cat', 'public' => true ),
	'product_tag' => array( 'query_var' => 'product_tag', 'public' => true ),
	'privada'     => array( 'query_var' => 'privada', 'public' => false ),
);
$GLOBALS['uox_termos'] = array(
	'product_cat' => array(
		'olhal-de-ancoragem' => array( 'id' => 34, 'name' => 'Olhal de Ancoragem', 'description' => '<p>Olhais em aço inox.</p>', 'meta' => array( 'rank_math_title' => 'Olhal de Ancoragem em Inox | Uônix', 'rank_math_description' => 'Olhais de ancoragem conforme a NBR 16325.' ) ),
		'acessorios'         => array( 'id' => 160, 'name' => 'Acessórios &amp; Peças', 'description' => '', 'meta' => array() ),
	),
	'post_tag'    => array( 'olhal-de-ancoragem' => array( 'id' => 501, 'name' => 'olhal de ancoragem', 'description' => '', 'meta' => array() ) ),
	'product_tag' => array( 'olhal-de-ancoragem' => array( 'id' => 702, 'name' => 'Olhal', 'description' => '', 'meta' => array() ) ),
	'category'    => array( 'normas' => array( 'id' => 9, 'name' => 'Normas', 'description' => '<p>Normas técnicas de ancoragem.</p>', 'meta' => array() ) ),
	'privada'     => array( 'x' => array( 'id' => 99, 'name' => 'Privada', 'description' => '', 'meta' => array() ) ),
);
function get_taxonomies( $args = array(), $output = 'names' ) {
	$saida = array();
	foreach ( $GLOBALS['uox_taxonomias'] as $nome => $t ) {
		if ( isset( $args['public'] ) && $args['public'] !== $t['public'] ) {
			continue;
		}
		$saida[ $nome ] = 'objects' === $output ? (object) array( 'name' => $nome, 'query_var' => $t['query_var'] ) : $nome;
	}
	return $saida;
}
function get_term_by( $campo, $valor, $taxonomia ) {
	$t = 'slug' === $campo ? ( $GLOBALS['uox_termos'][ $taxonomia ][ $valor ] ?? null ) : null;
	return null === $t ? false : (object) array( 'term_id' => $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'taxonomy' => $taxonomia, 'slug' => $valor );
}
function get_term_meta( $id, $chave, $single = false ) {
	foreach ( $GLOBALS['uox_termos'] as $termos ) {
		foreach ( $termos as $t ) {
			if ( $id === $t['id'] ) {
				return $t['meta'][ $chave ] ?? '';
			}
		}
	}
	return '';
}
$GLOBALS['wp_rewrite'] = (object) array( 'use_verbose_page_rules' => true );
// Regras na ordem em que o WordPress as grava: as do Rank Math (categorias sem base)
// antes, a regra de página por último.
function uox_regras() {
	return array(
		'olhal-de-ancoragem/?$'            => 'index.php?product_cat=olhal-de-ancoragem',
		'acessorios/?$'                    => 'index.php?product_cat=acessorios',
		'categoria/(.+?)/?$'               => 'index.php?category_name=$matches[1]',
		'tag/([^/]+)/?$'                   => 'index.php?tag=$matches[1]',
		'produto-tag/([^/]+)/?$'           => 'index.php?product_tag=$matches[1]',
		'privada/([^/]+)/?$'               => 'index.php?privada=$matches[1]',
		'servico/([^/]+)(?:/([0-9]+))?/?$' => 'index.php?post_type=servicos&name=$matches[1]&page=$matches[2]',
		'(.?.+?)(?:/([0-9]+))?/?$'         => 'index.php?pagename=$matches[1]&page=$matches[2]',
	);
}

// HTTP simulado.
$GLOBALS['uox_http']    = array();
$GLOBALS['uox_pedidos'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['uox_pedidos'][] = array( 'url' => $url, 'args' => $args );
	return array() !== $GLOBALS['uox_http'] ? array_shift( $GLOBALS['uox_http'] ) : new WP_Error( 'sem_resposta' );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

$GLOBALS['uox_cron'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['uox_cron'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['uox_cron'][ $h ] = array( $t, $r ); return true; }

$RAIZ = dirname( __DIR__, 2 );
require_once $RAIZ . '/mu-plugins/uonix-admin/53-admin-analytics-metrics.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/54-admin-intelligence-ai.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php';

function uox_posts_padrao() {
	$GLOBALS['uox_posts'] = array(
		10 => array( 'path' => '/produtos/olhal-inox/', 'type' => 'product', 'status' => 'publish', 'title' => 'Olhal Inox', 'meta' => array( 'rank_math_title' => 'Olhal de Ancoragem Inox | Uônix', 'rank_math_description' => 'Olhal para ancoragem predial.' ) ),
		11 => array( 'path' => '/rascunho/', 'type' => 'page', 'status' => 'draft', 'title' => 'Rascunho', 'meta' => array() ),
		12 => array( 'path' => '/produtos/so-slug/', 'type' => 'product', 'status' => 'publish', 'title' => 'Só por Slug', 'meta' => array(), 'so_por_slug' => true ),
		13 => array( 'path' => '/servico/teste/', 'type' => 'page', 'status' => 'publish', 'title' => 'Teste de Ancoragem', 'meta' => array( 'rank_math_title' => '%title% %sep% %sitename%' ) ),
		14 => array( 'path' => '/home-real/', 'type' => 'page', 'status' => 'publish', 'title' => 'Início', 'meta' => array() ),
	);
	$GLOBALS['uox_options']['page_on_front'] = 14;
}
function uox_linha( $query = 'olhal de ancoragem inox', $path = '/produtos/olhal-inox/' ) {
	return array( 'query' => $query, 'impressions' => 55.0, 'position' => 6.24, 'ctr' => 0.0, 'clicks' => 0.0, 'target_page' => $path );
}
uox_posts_padrao();

// ---------------------------------------------------------------------------
// 1. Configuração.
// ---------------------------------------------------------------------------
uox_ai_assert( 'chave-de-teste' === uonix_intelligence_ai_api_key(), 'A chave é aparada (espaço e quebra de linha do wp-config.php)' );
uox_ai_assert( 'gemini-3.8-flash' === uonix_intelligence_ai_model(), 'Sem UONIX_GEMINI_MODEL, o modelo é o fixo gemini-3.8-flash' );
uox_ai_assert( 'uonix_intelligence_ai_suggestions' === uonix_intelligence_ai_option() && 'uonix_intelligence_ai_daily' === uonix_intelligence_ai_hook(), 'Nomes da opção e do hook seguem o contrato' );
$lim = uonix_intelligence_ai_limits();
uox_ai_assert( 60 === $lim['title'] && 155 === $lim['description'] && 5 === $lim['per_run'] && 15 === $lim['timeout'] && 1024 === $lim['max_output_tokens'], 'Limites seguem a especificação' );

// ---------------------------------------------------------------------------
// 2. Página líder → post publicado (foco de revisão 1: 404, 301 e rascunho viram 0).
// ---------------------------------------------------------------------------
uox_ai_assert( 10 === uonix_intelligence_ai_page_post_id( '/produtos/olhal-inox/' ), 'Caminho de produto acha o post' );
uox_ai_assert( 14 === uonix_intelligence_ai_page_post_id( '/' ), 'A home vai para page_on_front' );
uox_ai_assert( 12 === uonix_intelligence_ai_page_post_id( '/produtos/so-slug/' ), 'Produto que url_to_postid não acha cai no get_page_by_path' );
uox_ai_assert( 0 === uonix_intelligence_ai_page_post_id( '/rascunho/' ), 'Post não publicado não conta' );
uox_ai_assert( 0 === uonix_intelligence_ai_page_post_id( '/olhal-de-ancoragem/' ), 'Endereço sem post (404 ou 301, medido no #301) dá 0' );
uox_ai_assert( 0 === uonix_intelligence_ai_page_post_id( '' ) && 0 === uonix_intelligence_ai_page_post_id( 'produtos/x' ), 'Caminho vazio ou sem barra inicial dá 0' );

// ---------------------------------------------------------------------------
// 3. Entrada do pedido (foco de revisão 3: template cru nunca vai).
// ---------------------------------------------------------------------------
$in = uonix_intelligence_ai_input( uox_linha() );
uox_ai_assert( is_array( $in ) && 'Olhal de Ancoragem Inox | Uônix' === $in['title'] && 'Olhal para ancoragem predial.' === $in['description'], 'Título e descrição atuais vêm do Rank Math' );
uox_ai_assert( 'https://uonix.com.br/produtos/olhal-inox/' === $in['page_url'] && 55 === $in['impressions'] && 6.2 === $in['position'] && 10 === $in['post_id'], 'Entrada traz URL, impressões inteiras, posição com uma casa e o post' );
$in_tpl = uonix_intelligence_ai_input( uox_linha( 'teste de ancoragem predial', '/servico/teste/' ) );
uox_ai_assert( is_array( $in_tpl ) && 'Teste de Ancoragem' === $in_tpl['title'], 'Título com variáveis do Rank Math sem resolvedor cai no título do post; obteve ' . var_export( $in_tpl['title'] ?? null, true ) );
uox_ai_assert( '' === $in_tpl['description'], 'Descrição vazia é aceita' );
uox_ai_assert( null === uonix_intelligence_ai_input( uox_linha( 'x', '/olhal-de-ancoragem/' ) ), 'Sem post publicado, não há entrada' );
uox_ai_assert( null === uonix_intelligence_ai_input( uox_linha( '', '/produtos/olhal-inox/' ) ), 'Sem consulta, não há entrada' );
uox_ai_assert( uonix_intelligence_ai_input_hash( $in ) !== uonix_intelligence_ai_input_hash( array_merge( $in, array( 'title' => 'Outro' ) ) ), 'O hash muda quando o título atual muda' );
foreach ( array(
	'descrição atual' => array( 'description' => 'Outra descrição.' ),
	'lista de diferenciais' => array( 'differentiators' => array( 'Aço Inox 304/316' ) ),
	'modelo' => array( 'model' => 'gemini-9-flash' ),
) as $campo => $troca ) {
	uox_ai_assert( uonix_intelligence_ai_input_hash( $in ) !== uonix_intelligence_ai_input_hash( array_merge( $in, $troca ) ), "O hash muda quando muda: {$campo} (revisão do #329, M2)" );
}
uox_ai_assert( uonix_intelligence_ai_input_hash( $in ) === uonix_intelligence_ai_input_hash( array_merge( $in, array( 'impressions' => 56, 'position' => 6.3, 'ctr' => 0.01 ) ) ), 'O hash NÃO muda quando só as métricas mudam: a janela de 30 dias anda todo dia (revisão do #329, A1)' );
uox_ai_assert( uonix_intelligence_ai_entry_key( 'a', '/b/' ) === hash( 'sha256', "a\n/b/" ), 'A chave da entrada é sha256 de consulta e caminho' );

// Fronteira de dados: campos de lead na linha nunca chegam ao corpo do pedido.
$linha_suja = uox_linha() + array( 'email' => 'cliente@example.test', 'nome' => 'Fulano de Tal', 'telefone' => '11 99999-0000' );
$corpo      = wp_json_encode( uonix_intelligence_ai_request_body( uonix_intelligence_ai_input( $linha_suja ) ), JSON_UNESCAPED_UNICODE );
uox_ai_assert( false === strpos( $corpo, 'cliente@example.test' ) && false === strpos( $corpo, 'Fulano' ) && false === strpos( $corpo, '99999' ), 'Nenhum campo de lead vai ao Gemini' );
uox_ai_assert( false === strpos( $corpo, '"post_id"' ) && false === strpos( $corpo, '"model"' ), 'post_id e model ficam fora do corpo' );
$body = uonix_intelligence_ai_request_body( $in );
$gc   = $body['generationConfig'];
uox_ai_assert( 'application/json' === $gc['responseMimeType'] && 0.2 === $gc['temperature'] && 1024 === $gc['maxOutputTokens'] && array( 'thinkingBudget' => 0 ) === $gc['thinkingConfig'], 'Configuração de geração segue a medição de 2026-09-30' );
uox_ai_assert( array( 'title', 'description', 'differentiators_used' ) === $gc['responseSchema']['required'], 'Esquema exige as três chaves' );
$texto_pedido = $body['contents'][0]['parts'][0]['text'];
uox_ai_assert( false !== strpos( $texto_pedido, 'olhal de ancoragem inox' ) && false !== strpos( $texto_pedido, 'Aço Inox 304/316' ) && false !== strpos( $texto_pedido, 'no máximo 60' ) && false !== strpos( $texto_pedido, 'no máximo 155' ), 'O pedido leva a consulta, a lista e os limites' );
// Fronteira de dados pelo conteúdo, e não por busca de texto: os dados vão como JSON
// na última linha da instrução, e só as oito chaves permitidas podem estar lá.
$json_dados = json_decode( substr( $texto_pedido, (int) strrpos( $texto_pedido, "\n" ) + 1 ), true );
uox_ai_assert( is_array( $json_dados ) && array( 'consulta', 'impressoes_30_dias', 'posicao_media', 'taxa_de_clique', 'pagina', 'titulo_atual', 'descricao_atual', 'diferenciais_permitidos' ) === array_keys( $json_dados ), 'Os dados enviados têm exatamente as oito chaves permitidas; obteve ' . var_export( is_array( $json_dados ) ? array_keys( $json_dados ) : $json_dados, true ) );

// ---------------------------------------------------------------------------
// 4. Validação (foco de revisão 4: diferencial escrito diferente é recusado).
// ---------------------------------------------------------------------------
$P  = uonix_intelligence_seo_differentiators();
$ok = array( 'title' => 'Olhal de Ancoragem em Aço Inox 304/316 | Uônix', 'description' => 'Olhal em Aço Inox 304/316 para ancoragem predial, conforme NBR 16325.', 'differentiators_used' => array( 'Aço Inox 304/316', 'Conforme NBR 16325' ) );
$v  = uonix_intelligence_ai_validate( json_encode( $ok, JSON_UNESCAPED_UNICODE ), $P );
uox_ai_assert( is_array( $v ) && $ok['title'] === $v['title'] && array( 'Aço Inox 304/316', 'Conforme NBR 16325' ) === $v['differentiators_used'], 'Resposta válida é aceita' );
$recusas = array(
	'título de 61 caracteres'           => array_merge( $ok, array( 'title' => str_repeat( 'a', 61 ) ) ),
	'título vazio'                      => array_merge( $ok, array( 'title' => '   ' ) ),
	'descrição de 156 caracteres'       => array_merge( $ok, array( 'description' => str_repeat( 'b', 156 ) ) ),
	'HTML no título'                    => array_merge( $ok, array( 'title' => 'Olhal <b>Inox</b>' ) ),
	'diferencial fora da lista'         => array_merge( $ok, array( 'differentiators_used' => array( 'Aço Inox 304/316', 'Conforme NBR 16325', 'Garantia de 10 anos' ) ) ),
	'declarado e ausente do texto'      => array_merge( $ok, array( 'differentiators_used' => array( 'Aço Inox 304/316', 'Conforme NBR 16325', 'Pronta Entrega' ) ) ),
	'presente e não declarado'          => array_merge( $ok, array( 'differentiators_used' => array( 'Aço Inox 304/316' ) ) ),
	'declarado e escrito diferente'     => array( 'title' => 'Olhal inox 304 e 316 | Uônix', 'description' => 'Olhal para ancoragem predial.', 'differentiators_used' => array( 'Aço Inox 304/316' ) ),
	'chave a mais'                      => $ok + array( 'extra' => 'x' ),
	'chave a menos'                     => array( 'title' => $ok['title'], 'description' => $ok['description'] ),
	'tipo errado'                       => array_merge( $ok, array( 'differentiators_used' => 'Aço Inox 304/316' ) ),
);
foreach ( $recusas as $caso => $dados ) {
	uox_ai_assert( null === uonix_intelligence_ai_validate( json_encode( $dados, JSON_UNESCAPED_UNICODE ), $P ), "Recusa: {$caso}" );
}
uox_ai_assert( null === uonix_intelligence_ai_validate( 'não é json', $P ) && null === uonix_intelligence_ai_validate( null, $P ), 'Recusa: texto que não é JSON, ou nulo' );
uox_ai_assert( is_array( uonix_intelligence_ai_validate( json_encode( array( 'title' => str_repeat( 'á', 60 ), 'description' => 'ok', 'differentiators_used' => array() ) ), $P ) ), '60 caracteres acentuados cabem: a contagem é por caractere, não por byte' );

// ---------------------------------------------------------------------------
// 5. Chamada HTTP.
// ---------------------------------------------------------------------------
function uox_gemini( $dados, $finish = 'STOP', $code = 200 ) {
	$texto = is_string( $dados ) ? $dados : json_encode( $dados, JSON_UNESCAPED_UNICODE );
	return array( 'code' => $code, 'body' => json_encode( array( 'candidates' => array( array( 'finishReason' => $finish, 'content' => array( 'parts' => array( array( 'text' => $texto ) ) ) ) ), 'modelVersion' => 'gemini-3.8-flash' ) ) );
}
function uox_http( $code ) { return array( 'code' => $code, 'body' => '{"error":{"status":"X"}}' ); }

$GLOBALS['uox_http']    = array( uox_gemini( $ok ) );
$GLOBALS['uox_pedidos'] = array();
$r = uonix_intelligence_ai_call( $in, 0 );
$pedido = $GLOBALS['uox_pedidos'][0] ?? array();
uox_ai_assert( 'ok' === $r['status'] && $ok['title'] === $r['suggestion']['title'], 'Resposta 200 válida vira ok' );
uox_ai_assert( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' === ( $pedido['url'] ?? '' ), 'URL do modelo fixo, sem a chave' );
uox_ai_assert( false === strpos( $pedido['url'] ?? '', 'chave-de-teste' ) && 'chave-de-teste' === ( $pedido['args']['headers']['x-goog-api-key'] ?? '' ), 'A chave vai no cabeçalho x-goog-api-key, nunca na URL' );
uox_ai_assert( 15 === ( $pedido['args']['timeout'] ?? 0 ), 'Timeout de 15 s' );

$casos_http = array(
	'503 e depois 200 (uma nova tentativa)' => array( array( uox_http( 503 ), uox_gemini( $ok ) ), 'ok', 2 ),
	'429 e depois 200'                     => array( array( uox_http( 429 ), uox_gemini( $ok ) ), 'ok', 2 ),
	'503 duas vezes'                       => array( array( uox_http( 503 ), uox_http( 503 ) ), 'unavailable', 2 ),
	'500 (sem nova tentativa)'             => array( array( uox_http( 500 ) ), 'unavailable', 1 ),
	'erro de transporte'                   => array( array( new WP_Error( 'http_request_failed' ) ), 'unavailable', 1 ),
	'404 do modelo'                        => array( array( uox_http( 404 ) ), 'model_missing', 1 ),
	'MAX_TOKENS sem texto (medido)'        => array( array( uox_gemini( '', 'MAX_TOKENS' ) ), 'unavailable', 1 ),
	'SAFETY'                               => array( array( uox_gemini( '', 'SAFETY' ) ), 'rejected', 1 ),
	'200 com resposta inválida'            => array( array( uox_gemini( array( 'title' => str_repeat( 'a', 61 ), 'description' => 'x', 'differentiators_used' => array() ) ) ), 'rejected', 1 ),
	'200 sem candidates'                   => array( array( array( 'code' => 200, 'body' => '{"promptFeedback":{}}' ) ), 'unavailable', 1 ),
);
foreach ( $casos_http as $caso => $c ) {
	$GLOBALS['uox_http']    = $c[0];
	$GLOBALS['uox_pedidos'] = array();
	$r = uonix_intelligence_ai_call( $in, 0 );
	uox_ai_assert( $c[1] === $r['status'] && $c[2] === count( $GLOBALS['uox_pedidos'] ), "HTTP {$caso}: status {$c[1]} com {$c[2]} pedido(s); obteve {$r['status']} com " . count( $GLOBALS['uox_pedidos'] ) );
}
$partes_com_pensamento = array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => 'pensando…', 'thought' => true ), array( 'text' => json_encode( $ok, JSON_UNESCAPED_UNICODE ) ) ) ) ) ) ) ) );
$GLOBALS['uox_http'] = array( $partes_com_pensamento );
uox_ai_assert( 'ok' === uonix_intelligence_ai_call( $in, 0 )['status'], 'Parte marcada como thought é ignorada' );

// ---------------------------------------------------------------------------
// 6. Execução com cache.
// ---------------------------------------------------------------------------
function uox_analise( array $linhas ) { return array( 'available' => true, 'rows' => $linhas ); }
$GLOBALS['uox_options'] = array( 'page_on_front' => 14 );
$GLOBALS['uox_http']    = array( uox_gemini( $ok ) );
$GLOBALS['uox_pedidos'] = array();
$res = uonix_intelligence_ai_run( uox_analise( array( uox_linha(), uox_linha( 'pagina morta', '/olhal-de-ancoragem/' ) ) ), 0 );
$cache = get_option( uonix_intelligence_ai_option() );
$k_ok  = uonix_intelligence_ai_entry_key( 'olhal de ancoragem inox', '/produtos/olhal-inox/' );
$k_404 = uonix_intelligence_ai_entry_key( 'pagina morta', '/olhal-de-ancoragem/' );
uox_ai_assert( 1 === $res['called'] && 'ok' === $cache[ $k_ok ]['status'] && 'no_page' === $cache[ $k_404 ]['status'], 'Primeira execução: uma chamada, página morta sem chamada (no_page)' );
uox_ai_assert( false === $GLOBALS['uox_autoload'][ uonix_intelligence_ai_option() ], 'O cache é gravado sem autoload' );
uox_ai_assert( false === strpos( serialize( $cache ), 'olhal de ancoragem inox' ) && false === strpos( serialize( $cache ), 'pagina morta' ), 'A consulta não fica em texto puro no cache (#253)' );

$GLOBALS['uox_pedidos'] = array();
$res2 = uonix_intelligence_ai_run( uox_analise( array( uox_linha() ) ), 0 );
uox_ai_assert( 0 === $res2['called'] && 0 === count( $GLOBALS['uox_pedidos'] ), 'Mesma entrada: zero chamadas' );
uox_ai_assert( ! isset( get_option( uonix_intelligence_ai_option() )[ $k_404 ] ), 'Oportunidade que saiu da lista é removida do cache' );

$GLOBALS['uox_posts'][10]['meta']['rank_math_title'] = 'Olhal Inox Novo | Uônix';
$GLOBALS['uox_http'] = array( uox_http( 503 ), uox_http( 503 ) );
$res3 = uonix_intelligence_ai_run( uox_analise( array( uox_linha() ) ), 0 );
uox_ai_assert( 1 === $res3['called'] && 'unavailable' === get_option( uonix_intelligence_ai_option() )[ $k_ok ]['status'] && ! isset( get_option( uonix_intelligence_ai_option() )[ $k_ok ]['suggestion'] ), 'Título mudou e o Gemini falhou: unavailable, e a sugestão antiga não sobrevive' );
// No dia seguinte, a mesma entrada que falhou é tentada de novo: falha transitória não trava a sugestão.
$GLOBALS['uox_http'] = array( uox_gemini( $ok ) );
$res3b = uonix_intelligence_ai_run( uox_analise( array( uox_linha() ) ), 0 );
uox_ai_assert( 1 === $res3b['called'] && 'ok' === get_option( uonix_intelligence_ai_option() )[ $k_ok ]['status'], 'Mesma entrada depois de uma falha: chama de novo na execução seguinte' );
uox_posts_padrao();

$GLOBALS['uox_options']['uonix_intelligence_ai_suggestions'] = array();
$seis = array();
foreach ( range( 1, 6 ) as $n ) {
	$seis[] = uox_linha( 'consulta ' . $n );
}
$GLOBALS['uox_http']    = array_fill( 0, 10, uox_gemini( $ok ) );
$GLOBALS['uox_pedidos'] = array();
uox_ai_assert( 5 === uonix_intelligence_ai_run( uox_analise( $seis ), 0 )['called'], 'Teto de 5 chamadas por execução' );
uox_ai_assert( array( 'called' => 0, 'skipped' => 'no_opportunities' ) === uonix_intelligence_ai_run( array( 'available' => false, 'reason' => 'snapshot_missing' ), 0 ), 'Sem oportunidades disponíveis, nada é chamado' );

// ---------------------------------------------------------------------------
// 7. Leitor (foco de revisão 5: título editado depois da geração vira pending).
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array( 'page_on_front' => 14 );
$GLOBALS['uox_http']    = array( uox_gemini( $ok ) );
uonix_intelligence_ai_run( uox_analise( array( uox_linha() ) ), 0 );
$s = uonix_intelligence_ai_suggestion_for( uox_linha() );
uox_ai_assert( 'ok' === $s['status'] && $ok['title'] === $s['title'] && 'Olhal de Ancoragem Inox | Uônix' === $s['current_title'] && 10 === $s['post_id'] && '' !== $s['generated_at'], 'Leitor devolve a sugestão com o texto atual ao lado' );
// A sincronização do dia seguinte muda só as métricas: a sugestão continua valendo, e o
// cron não chama o Gemini de novo (revisão do #329, A1).
$dia_seguinte = array_merge( uox_linha(), array( 'impressions' => 61.0, 'position' => 5.9, 'ctr' => 0.016 ) );
uox_ai_assert( 'ok' === uonix_intelligence_ai_suggestion_for( $dia_seguinte )['status'], 'Só as métricas mudaram: o leitor continua em ok' );
$GLOBALS['uox_pedidos'] = array();
uox_ai_assert( 0 === uonix_intelligence_ai_run( uox_analise( array( $dia_seguinte ) ), 0 )['called'] && 0 === count( $GLOBALS['uox_pedidos'] ), 'Só as métricas mudaram: o cron não chama o Gemini' );
$GLOBALS['uox_posts'][10]['meta']['rank_math_title'] = 'Editado à mão | Uônix';
uox_ai_assert( 'pending' === uonix_intelligence_ai_suggestion_for( uox_linha() )['status'], 'Título editado depois da geração: pending, nunca a sugestão antiga' );
$GLOBALS['uox_posts'][10]['meta']['rank_math_title'] = 'Olhal de Ancoragem Inox | Uônix';
$GLOBALS['uox_posts'][10]['meta']['rank_math_description'] = 'Descrição editada à mão.';
uox_ai_assert( 'pending' === uonix_intelligence_ai_suggestion_for( uox_linha() )['status'], 'Descrição editada depois da geração: pending, nunca a sugestão antiga (revisão do #329, M2)' );
uox_posts_padrao();
$sem_mapa = uox_linha();
$sem_mapa['target_page'] = null;
uox_ai_assert( 'pending' === uonix_intelligence_ai_suggestion_for( $sem_mapa )['status'], 'Página líder ainda não sincronizada: pending' );
uox_ai_assert( 'no_page' === uonix_intelligence_ai_suggestion_for( uox_linha( 'x', '' ) )['status'] && 'no_page' === uonix_intelligence_ai_suggestion_for( uox_linha( 'x', '/olhal-de-ancoragem/' ) )['status'], 'Sem página, ou página morta: no_page' );
uox_ai_assert( 'pending' === uonix_intelligence_ai_suggestion_for( uox_linha( 'nunca gerada' ) )['status'], 'Entrada sem cache: pending' );

// Textos de estado: um texto próprio por estado, e nenhum vazio.
$textos = array();
foreach ( array( 'not_configured', 'pending', 'unavailable', 'rejected', 'no_page', 'model_missing' ) as $estado ) {
	$textos[ $estado ] = uonix_intelligence_ai_state_message( $estado );
	uox_ai_assert( '' !== trim( $textos[ $estado ] ), "Estado {$estado} tem texto" );
}
uox_ai_assert( count( $textos ) === count( array_unique( $textos ) ), 'Cada estado tem um texto diferente' );
uox_ai_assert( false !== strpos( $textos['model_missing'], 'UONIX_GEMINI_MODEL' ) && false !== strpos( $textos['not_configured'], 'UONIX_GEMINI_API_KEY' ), 'Os textos de configuração nomeiam a constante' );

// ---------------------------------------------------------------------------
// 8. Agendamento, e sem a chave (processo separado: a constante não se redefine).
// ---------------------------------------------------------------------------
uonix_intelligence_ai_schedule();
uox_ai_assert( 'daily' === ( $GLOBALS['uox_cron'][ uonix_intelligence_ai_hook() ][1] ?? '' ), 'O cron da IA é diário' );
$registrado = array_filter( $GLOBALS['uox_actions'], static function ( $a ) { return uonix_intelligence_ai_hook() === $a[0] && 'uonix_intelligence_ai_run' === $a[1] && 0 === $a[3]; } );
uox_ai_assert( 1 === count( $registrado ), 'O hook roda uonix_intelligence_ai_run sem argumentos' );

// ---------------------------------------------------------------------------
// N. Página de categoria e tag (#344). O termo vem da regra de reescrita que a URL casa,
//    como o WordPress faz em `url_to_postid()`, e não de procurar o slug em cada taxonomia.
// ---------------------------------------------------------------------------
$termo = static function ( $caminho, $regras = null ) {
	$t = uonix_intelligence_resolve_term_path( $caminho, $regras );
	return is_array( $t ) ? $t['taxonomy'] . ':' . $t['id'] : 'nenhum';
};
$R = uox_regras();
uox_ai_assert( 'product_cat:34' === $termo( '/olhal-de-ancoragem/', $R ), '#344: o slug existe em três taxonomias e a regra diz product_cat; obteve ' . $termo( '/olhal-de-ancoragem/', $R ) );
uox_ai_assert( 'product_cat:34' === $termo( '/olhal-de-ancoragem/?utm_source=x', $R ) && 'product_cat:34' === $termo( 'https://uonix.com.br/olhal-de-ancoragem/', $R ), '#344: a consulta da URL e o domínio não atrapalham' );
uox_ai_assert( 'post_tag:501' === $termo( '/tag/olhal-de-ancoragem/', $R ) && 'product_tag:702' === $termo( '/produto-tag/olhal-de-ancoragem/', $R ), '#344: tag do blog e tag de produto com o mesmo slug, cada uma pela própria regra' );
uox_ai_assert( 'category:9' === $termo( '/categoria/blog/normas/', $R ), '#344: categoria aninhada usa o último segmento' );
uox_ai_assert( 'nenhum' === $termo( '/tag/inexistente/', $R ), '#344: termo que não existe: nenhum' );
uox_ai_assert( 'nenhum' === $termo( '/tag/acessorios/', array( 'tag/([^/]+)/?$' => 'index.php?tag=$matches[1]', 'tag/(.+?)/?$' => 'index.php?product_cat=$matches[1]' ) ), '#344: a regra que casa decide mesmo sem o termo (é 404 no core), sem tentar a regra seguinte' );
uox_ai_assert( 'nenhum' === $termo( '/privada/x/', $R ), '#344: taxonomia não pública: nenhum' );
uox_ai_assert( 'nenhum' === $termo( '/servico/teste/', $R ) && 'nenhum' === $termo( '/home-real/', $R ), '#344: a primeira regra que casa é de post ou de página: nenhum termo' );
uox_ai_assert( 'nenhum' === $termo( '/sem-pagina/', $R ), '#344: a regra de página sem página existente é pulada, como no core, e nada mais casa' );
uox_ai_assert( 'nenhum' === $termo( '/', $R ) && 'nenhum' === $termo( '', $R ) && 'nenhum' === $termo( null, $R ), '#344: raiz e caminho vazio: nenhum' );
// A primeira regra que casa decide, mesmo havendo depois uma de taxonomia que também casaria.
uox_ai_assert( 'nenhum' === $termo( '/olhal-de-ancoragem/', array( '(.+)/?$' => 'index.php?post_type=servicos&name=$matches[1]', 'olhal-de-ancoragem/?$' => 'index.php?product_cat=olhal-de-ancoragem' ) ), '#344: uma regra de post antes da de categoria decide, como no core' );
// A regra de página só decide quando a página existe; senão é pulada e a seguinte decide.
$pagina_antes = array( '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]', 'olhal-de-ancoragem/?$' => 'index.php?product_cat=olhal-de-ancoragem', 'home-real/?$' => 'index.php?product_cat=acessorios' );
uox_ai_assert( 'product_cat:34' === $termo( '/olhal-de-ancoragem/', $pagina_antes ), '#344: regra de página sem página é pulada e a de categoria decide' );
uox_ai_assert( 'nenhum' === $termo( '/home-real/', $pagina_antes ), '#344: regra de página com a página existente decide: nenhum termo' );
// Caminho codificado que só casa decodificado, como o `WP::parse_request()` tenta.
uox_ai_assert( 'product_cat:34' === $termo( '/olhal%2Dde%2Dancoragem/', $R ), '#344: caminho codificado casa a regra pela forma decodificada' );
uox_ai_assert( 'nenhum' === $termo( '/olhal-de-ancoragem/', 'lixo' ) && 'nenhum' === $termo( '/olhal-de-ancoragem/', array() ), '#344: regras malformadas ou vazias: nenhum' );
$GLOBALS['uox_options'] = array( 'page_on_front' => 14 );
uox_ai_assert( 'nenhum' === $termo( '/olhal-de-ancoragem/' ), '#344: sem a opção rewrite_rules: nenhum' );
$GLOBALS['uox_options']['rewrite_rules'] = uox_regras();
uox_ai_assert( 'product_cat:34' === $termo( '/olhal-de-ancoragem/' ), '#344: sem regras passadas, lê a opção rewrite_rules' );
uox_ai_assert( 0 === uonix_intelligence_ai_page_post_id( '/olhal-de-ancoragem/' ), '#344: a categoria continua sem post' );
$obj = uonix_intelligence_ai_page_object( '/olhal-de-ancoragem/' );
uox_ai_assert( array( 'type' => 'term', 'taxonomy' => 'product_cat', 'id' => 34 ) === $obj, '#344: o objeto da página é o termo; obteve ' . var_export( $obj, true ) );
uox_ai_assert( array( 'type' => 'post', 'id' => 10 ) === uonix_intelligence_ai_page_object( '/produtos/olhal-inox/' ) && null === uonix_intelligence_ai_page_object( '/rascunho/' ), '#344: post publicado continua post, e rascunho continua sem página' );

// Entrada com termo: título e descrição da meta do Rank Math do termo.
$in_t = uonix_intelligence_ai_input( uox_linha( 'olhal de ancoragem', '/olhal-de-ancoragem/' ) );
uox_ai_assert( is_array( $in_t ) && 'Olhal de Ancoragem em Inox | Uônix' === $in_t['title'] && 'Olhais de ancoragem conforme a NBR 16325.' === $in_t['description'], '#344: título e descrição atuais vêm da meta do Rank Math do termo; obteve ' . var_export( $in_t, true ) );
uox_ai_assert( is_array( $in_t ) && 'categoria de produtos' === ( $in_t['page_kind'] ?? '' ) && array( 'type' => 'term', 'taxonomy' => 'product_cat', 'id' => 34 ) === ( $in_t['object'] ?? null ) && 0 === $in_t['post_id'] && 'https://uonix.com.br/olhal-de-ancoragem/' === $in_t['page_url'], '#344: a entrada diz o tipo de página e o objeto, e post_id 0' );
// Sem meta: o nome do termo e a descrição dele, sem HTML e sem entidade.
$in_sem = uonix_intelligence_ai_input( uox_linha( 'acessorios', '/acessorios/' ) );
uox_ai_assert( is_array( $in_sem ) && 'Acessórios & Peças' === $in_sem['title'] && '' === $in_sem['description'], '#344: sem meta, o nome do termo; obteve ' . var_export( $in_sem['title'] ?? null, true ) );
$in_cat = uonix_intelligence_ai_input( uox_linha( 'normas', '/categoria/blog/normas/' ) );
uox_ai_assert( is_array( $in_cat ) && 'Normas' === $in_cat['title'] && 'Normas técnicas de ancoragem.' === $in_cat['description'] && 'categoria do blog' === $in_cat['page_kind'], '#344: sem meta, a descrição do termo, sem HTML' );
uox_ai_assert( null === uonix_intelligence_ai_input( uox_linha( 'x', '/tag/inexistente/' ) ), '#344: termo inexistente: sem entrada' );

// O pedido diz ao Gemini que é uma categoria; o de post não ganha a chave.
$corpo_t = uonix_intelligence_ai_request_body( $in_t )['contents'][0]['parts'][0]['text'];
$corpo_p = uonix_intelligence_ai_request_body( uonix_intelligence_ai_input( uox_linha() ) )['contents'][0]['parts'][0]['text'];
uox_ai_assert( false !== strpos( $corpo_t, '"tipo_de_pagina":"categoria de produtos"' ) && false === strpos( $corpo_p, 'tipo_de_pagina' ), '#344: o pedido leva tipo_de_pagina só para termo' );
uox_ai_assert( false === strpos( $corpo_t, '"object"' ) && false === strpos( $corpo_t, '"id":34' ), '#344: o objeto (tipo e id) não vai ao Gemini' );

// Hash: o de post não muda (a sugestão pronta não é refeita); o de termo leva o tipo.
$in_p    = uonix_intelligence_ai_input( uox_linha() );
$base_p  = array();
foreach ( array( 'query', 'page_url', 'title', 'description', 'differentiators', 'model' ) as $k ) {
	$base_p[ $k ] = $in_p[ $k ];
}
uox_ai_assert( hash( 'sha256', (string) wp_json_encode( $base_p ) ) === uonix_intelligence_ai_input_hash( $in_p ), '#344: o hash de post é o mesmo de antes, e a sugestão pronta não é refeita' );
uox_ai_assert( uonix_intelligence_ai_input_hash( $in_t ) !== uonix_intelligence_ai_input_hash( array_merge( $in_t, array( 'page_kind' => 'tag do blog' ) ) ), '#344: o hash do termo muda com o tipo de página' );
$h_antes = uonix_intelligence_ai_input_hash( $in_t );
$GLOBALS['uox_termos']['product_cat']['olhal-de-ancoragem']['meta']['rank_math_title'] = 'Olhal de Ancoragem | Uônix';
uox_ai_assert( $h_antes !== uonix_intelligence_ai_input_hash( uonix_intelligence_ai_input( uox_linha( 'olhal de ancoragem', '/olhal-de-ancoragem/' ) ) ), '#344: o hash muda quando a meta do termo muda' );
$GLOBALS['uox_termos']['product_cat']['olhal-de-ancoragem']['meta']['rank_math_title'] = 'Olhal de Ancoragem em Inox | Uônix';

// Execução: a categoria gera sugestão, e o leitor a devolve com o objeto.
$GLOBALS['uox_http']    = array( uox_gemini( $ok ) );
$GLOBALS['uox_pedidos'] = array();
$res_t = uonix_intelligence_ai_run( uox_analise( array( uox_linha( 'olhal de ancoragem', '/olhal-de-ancoragem/' ) ) ), 0 );
$sug_t = uonix_intelligence_ai_suggestion_for( uox_linha( 'olhal de ancoragem', '/olhal-de-ancoragem/' ) );
uox_ai_assert( 1 === $res_t['called'] && 'ok' === $sug_t['status'] && array( 'type' => 'term', 'taxonomy' => 'product_cat', 'id' => 34 ) === ( $sug_t['object'] ?? null ) && 'Olhal de Ancoragem em Inox | Uônix' === $sug_t['current_title'], '#344: a categoria gera sugestão, e o leitor devolve o objeto; obteve ' . var_export( array( $res_t, $sug_t['status'] ?? null ), true ) );
uox_ai_assert( false === strpos( uonix_intelligence_ai_state_message( 'no_page' ), 'categoria' ), '#344: o texto de no_page não cita mais categoria nem tag' );
unset( $GLOBALS['uox_options']['rewrite_rules'] );

$codigo = 'define("ABSPATH", 1); function add_action() {} function add_filter() {} function get_option($k, $d = false) { return $d; } function wp_remote_post() { echo "CHAMOU"; return null; } '
	. 'require ' . var_export( $RAIZ . '/mu-plugins/uonix-admin/54-admin-intelligence-ai.php', true ) . '; '
	. 'echo json_encode(array(uonix_intelligence_ai_run(array("available" => true, "rows" => array(array("query" => "x", "target_page" => "/a/")))), uonix_intelligence_ai_suggestion_for(array("query" => "x", "target_page" => "/a/"))));';
$saida = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $codigo ) . ' 2>&1' );
uox_ai_assert( false === strpos( $saida, 'CHAMOU' ) && '[{"called":0,"skipped":"not_configured"},{"status":"not_configured"}]' === trim( $saida ), 'Sem UONIX_GEMINI_API_KEY: nenhuma chamada e estado not_configured; obteve ' . $saida );

if ( $failures > 0 ) {
	fwrite( STDERR, "FALHAS: {$failures}\n" );
	exit( 1 );
}
echo "PASS: sugestão por IA (54).\n";
