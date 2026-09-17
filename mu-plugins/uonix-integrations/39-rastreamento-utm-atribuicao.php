<?php
/**
 * Persistência consentida de atribuição de campanhas.
 *
 * Mantém UTM/click IDs somente após consentimento de marketing e associa dados
 * validados a submissões Fluent Forms e pedidos WooCommerce/RFQ.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_attribution_allowed_keys' ) ) {
	function uonix_attribution_allowed_keys() {
		return array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'gclid',
			'fbclid',
		);
	}
}

if ( ! function_exists( 'uonix_attribution_sanitize_value' ) ) {
	/**
	 * Aceita somente identificadores compactos de campanha; descarta valores
	 * arbitrários antes de qualquer cookie, HTML, dataLayer ou banco.
	 */
	function uonix_attribution_sanitize_value( $key, $value ) {
		if ( ! in_array( $key, uonix_attribution_allowed_keys(), true ) || ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value || strlen( $value ) > 255 ) {
			return '';
		}

		return preg_match( '/\A[\p{L}\p{N}][\p{L}\p{N} ._~:@\/\-]{0,254}\z/u', $value )
			? $value
			: '';
	}
}

if ( ! function_exists( 'uonix_attribution_values_from_source' ) ) {
	function uonix_attribution_values_from_source( $source ) {
		$values = array();
		foreach ( uonix_attribution_allowed_keys() as $key ) {
			if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) ) {
				continue;
			}
			$value = uonix_attribution_sanitize_value( $key, $source[ $key ] );
			if ( '' !== $value ) {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}
}

if ( ! function_exists( 'uonix_attribution_marketing_consent' ) ) {
	function uonix_attribution_marketing_consent() {
		return isset( $_COOKIE['uonix_attribution_marketing'] )
			&& is_scalar( $_COOKIE['uonix_attribution_marketing'] )
			&& '1' === (string) $_COOKIE['uonix_attribution_marketing'];
	}
}

if ( ! function_exists( 'uonix_attribution_submission_values' ) ) {
	function uonix_attribution_submission_values( $form_data ) {
		$source = array();
		foreach ( uonix_attribution_allowed_keys() as $key ) {
			$posted_key = 'uonix_attribution_' . $key;
			if ( is_array( $form_data ) && array_key_exists( $posted_key, $form_data ) ) {
				$source[ $key ] = $form_data[ $posted_key ];
			} elseif ( isset( $_COOKIE[ $posted_key ] ) ) {
				$source[ $key ] = $_COOKIE[ $posted_key ];
			}
		}
		return uonix_attribution_values_from_source( $source );
	}
}

if ( ! function_exists( 'uonix_attribution_filter_internal_redirect' ) ) {
	/**
	 * Mantém identificadores somente quando o WordPress redireciona para o
	 * próprio domínio. Destinos externos, esquemas e URLs já normalizadas são no-op.
	 */
	function uonix_attribution_filter_internal_redirect( $location, $status ) {
		if ( ! is_string( $location ) || '' === $location ) {
			return $location;
		}

		$values = uonix_attribution_values_from_source( $_GET );
		if ( empty( $values ) ) {
			return $location;
		}

		$target = wp_parse_url( $location );
		$site = wp_parse_url( home_url( '/' ) );
		$target_host = is_array( $target ) && isset( $target['host'] ) ? strtolower( (string) $target['host'] ) : '';
		$site_host = is_array( $site ) && isset( $site['host'] ) ? strtolower( (string) $site['host'] ) : '';
		$target_scheme = is_array( $target ) && isset( $target['scheme'] ) ? strtolower( (string) $target['scheme'] ) : '';

		if ( ( '' !== $target_host && $target_host !== $site_host ) || ( '' === $target_host && '' !== $target_scheme ) ) {
			return $location;
		}

		$current = array();
		if ( is_array( $target ) && ! empty( $target['query'] ) ) {
			parse_str( (string) $target['query'], $current );
		}
		$needs_update = false;
		foreach ( $values as $key => $value ) {
			if ( ! isset( $current[ $key ] ) || ! is_scalar( $current[ $key ] ) || (string) $current[ $key ] !== $value ) {
				$needs_update = true;
				break;
			}
		}

		return $needs_update ? add_query_arg( $values, $location ) : $location;
	}
}
add_filter( 'wp_redirect', 'uonix_attribution_filter_internal_redirect', 20, 2 );

if ( ! function_exists( 'uonix_attribution_save_order_meta' ) ) {
	function uonix_attribution_save_order_meta( $order ) {
		if ( ! $order || ! uonix_attribution_marketing_consent() ) {
			return;
		}

		$values = uonix_attribution_submission_values( $_POST );
		foreach ( $values as $key => $value ) {
			$order->update_meta_data( '_uonix_' . $key, $value );
		}
		if ( ! empty( $values ) ) {
			$order->update_meta_data( '_uonix_attribution_consent', 'marketing' );
		}
	}
}
add_action(
	'woocommerce_checkout_create_order',
	static function ( $order ) {
		uonix_attribution_save_order_meta( $order );
	},
	30,
	1
);

if ( ! function_exists( 'uonix_attribution_save_fluentform_meta' ) ) {
	function uonix_attribution_save_fluentform_meta( $entry_id, $form_data, $form ) {
		if ( ! uonix_attribution_marketing_consent() || ! is_object( $form ) || ! isset( $form->id ) ) {
			return;
		}

		$form_id = (int) $form->id;
		if ( ! in_array( $form_id, array( 2, 3, 4 ), true ) || (int) $entry_id <= 0 ) {
			return;
		}

		$values = uonix_attribution_submission_values( $form_data );
		if ( empty( $values ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return;
		}

		$table = $wpdb->prefix . 'fluentform_submission_meta';
		if ( ! method_exists( $wpdb, 'get_var' ) || $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		$meta_key = 'uonix_attribution';
		$wpdb->delete( $table, array( 'response_id' => (int) $entry_id, 'meta_key' => $meta_key ) );
		$wpdb->insert(
			$table,
			array(
				'response_id' => (int) $entry_id,
				'form_id' => $form_id,
				'meta_key' => $meta_key,
				'value' => wp_json_encode( $values ),
				'status' => 'published',
				'user_id' => 0,
				'name' => 'Uonix attribution',
			)
		);
	}
}
add_action( 'fluentform_submission_inserted', 'uonix_attribution_save_fluentform_meta', 30, 3 );

if ( ! function_exists( 'uonix_attribution_order_admin_card' ) ) {
	function uonix_attribution_order_admin_card( $order ) {
		if ( ! $order || 'marketing' !== $order->get_meta( '_uonix_attribution_consent' ) ) {
			return;
		}
		$source = $order->get_meta( '_uonix_utm_source' );
		$campaign = $order->get_meta( '_uonix_utm_campaign' );
		if ( '' === (string) $source && '' === (string) $campaign ) {
			return;
		}
		?>
		<div class="order_data_column" style="width:100%;margin-top:15px;padding-top:10px;border-top:1px solid #ddd;">
			<h3>Origem de campanha</h3>
			<p>
				<?php if ( '' !== (string) $source ) : ?><strong>Origem:</strong> <?php echo esc_html( $source ); ?><br><?php endif; ?>
				<?php if ( '' !== (string) $campaign ) : ?><strong>Campanha:</strong> <?php echo esc_html( $campaign ); ?><br><?php endif; ?>
				<em>Registrada após consentimento de marketing.</em>
			</p>
		</div>
		<?php
	}
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'uonix_attribution_order_admin_card', 30 );

if ( ! function_exists( 'uonix_render_attribution_tracker_script' ) ) {
	function uonix_render_attribution_tracker_script() {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		?>
		<script id="uonix-attribution-tracker">
		(function() {
			var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'];
			var prefix = 'uonix_attribution_';
			var maxAge = 30 * 24 * 60 * 60;
			var pending = {};

			function validValue(value) {
				return typeof value === 'string' && /^[\p{L}\p{N}][\p{L}\p{N} ._~:@/\-]{0,254}$/u.test(value);
			}

			function setCookie(name, value, maxAgeSeconds) {
				var secure = window.location.protocol === 'https:' ? '; Secure' : '';
				document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAgeSeconds + '; SameSite=Lax' + secure;
			}

			function removeCookie(name) {
				var secure = window.location.protocol === 'https:' ? '; Secure' : '';
				document.cookie = name + '=; Path=/; Max-Age=0; SameSite=Lax' + secure;
			}

			function getCookie(name) {
				var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
				try { return match ? decodeURIComponent(match[1]) : ''; } catch (error) { return ''; }
			}

			function hasMarketingConsent() {
				if (window._adoptExplicitlyRejected === true || window._adoptMarketingGranted === false) return false;
				if (window._adoptMarketingGranted === true) return true;
				if (Array.isArray(window.acceptedTags) && window.acceptedTags.indexOf('marketing') !== -1) return true;
				try {
					if (window.localStorage && window.localStorage.getItem('_adoptReject') === '1') return false;
					var raw = window.localStorage && window.localStorage.getItem('adoptConsentMode');
					var consent = raw ? JSON.parse(raw) : null;
					return !!(consent && (consent.marketing === true || consent.ad_storage === 'granted'));
				} catch (error) {
					return false;
				}
			}

			function valuesFromStorage() {
				var values = {};
				keys.forEach(function(key) {
					var value = pending[key] || '';
					if (!value) {
						try { value = window.sessionStorage ? window.sessionStorage.getItem(prefix + key) : ''; } catch (error) { value = ''; }
					}
					if (!value) value = getCookie(prefix + key);
					if (validValue(value)) values[key] = value;
				});
				return values;
			}

			function targetForm(form) {
				return !!(form && form.matches && form.matches('form.frm-fluent-form, form.fluentform, form[data-form_id], form[data-form-id], form.uonix-news-wrapper, form.woocommerce-checkout') && !form.matches('#uonix-custom-trab-form'));
			}

			function injectIntoForm(form, values) {
				if (!targetForm(form)) return;
				keys.forEach(function(key) {
					var value = values[key];
					if (!value) return;
					var name = prefix + key;
					var input = form.querySelector('input[name="' + name + '"]');
					if (!input) {
						input = document.createElement('input');
						input.type = 'hidden';
						input.name = name;
						input.setAttribute('data-uonix-attribution-field', '1');
						form.appendChild(input);
					}
					input.value = value;
				});
			}

			function injectAll(values) {
				document.querySelectorAll('form').forEach(function(form) { injectIntoForm(form, values); });
			}

			function persistAfterConsent() {
				if (!hasMarketingConsent()) return;
				var values = valuesFromStorage();
				if (!Object.keys(values).length) return;
				keys.forEach(function(key) {
					if (!values[key]) return;
					setCookie(prefix + key, values[key], maxAge);
					try { if (window.sessionStorage) window.sessionStorage.setItem(prefix + key, values[key]); } catch (error) {}
				});
				setCookie('uonix_attribution_marketing', '1', maxAge);
				if (values.fbclid && !getCookie('_fbc')) setCookie('_fbc', 'fb.1.' + Date.now() + '.' + values.fbclid, maxAge);
				injectAll(values);
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push({
					event: 'uonix_attribution_loaded',
					traffic_source: values.utm_source || (values.fbclid ? 'meta' : (values.gclid ? 'google' : '')),
					traffic_medium: values.utm_medium || (values.fbclid || values.gclid ? 'cpc' : ''),
					traffic_campaign: values.utm_campaign || '',
					traffic_content: values.utm_content || '',
					traffic_term: values.utm_term || ''
				});
			}

			function revokeAttribution() {
				keys.forEach(function(key) {
					removeCookie(prefix + key);
					try { if (window.sessionStorage) window.sessionStorage.removeItem(prefix + key); } catch (error) {}
				});
				removeCookie('uonix_attribution_marketing');
				removeCookie('_fbc');
				document.querySelectorAll('[data-uonix-attribution-field="1"]').forEach(function(input) { input.remove(); });
			}

			try {
				var query = new URLSearchParams(window.location.search);
				keys.forEach(function(key) {
					var value = query.get(key);
					if (validValue(value)) pending[key] = value;
				});
			} catch (error) {}

			document.addEventListener('submit', function(event) {
				if (hasMarketingConsent()) injectIntoForm(event.target, valuesFromStorage());
			}, true);

			window.addEventListener('uonix_adopt_consent_updated', function(event) {
				var detail = event && event.detail;
				if (detail && detail.marketing === false) {
					revokeAttribution();
					return;
				}
				persistAfterConsent();
			});

			if (hasMarketingConsent()) persistAfterConsent();
		})();
		</script>
		<?php
	}
}
add_action( 'wp_head', 'uonix_render_attribution_tracker_script', 20 );
