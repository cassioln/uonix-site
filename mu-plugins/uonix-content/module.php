<?php
/**
 * Conteúdo, blog, comentários, footer e shortcodes editoriais.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'02-shortcodes-pdf-servicos.php',
		'10-comentarios-master.php',
		'11-comentarios-avatar-validacao.php',
		'25-blog-pagina.php',
		'26-blog-post-single.php',
		'36-blog-carrosseis-busca.php',
		'37-blog-arquivo-editor.php',
		'44-footer-copyright.php',
		'45-seo-faqpage-schema.php',
		'46-seo-tabs-wordcount.php',
		'47-seo-organization-schema.php',
		'48-seo-master-schema-graph.php',
		'49-seo-product-schema-enhancement.php',
	),
	'uonix-content'
);
