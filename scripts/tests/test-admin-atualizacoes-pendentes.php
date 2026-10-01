<?php
/**
 * Teste da lista informativa de atualizações pendentes no card de manutenção.
 *
 * A lista é somente leitura: o papel editor vê o que está atrasado, mas não pode
 * receber link, botão ou atalho para disparar atualização daqui.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$GLOBALS['uox_test_site_transients'] = array();
$GLOBALS['uox_test_plugins']         = array();
$GLOBALS['wp_version']               = '6.8.1';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_site_transient( $key ) {
	return $GLOBALS['uox_test_site_transients'][ $key ] ?? false;
}

function get_plugins() {
	return $GLOBALS['uox_test_plugins'];
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/39-admin-editor-dashboard.php';

$failures = 0;

function uox_atualizacoes_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function uox_atualizacoes_render() {
	ob_start();
	uox_render_lista_atualizacoes_pendentes();
	return (string) ob_get_clean();
}

// Gravidade: major → alta, minor → média, patch → baixa.
uox_atualizacoes_assert( 'alta' === uox_get_gravidade_atualizacao( '6.8.1', '7.0' ), 'salto de major deve ser alta' );
uox_atualizacoes_assert( 'media' === uox_get_gravidade_atualizacao( '6.8.1', '6.9' ), 'salto de minor deve ser média' );
uox_atualizacoes_assert( 'baixa' === uox_get_gravidade_atualizacao( '6.8.1', '6.8.2' ), 'salto de patch deve ser baixa' );

// Sem pendências: mensagem de "tudo atualizado" e nenhuma lista.
$vazio = uox_atualizacoes_render();
uox_atualizacoes_assert( false !== strpos( $vazio, 'Tudo atualizado' ), 'sem pendências deve mostrar "Tudo atualizado"' );
uox_atualizacoes_assert( false === strpos( $vazio, '<li' ), 'sem pendências não deve renderizar itens' );

// Núcleo com response "latest" não é pendência.
$GLOBALS['uox_test_site_transients']['update_core'] = (object) array(
	'updates' => array( (object) array( 'response' => 'latest', 'current' => '6.8.1' ) ),
);
uox_atualizacoes_assert( array() === uox_get_atualizacoes_pendentes(), 'núcleo em "latest" não deve virar pendência' );

// Núcleo e plugin pendentes.
$GLOBALS['uox_test_site_transients']['update_core']    = (object) array(
	'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '6.9' ) ),
);
$GLOBALS['uox_test_site_transients']['update_plugins'] = (object) array(
	'response' => array(
		'exemplo/exemplo.php' => (object) array( 'new_version' => '3.0.0' ),
	),
);
$GLOBALS['uox_test_plugins'] = array(
	'exemplo/exemplo.php' => array( 'Name' => 'Plugin <Exemplo>', 'Version' => '2.4.1' ),
);

$itens = uox_get_atualizacoes_pendentes();
uox_atualizacoes_assert( 2 === count( $itens ), 'núcleo e plugin pendentes devem gerar 2 itens' );
uox_atualizacoes_assert( 'media' === ( $itens[0]['gravidade'] ?? '' ), 'núcleo 6.8.1 → 6.9 deve ser média' );
uox_atualizacoes_assert( 'alta' === ( $itens[1]['gravidade'] ?? '' ), 'plugin 2.4.1 → 3.0.0 deve ser alta' );

$lista = uox_atualizacoes_render();
uox_atualizacoes_assert( 2 === substr_count( $lista, '<li' ), 'a lista deve renderizar um <li> por pendência' );
uox_atualizacoes_assert( false !== strpos( $lista, 'Plugin &lt;Exemplo&gt;' ), 'o nome do plugin deve sair escapado' );
uox_atualizacoes_assert( false === strpos( $lista, '<Exemplo>' ), 'o nome do plugin não pode sair cru' );

// Somente informativo: nenhum controle que dispare atualização.
uox_atualizacoes_assert(
	1 !== preg_match( '#<a[\s>]|<button|<form|href=#i', $lista ),
	'a lista é somente informativa: não pode conter link, botão ou formulário'
);

if ( 0 !== $failures ) {
	exit( 1 );
}

echo "PASS: lista de atualizações pendentes somente informativa\n";
