<?php
/**
 * Contrato dos espelhos de lead para o Fluent Forms ID 4.
 *
 * O campo capturalead_newsletters e um radio com valores canonicos "sim" e
 * "nao". O Fluent Forms rejeita arrays e variacoes de caixa durante a
 * validacao, impedindo a criacao inteira do lead (a submissao entera falha,
 * nao so o campo).
 *
 * Este teste faz uma asserçao POSITIVA e isolada por arquivo: extrai a
 * expressao exata atribuida a 'capturalead_newsletters' no ponto de
 * chamada de handleSubmission(..., 4) e valida contra um allowlist
 * estrito de escalares canonicos. Qualquer valor fora do allowlist -
 * incluindo array(), variações de caixa, ou strings arbitrárias como
 * 'talvez' - reprova o teste.
 */

$root = dirname( __DIR__, 2 );

// allowlist estrito de expressoes PHP aceitas para o valor do campo.
// Cada entrada é a expressão EXATA (após normalização de espaços) que o
// código pode usar para atribuir capturalead_newsletters.
$allowed_expressions = array(
	"'sim'",
	"'nao'",
	"\$opt_in ? 'sim' : 'nao'",
	"\$optin ? 'sim' : 'nao'",
);

// Cada entrada: arquivo => regex que captura o valor atribuído a
// capturalead_newsletters no payload enviado ao Form 4 (grupo 1 = expressão).
$sources = array(
	'mu-plugins/uonix-fluentforms/08-fluentforms-sync-woocommerce.php' =>
		"/'capturalead_newsletters'\\s*=>\\s*([^,\\n]+),/",
	'mu-plugins/uonix-fluentforms/09-fluentforms-sync-contato.php' =>
		"/\\\$payloadForm4\\['capturalead_newsletters'\\]\\s*=\\s*([^;]+);/",
	'mu-plugins/uonix-forms/29-form-captura-lead.php' =>
		"/\\\$payload_form4\\['capturalead_newsletters'\\]\\s*=\\s*([^;]+);/",
	'mu-plugins/uonix-forms/32-form-newsletter.php' =>
		"/'capturalead_newsletters'\\s*=>\\s*([^,\\n]+),/",
	'mu-plugins/uonix-content/10-comentarios-master.php' =>
		"/'capturalead_newsletters'\\s*=>\\s*([^,\\n]+),/",
);

$failures = array();

function uonix_form4_assert( $condition, $message ) {
	global $failures;

	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function uonix_form4_normalize( $expr ) {
	return trim( preg_replace( '/\s+/', ' ', $expr ) );
}

foreach ( $sources as $file => $pattern ) {
	$path = $root . '/' . $file;
	$source = file_get_contents( $path );

	uonix_form4_assert( false !== $source, $file . ': arquivo legivel.' );
	if ( false === $source ) {
		continue;
	}

	$matches = array();
	$found = preg_match_all( $pattern, $source, $matches );

	uonix_form4_assert(
		$found >= 1,
		$file . ': encontrou ao menos uma atribuicao de capturalead_newsletters ao Form 4 (payload real).'
	);

	if ( $found < 1 ) {
		continue;
	}

	// Todas as ocorrências encontradas (pode haver mais de uma chamada
	// handleSubmission no mesmo arquivo) devem estar no allowlist.
	foreach ( $matches[1] as $raw_expr ) {
		$expr = uonix_form4_normalize( $raw_expr );
		uonix_form4_assert(
			in_array( $expr, $allowed_expressions, true ),
			$file . ": valor '{$expr}' atribuido a capturalead_newsletters NAO esta no allowlist canonico (sim/nao)."
		);
	}
}

// Verificação adicional e explícita: nenhum arquivo pode conter os padrões
// invalidos conhecidos historicamente (maiusculas ou array), como cinto de
// seguranca contra reintroducao por copy-paste em outro ponto do arquivo.
$forbidden_patterns = array(
	"/'capturalead_newsletters'\\s*=>\\s*\\\$opt_in\\s*\\?\\s*'SIM'\\s*:\\s*'NAO'/",
	"/'capturalead_newsletters'\\s*=>\\s*\\\$optin\\s*\\?\\s*'SIM'\\s*:\\s*'NAO'/",
	"/\\['capturalead_newsletters'\\]\\s*=\\s*array\\(\\s*'sim'\\s*\\)/",
	"/\\['capturalead_newsletters'\\]\\s*=\\s*\\[\\s*'sim'\\s*\\]/",
	"/'capturalead_newsletters'\\s*=>\\s*array\\(\\s*'sim'\\s*\\)/",
	"/'capturalead_newsletters'\\s*=>\\s*\\[\\s*'sim'\\s*\\]/",
);

foreach ( $sources as $file => $pattern ) {
	$source = file_get_contents( $root . '/' . $file );
	if ( false === $source ) {
		continue;
	}
	foreach ( $forbidden_patterns as $forbidden ) {
		uonix_form4_assert(
			0 === preg_match( $forbidden, $source ),
			$file . ': contem um padrao invalido conhecido (SIM/NAO maiusculo ou array) para capturalead_newsletters.'
		);
	}
}

$woo_source = file_get_contents( $root . '/mu-plugins/uonix-fluentforms/08-fluentforms-sync-woocommerce.php' );
uonix_form4_assert(
	false !== strpos( $woo_source, '->errors()' ),
	'WooCommerce registra os erros estruturados de validacao do Fluent Forms (ValidationException::errors()).'
);

if ( $failures ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: os 5 espelhos do Form 4 enviam exclusivamente valores canonicos sim/nao para o radio de newsletter.\n" );
