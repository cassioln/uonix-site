(function ($) {
	'use strict';

	const config = window.uonixVtsAdmin || {};

	function collectSheet($root) {
		const sections = [];
		$root.find('.uonix-vts-admin__section').each(function () {
			const $section = $(this);
			const items = [];
			$section.find('.uonix-vts-admin__item').each(function () {
				const $item = $(this);
				items.push({
					label: String($item.find('.uonix-vts-admin__item-label').val() || ''),
					value: String($item.find('.uonix-vts-admin__item-value').val() || '')
				});
			});
			sections.push({
				title: String($section.find('.uonix-vts-admin__section-title').val() || ''),
				layout: String($section.find('.uonix-vts-admin__section-layout').val() || 'compact'),
				items: items
			});
		});

		return {
			version: 1,
			title: String($root.find('.uonix-vts-admin__sheet-title').val() || ''),
			sections: sections
		};
	}

	function sync($root) {
		const $payload = $root.find('.uonix-vts-admin__payload');
		if ($root.hasClass('has-payload-error')) {
			return;
		}
		if ($root.hasClass('is-deleted')) {
			$payload.prop('disabled', false).val(JSON.stringify({ action: 'delete' }));
			return;
		}
		if (!$root.hasClass('is-active')) {
			$payload.prop('disabled', true).val('');
			return;
		}
		$payload.prop('disabled', false).val(JSON.stringify({
			action: 'upsert',
			sheet: collectSheet($root)
		}));
	}

	function resetSortable($list, options) {
		if ($list.hasClass('ui-sortable')) {
			$list.sortable('destroy');
		}
		$list.sortable(options);
	}

	function initSortable($root) {
		resetSortable($root.find('.uonix-vts-admin__sections'), {
			handle: '.uonix-vts-admin__section-handle',
			items: '> .uonix-vts-admin__section',
			update: function () {
				sync($root);
			}
		});
		$root.find('.uonix-vts-admin__items').each(function () {
			resetSortable($(this), {
				handle: '.uonix-vts-admin__item-handle',
				items: '> .uonix-vts-admin__item',
				connectWith: false,
				update: function () {
					sync($root);
				}
			});
		});
	}

	function appendItem($root, $items, item) {
		const template = $root.find('.uonix-vts-admin__item-template')[0];
		if (!template || !template.content) {
			return;
		}
		const fragment = template.content.cloneNode(true);
		const $item = $(fragment).find('.uonix-vts-admin__item');
		$item.find('.uonix-vts-admin__item-label').val(String(item.label || ''));
		$item.find('.uonix-vts-admin__item-value').val(String(item.value || ''));
		$items.append($item);
	}

	function appendSection($root, section) {
		const template = $root.find('.uonix-vts-admin__section-template')[0];
		if (!template || !template.content) {
			return;
		}
		const fragment = template.content.cloneNode(true);
		const $section = $(fragment).find('.uonix-vts-admin__section');
		$section.find('.uonix-vts-admin__section-title').val(String(section.title || ''));
		$section.find('.uonix-vts-admin__section-layout').val('detailed' === section.layout ? 'detailed' : 'compact');
		const $items = $section.find('.uonix-vts-admin__items');
		(Array.isArray(section.items) ? section.items : []).forEach(function (item) {
			appendItem($root, $items, item);
		});
		$root.find('.uonix-vts-admin__sections').append($section);
	}

	function renderSheetIntoEditor($root, sheet) {
		$root.find('.uonix-vts-admin__sheet-title').val(String(sheet.title || ''));
		$root.find('.uonix-vts-admin__sections').empty();
		(Array.isArray(sheet.sections) ? sheet.sections : []).forEach(function (section) {
			appendSection($root, section);
		});
		initSortable($root);
	}

	function showPayloadError($root) {
		const message = config.strings && config.strings.payloadError
			? config.strings.payloadError
			: 'Não foi possível carregar a ficha técnica salva.';
		$root.addClass('has-payload-error');
		$root.find('.uonix-vts-admin__payload').prop('disabled', true);
		window.alert(message);
	}

	function initAll() {
		$('.uonix-vts-admin').each(function () {
			const $root = $(this);
			if ($root.data('uonixVtsReady')) {
				return;
			}
			$root.data('uonixVtsReady', true);
			const raw = String($root.find('.uonix-vts-admin__payload').val() || '');
			let hydrated = false;
			if (raw) {
				try {
					const envelope = JSON.parse(raw);
					if ('upsert' === envelope.action && envelope.sheet) {
						renderSheetIntoEditor($root, envelope.sheet);
						hydrated = true;
					}
				} catch (error) {
					showPayloadError($root);
					return;
				}
			}
			if (!hydrated) {
				initSortable($root);
			}
			sync($root);
		});
	}

	function reconcileSaved() {
		$('.uonix-vts-admin').each(function () {
			const $root = $(this);
			const $payload = $root.find('.uonix-vts-admin__payload');
			if ($payload.prop('disabled')) {
				return;
			}

			let envelope;
			try {
				envelope = JSON.parse($payload.val() || '');
			} catch (error) {
				return;
			}

			if ('upsert' === envelope.action) {
				$root.attr('data-had-sheet', '1');
				return;
			}
			if ('delete' === envelope.action) {
				$root.attr('data-had-sheet', '0');
				$root.removeClass('is-active is-deleted');
				$payload.prop('disabled', true).val('');
			}
		});
	}

	$(document)
		.on('input change', '.uonix-vts-admin input, .uonix-vts-admin select', function () {
			sync($(this).closest('.uonix-vts-admin'));
		})
		.on('click', '.uonix-vts-admin__add', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$root.addClass('is-active').removeClass('is-deleted');
			$root.find('.uonix-vts-admin__sheet-title').val('Ficha técnica');
			sync($root);
		})
		.on('click', '.uonix-vts-admin__add-section', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			appendSection($root, {
				title: '',
				layout: 'compact',
				items: [{ label: '', value: '' }]
			});
			initSortable($root);
			sync($root);
		})
		.on('click', '.uonix-vts-admin__add-item', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			appendItem($root, $(this).siblings('.uonix-vts-admin__items'), { label: '', value: '' });
			initSortable($root);
			sync($root);
		})
		.on('click', '.uonix-vts-admin__remove-item', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$(this).closest('.uonix-vts-admin__item').remove();
			sync($root);
		})
		.on('click', '.uonix-vts-admin__remove-section', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$(this).closest('.uonix-vts-admin__section').remove();
			sync($root);
		})
		.on('click', '.uonix-vts-admin__remove-sheet', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			const message = config.strings && config.strings.removeConfirm
				? config.strings.removeConfirm
				: 'Remover a ficha técnica desta variação ao salvar?';
			if (!window.confirm(message)) {
				return;
			}
			if ('1' === String($root.attr('data-had-sheet'))) {
				$root.addClass('is-deleted').removeClass('is-active');
			} else {
				$root.removeClass('is-active is-deleted');
			}
			sync($root);
		});

	$('#woocommerce-product-data')
		.on(
			'woocommerce_variations_loaded woocommerce_variations_added',
			initAll
		)
		.on(
			'woocommerce_variations_saved',
			reconcileSaved
		);
	$(initAll);
})(jQuery);
