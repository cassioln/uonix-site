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
	 *
	 * A validação é conservadora de propósito. Uma URL de outro host contendo
	 * "/wp-content/" no caminho não deve produzir o mtime de um arquivo local
	 * homônimo, e o resultado é confinado a WP_CONTENT_DIR por realpath para
	 * recusar symlink e travessia — inclusive codificada.
	 */
	function uonix_rfq_stable_asset_path( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}

		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $src ) : parse_url( $src );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}

		// Só resolvemos caminho local para assets do próprio site: um host
		// diferente indica CDN ou terceiro, sem arquivo local correspondente.
		if ( ! empty( $parts['host'] ) && function_exists( 'home_url' ) ) {
			$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url() ) : parse_url( home_url() );
			$home_host = is_array( $home ) && ! empty( $home['host'] ) ? $home['host'] : '';
			// `www.` é o mesmo site: tratar como host distinto faria o asset cair
			// no fallback de versão sem necessidade, quando home_url() e a URL
			// enfileirada usam variantes diferentes.
			$normalize = static function ( $host ) {
				return preg_replace( '/^www\./i', '', strtolower( (string) $host ) );
			};
			if ( '' !== $home_host && $normalize( $parts['host'] ) !== $normalize( $home_host ) ) {
				return '';
			}
		}

		$path = rawurldecode( (string) $parts['path'] );
		if ( '' === $path ) {
			return '';
		}

		$needle = '/wp-content/';
		$position = strpos( $path, $needle );
		if ( false === $position ) {
			return '';
		}

		$relative = substr( $path, $position + strlen( $needle ) );
		if ( ! is_string( $relative ) || '' === $relative ) {
			return '';
		}

		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
		if ( '' === $base ) {
			return '';
		}

		$candidate = rtrim( $base, '/' ) . '/' . ltrim( $relative, '/' );

		// Canonicaliza e confina: symlink ou travessia que escape de wp-content
		// é recusado, e não apenas a forma textual "..".
		$resolved = realpath( $candidate );
		$resolved_base = realpath( $base );
		if ( false === $resolved || false === $resolved_base ) {
			return '';
		}
		if ( 0 !== strpos( $resolved, rtrim( $resolved_base, '/' ) . '/' ) ) {
			return '';
		}

		return $resolved;
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

		// Separa fragmento antes de mexer na query: `?ver=` precisa ficar ANTES
		// de `#`, senão o parâmetro entra no fragmento e o asset perde a versão.
		$fragment = '';
		$hash_at = strpos( $src, '#' );
		if ( false !== $hash_at ) {
			$fragment = substr( $src, $hash_at );
			$src = substr( $src, 0, $hash_at );
		}

		$base_url = $src;
		$preserved = array();
		$query_start = strpos( $src, '?' );
		if ( false !== $query_start ) {
			$base_url = substr( $src, 0, $query_start );
			$query = substr( $src, $query_start + 1 );
			foreach ( explode( '&', (string) $query ) as $pair ) {
				if ( '' === $pair || 'ver' === strtok( $pair, '=' ) ) {
					continue;
				}
				$preserved[] = $pair;
			}
		}
		if ( '' === $base_url ) {
			return $src . $fragment;
		}

		$preserved[] = 'ver=' . rawurlencode( $version );

		return $base_url . '?' . implode( '&', $preserved ) . $fragment;
	}
}

// Prioridade alta para rodar depois de quem monta a URL, mas o filtro é
// idempotente: aplicar duas vezes produz o mesmo resultado.
add_filter( 'style_loader_src', 'uonix_rfq_stabilize_asset_src', 20, 2 );
add_filter( 'script_loader_src', 'uonix_rfq_stabilize_asset_src', 20, 2 );
