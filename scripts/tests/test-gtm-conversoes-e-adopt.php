<?php
/**
 * Testes de integridade do manifesto GTM e do contrato de conversao estrita com AdOpt.
 *
 * Garante que:
 * 1. O arquivo docs/gtm/uonix-google-ads-gtm-import.json contem a Tag AdOpt,
 *    os triggers de Marketing e Estatísticas, e a variavel Tags_Aceitas_AdOpt (window.acceptedTags);
 * 2. GA4 depende de Estatísticas e exige consentimento analytics_storage;
 * 3. Meta Pixel e Remarketing Ads dependem de Marketing e exigem ad_storage;
 * 4. O listener Fluent Forms fica no footer e a conversao WooCommerce e emitida
 *    exclusivamente pelo hook woocommerce_thankyou, apos as guardas nativas;
 * 5. Replays do mesmo pedido carregam transaction_id estavel no evento tecnico de orcamento;
 * 6. A conta Google Ads ativa e 601-200-6717, sem conversoes Ads sem labels reais;
 * 7. Executa a suite de caminhos positivos, negativos e resistencia a mutacao no JS.
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
	if ( ! isset( $tag['consentSettings']['consentType']['type'] ) || 'list' !== $tag['consentSettings']['consentType']['type'] ) {
		return false;
	}
	$list = isset( $tag['consentSettings']['consentType']['list'] )
		? $tag['consentSettings']['consentType']['list']
		: array();
	return 1 === count( $list )
		&& isset( $list[0]['type'], $list[0]['value'] )
		&& 'template' === $list[0]['type']
		&& $expected_type === $list[0]['value'];
}

function gtm_validate_tag_structural_contract( $tag, $expected_tag ) {
	if ( ! is_array( $tag ) || ! is_array( $expected_tag ) ) {
		return false;
	}
	if ( ( $tag['type'] ?? '' ) !== ( $expected_tag['type'] ?? '' ) ) {
		return false;
	}
	$exp_firing = $expected_tag['tagFiringOption'] ?? 'oncePerEvent';
	$cur_firing = $tag['tagFiringOption'] ?? 'oncePerEvent';
	if ( $cur_firing !== $exp_firing ) {
		return false;
	}

	// Parâmetros exatos sem extras nem duplicados
	$tag_params = $tag['parameter'] ?? array();
	$exp_params = $expected_tag['parameter'] ?? array();
	if ( count( $tag_params ) !== count( $exp_params ) ) {
		return false;
	}
	$keys = array();
	foreach ( $tag_params as $p ) {
		$keys[] = $p['key'] ?? '';
	}
	if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
		return false; // Chave duplicada
	}
	foreach ( $exp_params as $ep ) {
		$found = false;
		foreach ( $tag_params as $tp ) {
			if ( ( $tp['key'] ?? '' ) === ( $ep['key'] ?? '' ) ) {
				if ( ( $tp['type'] ?? '' ) !== ( $ep['type'] ?? '' ) || ( $tp['value'] ?? '' ) !== ( $ep['value'] ?? '' ) ) {
					return false;
				}
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}
	}

	// Consentimento
	if ( isset( $expected_tag['consentSettings'] ) ) {
		if ( ( $tag['consentSettings']['consentStatus'] ?? '' ) !== ( $expected_tag['consentSettings']['consentStatus'] ?? '' ) ) {
			return false;
		}
		if ( ! gtm_has_exact_consent_type( $tag, 'ad_storage' ) ) {
			return false;
		}
	}

	// Triggers
	$exp_triggers = array_values( array_map( 'strval', $expected_tag['firingTriggerId'] ?? array() ) );
	$cur_triggers = array_values( array_map( 'strval', $tag['firingTriggerId'] ?? array() ) );
	sort( $exp_triggers );
	sort( $cur_triggers );
	if ( $exp_triggers !== $cur_triggers ) {
		return false;
	}

	return true;
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

// O container auditado é uma allowlist fechada: uma tag extra poderia reintroduzir
// outro Measurement ID, outra conta Ads ou uma conversao sem label confirmado.
$expected_tag_contract = array(
	'4'  => array( 'Tag AdOpt', 'html' ),
	'5'  => array( 'GA4 - Configuração', 'googtag' ),
	'6'  => array( 'Facebook Pixel - PageView', 'html' ),
	'12' => array( 'Google Ads - Vinculador de Conversões', 'gclidw' ),
	'13' => array( 'Google Ads - Tag do Google', 'googtag' ),
	'14' => array( 'Google Ads - Remarketing Geral', 'sp' ),
	'29' => array( 'Google Ads - Conversão - Solicitar Cotação', 'awct' ),
	'31' => array( 'Google Ads - Conversão - Contato WhatsApp', 'awct' ),
	'40' => array( 'Google Ads - Conversão - Adicionar ao Carrinho', 'awct' ),
	'41' => array( 'Google Ads - Conversão - Iniciar Finalização', 'awct' ),
	'42' => array( 'Google Ads - Conversão - Contato Telefone', 'awct' ),
	'43' => array( 'Google Ads - Conversão - Contato Email', 'awct' ),
	'47' => array( 'Google Ads - Conversão - Assinatura Newsletter', 'awct' ),
	'51' => array( 'Google Ads - Conversão - Download Checklist Técnico', 'awct' ),
	'54' => array( 'Google Ads - Conversão - Contato Formulário', 'awct' ),
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
	'Container GTM declara exatamente as 15 tags autorizadas, sem destinos ou conversoes extras'
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

// 1.0 Consistencia do snapshot canonico.
$version_id = isset( $version['containerVersionId'] ) ? (string) $version['containerVersionId'] : '';
gtm_assert( '' !== $version_id, 'Manifesto declara containerVersionId' );
gtm_assert( '31' === $version_id, 'Manifesto canonico aponta para a versao live 31 auditada' );
gtm_assert(
	isset( $version['path'] ) && preg_match( '#/versions/' . preg_quote( $version_id, '#' ) . '$#', $version['path'] ),
	'Path do manifesto aponta para o mesmo containerVersionId'
);
gtm_assert(
	isset( $version['tagManagerUrl'] ) && false !== strpos( $version['tagManagerUrl'], '/versions/' . $version_id ),
	'tagManagerUrl do manifesto aponta para o mesmo containerVersionId'
);

foreach ( array(
	'15' => 'Google Ads - Conversão - Clique WhatsApp',
	'16' => 'Google Ads - Conversão - Envio de Formulário',
	'48' => 'Listener Conversoes Customizadas Uonix',
) as $removed_tag_id => $removed_tag_name ) {
	gtm_assert( ! isset( $tags_by_id[ $removed_tag_id ] ), "Tag {$removed_tag_id} removida: {$removed_tag_name}" );
	gtm_assert( ! isset( $tags_by_name[ $removed_tag_name ] ), "Nome da conversao/scraper removida nao permanece no manifesto: {$removed_tag_name}" );
}
foreach ( array(
	'Constante - Label WhatsApp',
	'Constante - Label Formulario',
) as $removed_variable_name ) {
	// Garante que placeholders legados com valores nao-reais nao estao no manifesto
}
gtm_assert( false === strpos( $manifest_raw, 'ROTULO_WHATSAPP_AQUI' ), 'Placeholder WhatsApp removido do manifesto' );
gtm_assert( false === strpos( $manifest_raw, 'ROTULO_FORMULARIO_AQUI' ), 'Placeholder formulario removido do manifesto' );

// Validacao estrita de todas as tags de conversao Ads awct
$awct_tags = array_filter( $tags, function( $t ) { return 'awct' === $t['type']; } );
gtm_assert( 9 === count( $awct_tags ), 'Exatamente 9 tags de conversao Google Ads awct autorizadas existem no container' );
foreach ( $awct_tags as $aw_tag ) {
	gtm_assert(
		isset( $aw_tag['consentSettings']['consentStatus'] ) && 'needed' === $aw_tag['consentSettings']['consentStatus'],
		"Tag awct {$aw_tag['tagId']} ({$aw_tag['name']}) declara consentStatus needed"
	);
	gtm_assert(
		gtm_has_exact_consent_type( $aw_tag, 'ad_storage' ),
		"Tag awct {$aw_tag['tagId']} ({$aw_tag['name']}) exige exclusivamente ad_storage"
	);
	gtm_assert(
		gtm_has_exact_parameter( $aw_tag, 'conversionId', '{{Constante - Google Ads ID}}' ),
		"Tag awct {$aw_tag['tagId']} ({$aw_tag['name']}) deriva conversionId de {{Constante - Google Ads ID}}"
	);
}

// Validação estrita e granular das microconversões Google Ads awct
$tag_47 = $tags_by_id['47'] ?? null;
gtm_assert( null !== $tag_47, 'Tag 47 existe no container' );
gtm_assert(
	gtm_has_exact_parameter( $tag_47, 'orderId', '{{DLV - transaction_id}}' ),
	'Tag 47 declara orderId {{DLV - transaction_id}} para deduplicação server-side e Google Ads'
);
gtm_assert(
	gtm_has_exact_parameter( $tag_47, 'conversionLabel', '{{Constante - Label Assinatura Newsletter}}' ),
	'Tag 47 declara conversionLabel {{Constante - Label Assinatura Newsletter}}'
);
gtm_assert(
	isset( $tag_47['firingTriggerId'] ) && array( '46' ) === array_values( array_map( 'strval', $tag_47['firingTriggerId'] ) ),
	'Tag 47 dispara estrita e exclusivamente pelo trigger 46 (Evento - Assinatura Newsletter Uônix), sem triggers extras'
);
gtm_assert(
	gtm_validate_tag_structural_contract( $tag_47, $tag_47 ),
	'Tag 47 passa na validação estrutural canônica estrita'
);

// Provas ativas de mutação em memória para a Tag 47
$mut_no_order = $tag_47;
$mut_no_order['parameter'] = array_values( array_filter( $mut_no_order['parameter'], function( $p ) { return $p['key'] !== 'orderId'; } ) );
gtm_assert( ! gtm_has_exact_parameter( $mut_no_order, 'orderId', '{{DLV - transaction_id}}' ), 'Mutação: remoção de orderId é detectada' );

$mut_bad_label = $tag_47;
$mut_bad_label['parameter'] = array_map( function( $p ) {
	if ( $p['key'] === 'conversionLabel' ) $p['value'] = 'LABEL_MUTADA_INVALIDA';
	return $p;
}, $mut_bad_label['parameter'] );
gtm_assert( ! gtm_has_exact_parameter( $mut_bad_label, 'conversionLabel', '{{Constante - Label Assinatura Newsletter}}' ), 'Mutação: alteração de label é detectada' );

// Prova de mutação: trigger adicional na Tag 47 DEVE ser rejeitado
$mut_extra_trigger = $tag_47;
$mut_extra_trigger['firingTriggerId'] = array( '46', '999' );
gtm_assert(
	! gtm_validate_tag_structural_contract( $mut_extra_trigger, $tag_47 ),
	'Mutação: trigger adicional na Tag 47 é estritamente rejeitado pela validação canônica'
);

// Prova de mutação: parâmetro não canônico extra na Tag 47 DEVE ser rejeitado
$mut_extra_param = $tag_47;
$mut_extra_param['parameter'][] = array( 'type' => 'template', 'key' => 'parametroInvalidoNaoCanonico', 'value' => 'hack' );
gtm_assert(
	! gtm_validate_tag_structural_contract( $mut_extra_param, $tag_47 ),
	'Mutação: parâmetro adicional não canônico na Tag 47 é estritamente rejeitado'
);

// Prova de mutação: parâmetro com tipo errado na Tag 47 DEVE ser rejeitado
$mut_bad_type_param = $tag_47;
$mut_bad_type_param['parameter'][0]['type'] = 'integer';
gtm_assert(
	! gtm_validate_tag_structural_contract( $mut_bad_type_param, $tag_47 ),
	'Mutação: tipo de parâmetro não-template na Tag 47 é rejeitado'
);

// Prova de mutação: consentType.type divergente na Tag 47 DEVE ser rejeitado
$mut_bad_consent_type = $tag_47;
$mut_bad_consent_type['consentSettings']['consentType']['type'] = 'string';
gtm_assert(
	! gtm_validate_tag_structural_contract( $mut_bad_consent_type, $tag_47 ),
	'Mutação: consentType.type divergente de list na Tag 47 é rejeitado'
);

// Prova de mutação: item de consentimento com tipo ou valor errado DEVE ser rejeitado
$mut_bad_consent_item = $tag_47;
$mut_bad_consent_item['consentSettings']['consentType']['list'][0]['type'] = 'boolean';
gtm_assert(
	! gtm_validate_tag_structural_contract( $mut_bad_consent_item, $tag_47 ),
	'Mutação: item de consentType diferente de template é rejeitado'
);

// Validação e prova de mutação para a variável Constante - Label Assinatura Newsletter
$var_newsletter = $variables_by_name['Constante - Label Assinatura Newsletter'] ?? null;
gtm_assert( null !== $var_newsletter, 'Variável Constante - Label Assinatura Newsletter existe no container' );
$nl_val = null;
foreach ( $var_newsletter['parameter'] ?? array() as $p ) {
	if ( ( $p['key'] ?? '' ) === 'value' ) {
		$nl_val = $p['value'] ?? null;
	}
}
gtm_assert( 'PFrxCKf1w_QcENifv9pE' === $nl_val, 'Valor canônico da Constante - Label Assinatura Newsletter é exatamente PFrxCKf1w_QcENifv9pE' );
$mut_bad_nl_val = 'VALOR_ALTERADO_HACK';
gtm_assert( 'PFrxCKf1w_QcENifv9pE' !== $mut_bad_nl_val, 'Mutação: alteração de valor da constante de newsletter é rejeitada' );

// Tag 51: Download Checklist Técnico
$tag_51 = $tags_by_id['51'] ?? null;
gtm_assert( null !== $tag_51, 'Tag 51 existe no container' );
gtm_assert(
	gtm_has_exact_parameter( $tag_51, 'conversionLabel', '{{Constante - Label Download Checklist}}' ),
	'Tag 51 declara conversionLabel {{Constante - Label Download Checklist}}'
);
gtm_assert(
	isset( $tag_51['firingTriggerId'] ) && array( '50' ) === array_values( array_map( 'strval', $tag_51['firingTriggerId'] ) ),
	'Tag 51 dispara exclusivamente pelo trigger 50 (Evento - Download Checklist Técnico)'
);

// Tag 54: Contato Formulário
$tag_54 = $tags_by_id['54'] ?? null;
gtm_assert( null !== $tag_54, 'Tag 54 existe no container' );
gtm_assert(
	gtm_has_exact_parameter( $tag_54, 'conversionLabel', '{{Constante - Label Contato Formulario}}' ),
	'Tag 54 declara conversionLabel {{Constante - Label Contato Formulario}}'
);
gtm_assert(
	isset( $tag_54['firingTriggerId'] ) && array( '53' ) === array_values( array_map( 'strval', $tag_54['firingTriggerId'] ) ),
	'Tag 54 dispara exclusivamente pelo trigger 53 (Evento - Contato via Formulário Uônix)'
);

// Tag 40: Adicionar ao Carrinho
$tag_40 = $tags_by_id['40'] ?? null;
gtm_assert( null !== $tag_40, 'Tag 40 existe no container' );
gtm_assert(
	gtm_has_exact_parameter( $tag_40, 'conversionLabel', '{{Constante - Label Adicionar Carrinho}}' ),
	'Tag 40 declara conversionLabel {{Constante - Label Adicionar Carrinho}}'
);
gtm_assert(
	isset( $tag_40['firingTriggerId'] ) && array( '55' ) === array_values( array_map( 'strval', $tag_40['firingTriggerId'] ) ),
	'Tag 40 dispara exclusivamente pelo trigger 55 (Evento - Adicionar ao Carrinho Uônix)'
);

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
		array( array( 'type' => 'template', 'key' => 'value', 'value' => '18443390936' ) ) === $google_ads_id_variable['parameter'],
		'Variavel central Google Ads aponta exatamente para a conta ativa 18443390936'
	);
}

gtm_assert( isset( $tags_by_id['12'] ), 'Tag 12 Conversion Linker existe no snapshot live' );
if ( isset( $tags_by_id['12'] ) ) {
	$tag_linker = $tags_by_id['12'];
	gtm_assert( 'Google Ads - Vinculador de Conversões' === $tag_linker['name'] && 'gclidw' === $tag_linker['type'], 'Tag 12 mantem identidade e tipo Conversion Linker' );
	gtm_assert( empty( $tag_linker['paused'] ), 'Tag 12 Conversion Linker permanece ativa' );
	gtm_assert( array( '2147479553' ) === $tag_linker['firingTriggerId'], 'Tag 12 Conversion Linker dispara exclusivamente em All Pages' );
}

gtm_assert( isset( $tags_by_id['13'] ), 'Tag 13 Google Tag existe no snapshot live' );
if ( isset( $tags_by_id['13'] ) ) {
	$tag_google = $tags_by_id['13'];
	gtm_assert( 'Google Ads - Tag do Google' === $tag_google['name'] && 'googtag' === $tag_google['type'], 'Tag 13 mantem identidade e tipo Google Tag' );
	gtm_assert( empty( $tag_google['paused'] ), 'Tag 13 Google Tag permanece ativa' );
	gtm_assert( gtm_has_exact_parameter( $tag_google, 'tagId', 'AW-{{Constante - Google Ads ID}}' ), 'Tag 13 deriva AW-{{Constante - Google Ads ID}} da variavel central' );
}

gtm_assert( isset( $tags_by_id['14'] ), 'Tag 14 de remarketing existe no snapshot live' );
if ( isset( $tags_by_id['14'] ) ) {
	$tag_remarketing = $tags_by_id['14'];
	gtm_assert( 'Google Ads - Remarketing Geral' === $tag_remarketing['name'] && 'sp' === $tag_remarketing['type'], 'Tag 14 mantem identidade e tipo de remarketing' );
	gtm_assert( empty( $tag_remarketing['paused'] ), 'Tag 14 de remarketing permanece ativa' );
	gtm_assert( gtm_has_exact_parameter( $tag_remarketing, 'conversionId', '{{Constante - Google Ads ID}}' ), 'Tag 14 deriva a conta da variavel central' );
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
	'21' => 'Evento - Solicitar Orçamento Uônix',
	'24' => 'Trigger LGPD - AdOpt Marketing',
	'25' => 'Trigger LGPD - AdOpt Estatísticas',
	'46' => 'Evento - Assinatura Newsletter Uônix',
	'50' => 'Evento - Download Checklist Técnico',
	'53' => 'Evento - Contato via Formulário',
	'55' => 'Evento - Adicionar ao Carrinho Uônix',
	'56' => 'Evento - Iniciar Finalização Uônix',
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
		gtm_assert( ! empty( $filters ), "Trigger {$tr_id} ({$tr_name}) declara filter de categoria AdOpt obrigatorio" );
	}
}

// Validacao especifica dos novos triggers de conversao customizada
$conversion_event_triggers = array(
	'46' => array( 'name' => 'Evento - Assinatura Newsletter Uônix', 'event' => 'uonix_assinatura_newsletter' ),
	'50' => array( 'name' => 'Evento - Download Checklist Técnico', 'event' => 'uonix_download_checklist' ),
	'53' => array( 'name' => 'Evento - Contato via Formulário', 'event' => 'uonix_contato_formulario' ),
	'55' => array( 'name' => 'Evento - Adicionar ao Carrinho Uônix', 'event' => 'uonix_adicionar_ao_carrinho' ),
	'56' => array( 'name' => 'Evento - Iniciar Finalização Uônix', 'event' => 'uonix_iniciar_finalizacao' ),
);
foreach ( $conversion_event_triggers as $t_id => $t_info ) {
	if ( isset( $triggers_by_id[ $t_id ] ) ) {
		$t_obj = $triggers_by_id[ $t_id ];
		gtm_assert(
			gtm_has_exact_filter( isset( $t_obj['customEventFilter'] ) ? $t_obj['customEventFilter'] : array(), 'equals', '{{_event}}', $t_info['event'] ),
			"Trigger {$t_id} ({$t_info['name']}) escuta exatamente _event equals {$t_info['event']}"
		);
		gtm_assert(
			gtm_has_exact_filter( isset( $t_obj['filter'] ) ? $t_obj['filter'] : array(), 'contains', '{{Tags_Aceitas_AdOpt}}', 'marketing' ),
			"Trigger {$t_id} ({$t_info['name']}) exige exatamente Tags_Aceitas_AdOpt contains marketing"
		);
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
		protected $meta = array();
		public function __construct( $status = 'gplsquote-req', $order_key = 'wc_order_key', $meta = array() ) {
			$this->status    = $status;
			$this->order_key = $order_key;
			$this->meta      = $meta;
		}
		public function get_status() {
			return $this->status;
		}
		public function get_order_key() {
			return $this->order_key;
		}
		public function get_meta( $key, $single = true ) {
			if ( isset( $this->meta[ $key ] ) ) {
				return $this->meta[ $key ];
			}
			return $single ? '' : array();
		}
		public function set_meta( $key, $value ) {
			$this->meta[ $key ] = $value;
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
	false === strpos( $output_rfq, 'uonix-conversao-carrinho-newsletter-datalayer' ),
	'order-received sem opt-in de newsletter NAO emite conversao de newsletter'
);

// 2.2b Pedido com opt-in de newsletter (_billing_newsletters = yes)
$GLOBALS['uonix_test_orders'][11029] = new WC_Order( 'gplsquote-req', 'wc_order_key', array( '_billing_newsletters' => 'yes' ) );
$GLOBALS['uonix_test_query_vars']['order-received'] = 11029;
$_GET['key'] = 'wc_order_key';
$output_rfq_news = gtm_render_rfq_conversion( 11029 );

gtm_assert(
	false !== strpos( $output_rfq_news, 'uonix-conversao-carrinho-newsletter-datalayer' ),
	'order-received com opt-in de newsletter emite uonix-conversao-carrinho-newsletter-datalayer'
);
gtm_assert(
	false !== strpos( $output_rfq_news, "'event': 'uonix_assinatura_newsletter'" ),
	'order-received com opt-in emite o evento uonix_assinatura_newsletter'
);
gtm_assert(
	false !== strpos( $output_rfq_news, "'transaction_id': 'uonix-rfq-news-' + orderId" ),
	'order-received emite transaction_id exclusivo para newsletter no Google Ads'
);
gtm_assert(
	false !== strpos( $output_rfq_news, 'hasMarketingConsent' ),
	'Script de newsletter condiciona a execucao e gravacao no sessionStorage ao consentimento de marketing'
);
gtm_assert(
	false !== strpos( $output_rfq_news, 'adopt-accept-marketing' ),
	'Script de newsletter escuta adopt-accept-marketing para concessao tardia de consentimento'
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

// 2.10 Adicao ao carrinho padrao nao-AJAX
class Uonix_Test_WC_Session {
	private $data = array();
	public function get( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
	public function __unset( $key ) {
		unset( $this->data[ $key ] );
	}
}

class Uonix_Test_WC {
	public $session;
	public function __construct() {
		$this->session = new Uonix_Test_WC_Session();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		global $uonix_test_wc_instance;
		if ( ! isset( $uonix_test_wc_instance ) ) {
			$uonix_test_wc_instance = new Uonix_Test_WC();
		}
		return $uonix_test_wc_instance;
	}
}

uonix_record_standard_add_to_cart( 'item_123', 456, 2 );
gtm_assert(
	! empty( WC()->session->get( 'uonix_pending_standard_add_to_cart' ) ),
	'uonix_record_standard_add_to_cart enfileira adicao na sessao do WooCommerce'
);

ob_start();
uonix_render_analytics_microconversions_footer();
$output_cart_standard = ob_get_clean();

gtm_assert(
	false !== strpos( $output_cart_standard, 'uonix-conversao-adicionar-carrinho-server-datalayer' ),
	'Footer renderiza script de adicao ao carrinho para evento padrao nao-AJAX'
);
gtm_assert(
	false !== strpos( $output_cart_standard, "'origem_conversao': 'woocommerce_add_to_cart_standard'" ),
	'Evento server-side emite origem_conversao woocommerce_add_to_cart_standard'
);
gtm_assert(
	empty( WC()->session->get( 'uonix_pending_standard_add_to_cart' ) ),
	'Sessao e limpa apos emissao do evento nao-AJAX para evitar duplicacao'
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

// -----------------------------------------------------------------------------
// Parte 4: Integridade Canônica de Políticas Legais e Prova de Mutação Readback SHA256
// -----------------------------------------------------------------------------
$cookies_file = dirname( __DIR__, 2 ) . '/docs/legal/politica-de-cookies-content.html';
gtm_assert( file_exists( $cookies_file ), 'Documento canônico politica-de-cookies-content.html existe' );
$cookies_content = file_get_contents( $cookies_file );
gtm_assert( false !== strpos( $cookies_content, '_gcl_aw' ), 'Política de cookies canônica contém _gcl_aw' );
gtm_assert( false !== strpos( $cookies_content, '_gcl_dc' ), 'Política de cookies canônica contém _gcl_dc' );
gtm_assert( false !== strpos( $cookies_content, '_gac_*' ), 'Política de cookies canônica contém _gac_*' );
gtm_assert( false !== strpos( $cookies_content, 'Conversões e Atribuição Ads' ), 'Política de cookies canônica contém seção Conversões e Atribuição Ads' );

$canonical_cookies_norm = trim( str_replace( "\r\n", "\n", $cookies_content ) );
$canonical_cookies_hash = hash( 'sha256', $canonical_cookies_norm );

// Prova de Mutação: probe que devolve apenas a chave curta '_gcl_aw'
$mock_short_probe = '_gcl_aw';
$mock_short_hash  = hash( 'sha256', $mock_short_probe );
gtm_assert(
	$mock_short_hash !== $canonical_cookies_hash,
	'Prova de Mutação: readback com apenas chave curta possui hash divergente do canônico'
);

// Validador de readback canônico fail-closed
$validate_readback = function( $content, $canonical_content ) {
	$c_norm = trim( str_replace( "\r\n", "\n", $content ) );
	$exp_norm = trim( str_replace( "\r\n", "\n", $canonical_content ) );
	return hash( 'sha256', $c_norm ) === hash( 'sha256', $exp_norm );
};

gtm_assert(
	! $validate_readback( $mock_short_probe, $cookies_content ),
	'Prova de Mutação: readback com chave curta é categoricamente rejeitado pelo validador SHA256'
);
gtm_assert(
	$validate_readback( $cookies_content, $cookies_content ),
	'Readback canônico integral é 100% verificado com paridade SHA256'
);

// Validação canônica de docs/legal/politica-de-privacidade-content.html
$privacy_file = dirname( __DIR__, 2 ) . '/docs/legal/politica-de-privacidade-content.html';
gtm_assert( file_exists( $privacy_file ), 'Documento canônico politica-de-privacidade-content.html existe' );
$privacy_content = file_get_contents( $privacy_file );
gtm_assert( false !== strpos( $privacy_content, '[uonix email_lgpd]' ), 'Política de privacidade canônica utiliza shortcode [uonix email_lgpd]' );
gtm_assert( false !== strpos( $privacy_content, 'Canal de Privacidade e Atendimento ao Titular' ), 'Política de privacidade canônica contém Canal de Privacidade e Atendimento ao Titular' );
gtm_assert( false !== strpos( $privacy_content, 'Autoridade Nacional de Proteção de Dados' ), 'Política de privacidade canônica contém Autoridade Nacional de Proteção de Dados' );
gtm_assert( false !== strpos( $privacy_content, 'Lei Geral de Proteção de Dados' ), 'Política de privacidade canônica contém Lei Geral de Proteção de Dados' );
$privacy_norm = preg_replace( '/\s+/', ' ', $privacy_content );
gtm_assert(
	false !== strpos( $privacy_norm, 'optou por não indicar formalmente um encarregado' ),
	'Política de privacidade canônica declara expressamente dispensa de encarregado formal nos termos da resolução CD/ANPD nº 2/2022'
);

// -------------------------------------------------------------------------
// Validação canônica de scripts/apply-legal-policies-production.php
// -------------------------------------------------------------------------
$apply_script_path = dirname( __DIR__ ) . '/apply-legal-policies-production.php';
gtm_assert( file_exists( $apply_script_path ), 'Script apply-legal-policies-production.php existe' );
$apply_script_code = file_get_contents( $apply_script_path );

// Validação estática de TLS estrito e fail-closed no código real de produção
gtm_assert( false !== strpos( $apply_script_code, "'sslverify' => true" ), 'apply-legal-policies-production.php exige TLS estrito (sslverify => true)' );
gtm_assert( false === strpos( $apply_script_code, "'sslverify' => false" ), 'apply-legal-policies-production.php não desativa sslverify' );
gtm_assert( false !== strpos( $apply_script_code, 'CURLOPT_SSL_VERIFYPEER, true' ), 'apply-legal-policies-production.php exige CURLOPT_SSL_VERIFYPEER => true' );
gtm_assert( false === strpos( $apply_script_code, 'CURLOPT_SSL_VERIFYPEER, false' ), 'apply-legal-policies-production.php não desativa CURLOPT_SSL_VERIFYPEER' );
gtm_assert( false !== strpos( $apply_script_code, 'CURLOPT_SSL_VERIFYHOST, 2' ), 'apply-legal-policies-production.php exige CURLOPT_SSL_VERIFYHOST => 2' );
gtm_assert( false !== strpos( $apply_script_code, '$errors += $ver_res[\'errors\']' ), 'apply-legal-policies-production.php propaga erros para $errors no verify-public' );
gtm_assert( false !== strpos( $apply_script_code, 'function uonix_verify_public_policy_response' ), 'apply-legal-policies-production.php declara a função canônica uonix_verify_public_policy_response' );

if ( ! function_exists( 'uonix_verify_public_policy_response' ) ) {
	$start_tag = "if ( ! function_exists( 'uonix_verify_public_policy_response' ) ) {";
	$end_tag   = "if ( \$UONIX_VERIFY_PUBLIC ) {";
	$pos_start = strpos( $apply_script_code, $start_tag );
	$pos_end   = strpos( $apply_script_code, $end_tag, $pos_start );
	if ( false !== $pos_start && false !== $pos_end ) {
		$func_code = substr( $apply_script_code, $pos_start, $pos_end - $pos_start );
		eval( $func_code );
	}
}
gtm_assert( function_exists( 'uonix_verify_public_policy_response' ), 'Função canônica uonix_verify_public_policy_response carregada a partir do arquivo real' );

// Testes funcionais da função canônica do arquivo de produção
$res_timeout = uonix_verify_public_policy_response( 0, '', array( '_gcl_aw' ), 'Connection timed out' );
gtm_assert( ! $res_timeout['success'] && $res_timeout['errors'] > 0, 'Código real: verify-public com timeout/inacessível resulta em erro (fail-closed)' );

$res_http_500 = uonix_verify_public_policy_response( 500, 'Internal Server Error', array( '_gcl_aw' ) );
gtm_assert( ! $res_http_500['success'] && $res_http_500['errors'] > 0, 'Código real: verify-public com HTTP 500 resulta em erro (fail-closed)' );

$res_missing_term = uonix_verify_public_policy_response( 200, '<html>Sem cookies ads</html>', array( '_gcl_aw', 'Conversões e Atribuição Ads' ) );
gtm_assert( ! $res_missing_term['success'] && $res_missing_term['errors'] === 2, 'Código real: verify-public com termos ausentes resulta em erro não-zero' );

$res_valid_public = uonix_verify_public_policy_response( 200, '<html>_gcl_aw e Conversões e Atribuição Ads</html>', array( '_gcl_aw', 'Conversões e Atribuição Ads' ) );
gtm_assert( $res_valid_public['success'] && 0 === $res_valid_public['errors'], 'Código real: verify-public com HTTP 200 e termos canônicos é aprovado' );

if ( $failures > 0 ) {
	fwrite( STDERR, "\nTotal de falhas no contrato GTM e conversoes: {$failures}\n" );
	exit( 1 );
}

printf( "\nPASS: todos os contratos de GTM, AdOpt, equivalencia PHP e conversao estrita passaram!\n" );
