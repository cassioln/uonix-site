<?php
/**
 * Functions do tema filho Kadence.
 *
 * Carrega apenas os snippets visuais que continuam acoplados ao tema Kadence.
 * Regras de negócio, shortcodes e integrações ficam em wp-content/mu-plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'uonix_child_load_snippets' ) ) {
    /**
     * Carrega snippets visuais do tema filho.
     *
     * Para desativar temporariamente um snippet, renomeie o arquivo para:
     * - _nome-do-snippet.php
     * - nome-do-snippet.disabled.php
     */
    function uonix_child_load_snippets() {
        $snippets_dir = trailingslashit( get_stylesheet_directory() ) . 'snippets';

        if ( ! is_dir( $snippets_dir ) ) {
            return;
        }

        $snippet_files = glob( trailingslashit( $snippets_dir ) . '*.php' );

        if ( empty( $snippet_files ) ) {
            return;
        }

        natcasesort( $snippet_files );

        foreach ( $snippet_files as $snippet_file ) {
            $basename = basename( $snippet_file );

            if (
                'index.php' === $basename ||
                '_' === substr( $basename, 0, 1 ) ||
                '.disabled.php' === substr( $basename, -13 )
            ) {
                continue;
            }

            try {
                require_once $snippet_file;
            } catch ( Throwable $exception ) {
                error_log(
                    sprintf(
                        'UONIX snippet error em %s: %s',
                        $basename,
                        $exception->getMessage()
                    )
                );
            }
        }
    }
}

uonix_child_load_snippets();
