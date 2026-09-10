<?php
/**
 * Testes de integridade do manifesto GTM e do contrato de conversao estrita com AdOpt.
 *
 * Garante que:
 * 1. O arquivo docs/gtm/uonix-google-ads-gtm-import.json contem a Tag AdOpt,
 *    os triggers de Marketing e Estatísticas, e a variavel Tags_Aceitas_AdOpt (window.acceptedTags);
 * 2. GA4 depende de Estatísticas e exige consentimento analytics_storage;
 * 3. Meta Pixel, Remarketing e Conversão Ads dependem de Marketing e exigem ad_storage;
 * 4. A tag de Conversao Ads de Orcamento escuta EXCLUSIVAMENTE uonix_solicitar_orcamento (Trigger 21),
 *    sem triggers genericos de formSubmission nem order-received;
 * 5. A funcao PHP uonix_render_analytics_conversion_footer emite os scripts esperados
 *    para o endpoint de order-received e para os formulários Fluent Forms;
 * 6. Executa a suite de caminhos positivos, negativos e resistencia a mutacao no JS.
 */

define( 'ABSPATH', __DIR__ );
$failures = 0;

function gtm_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	} else {
		printf( "PASS: %s\n", $message );
	}
}

// -----------------------------------------------------------------------------
// Parte 1: Validacao programatica do Manifesto GTM
// -----------------------------------------------------------------------------
$manifest_path = dirname( __DIR__, 2 ) . '/docs/gtm/uonix-google-ads-gtm-import.json';
gtm_assert( file_exists( $manifest_path ), 'docs/gtm/uonix-google-ads-gtm-import.json existe no repositorio' );

$manifest_raw = file_get_contents( $manifest_path );
$manifest = json_decode( $manifest_raw, true );
gtm_assert( is_array( $manifest ), 'docs/gtm/uonix-google-ads-gtm-import.json e um JSON valido' );

gtm_assert( isset( $manifest['exportFormatVersion'] ) && 2 === $manifest['exportFormatVersion'], 'exportFormatVersion e 2' );

$version = isset( $manifest['containerVersion'] ) ? $manifest['containerVersion'] : array();
$container = isset( $version['container'] ) ? $version['container'] : array();
gtm_assert( isset( $container['publicId'] ) && 'GTM-P8TR5CCH' === $container['publicId'], 'Container publicId e GTM-P8TR5CCH' );

$tags      = isset( $version['tag'] ) ? $version['tag'] : array();
$triggers  = isset( $version['trigger'] ) ? $version['trigger'] : array();
$variables = isset( $version['variable'] ) ? $version['variable'] : array();

$tags_by_name      = array();
$triggers_by_id    = array();
$triggers_by_name  = array();
$variables_by_name = array();

foreach ( $tags as $t ) {
	$tags_by_name[ $t['name'] ] = $t;
}
foreach ( $triggers as $tr ) {
	$triggers_by_id[ $tr['triggerId'] ] = $tr;
	$triggers_by_name[ $tr['name'] ]    = $tr;
}
foreach ( $variables as $v ) {
	$variables_by_name[ $v['name'] ] = $v;
}

// 1.1 Tag AdOpt
gtm_assert( isset( $tags_by_name['Tag AdOpt'] ), 'Tag AdOpt existe no manifesto' );
if ( isset( $tags_by_name['Tag AdOpt'] ) ) {
	$tag_adopt = $tags_by_name['Tag AdOpt'];
	gtm_assert( 'html' === $tag_adopt['type'], 'Tag AdOpt e do tipo html' );
	gtm_assert( in_array( '2147479553', $tag_adopt['firingTriggerId'], true ), 'Tag AdOpt dispara em All Pages (2147479553)' );
	$html_param = '';
	foreach ( $tag_adopt['parameter'] as $p ) {
		if ( 'html' === $p['key'] ) $html_param = $p['value'];
	}
	gtm_assert( false !== strpos( $html_param, 'tag.goadopt.io/injector.js' ), 'Tag AdOpt carrega injector.js oficial da AdOpt' );
}

// 1.2 Variavel Tags_Aceitas_AdOpt
gtm_assert( isset( $variables_by_name['Tags_Aceitas_AdOpt'] ), 'Variavel Tags_Aceitas_AdOpt existe no manifesto' );
if ( isset( $variables_by_name['Tags_Aceitas_AdOpt'] ) ) {
	$var_adopt = $variables_by_name['Tags_Aceitas_AdOpt'];
	gtm_assert( 'j' === $var_adopt['type'], 'Tags_Aceitas_AdOpt e do tipo Variavel JavaScript (j)' );
	$js_name = '';
	foreach ( $var_adopt['parameter'] as $p ) {
		if ( 'name' === $p['key'] ) $js_name = $p['value'];
	}
	gtm_assert( 'window.acceptedTags' === $js_name, 'Tags_Aceitas_AdOpt le exatamente window.acceptedTags' );
}

// 1.3 Trigger de Estatisticas
gtm_assert( isset( $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'] ), 'Trigger LGPD - AdOpt Estatísticas existe' );
if ( isset( $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'] ) ) {
	$tr_stat = $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'];
	gtm_assert( 'customEvent' === $tr_stat['type'], 'Trigger de Estatisticas e do tipo customEvent' );
	$filter_has_stat = false;
	if ( isset( $tr_stat['filter'] ) ) {
		foreach ( $tr_stat['filter'] as $f ) {
			foreach ( $f['parameter'] as $p ) {
				if ( 'statistics' === $p['value'] ) $filter_has_stat = true;
			}
		}
	}
	gtm_assert( $filter_has_stat, 'Trigger de Estatisticas filtra pela categoria statistics em Tags_Aceitas_AdOpt' );
}

// 1.4 Trigger de Marketing
gtm_assert( isset( $triggers_by_name['Trigger LGPD - AdOpt Marketing'] ), 'Trigger LGPD - AdOpt Marketing existe' );
if ( isset( $triggers_by_name['Trigger LGPD - AdOpt Marketing'] ) ) {
	$tr_mkt = $triggers_by_name['Trigger LGPD - AdOpt Marketing'];
	gtm_assert( 'customEvent' === $tr_mkt['type'], 'Trigger de Marketing e do tipo customEvent' );
	$filter_has_mkt = false;
	if ( isset( $tr_mkt['filter'] ) ) {
		foreach ( $tr_mkt['filter'] as $f ) {
			foreach ( $f['parameter'] as $p ) {
				if ( 'marketing' === $p['value'] ) $filter_has_mkt = true;
			}
		}
	}
	gtm_assert( $filter_has_mkt, 'Trigger de Marketing filtra pela categoria marketing em Tags_Aceitas_AdOpt' );
}

// 1.5 Trigger Solicitar Orcamento
gtm_assert( isset( $triggers_by_name['Evento - Solicitar Orçamento Uônix'] ), 'Trigger Evento - Solicitar Orçamento Uônix existe' );
if ( isset( $triggers_by_name['Evento - Solicitar Orçamento Uônix'] ) ) {
	$tr_orc = $triggers_by_name['Evento - Solicitar Orçamento Uônix'];
	gtm_assert( 'customEvent' === $tr_orc['type'], 'Trigger Solicitar Orcamento e do tipo customEvent' );
	$has_event_name = false;
	if ( isset( $tr_orc['customEventFilter'] ) ) {
		foreach ( $tr_orc['customEventFilter'] as $cef ) {
			foreach ( $cef['parameter'] as $p ) {
				if ( 'uonix_solicitar_orcamento' === $p['value'] ) $has_event_name = true;
			}
		}
	}
	gtm_assert( $has_event_name, 'Trigger Solicitar Orcamento escuta o evento customizado uonix_solicitar_orcamento' );

	$has_mkt_filter = false;
	if ( isset( $tr_orc['filter'] ) ) {
		foreach ( $tr_orc['filter'] as $f ) {
			foreach ( $f['parameter'] as $p ) {
				if ( 'marketing' === $p['value'] ) $has_mkt_filter = true;
			}
		}
	}
	gtm_assert( $has_mkt_filter, 'Trigger Solicitar Orcamento exige que Tags_Aceitas_AdOpt contenha marketing' );
}

// 1.5.1 Contrato GTM: Declaracao explicita de negate: false em cada customEventFilter e filter
$custom_triggers_to_check = array(
	'3'  => 'Trigger LGPD - AdOpt',
	'21' => 'Evento - Solicitar Orçamento Uônix',
	'24' => 'Trigger LGPD - AdOpt Marketing',
	'25' => 'Trigger LGPD - AdOpt Estatísticas',
);
foreach ( $custom_triggers_to_check as $tr_id => $tr_name ) {
	gtm_assert( isset( $triggers_by_id[ $tr_id ] ), "Trigger {$tr_id} ({$tr_name}) existe no manifesto" );
	if ( isset( $triggers_by_id[ $tr_id ] ) ) {
		$t = $triggers_by_id[ $tr_id ];
		if ( isset( $t['customEventFilter'] ) ) {
			foreach ( $t['customEventFilter'] as $idx => $cef ) {
				gtm_assert(
					array_key_exists( 'negate', $cef ) && false === $cef['negate'],
					"Trigger {$tr_id} ({$tr_name}) customEventFilter[{$idx}] declara explicitamente negate === false"
				);
			}
		}
		if ( isset( $t['filter'] ) ) {
			foreach ( $t['filter'] as $idx => $flt ) {
				gtm_assert(
					array_key_exists( 'negate', $flt ) && false === $flt['negate'],
					"Trigger {$tr_id} ({$tr_name}) filter[{$idx}] declara explicitamente negate === false"
				);
			}
		}
	}
}

// 1.6 GA4
gtm_assert( isset( $tags_by_name['GA4 - Configuração'] ), 'Tag GA4 - Configuração existe' );
if ( isset( $tags_by_name['GA4 - Configuração'] ) ) {
	$tag_ga4 = $tags_by_name['GA4 - Configuração'];
	gtm_assert( in_array( '25', $tag_ga4['firingTriggerId'], true ), 'GA4 dispara via Trigger LGPD - AdOpt Estatísticas (25)' );
	gtm_assert(
		isset( $tag_ga4['consentSettings']['consentStatus'] ) && 'needed' === $tag_ga4['consentSettings']['consentStatus'],
		'GA4 declara consentStatus needed'
	);
}

// 1.7 Meta Pixel
gtm_assert( isset( $tags_by_name['Facebook Pixel - PageView'] ), 'Tag Facebook Pixel - PageView existe' );
if ( isset( $tags_by_name['Facebook Pixel - PageView'] ) ) {
	$tag_fb = $tags_by_name['Facebook Pixel - PageView'];
	gtm_assert( in_array( '24', $tag_fb['firingTriggerId'], true ), 'Meta Pixel dispara via Trigger LGPD - AdOpt Marketing (24)' );
	gtm_assert(
		isset( $tag_fb['consentSettings']['consentStatus'] ) && 'needed' === $tag_fb['consentSettings']['consentStatus'],
		'Meta Pixel declara consentStatus needed'
	);
}

// 1.8 Google Ads Remarketing
gtm_assert( isset( $tags_by_name['Google Ads - Remarketing Geral'] ), 'Tag Google Ads - Remarketing Geral existe' );
if ( isset( $tags_by_name['Google Ads - Remarketing Geral'] ) ) {
	$tag_rem = $tags_by_name['Google Ads - Remarketing Geral'];
	gtm_assert( in_array( '24', $tag_rem['firingTriggerId'], true ), 'Google Ads Remarketing dispara via Trigger LGPD - AdOpt Marketing (24)' );
	gtm_assert(
		isset( $tag_rem['consentSettings']['consentStatus'] ) && 'needed' === $tag_rem['consentSettings']['consentStatus'],
		'Google Ads Remarketing declara consentStatus needed'
	);
}

// 1.9 Google Ads Conversao Envio Formulario
gtm_assert( isset( $tags_by_name['Google Ads - Conversão - Envio de Formulário'] ), 'Tag Google Ads - Conversão - Envio de Formulário existe' );
if ( isset( $tags_by_name['Google Ads - Conversão - Envio de Formulário'] ) ) {
	$tag_conv = $tags_by_name['Google Ads - Conversão - Envio de Formulário'];
	gtm_assert( array( '21' ) === $tag_conv['firingTriggerId'], 'Tag de Conversao Ads dispara EXCLUSIVAMENTE no Trigger 21 (uonix_solicitar_orcamento)' );
	gtm_assert( ! in_array( '17', $tag_conv['firingTriggerId'], true ), 'Tag de Conversao Ads NAO contem trigger redundante de order-received (17)' );
	gtm_assert( ! in_array( '11', $tag_conv['firingTriggerId'], true ), 'Tag de Conversao Ads NAO contem trigger generico de formSubmission (11)' );
	gtm_assert(
		isset( $tag_conv['consentSettings']['consentStatus'] ) && 'needed' === $tag_conv['consentSettings']['consentStatus'],
		'Tag de Conversao Ads declara consentStatus needed (ad_storage)'
	);
}

// -----------------------------------------------------------------------------
// Parte 2: Validacao das funcoes PHP de emissao de scripts e fail-closed de WooCommerce
// -----------------------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return false; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		protected $status;
		public function __construct( $status = 'gplsquote-req' ) {
			$this->status = $status;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

$GLOBALS['uonix_test_is_order_received']   = false;
$GLOBALS['uonix_test_query_vars']           = array();
$GLOBALS['uonix_test_orders']               = array();
$GLOBALS['uonix_test_disable_wc_get_order'] = false;

function is_wc_endpoint_url( $endpoint = '' ) {
	return 'order-received' === $endpoint && ! empty( $GLOBALS['uonix_test_is_order_received'] );
}

function get_query_var( $var = '', $default = '' ) {
	return isset( $GLOBALS['uonix_test_query_vars'][ $var ] ) ? $GLOBALS['uonix_test_query_vars'][ $var ] : $default;
}

function wc_get_order( $order_id ) {
	if ( ! empty( $GLOBALS['uonix_test_disable_wc_get_order'] ) ) {
		return false;
	}
	return isset( $GLOBALS['uonix_test_orders'][ $order_id ] ) ? $GLOBALS['uonix_test_orders'][ $order_id ] : false;
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php';

gtm_assert( function_exists( 'uonix_render_analytics_conversion_footer' ), 'Funcao uonix_render_analytics_conversion_footer existe' );

// 2.1 Em pagina comum (is_order_received = false)
$GLOBALS['uonix_test_is_order_received'] = false;
$GLOBALS['uonix_test_query_vars']         = array();
$GLOBALS['uonix_test_orders']             = array();
ob_start();
uonix_render_analytics_conversion_footer();
$output_normal = ob_get_clean();

gtm_assert(
	false !== strpos( $output_normal, 'uonix-conversao-orcamento-datalayer' ),
	'Pagina comum emite o listener uonix-conversao-orcamento-datalayer'
);
gtm_assert(
	false === strpos( $output_normal, 'uonix-conversao-carrinho-datalayer' ),
	'Pagina comum NAO emite uonix-conversao-carrinho-datalayer'
);

// 2.2 Em pagina order-received com Pedido de Cotacao RFQ valido (status: gplsquote-req)
$GLOBALS['uonix_test_is_order_received']     = true;
$GLOBALS['uonix_test_query_vars']['order-received'] = 11027;
$GLOBALS['uonix_test_orders'][11027]        = new WC_Order( 'gplsquote-req' );

ob_start();
uonix_render_analytics_conversion_footer();
$output_rfq = ob_get_clean();

gtm_assert(
	false !== strpos( $output_rfq, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com pedido RFQ gplsquote-req emite uonix-conversao-carrinho-datalayer'
);
gtm_assert(
	false !== strpos( $output_rfq, "'event': 'uonix_solicitar_orcamento'" ),
	'order-received com pedido RFQ emite o evento uonix_solicitar_orcamento'
);
gtm_assert(
	false !== strpos( $output_rfq, "'origem_conversao': 'woocommerce_order_received'" ),
	'order-received com pedido RFQ emite metadado de origem woocommerce_order_received'
);
gtm_assert(
	false !== strpos( $output_rfq, "'order_id': 11027" ),
	'order-received com pedido RFQ emite o order_id correto'
);

// 2.3 Em pagina order-received com status com prefixo 'wc-gplsquote-req'
$GLOBALS['uonix_test_query_vars']['order-received'] = 11028;
$GLOBALS['uonix_test_orders'][11028]        = new WC_Order( 'wc-gplsquote-req' );
ob_start();
uonix_render_analytics_conversion_footer();
$output_rfq_prefix = ob_get_clean();

gtm_assert(
	false !== strpos( $output_rfq_prefix, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com pedido wc-gplsquote-req normaliza prefixo e emite conversao'
);

// 2.4 Negativo: Pedido comum com status 'processing' ou 'completed' NAO deve emitir
foreach ( array( 'processing', 'completed' ) as $common_status ) {
	$GLOBALS['uonix_test_query_vars']['order-received'] = 12001;
	$GLOBALS['uonix_test_orders'][12001]        = new WC_Order( $common_status );
	ob_start();
	uonix_render_analytics_conversion_footer();
	$output_common = ob_get_clean();

	gtm_assert(
		false === strpos( $output_common, 'uonix-conversao-carrinho-datalayer' ),
		"order-received com pedido comum (status: {$common_status}) NAO emite conversao (fail-closed)"
	);
}

// 2.5 Negativo: Outros status (on-hold, pending, cancelled, failed, refund) NAO devem emitir
foreach ( array( 'on-hold', 'pending', 'cancelled', 'failed', 'refunded' ) as $other_status ) {
	$GLOBALS['uonix_test_query_vars']['order-received'] = 12002;
	$GLOBALS['uonix_test_orders'][12002]        = new WC_Order( $other_status );
	ob_start();
	uonix_render_analytics_conversion_footer();
	$output_other = ob_get_clean();

	gtm_assert(
		false === strpos( $output_other, 'uonix-conversao-carrinho-datalayer' ),
		"order-received com status {$other_status} NAO emite conversao (fail-closed)"
	);
}

// 2.6 Negativo: ID do pedido ausente ou zero
$GLOBALS['uonix_test_query_vars']['order-received'] = 0;
ob_start();
uonix_render_analytics_conversion_footer();
$output_zero_id = ob_get_clean();

gtm_assert(
	false === strpos( $output_zero_id, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com ID de pedido 0 NAO emite conversao (fail-closed)'
);

// 2.7 Negativo: Pedido inexistente (wc_get_order retorna false/null)
$GLOBALS['uonix_test_query_vars']['order-received'] = 99999;
ob_start();
uonix_render_analytics_conversion_footer();
$output_nonexistent = ob_get_clean();

gtm_assert(
	false === strpos( $output_nonexistent, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com pedido inexistente NAO emite conversao (fail-closed)'
);

// 2.8 Negativo: wc_get_order indisponivel / erro
$GLOBALS['uonix_test_disable_wc_get_order'] = true;
$GLOBALS['uonix_test_query_vars']['order-received'] = 11027;
ob_start();
uonix_render_analytics_conversion_footer();
$output_wc_disabled = ob_get_clean();

gtm_assert(
	false === strpos( $output_wc_disabled, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com wc_get_order indisponivel NAO emite conversao (fail-closed)'
);
$GLOBALS['uonix_test_disable_wc_get_order'] = false;

// 2.9 Negativo: Objeto retornado nao possui metodo get_status
$GLOBALS['uonix_test_query_vars']['order-received'] = 12003;
$GLOBALS['uonix_test_orders'][12003]        = (object) array( 'id' => 12003 );
ob_start();
uonix_render_analytics_conversion_footer();
$output_invalid_obj = ob_get_clean();

gtm_assert(
	false === strpos( $output_invalid_obj, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com objeto invalido sem get_status NAO emite conversao (fail-closed)'
);

// -----------------------------------------------------------------------------
// Parte 3: Executa os testes de mutacao e caminhos positivos/negativos em Node.js
// -----------------------------------------------------------------------------
$node_test_path = __DIR__ . '/test-gtm-datalayer-listener.js';
$cmd = sprintf( 'node %s 2>&1', escapeshellarg( $node_test_path ) );
exec( $cmd, $node_output, $node_return );

gtm_assert( 0 === $node_return, 'test-gtm-datalayer-listener.js executa sem erros' );
if ( 0 !== $node_return ) {
	echo implode( "\n", $node_output ) . "\n";
}

if ( $failures > 0 ) {
	fwrite( STDERR, "\nTotal de falhas no contrato GTM e conversoes: {$failures}\n" );
	exit( 1 );
}

printf( "\nPASS: todos os contratos de GTM, AdOpt, equivalencia PHP e conversao estrita passaram!\n" );
