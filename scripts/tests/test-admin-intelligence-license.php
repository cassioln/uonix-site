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

// Registra todo hook, para conferir que o arquivo registra só os dois da licença.
$GLOBALS['uox_hooks']   = array();
$GLOBALS['uox_filtros'] = array();
function add_action( $hook, $callback = null, $prioridade = 10, $args = 1 ) {
	$GLOBALS['uox_hooks'][] = $hook;
	return true;
}
function add_filter( $hook, $callback = null, $prioridade = 10, $args = 1 ) {
	$GLOBALS['uox_hooks'][]            = $hook;
	$GLOBALS['uox_filtros'][ $hook ][] = array( $callback, $prioridade, $args );
	return true;
}
function wp_timezone() { return new DateTimeZone( 'America/Sao_Paulo' ); }

// Opções em memória. `update_option` segue o core: aplica `pre_update_option_{$nome}`
// e desiste se o valor voltar igual ao antigo, inclusive na primeira gravação, em que
// o antigo é `false`.
$GLOBALS['uox_options'] = array();
function get_option( $nome, $padrao = false ) {
	return array_key_exists( $nome, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $nome ] : $padrao;
}
function update_option( $nome, $valor, $autoload = null ) {
	$antigo = get_option( $nome );
	foreach ( $GLOBALS['uox_filtros'][ 'pre_update_option_' . $nome ] ?? array() as $filtro ) {
		$valor = call_user_func( $filtro[0], $valor, $antigo, $nome );
	}
	if ( $valor === $antigo || serialize( $valor ) === serialize( $antigo ) ) {
		return false;
	}
	$GLOBALS['uox_options'][ $nome ] = $valor;
	return true;
}
function delete_option( $nome ) {
	$existia = array_key_exists( $nome, $GLOBALS['uox_options'] );
	unset( $GLOBALS['uox_options'][ $nome ] );
	return $existia;
}

// O 49 decide quem é o dono; aqui é um interruptor.
$GLOBALS['uox_dono'] = false;
function uonix_ksio_can_configure_insights() { return $GLOBALS['uox_dono']; }

class Uox_Die_Exception extends Exception {}
class Uox_Redirect_Exception extends Exception {}
$GLOBALS['uox_nonce']       = array();
$GLOBALS['uox_nonce_ok']    = true;
$GLOBALS['uox_redirect']    = '';
$GLOBALS['uox_die_status']  = 0;
function wp_die( $mensagem = '', $titulo = '', $args = array() ) {
	$GLOBALS['uox_die_status'] = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
	throw new Uox_Die_Exception( (string) $mensagem );
}
function esc_html__( $texto ) { return $texto; }
function check_admin_referer( $acao ) {
	$GLOBALS['uox_nonce'][] = $acao;
	if ( ! $GLOBALS['uox_nonce_ok'] ) {
		wp_die( 'nonce inválido', '', array( 'response' => 403 ) );
	}
	return 1;
}
function wp_unslash( $valor ) { return $valor; }
function admin_url( $caminho = '' ) { return 'https://exemplo.test/wp-admin/' . $caminho; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( $url ) {
	$GLOBALS['uox_redirect'] = $url;
	throw new Uox_Redirect_Exception( $url );
}

$RAIZ    = dirname( __DIR__, 2 );
$ARQUIVO = $RAIZ . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';
require_once $ARQUIVO;

// ---------------------------------------------------------------------------
// 1. Hooks: só o handler do formulário e a trava de gravação da própria opção.
// ---------------------------------------------------------------------------
uox_assert(
	array( 'admin_post_uonix_intelligence_save_license', 'pre_update_option_uonix_intelligence_license' ) === $GLOBALS['uox_hooks'],
	'o arquivo da licença registra só o handler e a trava da opção; registrou ' . implode( ', ', $GLOBALS['uox_hooks'] )
);
$trava = $GLOBALS['uox_filtros']['pre_update_option_uonix_intelligence_license'][0] ?? array( null, 0, 0 );
uox_assert( 'uonix_intelligence_license_guard_write' === $trava[0] && PHP_INT_MAX === $trava[1] && 2 === $trava[2], 'a trava roda por último no filtro e recebe valor novo e antigo; obteve ' . var_export( $trava, true ) );
uox_assert( 'uonix_intelligence_license' === uonix_intelligence_license_option(), 'nome da opção do painel' );

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
function uox_sub( $prefixo, $expressao, $arquivo = null ) {
	$codigo = 'define("ABSPATH", 1); function add_action() {} function add_filter() {} ' . $prefixo
		. ' require ' . var_export( null === $arquivo ? $GLOBALS['ARQUIVO'] : $arquivo, true ) . '; echo json_encode(' . $expressao . ');';
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
uox_assert( uonix_intelligence_license_message( $suspenso ) === uonix_intelligence_license_summary( $suspenso ), 'sem origem declarada, o resumo do envio pausado é o próprio aviso' );
uox_assert( false !== strpos( uonix_intelligence_license_summary( uox_licenca( null, null ) ), 'licença no painel' ), 'resumo sem controle cita as duas camadas' );

// ---------------------------------------------------------------------------
// 5. A camada do painel: opção ausente não restringe; presente e estranha pausa.
// ---------------------------------------------------------------------------
function uox_painel( $gravado ) {
	return uonix_intelligence_license_panel_evaluate( $gravado, $GLOBALS['hoje'] );
}

$r = uox_painel( null );
uox_assert( true === $r['sending'] && false === $r['configured'], 'opção ausente: o painel não restringe; obteve ' . var_export( $r, true ) );

$painel_envia = array(
	'active sem data'           => array( 'status' => 'active', 'valid_until' => '' ),
	'active sem a chave da data' => array( 'status' => 'active' ),
	'active no prazo'           => array( 'status' => 'active', 'valid_until' => '2026-09-29' ),
	'trial no prazo'            => array( 'status' => 'trial', 'valid_until' => '2026-10-31' ),
);
foreach ( $painel_envia as $caso => $gravado ) {
	$r = uox_painel( $gravado );
	uox_assert( true === $r['sending'] && true === $r['configured'], "painel {$caso}: deveria enviar; obteve " . var_export( $r, true ) );
}

$painel_pausa = array(
	'suspended'                 => array( array( 'status' => 'suspended', 'valid_until' => '' ), 'suspended' ),
	'active vencido'            => array( array( 'status' => 'active', 'valid_until' => '2026-09-28' ), 'expired' ),
	'trial sem data'            => array( array( 'status' => 'trial', 'valid_until' => '' ), 'invalid' ),
	'sem a chave do status'     => array( array( 'valid_until' => '2026-12-31' ), 'invalid' ),
	'status nulo'               => array( array( 'status' => null ), 'invalid' ),
	'status desconhecido'       => array( array( 'status' => 'suspenso' ), 'invalid' ),
	'data que é array'          => array( array( 'status' => 'active', 'valid_until' => array() ), 'invalid' ),
	'data inexistente'          => array( array( 'status' => 'active', 'valid_until' => '2026-02-30' ), 'invalid' ),
	'array vazio'               => array( array(), 'invalid' ),
	'texto'                     => array( 'suspended', 'invalid' ),
	'string vazia'              => array( '', 'invalid' ),
	'false'                     => array( false, 'invalid' ),
);
foreach ( $painel_pausa as $caso => $par ) {
	$r = uox_painel( $par[0] );
	uox_assert( false === $r['sending'] && true === $r['configured'] && $par[1] === $r['reason'], "painel {$caso}: deveria pausar com {$par[1]}; obteve " . var_export( $r, true ) );
}

// ---------------------------------------------------------------------------
// 6. Combinação: vale a mais restritiva, e o painel nunca religa a constante.
// ---------------------------------------------------------------------------
$chaves = array( 'configured', 'status', 'valid_until', 'sending', 'reason', 'source', 'constant', 'panel' );
$matriz = array(
	// caso => constante, painel, envia?, motivo, origem, data que vale
	'nenhuma das duas'                     => array( array( null, null ), null, true, '', '', '' ),
	'constante suspende, painel ativo'     => array( array( 'suspended', null ), array( 'status' => 'active', 'valid_until' => '' ), false, 'suspended', 'constant', '' ),
	'constante suspende, painel ausente'   => array( array( 'suspended', null ), null, false, 'suspended', 'constant', '' ),
	'constante ativa, painel suspende'     => array( array( 'active', null ), array( 'status' => 'suspended', 'valid_until' => '' ), false, 'suspended', 'panel', '' ),
	'sem constante, painel suspende'       => array( array( null, null ), array( 'status' => 'suspended', 'valid_until' => '' ), false, 'suspended', 'panel', '' ),
	'as duas suspendem'                    => array( array( 'suspended', null ), array( 'status' => 'suspended', 'valid_until' => '' ), false, 'suspended', 'constant', '' ),
	'constante ativa, painel vencido'      => array( array( 'active', null ), array( 'status' => 'active', 'valid_until' => '2026-09-01' ), false, 'expired', 'panel', '2026-09-01' ),
	'constante inválida, painel ativo'     => array( array( 'suspenso', null ), array( 'status' => 'active', 'valid_until' => '' ), false, 'invalid', 'constant', '' ),
	'constante ativa, painel malformado'   => array( array( 'active', null ), 'lixo', false, 'invalid', 'panel', '' ),
	'as duas ativas, painel mais curto'    => array( array( 'active', '2026-12-31' ), array( 'status' => 'active', 'valid_until' => '2026-10-31' ), true, '', 'panel', '2026-10-31' ),
	'as duas ativas, constante mais curta' => array( array( 'active', '2026-10-31' ), array( 'status' => 'trial', 'valid_until' => '2026-12-31' ), true, '', 'constant', '2026-10-31' ),
	'constante sem data, painel com data'  => array( array( 'active', null ), array( 'status' => 'trial', 'valid_until' => '2026-10-31' ), true, '', 'panel', '2026-10-31' ),
	'constante com data, painel sem data'  => array( array( 'active', '2026-10-31' ), array( 'status' => 'active', 'valid_until' => '' ), true, '', 'constant', '2026-10-31' ),
	'mesmo prazo nas duas'                 => array( array( 'active', '2026-10-31' ), array( 'status' => 'trial', 'valid_until' => '2026-10-31' ), true, '', 'constant', '2026-10-31' ),
	'as duas sem data'                     => array( array( 'active', null ), array( 'status' => 'active', 'valid_until' => '' ), true, '', 'constant', '' ),
	'só o painel, ativo'                   => array( array( null, null ), array( 'status' => 'active', 'valid_until' => '' ), true, '', 'panel', '' ),
	'só a constante, ativa'                => array( array( 'active', null ), null, true, '', 'constant', '' ),
);
foreach ( $matriz as $caso => $linha ) {
	$constante = uox_licenca( $linha[0][0], $linha[0][1] );
	$painel    = uox_painel( $linha[1] );
	$r         = uonix_intelligence_license_combine( $constante, $painel );
	uox_assert( $chaves === array_keys( $r ), "{$caso}: chaves do estado combinado; obteve " . implode( ',', array_keys( $r ) ) );
	uox_assert( $linha[2] === $r['sending'], "{$caso}: envio deveria ser " . var_export( $linha[2], true ) . '; obteve ' . var_export( $r, true ) );
	uox_assert( $linha[3] === $r['reason'], "{$caso}: motivo deveria ser '{$linha[3]}'; obteve '{$r['reason']}'" );
	uox_assert( $linha[4] === $r['source'], "{$caso}: origem deveria ser '{$linha[4]}'; obteve '{$r['source']}'" );
	uox_assert( $linha[5] === $r['valid_until'], "{$caso}: data que vale deveria ser '{$linha[5]}'; obteve '{$r['valid_until']}'" );
	uox_assert( $constante === $r['constant'] && $painel === $r['panel'], "{$caso}: as duas camadas seguem no retorno, sem alteração" );
	uox_assert( ( ! empty( $constante['configured'] ) || ! empty( $painel['configured'] ) ) === $r['configured'], "{$caso}: configurado se qualquer camada estiver" );
}

// O leitor combina a constante com a opção gravada. Aqui não há constante definida.
$GLOBALS['uox_options'] = array();
$r = uonix_intelligence_license_state( $hoje );
uox_assert( true === $r['sending'] && false === $r['configured'] && '' === $r['source'], 'sem constante e sem opção, o leitor envia; obteve ' . var_export( $r, true ) );
$GLOBALS['uox_options']['uonix_intelligence_license'] = array( 'status' => 'suspended', 'valid_until' => '' );
$r = uonix_intelligence_license_state( $hoje );
uox_assert( false === $r['sending'] && 'panel' === $r['source'] && 'suspended' === $r['reason'], 'o leitor lê a opção do painel; obteve ' . var_export( $r, true ) );
$GLOBALS['uox_options'] = array();

// ---------------------------------------------------------------------------
// 7. Textos com origem, para o dono.
// ---------------------------------------------------------------------------
$combinado = uonix_intelligence_license_combine( uox_licenca( null, null ), uox_painel( array( 'status' => 'trial', 'valid_until' => '2026-10-31' ) ) );
uox_assert( false !== strpos( uonix_intelligence_license_summary( $combinado ), 'Cortesia (trial) até 31/10/2026, pelo painel do Uônix Insights. O envio está ativo.' ), 'resumo ativo diz de onde vem o prazo; obteve ' . uonix_intelligence_license_summary( $combinado ) );
$combinado = uonix_intelligence_license_combine( uox_licenca( 'suspended', null ), uox_painel( null ) );
$resumo    = uonix_intelligence_license_summary( $combinado );
uox_assert( 0 === strpos( $resumo, uonix_intelligence_license_message( $combinado ) ) && false !== strpos( $resumo, 'Origem: wp-config.php.' ), 'resumo pausado é o aviso mais a origem; obteve ' . $resumo );
$combinado = uonix_intelligence_license_combine( uox_licenca( null, null ), uox_painel( null ) );
uox_assert( 0 === strpos( uonix_intelligence_license_summary( $combinado ), 'Sem controle configurado' ), 'resumo combinado sem nenhuma camada diz que não há controle' );

$camadas = array(
	'ausente'   => array( uox_licenca( null, null ), 'Sem controle.' ),
	'sem data'  => array( uox_licenca( 'active', null ), 'Contratada (active), sem data-limite. Envia.' ),
	'cortesia'  => array( uox_licenca( 'trial', '2026-10-31' ), 'Cortesia (trial) até 31/10/2026. Envia.' ),
	'suspensa'  => array( uox_licenca( 'suspended', null ), 'Suspensa. Pausa o envio.' ),
	'vencida'   => array( uox_licenca( 'active', '2026-09-28' ), 'Vencida em 28/09/2026. Pausa o envio.' ),
	'inválida'  => array( uox_licenca( 'suspenso', null ), 'Configuração inválida. Pausa o envio.' ),
	'não array' => array( null, 'Sem controle.' ),
);
foreach ( $camadas as $caso => $par ) {
	$texto = uonix_intelligence_license_layer_summary( $par[0] );
	uox_assert( $par[1] === $texto, "linha da camada, {$caso}: esperado '{$par[1]}'; obteve '{$texto}'" );
}
uox_assert( '' === uonix_intelligence_license_source_label( 'outro' ) && '' === uonix_intelligence_license_source_label( array() ), 'origem desconhecida não vira texto' );

// ---------------------------------------------------------------------------
// 8. Validação do formulário, antes de gravar.
// ---------------------------------------------------------------------------
$entradas = array(
	'sem controle'            => array( '', '', '', null ),
	'sem controle com data'   => array( '', '2026-10-31', '', null ),
	'active sem data'         => array( 'active', '', '', array( 'status' => 'active', 'valid_until' => '' ) ),
	'active com data passada' => array( 'active', '2026-09-01', '', array( 'status' => 'active', 'valid_until' => '2026-09-01' ) ),
	'trial com data'          => array( 'trial', '2026-10-31', '', array( 'status' => 'trial', 'valid_until' => '2026-10-31' ) ),
	'suspended sem data'      => array( 'suspended', '', '', array( 'status' => 'suspended', 'valid_until' => '' ) ),
	'status desconhecido'     => array( 'suspenso', '', 'status', null ),
	'status em maiúscula'     => array( 'Active', '', 'status', null ),
	'status array'            => array( array( 'active' ), '', 'status', null ),
	'status nulo'             => array( null, '', 'status', null ),
	'data inexistente'        => array( 'active', '2026-02-30', 'date', null ),
	'data sem zero'           => array( 'active', '2026-9-30', 'date', null ),
	'data brasileira'         => array( 'active', '30/09/2026', 'date', null ),
	'data array'              => array( 'active', array( '2026-10-31' ), 'date', null ),
	'trial sem data'          => array( 'trial', '', 'trial', null ),
);
foreach ( $entradas as $caso => $linha ) {
	$r = uonix_intelligence_license_panel_input( $linha[0], $linha[1] );
	uox_assert( array( 'error' => $linha[2], 'value' => $linha[3] ) === $r, "entrada {$caso}: obteve " . var_export( $r, true ) );
}

// ---------------------------------------------------------------------------
// 9. Handler: só o dono grava, e só status e data.
// ---------------------------------------------------------------------------
function uox_salvar( array $post, $dono = true, $nonce_ok = true ) {
	$_POST                     = $post;
	$GLOBALS['uox_dono']       = $dono;
	$GLOBALS['uox_nonce_ok']   = $nonce_ok;
	$GLOBALS['uox_nonce']      = array();
	$GLOBALS['uox_redirect']   = '';
	$GLOBALS['uox_die_status'] = 0;
	try {
		uonix_intelligence_save_license();
		return 'retornou';
	} catch ( Uox_Die_Exception $e ) {
		return 'die';
	} catch ( Uox_Redirect_Exception $e ) {
		return 'redirect';
	} finally {
		$GLOBALS['uox_dono']     = false;
		$GLOBALS['uox_nonce_ok'] = true;
	}
}
function uox_query( $url ) {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}
$anterior = array( 'status' => 'active', 'valid_until' => '2026-12-31' );

// Quem não é o dono: 403 antes do nonce, e nada muda.
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
$r = uox_salvar( array( 'uonix_license_status' => 'suspended', 'uonix_license_valid_until' => '' ), false );
uox_assert( 'die' === $r && 403 === $GLOBALS['uox_die_status'], 'quem não é o dono recebe 403; obteve ' . $r );
uox_assert( array() === $GLOBALS['uox_nonce'], 'a guarda do dono vem antes do nonce' );
uox_assert( $anterior === get_option( 'uonix_intelligence_license' ), 'quem não é o dono não altera a licença' );
$r = uox_salvar( array( 'uonix_license_status' => '' ), false );
uox_assert( 'die' === $r && $anterior === get_option( 'uonix_intelligence_license' ), 'quem não é o dono também não apaga a licença' );

// Dono com nonce inválido: nada muda.
$r = uox_salvar( array( 'uonix_license_status' => 'suspended' ), true, false );
uox_assert( 'die' === $r && array( 'uonix_intelligence_save_license' ) === $GLOBALS['uox_nonce'], 'o dono passa pelo nonce da ação certa; obteve ' . var_export( $GLOBALS['uox_nonce'], true ) );
uox_assert( $anterior === get_option( 'uonix_intelligence_license' ), 'nonce inválido não grava' );

// Dono: grava só as duas chaves, e campo extra no POST é ignorado.
$GLOBALS['uox_options'] = array();
$r = uox_salvar( array( 'uonix_license_status' => 'trial', 'uonix_license_valid_until' => ' 2026-10-31 ', 'uonix_license_extra' => 'x', 'status' => 'active' ) );
$q = uox_query( $GLOBALS['uox_redirect'] );
uox_assert( 'redirect' === $r && array( 'status' => 'trial', 'valid_until' => '2026-10-31' ) === get_option( 'uonix_intelligence_license' ), 'o dono grava status e data, sem espaços e sem campo extra; obteve ' . var_export( get_option( 'uonix_intelligence_license' ), true ) );
uox_assert( array( 'page' => 'uonix-analytics', 'tab' => 'settings', 'uonix_license_saved' => '1' ) === $q, 'volta para a aba Configurações com aviso de salvo; obteve ' . var_export( $q, true ) );

// Entrada inválida: nada é gravado, e o erro volta na URL.
foreach ( array(
	'status'  => array( 'uonix_license_status' => 'suspenso', 'uonix_license_valid_until' => '' ),
	'date'    => array( 'uonix_license_status' => 'active', 'uonix_license_valid_until' => '2026-02-30' ),
	'trial'   => array( 'uonix_license_status' => 'trial', 'uonix_license_valid_until' => '' ),
) as $erro => $post ) {
	$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
	$r = uox_salvar( $post );
	$q = uox_query( $GLOBALS['uox_redirect'] );
	uox_assert( 'redirect' === $r && $erro === ( $q['uonix_license_error'] ?? '' ) && ! isset( $q['uonix_license_saved'] ), "entrada inválida ({$erro}) volta com o erro; obteve " . var_export( $q, true ) );
	uox_assert( $anterior === get_option( 'uonix_intelligence_license' ), "entrada inválida ({$erro}) não altera a licença" );
}

// Sem status no POST é "sem controle": apaga.
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
$r = uox_salvar( array( 'uonix_license_status' => '' ) );
$q = uox_query( $GLOBALS['uox_redirect'] );
uox_assert( 'redirect' === $r && ! array_key_exists( 'uonix_intelligence_license', $GLOBALS['uox_options'] ) && 'cleared' === ( $q['uonix_license_saved'] ?? '' ), '"sem controle" apaga a opção; obteve ' . var_export( $q, true ) );
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
uox_salvar( array() );
uox_assert( ! array_key_exists( 'uonix_intelligence_license', $GLOBALS['uox_options'] ), 'POST sem o campo de status também é "sem controle"' );

// ---------------------------------------------------------------------------
// 10. Trava de gravação: `update_option` de quem não é o dono não muda nada.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_dono']    = false;
uox_assert( false === update_option( 'uonix_intelligence_license', array( 'status' => 'active', 'valid_until' => '' ) ), 'quem não é o dono não cria a opção' );
uox_assert( ! array_key_exists( 'uonix_intelligence_license', $GLOBALS['uox_options'] ), 'a primeira gravação também é barrada' );

$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => array( 'status' => 'suspended', 'valid_until' => '' ) );
update_option( 'uonix_intelligence_license', array( 'status' => 'active', 'valid_until' => '' ) );
uox_assert( array( 'status' => 'suspended', 'valid_until' => '' ) === get_option( 'uonix_intelligence_license' ), 'quem não é o dono não tira a suspensão pelo update_option (caminho do options.php)' );

$GLOBALS['uox_dono'] = true;
update_option( 'uonix_intelligence_license', array( 'status' => 'active', 'valid_until' => '' ) );
uox_assert( array( 'status' => 'active', 'valid_until' => '' ) === get_option( 'uonix_intelligence_license' ), 'o dono grava pelo update_option' );
$GLOBALS['uox_dono']    = false;
$GLOBALS['uox_options'] = array();

// Sem o 49 (e fora do WP-CLI) a trava recusa; no WP-CLI ela passa. Processo separado,
// porque aqui o 49 está simulado e WP_CLI não se redefine.
$r = uox_sub( '', 'uonix_intelligence_license_guard_write("novo", "velho")' );
uox_assert( 'velho' === $r, 'sem o 49 a trava mantém o valor antigo; obteve ' . var_export( $r, true ) );
$r = uox_sub( 'define("WP_CLI", true);', 'uonix_intelligence_license_guard_write("novo", "velho")' );
uox_assert( 'novo' === $r, 'no WP-CLI a trava deixa gravar; obteve ' . var_export( $r, true ) );
$r = uox_sub( 'define("WP_CLI", false);', 'uonix_intelligence_license_guard_write("novo", "velho")' );
uox_assert( 'velho' === $r, 'WP_CLI definida como false não libera; obteve ' . var_export( $r, true ) );
$r = uox_sub( 'function uonix_ksio_can_configure_insights() { return false; }', 'uonix_intelligence_license_guard_write("novo", "velho")' );
uox_assert( 'velho' === $r, 'o 49 dizendo que não é o dono mantém o valor antigo; obteve ' . var_export( $r, true ) );

// Sem o 49 os três handlers de gravação da aba Configurações recusam, cada um
// carregado sozinho: o da licença, o dos destinatários (55) e o do envio de teste (57).
$handlers = array(
	'uonix_intelligence_save_license'     => $GLOBALS['ARQUIVO'],
	'uonix_intelligence_save_recipients'  => dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/55-admin-intelligence-metrics.php',
	'uonix_intelligence_handle_test_send' => dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/57-admin-intelligence-report.php',
);
foreach ( $handlers as $handler => $arquivo ) {
	$r = uox_sub( 'class E extends Exception {} function wp_die($m = "", $t = "", $a = array()) { throw new E("die " . ($a["response"] ?? 0)); } function esc_html__($t) { return $t; } function check_admin_referer() { throw new E("nonce"); }', '(function () { try { ' . $handler . '(); return "retornou"; } catch (E $e) { return $e->getMessage(); } })()', $arquivo );
	uox_assert( 'die 403' === $r, "sem o 49, {$handler} recusa com 403 antes do nonce; obteve " . var_export( $r, true ) );
}

// O leitor combina com a constante também no processo real: a constante suspende
// e o painel ativo não religa.
$r = uox_sub( 'function get_option($n, $d = false) { return "uonix_intelligence_license" === $n ? array("status" => "active", "valid_until" => "") : $d; } define("KSIODEV_INTELLIGENCE_STATUS", "suspended");', 'uonix_intelligence_license_state()' );
uox_assert( is_array( $r ) && false === $r['sending'] && 'constant' === $r['source'] && true === $r['panel']['sending'], 'constante suspensa com painel ativo: pausa pela constante; obteve ' . var_export( $r, true ) );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: licença da Central de Inteligência.\n" );
