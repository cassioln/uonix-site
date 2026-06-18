<?php
/**
 * Produto: substitui alertas nativos de variação por modal Uônix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$sweetalert_path = UONIX_MU_PATH . 'uonix-woocommerce/assets/vendor/sweetalert2/sweetalert2.all.min.js';
		$sweetalert_url  = UONIX_MU_URL . 'uonix-woocommerce/assets/vendor/sweetalert2/sweetalert2.all.min.js';
		$sweetalert_ver  = file_exists( $sweetalert_path ) ? (string) filemtime( $sweetalert_path ) : '11';

		wp_enqueue_script( 'sweetalert2', $sweetalert_url, array(), $sweetalert_ver, true );

		wp_register_style( 'uonix-product-variation-alerts', false, array(), '1.0.0' );
		wp_enqueue_style( 'uonix-product-variation-alerts' );

		wp_add_inline_style(
			'uonix-product-variation-alerts',
			'
			.uonix-product-alert-popup {
				border-radius: 8px !important;
				padding: 28px 28px 24px !important;
				font-family: inherit !important;
			}

			.uonix-product-alert-title {
				color: #1a2b3c !important;
				font-size: 22px !important;
				font-weight: 900 !important;
				letter-spacing: 0 !important;
			}

			.uonix-product-alert-html {
				color: #475569 !important;
				font-size: 15px !important;
				line-height: 1.5 !important;
			}

			.uonix-product-alert-list {
				display: inline-flex;
				flex-wrap: wrap;
				justify-content: center;
				gap: 8px;
				margin: 14px auto 0;
				padding: 0;
				list-style: none;
			}

			.uonix-product-alert-list li {
				padding: 6px 10px;
				border-radius: 4px;
				background: #f1f5f9;
				color: #0e3780;
				font-size: 12px;
				font-weight: 800;
				text-transform: uppercase;
			}
			'
		);

		wp_register_script( 'uonix-product-variation-alerts', false, array( 'jquery', 'sweetalert2' ), '1.0.0', true );
		wp_enqueue_script( 'uonix-product-variation-alerts' );

		$js = <<<'JS'
(function ($, window) {
	'use strict';

	if (typeof window.Swal === 'undefined') {
		return;
	}

	const originalAlert = window.alert;
	let lastVariationForm = null;

	const UonixProductAlert = window.Swal.mixin({
		confirmButtonColor: '#0e3780',
		customClass: {
			popup: 'uonix-product-alert-popup',
			title: 'uonix-product-alert-title',
			htmlContainer: 'uonix-product-alert-html'
		},
		returnFocus: false
	});

	function normalizeText(value) {
		return String(value || '')
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/\s+/g, ' ')
			.trim();
	}

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function isVariationAlert(message) {
		const text = normalizeText(message);

		if (!text) {
			return false;
		}

		return text.indexOf('selecione uma das opcoes') !== -1 ||
			text.indexOf('escolha uma opcao') !== -1 ||
			text.indexOf('choose product options') !== -1 ||
			text.indexOf('choose an option') !== -1 ||
			text.indexOf('produto antes de adiciona-lo') !== -1 ||
			text.indexOf('product before adding') !== -1 ||
			text.indexOf('unavailable') !== -1 ||
			text.indexOf('indisponivel') !== -1;
	}

	function getMissingAttributes() {
		const $form = lastVariationForm && lastVariationForm.length
			? lastVariationForm
			: $('form.variations_form').first();
		const missing = [];

		$form.find('table.variations tr').each(function () {
			const $row = $(this);
			const label = $.trim($row.find('th.label label, label').first().text().replace(':', ''));
			const $select = $row.find('select').first();

			if ($select.length && !$select.val() && label) {
				missing.push(label);
			}
		});

		return missing;
	}

	function showVariationModal(message) {
		const missing = getMissingAttributes();
		const unavailable = normalizeText(message).indexOf('unavailable') !== -1 ||
			normalizeText(message).indexOf('indisponivel') !== -1;

		let html = unavailable
			? '<p>Esta combinação não está disponível. Escolha outra combinação para continuar.</p>'
			: '<p>Escolha os atributos obrigatórios antes de adicionar o produto ao orçamento.</p>';

		if (!unavailable && missing.length) {
			html += '<ul class="uonix-product-alert-list">' +
				missing.map(function (label) {
					return '<li>' + escapeHtml(label) + '</li>';
				}).join('') +
				'</ul>';
		}

		UonixProductAlert.fire({
			icon: 'warning',
			title: unavailable ? 'Combinação indisponível' : 'Selecione as opções do produto',
			html: html,
			confirmButtonText: 'Entendi'
		});
	}

	$(document).on('click submit', 'form.variations_form, form.variations_form .single_add_to_cart_button', function () {
		lastVariationForm = $(this).closest('form.variations_form');
	});

	window.alert = function (message) {
		if (isVariationAlert(message)) {
			showVariationModal(message);
			return;
		}

		return originalAlert.apply(window, arguments);
	};
})(jQuery, window);
JS;

		wp_add_inline_script( 'uonix-product-variation-alerts', $js );
	},
	30
);
