(function ($) {
	'use strict';

	$(document).on('click', '.uonix-vtst-diagram-image-select', function (event) {
		event.preventDefault();
		var $box = $(this).closest('.inside');
		var frame = wp.media({
			title: 'Selecionar imagem do esquema técnico',
			button: { text: 'Usar esta imagem' },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
			$box.find('#uonix-vtst-diagram-image-id').val(attachment.id);
			$box.find('.uonix-vtst-diagram-admin__preview').html(
				$('<img>', {
					'class': 'uonix-vtst-diagram-admin__preview-image',
					'src': src,
					'alt': attachment.alt || ''
				})
			);
		});

		frame.open();
	});

	$(document).on('click', '.uonix-vtst-diagram-image-remove', function (event) {
		event.preventDefault();
		var $box = $(this).closest('.inside');
		$box.find('#uonix-vtst-diagram-image-id').val('');
		$box.find('.uonix-vtst-diagram-admin__preview').empty();
	});
}(jQuery));
