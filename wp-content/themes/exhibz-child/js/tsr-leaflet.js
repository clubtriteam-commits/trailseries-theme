/**
 * On-demand Leaflet loader (audit M19).
 *
 * Leaflet's CSS + JS (~150 KB) and the map tiles used to load with every
 * view of the home page and /traseta/, although the home map is below the
 * fold and /traseta/ only draws a map inside the track modal. Pages now call
 * window.tsrLeaflet() when a map is actually needed; it injects both files
 * once and resolves with the global L. Both files are appended at the end of
 * <head>, so Leaflet's CSS still comes after the theme stylesheet (the theme
 * overrides some .leaflet-* rules at equal specificity, as before).
 *
 * @package exhibz-child
 */
( function () {
	'use strict';

	var BASE    = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';
	var JS_SRI  = 'sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==';
	var CSS_SRI = 'sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==';
	var pending = null;

	function add( tag, attrs ) {
		var el = document.createElement( tag );
		Object.keys( attrs ).forEach( function ( k ) {
			el[ k ] = attrs[ k ];
		} );
		return new Promise( function ( resolve, reject ) {
			el.onload  = resolve;
			el.onerror = reject;
			document.head.appendChild( el );
		} );
	}

	window.tsrLeaflet = function () {
		if ( window.L ) {
			return Promise.resolve( window.L );
		}
		if ( ! pending ) {
			pending = Promise.all( [
				add( 'link', { rel: 'stylesheet', href: BASE + 'leaflet.min.css', integrity: CSS_SRI, crossOrigin: 'anonymous', referrerPolicy: 'no-referrer' } ),
				add( 'script', { src: BASE + 'leaflet.min.js', integrity: JS_SRI, crossOrigin: 'anonymous', referrerPolicy: 'no-referrer', async: true } )
			] ).then( function () {
				return window.L;
			}, function ( err ) {
				pending = null; // allow a retry on the next call
				throw err;
			} );
		}
		return pending;
	};

	/**
	 * Load and initialise when `el` gets near the viewport (one call per map).
	 * Falls back to loading right away without IntersectionObserver.
	 */
	window.tsrLeafletWhenVisible = function ( el, init ) {
		if ( ! el ) {
			return;
		}
		var run = function () {
			window.tsrLeaflet().then( init, function () {
				el.setAttribute( 'data-map-error', '' );
			} );
		};
		if ( ! ( 'IntersectionObserver' in window ) ) {
			run();
			return;
		}
		var io = new IntersectionObserver( function ( entries ) {
			if ( entries.some( function ( e ) { return e.isIntersecting; } ) ) {
				io.disconnect();
				run();
			}
		}, { rootMargin: '400px 0px' } );
		io.observe( el );
	};
}() );
