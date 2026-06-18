<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Carrinho - scroll do menu e abertura do mini-cart.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 3348-3503 do export original.
// -----------------------------------------------------------------------------
/**
 * Oculta .menu_buttons ao descer scroll (sobe + fade out) e mostra ao subir
 */
/**
 * UÔNIX: Controle de Visibilidade + Integração Carrinho
 * - Scroll: mostra/esconde elementos do menu
 * - Clique: botão custom abre o mini-cart do WooCommerce
 */

/**
 * UÔNIX: Controle de Visibilidade + Integração Carrinho
 * - Scroll: mostra/esconde elementos do menu
 * - Clique: botão custom abre o mini-cart do WooCommerce
 */

add_action('wp_footer', function () {
?>
<script>
(() => {
  const menuButtons = document.querySelectorAll('.menu_buttons');
  const seloMenu    = document.querySelectorAll('.selo-uonix-menu');
  const carrinhoBtn = document.querySelectorAll('.carrinho_button');

  if (!menuButtons.length && !seloMenu.length && !carrinhoBtn.length) return;

  const START_SCROLL = 39;
  const SHOW_DELAY   = 710;

  let lastY = window.scrollY;
  let showTimeout = null;

  // Funções do Menu (Invertidas em relação ao Carrinho)
  const hideMenu = (elements) => elements.forEach(el => el.classList.add('menu-buttons--hide'));
  const showMenu = (elements) => elements.forEach(el => el.classList.remove('menu-buttons--hide'));

  // Funções do Carrinho (Nasce oculto, ganha classe para aparecer)
  const hideCart = (elements) => elements.forEach(el => el.classList.remove('carrinho--show'));
  const showCart = (elements) => elements.forEach(el => el.classList.add('carrinho--show'));

  const onScroll = () => {
    const y = window.scrollY;

    if (y < START_SCROLL) {
      clearTimeout(showTimeout);
      showTimeout = null;

      showMenu(menuButtons);
      showMenu(seloMenu);
      hideCart(carrinhoBtn); // Garante que não apareça no topo

      lastY = y;
      return;
    }

    if (y > lastY) {
      clearTimeout(showTimeout);
      showTimeout = null;

      hideMenu(menuButtons);
      hideMenu(seloMenu);
      showCart(carrinhoBtn);
    } else if (y < lastY) {
      if (!showTimeout) {
        showTimeout = setTimeout(() => {
//           showMenu(menuButtons);
//           showMenu(seloMenu);
//           showCart(carrinhoBtn);
          showTimeout = null;
        }, SHOW_DELAY);
      }
    }

    lastY = y;
  };

  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>
<?php
}, 99);

/* ===============================
   CSS DO MENU (VISUAL)
=============================== */
add_action('wp_head', function () {
?>
<style>
/* 1. Menu e Selo nascem VISÍVEIS */
.menu_buttons,
.selo-uonix-menu {
  transform: translateY(0);
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  will-change: transform, opacity;
  transition: transform 0.6s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.6s ease, visibility 0.6s ease;
}

/* 2. Carrinho nasce OCULTO (Fim do "piscar" no carregamento) */
.carrinho_button {
  transform: translateY(-25px);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  will-change: transform, opacity;
  transition: transform 0.6s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.6s ease, visibility 0.6s ease;
}

/* 3. Ações do JS */
.menu-buttons--hide {
  transform: translateY(-25px) !important;
  opacity: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
}

.carrinho--show {
  transform: translateY(0) !important;
  opacity: 1 !important;
  visibility: visible !important;
  pointer-events: auto !important;
}
</style>
<?php
}, 99);

/* ===============================
   CLICK: ABRIR MINI CART
=============================== */
add_action('wp_footer', function () {
?>
<script>
(() => {
  document.addEventListener('click', (e) => {

    const trigger = e.target.closest('.open-cart-sidebar a, .open-cart-sidebar');

    if (!trigger) return;

    e.preventDefault();

    const cartButton = document.querySelector('.wc-block-mini-cart__button');

    if (cartButton) {
      cartButton.click();
    } else {
      console.warn('UÔNIX: botão do mini-cart não encontrado');
    }

  }, true);
})();
</script>
<?php
}, 100);


