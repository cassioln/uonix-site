<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Contagem de palavras do Rank Math incluindo tabs + breve descricao.
 *
 * PROBLEMA
 * O analisador do Rank Math (que roda no editor, client-side) so conta o
 * post_content. O texto das tabs customizadas (plugin
 * wb-custom-product-tabs-for-woocommerce, meta 'wb_custom_tabs') e a Breve
 * descricao (post_excerpt) NAO entram na contagem de ~600 palavras, ainda que o
 * Google leia esse conteudo normalmente na pagina.
 *
 * SOLUCAO (apenas contagem/analise no editor; NAO altera o conteudo publico)
 * 1. Um metabox lista cada tab customizada com um checkbox "Contar no SEO"
 *    (padrao LIGADO, inclusive para tabs novas que surgirem). A escolha e salva
 *    em post_meta '_uonix_seo_count_tabs' (uma allowlist de indices), fora da
 *    estrutura do plugin de tabs -> robusto contra updates do WebToffee.
 * 2. Enfileiramos um JS que se pendura no filtro oficial 'rank_math_content'
 *    (prioridade > 11, para rodar depois do proprio Rank Math) e ANEXA ao texto
 *    analisado: a Breve descricao (sempre) + o conteudo das tabs marcadas.
 *
 * A tab "Especificacoes Tecnicas" e de outro plugin (WooCommerce nativo), nao
 * esta em wb_custom_tabs, portanto nem aparece aqui e nunca e contada.
 *
 * IMPORTANTE: mexemos SO no que o editor analisa. O front-end/HTML servido ao
 * publico continua identico; o post_content real nao e tocado.
 */

const UONIX_SEO_COUNT_TABS_META = '_uonix_seo_count_tabs';

/* ---------------------------------------------------------------------------
 * 1) Metabox: checkbox "Contar no SEO" por tab customizada (padrao ligado)
 * ------------------------------------------------------------------------- */

add_action( 'add_meta_boxes', 'uonix_seo_tabs_add_metabox' );

function uonix_seo_tabs_add_metabox() {
    add_meta_box(
        'uonix_seo_tabs_wordcount',
        'Contagem SEO (Rank Math)',
        'uonix_seo_tabs_metabox_render',
        'product',
        'side',
        'default'
    );
}

/**
 * Le as tabs customizadas de um produto. Retorna array de tabs (cada uma com
 * as chaves title/content/nickname/...), ou array vazio.
 */
function uonix_seo_tabs_get_tabs( $post_id ) {
    $tabs = get_post_meta( $post_id, 'wb_custom_tabs', true );
    return is_array( $tabs ) ? $tabs : array();
}

/**
 * Retorna a allowlist de indices de tabs a contar. Se o meta nunca foi salvo
 * (null), o padrao e "todas ligadas": retorna null para sinalizar "default on".
 */
function uonix_seo_tabs_get_allowlist( $post_id ) {
    $raw = get_post_meta( $post_id, UONIX_SEO_COUNT_TABS_META, true );
    if ( '' === $raw || null === $raw ) {
        return null; // Nunca salvo -> padrao: contar todas.
    }
    if ( ! is_array( $raw ) ) {
        return array();
    }
    return array_map( 'intval', $raw );
}

/**
 * Decide se uma tab (por indice) deve ser contada, respeitando "padrao ligado".
 */
function uonix_seo_tabs_is_counted( $allowlist, $index ) {
    if ( null === $allowlist ) {
        return true; // default on
    }
    return in_array( (int) $index, $allowlist, true );
}

function uonix_seo_tabs_metabox_render( $post ) {
    $tabs      = uonix_seo_tabs_get_tabs( $post->ID );
    $allowlist = uonix_seo_tabs_get_allowlist( $post->ID );

    wp_nonce_field( 'uonix_seo_tabs_save', 'uonix_seo_tabs_nonce' );

    echo '<p style="margin-top:0;color:#555;">A Breve descrição é sempre contada. Marque quais abas também entram na contagem de palavras do Rank Math.</p>';

    if ( empty( $tabs ) ) {
        echo '<p><em>Este produto não tem abas personalizadas.</em></p>';
        return;
    }

    echo '<ul style="margin:0;">';
    foreach ( $tabs as $i => $tab ) {
        $title = isset( $tab['title'] ) && '' !== $tab['title']
            ? $tab['title']
            : ( isset( $tab['nickname'] ) && '' !== $tab['nickname'] ? $tab['nickname'] : 'Aba ' . ( $i + 1 ) );
        $checked = uonix_seo_tabs_is_counted( $allowlist, $i ) ? 'checked' : '';
        printf(
            '<li style="margin-bottom:6px;"><label><input type="checkbox" name="uonix_seo_count_tabs[]" value="%d" %s> %s</label></li>',
            (int) $i,
            $checked,
            esc_html( $title )
        );
    }
    echo '</ul>';
    echo '<input type="hidden" name="uonix_seo_tabs_present" value="1">';
}

add_action( 'save_post_product', 'uonix_seo_tabs_save', 10, 2 );

function uonix_seo_tabs_save( $post_id, $post ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['uonix_seo_tabs_nonce'] )
        || ! wp_verify_nonce( sanitize_key( $_POST['uonix_seo_tabs_nonce'] ), 'uonix_seo_tabs_save' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    // So processa se o metabox foi realmente renderizado (evita apagar em
    // saves programaticos que nao enviam o campo).
    if ( ! isset( $_POST['uonix_seo_tabs_present'] ) ) {
        return;
    }

    $selected = isset( $_POST['uonix_seo_count_tabs'] ) && is_array( $_POST['uonix_seo_count_tabs'] )
        ? array_map( 'intval', wp_unslash( $_POST['uonix_seo_count_tabs'] ) )
        : array();

    update_post_meta( $post_id, UONIX_SEO_COUNT_TABS_META, $selected );
}

/* ---------------------------------------------------------------------------
 * 2) Injeta breve descricao + tabs marcadas na analise do Rank Math (JS)
 * ------------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', 'uonix_seo_tabs_enqueue' );

function uonix_seo_tabs_enqueue( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'product' !== $screen->post_type ) {
        return;
    }

    global $post;
    if ( ! $post ) {
        return;
    }

    $extra = uonix_seo_tabs_build_extra_text( $post );
    if ( '' === $extra ) {
        return;
    }

    // Script inline dependente de wp-hooks (garante wp.hooks disponivel).
    wp_register_script( 'uonix-seo-tabs-wc', '', array( 'wp-hooks' ), '1.0.0', true );
    wp_enqueue_script( 'uonix-seo-tabs-wc' );

    // JSON_HEX_TAG/AMP/APOS/QUOT escapam < > & ' " para \u00XX, impedindo que
    // conteudo com a string literal '</script>' (ou '<!--') feche a tag inline
    // e injete script (XSS). Essencial ao embutir num <script> inline.
    $payload = wp_json_encode( $extra, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    if ( false === $payload ) {
        // UTF-8 malformado -> nao emite 'var uonixExtra = false;'; aborta silencioso.
        return;
    }
    $js = <<<JS
( function() {
    if ( ! window.wp || ! wp.hooks || ! wp.hooks.addFilter ) { return; }
    var uonixExtra = {$payload};
    // Prioridade 20 > 11 (Rank Math) para anexar depois que ele monta o conteudo.
    wp.hooks.addFilter( 'rank_math_content', 'uonix/seo-tabs-wordcount', function( content ) {
        if ( typeof content !== 'string' ) { content = content ? String( content ) : ''; }
        return content + uonixExtra;
    }, 20 );
} )();
JS;

    wp_add_inline_script( 'uonix-seo-tabs-wc', $js );
}

/**
 * Monta o HTML extra (breve descricao sempre + tabs marcadas) que sera anexado
 * ao conteudo analisado pelo Rank Math. Envolvemos cada bloco em <p> para que a
 * contagem por palavras/paragrafos do analisador funcione normalmente.
 */
function uonix_seo_tabs_build_extra_text( $post ) {
    $blocks = array();

    // Breve descricao (post_excerpt) - sempre.
    $excerpt = trim( (string) $post->post_excerpt );
    if ( '' !== $excerpt ) {
        $blocks[] = $excerpt;
    }

    // Tabs marcadas.
    $tabs      = uonix_seo_tabs_get_tabs( $post->ID );
    $allowlist = uonix_seo_tabs_get_allowlist( $post->ID );
    foreach ( $tabs as $i => $tab ) {
        if ( empty( $tab['content'] ) ) {
            continue;
        }
        if ( ! uonix_seo_tabs_is_counted( $allowlist, $i ) ) {
            continue;
        }
        $blocks[] = (string) $tab['content'];
    }

    if ( empty( $blocks ) ) {
        return '';
    }

    // Prefixa \n e envolve em <p> quando o bloco nao parecer ja ter HTML de bloco.
    $out = "\n";
    foreach ( $blocks as $b ) {
        $b = trim( $b );
        if ( '' === $b ) {
            continue;
        }
        // Se ja contem tags de bloco (h1-6/p/ul/ol), anexa como esta; senao, embrulha em <p>.
        if ( preg_match( '/<\\s*(h[1-6]|p|ul|ol|div)\\b/i', $b ) ) {
            $out .= $b . "\n";
        } else {
            $out .= '<p>' . $b . "</p>\n";
        }
    }

    return $out;
}
