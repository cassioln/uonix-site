<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Complementa a identidade Organization emitida pelo Rank Math.
 *
 * O Rank Math Pro já gera a entidade HomeAndConstructionBusiness + Organization
 * na home. Este complemento não cria um segundo JSON-LD: apenas acrescenta
 * área atendida e contatos de atendimento/orçamento à entidade existente.
 *
 * A Uônix opera como fabricante/fornecedora com catálogo e solicitação de
 * orçamento, além de serviços técnicos. Não declara OnlineStore nem políticas
 * de transação/frete enquanto não houver venda direta no site.
 */

add_filter( 'rank_math/json_ld', 'uonix_enrich_rank_math_organization_schema', 20, 2 );

/**
 * Enriquecer a entidade de organização nativa do Rank Math.
 *
 * @param array $data   Grafo JSON-LD do Rank Math.
 * @param mixed $jsonld Instância interna do Rank Math (não necessária aqui).
 * @return array
 */
function uonix_enrich_rank_math_organization_schema( $data, $jsonld ) {
    foreach ( $data as $key => $entity ) {
        if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
            continue;
        }

        $types = (array) $entity['@type'];
        if ( ! in_array( 'HomeAndConstructionBusiness', $types, true ) || ! in_array( 'Organization', $types, true ) ) {
            continue;
        }

        // Produtos: fornecimento e envio para todo o Brasil.
        $data[ $key ]['areaServed'] = array(
            array(
                '@type' => 'Country',
                'name'  => 'Brasil',
            ),
        );

        // Serviços de instalação atendem a Grande São Paulo; isso permanece
        // explícito no conteúdo das páginas de serviço, sem limitar a entidade
        // corporativa que também fornece produtos nacionalmente.
        $data[ $key ]['contactPoint'] = array(
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
        );
    }

    return $data;
}
