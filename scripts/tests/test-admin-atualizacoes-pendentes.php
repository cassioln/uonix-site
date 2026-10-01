<?php
/**
 * Teste da lista informativa de "Atualizações Sistêmicas" no card de manutenção.
 *
 * A lista é somente leitura: o papel editor vê o que está atrasado — na aba
 * "Todos" (padrão) e por categoria, só as que têm pendência, paginado em 10 —
 * mas não pode receber link, formulário ou botão que dispare atualização daqui.
 * Os únicos botões são de navegação (abas e paginação).
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

// Conteúdo de um painel: da sua tag até o próximo painel ou o aviso de rodapé.
function uox_atualizacoes_painel( $html, $aba ) {
	$ini = strpos( $html, 'data-uox-painel="' . $aba . '"' );
	if ( false === $ini ) {
		return '';
	}
	$fim = strpos( $html, '<div class="uox-atualizacoes-painel"', $ini );
	if ( false === $fim ) {
		$fim = strpos( $html, 'uox-atualizacoes-aviso', $ini );
	}
	return substr( $html, $ini, false === $fim ? null : $fim - $ini );
}

// Tag de abertura do botão de uma aba.
function uox_atualizacoes_tag_aba( $html, $aba ) {
	return preg_match( '#<button[^>]*data-uox-aba="' . $aba . '"[^>]*>#', $html, $m ) ? $m[0] : '';
}

// Abas renderizadas, na ordem.
function uox_atualizacoes_abas( $html ) {
	preg_match_all( '#<button[^>]*data-uox-aba="([^"]+)"#', $html, $m );
	return $m[1];
}

// Somente informativo: nenhum link/formulário, e todo <button> é type=button de
// navegação (aba ou paginação). Devolve quantos botões há.
function uox_atualizacoes_assert_informativo( $html, $contexto ) {
	uox_atualizacoes_assert( 1 !== preg_match( '#<a[\s>]|<form|href=#i', $html ), "{$contexto}: não pode conter link ou formulário" );
	preg_match_all( '#<button[^>]*>#', $html, $m );
	foreach ( $m[0] as $botao ) {
		uox_atualizacoes_assert(
			false !== strpos( $botao, 'type="button"' )
				&& ( false !== strpos( $botao, 'data-uox-aba=' ) || false !== strpos( $botao, 'data-uox-pagina-acao=' ) ),
			"{$contexto}: todo botão deve ser type=button de aba ou paginação ({$botao})"
		);
	}
	return count( $m[0] );
}

function uox_atualizacoes_assert_aviso( $html, $contexto ) {
	$aviso = preg_match( '#<p class="uox-atualizacoes-aviso"[^>]*>(.*?)</p>#s', $html, $m ) ? $m[1] : '';
	uox_atualizacoes_assert(
		false !== strpos( $aviso, 'administrador' ) && false !== strpos( $aviso, 'backup' ) && false !== stripos( $aviso, 'segurança' ),
		"{$contexto}: o rodapé deve avisar sobre segurança, perfil de administrador e backup"
	);
	$pos_aviso  = strpos( $html, 'uox-atualizacoes-aviso' );
	$pos_painel = strrpos( $html, '<div class="uox-atualizacoes-painel"' );
	uox_atualizacoes_assert(
		false !== $pos_aviso && false !== $pos_painel && $pos_aviso > $pos_painel && false !== strpos( substr( $html, $pos_aviso ), '</div>' ),
		"{$contexto}: o aviso deve ficar no rodapé, depois dos painéis e dentro do bloco"
	);
}

// Gera N plugins com salto de patch (gravidade baixa).
function uox_atualizacoes_plugins_patch( $quantidade ) {
	$resposta   = array();
	$instalados = array();
	for ( $i = 1; $i <= $quantidade; $i++ ) {
		$arquivo                = sprintf( 'p%02d/p%02d.php', $i, $i );
		$resposta[ $arquivo ]   = (object) array( 'new_version' => '1.0.1' );
		$instalados[ $arquivo ] = array( 'Name' => sprintf( 'Plugin %02d', $i ), 'Version' => '1.0.0' );
	}
	$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => $resposta );
	$GLOBALS['uox_test_plugins']                           = $instalados;
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

// Sem pendências: o bloco inteiro some.
uox_atualizacoes_assert( '' === uox_atualizacoes_render(), 'sem pendências o bloco não deve ser renderizado' );

// Núcleo com response "latest" não é pendência.
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
	'updates' => array( (object) array( 'response' => 'latest', 'current' => '6.8.1' ) ),
);
$grupos = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( array( 'plugins', 'core', 'temas' ) === array_keys( $grupos ), 'os grupos devem ser plugins, core e temas, nessa ordem' );
uox_atualizacoes_assert( 0 === uox_atualizacoes_total( $grupos ), 'núcleo em "latest" não deve virar pendência' );
uox_atualizacoes_assert( '' === uox_atualizacoes_render(), 'núcleo em "latest": o bloco não deve ser renderizado' );

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
uox_atualizacoes_assert( false !== strpos( $so_nucleo, 'id="uox-atualizacoes-sistemicas"' ), 'com pendência, o bloco deve ser renderizado' );
// Uma categoria só: "Todos" repetiria a mesma lista, então fica a aba única.
uox_atualizacoes_assert( array( 'core' ) === uox_atualizacoes_abas( $so_nucleo ), 'só com o núcleo pendente, a única aba deve ser WordPress (sem Todos, vazias ocultas)' );
uox_atualizacoes_assert( false === strpos( $so_nucleo, 'data-uox-painel="todos"' ), 'com uma categoria só, não deve haver painel Todos' );
uox_atualizacoes_assert( false === strpos( $so_nucleo, 'data-uox-painel="plugins"' ), 'categoria vazia não deve ter painel' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $so_nucleo, 'core' ), 'is-active' ), 'a aba única deve abrir ativa' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $so_nucleo, 'core' ), 'tabindex="0"' ), 'a aba única deve estar na ordem do Tab' );
uox_atualizacoes_assert( 1 === substr_count( $so_nucleo, 'class="uox-atualizacoes-tab is-active"' ), 'exatamente uma aba deve abrir ativa' );
uox_atualizacoes_assert( false === strpos( uox_atualizacoes_tag_painel( $so_nucleo, 'core' ), 'hidden' ), 'o painel da aba única deve abrir visível' );
uox_atualizacoes_assert( false !== strpos( $so_nucleo, 'Crítica' ), 'o selo "Crítica" deve aparecer para o núcleo' );
uox_atualizacoes_assert( false === strpos( $so_nucleo, 'uox-atualizacoes-paginacao' ), 'até 10 itens não há paginação' );
uox_atualizacoes_assert( false !== strpos( $so_nucleo, '#uox-atualizacoes-sistemicas [hidden]{display:none !important;}' ), 'o bloco deve garantir que [hidden] vença o display:flex inline' );
// Altura fixa: todos os painéis dentro de um contêiner único, cuja altura o JS
// trava. Aqui só se confere a presença; o comportamento foi conferido no
// navegador (falta teste headless que execute o JS).
$pos_caixa = strpos( $so_nucleo, '<div class="uox-atualizacoes-paineis"' );
uox_atualizacoes_assert(
	false !== $pos_caixa && $pos_caixa < strpos( $so_nucleo, 'data-uox-painel=' ) && $pos_caixa > strpos( $so_nucleo, 'role="tablist"' ),
	'os painéis devem ficar num contêiner único, abaixo das abas'
);
// O JS trava na maior página entre as abas (normalmente a 1 de "Todos"), não
// trava em 0 com o widget recolhido e remede quando a largura muda.
uox_atualizacoes_assert( false !== strpos( $so_nucleo, 'function fixarAltura()' ), 'o JS deve travar a altura da área dos painéis' );
uox_atualizacoes_assert( false !== strpos( $so_nucleo, "caixa.style.height = maior > 0 ? maior + 'px' : '';" ), 'medida 0 (widget recolhido) não pode travar a altura em 0' );
uox_atualizacoes_assert( false !== strpos( $so_nucleo, 'new ResizeObserver(' ), 'a altura deve ser refeita quando a largura do bloco muda, não só no resize da janela' );
uox_atualizacoes_assert( 1 === uox_atualizacoes_assert_informativo( $so_nucleo, 'só núcleo' ), 'só núcleo: o único botão deve ser a aba única' );
uox_atualizacoes_assert_aviso( $so_nucleo, 'só núcleo' );

// Núcleo, plugins (fora de ordem) e temas pendentes.
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array(
	'response' => array(
		'patch/patch.php' => (object) array( 'new_version' => '1.0.1' ),
		'major/major.php' => (object) array( 'new_version' => '3.0.0' ),
		'minor/minor.php' => (object) array( 'new_version' => '2.5.0' ),
		// Ainda no transient, mas removido fora do WordPress: não pode listar.
		'orfao/orfao.php' => (object) array( 'new_version' => '5.0.0' ),
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
uox_atualizacoes_assert( 3 === count( $grupos['plugins'] ), 'os 3 plugins instalados devem ir para o grupo plugins (o órfão do cache fica de fora)' );
uox_atualizacoes_assert( 2 === count( $grupos['temas'] ), 'os 2 temas instalados devem ir para o grupo temas (o órfão do cache fica de fora)' );
uox_atualizacoes_assert( ! in_array( 'orfao/orfao.php', array_column( $grupos['plugins'], 'nome' ), true ), 'plugin no cache mas não instalado não deve listar' );
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
uox_atualizacoes_assert( ! isset( $temas['sumido'] ), 'tema no cache mas não instalado não deve listar' );
uox_atualizacoes_assert(
	! in_array( '?', array_merge( array_column( $grupos['plugins'], 'versao_atual' ), array_column( $grupos['temas'], 'versao_atual' ) ), true ),
	'nenhum item deve aparecer com versão atual "?"'
);
uox_atualizacoes_assert(
	array( 'alta', 'media' ) === array_column( $grupos['temas'], 'gravidade' ),
	'temas devem sair ordenados pela gravidade (alta primeiro)'
);

$lista = uox_atualizacoes_render();
uox_atualizacoes_assert( array( 'todos', 'plugins', 'core', 'temas' ) === uox_atualizacoes_abas( $lista ), 'com tudo pendente, as abas devem ser Todos, Plugins, WordPress e Tema' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Todos (6)' ), 'a aba Todos deve somar todas as categorias' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Plugins (3)' ), 'a aba Plugins deve mostrar a contagem' );
uox_atualizacoes_assert( false !== strpos( $lista, 'WordPress (1)' ), 'a aba WordPress deve mostrar a contagem' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Tema (2)' ), 'a aba Tema deve mostrar a contagem' );
uox_atualizacoes_assert( 12 === substr_count( $lista, '<li' ), 'cada pendência aparece em Todos e na sua categoria (6 + 6)' );

$painel_todos = uox_atualizacoes_painel( $lista, 'todos' );
uox_atualizacoes_assert( 6 === substr_count( $painel_todos, '<li' ), 'o painel Todos deve listar as 6 pendências' );
preg_match_all( '#>(Crítica|Alta|Média|Baixa)</span>#u', $painel_todos, $selos );
uox_atualizacoes_assert(
	array( 'Crítica', 'Alta', 'Alta', 'Média', 'Média', 'Baixa' ) === $selos[1],
	'Todos deve misturar as categorias da gravidade mais alta para a mais baixa'
);
uox_atualizacoes_assert(
	0 === substr_count( $painel_todos, 'WordPress &middot;' ) && 3 === substr_count( $painel_todos, 'Plugin &middot;' ) && 2 === substr_count( $painel_todos, 'Tema &middot;' ),
	'em Todos, plugins e temas indicam a categoria; o núcleo não repete "WordPress"'
);
uox_atualizacoes_assert( false === strpos( uox_atualizacoes_painel( $lista, 'plugins' ), '&middot;' ), 'na aba da própria categoria, o item não repete a categoria' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $lista, 'todos' ), 'is-active' ), 'com tudo pendente, Todos continua a aba padrão' );
uox_atualizacoes_assert(
	false !== strpos( uox_atualizacoes_tag_painel( $lista, 'plugins' ), 'hidden' )
		&& false !== strpos( uox_atualizacoes_tag_painel( $lista, 'core' ), 'hidden' )
		&& false !== strpos( uox_atualizacoes_tag_painel( $lista, 'temas' ), 'hidden' ),
	'os painéis por categoria devem abrir ocultos'
);
uox_atualizacoes_assert( false !== strpos( $lista, 'Plugin &lt;Exemplo&gt;' ), 'o nome do plugin deve sair escapado' );
uox_atualizacoes_assert( false === strpos( $lista, '<Exemplo>' ), 'o nome do plugin não pode sair cru' );
uox_atualizacoes_assert( 4 === uox_atualizacoes_assert_informativo( $lista, 'lista completa' ), 'lista completa: os únicos botões devem ser as 4 abas' );

// ARIA de abas: cada aba aponta para o seu painel e vice-versa; só a ativa no Tab.
foreach ( uox_atualizacoes_abas( $lista ) as $aba ) {
	$tag_aba    = uox_atualizacoes_tag_aba( $lista, $aba );
	$tag_painel = uox_atualizacoes_tag_painel( $lista, $aba );
	uox_atualizacoes_assert(
		false !== strpos( $tag_aba, 'id="uox-atualizacoes-tab-' . $aba . '"' ) && false !== strpos( $tag_aba, 'aria-controls="uox-atualizacoes-painel-' . $aba . '"' ),
		"{$aba}: a aba deve ter id e aria-controls apontando para o painel"
	);
	uox_atualizacoes_assert(
		false !== strpos( $tag_painel, 'id="uox-atualizacoes-painel-' . $aba . '"' )
			&& false !== strpos( $tag_painel, 'role="tabpanel"' )
			&& false !== strpos( $tag_painel, 'aria-labelledby="uox-atualizacoes-tab-' . $aba . '"' ),
		"{$aba}: o painel deve ter id, role=tabpanel e aria-labelledby apontando para a aba"
	);
	uox_atualizacoes_assert(
		false !== strpos( $tag_aba, 'todos' === $aba ? 'tabindex="0"' : 'tabindex="-1"' ),
		"{$aba}: só a aba ativa entra na ordem do Tab (tabindex itinerante)"
	);
}
uox_atualizacoes_assert( false !== strpos( $lista, "ArrowRight" ) && false !== strpos( $lista, "ArrowLeft" ), 'as abas devem responder às setas do teclado' );
uox_atualizacoes_assert_aviso( $lista, 'lista completa' );

// Categoria que zera some da barra de abas.
unset( $GLOBALS['uox_test_site_transients']['update_themes'] );
$sem_tema = uox_atualizacoes_render();
uox_atualizacoes_assert( array( 'todos', 'plugins', 'core' ) === uox_atualizacoes_abas( $sem_tema ), 'sem tema pendente, a aba Tema deve sumir' );
uox_atualizacoes_assert( false === strpos( $sem_tema, 'Tema (0)' ), 'nenhuma aba deve aparecer com (0)' );

// Paginação: exatamente 10 itens cabem numa página só.
unset( $GLOBALS['uox_test_site_transients']['update_core'] );
uox_atualizacoes_plugins_patch( 10 );
$dez = uox_atualizacoes_render();
uox_atualizacoes_assert( false === strpos( $dez, 'uox-atualizacoes-paginacao' ), '10 itens não devem gerar paginação' );
uox_atualizacoes_assert( 0 === preg_match( '#<li[^>]*hidden#', $dez ), '10 itens devem aparecer todos na primeira página' );
uox_atualizacoes_assert( array( 'plugins' ) === uox_atualizacoes_abas( $dez ), 'só plugins pendentes: a única aba deve ser Plugins (sem Todos)' );
uox_atualizacoes_assert( false !== strpos( uox_atualizacoes_tag_aba( $dez, 'plugins' ), 'is-active' ) && false === strpos( uox_atualizacoes_tag_painel( $dez, 'plugins' ), 'hidden' ), 'só plugins: a aba Plugins abre ativa e visível' );
uox_atualizacoes_assert( false === strpos( $dez, 'Plugin &middot;' ), 'só plugins: sem "Todos", nenhum item repete a categoria' );

// Paginação: 12 plugins + núcleo = Todos com 13 (2 páginas) e Plugins com 12 (2 páginas).
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
	'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '6.9' ) ),
);
uox_atualizacoes_plugins_patch( 12 );
$paginado = uox_atualizacoes_render();

foreach ( array( 'todos' => 13, 'plugins' => 12 ) as $aba => $quantidade ) {
	$painel = uox_atualizacoes_painel( $paginado, $aba );
	uox_atualizacoes_assert( false !== strpos( $painel, 'data-uox-paginas="2"' ), "{$aba}: {$quantidade} itens devem dar 2 páginas" );
	uox_atualizacoes_assert( 10 === preg_match_all( '#<li data-uox-pagina="1" style=#', $painel ), "{$aba}: a página 1 deve mostrar 10 itens visíveis" );
	uox_atualizacoes_assert( $quantidade - 10 === preg_match_all( '#<li data-uox-pagina="2" hidden#', $painel ), "{$aba}: os itens além de 10 devem ir para a página 2, ocultos" );
	uox_atualizacoes_assert( false !== strpos( $painel, 'Página 1 de 2' ), "{$aba}: a paginação deve indicar \"Página 1 de 2\"" );
	uox_atualizacoes_assert( 1 === preg_match( '#<button[^>]*data-uox-pagina-acao="anterior"[^>]*disabled#', $painel ), "{$aba}: \"Anterior\" deve abrir desabilitado" );
	uox_atualizacoes_assert( 1 === preg_match( '#<button[^>]*data-uox-pagina-acao="proxima"(?![^>]*disabled)[^>]*>#', $painel ), "{$aba}: \"Próxima\" deve abrir habilitado" );
}

uox_atualizacoes_assert( false === strpos( uox_atualizacoes_painel( $paginado, 'core' ), 'uox-atualizacoes-paginacao' ), 'a aba com 1 item não deve ter paginação' );
uox_atualizacoes_assert(
	1 === preg_match( '#<div class="uox-atualizacoes-painel" data-uox-painel="todos"[^>]*style="display:flex; flex-direction:column; min-height:100%;#', $paginado )
		&& 1 === preg_match( '#<div class="uox-atualizacoes-paginacao" style="[^"]*margin-top:auto;#', $paginado ),
	'a paginação deve ficar colada no fundo da área de altura fixa (painel em coluna flex + margin-top:auto)'
);
uox_atualizacoes_assert(
	1 === preg_match( "#if \\(!tab\\.classList\\.contains\\('is-active'\\)\\) \\{\\s*wrap\\.querySelectorAll\\('\\[data-uox-painel\\]'\\)\\.forEach\\(function \\(painel\\) \\{\\s*irParaPagina\\(painel, 1\\);#", $paginado ),
	'trocar de aba deve voltar todas as abas para a página 1'
);
// O reset só funciona antes de a aba clicada virar is-active.
$pos_ativar = strpos( $paginado, 'function ativarAba(tab)' );
$pos_reset  = strpos( $paginado, "if (!tab.classList.contains('is-active'))", (int) $pos_ativar );
$pos_toggle = strpos( $paginado, "botao.classList.toggle('is-active'", (int) $pos_ativar );
uox_atualizacoes_assert(
	false !== $pos_ativar && false !== $pos_reset && false !== $pos_toggle && $pos_reset < $pos_toggle,
	'em ativarAba, o reset de página deve vir antes de alternar is-active'
);
uox_atualizacoes_assert(
	false !== strpos( $paginado, "clone.style.cssText = 'position:absolute;" ),
	'o clone de medição deve substituir (=) o estilo inline, descartando o min-height:100% do painel'
);
uox_atualizacoes_assert( 7 === uox_atualizacoes_assert_informativo( $paginado, 'paginado' ), 'paginado: 3 abas + 2 botões de paginação em 2 painéis' );
uox_atualizacoes_assert_aviso( $paginado, 'paginado' );

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: atualizações sistêmicas em abas (Todos + categorias), paginadas e somente informativas\n";
