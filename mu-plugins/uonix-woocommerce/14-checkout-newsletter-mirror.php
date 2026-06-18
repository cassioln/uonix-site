<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Checkout - checkbox espelho de newsletter.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 3663-3783 do export original.
// -----------------------------------------------------------------------------
/**
 * Move o campo de Newsletter para cima dos Termos e Condições
 */
/**
 * UÔNIX: Newsletter Mirror (Solução Definitiva para Checkout)
 */
add_action('wp_head', function () {
	?>
	<style>
		/* 1. ESTILO DOS CHECKBOXES (Customizados) */
		input[type="checkbox"].input-checkbox,
		.woocommerce-form__input-checkbox {
			-webkit-appearance: none;
			-moz-appearance: none;
			appearance: none;
			
			width: 22px !important;    /* Tamanho maior */
			height: 22px !important;   /* Tamanho maior */
			
			border: 2px solid #ccc !important;
			border-radius: 4px !important;
			background-color: #fff !important;
			cursor: pointer;
			display: inline-block;
			vertical-align: middle;
			position: relative;
			transition: all 0.2s ease;
			margin-right: 10px !important;
		}

		/* 2. ESTADO: QUANDO MARCADO (Checked) */
		input[type="checkbox"].input-checkbox:checked,
		.woocommerce-form__input-checkbox:checked {
			background-color: #f76a0c !important; /* Laranja Uônix */
			border-color: #f76a0c !important;
		}

		/* 3. O "V" (Checkmark) BRANCO */
		input[type="checkbox"].input-checkbox:checked::after,
		.woocommerce-form__input-checkbox:checked::after {
			content: '';
			position: absolute;
			left: 6px;  /* Ajuste para centralizar o V */
			top: 2px;
			width: 6px;
			height: 11px;
			border: solid white;
			border-width: 0 3px 3px 0;
			transform: rotate(45deg);
		}

		/* 4. AJUSTE DO LABEL (Alinhamento do texto) */
		.woocommerce-form__label-for-checkbox, 
		#uonix-newsletter-native-container label {
			display: flex !important;
			align-items: center !important;
			font-size: 15px !important;
			cursor: pointer;
			margin-bottom: 10px;
		}

		/* Efeito de hover suave */
		input[type="checkbox"].input-checkbox:hover {
			border-color: #f76a0c !important;
			box-shadow: 0 0 5px rgba(247, 106, 12, 0.2);
		}
	</style>
	<?php
}, 100);
// 1. Criar o campo visual acima dos termos via PHP (Não some com AJAX)
add_action( 'woocommerce_checkout_before_terms_and_conditions', function() {
    ?>
    <div id="uonix-newsletter-mirror-container" style="margin-bottom: 20px; padding: 10px; background: #f9f9f9; border-radius: 5px; border: 1px dashed #ccc;">
        <label class="checkbox" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; color: #1a2b3c;">
            <input type="checkbox" id="uonix-news-mirror" class="input-checkbox">
            <span>Quero receber notícias, informações e novidades da Uônix.</span>
        </label>
    </div>
    <?php
});

add_action('wp_footer', function() {
    if ( ! is_checkout() ) return;
    ?>
    <style>
        /* 2. Esconde o campo original do plugin para não aparecer duplicado */
        #billing_newsletters_field {
            display: none !important;
            visibility: hidden !important;
        }
    </style>

    <script type="text/javascript">
    (function($) {
        // Função que sincroniza o clique do espelho para o original
        function syncUonixNews() {
            var $original = $('#billing_newsletters');
            var $mirror = $('#uonix-news-mirror');

            // Se o original estiver marcado, marca o espelho (ao carregar)
            if ($original.is(':checked')) {
                $mirror.prop('checked', true);
            }

            // Quando clicar no espelho, reflete no original
            $mirror.on('change', function() {
                $original.prop('checked', $(this).is(':checked')).trigger('change');
            });
        }

        // Executa ao carregar e após cada atualização de AJAX do checkout
        $(document).ready(syncUonixNews);
        $(document.body).on('updated_checkout', syncUonixNews);

    })(jQuery);
    </script>
		   

    <?php
}, 999);


