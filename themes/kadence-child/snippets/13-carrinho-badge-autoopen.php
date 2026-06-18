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
    ?>
    <script id="uonix-auto-open-smart">
    (function($) {
        // Função central para abrir o carrinho
        const openUonixCart = () => {
            const cartButton = document.querySelector('.wc-block-mini-cart__button');
            if (cartButton) {
                cartButton.dispatchEvent(new MouseEvent('click', {
                    view: window,
                    bubbles: true,
                    cancelable: true
                }));
            }
        };

        // CENÁRIO 1: Página recarregou com a mensagem de sucesso (Seu caso atual)
        $(document).ready(function() {
            // Verifica se a mensagem de "Adicionado" existe na página
            if ($('.woocommerce-message').length > 0) {
                // Aguarda o carregamento completo dos blocos antes de clicar
                setTimeout(openUonixCart, 800); 
            }
        });

        // CENÁRIO 2: Adição via AJAX (Caso mude a configuração no futuro)
        $(document.body).on('added_to_cart', function() {
            setTimeout(openUonixCart, 500);
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

add_action('wp_footer', function () {
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

	<script id="uonix-badge-double-sync-js">
	(function ($) {
		function syncUonixCart() {
			// -- Seletores atualizados para o Mega Menu
			var $menuLink = $('#mega-menu-item-4819 a.mega-menu-link');
			var $officialBadge = $('.wc-block-mini-cart__badge');

			// Pega a quantidade atual do WooCommerce
			var countText = $officialBadge.first().text().trim();
			var currentCount = parseInt(countText, 10) || 0;

			// Fallback para carregamento inicial
			if (countText === "" && typeof uonixInitialCount !== 'undefined') {
				currentCount = uonixInitialCount;
			}

			// --- Lógica para o Badge Custom do Menu ---
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

			// --- Lógica para o Badge Oficial (Woo Blocks) ---
			if ($officialBadge.length) {
				if (currentCount <= 0) {
					$officialBadge.addClass('uonix-force-hide').attr('hidden', 'true');
				} else {
					$officialBadge.removeClass('uonix-force-hide').removeAttr('hidden');
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

			// Reforço constante para sincronia em tempo real
			setInterval(syncUonixCart, 2000);
		});
	})(jQuery);
	</script>

	<?php
}, 100);


