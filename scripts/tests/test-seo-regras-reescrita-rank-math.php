<?php
/**
 * As regras do Rank Math voltam sozinhas quando uma regravação as perdeu (#305).
 *
 * O RISCO QUE ESTE TESTE TRAVA
 *
 * Entre 16 e 28/09/2026, as categorias de produto e o `/sitemap_index.xml` deram 404 em
 * produção. O WooCommerce regravou `rewrite_rules` num heartbeat, requisição em que o Rank
 * Math não carrega módulo nenhum, e as regras dele sumiram da opção. A guarda confere, numa
 * requisição comum, se a regra de sitemap que o Rank Math acabou de registrar está gravada,
 * e agenda a regravação quando não está.
 *
 * Os três jeitos de a guarda virar o próprio defeito, todos travados aqui:
 *   - regravar DENTRO de um heartbeat, que é exatamente o que perde as regras;
 *   - regravar a cada requisição, se a regra nunca "pegar" (loop de flush);
 *   - custar uma consulta ao banco por requisição no caminho normal.
 *
 * Sem WordPress carregado: stubs das funções do core antes de incluir o arquivo.
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

$alvo = __DIR__ . '/../../mu-plugins/uonix-content/50-seo-regras-reescrita-rank-math.php';
if ( ! file_exists( $alvo ) ) {
	fwrite( STDERR, "FALHOU: arquivo não encontrado: {$alvo}\n" );
	exit( 1 );
}

// error_log() é nativa; o teste a desvia para um arquivo e confere o que foi escrito.
$logTeste = tempnam( sys_get_temp_dir(), 'uox305' );
ini_set( 'error_log', $logTeste );

$GLOBALS['uox_actions']  = array();
$GLOBALS['uox_options']  = array();
$GLOBALS['uox_lidas']    = array();
$GLOBALS['uox_autoload'] = array();
$GLOBALS['uox_flush']    = array();

function add_action( $hook, $callback, $prioridade = 10, $args = 1 ) {
	$GLOBALS['uox_actions'][] = array( $hook, $callback, $prioridade, $args );
	return true;
}
function get_option( $nome, $padrao = false ) {
	$GLOBALS['uox_lidas'][] = $nome;
	return array_key_exists( $nome, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $nome ] : $padrao;
}
function update_option( $nome, $valor, $autoload = null ) {
	$GLOBALS['uox_options'][ $nome ]  = $valor;
	$GLOBALS['uox_autoload'][ $nome ] = $autoload;
	return true;
}
function flush_rewrite_rules( $hard = true ) {
	$GLOBALS['uox_flush'][] = $hard;
}

class WP_Rewrite {
	public $extra_rules_top = array();
	public $permalink_structure = '/%postname%/';
	public function using_permalinks() {
		return '' !== $this->permalink_structure;
	}
}

require $alvo;

$falhas = 0;
function verifica( $condicao, $mensagem ) {
	global $falhas;
	if ( ! $condicao ) {
		$falhas++;
		fwrite( STDERR, "FAIL: {$mensagem}\n" );
	}
}

// As regras que o Rank Math registra no `init` quando o módulo de sitemap está carregado
// (includes/modules/sitemap/class-router.php, versão 1.0.277).
$REGRAS_RANK_MATH = array(
	'sitemap_index\.xml$'              => 'index.php?sitemap=1',
	'([^/]+?)-sitemap([0-9]+)?\.xml$'  => 'index.php?sitemap=$matches[1]&sitemap_n=$matches[2]',
	'([a-z]+)?-?sitemap\.xsl$'         => 'index.php?xsl=$matches[1]',
);
$GRAVADAS_COMPLETAS = $REGRAS_RANK_MATH + array(
	'olhal-de-ancoragem/?$'  => 'index.php?product_cat=olhal-de-ancoragem',
	'(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]',
);
$GRAVADAS_SEM_RANK_MATH = array(
	'(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]',
);
$AGORA      = 1790000000;
$opcaoReparo = uonix_rewrite_guard_repair_option();

$reinicia = static function ( $gravadas, $registradas, $extra = array() ) {
	$GLOBALS['wp_rewrite']                  = new WP_Rewrite();
	$GLOBALS['wp_rewrite']->extra_rules_top = $registradas;
	$GLOBALS['uox_options']                 = array( 'rewrite_rules' => $gravadas ) + $extra;
	$GLOBALS['uox_lidas']                   = array();
	$GLOBALS['uox_flush']                   = array();
	$GLOBALS['uox_actions']                 = array();
	$_POST                                  = array();
};
$agendouShutdown = static function () {
	foreach ( $GLOBALS['uox_actions'] as $a ) {
		if ( 'shutdown' === $a[0] ) {
			return $a;
		}
	}
	return null;
};

// ---------------------------------------------------------------------------
// 1. Registro: confere em `wp_loaded`, depois do `init` inteiro, sem argumento.
// ---------------------------------------------------------------------------
$registro = null;
foreach ( $GLOBALS['uox_actions'] as $a ) {
	if ( 'wp_loaded' === $a[0] && 'uonix_rewrite_guard_check' === $a[1] ) {
		$registro = $a;
	}
}
verifica( null !== $registro, 'a guarda é registrada em wp_loaded' );
verifica( null !== $registro && 0 === $registro[3], 'o callback de wp_loaded não recebe argumento (accepted_args 0)' );

// ---------------------------------------------------------------------------
// 2. Caminho normal: tudo gravado. Nada agendado, e nenhuma leitura além da opção
//    `rewrite_rules`, que é autoload (já está na memória).
// ---------------------------------------------------------------------------
$reinicia( $GRAVADAS_COMPLETAS, $REGRAS_RANK_MATH );
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'ok' === $estado, "regras completas: estado ok; obteve {$estado}" );
verifica( null === $agendouShutdown() && array() === $GLOBALS['uox_flush'], 'regras completas: nenhuma regravação' );
verifica( array( 'rewrite_rules' ) === $GLOBALS['uox_lidas'], 'caminho normal lê só rewrite_rules (autoload), sem consulta extra; leu ' . implode( ',', $GLOBALS['uox_lidas'] ) );

// ---------------------------------------------------------------------------
// 3. O defeito: a opção foi regravada sem o Rank Math. Requisição comum agenda a
//    regravação suave no shutdown, grava o registro sem autoload e escreve no log.
// ---------------------------------------------------------------------------
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH );
file_put_contents( $logTeste, '' );
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'scheduled' === $estado, "regras do Rank Math ausentes: regravação agendada; obteve {$estado}" );
verifica( array() === $GLOBALS['uox_flush'], 'a regravação não roda no meio da requisição, só no shutdown' );
$sd = $agendouShutdown();
verifica( null !== $sd && 0 === $sd[3], 'regravação agendada no shutdown, sem argumento' );
if ( null !== $sd ) {
	call_user_func( $sd[1] );
}
verifica( array( false ) === $GLOBALS['uox_flush'], 'a regravação é suave (flush_rewrite_rules( false )), sem mexer no .htaccess; obteve ' . var_export( $GLOBALS['uox_flush'], true ) );
$registroReparo = $GLOBALS['uox_options'][ $opcaoReparo ] ?? null;
verifica( is_array( $registroReparo ) && $AGORA === ( $registroReparo['at'] ?? 0 ), 'o reparo fica registrado com a hora' );
verifica( is_array( $registroReparo ) && in_array( 'sitemap_index\.xml$', (array) ( $registroReparo['missing'] ?? array() ), true ), 'o registro diz qual regra faltava' );
verifica( false === ( $GLOBALS['uox_autoload'][ $opcaoReparo ] ?? null ), 'o registro do reparo é gravado sem autoload' );
verifica( false !== strpos( (string) file_get_contents( $logTeste ), 'UONIX' ) && false !== strpos( (string) file_get_contents( $logTeste ), 'sitemap_index' ), 'o reparo aparece no log do PHP' );

// Duas conferências na mesma requisição agendam uma regravação só: a segunda cai no intervalo.
verifica( 'cooldown' === uonix_rewrite_guard_check( $AGORA ), 'a segunda conferência da mesma requisição cai no intervalo' );
$quantos = 0;
foreach ( $GLOBALS['uox_actions'] as $a ) {
	$quantos += 'shutdown' === $a[0] ? 1 : 0;
}
verifica( 1 === $quantos, "uma regravação por requisição; agendou {$quantos}" );

// Basta faltar uma das regras de sitemap.
$semXsl = $GRAVADAS_COMPLETAS;
unset( $semXsl['([a-z]+)?-?sitemap\.xsl$'] );
$reinicia( $semXsl, $REGRAS_RANK_MATH );
verifica( 'scheduled' === uonix_rewrite_guard_check( $AGORA ), 'falta só a regra do .xsl: também agenda' );

// ---------------------------------------------------------------------------
// 4. Heartbeat: NUNCA regrava, mesmo com as regras ausentes e registradas.
// ---------------------------------------------------------------------------
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH );
$_POST  = array( 'action' => 'heartbeat' );
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'heartbeat' === $estado && null === $agendouShutdown(), "heartbeat não regrava; obteve {$estado}" );
verifica( ! isset( $GLOBALS['uox_options'][ $opcaoReparo ] ), 'heartbeat não grava registro' );

// ---------------------------------------------------------------------------
// 5. Sem o Rank Math carregado nesta requisição (heartbeat de outro jeito, módulo de
//    sitemap desligado): nada registrado, nada a comparar, nada agendado.
// ---------------------------------------------------------------------------
$reinicia( $GRAVADAS_SEM_RANK_MATH, array( 'outra-regra/?$' => 'index.php?pagename=outra' ) );
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'not_registered' === $estado && null === $agendouShutdown(), "sem regra de sitemap registrada: nada a fazer; obteve {$estado}" );

// ---------------------------------------------------------------------------
// 6. Opção vazia ou malformada: o próprio WordPress regenera sob demanda. A guarda não
//    interfere.
// ---------------------------------------------------------------------------
foreach ( array( '', false, 'lixo' ) as $gravadas ) {
	$reinicia( $gravadas, $REGRAS_RANK_MATH );
	$estado = uonix_rewrite_guard_check( $AGORA );
	verifica( 'empty' === $estado && null === $agendouShutdown(), 'rewrite_rules ' . var_export( $gravadas, true ) . ": nada agendado; obteve {$estado}" );
}

// ---------------------------------------------------------------------------
// 7. Sem links permanentes, as regras não são usadas.
// ---------------------------------------------------------------------------
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH );
$GLOBALS['wp_rewrite']->permalink_structure = '';
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'no_permalinks' === $estado && null === $agendouShutdown(), "sem links permanentes: nada agendado; obteve {$estado}" );

// ---------------------------------------------------------------------------
// 8. Intervalo mínimo: se a regra não "pegar", a guarda não regrava a cada requisição.
// ---------------------------------------------------------------------------
$intervalo = uonix_rewrite_guard_cooldown();
verifica( $intervalo >= 5 * MINUTE_IN_SECONDS && $intervalo <= 60 * MINUTE_IN_SECONDS, "intervalo mínimo entre 5 e 60 min; é {$intervalo} s" );
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH, array( $opcaoReparo => array( 'at' => $AGORA - $intervalo + 1, 'missing' => array() ) ) );
$estado = uonix_rewrite_guard_check( $AGORA );
verifica( 'cooldown' === $estado && null === $agendouShutdown(), "reparo recente: espera o intervalo; obteve {$estado}" );
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH, array( $opcaoReparo => array( 'at' => $AGORA - $intervalo, 'missing' => array() ) ) );
verifica( 'scheduled' === uonix_rewrite_guard_check( $AGORA ), 'passado o intervalo: regrava de novo' );
// Registro com hora no futuro (relógio adiantado e depois corrigido) não trava a guarda para sempre.
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH, array( $opcaoReparo => array( 'at' => $AGORA + 30 * 86400, 'missing' => array() ) ) );
verifica( 'scheduled' === uonix_rewrite_guard_check( $AGORA ), 'registro com hora no futuro não trava a guarda' );
// Registro malformado não trava nem quebra.
$reinicia( $GRAVADAS_SEM_RANK_MATH, $REGRAS_RANK_MATH, array( $opcaoReparo => 'lixo' ) );
verifica( 'scheduled' === uonix_rewrite_guard_check( $AGORA ), 'registro malformado não trava a guarda' );

// ---------------------------------------------------------------------------
// 9. O carregador do módulo precisa incluir o arquivo, senão nada disso roda.
// ---------------------------------------------------------------------------
$modulo = (string) file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/module.php' );
verifica( false !== strpos( $modulo, "'50-seo-regras-reescrita-rank-math.php'" ), 'module.php do uonix-content carrega a guarda' );

@unlink( $logTeste );

if ( $falhas > 0 ) {
	fwrite( STDERR, "\n{$falhas} verificação(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: guarda das regras de reescrita do Rank Math (#305).\n" );
