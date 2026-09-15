<?php
/**
 * Otimizações de SEO para área e formulário de comentários.
 *
 * Resolve o problema de o Googlebot indexar strings de boilerplate do formulário
 * de comentários do WordPress ("Deixe um comentário", "O seu endereço de e-mail não
 * será publicado", "Publicar comentário") como texto de resumo na SERP (Issue #187).
 *
 * Mantém o formulário de comentários 100% aberto, funcional e acessível aos usuários,
 * mas impede a diluição temática e snippets indesejados através de:
 * 1. Atributo data-nosnippet no container de comentários e elementos do formulário.
 * 2. Inclusão de rel="nofollow" nos links de resposta e cancelamento de resposta.
 * 3. Diretiva Disallow: /*?replytocom=* no robots.txt.
 * 4. Ativação forçada da remoção/redirecionamento de ?replytocom no Rank Math.
 *
 * Referências:
 * - Google Search Central (data-nosnippet): https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag#data-nosnippet
 * - Google Search Central (replytocom crawl budget): https://developers.google.com/search/blog/2009/02/best-practices-for-handling-comment-spam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_comments_form_nosnippet_before' ) ) {
	/**
	 * Abre container com data-nosnippet antes do formulário de comentários.
	 */
	function uonix_comments_form_nosnippet_before() {
		if ( is_admin() ) {
			return;
		}
		echo '<div data-nosnippet class="uonix-comments-form-nosnippet">';
	}
}
add_action( 'comment_form_before', 'uonix_comments_form_nosnippet_before', 1 );

if ( ! function_exists( 'uonix_comments_form_nosnippet_after' ) ) {
	/**
	 * Fecha container com data-nosnippet após o formulário de comentários.
	 */
	function uonix_comments_form_nosnippet_after() {
		if ( is_admin() ) {
			return;
		}
		echo '</div>';
	}
}
add_action( 'comment_form_after', 'uonix_comments_form_nosnippet_after', 999 );

if ( ! function_exists( 'uonix_kadence_before_comments_nosnippet' ) ) {
	/**
	 * Abre container com data-nosnippet antes da área de comentários do tema Kadence.
	 */
	function uonix_kadence_before_comments_nosnippet() {
		if ( is_admin() ) {
			return;
		}
		echo '<div data-nosnippet class="uonix-comments-area-nosnippet">';
	}
}
add_action( 'kadence_before_comments', 'uonix_kadence_before_comments_nosnippet', 1 );

if ( ! function_exists( 'uonix_kadence_after_comments_nosnippet' ) ) {
	/**
	 * Fecha container com data-nosnippet após a área de comentários do tema Kadence.
	 */
	function uonix_kadence_after_comments_nosnippet() {
		if ( is_admin() ) {
			return;
		}
		echo '</div>';
	}
}
add_action( 'kadence_after_comments', 'uonix_kadence_after_comments_nosnippet', 999 );

if ( ! function_exists( 'uonix_comments_form_defaults_nosnippet' ) ) {
	/**
	 * Injeta data-nosnippet nos campos padrão e títulos do comment_form.
	 *
	 * @param array $defaults Argumentos padrão do formulário.
	 * @return array
	 */
	function uonix_comments_form_defaults_nosnippet( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			return $defaults;
		}

		$keys = array(
			'title_reply_before',
			'comment_notes_before',
			'comment_notes_after',
			'must_log_in',
			'logged_in_as',
			'submit_field',
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $defaults[ $key ] ) && is_string( $defaults[ $key ] ) ) {
				if ( false === stripos( $defaults[ $key ], 'data-nosnippet' ) ) {
					$marked = preg_replace(
						'/<([a-zA-Z0-9]+)\b(?![^>]*\bdata-nosnippet\b)([^>]*)>/i',
						'<$1$2 data-nosnippet>',
						$defaults[ $key ],
						1
					);
					if ( null !== $marked ) {
						$defaults[ $key ] = $marked;
					}
				}
			}
		}

		return $defaults;
	}
}
add_filter( 'comment_form_defaults', 'uonix_comments_form_defaults_nosnippet', 25 );

if ( ! function_exists( 'uonix_comment_reply_link_nofollow' ) ) {
	/**
	 * Garante rel="nofollow" nos links de resposta a comentários.
	 *
	 * @param string $link HTML do link de resposta.
	 * @return string
	 */
	function uonix_comment_reply_link_nofollow( $link ) {
		if ( ! is_string( $link ) || '' === $link ) {
			return $link;
		}

		if ( false === strpos( $link, 'rel=' ) ) {
			$link = str_replace( '<a ', '<a rel="nofollow" ', $link );
		} elseif ( false === strpos( $link, 'nofollow' ) ) {
			$link = preg_replace( '/rel=(["\'])([^"\']*)\1/i', 'rel=$1$2 nofollow$1', $link );
		}

		return $link;
	}
}
add_filter( 'comment_reply_link', 'uonix_comment_reply_link_nofollow', 30 );

if ( ! function_exists( 'uonix_cancel_comment_reply_link_nofollow' ) ) {
	/**
	 * Garante rel="nofollow" e data-nosnippet no link de cancelamento de resposta.
	 *
	 * @param string $link HTML do link.
	 * @return string
	 */
	function uonix_cancel_comment_reply_link_nofollow( $link ) {
		if ( ! is_string( $link ) || '' === $link ) {
			return $link;
		}

		if ( false === strpos( $link, 'rel=' ) ) {
			$link = str_replace( '<a ', '<a rel="nofollow" ', $link );
		} elseif ( false === strpos( $link, 'nofollow' ) ) {
			$link = preg_replace( '/rel=(["\'])([^"\']*)\1/i', 'rel=$1$2 nofollow$1', $link );
		}

		if ( false === stripos( $link, 'data-nosnippet' ) ) {
			$link = '<span data-nosnippet>' . $link . '</span>';
		}

		return $link;
	}
}
add_filter( 'cancel_comment_reply_link', 'uonix_cancel_comment_reply_link_nofollow', 30 );

if ( ! function_exists( 'uonix_comments_robots_txt_replytocom' ) ) {
	/**
	 * Adiciona diretiva Disallow para ?replytocom no robots.txt se o site for público.
	 *
	 * @param string $output Conteúdo gerado do robots.txt.
	 * @param string $public Se o site é público ('1' ou '0').
	 * @return string
	 */
	function uonix_comments_robots_txt_replytocom( $output, $public ) {
		if ( '0' === (string) $public ) {
			return $output;
		}

		if ( false === strpos( (string) $output, 'replytocom' ) ) {
			$rule   = "Disallow: /*?replytocom=*\n";
			$output = rtrim( (string) $output ) . "\n" . $rule;
		}

		return $output;
	}
}
add_filter( 'robots_txt', 'uonix_comments_robots_txt_replytocom', 20, 2 );

// Assegura que o Rank Math remova e redirecione o parâmetro ?replytocom.
add_filter( 'rank_math/frontend/remove_reply_to_com', '__return_true', 99 );
