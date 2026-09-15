<?php
/**
 * Testes unitários para 54-comments-nosnippet-seo.php (Issue #187).
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['uonix_test_filters'] = array();
$GLOBALS['uonix_test_actions'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['uonix_test_filters'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['uonix_test_actions'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

$target_file = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-integrations/54-comments-nosnippet-seo.php';
if ( ! file_exists( $target_file ) ) {
	fwrite( STDERR, "FAIL: 54-comments-nosnippet-seo.php nao encontrado em {$target_file}\n" );
	exit( 1 );
}

require_once $target_file;

$failures = 0;

function uonix_test_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		++$failures;
	}
}

// 1. Validar hooks essenciais registrados
$registered_filters = array();
foreach ( $GLOBALS['uonix_test_filters'] as $f ) {
	$registered_filters[ $f['hook'] ][] = $f;
}

$registered_actions = array();
foreach ( $GLOBALS['uonix_test_actions'] as $a ) {
	$registered_actions[ $a['hook'] ][] = $a;
}

uonix_test_assert( isset( $registered_actions['comment_form_before'] ), 'comment_form_before registrado' );
uonix_test_assert( isset( $registered_actions['comment_form_after'] ), 'comment_form_after registrado' );
uonix_test_assert( isset( $registered_actions['kadence_before_comments'] ), 'kadence_before_comments registrado' );
uonix_test_assert( isset( $registered_actions['kadence_after_comments'] ), 'kadence_after_comments registrado' );
uonix_test_assert( isset( $registered_filters['comment_form_defaults'] ), 'comment_form_defaults registrado' );
uonix_test_assert( isset( $registered_filters['comment_reply_link'] ), 'comment_reply_link registrado' );
uonix_test_assert( isset( $registered_filters['cancel_comment_reply_link'] ), 'cancel_comment_reply_link registrado' );
uonix_test_assert( isset( $registered_filters['robots_txt'] ), 'robots_txt registrado' );
uonix_test_assert( isset( $registered_filters['rank_math/frontend/remove_reply_to_com'] ), 'rank_math/frontend/remove_reply_to_com registrado' );

// 2. Testar comportamento do comment_form_defaults
$sample_defaults = array(
	'title_reply_before'   => '<h3 id="reply-title" class="comment-reply-title">',
	'comment_notes_before' => '<p class="comment-notes"><span id="email-notes">O seu endereço de e-mail não será publicado.</span></p>',
	'submit_field'         => '<p class="form-submit">%1$s %2$s</p>',
	'other_field'          => '<div>mantem intocado</div>',
);
$filtered_defaults = uonix_comments_form_defaults_nosnippet( $sample_defaults );

uonix_test_assert(
	false !== strpos( $filtered_defaults['title_reply_before'], 'data-nosnippet' ),
	'title_reply_before contem data-nosnippet'
);
uonix_test_assert(
	false !== strpos( $filtered_defaults['comment_notes_before'], 'data-nosnippet' ),
	'comment_notes_before contem data-nosnippet'
);
uonix_test_assert(
	false !== strpos( $filtered_defaults['submit_field'], 'data-nosnippet' ),
	'submit_field contem data-nosnippet'
);
uonix_test_assert(
	false === strpos( $filtered_defaults['other_field'], 'data-nosnippet' ),
	'campos fora da lista nao sao afetados'
);

// 3. Testar nofollow em comment_reply_link
$link_without_rel = '<a href="?replytocom=1#respond" class="comment-reply-link">Responder</a>';
$link_processed   = uonix_comment_reply_link_nofollow( $link_without_rel );
uonix_test_assert(
	false !== strpos( $link_processed, 'rel="nofollow"' ),
	'comment_reply_link sem rel ganha rel="nofollow"'
);

$link_with_ugc       = '<a href="?replytocom=1#respond" rel="ugc" class="comment-reply-link">Responder</a>';
$link_with_ugc_fixed = uonix_comment_reply_link_nofollow( $link_with_ugc );
uonix_test_assert(
	false !== strpos( $link_with_ugc_fixed, 'nofollow' ),
	'comment_reply_link com rel="ugc" recebe nofollow adicional'
);

// 4. Testar cancel_comment_reply_link
$cancel_link     = '<a id="cancel-comment-reply-link" href="#respond">Cancelar resposta</a>';
$cancel_filtered = uonix_cancel_comment_reply_link_nofollow( $cancel_link );
uonix_test_assert(
	false !== strpos( $cancel_filtered, 'rel="nofollow"' ),
	'cancel_comment_reply_link ganha rel="nofollow"'
);
uonix_test_assert(
	false !== strpos( $cancel_filtered, 'data-nosnippet' ),
	'cancel_comment_reply_link e envolvido com data-nosnippet'
);

// 5. Testar robots.txt
$robots_initial = "User-agent: *\nDisallow: /wp-admin/\n";
$robots_public  = uonix_comments_robots_txt_replytocom( $robots_initial, '1' );
uonix_test_assert(
	false !== strpos( $robots_public, 'Disallow: /*?replytocom=*' ),
	'robots.txt publico inclui Disallow: /*?replytocom=*'
);

// Nao deve duplicar se ja existir
$robots_idempotent = uonix_comments_robots_txt_replytocom( $robots_public, '1' );
uonix_test_assert(
	substr_count( $robots_idempotent, 'replytocom' ) === 1,
	'robots.txt nao duplica regra se ja existir'
);

// Se blog nao for publico (0), nao altera
$robots_private = uonix_comments_robots_txt_replytocom( $robots_initial, '0' );
uonix_test_assert(
	$robots_private === $robots_initial,
	'robots.txt privado permanece inalterado'
);

// 6. Testar output dos containers
ob_start();
uonix_comments_form_nosnippet_before();
$output_before = ob_get_clean();
uonix_test_assert(
	false !== strpos( $output_before, 'data-nosnippet' ),
	'uonix_comments_form_nosnippet_before imprime data-nosnippet'
);

ob_start();
uonix_comments_form_nosnippet_after();
$output_after = ob_get_clean();
uonix_test_assert(
	'</div>' === trim( $output_after ),
	'uonix_comments_form_nosnippet_after fecha a div'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} falha(s) encontrada(s).\n" );
	exit( 1 );
}

echo "PASS: Área de comentários protegida com data-nosnippet, nofollow e robots.txt.\n";
exit( 0 );
