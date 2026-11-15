/* Ecopart: the "?" explanation. Opens on click, closes on a second click, outside click or Escape. */
( function () {
	'use strict';
	function close( except ) {
		document.querySelectorAll( '.ecopart-why-btn[aria-expanded="true"]' ).forEach( function ( b ) {
			if ( b !== except ) {
				b.setAttribute( 'aria-expanded', 'false' );
				var t = document.getElementById( b.getAttribute( 'aria-controls' ) );
				if ( t ) {
					t.hidden = true;
				}
			}
		} );
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '.ecopart-why-btn' );
		if ( ! b ) {
			if ( ! ( e.target.closest && e.target.closest( '.ecopart-tip' ) ) ) {
				close();
			}
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		close( b );
		var tip = document.getElementById( b.getAttribute( 'aria-controls' ) );
		var open = b.getAttribute( 'aria-expanded' ) !== 'true';
		b.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( tip ) {
			tip.hidden = ! open;
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			var b = document.querySelector( '.ecopart-why-btn[aria-expanded="true"]' );
			close();
			if ( b ) {
				b.focus();
			}
		}
	} );
}() );
