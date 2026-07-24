<?php
/**
 * Ajustes de performance de primeira dobra.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

uonix_mu_require_files(
	__DIR__,
	array(
		'50-home-primeira-dobra.php',
	),
	'uonix-performance'
);
