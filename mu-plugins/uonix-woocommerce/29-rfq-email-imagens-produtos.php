<?php
/**
 * WooCommerce: Otimização de imagens de produtos para e-mails de orçamento (RFQ).
 *
 * Gera e serve miniaturas com fundo branco composto (#FFFFFF) em formato JPEG de alta
 * resolução (300x300 px) para os e-mails transacionais da Uônix.
 *
 * Motivo:
 * Clientes de e-mail (especialmente o Gmail Image Proxy e Outlook) descartam o canal alfa
 * de arquivos WebP/PNG transparentes, convertendo a transparência em fundo preto absoluto.
 * Além disso, o tamanho 'thumbnail' padrão (150x150) fica em baixa resolução em telas Retina
 * e comprime excessivamente produtos de proporção horizontal (como escovas e limpadores).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna a URL de uma miniatura de produto otimizada para e-mail com fundo branco e alta resolução.
 *
 * @param WC_Product|int|mixed $product Produto WooCommerce ou ID do post.
 * @param int                  $size    Tamanho da imagem quadrada em pixels (padrão: 300).
 * @return string URL pública da imagem JPEG composta sobre branco, ou fallback.
 */
function uonix_get_email_product_image_url( $product, $size = 300 ) {
	if ( ! is_numeric( $size ) || (int) $size < 16 || (int) $size > 2048 ) {
		$size = 300;
	} else {
		$size = (int) $size;
	}

	if ( is_numeric( $product ) ) {
		$product = wc_get_product( $product );
	}

	if ( ! is_a( $product, 'WC_Product' ) ) {
		return '';
	}

	$thumb_id = $product->get_image_id();

	// Se for variação sem imagem própria, herda a imagem do produto pai
	if ( ! $thumb_id && $product->is_type( 'variation' ) ) {
		$parent_id = method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : 0;
		if ( $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent && is_a( $parent, 'WC_Product' ) ) {
				$thumb_id = $parent->get_image_id();
			}
		}
	}

	if ( ! $thumb_id ) {
		return function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
	}

	$source_path = get_attached_file( $thumb_id );
	if ( ! $source_path || ! file_exists( $source_path ) ) {
		return wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $thumb_id, 'full' );
	}

	$upload_dir = wp_upload_dir();
	$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'email-thumbs';
	$cache_url  = trailingslashit( $upload_dir['baseurl'] ) . 'email-thumbs';

	$filename  = sprintf( 'email-thumb-%d-%dx%d.jpg', $thumb_id, $size, $size );
	$dest_path = $cache_dir . '/' . $filename;
	$dest_url  = $cache_url . '/' . $filename;

	// Se o arquivo em cache já existe, tem tamanho válido (>0) e é mais recente que a imagem fonte, retorna imediatamente
	if ( file_exists( $dest_path ) && filesize( $dest_path ) > 0 && filemtime( $dest_path ) >= filemtime( $source_path ) ) {
		return $dest_url;
	}

	// Garante que o diretório de cache existe
	if ( ! is_dir( $cache_dir ) ) {
		wp_mkdir_p( $cache_dir );
	}

	// Processamento com GD do PHP protegido contra exceções, erros de valor e falhas de I/O
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$canvas    = null;
		$im        = null;
		$temp_path = null;

		try {
			$raw_data = @file_get_contents( $source_path );
			if ( false !== $raw_data && strlen( $raw_data ) > 0 ) {
				$im = @imagecreatefromstring( $raw_data );

				if ( $im ) {
					$sw = imagesx( $im );
					$sh = imagesy( $im );

					if ( $sw > 0 && $sh > 0 ) {
						// Padding de 15px de cada lado para respiro visual do produto
						$padding = 15;
						$max_box = max( 1, $size - ( $padding * 2 ) );

						$ratio = min( $max_box / $sw, $max_box / $sh );
						$dw    = max( 1, (int) round( $sw * $ratio ) );
						$dh    = max( 1, (int) round( $sh * $ratio ) );
						$dx    = (int) round( ( $size - $dw ) / 2 );
						$dy    = (int) round( ( $size - $dh ) / 2 );

						$canvas = @imagecreatetruecolor( $size, $size );
						if ( $canvas ) {
							$white = imagecolorallocate( $canvas, 255, 255, 255 );
							imagefilledrectangle( $canvas, 0, 0, $size, $size, $white );

							imagealphablending( $canvas, true );
							imagecopyresampled( $canvas, $im, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );

							// Gravação atômica em arquivo temporário único no mesmo diretório
							$temp_path = $dest_path . '.' . uniqid( 'tmp_', true ) . '.tmp';
							$written   = @imagejpeg( $canvas, $temp_path, 90 );

							if ( true === $written && file_exists( $temp_path ) && filesize( $temp_path ) > 0 ) {
								if ( @rename( $temp_path, $dest_path ) && file_exists( $dest_path ) && filesize( $dest_path ) > 0 ) {
									if ( PHP_VERSION_ID < 80500 ) {
										imagedestroy( $canvas );
										imagedestroy( $im );
									}
									return $dest_url;
								}
							}
						}
					}
				}
			}
		} catch ( Throwable $e ) {
			// Silencioso em produção: erros de I/O ou decodificação degradam suavemente para fallback
		} finally {
			if ( $temp_path && file_exists( $temp_path ) ) {
				@unlink( $temp_path );
			}
			if ( PHP_VERSION_ID < 80500 ) {
				if ( is_resource( $canvas ) || ( is_object( $canvas ) && $canvas instanceof GdImage ) ) {
					@imagedestroy( $canvas );
				}
				if ( is_resource( $im ) || ( is_object( $im ) && $im instanceof GdImage ) ) {
					@imagedestroy( $im );
				}
			}
		}
	}

	// Fallback padrão caso a geração falhe ou GD indisponível
	return wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $thumb_id, 'full' );
}

/**
 * Garante que o logotipo e as imagens de cabeçalho nos e-mails tenham dimensões elegantes e centralizadas.
 */
add_filter( 'woocommerce_email_styles', function( $css ) {
	$css .= '
	#template_header_image {
		text-align: center !important;
		padding: 24px 0 16px !important;
	}
	#template_header_image img {
		width: 150px !important;
		max-width: 150px !important;
		height: auto !important;
		margin: 0 auto !important;
		display: block !important;
	}
	';
	return $css;
}, 99 );
