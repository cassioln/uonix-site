/**
 * Desvia o POST /batch/v1 do editor de widgets para /uonix/v1/lote.
 * O motivo está em mu-plugins/uonix-admin/62-admin-widgets-lote-rest.php.
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || 'function' !== typeof wp.apiFetch.use ) {
		return;
	}

	var LOTE_NUCLEO = /^\/batch\/v1(?=[/?#]|$)/;

	wp.apiFetch.use( function ( options, next ) {
		var metodo = String( ( options && options.method ) || 'GET' ).toUpperCase();

		if ( 'POST' === metodo && 'string' === typeof options.path && LOTE_NUCLEO.test( options.path ) ) {
			return next( Object.assign( {}, options, { path: options.path.replace( LOTE_NUCLEO, '/uonix/v1/lote' ) } ) );
		}

		return next( options );
	} );
} )( window.wp );
