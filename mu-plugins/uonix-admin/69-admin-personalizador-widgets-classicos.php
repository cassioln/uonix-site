<?php
/**
 * Personalizador com widgets clássicos.
 *
 * Com o editor de blocos nos widgets, o Personalizador dispara
 * enqueue_block_editor_assets e carrega os estilos de todos os blocos: em
 * produção, customize.php pede cerca de 475 arquivos de uma vez. O servidor da
 * Locaweb responde 429 a dezenas deles, inclusive customize-controls.min.js, e
 * o Personalizador abre sem seções e sem prévia. Local e QA não têm o limite.
 *
 * No modo clássico, o núcleo não carrega esses arquivos. A tela de widgets
 * (widgets.php, o "Editar Rodapé" do editor) continua com blocos. Dentro do
 * Personalizador, um widget de bloco aparece como caixa de HTML.
 *
 * O critério é is_customize_preview(), e não a tela: ele vale para os
 * controles, a prévia e o customize_save. O modo decide como o núcleo monta e
 * valida o valor do widget (raw_instance ou instância serializada), então os
 * três precisam concordar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Desliga o editor de blocos dos widgets dentro do Personalizador.
 *
 * @param bool $usar Decisão atual.
 * @return bool
 */
function uonix_admin_personalizador_widgets_classicos( $usar ) {
	if ( is_customize_preview() ) {
		return false;
	}

	return $usar;
}
add_filter( 'use_widgets_block_editor', 'uonix_admin_personalizador_widgets_classicos' );
