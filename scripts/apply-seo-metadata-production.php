<?php
/**
 * Script de Sincronização Automatizada de Metadados de SEO e FAQ via WP-CLI.
 *
 * Aplica de forma idempotente:
 * 1. Todos os metadados Rank Math (Title, Description, Focus Keyword) por SLUG (garantindo compatibilidade com IDs de produção).
 * 2. Atualização do Bloco Padrão de FAQ (wp_block 'faq') com as 9 perguntas técnicas e link relativo.
 * 3. Limpeza de cache ao finalizar.
 *
 * Modo de Uso:
 * Local:      wp eval-file scripts/apply-seo-metadata-production.php --allow-root
 * Produção:   wp eval-file scripts/apply-seo-metadata-production.php --path=/home/storage/f/34/12/siteuonix1/public_html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( "Este script deve ser executado via WP-CLI (wp eval-file).\n" );
}

echo "========================================================================\n";
echo "🚀 INICIANDO SINCRONIZAÇÃO DE METADADOS SEO E FAQ (UÔNIX)\n";
echo "========================================================================\n\n";

$updated_count = 0;
$skipped_count = 0;

/**
 * Helper para atualizar metadados de um post/página por slug e post_type.
 */
function uonix_sync_post_meta( $slugs, $post_type, $title, $desc, $focus_kw ) {
    global $updated_count, $skipped_count;

    $candidates = (array) $slugs;
    $post_found = null;

    foreach ( $candidates as $candidate_slug ) {
        $posts = get_posts( array(
            'name'           => $candidate_slug,
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        ) );
        if ( ! empty( $posts ) ) {
            $post_found = $posts[0];
            $matched_slug = $candidate_slug;
            break;
        }
    }

    if ( ! $post_found ) {
        echo "⚠️  [NÃO ENCONTRADO] {$post_type}: " . implode( ' / ', $candidates ) . "\n";
        $skipped_count++;
        return;
    }

    $post_id = $post_found->ID;
    update_post_meta( $post_id, 'rank_math_title', $title );
    update_post_meta( $post_id, 'rank_math_description', $desc );
    update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );

    echo "✅ [OK] {$post_type} (ID {$post_id}) '{$matched_slug}' -> Metadados atualizados com sucesso.\n";
    $GLOBALS['updated_count']++;
}

/**
 * Helper para atualizar metadados de taxonomia (categoria) por slug.
 */
function uonix_sync_term_meta( $slug, $taxonomy, $title, $desc, $focus_kw ) {
    $term = get_term_by( 'slug', $slug, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        echo "⚠️  [NÃO ENCONTRADO] Termo {$taxonomy}: '{$slug}'\n";
        $GLOBALS['skipped_count']++;
        return;
    }

    $term_id = $term->term_id;
    update_term_meta( $term_id, 'rank_math_title', $title );
    update_term_meta( $term_id, 'rank_math_description', $desc );
    update_term_meta( $term_id, 'rank_math_focus_keyword', $focus_kw );

    echo "✅ [OK] Categoria (ID {$term_id}) '{$slug}' -> Metadados atualizados com sucesso.\n";
    $GLOBALS['updated_count']++;
}

// =========================================================================
// 1. PÁGINAS INSTITUCIONAIS E HUBS
// =========================================================================
echo "--- 1. PÁGINAS INSTITUCIONAIS E COMERCIAIS ---\n";

// Home Page (ID da página inicial definida nas opções ou por slug home/inicio)
$front_page_id = get_option( 'page_on_front' );
if ( $front_page_id ) {
    update_post_meta( $front_page_id, 'rank_math_title', 'Fabricante de Ancoragem Predial e Dispositivos de Ancoragem | Uônix' );
    update_post_meta( $front_page_id, 'rank_math_description', 'Fabricante de dispositivos de ancoragem predial e olhais em aço inox 304/316. Projetos, ensaios de arrancamento e instalação NR-35 com ART para todo o Brasil.' );
    update_post_meta( $front_page_id, 'rank_math_focus_keyword', 'ancoragem predial, dispositivos de ancoragem, olhal de ancoragem, nr-35' );
    echo "✅ [OK] Página Inicial / Home (ID {$front_page_id}) atualizada.\n";
    $updated_count++;
}

uonix_sync_post_meta(
    'produtos', 'page',
    'Dispositivos de Ancoragem e Linha de Fixação | Fábrica Uônix',
    'Catálogo completo de dispositivos de ancoragem, olhais em inox 304 e 316, fixação química e mecânica. Venda direto da fábrica com laudo de teste para todo o Brasil.',
    'dispositivos de ancoragem, produtos de ancoragem, olhal de ancoragem'
);

uonix_sync_post_meta(
    'servicos', 'page',
    'Serviços de Ancoragem Predial e Linhas de Vida NR-35 | Uônix',
    'Serviços especializados em ancoragem predial: instalação de pontos, ensaios de arrancamento estático 1.500 kgf, projetos executivos e emissão de ART CREA em todo o Brasil.',
    'serviços de ancoragem, instalação de ancoragem, ensaio de arrancamento'
);

uonix_sync_post_meta(
    'empresa', 'page',
    'Sobre a Uônix | Especialista em Ancoragem Predial e Segurança',
    'Conheça a Uônix: fabricante nacional e consultoria de engenharia especializada em sistemas de ancoragem predial, linhas de vida e conformidade com a NR-35 e NBR 16325.',
    'uônix, sobre a uônix, fabricante de ancoragem predial'
);

uonix_sync_post_meta(
    array( 'cotacao', 'orcamento', 'contato' ), 'page',
    'Solicite Cotação e Orçamento | Uônix Ancoragem Predial',
    'Solicite seu orçamento de dispositivos de ancoragem direto da fábrica ou consulte nosso departamento de engenharia para projetos e instalações NR-35 em todo o país.',
    'orçamento ancoragem predial, cotação dispositivos de ancoragem, contato uonix'
);

// =========================================================================
// 2. CATEGORIAS WOOCOMMERCE
// =========================================================================
echo "\n--- 2. CATEGORIAS DE PRODUTOS WOOCOMMERCE ---\n";

uonix_sync_term_meta(
    'olhal-de-ancoragem', 'product_cat',
    'Olhal de Ancoragem Inox 304 e 316 | Dispositivos de Ancoragem Uônix',
    'Olhais e dispositivos de ancoragem tipo A1 fabricados em aço inox 304 e 316. Resistência atestada acima de 1.500 kgf conforme NR-35 e NBR 16325-1. Envio para todo o Brasil.',
    'olhal de ancoragem, dispositivos de ancoragem, ancoragem inox'
);

uonix_sync_term_meta(
    'fixacao-quimica', 'product_cat',
    'Fixação Química para Ancoragem Predial | Chumbadores e Resinas Uônix',
    'Linha de fixação química de alta performance para instalação de pontos de ancoragem em concreto. Chumbadores químicos bicomponentes, ampolas e aplicadores profissionais.',
    'fixação química, chumbador químico, resina de ancoragem'
);

uonix_sync_term_meta(
    'fixacao-mecanica', 'product_cat',
    'Fixação Mecânica para Ancoragem | Chumbadores e Barras Roscadas Uônix',
    'Chumbadores mecânicos de expansão, barras roscadas em inox 304 e porcas de alta resistência para sistemas de ancoragem predial e fixação estrutural pesada.',
    'fixação mecânica, chumbador de expansão, barra roscada inox'
);

uonix_sync_term_meta(
    'acessorios', 'product_cat',
    'Acessórios para Sistemas de Ancoragem e Linhas de Vida | Uônix',
    'Acessórios essenciais para ancoragem predial: grampos para cabo de aço, arruelas de funileiro em inox 304 e componentes de fixação com alta durabilidade.',
    'acessórios de ancoragem, grampo cabo de aço'
);

// =========================================================================
// 3. SERVIÇOS DE ENGENHARIA (CPT 'servicos')
// =========================================================================
echo "\n--- 3. SERVIÇOS TÉCNICOS DE ENGENHARIA (CPT servicos) ---\n";

$servicos_data = array(
    'instalacao-de-pontos-de-ancoragem' => array(
        'aliases' => array( 'instalacao-de-pontos-de-ancoragem', 'instalacao-pontos-ancoragem' ),
        'title'   => 'Instalação de Pontos de Ancoragem Predial NR-35 com ART | Uônix',
        'desc'    => 'Instalação profissional de pontos de ancoragem predial em concreto e estrutura metálica. Atendimento às normas NR-35, NR-18 e NBR 16325 com emissão de ART em todo o Brasil.',
        'kw'      => 'instalação de pontos de ancoragem, ancoragem predial nr-35, pontos de ancoragem'
    ),
    'ensaios-de-arrancamento' => array(
        'aliases' => array( 'ensaios-de-arrancamento', 'ensaio-de-arrancamento' ),
        'title'   => 'Ensaio de Arrancamento Estático de Ancoragem (15 kN) | Uônix',
        'desc'    => 'Teste e ensaio de arrancamento estático com carga de 1.500 kgf (15 kN) para validação de pontos de ancoragem conforme NBR 16325-1. Laudo técnico e ART inclusos.',
        'kw'      => 'ensaio de arrancamento, teste de arrancamento estático, laudo teste de ancoragem'
    ),
    'projeto-ancoragem' => array(
        'aliases' => array( 'projeto-ancoragem', 'projeto-de-ancoragem' ),
        'title'   => 'Projeto de Ancoragem Predial e Linhas de Vida com ART | Uônix',
        'desc'    => 'Desenvolvimento de projeto executivo de sistemas de ancoragem predial, memorial de cálculo, dimensionamento de fixações e planta de pontos conforme NR-35 e NBR 16325.',
        'kw'      => 'projeto de ancoragem predial, projeto linha de vida, projeto nr-35'
    ),
    'relatorio-tecnico-e-fotografico' => array(
        'aliases' => array( 'relatorio-tecnico-e-fotografico', 'laudo-tecnico-e-fotografico' ),
        'title'   => 'Laudo Técnico e Fotográfico de Ancoragem Predial NR-35 | Uônix',
        'desc'    => 'Inspeção e laudo técnico com relatório fotográfico detalhado de conformidade dos pontos de ancoragem existentes. Emissão de parecer de engenharia e ART CREA.',
        'kw'      => 'laudo técnico de ancoragem, laudo fotográfico nr-35, inspeção de ancoragem'
    ),
    'art' => array(
        'aliases' => array( 'art' ),
        'title'   => 'Emissão de ART para Ancoragem Predial e Trabalho em Altura | Uônix',
        'desc'    => 'Emissão de Anotação de Responsabilidade Técnica (ART) por engenheiros habilitados pelo CREA para projetos, instalações e testes de ancoragem predial e linhas de vida.',
        'kw'      => 'art ancoragem predial, art nr-35, art para trabalho em altura'
    ),
    'projeto-cadeirinha-pintura' => array(
        'aliases' => array( 'projeto-cadeirinha-pintura', 'projeto-de-cadeirinha-de-pintura' ),
        'title'   => 'Projeto de Ancoragem para Cadeirinha de Pintura Fachada NR-18 | Uônix',
        'desc'    => 'Dimensionamento e projeto de pontos de ancoragem específicos para uso de cadeirinha suspensa (balancim individual) em manutenção e pintura predial conforme NR-18.',
        'kw'      => 'projeto cadeirinha de pintura, ancoragem cadeirinha suspensa, nr-18 fachada'
    ),
    'projeto-balancim' => array(
        'aliases' => array( 'projeto-balancim', 'projeto-de-balancim' ),
        'title'   => 'Projeto de Ancoragem para Balancim Elétrico e Manual | Uônix',
        'desc'    => 'Projeto estrutural e pontos de fixação para balancins suspensos elétricos e manuais em fachadas prediais. Cálculo de sobrecargas e emissão de ART.',
        'kw'      => 'projeto de balancim, ancoragem para balancim elétrico, balancim fachada'
    ),
    'projeto-andaime-fachadeiro' => array(
        'aliases' => array( 'projeto-andaime-fachadeiro', 'projeto-de-andaime-fachadeiro' ),
        'title'   => 'Projeto de Amarração e Ancoragem de Andaime Fachadeiro | Uônix',
        'desc'    => 'Projeto de fixação e estroncamento de andaimes fachadeiros para obras e reformas prediais. Conformidade com NR-18 e cálculo estrutural com ART.',
        'kw'      => 'projeto andaime fachadeiro, ancoragem de andaimes, amarração de andaime nr-18'
    ),
    'projetos-de-instalacao' => array(
        'aliases' => array( 'projetos-de-instalacao', 'projetos-de-instalac-a-o' ),
        'title'   => 'Projetos de Instalação de Sistemas de Proteção contra Quedas | Uônix',
        'desc'    => 'Engenharia completa para instalação de sistemas de ancoragem e proteção coletiva em edificações comerciais, residenciais e industriais em todo o território nacional.',
        'kw'      => 'projetos de instalação ancoragem, proteção contra quedas nr-35'
    ),
);

foreach ( $servicos_data as $s_meta ) {
    uonix_sync_post_meta( $s_meta['aliases'], 'servicos', $s_meta['title'], $s_meta['desc'], $s_meta['kw'] );
}

// =========================================================================
// 4. ATUALIZAÇÃO DO PADRÃO DE BLOCO FAQ (wp_block 'faq' / ID 2859)
// =========================================================================
echo "\n--- 4. ATUALIZAÇÃO DO BLOCO PADRÃO FAQ (wp_block) ---\n";

$faq_posts = get_posts( array(
    'post_type'      => 'wp_block',
    'name'           => 'faq',
    'post_status'    => 'any',
    'posts_per_page' => 1,
) );

if ( empty( $faq_posts ) ) {
    // Tenta por ID 2859 se não encontrar por slug
    $faq_post_target = get_post( 2859 );
} else {
    $faq_post_target = $faq_posts[0];
}

if ( $faq_post_target ) {
    $c = $faq_post_target->post_content;

    // 1. Correção textual olhar -> olhal
    $c = str_replace( 'Como é feita a instalação do olhar de ancoragem da Uônix ?', 'Como é feita a instalação do olhal de ancoragem da Uônix?', $c );
    $c = str_replace( 'Como é feita a instalação do olhar de ancoragem da Uônix?', 'Como é feita a instalação do olhal de ancoragem da Uônix?', $c );

    // 2. Remoção de localhost do link do botão
    $c = str_replace( 'http://localhost:8080/?assunto=info#contato', '/?assunto=info#contato', $c );

    // 3. Atualização do paneCount
    $c = str_replace( '"paneCount":23', '"paneCount":25', $c );

    // 4. Inserção das novas perguntas se não existirem
    if ( false === strpos( $c, 'A Uônix envia dispositivos de ancoragem para todo o Brasil?' ) ) {
        $new_panes = "<!-- wp:kadence/pane {\"id\":24,\"uniqueID\":\"2859_a1b2c3-24\"} -->\n"
            . "<div class=\"wp-block-kadence-pane kt-accordion-pane kt-accordion-pane-24 kt-pane2859_a1b2c3-24\"><div class=\"kt-accordion-header-wrap\"><button class=\"kt-blocks-accordion-header kt-acccordion-button-label-show\" type=\"button\"><span class=\"kt-blocks-accordion-title-wrap\"><span class=\"kt-blocks-accordion-title\"><strong>A Uônix envia dispositivos de ancoragem para todo o Brasil?</strong></span></span><span class=\"kt-blocks-accordion-icon-trigger\"></span></button></div><div class=\"kt-accordion-panel\"><div class=\"kt-accordion-panel-inner\"><!-- wp:paragraph -->\n"
            . "<p>Sim, realizamos entregas rápidas e seguras para todo o território nacional, com embalagem reforçada e documentação técnica completa.</p>\n"
            . "<!-- /wp:paragraph --></div></div></div>\n"
            . "<!-- /wp:kadence/pane -->\n\n"
            . "<!-- wp:kadence/pane {\"id\":25,\"uniqueID\":\"2859_d4e5f6-25\"} -->\n"
            . "<div class=\"wp-block-kadence-pane kt-accordion-pane kt-accordion-pane-25 kt-pane2859_d4e5f6-25\"><div class=\"kt-accordion-header-wrap\"><button class=\"kt-blocks-accordion-header kt-acccordion-button-label-show\" type=\"button\"><span class=\"kt-blocks-accordion-title-wrap\"><span class=\"kt-blocks-accordion-title\"><strong>Além de fabricar os produtos, a Uônix realiza o projeto e a instalação?</strong></span></span><span class=\"kt-blocks-accordion-icon-trigger\"></span></button></div><div class=\"kt-accordion-panel\"><div class=\"kt-accordion-panel-inner\"><!-- wp:paragraph -->\n"
            . "<p>Sim, dispomos de corpo de engenharia próprio para elaboração de projetos executivos, instalação in loco, ensaios de arrancamento estático e emissão de ART registrada no CREA.</p>\n"
            . "<!-- /wp:paragraph --></div></div></div>\n"
            . "<!-- /wp:kadence/pane -->\n</div></div></div>\n<!-- /wp:kadence/accordion -->";

        $c = str_replace( "</div></div></div>\n<!-- /wp:kadence/accordion -->", $new_panes, $c );
    }

    wp_update_post( array(
        'ID'           => $faq_post_target->ID,
        'post_content' => $c,
    ) );
    echo "✅ [OK] Bloco Padrão FAQ (ID {$faq_post_target->ID}) sincronizado com 9 perguntas e links relativos.\n";
    $updated_count++;
} else {
    echo "⚠️  [AVISO] Bloco wp_block 'faq' não localizado para atualização automática.\n";
    $skipped_count++;
}

// =========================================================================
// 5. FLUSH DE CACHE
// =========================================================================
echo "\n--- 5. LIMPEZA DE CACHE ---\n";
if ( function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
    echo "✅ [OK] wp_cache_flush() executado com sucesso.\n";
}

echo "\n========================================================================\n";
echo "🎉 SINCRONIZAÇÃO CONCLUÍDA!\n";
echo "   Itens atualizados: {$updated_count}\n";
echo "   Itens ignorados/ausentes: {$skipped_count}\n";
echo "========================================================================\n";
