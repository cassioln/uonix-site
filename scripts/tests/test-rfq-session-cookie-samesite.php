<?php
$root = dirname( __DIR__, 2 );
$file = $root . '/mu-plugins/uonix-woocommerce/33-rfq-session-cookie-samesite.php';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "FAIL: módulo SameSite RFQ ausente\n" );
	exit( 1 );
}

define( 'ABSPATH', $root . '/' );
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
check( "Set-Cookie: {$name}=value; path=/;secure;HttpOnly; SameSite=Lax" === uonix_rfq_cookie_with_samesite( "Set-Cookie: {$name}=value; path=/;secure;HttpOnly", $name ), 'atributos sem espaços não foram aceitos' );
check( null === uonix_rfq_cookie_with_samesite( 'Set-Cookie: wordpress_logged_in=x; secure; HttpOnly', $name ), 'cookie alheio foi alterado' );
check( null === uonix_rfq_cookie_with_samesite( $base . '; SameSite=Strict', $name ), 'SameSite existente foi duplicado' );
check( null === uonix_rfq_cookie_with_samesite( "Set-Cookie: {$name}=x; HttpOnly", $name ), 'cookie sem Secure foi aceito' );
check( null === uonix_rfq_cookie_with_samesite( "Set-Cookie: {$name}=x; secure", $name ), 'cookie sem HttpOnly foi aceito' );
check( null === uonix_rfq_cookie_with_samesite( $base . "\r\nInjected: x", $name ), 'CRLF foi aceito' );
$two = array(
	"Set-Cookie: {$name}=FIRST; path=/; secure; HttpOnly",
	'Set-Cookie: wordpress_logged_in=x; path=/; secure; HttpOnly',
	"Set-Cookie: {$name}=SECOND; path=/; secure; HttpOnly",
);
check( "Set-Cookie: {$name}=SECOND; path=/; secure; HttpOnly; SameSite=Lax" === uonix_rfq_latest_cookie_with_samesite( $two, $name ), 'a última ocorrência do cookie não venceu' );
check( false !== strpos( file_get_contents( $file ), 'header_register_callback' ), 'callback final de headers ausente' );
check( false === strpos( file_get_contents( $file ), "header_remove( 'Set-Cookie' )" ), 'implementação ainda remove cookies globalmente' );
echo "PASS: cookie RFQ recebe SameSite=Lax sem reconstruir cookies de terceiros.\n";
