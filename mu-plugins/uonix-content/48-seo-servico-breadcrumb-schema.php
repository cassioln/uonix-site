<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Schema Hub & Spoke com Fallback Inteligente para o Rank Math.
 *
 * Prioridade:
 * 1. SE o usuário configurou um Schema personalizado no painel do Rank Math
 *    (ex.: Service, FAQ, etc.), o Rank Math emite e este módulo NÃO duplica.
 * 2. SE o Rank Math estiver vazio/sem schema para o post, este módulo atua
 *    como FALLBACK AUTOMÁTICO, injetando o Schema estruturado (BreadcrumbList,
 *    Service e OfferCatalog).
 *
 * Permite alteração visual no painel do WordPress a qualquer momento sem quebrar o SEO.
 */

add_action( 'wp_head', 'uonix_seo_servico_breadcrumb_schema', 20 );

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

    // 1. Verifica rich snippet padrão do Rank Math Free
    $rich_snippet = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
    if ( ! empty( $rich_snippet ) && 'off' !== $rich_snippet ) {
        return true;
    }

    // 2. Verifica schemas customizados do Rank Math Pro / Schema Generator
    $schemas = get_post_meta( $post_id, 'rank_math_schemas', true );
    if ( ! empty( $schemas ) && is_array( $schemas ) ) {
        return true;
    }

    // 3. Verifica schemas específicos salvos por tipo (ex: rank_math_schema_Service)
    $service_schema = get_post_meta( $post_id, 'rank_math_schema_Service', true );
    if ( ! empty( $service_schema ) && is_array( $service_schema ) ) {
        return true;
    }

    return false;
}

function uonix_seo_servico_breadcrumb_schema() {
    $servicos_page = get_page_by_path( 'servicos' );
    $servicos_url  = $servicos_page ? get_permalink( $servicos_page ) : home_url( '/servicos/' );

    // =========================================================================
    // CASO A: Página Hub (/servicos/)
    // =========================================================================
    if ( is_page( 'servicos' ) ) {
        $hub_id = get_queried_object_id();

        // Se o usuário configurou um Schema específico no Rank Math para o Hub, respeita
        $has_rm_schema = uonix_has_custom_rank_math_schema( $hub_id );

        $hub_desc = get_post_meta( $hub_id, 'rank_math_description', true );
        if ( empty( $hub_desc ) ) {
            $hub_desc = 'Serviços completos de ancoragem predial, projetos com ART, ensaios de arrancamento e linhas de vida NR-35 em todo o Brasil.';
        }

        $graph = array();

        // Breadcrumb do Hub (sempre emitido como base de navegação)
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $servicos_url . '#breadcrumb',
            'itemListElement' => array(
                array(
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Início',
                    'item'     => home_url( '/' ),
                ),
                array(
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => 'Serviços',
                    'item'     => $servicos_url,
                ),
            ),
        );

        // Se NÃO houver Schema no Rank Math, injeta o Catálogo de Fallback
        if ( ! $has_rm_schema ) {
            $services_query = get_posts( array(
                'post_type'      => 'servicos',
                'post_status'    => 'publish',
                'posts_per_page' => 20,
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

        $json = wp_json_encode(
            array( '@context' => 'https://schema.org', '@graph' => $graph ),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ( false !== $json ) {
            echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
        }
        return;
    }

    // =========================================================================
    // CASO B: Página Individual de Serviço (/servico/*)
    // =========================================================================
    if ( ! is_singular( 'servicos' ) ) {
        return;
    }

    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return;
    }

    $service_url   = get_permalink( $post_id );
    $service_title = get_the_title( $post_id );
    if ( ! $service_url || '' === trim( (string) $service_title ) ) {
        return;
    }

    // Verifica se o usuário já definiu um Schema manual pelo painel do Rank Math
    $has_rm_schema = uonix_has_custom_rank_math_schema( $post_id );

    $graph = array();

    // 1. Entidade BreadcrumbList (Início › Serviços › [Serviço Atual])
    $graph[] = array(
        '@type'           => 'BreadcrumbList',
        '@id'             => $service_url . '#breadcrumb',
        'itemListElement' => array(
            array(
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Início',
                'item'     => home_url( '/' ),
            ),
            array(
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => 'Serviços',
                'item'     => $servicos_url,
            ),
            array(
                '@type'    => 'ListItem',
                'position' => 3,
                'name'     => wp_strip_all_tags( $service_title ),
                'item'     => $service_url,
            ),
        ),
    );

    // 2. Entidade Service (Apenas como Fallback se o Rank Math NÃO tiver schema configurado)
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

    $schema = array(
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    );

    $json = wp_json_encode(
        $schema,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ( false === $json ) {
        return;
    }

    echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
}



