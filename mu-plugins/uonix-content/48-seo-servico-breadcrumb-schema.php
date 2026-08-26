<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Schema BreadcrumbList para páginas de serviço.
 *
 * As páginas do post type 'servicos' não emitem nenhum JSON-LD hoje (o Rank Math
 * não gera grafo para esse tipo). Este módulo injeta um bloco schema.org/BreadcrumbList
 * independente no <head> das páginas singulares de serviço, refletindo a hierarquia
 * REAL de navegação: Início › Serviços › [Serviço atual].
 *
 * É apenas dado estruturado (JSON-LD): NÃO renderiza trilha visual e NÃO altera o
 * layout/design. Dá elegibilidade ao rich result de breadcrumb na SERP sem tocar na
 * aparência do site. Não conflita com o Organization que o Rank Math emite na home
 * (aqui não há grafo do Rank Math a duplicar).
 *
 * URLs derivam de home_url()/get_permalink() — nunca hardcoded — para não vazar o
 * host do ambiente (ex.: localhost:8080 no clone local).
 */

add_action( 'wp_head', 'uonix_seo_servico_breadcrumb_schema', 20 );

function uonix_seo_servico_breadcrumb_schema() {
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

    // Página institucional que lista os serviços (arquivo real do cluster).
    // O post type 'servicos' tem has_archive=false, então o pai canônico é a
    // página /servicos/. Resolve-se pelo path para não hardcodar o host.
    $servicos_page = get_page_by_path( 'servicos' );
    $servicos_url  = $servicos_page ? get_permalink( $servicos_page ) : home_url( '/servicos/' );

    $items = array(
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
    );

    $schema = array(
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    );

    echo "\n<script type=\"application/ld+json\">"
        . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . "</script>\n";
}
