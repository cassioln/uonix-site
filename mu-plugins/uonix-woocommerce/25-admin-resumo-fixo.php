<?php
/**
 * Exibe a metabox postexcerpt em posição fixa antes do editor principal: a breve
 * descrição do produto e o Resumo dos posts do blog.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tipos de post atendidos e como o wrapper fixo de cada um é montado.
 *
 * O ID da tela de edição é o próprio tipo de post. `dica` liga a dica do
 * WooCommerce no cabeçalho; `inside` envolve o callback na div.inside que o
 * do_meta_boxes() usaria, para o textarea simples do Resumo ganhar o
 * espaçamento padrão (o editor TinyMCE do produto já preenche a caixa);
 * `oculta_ajuda` esconde, só na tela, o parágrafo de ajuda que o callback do
 * core imprime abaixo do textarea.
 *
 * @return array<string, array{classe: string, dica: bool, inside: bool, oculta_ajuda: bool}>
 */
function uonix_admin_resumo_fixo_tipos() {
	return array(
		'product' => array(
			'classe'       => 'uonix-product-short-description',
			'dica'         => true,
			'inside'       => false,
			'oculta_ajuda' => false,
		),
		'post'    => array(
			'classe'       => 'uonix-post-excerpt',
			'dica'         => false,
			'inside'       => true,
			'oculta_ajuda' => true,
		),
	);
}

/**
 * Devolve o tipo atendido do post, ou null.
 *
 * @param mixed $post Post sendo editado.
 * @return string|null
 */
function uonix_admin_resumo_fixo_tipo( $post ) {
	if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! is_string( $post->post_type ) ) {
		return null;
	}

	return array_key_exists( $post->post_type, uonix_admin_resumo_fixo_tipos() ) ? $post->post_type : null;
}

/**
 * Confirma o contrato mínimo da definição nativa da metabox postexcerpt.
 *
 * @param mixed $caixa Definição candidata da metabox.
 * @return bool
 */
function uonix_admin_resumo_fixo_caixa_utilizavel( $caixa ) {
	return
		is_array( $caixa ) &&
		isset( $caixa['id'], $caixa['title'], $caixa['callback'] ) &&
		'postexcerpt' === $caixa['id'] &&
		is_string( $caixa['title'] ) &&
		is_callable( $caixa['callback'] );
}

/**
 * Confirma que o registro está íntegro e sem definição ativa da metabox.
 *
 * @param array  $meta_boxes Registro global de metaboxes.
 * @param string $screen_id  ID da tela administrativa.
 * @return bool
 */
function uonix_admin_resumo_fixo_registro_livre( array $meta_boxes, $screen_id ) {
	if ( ! isset( $meta_boxes[ $screen_id ] ) || ! is_array( $meta_boxes[ $screen_id ] ) ) {
		return false;
	}

	$grupos_examinados = 0;

	foreach ( $meta_boxes[ $screen_id ] as $prioridades ) {
		if ( ! is_array( $prioridades ) ) {
			return false;
		}

		foreach ( $prioridades as $caixas ) {
			if ( ! is_array( $caixas ) ) {
				return false;
			}

			$grupos_examinados++;

			if ( array_key_exists( 'postexcerpt', $caixas ) && false !== $caixas['postexcerpt'] ) {
				return false;
			}
		}
	}

	return $grupos_examinados > 0;
}

/**
 * Localiza uma única definição ativa e utilizável da metabox postexcerpt.
 *
 * Marcadores false deixados por remove_meta_box() são ignorados. Qualquer
 * duplicidade ou definição ativa malformada mantém o comportamento original.
 *
 * @param array  $meta_boxes Registro global de metaboxes.
 * @param string $screen_id  ID da tela administrativa.
 * @return array|null Contexto, prioridade e definição original da metabox.
 */
function uonix_admin_resumo_fixo_localizar( array $meta_boxes, $screen_id ) {
	if ( ! isset( $meta_boxes[ $screen_id ] ) || ! is_array( $meta_boxes[ $screen_id ] ) ) {
		return null;
	}

	$encontradas = array();

	foreach ( $meta_boxes[ $screen_id ] as $contexto => $prioridades ) {
		if ( ! is_array( $prioridades ) ) {
			return null;
		}

		foreach ( $prioridades as $prioridade => $caixas ) {
			if ( ! is_array( $caixas ) ) {
				return null;
			}

			if ( ! in_array( $prioridade, array( 'high', 'core', 'default', 'low' ), true ) ) {
				if ( array_key_exists( 'postexcerpt', $caixas ) && false !== $caixas['postexcerpt'] ) {
					return null;
				}

				continue;
			}

			if ( ! array_key_exists( 'postexcerpt', $caixas ) ) {
				continue;
			}

			$caixa = $caixas['postexcerpt'];
			if ( false === $caixa ) {
				continue;
			}

			$encontradas[] = array(
				'context'  => $contexto,
				'priority' => $prioridade,
				'box'      => $caixa,
			);
		}
	}

	if ( 1 !== count( $encontradas ) ) {
		return null;
	}

	$encontrada = $encontradas[0];
	$caixa      = $encontrada['box'];

	if ( ! uonix_admin_resumo_fixo_caixa_utilizavel( $caixa ) ) {
		return null;
	}

	return $encontrada;
}

/**
 * Captura o callback original antes de o WordPress montar as Opções de tela.
 *
 * Se o contrato esperado não estiver íntegro, nada é removido e a metabox
 * original continua disponível.
 */
function uonix_admin_resumo_fixo_capturar() {
	global $post, $wp_meta_boxes;

	$capturada      = $GLOBALS['uonix_admin_resumo_fixo_capturada'] ?? null;
	$capturada_tipo = $GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] ?? null;
	$GLOBALS['uonix_admin_resumo_fixo_capturada']      = null;
	$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = null;

	$tipo = uonix_admin_resumo_fixo_tipo( $post );

	if (
		null === $tipo ||
		! function_exists( 'use_block_editor_for_post' ) ||
		use_block_editor_for_post( $post ) ||
		! is_array( $wp_meta_boxes )
	) {
		return;
	}

	$encontrada = uonix_admin_resumo_fixo_localizar( $wp_meta_boxes, $tipo );
	if ( null === $encontrada ) {
		if (
			$tipo === $capturada_tipo &&
			uonix_admin_resumo_fixo_caixa_utilizavel( $capturada ) &&
			uonix_admin_resumo_fixo_registro_livre( $wp_meta_boxes, $tipo )
		) {
			$GLOBALS['uonix_admin_resumo_fixo_capturada']      = $capturada;
			$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = $tipo;
		}

		return;
	}

	remove_meta_box( 'postexcerpt', $tipo, $encontrada['context'] );
	$GLOBALS['uonix_admin_resumo_fixo_capturada']      = $encontrada['box'];
	$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = $tipo;
}

/**
 * Renderiza o callback original da metabox fora das áreas reordenáveis.
 *
 * Este hook roda antes de o WordPress criar o editor principal #postdivrich.
 *
 * @param mixed $post Post sendo editado.
 */
function uonix_admin_resumo_fixo_renderizar( $post ) {
	global $wp_meta_boxes;

	$caixa          = $GLOBALS['uonix_admin_resumo_fixo_capturada'] ?? null;
	$capturada_tipo = $GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] ?? null;
	$GLOBALS['uonix_admin_resumo_fixo_capturada']      = null;
	$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = null;

	$tipo = uonix_admin_resumo_fixo_tipo( $post );

	// A captura só vale para a tela do mesmo tipo de onde a metabox saiu.
	if (
		null === $tipo ||
		$tipo !== $capturada_tipo ||
		! uonix_admin_resumo_fixo_caixa_utilizavel( $caixa )
	) {
		return;
	}

	if (
		! is_array( $wp_meta_boxes ) ||
		! uonix_admin_resumo_fixo_registro_livre( $wp_meta_boxes, $tipo )
	) {
		return;
	}

	$config = uonix_admin_resumo_fixo_tipos()[ $tipo ];

	if ( $config['oculta_ajuda'] ) {
		echo '<style>#postexcerpt.' . esc_attr( $config['classe'] ) . ' > .inside > p { display: none; }</style>';
	}
	?>
	<div id="postexcerpt" class="postarea postbox <?php echo esc_attr( $config['classe'] ); ?>">
		<h2 class="postbox-header">
			<label for="excerpt"><?php echo esc_html( $caixa['title'] ); ?></label>
			<?php
			if ( $config['dica'] ) {
				$dica = __( 'Summarize this product in 1-2 short sentences. We’ll show it at the top of the page.', 'woocommerce' );
				?>
				<span class="woocommerce-help-tip" tabindex="0" aria-label="<?php echo esc_attr( $dica ); ?>" data-tip="<?php echo esc_attr( $dica ); ?>"></span>
				<?php
			}
			?>
		</h2>
		<?php
		if ( $config['inside'] ) {
			echo '<div class="inside">';
		}
		call_user_func( $caixa['callback'], $post, $caixa );
		if ( $config['inside'] ) {
			echo '</div>';
		}
		?>
	</div>
	<?php
}

add_action( 'admin_head', 'uonix_admin_resumo_fixo_capturar', PHP_INT_MAX, 0 );
add_action( 'edit_form_after_title', 'uonix_admin_resumo_fixo_renderizar', 10, 1 );
