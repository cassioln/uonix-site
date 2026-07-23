<?php
/**
 * Testes da política de e-mail por ambiente.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_test_filters'] = array();
$GLOBALS['uonix_test_actions'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function is_email( $value ) {
	return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/49-email-environment-label.php';

$failures = 0;

function uonix_test_assert_same( $expected, $actual, $message ) {
	global $failures;

	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
}

$required_functions = array(
	'uonix_email_environment_label',
	'uonix_apply_email_environment_policy',
	'uonix_should_block_email_environment',
);

foreach ( $required_functions as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, sprintf( "FAIL: %s() ainda não existe.\n", $required_function ) );
		exit( 1 );
	}
}

uonix_test_assert_same( '', uonix_email_environment_label( 'production' ), 'produção não recebe rótulo' );
uonix_test_assert_same( 'QA', uonix_email_environment_label( 'staging' ), 'staging recebe QA' );
uonix_test_assert_same( 'DEV', uonix_email_environment_label( 'development' ), 'development recebe DEV' );
uonix_test_assert_same( 'LOCAL', uonix_email_environment_label( 'local' ), 'local recebe LOCAL' );

$base_args = array(
	'to'      => 'cliente@example.com',
	'subject' => 'Mensagem de teste',
	'message' => 'Corpo da mensagem',
	'headers' => array(
		'From: Site <site@example.com>',
		'Cc: copia@example.com',
		'Bcc: oculta@example.com',
		'Reply-To: resposta@example.com',
	),
);

$production = uonix_apply_email_environment_policy( $base_args, 'production', '' );
uonix_test_assert_same( $base_args, $production, 'produção preserva destinatário, assunto, corpo e headers' );

$staging = uonix_apply_email_environment_policy( $base_args, 'staging', 'safe@example.com' );
uonix_test_assert_same( 'safe@example.com', $staging['to'], 'QA redireciona para a caixa segura' );
uonix_test_assert_same( '[QA] Mensagem de teste', $staging['subject'], 'QA recebe prefixo' );
uonix_test_assert_same(
	array( 'From: Site <site@example.com>', 'Reply-To: resposta@example.com' ),
	$staging['headers'],
	'QA remove Cc/Bcc e preserva Reply-To'
);

$development = uonix_apply_email_environment_policy( $base_args, 'development', 'safe@example.com' );
uonix_test_assert_same( 'safe@example.com', $development['to'], 'DEV redireciona para a caixa segura' );
uonix_test_assert_same( '[DEV] Mensagem de teste', $development['subject'], 'DEV recebe prefixo' );

$local = uonix_apply_email_environment_policy( $base_args, 'local', '' );
uonix_test_assert_same( 'cliente@example.com', $local['to'], 'local preserva destinatário para o Mailpit' );
uonix_test_assert_same( '[LOCAL] Mensagem de teste', $local['subject'], 'local recebe prefixo LOCAL' );
uonix_test_assert_same( $base_args['headers'], $local['headers'], 'local preserva headers porque o Mailpit captura a mensagem' );

uonix_test_assert_same( true, uonix_should_block_email_environment( 'staging', '' ), 'QA bloqueia sem caixa segura' );
uonix_test_assert_same( true, uonix_should_block_email_environment( 'development', 'invalido' ), 'DEV bloqueia caixa inválida' );
uonix_test_assert_same( false, uonix_should_block_email_environment( 'local', '' ), 'local não exige caixa segura' );
uonix_test_assert_same( false, uonix_should_block_email_environment( 'production', '' ), 'produção não exige caixa segura' );

$wp_mail_priorities = array();
foreach ( $GLOBALS['uonix_test_filters'] as $filter ) {
	if ( 'wp_mail' === $filter[0] ) {
		$wp_mail_priorities[] = $filter[2];
	}
}
uonix_test_assert_same( array( PHP_INT_MAX ), $wp_mail_priorities, 'política final de wp_mail roda depois dos roteadores funcionais' );

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: política de e-mail por ambiente.\n" );
