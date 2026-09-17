<?php
$root = dirname( __DIR__, 2 );
$file = $root . '/mu-plugins/uonix-woocommerce/33-rfq-session-cookie-samesite.php';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "FAIL: módulo SameSite RFQ ausente\n" );
	exit( 1 );
}

define( 'ABSPATH', $root . '/' );
$GLOBALS['uonix_actions'] = array();
function add_action( $tag, $callback, $priority = 10 ) { $GLOBALS['uonix_actions'][] = compact( 'tag', 'callback', 'priority' ); }
function apply_filters( $tag, $value ) { return $value; }
require $file;

function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}
$name = 'rfqtk_wp_session_abc';
$base = "Set-Cookie: {$name}=value; path=/; secure; HttpOnly";
check( $base . '; SameSite=Lax' === uonix_rfq_cookie_with_samesite( $base, $name ), 'cookie alvo não recebeu Lax' );
check( null === uonix_rfq_cookie_with_samesite( 'Set-Cookie: wordpress_logged_in=x; secure; HttpOnly', $name ), 'cookie alheio foi alterado' );
check( null === uonix_rfq_cookie_with_samesite( $base . '; SameSite=Strict', $name ), 'SameSite existente foi duplicado' );
check( null === uonix_rfq_cookie_with_samesite( "Set-Cookie: {$name}=x; HttpOnly", $name ), 'cookie sem Secure foi aceito' );
check( null === uonix_rfq_cookie_with_samesite( "Set-Cookie: {$name}=x; secure", $name ), 'cookie sem HttpOnly foi aceito' );
check( null === uonix_rfq_cookie_with_samesite( $base . "\r\nInjected: x", $name ), 'CRLF foi aceito' );
check( 1 === count( $GLOBALS['uonix_actions'] ) && 'send_headers' === $GLOBALS['uonix_actions'][0]['tag'], 'hook send_headers ausente' );
check( false === strpos( file_get_contents( $file ), "header_remove( 'Set-Cookie' )" ), 'implementação ainda remove cookies globalmente' );
echo "PASS: cookie RFQ recebe SameSite=Lax sem reconstruir cookies de terceiros.\n";
