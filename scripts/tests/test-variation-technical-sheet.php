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

function vts_assert_not_contains( $needle, $haystack, $message ) {
	++$GLOBALS['vts_assertions'];
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		vts_fail( $message . '; trecho inesperado: ' . var_export( $needle, true ) );
	}
}

function vts_assert_hook( $registry, $hook, $callback, $priority, $accepted_args, $message ) {
	++$GLOBALS['vts_assertions'];
	foreach ( $GLOBALS[ $registry ][ $hook ] ?? array() as $registered ) {
		if (
			$registered['callback'] === $callback &&
			$registered['priority'] === $priority &&
			$registered['accepted_args'] === $accepted_args
		) {
			return;
		}
	}
	vts_fail( $message . '; hook, prioridade ou accepted_args não encontrado' );
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

$GLOBALS['vts_actions']           = array();
$GLOBALS['vts_filters']           = array();
$GLOBALS['vts_enqueued_styles']   = array();
$GLOBALS['vts_enqueued_scripts']  = array();
$GLOBALS['vts_localized_scripts'] = array();
$GLOBALS['vts_current_screen']    = null;
$GLOBALS['vts_current_post_id']   = 0;
$GLOBALS['vts_escaped_html']      = array();
$GLOBALS['vts_is_product']        = false;
$GLOBALS['vts_editable_posts']  = array();
$GLOBALS['vts_products']        = array();
$GLOBALS['wc_meta_box_errors']  = array();
$GLOBALS['vts_terms']           = array(
	'pa_tipo'     => array( 'pesado' => 'Pesado' ),
	'pa_material' => array( 'inox-316' => 'Inox 316' ),
	'pa_bitola'   => array( '5-16' => '5/16"' ),
);

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['vts_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['vts_filters'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function esc_html( $text ) {
	$GLOBALS['vts_escaped_html'][] = (string) $text;
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function current_user_can( $capability, $object_id = 0 ) {
	return 'edit_post' === $capability && ! empty( $GLOBALS['vts_editable_posts'][ absint( $object_id ) ] );
}

function wc_get_product( $product_id ) {
	return $GLOBALS['vts_products'][ absint( $product_id ) ] ?? false;
}

final class WC_Admin_Meta_Boxes {
	public static function add_error( $message ) {
		$GLOBALS['wc_meta_box_errors'][] = (string) $message;
	}
}

function wc_attribute_label( $name, $product = null ) {
	$labels = array(
		'pa_tipo'             => 'Tipo',
		'pa_material'         => 'Material',
		'pa_bitola'           => 'Bitola',
		'acabamento'          => 'Acabamento',
		'acabamento-especial' => 'Acabamento & brilho',
	);
	return $labels[ $name ] ?? ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $name ) );
}

function taxonomy_exists( $taxonomy ) {
	return isset( $GLOBALS['vts_terms'][ $taxonomy ] );
}

function get_term_by( $field, $value, $taxonomy ) {
	if ( 'slug' !== $field || ! isset( $GLOBALS['vts_terms'][ $taxonomy ][ $value ] ) ) {
		return false;
	}
	return (object) array( 'name' => $GLOBALS['vts_terms'][ $taxonomy ][ $value ] );
}

function is_wp_error( $value ) {
	return false;
}

function is_product() {
	return $GLOBALS['vts_is_product'];
}

function wp_enqueue_style( $handle, $source = '', $dependencies = array(), $version = false, $media = 'all' ) {
	$GLOBALS['vts_enqueued_styles'][] = array(
		'handle'       => $handle,
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
		'media'        => $media,
	);
}

function wp_enqueue_script( $handle, $source = '', $dependencies = array(), $version = false, $in_footer = false ) {
	$GLOBALS['vts_enqueued_scripts'][] = array(
		'handle'       => $handle,
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
		'in_footer'    => $in_footer,
	);
}

function wp_localize_script( $handle, $object_name, $data ) {
	$GLOBALS['vts_localized_scripts'][] = array(
		'handle'      => $handle,
		'object_name' => $object_name,
		'data'        => $data,
	);
	return true;
}

function get_current_screen() {
	return $GLOBALS['vts_current_screen'];
}

function get_the_ID() {
	return $GLOBALS['vts_current_post_id'];
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
define( 'UONIX_MU_PATH', $repo_root . '/mu-plugins/' );
define( 'UONIX_MU_URL', 'https://example.test/wp-content/mu-plugins/' );

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

final class VTS_Fake_Render_Variation {
	private $attributes;
	private $meta;
	private $id;

	public function __construct( $id, array $attributes, $sheet = null ) {
		$this->id         = $id;
		$this->attributes = $attributes;
		$this->meta       = null === $sheet ? array() : array( Uonix_VTS_Schema::META_KEY => $sheet );
	}

	public function get_attributes() {
		return $this->attributes;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_id() {
		return $this->id;
	}
}

final class VTS_Fake_Admin_Variation {
	public $meta;
	public $save_calls = 0;
	private $attributes;
	private $id;
	private $parent_id;

	public function __construct( $id, $parent_id, array $attributes = array(), array $meta = array() ) {
		$this->id         = $id;
		$this->parent_id  = $parent_id;
		$this->attributes = $attributes;
		$this->meta       = $meta;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function get_attributes() {
		return $this->attributes;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save() {
		++$this->save_calls;
	}
}

vts_assert_hook(
	'vts_actions',
	'woocommerce_product_after_variable_attributes',
	array( 'Uonix_VTS_Admin', 'render_editor' ),
	10,
	3,
	'editor administrativo registrado na prioridade 10 com três argumentos'
);
vts_assert_hook(
	'vts_actions',
	'woocommerce_admin_process_variation_object',
	array( 'Uonix_VTS_Admin', 'save_variation' ),
	10,
	2,
	'persistência administrativa registrada na prioridade 10 com dois argumentos'
);
vts_assert_hook(
	'vts_actions',
	'admin_enqueue_scripts',
	array( 'Uonix_VTS_Admin', 'enqueue_assets' ),
	10,
	1,
	'assets administrativos registrados na prioridade 10 com um argumento'
);

$GLOBALS['vts_current_screen']  = (object) array( 'post_type' => 'product' );
$GLOBALS['vts_current_post_id'] = 10382;
Uonix_VTS_Admin::enqueue_assets( 'plugins.php' );
vts_assert_same( array(), $GLOBALS['vts_enqueued_scripts'], 'script não carrega fora de post.php e post-new.php' );
vts_assert_same( array(), $GLOBALS['vts_enqueued_styles'], 'CSS administrativo não carrega fora de post.php e post-new.php' );

$GLOBALS['vts_current_screen'] = (object) array( 'post_type' => 'post' );
Uonix_VTS_Admin::enqueue_assets( 'post.php' );
vts_assert_same( array(), $GLOBALS['vts_enqueued_scripts'], 'script não carrega em outro post type' );
vts_assert_same( array(), $GLOBALS['vts_localized_scripts'], 'configuração não é exposta em outro post type' );

$GLOBALS['vts_current_screen'] = null;
Uonix_VTS_Admin::enqueue_assets( 'post.php' );
vts_assert_same( array(), $GLOBALS['vts_enqueued_scripts'], 'script não carrega sem tela administrativa válida' );

$GLOBALS['vts_current_screen'] = (object) array( 'post_type' => 'product' );
Uonix_VTS_Admin::enqueue_assets( 'post.php' );
vts_assert_same( 1, count( $GLOBALS['vts_enqueued_scripts'] ), 'script administrativo carrega uma vez na edição de produto' );
vts_assert_same( 1, count( $GLOBALS['vts_enqueued_styles'] ), 'CSS administrativo carrega uma vez na edição de produto' );
vts_assert_same( 1, count( $GLOBALS['vts_localized_scripts'] ), 'configuração administrativa é localizada uma única vez' );
$admin_script = $GLOBALS['vts_enqueued_scripts'][0];
$admin_style  = $GLOBALS['vts_enqueued_styles'][0];
$admin_config = $GLOBALS['vts_localized_scripts'][0];
vts_assert_same( 'uonix-vts-admin', $admin_script['handle'], 'handle do script administrativo é estável' );
vts_assert_contains( 'uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js', $admin_script['source'], 'URL do script aponta para o asset próprio' );
vts_assert_same( array( 'jquery', 'jquery-ui-sortable' ), $admin_script['dependencies'], 'script declara jQuery e sortable como dependências' );
vts_assert_same( true, $admin_script['in_footer'], 'script administrativo carrega no rodapé' );
vts_assert_same( (string) filemtime( UONIX_MU_PATH . 'uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js' ), $admin_script['version'], 'script usa filemtime como versão' );
vts_assert_same( 'uonix-vts-admin', $admin_style['handle'], 'handle do CSS administrativo é estável' );
vts_assert_contains( 'uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css', $admin_style['source'], 'URL do CSS aponta para o asset próprio' );
vts_assert_same( (string) filemtime( UONIX_MU_PATH . 'uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css' ), $admin_style['version'], 'CSS administrativo usa filemtime como versão' );
vts_assert_same( 'uonix-vts-admin', $admin_config['handle'], 'configuração é associada ao script correto' );
vts_assert_same( 'uonixVtsAdmin', $admin_config['object_name'], 'objeto JavaScript global tem nome fixo' );
vts_assert_same( 10382, $admin_config['data']['parentId'], 'configuração inclui o produto pai atual' );
vts_assert_same( 'Remover a ficha técnica desta variação ao salvar?', $admin_config['data']['strings']['removeConfirm'], 'confirmação de remoção é localizada' );
vts_assert_same( 'Não foi possível carregar a ficha técnica salva.', $admin_config['data']['strings']['payloadError'], 'erro de hidratação é localizado' );

$GLOBALS['vts_enqueued_scripts']  = array();
$GLOBALS['vts_enqueued_styles']   = array();
$GLOBALS['vts_localized_scripts'] = array();
Uonix_VTS_Admin::enqueue_assets( 'post-new.php' );
vts_assert_same( 1, count( $GLOBALS['vts_enqueued_scripts'] ), 'script também carrega na criação de produto' );
$GLOBALS['vts_enqueued_scripts']  = array();
$GLOBALS['vts_enqueued_styles']   = array();
$GLOBALS['vts_localized_scripts'] = array();

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

$render_sheet = vts_valid_sheet();
$render_sheet['sections'][] = array(
	'title'  => 'Informações',
	'layout' => 'detailed',
	'items'  => array(
		array( 'label' => 'Peso', 'value' => '0,059 Kg' ),
	),
);
$variation = new VTS_Fake_Render_Variation(
	10410,
	array(
		'pa_material' => 'inox-316',
		'pa_bitola'   => '5-16',
		'pa_tipo'     => 'pesado',
	),
	$render_sheet
);

$pairs = Uonix_VTS_Renderer::attribute_pairs( $variation );
vts_assert_same(
	array(
		array( 'label' => 'Modelo', 'value' => 'Pesado' ),
		array( 'label' => 'Material', 'value' => 'Inox 316' ),
		array( 'label' => 'Pol.', 'value' => '5/16"' ),
	),
	$pairs,
	'subtítulo usa aliases e valores oficiais'
);

$html = Uonix_VTS_Renderer::render( $render_sheet, $variation );
vts_assert_contains( 'class="uonix-vts"', $html, 'wrapper novo presente' );
vts_assert_contains( 'Dimensões (mm)', $html, 'título renderizado' );
vts_assert_contains( 'Modelo: Pesado · Material: Inox 316 · Pol.: 5/16&quot;', $html, 'subtítulo oficial' );
vts_assert_contains( 'uonix-vts__grid--compact', $html, 'seção compacta renderizada' );
vts_assert_contains( 'uonix-vts__grid--detailed', $html, 'seção detalhada renderizada' );
vts_assert_contains( 'uonix-vts__section-title">Informações', $html, 'título preenchido renderizado' );
vts_assert_same( 1, substr_count( $html, 'uonix-vts__section-title' ), 'título vazio não cria marcação extra' );
$escaped_counts = array_count_values( $GLOBALS['vts_escaped_html'] );
vts_assert_same( 2, $escaped_counts['compact'] ?? 0, 'layout compacto passa por esc_html nos dois atributos de classe' );
vts_assert_same( 2, $escaped_counts['detailed'] ?? 0, 'layout detalhado passa por esc_html nos dois atributos de classe' );

$xss_sheet = vts_valid_sheet();
$xss_sheet['title'] = 'Ficha <script>alert(1)</script>';
$xss_sheet['sections'][0]['items'][0]['value'] = '<script>alert(2)</script>37';
vts_assert_not_contains( '<script>', Uonix_VTS_Renderer::render( $xss_sheet, $variation ), 'script nunca executável' );

$escaped_sheet                                        = vts_valid_sheet();
$escaped_sheet['title']                               = 'Ficha & "geral"';
$escaped_sheet['sections'][0]['title']                = 'Seção & "detalhes"';
$escaped_sheet['sections'][0]['items'][0]['label']    = 'A & "B"';
$escaped_sheet['sections'][0]['items'][0]['value']    = '37 & "38"';
$escaped_sheet_html                                   = Uonix_VTS_Renderer::render( $escaped_sheet, $variation );
vts_assert_contains( 'Ficha &amp; &quot;geral&quot;', $escaped_sheet_html, 'título persistido é escapado no sink' );
vts_assert_contains( 'Seção &amp; &quot;detalhes&quot;', $escaped_sheet_html, 'título de seção persistido é escapado no sink' );
vts_assert_contains( 'A &amp; &quot;B&quot;', $escaped_sheet_html, 'rótulo persistido é escapado no sink' );
vts_assert_contains( '37 &amp; &quot;38&quot;', $escaped_sheet_html, 'valor persistido é escapado no sink' );

$data = Uonix_VTS_Renderer::append_to_variation_data(
	array( 'variation_description' => '<p>Descrição livre</p>' ),
	null,
	$variation
);
vts_assert_contains( '<p>Descrição livre</p>', $data['variation_description'], 'descrição livre preservada' );
vts_assert_true(
	strpos( $data['variation_description'], '<p>Descrição livre</p>' ) < strpos( $data['variation_description'], 'uonix-vts' ),
	'ficha anexada depois da descrição'
);

$without_sheet = new VTS_Fake_Render_Variation( 10411, array( 'pa_tipo' => 'pesado' ) );
$untouched     = array( 'variation_description' => '<p>Sem ficha</p>', 'custom' => 'byte-for-byte' );
vts_assert_same(
	$untouched,
	Uonix_VTS_Renderer::append_to_variation_data( $untouched, null, $without_sheet ),
	'meta ausente deixa todo o payload inalterado'
);

vts_assert_hook(
	'vts_filters',
	'woocommerce_available_variation',
	array( 'Uonix_VTS_Renderer', 'append_to_variation_data' ),
	10,
	3,
	'filtro frontend registrado na prioridade 10 com três argumentos'
);
vts_assert_hook(
	'vts_actions',
	'wp_enqueue_scripts',
	array( 'Uonix_VTS_Renderer', 'enqueue_frontend_assets' ),
	10,
	0,
	'asset frontend registrado na prioridade 10 sem argumentos'
);

Uonix_VTS_Renderer::enqueue_frontend_assets();
vts_assert_same( array(), $GLOBALS['vts_enqueued_styles'], 'CSS não carrega fora de produto' );
$GLOBALS['vts_is_product'] = true;
Uonix_VTS_Renderer::enqueue_frontend_assets();
vts_assert_same( 1, count( $GLOBALS['vts_enqueued_styles'] ), 'CSS carrega uma vez em produto' );
vts_assert_same( 'uonix-vts', $GLOBALS['vts_enqueued_styles'][0]['handle'], 'handle do CSS é estável' );
vts_assert_contains( 'uonix-woocommerce/assets/css/ficha-tecnica-variacao.css', $GLOBALS['vts_enqueued_styles'][0]['source'], 'URL aponta para asset versionado' );
vts_assert_same(
	(string) filemtime( UONIX_MU_PATH . 'uonix-woocommerce/assets/css/ficha-tecnica-variacao.css' ),
	$GLOBALS['vts_enqueued_styles'][0]['version'],
	'versão do CSS usa filemtime'
);

$extra_variation = new VTS_Fake_Render_Variation(
	10412,
	array(
		'acabamento'  => 'Escovado',
		'pa_material' => 'inox-316',
		'pa_tipo'     => 'pesado',
	)
);
$extra_pairs = Uonix_VTS_Renderer::attribute_pairs( $extra_variation );
vts_assert_same( 'Modelo', $extra_pairs[0]['label'], 'alias conhecido mantém precedência' );
vts_assert_same( 'Material', $extra_pairs[1]['label'], 'segundo alias mantém precedência' );
vts_assert_same( array( 'label' => 'Acabamento', 'value' => 'Escovado' ), $extra_pairs[2], 'atributo restante é anexado depois dos aliases' );

$empty_attribute_variation = new VTS_Fake_Render_Variation(
	10415,
	array(
		'pa_tipo'     => 'pesado',
		'pa_material' => '',
		'pa_bitola'   => '',
		'acabamento'  => '',
	)
);
vts_assert_same(
	array( array( 'label' => 'Modelo', 'value' => 'Pesado' ) ),
	Uonix_VTS_Renderer::attribute_pairs( $empty_attribute_variation ),
	'atributos oficiais e adicionais vazios são omitidos'
);

$escaped_attribute_variation = new VTS_Fake_Render_Variation(
	10416,
	array( 'acabamento-especial' => 'Escovado & polido' )
);
$escaped_attribute_html = Uonix_VTS_Renderer::render( vts_valid_sheet(), $escaped_attribute_variation );
vts_assert_contains(
	'Acabamento &amp; brilho: Escovado &amp; polido',
	$escaped_attribute_html,
	'rótulo e valor de atributo são escapados nos sinks'
);

$hostile_variation = new VTS_Fake_Render_Variation( 10413, array( 'acabamento' => '<script>alert(3)</script>' ) );
$hostile_html      = Uonix_VTS_Renderer::render( vts_valid_sheet(), $hostile_variation );
vts_assert_not_contains( '<script>', $hostile_html, 'atributo hostil nunca vira marcação executável' );
vts_assert_contains( 'Acabamento: &lt;script&gt;alert(3)&lt;/script&gt;', $hostile_html, 'atributo hostil é escapado como texto' );

$invalid_variation = new VTS_Fake_Render_Variation( 10414, array(), array( 'version' => 2 ) );
vts_assert_same(
	$untouched,
	Uonix_VTS_Renderer::append_to_variation_data( $untouched, null, $invalid_variation ),
	'meta inválido deixa todo o payload inalterado'
);

$admin_parent_id = 9900;
$GLOBALS['vts_editable_posts'][ $admin_parent_id ] = true;
$admin_sheet = vts_valid_sheet();
$admin_variation = new VTS_Fake_Admin_Variation(
	10420,
	$admin_parent_id,
	array(
		'pa_material' => 'inox-316',
		'pa_bitola'   => '5-16',
		'pa_tipo'     => 'pesado',
	),
	array( Uonix_VTS_Schema::META_KEY => $admin_sheet )
);
$GLOBALS['vts_products'][10420] = $admin_variation;
ob_start();
Uonix_VTS_Admin::render_editor( 2, array(), (object) array( 'ID' => 10420 ) );
$existing_admin_html = ob_get_clean();
vts_assert_contains( 'class="uonix-vts-admin is-active"', $existing_admin_html, 'ficha existente ativa o editor' );
vts_assert_contains( 'data-had-sheet="1"', $existing_admin_html, 'ficha existente é marcada no estado do editor' );
vts_assert_contains( 'data-variation-id="10420"', $existing_admin_html, 'editor identifica a variação sem ID HTML' );
vts_assert_contains( 'name="uonix_variation_technical_sheet[2]"', $existing_admin_html, 'payload usa índice único da variação' );
vts_assert_contains( '&quot;action&quot;:&quot;upsert&quot;', $existing_admin_html, 'payload existente contém envelope escapado' );
vts_assert_contains( '&quot;value&quot;:&quot;37&quot;', $existing_admin_html, 'payload existente preserva a ficha normalizada' );
vts_assert_contains( 'value="Modelo: Pesado · Material: Inox 316 · Pol.: 5/16&quot;"', $existing_admin_html, 'readonly usa atributos oficiais escapados' );
vts_assert_contains( 'aria-label="Título geral da ficha técnica"', $existing_admin_html, 'título geral possui nome acessível' );
vts_assert_contains( 'aria-label="Cabeçalho automático da variação" readonly', $existing_admin_html, 'cabeçalho derivado é readonly e acessível' );
vts_assert_contains( 'aria-label="Reordenar seção"', $existing_admin_html, 'handle de seção possui nome acessível' );
vts_assert_contains( 'aria-label="Título opcional da seção"', $existing_admin_html, 'título opcional da seção possui nome acessível' );
vts_assert_contains( 'aria-label="Formato da seção"', $existing_admin_html, 'formato da seção possui nome acessível' );
vts_assert_contains( 'aria-label="Remover seção"', $existing_admin_html, 'remoção de seção possui nome acessível' );
vts_assert_contains( 'aria-label="Reordenar item"', $existing_admin_html, 'handle de item possui nome acessível' );
vts_assert_contains( 'aria-label="Rótulo do item"', $existing_admin_html, 'rótulo do item possui nome acessível' );
vts_assert_contains( 'aria-label="Valor do item"', $existing_admin_html, 'valor do item possui nome acessível' );
vts_assert_contains( 'aria-label="Remover item"', $existing_admin_html, 'remoção de item possui nome acessível' );
vts_assert_contains( 'class="uonix-vts-admin__section-template"', $existing_admin_html, 'template de seção usa classe própria' );
vts_assert_contains( 'class="uonix-vts-admin__item-template"', $existing_admin_html, 'template de item usa classe própria' );
vts_assert_same( 0, preg_match_all( '/<button\\b(?![^>]*\\btype="button")/i', $existing_admin_html ), 'todos os botões administrativos têm type button' );
vts_assert_same( 1, preg_match( '/<input[^>]*class="uonix-vts-admin__payload"[^>]*>/', $existing_admin_html, $existing_payload_tag ), 'payload existente é renderizado' );
vts_assert_not_contains( ' disabled', $existing_payload_tag[0], 'payload existente fica habilitado' );

$empty_admin_variation = new VTS_Fake_Admin_Variation( 10421, $admin_parent_id );
$GLOBALS['vts_products'][10421] = $empty_admin_variation;
ob_start();
Uonix_VTS_Admin::render_editor( 3, array(), (object) array( 'ID' => 10421 ) );
$empty_admin_html = ob_get_clean();
vts_assert_contains( 'class="uonix-vts-admin"', $empty_admin_html, 'variação nova recebe shell inativo' );
vts_assert_not_contains( 'class="uonix-vts-admin is-active"', $empty_admin_html, 'variação nova não começa ativa' );
vts_assert_contains( 'data-had-sheet="0"', $empty_admin_html, 'variação nova informa ausência de ficha' );
vts_assert_contains( 'name="uonix_variation_technical_sheet[3]"', $empty_admin_html, 'segunda variação usa outro índice' );
vts_assert_same( 1, preg_match( '/<input[^>]*class="uonix-vts-admin__payload"[^>]*>/', $empty_admin_html, $empty_payload_tag ), 'payload vazio é renderizado' );
vts_assert_contains( ' disabled', $empty_payload_tag[0], 'payload novo fica desabilitado' );
vts_assert_contains( ' value=""', $empty_payload_tag[0], 'payload novo começa vazio' );
vts_assert_same( 0, preg_match_all( '/\\sid="/i', $existing_admin_html . $empty_admin_html ), 'editores e templates não criam IDs HTML duplicáveis' );

$invalid_admin_variation = new VTS_Fake_Admin_Variation(
	10422,
	$admin_parent_id,
	array(),
	array( Uonix_VTS_Schema::META_KEY => array( 'version' => 2 ) )
);
$GLOBALS['vts_products'][10422] = $invalid_admin_variation;
ob_start();
Uonix_VTS_Admin::render_editor( 4, array(), (object) array( 'ID' => 10422 ) );
$invalid_admin_html = ob_get_clean();
vts_assert_contains( 'data-had-sheet="0"', $invalid_admin_html, 'meta inválido não hidrata o editor' );
vts_assert_same( 1, preg_match( '/<input[^>]*class="uonix-vts-admin__payload"[^>]*>/', $invalid_admin_html, $invalid_payload_tag ), 'meta inválido ainda produz shell seguro' );
vts_assert_contains( ' disabled', $invalid_payload_tag[0], 'meta inválido não envia upsert vazio' );

$GLOBALS['vts_editable_posts'][ $admin_parent_id ] = false;
ob_start();
Uonix_VTS_Admin::render_editor( 2, array(), (object) array( 'ID' => 10420 ) );
$unauthorized_admin_html = ob_get_clean();
vts_assert_same( '', $unauthorized_admin_html, 'usuário sem capacidade não recebe markup administrativo' );
$GLOBALS['vts_editable_posts'][ $admin_parent_id ] = true;

$save_variation = new VTS_Fake_Admin_Variation(
	10430,
	$admin_parent_id,
	array(),
	array(
		Uonix_VTS_Schema::META_KEY => vts_valid_sheet(),
		'_unrelated'               => 'preserve-byte-for-byte',
	)
);
$before_save_meta = $save_variation->meta;
$_POST = array();
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( $before_save_meta, $save_variation->meta, 'campo ausente não altera meta' );

$_POST = array( 'uonix_variation_technical_sheet' => 'not-an-array' );
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( $before_save_meta, $save_variation->meta, 'campo raiz malformado não altera meta' );

$_POST = array( 'uonix_variation_technical_sheet' => array( 1 => '{"action":"delete"}' ) );
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( $before_save_meta, $save_variation->meta, 'índice ausente não altera meta' );

$submitted_sheet = vts_valid_sheet();
$submitted_sheet['title'] = '<b>Ficha sanitizada</b>';
$submitted_sheet['sections'][0]['items'][0]['value'] = '<script>não persistir</script>42';
$_POST = array(
	'uonix_variation_technical_sheet' => array(
		0 => addslashes(
			wp_json_encode(
				array(
					'action' => 'upsert',
					'sheet'  => $submitted_sheet,
				)
			)
		),
	),
);
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( 'Ficha sanitizada', $save_variation->meta[ Uonix_VTS_Schema::META_KEY ]['title'], 'upsert remove HTML antes de persistir' );
vts_assert_same( '42', $save_variation->meta[ Uonix_VTS_Schema::META_KEY ]['sections'][0]['items'][0]['value'], 'upsert persiste valor sanitizado' );
vts_assert_same( 'preserve-byte-for-byte', $save_variation->meta['_unrelated'], 'upsert preserva meta não relacionado' );
$saved_meta = $save_variation->meta;

$error_count = count( $GLOBALS['wc_meta_box_errors'] );
$_POST = array( 'uonix_variation_technical_sheet' => array( 0 => '{invalid' ) );
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( $saved_meta, $save_variation->meta, 'JSON inválido preserva meta anterior' );
vts_assert_same( $error_count + 1, count( $GLOBALS['wc_meta_box_errors'] ), 'JSON inválido registra um erro administrativo' );
vts_assert_contains( 'variação #10430 não foi salva', end( $GLOBALS['wc_meta_box_errors'] ), 'erro administrativo identifica a variação' );
vts_assert_contains( 'A ficha técnica contém JSON inválido.', end( $GLOBALS['wc_meta_box_errors'] ), 'erro administrativo inclui o motivo específico da rejeição' );

$GLOBALS['vts_editable_posts'][ $admin_parent_id ] = false;
$_POST = array( 'uonix_variation_technical_sheet' => array( 0 => '{"action":"delete"}' ) );
Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_same( $saved_meta, $save_variation->meta, 'usuário sem capacidade não remove meta' );
$GLOBALS['vts_editable_posts'][ $admin_parent_id ] = true;

Uonix_VTS_Admin::save_variation( $save_variation, 0 );
vts_assert_false( array_key_exists( Uonix_VTS_Schema::META_KEY, $save_variation->meta ), 'delete explícito remove fisicamente a chave da ficha' );
vts_assert_same( 'preserve-byte-for-byte', $save_variation->meta['_unrelated'], 'delete explícito preserva meta não relacionado' );
vts_assert_same( 0, $save_variation->save_calls, 'hook não chama save novamente' );
Uonix_VTS_Admin::save_variation( null, 0 );
$_POST = array();

$admin_js = file_get_contents( UONIX_MU_PATH . 'uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js' );
vts_assert_contains( "(function ($) {\n\t'use strict';", $admin_js, 'script usa IIFE estrita' );
vts_assert_contains( 'const config = window.uonixVtsAdmin || {};', $admin_js, 'script consome somente o objeto localizado' );
vts_assert_not_contains( 'window.uonixVtsAdmin =', $admin_js, 'script não expõe outro estado global' );
vts_assert_contains( 'function collectSheet($root)', $admin_js, 'editor coleta estado estruturado' );
vts_assert_contains( 'version: 1', $admin_js, 'serialização fixa a versão do esquema' );
vts_assert_contains( "action: 'delete'", $admin_js, 'estado removido serializa delete explícito' );
vts_assert_contains( "\$payload.prop('disabled', true).val('');", $admin_js, 'estado inativo desabilita o payload' );
vts_assert_contains( "action: 'upsert'", $admin_js, 'estado ativo serializa upsert explícito' );
vts_assert_same( 2, substr_count( $admin_js, 'JSON.stringify(' ), 'delete e upsert são serializados separadamente como JSON' );
vts_assert_same( 2, substr_count( $admin_js, '.content.cloneNode(true)' ), 'seções e itens são clonados de templates próprios' );
vts_assert_contains( 'JSON.parse(raw)', $admin_js, 'ficha salva é hidratada do envelope' );
vts_assert_contains( 'renderSheetIntoEditor($root, envelope.sheet)', $admin_js, 'hidratação reutiliza o renderer do editor' );
vts_assert_contains( "hasClass('has-payload-error')", $admin_js, 'erro de payload bloqueia sincronização destrutiva' );
vts_assert_contains( "addClass('has-payload-error')", $admin_js, 'falha de hidratação fica visível no estado do editor' );
vts_assert_contains( "hasClass('ui-sortable')", $admin_js, 'reinicialização detecta sortable existente' );
vts_assert_contains( "sortable('destroy')", $admin_js, 'sortable existente é destruído antes de reinicializar' );
vts_assert_contains( "handle: '.uonix-vts-admin__section-handle'", $admin_js, 'seções usam alça própria de reordenação' );
vts_assert_contains( "handle: '.uonix-vts-admin__item-handle'", $admin_js, 'itens usam alça própria de reordenação' );
vts_assert_contains( 'connectWith: false', $admin_js, 'itens não atravessam seções ao reordenar' );
vts_assert_contains( "on('input change', '.uonix-vts-admin input, .uonix-vts-admin select'", $admin_js, 'edições usam evento delegado no markup próprio' );
vts_assert_contains( "on('click', '.uonix-vts-admin__add-section'", $admin_js, 'adição de seção funciona por evento delegado' );
vts_assert_contains( "on('click', '.uonix-vts-admin__add-item'", $admin_js, 'adição de item funciona por evento delegado' );
vts_assert_contains( "on('click', '.uonix-vts-admin__remove-sheet'", $admin_js, 'remoção da ficha funciona por evento delegado' );
vts_assert_same( 2, substr_count( $admin_js, 'config.strings.removeConfirm' ), 'confirmação usa a string localizada tanto na guarda quanto no valor' );
vts_assert_same( 2, substr_count( $admin_js, 'config.strings.payloadError' ), 'erro de hidratação usa a string localizada tanto na guarda quanto no valor' );
vts_assert_contains( 'woocommerce_variations_loaded woocommerce_variations_added', $admin_js, 'editor reinicializa após ciclos AJAX de variações' );
vts_assert_contains( 'function reconcileSaved()', $admin_js, 'salvamento AJAX reconcilia o estado persistido do editor' );
vts_assert_contains( "if (\$payload.prop('disabled'))", $admin_js, 'reconciliação ignora variação sem payload submetido' );
vts_assert_contains( "JSON.parse(\$payload.val() || '')", $admin_js, 'reconciliação interpreta somente o envelope efetivamente submetido' );
vts_assert_contains( "'woocommerce_variations_saved',", $admin_js, 'editor observa o sucesso do salvamento AJAX nativo' );
vts_assert_contains( "if ('upsert' === envelope.action)", $admin_js, 'upsert salvo possui ramo explícito de reconciliação' );
vts_assert_contains( "\$root.attr('data-had-sheet', '1');", $admin_js, 'upsert salvo passa a ser tratado como ficha persistida' );
vts_assert_contains( "if ('delete' === envelope.action)", $admin_js, 'delete salvo possui ramo explícito de reconciliação' );
vts_assert_contains( "\$root.attr('data-had-sheet', '0');", $admin_js, 'delete salvo deixa de ser tratado como ficha persistida' );
vts_assert_same( 2, substr_count( $admin_js, "\$root.removeClass('is-active is-deleted');" ), 'remoção local e delete salvo recolhem o editor separadamente' );
vts_assert_same( 2, substr_count( $admin_js, "\$payload.prop('disabled', true).val('');" ), 'estado inativo e delete confirmado desabilitam o payload separadamente' );
vts_assert_not_contains( 'variable_description', $admin_js, 'script não depende de IDs internos de descrição' );

$admin_css = file_get_contents( UONIX_MU_PATH . 'uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css' );
vts_assert_contains( '.uonix-vts-admin {', $admin_css, 'CSS administrativo é escopado no componente próprio' );
vts_assert_contains( 'border: 1px solid #c9d5e6', $admin_css, 'editor usa a borda aprovada' );
vts_assert_contains( 'background: #f8fafc', $admin_css, 'editor usa o fundo aprovado' );
vts_assert_contains( '.uonix-vts-admin input[type="text"],', $admin_css, 'campos editáveis têm regra explícita' );
vts_assert_contains( 'color: #2c3338 !important', $admin_css, 'texto editável e selects usam contraste aprovado' );
vts_assert_contains( '.uonix-vts-admin input[readonly]', $admin_css, 'campo readonly tem regra distinta' );
vts_assert_contains( 'color: #50575e !important', $admin_css, 'readonly usa contraste aprovado' );
vts_assert_contains( 'background: #f0f2f4', $admin_css, 'readonly usa fundo aprovado' );
vts_assert_contains( '.uonix-vts-admin input::placeholder', $admin_css, 'placeholder tem regra explícita' );
vts_assert_contains( 'color: #8c8f94', $admin_css, 'placeholder usa cor aprovada' );
vts_assert_contains( ".uonix-vts-admin__editor {\n\tdisplay: none;\n}", $admin_css, 'editor começa recolhido sem ficha' );
vts_assert_contains( '.uonix-vts-admin.is-active .uonix-vts-admin__editor', $admin_css, 'estado ativo exibe o editor' );
vts_assert_contains( '.uonix-vts-admin.is-deleted::after', $admin_css, 'remoção pendente possui aviso visual' );
vts_assert_contains( 'grid-template-columns: 28px minmax(160px, 1fr) 150px 32px', $admin_css, 'cabeçalho da seção usa colunas legíveis' );
vts_assert_contains( '@media (max-width: 782px)', $admin_css, 'editor administrativo adapta-se ao breakpoint do WordPress' );
vts_assert_contains( 'grid-template-columns: 28px 1fr 32px', $admin_css, 'campos empilham em tela estreita' );

$frontend_css = file_get_contents( UONIX_MU_PATH . 'uonix-woocommerce/assets/css/ficha-tecnica-variacao.css' );
vts_assert_contains( 'repeat(auto-fit, minmax(68px, 1fr))', $frontend_css, 'grade compacta responde à largura disponível' );
vts_assert_contains( 'repeat(auto-fit, minmax(150px, 1fr))', $frontend_css, 'grade detalhada responde à largura disponível' );
vts_assert_contains( '@media (max-width: 600px)', $frontend_css, 'cabeçalho possui adaptação móvel' );
vts_assert_not_contains( 'repeat(6, 1fr)', $frontend_css, 'CSS não fixa seis colunas' );
vts_assert_not_contains( 'repeat(4, 1fr)', $frontend_css, 'CSS não fixa quatro colunas' );
vts_assert_not_contains( '.uonix-ficha-', $frontend_css, 'CSS não reutiliza classes legadas' );

$validation_workflow = file_get_contents( $repo_root . '/.github/workflows/validate.yml' );
vts_assert_contains( '- name: Validate variation technical sheet admin script', $validation_workflow, 'workflow nomeia o gate do JavaScript administrativo' );
vts_assert_contains( 'node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js', $validation_workflow, 'workflow valida a sintaxe do editor administrativo' );

printf( "PASS: contratos da ficha técnica por variação. (%d asserções)\n", $GLOBALS['vts_assertions'] );
