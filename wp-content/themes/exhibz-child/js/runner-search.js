/**
 * Runner search with suggestions (/runner/).
 *
 * Progressive enhancement of the plain search form: the name index
 * ([name, starts, last year, club, other spelling] per runner) is fetched
 * once, on first focus, and every keystroke is matched in the browser —
 * no request while typing. Matching ignores script (Cyrillic / Latin),
 * word order, letter case and one typo per word. Without JS the form
 * still submits to the server search.
 */
( function () {
	'use strict';
	var form = document.querySelector( '[data-tsr-runner-search]' );
	if ( ! form || ! window.fetch ) {
		return;
	}
	var input = form.querySelector( 'input[name="runner"]' );
	var C2L = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sht', 'ъ': 'a', 'ь': 'y', 'ю': 'yu', 'я': 'ya', 'ѝ': 'i' };
	var FOLDS = [ [ 'sch', 'sh' ], [ 'kh', 'h' ], [ 'ph', 'f' ], [ 'w', 'v' ], [ 'x', 'ks' ], [ 'q', 'k' ], [ 'dzh', 'j' ], [ 'dj', 'j' ], [ 'zh', 'j' ], [ 'tz', 'c' ], [ 'ts', 'c' ], [ 'ou', 'u' ], [ 'y', 'i' ], [ 'ck', 'k' ] ];

	// Same folding as TSR_Runner_Merge::fold_word() on the server.
	function fold( w ) {
		w = w.toLowerCase().normalize( 'NFKD' ).replace( /[̀-ͯ]/g, '' );
		w = Array.from( w ).map( function ( c ) { return C2L[ c ] !== undefined ? C2L[ c ] : c; } ).join( '' ).replace( /[^a-z]/g, '' );
		FOLDS.forEach( function ( f ) { w = w.split( f[0] ).join( f[1] ); } );
		return w.replace( /(.)\1+/g, '$1' );
	}
	function tokens( s ) {
		return s.split( /[\s\-]+/ ).map( fold ).filter( Boolean );
	}
	// Damerau–Levenshtein distance ≤ 1.
	function near( a, b ) {
		if ( a === b ) { return true; }
		var la = a.length, lb = b.length, i = 0;
		if ( Math.abs( la - lb ) > 1 ) { return false; }
		while ( i < la && i < lb && a[ i ] === b[ i ] ) { i++; }
		if ( la === lb ) {
			return a.slice( i + 1 ) === b.slice( i + 1 ) || ( a[ i ] === b[ i + 1 ] && a[ i + 1 ] === b[ i ] && a.slice( i + 2 ) === b.slice( i + 2 ) );
		}
		return la > lb ? a.slice( i + 1 ) === b.slice( i ) : a.slice( i ) === b.slice( i + 1 );
	}

	var people = null, loading = null;
	function load() {
		if ( ! loading ) {
			loading = fetch( form.dataset.index, { credentials: 'omit' } )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( list ) {
					people = list.map( function ( e ) {
						return { name: e[0], starts: e[1], last: e[2], club: e[3], aka: e[4], t: tokens( e[0] + ' ' + ( e[4] || '' ) ) };
					} );
				} )
				.catch( function () { people = []; } );
		}
		return loading;
	}

	function search( q ) {
		var qt = tokens( q ), out = [];
		if ( ! people || ! qt.length || qt.join( '' ).length < 2 ) { return out; }
		people.forEach( function ( p ) {
			var score = 0, used = {}, ok = true;
			qt.forEach( function ( t, qi ) {
				var best = 0, at = -1;
				p.t.forEach( function ( w, j ) {
					if ( used[ j ] ) { return; }
					var s = w === t ? 4 : w.indexOf( t ) === 0 ? 3 : ( t.length >= 4 && ( near( w, t ) || ( qi === qt.length - 1 && near( w.slice( 0, t.length ), t ) ) ) ) ? 1 : 0;
					if ( s > best ) { best = s; at = j; }
				} );
				if ( ! best ) { ok = false; } else { score += best; used[ at ] = true; }
			} );
			if ( ok ) { out.push( { p: p, score: score } ); }
		} );
		out.sort( function ( a, b ) { return b.score - a.score || b.p.starts - a.p.starts || a.p.name.localeCompare( b.p.name, 'bg' ); } );
		return out.slice( 0, 8 ).map( function ( o ) { return o.p; } );
	}

	// ── UI: ARIA combobox ──────────────────────────────────────────────
	var list = document.createElement( 'ul' );
	list.className = 'tsr-rs-list';
	list.id = 'tsr-rs-list';
	list.setAttribute( 'role', 'listbox' );
	list.setAttribute( 'aria-label', 'Бегачи' );
	list.hidden = true;
	var status = document.createElement( 'p' );
	status.className = 'screen-reader-text';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	form.classList.add( 'has-suggest' );
	form.appendChild( list );
	form.appendChild( status );
	input.setAttribute( 'role', 'combobox' );
	input.setAttribute( 'aria-expanded', 'false' );
	input.setAttribute( 'aria-controls', list.id );
	input.setAttribute( 'aria-autocomplete', 'list' );

	var results = [], active = -1, timer;
	function text( tag, cls, value ) {
		var el = document.createElement( tag );
		if ( cls ) { el.className = cls; }
		el.textContent = value;
		return el;
	}
	function close() {
		list.hidden = true;
		input.setAttribute( 'aria-expanded', 'false' );
		input.removeAttribute( 'aria-activedescendant' );
		active = -1;
	}
	function render() {
		var q = input.value.trim();
		if ( q.length < 2 || ! people ) { close(); status.textContent = ''; return; }
		results = search( q );
		list.textContent = '';
		if ( ! results.length ) {
			var none = text( 'li', 'tsr-rs-none', 'Няма бегач с такова име. Провери изписването или опитай само с фамилията.' );
			none.setAttribute( 'role', 'presentation' );
			list.appendChild( none );
		}
		results.forEach( function ( p, i ) {
			var li = document.createElement( 'li' ), main = document.createElement( 'span' ), count = document.createElement( 'span' );
			li.className = 'tsr-rs-opt';
			li.id = 'tsr-rs-opt-' + i;
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'aria-selected', 'false' );
			main.appendChild( text( 'span', 'tsr-rs-opt__name', p.name ) );
			main.appendChild( text( 'span', 'tsr-rs-opt__meta', [ p.aka ? 'и като ' + p.aka : '', p.club, 'последно ' + p.last ].filter( Boolean ).join( ' · ' ) ) );
			count.className = 'tsr-rs-opt__n';
			count.appendChild( text( 'b', '', String( p.starts ) ) );
			count.appendChild( document.createTextNode( p.starts === 1 ? 'старт' : 'старта' ) );
			li.appendChild( main );
			li.appendChild( count );
			list.appendChild( li );
		} );
		list.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
		active = -1;
		status.textContent = results.length ? results.length + ' предложения. Стрелки нагоре и надолу за избор.' : 'Няма съвпадения.';
	}
	function move( d ) {
		if ( list.hidden || ! results.length ) { return; }
		active = ( active + d + results.length ) % results.length;
		Array.prototype.forEach.call( list.children, function ( li, i ) { li.setAttribute( 'aria-selected', i === active ? 'true' : 'false' ); } );
		input.setAttribute( 'aria-activedescendant', 'tsr-rs-opt-' + active );
		list.children[ active ].scrollIntoView( { block: 'nearest' } );
	}
	function pick( i ) {
		if ( ! results[ i ] ) { return; }
		input.value = results[ i ].name;
		close();
		form.submit(); // the exact canonical name opens that runner's profile
	}

	input.addEventListener( 'focus', function () { load().then( function () { if ( document.activeElement === input && input.value.trim().length >= 2 && ! input.dataset.opened ) { render(); } } ); } );
	input.addEventListener( 'input', function () {
		delete input.dataset.opened;
		clearTimeout( timer );
		timer = setTimeout( function () { load().then( render ); }, 200 );
	} );
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'ArrowDown' ) { e.preventDefault(); if ( list.hidden ) { load().then( render ); } else { move( 1 ); } }
		else if ( e.key === 'ArrowUp' ) { e.preventDefault(); move( -1 ); }
		else if ( e.key === 'Enter' && ! list.hidden && results.length ) { e.preventDefault(); pick( active < 0 ? 0 : active ); }
		else if ( e.key === 'Escape' ) { close(); }
	} );
	list.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
	list.addEventListener( 'click', function ( e ) {
		var li = e.target.closest( '.tsr-rs-opt' );
		if ( li ) { pick( Array.prototype.indexOf.call( list.children, li ) ); }
	} );
	document.addEventListener( 'click', function ( e ) { if ( ! form.contains( e.target ) ) { close(); } } );
	// A profile is open: do not reopen the list over it until the visitor types.
	if ( input.value ) { input.dataset.opened = '1'; }
}() );
