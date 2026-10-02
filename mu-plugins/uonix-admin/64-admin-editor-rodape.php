<?php
/**
 * Perfil editor: tela de widgets vira "Editar Rodapé", só com as áreas do rodapé.
 *
 * O editor tem edit_theme_options, mas o menu Aparência fica oculto para ele
 * (39-admin-editor-dashboard.php). Este arquivo:
 *
 * 1. destaca o menu "Seções do Site" em widgets.php (o menu e o item
 *    "Rodapé" ficam em 67-admin-editor-menus.php);
 * 2. troca o título "Widgets" por "Editar Rodapé" (aba do navegador e o h1
 *    do editor em blocos, que vem do JavaScript);
 * 3. filtra as leituras REST de áreas e widgets para só as áreas footer*,
 *    o que esconde Barra Lateral, Max Mega Menu Widgets e Widgets inativos;
 * 4. recusa escrita REST fora do rodapé.
 *
 * O item 4 é a segurança; o 3 sozinho é só aparência. A área mega-menu guarda
 * o conteúdo do megamenu (ver 62-admin-widgets-lote-rest.php e o incidente de
 * 2026-10-02): um PUT em /wp/v2/sidebars/footerN tira de todas as outras áreas
 * os ids que receber em "widgets", então ele também é recusado quando traz um
 * widget que hoje mora fora do rodapé.
 *
 * Os ganchos rest_request_before_callbacks e rest_request_after_callbacks
 * valem para a chamada direta, para o pré-carregamento da tela e para cada
 * item do lote (batch/v1 e uonix/v1/lote).
 *
 * Administrador (manage_options) não é afetado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O usuário atual é um editor restrito ao rodapé?
 *
 * @return bool
 */
function uonix_admin_editor_rodape_ativo() {
	return current_user_can( 'edit_theme_options' ) && ! current_user_can( 'manage_options' );
}

/**
 * A área de widgets pertence ao rodapé (footer1 a footer6 do Kadence)?
 *
 * @param mixed $id Id da área.
 * @return bool
 */
function uonix_admin_editor_rodape_area_do_rodape( $id ) {
	return is_string( $id ) && 1 === preg_match( '/^footer[1-6]$/', $id );
}

/**
 * Em widgets.php, destaca "Seções do Site" (slug widgets.php) em vez do
 * Aparência oculto.
 *
 * @param string $parent_file Arquivo pai do menu.
 * @return string
 */
function uonix_admin_editor_rodape_parent_file( $parent_file ) {
	global $pagenow;

	if ( 'widgets.php' === $pagenow && uonix_admin_editor_rodape_ativo() ) {
		return 'widgets.php';
	}

	return $parent_file;
}
add_filter( 'parent_file', 'uonix_admin_editor_rodape_parent_file' );

/**
 * Título "Widgets" vira "Editar Rodapé" nas strings do PHP em widgets.php.
 *
 * Cobre o <title> da aba. O h1 do editor em blocos vem do JavaScript e é
 * tratado em uonix_admin_editor_rodape_titulo_js().
 *
 * @param string $traducao Texto traduzido.
 * @param string $texto    Texto original.
 * @param string $dominio  Domínio de tradução.
 * @return string
 */
function uonix_admin_editor_rodape_gettext( $traducao, $texto, $dominio ) {
	global $pagenow;

	if ( 'Widgets' === $texto && 'default' === $dominio && 'widgets.php' === $pagenow && uonix_admin_editor_rodape_ativo() ) {
		return 'Editar Rodapé';
	}

	return $traducao;
}
add_filter( 'gettext', 'uonix_admin_editor_rodape_gettext', 10, 3 );

/**
 * Título do editor em blocos: filtro i18n.gettext_default antes do wp-edit-widgets.
 *
 * O editor chama __( 'Widgets' ) sem domínio. O filtro genérico i18n.gettext
 * recebe o domínio como veio (undefined); só o i18n.gettext_default já vem
 * com o domínio normalizado. A categoria de bloco "Widgets" usa _x() com
 * contexto e passa por outro filtro, então não é renomeada.
 *
 * @param string $hook_suffix Tela atual.
 * @return void
 */
function uonix_admin_editor_rodape_titulo_js( $hook_suffix ) {
	if ( 'widgets.php' !== $hook_suffix || ! uonix_admin_editor_rodape_ativo() ) {
		return;
	}

	wp_add_inline_script(
		'wp-edit-widgets',
		"wp.hooks.addFilter( 'i18n.gettext_default', 'uonix/editar-rodape', function ( traducao, texto ) { return 'Widgets' === texto ? 'Editar Rodapé' : traducao; } );",
		'before'
	);
}
add_action( 'admin_enqueue_scripts', 'uonix_admin_editor_rodape_titulo_js' );

/**
 * Id da área a que o widget pertence hoje, ou null.
 *
 * @param string $widget_id Id do widget.
 * @return string|null
 */
function uonix_admin_editor_rodape_area_do_widget( $widget_id ) {
	return wp_find_widgets_sidebar( (string) $widget_id );
}

/**
 * O widget pode ser tocado pelo editor? Só se mora no rodapé ou em área nenhuma.
 *
 * Widgets inativos ficam de fora: estão ocultos na tela e trazê-los para o
 * rodapé tiraria do lugar um widget que o editor não enxerga.
 *
 * @param string $widget_id Id do widget.
 * @return bool
 */
function uonix_admin_editor_rodape_widget_liberado( $widget_id ) {
	$area = uonix_admin_editor_rodape_area_do_widget( $widget_id );

	return null === $area || uonix_admin_editor_rodape_area_do_rodape( $area );
}

/**
 * O id que o controlador vai usar é o mesmo da rota?
 *
 * No WP_REST_Request, JSON, POST e GET vêm antes do parâmetro da URL, e os
 * controladores de áreas e widgets usam $request['id']. Um "id" no corpo ou
 * na query mandaria o controlador agir em outra área ou widget que a guarda
 * não conferiu. O editor em blocos nunca manda id diferente do da URL.
 *
 * @param WP_REST_Request $request  Requisição.
 * @param string          $da_rota  Id capturado da rota.
 * @return bool
 */
function uonix_admin_editor_rodape_id_da_rota( $request, $da_rota ) {
	$id = $request->get_param( 'id' );

	return is_string( $id ) && $id === $da_rota;
}

/**
 * Erro padrão de recusa.
 *
 * @return WP_Error
 */
function uonix_admin_editor_rodape_recusa() {
	return new WP_Error(
		'uonix_editor_rodape_fora_do_rodape',
		'O perfil editor só pode alterar as áreas do rodapé.',
		array( 'status' => 403 )
	);
}

/**
 * Recusa leitura e escrita de áreas e widgets fora do rodapé.
 *
 * @param mixed           $resposta Resposta já definida ou null.
 * @param array           $handler  Definição da rota.
 * @param WP_REST_Request $request  Requisição.
 * @return mixed
 */
function uonix_admin_editor_rodape_guarda( $resposta, $handler, $request ) {
	if ( ! uonix_admin_editor_rodape_ativo() ) {
		return $resposta;
	}

	// O núcleo casa as rotas sem diferenciar maiúsculas (flag i), então a
	// guarda também; o id capturado fica como veio, igual ao do controlador.
	$rota   = untrailingslashit( (string) $request->get_route() );
	$metodo = strtoupper( (string) $request->get_method() );

	if ( 1 === preg_match( '#^/wp/v2/sidebars/([\w-]+)$#i', $rota, $m ) ) {
		if ( ! uonix_admin_editor_rodape_id_da_rota( $request, $m[1] ) || ! uonix_admin_editor_rodape_area_do_rodape( $m[1] ) ) {
			return uonix_admin_editor_rodape_recusa();
		}

		$widgets = $request->get_param( 'widgets' );
		if ( 'GET' !== $metodo && is_array( $widgets ) ) {
			foreach ( $widgets as $widget_id ) {
				if ( ! uonix_admin_editor_rodape_widget_liberado( $widget_id ) ) {
					return uonix_admin_editor_rodape_recusa();
				}
			}
		}

		return $resposta;
	}

	if ( 1 === preg_match( '#^/wp/v2/widgets/([\w-]+)$#i', $rota, $m ) ) {
		if ( ! uonix_admin_editor_rodape_id_da_rota( $request, $m[1] ) ) {
			return uonix_admin_editor_rodape_recusa();
		}

		$area = uonix_admin_editor_rodape_area_do_widget( $m[1] );

		if ( null !== $area && ! uonix_admin_editor_rodape_area_do_rodape( $area ) ) {
			return uonix_admin_editor_rodape_recusa();
		}

		$destino = $request->get_param( 'sidebar' );
		if ( 'GET' !== $metodo && null !== $destino && ! uonix_admin_editor_rodape_area_do_rodape( $destino ) ) {
			return uonix_admin_editor_rodape_recusa();
		}

		return $resposta;
	}

	if ( 0 === strcasecmp( $rota, '/wp/v2/widgets' ) && 'POST' === $metodo ) {
		if ( ! uonix_admin_editor_rodape_area_do_rodape( $request->get_param( 'sidebar' ) ) ) {
			return uonix_admin_editor_rodape_recusa();
		}

		// Com "id", o POST grava por cima do widget existente e o tira da área
		// onde estava (save_widget + wp_assign_widget_to_sidebar).
		$id = $request->get_param( 'id' );
		if ( null !== $id && ! uonix_admin_editor_rodape_widget_liberado( $id ) ) {
			return uonix_admin_editor_rodape_recusa();
		}
	}

	return $resposta;
}
add_filter( 'rest_request_before_callbacks', 'uonix_admin_editor_rodape_guarda', 10, 3 );

/**
 * Nas listagens, deixa só as áreas do rodapé e os widgets delas.
 *
 * @param mixed           $resposta Resposta do callback.
 * @param array           $handler  Definição da rota.
 * @param WP_REST_Request $request  Requisição.
 * @return mixed
 */
function uonix_admin_editor_rodape_filtra_listas( $resposta, $handler, $request ) {
	if ( ! uonix_admin_editor_rodape_ativo() || ! $resposta instanceof WP_REST_Response ) {
		return $resposta;
	}

	if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
		return $resposta;
	}

	$rota = untrailingslashit( (string) $request->get_route() );
	if ( 0 === strcasecmp( $rota, '/wp/v2/sidebars' ) ) {
		$campo = 'id';
	} elseif ( 0 === strcasecmp( $rota, '/wp/v2/widgets' ) ) {
		$campo = 'sidebar';
	} else {
		return $resposta;
	}

	$dados = $resposta->get_data();
	if ( ! is_array( $dados ) ) {
		return $resposta;
	}

	$filtrados = array();
	foreach ( $dados as $item ) {
		if ( is_array( $item ) && isset( $item[ $campo ] ) && uonix_admin_editor_rodape_area_do_rodape( $item[ $campo ] ) ) {
			$filtrados[] = $item;
		}
	}

	$resposta->set_data( $filtrados );

	return $resposta;
}
add_filter( 'rest_request_after_callbacks', 'uonix_admin_editor_rodape_filtra_listas', 10, 3 );
