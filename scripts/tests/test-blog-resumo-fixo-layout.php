<?php
/**
 * Prova o Resumo fixo acima do conteúdo na edição de posts do blog.
 *
 * O caso do produto continua coberto por test-product-short-description-layout.php,
 * que precisa passar sem alteração.
 */

define( 'ABSPATH', __DIR__ );

$repo_root = dirname( __DIR__, 2 );

$GLOBALS['post_layout_actions']          = array();
$GLOBALS['post_layout_removed']          = array();
$GLOBALS['post_layout_callback']         = array();
$GLOBALS['post_layout_asserts']          = 0;
$GLOBALS['post_layout_use_block_editor'] = false;

function post_layout_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function post_layout_assert_same( $expected, $actual, $message ) {
	$GLOBALS['post_layout_asserts']++;

	if ( $expected !== $actual ) {
		post_layout_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function post_layout_assert_contains( $needle, $haystack, $message ) {
	$GLOBALS['post_layout_asserts']++;

	if ( false === strpos( $haystack, $needle ) ) {
		post_layout_fail( $message . '; trecho ausente=' . var_export( $needle, true ) );
	}
}

function post_layout_assert_not_contains( $needle, $haystack, $message ) {
	$GLOBALS['post_layout_asserts']++;

	if ( false !== strpos( $haystack, $needle ) ) {
		post_layout_fail( $message . '; trecho inesperado=' . var_export( $needle, true ) );
	}
}

function post_layout_assert_before( $first, $second, $haystack, $message ) {
	$GLOBALS['post_layout_asserts']++;
	$first_position  = strpos( $haystack, $first );
	$second_position = strpos( $haystack, $second );

	if ( false === $first_position || false === $second_position || $first_position >= $second_position ) {
		post_layout_fail( $message );
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['post_layout_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function remove_meta_box( $id, $screen, $context ) {
	global $wp_meta_boxes;

	$GLOBALS['post_layout_removed'][] = array(
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
	return $GLOBALS['post_layout_use_block_editor'];
}

// Imita post_excerpt_meta_box() do core: textarea simples e texto de ajuda.
function post_layout_excerpt_callback( $post, $box ) {
	$GLOBALS['post_layout_callback'][] = array(
		'post' => $post,
		'box'  => $box,
	);

	printf(
		'<label class="screen-reader-text" for="excerpt">Resumo</label><textarea rows="1" cols="40" name="excerpt" id="excerpt">%s</textarea><p>Resumos são pequenas descrições opcionais.</p>',
		esc_html( $post->post_excerpt )
	);
}

function post_layout_box( $title = 'Resumo' ) {
	return array(
		'id'       => 'postexcerpt',
		'title'    => $title,
		'callback' => 'post_layout_excerpt_callback',
		'args'     => null,
	);
}

// O core registra o Resumo do post em normal/core.
function post_layout_registry( $screen, $box ) {
	return array(
		$screen => array(
			'normal' => array(
				'high'    => array(),
				'core'    => array( 'postexcerpt' => $box ),
				'default' => array(),
				'low'     => array(),
			),
		),
	);
}

function post_layout_active_locations( array $meta_boxes, $screen_id ) {
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

function post_layout_reset( $post, $registry ) {
	$GLOBALS['post']                                   = $post;
	$GLOBALS['wp_meta_boxes']                          = $registry;
	$GLOBALS['post_layout_removed']                    = array();
	$GLOBALS['post_layout_callback']                   = array();
	$GLOBALS['uonix_admin_resumo_fixo_capturada']      = null;
	$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = null;
}

$module_file = $repo_root . '/mu-plugins/uonix-woocommerce/25-admin-resumo-fixo.php';
if ( ! is_readable( $module_file ) ) {
	post_layout_fail( 'módulo do resumo fixo ausente' );
}

require $module_file;

$capture = $GLOBALS['post_layout_actions']['admin_head'][0]['callback'] ?? null;
$render  = $GLOBALS['post_layout_actions']['edit_form_after_title'][0]['callback'] ?? null;
post_layout_assert_same( true, is_callable( $capture ), 'captura registrada em admin_head' );
post_layout_assert_same( true, is_callable( $render ), 'render registrado em edit_form_after_title' );

$blog_post = (object) array(
	'post_type'    => 'post',
	'post_excerpt' => 'Entenda o que é esse dispositivo.',
);
$box       = post_layout_box();

// 1. Post no editor clássico: o Resumo sai da área móvel e vai para cima do conteúdo.
post_layout_reset( $blog_post, post_layout_registry( 'post', $box ) );
post_layout_assert_same( array( 'normal/core' ), post_layout_active_locations( $GLOBALS['wp_meta_boxes'], 'post' ), 'antes da captura o Resumo alimentaria Opções de tela' );
$capture();
post_layout_assert_same( array(), post_layout_active_locations( $GLOBALS['wp_meta_boxes'], 'post' ), 'captura tira o Resumo de Opções de tela' );
post_layout_assert_same(
	array(
		array(
			'id'      => 'postexcerpt',
			'screen'  => 'post',
			'context' => 'normal',
		),
	),
	$GLOBALS['post_layout_removed'],
	'captura remove somente a metabox postexcerpt da tela post'
);

ob_start();
$render( $blog_post );
echo '<div id="postdivrich"></div>';
$html = ob_get_clean();

post_layout_assert_same( 1, substr_count( $html, 'id="postexcerpt"' ), 'há um único wrapper postexcerpt' );
post_layout_assert_contains( 'class="postarea postbox uonix-post-excerpt"', $html, 'wrapper do post usa a classe própria' );
post_layout_assert_not_contains( 'uonix-product-short-description', $html, 'post não herda a classe do produto' );
post_layout_assert_contains( '<label for="excerpt">Resumo</label>', $html, 'cabeçalho usa o título da metabox nativa' );
post_layout_assert_not_contains( 'woocommerce-help-tip', $html, 'post não recebe a dica do WooCommerce' );
post_layout_assert_contains( 'name="excerpt" id="excerpt"', $html, 'callback nativo mantém o campo excerpt' );
post_layout_assert_before( '<div class="inside">', 'id="excerpt"', $html, 'campo do post fica dentro da div.inside padrão' );
post_layout_assert_before( 'Resumos são pequenas descrições opcionais.</p></div>', 'id="postdivrich"', $html, 'div.inside fecha depois do texto de ajuda e antes do editor' );
post_layout_assert_contains( '<style>#postexcerpt.uonix-post-excerpt > .inside > p { display: none; }</style>', $html, 'texto de ajuda do core fica oculto só na caixa fixa do post' );
post_layout_assert_not_contains( 'handlediv', $html, 'wrapper fixo não é recolhível' );
post_layout_assert_not_contains( 'hndle', $html, 'wrapper fixo não simula alça de arraste' );
post_layout_assert_before( 'id="postexcerpt"', 'id="postdivrich"', $html, 'Resumo é renderizado antes do editor de conteúdo' );
post_layout_assert_same( 1, count( $GLOBALS['post_layout_callback'] ), 'callback nativo executado exatamente uma vez' );
post_layout_assert_same( $blog_post, $GLOBALS['post_layout_callback'][0]['post'], 'callback recebe o mesmo post' );
post_layout_assert_same( $box, $GLOBALS['post_layout_callback'][0]['box'], 'callback recebe a definição original' );

$render( $blog_post );
post_layout_assert_same( 1, count( $GLOBALS['post_layout_callback'] ), 'render repetido não duplica o Resumo' );

// 2. Post no editor de blocos: nada muda.
post_layout_reset( $blog_post, post_layout_registry( 'post', $box ) );
$GLOBALS['post_layout_use_block_editor'] = true;
$capture();
post_layout_assert_same( array(), $GLOBALS['post_layout_removed'], 'editor de blocos não remove o Resumo' );
post_layout_assert_same( array( 'normal/core' ), post_layout_active_locations( $GLOBALS['wp_meta_boxes'], 'post' ), 'editor de blocos preserva a metabox nativa' );
post_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'editor de blocos não guarda captura' );
$GLOBALS['post_layout_use_block_editor'] = false;

// 3. Página: tipo fora da lista, nada muda.
$page = (object) array(
	'post_type'    => 'page',
	'post_excerpt' => '',
);
post_layout_reset( $page, post_layout_registry( 'page', $box ) );
$capture();
post_layout_assert_same( array(), $GLOBALS['post_layout_removed'], 'página não perde o Resumo' );
post_layout_assert_same( array( 'normal/core' ), post_layout_active_locations( $GLOBALS['wp_meta_boxes'], 'page' ), 'página preserva a metabox nativa' );
ob_start();
$render( $page );
post_layout_assert_same( '', ob_get_clean(), 'página não recebe Resumo fixo' );

// 4. Captura de um tipo nunca é desenhada em outro.
$product = (object) array(
	'post_type'    => 'product',
	'post_excerpt' => 'Breve descrição',
);
$product_box = post_layout_box( 'Breve descrição sobre o produto' );
post_layout_reset( $product, post_layout_registry( 'product', $product_box ) );
$capture();
post_layout_assert_same( 'product', $GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'], 'captura registra o tipo de origem' );
$GLOBALS['wp_meta_boxes']['post'] = array( 'normal' => array( 'core' => array( 'postexcerpt' => false ) ) );
ob_start();
$render( $blog_post );
post_layout_assert_same( '', ob_get_clean(), 'captura do produto não é desenhada num post' );
post_layout_assert_same( array(), $GLOBALS['post_layout_callback'], 'callback do produto não roda num post' );
post_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'render recusado ainda consome a captura' );
post_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'], 'render recusado consome também o tipo capturado' );

post_layout_reset( $blog_post, post_layout_registry( 'post', $box ) );
$capture();
$GLOBALS['wp_meta_boxes']['product'] = array( 'normal' => array( 'default' => array( 'postexcerpt' => false ) ) );
ob_start();
$render( $product );
post_layout_assert_same( '', ob_get_clean(), 'captura do post não é desenhada num produto' );

// Captura repetida com registro já livre só preserva o estado do mesmo tipo.
post_layout_reset( $blog_post, post_layout_registry( 'post', false ) );
$GLOBALS['uonix_admin_resumo_fixo_capturada']      = $product_box;
$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = 'product';
$capture();
post_layout_assert_same( null, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'captura repetida descarta estado de outro tipo' );

post_layout_reset( $blog_post, post_layout_registry( 'post', false ) );
$GLOBALS['uonix_admin_resumo_fixo_capturada']      = $box;
$GLOBALS['uonix_admin_resumo_fixo_capturada_tipo'] = 'post';
$capture();
post_layout_assert_same( $box, $GLOBALS['uonix_admin_resumo_fixo_capturada'], 'captura repetida preserva o estado do mesmo tipo' );

// O produto continua com a dica do WooCommerce e sem a div.inside.
post_layout_reset( $product, post_layout_registry( 'product', $product_box ) );
$capture();
ob_start();
$render( $product );
$product_html = ob_get_clean();
post_layout_assert_contains( 'class="postarea postbox uonix-product-short-description"', $product_html, 'produto mantém a classe própria' );
post_layout_assert_contains( 'class="woocommerce-help-tip"', $product_html, 'produto mantém a dica do WooCommerce' );
post_layout_assert_not_contains( 'class="inside"', $product_html, 'produto não ganha a div.inside' );
post_layout_assert_not_contains( '<style>', $product_html, 'produto não recebe o CSS que oculta a ajuda' );

// 5. Duplicidade na tela post falha de forma conservadora, como no produto.
$duplicada = post_layout_registry( 'post', $box );
$duplicada['post']['side']['default']['postexcerpt'] = $box;
post_layout_reset( $blog_post, $duplicada );
$capture();
post_layout_assert_same( array(), $GLOBALS['post_layout_removed'], 'duplicidade preserva as metaboxes nativas' );
ob_start();
$render( $blog_post );
post_layout_assert_same( '', ob_get_clean(), 'duplicidade não cria render ambíguo' );

printf(
	"PASS: Resumo fixo acima do conteúdo na edição de posts (%d asserções).\n",
	$GLOBALS['post_layout_asserts']
);
