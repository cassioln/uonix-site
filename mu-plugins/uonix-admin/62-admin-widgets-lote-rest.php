<?php
/**
 * Editor de widgets: salvar sem passar pelo caminho batch/v1.
 *
 * Em produção, o nginx da Locaweb devolve 403 em HTML a todo POST cuja URL
 * contenha batch/v1 (medido em 2026-10-01, também via ?rest_route=). O editor
 * de widgets em blocos salva por POST /batch/v1, então todo "Atualizar" falha
 * com "A resposta não é um JSON válido". QA e local não têm esse bloqueio.
 *
 * A rota uonix/v1/lote usa os argumentos e o callback do lote do núcleo, e
 * o script desvia só o POST da tela de widgets para ela. Diferente do batch/v1,
 * que é aberto, ela exige edit_theme_options, a capability da tela de widgets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra uonix/v1/lote com a definição do batch/v1 do núcleo.
 *
 * @return void
 */
function uonix_admin_widgets_lote_registrar_rota() {
	$rotas = rest_get_server()->get_routes();

	if ( empty( $rotas['/batch/v1'][0]['args'] ) ) {
		return;
	}

	register_rest_route(
		'uonix/v1',
		'/lote',
		array(
			'methods'             => 'POST',
			'callback'            => 'uonix_admin_widgets_lote_servir',
			'permission_callback' => 'uonix_admin_widgets_lote_permissao',
			'args'                => $rotas['/batch/v1'][0]['args'],
		)
	);
}
add_action( 'rest_api_init', 'uonix_admin_widgets_lote_registrar_rota' );

/**
 * Só quem pode usar Aparência > Widgets envia lotes por esta rota.
 *
 * @return bool
 */
function uonix_admin_widgets_lote_permissao() {
	return current_user_can( 'edit_theme_options' );
}

/**
 * Atende o lote pelo mesmo método do batch/v1 do núcleo.
 *
 * A rota é registrada em `rest_api_init`, antes de qualquer filtro `rest_endpoints`
 * posterior. Se um plugin desligar o `/batch/v1` de propósito depois disso, esta rota
 * não pode continuar servindo o mesmo lote por outro caminho: a existência do
 * `/batch/v1` é conferida a cada chamada, e sem ele a resposta é 404 (#365).
 *
 * @param WP_REST_Request $request Requisição do lote.
 * @return WP_REST_Response|WP_Error
 */
function uonix_admin_widgets_lote_servir( WP_REST_Request $request ) {
	$servidor = rest_get_server();
	$rotas    = $servidor->get_routes();

	if ( empty( $rotas['/batch/v1'] ) ) {
		return new WP_Error( 'rest_no_route', 'Nenhuma rota correspondeu à URL e ao método de requisição.', array( 'status' => 404 ) );
	}

	return $servidor->serve_batch_request_v1( $request );
}

/**
 * Carrega o desvio do apiFetch só em Aparência > Widgets.
 *
 * @param string $hook_suffix Tela atual do admin.
 * @return void
 */
function uonix_admin_widgets_lote_enqueue( $hook_suffix ) {
	if ( 'widgets.php' !== $hook_suffix ) {
		return;
	}

	$script_relative = 'uonix-admin/assets/js/admin-widgets-lote.js';

	wp_enqueue_script(
		'uonix-admin-widgets-lote',
		UONIX_MU_URL . $script_relative,
		array( 'wp-api-fetch' ),
		(string) filemtime( UONIX_MU_PATH . $script_relative ),
		false
	);
}
add_action( 'admin_enqueue_scripts', 'uonix_admin_widgets_lote_enqueue' );
