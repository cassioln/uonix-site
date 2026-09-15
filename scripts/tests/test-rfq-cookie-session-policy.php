<?php
/**
 * Contrato da política de sessão do RFQ para páginas públicas cacheáveis.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );
$module    = $repo_root . '/mu-plugins/uonix-woocommerce/30-rfq-cookie-session-policy.php';

function fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fail( $message );
	}
}

assert_true( is_file( $module ), 'Módulo de política RFQ/cookie ausente.' );

$source = file_get_contents( $module );
assert_true( is_string( $source ), 'Não foi possível ler o módulo de política RFQ/cookie.' );
assert_true(
	false !== strpos( $source, 'settings_gpls_woo_rfq_cookie_or_phpsession' ),
	'A política deve tratar explicitamente a opção oficial do RFQ.'
);
assert_true(
	false !== strpos( $source, "return 'rfq_cookie';" ),
	'A política deve selecionar rfq_cookie para toda leitura da opção oficial.'
);
assert_true(
	false !== strpos( $source, "add_filter( 'pre_option_settings_gpls_woo_rfq_cookie_or_phpsession'" ),
	'A política deve atuar antes da leitura da opção pelo plugin RFQ.'
);

$GLOBALS['uonix_rfq_policy_filters'] = array();
$GLOBALS['uonix_rfq_policy_options'] = array(
	'uonix_rfq_cookie_session_enabled' => true,
);
$_COOKIE = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['uonix_rfq_policy_filters'][ $hook ][] = $callback;
}

function get_option( $option, $default = false ) {
	return $GLOBALS['uonix_rfq_policy_options'][ $option ] ?? $default;
}

require $module;

$filter = $GLOBALS['uonix_rfq_policy_filters']['pre_option_settings_gpls_woo_rfq_cookie_or_phpsession'][0] ?? null;
assert_true( is_callable( $filter ), 'A política deve registrar o filtro de pré-opção oficial.' );
assert_true( 'rfq_cookie' === $filter( false ), 'Tráfego público deve usar rfq_cookie.' );
assert_true( 'rfq_cookie' === $filter( 'php_session' ), 'A política deve prevalecer sobre a opção persistida.' );

$_COOKIE['PHPSESSID'] = 'legacy-rfq-session';
assert_true(
	false === $filter( false ),
	'Visitante com sessão PHP legada deve permanecer no backend persistido durante a transição.'
);
unset( $_COOKIE['PHPSESSID'] );

function uonix_rfq_get_session_mode( $persisted_mode ) {
	$filter = $GLOBALS['uonix_rfq_policy_filters']['pre_option_settings_gpls_woo_rfq_cookie_or_phpsession'][0] ?? null;
	assert_true( is_callable( $filter ), 'Filtro RFQ ausente durante o bootstrap simulado.' );
	$pre_option = $filter( false );

	return false === $pre_option ? $persisted_mode : $pre_option;
}

/**
 * A opção define o backend inteiro do plugin. Simulamos cada get_option() que
 * ocorre durante o bootstrap e verificamos que o ramo PHP nunca é alcançado.
 */
function uonix_rfq_bootstrap_session_backend( callable $option_reader ): string {
	$session_type = $option_reader();
	if ( 'php_session' === $session_type ) {
		return 'php_session_backend';
	}

	$session_type = $option_reader();
	if ( 'php_session' === $session_type ) {
		return 'php_session_backend';
	}

	return 'rfq_cookie_backend';
}

$backend = uonix_rfq_bootstrap_session_backend(
	static function () {
		return uonix_rfq_get_session_mode( 'php_session' );
	}
);
assert_true( 'rfq_cookie_backend' === $backend, 'Bootstrap RFQ não pode selecionar o backend PHP.' );

/**
 * Uma política de runtime tem de oferecer rollback operacional sem depender de
 * remover código ou redeploy. Quando a constante explícita é false, o filtro
 * devolve a opção persistida exatamente como o plugin espera.
 */
assert_true(
	false !== strpos( $source, 'UONIX_RFQ_COOKIE_SESSION_ENABLED' ),
	'A política deve declarar uma chave explícita de ativação/rollback.'
);
$GLOBALS['uonix_rfq_policy_options']['uonix_rfq_cookie_session_enabled'] = '0';
assert_true(
	'php_session' === uonix_rfq_get_session_mode( 'php_session' ),
	'Rollback operacional precisa devolver o modo persistido do plugin.'
);

echo "PASS: política RFQ usa backend cookie uniforme e preserva rollback operacional.\n";
