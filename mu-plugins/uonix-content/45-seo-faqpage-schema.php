<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Schema FAQPage a partir da tab "Dúvidas Frequentes".
 *
 * O plugin wb-custom-product-tabs-for-woocommerce guarda tabs em
 * post_meta 'wb_custom_tabs' (array serializado). Quando um produto tem uma
 * tab de FAQ (nickname 'FAQ' ou titulo contendo "Dúvidas Frequentes"), este
 * modulo extrai os pares <h3>/<h4>/<h5> pergunta + resposta e injeta um bloco
 * JSON-LD schema.org/FAQPage no <head> da pagina do produto.
 *
 * O schema e adicional ao Product/Offer que o Rank Math ja emite; nao conflita.
 * Generico: funciona para qualquer produto que tenha a tab de FAQ.
 */

add_action( 'wp_head', 'uonix_seo_faqpage_schema_from_tab', 20 );

function uonix_seo_faqpage_schema_from_tab() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    global $post;
    if ( ! $post ) {
        return;
    }

    $tabs = get_post_meta( $post->ID, 'wb_custom_tabs', true );
    if ( ! is_array( $tabs ) || empty( $tabs ) ) {
        return;
    }

    // Localiza a tab de FAQ.
    $faq_html = '';
    foreach ( $tabs as $t ) {
        $is_faq = ( isset( $t['nickname'] ) && 'FAQ' === $t['nickname'] )
            || ( isset( $t['title'] ) && false !== mb_stripos( $t['title'], 'Dúvidas Frequentes' ) )
            || ( isset( $t['title'] ) && false !== mb_stripos( $t['title'], 'Duvidas Frequentes' ) );
        if ( $is_faq && ! empty( $t['content'] ) ) {
            $faq_html = $t['content'];
            break;
        }
    }
    if ( '' === $faq_html ) {
        return;
    }

    // Extrai pares pergunta + resposta. A pergunta pode ser <h3>, <h4> ou <h5>
    // (niveis profundos, seguros p/ perguntas dentro de uma tab); a resposta e o
    // conteudo ate o proximo <h3>/<h4>/<h5> ou o fim do bloco. Deliberadamente NAO
    // aceita <h1>/<h2> para nao competir com a hierarquia de headings da pagina
    // (o produto ja tem 1 H1 e H2s na descricao).
    if ( ! preg_match_all( '/<h[345][^>]*>(.*?)<\/h[345]>(.*?)(?=<h[345][\s>]|$)/is', $faq_html, $m, PREG_SET_ORDER ) ) {
        return;
    }

    $qa = array();
    foreach ( $m as $pair ) {
        $question = trim( wp_strip_all_tags( $pair[1] ) );
        $answer   = trim( wp_strip_all_tags( $pair[2] ) );
        if ( '' === $question || '' === $answer ) {
            continue;
        }
        $qa[] = array(
            '@type'          => 'Question',
            'name'           => $question,
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => $answer,
            ),
        );
    }
    if ( empty( $qa ) ) {
        return;
    }

    $schema = array(
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $qa,
    );

    echo "\n<script type=\"application/ld+json\">"
        . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . "</script>\n";
}
