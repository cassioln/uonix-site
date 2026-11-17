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
// Warning, notice e deprecated de PHP reprovam a suíte, com o arquivo e a linha (#317). O requisito "sem erro de PHP"
// da #312 só foi provado porque a revisão rodou as suítes com `-d error_reporting=-1`; sem este handler, nada no CI
// prendia isso. O `@` suprime de propósito (`error_reporting()` zera), e o handler respeita isso. O `E_ALL` é forçado aqui, e
// não herdado: o `php.ini` do runner pode silenciar warning ou deprecated, e aí o handler nunca veria nada e a suíte voltaria
// a ser decorativa.
error_reporting( E_ALL );
set_error_handler(
	static function ( $nivel, $mensagem, $arquivo, $linha ) {
		global $failures;
		if ( 0 === ( error_reporting() & $nivel ) ) {
			return false;
		}
		++$failures;
		// A sonda de vida (logo abaixo) conta o erro sem imprimir uma linha "FAIL:" numa suíte verde.
		if ( empty( $GLOBALS['uox_sonda_do_guarda'] ) ) {
			fwrite( STDERR, "FAIL: erro de PHP ({$nivel}): {$mensagem} em " . basename( (string) $arquivo ) . ":{$linha}\n" );
		}

		return true;
	}
);
// Sonda de vida do guarda (#317): um erro de PHP de verdade tem de ser CONTADO por ele. Sem isto, tirar o `error_reporting( E_ALL )`
// (com o INI do runner em 0), fazer o handler devolver `false` ou não incrementar `$failures` deixava as seis suítes verdes.
// A sonda dispara um E_USER_NOTICE, confere que `$failures` subiu em um, e desfaz a contagem para a suíte seguir.
$uox_antes_da_sonda = $failures;
@trigger_error( '', E_USER_NOTICE );
uox_assert( $failures === $uox_antes_da_sonda, 'sonda do guarda: o `@` suprime, e o handler respeita (a contagem não muda)' );
$uox_antes_da_sonda = $failures;
$GLOBALS['uox_sonda_do_guarda'] = true;
trigger_error( 'sonda do guarda #317', E_USER_NOTICE );
$GLOBALS['uox_sonda_do_guarda'] = false;
$uox_contou = ( $failures === $uox_antes_da_sonda + 1 );
$failures = $uox_antes_da_sonda;
uox_assert( $uox_contou, 'sonda do guarda: o handler de erros de PHP CONTA um erro de verdade (E_USER_NOTICE)' );

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

// #315: constante DEFINIDA como `null` não é constante ausente. `define( 'X', null )` é o que o `wp config set --raw` e uma
// edição à mão produzem, e é o caminho de quem suspende à mão. A regra documentada diz que valor não reconhecido pausa:
// definida com `null` é `invalid`, e só a constante AUSENTE é "sem controle". O estado traz `configured` verdadeiro.
foreach ( array(
	'status null'                              => array( 'define("KSIODEV_INTELLIGENCE_STATUS", null);', false, true, 'invalid' ),
	'valid_until null'                         => array( 'define("KSIODEV_INTELLIGENCE_VALID_UNTIL", null);', false, true, 'invalid' ),
	'as duas null'                             => array( 'define("KSIODEV_INTELLIGENCE_STATUS", null); define("KSIODEV_INTELLIGENCE_VALID_UNTIL", null);', false, true, 'invalid' ),
	'status null com data futura'              => array( 'define("KSIODEV_INTELLIGENCE_STATUS", null); define("KSIODEV_INTELLIGENCE_VALID_UNTIL", "2099-01-01");', false, true, 'invalid' ),
	'active com valid_until null'              => array( 'define("KSIODEV_INTELLIGENCE_STATUS", "active"); define("KSIODEV_INTELLIGENCE_VALID_UNTIL", null);', false, true, 'invalid' ),
	'status false'                             => array( 'define("KSIODEV_INTELLIGENCE_STATUS", false);', false, true, 'invalid' ),
	'status inteiro'                           => array( 'define("KSIODEV_INTELLIGENCE_STATUS", 1);', false, true, 'invalid' ),
	'status array'                             => array( 'define("KSIODEV_INTELLIGENCE_STATUS", array("active"));', false, true, 'invalid' ),
	'nenhuma constante (o controle: sem controle)' => array( '', true, false, '' ),
	'só active (o controle: envia)'            => array( 'define("KSIODEV_INTELLIGENCE_STATUS", "active");', true, true, '' ),
) as $caso_c => $c ) {
	$r = uox_sub( $c[0], 'uonix_intelligence_license_state()' );
	uox_assert( is_array( $r ) && $c[1] === $r['sending'] && $c[2] === $r['configured'] && $c[3] === $r['reason'], "#315: {$caso_c}: envio " . var_export( $c[1], true ) . ', configurada ' . var_export( $c[2], true ) . ", motivo '{$c[3]}'; obteve " . var_export( $r, true ) );
}
// A camada da constante isolada diz o mesmo, e a camada do painel não muda: a opção do painel ausente segue sendo "sem controle".
$r_camada = uox_sub( 'define("KSIODEV_INTELLIGENCE_STATUS", null);', 'uonix_intelligence_license_state()["constant"]' );
uox_assert( is_array( $r_camada ) && false === $r_camada['sending'] && true === $r_camada['configured'] && 'invalid' === $r_camada['reason'], '#315: a camada da constante, sozinha, pausa com `invalid`; obteve ' . var_export( $r_camada, true ) );
$r_painel = uox_sub( 'define("KSIODEV_INTELLIGENCE_STATUS", null);', 'uonix_intelligence_license_state()["panel"]' );
uox_assert( is_array( $r_painel ) && true === $r_painel['sending'] && false === $r_painel['configured'], '#315: a camada do painel não muda (opção ausente é sem controle); obteve ' . var_export( $r_painel, true ) );
// A regra pura mantém o contrato do argumento: `null` ali SEGUE sendo "ausente" (quem decide é quem chama).
$r_pura = uonix_intelligence_license_evaluate( null, null, $GLOBALS['hoje'] );
uox_assert( true === $r_pura['sending'] && false === $r_pura['configured'], '#315: `evaluate( null, null )` segue sendo "sem controle" (a camada do painel depende disso)' );

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

// #314: estado montado à mão, com chave ausente ou de tipo errado. O `set_error_handler` do topo reprova qualquer
// warning; aqui o que se confere é o TEXTO. Chave ausente conta como vazia, e valor que não é texto também.
$msg_sem_data = uonix_intelligence_license_message( array( 'reason' => 'expired' ) );
uox_assert( 0 === strpos( $msg_sem_data, 'A licença da Central de Inteligência venceu, e o envio automático' ) && false !== strpos( $msg_sem_data, 'ksio.dev' ), '#314: vencida sem `valid_until` diz só "venceu", sem "em ," e sem inventar o dia; obteve ' . $msg_sem_data );
uox_assert( false !== strpos( uonix_intelligence_license_message( array( 'reason' => 'expired', 'valid_until' => '2026-09-01' ) ), 'venceu em 01/09/2026, e o envio' ), '#314: com a data, a frase segue "venceu em DD/MM/AAAA"' );
$msg_data_array = uonix_intelligence_license_message( array( 'reason' => 'expired', 'valid_until' => array( 'x' ) ) );
uox_assert( 0 === strpos( $msg_data_array, 'A licença da Central de Inteligência venceu, e o envio automático' ) && false === strpos( $msg_data_array, 'Array' ), '#314: `valid_until` que é array não vira a palavra "Array" nem uma data; obteve ' . $msg_data_array );
$msg_motivo_array = uonix_intelligence_license_message( array( 'reason' => array( 'expired' ) ) );
uox_assert( false !== strpos( $msg_motivo_array, 'não é válida' ), '#314: `reason` que é array cai no aviso de configuração inválida; obteve ' . $msg_motivo_array );
$msg_sem_nada = uonix_intelligence_license_message( array() );
uox_assert( false !== strpos( $msg_sem_nada, 'não é válida' ), '#314: estado vazio conta como pausado, com o aviso de configuração inválida' );
foreach ( array( 'null' => null, 'false' => false, 'número' => 7, 'texto' => 'x' ) as $rotulo_m => $estado_m ) {
	uox_assert( false !== strpos( uonix_intelligence_license_message( $estado_m ), 'não é válida' ), "#314: estado {$rotulo_m} (que não é array) conta como pausado" );
}
// O resumo, com o envio ativo mas sem `status` e sem `valid_until`.
$res_sem_chaves = uonix_intelligence_license_summary( array( 'sending' => true, 'configured' => true ) );
uox_assert( '' !== $res_sem_chaves && false === strpos( $res_sem_chaves, ' até .' ) && false === strpos( $res_sem_chaves, 'Array' ), '#314: resumo sem `status` nem `valid_until` não imprime "até ." nem "Array"; obteve ' . $res_sem_chaves );
$res_data_array = uonix_intelligence_license_summary( array( 'sending' => true, 'configured' => true, 'status' => 'active', 'valid_until' => array( 'x' ) ) );
uox_assert( false === strpos( $res_data_array, 'Array' ), '#314: resumo com `valid_until` que é array não imprime "Array"; obteve ' . $res_data_array );
$res_status_array = uonix_intelligence_license_summary( array( 'sending' => true, 'configured' => true, 'status' => array( 'trial' ), 'valid_until' => '' ) );
uox_assert( false === strpos( $res_status_array, 'Cortesia' ) && false === strpos( $res_status_array, 'Array' ), '#314: `status` que é array não vira cortesia nem "Array"; obteve ' . $res_status_array );
uox_assert( '' !== uonix_intelligence_license_summary( array() ) && '' !== uonix_intelligence_license_summary( null ), '#314: resumo de estado vazio ou nulo existe (conta como pausado)' );
// `date_label` direto: texto que não é data devolve o texto, e o que não é texto devolve vazio.
uox_assert( '30/09/2026' === uonix_intelligence_license_date_label( '2026-09-30' ) && 'x' === uonix_intelligence_license_date_label( 'x' ) && '' === uonix_intelligence_license_date_label( array( 'x' ) ) && '' === uonix_intelligence_license_date_label( null ), '#314: `date_label` aceita data, devolve o texto que não é data e devolve vazio para o que não é texto' );
// Valores ESCALARES que não são texto (inteiro, decimal, booleano) e objeto com `__toString`: o `text_key` os trata como vazio. Com
// `is_scalar` no lugar de `is_string`, `valid_until` inteiro imprimia "até ." (o defeito do #314 voltava). O `(string)` direto
// também aceitaria o objeto. Valor que não é texto não é data, nem motivo, nem status.
$stringavel = new class() { public function __toString() { return 'expired'; } };
foreach ( array( 'inteiro' => 20260930, 'decimal' => 1.5, 'verdadeiro' => true, 'falso' => false, 'objeto com __toString' => $stringavel ) as $rotulo_e => $valor_e ) {
	$r_sum = uonix_intelligence_license_summary( array( 'sending' => true, 'configured' => true, 'status' => 'active', 'valid_until' => $valor_e ) );
	uox_assert( 'Contratada (active), sem data-limite. O envio está ativo.' === $r_sum, "#314: valid_until {$rotulo_e} vira vazio no resumo, sem \"até .\"; obteve " . $r_sum );
	$r_msg = uonix_intelligence_license_message( array( 'reason' => 'expired', 'valid_until' => $valor_e ) );
	uox_assert( 0 === strpos( $r_msg, 'A licença da Central de Inteligência venceu, e o envio automático' ), "#314: valid_until {$rotulo_e} na mensagem de vencida diz só \"venceu\"; obteve " . $r_msg );
	$r_mot = uonix_intelligence_license_message( array( 'reason' => $valor_e ) );
	uox_assert( false !== strpos( $r_mot, 'não é válida' ), "#314: reason {$rotulo_e} cai em configuração inválida, e não é lido como o motivo; obteve " . $r_mot );
	$r_sta = uonix_intelligence_license_summary( array( 'sending' => true, 'configured' => true, 'status' => $valor_e, 'valid_until' => '' ) );
	uox_assert( false === strpos( $r_sta, 'Cortesia' ), "#314: status {$rotulo_e} não vira cortesia; obteve " . $r_sta );
	$r_lay = uonix_intelligence_license_layer_summary( array( 'configured' => true, 'sending' => false, 'reason' => 'expired', 'valid_until' => $valor_e ) );
	uox_assert( 'Vencida. Pausa o envio.' === $r_lay, "#314: valid_until {$rotulo_e} na camada vencida diz só \"Vencida.\"; obteve " . $r_lay );
}
// Estado que é TEXTO, ou objeto: não se indexa como array. 'lixo'['reason'] seria um erro, e 'lixo'[0] daria 'l'.
foreach ( array( 'texto' => 'reason', 'texto com o nome da chave' => 'valid_until', 'objeto com a chave' => new ArrayObject( array( 'reason' => 'expired', 'valid_until' => '2026-09-01', 'sending' => false ) ) ) as $rotulo_t => $estado_t ) {
	$msg_t = uonix_intelligence_license_message( $estado_t );
	$res_t = uonix_intelligence_license_summary( $estado_t );
	uox_assert( false !== strpos( $msg_t, 'não é válida' ) && '' !== $res_t && false === strpos( $msg_t . $res_t, 'venceu em 01/09/2026' ), "#314: estado que é {$rotulo_t} conta como pausado e inválido, sem ler a chave dele" );
}
// A camada isolada: o `layer_summary` de uma camada montada à mão.
uox_assert( '' !== uonix_intelligence_license_layer_summary( array( 'configured' => true, 'sending' => true, 'status' => array( 'x' ), 'valid_until' => array( 'y' ) ) ), '#314: `layer_summary` com `status` e `valid_until` que são array não quebra' );
// Camada que NÃO envia, com `reason` e `valid_until` de tipo errado: o cast dava "Array" no texto e um aviso.
$camada_ruim = uonix_intelligence_license_layer_summary( array( 'configured' => true, 'sending' => false, 'reason' => array( 'expired' ), 'valid_until' => array( '2026-09-01' ) ) );
uox_assert( 'Configuração inválida. Pausa o envio.' === $camada_ruim, '#314: `layer_summary` com `reason` array cai em "Configuração inválida"; obteve ' . $camada_ruim );
$camada_vencida_sem_data = uonix_intelligence_license_layer_summary( array( 'configured' => true, 'sending' => false, 'reason' => 'expired', 'valid_until' => array( 'x' ) ) );
uox_assert( 'Vencida. Pausa o envio.' === $camada_vencida_sem_data, '#314: `layer_summary` vencida sem data válida diz só "Vencida.", sem "em ." nem "Array"; obteve ' . $camada_vencida_sem_data );
uox_assert( 'Vencida em 01/09/2026. Pausa o envio.' === uonix_intelligence_license_layer_summary( array( 'configured' => true, 'sending' => false, 'reason' => 'expired', 'valid_until' => '2026-09-01' ) ), '#314: com a data, a camada vencida segue "Vencida em DD/MM/AAAA."' );

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
	'status nulo'               => array( array( 'status' => null, 'valid_until' => '' ), 'invalid' ),
	'status desconhecido'       => array( array( 'status' => 'suspenso', 'valid_until' => '' ), 'invalid' ),
	'data que é array'          => array( array( 'status' => 'active', 'valid_until' => array() ), 'invalid' ),
	'data inexistente'          => array( array( 'status' => 'active', 'valid_until' => '2026-02-30' ), 'invalid' ),
	'array vazio'               => array( array(), 'invalid' ),
	'texto'                     => array( 'suspended', 'invalid' ),
	'string vazia'              => array( '', 'invalid' ),
	'false'                     => array( false, 'invalid' ),
	// Chave da data ausente, nula, ou com o nome errado: opção malformada, e o
	// motivo é `invalid` mesmo quando o status sozinho seria `suspended` — a forma
	// errada da opção pesa mais do que o status que ela traz.
	'active sem a chave da data'      => array( array( 'status' => 'active' ), 'invalid' ),
	'active com valid_until nulo'     => array( array( 'status' => 'active', 'valid_until' => null ), 'invalid' ),
	'nome da chave errado (valid_untill)' => array( array( 'status' => 'active', 'valid_untill' => '2026-12-31' ), 'invalid' ),
	'suspended sem a chave da data'   => array( array( 'status' => 'suspended' ), 'invalid' ),
	'valid_until booleano'            => array( array( 'status' => 'active', 'valid_until' => false ), 'invalid' ),
	'valid_until inteiro'             => array( array( 'status' => 'active', 'valid_until' => 20261231 ), 'invalid' ),
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
	'sem controle'            => array( 'none', '', '', null ),
	'sem controle com data'   => array( 'none', '2026-10-31', '', null ),
	'status vazio'            => array( '', '', 'missing', null ),
	'status vazio com data'   => array( '', '2026-10-31', 'missing', null ),
	'active sem data'         => array( 'active', '', '', array( 'status' => 'active', 'valid_until' => '' ) ),
	'active com data passada' => array( 'active', '2026-09-01', '', array( 'status' => 'active', 'valid_until' => '2026-09-01' ) ),
	'trial com data'          => array( 'trial', '2026-10-31', '', array( 'status' => 'trial', 'valid_until' => '2026-10-31' ) ),
	'suspended sem data'      => array( 'suspended', '', '', array( 'status' => 'suspended', 'valid_until' => '' ) ),
	'status desconhecido'     => array( 'suspenso', '', 'status', null ),
	'status em maiúscula'     => array( 'Active', '', 'status', null ),
	'status array'            => array( array( 'active' ), '', 'status', null ),
	'status ausente'          => array( null, '', 'missing', null ),
	'none em maiúscula'       => array( 'None', '', 'status', null ),
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
$r = uox_salvar( array( 'uonix_license_status' => 'none' ), false );
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
	'missing' => array( 'uonix_license_status' => '', 'uonix_license_valid_until' => '2026-10-31' ),
) as $erro => $post ) {
	$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
	$r = uox_salvar( $post );
	$q = uox_query( $GLOBALS['uox_redirect'] );
	uox_assert( 'redirect' === $r && $erro === ( $q['uonix_license_error'] ?? '' ) && ! isset( $q['uonix_license_saved'] ), "entrada inválida ({$erro}) volta com o erro; obteve " . var_export( $q, true ) );
	uox_assert( $anterior === get_option( 'uonix_intelligence_license' ), "entrada inválida ({$erro}) não altera a licença" );
}

// "Sem controle" é o valor explícito `none`: apaga.
$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $anterior );
$r = uox_salvar( array( 'uonix_license_status' => 'none' ) );
$q = uox_query( $GLOBALS['uox_redirect'] );
uox_assert( 'redirect' === $r && ! array_key_exists( 'uonix_intelligence_license', $GLOBALS['uox_options'] ) && 'cleared' === ( $q['uonix_license_saved'] ?? '' ), '"sem controle" (none) apaga a opção; obteve ' . var_export( $q, true ) );

// POST sem o campo de status é erro, e não apaga (#322). Vale também com a opção
// malformada, que pausa: salvar sem escolher não pode tirar a pausa.
foreach ( array(
	'válida'     => $anterior,
	'malformada' => array( 'status' => 'active' ),
) as $caso => $gravada ) {
	$GLOBALS['uox_options'] = array( 'uonix_intelligence_license' => $gravada );
	$r = uox_salvar( array( 'uonix_license_valid_until' => '2026-10-31' ) );
	$q = uox_query( $GLOBALS['uox_redirect'] );
	uox_assert( 'redirect' === $r && 'missing' === ( $q['uonix_license_error'] ?? '' ) && ! isset( $q['uonix_license_saved'] ), "POST sem status, opção {$caso}: volta com erro; obteve " . var_export( $q, true ) );
	uox_assert( $gravada === get_option( 'uonix_intelligence_license' ), "POST sem status, opção {$caso}: nada é apagado nem gravado" );
}

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
