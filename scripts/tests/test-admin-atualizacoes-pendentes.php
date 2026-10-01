<?php
/**
 * Teste da lista informativa de "Atualizações Sistêmicas" no card de manutenção.
 *
 * A lista é somente leitura: o papel editor vê o que está atrasado, separado em
 * abas (Plugins / WordPress / Tema), mas não pode receber link, formulário ou
 * botão que dispare atualização daqui — os únicos botões são os das abas.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_site_transients'] = array();
$GLOBALS['uox_test_plugins']         = array();
$GLOBALS['uox_test_themes']          = array();
$GLOBALS['wp_version']               = '6.8.1';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_site_transient( $key ) {
	return $GLOBALS['uox_test_site_transients'][ $key ] ?? false;
}

function get_plugins() {
	return $GLOBALS['uox_test_plugins'];
}

class Uox_Test_Theme {
	private $headers;

	public function __construct( $headers ) {
		$this->headers = $headers;
	}

	public function exists() {
		return null !== $this->headers;
	}

	public function get( $header ) {
		return $this->headers[ $header ] ?? false;
	}
}

function wp_get_theme( $stylesheet ) {
	return new Uox_Test_Theme( $GLOBALS['uox_test_themes'][ $stylesheet ] ?? null );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php';

$failures = 0;

function uox_atualizacoes_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_atualizacoes_render() {
	ob_start();
	uox_render_lista_atualizacoes_pendentes();
	return (string) ob_get_clean();
}

function uox_atualizacoes_total( $grupos ) {
	return array_sum( array_map( 'count', $grupos ) );
}

// Tag de abertura do painel de uma aba (com ou sem hidden).
function uox_atualizacoes_tag_painel( $html, $aba ) {
	return preg_match( '#<div class="uox-atualizacoes-painel" data-uox-painel="' . $aba . '"[^>]*>#', $html, $m ) ? $m[0] : '';
}

// Tag de abertura do botão de uma aba.
function uox_atualizacoes_tag_aba( $html, $aba ) {
	return preg_match( '#<button[^>]*data-uox-aba="' . $aba . '"[^>]*>#', $html, $m ) ? $m[0] : '';
}

// Somente informativo: nenhum link/formulário, e os únicos <button> são as 3 abas.
function uox_atualizacoes_assert_informativo( $html, $contexto ) {
	uox_atualizacoes_assert( 1 !== preg_match( '#<a[\s>]|<form|href=#i', $html ), "{$contexto}: não pode conter link ou formulário" );
	$botoes = substr_count( $html, '<button' );
	uox_atualizacoes_assert(
		3 === $botoes
			&& 3 === substr_count( $html, 'data-uox-aba=' )
			&& 3 === substr_count( $html, '<button type="button"' ),
		"{$contexto}: os únicos botões devem ser as 3 abas type=button (achou {$botoes})"
	);
}

// Gravidade por distância de versão: major → alta, minor → média, patch → baixa.
uox_atualizacoes_assert( 'alta' === uox_get_gravidade_atualizacao( '6.8.1', '7.0' ), 'salto de major deve ser alta' );
uox_atualizacoes_assert( 'media' === uox_get_gravidade_atualizacao( '6.8.1', '6.9' ), 'salto de minor deve ser média' );
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '6.8.1', '6.8.2' ), 'salto de patch deve ser baixa' );

// Ordem: crítica antes de alta, média e baixa; desconhecida por último.
uox_atualizacoes_assert(
	uox_ordem_gravidade( 'critica' ) < uox_ordem_gravidade( 'alta' )
		&& uox_ordem_gravidade( 'alta' ) < uox_ordem_gravidade( 'media' )
		&& uox_ordem_gravidade( 'media' ) < uox_ordem_gravidade( 'baixa' )
		&& uox_ordem_gravidade( 'baixa' ) < uox_ordem_gravidade( 'xyz' ),
	'ordem de gravidade deve ser crítica < alta < média < baixa < desconhecida'
);

// Sem pendências: mensagem de "tudo atualizado", sem abas nem itens.
$vazio = uox_atualizacoes_render();
uox_atualizacoes_assert( false !== strpos( $vazio, 'Atualizações Sistêmicas' ), 'o bloco deve ter o título "Atualizações Sistêmicas"' );
uox_atualizacoes_assert( false !== strpos( $vazio, 'Tudo atualizado' ), 'sem pendências deve mostrar "Tudo atualizado"' );
uox_atualizacoes_assert( false === strpos( $vazio, '<li' ), 'sem pendências não deve renderizar itens' );
uox_atualizacoes_assert( false === strpos( $vazio, '<button' ), 'sem pendências não deve renderizar abas' );

// Núcleo com response "latest" não é pendência.
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
	'updates' => array( (object) array( 'response' => 'latest', 'current' => '6.8.1' ) ),
);
$grupos = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( array( 'plugins', 'core', 'temas' ) === array_keys( $grupos ), 'os grupos devem ser plugins, core e temas, nessa ordem' );
uox_atualizacoes_assert( 0 === uox_atualizacoes_total( $grupos ), 'núcleo em "latest" não deve virar pendência' );

// Só o núcleo pendente: crítica mesmo num salto de patch, e a aba WordPress abre ativa.
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
	'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '6.8.2' ) ),
);
$grupos = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( 1 === count( $grupos['core'] ), 'núcleo pendente deve ir para o grupo core' );
uox_atualizacoes_assert( 'critica' === ( $grupos['core'][0]['gravidade'] ?? '' ), 'núcleo deve ser sempre crítica, mesmo num salto de patch' );
uox_atualizacoes_assert(
	'6.8.1' === ( $grupos['core'][0]['versao_atual'] ?? '' ) && '6.8.2' === ( $grupos['core'][0]['versao_nova'] ?? '' ),
	'núcleo deve levar versão atual e nova'
);

$so_nucleo = uox_atualizacoes_render();
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $so_nucleo, 'core' ), 'is-active' ), 'só com o núcleo pendente, a aba WordPress deve abrir ativa' );
uox_atualizacoes_assert( false === strpos( uox_atualizacoes_tag_aba( $so_nucleo, 'plugins' ), 'is-active' ), 'só com o núcleo pendente, a aba Plugins (vazia) não pode abrir ativa' );
uox_atualizacoes_assert( false === strpos( uox_atualizacoes_tag_painel( $so_nucleo, 'core' ), 'hidden' ), 'só com o núcleo pendente, o painel do núcleo deve ficar visível' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_painel( $so_nucleo, 'plugins' ), 'hidden' ), 'só com o núcleo pendente, o painel de plugins deve ficar oculto' );
uox_atualizacoes_assert( false !== strpos( $so_nucleo, 'Crítica' ), 'o selo "Crítica" deve aparecer para o núcleo' );
uox_atualizacoes_assert( 1 === substr_count( $so_nucleo, 'class="uox-atualizacoes-tab is-active"' ), 'exatamente uma aba deve abrir ativa' );
uox_atualizacoes_assert_informativo( $so_nucleo, 'só núcleo' );

// Núcleo, plugins (fora de ordem) e temas pendentes.
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array(
	'response' => array(
		'patch/patch.php' => (object) array( 'new_version' => '1.0.1' ),
		'major/major.php' => (object) array( 'new_version' => '3.0.0' ),
		'minor/minor.php' => (object) array( 'new_version' => '2.5.0' ),
	),
);
$GLOBALS['uox_test_plugins'] = array(
	'patch/patch.php' => array( 'Name' => 'Plugin Patch', 'Version' => '1.0.0' ),
	'major/major.php' => array( 'Name' => 'Plugin <Exemplo>', 'Version' => '2.4.1' ),
	'minor/minor.php' => array( 'Name' => 'Plugin Minor', 'Version' => '2.4.1' ),
);
$GLOBALS['uox_test_site_transients']['update_themes'] = (object) array(
	'response' => array(
		// A API de temas devolve array; o código também aceita objeto. O tema de
		// gravidade média vem primeiro de propósito: sem ordenação, ele lideraria.
		'filho'  => (object) array( 'new_version' => '1.1.0' ),
		'sumido' => array( 'new_version' => '9.9.9' ),
		'pai'    => array( 'new_version' => '2.0.0' ),
	),
);
$GLOBALS['uox_test_themes'] = array(
	'pai'   => array( 'Name' => 'Tema Pai', 'Version' => '1.0.0' ),
	'filho' => array( 'Name' => 'Tema Filho', 'Version' => '1.0.0' ),
);

$grupos = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( 1 === count( $grupos['core'] ), 'o núcleo deve continuar no grupo core' );
uox_atualizacoes_assert( 3 === count( $grupos['plugins'] ), 'os 3 plugins devem ir para o grupo plugins' );
uox_atualizacoes_assert( 3 === count( $grupos['temas'] ), 'os 3 temas devem ir para o grupo temas' );
uox_atualizacoes_assert(
	array( 'alta', 'media', 'baixa' ) === array_column( $grupos['plugins'], 'gravidade' ),
	'plugins devem sair ordenados alta → média → baixa'
);
uox_atualizacoes_assert(
	array( 'Plugin <Exemplo>', 'Plugin Minor', 'Plugin Patch' ) === array_column( $grupos['plugins'], 'nome' ),
	'a ordenação deve levar o item inteiro, não só a gravidade'
);

$temas = array_column( $grupos['temas'], null, 'nome' );
uox_atualizacoes_assert(
	'alta' === ( $temas['Tema Pai']['gravidade'] ?? '' ) && '2.0.0' === ( $temas['Tema Pai']['versao_nova'] ?? '' ),
	'tema com dados em array deve ler new_version (1.0.0 → 2.0.0 = alta)'
);
uox_atualizacoes_assert(
	'media' === ( $temas['Tema Filho']['gravidade'] ?? '' ) && '1.1.0' === ( $temas['Tema Filho']['versao_nova'] ?? '' ),
	'tema com dados em objeto deve ler new_version (1.0.0 → 1.1.0 = média)'
);
uox_atualizacoes_assert(
	isset( $temas['sumido'] ) && '?' === $temas['sumido']['versao_atual'],
	'tema não instalado deve cair para o stylesheet como nome e "?" como versão'
);
uox_atualizacoes_assert(
	array( 'alta', 'alta', 'media' ) === array_column( $grupos['temas'], 'gravidade' ),
	'temas devem sair ordenados pela gravidade (alta primeiro)'
);

$lista = uox_atualizacoes_render();
uox_atualizacoes_assert( 7 === substr_count( $lista, '<li' ), 'a lista deve renderizar um <li> por pendência (1 + 3 + 3)' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Plugins (3)' ), 'a aba Plugins deve mostrar a contagem' );
uox_atualizacoes_assert( false !== strpos( $lista, 'WordPress (1)' ), 'a aba WordPress deve mostrar a contagem' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Tema (3)' ), 'a aba Tema deve mostrar a contagem' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $lista, 'plugins' ), 'is-active' ), 'com plugins pendentes, a aba Plugins abre ativa' );
uox_atualizacoes_assert( false === strpos( uox_atualizacoes_tag_painel( $lista, 'plugins' ), 'hidden' ), 'o painel ativo não pode sair com hidden' );
uox_atualizacoes_assert(
	false !== strpos( uox_atualizacoes_tag_painel( $lista, 'core' ), 'hidden' ) && false !== strpos( uox_atualizacoes_tag_painel( $lista, 'temas' ), 'hidden' ),
	'os painéis inativos devem sair com hidden'
);
uox_atualizacoes_assert( false !== strpos( $lista, 'Plugin &lt;Exemplo&gt;' ), 'o nome do plugin deve sair escapado' );
uox_atualizacoes_assert( false === strpos( $lista, '<Exemplo>' ), 'o nome do plugin não pode sair cru' );
uox_atualizacoes_assert_informativo( $lista, 'lista completa' );

// Painel vazio dentro de um bloco com pendências.
unset( $GLOBALS['uox_test_site_transients']['update_themes'] );
$sem_tema = uox_atualizacoes_render();
uox_atualizacoes_assert( false !== strpos( $sem_tema, 'Tema (0)' ), 'aba sem pendência deve mostrar (0)' );
uox_atualizacoes_assert( false !== strpos( $sem_tema, 'Nenhuma pendência nesta categoria.' ), 'painel vazio deve dizer que não há pendência' );

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: atualizações sistêmicas em abas, somente informativas\n";
