<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Enriquecimento B2B do Schema Product emitido pelo Rank Math.
 *
 * Complementa o nó Product nativo do Rank Math com atributos comerciais e de confiança
 * que são FATOS sustentáveis do negócio (fabricante/fornecedor B2B, catálogo por cotação):
 *  - Marca oficial referenciada à @id canônica da Organização ('/#organization') por @id PURO.
 *  - País de origem (Brasil — fabricação nacional).
 *  - Garantia de fábrica de 12 meses contra defeitos de fabricação (WarrantyPromise).
 *  - Na oferta: condição "novo" (NewCondition), vendedor (@id puro) e área atendida (Brasil).
 *
 * NÃO declara política de devolução (MerchantReturnPolicy) nem frete grátis
 * (OfferShippingDetails/shippingRate): a Uônix não oferece devolução gratuita e o frete
 * não é gratuito — marcá-los seria claim comercial não sustentado (risco de ação manual do
 * Google) e incompatível com o catálogo por cotação (produtos sem preço transacional).
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

        // 1. Marca oficial: referência por @id PURO à Organização já emitida pelo Rank Math.
        //    Não redefinir @type/name aqui (evita conflito de entidade no mesmo @id).
        $data[ $key ]['brand'] = array(
            '@id' => $org_id,
        );

        // 2. País de origem (fabricação nacional) — fato sustentável.
        $data[ $key ]['countryOfOrigin'] = array(
            '@type' => 'Country',
            'name'  => 'Brasil',
        );

        // 3. Garantia de fábrica de 12 meses contra defeito de fabricação (WarrantyPromise).
        //    Isto é GARANTIA (defeito de fabricação), não política de devolução do consumidor.
        $data[ $key ]['warranty'] = array(
            '@type'              => 'WarrantyPromise',
            'durationOfWarranty' => array(
                '@type'    => 'QuantitativeValue',
                'value'    => 12,
                'unitCode' => 'MON',
            ),
            'warrantyScope'      => 'Garantia de 12 meses contra defeito de fabricação',
        );

        // 4. Enriquecimento da oferta (quando o Rank Math emite uma oferta única).
        //    Apenas fatos sustentáveis: produto novo, vendedor (@id puro) e área atendida.
        //    Sem shippingDetails/shippingRate (frete não é gratuito) e sem MerchantReturnPolicy.
        if ( isset( $data[ $key ]['offers'] ) && is_array( $data[ $key ]['offers'] ) && isset( $data[ $key ]['offers']['@type'] ) ) {
            $data[ $key ]['offers']['itemCondition'] = 'https://schema.org/NewCondition';
            $data[ $key ]['offers']['seller']        = array( '@id' => $org_id );
            $data[ $key ]['offers']['areaServed']    = array(
                array(
                    '@type' => 'Country',
                    'name'  => 'Brasil',
                ),
            );
        }
    }

    return $data;
}
