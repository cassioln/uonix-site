<?php
/**
 * Teste: instâncias de widget de bloco sem `content` string não quebram o editor de widgets.
 *
 * MOTIVAÇÃO (relato de 2026-10-01): em Aparência > Widgets todas as áreas
 * apareciam vazias e salvar dava "Cannot read properties of undefined (reading
 * 'map')". O Max Mega Menu cria widgets "Bloco" só com as chaves dele; o editor
 * passa `content` indefinido ao parser e a carga de todos os widgets aborta.
 *
 * O teste falha se a leitura de `widget_block` não completar `content` vazio
 * nessas instâncias, se mexer em instâncias que já têm `content` string, se
 * tocar em valores que não são instâncias, se o módulo sair da lista do
 * `module.php` (executado, não lido como texto) ou se qualquer Warning do PHP
 * escapar da função (#354, #355).
 *
 * `content` que não é string (null, número, array) também quebra o `parse()` do
 * editor: o filtro o normaliza (#355).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uox_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-navigation/50-mega-menu-widget-bloco.php';

$failures = 0;

set_error_handler(
	static function ( $nivel, $mensagem, $arquivo, $linha ) {
		throw new ErrorException( $mensagem, 0, $nivel, $arquivo, $linha );
	}
);

function uox_wb_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

require_once __DIR__ . '/fixtures/lib-loader-modulo.php';
$carregados = uox_loader_arquivos_do_modulo( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-navigation/module.php' );
uox_wb_assert( in_array( '50-mega-menu-widget-bloco.php', $carregados, true ), 'o módulo 50 não está na lista que o module.php de navegação carrega: o editor de widgets voltaria a abrir vazio' );

// BAIXO 3 da revisão do #461: o helper do loader falha alto e confere o caminho.
$tmp_helper = sys_get_temp_dir() . '/uox-loader-' . getmypid();
mkdir( $tmp_helper . '/mod', 0777, true );
file_put_contents( $tmp_helper . '/mod/real.php', '<?php' );
$modulo_ok = $tmp_helper . '/mod/module.php';
file_put_contents( $modulo_ok, "<?php\nuonix_mu_require_files( __DIR__, array( 'real.php', 'fantasma.php' ), 'x' );\n" );
uox_wb_assert( array( 'real.php' ) === uox_loader_arquivos_do_modulo( $modulo_ok ), 'o helper deveria listar só o arquivo legível em $base_dir (como o loader real), não o fantasma' );

$modulo_dir_errado = $tmp_helper . '/mod/module-errado.php';
file_put_contents( $modulo_dir_errado, "<?php\nuonix_mu_require_files( __DIR__ . '/nao-existe', array( 'real.php' ), 'x' );\n" );
uox_wb_assert( array() === uox_loader_arquivos_do_modulo( $modulo_dir_errado ), 'um __DIR__ trocado no module.php deveria deixar a lista vazia, como o loader real' );

$modulo_sem_chamada = $tmp_helper . '/mod/module-sem-chamada.php';
file_put_contents( $modulo_sem_chamada, "<?php\n// nenhuma chamada ao loader\n" );
foreach ( array( 'sem chamada ao loader' => $modulo_sem_chamada, 'module.php inexistente' => $tmp_helper . '/mod/nao-existe.php' ) as $rotulo => $caminho ) {
	$acusou = false;
	try {
		uox_loader_arquivos_do_modulo( $caminho );
	} catch ( RuntimeException $e ) {
		$acusou = true;
	}
	uox_wb_assert( $acusou, "o helper deveria lançar RuntimeException: {$rotulo}" );
}
// Outro teste já declarou uonix_mu_require_files() neste processo: o gravador do helper não
// é instalado e a lista sairia vazia. Reproduzido em processo à parte, porque a função
// global não se redefine neste. O helper tem de acusar, não devolver [] em silêncio.
$colisao = $tmp_helper . '/colisao.php';
file_put_contents(
	$colisao,
	"<?php\ndefine( 'ABSPATH', __DIR__ );\n"
	. "function uonix_mu_require_files( \$base_dir, array \$files, \$module_name ) {}\n"
	. "require_once " . var_export( __DIR__ . '/fixtures/lib-loader-modulo.php', true ) . ";\n"
	. "try {\n\tuox_loader_arquivos_do_modulo( " . var_export( $modulo_ok, true ) . " );\n\texit( 1 );\n} catch ( RuntimeException \$e ) {\n\texit( 0 );\n}\n"
);
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $colisao ) . ' 2>&1', $saida_colisao, $codigo_colisao );
uox_wb_assert( 0 === $codigo_colisao, 'o helper deveria acusar (RuntimeException) quando outro teste já declarou uonix_mu_require_files(): ' . implode( ' ', $saida_colisao ) );
unlink( $colisao );
array_map( 'unlink', glob( $tmp_helper . '/mod/*.php' ) );
rmdir( $tmp_helper . '/mod' );
rmdir( $tmp_helper );

$registrado = $GLOBALS['uox_test_filters']['option_widget_block'][0] ?? null;
uox_wb_assert( null !== $registrado, 'filtro option_widget_block não registrado' );
uox_wb_assert( null !== $registrado && 'uonix_navigation_widget_block_content_padrao' === $registrado[0], 'callback registrado é outro' );

// Estado medido em produção, QA e local em 2026-10-01 (block-87, 88, 89 e 93).
$opcao = array(
	87             => array(
		'content'                  => '[uonix_vitrine_marcas]',
		'mega_menu_is_grid_widget' => 'true',
	),
	88             => array( 'mega_menu_is_grid_widget' => 'true' ),
	89             => array( 'mega_menu_is_grid_widget' => 'true' ),
	93             => array(
		'mega_menu_columns'        => 2,
		'mega_menu_parent_menu_id' => 8848,
		'mega_menu_order'          => array( 8848 => 2 ),
	),
	156            => array( 'content' => '' ),
	'_multiwidget' => 1,
);

$saida = uonix_navigation_widget_block_content_padrao( $opcao );

foreach ( array( 88, 89, 93 ) as $n ) {
	uox_wb_assert( isset( $saida[ $n ] ) && array_key_exists( 'content', $saida[ $n ] ), "block-{$n}: deveria ganhar content" );
	uox_wb_assert( isset( $saida[ $n ]['content'] ) && '' === $saida[ $n ]['content'], "block-{$n}: content deveria ser string vazia" );
	foreach ( $opcao[ $n ] as $chave => $valor ) {
		uox_wb_assert( isset( $saida[ $n ][ $chave ] ) && $valor === $saida[ $n ][ $chave ], "block-{$n}: chave {$chave} do Max Mega Menu foi alterada" );
	}
}

uox_wb_assert( $opcao[87] === $saida[87], 'block-87: instância com content não deveria mudar' );
uox_wb_assert( $opcao[156] === $saida[156], 'block-156: content vazio existente não deveria mudar' );
uox_wb_assert( 1 === $saida['_multiwidget'], '_multiwidget não deveria mudar' );
uox_wb_assert( array_keys( $opcao ) === array_keys( $saida ), 'ordem ou conjunto de chaves da opção mudou' );

// #355: `content` que existe mas não é string também quebra o parse() do editor.
$nao_string = uonix_navigation_widget_block_content_padrao(
	array(
		10             => array( 'content' => null ),
		11             => array( 'content' => 123 ),
		12             => array( 'content' => array( 'x' => 1 ) ),
		13             => array( 'content' => 1.5 ),
		14             => array( 'content' => true ),
		15             => array( 'content' => false ),
		'_multiwidget' => 1,
	)
);
uox_wb_assert( '' === $nao_string[10]['content'], 'content null deveria virar string vazia' );
uox_wb_assert( '123' === $nao_string[11]['content'], 'content numérico inteiro deveria virar a string do número' );
uox_wb_assert( '' === $nao_string[12]['content'], 'content array deveria virar string vazia' );
uox_wb_assert( '1.5' === $nao_string[13]['content'], 'content float deveria virar a string do número' );
uox_wb_assert( '1' === $nao_string[14]['content'], 'content true deveria virar "1", como a conversão do PHP' );
uox_wb_assert( '' === $nao_string[15]['content'], 'content false deveria virar string vazia' );
foreach ( array( 10, 11, 12, 13, 14, 15 ) as $n ) {
	uox_wb_assert( is_string( $nao_string[ $n ]['content'] ), "block-{$n}: content deveria ser string" );
}
uox_wb_assert( 1 === $nao_string['_multiwidget'], '_multiwidget não deveria mudar com content não-string ao lado' );

// A chave extra do Max Mega Menu sobrevive à normalização.
$com_extra = uonix_navigation_widget_block_content_padrao( array( 20 => array( 'content' => null, 'mega_menu_columns' => 2 ) ) );
uox_wb_assert( 2 === $com_extra[20]['mega_menu_columns'], 'a chave do Max Mega Menu foi alterada ao normalizar content' );

// MÉDIO 1 da revisão do #461: o ramo "objeto vira vazio" e o zero. Um cast cego daria
// Fatal com stdClass; truthiness trocaria 0 por vazio.
class Uox_Stringable {
	public function __toString(): string {
		return 'texto';
	}
}
$extra = uonix_navigation_widget_block_content_padrao(
	array(
		30 => array( 'content' => new stdClass() ),
		31 => array( 'content' => new Uox_Stringable() ),
		32 => array( 'content' => 0 ),
		33 => array( 'content' => 0.0 ),
		34 => array( 'content' => '0' ),
	)
);
uox_wb_assert( '' === $extra[30]['content'], 'content objeto deveria virar string vazia' );
uox_wb_assert( '' === $extra[31]['content'], 'content objeto com __toString deveria virar string vazia' );
uox_wb_assert( '0' === $extra[32]['content'], 'content 0 deveria virar "0"' );
uox_wb_assert( '0' === $extra[33]['content'], 'content 0.0 deveria virar "0"' );
uox_wb_assert( '0' === $extra[34]['content'], 'content string "0" não deveria mudar' );

// BAIXO 1: (string) NAN emite Warning no PHP 8.5 (o handler acima o transforma em exceção).
// Float não finito não tem texto útil: vira vazio, e INF também, para ser coerente.
$nao_finito = uonix_navigation_widget_block_content_padrao(
	array(
		40 => array( 'content' => NAN ),
		41 => array( 'content' => INF ),
		42 => array( 'content' => -INF ),
	)
);
foreach ( array( 40, 41, 42 ) as $n ) {
	uox_wb_assert( '' === $nao_finito[ $n ]['content'], "block-{$n}: float não finito deveria virar string vazia" );
}

// Idempotência: aplicar duas vezes dá o mesmo resultado.
$uma_vez = uonix_navigation_widget_block_content_padrao( array( 50 => array( 'content' => 123 ), 51 => array( 'content' => null ), 52 => array() ) );
uox_wb_assert( $uma_vez === uonix_navigation_widget_block_content_padrao( $uma_vez ), 'o filtro deveria ser idempotente' );

uox_wb_assert( false === uonix_navigation_widget_block_content_padrao( false ), 'opção inexistente (false) deveria passar intacta' );
uox_wb_assert( 'x' === uonix_navigation_widget_block_content_padrao( 'x' ), 'valor que não é array deveria passar intacto' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} falha(s).\n" );
	exit( 1 );
}

echo "OK: widgets de bloco ganham content string na leitura (ausente, nulo, escalar, array ou objeto).\n";
