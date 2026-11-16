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

require_once __DIR__ . '/fixtures/atualizacoes-sistemicas-stubs.php';

$failures = 0;

set_error_handler(
	static function ( $nivel, $mensagem, $arquivo, $linha ) {
		throw new ErrorException( $mensagem, 0, $nivel, $arquivo, $linha );
	}
);

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

// Monta plugins instalados e pendentes: arquivo => [Name, Version, nova].
function uox_atualizacoes_define_plugins( array $plugins ) {
	$resposta   = array();
	$instalados = array();
	foreach ( $plugins as $arquivo => $dados ) {
		$resposta[ $arquivo ]   = (object) array( 'new_version' => $dados['nova'] );
		$instalados[ $arquivo ] = array( 'Name' => $dados['Name'], 'Version' => $dados['Version'] );
	}
	$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => $resposta );
	$GLOBALS['uox_test_plugins']                           = $instalados;
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
// trava. Aqui só se confere a presença; o comportamento é executado num Chrome
// headless por test-admin-atualizacoes-sistemicas-navegador.mjs.
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

// Stubs que só o card de manutenção usa.
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $chave ) {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $chave ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $valor ) {
		return $valor;
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $caminho = '' ) {
		return 'https://exemplo.test/wp-admin/' . $caminho;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $acao ) {
		echo '<input type="hidden" name="_wpnonce" value="x">';
	}
}
if ( ! function_exists( 'uox_cache_flush_throttle_seconds' ) ) {
	function uox_cache_flush_throttle_seconds() {
		return 0;
	}
}
if ( ! function_exists( 'uox_cache_flush_remaining_seconds' ) ) {
	function uox_cache_flush_remaining_seconds() {
		return 0;
	}
}

// Isolamento dos casos de #339, #340 e #342: nenhum plugin, tema ou núcleo pendente herdado.
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => array() );
$GLOBALS['uox_test_site_transients']['update_themes']  = (object) array( 'response' => array() );
$GLOBALS['uox_test_plugins']                           = array();
$GLOBALS['uox_test_themes']                            = array();
unset( $GLOBALS['uox_test_site_transients']['update_core'] );

// #340: só `upgrade` é pendência do núcleo. `development` e `autoupdate` não pedem
// ação do editor, e como o núcleo sai sempre "Crítica" um falso positivo seria alarme.
foreach ( array( 'development', 'autoupdate' ) as $resposta_nucleo ) {
	$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
		'updates' => array( (object) array( 'response' => $resposta_nucleo, 'current' => '6.8.1' ) ),
	);
	uox_atualizacoes_assert( 0 === count( uox_get_atualizacoes_pendentes()['core'] ), "núcleo com response \"{$resposta_nucleo}\" não deve virar pendência" );
	uox_atualizacoes_assert( '' === uox_atualizacoes_render(), "núcleo com response \"{$resposta_nucleo}\": o bloco não deve ser renderizado" );
}
// Propriedades ausentes não geram Warning: o handler acima o transformaria em exceção.
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array( 'updates' => array( (object) array( 'current' => '6.8.2' ) ) );
uox_atualizacoes_assert( 0 === count( uox_get_atualizacoes_pendentes()['core'] ), 'updates[0] sem response não deve virar pendência nem gerar Warning' );
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array( 'updates' => array( (object) array( 'response' => 'upgrade' ) ) );
uox_atualizacoes_assert( 0 === count( uox_get_atualizacoes_pendentes()['core'] ), 'updates[0] "upgrade" sem current não deve virar pendência nem gerar Warning' );
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array( 'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '' ) ) );
uox_atualizacoes_assert( 0 === count( uox_get_atualizacoes_pendentes()['core'] ), 'updates[0] "upgrade" com current vazio não deve virar pendência' );
unset( $GLOBALS['uox_test_site_transients']['update_core'] );

// #339: versão que não segue X.Y.Z (data, hash, "?") não tem gravidade calculável.
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '20260101', '20260301' ), 'versão por data deve ser desconhecida, não alta' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '?', '1.2.3' ), 'versão atual "?" deve ser desconhecida' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '1.2.3', '?' ), 'versão nova "?" deve ser desconhecida' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '', '1.2.3' ), 'versão atual vazia deve ser desconhecida' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '1.2.3', 'abc' ), 'versão nova sem número deve ser desconhecida' );
// Formatos válidos continuam como antes, inclusive com sufixo de pré-release e 1 ou 4 partes.
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '1.2.3', '1.2.4-beta1' ), 'sufixo de pré-release não torna a versão desconhecida' );
uox_atualizacoes_assert( 'alta' === uox_get_gravidade_atualizacao( '2', '3' ), 'versão de uma parte é válida' );
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '1.2.3.4', '1.2.3.5' ), 'versão de quatro partes é válida' );
// Fronteira do formato de data: major com até 4 dígitos é versão (100.0, 2026.1.1 CalVer de
// ano, 1234); com 5 ou mais é data. Sem estes limites, trocar o corte passaria despercebido.
uox_atualizacoes_assert( 'alta' === uox_get_gravidade_atualizacao( '99.0', '100.0' ), 'major de 3 dígitos (100.0) é versão, não data' );
uox_atualizacoes_assert( 'alta' === uox_get_gravidade_atualizacao( '999.0', '1000.0' ), 'major de 4 dígitos (1000.0) é versão, não data' );
uox_atualizacoes_assert( 'media' === uox_get_gravidade_atualizacao( '2026.1.1', '2026.2.0' ), 'CalVer de ano (2026.x) tem gravidade calculada' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '12345', '12346' ), 'major de 5 dígitos já é data' );
uox_atualizacoes_assert( 'desconhecida' === uox_get_gravidade_atualizacao( '2026010100', '2026020100' ), 'data com hora (10 dígitos) é desconhecida' );
// #342: downgrade de major não é "baixa" por acidente de comparação.
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '2.0.0', '1.9.0' ), 'downgrade de major é baixa (não "média" por menor maior)' );
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '3.5.0', '2.9.0' ), 'downgrade de major com minor maior na nova continua baixa' );

// #339: plugin instalado com versão por data sai "Desconhecida" na lista, sem Warning,
// e depois dos itens com gravidade calculada.
uox_atualizacoes_define_plugins(
	array(
		'data/data.php' => array( 'Name' => 'Plugin Por Data', 'Version' => '20260101', 'nova' => '20260301' ),
		'ok/ok.php'     => array( 'Name' => 'Plugin Normal', 'Version' => '1.0.0', 'nova' => '1.0.1' ),
	)
);
$desconhecido = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( 'desconhecida' === ( $desconhecido['plugins'][1]['gravidade'] ?? '' ) && 'Plugin Por Data' === ( $desconhecido['plugins'][1]['nome'] ?? '' ), 'plugin com versão por data fica por último, com gravidade desconhecida' );
$html_desc = uox_atualizacoes_render();
uox_atualizacoes_assert( false !== strpos( $html_desc, 'Desconhecida' ), 'o rótulo "Desconhecida" deve aparecer na lista' );
uox_atualizacoes_assert( false === strpos( $html_desc, '>Alta<' ), 'versão por data não pode sair "Alta"' );
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => array() );
$GLOBALS['uox_test_plugins']                           = array();

// #342: o card de manutenção liga a lista. Sem a chamada em uox_render_manutencao_cache(),
// o bloco existiria mas nunca apareceria no dashboard.
uox_atualizacoes_define_plugins( array( 'ligado/ligado.php' => array( 'Name' => 'Plugin Ligado', 'Version' => '1.0.0', 'nova' => '1.0.1' ) ) );
ob_start();
uox_render_manutencao_cache();
$card = (string) ob_get_clean();
uox_atualizacoes_assert( false !== strpos( $card, 'id="uox-atualizacoes-sistemicas"' ) && false !== strpos( $card, 'Plugin Ligado' ), 'o card de manutenção deve renderizar a lista de atualizações' );
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array( 'response' => array() );
$GLOBALS['uox_test_plugins']                           = array();

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: atualizações sistêmicas em abas (Todos + categorias), paginadas e somente informativas\n";
