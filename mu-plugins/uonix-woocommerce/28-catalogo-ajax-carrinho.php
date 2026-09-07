<?php
/**
 * UONIX - Adição Direta e Controle de Quantidade AJAX no Catálogo e Loops
 *
 * Transforma o botão de produtos simples em um seletor de quantidade retangular
 * com botões de diminuir/lixeira e aumentar, com sincronização em tempo real do carrinho.
 * Para produtos com variações, renderiza o botão "Ver Opções" em laranja.
 *
 * @package Uonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna os SVGs padronizados do Design System Uônix
 */
function uonix_get_cart_loop_svg( $icon ) {
	switch ( $icon ) {
		case 'trash':
			return '<svg class="uonix-qty-icon uonix-icon-trash" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';
		case 'minus':
			return '<svg class="uonix-qty-icon uonix-icon-minus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
		case 'plus':
			return '<svg class="uonix-qty-icon uonix-icon-plus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
		default:
			return '';
	}
}

/**
 * Processa a transição de quantidade do produto no carrinho respeitando restrições comerciais e estoque.
 *
 * @param WC_Product $product
 * @param WC_Cart    $cart
 * @param string     $action_type 'add' | 'increment' | 'decrement' | 'remove'
 * @param int        $current_qty
 * @param string     $cart_item_key
 * @return array {
 *     @type int    $new_qty
 *     @type string $notice_message
 *     @type bool   $limit_reached
 *     @type bool   $sold_individually
 * }
 */
function uonix_process_cart_item_quantity_transition( $product, $cart, $action_type, $current_qty, $cart_item_key ) {
	$is_sold_individually = method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually();
	$notice_message       = '';
	$limit_reached        = false;
	$new_qty              = $current_qty;
	$product_id           = method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
	$product_name         = method_exists( $product, 'get_name' ) ? $product->get_name() : 'Produto';

	switch ( $action_type ) {
		case 'add':
		case 'increment':
			// 1. Limite comercial: produto vendido individualmente não pode exceder 1 unidade no carrinho
			if ( $is_sold_individually && $current_qty >= 1 ) {
				$limit_reached  = true;
				$new_qty        = 1;
				$notice_message = sprintf(
					/* translators: %s: Nome do produto */
					__( 'Você só pode adicionar 1 unidade de &ldquo;%s&rdquo; ao seu carrinho.', 'woocommerce' ),
					$product_name
				);
				break;
			}

			// 2. Limite de estoque: respeita o estoque gerenciado quando não permite encomendas
			if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() &&
				method_exists( $product, 'backorders_allowed' ) && ! $product->backorders_allowed() &&
				method_exists( $product, 'get_stock_quantity' ) && $product->get_stock_quantity() !== null ) {
				$stock_limit = (int) $product->get_stock_quantity();
				if ( $current_qty >= $stock_limit ) {
					$limit_reached  = true;
					$new_qty        = $current_qty;
					$notice_message = sprintf(
						/* translators: 1: Quantidade de estoque, 2: Nome do produto */
						__( 'Você não pode adicionar mais de %1$d unidade(s) de &ldquo;%2$s&rdquo; (limite de estoque).', 'woocommerce' ),
						$stock_limit,
						$product_name
					);
					break;
				}
			}

			// 3. Aplicação normal da alteração
			if ( $cart_item_key ) {
				$new_qty = $current_qty + 1;
				$cart->set_quantity( $cart_item_key, $new_qty );
			} else {
				$new_key = $cart->add_to_cart( $product_id, 1 );
				$new_qty = $new_key ? 1 : 0;
			}
			break;

		case 'decrement':
			if ( $current_qty > 1 && $cart_item_key ) {
				$new_qty = $current_qty - 1;
				$cart->set_quantity( $cart_item_key, $new_qty );
			} elseif ( $cart_item_key ) {
				$cart->remove_cart_item( $cart_item_key );
				$new_qty = 0;
			} else {
				$new_qty = 0;
			}
			break;

		case 'remove':
			if ( $cart_item_key ) {
				$cart->remove_cart_item( $cart_item_key );
			}
			$new_qty = 0;
			break;

		default:
			break;
	}

	return array(
		'new_qty'           => $new_qty,
		'notice_message'    => $notice_message,
		'limit_reached'     => $limit_reached,
		'sold_individually' => $is_sold_individually,
	);
}

/**
 * Endpoint AJAX: Atualização de quantidade no carrinho a partir do loop
 */
add_action( 'wp_ajax_uonix_update_loop_cart_qty', 'uonix_ajax_update_loop_cart_qty' );
add_action( 'wp_ajax_nopriv_uonix_update_loop_cart_qty', 'uonix_ajax_update_loop_cart_qty' );

function uonix_ajax_update_loop_cart_qty() {
	check_ajax_referer( 'uonix_loop_cart_nonce', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'WooCommerce Cart não disponível.' ) );
	}

	$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$action_type = isset( $_POST['action_type'] ) ? sanitize_key( $_POST['action_type'] ) : 'add';

	if ( ! $product_id ) {
		wp_send_json_error( array( 'message' => 'Produto inválido.' ) );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_purchasable() ) {
		wp_send_json_error( array( 'message' => 'Produto indisponível para compra.' ) );
	}

	$cart = WC()->cart;
	$cart_item_key = '';
	$current_qty   = 0;

	// Localiza o item no carrinho atual
	foreach ( $cart->get_cart() as $key => $item ) {
		if ( (int) $item['product_id'] === $product_id ) {
			$cart_item_key = $key;
			$current_qty   = (int) $item['quantity'];
			break;
		}
	}

	$transition = uonix_process_cart_item_quantity_transition( $product, $cart, $action_type, $current_qty, $cart_item_key );

	// Recalcula totais do carrinho
	$cart->calculate_totals();

	// Obtém a contagem real atualizada deste produto
	$final_product_qty = 0;
	foreach ( $cart->get_cart() as $item ) {
		if ( (int) $item['product_id'] === $product_id ) {
			$final_product_qty += (int) $item['quantity'];
		}
	}

	$total_cart_count = $cart->get_cart_contents_count();

	// Gera fragmentos do mini cart se a função estiver disponível
	$fragments = array();
	if ( function_exists( 'woocommerce_mini_cart' ) ) {
		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();
		$fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
	}

	wp_send_json_success(
		array(
			'product_id'        => $product_id,
			'quantity'          => $final_product_qty,
			'cart_count'        => $total_cart_count,
			'action'            => $action_type,
			'sold_individually' => $transition['sold_individually'],
			'limit_reached'     => $transition['limit_reached'],
			'message'           => $transition['notice_message'],
			'fragments'         => apply_filters( 'woocommerce_add_to_cart_fragments', $fragments ),
			'cart_hash'         => apply_filters( 'woocommerce_add_to_cart_hash', $cart->get_cart_hash(), $cart ),
		)
	);
}

/**
 * Endpoint AJAX para consulta rápida das quantidades reais de produtos no carrinho
 */
add_action( 'wp_ajax_uonix_get_cart_quantities', 'uonix_ajax_get_cart_quantities' );
add_action( 'wp_ajax_nopriv_uonix_get_cart_quantities', 'uonix_ajax_get_cart_quantities' );

function uonix_ajax_get_cart_quantities() {
	$quantities = array();
	$total_count = 0;

	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$qty        = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			if ( $product_id > 0 && $qty > 0 ) {
				if ( ! isset( $quantities[ $product_id ] ) ) {
					$quantities[ $product_id ] = 0;
				}
				$quantities[ $product_id ] += $qty;
			}
		}
		$total_count = WC()->cart->get_cart_contents_count();
	}

	wp_send_json_success(
		array(
			'items'       => $quantities,
			'total_count' => $total_count,
		)
	);
}

/**
 * Injeção de Scripts no wp_footer para controle de carrinho no catálogo
 */
add_action( 'wp_footer', 'uonix_loop_cart_scripts', 99 );

function uonix_loop_cart_scripts() {
	$params = array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'uonix_loop_cart_nonce' ),
	);
	?>
	<script id="uonix-loop-cart-action-js">
	window.uonixLoopCartParams = <?php echo wp_json_encode( $params ); ?>;
	(function ($) {
		'use strict';

		var trashSvg = '<svg class="uonix-qty-icon uonix-icon-trash" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';
		var minusSvg = '<svg class="uonix-qty-icon uonix-icon-minus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

		function updateControlState($wrap, qty, isSoldIndividually) {
			$wrap.attr('data-qty', qty);
			var $leftBtn = $wrap.find('.uonix-qty-minus');
			var $plusBtn = $wrap.find('.uonix-qty-plus');
			var $num = $wrap.find('.uonix-qty-num');
			var soldIndividually = (typeof isSoldIndividually !== 'undefined')
				? Boolean(isSoldIndividually)
				: ($wrap.attr('data-sold-individually') === '1');

			if (qty > 0) {
				$wrap.addClass('has-items');
				$num.text(qty);

				if (qty === 1) {
					$leftBtn.addClass('is-trash')
						.attr('title', 'Remover do carrinho')
						.attr('aria-label', 'Remover do carrinho')
						.html(trashSvg);
				} else {
					$leftBtn.removeClass('is-trash')
						.attr('title', 'Diminuir quantidade')
						.attr('aria-label', 'Diminuir quantidade')
						.html(minusSvg);
				}

				if (soldIndividually) {
					$plusBtn.prop('disabled', true)
						.addClass('is-disabled')
						.attr('title', 'Limite de 1 unidade atingido')
						.attr('aria-label', 'Limite de 1 unidade atingido');
				} else {
					$plusBtn.prop('disabled', false)
						.removeClass('is-disabled')
						.attr('title', 'Aumentar quantidade')
						.attr('aria-label', 'Aumentar quantidade');
				}
			} else {
				$wrap.removeClass('has-items');
				$num.text('0');
				$leftBtn.addClass('is-trash')
					.attr('title', 'Remover do carrinho')
					.attr('aria-label', 'Remover do carrinho')
					.html(trashSvg);

				$plusBtn.prop('disabled', false)
					.removeClass('is-disabled')
					.attr('title', 'Aumentar quantidade')
					.attr('aria-label', 'Aumentar quantidade');
			}
		}

		// Atualiza todos os botões da vitrine com base no mapa de quantidades { product_id: qty }
		function applyCartQuantities(itemsMap) {
			itemsMap = itemsMap || {};
			$('.uonix-product-action-wrap').each(function () {
				var $wrap = $(this);
				var pid = $wrap.attr('data-product-id');
				if (pid) {
					var qty = itemsMap[pid] ? parseInt(itemsMap[pid], 10) : 0;
					var currentQty = parseInt($wrap.attr('data-qty') || '0', 10);
					if (qty !== currentQty) {
						updateControlState($wrap, qty);
					}
				}
			});
		}

		// Consulta debounced das quantidades reais do carrinho via endpoint AJAX leve
		var fetchDebounceTimer = null;
		function fetchCartQuantitiesDebounced() {
			clearTimeout(fetchDebounceTimer);
			fetchDebounceTimer = setTimeout(function () {
				$.ajax({
					url: (window.uonixLoopCartParams && window.uonixLoopCartParams.ajax_url) || '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'json',
					data: {
						action: 'uonix_get_cart_quantities'
					},
					success: function (res) {
						if (res && res.success && res.data) {
							applyCartQuantities(res.data.items);
							if (typeof window.syncUonixCart === 'function') {
								window.syncUonixCart();
							}
						}
					}
				});
			}, 100);
		}

		// 1. Intercepta requisições de Store API do WooCommerce Blocks (mini-cart drawer)
		if (window.fetch) {
			var origFetch = window.fetch;
			window.fetch = function () {
				var args = arguments;
				var url = (args && args[0]) ? (typeof args[0] === 'string' ? args[0] : args[0].url) : '';
				var promise = origFetch.apply(this, args);

				if (url && (url.indexOf('/wc/store/') !== -1 || url.indexOf('wc/store/v1/cart') !== -1)) {
					promise.then(function (response) {
						if (response && response.ok) {
							try {
								response.clone().json().then(function (data) {
									if (data && data.items && Array.isArray(data.items)) {
										var itemsMap = {};
										data.items.forEach(function (item) {
											var pid = item.id;
											var qty = parseInt(item.quantity, 10) || 0;
											if (pid) {
												itemsMap[pid] = (itemsMap[pid] || 0) + qty;
											}
										});
										applyCartQuantities(itemsMap);
									} else {
										fetchCartQuantitiesDebounced();
									}
								}).catch(function () {
									fetchCartQuantitiesDebounced();
								});
							} catch (e) {
								fetchCartQuantitiesDebounced();
							}
						}
					});
				}
				return promise;
			};
		}

		// 2. Integração com o dispatcher de State do WooCommerce Blocks (@wordpress/data)
		if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
			try {
				var previousCartHash = null;
				window.wp.data.subscribe(function () {
					var cartSelect = window.wp.data.select('wc/store/cart');
					if (cartSelect && typeof cartSelect.getCartData === 'function') {
						var cartData = cartSelect.getCartData();
						if (cartData && cartData.items) {
							var currentHash = JSON.stringify(cartData.items.map(function (i) { return i.id + ':' + i.quantity; }));
							if (currentHash !== previousCartHash) {
								previousCartHash = currentHash;
								var itemsMap = {};
								cartData.items.forEach(function (item) {
									var pid = item.id;
									var qty = parseInt(item.quantity, 10) || 0;
									if (pid) {
										itemsMap[pid] = (itemsMap[pid] || 0) + qty;
									}
								});
								applyCartQuantities(itemsMap);
							}
						}
					}
				});
			} catch (e) {}
		}

		// 3. Monitora eventos clássicos do WooCommerce
		$(document.body).on(
			'added_to_cart removed_from_cart updated_cart_totals updated_checkout wc_fragments_refreshed wc_fragments_loaded',
			function () {
				fetchCartQuantitiesDebounced();
			}
		);

		// 4. Delegação em botões de exclusão e alteração do mini-cart drawer
		$(document).on('click', '.wc-block-mini-cart__drawer button, .wc-block-cart-item__remove-link, .woocommerce-mini-cart .remove', function () {
			setTimeout(fetchCartQuantitiesDebounced, 250);
		});

		// 5. Atualiza ao recuperar foco da janela/aba
		window.addEventListener('focus', function () {
			fetchCartQuantitiesDebounced();
		});
		window.addEventListener('pageshow', function () {
			fetchCartQuantitiesDebounced();
		});

		function sendCartRequest($wrap, actionType) {
			if ($wrap.hasClass('is-loading')) {
				return;
			}

			var productId = $wrap.attr('data-product-id');
			var currentQty = parseInt($wrap.attr('data-qty') || '0', 10);

			$wrap.addClass('is-loading');

			// Atualização otimista da interface
			var optimisticQty = currentQty;
			if (actionType === 'add' || actionType === 'increment') {
				optimisticQty = currentQty + 1;
			} else if (actionType === 'decrement') {
				optimisticQty = Math.max(0, currentQty - 1);
			} else if (actionType === 'remove') {
				optimisticQty = 0;
			}
			updateControlState($wrap, optimisticQty);

			$.ajax({
				url: (window.uonixLoopCartParams && window.uonixLoopCartParams.ajax_url) || '/wp-admin/admin-ajax.php',
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'uonix_update_loop_cart_qty',
					nonce: (window.uonixLoopCartParams && window.uonixLoopCartParams.nonce) || '',
					product_id: productId,
					action_type: actionType
				},
				success: function (res) {
					if (res && res.success) {
						var confirmedQty = parseInt(res.data.quantity, 10);
						var isSoldIndiv = Boolean(res.data.sold_individually);
						if (isSoldIndiv) {
							$wrap.attr('data-sold-individually', '1');
						}
						updateControlState($wrap, confirmedQty, isSoldIndiv);

						// Atualiza fragmentos do WooCommerce se retornados
						if (res.data.fragments) {
							$.each(res.data.fragments, function(key, value) {
								$(key).replaceWith(value);
							});
						}

						// Dispara atualização para badges do cabeçalho e mini-cart
						if (typeof window.syncUonixCart === 'function') {
							window.syncUonixCart();
						}
						$(document.body).trigger('wc_fragments_refreshed');
						$(document.body).trigger(actionType === 'remove' ? 'removed_from_cart' : 'added_to_cart', [res.data.fragments, res.data.cart_hash]);
					} else {
						// Em caso de erro, reverte para o estado anterior
						updateControlState($wrap, currentQty);
						if (res && res.data && res.data.message) {
							alert(res.data.message);
						}
					}
				},
				error: function () {
					updateControlState($wrap, currentQty);
				},
				complete: function () {
					$wrap.removeClass('is-loading');
				}
			});
		}

		// Delegação de cliques no botão "Adicionar ao carrinho"
		$(document).on('click', '.uonix-product-action-wrap .uonix-add-to-cart-btn', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.uonix-product-action-wrap');
			sendCartRequest($wrap, 'add');
		});

		// Delegação de cliques no botão de diminuir / lixeira
		$(document).on('click', '.uonix-product-action-wrap .uonix-qty-minus', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.uonix-product-action-wrap');
			var isTrash = $(this).hasClass('is-trash');
			sendCartRequest($wrap, isTrash ? 'remove' : 'decrement');
		});

		// Delegação de cliques no botão de aumentar
		$(document).on('click', '.uonix-product-action-wrap .uonix-qty-plus', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $wrap = $btn.closest('.uonix-product-action-wrap');
			var isSoldIndividually = ($wrap.attr('data-sold-individually') === '1');
			var currentQty = parseInt($wrap.attr('data-qty') || '0', 10);

			if ($btn.prop('disabled') || $btn.hasClass('is-disabled') || (isSoldIndividually && currentQty >= 1)) {
				return;
			}

			sendCartRequest($wrap, 'increment');
		});

		// Consulta inicial para garantir consistência
		$(document).ready(function () {
			fetchCartQuantitiesDebounced();
		});

	})(jQuery);
	</script>
	<?php
}

/**
 * Estilos CSS padronizados do Design System Uônix para o botão e controle de quantidade
 */
add_action( 'wp_head', 'uonix_loop_cart_styles', 99 );

function uonix_loop_cart_styles() {
	?>
	<style id="uonix-loop-cart-styles">
		/* Os botões de ação aparecem SOMENTE quando o card do produto tem foco / hover (igual a Produtos Relacionados) */
		.woocommerce ul.products li.product .entry-content-wrap,
		.woocommerce ul.products li.product .product-details {
			transition: transform 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95) !important;
		}

		.woocommerce ul.products li.product:hover .entry-content-wrap,
		.woocommerce ul.products li.product:hover .product-details,
		.woocommerce ul.products li.product:focus-within .entry-content-wrap,
		.woocommerce ul.products li.product:focus-within .product-details {
			transform: translateY(-2rem) !important;
		}

		.woocommerce ul.products li.product .product-details.content-bg.entry-content-wrap,
		.woocommerce ul.products li.product .entry-content-wrap,
		.woocommerce ul.products li.product .product-details,
		.product-details.content-bg.entry-content-wrap {
			justify-content: center !important;
			min-height: 6rem !important;
		}

		.woocommerce ul.products li.product .product-action-wrap {
			position: absolute !important;
			bottom: -2rem !important;
			left: 0 !important;
			right: 0 !important;
			width: auto !important;
			padding: 0 1rem !important;
			margin-top: 0 !important;
			opacity: 0 !important;
			visibility: visible !important;
			pointer-events: none !important;
			transition: opacity 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95), bottom 0.3s cubic-bezier(0.17, 0.67, 0.35, 0.95) !important;
			z-index: 5 !important;
		}

		.woocommerce ul.products li.product:hover .product-action-wrap,
		.woocommerce ul.products li.product:focus-within .product-action-wrap {
			bottom: -0.8rem !important;
			opacity: 1 !important;
			pointer-events: auto !important;
		}

		/* Container da Ação no Card */
		.uonix-product-action-wrap {
			margin-top: auto !important;
			width: 100% !important;
			position: relative !important;
			display: block !important;
			box-sizing: border-box !important;
		}

		/* Botão com Variações (Ver Opções): Mesma cor e formatação que Produtos Relacionados */
		.woocommerce ul.products li.product a.uonix-btn-variable,
		.woocommerce ul.products li.product .uonix-btn-variable,
		.uonix-btn-variable {
			display: flex !important;
			align-items: center !important;
			justify-content: center !important;
			width: 100% !important;
			background: #0e3780 !important;
			color: #ffffff !important;
			padding: 12px 15px !important;
			font-size: 13px !important;
			font-weight: 800 !important;
			text-transform: uppercase !important;
			letter-spacing: 0.5px !important;
			border: none !important;
			border-radius: 6px !important;
			transition: all 0.3s ease !important;
			text-decoration: none !important;
			cursor: pointer !important;
			box-sizing: border-box !important;
			text-align: center !important;
			line-height: 1.2 !important;
			white-space: normal !important;
			box-shadow: none !important;
		}

		/* Mantém o botão Azul institucional quando o hover/foco for no card */
		.woocommerce ul.products li.product:hover .uonix-btn-variable,
		.woocommerce ul.products li.product:focus-within .uonix-btn-variable,
		.woocommerce ul.products li.product:hover a.uonix-btn-variable,
		.woocommerce ul.products li.product:focus-within a.uonix-btn-variable {
			background: #0e3780 !important;
			color: #ffffff !important;
			transform: none !important;
			box-shadow: none !important;
		}

		/* Hover direto no botão Ver Opções acende em Laranja Uônix (idêntico a Produtos Relacionados) */
		.woocommerce ul.products li.product .uonix-btn-variable:hover,
		.woocommerce ul.products li.product a.uonix-btn-variable:hover,
		.woocommerce ul.products li.product:hover .uonix-btn-variable:hover,
		.woocommerce ul.products li.product:hover a.uonix-btn-variable:hover,
		.woocommerce ul.products li.product .uonix-btn-variable:focus,
		.woocommerce ul.products li.product a.uonix-btn-variable:focus,
		.woocommerce ul.products li.product:hover .uonix-btn-variable:focus,
		.woocommerce ul.products li.product .uonix-btn-variable:focus-visible,
		.woocommerce ul.products li.product a.uonix-btn-variable:focus-visible,
		.woocommerce ul.products li.product:hover .uonix-btn-variable:focus-visible {
			background: #f76a0c !important;
			color: #ffffff !important;
			transform: translateY(-2px) !important;
			box-shadow: 0 6px 15px rgba(247, 106, 12, 0.25) !important;
		}

		/* Botão Adicionar ao Carrinho Inicial (Azul Uônix) */
		.woocommerce ul.products li.product .uonix-add-to-cart-btn,
		.uonix-add-to-cart-btn {
			display: flex !important;
			align-items: center !important;
			justify-content: center !important;
			width: 100% !important;
			background: #0e3780 !important;
			color: #ffffff !important;
			padding: 12px 15px !important;
			font-size: 13px !important;
			font-weight: 800 !important;
			text-transform: uppercase !important;
			letter-spacing: 0.5px !important;
			border: none !important;
			border-radius: 6px !important;
			transition: all 0.3s ease !important;
			text-decoration: none !important;
			cursor: pointer !important;
			box-sizing: border-box !important;
		}

		/* Mantém azul quando o hover for no card */
		.woocommerce ul.products li.product:hover .uonix-add-to-cart-btn,
		.woocommerce ul.products li.product:focus-within .uonix-add-to-cart-btn {
			background: #0e3780 !important;
			color: #ffffff !important;
			transform: none !important;
			box-shadow: none !important;
		}

		/* Hover direto no botão Adicionar ao Carrinho acende em Laranja */
		.woocommerce ul.products li.product .uonix-add-to-cart-btn:hover,
		.woocommerce ul.products li.product:hover .uonix-add-to-cart-btn:hover,
		.woocommerce ul.products li.product .uonix-add-to-cart-btn:focus,
		.woocommerce ul.products li.product:hover .uonix-add-to-cart-btn:focus,
		.woocommerce ul.products li.product .uonix-add-to-cart-btn:focus-visible {
			background: #f76a0c !important;
			color: #ffffff !important;
			transform: translateY(-2px) !important;
			box-shadow: 0 6px 15px rgba(247, 106, 12, 0.25) !important;
		}

		/* Quando houver itens no carrinho: oculta o botão Adicionar ao Carrinho */
		.woocommerce ul.products li.product .uonix-product-action-wrap.has-items .uonix-add-to-cart-btn,
		.woocommerce ul.products li.product .uonix-product-action-wrap:not([data-qty="0"]) .uonix-add-to-cart-btn,
		.uonix-product-action-wrap.has-items .uonix-add-to-cart-btn,
		.uonix-product-action-wrap:not([data-qty="0"]) .uonix-add-to-cart-btn {
			display: none !important;
		}

		/* Controle de Quantidade Retangular (Linguagem Visual Uônix) */
		.woocommerce ul.products li.product .uonix-qty-control,
		.uonix-qty-control {
			display: none !important;
			align-items: center !important;
			justify-content: space-between !important;
			width: 100% !important;
			height: 43px !important;
			background: #ffffff !important;
			border: 2px solid #0e3780 !important;
			border-radius: 6px !important;
			overflow: hidden !important;
			box-sizing: border-box !important;
			transition: border-color 0.3s ease, box-shadow 0.3s ease !important;
			user-select: none !important;
		}

		/* Quando houver itens no carrinho: exibe o controle de quantidade */
		.woocommerce ul.products li.product .uonix-product-action-wrap.has-items .uonix-qty-control,
		.woocommerce ul.products li.product .uonix-product-action-wrap:not([data-qty="0"]) .uonix-qty-control,
		.uonix-product-action-wrap.has-items .uonix-qty-control,
		.uonix-product-action-wrap:not([data-qty="0"]) .uonix-qty-control {
			display: flex !important;
		}

		.uonix-qty-control:hover,
		.woocommerce ul.products li.product:hover .uonix-qty-control {
			border-color: #0e3780 !important;
			box-shadow: 0 4px 12px rgba(14, 55, 128, 0.1) !important;
		}

		/* Botões Internos (-) (+) (Lixeira) */
		.uonix-qty-btn {
			display: flex !important;
			align-items: center !important;
			justify-content: center !important;
			width: 44px !important;
			height: 100% !important;
			background: transparent !important;
			border: none !important;
			color: #0e3780 !important;
			cursor: pointer !important;
			padding: 0 !important;
			margin: 0 !important;
			transition: all 0.2s ease !important;
			outline: none !important;
		}

		.uonix-qty-btn:hover,
		.uonix-qty-btn:focus-visible {
			background: #eef3fa !important;
			color: #f76a0c !important;
		}

		/* Botão da Lixeira quando qty = 1 */
		.uonix-qty-btn.is-trash:hover,
		.uonix-qty-btn.is-trash:focus-visible {
			background: #fdf0f0 !important;
			color: #d93838 !important;
		}

		/* Botões desabilitados por restrição comercial (ex: limite de 1 unidade por pedido) */
		.uonix-qty-btn:disabled,
		.uonix-qty-btn.is-disabled {
			opacity: 0.35 !important;
			cursor: not-allowed !important;
			pointer-events: none !important;
		}

		/* Texto Central "X no carrinho" */
		.uonix-qty-text {
			flex: 1 !important;
			text-align: center !important;
			font-size: 13px !important;
			font-weight: 800 !important;
			text-transform: uppercase !important;
			color: #0e3780 !important;
			letter-spacing: 0.3px !important;
			white-space: nowrap !important;
			pointer-events: none !important;
			line-height: 1 !important;
		}

		.uonix-qty-text .uonix-qty-num {
			font-size: 14px !important;
			font-weight: 900 !important;
			margin-right: 2px !important;
		}

		/* Estado de Carregamento */
		.uonix-product-action-wrap.is-loading .uonix-qty-control,
		.uonix-product-action-wrap.is-loading .uonix-add-to-cart-btn {
			opacity: 0.65 !important;
			pointer-events: none !important;
		}
	</style>
	<?php
}
