<?php
/**
 * Widgets "Bloco" criados pelo Max Mega Menu sem a chave `content`.
 *
 * Ao adicionar um widget "Bloco" num painel de mega menu, o Max Mega Menu grava
 * a instância só com as chaves dele (`mega_menu_columns`,
 * `mega_menu_is_grid_widget`...), sem `content`, e ela fica assim até alguém
 * preencher o bloco. O front-end não sente: `WP_Widget_Block` completa
 * `content` vazio ao renderizar. O editor de widgets em blocos sente: entrega
 * `content` indefinido ao parser, o laço que converte todos os widgets aborta e
 * todas as áreas aparecem vazias; salvar dá "Cannot read properties of undefined
 * (reading 'map')" (relato de 2026-10-01, block-88, 89 e 93 em todos os
 * ambientes).
 *
 * Na leitura da opção, toda instância sem `content` recebe o mesmo `content`
 * vazio que o núcleo já assume ao renderizar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Completa `content` vazio nas instâncias de widget de bloco que não o têm.
 *
 * @param mixed $instances Valor da opção `widget_block`.
 * @return mixed
 */
function uonix_navigation_widget_block_content_padrao( $instances ) {
	if ( ! is_array( $instances ) ) {
		return $instances;
	}

	foreach ( $instances as $number => $instance ) {
		if ( is_array( $instance ) && ! array_key_exists( 'content', $instance ) ) {
			$instances[ $number ]['content'] = '';
		}
	}

	return $instances;
}
add_filter( 'option_widget_block', 'uonix_navigation_widget_block_content_padrao' );
