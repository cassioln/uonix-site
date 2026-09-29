<?php
/**
 * Testes da licença da Central de Inteligência (50-admin-intelligence-license.php).
 *
 * A trava nos pontos de envio é testada junto de cada um: test-admin-intelligence-report.php
 * (relatório e envio de teste) e test-admin-intelligence-anomalies.php (alerta).
 *
 * Contrato: docs/uonix-insights-inteligencia.md, seção *Licenciamento*.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$failures = 0;
function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

// Registra qualquer tentativa de hook: o arquivo não pode registrar nenhum.
$GLOBALS['uox_hooks'] = array();
function add_action( $hook ) { $GLOBALS['uox_hooks'][] = $hook; return true; }
function add_filter( $hook ) { $GLOBALS['uox_hooks'][] = $hook; return true; }
function wp_timezone() { return new DateTimeZone( 'America/Sao_Paulo' ); }

$RAIZ    = dirname( __DIR__, 2 );
$ARQUIVO = $RAIZ . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';
require_once $ARQUIVO;

// ---------------------------------------------------------------------------
// 1. Sem efeito fora da Central: o arquivo só define funções.
// ---------------------------------------------------------------------------
uox_assert( array() === $GLOBALS['uox_hooks'], 'o arquivo da licença não pode registrar hook; registrou ' . implode( ', ', $GLOBALS['uox_hooks'] ) );

// ---------------------------------------------------------------------------
// 2. A regra pura.
// ---------------------------------------------------------------------------
$hoje = new DateTimeImmutable( '2026-09-29 10:00:00', new DateTimeZone( 'America/Sao_Paulo' ) );
function uox_licenca( $status, $valid_until ) {
	return uonix_intelligence_license_evaluate( $status, $valid_until, $GLOBALS['hoje'] );
}

$padrao = uox_licenca( null, null );
uox_assert( true === $padrao['sending'], 'sem nenhuma constante o envio segue como sempre' );
uox_assert( false === $padrao['configured'], 'sem nenhuma constante o controle conta como não configurado' );
uox_assert( '' === $padrao['reason'], 'sem nenhuma constante não há motivo de pausa' );

$envia = array(
	'active sem data'             => array( 'active', null ),
	'active até amanhã'           => array( 'active', '2026-09-30' ),
	'active no último dia'        => array( 'active', '2026-09-29' ),
	'trial no prazo'              => array( 'trial', '2026-10-31' ),
	'trial no último dia'         => array( 'trial', '2026-09-29' ),
	'só a data, no prazo'         => array( null, '2026-12-31' ),
);
foreach ( $envia as $caso => $par ) {
	$r = uox_licenca( $par[0], $par[1] );
	uox_assert( true === $r['sending'] && '' === $r['reason'], "{$caso}: deveria enviar; obteve " . var_export( $r, true ) );
	uox_assert( true === $r['configured'], "{$caso}: com constante definida o controle conta como configurado" );
}

$pausa = array(
	'suspended'                   => array( 'suspended', null, 'suspended' ),
	'suspended com data futura'   => array( 'suspended', '2030-01-01', 'suspended' ),
	'active vencido ontem'        => array( 'active', '2026-09-28', 'expired' ),
	'trial vencido'               => array( 'trial', '2026-09-01', 'expired' ),
	'só a data, vencida'          => array( null, '2026-09-28', 'expired' ),
	'trial sem data-limite'       => array( 'trial', null, 'invalid' ),
	// Erro de digitação ao suspender não pode manter o envio ligado.
	'status em português'         => array( 'suspenso', null, 'invalid' ),
	'status em maiúscula'         => array( 'Active', null, 'invalid' ),
	'status com espaço'           => array( ' active', null, 'invalid' ),
	'status vazio'                => array( '', null, 'invalid' ),
	'status booleano'             => array( true, null, 'invalid' ),
	'status numérico'             => array( 1, null, 'invalid' ),
	'data inexistente'            => array( 'active', '2026-02-30', 'invalid' ),
	'data sem zero à esquerda'    => array( 'active', '2026-9-30', 'invalid' ),
	'data no formato brasileiro'  => array( 'active', '30/09/2026', 'invalid' ),
	'data vazia'                  => array( 'active', '', 'invalid' ),
	'data numérica'               => array( 'active', 20261231, 'invalid' ),
);
foreach ( $pausa as $caso => $trio ) {
	$r = uox_licenca( $trio[0], $trio[1] );
	uox_assert( false === $r['sending'], "{$caso}: deveria pausar; obteve " . var_export( $r, true ) );
	uox_assert( $trio[2] === $r['reason'], "{$caso}: o motivo deveria ser {$trio[2]}; obteve {$r['reason']}" );
}

// ---------------------------------------------------------------------------
// 3. Leitura das constantes, em processo separado porque constante não se redefine.
// ---------------------------------------------------------------------------
function uox_sub( $prefixo, $expressao ) {
	$codigo = 'define("ABSPATH", 1); function add_action() {} function add_filter() {} ' . $prefixo
		. ' require ' . var_export( $GLOBALS['ARQUIVO'], true ) . '; echo json_encode(' . $expressao . ');';
	$saida = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $codigo ) . ' 2>&1' );
	return json_decode( (string) $saida, true );
}

$r = uox_sub( 'function wp_timezone() { return new DateTimeZone("America/Sao_Paulo"); }', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && true === $r['sending'] && false === $r['configured'], 'sem constante, o leitor devolve envio ativo e não configurado; obteve ' . var_export( $r, true ) );

$r = uox_sub( 'define("KSIODEV_INTELLIGENCE_STATUS", "suspended");', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && false === $r['sending'] && 'suspended' === $r['reason'], 'o leitor lê KSIODEV_INTELLIGENCE_STATUS; obteve ' . var_export( $r, true ) );

$r = uox_sub( 'define("KSIODEV_INTELLIGENCE_STATUS", "active"); define("KSIODEV_INTELLIGENCE_VALID_UNTIL", "2000-01-01");', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && false === $r['sending'] && 'expired' === $r['reason'], 'o leitor lê KSIODEV_INTELLIGENCE_VALID_UNTIL; obteve ' . var_export( $r, true ) );

// O "hoje" é o do fuso do site. Kiritimati (UTC+14) e Pago Pago (UTC-11) estão
// sempre em datas diferentes; a data-limite é o dia corrente em Pago Pago.
$fim = '(new DateTimeImmutable("now", new DateTimeZone("Pacific/Pago_Pago")))->format("Y-m-d")';
$r   = uox_sub( 'function wp_timezone() { return new DateTimeZone("Pacific/Kiritimati"); } define("KSIODEV_INTELLIGENCE_VALID_UNTIL", ' . $fim . ');', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && 'expired' === $r['reason'], 'com o site em Kiritimati, o último dia de Pago Pago já passou; obteve ' . var_export( $r, true ) );
$r = uox_sub( 'function wp_timezone() { return new DateTimeZone("Pacific/Pago_Pago"); } define("KSIODEV_INTELLIGENCE_VALID_UNTIL", ' . $fim . ');', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && true === $r['sending'], 'com o site em Pago Pago, hoje é o último dia e ainda envia; obteve ' . var_export( $r, true ) );

// ---------------------------------------------------------------------------
// 4. Textos: aviso de contato só quando pausado, e resumo para o dono.
// ---------------------------------------------------------------------------
uox_assert( '' === uonix_intelligence_license_message( uox_licenca( 'active', null ) ), 'com o envio ativo não há aviso' );
uox_assert( '' === uonix_intelligence_license_message( uox_licenca( null, null ) ), 'sem controle configurado não há aviso' );

$msg = uonix_intelligence_license_message( uox_licenca( 'suspended', null ) );
uox_assert( false !== strpos( $msg, 'suspenso' ) && false !== strpos( $msg, 'ksio.dev' ), 'suspenso: o aviso diz que está suspenso e manda falar com a ksio.dev; obteve ' . $msg );
$msg = uonix_intelligence_license_message( uox_licenca( 'trial', '2026-09-01' ) );
uox_assert( false !== strpos( $msg, '01/09/2026' ) && false !== strpos( $msg, 'ksio.dev' ), 'vencido: o aviso traz a data de vencimento; obteve ' . $msg );
$msg = uonix_intelligence_license_message( uox_licenca( 'suspenso', null ) );
uox_assert( false !== strpos( $msg, 'não é válida' ), 'inválido: o aviso diz que a configuração não é válida; obteve ' . $msg );
uox_assert( '' !== uonix_intelligence_license_message( 'lixo' ), 'estado que não é array conta como pausado e tem aviso' );

uox_assert( 0 === strpos( uonix_intelligence_license_summary( uox_licenca( null, null ) ), 'Sem controle configurado' ), 'resumo sem constante diz que não há controle configurado' );
uox_assert( false !== strpos( uonix_intelligence_license_summary( uox_licenca( 'trial', '2026-10-31' ) ), 'Cortesia (trial) até 31/10/2026' ), 'resumo da cortesia traz o prazo' );
uox_assert( false !== strpos( uonix_intelligence_license_summary( uox_licenca( 'active', null ) ), 'sem data-limite' ), 'resumo do contratado sem data diz que não há data-limite' );
$suspenso = uox_licenca( 'suspended', null );
uox_assert( uonix_intelligence_license_message( $suspenso ) === uonix_intelligence_license_summary( $suspenso ), 'com o envio pausado o resumo é o próprio aviso' );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: licença da Central de Inteligência.\n" );
