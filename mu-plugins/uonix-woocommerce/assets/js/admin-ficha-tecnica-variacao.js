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

	function isValidText(value, maxLength, allowEmpty) {
		if (typeof value !== 'string' || /[\u0000-\u001F\u007F]/.test(value)) {
			return false;
		}
		if (Array.from(value).length > maxLength) {
			return false;
		}
		return allowEmpty || value.trim().length > 0;
	}

	function isPlainObject(value) {
		return null !== value && 'object' === typeof value && !Array.isArray(value);
	}

	function isValidCopySheet(sheet) {
		if (!isPlainObject(sheet) || sheet.version !== 1 || !isValidText(sheet.title, 160, false) || !Array.isArray(sheet.sections)) {
			return false;
		}
		if (sheet.sections.length < 1 || sheet.sections.length > 50) {
			return false;
		}
		return sheet.sections.every(function (section) {
			if (!isPlainObject(section) || !isValidText(section.title, 120, true)) {
				return false;
			}
			if (section.layout !== 'compact' && section.layout !== 'detailed') {
				return false;
			}
			if (!Array.isArray(section.items) || section.items.length < 1 || section.items.length > 100) {
				return false;
			}
			return section.items.every(function (item) {
				return isPlainObject(item) &&
					isValidText(item.label, 120, false) &&
					isValidText(item.value, 500, false);
			});
		});
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

	function syncAndMarkChanged($root) {
		sync($root);
		$root.find('.uonix-vts-admin__payload').trigger('change');
	}

	function refreshMoveButtons($root) {
		const $sections = $root.find('.uonix-vts-admin__sections').children('.uonix-vts-admin__section');
		$sections.each(function (sectionIndex) {
			const $section = $(this);
			const $head = $section.children('.uonix-vts-admin__section-head');
			$head.find('.uonix-vts-admin__move-section-up').prop('disabled', sectionIndex === 0);
			$head.find('.uonix-vts-admin__move-section-down').prop('disabled', sectionIndex === $sections.length - 1);
			const $items = $section.children('.uonix-vts-admin__items').children('.uonix-vts-admin__item');
			$items.each(function (itemIndex) {
				const $item = $(this);
				$item.find('.uonix-vts-admin__move-item-up').prop('disabled', itemIndex === 0);
				$item.find('.uonix-vts-admin__move-item-down').prop('disabled', itemIndex === $items.length - 1);
			});
		});
	}

	function moveRelative($element, direction, selector) {
		const $sibling = direction === 'up' ? $element.prev(selector) : $element.next(selector);
		if (!$sibling.length) {
			return false;
		}
		if (direction === 'up') {
			$element.insertBefore($sibling);
		} else {
			$element.insertAfter($sibling);
		}
		return true;
	}

	function moveFromButton(button, elementSelector, direction) {
		const $button = $(button);
		const $root = $button.closest('.uonix-vts-admin');
		if (!moveRelative($button.closest(elementSelector), direction, elementSelector)) {
			return;
		}
		refreshMoveButtons($root);
		syncAndMarkChanged($root);
		const $focusTarget = $button.prop('disabled')
			? (direction === 'up'
				? $button.nextAll('.uonix-vts-admin__icon-button:not(:disabled)').first()
				: $button.prevAll('.uonix-vts-admin__icon-button:not(:disabled)').first())
			: $button;
		$focusTarget.trigger('focus');
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
			cancel: 'input, textarea, select, option',
			items: '> .uonix-vts-admin__section',
			update: function () {
				refreshMoveButtons($root);
				syncAndMarkChanged($root);
			}
		});
		$root.find('.uonix-vts-admin__items').each(function () {
			resetSortable($(this), {
				handle: '.uonix-vts-admin__item-handle',
				cancel: 'input, textarea, select, option',
				items: '> .uonix-vts-admin__item',
				connectWith: false,
				update: function () {
					refreshMoveButtons($root);
					syncAndMarkChanged($root);
				}
			});
		});
		refreshMoveButtons($root);
	}

	let datalistSequence = 0;

	function ensureItemDatalists($item) {
		const parentAttrs = Array.isArray(config.parentAttributes) ? config.parentAttributes : [];
		if (parentAttrs.length === 0) {
			return;
		}

		datalistSequence++;
		const labelListId = 'uonix-vts-labels-' + datalistSequence;
		const valueListId = 'uonix-vts-values-' + datalistSequence;

		const $labelInput = $item.find('.uonix-vts-admin__item-label');
		const $valueInput = $item.find('.uonix-vts-admin__item-value');

		const $labelList = $('<datalist>', { id: labelListId });
		parentAttrs.forEach(function (attr) {
			if (attr && attr.label) {
				const opt = document.createElement('option');
				opt.value = String(attr.label);
				$labelList[0].appendChild(opt);
			}
		});

		const $valueList = $('<datalist>', { id: valueListId });

		$labelInput.attr('list', labelListId);
		$valueInput.attr('list', valueListId);

		$item.append($labelList).append($valueList);

		function updateValueSuggestions() {
			const currentLabel = String($labelInput.val() || '').trim().toLowerCase();
			$valueList.empty();
			if (!currentLabel) {
				return;
			}
			const matched = parentAttrs.find(function (attr) {
				return String(attr && attr.label || '').trim().toLowerCase() === currentLabel;
			});
			if (matched && Array.isArray(matched.options)) {
				matched.options.forEach(function (val) {
					const opt = document.createElement('option');
					opt.value = String(val);
					$valueList[0].appendChild(opt);
				});
			}
		}

		function updateTagState() {
			const currentVal = String($labelInput.val() || '');
			const currentTrim = currentVal.trim().toLowerCase();
			const matched = parentAttrs.find(function (attr) {
				return String(attr && attr.label || '').trim().toLowerCase() === currentTrim;
			});
			if (matched && currentTrim !== '') {
				$labelInput.addClass('uonix-vts-admin__item-label--tagged');
				$labelInput.attr('title', 'Atributo vinculado do produto: ' + matched.label + ' (apagar limpa a tag)');
			} else {
				$labelInput.removeClass('uonix-vts-admin__item-label--tagged');
				$labelInput.removeAttr('title');
			}
		}

		function clearTagAndValue() {
			$labelInput.val('');
			$labelInput.removeClass('uonix-vts-admin__item-label--tagged');
			$labelInput.removeAttr('title');
			updateValueSuggestions();
			$labelInput.trigger('input').trigger('change');
		}

		$labelInput.on('keydown', function (e) {
			if ($labelInput.hasClass('uonix-vts-admin__item-label--tagged')) {
				if (e.key === 'Backspace' || e.key === 'Delete' || e.keyCode === 8 || e.keyCode === 46) {
					e.preventDefault();
					clearTagAndValue();
				}
			}
		});

		$labelInput.on('beforeinput', function (e) {
			if ($labelInput.hasClass('uonix-vts-admin__item-label--tagged')) {
				const inputType = e.originalEvent && e.originalEvent.inputType;
				if (inputType === 'deleteContentBackward' || inputType === 'deleteContentForward' || inputType === 'deleteByCut') {
					e.preventDefault();
					clearTagAndValue();
				}
			}
		});

		$labelInput.on('input change', function () {
			updateTagState();
			updateValueSuggestions();
		});

		updateTagState();
		updateValueSuggestions();
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
		ensureItemDatalists($item);
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

	function showCopyError() {
		const message = config.strings && config.strings.copyError
			? config.strings.copyError
			: 'Não foi possível copiar a ficha selecionada.';
		window.alert(message);
	}

	function populateCopyOptions($root) {
		const $select = $root.find('.uonix-vts-admin__copy-source');
		const destinationId = Number($root.attr('data-variation-id')) || 0;
		const placeholder = config.strings && config.strings.copyPlaceholder
			? config.strings.copyPlaceholder
			: 'Selecione uma variação';
		$select.empty().append($('<option>', {
			value: '',
			text: placeholder
		}));
		(Array.isArray(config.copyOptions) ? config.copyOptions : []).forEach(function (option) {
			const sourceId = Number(option && option.id) || 0;
			if (!sourceId || sourceId === destinationId) {
				return;
			}
			$select.append($('<option>', {
				value: String(sourceId),
				text: String(option.label || ('#' + sourceId))
			}));
		});
		$root.find('.uonix-vts-admin__copy').prop('disabled', $select.find('option').length < 2);
	}

	function initAll() {
		$('.uonix-vts-admin').each(function () {
			const $root = $(this);
			if ($root.data('uonixVtsReady')) {
				return;
			}
			$root.data('uonixVtsReady', true);
			populateCopyOptions($root);
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
		.on('input change', '.uonix-vts-admin input:not(.uonix-vts-admin__payload), .uonix-vts-admin select', function () {
			sync($(this).closest('.uonix-vts-admin'));
		})
		.on('click', '.uonix-vts-admin__add', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$root.addClass('is-active').removeClass('is-deleted');
			$root.find('.uonix-vts-admin__sheet-title').val('Ficha técnica');
			syncAndMarkChanged($root);
		})
		.on('click', '.uonix-vts-admin__add-section', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			appendSection($root, {
				title: '',
				layout: 'compact',
				items: [{ label: '', value: '' }]
			});
			initSortable($root);
			syncAndMarkChanged($root);
		})
		.on('click', '.uonix-vts-admin__add-item', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			appendItem(
				$root,
				$(this).closest('.uonix-vts-admin__section').children('.uonix-vts-admin__items'),
				{ label: '', value: '' }
			);
			initSortable($root);
			syncAndMarkChanged($root);
		})
		.on('click', '.uonix-vts-admin__move-section-up', function () {
			moveFromButton(this, '.uonix-vts-admin__section', 'up');
		})
		.on('click', '.uonix-vts-admin__move-section-down', function () {
			moveFromButton(this, '.uonix-vts-admin__section', 'down');
		})
		.on('click', '.uonix-vts-admin__move-item-up', function () {
			moveFromButton(this, '.uonix-vts-admin__item', 'up');
		})
		.on('click', '.uonix-vts-admin__move-item-down', function () {
			moveFromButton(this, '.uonix-vts-admin__item', 'down');
		})
		.on('click', '.uonix-vts-admin__remove-item', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$(this).closest('.uonix-vts-admin__item').remove();
			refreshMoveButtons($root);
			syncAndMarkChanged($root);
		})
		.on('click', '.uonix-vts-admin__remove-section', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			$(this).closest('.uonix-vts-admin__section').remove();
			refreshMoveButtons($root);
			syncAndMarkChanged($root);
		})
		.on('click', '.uonix-vts-admin__copy', function () {
			const $root = $(this).closest('.uonix-vts-admin');
			const sourceId = Number($root.find('.uonix-vts-admin__copy-source').val()) || 0;
			const destinationId = Number($root.attr('data-variation-id')) || 0;
			if (!sourceId || sourceId === destinationId) {
				showCopyError();
				return;
			}
			const hasData = $root.hasClass('is-active') && collectSheet($root).sections.length > 0;
			const message = config.strings && config.strings.copyConfirm
				? config.strings.copyConfirm
				: 'Substituir a ficha atual pela ficha selecionada?';
			if (hasData && !window.confirm(message)) {
				return;
			}
			$.post(config.ajaxUrl, {
				action: config.copyAction,
				nonce: config.nonce,
				source_id: sourceId,
				parent_id: config.parentId
			}).done(function (response) {
				if (!response || !response.success || !response.data || !isValidCopySheet(response.data.sheet)) {
					showCopyError();
					return;
				}
				renderSheetIntoEditor($root, response.data.sheet);
				$root.addClass('is-active').removeClass('is-deleted has-payload-error');
				syncAndMarkChanged($root);
			}).fail(showCopyError);
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
			syncAndMarkChanged($root);
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
