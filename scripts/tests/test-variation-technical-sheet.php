<?php
/**
 * Contratos da ficha técnica estruturada por variação.
 *
 * Executado sem carregar WordPress para manter o contrato rápido e determinístico.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}

$GLOBALS['vts_assertions'] = 0;

function vts_fail( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

function vts_assert_same( $expected, $actual, $message ) {
	++$GLOBALS['vts_assertions'];
	if ( $expected !== $actual ) {
		vts_fail(
			$message . '; esperado ' . var_export( $expected, true ) .
			', recebido ' . var_export( $actual, true )
		);
	}
}

function vts_assert_true( $actual, $message ) {
	vts_assert_same( true, $actual, $message );
}

function vts_assert_false( $actual, $message ) {
	vts_assert_same( false, $actual, $message );
}

function vts_assert_contains( $needle, $haystack, $message ) {
	++$GLOBALS['vts_assertions'];
	if ( false === strpos( (string) $haystack, (string) $needle ) ) {
		vts_fail( $message . '; trecho ausente: ' . var_export( $needle, true ) );
	}
}

function vts_assert_failure_contract( $expected_code, $actual, $message ) {
	vts_assert_same(
		array( 'ok', 'code', 'message', 'action', 'sheet' ),
		array_keys( $actual ),
		$message . ': chaves estáveis'
	);
	vts_assert_same( false, $actual['ok'], $message . ': ok falso' );
	vts_assert_same( $expected_code, $actual['code'], $message . ': código estável' );
	vts_assert_true( is_string( $actual['message'] ) && '' !== $actual['message'], $message . ': mensagem legível' );
	vts_assert_same( null, $actual['action'], $message . ': ação nula' );
	vts_assert_same( null, $actual['sheet'], $message . ': ficha nula' );
}

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		vts_fail( sprintf( 'aviso PHP inesperado (%d) em %s:%d: %s', $severity, $file, $line, $message ) );
	}
);

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

function wp_check_invalid_utf8( $text, $strip = false ) {
	$text = (string) $text;
	if ( 1 === preg_match( '//u', $text ) ) {
		return $text;
	}
	return $strip && function_exists( 'iconv' ) ? (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) : '';
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = wp_check_invalid_utf8( (string) $text );
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( (string) $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( (string) $text );
}

function sanitize_text_field( $text ) {
	$text = wp_check_invalid_utf8( (string) $text );
	$text = wp_strip_all_tags( $text, false );
	$text = preg_replace( '/[\r\n	 ]+/', ' ', $text );
	return trim( (string) $text );
}

$GLOBALS['vts_capture_loader'] = false;
$GLOBALS['vts_loader_calls']   = array();

function uonix_mu_require_files( $base_dir, $files, $scope ) {
	if ( $GLOBALS['vts_capture_loader'] ) {
		$GLOBALS['vts_loader_calls'][] = array(
			'base_dir' => $base_dir,
			'files'    => $files,
			'scope'    => $scope,
		);
		return;
	}

	foreach ( $files as $file ) {
		require_once rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR . $file;
	}
}

$repo_root = dirname( __DIR__, 2 );

$GLOBALS['vts_capture_loader'] = true;
require $repo_root . '/mu-plugins/uonix-woocommerce/module.php';
$GLOBALS['vts_capture_loader'] = false;

vts_assert_same( 1, count( $GLOBALS['vts_loader_calls'] ), 'módulo registra uma única chamada ao loader' );
$loader_call = $GLOBALS['vts_loader_calls'][0];
vts_assert_same( $repo_root . '/mu-plugins/uonix-woocommerce', $loader_call['base_dir'], 'loader usa o diretório real do módulo' );
vts_assert_same( 'uonix-woocommerce', $loader_call['scope'], 'loader usa o escopo esperado' );
$loader_position_20 = array_search( '20-catalogo-titulos-produtos.php', $loader_call['files'], true );
$loader_position_22 = array_search( '22-ficha-tecnica-variacao.php', $loader_call['files'], true );
vts_assert_true( false !== $loader_position_20, 'loader mantém o módulo 20' );
vts_assert_true( false !== $loader_position_22, 'loader registra o bootstrap 22 em execução' );
vts_assert_true( $loader_position_20 < $loader_position_22, 'loader mantém o módulo 22 depois do módulo 20' );

require_once $repo_root . '/mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php';

function vts_valid_sheet() {
	return array(
		'version'  => 1,
		'title'    => 'Dimensões (mm)',
		'sections' => array(
			array(
				'title'  => '',
				'layout' => 'compact',
				'items'  => array(
					array(
						'label' => 'A',
						'value' => '37',
					),
				),
			),
		),
	);
}

$valid = Uonix_VTS_Schema::normalize_envelope(
	wp_json_encode(
		array(
			'action' => 'upsert',
			'sheet'  => vts_valid_sheet(),
		)
	)
);

vts_assert_same( true, $valid['ok'], 'envelope upsert válido' );
vts_assert_same( 'upsert', $valid['action'], 'ação upsert preservada' );
vts_assert_same( '37', $valid['sheet']['sections'][0]['items'][0]['value'], 'valor preservado' );

$delete = Uonix_VTS_Schema::normalize_envelope( '{"action":"delete"}' );
vts_assert_same( true, $delete['ok'], 'delete explícito aceito' );
vts_assert_same( 'delete', $delete['action'], 'ação delete preservada' );
vts_assert_same( null, $delete['sheet'], 'delete não transporta ficha' );

$invalid = Uonix_VTS_Schema::normalize_envelope( '' );
vts_assert_failure_contract( 'empty_json', $invalid, 'JSON vazio recusado com contrato completo' );

$partial = Uonix_VTS_Schema::normalize_envelope(
	'{"action":"upsert","sheet":{"version":1,"title":"Ficha","sections":[{"title":"","layout":"compact","items":[{"label":"A","value":""}]}]}}'
);
vts_assert_same( false, $partial['ok'], 'item parcial recusa a ficha inteira' );
vts_assert_same( 'partial_item', $partial['code'], 'item parcial possui código estável' );

vts_assert_same( 1, Uonix_VTS_Schema::VERSION, 'versão do esquema fixada' );
vts_assert_same( 262144, Uonix_VTS_Schema::MAX_PAYLOAD_BYTES, 'teto de payload fixado' );
vts_assert_same( 50, Uonix_VTS_Schema::MAX_SECTIONS, 'teto de seções fixado' );
vts_assert_same( 100, Uonix_VTS_Schema::MAX_ITEMS, 'teto de itens fixado' );
vts_assert_same( 160, Uonix_VTS_Schema::MAX_TITLE_CHARS, 'teto de título fixado' );
vts_assert_same( 120, Uonix_VTS_Schema::MAX_SECTION_CHARS, 'teto de título de seção fixado' );
vts_assert_same( 120, Uonix_VTS_Schema::MAX_LABEL_CHARS, 'teto de rótulo fixado' );
vts_assert_same( 500, Uonix_VTS_Schema::MAX_VALUE_CHARS, 'teto de valor fixado' );
vts_assert_same( '_uonix_variation_technical_sheet', Uonix_VTS_Schema::META_KEY, 'meta principal fixado' );
vts_assert_same( '_uonix_variation_technical_sheet_legacy_backup_v1', Uonix_VTS_Schema::BACKUP_META_KEY, 'meta de backup fixado' );

$invalid_json = Uonix_VTS_Schema::normalize_envelope( '{invalid' );
vts_assert_same( 'invalid_json', $invalid_json['code'], 'JSON inválido recusado' );

$invalid_action = Uonix_VTS_Schema::normalize_envelope( '{"action":"noop"}' );
vts_assert_same( 'invalid_action', $invalid_action['code'], 'ação desconhecida recusada' );

$composite_action = Uonix_VTS_Schema::normalize_envelope( '{"action":[],"sheet":{}}' );
vts_assert_same( 'invalid_action', $composite_action['code'], 'ação composta recusada sem conversão implícita' );

$too_large = Uonix_VTS_Schema::normalize_envelope( str_repeat( 'x', Uonix_VTS_Schema::MAX_PAYLOAD_BYTES + 1 ) );
vts_assert_same( 'payload_too_large', $too_large['code'], 'payload acima do teto recusado antes do decode' );

$max_payload_section = array(
	'title'  => '',
	'layout' => 'compact',
	'items'  => array_fill(
		0,
		Uonix_VTS_Schema::MAX_ITEMS,
		array( 'label' => 'A', 'value' => 'v' )
	),
);
$max_payload_data = array(
	'action' => 'upsert',
	'sheet'  => array(
		'version'  => Uonix_VTS_Schema::VERSION,
		'title'    => 'Ficha no teto',
		'sections' => array_fill( 0, 6, $max_payload_section ),
	),
);
$max_payload           = wp_json_encode( $max_payload_data );
$max_payload_remaining = Uonix_VTS_Schema::MAX_PAYLOAD_BYTES - strlen( $max_payload );
vts_assert_true( 0 <= $max_payload_remaining, 'fixture válida cabe antes do preenchimento até o teto' );
foreach ( $max_payload_data['sheet']['sections'] as &$max_payload_section_ref ) {
	foreach ( $max_payload_section_ref['items'] as &$max_payload_item_ref ) {
		$max_payload_add = min(
			$max_payload_remaining,
			Uonix_VTS_Schema::MAX_VALUE_CHARS - strlen( $max_payload_item_ref['value'] )
		);
		$max_payload_item_ref['value'] .= str_repeat( 'x', $max_payload_add );
		$max_payload_remaining         -= $max_payload_add;
		if ( 0 === $max_payload_remaining ) {
			break 2;
		}
	}
}
unset( $max_payload_item_ref, $max_payload_section_ref );
vts_assert_same( 0, $max_payload_remaining, 'fixture válida possui capacidade para ocupar o teto' );
$max_payload = wp_json_encode( $max_payload_data );
vts_assert_same( Uonix_VTS_Schema::MAX_PAYLOAD_BYTES, strlen( $max_payload ), 'fixture válida ocupa exatamente o teto de bytes' );
vts_assert_same( true, Uonix_VTS_Schema::normalize_envelope( $max_payload )['ok'], 'payload válido exatamente no teto é aceito' );

$max_title          = vts_valid_sheet();
$max_title['title'] = str_repeat( 'á', Uonix_VTS_Schema::MAX_TITLE_CHARS );
vts_assert_same( true, Uonix_VTS_Schema::normalize_sheet( $max_title )['ok'], 'título exatamente no teto multibyte é aceito' );

$max_sections             = vts_valid_sheet();
$max_sections['sections'] = array_fill( 0, Uonix_VTS_Schema::MAX_SECTIONS, $max_sections['sections'][0] );
$max_sections_result      = Uonix_VTS_Schema::normalize_sheet( $max_sections );
vts_assert_same( true, $max_sections_result['ok'], 'quantidade de seções exatamente no teto é aceita' );
vts_assert_same( Uonix_VTS_Schema::MAX_SECTIONS, count( $max_sections_result['sheet']['sections'] ), 'todas as seções no teto são preservadas' );

$max_section_title                                  = vts_valid_sheet();
$max_section_title['sections'][0]['title']          = str_repeat( 's', Uonix_VTS_Schema::MAX_SECTION_CHARS );
vts_assert_same( true, Uonix_VTS_Schema::normalize_sheet( $max_section_title )['ok'], 'título de seção exatamente no teto é aceito' );

$max_items                                  = vts_valid_sheet();
$max_items['sections'][0]['items']          = array_fill(
	0,
	Uonix_VTS_Schema::MAX_ITEMS,
	array( 'label' => 'A', 'value' => '37' )
);
$max_items_result = Uonix_VTS_Schema::normalize_sheet( $max_items );
vts_assert_same( true, $max_items_result['ok'], 'quantidade de itens exatamente no teto é aceita' );
vts_assert_same( Uonix_VTS_Schema::MAX_ITEMS, count( $max_items_result['sheet']['sections'][0]['items'] ), 'todos os itens no teto são preservados' );

$max_label                                        = vts_valid_sheet();
$max_label['sections'][0]['items'][0]['label']    = str_repeat( 'l', Uonix_VTS_Schema::MAX_LABEL_CHARS );
vts_assert_same( true, Uonix_VTS_Schema::normalize_sheet( $max_label )['ok'], 'rótulo exatamente no teto é aceito' );

$max_value                                        = vts_valid_sheet();
$max_value['sections'][0]['items'][0]['value']    = str_repeat( 'v', Uonix_VTS_Schema::MAX_VALUE_CHARS );
vts_assert_same( true, Uonix_VTS_Schema::normalize_sheet( $max_value )['ok'], 'valor exatamente no teto é aceito' );

$detailed                                  = vts_valid_sheet();
$detailed['sections'][0]['layout']         = 'detailed';
$detailed_result                           = Uonix_VTS_Schema::normalize_sheet( $detailed );
vts_assert_same( true, $detailed_result['ok'], 'layout detalhado aceito' );
vts_assert_same( 'detailed', $detailed_result['sheet']['sections'][0]['layout'], 'layout detalhado preservado' );

$wrong_version             = vts_valid_sheet();
$wrong_version['version'] = 2;
vts_assert_same( 'invalid_version', Uonix_VTS_Schema::normalize_sheet( $wrong_version )['code'], 'versão desconhecida recusada' );

$string_version             = vts_valid_sheet();
$string_version['version'] = '1';
vts_assert_same( 'invalid_version', Uonix_VTS_Schema::normalize_sheet( $string_version )['code'], 'versão textual não substitui o inteiro do esquema' );

$missing_title          = vts_valid_sheet();
$missing_title['title'] = '';
vts_assert_same( 'missing_title', Uonix_VTS_Schema::normalize_sheet( $missing_title )['code'], 'título obrigatório' );

$too_many_sections             = vts_valid_sheet();
$too_many_sections['sections'] = array_fill( 0, Uonix_VTS_Schema::MAX_SECTIONS + 1, $too_many_sections['sections'][0] );
vts_assert_same( 'too_many_sections', Uonix_VTS_Schema::normalize_sheet( $too_many_sections )['code'], 'excesso de seções recusado' );

$too_many_items                                  = vts_valid_sheet();
$too_many_items['sections'][0]['items']          = array_fill(
	0,
	Uonix_VTS_Schema::MAX_ITEMS + 1,
	array( 'label' => 'A', 'value' => '37' )
);
vts_assert_same( 'too_many_items', Uonix_VTS_Schema::normalize_sheet( $too_many_items )['code'], 'excesso de itens recusado' );

$unknown_layout                                  = vts_valid_sheet();
$unknown_layout['sections'][0]['layout']         = 'wide';
vts_assert_same( 'invalid_layout', Uonix_VTS_Schema::normalize_sheet( $unknown_layout )['code'], 'layout desconhecido recusado' );

$too_long_title          = vts_valid_sheet();
$too_long_title['title'] = str_repeat( 'á', Uonix_VTS_Schema::MAX_TITLE_CHARS + 1 );
vts_assert_same( 'title_too_long', Uonix_VTS_Schema::normalize_sheet( $too_long_title )['code'], 'título multibyte acima do teto recusado' );

$too_long_section                                  = vts_valid_sheet();
$too_long_section['sections'][0]['title']          = str_repeat( 's', Uonix_VTS_Schema::MAX_SECTION_CHARS + 1 );
vts_assert_same( 'section_title_too_long', Uonix_VTS_Schema::normalize_sheet( $too_long_section )['code'], 'título de seção acima do teto recusado' );

$too_long_label                                        = vts_valid_sheet();
$too_long_label['sections'][0]['items'][0]['label']    = str_repeat( 'l', Uonix_VTS_Schema::MAX_LABEL_CHARS + 1 );
vts_assert_same( 'label_too_long', Uonix_VTS_Schema::normalize_sheet( $too_long_label )['code'], 'rótulo acima do teto recusado' );

$too_long_value                                        = vts_valid_sheet();
$too_long_value['sections'][0]['items'][0]['value']    = str_repeat( 'v', Uonix_VTS_Schema::MAX_VALUE_CHARS + 1 );
vts_assert_same( 'value_too_long', Uonix_VTS_Schema::normalize_sheet( $too_long_value )['code'], 'valor acima do teto recusado' );

$empty_sheet             = vts_valid_sheet();
$empty_sheet['sections'] = array();
vts_assert_same( 'empty_sections', Uonix_VTS_Schema::normalize_sheet( $empty_sheet )['code'], 'upsert sem seção válida recusado' );

$sanitized = vts_valid_sheet();
$sanitized['title'] = " <b>Ficha</b>\x07 técnica ";
$sanitized['sections'][] = array(
	'title'  => '',
	'layout' => 'compact',
	'items'  => array(
		array( 'label' => '', 'value' => '' ),
	),
);
$sanitized['sections'][0]['items'][] = array( 'label' => '', 'value' => '' );
$sanitized['sections'][0]['items'][] = array( 'label' => '<i>B</i>', 'value' => " 4\t2 " );
$normalized = Uonix_VTS_Schema::normalize_sheet( $sanitized );
vts_assert_same( true, $normalized['ok'], 'itens e seções totalmente vazios são descartados' );
vts_assert_same( 'Ficha técnica', $normalized['sheet']['title'], 'tags e controles removidos do título' );
vts_assert_same( 1, count( $normalized['sheet']['sections'] ), 'seção totalmente vazia descartada' );
vts_assert_same( 2, count( $normalized['sheet']['sections'][0]['items'] ), 'item totalmente vazio descartado' );
vts_assert_same( 'B', $normalized['sheet']['sections'][0]['items'][1]['label'], 'tags removidas do rótulo' );
vts_assert_same( '4 2', $normalized['sheet']['sections'][0]['items'][1]['value'], 'controle removido e espaço normalizado' );

$partial_reverse                                        = vts_valid_sheet();
$partial_reverse['sections'][0]['items'][0]['label']    = '';
vts_assert_same( 'partial_item', Uonix_VTS_Schema::normalize_sheet( $partial_reverse )['code'], 'item parcial sem rótulo recusado' );

printf( "PASS: contratos da ficha técnica por variação. (%d asserções)\n", $GLOBALS['vts_assertions'] );
