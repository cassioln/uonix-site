<?php
/**
 * Verifica o ciclo real da ficha técnica em uma instalação WooCommerce descartável.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "FAIL: WordPress não está carregado.\n" );
	exit( 1 );
}

/**
 * @param bool   $condition Condição esperada.
 * @param string $message Diagnóstico seguro.
 */
function uonix_vts_verify_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param mixed  $expected Valor esperado.
 * @param mixed  $actual Valor observado.
 * @param string $message Diagnóstico seguro.
 */
function uonix_vts_verify_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param string $needle Trecho esperado.
 * @param mixed  $haystack Texto observado.
 * @param string $message Diagnóstico seguro.
 */
function uonix_vts_verify_contains( $needle, $haystack, $message ) {
	if ( ! is_string( $haystack ) || false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param string $needle Trecho proibido.
 * @param mixed  $haystack Texto observado.
 * @param string $message Diagnóstico seguro.
 */
function uonix_vts_verify_not_contains( $needle, $haystack, $message ) {
	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Aciona o hook público e deixa o save exclusivamente a cargo do chamador WooCommerce.
 *
 * @param WC_Product_Variation $variation Variação atual.
 * @param bool                 $include_field Se o campo deve existir em POST.
 * @param string               $payload Envelope bruto.
 * @return WC_Product_Variation
 */
function uonix_vts_verify_save_cycle( $variation, $include_field, $payload = '' ) {
	$previous_post = $_POST;
	try {
		$_POST = array();
		if ( $include_field ) {
			$_POST['uonix_variation_technical_sheet'] = array( 0 => $payload );
		}
		do_action( 'woocommerce_admin_process_variation_object', $variation, 0 );
	} finally {
		$_POST = $previous_post;
	}
	$variation->save();
	$reloaded = wc_get_product( $variation->get_id() );
	uonix_vts_verify_true( $reloaded instanceof WC_Product_Variation, 'A variação não pôde ser relida após o save.' );
	return $reloaded;
}

/**
 * Remove todas as fixtures e comprova que nenhum filho ficou órfão.
 *
 * @param array<string, mixed> $state Estado mutável do verificador.
 * @return array<int, string>
 */
function uonix_vts_verify_cleanup( &$state ) {
	if ( ! empty( $state['cleanup_done'] ) || ! empty( $state['cleanup_running'] ) ) {
		return array();
	}
	$state['cleanup_running'] = true;
	$errors                   = array();

	try {
		wp_set_current_user( absint( $state['original_user_id'] ) );
	} catch ( Throwable $exception ) {
		$errors[] = 'não foi possível restaurar o usuário original';
	}

	$variation_id = absint( $state['variation_id'] );
	$parent_id    = absint( $state['parent_id'] );
	$user_id      = absint( $state['user_id'] );

	if ( $variation_id && get_post( $variation_id ) && false === wp_delete_post( $variation_id, true ) ) {
		$errors[] = 'não foi possível excluir a variação temporária';
	}
	if ( $parent_id && get_post( $parent_id ) && false === wp_delete_post( $parent_id, true ) ) {
		$errors[] = 'não foi possível excluir o produto temporário';
	}

	if ( $parent_id ) {
		$orphans = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'any',
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( ! empty( $orphans ) ) {
			$errors[] = 'o cleanup deixou variação órfã';
		}
		if ( get_post( $parent_id ) ) {
			$errors[] = 'o produto temporário ainda existe após o cleanup';
		}
	}
	if ( $variation_id && get_post( $variation_id ) ) {
		$errors[] = 'a variação temporária ainda existe após o cleanup';
	}

	if ( $user_id ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( get_userdata( $user_id ) && ! wp_delete_user( $user_id ) ) {
			$errors[] = 'não foi possível excluir o usuário temporário';
		}
		if ( get_userdata( $user_id ) ) {
			$errors[] = 'o usuário temporário ainda existe após o cleanup';
		}
	}

	$state['cleanup_running'] = false;
	$state['cleanup_done']    = empty( $errors );
	return $errors;
}

$required_classes = array(
	'WC_Product_Variable',
	'WC_Product_Variation',
	'Uonix_VTS_Schema',
	'Uonix_VTS_Admin',
	'Uonix_VTS_Renderer',
);
foreach ( $required_classes as $required_class ) {
	if ( ! class_exists( $required_class ) ) {
		fwrite( STDERR, "FAIL: WooCommerce ou a ficha técnica não estão carregados.\n" );
		exit( 1 );
	}
}
foreach ( array( 'wc_get_product', 'wp_insert_user', 'metadata_exists' ) as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		fwrite( STDERR, "FAIL: o runtime WordPress/WooCommerce está incompleto.\n" );
		exit( 1 );
	}
}

$state = array(
	'original_user_id' => get_current_user_id(),
	'user_id'          => 0,
	'parent_id'        => 0,
	'variation_id'     => 0,
	'cleanup_running'  => false,
	'cleanup_done'     => false,
);
register_shutdown_function(
	function () use ( &$state ) {
		if ( empty( $state['cleanup_done'] ) ) {
			foreach ( uonix_vts_verify_cleanup( $state ) as $cleanup_error ) {
				error_log( 'Ficha Técnica Hermes: ' . $cleanup_error );
			}
		}
	}
);

$failure = null;
try {
	$pid        = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
	$run_nonce  = wp_rand( 100000, 999999 );
	$prefix     = 'TESTE Ficha Técnica Hermes ' . $pid . '-' . $run_nonce;
	$login      = 'uonix_vts_' . $pid . '_' . $run_nonce;

	uonix_vts_verify_true( null !== get_role( 'shop_manager' ), 'A função shop_manager não está disponível.' );
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.com',
			'user_pass'  => wp_generate_password( 32, true, true ),
			'display_name' => $prefix,
			'role'       => 'shop_manager',
		)
	);
	uonix_vts_verify_true( ! is_wp_error( $user_id ) && absint( $user_id ) > 0, 'Não foi possível criar o shop_manager temporário.' );
	$state['user_id'] = absint( $user_id );

	$parent = new WC_Product_Variable();
	$parent->set_name( $prefix );
	$parent->set_status( 'draft' );
	$parent->set_catalog_visibility( 'hidden' );
	$parent_id = $parent->save();
	uonix_vts_verify_true( absint( $parent_id ) > 0, 'Não foi possível criar o produto variável temporário.' );
	$state['parent_id'] = absint( $parent_id );

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $state['parent_id'] );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '10' );
	$variation->set_description( '<p>Descrição livre</p>' );
	$variation_id = $variation->save();
	uonix_vts_verify_true( absint( $variation_id ) > 0, 'Não foi possível criar a variação temporária.' );
	$state['variation_id'] = absint( $variation_id );

	wp_set_current_user( $state['user_id'] );
	uonix_vts_verify_true( current_user_can( 'edit_post', $state['parent_id'] ), 'O shop_manager não recebeu edit_post no produto pai.' );

	$fixture_sheet = array(
		'version'  => 1,
		'title'    => 'Ficha verificada',
		'sections' => array(
			array(
				'title'  => 'Medidas',
				'layout' => 'compact',
				'items'  => array(
					array( 'label' => 'A', 'value' => '37' ),
					array( 'label' => 'Teste seguro', 'value' => '<script>alert(1)</script>visível' ),
				),
			),
		),
	);
	$payload = wp_json_encode( array( 'action' => 'upsert', 'sheet' => $fixture_sheet ) );
	uonix_vts_verify_true( is_string( $payload ), 'Não foi possível serializar a fixture.' );
	$reloaded = uonix_vts_verify_save_cycle( $variation, true, $payload );
	$stored   = $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true );
	uonix_vts_verify_true( is_array( $stored ), 'O hook real não persistiu uma ficha estruturada.' );
	uonix_vts_verify_same( '37', $stored['sections'][0]['items'][0]['value'], 'O shop_manager não salvou a ficha pelo hook real.' );
	$stored_json = wp_json_encode( $stored );
	uonix_vts_verify_true( is_string( $stored_json ), 'A ficha persistida não pôde ser serializada para inspeção.' );
	uonix_vts_verify_not_contains( '<script', $stored_json, 'A tentativa de script persistiu como markup.' );

	$frontend = apply_filters(
		'woocommerce_available_variation',
		array( 'variation_description' => '<p>Descrição livre</p>' ),
		$parent,
		$reloaded
	);
	uonix_vts_verify_true( is_array( $frontend ) && isset( $frontend['variation_description'] ), 'O filtro frontend não retornou o payload esperado.' );
	uonix_vts_verify_contains( '<p>Descrição livre</p>', $frontend['variation_description'], 'A descrição livre não foi preservada.' );
	uonix_vts_verify_contains( 'uonix-vts', $frontend['variation_description'], 'O renderer não foi executado pelo filtro real.' );
	uonix_vts_verify_contains( '37', $frontend['variation_description'], 'O valor da ficha não foi renderizado.' );
	uonix_vts_verify_not_contains( '<script', $frontend['variation_description'], 'A tentativa de script virou markup executável.' );

	$before_invalid = $stored;
	$reloaded       = uonix_vts_verify_save_cycle( $reloaded, true, '{invalid' );
	uonix_vts_verify_same( $before_invalid, $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true ), 'JSON inválido alterou a ficha anterior.' );

	$reloaded = uonix_vts_verify_save_cycle( $reloaded, false );
	uonix_vts_verify_same( $before_invalid, $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true ), 'Campo ausente alterou a ficha anterior.' );

	$unauthorized_sheet = $fixture_sheet;
	$unauthorized_sheet['sections'][0]['items'][0]['value'] = '999';
	$unauthorized_payload = wp_json_encode( array( 'action' => 'upsert', 'sheet' => $unauthorized_sheet ) );
	uonix_vts_verify_true( is_string( $unauthorized_payload ), 'Não foi possível serializar a tentativa não autorizada.' );
	wp_set_current_user( 0 );
	$reloaded = uonix_vts_verify_save_cycle( $reloaded, true, $unauthorized_payload );
	uonix_vts_verify_same( $before_invalid, $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true ), 'Usuário sem edit_post sobrescreveu a ficha.' );

	wp_set_current_user( $state['user_id'] );
	$delete_payload = wp_json_encode( array( 'action' => 'delete' ) );
	uonix_vts_verify_true( is_string( $delete_payload ), 'Não foi possível serializar o delete explícito.' );
	$reloaded = uonix_vts_verify_save_cycle( $reloaded, true, $delete_payload );
	uonix_vts_verify_true( ! metadata_exists( 'post', $state['variation_id'], Uonix_VTS_Schema::META_KEY ), 'Delete explícito não removeu fisicamente a ficha.' );
	uonix_vts_verify_same( '<p>Descrição livre</p>', $reloaded->get_description( 'edit' ), 'O ciclo da ficha alterou a descrição livre.' );
} catch ( Throwable $exception ) {
	$failure = $exception;
}

$cleanup_errors = uonix_vts_verify_cleanup( $state );
if ( ! empty( $cleanup_errors ) ) {
	$cleanup_failure = new RuntimeException( implode( '; ', $cleanup_errors ) );
	$failure         = null === $failure ? $cleanup_failure : new RuntimeException( $failure->getMessage() . '; ' . $cleanup_failure->getMessage() );
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}

printf( "PASS: ficha técnica percorreu save, reload, frontend, autorização e cleanup sem órfãos.\n" );
