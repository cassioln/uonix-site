<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Enriquecimento B2B do Schema Product emitido pelo Rank Math.
 *
 * Complementa o nó Product nativo do Rank Math com atributos comerciais e de confiança:
 *  - Marca oficial conectada à @id canônica da Organização ('/#organization').
 *  - Indicação de país de origem (Brasil — fabricação 100% nacional).
 *  - Política de garantia de 12 meses contra defeitos de fabricação (MerchantReturnPolicy).
 *  - Detalhes de envio e cobertura logística para todo o território nacional (OfferShippingDetails).
 *  - Vendedor (seller) referenciando #organization por @id puro.
 *
 * Utiliza o filtro oficial rank_math/json_ld, sem criar blocos de script extras.
 */

add_filter( 'rank_math/json_ld', 'uonix_enrich_rank_math_product_schema', 25, 2 );

/**
 * Enriquecer entidades Product no grafo JSON-LD do Rank Math.
 *
 * @param array $data   Grafo JSON-LD emitido pelo Rank Math.
 * @param mixed $jsonld Instância interna do Rank Math.
 * @return array
 */
function uonix_enrich_rank_math_product_schema( $data, $jsonld ) {
    if ( ! is_array( $data ) || empty( $data ) ) {
        return $data;
    }

    $org_id = function_exists( 'home_url' ) ? home_url( '/#organization' ) : 'https://uonix.com.br/#organization';

    foreach ( $data as $key => $entity ) {
        if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
            continue;
        }

        $types = (array) $entity['@type'];
        if ( ! in_array( 'Product', $types, true ) ) {
            continue;
        }

        // 1. Marca oficial conectada
        $data[ $key ]['brand'] = array(
            '@type' => 'Brand',
            'name'  => 'Uônix',
            '@id'   => $org_id,
        );

        // 2. País de Origem (Fabricação Nacional)
        $data[ $key ]['countryOfOrigin'] = array(
            '@type' => 'Country',
            'name'  => 'Brasil',
        );

        // 3. Política de Devolução / Garantia de Fábrica (12 meses)
        $data[ $key ]['hasMerchantReturnPolicy'] = array(
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => 'BR',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => 365,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/FreeReturn',
        );

        // 4. Enriquecimento de Ofertas (Offers)
        if ( isset( $data[ $key ]['offers'] ) && is_array( $data[ $key ]['offers'] ) ) {
            // Se for array indexado de ofertas ou oferta única
            if ( isset( $data[ $key ]['offers']['@type'] ) ) {
                $data[ $key ]['offers']['itemCondition'] = 'https://schema.org/NewCondition';
                $data[ $key ]['offers']['seller']        = array( '@id' => $org_id );
                $data[ $key ]['offers']['areaServed']    = array(
                    array(
                        '@type' => 'Country',
                        'name'  => 'Brasil',
                    ),
                );
                $data[ $key ]['offers']['shippingDetails'] = array(
                    '@type'               => 'OfferShippingDetails',
                    'shippingRate'        => array(
                        '@type'    => 'MonetaryAmount',
                        'value'    => '0',
                        'currency' => 'BRL',
                    ),
                    'shippingDestination' => array(
                        array(
                            '@type'          => 'DefinedRegion',
                            'addressCountry' => 'BR',
                        ),
                    ),
                    'deliveryTime'        => array(
                        '@type'        => 'ShippingDeliveryTime',
                        'handlingTime' => array(
                            '@type'    => 'QuantitativeValue',
                            'minValue' => 0,
                            'maxValue' => 1,
                            'unitCode' => 'DAY',
                        ),
                        'transitTime'  => array(
                            '@type'    => 'QuantitativeValue',
                            'minValue' => 1,
                            'maxValue' => 5,
                            'unitCode' => 'DAY',
                        ),
                    ),
                );
            }
        }
    }

    return $data;
}
