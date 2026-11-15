/* Ecopart admin: wizard steps, live preview, the product amount hint and price check, quick edit. No dependencies. */
( function () {
	'use strict';
	var cfg = window.ecopartAdmin || {};

	function num( v ) {
		v = String( v || '' ).trim().replace( /\s/g, '' ).replace( ',', '.' );
		return v === '' || isNaN( v ) ? null : parseFloat( v );
	}
	function money( v ) {
		var d = parseInt( cfg.decimals, 10 ) || 0;
		var p = v.toFixed( d ).split( '.' );
		p[ 0 ] = p[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, cfg.thousand || '' );
		var amount = p.join( cfg.dec || '.' );
		return ( cfg.format || '%1$s%2$s' ).replace( '%1$s', cfg.symbol || '' ).replace( '%2$s', amount ).replace( /&nbsp;/g, ' ' );
	}
	function rateText( r ) {
		return String( Math.round( r * 1000 ) / 1000 ).replace( '.', cfg.dec || '.' );
	}

	/* Wizard: one step at a time. Without this script all steps show and the form still saves. */
	function wizard( form ) {
		var steps = form.querySelectorAll( '.ecopart-step' );
		var dots = document.querySelectorAll( '.ecopart-dots li' );
		if ( ! steps.length ) {
			return;
		}
		var cur = 0;
		function show( i ) {
			cur = Math.max( 0, Math.min( steps.length - 1, i ) );
			steps.forEach( function ( s, n ) {
				s.classList.toggle( 'is-cur', n === cur );
			} );
			dots.forEach( function ( d, n ) {
				d.classList.toggle( 'cur', n === cur );
				d.classList.toggle( 'done', n < cur );
				if ( n === cur ) {
					d.setAttribute( 'aria-current', 'step' );
				} else {
					d.removeAttribute( 'aria-current' );
				}
			} );
			var h = steps[ cur ].querySelector( 'h2' );
			if ( h && form.dataset.moved ) {
				h.setAttribute( 'tabindex', '-1' );
				h.focus();
			}
		}
		form.classList.add( 'ecopart-js' );
		form.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.ecopart-next, .ecopart-back' );
			if ( b ) {
				form.dataset.moved = '1';
				show( cur + ( b.classList.contains( 'ecopart-next' ) ? 1 : -1 ) );
			}
		} );
		show( 0 );
	}

	/* Live preview follows the style, size, "?" and location choices. */
	function preview( form ) {
		var stage = form.parentNode.querySelector( '.ecopart-stage' ) || document.querySelector( '.ecopart-stage' );
		if ( ! stage ) {
			return;
		}
		function sync() {
			var style = form.querySelector( 'input[name="style"]:checked' );
			var size = form.querySelector( 'input[name="size"]:checked' );
			var why = form.querySelector( 'input[name="why"]' );
			if ( style ) {
				stage.querySelectorAll( '.ecopart-text[data-style]' ).forEach( function ( el ) {
					el.hidden = el.dataset.style !== style.value;
				} );
			}
			if ( size ) {
				stage.classList.toggle( 'is-normal', size.value === 'normal' );
			}
			if ( why ) {
				stage.querySelectorAll( '.ecopart-preview-why' ).forEach( function ( el ) {
					el.hidden = ! why.checked;
				} );
			}
			[ [ 'loc_product', 'product' ], [ 'loc_cart', 'cart' ] ].forEach( function ( m ) {
				var box = form.querySelector( 'input[name="' + m[ 0 ] + '"]' );
				var pane = stage.querySelector( '[data-loc="' + m[ 1 ] + '"]' );
				if ( box && pane ) {
					if ( box.checked ) {
						pane.removeAttribute( 'data-off' );
					} else {
						pane.setAttribute( 'data-off', '1' );
					}
				}
			} );
		}
		form.addEventListener( 'change', sync );
		sync();
	}

	/* Product editor: the VAT hint and the "price lower than the fee" check, live. */
	function priceOf( input ) {
		var box = input.closest( '.woocommerce_variation' ) || document.getElementById( 'general_product_data' );
		if ( ! box ) {
			return num( input.dataset.price );
		}
		var sale = box.querySelector( 'input[name^="_sale_price"], input[name^="variable_sale_price"]' );
		var reg = box.querySelector( 'input[name^="_regular_price"], input[name^="variable_regular_price"]' );
		var p = sale && num( sale.value ) ? num( sale.value ) : reg ? num( reg.value ) : null;
		return p === null ? num( input.dataset.price ) : p;
	}
	function check( input ) {
		var field = input.closest( '.ecopart-field' );
		if ( ! field ) {
			return;
		}
		var hint = field.querySelector( '.ecopart-hint' );
		var warn = field.querySelector( '.ecopart-warn' );
		var amount = num( input.value );
		var rate = num( input.dataset.rate );
		var ttc = input.dataset.mode === 'ttc';
		if ( hint ) {
			if ( amount === null && input.dataset.var === '1' ) {
				hint.textContent = hint.dataset.empty || hint.textContent;
			} else if ( amount !== null && rate !== null ) {
				var other = ttc ? amount / ( 1 + rate / 100 ) : amount * ( 1 + rate / 100 );
				hint.textContent = ( ttc ? cfg.hintExcl : cfg.hintIncl ).replace( '%1$s', money( other ) ).replace( '%2$s', rateText( rate ) ).replace( '%%', '%' );
			}
		}
		if ( warn ) {
			var price = priceOf( input );
			var fee = amount;
			if ( fee !== null && rate !== null ) {
				if ( ! ttc && cfg.pricesIncl ) {
					fee = fee * ( 1 + rate / 100 );
				} else if ( ttc && ! cfg.pricesIncl ) {
					fee = fee / ( 1 + rate / 100 );
				}
			}
			warn.hidden = ! ( fee !== null && fee > 0 && price !== null && price > 0 && price < fee );
		}
	}
	function products() {
		document.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( ! t.matches || ! t.matches( 'input' ) ) {
				return;
			}
			if ( t.classList.contains( 'ecopart-amount-input' ) ) {
				check( t );
				return;
			}
			if ( /^(_sale_price|_regular_price|variable_sale_price|variable_regular_price)/.test( t.name || '' ) ) {
				var box = t.closest( '.woocommerce_variation' ) || document.getElementById( 'general_product_data' );
				if ( box ) {
					box.querySelectorAll( '.ecopart-amount-input' ).forEach( function ( i ) {
						if ( ! i.closest( '.woocommerce_variation' ) === ! t.closest( '.woocommerce_variation' ) ) {
							check( i );
						}
					} );
				}
			}
		} );
		document.querySelectorAll( '.ecopart-hint' ).forEach( function ( h ) {
			h.dataset.empty = h.textContent;
		} );
	}

	/* Quick edit: copy the row's amount and type into the form. */
	function quickEdit() {
		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest && e.target.closest( '.editinline' );
			if ( ! link ) {
				return;
			}
			var row = link.closest( 'tr' );
			var data = row && row.querySelector( '.ecopart-inline' );
			var id = row && row.id ? row.id.replace( 'post-', '' ) : '';
			if ( ! data || ! id ) {
				return;
			}
			window.setTimeout( function () {
				var edit = document.getElementById( 'edit-' + id );
				if ( ! edit ) {
					return;
				}
				var a = edit.querySelector( 'input[name="ecopart_amount"]' );
				var t = edit.querySelector( 'select[name="ecopart_type"]' );
				if ( a ) {
					a.value = data.dataset.amount || '';
				}
				if ( t && data.dataset.type ) {
					t.value = data.dataset.type;
				}
			}, 0 );
		} );
	}

	function start() {
		document.querySelectorAll( '.ecopart-form' ).forEach( function ( f ) {
			if ( f.dataset.form === 'wizard' ) {
				wizard( f );
			}
			preview( f );
		} );
		products();
		quickEdit();
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
