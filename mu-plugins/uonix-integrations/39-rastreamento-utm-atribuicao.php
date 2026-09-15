<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UONIX Snippets - Integrações - Rastreamento e Persistência de Atribuição (UTM, Meta fbclid e Google gclid).
 *
 * Resolve a perda de atribuição (ex: fbclid isolado caindo como direct/none no GA4 e CAPI):
 * 1. Captura e persiste parâmetros de campanha (utm_*, fbclid, gclid) em cookies primários e sessionStorage (First-Touch).
 * 2. Garante a geração canônica do cookie Meta `_fbc` (fb.1.{timestamp_ms}.{fbclid}) quando houver fbclid.
 * 3. Notifica o DataLayer (GTM / GA4) com o evento `uonix_attribution_loaded`.
 * 4. Injeta campos ocultos nos formulários do site e salva a atribuição nos metadados de pedidos WooCommerce (RFQ).
 * 5. Preserva query strings de rastreamento em redirecionamentos canônicos internos.
 *
 * Em conformidade com a Issue #189 e com a skill meta-conversions-api.
 */

if ( ! function_exists( 'uonix_render_attribution_tracker_script' ) ) {
	/**
	 * Emite o script client-side leve para captura, persistência e injeção de parâmetros UTM / fbclid.
	 */
	function uonix_render_attribution_tracker_script() {
		// Não executa no painel administrativo ou em chamadas cron/rest/feed
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		?>
		<script id="uonix-attribution-tracker">
		(function() {
			try {
				var params = new URLSearchParams(window.location.search);
				var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'];
				var found = false;
				var attributionData = {};

				// 1. Coleta os parâmetros presentes na URL atual
				for (var i = 0; i < utmKeys.length; i++) {
					var k = utmKeys[i];
					var val = params.get(k);
					if (val) {
						found = true;
						attributionData[k] = val;
					}
				}

				var maxAge = 30 * 24 * 60 * 60; // 30 dias
				var secure = window.location.protocol === 'https:' ? '; Secure' : '';

				// Função auxiliar para gravar cookie primário seguro
				function setAttributionCookie(name, value) {
					document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
				}

				function getAttributionCookie(name) {
					var match = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'));
					return match ? decodeURIComponent(match[3]) : '';
				}

				// 2. Se encontrou novos parâmetros na URL, salva no navegador (Primeiro/Último toque)
				if (found) {
					for (var key in attributionData) {
						if (attributionData.hasOwnProperty(key)) {
							setAttributionCookie('uonix_' + key, attributionData[key]);
							try {
								sessionStorage.setItem('uonix_' + key, attributionData[key]);
							} catch (e) {}
						}
					}

					// Caso específico da Meta: Se houver fbclid, gera o cookie canônico _fbc se ainda não existir
					if (attributionData.fbclid) {
						var existingFbc = getAttributionCookie('_fbc');
						if (!existingFbc) {
							var fbcValue = 'fb.1.' + Date.now() + '.' + attributionData.fbclid;
							setAttributionCookie('_fbc', fbcValue);
						}
					}
				}

				// 3. Recupera os dados consolidados (atuais ou persistidos previamente)
				var consolidated = {};
				var hasAny = false;
				for (var j = 0; j < utmKeys.length; j++) {
					var keyName = utmKeys[j];
					var saved = attributionData[keyName];
					if (!saved) {
						try {
							saved = sessionStorage.getItem('uonix_' + keyName);
						} catch (e) {}
					}
					if (!saved) {
						saved = getAttributionCookie('uonix_' + keyName);
					}
					if (saved) {
						consolidated[keyName] = saved;
						hasAny = true;
					}
				}

				// 4. Empurra os dados para o dataLayer se houver origem identificada
				if (hasAny && window.dataLayer) {
					window.dataLayer.push({
						event: 'uonix_attribution_loaded',
						traffic_source: consolidated.utm_source || (consolidated.fbclid ? 'meta' : (consolidated.gclid ? 'google' : '')),
						traffic_medium: consolidated.utm_medium || (consolidated.fbclid || consolidated.gclid ? 'cpc' : ''),
						traffic_campaign: consolidated.utm_campaign || '',
						traffic_content: consolidated.utm_content || '',
						traffic_term: consolidated.utm_term || '',
						traffic_fbclid: consolidated.fbclid || '',
						traffic_gclid: consolidated.gclid || ''
					});
				}

				// 5. Injeta campos ocultos em formulários da página para repassar ao backend
				function injectHiddenFields() {
					if (!hasAny) return;
					var forms = document.querySelectorAll('form');
					forms.forEach(function(form) {
						// Ignora formulários de busca interna
						if (form.getAttribute('role') === 'search' || form.classList.contains('search-form')) {
							return;
						}
						for (var field in consolidated) {
							if (consolidated.hasOwnProperty(field)) {
								var existingInput = form.querySelector('input[name="' + field + '"]');
								if (!existingInput) {
									var hiddenInput = document.createElement('input');
									hiddenInput.type = 'hidden';
									hiddenInput.name = field;
									hiddenInput.value = consolidated[field];
									form.appendChild(hiddenInput);
								} else if (!existingInput.value) {
									existingInput.value = consolidated[field];
								}
							}
						}
					});
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', injectHiddenFields);
				} else {
					injectHiddenFields();
				}
			} catch (err) {}
		})();
		</script>
		<?php
	}
}
add_action( 'wp_head', 'uonix_render_attribution_tracker_script', 5 );

if ( ! function_exists( 'uonix_save_attribution_to_woocommerce_order' ) ) {
	/**
	 * Salva os metadados de atribuição no pedido de orçamento (RFQ) do WooCommerce.
	 *
	 * @param int $order_id ID do pedido criado.
	 */
	function uonix_save_attribution_to_woocommerce_order( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$utm_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid' );
		$updated  = false;

		foreach ( $utm_keys as $key ) {
			$val = '';
			// Tenta pegar do POST
			if ( ! empty( $_POST[ $key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			} elseif ( ! empty( $_COOKIE[ 'uonix_' . $key ] ) ) {
				// Fallback no cookie persistido
				$val = sanitize_text_field( wp_unslash( $_COOKIE[ 'uonix_' . $key ] ) );
			}

			if ( ! empty( $val ) ) {
				$order->update_meta_data( '_uonix_' . $key, $val );
				$updated = true;
			}
		}

		// Se tiver fbclid ou _fbc, garante registro
		if ( ! empty( $_COOKIE['_fbc'] ) ) {
			$order->update_meta_data( '_uonix_fbc', sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) );
			$updated = true;
		}
		if ( ! empty( $_COOKIE['_fbp'] ) ) {
			$order->update_meta_data( '_uonix_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
			$updated = true;
		}

		if ( $updated ) {
			$order->save();
		}
	}
}
add_action( 'woocommerce_checkout_update_order_meta', 'uonix_save_attribution_to_woocommerce_order', 20 );

if ( ! function_exists( 'uonix_display_attribution_in_order_admin' ) ) {
	/**
	 * Exibe o card de Atribuição e Origem da Campanha no painel de edição do pedido WooCommerce.
	 *
	 * @param WC_Order $order Objeto do pedido.
	 */
	function uonix_display_attribution_in_order_admin( $order ) {
		$source   = $order->get_meta( '_uonix_utm_source' );
		$medium   = $order->get_meta( '_uonix_utm_medium' );
		$campaign = $order->get_meta( '_uonix_utm_campaign' );
		$fbclid   = $order->get_meta( '_uonix_fbclid' );
		$gclid    = $order->get_meta( '_uonix_gclid' );

		if ( empty( $source ) && empty( $campaign ) && empty( $fbclid ) && empty( $gclid ) ) {
			return;
		}
		?>
		<div class="order_data_column" style="width: 100%; margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
			<h3><strong>🎯 Origem do Lead / Campanha (Atribuição)</strong></h3>
			<p>
				<?php if ( ! empty( $source ) ) : ?>
					<strong>Origem:</strong> <?php echo esc_html( $source ); ?><br>
				<?php endif; ?>
				<?php if ( ! empty( $medium ) ) : ?>
					<strong>Mídia:</strong> <?php echo esc_html( $medium ); ?><br>
				<?php endif; ?>
				<?php if ( ! empty( $campaign ) ) : ?>
					<strong>Campanha:</strong> <?php echo esc_html( $campaign ); ?><br>
				<?php endif; ?>
				<?php if ( ! empty( $fbclid ) ) : ?>
					<strong>Meta fbclid:</strong> <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( substr( $fbclid, 0, 30 ) . '...' ); ?></code><br>
				<?php endif; ?>
				<?php if ( ! empty( $gclid ) ) : ?>
					<strong>Google gclid:</strong> <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( substr( $gclid, 0, 30 ) . '...' ); ?></code><br>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'uonix_display_attribution_in_order_admin', 20 );

if ( ! function_exists( 'uonix_preserve_attribution_query_string_on_redirect' ) ) {
	/**
	 * Preserva parâmetros UTM e identificadores de clique em redirecionamentos internos canônicos.
	 *
	 * @param string $location URL de destino.
	 * @param int    $status   Código HTTP de redirecionamento.
	 * @return string
	 */
	function uonix_preserve_attribution_query_string_on_redirect( $location, $status ) {
		if ( empty( $_SERVER['QUERY_STRING'] ) || empty( $location ) ) {
			return $location;
		}

		$utm_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid' );
		$to_append = array();

		foreach ( $utm_keys as $key ) {
			if ( isset( $_GET[ $key ] ) && ! empty( $_GET[ $key ] ) ) {
				$to_append[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		if ( ! empty( $to_append ) ) {
			return add_query_arg( $to_append, $location );
		}

		return $location;
	}
}
add_filter( 'wp_redirect', 'uonix_preserve_attribution_query_string_on_redirect', 10, 2 );
