<?php
/**
 * Prova o layout fixo e fail-closed da breve descrição do produto.
 */

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );

$GLOBALS['short_layout_actions']  = array();
$GLOBALS['short_layout_removed']  = array();
$GLOBALS['short_layout_callback'] = array();
$GLOBALS['short_layout_asserts']  = 0;
$GLOBALS['short_layout_use_block_editor'] = false;

function short_layout_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function short_layout_assert_same( $expected, $actual, $message ) {
	$GLOBALS['short_layout_asserts']++;

	if ( $expected !== $actual ) {
		short_layout_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function short_layout_assert_contains( $needle, $haystack, $message ) {
	$GLOBALS['short_layout_asserts']++;

	if ( false === strpos( $haystack, $needle ) ) {
		short_layout_fail( $message . '; trecho ausente=' . var_export( $needle, true ) );
	}
}

function short_layout_assert_not_contains( $needle, $haystack, $message ) {
	$GLOBALS['short_layout_asserts']++;

	if ( false !== strpos( $haystack, $needle ) ) {
		short_layout_fail( $message . '; trecho inesperado=' . var_export( $needle, true ) );
	}
}

function short_layout_assert_before( $first, $second, $haystack, $message ) {
	$GLOBALS['short_layout_asserts']++;
	$first_position  = strpos( $haystack, $first );
	$second_position = strpos( $haystack, $second );

	if ( false === $first_position || false === $second_position || $first_position >= $second_position ) {
		short_layout_fail( $message );
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['short_layout_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function remove_meta_box( $id, $screen, $context ) {
	global $wp_meta_boxes;

	$GLOBALS['short_layout_removed'][] = array(
		'id'      => $id,
		'screen'  => $screen,
		'context' => $context,
	);

	if ( ! isset( $wp_meta_boxes[ $screen ][ $context ] ) ) {
		return;
	}

	foreach ( array( 'high', 'core', 'default', 'low' ) as $priority ) {
		$wp_meta_boxes[ $screen ][ $context ][ $priority ][ $id ] = false;
	}
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function use_block_editor_for_post( $post ) {
	unset( $post );
	return $GLOBALS['short_layout_use_block_editor'];
}

function short_layout_excerpt_callback( $post, $box ) {
	$GLOBALS['short_layout_callback'][] = array(
		'post' => $post,
		'box'  => $box,
	);

	printf(
		'<div id="wp-excerpt-wrap"><textarea name="excerpt" id="excerpt">%s</textarea></div>',
		esc_html( $post->post_excerpt )
	);
}

function short_layout_box( $callback = 'short_layout_excerpt_callback', $title = 'Breve descrição sobre o produto' ) {
	return array(
		'id'       => 'postexcerpt',
		'title'    => $title,
		'callback' => $callback,
		'args'     => array( 'fixture' => 'preservado' ),
	);
}

function short_layout_registry( $box ) {
	return array(
		'product' => array(
			'normal' => array(
				'high'    => array( 'postexcerpt' => false ),
				'core'    => array(),
				'default' => array( 'postexcerpt' => $box ),
				'low'     => array(),
			),
		),
	);
}

function short_layout_active_postexcerpt_locations( array $meta_boxes, $screen_id ) {
	$locations = array();

	foreach ( $meta_boxes[ $screen_id ] ?? array() as $context => $priorities ) {
		foreach ( is_array( $priorities ) ? $priorities : array() as $priority => $boxes ) {
			if ( is_array( $boxes ) && array_key_exists( 'postexcerpt', $boxes ) && false !== $boxes['postexcerpt'] ) {
				$locations[] = $context . '/' . $priority;
			}
		}
	}

	return $locations;
}

$module_file = $repo_root . '/mu-plugins/uonix-woocommerce/25-admin-resumo-fixo.php';
if ( ! is_readable( $module_file ) ) {
	short_layout_fail( 'módulo do editor fixo ausente' );
}

require $module_file;

$registrations = $GLOBALS['short_layout_actions']['edit_form_after_title'] ?? array();
short_layout_assert_same( 1, count( $registrations ), 'edit_form_after_title registrado uma vez' );
short_layout_assert_same( 10, $registrations[0]['priority'], 'render usa a posição oficial após o título' );
short_layout_assert_same( 1, $registrations[0]['accepted_args'], 'render recebe o post atual' );
$capture_registrations = $GLOBALS['short_layout_actions']['admin_head'] ?? array();
short_layout_assert_same( 1, count( $capture_registrations ), 'captura registrada antes de o WordPress renderizar Opções de tela' );
short_layout_assert_same( PHP_INT_MAX, $capture_registrations[0]['priority'], 'captura roda depois dos demais ajustes administrativos' );
short_layout_assert_same( 0, $capture_registrations[0]['accepted_args'], 'captura não depende de argumentos artificiais do hook' );
short_layout_assert_same( array(), $GLOBALS['short_layout_actions']['add_meta_boxes'] ?? array(), 'módulo não cria janela de captura em add_meta_boxes' );

$active_box = short_layout_box();
$located    = uonix_admin_resumo_fixo_localizar( short_layout_registry( $active_box ), 'product' );
short_layout_assert_same( 'normal', $located['context'], 'localiza o contexto real da caixa' );
short_layout_assert_same( 'default', $located['priority'], 'localiza a prioridade real da caixa' );
short_layout_assert_same( $active_box, $located['box'], 'preserva toda a definição original da caixa' );

short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array(), 'product' ), 'registro sem a tela product não é considerado livre' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array() ), 'product' ), 'tela product sem prioridades examináveis não é considerada livre' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array( 'normal' => null ) ), 'product' ), 'contexto malformado não prova ausência de definição ativa' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array( 'normal' => null, 'advanced' => array( 'default' => array( 'postexcerpt' => false ) ) ) ), 'product' ), 'contexto malformado invalida também um grupo válido' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array( 'normal' => array( 'default' => null ) ) ), 'product' ), 'prioridade malformada não prova ausência de definição ativa' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array( 'normal' => array( 'default' => null ), 'advanced' => array( 'default' => array( 'postexcerpt' => false ) ) ) ), 'product' ), 'prioridade malformada invalida também um grupo válido' );
short_layout_assert_same( true, uonix_admin_resumo_fixo_registro_livre( array( 'product' => array( 'normal' => array( 'default' => array( 'postexcerpt' => false ) ) ) ), 'product' ), 'marcador false comprova que a definição foi removida' );
short_layout_assert_same( false, uonix_admin_resumo_fixo_registro_livre( short_layout_registry( $active_box ), 'product' ), 'definição ativa impede considerar o registro livre' );

$unknown_priority = array(
	'product' => array(
		'normal' => array(
			'custom' => array( 'postexcerpt' => $active_box ),
		),
	),
);
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( $unknown_priority, 'product' ),
	'prioridade que remove_meta_box não alcança preserva a caixa original'
);

$unknown_priority_with_valid = short_layout_registry( $active_box );
$unknown_priority_with_valid['product']['normal']['custom']['postexcerpt'] = $active_box;
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( $unknown_priority_with_valid, 'product' ),
	'prioridade ativa desconhecida invalida também uma definição removível válida'
);

$wrong_id       = short_layout_box();
$wrong_id['id'] = 'outro-editor';
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( short_layout_registry( $wrong_id ), 'product' ),
	'id divergente não é tratado como o editor nativo'
);

$invalid_title          = short_layout_box();
$invalid_title['title'] = array( 'não', 'renderizar' );
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( short_layout_registry( $invalid_title ), 'product' ),
	'título não textual falha de forma conservadora'
);

short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( short_layout_registry( short_layout_box( 'callback_inexistente' ) ), 'product' ),
	'callback inválido não é capturado'
);

$duplicates = short_layout_registry( $active_box );
$duplicates['product']['advanced']['high']['postexcerpt'] = $active_box;
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( $duplicates, 'product' ),
	'duas definições ativas falham de forma conservadora'
);

$malformed = short_layout_registry( $active_box );
$malformed['product']['advanced']['default']['postexcerpt'] = array( 'id' => 'postexcerpt' );
short_layout_assert_same(
	null,
	uonix_admin_resumo_fixo_localizar( $malformed, 'product' ),
	'definição malformada junto da válida impede remoção'
);

$capture = $capture_registrations[0]['callback'];
$render  = $registrations[0]['callback'];
$product_post = (object) array(
	'post_type'    => 'product',
	'post_excerpt' => 'Resumo preservado',
);

$valid_with_malformed_context = short_layout_registry( $active_box );
$valid_with_malformed_context['product']['side'] = null;
$GLOBALS['post']                              = $product_post;
$GLOBALS['wp_meta_boxes']                     = $valid_with_malformed_context;
$GLOBALS['short_layout_removed']              = array();
$GLOBALS['uonix_admin_resumo_fixo_capturada'] = null;
$capture();
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'contexto irmão malformado preserva a metabox nativa válida' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'contexto irmão malformado impede remoção parcial do registro' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'contexto irmão malformado não cria captura parcial' );

$valid_with_malformed_priority = short_layout_registry( $active_box );
$valid_with_malformed_priority['product']['side'] = array( 'default' => null );
$GLOBALS['post']                              = $product_post;
$GLOBALS['wp_meta_boxes']                     = $valid_with_malformed_priority;
$GLOBALS['short_layout_removed']              = array();
$GLOBALS['uonix_admin_resumo_fixo_capturada'] = null;
$capture();
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'prioridade irmã malformada preserva a metabox nativa válida' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'prioridade irmã malformada impede remoção parcial do registro' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'prioridade irmã malformada não cria captura parcial' );

$valid_with_malformed_unknown_priority = short_layout_registry( $active_box );
$valid_with_malformed_unknown_priority['product']['side'] = array( 'custom' => null );
$GLOBALS['post']                              = $product_post;
$GLOBALS['wp_meta_boxes']                     = $valid_with_malformed_unknown_priority;
$GLOBALS['short_layout_removed']              = array();
$GLOBALS['uonix_admin_resumo_fixo_capturada'] = null;
$capture();
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'prioridade desconhecida malformada preserva a metabox nativa válida' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'prioridade desconhecida malformada impede remoção parcial do registro' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'prioridade desconhecida malformada não cria captura parcial' );

$GLOBALS['post']                  = $product_post;
$GLOBALS['wp_meta_boxes']         = array(
	'product' => array(
		'advanced' => array(
			'default' => array( 'postexcerpt' => $active_box ),
		),
	),
);
$GLOBALS['short_layout_removed']  = array();
$GLOBALS['short_layout_callback'] = array();
$capture();
short_layout_assert_same( array(), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'captura remove a definição válida fora do contexto normal' );
short_layout_assert_same( 'advanced', $GLOBALS['short_layout_removed'][0]['context'] ?? null, 'remoção usa o contexto realmente localizado' );
ob_start();
$render( $product_post );
$advanced_context_html = ob_get_clean();
short_layout_assert_same( 1, substr_count( $advanced_context_html, 'id="postexcerpt"' ), 'contexto advanced também renderiza uma única cópia fixa' );
short_layout_assert_same( 1, count( $GLOBALS['short_layout_callback'] ), 'contexto advanced preserva o callback original' );

$non_product_post = (object) array(
	'post_type'    => 'post',
	'post_excerpt' => 'Não deve ser renderizado',
);
$GLOBALS['uonix_admin_resumo_fixo_capturada'] = $active_box;
$GLOBALS['short_layout_callback']             = array();
ob_start();
$render( $non_product_post );
$direct_wrong_type_html = ob_get_clean();
short_layout_assert_same( '', $direct_wrong_type_html, 'render recusa definição capturada fora do post type product' );
short_layout_assert_same( array(), $GLOBALS['short_layout_callback'], 'render fora de product não chama o callback capturado' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'tentativa de render consome a captura mesmo quando o post type diverge' );

$GLOBALS['uonix_admin_resumo_fixo_capturada'] = $active_box;
$GLOBALS['post']                 = $non_product_post;
$GLOBALS['wp_meta_boxes']        = short_layout_registry( $active_box );
$GLOBALS['short_layout_removed'] = array();
$capture();
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'captura fora de product elimina estado antigo antes de retornar' );
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'outro post type preserva a caixa ativa antes de Opções de tela' );
ob_start();
$render( $GLOBALS['post'] );
$wrong_type_html = ob_get_clean();
short_layout_assert_same( '', $wrong_type_html, 'outro post type não renderiza editor' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'outro post type não remove a caixa' );

$GLOBALS['post']                          = $product_post;
$GLOBALS['wp_meta_boxes']                 = short_layout_registry( $active_box );
$GLOBALS['short_layout_removed']          = array();
$GLOBALS['short_layout_use_block_editor'] = true;
$capture();
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'editor de blocos não guarda callback sem ponto de renderização clássico' );
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'editor de blocos preserva a metabox original' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'editor de blocos não remove a metabox original' );
$GLOBALS['short_layout_use_block_editor'] = false;

$GLOBALS['post'] = $product_post;
$GLOBALS['wp_meta_boxes'] = short_layout_registry( false );
$GLOBALS['uonix_admin_resumo_fixo_capturada'] = array(
	'id'       => 'postexcerpt',
	'title'    => 'Captura antiga inválida',
	'callback' => 'callback_inexistente',
);
$capture();
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'registro livre não preserva captura anterior malformada' );

$GLOBALS['post']                 = $product_post;
$GLOBALS['wp_meta_boxes']        = short_layout_registry( short_layout_box( 'callback_inexistente' ) );
$GLOBALS['short_layout_removed'] = array();
$capture();
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'callback inválido permanece nas Opções de tela nativas' );
ob_start();
$render( $product_post );
$invalid_callback_html = ob_get_clean();
short_layout_assert_same( '', $invalid_callback_html, 'callback inválido não cria cópia parcial' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'callback inválido preserva a metabox original' );

$GLOBALS['uonix_admin_resumo_fixo_capturada'] = array(
	'id'       => 'postexcerpt',
	'title'    => 'Breve descrição sobre o produto',
	'callback' => 'callback_inexistente',
);
$GLOBALS['wp_meta_boxes'] = short_layout_registry( false );
ob_start();
$render( $product_post );
$tampered_callback_html = ob_get_clean();
short_layout_assert_same( '', $tampered_callback_html, 'callback corrompido entre captura e renderização falha de forma conservadora' );

$GLOBALS['uonix_admin_resumo_fixo_capturada'] = $active_box;
$GLOBALS['wp_meta_boxes']                     = null;
$GLOBALS['short_layout_callback']             = array();
ob_start();
$render( $product_post );
$missing_registry_html = ob_get_clean();
short_layout_assert_same( '', $missing_registry_html, 'registro global indisponível impede renderização sem prova de unicidade' );
short_layout_assert_same( array(), $GLOBALS['short_layout_callback'], 'registro indisponível não chama o callback capturado' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'registro indisponível ainda consome o estado capturado' );

$GLOBALS['uonix_admin_resumo_fixo_capturada'] = $active_box;
$GLOBALS['wp_meta_boxes']                     = array();
ob_start();
$render( $product_post );
$missing_screen_html = ob_get_clean();
short_layout_assert_same( '', $missing_screen_html, 'registro sem a tela product não prova ausência de definição ativa' );
short_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'registro sem a tela product consome o estado capturado' );

$GLOBALS['post']                 = $product_post;
$GLOBALS['wp_meta_boxes']        = $duplicates;
$GLOBALS['short_layout_removed'] = array();
$capture();
short_layout_assert_same( array( 'normal/default', 'advanced/high' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'duplicidade permanece nas Opções de tela nativas' );
ob_start();
$render( $product_post );
$duplicate_html = ob_get_clean();
short_layout_assert_same( '', $duplicate_html, 'duplicidade não cria render ambíguo' );
short_layout_assert_same( array(), $GLOBALS['short_layout_removed'], 'duplicidade preserva todas as caixas' );

$GLOBALS['post']                  = $product_post;
$GLOBALS['wp_meta_boxes']         = short_layout_registry( $active_box );
$GLOBALS['short_layout_removed']  = array();
$GLOBALS['short_layout_callback'] = array();
$capture();
$GLOBALS['wp_meta_boxes']['product']['normal']['default']['postexcerpt'] = $active_box;
ob_start();
$render( $product_post );
$late_readded_html = ob_get_clean();
short_layout_assert_same( '', $late_readded_html, 'definição ativa reinserida depois da captura impede uma segunda cópia fixa' );
short_layout_assert_same( array(), $GLOBALS['short_layout_callback'], 'definição reinserida mantém o callback para o fluxo nativo' );
short_layout_assert_same( array( 'normal/default' ), short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ), 'definição reinserida permanece ativa no registro nativo' );

$GLOBALS['post']                  = $product_post;
$GLOBALS['wp_meta_boxes']         = short_layout_registry( $active_box );
$GLOBALS['short_layout_removed']  = array();
$GLOBALS['short_layout_callback'] = array();
$capture();
$GLOBALS['wp_meta_boxes']['product']['normal']['custom']['postexcerpt'] = $active_box;
$capture();
$GLOBALS['wp_meta_boxes']['product']['normal']['custom']['postexcerpt'] = false;
ob_start();
$render( $product_post );
$transient_ambiguous_html = ob_get_clean();
short_layout_assert_same( '', $transient_ambiguous_html, 'captura ambígua invalida estado antigo mesmo se a definição problemática desaparecer antes do render' );
short_layout_assert_same( array(), $GLOBALS['short_layout_callback'], 'estado antigo invalidado não executa callback após ambiguidade transitória' );

$unsafe_title                    = 'Breve <script>alert(1)</script> descrição';
$valid_box                       = short_layout_box( 'short_layout_excerpt_callback', $unsafe_title );
$GLOBALS['post']                 = $product_post;
$GLOBALS['wp_meta_boxes']        = short_layout_registry( $valid_box );
$GLOBALS['short_layout_removed'] = array();
$GLOBALS['short_layout_callback'] = array();

short_layout_assert_same(
	array( 'normal/default' ),
	short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ),
	'antes da captura a metabox ainda alimentaria Opções de tela'
);

$capture();

short_layout_assert_same(
	array(),
	short_layout_active_postexcerpt_locations( $GLOBALS['wp_meta_boxes'], 'product' ),
	'captura remove a breve descrição antes de Opções de tela ser montada'
);
short_layout_assert_same(
	array(
		array(
			'id'      => 'postexcerpt',
			'screen'  => 'product',
			'context' => 'normal',
		),
	),
	$GLOBALS['short_layout_removed'],
	'captura válida remove somente a metabox original'
);

$capture();
short_layout_assert_same( $valid_box, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'captura repetida preserva a definição já removida do registro' );
short_layout_assert_same( 1, count( $GLOBALS['short_layout_removed'] ), 'captura repetida não remove a metabox novamente' );

ob_start();
$render( $product_post );
echo '<div id="postdivrich"></div>';
$html = ob_get_clean();

short_layout_assert_same(
	array(
		array(
			'id'      => 'postexcerpt',
			'screen'  => 'product',
			'context' => 'normal',
		),
	),
	$GLOBALS['short_layout_removed'],
	'render válido remove somente a metabox original'
);

short_layout_assert_same( 1, substr_count( $html, 'id="postexcerpt"' ), 'há um único wrapper postexcerpt' );
short_layout_assert_contains( 'class="postarea postbox uonix-product-short-description"', $html, 'wrapper usa o fluxo fixo do editor principal' );
short_layout_assert_contains( '<label for="excerpt">', $html, 'título é associado ao editor excerpt' );
short_layout_assert_contains( 'Breve &lt;script&gt;alert(1)&lt;/script&gt; descrição', $html, 'título capturado é escapado' );
short_layout_assert_contains( 'class="woocommerce-help-tip"', $html, 'dica do WooCommerce é preservada sem usar alça móvel' );
short_layout_assert_contains( 'Summarize this product in 1-2 short sentences.', $html, 'dica mantém a orientação da breve descrição' );
short_layout_assert_contains( 'data-tip="Summarize this product in 1-2 short sentences.', $html, 'tooltip funciona pelo inicializador global do WooCommerce' );
short_layout_assert_contains( 'name="excerpt" id="excerpt"', $html, 'callback original mantém o campo nativo excerpt' );
short_layout_assert_not_contains( 'handle-actions', $html, 'wrapper fixo não tem ações de metabox' );
short_layout_assert_not_contains( 'handle-order-', $html, 'wrapper fixo não tem setas de ordem' );
short_layout_assert_not_contains( 'handlediv', $html, 'wrapper fixo não é recolhível' );
short_layout_assert_not_contains( 'ui-sortable', $html, 'wrapper fixo não pertence ao sortable' );
short_layout_assert_not_contains( 'hndle', $html, 'título fixo não simula alça de arraste' );
short_layout_assert_before( 'id="postexcerpt"', 'id="postdivrich"', $html, 'breve descrição é renderizada antes da descrição principal' );

short_layout_assert_same( 1, count( $GLOBALS['short_layout_callback'] ), 'callback original executado exatamente uma vez' );
short_layout_assert_same( $product_post, $GLOBALS['short_layout_callback'][0]['post'], 'callback recebe o mesmo post' );
short_layout_assert_same( $valid_box, $GLOBALS['short_layout_callback'][0]['box'], 'callback recebe a definição completa e argumentos originais' );

$render( $product_post );
short_layout_assert_same( 1, count( $GLOBALS['short_layout_callback'] ), 'render repetido não duplica o editor' );
short_layout_assert_same( 1, count( $GLOBALS['short_layout_removed'] ), 'render repetido não remove novamente' );

short_layout_assert_same( array(), $GLOBALS['short_layout_actions']['save_post'] ?? array(), 'módulo não cria salvamento paralelo no WordPress' );
short_layout_assert_same( array(), $GLOBALS['short_layout_actions']['woocommerce_process_product_meta'] ?? array(), 'módulo não cria salvamento paralelo no WooCommerce' );

$loader = file_get_contents( $repo_root . '/mu-plugins/uonix-woocommerce/module.php' );
short_layout_assert_contains( "'24-admin-resumo-editor-estavel.php'", $loader, 'fallback defensivo continua no loader' );
short_layout_assert_contains( "'25-admin-resumo-fixo.php'", $loader, 'loader inclui o novo módulo' );
short_layout_assert_before( "'24-admin-resumo-editor-estavel.php'", "'25-admin-resumo-fixo.php'", $loader, 'layout fixo carrega depois do fallback TinyMCE' );

printf(
	"PASS: breve descrição fixa acima da descrição principal (%d asserções).\n",
	$GLOBALS['short_layout_asserts']
);
