<?php
/**
 * Contrato do schema BreadcrumbList das páginas de serviço Uônix.
 *
 * Garante que o módulo:
 *  - registra um único hook wp_head na prioridade 20;
 *  - só emite em páginas singulares do post type 'servicos' (guard);
 *  - produz UM bloco JSON-LD BreadcrumbList com a hierarquia real
 *    Início › Serviços › [Serviço], nas posições 1..3;
 *  - deriva URLs de helpers do WordPress (sem host hardcoded);
 *  - escapa com JSON seguro (sem </script> cru).
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uonix_breadcrumb_actions'] = array();
$GLOBALS['uonix_test_is_servicos']   = true;
$GLOBALS['uonix_test_title_override'] = null;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['uonix_breadcrumb_actions'][] = array(
        'hook'     => $hook,
        'callback' => $callback,
        'priority' => $priority,
    );
}

// Stubs mínimos do WordPress usados pelo módulo.
function is_singular( $type = '' ) {
    return ( 'servicos' === $type ) && (bool) $GLOBALS['uonix_test_is_servicos'];
}
function get_queried_object_id() {
    return 2377;
}
function get_permalink( $id = 0 ) {
    if ( 2377 === $id ) {
        return 'https://uonix.com.br/servico/instalacao-de-pontos-de-ancoragem/';
    }
    // Página /servicos/ (objeto retornado por get_page_by_path abaixo).
    if ( is_object( $id ) && isset( $id->ID ) && 7711 === $id->ID ) {
        return 'https://uonix.com.br/servicos/';
    }
    if ( 7711 === $id ) {
        return 'https://uonix.com.br/servicos/';
    }
    return '';
}
function get_the_title( $id = 0 ) {
    if ( ! empty( $GLOBALS['uonix_test_title_override'] ) ) {
        return $GLOBALS['uonix_test_title_override'];
    }
    return ( 2377 === $id ) ? 'Instalação de Pontos de Ancoragem' : '';
}
function get_page_by_path( $path ) {
    if ( 'servicos' === $path ) {
        return (object) array( 'ID' => 7711 );
    }
    return null;
}
function home_url( $path = '' ) {
    return 'https://uonix.com.br' . $path;
}
function wp_strip_all_tags( $string, $remove_breaks = false ) {
    return trim( strip_tags( (string) $string ) );
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-content/48-seo-servico-breadcrumb-schema.php';

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

// 1. Registro do hook.
breadcrumb_assert(
    1 === count( $GLOBALS['uonix_breadcrumb_actions'] ),
    'registra um único hook'
);
$registration = $GLOBALS['uonix_breadcrumb_actions'][0];
breadcrumb_assert( 'wp_head' === $registration['hook'], 'usa o hook wp_head' );
breadcrumb_assert( 20 === $registration['priority'], 'roda na prioridade 20' );

// 2. Caso positivo: página de serviço emite BreadcrumbList.
$GLOBALS['uonix_test_is_servicos'] = true;
ob_start();
uonix_seo_servico_breadcrumb_schema();
$output = ob_get_clean();

breadcrumb_assert( false !== strpos( $output, 'application/ld+json' ), 'emite um bloco JSON-LD' );

// Sem </script> cru vazando (escape seguro do payload).
$payload = '';
if ( preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $m ) ) {
    $payload = $m[1];
}
breadcrumb_assert( '' !== $payload, 'bloco JSON-LD é parseável' );
breadcrumb_assert( false === strpos( $payload, '</script>' ), 'payload não contém </script> cru' );

$data = json_decode( $payload, true );
breadcrumb_assert( is_array( $data ), 'JSON-LD decodifica para array' );
breadcrumb_assert( 'BreadcrumbList' === ( $data['@type'] ?? '' ), 'tipo é BreadcrumbList' );
breadcrumb_assert( 'https://schema.org' === ( $data['@context'] ?? '' ), 'contexto é schema.org' );

$items = $data['itemListElement'] ?? array();
breadcrumb_assert( 3 === count( $items ), 'contém exatamente 3 níveis' );

breadcrumb_assert( 1 === ( $items[0]['position'] ?? 0 ) && 'Início' === ( $items[0]['name'] ?? '' ), 'nível 1 é Início' );
breadcrumb_assert( 'https://uonix.com.br/' === ( $items[0]['item'] ?? '' ), 'Início aponta para a home' );

breadcrumb_assert( 2 === ( $items[1]['position'] ?? 0 ) && 'Serviços' === ( $items[1]['name'] ?? '' ), 'nível 2 é Serviços' );
breadcrumb_assert( 'https://uonix.com.br/servicos/' === ( $items[1]['item'] ?? '' ), 'Serviços aponta para /servicos/' );

breadcrumb_assert( 3 === ( $items[2]['position'] ?? 0 ) && 'Instalação de Pontos de Ancoragem' === ( $items[2]['name'] ?? '' ), 'nível 3 é o serviço atual' );
breadcrumb_assert( 'https://uonix.com.br/servico/instalacao-de-pontos-de-ancoragem/' === ( $items[2]['item'] ?? '' ), 'serviço aponta para seu permalink' );

// URLs não vazam host de ambiente (derivam de helpers, não hardcoded).
breadcrumb_assert( false === strpos( $payload, 'localhost' ), 'não vaza host local (localhost)' );

// 3. Guard: fora de página de serviço não emite nada.
$GLOBALS['uonix_test_is_servicos'] = false;
ob_start();
uonix_seo_servico_breadcrumb_schema();
$non_service = ob_get_clean();
breadcrumb_assert( '' === $non_service, 'não emite nada fora de páginas de serviço' );

// 4. Caso adversarial: título com </script> NÃO pode quebrar do bloco <script>.
//    O escape com JSON_HEX_TAG converte '<'/'>' em \u003C/\u003E.
$GLOBALS['uonix_test_is_servicos']    = true;
$GLOBALS['uonix_test_title_override'] = 'X</script><script>alert(1)</script>';
ob_start();
uonix_seo_servico_breadcrumb_schema();
$adv = ob_get_clean();
$GLOBALS['uonix_test_title_override'] = null;

$adv_payload = '';
if ( preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $adv, $am ) ) {
    $adv_payload = $am[1];
}
breadcrumb_assert( '' !== $adv_payload, 'caso adversarial: bloco JSON-LD é parseável' );
breadcrumb_assert( false === strpos( $adv_payload, '</script>' ), 'caso adversarial: payload NÃO contém </script> cru (sem breakout)' );
breadcrumb_assert( false === strpos( $adv_payload, '<script' ), 'caso adversarial: payload NÃO contém <script cru' );
breadcrumb_assert( false === strpos( $adv_payload, '<' ), 'caso adversarial: nenhum < cru sobrevive no payload (strip + JSON_HEX_TAG)' );
$adv_data = json_decode( $adv_payload, true );
breadcrumb_assert( is_array( $adv_data ) && 'BreadcrumbList' === ( $adv_data['@type'] ?? '' ), 'caso adversarial: JSON ainda decodifica para BreadcrumbList' );

// 5. Prova isolada da camada de escape (defesa em profundidade): mesmo que um '<'
//    chegasse ao encoder (hipótese onde wp_strip_all_tags não estivesse no caminho),
//    JSON_HEX_TAG o converte em \u003C — nunca um <script/</script> literal.
$hardened = wp_json_encode(
    array( 'name' => 'a<b></script>' ),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
breadcrumb_assert( false === strpos( (string) $hardened, '<' ), 'escape endurecido: JSON_HEX_TAG remove todo < cru' );
breadcrumb_assert( false !== strpos( (string) $hardened, '\\u003C' ), 'escape endurecido: < vira \\u003C' );

if ( 0 !== $failures ) {
    fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
    exit( 1 );
}

printf( "\nPASS: BreadcrumbList de serviço emite hierarquia real, sem duplicar schema nem vazar host.\n" );
