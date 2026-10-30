/* global RelayDelivery */
( function () {
	'use strict';
	if ( typeof RelayDelivery === 'undefined' ) {
		return;
	}
	function post( data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		body.append( 'nonce', RelayDelivery.nonce );
		return fetch( RelayDelivery.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}
	function badge( text, cls ) {
		var s = document.createElement( 'span' );
		s.className = 'relay-badge ' + cls;
		s.textContent = text;
		return s;
	}

	// Settings: « Tester la connexion ».
	var test = document.getElementById( 'relay-test' );
	if ( test ) {
		test.addEventListener( 'click', function () {
			var out = document.getElementById( 'relay-connection' );
			var key = document.getElementById( 'relay-api-key' ).value.trim();
			out.textContent = RelayDelivery.i18n.testing;
			test.disabled = true;
			post( { action: 'relay_delivery_test', key: key } )
				.then( function ( res ) {
					out.textContent = '';
					if ( res && res.success ) {
						out.appendChild( badge( res.data.label, 'relay-badge--ok' ) );
						if ( res.data.test ) {
							out.appendChild( document.createTextNode( ' ' ) );
							out.appendChild( badge( res.data.test, 'relay-badge--test' ) );
						}
					} else {
						out.appendChild( badge( ( res && res.data && res.data.message ) || RelayDelivery.i18n.error, 'relay-badge--ko' ) );
					}
				} )
				.catch( function () {
					out.textContent = '';
					out.appendChild( badge( RelayDelivery.i18n.error, 'relay-badge--ko' ) );
				} )
				.then( function () {
					test.disabled = false;
				} );
		} );
	}

	// Order box actions.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.relay-action' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var box = btn.closest( '.relay-box' );
		var action = btn.getAttribute( 'data-relay-action' );
		if ( action === 'cancel' && ! window.confirm( RelayDelivery.i18n.confirmCancel ) ) {
			return;
		}
		box.querySelectorAll( '.relay-action' ).forEach( function ( b ) {
			b.disabled = true;
		} );
		btn.textContent = RelayDelivery.i18n.working;
		post( { action: 'relay_delivery_order', relay_action: action, order_id: box.getAttribute( 'data-order' ) } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || RelayDelivery.i18n.error );
				}
			} )
			.catch( function () {
				window.alert( RelayDelivery.i18n.error );
			} )
			.then( function () {
				window.location.reload();
			} );
	} );
}() );
