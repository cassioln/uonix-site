<?php
/**
 * Testa o guard do Turnstile antes do callback da Store API do WooCommerce.
 *
 * O checkout clássico já possui seu próprio hook. Este contrato cobre a rota
 * REST anônima, que recebe JSON e não popula $_POST.
 */

define( 'ABSPATH', __DIR__ );
define( 'UONIX_ENV', 'production' );

$GLOBALS['uonix_store_api_hooks']             = array();
$GLOBALS['uonix_turnstile_remote_calls']      = array();
$GLOBALS['uonix_turnstile_remote_response']   = array();
$GLOBALS['uonix_turnstile_protect_checkout']  = true;
$GLOBALS['uonix_turnstile_settings_override'] = array(
	'siteKey'   => 'site-key',
	'secretKey' => 'secret-key',
);

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Request {
	private $method;
	private $route;
	private $params;

	public function __construct( $method, $route, $params = array() ) {
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['uonix_store_api_hooks'][] = array(
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => (int) $priority,
		'accepted_args' => (int) $accepted_args,
	);
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	if ( 'uonix_turnstile_protect_woocommerce_checkout' === $hook ) {
		return (bool) $GLOBALS['uonix_turnstile_protect_checkout'];
	}

	return $value;
}

function get_option( $key, $default = false ) {
	if ( '_fluentform_turnstile_details' === $key ) {
		return $GLOBALS['uonix_turnstile_settings_override'];
	}

	return $default;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://uonix.test' . $path;
}

function wp_script_is() {
	return false;
}

function wp_register_script() {}
function wp_add_inline_script() {}
function wp_enqueue_script() {}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['uonix_turnstile_remote_calls'][] = array( $url, $args );

	return $GLOBALS['uonix_turnstile_remote_response'];
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
}

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}

$turnstile_module = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/34-turnstile-custom-forms.php';
$store_api_module = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-woocommerce/26-turnstile-store-api-checkout.php';

if ( ! is_file( $turnstile_module ) || ! is_file( $store_api_module ) ) {
	fwrite( STDERR, "FAIL: módulos do Turnstile da Store API ausentes.\n" );
	exit( 1 );
}

require_once $turnstile_module;
require_once $store_api_module;

$failures = 0;

function uonix_store_api_assert( $condition, $message ) {
	global $failures;

	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\n", $message ) );
		return;
	}

	printf( "ok   %s\n", $message );
}

function uonix_store_api_reset() {
	$GLOBALS['uonix_turnstile_remote_calls']      = array();
	$GLOBALS['uonix_turnstile_remote_response']   = array(
		'body' => json_encode(
			array(
				'success' => true,
				'action'  => 'woocommerce_checkout',
			)
		),
	);
	$GLOBALS['uonix_turnstile_protect_checkout']  = true;
	$GLOBALS['uonix_turnstile_settings_override'] = array(
		'siteKey'   => 'site-key',
		'secretKey' => 'secret-key',
	);
	$_POST = array();
}

/* Registro: o filtro precisa receber response, handler e request reais. */
$store_hook = null;
foreach ( $GLOBALS['uonix_store_api_hooks'] as $hook ) {
	if ( 'rest_request_before_callbacks' === $hook['hook'] ) {
		$store_hook = $hook;
		break;
	}
}
uonix_store_api_assert( null !== $store_hook, 'Store API registra guard antes dos callbacks' );
uonix_store_api_assert( 3 === ( $store_hook['accepted_args'] ?? null ), 'guard recebe response, handler e request' );

/* Matcher: aliases e subrotas reais entram; prefixos vizinhos não. */
uonix_store_api_assert( true === uonix_turnstile_store_api_route_is_protected( '/wc/store/v1/checkout' ), 'protege checkout versionado' );
uonix_store_api_assert( true === uonix_turnstile_store_api_route_is_protected( '/wc/store/checkout/11027' ), 'protege alias legado e subrota de pedido' );
uonix_store_api_assert( false === uonix_turnstile_store_api_route_is_protected( '/wc/store/v1/checkout-draft' ), 'não confunde prefixo vizinho com checkout' );

/* O segundo argumento do validador deve vencer $_POST para a Store API JSON. */
uonix_store_api_reset();
$_POST['cf-turnstile-response'] = 'token-de-post';
$validation = uonix_turnstile_validate_request( 'woocommerce_checkout', 'token-json' );
uonix_store_api_assert( true === $validation, 'validador aceita token JSON válido' );
uonix_store_api_assert(
	'token-json' === ( $GLOBALS['uonix_turnstile_remote_calls'][0][1]['body']['response'] ?? null ),
	'token explícito da Store API não é substituído por $_POST'
);

/* POST sem token é abortado antes do callback que criaria pedido. */
uonix_store_api_reset();
$missing = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
uonix_store_api_assert( is_wp_error( $missing ), 'POST de checkout sem token é bloqueado' );
uonix_store_api_assert( 'uonix_turnstile_store_api_failed' === ( is_wp_error( $missing ) ? $missing->get_error_code() : null ), 'erro público da Store API não vaza detalhe interno' );
uonix_store_api_assert( 403 === ( is_wp_error( $missing ) ? ( $missing->get_error_data()['status'] ?? null ) : null ), 'recusa da Store API responde 403' );
uonix_store_api_assert( array() === $GLOBALS['uonix_turnstile_remote_calls'], 'token ausente não chama Cloudflare inutilmente' );

/* Token na extensão JSON é encaminhado ao validador compartilhado. */
uonix_store_api_reset();
$allowed = uonix_turnstile_store_api_guard(
	null,
	array(),
	new WP_REST_Request(
		'POST',
		'/wc/store/v1/checkout',
		array( 'extensions' => array( 'uonix_turnstile' => array( 'token' => 'token-da-extensao' ) ) )
	)
);
uonix_store_api_assert( null === $allowed, 'token válido na extensão libera o callback do checkout' );
uonix_store_api_assert(
	'token-da-extensao' === ( $GLOBALS['uonix_turnstile_remote_calls'][0][1]['body']['response'] ?? null ),
	'guard encaminha o token da extensão para a Cloudflare'
);

/* GET, rotas fora do checkout e erro anterior não devem ser alterados. */
uonix_store_api_reset();
$get_response = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'GET', '/wc/store/v1/checkout' ) );
uonix_store_api_assert( null === $get_response && array() === $GLOBALS['uonix_turnstile_remote_calls'], 'GET do checkout permanece livre' );

uonix_store_api_reset();
$other_response = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/cart' ) );
uonix_store_api_assert( null === $other_response && array() === $GLOBALS['uonix_turnstile_remote_calls'], 'Store API fora do checkout não é interceptada' );

uonix_store_api_reset();
$previous_error = new WP_Error( 'third_party_error', 'erro anterior' );
$preserved = uonix_turnstile_store_api_guard( $previous_error, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
uonix_store_api_assert( $previous_error === $preserved && array() === $GLOBALS['uonix_turnstile_remote_calls'], 'erro anterior do REST é preservado' );

/* A exceção fail-open é exclusivamente erro de transporte da Cloudflare. */
uonix_store_api_reset();
$GLOBALS['uonix_turnstile_remote_response'] = new WP_Error( 'http_request_failed', 'timeout' );
$transport = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout', array( 'cf-turnstile-response' => 'token' ) ) );
uonix_store_api_assert( null === $transport, 'timeout da Cloudflare não derruba checkout' );

uonix_store_api_reset();
$GLOBALS['uonix_turnstile_remote_response'] = array( 'body' => json_encode( array( 'success' => false ) ) );
$rejected = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout', array( 'cf-turnstile-response' => 'token' ) ) );
uonix_store_api_assert( is_wp_error( $rejected ) && 'uonix_turnstile_store_api_failed' === $rejected->get_error_code(), 'recusa real da Cloudflare bloqueia checkout' );

/* Desabilitar a proteção ou remover as chaves mantém o comportamento opt-in. */
uonix_store_api_reset();
$GLOBALS['uonix_turnstile_protect_checkout'] = false;
$disabled_by_filter = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
uonix_store_api_assert( null === $disabled_by_filter && array() === $GLOBALS['uonix_turnstile_remote_calls'], 'filtro explícito desabilita o guard' );

uonix_store_api_reset();
$GLOBALS['uonix_turnstile_settings_override'] = array();
$disabled_by_settings = uonix_turnstile_store_api_guard( null, array(), new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
uonix_store_api_assert( null === $disabled_by_settings && array() === $GLOBALS['uonix_turnstile_remote_calls'], 'ambiente sem chaves não bloqueia checkout' );

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: Store API do WooCommerce não cria checkout sem Turnstile válido.\n" );
