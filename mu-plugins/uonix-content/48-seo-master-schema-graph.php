<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX SEO - Motor Universal de Dados Estruturados Schema.org (@graph).
 *
 * Arquitetura e Contratos de Emissão:
 * 1. Serviços Individuais (servicos): Emite Service enriquecido (normas ABNT/NR-35) + BreadcrumbList.
 * 2. Hub de Serviços (/servicos/): Emite CollectionPage + OfferCatalog dinâmico + BreadcrumbList.
 * 3. Artigos do Blog (post): Emite BreadcrumbList (Rank Math gerencia BlogPosting nativamente).
 * 4. Categorias de Produto (product_cat): Emite BreadcrumbList (Rank Math gerencia CollectionPage nativamente).
 * 5. Produtos Individuais (product): Emite BreadcrumbList (Rank Math gerencia Product nativamente).
 * 6. Página Institucional (/empresa/): Emite AboutPage conectada à Organização oficial via @id puro.
 * 7. FAQ Dinâmico: Extrai e emite FAQPage para páginas com accordions Kadence (exceto produtos).
 *
 * Regra de Grafos Google: Entidades principais (como a Organização oficial) são referenciadas
 * exclusivamente por seu @id canônico ('/#organization') para evitar nós duplicados ou conflitantes.
 */

add_action( 'wp_head', 'uonix_seo_master_schema_graph_engine', 20 );

/**
 * Verifica se o post possui Schema ativo e personalizado configurado no Rank Math.
 *
 * @param int $post_id ID do post ou página.
 * @return bool True se houver Schema ativo no Rank Math, False se vazio/padrão.
 */
function uonix_has_custom_rank_math_schema( $post_id ) {
    if ( ! $post_id || ! function_exists( 'get_post_meta' ) ) {
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
    if ( function_exists( 'is_page' ) && is_page( 'servicos' ) ) {
        $hub_id        = get_queried_object_id();
        $servicos_url  = get_permalink( $hub_id );
        $has_rm_schema = uonix_has_custom_rank_math_schema( $hub_id );

        $hub_desc = function_exists( 'get_post_meta' ) ? get_post_meta( $hub_id, 'rank_math_description', true ) : '';
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

        if ( ! $has_rm_schema && function_exists( 'get_posts' ) ) {
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
                    '@id' => home_url( '/#organization' ),
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
    elseif ( function_exists( 'is_singular' ) && is_singular( 'servicos' ) ) {
        $post_id       = get_queried_object_id();
        $service_url   = get_permalink( $post_id );
        $service_title = get_the_title( $post_id );
        $has_rm_schema = uonix_has_custom_rank_math_schema( $post_id );

        $servicos_page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'servicos' ) : null;
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
            $service_desc = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, 'rank_math_description', true ) : '';
            if ( empty( $service_desc ) && function_exists( 'get_the_excerpt' ) ) {
                $service_desc = get_the_excerpt( $post_id );
            }
            if ( empty( $service_desc ) && function_exists( 'get_post_field' ) ) {
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
                    '@id' => home_url( '/#organization' ),
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
    elseif ( function_exists( 'is_singular' ) && is_singular( 'post' ) ) {
        $post_id    = get_queried_object_id();
        $post_url   = get_permalink( $post_id );
        $post_title = get_the_title( $post_id );

        $blog_page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'blog' ) : null;
        $blog_url  = $blog_page ? get_permalink( $blog_page ) : home_url( '/blog/' );

        // Breadcrumb do Artigo (Rank Math emite BlogPosting/Article nativamente)
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $post_url . '#breadcrumb',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $blog_url ),
                array( '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( $post_title ), 'item' => $post_url ),
            ),
        );
    }

    // =========================================================================
    // 4. PÁGINA INSTITUCIONAL A EMPRESA (/empresa/)
    // =========================================================================
    elseif ( function_exists( 'is_page' ) && is_page( 'empresa' ) ) {
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
                '@id' => home_url( '/#organization' ),
            ),
        );
    }

    // =========================================================================
    // 5. PÁGINAS INDIVIDUAIS DE PRODUTO (WooCommerce)
    // =========================================================================
    elseif ( ( function_exists( 'is_product' ) && is_product() ) || ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) ) {
        $product_id    = get_queried_object_id();
        $product_url   = get_permalink( $product_id );
        $product_title = get_the_title( $product_id );

        $produtos_page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'produtos' ) : null;
        $produtos_url  = $produtos_page ? get_permalink( $produtos_page ) : home_url( '/produtos/' );

        $breadcrumb_items = array(
            array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
            array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Produtos', 'item' => $produtos_url ),
        );

        $pos = 3;
        if ( function_exists( 'get_the_terms' ) ) {
            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( ! empty( $terms ) && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
                $cat = $terms[0];
                $breadcrumb_items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => wp_strip_all_tags( $cat->name ),
                    'item'     => get_term_link( $cat ),
                );
            }
        }

        $breadcrumb_items[] = array(
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => wp_strip_all_tags( $product_title ),
            'item'     => $product_url,
        );

        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $product_url . '#breadcrumb',
            'itemListElement' => $breadcrumb_items,
        );
    }

    // =========================================================================
    // 6. CATEGORIAS DE PRODUTOS WOOCOMMERCE (product_cat - Atuais e Futuras)
    // =========================================================================
    elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $term = get_queried_object();
        if ( $term && isset( $term->term_id ) ) {
            $cat_url  = get_term_link( $term );
            $cat_name = $term->name;

            $produtos_page = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'produtos' ) : null;
            $produtos_url  = $produtos_page ? get_permalink( $produtos_page ) : home_url( '/produtos/' );

            // Breadcrumb da Categoria (Rank Math já gerencia CollectionPage nativamente)
            $graph[] = array(
                '@type'           => 'BreadcrumbList',
                '@id'             => $cat_url . '#breadcrumb',
                'itemListElement' => array(
                    array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => home_url( '/' ) ),
                    array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Produtos', 'item' => $produtos_url ),
                    array( '@type' => 'ListItem', 'position' => 3, 'name' => wp_strip_all_tags( $cat_name ), 'item' => $cat_url ),
                ),
            );
        }
    }

    // =========================================================================
    // 7. DEMAIS PÁGINAS INSTITUCIONAIS (Ex: /produtos/, /cotacao/, /contato/)
    // =========================================================================
    elseif ( function_exists( 'is_page' ) && is_page() && ! is_front_page() ) {
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

    // =========================================================================
    // 8. DETECÇÃO DINÂMICA DE FAQPAGES (Blocos Kadence Accordion / Reutilizáveis)
    // =========================================================================
    $current_id = get_queried_object_id();
    $is_product = ( function_exists( 'is_product' ) && is_product() ) || ( function_exists( 'is_singular' ) && is_singular( 'product' ) );
    if ( $current_id && ! is_front_page() && ! $is_product ) {
        $faq_questions = uonix_extract_faqpage_questions( $current_id );
        if ( ! empty( $faq_questions ) ) {
            $current_url = get_permalink( $current_id );
            $graph[]     = array(
                '@type'      => 'FAQPage',
                '@id'        => $current_url . '#faq',
                'mainEntity' => $faq_questions,
            );
        }
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

/**
 * Extrai pares de pergunta e resposta de blocos Gutenberg (Kadence pane / wp_block) de um post ou página.
 *
 * @param int $post_id ID do post ou página.
 * @return array Lista de entidades Question/Answer compatíveis com Schema.org/FAQPage.
 */
function uonix_extract_faqpage_questions( $post_id ) {
    if ( ! $post_id || ! function_exists( 'get_post' ) ) {
        return array();
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return array();
    }

    $content = $post->post_content ?? '';

    // Se houver referências a blocos reutilizáveis (wp:block), concatena o conteúdo deles
    if ( preg_match_all( '/<!--\s+wp:block\s+{"ref":(\d+)}\s+\/-->/i', $content, $ref_matches ) ) {
        foreach ( $ref_matches[1] as $ref_id ) {
            $ref_post = get_post( (int) $ref_id );
            if ( $ref_post && ! empty( $ref_post->post_content ) ) {
                $content .= "\n" . $ref_post->post_content;
            }
        }
    }

    // Se na página /produtos/ ou outra página o bloco FAQ existir em wp_block por slug 'faq'
    if ( false === strpos( $content, 'wp:kadence/pane' ) ) {
        if ( function_exists( 'is_page' ) && is_page( 'produtos' ) ) {
            $faq_block = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'faq', OBJECT, 'wp_block' ) : null;
            if ( ! $faq_block && function_exists( 'get_posts' ) ) {
                $found_blocks = get_posts( array(
                    'post_type'      => 'wp_block',
                    'name'           => 'faq',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                ) );
                if ( ! empty( $found_blocks ) ) {
                    $faq_block = $found_blocks[0];
                }
            }
            if ( $faq_block && ! empty( $faq_block->post_content ) ) {
                $content .= "\n" . $faq_block->post_content;
            }
        }
    }

    if ( false === strpos( $content, 'wp:kadence/pane' ) ) {
        return array();
    }

    preg_match_all( '/<!-- wp:kadence\/pane.*?-->(.*?)<!-- \/wp:kadence\/pane -->/is', $content, $panes );
    if ( empty( $panes[1] ) ) {
        return array();
    }

    $qa_items = array();
    foreach ( $panes[1] as $pane_html ) {
        $q = '';
        $a = '';

        if ( preg_match( '/class=[\'"]kt-blocks-accordion-title[\'"][^>]*>(.*?)<\/span>/is', $pane_html, $qm ) ) {
            $q = trim( wp_strip_all_tags( $qm[1] ) );
            $q = preg_replace( '/\s+/', ' ', $q );
        }

        if ( preg_match( '/class=[\'"]kt-accordion-panel-inner[\'"][^>]*>(.*?)<\/div>/is', $pane_html, $am ) ) {
            $a = trim( wp_strip_all_tags( $am[1] ) );
            $a = preg_replace( '/\s+/', ' ', $a );
        } else {
            $a = trim( wp_strip_all_tags( $pane_html ) );
            if ( ! empty( $q ) ) {
                $a = str_replace( $q, '', $a );
            }
            $a = preg_replace( '/\s+/', ' ', $a );
        }

        if ( ! empty( $q ) && ! empty( $a ) && mb_strlen( $q ) > 3 ) {
            $qa_items[] = array(
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $a,
                ),
            );
        }
    }

    return $qa_items;
}
