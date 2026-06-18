<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Blog - coluna administrativa de feedback e traducao.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1314-1368 do export original.
// -----------------------------------------------------------------------------
/**
 * Tradução da Coluna de Feedback em Posts (Plugin YellowPencil)
 */
/**
 * UÔNIX: Limpeza e Tradução da Coluna de Feedback
 * Remove a coluna original do plugin para evitar duplicação e cria uma nova.
 */

// 1. Remove a coluna original e adiciona a nova coluna 'uonix_feedback'
add_filter( 'manage_edit-post_columns', function( $columns ) {
    // Removemos a coluna 'helpful' original do plugin YellowPencil
    unset( $columns['helpful'] );
    
    // Adicionamos a nossa coluna personalizada ao final
    $columns['uonix_feedback'] = 'Feedback';
    return $columns;
}, 20 );

// 2. Controla o conteúdo da nova coluna 'uonix_feedback'
add_action( 'manage_posts_custom_column', function( $column_name, $post_id ) {
    if ( 'uonix_feedback' === $column_name ) {
        // Puxamos os valores reais do banco
        $yes = (int) get_post_meta( $post_id, '_wthf_yes', true ) ?: 0;
        $no  = (int) get_post_meta( $post_id, '_wthf_no', true ) ?: 0;
        $total = $yes + $no;

        // Só exibe algo se houver pelo menos 1 voto
        if ( $total > 0 ) {
            $percentage = round( ( $yes / $total ) * 100 );

            // 1. Exibe a Porcentagem
            echo '<div style="font-weight:bold; font-size:14px; margin-bottom:5px;">Aprovação: ' . $percentage . '%</div>';

            // 2. Barra de Progresso (Visual Original do Plugin)
            echo '<div style="width:38%; background:#e2e2e2; height:5px; border-radius:3px; margin-bottom:1px; overflow:hidden;">';
            echo '<div style="width:' . $percentage . '%; background:#28a745; height:100%;"></div>';
            echo '</div>';

            // 3. Exibe os Votos Coloridos
            echo '<span style="color:#28a745; font-weight:bold;">' . $yes . ' útil</span> / ';
            echo '<span style="color:#dc3545; font-weight:bold;">' . $no . ' não útil</span>';
        }
        // Se $total for 0, o WordPress não imprimirá nada, deixando a coluna vazia.
    }
}, 10, 2 );


// 3. Tradução do Título no Front-end (Blog)
add_filter( 'gettext', function( $translated_text, $text, $domain ) {
    if ( 'was-this-article-helpful' === $domain && 'Was This Article Helpful?' === $text ) {
        return 'Esta informação foi útil para você?';
    }
    return $translated_text;
}, 20, 3 );


