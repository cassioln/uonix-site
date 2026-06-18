<?php
/**
 * Plugin Name: Uonix Core
 * Description: Bootstrap dos módulos MU do site Uonix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UONIX_MU_PATH', trailingslashit( WPMU_PLUGIN_DIR ) );
define( 'UONIX_MU_URL', trailingslashit( WPMU_PLUGIN_URL ) );
define( 'UONIX_ENV', function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );

if ( ! function_exists( 'uonix_mu_require_files' ) ) {
	/**
	 * Carrega os arquivos de um módulo em ordem explícita e registra falhas sem derrubar o site.
	 */
	function uonix_mu_require_files( $base_dir, array $files, $module_name ) {
		foreach ( $files as $file ) {
			$path = trailingslashit( $base_dir ) . $file;

			if ( ! is_readable( $path ) ) {
				error_log( sprintf( 'UONIX MU: arquivo ausente no módulo %s: %s', $module_name, $path ) );
				continue;
			}

			try {
				require_once $path;
			} catch ( Throwable $exception ) {
				error_log(
					sprintf(
						'UONIX MU: erro ao carregar %s em %s: %s',
						$file,
						$module_name,
						$exception->getMessage()
					)
				);
			}
		}
	}
}

if ( ! function_exists( 'uonix_mu_load_modules' ) ) {
	/**
	 * Carrega os módulos principais do site.
	 */
	function uonix_mu_load_modules() {
		$modules = array(
			'uonix-shared/module.php',
			'uonix-content/module.php',
			'uonix-woocommerce/module.php',
			'uonix-navigation/module.php',
			'uonix-fluentforms/module.php',
			'uonix-forms/module.php',
			'uonix-admin/module.php',
			'uonix-integrations/module.php',
		);

		if ( in_array( UONIX_ENV, array( 'local', 'development' ), true ) ) {
			$modules[] = 'uonix-local/module.php';
		}

		foreach ( $modules as $module ) {
			$path = UONIX_MU_PATH . $module;

			if ( ! is_readable( $path ) ) {
				error_log( sprintf( 'UONIX MU: módulo não encontrado: %s', $path ) );
				continue;
			}

			try {
				require_once $path;
			} catch ( Throwable $exception ) {
				error_log(
					sprintf(
						'UONIX MU: erro ao carregar módulo %s: %s',
						$module,
						$exception->getMessage()
					)
				);
			}
		}

		do_action( 'uonix_mu_loaded' );
	}
}

uonix_mu_load_modules();
