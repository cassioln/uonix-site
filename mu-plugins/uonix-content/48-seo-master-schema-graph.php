<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Motor Universal de Dados Estruturados Schema.org (@graph).
 *
 * Totalmente Dinâmico e Escalável:
 * 1. Qualquer novo Serviço criado no painel ganha automaticamente Breadcrumb + Service.
 * 2. Qualquer novo Artigo de Blog criado ganha automaticamente Breadcrumb + BlogPosting.
 * 3. Qualquer nova Categoria de Produto criada ganha automaticamente Breadcrumb + CollectionPage + ItemList com seus produtos.
 * 4. O Hub /servicos/ puxa automaticamente todos os serviços existentes em tempo real.
 * 5. A página /empresa/ emite AboutPage conectada à Organização oficial da Uônix.
 *
 * Fallback Inteligente: Se qualquer post/página tiver um Schema manual configurado
 * no painel do Rank Math, o Rank Math assume a emissão e este motor não duplica.
 *
 * URLs 100% dinâmicas via home_url() e get_permalink() — zero localhost em produção.
 */

add_action( 'wp_head', 'uonix_seo_master_schema_graph_engine', 20 );

/**
 * Verifica se o post possui Schema ativo e personalizado configurado no Rank Math.
 *
 * @param int $post_id ID do post ou página.
 * @return bool True se houver Schema ativo no Rank Math, False se vazio/padrão.
 */
function uonix_has_custom_rank_math_schema( $post_id ) {
    if ( ! $post_id ) {
        return false;
    }

    $rich_snippet = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
    if ( ! empty( $rich_snippet ) && 'off' !== $rich_snippet ) {
        return true;
    }

    $schemas = get_post_meta( $post_id, 'rank_math_schemas', true );
    if ( ! empty( $schemas ) && is_array( $schemas ) ) {
        return true;
    }

    $service_schema = get_post_meta( $post_id, 'rank_math_schema_Service', true );
    if ( ! empty( $service_schema ) && is_array( $service_schema ) ) {
        return true;
    }

    return false;
}

function uonix_seo_master_schema_graph_engine() {
    $graph = array();

    // =========================================================================
    // 1. PÁGINA HUB DE SERVIÇOS (/servicos/)
    // =========================================================================
    if ( is_page( 'servicos' ) ) {
        $hub_id       = get_queried_object_id();
        $servicos_url = get_permalink( $hub_id );
        $has_rm_schema = uonix_has_custom_rank_math_schema( $hub_id );

        $hub_desc = get_post_meta( $hub_id, 'rank_math_description', true );
        if ( empty( $hub_desc ) ) {
            $hub_desc = 'Serviços completos de ancoragem predial, projetos com ART, ensaios de arrancamento e linhas de vida NR-35 em todo o Brasil.';
        }

        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $servicos_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Serviços', 'item' => $servicos_url ),
            ),
        );

        if ( ! $has_rm_schema ) {
            // Busca dinâmica de TODOS os serviços publicados em tempo real
            $services_query = get_posts( array(
                'post_type'      => 'servicos',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );

            $catalog_items = array();
            foreach ( $services_query as $srv ) {
                $catalog_items[] = array(
                    '@type' => 'Offer',
                    'itemOffered' => array(
                        '@type' => 'Service',
                        'name'  => wp_strip_all_tags( get_the_title( $srv->ID ) ),
                        'url'   => get_permalink( $srv->ID ),
                    ),
                );
            }

            $graph[] = array(
                '@type'       => 'CollectionPage',
                '@id'         => $servicos_url . '#collection',
                'name'        => 'Serviços de Ancoragem Predial e Linhas de Vida NR-35',
                'url'         => $servicos_url,
                'description' => wp_strip_all_tags( $hub_desc ),
                'provider'    => array(
                    '@type'     => 'LocalBusiness',
                    '@id'       => home_url( '/#organization' ),
                    'name'      => 'Uonix Montagens e Consultoria Tecnica Ltda',
                    'url'       => home_url( '/' ),
                    'telephone' => '+55 11 4372-9366',
                ),
                'mainEntity'  => array(
                    '@type'           => 'OfferCatalog',
                    'name'            => 'Catálogo de Serviços de Engenharia e Ancoragem Predial',
                    'itemListElement' => $catalog_items,
                ),
            );
        }
    }

    // =========================================================================
    // 2. PÁGINAS INDIVIDUAIS DE SERVIÇOS (Post Type 'servicos' - Atuais e Futuros)
    // =========================================================================
    elseif ( is_singular( 'servicos' ) ) {
        $post_id       = get_queried_object_id();
        $service_url   = get_permalink( $post_id );
        $service_title = get_the_title( $post_id );
        $has_rm_schema = uonix_has_custom_rank_math_schema( $post_id );

        $servicos_page = get_page_by_path( 'servicos' );
        $servicos_url  = $servicos_page ? get_permalink( $servicos_page ) : home_url( '/servicos/' );

        // Breadcrumb dinâmico
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $service_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Serviços', 'item' => $servicos_url ),
                array( '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( $service_title ), 'item' => $service_url ),
            ),
        );

        if ( ! $has_rm_schema ) {
            $service_desc = get_post_meta( $post_id, 'rank_math_description', true );
            if ( empty( $service_desc ) ) {
                $service_desc = get_the_excerpt( $post_id );
            }
            if ( empty( $service_desc ) ) {
                $service_desc = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
                $service_desc = wp_trim_words( $service_desc, 30, '...' );
            }

            $graph[] = array(
                '@type'          => 'Service',
                '@id'            => $service_url . '#service',
                'name'           => wp_strip_all_tags( $service_title ),
                'url'            => $service_url,
                'description'    => wp_strip_all_tags( $service_desc ),
                'serviceType'    => 'Ancoragem Predial e Segurança em Altura',
                'category'       => 'Engenharia de Acesso e Trabalho em Altura',
                'provider'       => array(
                    '@type'     => 'LocalBusiness',
                    '@id'       => home_url( '/#organization' ),
                    'name'      => 'Uonix Montagens e Consultoria Tecnica Ltda',
                    'url'       => home_url( '/' ),
                    'telephone' => '+55 11 4372-9366',
                ),
                'areaServed'     => array(
                    '@type' => 'Country',
                    'name'  => 'Brasil',
                ),
                'hasOfferCatalog' => array(
                    '@type' => 'OfferCatalog',
                    '@id'   => $servicos_url . '#collection',
                    'name'  => 'Catálogo de Serviços de Engenharia e Ancoragem Predial',
                ),
            );
        }
    }

    // =========================================================================
    // 3. ARTIGOS DO BLOG (Post Type 'post' - Atuais e Futuros)
    // =========================================================================
    elseif ( is_singular( 'post' ) ) {
        $post_id       = get_queried_object_id();
        $post_url      = get_permalink( $post_id );
        $post_title    = get_the_title( $post_id );
        $has_rm_schema = uonix_has_custom_rank_math_schema( $post_id );

        $blog_page = get_page_by_path( 'blog' );
        $blog_url  = $blog_page ? get_permalink( $blog_page ) : home_url( '/blog/' );

        // Breadcrumb do Artigo
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $post_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $blog_url ),
                array( '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( $post_title ), 'item' => $post_url ),
            ),
        );

        if ( ! $has_rm_schema ) {
            $post_desc = get_post_meta( $post_id, 'rank_math_description', true );
            if ( empty( $post_desc ) ) {
                $post_desc = get_the_excerpt( $post_id );
            }
            if ( empty( $post_desc ) ) {
                $post_desc = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
                $post_desc = wp_trim_words( $post_desc, 30, '...' );
            }

            $thumb_id  = get_post_thumbnail_id( $post_id );
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';

            $article_entity = array(
                '@type'            => 'BlogPosting',
                '@id'              => $post_url . '#article',
                'headline'         => wp_strip_all_tags( $post_title ),
                'url'              => $post_url,
                'description'      => wp_strip_all_tags( $post_desc ),
                'datePublished'    => get_the_date( 'c', $post_id ),
                'dateModified'     => get_the_modified_date( 'c', $post_id ),
                'inLanguage'       => 'pt-BR',
                'mainEntityOfPage' => $post_url,
                'publisher'        => array(
                    '@type' => 'Organization',
                    '@id'   => home_url( '/#organization' ),
                    'name'  => 'Uonix Montagens e Consultoria Tecnica Ltda',
                    'url'   => home_url( '/' ),
                ),
                'about'            => array(
                    '@type' => 'Thing',
                    'name'  => 'Ancoragem Predial e Segurança em Trabalho em Altura',
                ),
            );

            if ( ! empty( $thumb_url ) ) {
                $article_entity['image'] = $thumb_url;
            }

            $graph[] = $article_entity;
        }
    }

    // =========================================================================
    // 4. PÁGINA INSTITUCIONAL A EMPRESA (/empresa/)
    // =========================================================================
    elseif ( is_page( 'empresa' ) ) {
        $page_id    = get_queried_object_id();
        $page_url   = get_permalink( $page_id );
        $page_title = get_the_title( $page_id );

        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $page_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => wp_strip_all_tags( $page_title ), 'item' => $page_url ),
            ),
        );

        $graph[] = array(
            '@type'        => 'AboutPage',
            '@id'          => $page_url . '#aboutpage',
            'url'          => $page_url,
            'name'         => 'Sobre a Uônix: Fabricante de Ancoragem Predial e Engenharia',
            'description'  => 'Conheça a história, infraestrutura de fabricação e capacidade técnica em engenharia de ancoragem predial e linhas de vida da Uônix.',
            'mainEntity'   => array(
                '@type'     => 'LocalBusiness',
                '@id'       => home_url( '/#organization' ),
                'name'      => 'Uonix Montagens e Consultoria Tecnica Ltda',
                'url'       => home_url( '/' ),
                'telephone' => '+55 11 4372-9366',
                'areaServed'=> array( '@type' => 'Country', 'name' => 'Brasil' ),
            ),
        );
    }

    // =========================================================================
    // 5. CATEGORIAS DE PRODUTOS WOOCOMMERCE (product_cat - Atuais e Futuras)
    // =========================================================================
    elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $term = get_queried_object();
        if ( $term && isset( $term->term_id ) ) {
            $cat_url   = get_term_link( $term );
            $cat_name  = $term->name;
            $cat_desc  = ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : ( get_term_meta( $term->term_id, 'rank_math_description', true ) ?: 'Catálogo de ' . $cat_name . ' direto da fábrica com entrega em todo o Brasil.' );

            $produtos_page = get_page_by_path( 'produtos' );
            $produtos_url  = $produtos_page ? get_permalink( $produtos_page ) : home_url( '/produtos/' );

            // Breadcrumb da Categoria
            $graph[] = array(
                '@type'           => 'BreadcrumbList',
                '@id'             => $cat_url . '#breadcrumb',
                'itemListElement' => array(
                    array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                    array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Produtos', 'item' => $produtos_url ),
                    array( '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( $cat_name ), 'item' => $cat_url ),
                ),
            );

            // Consulta dinâmica de todos os produtos desta categoria
            $products_in_cat = get_posts( array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $term->term_id,
                    ),
                ),
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );

            $product_items = array();
            $pos = 1;
            foreach ( $products_in_cat as $prod ) {
                $product_items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'url'      => get_permalink( $prod->ID ),
                    'name'     => wp_strip_all_tags( get_the_title( $prod->ID ) ),
                );
            }

            $graph[] = array(
                '@type'       => 'CollectionPage',
                '@id'         => $cat_url . '#collection',
                'name'        => wp_strip_all_tags( $cat_name ) . ' | Direto da Fábrica | Uônix',
                'url'         => $cat_url,
                'description' => wp_strip_all_tags( $cat_desc ),
                'provider'    => array(
                    '@type' => 'LocalBusiness',
                    '@id'   => home_url( '/#organization' ),
                    'name'  => 'Uonix Montagens e Consultoria Tecnica Ltda',
                ),
                'mainEntity'  => array(
                    '@type'           => 'ItemList',
                    'numberOfItems'   => count( $product_items ),
                    'itemListElement' => $product_items,
                ),
            );
        }
    }

    // =========================================================================
    // 6. DEMAIS PÁGINAS INSTITUCIONAIS (Ex: /produtos/, /cotacao/, /contato/)
    // =========================================================================
    elseif ( is_page() && ! is_front_page() ) {
        $page_id    = get_queried_object_id();
        $page_url   = get_permalink( $page_id );
        $page_title = get_the_title( $page_id );

        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $page_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => wp_strip_all_tags( $page_title ), 'item' => $page_url ),
            ),
        );
    }

    if ( empty( $graph ) ) {
        return;
    }

    $json = wp_json_encode(
        array( '@context' => 'https://schema.org', '@graph' => $graph ),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    if ( false !== $json ) {
        echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
    }
}
