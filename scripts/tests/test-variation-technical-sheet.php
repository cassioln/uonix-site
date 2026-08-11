<?php
/**
 * Contratos da ficha técnica estruturada por variação.
 *
 * Executado sem carregar WordPress para manter o contrato rápido e determinístico.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
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
$GLOBALS['vts_editable_posts']    = array();
$GLOBALS['vts_products']         = array();
$GLOBALS['vts_nonce_valid']      = true;
$GLOBALS['vts_nonce_checks']     = array();
$GLOBALS['vts_created_nonces']   = array();
$GLOBALS['vts_json_response']    = null;
$GLOBALS['vts_formatted_calls']  = array();
$GLOBALS['vts_cli_commands']     = array();
$GLOBALS['vts_cli_logs']         = array();
$GLOBALS['vts_cli_errors']       = array();
$GLOBALS['vts_get_posts_calls']  = array();
$GLOBALS['vts_current_time_calls'] = array();
$GLOBALS['vts_metadata_exists_calls'] = array();
$GLOBALS['vts_current_time']     = '2026-08-11 12:00:00';
$GLOBALS['vts_migration_store']  = array();
$GLOBALS['vts_store_writes']     = 0;
$GLOBALS['vts_save_fail_once']   = array();
$GLOBALS['vts_save_corrupt_once'] = array();
$GLOBALS['wc_meta_box_errors']   = array();
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
	$product_id = absint( $product_id );
	if ( array_key_exists( $product_id, $GLOBALS['vts_migration_store'] ) ) {
		return new VTS_Fake_Migration_Variation( $product_id );
	}
	return $GLOBALS['vts_products'][ $product_id ] ?? false;
}

function get_posts( $args = array() ) {
	$GLOBALS['vts_get_posts_calls'][] = $args;
	$query = isset( $args['meta_query'][0] ) && is_array( $args['meta_query'][0] )
		? $args['meta_query'][0]
		: array();
	$key     = isset( $query['key'] ) ? $query['key'] : '';
	$value   = isset( $query['value'] ) ? (string) $query['value'] : '';
	$compare = isset( $query['compare'] ) ? strtoupper( (string) $query['compare'] ) : '';
	$ids     = array();
	foreach ( $GLOBALS['vts_migration_store'] as $variation_id => $record ) {
		if (
			'_variation_description' === $key &&
			'LIKE' === $compare &&
			false !== strpos( $record['description'], $value )
		) {
			$ids[] = (int) $variation_id;
		}
		if (
			class_exists( 'Uonix_VTS_Schema' ) &&
			Uonix_VTS_Schema::BACKUP_META_KEY === $key &&
			'EXISTS' === $compare &&
			array_key_exists( Uonix_VTS_Schema::BACKUP_META_KEY, $record['meta'] )
		) {
			$ids[] = (int) $variation_id;
		}
	}
	sort( $ids, SORT_NUMERIC );
	return $ids;
}

function current_time( $type, $gmt = 0 ) {
	$GLOBALS['vts_current_time_calls'][] = array( $type, $gmt );
	return $GLOBALS['vts_current_time'];
}

function metadata_exists( $meta_type, $object_id, $meta_key ) {
	$GLOBALS['vts_metadata_exists_calls'][] = array( $meta_type, absint( $object_id ), $meta_key );
	return 'post' === $meta_type &&
		isset( $GLOBALS['vts_migration_store'][ absint( $object_id ) ] ) &&
		array_key_exists( $meta_key, $GLOBALS['vts_migration_store'][ absint( $object_id ) ]['meta'] );
}

function wc_get_formatted_variation( $variation, $flat = false, $include_names = true, $skip_attributes_in_name = false ) {
	$GLOBALS['vts_formatted_calls'][] = array( $variation, $flat, $include_names, $skip_attributes_in_name );
	return is_object( $variation ) && method_exists( $variation, 'get_copy_label' ) ? $variation->get_copy_label() : '';
}

function admin_url( $path = '', $scheme = 'admin' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_create_nonce( $action = -1 ) {
	$GLOBALS['vts_created_nonces'][] = $action;
	return 'nonce:' . (string) $action;
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	$GLOBALS['vts_nonce_checks'][] = array(
		'action'    => $action,
		'query_arg' => $query_arg,
		'stop'      => $stop,
	);
	return $GLOBALS['vts_nonce_valid'] ? 1 : false;
}

final class VTS_CLI_Error extends RuntimeException {}

class WP_CLI {
	public static function add_command( $name, $callable ) {
		$GLOBALS['vts_cli_commands'][] = array(
			'name'     => $name,
			'callable' => $callable,
		);
	}

	public static function log( $message ) {
		$GLOBALS['vts_cli_logs'][] = (string) $message;
	}

	public static function error( $message ) {
		$GLOBALS['vts_cli_errors'][] = (string) $message;
		throw new VTS_CLI_Error( (string) $message );
	}
}

final class VTS_Json_Response_Exception extends RuntimeException {}

function wp_send_json_success( $value = null, $status_code = null, $flags = 0 ) {
	$GLOBALS['vts_json_response'] = array(
		'success' => true,
		'data'    => $value,
		'status'  => $status_code,
		'flags'   => $flags,
	);
	throw new VTS_Json_Response_Exception( 'json_success' );
}

function wp_send_json_error( $value = null, $status_code = null, $flags = 0 ) {
	$GLOBALS['vts_json_response'] = array(
		'success' => false,
		'data'    => $value,
		'status'  => $status_code,
		'flags'   => $flags,
	);
	throw new VTS_Json_Response_Exception( 'json_error' );
}

function vts_capture_json_response( $callback ) {
	$GLOBALS['vts_json_response'] = null;
	try {
		$callback();
	} catch ( VTS_Json_Response_Exception $exception ) {
		return $GLOBALS['vts_json_response'];
	}
	return $GLOBALS['vts_json_response'];
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

final class VTS_Fake_Admin_Parent {
	private $children;
	private $id;

	public function __construct( $id, array $children ) {
		$this->id       = $id;
		$this->children = $children;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_children() {
		return $this->children;
	}
}

final class VTS_Fake_Admin_Variation {
	public $meta;
	public $save_calls = 0;
	private $attributes;
	private $copy_label;
	private $id;
	private $parent_id;

	public function __construct( $id, $parent_id, array $attributes = array(), array $meta = array(), $copy_label = '' ) {
		$this->id         = $id;
		$this->parent_id  = $parent_id;
		$this->attributes = $attributes;
		$this->meta       = $meta;
		$this->copy_label = $copy_label;
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

	public function get_copy_label() {
		return $this->copy_label;
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

final class VTS_Fake_Migration_Variation {
	private $description;
	private $id;
	private $meta;

	public function __construct( $id ) {
		$this->id          = absint( $id );
		$record            = $GLOBALS['vts_migration_store'][ $this->id ];
		$this->description = $record['description'];
		$this->meta        = $record['meta'];
	}

	public function get_id() {
		return $this->id;
	}

	public function get_type() {
		return 'variation';
	}

	public function get_description( $context = 'view' ) {
		return $this->description;
	}

	public function set_description( $description ) {
		$this->description = (string) $description;
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
		++$GLOBALS['vts_store_writes'];
		$GLOBALS['vts_save_calls_by_id'][ $this->id ] = 1 + ( $GLOBALS['vts_save_calls_by_id'][ $this->id ] ?? 0 );
		$call_number = $GLOBALS['vts_save_calls_by_id'][ $this->id ];
		if ( in_array( $call_number, $GLOBALS['vts_save_fail_before_on_call'][ $this->id ] ?? array(), true ) ) {
			throw new RuntimeException( 'falha anterior à persistência simulada #' . $this->id );
		}
		$GLOBALS['vts_migration_store'][ $this->id ]['description'] = $this->description;
		$GLOBALS['vts_migration_store'][ $this->id ]['meta']        = $this->meta;
		if ( ! empty( $GLOBALS['vts_save_corrupt_once'][ $this->id ] ) ) {
			unset( $GLOBALS['vts_save_corrupt_once'][ $this->id ] );
			$GLOBALS['vts_migration_store'][ $this->id ]['description'] .= '<!--corrompido-->';
		}
		if ( ! empty( $GLOBALS['vts_save_reinsert_empty_sheet_once'][ $this->id ] ) ) {
			unset( $GLOBALS['vts_save_reinsert_empty_sheet_once'][ $this->id ] );
			$GLOBALS['vts_migration_store'][ $this->id ]['meta'][ Uonix_VTS_Schema::META_KEY ] = '';
		}
		if ( ! empty( $GLOBALS['vts_save_fail_once'][ $this->id ] ) ) {
			unset( $GLOBALS['vts_save_fail_once'][ $this->id ] );
			throw new RuntimeException( 'falha de save simulada #' . $this->id );
		}
		return $this->id;
	}
}

function vts_legacy_inventory_fixture() {
	return array(
		10410 => '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Leve · Material: Inox · Pol.: 3/8"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>45</span></div><div><strong>B</strong><span>33</span></div><div><strong>C</strong><span>12</span></div><div><strong>D</strong><span>8</span></div><div><strong>E</strong><span>17</span></div><div><strong>F</strong><span>18</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>57 mm</span></div><div><strong>Torque</strong><span>14 N·m</span></div><div><strong>Torque</strong><span>1,4 Kgf·m</span></div><div><strong>Peso</strong><span>0,059 Kg</span></div></div></div></div>',
		10411 => '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Pesado · Material: Inox · Pol.: 5/16"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>37</span></div><div><strong>B</strong><span>28</span></div><div><strong>C</strong><span>10</span></div><div><strong>D</strong><span>6</span></div><div><strong>E</strong><span>14</span></div><div><strong>F</strong><span>14</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>48 mm</span></div><div><strong>Torque</strong><span>8 N·m</span></div><div><strong>Torque</strong><span>0,8 Kgf·m</span></div><div><strong>Peso</strong><span>0,03 Kg</span></div></div></div></div>',
		10460 => '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Pesado · Material: Inox · Pol.: 3/8"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>45</span></div><div><strong>B</strong><span>33</span></div><div><strong>C</strong><span>12</span></div><div><strong>D</strong><span>8</span></div><div><strong>E</strong><span>17</span></div><div><strong>F</strong><span>18</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>57 mm</span></div><div><strong>Torque</strong><span>14 N·m</span></div><div><strong>Torque</strong><span>1,4 Kgf·m</span></div><div><strong>Peso</strong><span>0,05 Kg</span></div></div></div></div>',
		10461 => '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Pesado · Material: Galvan · Pol.: 5/16"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>42</span></div><div><strong>B</strong><span>43</span></div><div><strong>C</strong><span>14</span></div><div><strong>D</strong><span>9</span></div><div><strong>E</strong><span>20</span></div><div><strong>F</strong><span>34</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>48 mm</span></div><div><strong>Torque</strong><span>25,1 N·m</span></div><div><strong>Torque</strong><span>2,5 Kgf·m</span></div><div><strong>Peso</strong><span>0,13 Kg</span></div></div></div></div>',
		10462 => '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Pesado · Material: Galvan · Pol.: 3/8"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>50</span></div><div><strong>B</strong><span>50</span></div><div><strong>C</strong><span>16</span></div><div><strong>D</strong><span>11</span></div><div><strong>E</strong><span>22</span></div><div><strong>F</strong><span>40</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>57 mm</span></div><div><strong>Torque</strong><span>33,9 N·m</span></div><div><strong>Torque</strong><span>3,4 Kgf·m</span></div><div><strong>Peso</strong><span>0,2 Kg</span></div></div></div></div>',
	);
}

function vts_reset_migration_store( $descriptions = null ) {
	$descriptions = null === $descriptions ? vts_legacy_inventory_fixture() : $descriptions;
	$GLOBALS['vts_migration_store']    = array();
	$GLOBALS['vts_store_writes']       = 0;
	$GLOBALS['vts_save_fail_once']                = array();
	$GLOBALS['vts_save_corrupt_once']             = array();
	$GLOBALS['vts_save_reinsert_empty_sheet_once'] = array();
	$GLOBALS['vts_save_calls_by_id']              = array();
	$GLOBALS['vts_save_fail_before_on_call'] = array();
	$GLOBALS['vts_cli_logs']           = array();
	$GLOBALS['vts_cli_errors']         = array();
	$GLOBALS['vts_get_posts_calls']    = array();
	$GLOBALS['vts_current_time_calls'] = array();
	$GLOBALS['vts_metadata_exists_calls'] = array();
	foreach ( $descriptions as $variation_id => $description ) {
		$GLOBALS['vts_migration_store'][ $variation_id ] = array(
			'description' => $description,
			'meta'        => array(),
		);
	}
}

function vts_store_snapshot() {
	$snapshot = $GLOBALS['vts_migration_store'];
	ksort( $snapshot, SORT_NUMERIC );
	foreach ( $snapshot as &$record ) {
		ksort( $record['meta'], SORT_STRING );
	}
	unset( $record );
	return $snapshot;
}

function vts_count_verified_backups() {
	$count = 0;
	foreach ( $GLOBALS['vts_migration_store'] as $record ) {
		if ( ! array_key_exists( Uonix_VTS_Schema::BACKUP_META_KEY, $record['meta'] ) ) {
			continue;
		}
		$backup = $record['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ];
		if (
			! is_array( $backup ) ||
			array_keys( $backup ) !== array(
				'original_description',
				'source_hash',
				'remaining_description',
				'remaining_description_hash',
				'sheet',
				'sheet_hash',
				'migrated_at_gmt',
				'version',
			) ||
			1 !== $backup['version'] ||
			$backup['source_hash'] !== hash( 'sha256', $backup['original_description'] ) ||
			$backup['remaining_description_hash'] !== hash( 'sha256', $backup['remaining_description'] ) ||
			$backup['sheet_hash'] !== hash( 'sha256', wp_json_encode( $backup['sheet'] ) ) ||
			! is_string( $backup['migrated_at_gmt'] ) ||
			'' === $backup['migrated_at_gmt']
		) {
			continue;
		}
		++$count;
	}
	return $count;
}

function vts_count_legacy_wrappers() {
	$count = 0;
	foreach ( $GLOBALS['vts_migration_store'] as $record ) {
		if ( false !== strpos( $record['description'], 'uonix-fichas-compactas' ) ) {
			++$count;
		}
	}
	return $count;
}

function vts_count_structured_sheets() {
	$count = 0;
	foreach ( $GLOBALS['vts_migration_store'] as $record ) {
		if ( array_key_exists( Uonix_VTS_Schema::META_KEY, $record['meta'] ) ) {
			++$count;
		}
	}
	return $count;
}

function vts_mutate_post_migration_sheet( $variation_id ) {
	$GLOBALS['vts_post_migration_sheet_before'][ $variation_id ] = $GLOBALS['vts_migration_store'][ $variation_id ]['meta'][ Uonix_VTS_Schema::META_KEY ];
	$GLOBALS['vts_migration_store'][ $variation_id ]['meta'][ Uonix_VTS_Schema::META_KEY ]['sections'][0]['items'][0]['value'] = 'EDITADO DEPOIS';
}

function vts_restore_post_migration_sheet( $variation_id ) {
	$GLOBALS['vts_migration_store'][ $variation_id ]['meta'][ Uonix_VTS_Schema::META_KEY ] = $GLOBALS['vts_post_migration_sheet_before'][ $variation_id ];
	unset( $GLOBALS['vts_post_migration_sheet_before'][ $variation_id ] );
}

function vts_expect_cli_error( $callback, $message_fragment = '' ) {
	try {
		$callback();
	} catch ( VTS_CLI_Error $exception ) {
		if ( '' !== $message_fragment ) {
			vts_assert_contains( $message_fragment, $exception->getMessage(), 'erro WP-CLI possui diagnóstico esperado' );
		}
		return $exception->getMessage();
	}
	vts_fail( 'era esperado um erro WP-CLI fail-closed' );
}

$copy_sheet = vts_valid_sheet();
$copy_sheet['sections'][0]['items'][0]['value'] = '<b>37</b>';
$GLOBALS['vts_products'][10382] = new VTS_Fake_Admin_Parent( 10382, array( 10410, 10410, 10411, 10412, 10414, 99998 ) );
$GLOBALS['vts_products'][10410] = new VTS_Fake_Admin_Variation(
	10410,
	10382,
	array(),
	array( Uonix_VTS_Schema::META_KEY => $copy_sheet ),
	'Leve, 3/8", Inox 316'
);
$GLOBALS['vts_products'][10411] = new VTS_Fake_Admin_Variation(
	10411,
	10382,
	array(),
	array(),
	'<script>indevido</script> Pesado, 5/16", Inox 316'
);
$GLOBALS['vts_products'][10412] = new VTS_Fake_Admin_Variation(
	10412,
	99999,
	array(),
	array( Uonix_VTS_Schema::META_KEY => vts_valid_sheet() ),
	'Variação de outro produto'
);
$GLOBALS['vts_products'][10413] = new VTS_Fake_Admin_Variation(
	10413,
	10382,
	array(),
	array( Uonix_VTS_Schema::META_KEY => array( 'version' => 2 ) ),
	'Ficha inválida'
);
$GLOBALS['vts_products'][10414] = new VTS_Fake_Admin_Variation(
	10415,
	10382,
	array(),
	array( Uonix_VTS_Schema::META_KEY => vts_valid_sheet() ),
	'Objeto com identidade divergente'
);
$GLOBALS['vts_editable_posts'][10382] = true;

vts_assert_hook(
	'vts_actions',
	'wp_ajax_uonix_get_variation_technical_sheet',
	array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ),
	10,
	0,
	'endpoint AJAX de cópia registrado sem argumentos'
);

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

$copy_options = Uonix_VTS_Admin::copy_options( 10382 );
vts_assert_same( 2, count( $copy_options ), 'lista inclui somente filhas válidas do produto pai' );
vts_assert_same( array( 'id', 'label' ), array_keys( $copy_options[0] ), 'opção de cópia expõe somente ID e label' );
vts_assert_same( 10410, $copy_options[0]['id'], 'lista contém a primeira variação filha' );
vts_assert_contains( '#10410', $copy_options[0]['label'], 'label inclui o ID da variação' );
vts_assert_contains( 'Inox 316', $copy_options[0]['label'], 'label preserva o atributo oficial completo' );
vts_assert_same( 10411, $copy_options[1]['id'], 'lista contém filha sem ficha para resposta fail-closed posterior' );
vts_assert_not_contains( '<script', $copy_options[1]['label'], 'label de opção remove markup inesperado' );
vts_assert_same( array(), Uonix_VTS_Admin::copy_options( 99997 ), 'produto pai inexistente não produz opções' );
vts_assert_same( 2, count( $GLOBALS['vts_formatted_calls'] ), 'somente filhas válidas têm label formatado' );
vts_assert_same( array( true, false, false ), array_slice( $GLOBALS['vts_formatted_calls'][0], 1 ), 'label usa assinatura normativa do WooCommerce' );

$copy = Uonix_VTS_Admin::get_copy_sheet( 10410, 10382 );
vts_assert_same( array( 'ok', 'code', 'message', 'sheet' ), array_keys( $copy ), 'resultado de cópia possui contrato estável' );
vts_assert_same( true, $copy['ok'], 'ficha da irmã é retornada' );
vts_assert_same( '37', $copy['sheet']['sections'][0]['items'][0]['value'], 'dados retornados passam pelo schema' );
vts_assert_not_contains( '<b>', wp_json_encode( $copy['sheet'] ), 'cópia retorna somente texto normalizado' );
$wrong_parent = Uonix_VTS_Admin::get_copy_sheet( 10410, 99999 );
vts_assert_same( false, $wrong_parent['ok'], 'origem fora do pai é recusada' );
vts_assert_same( null, $wrong_parent['sheet'], 'falha de parentesco não expõe ficha' );
$without_sheet = Uonix_VTS_Admin::get_copy_sheet( 10411, 10382 );
vts_assert_same( false, $without_sheet['ok'], 'origem sem ficha é recusada' );
vts_assert_same( 'missing_sheet', $without_sheet['code'], 'origem sem ficha possui código específico' );
vts_assert_same( null, $without_sheet['sheet'], 'origem sem ficha não expõe dados' );
$invalid_copy_sheet = Uonix_VTS_Admin::get_copy_sheet( 10413, 10382 );
vts_assert_same( false, $invalid_copy_sheet['ok'], 'meta armazenado inválido é recusado' );
vts_assert_same( 'invalid_sheet', $invalid_copy_sheet['code'], 'meta inválido possui código específico' );
vts_assert_same( null, $invalid_copy_sheet['sheet'], 'meta inválido não expõe dados' );
$mismatched_copy_source = Uonix_VTS_Admin::get_copy_sheet( 10414, 10382 );
vts_assert_same( false, $mismatched_copy_source['ok'], 'objeto com ID divergente da consulta é recusado' );
vts_assert_same( null, $mismatched_copy_source['sheet'], 'identidade divergente não expõe dados' );

$GLOBALS['vts_nonce_valid'] = true;
$_POST = array(
	'source_id' => '-10410',
	'parent_id' => '10382',
);
$ajax_success = vts_capture_json_response( array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ) );
vts_assert_same( true, $ajax_success['success'], 'endpoint autenticado retorna sucesso' );
vts_assert_same( array( 'sheet' ), array_keys( $ajax_success['data'] ), 'endpoint retorna somente a ficha' );
vts_assert_same( '37', $ajax_success['data']['sheet']['sections'][0]['items'][0]['value'], 'endpoint retorna ficha normalizada' );
vts_assert_same(
	array( 'action' => 'uonix_variation_technical_sheet_copy', 'query_arg' => 'nonce', 'stop' => false ),
	$GLOBALS['vts_nonce_checks'][0],
	'endpoint valida nonce sem encerrar antes da resposta controlada'
);

$GLOBALS['vts_nonce_valid'] = false;
$ajax_bad_nonce = vts_capture_json_response( array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ) );
vts_assert_same( false, $ajax_bad_nonce['success'], 'nonce inválido é recusado' );
vts_assert_same( 403, $ajax_bad_nonce['status'], 'nonce inválido responde como proibido' );
vts_assert_same( array( 'code' ), array_keys( $ajax_bad_nonce['data'] ), 'nonce inválido não expõe ficha' );

$GLOBALS['vts_nonce_valid'] = true;
$GLOBALS['vts_editable_posts'][10382] = false;
$ajax_forbidden = vts_capture_json_response( array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ) );
vts_assert_same( false, $ajax_forbidden['success'], 'usuário sem edit_post é recusado' );
vts_assert_same( 403, $ajax_forbidden['status'], 'falta de capacidade responde como proibida' );
vts_assert_same( array( 'code' ), array_keys( $ajax_forbidden['data'] ), 'falta de capacidade não expõe ficha' );

$GLOBALS['vts_editable_posts'][10382] = true;
$GLOBALS['vts_editable_posts'][99999] = true;
$_POST['parent_id'] = '99999';
$ajax_wrong_parent = vts_capture_json_response( array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ) );
vts_assert_same( false, $ajax_wrong_parent['success'], 'origem fora do pai falha também no endpoint' );
vts_assert_same( array( 'code' ), array_keys( $ajax_wrong_parent['data'] ), 'parentesco inválido não expõe ficha via AJAX' );

$_POST = array( 'source_id' => '10411', 'parent_id' => '10382' );
$ajax_without_sheet = vts_capture_json_response( array( 'Uonix_VTS_Admin', 'ajax_get_copy_sheet' ) );
vts_assert_same( false, $ajax_without_sheet['success'], 'origem sem ficha falha também no endpoint' );
vts_assert_same( array( 'code' ), array_keys( $ajax_without_sheet['data'] ), 'origem sem ficha não expõe outros dados via AJAX' );
$_POST = array();

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
vts_assert_same( 'https://example.test/wp-admin/admin-ajax.php', $admin_config['data']['ajaxUrl'], 'configuração aponta para o endpoint AJAX administrativo' );
vts_assert_same( 'nonce:uonix_variation_technical_sheet_copy', $admin_config['data']['nonce'], 'configuração inclui nonce dedicado à cópia' );
vts_assert_same( 'uonix_get_variation_technical_sheet', $admin_config['data']['copyAction'], 'configuração inclui action AJAX estável' );
vts_assert_same( $copy_options, $admin_config['data']['copyOptions'], 'opções irmãs são localizadas uma única vez por produto' );
vts_assert_same( 'uonix_variation_technical_sheet_copy', $GLOBALS['vts_created_nonces'][0], 'nonce usa ação dedicada' );
vts_assert_same( 'Remover a ficha técnica desta variação ao salvar?', $admin_config['data']['strings']['removeConfirm'], 'confirmação de remoção é localizada' );
vts_assert_same( 'Não foi possível carregar a ficha técnica salva.', $admin_config['data']['strings']['payloadError'], 'erro de hidratação é localizado' );
vts_assert_same( 'Substituir a ficha atual pela ficha selecionada?', $admin_config['data']['strings']['copyConfirm'], 'confirmação de sobrescrita é localizada' );
vts_assert_same( 'Não foi possível copiar a ficha selecionada.', $admin_config['data']['strings']['copyError'], 'erro de cópia é localizado' );
vts_assert_same( 'Selecione uma variação', $admin_config['data']['strings']['copyPlaceholder'], 'placeholder da origem é localizado' );

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
vts_assert_contains( 'class="uonix-vts-admin__copy-control"', $existing_admin_html, 'controle de cópia possui label real' );
vts_assert_contains( 'class="uonix-vts-admin__copy-source" aria-label="Variação de origem"', $existing_admin_html, 'origem da cópia possui nome acessível' );
vts_assert_contains( 'type="button" class="button uonix-vts-admin__copy"', $existing_admin_html, 'ação de cópia é botão explícito' );
vts_assert_same( 1, substr_count( $existing_admin_html, 'class="uonix-vts-admin__copy-source"' ), 'cada editor possui um único seletor de origem' );
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
vts_assert_contains( 'function populateCopyOptions($root)', $admin_js, 'script possui inicializador próprio das opções de cópia' );
vts_assert_contains( 'Array.isArray(config.copyOptions)', $admin_js, 'opções localizadas são validadas como array' );
vts_assert_same( 2, substr_count( $admin_js, 'sourceId === destinationId' ), 'preenchimento e clique recusam copiar a própria variação' );
vts_assert_same( 2, substr_count( $admin_js, "\$('<option>', {" ), 'placeholder e origens são criados como nós de texto, sem HTML dinâmico' );
vts_assert_same( 2, substr_count( $admin_js, 'populateCopyOptions($root)' ), 'inicializador de opções é definido e chamado no ciclo AJAX' );
vts_assert_contains( "on('click', '.uonix-vts-admin__copy'", $admin_js, 'ação de cópia usa evento delegado' );
vts_assert_contains( "collectSheet(\$root).sections.length > 0", $admin_js, 'sobrescrita detecta ficha atual com conteúdo' );
vts_assert_same( 2, substr_count( $admin_js, 'config.strings.copyConfirm' ), 'confirmação de sobrescrita usa string localizada' );
vts_assert_same( 2, substr_count( $admin_js, 'config.strings.copyError' ), 'falha de cópia usa string localizada' );
vts_assert_contains( '$.post(config.ajaxUrl, {', $admin_js, 'cópia consulta o endpoint localizado' );
vts_assert_contains( 'action: config.copyAction', $admin_js, 'requisição usa action localizada' );
vts_assert_contains( 'nonce: config.nonce', $admin_js, 'requisição envia o nonce dedicado' );
vts_assert_contains( 'source_id: sourceId', $admin_js, 'requisição envia somente a origem selecionada' );
vts_assert_contains( 'parent_id: config.parentId', $admin_js, 'requisição vincula a origem ao produto pai' );
vts_assert_contains( 'function isValidCopySheet(sheet)', $admin_js, 'resposta AJAX possui validador estrutural próprio' );
vts_assert_contains( 'function isValidText(value, maxLength, allowEmpty)', $admin_js, 'validador possui contrato de texto compartilhado' );
vts_assert_contains( "typeof value !== 'string'", $admin_js, 'validador recusa valores não textuais' );
vts_assert_contains( '/[\\u0000-\\u001F\\u007F]/.test(value)', $admin_js, 'validador recusa caracteres de controle' );
vts_assert_contains( 'Array.from(value).length > maxLength', $admin_js, 'validador conta caracteres Unicode no limite' );
vts_assert_contains( 'value.trim().length > 0', $admin_js, 'validador recusa texto obrigatório vazio após trim' );
vts_assert_contains( 'function isPlainObject(value)', $admin_js, 'validador distingue objetos de arrays e nulos' );
vts_assert_contains( "null !== value && 'object' === typeof value && !Array.isArray(value)", $admin_js, 'validador exige objeto simples antes de acessar campos' );
vts_assert_contains( 'sheet.version !== 1', $admin_js, 'validador exige versão estrita do schema' );
vts_assert_contains( "isValidText(sheet.title, 160, false)", $admin_js, 'validador exige título geral não vazio dentro do limite' );
vts_assert_contains( 'sheet.sections.length < 1 || sheet.sections.length > 50) {', $admin_js, 'validador exige quantidade segura de seções' );
vts_assert_contains( 'isValidText(section.title, 120, true)', $admin_js, 'validador limita o título opcional da seção' );
vts_assert_contains( "section.layout !== 'compact' && section.layout !== 'detailed'", $admin_js, 'validador restringe layouts à whitelist' );
vts_assert_contains( 'section.items.length < 1 || section.items.length > 100) {', $admin_js, 'validador exige quantidade segura de itens' );
vts_assert_contains( 'isValidText(item.label, 120, false)', $admin_js, 'validador exige rótulo completo' );
vts_assert_contains( 'isValidText(item.value, 500, false)', $admin_js, 'validador exige valor completo' );
vts_assert_contains( '!isValidCopySheet(response.data.sheet)', $admin_js, 'resposta sem ficha válida falha antes de alterar o editor' );
vts_assert_true(
	strpos( $admin_js, '!isValidCopySheet(response.data.sheet)' ) < strpos( $admin_js, 'renderSheetIntoEditor($root, response.data.sheet)' ),
	'validação da resposta precede qualquer alteração do editor'
);
vts_assert_contains( 'renderSheetIntoEditor($root, response.data.sheet)', $admin_js, 'cópia reutiliza o renderer preservando ordem' );
vts_assert_contains( "renderSheetIntoEditor(\$root, response.data.sheet);\n\t\t\t\t\$root.addClass('is-active').removeClass('is-deleted has-payload-error');\n\t\t\t\tsync(\$root);", $admin_js, 'resposta válida aplica render, estado e payload na mesma sequência' );
vts_assert_contains( "removeClass('is-deleted has-payload-error')", $admin_js, 'resposta válida substitui estado removido ou payload inválido' );
vts_assert_contains( '.fail(showCopyError)', $admin_js, 'falha de transporte mostra erro controlado' );
vts_assert_not_contains( "trigger('woocommerce_variations_save')", $admin_js, 'cópia não salva automaticamente' );
vts_assert_not_contains( 'variable_description', $admin_js, 'script não depende de IDs internos de descrição' );

$legacy_wrapper = '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta"><div class="uonix-ficha-header"><strong>Dimensões (mm)</strong><span>Modelo: Pesado · Material: Galvan · Pol.: 5/16"</span></div><div class="uonix-medidas-grid"><div><strong>A</strong><span>42</span></div><div><strong>B</strong><span>43</span></div><div><strong>C</strong><span>14</span></div><div><strong>D</strong><span>9</span></div><div><strong>E</strong><span>20</span></div><div><strong>F</strong><span>34</span></div></div><div class="uonix-info-grid"><div><strong>Espaço mín.</strong><span>48 mm</span></div><div><strong>Torque</strong><span>25,1 N·m</span></div><div><strong>Torque</strong><span>2,5 Kgf·m</span></div><div><strong>Peso</strong><span>0,13 Kg</span></div></div></div></div>';
$legacy_description = '<p>Antes</p>' . $legacy_wrapper . '<p>Depois</p>';

vts_assert_true( class_exists( 'Uonix_VTS_Migration_Command' ), 'classe de migração legada é carregada pelo bootstrap' );
$migration_source = file_get_contents( UONIX_MU_PATH . 'uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-migration-command.php' );
vts_assert_contains( "if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) )", $migration_source, 'registro WP-CLI possui guarda fail-closed completa' );
vts_assert_contains( 'LIBXML_NONET', $migration_source, 'parser DOM bloqueia acesso de rede' );
vts_assert_contains( "metadata_exists( 'post', \$variation_id, \$meta_key )", $migration_source, 'migração distingue meta ausente de valor vazio' );
foreach ( array( '10410', '10411', '10460', '10461', '10462' ) as $inventory_id ) {
	vts_assert_not_contains( $inventory_id, $migration_source, 'produção não fixa ID do inventário #' . $inventory_id );
}
$execute_method_start = strpos( $migration_source, 'private static function execute_candidates' );
$execute_catch_start  = strpos( $migration_source, '} catch ( Throwable $exception )', $execute_method_start );
$rollback_method_start = strpos( $migration_source, 'private static function rollback_candidates' );
$rollback_catch_start  = strpos( $migration_source, '} catch ( Throwable $exception )', $rollback_method_start );
vts_assert_true( false !== $execute_method_start && false !== $execute_catch_start, 'bloco transacional de execute é localizável' );
vts_assert_true( false !== $rollback_method_start && false !== $rollback_catch_start, 'bloco transacional de rollback é localizável' );
vts_assert_not_contains( 'WP_CLI::error(', substr( $migration_source, $execute_method_start, $execute_catch_start - $execute_method_start ), 'execute não encerra WP-CLI antes da compensação' );
vts_assert_not_contains( 'WP_CLI::error(', substr( $migration_source, $rollback_method_start, $rollback_catch_start - $rollback_method_start ), 'rollback não encerra WP-CLI antes da compensação' );
$parsed_legacy = Uonix_VTS_Migration_Command::parse_legacy_description( $legacy_description );
vts_assert_same( array( 'ok', 'code', 'message', 'sheet', 'remaining_description' ), array_keys( $parsed_legacy ), 'parser possui contrato estável' );
vts_assert_same( true, $parsed_legacy['ok'], 'wrapper legado reconhecido' );
vts_assert_same( 'Dimensões (mm)', $parsed_legacy['sheet']['title'], 'título extraído' );
vts_assert_same( 'compact', $parsed_legacy['sheet']['sections'][0]['layout'], 'medidas viram seção compacta' );
vts_assert_same( '', $parsed_legacy['sheet']['sections'][0]['title'], 'seção compacta permanece sem título próprio' );
vts_assert_same( 6, count( $parsed_legacy['sheet']['sections'][0]['items'] ), 'seis medidas extraídas' );
vts_assert_same( 'A', $parsed_legacy['sheet']['sections'][0]['items'][0]['label'], 'primeiro rótulo compacto preservado' );
vts_assert_same( '42', $parsed_legacy['sheet']['sections'][0]['items'][0]['value'], 'primeiro valor compacto preservado' );
vts_assert_same( 'detailed', $parsed_legacy['sheet']['sections'][1]['layout'], 'informações viram seção detalhada' );
vts_assert_same( '', $parsed_legacy['sheet']['sections'][1]['title'], 'seção detalhada permanece sem título próprio' );
vts_assert_same( 4, count( $parsed_legacy['sheet']['sections'][1]['items'] ), 'quatro detalhes extraídos' );
vts_assert_same( 'Peso', $parsed_legacy['sheet']['sections'][1]['items'][3]['label'], 'último rótulo detalhado preservado' );
vts_assert_same( '0,13 Kg', $parsed_legacy['sheet']['sections'][1]['items'][3]['value'], 'último valor detalhado preservado' );
vts_assert_same( '<p>Antes</p><p>Depois</p>', $parsed_legacy['remaining_description'], 'texto livre preservado byte a byte' );
vts_assert_not_contains( 'Galvan', wp_json_encode( $parsed_legacy['sheet'] ), 'subtítulo legado não é migrado' );

$expected_legacy_hashes = array(
	10410 => 'c955e33d7574cb357e902ff71b91444e309b8b4a3103be233392084daaae9fad',
	10411 => '2df1eadc77a5acff66e1daf7522023e907a998df045333ddcd49a70fb43c60ad',
	10460 => '226cb89957865b48d9396d25025776b1d0a0e47bc0ba9548025360a45c2e88d0',
	10461 => 'adec338b0d83452f59aa208df70604c4d45e240dedcdcd4da9af279045a18dff',
	10462 => '3208a9a199c68bda5e95d6f33ac22fdc53bff1825004f90f9191574d77426ed0',
);
foreach ( vts_legacy_inventory_fixture() as $variation_id => $description ) {
	vts_assert_same( $expected_legacy_hashes[ $variation_id ], hash( 'sha256', $description ), 'fixture legado permanece byte a byte congelado #' . $variation_id );
}

$legacy_five_measures = str_replace( '<div><strong>F</strong><span>34</span></div>', '', $legacy_description );
$legacy_three_details = str_replace( '<div><strong>Peso</strong><span>0,13 Kg</span></div>', '', $legacy_description );
$legacy_unbalanced    = '<p>Antes</p>' . substr( $legacy_wrapper, 0, -6 ) . '<p>Depois</p>';
$legacy_duplicate     = '<p>Antes</p>' . $legacy_wrapper . $legacy_wrapper . '<p>Depois</p>';
$legacy_wrong_token   = str_replace( 'uonix-fichas-compactas', 'uonix-fichas-compactas-extra', $legacy_description );
$legacy_overlong_value = str_replace( '<span>42</span>', '<span>' . str_repeat( 'x', 501 ) . '</span>', $legacy_description );
$legacy_direct_sheet_text = str_replace( '</span></div><div class="uonix-medidas-grid">', '</span></div>TEXTO NÃO MIGRADO<div class="uonix-medidas-grid">', $legacy_description );
$legacy_direct_wrapper_text = str_replace( '<div class="uonix-fichas-compactas"><div class="uonix-ficha-compacta">', '<div class="uonix-fichas-compactas">TEXTO WRAPPER<div class="uonix-ficha-compacta">', $legacy_description );
$legacy_direct_header_text = str_replace( '<strong>Dimensões (mm)</strong><span>', '<strong>Dimensões (mm)</strong>TEXTO HEADER<span>', $legacy_description );
$legacy_direct_grid_text = str_replace( '<span>42</span></div><div><strong>B</strong>', '<span>42</span></div>TEXTO GRID<div><strong>B</strong>', $legacy_description );
$legacy_direct_pair_text = str_replace( '<strong>A</strong><span>42</span>', '<strong>A</strong>TEXTO PAR<span>42</span>', $legacy_description );
$legacy_missing_span_close = str_replace( 'Pol.: 5/16"</span></div>', 'Pol.: 5/16"</div>', $legacy_description );
$legacy_duplicate_attribute = str_replace( '<div class="uonix-ficha-header">', '<div class="uonix-ficha-header" class="duplicada">', $legacy_description );
foreach (
	array(
		'cinco medidas'      => $legacy_five_measures,
		'três detalhes'      => $legacy_three_details,
		'wrapper desbalanceado' => $legacy_unbalanced,
		'wrapper duplicado'  => $legacy_duplicate,
		'token de classe parcial' => $legacy_wrong_token,
		'valor acima do schema' => $legacy_overlong_value,
		'texto direto inesperado na ficha' => $legacy_direct_sheet_text,
		'texto direto inesperado no wrapper' => $legacy_direct_wrapper_text,
		'texto direto inesperado no cabeçalho' => $legacy_direct_header_text,
		'texto direto inesperado na grade' => $legacy_direct_grid_text,
		'texto direto inesperado no par' => $legacy_direct_pair_text,
		'span sem fechamento reparado pelo DOM' => $legacy_missing_span_close,
		'atributo duplicado reportado pelo DOM' => $legacy_duplicate_attribute,
	) as $case_name => $invalid_legacy
) {
	$invalid_parsed = Uonix_VTS_Migration_Command::parse_legacy_description( $invalid_legacy );
	vts_assert_same( false, $invalid_parsed['ok'], 'parser recusa ' . $case_name );
	vts_assert_true( is_string( $invalid_parsed['code'] ) && '' !== $invalid_parsed['code'], 'falha identifica ' . $case_name );
	vts_assert_same( null, $invalid_parsed['sheet'], 'falha não expõe ficha parcial em ' . $case_name );
	vts_assert_same( null, $invalid_parsed['remaining_description'], 'falha não produz descrição parcial em ' . $case_name );
}

vts_assert_same(
	array( array( 'name' => 'uonix ficha-tecnica', 'callable' => 'Uonix_VTS_Migration_Command' ) ),
	$GLOBALS['vts_cli_commands'],
	'bootstrap registra o comando WP-CLI uma única vez'
);
vts_assert_true( method_exists( 'Uonix_VTS_Migration_Command', 'migrate' ), 'comando expõe operação migrate' );

vts_reset_migration_store();
$migration_command = new Uonix_VTS_Migration_Command();
$migration_command->migrate( array(), array( 'dry-run' => true ) );
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'dry-run não grava' );
vts_assert_same( array( array( 'mysql', true ) ), $GLOBALS['vts_current_time_calls'], 'preflight usa um timestamp GMT determinístico' );
vts_assert_same( 6, count( $GLOBALS['vts_cli_logs'] ), 'dry-run relata cinco fontes e um resumo' );
foreach ( vts_legacy_inventory_fixture() as $variation_id => $description ) {
	$report_line = null;
	foreach ( $GLOBALS['vts_cli_logs'] as $line ) {
		if ( false !== strpos( $line, '#' . $variation_id . ':' ) ) {
			$report_line = $line;
			break;
		}
	}
	vts_assert_true( is_string( $report_line ), 'dry-run relata a variação #' . $variation_id );
	vts_assert_contains( hash( 'sha256', $description ), $report_line, 'dry-run relata hash da origem #' . $variation_id );
	vts_assert_contains( 'compacta=6', $report_line, 'dry-run relata seis medidas #' . $variation_id );
	vts_assert_contains( 'detalhada=4', $report_line, 'dry-run relata quatro detalhes #' . $variation_id );
}
vts_assert_same(
	'DRY-RUN OK: 5 fichas legadas reconhecidas; nenhuma alteração realizada.',
	$GLOBALS['vts_cli_logs'][5],
	'dry-run encerra com resumo normativo'
);
vts_assert_same(
	array(
		'post_type'      => 'product_variation',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'meta_query'     => array(
			array(
				'key'     => '_variation_description',
				'value'   => 'uonix-fichas-compactas',
				'compare' => 'LIKE',
			),
		),
	),
	$GLOBALS['vts_get_posts_calls'][0],
	'preflight consulta descrições de variações pelo meta real do WooCommerce'
);

vts_reset_migration_store();
$migration_command->migrate( array(), array() );
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'ausência de modo também é dry-run' );
vts_assert_same( 'DRY-RUN OK: 5 fichas legadas reconhecidas; nenhuma alteração realizada.', $GLOBALS['vts_cli_logs'][5], 'modo padrão permanece seguro' );

vts_reset_migration_store();
$GLOBALS['vts_current_time'] = '2026-02-30 12:00:00';
$invalid_time_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'dry-run' => true ) );
	},
	'horário GMT válido'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'timestamp GMT inválido falha antes de gravar' );
vts_assert_same( $invalid_time_snapshot, vts_store_snapshot(), 'timestamp GMT inválido preserva o store' );
$GLOBALS['vts_current_time'] = '2026-08-11 12:00:00';

vts_reset_migration_store();
$before_invalid_mode = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true, 'rollback' => true ) );
	},
	'Escolha somente'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'modos conflitantes não gravam' );
vts_assert_same( array(), $GLOBALS['vts_get_posts_calls'], 'modos conflitantes falham antes de consultar dados' );
vts_assert_same( $before_invalid_mode, vts_store_snapshot(), 'modos conflitantes preservam todo o store' );

$four_legacy = vts_legacy_inventory_fixture();
unset( $four_legacy[10462] );
vts_reset_migration_store( $four_legacy );
$four_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'dry-run' => true ) );
	},
	'exatamente 5'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'contagem divergente falha sem gravar' );
vts_assert_same( $four_snapshot, vts_store_snapshot(), 'contagem divergente preserva todo o store' );

$invalid_five = vts_legacy_inventory_fixture();
$invalid_five[10460] = str_replace( '<div><strong>F</strong><span>18</span></div>', '', $invalid_five[10460] );
vts_reset_migration_store( $invalid_five );
$invalid_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'dry-run' => true ) );
	},
	'#10460'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'parser divergente falha antes de qualquer gravação' );
vts_assert_same( $invalid_snapshot, vts_store_snapshot(), 'parser divergente preserva todas as cinco variações' );

vts_reset_migration_store();
$original_inventory = vts_legacy_inventory_fixture();
$migration_command->migrate( array(), array( 'execute' => true ) );
vts_assert_same( 5, $GLOBALS['vts_store_writes'], 'execute grava exatamente as cinco variações' );
vts_assert_same( 5, vts_count_verified_backups(), 'execute cria cinco backups integrais verificados' );
vts_assert_same( 0, vts_count_legacy_wrappers(), 'execute remove os cinco wrappers reconhecidos' );
vts_assert_same( 5, vts_count_structured_sheets(), 'execute cria cinco fichas estruturadas' );
vts_assert_same( array( 'EXECUTE OK: 5 fichas migradas; 5 backups verificados.' ), $GLOBALS['vts_cli_logs'], 'execute relata sucesso normativo' );
foreach ( $original_inventory as $variation_id => $original_description ) {
	$record = $GLOBALS['vts_migration_store'][ $variation_id ];
	$backup = $record['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ];
	vts_assert_same( '', $record['description'], 'descrição composta apenas pelo wrapper fica vazia #' . $variation_id );
	vts_assert_same( $original_description, $backup['original_description'], 'backup preserva descrição original byte a byte #' . $variation_id );
	vts_assert_same( hash( 'sha256', $original_description ), $backup['source_hash'], 'backup preserva hash da origem #' . $variation_id );
	vts_assert_same( '', $backup['remaining_description'], 'backup registra descrição remanescente #' . $variation_id );
	vts_assert_same( hash( 'sha256', '' ), $backup['remaining_description_hash'], 'backup registra hash remanescente #' . $variation_id );
	vts_assert_same( $backup['sheet'], $record['meta'][ Uonix_VTS_Schema::META_KEY ], 'meta salvo equivale à ficha verificada #' . $variation_id );
	vts_assert_same( hash( 'sha256', wp_json_encode( $backup['sheet'] ) ), $backup['sheet_hash'], 'backup registra hash da ficha #' . $variation_id );
	vts_assert_same( '2026-08-11 12:00:00', $backup['migrated_at_gmt'], 'backup registra timestamp GMT #' . $variation_id );
	vts_assert_same( 1, $backup['version'], 'backup registra versão da migração #' . $variation_id );
}

$after_first_execute = vts_store_snapshot();
$writes_after_execute = $GLOBALS['vts_store_writes'];
$GLOBALS['vts_cli_logs'] = array();
$GLOBALS['vts_get_posts_calls'] = array();
$GLOBALS['vts_current_time_calls'] = array();
$migration_command->migrate( array(), array( 'execute' => true ) );
vts_assert_same( $writes_after_execute, $GLOBALS['vts_store_writes'], 'segunda execução é idempotente' );
vts_assert_same( $after_first_execute, vts_store_snapshot(), 'segunda execução não altera bytes nem metas' );
vts_assert_same( array( 'NO-CHANGE: 5 fichas já migradas e verificadas.' ), $GLOBALS['vts_cli_logs'], 'segunda execução relata estado verificado' );
vts_assert_same( array(), $GLOBALS['vts_current_time_calls'], 'idempotência não fabrica novo timestamp' );
vts_assert_same( 2, count( $GLOBALS['vts_get_posts_calls'] ), 'idempotência consulta wrappers e backups' );
vts_assert_same( Uonix_VTS_Schema::BACKUP_META_KEY, $GLOBALS['vts_get_posts_calls'][1]['meta_query'][0]['key'], 'idempotência busca backups pela chave própria' );
vts_assert_same( 'EXISTS', $GLOBALS['vts_get_posts_calls'][1]['meta_query'][0]['compare'], 'idempotência exige backup existente' );

vts_mutate_post_migration_sheet( 10410 );
$idempotent_sheet_edit_snapshot = vts_store_snapshot();
$writes_before_idempotent_sheet_edit = $GLOBALS['vts_store_writes'];
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'divergiu'
);
vts_assert_same( $writes_before_idempotent_sheet_edit, $GLOBALS['vts_store_writes'], 'idempotência recusa ficha editada sem gravar' );
vts_assert_same( $idempotent_sheet_edit_snapshot, vts_store_snapshot(), 'idempotência preserva ficha editada ao recusar' );
vts_restore_post_migration_sheet( 10410 );

$GLOBALS['vts_migration_store'][10410]['description'] = '<p>Descrição posterior</p>';
$idempotent_description_edit_snapshot = vts_store_snapshot();
$writes_before_idempotent_description_edit = $GLOBALS['vts_store_writes'];
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'divergiu'
);
vts_assert_same( $writes_before_idempotent_description_edit, $GLOBALS['vts_store_writes'], 'idempotência recusa descrição editada sem gravar' );
vts_assert_same( $idempotent_description_edit_snapshot, vts_store_snapshot(), 'idempotência preserva descrição editada ao recusar' );

$GLOBALS['vts_migration_store'][10410]['description'] = '';
$edited_backup =& $GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ];
$edited_backup['sheet']['sections'][0]['items'][0]['value'] = '999';
$edited_backup['sheet_hash'] = hash( 'sha256', wp_json_encode( $edited_backup['sheet'] ) );
unset( $edited_backup );
$idempotent_backup_edit_snapshot = vts_store_snapshot();
$writes_before_idempotent_backup_edit = $GLOBALS['vts_store_writes'];
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'divergiu'
);
vts_assert_same( $writes_before_idempotent_backup_edit, $GLOBALS['vts_store_writes'], 'idempotência recusa backup editado sem gravar' );
vts_assert_same( $idempotent_backup_edit_snapshot, vts_store_snapshot(), 'idempotência preserva backup editado ao recusar' );

vts_reset_migration_store();
$GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ] = array(
	'version'              => 1,
	'source_hash'          => str_repeat( '0', 64 ),
	'original_description' => $GLOBALS['vts_migration_store'][10410]['description'],
);
$backup_conflict_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'backup'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'backup divergente aborta antes de gravar' );
vts_assert_same( $backup_conflict_snapshot, vts_store_snapshot(), 'backup divergente nunca é sobrescrito' );

$valid_backup_mutators = array(
	'origem divergente' => function ( &$backup ) {
		$backup['original_description'] .= '<p>origem adulterada</p>';
		$backup['source_hash']           = hash( 'sha256', $backup['original_description'] );
	},
	'versão desconhecida' => function ( &$backup ) {
		$backup['version'] = 2;
	},
	'timestamp inválido' => function ( &$backup ) {
		$backup['migrated_at_gmt'] = 'ontem';
	},
);
foreach ( $valid_backup_mutators as $case_name => $mutate_backup ) {
	vts_reset_migration_store();
	$migration_command->migrate( array(), array( 'execute' => true ) );
	$migration_command->migrate( array(), array( 'rollback' => true ) );
	$mutated_backup =& $GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ];
	$mutate_backup( $mutated_backup );
	unset( $mutated_backup );
	$mutated_backup_snapshot          = vts_store_snapshot();
	$GLOBALS['vts_store_writes']      = 0;
	$GLOBALS['vts_cli_logs']          = array();
	$GLOBALS['vts_get_posts_calls']   = array();
	$GLOBALS['vts_current_time_calls'] = array();
	vts_expect_cli_error(
		function () use ( $migration_command ) {
			$migration_command->migrate( array(), array( 'execute' => true ) );
		},
		'backup legado divergente'
	);
	vts_assert_same( 0, $GLOBALS['vts_store_writes'], $case_name . ' aborta antes de gravar' );
	vts_assert_same( $mutated_backup_snapshot, vts_store_snapshot(), $case_name . ' nunca é sobrescrito' );
}

vts_reset_migration_store();
$GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::META_KEY ] = vts_valid_sheet();
$sheet_conflict_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'ficha estruturada'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'ficha preexistente aborta antes de gravar' );
vts_assert_same( $sheet_conflict_snapshot, vts_store_snapshot(), 'ficha preexistente é preservada' );

vts_reset_migration_store();
$GLOBALS['vts_migration_store'][99999] = array(
	'description' => '<p>Variação sem wrapper legado</p>',
	'meta'        => array( Uonix_VTS_Schema::BACKUP_META_KEY => array( 'stale' => true ) ),
);
$orphan_backup_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'backup fora das cinco candidatas'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'backup órfão aborta antes de gravar' );
vts_assert_same( $orphan_backup_snapshot, vts_store_snapshot(), 'backup órfão e candidatas permanecem intactos' );

vts_reset_migration_store();
$GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::META_KEY ] = '';
$empty_sheet_conflict_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'ficha estruturada'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'meta estruturado vazio ainda é conflito físico' );
vts_assert_same( $empty_sheet_conflict_snapshot, vts_store_snapshot(), 'meta estruturado vazio nunca é sobrescrito' );

vts_reset_migration_store();
$GLOBALS['vts_migration_store'][10410]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ] = '';
$empty_backup_conflict_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'backup'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'backup vazio ainda é backup físico divergente' );
vts_assert_same( $empty_backup_conflict_snapshot, vts_store_snapshot(), 'backup vazio nunca é sobrescrito' );

vts_reset_migration_store();
$rollback_without_backup_snapshot = vts_store_snapshot();
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'rollback' => true ) );
	},
	'cinco backups'
);
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'rollback sem backups não grava' );
vts_assert_same( $rollback_without_backup_snapshot, vts_store_snapshot(), 'rollback sem backups preserva o store' );

vts_reset_migration_store();
$migration_command->migrate( array(), array( 'execute' => true ) );
$backups_before_rollback = array();
foreach ( array_keys( vts_legacy_inventory_fixture() ) as $variation_id ) {
	$backups_before_rollback[ $variation_id ] = $GLOBALS['vts_migration_store'][ $variation_id ]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ];
}
vts_mutate_post_migration_sheet( 10410 );
$post_edit_snapshot = vts_store_snapshot();
$writes_before_refused_rollback = $GLOBALS['vts_store_writes'];
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'rollback' => true ) );
	},
	'edição posterior'
);
vts_assert_same( $writes_before_refused_rollback, $GLOBALS['vts_store_writes'], 'rollback após edição posterior não grava' );
vts_assert_same( $post_edit_snapshot, vts_store_snapshot(), 'rollback após edição posterior preserva todo o store' );

vts_restore_post_migration_sheet( 10410 );
$GLOBALS['vts_cli_logs'] = array();
$migration_command->migrate( array(), array( 'rollback' => true ) );
vts_assert_same( 10, $GLOBALS['vts_store_writes'], 'rollback válido grava exatamente as cinco restaurações' );
vts_assert_same( 5, vts_count_legacy_wrappers(), 'rollback restaura as cinco descrições legadas' );
vts_assert_same( 0, vts_count_structured_sheets(), 'rollback remove os cinco metas estruturados' );
vts_assert_same( 5, vts_count_verified_backups(), 'rollback preserva os cinco backups verificados' );
vts_assert_same( array( 'ROLLBACK OK: 5 descrições restauradas; backups preservados.' ), $GLOBALS['vts_cli_logs'], 'rollback relata sucesso normativo' );
foreach ( vts_legacy_inventory_fixture() as $variation_id => $original_description ) {
	$record = $GLOBALS['vts_migration_store'][ $variation_id ];
	vts_assert_same( $original_description, $record['description'], 'rollback restaura bytes originais #' . $variation_id );
	vts_assert_false( array_key_exists( Uonix_VTS_Schema::META_KEY, $record['meta'] ), 'rollback remove fisicamente o meta estruturado #' . $variation_id );
	vts_assert_same( $backups_before_rollback[ $variation_id ], $record['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ], 'rollback não reescreve o backup #' . $variation_id );
}

$GLOBALS['vts_current_time']       = '2026-08-12 09:30:00';
$GLOBALS['vts_current_time_calls'] = array();
$GLOBALS['vts_cli_logs']           = array();
$writes_before_reexecute           = $GLOBALS['vts_store_writes'];
$migration_command->migrate( array(), array( 'execute' => true ) );
vts_assert_same( $writes_before_reexecute + 5, $GLOBALS['vts_store_writes'], 'execute após rollback migra novamente as cinco variações' );
vts_assert_same( array(), $GLOBALS['vts_current_time_calls'], 'execute após rollback reutiliza timestamps dos backups' );
vts_assert_same( array( 'EXECUTE OK: 5 fichas migradas; 5 backups verificados.' ), $GLOBALS['vts_cli_logs'], 'nova execução após rollback relata sucesso' );
foreach ( $backups_before_rollback as $variation_id => $backup_before ) {
	vts_assert_same( $backup_before, $GLOBALS['vts_migration_store'][ $variation_id ]['meta'][ Uonix_VTS_Schema::BACKUP_META_KEY ], 'nova execução reutiliza backup sem sobrescrever #' . $variation_id );
}
$GLOBALS['vts_current_time'] = '2026-08-11 12:00:00';

vts_reset_migration_store();
$before_execute_failure = vts_store_snapshot();
$GLOBALS['vts_save_fail_once'][10460] = true;
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'restauradas'
);
vts_assert_same( $before_execute_failure, vts_store_snapshot(), 'falha de save restaura todos os registros alterados no execute' );
vts_assert_same( 6, $GLOBALS['vts_store_writes'], 'falha no terceiro save restaura as três tentativas' );
vts_assert_same( 0, vts_count_verified_backups(), 'falha de execute remove backups criados na tentativa' );

vts_reset_migration_store();
$before_verify_failure = vts_store_snapshot();
$GLOBALS['vts_save_corrupt_once'][10411] = true;
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'restauradas'
);
vts_assert_same( $before_verify_failure, vts_store_snapshot(), 'mismatch após releitura restaura todos os registros alterados' );
vts_assert_same( 4, $GLOBALS['vts_store_writes'], 'mismatch no segundo registro restaura duas tentativas' );

vts_reset_migration_store();
$migration_command->migrate( array(), array( 'execute' => true ) );
$migrated_before_rollback_failure = vts_store_snapshot();
$GLOBALS['vts_store_writes'] = 0;
$GLOBALS['vts_save_fail_once'][10411] = true;
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'rollback' => true ) );
	},
	'restauradas'
);
vts_assert_same( $migrated_before_rollback_failure, vts_store_snapshot(), 'falha de save no rollback restaura o estado pós-migração' );
vts_assert_same( 4, $GLOBALS['vts_store_writes'], 'falha no segundo rollback restaura duas tentativas' );

vts_reset_migration_store();
$migration_command->migrate( array(), array( 'execute' => true ) );
$migrated_before_rollback_mismatch = vts_store_snapshot();
$GLOBALS['vts_store_writes'] = 0;
$GLOBALS['vts_save_reinsert_empty_sheet_once'][10411] = true;
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'rollback' => true ) );
	},
	'restauradas'
);
vts_assert_same( $migrated_before_rollback_mismatch, vts_store_snapshot(), 'meta vazio reaparecido após save aciona compensação do rollback' );
vts_assert_same( 4, $GLOBALS['vts_store_writes'], 'mismatch físico no segundo rollback restaura duas tentativas' );

vts_reset_migration_store();
$before_incomplete_restore = vts_store_snapshot();
$GLOBALS['vts_save_corrupt_once'][10460]         = true;
$GLOBALS['vts_save_fail_before_on_call'][10460] = array( 2 );
vts_expect_cli_error(
	function () use ( $migration_command ) {
		$migration_command->migrate( array(), array( 'execute' => true ) );
	},
	'restauração incompleta'
);
$after_incomplete_restore = vts_store_snapshot();
vts_assert_same( $before_incomplete_restore[10410], $after_incomplete_restore[10410], 'compensação continua e restaura #10410 após falha em outro ID' );
vts_assert_same( $before_incomplete_restore[10411], $after_incomplete_restore[10411], 'compensação continua e restaura #10411 após falha em outro ID' );
vts_assert_true( $before_incomplete_restore[10460] !== $after_incomplete_restore[10460], 'ID cuja própria restauração falhou permanece detectavelmente divergente' );
vts_assert_same( 2, $GLOBALS['vts_save_calls_by_id'][10410], 'compensação ainda tenta #10410' );
vts_assert_same( 2, $GLOBALS['vts_save_calls_by_id'][10411], 'compensação ainda tenta #10411' );
vts_assert_same( 2, $GLOBALS['vts_save_calls_by_id'][10460], 'compensação registra a tentativa falha de #10460' );

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
