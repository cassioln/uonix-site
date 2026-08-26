<?php
/**
 * Teste de contrato do enriquecimento B2B de Schema Product (Rank Math).
 *
 * Valida:
 *  - Registro do filtro rank_math/json_ld na prioridade 25 com 2 argumentos;
 *  - Enriquecimento de entidades Product (brand, countryOfOrigin, hasMerchantReturnPolicy, shippingDetails);
 *  - Referência canônica à Organização por @id puro;
 *  - Preservação intacta de grafos sem entidade Product (fail-closed).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_product_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['uonix_product_filters'][] = array(
        'hook'          => $hook,
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    );
}

function home_url( $path = '' ) {
    return 'https://uonix.com.br' . $path;
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/49-seo-product-schema-enhancement.php';

$failures = 0;

function product_assert( $condition, string $message ): void {
    global $failures;
    if ( ! $condition ) {
        ++$failures;
        fwrite( STDERR, "FAIL: {$message}\n" );
        return;
    }
    printf( "ok   %s\n", $message );
}

// 1. Registro do filtro
product_assert(
    1 === count( $GLOBALS['uonix_product_filters'] ),
    'registra um único filtro rank_math/json_ld'
);
$registration = $GLOBALS['uonix_product_filters'][0];
product_assert( 'rank_math/json_ld' === $registration['hook'], 'usa o hook oficial rank_math/json_ld' );
product_assert( 25 === $registration['priority'], 'roda na prioridade 25 (após outros enriquecimentos)' );
product_assert( 2 === $registration['accepted_args'], 'aceita 2 argumentos' );

// 2. Grafo com entidade Product
$sample_graph = array(
    'product' => array(
        '@type'       => 'Product',
        'name'        => 'Olhal de Ancoragem Modelo 210 Inox 304',
        'description' => 'Olhal de ancoragem predial conforme NR-35.',
        'offers'      => array(
            '@type'         => 'Offer',
            'price'         => '0',
            'priceCurrency' => 'BRL',
            'availability'  => 'https://schema.org/InStock',
        ),
    ),
);

$enriched = uonix_enrich_rank_math_product_schema( $sample_graph, null );
$prod     = $enriched['product'];

product_assert( 'Uônix' === ( $prod['brand']['name'] ?? '' ), 'brand é definida como Uônix' );
product_assert( 'https://uonix.com.br/#organization' === ( $prod['brand']['@id'] ?? '' ), 'brand conecta à @id canônica da organização' );
product_assert( 'Brasil' === ( $prod['countryOfOrigin']['name'] ?? '' ), 'countryOfOrigin é Brasil' );

product_assert( isset( $prod['hasMerchantReturnPolicy'] ), 'possui hasMerchantReturnPolicy (garantia de fábrica)' );
product_assert( 365 === ( $prod['hasMerchantReturnPolicy']['merchantReturnDays'] ?? 0 ), 'garantia de 365 dias (12 meses)' );

product_assert( 'https://schema.org/NewCondition' === ( $prod['offers']['itemCondition'] ?? '' ), 'oferta declara NewCondition' );
product_assert( 'https://uonix.com.br/#organization' === ( $prod['offers']['seller']['@id'] ?? '' ), 'seller referencia #organization por @id puro' );
product_assert( isset( $prod['offers']['shippingDetails'] ), 'oferta possui shippingDetails para entrega nacional' );

// 3. Grafo sem Product (preservação fail-closed)
$other_graph = array(
    'article' => array(
        '@type'    => 'Article',
        'headline' => 'Notícia',
    ),
);
$other_result = uonix_enrich_rank_math_product_schema( $other_graph, null );
product_assert( $other_result === $other_graph, 'preserva inalterados grafos sem entidade Product' );

// 4. Entradas nulas ou inválidas
product_assert( array() === uonix_enrich_rank_math_product_schema( array(), null ), 'lida de forma segura com array vazio' );

if ( 0 !== $failures ) {
    fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
    exit( 1 );
}

printf( "\nPASS: Enriquecimento B2B de Schema Product validado com sucesso.\n" );
