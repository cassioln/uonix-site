<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UONIX Snippets - Avaliacoes - modal Trustindex/Google.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

if ( ! function_exists( 'uonix_trustindex_modal_html' ) ) {
	function uonix_trustindex_modal_html() {
		return do_shortcode( '[trustindex no-registration=google]' );
	}
}

if ( ! function_exists( 'uonix_trustindex_modal_rest_response' ) ) {
	function uonix_trustindex_modal_rest_response() {
		return rest_ensure_response(
			array(
				'html' => uonix_trustindex_modal_html(),
			)
		);
	}
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'uonix/v1',
			'/trustindex-modal',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'uonix_trustindex_modal_rest_response',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Avaliacoes Google Mapas.
 */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() ) {
			return;
		}

		$endpoint = rest_url( 'uonix/v1/trustindex-modal' );
		?>

		<div id="trustindex-modal" class="trustindex-modal" aria-hidden="true" data-trustindex-endpoint="<?php echo esc_url( $endpoint ); ?>">
			<div class="trustindex-modal__overlay" data-trustindex-close></div>

			<div class="trustindex-modal__box" role="dialog" aria-modal="true" aria-label="Avaliações Google" tabindex="-1">
				<button class="trustindex-modal__close" type="button" aria-label="Fechar modal" data-trustindex-close>
					&times;
				</button>

				<div class="trustindex-modal__content" data-trustindex-content>
					<div class="trustindex-modal__status" data-trustindex-status>Carregando avaliações...</div>
				</div>
			</div>
		</div>

		<style>
			.trustindex-modal {
				position: fixed;
				inset: 0;
				z-index: 999999;
				display: none;
				align-items: center;
				justify-content: center;
				padding: 20px;
			}

			.trustindex-modal.is-open {
				display: flex;
			}

			.trustindex-modal__overlay {
				position: absolute;
				inset: 0;
				background: rgba(0, 0, 0, 0.65);
			}

			.trustindex-modal__box {
				position: relative;
				z-index: 1;
				width: 100%;
				max-width: 1400px;
				max-height: 90vh;
				overflow: visible;
				background: #ffffff;
				border-radius: 16px;
				padding: 52px 28px 28px;
				box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
			}

			.trustindex-modal__content {
				width: 100%;
				max-height: calc(90vh - 80px);
				overflow-y: auto;
				overflow-x: hidden;
			}

			.trustindex-modal__status {
				min-height: 120px;
				display: flex;
				align-items: center;
				justify-content: center;
				color: #334155;
				font-size: 16px;
				font-weight: 600;
			}

			.trustindex-modal__close {
				position: absolute;
				top: 8px;
				right: 8px;
				width: 32px;
				height: 32px;
				padding: 0;
				border: 0 !important;
				background: transparent !important;
				background-color: transparent !important;
				box-shadow: none !important;
				outline: none !important;
				color: inherit !important;
				font-size: 38px;
				font-weight: 200;
				line-height: 1;
				cursor: pointer;
				display: flex;
				align-items: center;
				justify-content: center;
				transform: scale(1);
				transform-origin: center;
				transition: transform 0.18s ease, opacity 0.18s ease;
			}

			.trustindex-modal__close:hover,
			.trustindex-modal__close:focus,
			.trustindex-modal__close:active {
				background: transparent !important;
				background-color: transparent !important;
				border: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				color: inherit !important;
				transform: scale(1.08);
				opacity: 0.75;
			}

			body.trustindex-modal-open {
				overflow: hidden;
			}

			@media (max-width: 767px) {
				.trustindex-modal {
					padding: 12px;
				}

				.trustindex-modal__box {
					max-width: 100%;
					max-height: 88vh;
					padding: 50px 16px 20px;
					border-radius: 12px;
				}

				.trustindex-modal__content {
					max-height: calc(88vh - 70px);
				}

				.trustindex-modal__close {
					top: 8px;
					right: 8px;
					width: 30px;
					height: 30px;
					font-size: 36px;
				}

				.trustindex-modal__close:hover,
				.trustindex-modal__close:focus,
				.trustindex-modal__close:active {
					transform: scale(1.06);
				}
			}
		</style>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const modal = document.getElementById('trustindex-modal');
				const modalBox = modal ? modal.querySelector('.trustindex-modal__box') : null;
				const content = modal ? modal.querySelector('[data-trustindex-content]') : null;
				let contentLoaded = false;
				let contentRequest = null;

				if (!modal || !modalBox || !content) {
					return;
				}

				function loadScript(src) {
					return new Promise(function (resolve, reject) {
						const existing = document.querySelector('script[data-uonix-trustindex-loader][src="' + src + '"]');

						if (existing) {
							resolve();
							return;
						}

						const script = document.createElement('script');
						script.src = src;
						script.async = true;
						script.dataset.uonixTrustindexLoader = '1';
						script.onload = resolve;
						script.onerror = reject;
						document.body.appendChild(script);
					});
				}

				function loadStylesheet(href) {
					if (!href || document.querySelector('link[data-uonix-trustindex-css][href="' + href + '"]')) {
						return;
					}

					const link = document.createElement('link');
					link.rel = 'stylesheet';
					link.href = href;
					link.dataset.uonixTrustindexCss = '1';
					document.head.appendChild(link);
				}

				function bootTrustindexWidget() {
					content.querySelectorAll('[data-css-url]').forEach(function (node) {
						loadStylesheet(node.dataset.cssUrl);
					});

					content.querySelectorAll('[data-src]').forEach(function (node) {
						if (node.dataset.src) {
							loadScript(node.dataset.src).catch(function () {});
						}
					});
				}

				function loadTrustindexContent() {
					if (contentLoaded) {
						bootTrustindexWidget();
						return Promise.resolve();
					}

					if (contentRequest) {
						return contentRequest;
					}

					contentRequest = fetch(modal.dataset.trustindexEndpoint, {
						credentials: 'same-origin',
						headers: {
							'Accept': 'application/json'
						}
					})
						.then(function (response) {
							if (!response.ok) {
								throw new Error('Trustindex request failed');
							}

							return response.json();
						})
						.then(function (payload) {
							content.innerHTML = payload && payload.html ? payload.html : '';
							contentLoaded = true;
							bootTrustindexWidget();
						})
						.catch(function () {
							content.innerHTML = '<div class="trustindex-modal__status">Não foi possível carregar as avaliações agora.</div>';
						});

					return contentRequest;
				}

				function openTrustindexModal(event) {
					if (event) {
						event.preventDefault();
					}

					modal.classList.add('is-open');
					modal.setAttribute('aria-hidden', 'false');
					document.body.classList.add('trustindex-modal-open');

					loadTrustindexContent();

					setTimeout(function () {
						modalBox.focus();
					}, 50);
				}

				function closeTrustindexModal() {
					modal.classList.remove('is-open');
					modal.setAttribute('aria-hidden', 'true');
					document.body.classList.remove('trustindex-modal-open');
				}

				document.addEventListener('click', function (event) {
					const openButton = event.target.closest('.abrir-trustindex-modal');

					if (openButton) {
						openTrustindexModal(event);
						return;
					}

					if (event.target.closest('[data-trustindex-close]')) {
						closeTrustindexModal();
					}
				});

				document.addEventListener('keydown', function (event) {
					if (event.key === 'Escape' && modal.classList.contains('is-open')) {
						closeTrustindexModal();
					}
				});
			});
		</script>

		<?php
	}
);
