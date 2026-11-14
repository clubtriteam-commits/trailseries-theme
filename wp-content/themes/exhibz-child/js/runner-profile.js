/**
 * Runner profile charts (/runner/): drawn as inline SVG from the JSON block
 * #tsr-rp-data that inc/runner.php prints. No library. The chart cards are
 * `hidden` in the markup and shown here, so without JS nothing empty appears.
 */
( function () {
	'use strict';
	var src = document.getElementById( 'tsr-rp-data' );
	if ( ! src ) {
		return;
	}
	var ROWS = JSON.parse( src.textContent );
	var fins = ROWS.filter( function ( r ) { return r.fin; } );
	var years = ROWS.map( function ( r ) { return r.y; } ).filter( function ( y, i, a ) { return a.indexOf( y ) === i; } ).sort();
	var COL = { 'short': '#00aadd', medium: '#0a1628', 'long': '#e05c1e' };
	var CAT = { 'short': 'къса', medium: 'средна', 'long': 'дълга' };
	var thisYear = years[ years.length - 1 ];

	function esc( s ) { return String( s ).replace( /[&<>"]/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ]; } ); }
	function hms( s ) { return Math.floor( s / 3600 ) + ':' + String( Math.floor( s % 3600 / 60 ) ).padStart( 2, '0' ) + ':' + String( s % 60 ).padStart( 2, '0' ); }
	function km( k ) { return String( +k ).replace( '.', ',' ) + ' км'; }
	function yy( y ) { return '’' + String( y ).slice( 2 ); }
	function avg( a ) { return a.length ? a.reduce( function ( t, x ) { return t + x; }, 0 ) / a.length : null; }
	function median( a ) { var s = a.slice().sort( function ( x, y ) { return x - y; } ); return s.length ? s[ Math.floor( s.length / 2 ) ] : null; }
	function box( name ) { return document.querySelector( '[data-chart="' + name + '"]' ); }
	function show( el ) { el.closest( '[data-tsr-rp-chart]' ).hidden = false; }

	// ── Form: percentile of every finish, yearly average ─────────────
	( function () {
		var el = box( 'form' ), pts = fins.filter( function ( r ) { return r.pct !== null; } ), n = pts.length;
		if ( ! el || n < 3 ) { return; }
		var W = 340, H = 190, L = 26, R = 6, T = 8, B = 24, pw = W - L - R, ph = H - T - B;
		var x = function ( i ) { return L + ( n === 1 ? pw / 2 : pw * i / ( n - 1 ) ); }, y = function ( v ) { return T + ph * ( 1 - v / 100 ); };
		var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Процентил във всеки старт, ' + years[0] + '–' + thisYear + '">', path = '';
		[ 0, 25, 50, 75, 100 ].forEach( function ( v ) { s += '<line x1="' + L + '" x2="' + ( W - R ) + '" y1="' + y( v ) + '" y2="' + y( v ) + '" stroke="#e2e6ea"/><text x="' + ( L - 5 ) + '" y="' + ( y( v ) + 3 ) + '" text-anchor="end">' + v + '</text>'; } );
		var lastLabel = -99;
		years.forEach( function ( yr ) {
			var idx = []; pts.forEach( function ( r, i ) { if ( r.y === yr ) { idx.push( i ); } } );
			if ( ! idx.length ) { return; }
			var a = avg( idx.map( function ( i ) { return pts[ i ]; } ).filter( function ( r ) { return ! r.odd; } ).map( function ( r ) { return r.pct; } ) );
			var x0 = x( idx[0] ) - 3, x1 = x( idx[ idx.length - 1 ] ) + 3, mid = ( x0 + x1 ) / 2;
			if ( a !== null ) { path += 'M' + x0 + ' ' + y( a ) + 'H' + x1; }
			if ( mid - lastLabel > 16 ) { s += '<text x="' + mid + '" y="' + ( H - 6 ) + '" text-anchor="middle">' + yy( yr ) + '</text>'; lastLabel = mid; }
			if ( idx[0] > 0 ) { s += '<line x1="' + ( x0 - 2.5 ) + '" x2="' + ( x0 - 2.5 ) + '" y1="' + T + '" y2="' + ( T + ph ) + '" stroke="#eef1f4"/>'; }
		} );
		s += '<path d="' + path + '" stroke="#0088bb" stroke-width="3" stroke-linecap="round" fill="none"/>';
		pts.forEach( function ( r, i ) {
			s += '<g class="tsr-rp-dot" tabindex="0" role="button" data-i="' + i + '" aria-label="' + esc( r.e + ' ' + r.y + ', ' + km( r.km ) + ': ' + r.pct + '%' ) + '"><circle cx="' + x( i ) + '" cy="' + y( r.pct ) + '" r="12" fill="transparent"/><circle cx="' + x( i ) + '" cy="' + y( r.pct ) + '" r="4" fill="' + ( COL[ r.cat ] || '#8a94a3' ) + '" stroke="#fff" stroke-width="1.5"' + ( r.odd ? ' opacity=".35"' : '' ) + '/></g>';
		} );
		el.innerHTML = s + '</svg>';
		show( el );
		var out = el.parentNode.querySelector( '.tsr-rp-readout' );
		function pick( d ) {
			el.querySelectorAll( '.tsr-rp-dot.is-on' ).forEach( function ( o ) { o.classList.remove( 'is-on' ); } );
			d.classList.add( 'is-on' );
			var r = pts[ +d.dataset.i ];
			out.innerHTML = '<b>' + esc( r.e + ' ' + r.y ) + '</b> · ' + esc( km( r.km ) ) + ( CAT[ r.cat ] ? ' (' + CAT[ r.cat ] + ')' : '' ) + ' · място ' + r.pl + ' от ' + r.n + ' · ' + hms( r.sec ) + ' · пред <b>' + r.pct + '%</b>' + ( r.odd ? ' · скъсено трасе?' : '' );
		}
		el.querySelectorAll( '.tsr-rp-dot' ).forEach( function ( d ) {
			d.addEventListener( 'click', function () { pick( d ); } );
			d.addEventListener( 'focus', function () { pick( d ); } );
		} );
	}() );

	// ── Volume: km and starts per year ───────────────────────────────
	( function () {
		var el = box( 'volume' );
		if ( ! el || years.length < 2 ) { return; }
		var W = 340, H = 170, L = 6, T = 18, B = 22, ph = H - T - B, bw = ( W - 2 * L ) / years.length;
		var sums = years.map( function ( yr ) { return fins.filter( function ( r ) { return r.y === yr; } ).reduce( function ( t, r ) { return t + ( r.k || 0 ); }, 0 ); } ), mx = Math.max.apply( null, sums ) || 1;
		var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Километри и стартове по години">';
		years.forEach( function ( yr, i ) {
			var h = Math.max( 2, ph * sums[ i ] / mx ), w = Math.min( bw - 8, 44 ), x0 = L + i * bw + ( bw - w ) / 2, cnt = ROWS.filter( function ( r ) { return r.y === yr; } ).length;
			s += '<rect x="' + x0 + '" y="' + ( T + ph - h ) + '" width="' + w + '" height="' + h + '" rx="5" fill="' + ( yr === thisYear ? '#e05c1e' : '#0088bb' ) + '"/>' +
				'<text x="' + ( x0 + w / 2 ) + '" y="' + ( T + ph - h - 5 ) + '" text-anchor="middle" style="fill:#111827;font-weight:700">' + cnt + '</text>' +
				( h > 18 && w > 18 ? '<text x="' + ( x0 + w / 2 ) + '" y="' + ( T + ph - h + 13 ) + '" text-anchor="middle" style="fill:#fff;font-size:9px">' + Math.round( sums[ i ] ) + '</text>' : '' ) +
				'<text x="' + ( x0 + w / 2 ) + '" y="' + ( H - 6 ) + '" text-anchor="middle">' + yy( yr ) + '</text>';
		} );
		el.innerHTML = s + '</svg>';
		show( el );
	}() );

	// ── Terrain-adjusted pace: yearly median ─────────────────────────
	( function () {
		var el = box( 'pace' );
		if ( ! el ) { return; }
		var ys = [], v = [];
		years.forEach( function ( yr ) {
			var m = median( fins.filter( function ( r ) { return r.y === yr && r.eff !== null && ! r.odd; } ).map( function ( r ) { return r.eff; } ) );
			if ( m !== null ) { ys.push( yr ); v.push( m ); }
		} );
		if ( ys.length < 2 ) { return; }
		var W = 340, H = 150, L = 34, R = 10, T = 16, B = 22, pw = W - L - R, ph = H - T - B;
		var lo = Math.floor( Math.min.apply( null, v ) * 2 ) / 2 - 0.5, hi = Math.ceil( Math.max.apply( null, v ) * 2 ) / 2 + 0.5;
		var x = function ( i ) { return L + pw * i / ( ys.length - 1 ); }, y = function ( t ) { return T + ph * ( t - lo ) / ( hi - lo ); };
		var mm = function ( t ) { var m = Math.floor( t ), sec = Math.round( ( t - m ) * 60 ); if ( sec === 60 ) { m++; sec = 0; } return m + ':' + String( sec ).padStart( 2, '0' ); };
		var step = ( hi - lo ) > 4 ? 1 : 0.5, s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Медианно изравнено темпо по години">';
		for ( var t = lo; t <= hi + 0.001; t += step ) { s += '<line x1="' + L + '" x2="' + ( W - R ) + '" y1="' + y( t ) + '" y2="' + y( t ) + '" stroke="#e2e6ea"/><text x="' + ( L - 5 ) + '" y="' + ( y( t ) + 3 ) + '" text-anchor="end">' + mm( t ) + '</text>'; }
		s += '<path d="' + v.map( function ( t, i ) { return ( i ? 'L' : 'M' ) + x( i ) + ' ' + y( t ); } ).join( '' ) + '" fill="none" stroke="#0a1628" stroke-width="2.5" stroke-linejoin="round"/>';
		var bi = v.indexOf( Math.min.apply( null, v ) );
		ys.forEach( function ( yr, i ) { s += '<circle cx="' + x( i ) + '" cy="' + y( v[ i ] ) + '" r="' + ( i === bi ? 5.5 : 4 ) + '" fill="' + ( i === bi ? '#16a34a' : '#0a1628' ) + '" stroke="#fff" stroke-width="1.5"/><text x="' + x( i ) + '" y="' + ( H - 6 ) + '" text-anchor="middle">' + yy( yr ) + '</text>'; } );
		s += '<text x="' + Math.min( Math.max( x( bi ), L + 36 ), W - R - 36 ) + '" y="' + ( y( v[ bi ] ) - 9 ) + '" text-anchor="middle" style="fill:#14532d;font-weight:700">' + mm( v[ bi ] ) + ' най-бърз</text>';
		el.innerHTML = s + '</svg>';
		show( el );
	}() );
}() );
