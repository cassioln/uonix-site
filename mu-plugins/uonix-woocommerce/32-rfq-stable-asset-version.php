<?php
/**
 * Estabiliza a versão dos assets do plugin RFQ para permitir cache de página.
 *
 * O plugin woo-rfq-for-woocommerce enfileira CSS e JS usando
 * `wp_rand( 10, 100000 )` como número de versão — por exemplo em
 * woo-rfq-for-woocommerce.php:878 e :934. Cada requisição produz uma URL
 * diferente, então o HTML nunca é idêntico entre duas visitas anônimas.
 *
 * Consequência medida em produção: o WP Super Cache GRAVA o arquivo estático,
 * mas nunca o SERVE em categoria e produto, porque o conteúdo muda sempre.
 * A home ficou em 260 ms (servida do disco) enquanto categoria e produto
 * permaneceram em ~2,1 s e ~2,8 s, regenerando a cada acesso.
 *
 * Esta política não altera o plugin: filtra apenas a URL final dos handles
 * conhecidos do RFQ e substitui a versão aleatória por uma derivada do arquivo
 * (mtime) ou da versão do plugin. Assim o cache-busting real continua
 * funcionando quando o asset muda, e o HTML volta a ser determinístico.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_rfq_stable_asset_handles' ) ) {
	/**
	 * Handles enfileirados pelo RFQ com versão aleatória.
	 *
	 * Somente estes são tocados: qualquer outro asset — WooCommerce, Kadence,
	 * jQuery — permanece exatamente como o WordPress o produziu.
	 */
	function uonix_rfq_stable_asset_handles() {
		return array(
			'gpls_woo_rfq_css'      => true,
			'url_gpls_wh_css'       => true,
			'url_gpls_wh_css2'      => true,
			'gpls_woo_rfq_js'       => true,
			'url_gpls_wh_js'        => true,
			'gpls_woo_password_js'  => true,
			'rfq_dummy_js'          => true,
		);
	}
}

if ( ! function_exists( 'uonix_rfq_stable_asset_version' ) ) {
	/**
	 * Versão determinística para um asset do RFQ.
	 *
	 * Preferimos o mtime do arquivo: muda quando o asset realmente muda, o que
	 * preserva o cache-busting. Se o caminho não puder ser resolvido, caímos na
	 * versão do plugin e, por último, numa constante — nunca em valor aleatório.
	 */
	function uonix_rfq_stable_asset_version( $src ) {
		$path = uonix_rfq_stable_asset_path( $src );

		if ( '' !== $path && is_readable( $path ) ) {
			$mtime = filemtime( $path );
			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}

		if ( defined( 'gpls_woo_rfq_VERSION' ) && '' !== (string) gpls_woo_rfq_VERSION ) {
			return (string) gpls_woo_rfq_VERSION;
		}

		return 'uonix-stable';
	}
}

if ( ! function_exists( 'uonix_rfq_stable_asset_path' ) ) {
	/**
	 * Converte a URL do asset em caminho local, quando ela pertence ao site.
	 *
	 * Retorna string vazia para URL externa ou fora de wp-content: nesse caso
	 * não há arquivo local cujo mtime possa ser lido.
	 */
	function uonix_rfq_stable_asset_path( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}

		$without_query = strtok( $src, '?' );
		if ( ! is_string( $without_query ) || '' === $without_query ) {
			return '';
		}

		$needle = '/wp-content/';
		$position = strpos( $without_query, $needle );
		if ( false === $position ) {
			return '';
		}

		$relative = substr( $without_query, $position + strlen( $needle ) );
		if ( ! is_string( $relative ) || '' === $relative ) {
			return '';
		}

		// Recusa travessia: o caminho precisa ficar dentro de wp-content.
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
		if ( '' === $base ) {
			return '';
		}

		return rtrim( $base, '/' ) . '/' . ltrim( $relative, '/' );
	}
}

if ( ! function_exists( 'uonix_rfq_stabilize_asset_src' ) ) {
	/**
	 * Substitui a versão aleatória por uma estável, preservando a URL do asset.
	 *
	 * @param string $src    URL completa do asset.
	 * @param string $handle Handle registrado no WordPress.
	 * @return string URL com versão determinística, ou a original se não for RFQ.
	 */
	function uonix_rfq_stabilize_asset_src( $src, $handle = '' ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		$handles = uonix_rfq_stable_asset_handles();
		if ( ! is_string( $handle ) || ! isset( $handles[ $handle ] ) ) {
			return $src;
		}

		$version = uonix_rfq_stable_asset_version( $src );
		$without_query = strtok( $src, '?' );
		if ( ! is_string( $without_query ) || '' === $without_query ) {
			return $src;
		}

		// Preserva outros parâmetros de query que não sejam a versão.
		$preserved = array();
		$query_start = strpos( $src, '?' );
		if ( false !== $query_start ) {
			$query = substr( $src, $query_start + 1 );
			foreach ( explode( '&', (string) $query ) as $pair ) {
				if ( '' === $pair || 0 === strpos( $pair, 'ver=' ) ) {
					continue;
				}
				$preserved[] = $pair;
			}
		}

		$preserved[] = 'ver=' . rawurlencode( $version );

		return $without_query . '?' . implode( '&', $preserved );
	}
}

// Prioridade alta para rodar depois de quem monta a URL, mas o filtro é
// idempotente: aplicar duas vezes produz o mesmo resultado.
add_filter( 'style_loader_src', 'uonix_rfq_stabilize_asset_src', 20, 2 );
add_filter( 'script_loader_src', 'uonix_rfq_stabilize_asset_src', 20, 2 );
