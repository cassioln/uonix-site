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

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 16483-16762 do export original.
// -----------------------------------------------------------------------------
/**
 * Avaliacoes Google Mapas
 */
add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    ?>

    <div id="trustindex-modal" class="trustindex-modal" aria-hidden="true">
        <div class="trustindex-modal__overlay" data-trustindex-close></div>

        <div class="trustindex-modal__box" role="dialog" aria-modal="true" aria-label="Avaliações Google" tabindex="-1">
            <button class="trustindex-modal__close" type="button" aria-label="Fechar modal" data-trustindex-close>
                &times;
            </button>

            <div class="trustindex-modal__content">
                <?php echo do_shortcode('[trustindex no-registration=google]'); ?>
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
			max-width: 900px;
			max-height: 90vh;
			overflow-y: auto;
			background: #ffffff;
			border-radius: 16px;
			padding: 48px 24px 24px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
		}

		.trustindex-modal__content {
			width: 100%;
		}

		/* Botão X */
		.trustindex-modal__close {
			position: absolute;
			top: 0;
			right: 0;

			width: 44px;
			height: 44px;
			padding: 0;

			border: 0 !important;
			background: transparent !important;
			background-color: transparent !important;
			box-shadow: none !important;
			outline: none !important;

			color: inherit !important;
			font-size: 28px;
			font-weight: 300;
			line-height: 1;

			cursor: pointer;

			display: flex;
			align-items: center;
			justify-content: center;

			transform: scale(1);
			transition: transform 0.2s ease, opacity 0.2s ease;
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

			transform: scale(1.15);
			opacity: 0.75;
		}
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

/* Botão X */
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

            if (!modal || !modalBox) {
                return;
            }

            function openTrustindexModal(event) {
                if (event) {
                    event.preventDefault();
                }

                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('trustindex-modal-open');

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
});


