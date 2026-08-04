<?php
/**
 * Protege o escopo do CSS da tela de login.
 *
 * MOTIVAÇÃO (print de 2026-08-03, DEV): em `wp-login.php?action=confirm_admin_email`
 * o card apareceu com o texto "PAINEL DE CONTROLE" grudado dentro dele e o título
 * "Verificação do e-mail de administração" desalinhado, fora do padrão visual.
 *
 * A causa não foi o WordPress: o core usa <h1> DUAS vezes nessa tela — o logo em
 * `#login > h1` e o próprio título do formulário em
 * `<h1 class="admin-email__heading">`, dentro de `form.admin-email-confirm-form`.
 * O CSS do módulo mirava `.login h1`, que casa com os dois. O resultado era o
 * título do formulário recebendo caixa de logo, `overflow`, largura de 100% e o
 * `::after` com o texto do rótulo.
 *
 * Este teste falha se o seletor amplo voltar ao bloco EM USO, e também se o
 * formulário dessa tela deixar de receber o estilo de card.
 */

$module = dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/45-login-personalizado.php';

if ( ! is_file( $module ) ) {
	fwrite( STDERR, "FAIL: 45-login-personalizado.php não encontrado.\n" );
	exit( 1 );
}

$source = file_get_contents( $module );

/*
 * Só o CSS realmente entregue ao navegador é auditado. O bloco de `admin_head`
 * começa com `return;` e é código morto; incluí-lo geraria falha em CSS que
 * nunca é impresso, e mascararia o defeito real com ruído.
 */
$login_css_start = strpos( $source, "add_action('login_enqueue_scripts'" );
$dead_block_start = strpos( $source, "add_action('admin_head'" );

if ( false === $login_css_start || false === $dead_block_start || $dead_block_start <= $login_css_start ) {
	fwrite( STDERR, "FAIL: não foi possível delimitar o CSS ativo do login.\n" );
	exit( 1 );
}

$active_css = substr( $source, $login_css_start, $dead_block_start - $login_css_start );

$failures = 0;

function uonix_visual_assert( $condition, $message ) {
	global $failures;

	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, sprintf( "FAIL: %s\n", $message ) );
		return;
	}

	printf( "ok   %s\n", $message );
}

/*
 * 1. Nenhum seletor de logo pode casar com qualquer <h1> da tela.
 *    Detecta `.login h1`, `body.login h1` e a variante interim-login.
 *
 * O `\.?` é obrigatório: sem ele o padrão exigia um separador imediatamente
 * antes de "login" e não casava com `.login h1` — o seletor exato do defeito.
 * A falsificação acusou essa cegueira, então a expressão foi corrigida.
 */
$broad_selectors = preg_match_all( '/(?:^|[\s,{}])(?:body)?\.?login(?:\.interim-login)?\s+h1(?![\w-])/mi', $active_css );

uonix_visual_assert(
	0 === $broad_selectors,
	sprintf( 'CSS ativo não usa seletor amplo de h1 no login (encontrados: %d)', $broad_selectors )
);

/*
 * 2. O logo continua estilizado — a correção não pode ter apagado a marca.
 */
uonix_visual_assert(
	false !== strpos( $active_css, '.login #login > h1 a' ),
	'logo do login permanece estilizado por seletor de filho direto'
);

uonix_visual_assert(
	false !== strpos( $active_css, '.login #login > h1::after' ),
	'rótulo "Painel de controle" fica restrito ao cabeçalho do logo'
);

uonix_visual_assert(
	false !== strpos( $active_css, 'body.login.interim-login #login > h1::after' ),
	'rótulo "Sessão expirada" também fica restrito ao cabeçalho do logo'
);

/*
 * 3. A tela de confirmação de e-mail precisa do mesmo card das outras telas.
 */
uonix_visual_assert(
	false !== strpos( $active_css, 'form.admin-email-confirm-form' ),
	'formulário de confirmação de e-mail recebe o estilo de card'
);

uonix_visual_assert(
	false !== strpos( $active_css, 'h1.admin-email__heading' ),
	'título da confirmação de e-mail é tratado como texto, não como logo'
);

uonix_visual_assert(
	1 === preg_match( '/h1\.admin-email__heading::after[^{]*\{[^}]*content:\s*none/s', $active_css ),
	'título da confirmação de e-mail não herda pseudo-elemento com rótulo'
);

if ( 0 !== $failures ) {
	exit( 1 );
}

printf( "PASS: CSS do login não vaza estilo de logo para títulos do core.\n" );
