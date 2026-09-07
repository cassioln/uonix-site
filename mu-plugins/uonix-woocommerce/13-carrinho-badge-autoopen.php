<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Carrinho - auto abertura e badge de quantidade.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 3504-3662 do export original.
// -----------------------------------------------------------------------------
/**
 * Abrir Sidebar do Carrinho Automaticamente após Adicionar Produto
 */
/**
 * UÔNIX: Abrir Mini Cart automaticamente (Detecta Reload e AJAX)
 * Especial para funcionamento com temas Kadence e WooCommerce Blocks.
 */
add_action('wp_footer', function() {
    // Restrição 1: Só deve abrir automaticamente se estiver na página individual do produto
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }
    ?>
    <script id="uonix-auto-open-smart">
    (function($) {
        'use strict';

        var STORAGE_FLAG = 'uonix_auto_open_main_cart';
        var STORAGE_TIME = 'uonix_auto_open_time';
        var mainProductAddedAjax = false;
        var ajaxExpirationTimer = null;

        // Função central para abrir o carrinho
        function openUonixCart() {
            var cartButton = document.querySelector('.wc-block-mini-cart__button');
            if (cartButton) {
                cartButton.dispatchEvent(new MouseEvent('click', {
                    view: window,
                    bubbles: true,
                    cancelable: true
                }));
            }
        }

        // Identifica se um elemento é o botão principal de compra do produto
        function isMainAddToCartTarget(target) {
            if (!target) return false;
            var $target = $(target);

            // Se for dentro de seções de loop secundário (relacionados, up-sells, cross-sells, catálogo), NÃO é o botão principal
            if ($target.closest('.related, .up-sells, .upsells, .cross-sells, ul.products, .uonix-product-action-wrap').length > 0) {
                return false;
            }

            // Se for o botão .single_add_to_cart_button ou dentro do form.cart principal
            if ($target.hasClass('single_add_to_cart_button') || $target.closest('form.cart:not(.uonix-related-cart)').length > 0) {
                return true;
            }

            return false;
        }

        // Monitora o clique no botão principal de compra
        $(document).on('click', '.single_add_to_cart_button, form.cart:not(.uonix-related-cart) button[type="submit"]', function(e) {
            // Ignora se estiver desabilitado (ex: variações ainda não selecionadas)
            if ($(this).hasClass('disabled') || $(this).is(':disabled')) {
                return;
            }

            if (isMainAddToCartTarget(this)) {
                // Registra para o cenário de reload
                try {
                    sessionStorage.setItem(STORAGE_FLAG, '1');
                    sessionStorage.setItem(STORAGE_TIME, Date.now().toString());
                } catch (err) {}

                // Registra para o cenário AJAX
                mainProductAddedAjax = true;
                clearTimeout(ajaxExpirationTimer);
                ajaxExpirationTimer = setTimeout(function() {
                    mainProductAddedAjax = false;
                }, 8000);
            }
        });

        // Monitora a submissão do formulário principal de produto
        $(document).on('submit', 'form.cart:not(.uonix-related-cart)', function(e) {
            if (isMainAddToCartTarget(this)) {
                try {
                    sessionStorage.setItem(STORAGE_FLAG, '1');
                    sessionStorage.setItem(STORAGE_TIME, Date.now().toString());
                } catch (err) {}

                mainProductAddedAjax = true;
                clearTimeout(ajaxExpirationTimer);
                ajaxExpirationTimer = setTimeout(function() {
                    mainProductAddedAjax = false;
                }, 8000);
            }
        });

        // Caso o usuário clique em botões de produtos secundários (ex: relacionados, upsells, loops)
        $(document).on('click', '.related, .up-sells, .upsells, .cross-sells, ul.products, .uonix-product-action-wrap', function() {
            mainProductAddedAjax = false;
            try {
                sessionStorage.removeItem(STORAGE_FLAG);
                sessionStorage.removeItem(STORAGE_TIME);
            } catch (err) {}
        });

        // CENÁRIO 1: Página recarregou com a mensagem de sucesso (Submit padrão do WooCommerce)
        $(document).ready(function() {
            var shouldOpen = false;
            try {
                var storedFlag = sessionStorage.getItem(STORAGE_FLAG);
                var storedTime = sessionStorage.getItem(STORAGE_TIME);

                // Limpa imediatamente para não reabrir em F5 futuro
                sessionStorage.removeItem(STORAGE_FLAG);
                sessionStorage.removeItem(STORAGE_TIME);

                if (storedFlag === '1' && storedTime) {
                    var elapsed = Date.now() - parseInt(storedTime, 10);
                    // Apenas válido se a submissão tiver ocorrido nos últimos 20 segundos
                    if (elapsed >= 0 && elapsed < 20000) {
                        shouldOpen = true;
                    }
                }
            } catch (err) {}

            // Verifica se a mensagem de "Adicionado" existe na página e se partiu do botão principal
            if (shouldOpen && $('.woocommerce-message').length > 0) {
                setTimeout(openUonixCart, 800); 
            }
        });

        // CENÁRIO 2: Adição via AJAX na página de produto
        $(document.body).on('added_to_cart', function(e, fragments, cart_hash, $button) {
            var isMain = false;

            if ($button && $button.length) {
                isMain = isMainAddToCartTarget($button[0]);
            } else if (mainProductAddedAjax) {
                isMain = true;
            }

            if (isMain) {
                mainProductAddedAjax = false;
                clearTimeout(ajaxExpirationTimer);
                setTimeout(openUonixCart, 500);
            }
        });

    })(jQuery);
    </script>
    <?php
}, 100);

/**
 * Badge de Contagem no Carrinho (Menu Extra-3)
 */
/**
 * UÔNIX: Badge de Carrinho (V17 - Sincronia de Badges e Ajuste Fino)
 * - Integra posicionamento V5.4 (23px, top: -10px, left: 48px)
 * - Faz o badge sumir quando o carrinho está vazio.
 */

add_action('wp_head', function () {
	if ( ! function_exists('WC') ) return;
	?>

	<style id="uonix-badge-double-sync-css">
		/* 1. ESTILO DO SEU BADGE CUSTOM (Laranja - Ajustado V5.4) */
		.uonix-cart-badge {
			position: absolute !important;

			/* Posição validada para o ícone de 50px */
			top: -10px !important;
			left: 48px !important;

			background-color: #f76a0c !important;
			color: #ffffff !important;
			font-family: inherit !important;
			font-size: 15px !important;
			font-weight: 800 !important;

			/* Tamanho validado */
			width: 23px !important;
			height: 23px !important;

			border-radius: 50% !important;
			display: none; /* Inicialmente escondido, o JS ativa */
			align-items: center !important;
			justify-content: center !important;
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3) !important;
			z-index: 99 !important;
			line-height: 1 !important;
			pointer-events: none !important;
		}

		/* 2. REGRA PARA ESCONDER O BADGE OFICIAL DO WOO QUANDO VAZIO */
		.wc-block-mini-cart__badge[hidden],
		.wc-block-mini-cart__badge:empty,
		.uonix-force-hide {
			display: none !important;
		}
	</style>
	<?php
}, 10);

add_action('wp_footer', function () {
	if ( ! function_exists('WC') ) return;
	?>

	<script id="uonix-badge-double-sync-js">
	(function ($) {
		function syncUonixCart() {
			// -- Seletores atualizados para o Mega Menu Desktop
			var $menuLink = $('#mega-menu-item-4819 a.mega-menu-link');
			var $officialBadge = $('.wc-block-mini-cart__badge');

			// Pega a quantidade atual do WooCommerce
			var countText = $officialBadge.first().text().trim();
			var currentCount = parseInt(countText, 10);
			if (isNaN(currentCount)) {
				currentCount = (typeof uonixInitialCount !== 'undefined') ? uonixInitialCount : 0;
			}

			// --- Lógica para o Badge Custom do Menu Desktop ---
			if ($menuLink.length) {
				// Se o badge não existir no HTML, cria ele
				if ($menuLink.find('.uonix-cart-badge').length === 0) {
					$menuLink.append('<span class="uonix-cart-badge">0</span>');
				}

				var $badgeCustom = $menuLink.find('.uonix-cart-badge');
				$badgeCustom.text(currentCount);

				// --- REGRA DE OURO: Sumir se for 0 ---
				if (currentCount > 0) {
					$badgeCustom.css('display', 'flex');
				} else {
					$badgeCustom.css('display', 'none');
				}
			}

			// --- Lógica para o Badge Custom do Menu Mobile ---
			var $mobileCart = $('.uonix-menu-cart');
			if ($mobileCart.length) {
				$mobileCart.each(function () {
					var $cart = $(this);
					var $badgeMobile = $cart.find('.uonix-menu-cart-badge');
					if ($badgeMobile.length === 0) {
						$cart.append('<span class="uonix-menu-cart-badge">0</span>');
						$badgeMobile = $cart.find('.uonix-menu-cart-badge');
					}

					$badgeMobile.text(currentCount);

					if (currentCount > 0) {
						$badgeMobile.addClass('is-active').css('display', 'flex');
					} else {
						$badgeMobile.removeClass('is-active').css('display', 'none');
					}
				});
			} else {
				var $orphanMobile = $('.uonix-menu-cart-badge');
				if ($orphanMobile.length) {
					$orphanMobile.text(currentCount);
					if (currentCount > 0) {
						$orphanMobile.addClass('is-active').css('display', 'flex');
					} else {
						$orphanMobile.removeClass('is-active').css('display', 'none');
					}
				}
			}

			// --- Lógica para o Badge Oficial (Woo Blocks) ---
			if ($officialBadge.length) {
				if (currentCount <= 0) {
					if (!$officialBadge.hasClass('uonix-force-hide')) {
						$officialBadge.addClass('uonix-force-hide');
					}
					if ($officialBadge.attr('hidden') !== 'true') {
						$officialBadge.attr('hidden', 'true');
					}
				} else {
					if ($officialBadge.hasClass('uonix-force-hide')) {
						$officialBadge.removeClass('uonix-force-hide');
					}
					if ($officialBadge.attr('hidden')) {
						$officialBadge.removeAttr('hidden');
					}
				}
			}
		}

		// Pega contagem via PHP no primeiro carregamento
		window.uonixInitialCount = <?php echo (is_object(WC()->cart)) ? WC()->cart->get_cart_contents_count() : 0; ?>;

		// Monitora gatilhos de mudança no carrinho (Ajax do Woo)
		$(document.body).on(
			'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded',
			function () {
				setTimeout(syncUonixCart, 300);
			}
		);

		$(document).ready(function () {
			syncUonixCart();

			// Observa apenas nós e conteúdo textual do badge oficial para sincronia imediata (sem attributes para evitar ciclo recursivo)
			var targetOfficial = document.querySelector('.wc-block-mini-cart__badge');
			if (targetOfficial && window.MutationObserver) {
				var observer = new MutationObserver(function (mutations) {
					var hasContentMutation = false;
					for (var i = 0; i < mutations.length; i++) {
						var mType = mutations[i].type;
						if (mType === 'childList' || mType === 'characterData') {
							hasContentMutation = true;
							break;
						}
					}
					if (hasContentMutation) {
						syncUonixCart();
					}
				});
				observer.observe(targetOfficial, {
					childList: true,
					characterData: true,
					subtree: true
				});
			}

			// Reforço constante para sincronia em tempo real
			setInterval(syncUonixCart, 2000);
		});
	})(jQuery);
	</script>

	<?php
}, 100);


