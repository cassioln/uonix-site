<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Admin - colunas de IDs em taxonomias de produtos.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 5167-5308 do export original.
// -----------------------------------------------------------------------------
/**
 * Mostrar coluna ID em listas do admin (taxonomias, atributos, termos, marcas, categorias, tags)
 */
/**
 * UÔNIX - Mostrar coluna ID (ordenável) em TODAS as listas de termos (taxonomias)
 * - Última coluna
 * - Ordenável por ID
 * - Ajuste visual (Contagem x ID)
 * - Correção: em product_cat, joga o "handle" de ordenação para a ÚLTIMA coluna
 */

add_action('admin_init', function () {

    if ( ! is_admin() ) return;

    // Pega todas as taxonomias com UI no admin
    $taxes = get_taxonomies(['show_ui' => true], 'names');
    if (empty($taxes)) return;

    foreach ($taxes as $tax) {

        // Colunas (adiciona ID por último)
        add_filter("manage_edit-{$tax}_columns", function ($columns) use ($tax) {

            // Mantém checkbox primeiro, se existir
            $cb = [];
            if (isset($columns['cb'])) {
                $cb = ['cb' => $columns['cb']];
                unset($columns['cb']);
            }

            // Remove ID antigo (se alguém já adicionou)
            if (isset($columns['uonix_id'])) unset($columns['uonix_id']);

            // Recompõe
            $columns = $cb + $columns;

            /**
             * ✅ FIX: só em CATEGORIAS (product_cat)
             * Alguns plugins/woo adicionam uma coluna "handle" (arrastar/ordenar) que aparece entre Contagem e ID.
             * Aqui a gente remove e recoloca no final, DEPOIS do ID.
             */
            $handle_key = null;
            if ($tax === 'product_cat') {
                $possible = ['handle','order','term_order','menu_order','drag','sort','wcco_sort','tto_order'];
                foreach ($possible as $k) {
                    if (isset($columns[$k])) { $handle_key = $k; break; }
                }
            }

            $handle_val = null;
            if ($handle_key) {
                $handle_val = $columns[$handle_key];
                unset($columns[$handle_key]);
            }

            // ID por último
            $columns['uonix_id'] = 'ID';

            // Handle por ÚLTIMO (só product_cat)
            if ($handle_key) {
                $columns[$handle_key] = $handle_val;
            }

            return $columns;
        }, 999);

        // Conteúdo da coluna
        add_filter("manage_{$tax}_custom_column", function ($content, $column_name, $term_id) {
            if ($column_name === 'uonix_id') {
                return (string) $term_id;
            }
            return $content;
        }, 10, 3);

        // Ordenável
        add_filter("manage_edit-{$tax}_sortable_columns", function ($sortable) {
            $sortable['uonix_id'] = 'uonix_id';
            return $sortable;
        }, 999);
    }
});

/**
 * Faz o clique na coluna "ID" realmente ordenar por ID.
 */
add_filter('request', function ($vars) {

    if ( ! is_admin() ) return $vars;

    global $pagenow;
    if ($pagenow !== 'edit-tags.php') return $vars;

    if (!empty($vars['orderby']) && $vars['orderby'] === 'uonix_id') {
        $vars['orderby'] = 'id'; // Term query entende "id"
    }

    return $vars;
});

/**
 * Ajustes visuais:
 * - mais espaço entre Contagem e ID
 * - header do ID alinhado à direita
 */
add_action('admin_head', function () {

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'edit-tags') return;

    ?>
    <style>
      /* Respira Contagem e ID */
      .wp-list-table th.column-posts,
      .wp-list-table td.column-posts {
        padding-right: 22px !important;
        min-width: 95px;
        text-align: right;
      }

      .wp-list-table th.column-uonix_id,
      .wp-list-table td.column-uonix_id {
        padding-left: 22px !important;
        min-width: 75px;
        text-align: right;
        white-space: nowrap;
      }

      /* ✅ Alinha o texto "ID" do cabeçalho à direita também */
      .wp-list-table th.column-uonix_id a {
        display: inline-block;
        float: right;
        white-space: nowrap;
      }
      .wp-list-table th.column-uonix_id .sorting-indicators {
        float: right;
        margin-right: 6px;
      }
    </style>
    <?php
});


