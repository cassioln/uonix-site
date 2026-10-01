<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Home - slider da hero.
 *
 * Cada filho direto de `.hero-slider > .kt-inside-inner-col` (montado no editor do
 * Kadence) vira um slide. Com um único filho nada é ativado e a hero fica estática;
 * com dois ou mais, os slides deslizam da direita para a esquerda a cada 6s, em loop,
 * com setas (a seta "anterior" faz o movimento espelhado).
 *
 * Os slides ficam empilhados na mesma célula de grid e nenhum nó é movido no DOM: o
 * slide 1 continua sendo o primeiro elemento do HTML — a imagem dele é o LCP da home.
 * O deslizamento é só `transform` inline em quem entra e em quem sai. Como `transform`
 * faz `background-attachment: fixed` virar `scroll`, o fundo fixo das linhas Kadence
 * (parallax do desktop) é desligado quando há 2+ slides; com um só, o parallax fica.
 *
 * O empilhamento é feito pelo CSS antes do JS rodar: sem flash do slide 2 abaixo do
 * slide 1 e sem layout shift na inicialização. Se o JS falhar, fica o slide 1 estático.
 */

add_action( 'wp_head', function () {
    if ( ! is_front_page() ) {
        return;
    }
    ?>
    <style id="uonix-hero-slider">
    .hero-slider { position: relative; }
    .hero-slider > .kt-inside-inner-col { display: grid; grid-template-columns: 100%; }
    .hero-slider > .kt-inside-inner-col > * { grid-area: 1 / 1; min-width: 0; }

    /* Antes do JS: só o primeiro slide aparece. O Kadence pode emitir <style> como filho. */
    .hero-slider:not(.uonix-hs-on) > .kt-inside-inner-col > :not(style, script, link) ~ :not(style, script, link) {
        opacity: 0;
        visibility: hidden;
    }

    /* Com 2+ slides o fundo fixo sai já antes do JS, para a imagem não pular ao iniciar. */
    .hero-slider > .kt-inside-inner-col:has(> :not(style, script, link) ~ :not(style, script, link)) > * {
        background-attachment: scroll;
    }

    /* visibility tira os links do slide oculto da ordem de tabulação e do leitor de tela. */
    .hero-slider.uonix-hs-on { overflow: hidden; }
    .hero-slider.uonix-hs-on > .kt-inside-inner-col > * {
        visibility: hidden;
        transition: transform .7s cubic-bezier(.65, 0, .35, 1);
    }
    .hero-slider.uonix-hs-on > .kt-inside-inner-col > .is-active,
    .hero-slider.uonix-hs-on > .kt-inside-inner-col > .is-saindo {
        visibility: visible;
    }
    .hero-slider.uonix-hs-on > .kt-inside-inner-col > .is-active { z-index: 1; }

    /* :focus/:active repetidos porque o tema pinta todo button:hover/focus/active de azul. */
    .hero-slider .uonix-hs-arrow,
    .hero-slider .uonix-hs-arrow:focus,
    .hero-slider .uonix-hs-arrow:active {
        position: absolute;
        top: 50%;
        z-index: 3;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: var(--global-palette9, #fff);
        color: var(--global-palette2, #003399);
        box-shadow: 0 6px 18px rgba(0, 0, 0, .28);
        cursor: pointer;
        transform: translateY(-50%);
        transition: background-color .2s ease, color .2s ease, transform .2s ease;
    }
    .hero-slider .uonix-hs-arrow:hover {
        background: var(--global-palette1, #f76a0b);
        color: var(--global-palette9, #fff);
        transform: translateY(-50%) scale(1.06);
    }
    .hero-slider .uonix-hs-arrow:focus-visible {
        outline: 3px solid var(--global-palette1, #f76a0b);
        outline-offset: 3px;
    }
    .uonix-hs-arrow svg { width: 22px; height: 22px; }
    .hero-slider .uonix-hs-arrow.prev { left: 24px; }
    .hero-slider .uonix-hs-arrow.next { right: 24px; }

    @media (max-width: 767px) {
        .hero-slider .uonix-hs-arrow,
        .hero-slider .uonix-hs-arrow:focus,
        .hero-slider .uonix-hs-arrow:active { width: 38px; height: 38px; }
        .uonix-hs-arrow svg { width: 18px; height: 18px; }
        .hero-slider .uonix-hs-arrow.prev { left: 8px; }
        .hero-slider .uonix-hs-arrow.next { right: 8px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .hero-slider.uonix-hs-on > .kt-inside-inner-col > * { transition: none; }
    }
    </style>
    <?php
}, 5 );

add_action( 'wp_footer', function () {
    if ( ! is_front_page() ) {
        return;
    }
    ?>
    <script id="uonix-hero-slider-js">
    (function () {
        var INTERVALO = 6000;
        var reduzMovimento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var chevron = function (d) {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="' + d + '"></polyline></svg>';
        };

        function iniciar(root) {
            var trilho = root.querySelector(':scope > .kt-inside-inner-col');
            if (!trilho) return;

            var slides = Array.prototype.filter.call(trilho.children, function (filho) {
                return !/^(STYLE|SCRIPT|LINK)$/.test(filho.tagName);
            });
            if (slides.length < 2) return; // Um único bloco não é slider: fica estático.

            var atual = 0;
            var timer = null;
            var pausadoHover = false;
            var pausadoFoco = false;
            var visivel = true;

            root.setAttribute('role', 'region');
            root.setAttribute('aria-roledescription', 'carrossel');
            root.setAttribute('aria-label', 'Destaques');
            slides.forEach(function (slide, i) {
                slide.setAttribute('role', 'group');
                slide.setAttribute('aria-roledescription', 'slide');
                slide.setAttribute('aria-label', (i + 1) + ' de ' + slides.length);
            });
            slides[0].classList.add('is-active');
            root.classList.add('uonix-hs-on');

            var DURACAO = reduzMovimento ? 0 : 700;
            var travado = false;

            function posicionar(slide, x, animar) {
                slide.style.transition = animar ? '' : 'none';
                slide.style.transform = x ? 'translateX(' + x + '%)' : '';
            }

            // passo > 0: entra pela direita e sai pela esquerda; passo < 0: o espelho.
            function ir(passo) {
                if (travado) return;
                var sai = slides[atual];
                atual = (atual + passo + slides.length) % slides.length;
                var entra = slides[atual];
                var lado = passo > 0 ? 100 : -100;

                if (!DURACAO) {
                    sai.classList.remove('is-active');
                    entra.classList.add('is-active');
                    return;
                }

                travado = true;
                posicionar(entra, lado, false);
                entra.classList.add('is-active');
                void entra.offsetWidth; // aplica a posição inicial antes de animar
                posicionar(entra, 0, true);

                sai.classList.remove('is-active');
                sai.classList.add('is-saindo');
                posicionar(sai, -lado, true);

                setTimeout(function () {
                    sai.classList.remove('is-saindo');
                    posicionar(sai, 0, false);
                    travado = false;
                }, DURACAO);
            }

            // Mesmas proteções do banner de /produtos/: não troca de slide sob um menu aberto.
            function menuAberto() {
                return !!document.querySelector('.mega-toggle-on, .mega-hover, .show-off-canvas');
            }

            function agendar() {
                clearTimeout(timer);
                if (reduzMovimento || pausadoHover || pausadoFoco || !visivel || document.hidden) return;
                timer = setTimeout(function () {
                    if (!menuAberto()) ir(1);
                    agendar();
                }, INTERVALO);
            }

            function criarSeta(classe, rotulo, pontos, passo) {
                var botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'uonix-hs-arrow ' + classe;
                botao.setAttribute('aria-label', rotulo);
                botao.innerHTML = chevron(pontos);
                botao.addEventListener('click', function () {
                    ir(passo);
                    agendar();
                });
                root.appendChild(botao);
            }
            criarSeta('prev', 'Slide anterior', '15 18 9 12 15 6', -1);
            criarSeta('next', 'Próximo slide', '9 18 15 12 9 6', 1);

            // Só mouse: no toque, pointerenter sem pointerleave deixaria o autoplay parado.
            root.addEventListener('pointerenter', function (e) {
                if (e.pointerType !== 'mouse') return;
                pausadoHover = true;
                clearTimeout(timer);
            });
            root.addEventListener('pointerleave', function (e) {
                if (e.pointerType !== 'mouse') return;
                pausadoHover = false;
                agendar();
            });
            // Foco de teclado dentro da hero pausa: o slide não pode sumir sob o link focado.
            // Clique de mouse na seta também foca o botão, mas sem :focus-visible.
            root.addEventListener('focusin', function (e) {
                if (!e.target.matches(':focus-visible')) return;
                pausadoFoco = true;
                clearTimeout(timer);
            });
            root.addEventListener('focusout', function (e) {
                if (root.contains(e.relatedTarget)) return;
                pausadoFoco = false;
                agendar();
            });
            document.addEventListener('visibilitychange', agendar);

            // Só com foco numa seta: trocar de slide com foco num link esconderia o link.
            root.addEventListener('keydown', function (e) {
                if (!e.target.closest('.uonix-hs-arrow')) return;
                if (e.key === 'ArrowLeft') { ir(-1); agendar(); }
                if (e.key === 'ArrowRight') { ir(1); agendar(); }
            });

            var toqueX = null;
            var toqueY = null;
            root.addEventListener('touchstart', function (e) {
                toqueX = e.touches[0].clientX;
                toqueY = e.touches[0].clientY;
            }, { passive: true });
            root.addEventListener('touchend', function (e) {
                if (toqueX === null) return;
                var dx = e.changedTouches[0].clientX - toqueX;
                var dy = e.changedTouches[0].clientY - toqueY;
                toqueX = null;
                if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
                    ir(dx < 0 ? 1 : -1);
                    agendar();
                }
            }, { passive: true });

            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (entradas) {
                    visivel = entradas[0].isIntersecting;
                    agendar();
                }, { threshold: 0.1 }).observe(root);
            } else {
                agendar();
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('.hero-slider'), iniciar);
    })();
    </script>
    <?php
}, 20 );
