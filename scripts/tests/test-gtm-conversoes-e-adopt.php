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
 * 5. O listener Fluent Forms fica no footer e a conversao WooCommerce e emitida
 *    exclusivamente pelo hook woocommerce_thankyou, apos as guardas nativas;
 * 6. Replays do mesmo pedido carregam transaction_id estavel ate o campo Order ID da tag Ads;
 * 7. A conta Google Ads ativa e 601-200-6717, sem reutilizar labels da conta anterior;
 * 8. Executa a suite de caminhos positivos, negativos e resistencia a mutacao no JS.
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

function gtm_has_exact_consent_type( $tag, $expected_type ) {
	$list = isset( $tag['consentSettings']['consentType']['list'] )
		? $tag['consentSettings']['consentType']['list']
		: array();
	return 1 === count( $list )
		&& isset( $list[0]['value'] )
		&& $expected_type === $list[0]['value'];
}

function gtm_has_exact_filter( $filters, $expected_type, $expected_arg0, $expected_arg1 ) {
	if ( ! is_array( $filters ) || 1 !== count( $filters ) ) {
		return false;
	}

	$filter = $filters[0];
	if (
		! isset( $filter['type'], $filter['parameter'] )
		|| $expected_type !== $filter['type']
		|| ! is_array( $filter['parameter'] )
		|| 2 !== count( $filter['parameter'] )
		|| ! array_key_exists( 'negate', $filter )
		|| false !== $filter['negate']
	) {
		return false;
	}

	$parameters = array();
	foreach ( $filter['parameter'] as $parameter ) {
		if (
			3 !== count( $parameter )
			|| ! isset( $parameter['type'], $parameter['key'], $parameter['value'] )
			|| 'template' !== $parameter['type']
			|| ! in_array( $parameter['key'], array( 'arg0', 'arg1' ), true )
			|| isset( $parameters[ $parameter['key'] ] )
		) {
			return false;
		}
		$parameters[ $parameter['key'] ] = $parameter['value'];
	}

	return isset( $parameters['arg0'], $parameters['arg1'] )
		&& $expected_arg0 === $parameters['arg0']
		&& $expected_arg1 === $parameters['arg1'];
}

function gtm_has_exact_parameter( $entity, $expected_key, $expected_value ) {
	$matches = array_values(
		array_filter(
			isset( $entity['parameter'] ) ? $entity['parameter'] : array(),
			function ( $parameter ) use ( $expected_key ) {
				return isset( $parameter['key'] ) && $expected_key === $parameter['key'];
			}
		)
	);

	return 1 === count( $matches )
		&& isset( $matches[0]['type'], $matches[0]['value'] )
		&& 'template' === $matches[0]['type']
		&& $expected_value === $matches[0]['value'];
}

// -----------------------------------------------------------------------------
// Parte 1: Validacao programatica do Manifesto GTM
// -----------------------------------------------------------------------------
$manifest_path = getenv( 'UONIX_GTM_MANIFEST_PATH' );
if ( false === $manifest_path || '' === $manifest_path ) {
	$manifest_path = dirname( __DIR__, 2 ) . '/docs/gtm/uonix-google-ads-gtm-import.json';
}
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
$tags_by_id        = array();
$triggers_by_id    = array();
$triggers_by_name  = array();
$variables_by_name = array();

foreach ( $tags as $t ) {
	$tags_by_name[ $t['name'] ] = $t;
	$tags_by_id[ $t['tagId'] ]   = $t;
}
foreach ( $triggers as $tr ) {
	$triggers_by_id[ $tr['triggerId'] ] = $tr;
	$triggers_by_name[ $tr['name'] ]    = $tr;
}
foreach ( $variables as $v ) {
	$variables_by_name[ $v['name'] ] = $v;
}

// O container auditado é uma allowlist fechada: uma tag extra ativa poderia
// reintroduzir outro Measurement ID, outra conta Ads ou um label não confirmado
// sem quebrar as asserções que apenas procuram as tags esperadas.
$expected_tag_contract = array(
	'4'  => array( 'Tag AdOpt', 'html' ),
	'5'  => array( 'GA4 - Configuração', 'googtag' ),
	'6'  => array( 'Facebook Pixel - PageView', 'html' ),
	'12' => array( 'Google Ads - Vinculador de Conversões', 'gclidw' ),
	'13' => array( 'Google Ads - Tag do Google', 'googtag' ),
	'14' => array( 'Google Ads - Remarketing Geral', 'sp' ),
	'15' => array( 'Google Ads - Conversão - Clique WhatsApp', 'awct' ),
	'16' => array( 'Google Ads - Conversão - Envio de Formulário', 'awct' ),
);
$actual_tag_ids   = array_map(
	function ( $tag ) {
		return isset( $tag['tagId'] ) ? (string) $tag['tagId'] : '';
	},
	$tags
);
$expected_tag_ids = array_map( 'strval', array_keys( $expected_tag_contract ) );
sort( $actual_tag_ids );
sort( $expected_tag_ids );
gtm_assert(
	$expected_tag_ids === $actual_tag_ids,
	'Container GTM declara exatamente as oito tags autorizadas, sem destinos ou conversoes extras'
);
foreach ( $expected_tag_contract as $expected_tag_id => $contract ) {
	gtm_assert( isset( $tags_by_id[ $expected_tag_id ] ), "Tag {$expected_tag_id} autorizada existe no manifesto" );
	if ( isset( $tags_by_id[ $expected_tag_id ] ) ) {
		gtm_assert(
			$contract[0] === $tags_by_id[ $expected_tag_id ]['name']
				&& $contract[1] === $tags_by_id[ $expected_tag_id ]['type'],
			"Tag {$expected_tag_id} mantem nome e tipo autorizados"
		);
	}
}

// 1.0 Consistencia do snapshot canonico e placeholders fail-closed.
$version_id = isset( $version['containerVersionId'] ) ? (string) $version['containerVersionId'] : '';
gtm_assert( '' !== $version_id, 'Manifesto declara containerVersionId' );
gtm_assert( '18' === $version_id, 'Manifesto canonico aponta para a versao live 18 auditada' );
gtm_assert(
	isset( $version['path'] ) && preg_match( '#/versions/' . preg_quote( $version_id, '#' ) . '$#', $version['path'] ),
	'Path do manifesto aponta para o mesmo containerVersionId'
);
gtm_assert(
	isset( $version['tagManagerUrl'] ) && false !== strpos( $version['tagManagerUrl'], '/versions/' . $version_id ),
	'tagManagerUrl do manifesto aponta para o mesmo containerVersionId'
);

$placeholder_variables = array();
foreach ( $variables as $variable ) {
	foreach ( isset( $variable['parameter'] ) ? $variable['parameter'] : array() as $parameter ) {
		$value = isset( $parameter['value'] ) ? (string) $parameter['value'] : '';
		if ( preg_match( '/(?:AQUI|PLACEHOLDER|TODO)/i', $value ) ) {
			$placeholder_variables[] = $variable['name'];
		}
	}
}
foreach ( $tags as $tag ) {
	if ( ! empty( $tag['paused'] ) ) {
		continue;
	}
	$tag_json = json_encode( $tag );
	foreach ( $placeholder_variables as $variable_name ) {
		gtm_assert(
			false === strpos( $tag_json, '{{' . $variable_name . '}}' ),
			"Tag ativa {$tag['name']} nao referencia a variavel placeholder {$variable_name}"
		);
	}
}

$google_ads_id_variables = array_values(
	array_filter(
		$variables,
		function ( $variable ) {
			return isset( $variable['name'] ) && 'Constante - Google Ads ID' === $variable['name'];
		}
	)
);
gtm_assert( 1 === count( $google_ads_id_variables ), 'Existe exatamente uma variavel central de ID Google Ads' );
if ( 1 === count( $google_ads_id_variables ) ) {
	$google_ads_id_variable = $google_ads_id_variables[0];
	gtm_assert( '7' === $google_ads_id_variable['variableId'], 'Variavel central Google Ads mantem o ID de entidade 7' );
	gtm_assert( 'c' === $google_ads_id_variable['type'], 'Variavel central Google Ads permanece constante' );
	gtm_assert(
		array( array( 'type' => 'template', 'key' => 'value', 'value' => '6012006717' ) ) === $google_ads_id_variable['parameter'],
		'Variavel central Google Ads aponta exatamente para a conta 601-200-6717'
	);
}

gtm_assert( isset( $tags_by_id['13'] ), 'Tag 13 Google Tag existe no snapshot live 18' );
if ( isset( $tags_by_id['13'] ) ) {
	$tag_google = $tags_by_id['13'];
	gtm_assert( 'Google Ads - Tag do Google' === $tag_google['name'] && 'googtag' === $tag_google['type'], 'Tag 13 mantem identidade e tipo Google Tag' );
	gtm_assert( empty( $tag_google['paused'] ), 'Tag 13 Google Tag permanece ativa' );
	gtm_assert( gtm_has_exact_parameter( $tag_google, 'tagId', 'AW-{{Constante - Google Ads ID}}' ), 'Tag 13 deriva AW-6012006717 da variavel central' );
}

gtm_assert( isset( $tags_by_id['14'] ), 'Tag 14 de remarketing existe no snapshot live 18' );
if ( isset( $tags_by_id['14'] ) ) {
	$tag_remarketing = $tags_by_id['14'];
	gtm_assert( 'Google Ads - Remarketing Geral' === $tag_remarketing['name'] && 'sp' === $tag_remarketing['type'], 'Tag 14 mantem identidade e tipo de remarketing' );
	gtm_assert( empty( $tag_remarketing['paused'] ), 'Tag 14 de remarketing permanece ativa' );
	gtm_assert( gtm_has_exact_parameter( $tag_remarketing, 'conversionId', '{{Constante - Google Ads ID}}' ), 'Tag 14 deriva a conta 601-200-6717 da variavel central' );
}

foreach ( array( 'Constante - Label WhatsApp', 'Constante - Label Formulario' ) as $label_variable_name ) {
	gtm_assert( isset( $variables_by_name[ $label_variable_name ] ), "Variavel {$label_variable_name} existe" );
	if ( isset( $variables_by_name[ $label_variable_name ] ) ) {
		$expected_placeholder = 'Constante - Label WhatsApp' === $label_variable_name
			? 'ROTULO_WHATSAPP_AQUI'
			: 'ROTULO_FORMULARIO_AQUI';
		gtm_assert(
			array( array( 'type' => 'template', 'key' => 'value', 'value' => $expected_placeholder ) ) === $variables_by_name[ $label_variable_name ]['parameter'],
			"Variavel {$label_variable_name} permanece placeholder ate validacao na conta nova"
		);
	}
}

gtm_assert( isset( $tags_by_id['15'] ), 'Tag 15 de conversao WhatsApp existe no snapshot live 18' );
if ( isset( $tags_by_id['15'] ) ) {
	$tag_whatsapp = $tags_by_id['15'];
	gtm_assert( 'Google Ads - Conversão - Clique WhatsApp' === $tag_whatsapp['name'], 'Tag 15 mantem a identidade da conversao WhatsApp' );
	gtm_assert( 'awct' === $tag_whatsapp['type'], 'Tag 15 permanece uma conversao Google Ads awct' );
	gtm_assert( array_key_exists( 'paused', $tag_whatsapp ) && true === $tag_whatsapp['paused'], 'Tag 15 permanece explicitamente pausada enquanto o label e placeholder' );
	gtm_assert( array( '10' ) === $tag_whatsapp['firingTriggerId'], 'Tag 15 permanece vinculada exclusivamente ao trigger de clique WhatsApp 10' );
	gtm_assert( isset( $tag_whatsapp['tagFiringOption'] ) && 'oncePerEvent' === $tag_whatsapp['tagFiringOption'], 'Tag 15 dispara no maximo uma vez por evento quando reativada' );
	gtm_assert(
		isset( $tag_whatsapp['consentSettings']['consentStatus'] ) && 'needed' === $tag_whatsapp['consentSettings']['consentStatus'],
		'Tag 15 permanece bloqueada por consentimento necessario'
	);
	gtm_assert( gtm_has_exact_consent_type( $tag_whatsapp, 'ad_storage' ), 'Tag 15 exige exclusivamente ad_storage' );
	gtm_assert( false !== strpos( json_encode( $tag_whatsapp ), '{{Constante - Label WhatsApp}}' ), 'Tag 15 referencia a variavel de label auditada' );
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

// A limpeza explicita com transaction_id:null depende do modelo persistente da
// Data Layer v2; a entidade precisa ser unica e manter o contrato completo.
$transaction_id_variables = array_values(
	array_filter(
		$variables,
		function ( $variable ) {
			return isset( $variable['name'] ) && 'DLV - transaction_id' === $variable['name'];
		}
	)
);
gtm_assert( 1 === count( $transaction_id_variables ), 'Existe exatamente uma variavel DLV - transaction_id no manifesto' );
if ( 1 === count( $transaction_id_variables ) ) {
	$transaction_id_variable   = $transaction_id_variables[0];
	$transaction_id_parameters = isset( $transaction_id_variable['parameter'] )
		? $transaction_id_variable['parameter']
		: array();
	$expected_parameters       = array(
		array( 'type' => 'integer', 'key' => 'dataLayerVersion', 'value' => '2' ),
		array( 'type' => 'boolean', 'key' => 'setDefaultValue', 'value' => 'false' ),
		array( 'type' => 'template', 'key' => 'name', 'value' => 'transaction_id' ),
	);
	gtm_assert( 'v' === $transaction_id_variable['type'], 'DLV - transaction_id e do tipo Data Layer Variable (v)' );
	gtm_assert(
		$expected_parameters === $transaction_id_parameters,
		'DLV - transaction_id usa Data Layer v2, sem valor padrao, e le exatamente transaction_id'
	);
}

// 1.3 Trigger de Estatisticas
gtm_assert( isset( $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'] ), 'Trigger LGPD - AdOpt Estatísticas existe' );
if ( isset( $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'] ) ) {
	$tr_stat = $triggers_by_name['Trigger LGPD - AdOpt Estatísticas'];
	gtm_assert( 'customEvent' === $tr_stat['type'], 'Trigger de Estatisticas e do tipo customEvent' );
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_stat['customEventFilter'] ) ? $tr_stat['customEventFilter'] : array(), 'matchRegex', '{{_event}}', '^(adopt-accept-statistics|adopt-visitor-consent-ready|adopt_consent_updated)$' ),
		'Trigger de Estatisticas escuta exatamente os eventos AdOpt auditados'
	);
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_stat['filter'] ) ? $tr_stat['filter'] : array(), 'contains', '{{Tags_Aceitas_AdOpt}}', 'statistics' ),
		'Trigger de Estatisticas exige exatamente Tags_Aceitas_AdOpt contains statistics'
	);
}

// 1.4 Trigger de Marketing
gtm_assert( isset( $triggers_by_name['Trigger LGPD - AdOpt Marketing'] ), 'Trigger LGPD - AdOpt Marketing existe' );
if ( isset( $triggers_by_name['Trigger LGPD - AdOpt Marketing'] ) ) {
	$tr_mkt = $triggers_by_name['Trigger LGPD - AdOpt Marketing'];
	gtm_assert( 'customEvent' === $tr_mkt['type'], 'Trigger de Marketing e do tipo customEvent' );
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_mkt['customEventFilter'] ) ? $tr_mkt['customEventFilter'] : array(), 'matchRegex', '{{_event}}', '^(adopt-accept-marketing|adopt-visitor-consent-ready|adopt_consent_updated)$' ),
		'Trigger de Marketing escuta exatamente os eventos AdOpt auditados'
	);
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_mkt['filter'] ) ? $tr_mkt['filter'] : array(), 'contains', '{{Tags_Aceitas_AdOpt}}', 'marketing' ),
		'Trigger de Marketing exige exatamente Tags_Aceitas_AdOpt contains marketing'
	);
}

// 1.5 Trigger Solicitar Orcamento
gtm_assert( isset( $triggers_by_name['Evento - Solicitar Orçamento Uônix'] ), 'Trigger Evento - Solicitar Orçamento Uônix existe' );
if ( isset( $triggers_by_name['Evento - Solicitar Orçamento Uônix'] ) ) {
	$tr_orc = $triggers_by_name['Evento - Solicitar Orçamento Uônix'];
	gtm_assert( 'customEvent' === $tr_orc['type'], 'Trigger Solicitar Orcamento e do tipo customEvent' );
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_orc['customEventFilter'] ) ? $tr_orc['customEventFilter'] : array(), 'equals', '{{_event}}', 'uonix_solicitar_orcamento' ),
		'Trigger Solicitar Orcamento escuta exatamente _event equals uonix_solicitar_orcamento'
	);
	gtm_assert(
		gtm_has_exact_filter( isset( $tr_orc['filter'] ) ? $tr_orc['filter'] : array(), 'contains', '{{Tags_Aceitas_AdOpt}}', 'marketing' ),
		'Trigger Solicitar Orcamento exige exatamente Tags_Aceitas_AdOpt contains marketing'
	);
}

// 1.5.1 Contrato GTM: estrutura obrigatoria dos triggers criticos.
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
		$custom_event_filters = isset( $t['customEventFilter'] ) && is_array( $t['customEventFilter'] )
			? $t['customEventFilter']
			: array();
		gtm_assert( ! empty( $custom_event_filters ), "Trigger {$tr_id} ({$tr_name}) declara customEventFilter obrigatorio" );

		$filters = isset( $t['filter'] ) && is_array( $t['filter'] ) ? $t['filter'] : array();
		if ( in_array( $tr_id, array( '21', '24', '25' ), true ) ) {
			gtm_assert( ! empty( $filters ), "Trigger {$tr_id} ({$tr_name}) declara filter de categoria obrigatorio" );
		}
	}
}

// A API do GTM pode remover silenciosamente negate:false ao criar uma versao.
// Validamos todos os filtros do container, inclusive click e pageview, para que
// uma nova exportacao nao reintroduza esse defeito fora dos triggers customizados.
foreach ( $triggers as $trigger ) {
	foreach ( array( 'customEventFilter', 'filter', 'autoEventFilter' ) as $filter_field ) {
		foreach ( isset( $trigger[ $filter_field ] ) ? $trigger[ $filter_field ] : array() as $filter_index => $filter ) {
			gtm_assert(
				array_key_exists( 'negate', $filter ) && false === $filter['negate'],
				"Trigger {$trigger['triggerId']} ({$trigger['name']}) {$filter_field}[{$filter_index}] declara explicitamente negate === false"
			);
		}
	}
}

gtm_assert(
	isset( $triggers_by_id['3'] )
		&& gtm_has_exact_filter( $triggers_by_id['3']['customEventFilter'], 'equals', '{{_event}}', 'adopt-visitor-consent-ready' ),
	'Trigger 3 escuta exclusivamente _event equals adopt-visitor-consent-ready'
);

// 1.6 GA4
gtm_assert( isset( $tags_by_name['GA4 - Configuração'] ), 'Tag GA4 - Configuração existe' );
if ( isset( $tags_by_name['GA4 - Configuração'] ) ) {
	$tag_ga4 = $tags_by_name['GA4 - Configuração'];
	gtm_assert( '5' === $tag_ga4['tagId'] && 'googtag' === $tag_ga4['type'], 'GA4 mantem a identidade da tag 5 e o tipo Google Tag' );
	gtm_assert( empty( $tag_ga4['paused'] ), 'GA4 permanece ativo' );
	gtm_assert( gtm_has_exact_parameter( $tag_ga4, 'tagId', 'G-RFY1BB1RM4' ), 'GA4 aponta para o stream web da propriedade 445033830' );
	gtm_assert( in_array( '25', $tag_ga4['firingTriggerId'], true ), 'GA4 dispara via Trigger LGPD - AdOpt Estatísticas (25)' );
	gtm_assert(
		isset( $tag_ga4['consentSettings']['consentStatus'] ) && 'needed' === $tag_ga4['consentSettings']['consentStatus'],
		'GA4 declara consentStatus needed'
	);
	gtm_assert( gtm_has_exact_consent_type( $tag_ga4, 'analytics_storage' ), 'GA4 exige exclusivamente analytics_storage' );
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
	gtm_assert( gtm_has_exact_consent_type( $tag_fb, 'ad_storage' ), 'Meta Pixel exige exclusivamente ad_storage' );
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
	gtm_assert( gtm_has_exact_consent_type( $tag_rem, 'ad_storage' ), 'Google Ads Remarketing exige exclusivamente ad_storage' );
}

// 1.9 Google Ads Conversao Envio Formulario
gtm_assert( isset( $tags_by_name['Google Ads - Conversão - Envio de Formulário'] ), 'Tag Google Ads - Conversão - Envio de Formulário existe' );
if ( isset( $tags_by_name['Google Ads - Conversão - Envio de Formulário'] ) ) {
	$tag_conv = $tags_by_name['Google Ads - Conversão - Envio de Formulário'];
	gtm_assert( array_key_exists( 'paused', $tag_conv ) && true === $tag_conv['paused'], 'Tag de Conversao Ads permanece pausada enquanto o label da conta nova e placeholder' );
	gtm_assert( gtm_has_exact_parameter( $tag_conv, 'conversionId', '{{Constante - Google Ads ID}}' ), 'Tag de Conversao Ads deriva a conta 601-200-6717 da variavel central' );
	gtm_assert( gtm_has_exact_parameter( $tag_conv, 'conversionLabel', '{{Constante - Label Formulario}}' ), 'Tag de Conversao Ads referencia exclusivamente o label de formulario central' );
	gtm_assert( array( '21' ) === $tag_conv['firingTriggerId'], 'Tag de Conversao Ads dispara EXCLUSIVAMENTE no Trigger 21 (uonix_solicitar_orcamento)' );
	gtm_assert( ! in_array( '17', $tag_conv['firingTriggerId'], true ), 'Tag de Conversao Ads NAO contem trigger redundante de order-received (17)' );
	gtm_assert( ! in_array( '11', $tag_conv['firingTriggerId'], true ), 'Tag de Conversao Ads NAO contem trigger generico de formSubmission (11)' );
	gtm_assert(
		isset( $tag_conv['consentSettings']['consentStatus'] ) && 'needed' === $tag_conv['consentSettings']['consentStatus'],
		'Tag de Conversao Ads declara consentStatus needed (ad_storage)'
	);
	gtm_assert( gtm_has_exact_consent_type( $tag_conv, 'ad_storage' ), 'Tag de Conversao Ads exige exclusivamente ad_storage' );
	$order_id_parameters = array_values(
		array_filter(
			isset( $tag_conv['parameter'] ) ? $tag_conv['parameter'] : array(),
			function ( $parameter ) {
				return isset( $parameter['key'] ) && 'orderId' === $parameter['key'];
			}
		)
	);
	gtm_assert(
		1 === count( $order_id_parameters )
			&& isset( $order_id_parameters[0]['type'], $order_id_parameters[0]['value'] )
			&& 'template' === $order_id_parameters[0]['type']
			&& '{{DLV - transaction_id}}' === $order_id_parameters[0]['value'],
		'Tag de Conversao Ads envia DLV - transaction_id no campo Order ID'
	);
}

// -----------------------------------------------------------------------------
// Parte 2: Validacao das funcoes PHP de emissao de scripts e fail-closed de WooCommerce
// -----------------------------------------------------------------------------
$GLOBALS['uonix_test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['uonix_test_actions'][] = array( $hook, $callback, $priority, $accepted_args );
	}
}

function gtm_do_action( $hook, ...$args ) {
	$callbacks = array_values(
		array_filter(
			$GLOBALS['uonix_test_actions'],
			function ( $registered ) use ( $hook ) {
				return $hook === $registered[0];
			}
		)
	);
	usort(
		$callbacks,
		function ( $left, $right ) {
			return $left[2] <=> $right[2];
		}
	);
	if ( empty( $args ) ) {
		$args = array( '' );
	}
	foreach ( $callbacks as $registered ) {
		call_user_func_array( $registered[1], array_slice( $args, 0, $registered[3] ) );
	}
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
		protected $order_key;
		public function __construct( $status = 'gplsquote-req', $order_key = 'wc_order_key' ) {
			$this->status = $status;
			$this->order_key = $order_key;
		}
		public function get_status() {
			return $this->status;
		}
		public function get_order_key() {
			return $this->order_key;
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
gtm_assert( function_exists( 'uonix_render_analytics_rfq_conversion' ), 'Funcao uonix_render_analytics_rfq_conversion existe' );
gtm_assert(
	in_array( array( 'woocommerce_thankyou', 'uonix_render_analytics_rfq_conversion', 10, 1 ), $GLOBALS['uonix_test_actions'], true ),
	'Conversao RFQ e registrada no hook woocommerce_thankyou apos as guardas nativas do WooCommerce'
);

function gtm_render_rfq_conversion( $order_id ) {
	ob_start();
	gtm_do_action( 'woocommerce_thankyou', $order_id );
	return ob_get_clean();
}

// 2.1 Em pagina comum (is_order_received = false)
$GLOBALS['uonix_test_is_order_received'] = false;
$GLOBALS['uonix_test_query_vars']         = array();
$GLOBALS['uonix_test_orders']             = array();
ob_start();
gtm_do_action( 'wp_footer' );
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
$_GET['key']                                  = 'wc_order_key';
$GLOBALS['uonix_test_orders'][11027]        = new WC_Order( 'gplsquote-req' );

ob_start();
gtm_do_action( 'wp_footer' );
$output_rfq_footer = ob_get_clean();

gtm_assert(
	false === strpos( $output_rfq_footer, 'uonix-conversao-carrinho-datalayer' ),
	'Footer generico NAO emite conversao WooCommerce antes das guardas nativas'
);
$output_rfq = gtm_render_rfq_conversion( 11027 );

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
	false !== strpos( $output_rfq, 'var orderId = 11027' )
		&& false !== strpos( $output_rfq, "'order_id': orderId" ),
	'order-received com pedido RFQ emite o order_id correto'
);
gtm_assert(
	false !== strpos( $output_rfq, "'transaction_id': 'uonix-rfq-order-' + orderId" ),
	'order-received emite transaction_id estavel e sem PII derivado do pedido'
);
gtm_assert(
	false === strpos( $output_rfq, 'sessionStorage' ) && false === strpos( $output_rfq, 'localStorage' ),
	'order-received nao grava marcador client-side antes da elegibilidade por consentimento'
);

// 2.3 Em pagina order-received com status com prefixo 'wc-gplsquote-req'
$GLOBALS['uonix_test_query_vars']['order-received'] = 11028;
$_GET['key']                                  = 'wc_order_key';
$GLOBALS['uonix_test_orders'][11028]        = new WC_Order( 'wc-gplsquote-req' );
$output_rfq_prefix = gtm_render_rfq_conversion( 11028 );

gtm_assert(
	false !== strpos( $output_rfq_prefix, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com pedido wc-gplsquote-req normaliza prefixo e emite conversao'
);

// 2.3b Negativo: chave ausente, vazia ou adulterada nunca qualifica a confirmacao RFQ.
$invalid_order_keys = array(
	'ausente'    => null,
	'vazia'      => '',
	'adulterada' => 'wc_order_key_tampered',
);
foreach ( $invalid_order_keys as $invalid_key_label => $invalid_order_key ) {
	$GLOBALS['uonix_test_query_vars']['order-received'] = 11027;
	if ( null === $invalid_order_key ) {
		unset( $_GET['key'] );
	} else {
		$_GET['key'] = $invalid_order_key;
	}
	$output_invalid_key = gtm_render_rfq_conversion( 11027 );

	gtm_assert(
		false === strpos( $output_invalid_key, 'uonix-conversao-carrinho-datalayer' ),
		"order-received RFQ com chave {$invalid_key_label} NAO emite conversao"
	);
}

// 2.4 Negativo: Pedido comum com status 'processing' ou 'completed' NAO deve emitir
$_GET['key'] = 'wc_order_key';
foreach ( array( 'processing', 'completed' ) as $common_status ) {
	$GLOBALS['uonix_test_query_vars']['order-received'] = 12001;
	$GLOBALS['uonix_test_orders'][12001]        = new WC_Order( $common_status );
	$output_common = gtm_render_rfq_conversion( 12001 );

	gtm_assert(
		false === strpos( $output_common, 'uonix-conversao-carrinho-datalayer' ),
		"order-received com pedido comum (status: {$common_status}) NAO emite conversao (fail-closed)"
	);
}

// 2.5 Negativo: Outros status (on-hold, pending, cancelled, failed, refund) NAO devem emitir
$_GET['key'] = 'wc_order_key';
foreach ( array( 'on-hold', 'pending', 'cancelled', 'failed', 'refunded' ) as $other_status ) {
	$GLOBALS['uonix_test_query_vars']['order-received'] = 12002;
	$GLOBALS['uonix_test_orders'][12002]        = new WC_Order( $other_status );
	$output_other = gtm_render_rfq_conversion( 12002 );

	gtm_assert(
		false === strpos( $output_other, 'uonix-conversao-carrinho-datalayer' ),
		"order-received com status {$other_status} NAO emite conversao (fail-closed)"
	);
}

// 2.6 Negativo: ID do pedido ausente ou zero
$GLOBALS['uonix_test_query_vars']['order-received'] = 0;
$output_zero_id = gtm_render_rfq_conversion( 0 );

gtm_assert(
	false === strpos( $output_zero_id, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com ID de pedido 0 NAO emite conversao (fail-closed)'
);

// 2.7 Negativo: Pedido inexistente (wc_get_order retorna false/null)
$GLOBALS['uonix_test_query_vars']['order-received'] = 99999;
$output_nonexistent = gtm_render_rfq_conversion( 99999 );

gtm_assert(
	false === strpos( $output_nonexistent, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com pedido inexistente NAO emite conversao (fail-closed)'
);

// 2.8 Negativo: wc_get_order indisponivel / erro
$GLOBALS['uonix_test_disable_wc_get_order'] = true;
$GLOBALS['uonix_test_query_vars']['order-received'] = 11027;
$output_wc_disabled = gtm_render_rfq_conversion( 11027 );

gtm_assert(
	false === strpos( $output_wc_disabled, 'uonix-conversao-carrinho-datalayer' ),
	'order-received com wc_get_order indisponivel NAO emite conversao (fail-closed)'
);
$GLOBALS['uonix_test_disable_wc_get_order'] = false;

// 2.9 Negativo: Objeto retornado nao possui metodo get_status
$GLOBALS['uonix_test_query_vars']['order-received'] = 12003;
$GLOBALS['uonix_test_orders'][12003]        = (object) array( 'id' => 12003 );
$output_invalid_obj = gtm_render_rfq_conversion( 12003 );

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
