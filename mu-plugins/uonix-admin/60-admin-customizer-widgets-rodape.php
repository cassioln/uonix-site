<?php
/**
 * Personalizador: widgets do rodapé editáveis por quem não administra o tema.
 *
 * O Kadence move as seções `sidebar-widgets-footer1` a `footer6` para o painel
 * `kadence_customizer_footer`, pelo filtro `customizer_widgets_section_args`,
 * na prioridade 10. Esse painel é criado com a capability do tema, que vem do
 * filtro `kadence_theme_customizer_capability` e por padrão é `manage_options`.
 *
 * O papel editor tem `edit_theme_options`, mas não tem `manage_options`. A
 * seção de widgets passa na checagem de permissão; o painel-pai, não. O
 * WordPress descarta o painel e não desenha a seção que ficou sem ele. O lápis
 * da prévia manda focar essa seção e nada aparece (relato de 2026-10-01).
 *
 * Para quem não tem a capability do tema, a seção volta ao painel "Widgets" do
 * núcleo, que exige só `edit_theme_options`. O editor passa a editar o conteúdo
 * dos widgets do rodapé. As opções de layout e de design do construtor de
 * rodapé do Kadence continuam restritas a quem tem a capability do tema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability que o Kadence exige para os painéis dele no Personalizador.
 *
 * Lê do próprio tema quando ele está carregado, para nunca divergir da regra
 * dele. Sem o tema, repete o padrão documentado pelo Kadence.
 *
 * @return string
 */
function uonix_admin_kadence_customizer_capability() {
	if ( is_callable( array( '\Kadence\Theme_Customizer', 'get_capability' ) ) ) {
		return (string) \Kadence\Theme_Customizer::get_capability();
	}

	return (string) apply_filters( 'kadence_theme_customizer_capability', 'manage_options' );
}

/**
 * Devolve ao painel "Widgets" as seções do rodapé que o usuário não veria.
 *
 * @param array  $section_args Argumentos da seção de widgets.
 * @param string $section_id   Id da seção, como `sidebar-widgets-footer4`.
 * @param string $sidebar_id   Id da área de widgets, como `footer4`.
 * @return array
 */
function uonix_admin_footer_widgets_section_args( $section_args, $section_id = '', $sidebar_id = '' ) {
	if ( ! is_array( $section_args ) ) {
		return $section_args;
	}

	if ( ! isset( $section_args['panel'] ) || 'kadence_customizer_footer' !== $section_args['panel'] ) {
		return $section_args;
	}

	if ( current_user_can( uonix_admin_kadence_customizer_capability() ) ) {
		return $section_args;
	}

	$section_args['panel'] = 'widgets';

	return $section_args;
}
add_filter( 'customizer_widgets_section_args', 'uonix_admin_footer_widgets_section_args', 20, 3 );
