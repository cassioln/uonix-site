( function( window, document, $ ) {
	'use strict';

	if ( ! $ || ! window.tinymce ) {
		return;
	}

	var editorId = 'excerpt';
	var moveState = null;

	function excerptWasMoved( ui ) {
		var item = ui && ui.item && ui.item[ 0 ];

		return Boolean( item && 'postexcerpt' === item.id );
	}

	function prepareEditorForMove() {
		var editor;
		var restoreVisual;

		if ( moveState ) {
			return;
		}

		editor = window.tinymce.get( editorId );
		if ( ! editor ) {
			return;
		}

		restoreVisual = ! editor.isHidden();
		moveState = { restoreVisual: restoreVisual };

		if ( restoreVisual ) {
			editor.save();
		}

		editor.remove();
	}

	function restoreEditorAfterMove() {
		var state = moveState;
		var init;

		moveState = null;

		if ( ! state || ! state.restoreVisual ) {
			return;
		}

		init = window.tinyMCEPreInit &&
			window.tinyMCEPreInit.mceInit &&
			window.tinyMCEPreInit.mceInit[ editorId ];

		if ( init ) {
			window.tinymce.init( init );
		}
	}

	$( document ).on(
		'sortstart.uonixExcerptEditor sortstop.uonixExcerptEditor',
		'.meta-box-sortables',
		function( event, ui ) {
			if ( ! excerptWasMoved( ui ) ) {
				return;
			}

			if ( 'sortstart' === event.type ) {
				prepareEditorForMove();
				return;
			}

			restoreEditorAfterMove();
		}
	);

	document.addEventListener(
		'click',
		function( event ) {
			var target = event.target;
			var button;

			if ( ! target || ! target.closest ) {
				return;
			}

			button = target.closest( '.handle-order-higher, .handle-order-lower' );
			if (
				! button ||
				'true' === button.getAttribute( 'aria-disabled' ) ||
				! button.closest( '#postexcerpt' )
			) {
				return;
			}

			prepareEditorForMove();
			window.setTimeout( restoreEditorAfterMove, 0 );
		},
		true
	);
}( window, document, window.jQuery ) );
