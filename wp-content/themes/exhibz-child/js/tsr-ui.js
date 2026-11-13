/**
 * Results / ranking UI (page-rezultati.php, page-klasiraniya.php).
 *
 * Progressive enhancement:
 *  - [data-tsr-autosubmit] selects submit their form on change; the form's
 *    fallback button is hidden once the script runs.
 *  - [data-tsr-tabs] becomes an accessible tablist (WAI-ARIA tabs pattern:
 *    arrow keys, Home/End). Without JS every panel stays visible.
 */
( function () {
	'use strict';

	document.querySelectorAll( 'select[data-tsr-autosubmit]' ).forEach( function ( select ) {
		var form = select.form;
		if ( ! form ) {
			return;
		}
		var button = form.querySelector( '.tsr-toolbar__go' );
		if ( button ) {
			button.hidden = true;
		}
		select.addEventListener( 'change', function () {
			form.submit();
		} );
	} );

	document.querySelectorAll( '[data-tsr-tabs]' ).forEach( function ( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );
		if ( ! tabs.length ) {
			return;
		}
		root.querySelector( '[role="tablist"]' ).hidden = false;
		root.classList.add( 'tsr-tabs-on' ); // panel headings repeat the tab labels → visually hidden

		function activate( tab, focus ) {
			tabs.forEach( function ( t ) {
				var on    = t === tab;
				var panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				if ( panel ) {
					panel.hidden = ! on;
				}
			} );
			if ( focus ) {
				tab.focus();
			}
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				activate( tab, false );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var next = null;
				if ( 'ArrowRight' === e.key ) {
					next = tabs[ ( i + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === e.key ) {
					next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === e.key ) {
					next = tabs[ 0 ];
				} else if ( 'End' === e.key ) {
					next = tabs[ tabs.length - 1 ];
				}
				if ( next ) {
					e.preventDefault();
					activate( next, true );
				}
			} );
		} );

		var initial = tabs.filter( function ( t ) {
			return 'true' === t.getAttribute( 'aria-selected' );
		} )[ 0 ] || tabs[ 0 ];
		activate( initial, false );
	} );
}() );
