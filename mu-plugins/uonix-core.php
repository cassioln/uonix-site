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

if ( ! function_exists( 'uonix_mu_detect_environment' ) ) {
	/**
	 * Detecta o ambiente pelo WordPress e pelo host, útil quando wp-config.php ainda não define WP_ENVIRONMENT_TYPE.
	 */
	function uonix_mu_detect_environment() {
		$wp_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$host           = '';

		if ( function_exists( 'home_url' ) ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
		}

		if ( empty( $host ) && ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}

		$host = strtolower( preg_replace( '/:\d+$/', '', (string) $host ) );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return 'local';
		}

		if ( str_starts_with( $host, 'qa.' ) || str_contains( $host, 'qa.uonix.' ) ) {
			return 'staging';
		}

		if ( in_array( $wp_environment, array( 'local', 'development', 'staging' ), true ) ) {
			return 'development' === $wp_environment ? 'local' : $wp_environment;
		}

		return 'production';
	}
}

define( 'UONIX_ENV', uonix_mu_detect_environment() );

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
			'uonix-performance/module.php',
			'uonix-integrations/module.php',
		);

		if ( 'local' === UONIX_ENV ) {
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
