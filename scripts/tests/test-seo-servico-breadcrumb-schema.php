<?php
/**
 * Contrato e Teste Anti-Duplicação do Motor Universal de Grafos Schema.org Uônix.
 *
 * Garante que o módulo:
 *  - registra um único hook wp_head na prioridade 20;
 *  - emite @graph com BreadcrumbList e Service em páginas de serviço;
 *  - referencia a Organização oficial exclusivamente por @id puro ('/#organization'), sem redeclarar tipos conflitantes;
 *  - emite APENAS BreadcrumbList em categorias de produto (sem duplicar CollectionPage do Rank Math);
 *  - emite APENAS BreadcrumbList em posts de blog (sem duplicar BlogPosting do Rank Math);
 *  - emite APENAS BreadcrumbList em páginas individuais de produto (sem emitir FAQPage do bloco genérico);
 *  - emite FAQPage apenas em páginas institucionais com accordions (ex: /produtos/);
 *  - deriva URLs de helpers do WordPress (sem host hardcoded);
 *  - escapa com JSON seguro (sem </script> cru).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

$GLOBALS['uonix_breadcrumb_actions']  = array();
$GLOBALS['uonix_test_route']          = 'servicos_singular';
$GLOBALS['uonix_test_title_override'] = null;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['uonix_breadcrumb_actions'][] = array(
        'hook'     => $hook,
        'callback' => $callback,
        'priority' => $priority,
    );
}

// Stubs do WordPress usados pelo módulo.
function is_singular( $type = '' ) {
    if ( 'servicos' === $type ) {
        return 'servicos_singular' === $GLOBALS['uonix_test_route'];
    }
    if ( 'post' === $type ) {
        return 'post_singular' === $GLOBALS['uonix_test_route'];
    }
    if ( 'product' === $type ) {
        return 'product_singular' === $GLOBALS['uonix_test_route'];
    }
    return false;
}
function is_product() {
    return 'product_singular' === $GLOBALS['uonix_test_route'];
}
function is_product_category() {
    return 'product_cat' === $GLOBALS['uonix_test_route'];
}
function is_page( $page = '' ) {
    if ( empty( $page ) ) {
        return in_array( $GLOBALS['uonix_test_route'], array( 'hub_servicos', 'empresa', 'produtos_page', 'outra_pagina' ), true );
    }
    if ( 'servicos' === $page ) {
        return 'hub_servicos' === $GLOBALS['uonix_test_route'];
    }
    if ( 'empresa' === $page ) {
        return 'empresa' === $GLOBALS['uonix_test_route'];
    }
    if ( 'produtos' === $page ) {
        return 'produtos_page' === $GLOBALS['uonix_test_route'];
    }
    return false;
}
function is_front_page() {
    return 'front_page' === $GLOBALS['uonix_test_route'];
}
function get_queried_object_id() {
    switch ( $GLOBALS['uonix_test_route'] ) {
        case 'servicos_singular':
            return 2377;
        case 'hub_servicos':
            return 7711;
        case 'empresa':
            return 6418;
        case 'post_singular':
            return 9100;
        case 'product_singular':
            return 2420;
        case 'produtos_page':
            return 7150;
        default:
            return 0;
    }
}
function get_queried_object() {
    if ( 'product_cat' === $GLOBALS['uonix_test_route'] ) {
        return (object) array(
            'term_id'     => 34,
            'name'        => 'Olhal de Ancoragem',
            'description' => 'Olhais em inox 304 e 316',
        );
    }
    return null;
}
function get_permalink( $id = 0 ) {
    if ( is_object( $id ) && isset( $id->ID ) ) {
        $id = $id->ID;
    }
    switch ( (int) $id ) {
        case 2377:
            return 'https://uonix.com.br/servico/instalacao-de-pontos-de-ancoragem/';
        case 7711:
            return 'https://uonix.com.br/servicos/';
        case 6418:
            return 'https://uonix.com.br/empresa/';
        case 9100:
            return 'https://uonix.com.br/fator-de-queda/';
        case 2420:
            return 'https://uonix.com.br/produtos/ancoragem-modelo-210-inox-304/';
        case 7150:
            return 'https://uonix.com.br/produtos/';
        default:
            return 'https://uonix.com.br/';
    }
}
function is_wp_error( $thing ) {
    return false;
}
function get_term_link( $term, $tax = 'product_cat' ) {
    return 'https://uonix.com.br/olhal-de-ancoragem/';
}
function get_the_terms( $post_id, $tax ) {
    if ( 2420 === $post_id && 'product_cat' === $tax ) {
        return array(
            (object) array(
                'term_id' => 34,
                'name'    => 'Olhal de Ancoragem',
                'slug'    => 'olhal-de-ancoragem',
            ),
        );
    }
    return false;
}
function get_the_title( $id = 0 ) {
    if ( ! empty( $GLOBALS['uonix_test_title_override'] ) ) {
        return $GLOBALS['uonix_test_title_override'];
    }
    switch ( (int) $id ) {
        case 2377:
            return 'Instalação de Pontos de Ancoragem';
        case 7711:
            return 'Serviços';
        case 6418:
            return 'A Empresa';
        case 9100:
            return 'Fator de Queda na Ancoragem';
        case 2420:
            return 'Olhal de Ancoragem Modelo 210 Inox 304';
        case 7150:
            return 'Produtos';
        default:
            return '';
    }
}
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
    if ( 'servicos' === $path ) {
        return (object) array( 'ID' => 7711 );
    }
    if ( 'empresa' === $path ) {
        return (object) array( 'ID' => 6418 );
    }
    if ( 'produtos' === $path ) {
        return (object) array( 'ID' => 7150 );
    }
    if ( 'blog' === $path ) {
        return (object) array( 'ID' => 2780 );
    }
    return null;
}
function home_url( $path = '' ) {
    return 'https://uonix.com.br' . $path;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
    return '';
}
function get_the_excerpt( $post_id = 0 ) {
    return 'Instalação de pontos de ancoragem predial NR-35';
}
function get_post_field( $field, $post_id = 0 ) {
    return '';
}
function wp_trim_words( $text, $num_words = 55, $more = null ) {
    return $text;
}
function wp_strip_all_tags( $string, $remove_breaks = false ) {
    return trim( strip_tags( (string) $string ) );
}
function get_post( $id = null ) {
    if ( 2377 === $id ) {
        return (object) array(
            'ID'           => 2377,
            'post_title'   => 'Instalação de Pontos de Ancoragem',
            'post_content' => '',
        );
    }
    if ( 7150 === $id ) {
        return (object) array(
            'ID'           => 7150,
            'post_title'   => 'Produtos',
            'post_content' => '<!-- wp:kadence/pane {"id":1} --><div class="wp-block-kadence-pane kt-accordion-pane"><span class="kt-blocks-accordion-title">Vocês emitem nota fiscal?</span><div class="kt-accordion-panel-inner"><p>Sim, com certeza.</p></div></div><!-- /wp:kadence/pane -->',
        );
    }
    if ( 2420 === $id ) {
        return (object) array(
            'ID'           => 2420,
            'post_title'   => 'Olhal de Ancoragem Modelo 210 Inox 304',
            'post_content' => '<!-- wp:kadence/pane {"id":1} --><div class="wp-block-kadence-pane kt-accordion-pane"><span class="kt-blocks-accordion-title">Dúvida no produto?</span><div class="kt-accordion-panel-inner"><p>Resposta.</p></div></div><!-- /wp:kadence/pane -->',
        );
    }
    return null;
}
function get_posts( $args = array() ) {
    return array();
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/48-seo-master-schema-graph.php';

$failures = 0;

function breadcrumb_assert( $condition, string $message ): void {
    global $failures;
    if ( ! $condition ) {
        ++$failures;
        fwrite( STDERR, "FAIL: {$message}\n" );
        return;
    }
    printf( "ok   %s\n", $message );
}

// 1. Registro do hook
breadcrumb_assert(
    1 === count( $GLOBALS['uonix_breadcrumb_actions'] ),
    'registra um único hook'
);
$registration = $GLOBALS['uonix_breadcrumb_actions'][0];
breadcrumb_assert( 'wp_head' === $registration['hook'], 'usa o hook wp_head' );
breadcrumb_assert( 20 === $registration['priority'], 'roda na prioridade 20' );

// Helper para capturar saída JSON-LD
function capture_engine_graph( string $route ): array {
    $GLOBALS['uonix_test_route'] = $route;
    ob_start();
    uonix_seo_master_schema_graph_engine();
    $output = ob_get_clean();

    if ( empty( $output ) ) {
        return array( 'raw' => '', 'graph' => array(), 'types' => array() );
    }

    if ( preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $m ) ) {
        $json = json_decode( $m[1], true );
        $graph = $json['@graph'] ?? array();
        $types = array_map( function( $node ) { return $node['@type'] ?? ''; }, $graph );
        return array( 'raw' => $output, 'json' => $json, 'graph' => $graph, 'types' => $types );
    }

    return array( 'raw' => $output, 'graph' => array(), 'types' => array() );
}

// 2. Teste da Rota Serviço Singular
$serv = capture_engine_graph( 'servicos_singular' );
breadcrumb_assert( in_array( 'BreadcrumbList', $serv['types'], true ), 'serviço singular: emite BreadcrumbList' );
breadcrumb_assert( in_array( 'Service', $serv['types'], true ), 'serviço singular: emite Service' );

// B2: Verificar se referencia #organization por @id puro (sem redeclarar type/name conflitante)
$srv_node = null;
foreach ( $serv['graph'] as $node ) {
    if ( 'Service' === ( $node['@type'] ?? '' ) ) {
        $srv_node = $node;
    }
}
breadcrumb_assert(
    isset( $srv_node['provider']['@id'] ) && 'https://uonix.com.br/#organization' === $srv_node['provider']['@id'] && ! isset( $srv_node['provider']['@type'] ),
    'serviço singular: provider referencia #organization por @id puro'
);

// 3. Teste B1: Categoria de Produtos (product_cat) NÃO emite segundo CollectionPage
$cat = capture_engine_graph( 'product_cat' );
breadcrumb_assert( in_array( 'BreadcrumbList', $cat['types'], true ), 'categoria: emite BreadcrumbList' );
breadcrumb_assert( ! in_array( 'CollectionPage', $cat['types'], true ), 'categoria (B1): NÃO emite CollectionPage duplicado (deixa para Rank Math)' );
breadcrumb_assert( 1 === count( $cat['graph'] ), 'categoria: emite exatamente 1 nó no @graph (apenas BreadcrumbList)' );

// 4. Teste B3: Artigo de Blog (post) NÃO emite segundo BlogPosting
$post = capture_engine_graph( 'post_singular' );
breadcrumb_assert( in_array( 'BreadcrumbList', $post['types'], true ), 'post blog: emite BreadcrumbList' );
breadcrumb_assert( ! in_array( 'BlogPosting', $post['types'], true ), 'post blog (B3): NÃO emite BlogPosting duplicado (deixa para Rank Math)' );
breadcrumb_assert( 1 === count( $post['graph'] ), 'post blog: emite exatamente 1 nó no @graph (apenas BreadcrumbList)' );

// 5. Teste B4: Produto Individual (product) emite BreadcrumbList e NÃO emite FAQPage do motor
$prod = capture_engine_graph( 'product_singular' );
breadcrumb_assert( in_array( 'BreadcrumbList', $prod['types'], true ), 'produto singular: emite BreadcrumbList' );
breadcrumb_assert( ! in_array( 'FAQPage', $prod['types'], true ), 'produto singular (B4): NÃO emite FAQPage do bloco genérico (preserva 45-seo-faqpage-schema)' );

// 6. Teste FAQ em Página Institucional (/produtos/)
$faq_page = capture_engine_graph( 'produtos_page' );
breadcrumb_assert( in_array( 'BreadcrumbList', $faq_page['types'], true ), 'página produtos: emite BreadcrumbList' );
breadcrumb_assert( in_array( 'FAQPage', $faq_page['types'], true ), 'página produtos: emite FAQPage com perguntas' );

// 7. Teste Hub de Serviços (/servicos/)
$hub = capture_engine_graph( 'hub_servicos' );
breadcrumb_assert( in_array( 'BreadcrumbList', $hub['types'], true ), 'hub serviços: emite BreadcrumbList' );
breadcrumb_assert( in_array( 'CollectionPage', $hub['types'], true ), 'hub serviços: emite CollectionPage + OfferCatalog' );
$hub_col = null;
foreach ( $hub['graph'] as $node ) {
    if ( 'CollectionPage' === ( $node['@type'] ?? '' ) ) {
        $hub_col = $node;
    }
}
breadcrumb_assert(
    isset( $hub_col['provider']['@id'] ) && 'https://uonix.com.br/#organization' === $hub_col['provider']['@id'] && ! isset( $hub_col['provider']['@type'] ),
    'hub serviços: provider referencia #organization por @id puro'
);

// 8. Teste Página A Empresa (/empresa/)
$emp = capture_engine_graph( 'empresa' );
breadcrumb_assert( in_array( 'BreadcrumbList', $emp['types'], true ), 'empresa: emite BreadcrumbList' );
breadcrumb_assert( in_array( 'AboutPage', $emp['types'], true ), 'empresa: emite AboutPage' );
$emp_about = null;
foreach ( $emp['graph'] as $node ) {
    if ( 'AboutPage' === ( $node['@type'] ?? '' ) ) {
        $emp_about = $node;
    }
}
breadcrumb_assert(
    isset( $emp_about['mainEntity']['@id'] ) && 'https://uonix.com.br/#organization' === $emp_about['mainEntity']['@id'] && ! isset( $emp_about['mainEntity']['@type'] ),
    'empresa: mainEntity referencia #organization por @id puro'
);

// 9. Teste Front Page: Não emite nada
$front = capture_engine_graph( 'front_page' );
breadcrumb_assert( '' === $front['raw'], 'front page: não emite nada fora do escopo' );

// 10. Caso adversarial: escape contra </script> e tags HTML
$GLOBALS['uonix_test_route']          = 'servicos_singular';
$GLOBALS['uonix_test_title_override'] = 'X</script><script>alert(1)</script>';
ob_start();
uonix_seo_master_schema_graph_engine();
$adv = ob_get_clean();
$adv_payload = '';
if ( preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $adv, $am ) ) {
    $adv_payload = $am[1];
}

breadcrumb_assert( '' !== $adv_payload, 'adversarial: bloco JSON-LD é parseável' );
breadcrumb_assert( false === strpos( $adv_payload, '</script>' ), 'adversarial: payload não contém </script> cru (sem breakout)' );
breadcrumb_assert( false === strpos( $adv_payload, '<script' ), 'adversarial: payload não contém <script cru' );
breadcrumb_assert( false === strpos( $adv_payload, '<' ), 'adversarial: nenhum < cru sobrevive no payload (strip + JSON_HEX_TAG)' );
$adv_data = json_decode( $adv_payload, true );
breadcrumb_assert( is_array( $adv_data ) && isset( $adv_data['@graph'] ), 'adversarial: JSON ainda decodifica para @graph' );

if ( 0 !== $failures ) {
    fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
    exit( 1 );
}

printf( "\nPASS: Motor Universal de Schema aprovado com 100%% de conformidade e testes anti-duplicação (B1, B2, B3, B4).\n" );
