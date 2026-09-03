<?php
/**
 * Contrato do complemento de identidade Uônix no JSON-LD do Rank Math.
 *
 * O Rank Math Pro já cria HomeAndConstructionBusiness + Organization. Este
 * teste garante que o complemento enriquece ESSA entidade, sem criar uma
 * organização concorrente, e declara apenas fatos aprovados pela Uônix.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_organization_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['uonix_organization_filters'][] = array(
        'hook'          => $hook,
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/47-seo-organization-schema.php';

$failures = 0;

function organization_assert( $condition, string $message ): void {
    global $failures;

    if ( ! $condition ) {
        ++$failures;
        fwrite( STDERR, "FAIL: {$message}\n" );
        return;
    }

    printf( "ok   %s\n", $message );
}

organization_assert(
    1 === count( $GLOBALS['uonix_organization_filters'] ),
    'registra um único filtro Rank Math JSON-LD'
);

$registration = $GLOBALS['uonix_organization_filters'][0];
organization_assert( 'rank_math/json_ld' === $registration['hook'], 'usa o filtro oficial rank_math/json_ld' );
organization_assert( 20 === $registration['priority'], 'roda após a entidade base do Rank Math' );
organization_assert( 2 === $registration['accepted_args'], 'aceita os dois argumentos do filtro oficial' );

$base = array(
    'organization' => array(
        '@type'     => array( 'HomeAndConstructionBusiness', 'Organization' ),
        '@id'       => 'https://uonix.com.br/#organization',
        'name'      => 'Uonix Montagens e Consultoria Tecnica Ltda',
        'telephone' => '+55 11 4372-9366',
    ),
    'website' => array(
        '@type' => 'WebSite',
        'name'  => 'Uonix',
    ),
);

$result = uonix_enrich_rank_math_organization_schema( $base, null );

organization_assert( 2 === count( $result ), 'não cria uma segunda entidade no grafo' );
organization_assert(
    'https://uonix.com.br/#organization' === $result['organization']['@id'],
    'preserva o identificador da organização emitida pelo Rank Math'
);
organization_assert(
    array( 'HomeAndConstructionBusiness', 'Organization' ) === $result['organization']['@type'],
    'preserva o tipo HomeAndConstructionBusiness existente'
);
organization_assert(
    '+55 11 4372-9366' === $result['organization']['telephone'],
    'preserva o telefone principal nativo do Rank Math'
);
organization_assert(
    array(
        array(
            '@type' => 'Country',
            'name'  => 'Brasil',
        ),
    ) === $result['organization']['areaServed'],
    'declara atendimento nacional para fornecimento de produtos'
);
organization_assert(
    array(
        array(
            '@type'             => 'ContactPoint',
            'telephone'         => '+55 11 4372-9366',
            'contactType'       => 'atendimento e orçamentos',
            'availableLanguage' => 'pt-BR',
        ),
        array(
            '@type'             => 'ContactPoint',
            'telephone'         => '+55 11 94725-4885',
            'contactType'       => 'atendimento e orçamentos via WhatsApp',
            'availableLanguage' => 'pt-BR',
        ),
    ) === $result['organization']['contactPoint'],
    'declara telefone e WhatsApp como contatos de atendimento/orçamento'
);
organization_assert(
    ! in_array( 'OnlineStore', (array) $result['organization']['@type'], true ),
    'não declara OnlineStore enquanto não há transação direta'
);

$untouched = uonix_enrich_rank_math_organization_schema(
    array( 'organization' => array( '@id' => 'https://other.example/#organization' ) ),
    null
);
organization_assert(
    ! isset( $untouched['organization']['areaServed'] ),
    'não altera entidades que não pertencem ao domínio Uônix'
);

if ( 0 !== $failures ) {
    fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
    exit( 1 );
}

printf( "\nPASS: complemento de identidade enriquece apenas a organização Uônix.\n" );
