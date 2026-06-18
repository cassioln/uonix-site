<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Catalogo - banner de produtos e autoplay.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 5675-5827 do export original.
// -----------------------------------------------------------------------------
/**
 * Autoplay para o Banner de Produtos (Kadence Tabs)
 */
/**
 * UÔNIX: Injetor de Setas e Autoplay para Tabs Kadence (Com Proteção de Foco, Scroll e Mega Menu)
 */
add_action('wp_footer', function() {
    // Carrega apenas na página de produtos
    if ( ! is_page(7150) ) return; 
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(iniciarSliderUonix, 500);

        function iniciarSliderUonix() {
            const banner = document.getElementById('tabs-produtos-banner');
            if (!banner) return;

            const contentWrap = banner.querySelector('.kt-tabs-content-wrap');
            const titleList = banner.querySelector('.kt-tabs-title-list');
            if (!contentWrap || !titleList) return;

            const tabLinks = titleList.querySelectorAll('li a');
            if (tabLinks.length <= 1) return;

            // ======================================================================
            // PROTEÇÃO 1: Impede que o clique do robô "vaze" e feche o Mega Menu
            // ======================================================================
            titleList.addEventListener('click', function(e) {
                // Se e.isTrusted for false, significa que o clique veio do nosso script, não de um dedo/rato humano.
                if (!e.isTrusted) {
                    e.stopPropagation(); 
                }
            });

            // 1. CRIA E INJETA AS SETAS SLIM NA IMAGEM
            const prevArrow = document.createElement('button');
            prevArrow.className = 'uonix-panel-arrow prev';
            prevArrow.innerHTML = '&#10094;';

            const nextArrow = document.createElement('button');
            nextArrow.className = 'uonix-panel-arrow next';
            nextArrow.innerHTML = '&#10095;';

            contentWrap.appendChild(prevArrow);
            contentWrap.appendChild(nextArrow);

            // 2. LÓGICA DE NAVEGAÇÃO
            let autoplayTimer;
            const tempoTransicao = 4000;
            let isBannerVisible = true; // Controla se o banner está na tela

            function goToTab(direction, isManualClick = false) {
                
                // Aborta se o usuário estiver digitando
                let elementoFoco = document.activeElement;
                let usuarioDigitando = elementoFoco && (elementoFoco.tagName === 'INPUT' || elementoFoco.tagName === 'TEXTAREA');
                if (!isManualClick && usuarioDigitando) return; 

                // ======================================================================
                // PROTEÇÃO 2: Aborta o autoplay se algum Menu ou Submenu estiver aberto
                // Verifica as classes do Max Mega Menu (.mega-toggle-on, .mega-hover) e do Kadence Mobile (.show-off-canvas)
                // ======================================================================
                let menuAberto = document.querySelector('.mega-toggle-on, .mega-hover, .show-off-canvas');
                if (!isManualClick && menuAberto) return;

                let activeLi = titleList.querySelector('li.kt-tab-title-active');
                if (!activeLi) return;
                
                let currentIndex = Array.from(titleList.children).indexOf(activeLi);
                let nextIndex = currentIndex + direction;
                
                if (nextIndex >= tabLinks.length) nextIndex = 0; 
                if (nextIndex < 0) nextIndex = tabLinks.length - 1;
                
                let targetLink = tabLinks[nextIndex];
                if (targetLink) {
                    
                    // TRAVA DE SCROLL VERTICAL: Salva a posição exata da página antes do clique
                    let currentScrollY = window.scrollY;
                    
                    // O clique simulado (isTrusted = false)
                    targetLink.click();
                    
                    // Se for automático, devolve a tela pra posição exata imediatamente para não dar "pulo"
                    if (!isManualClick) {
                        window.scrollTo(0, currentScrollY);
                    }

                    // SCROLL APENAS HORIZONTAL: Centraliza a aba nativamente sem puxar a tela
                    let li = targetLink.parentElement;
                    let scrollPos = li.offsetLeft - (titleList.clientWidth / 2) + (li.clientWidth / 2);
                    titleList.scrollTo({ left: scrollPos, behavior: 'smooth' });
                    
                    if (isManualClick && usuarioDigitando) {
                        setTimeout(() => elementoFoco.focus(), 10);
                    }
                }
            }

            function startAutoplay() {
                clearInterval(autoplayTimer);
                if (isBannerVisible) { // Só roda se estiver na tela!
                    autoplayTimer = setInterval(() => goToTab(1, false), tempoTransicao);
                }
            }

            function stopAutoplay() {
                clearInterval(autoplayTimer);
            }

            // 3. EVENTOS DE CLIQUE E PAUSA
            prevArrow.addEventListener('click', (e) => { 
                e.preventDefault(); 
                stopAutoplay(); 
                goToTab(-1, true); 
                startAutoplay(); 
            });

            nextArrow.addEventListener('click', (e) => { 
                e.preventDefault(); 
                stopAutoplay(); 
                goToTab(1, true); 
                startAutoplay(); 
            });

            banner.addEventListener('mouseenter', stopAutoplay);
            banner.addEventListener('mouseleave', startAutoplay);
            banner.addEventListener('touchstart', stopAutoplay, {passive: true});
            banner.addEventListener('touchend', () => setTimeout(startAutoplay, 2000), {passive: true});

            // 4. OBSERVADOR DE TELA (Evita que o Autoplay rode se o cliente rolou para baixo)
            if ('IntersectionObserver' in window) {
                let observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        isBannerVisible = entry.isIntersecting;
                        if (isBannerVisible) {
                            startAutoplay(); // Se apareceu na tela, roda
                        } else {
                            stopAutoplay(); // Se sumiu da tela, pausa
                        }
                    });
                }, { threshold: 0.1 });
                observer.observe(banner);
            } else {
                startAutoplay(); // Fallback para navegadores muito antigos
            }
        }
    });
    </script>
    <?php
}, 999);


