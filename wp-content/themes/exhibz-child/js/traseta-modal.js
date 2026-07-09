/**
 * Трасета — track detail modal.
 *
 * Click a track row → modal with a Leaflet map (full-resolution GPX
 * polyline), an SVG elevation profile, stats, and GPX/KML downloads.
 * GPX files are fetched and parsed lazily on first open, then cached.
 *
 * @package exhibz-child
 */
(function () {
	'use strict';

	var modal = document.getElementById( 'tsr-track-modal' );
	if ( ! modal || typeof L === 'undefined' ) {
		return;
	}

	var titleEl   = document.getElementById( 'tsr-modal-title' );
	var statsEl   = document.getElementById( 'tsr-modal-stats' );
	var chartWrap = document.getElementById( 'tsr-modal-chart-wrap' );
	var chartEl   = document.getElementById( 'tsr-modal-chart' );
	var tooltipEl = document.getElementById( 'tsr-modal-chart-tooltip' );
	var gpxBtn    = document.getElementById( 'tsr-modal-gpx' );
	var kmlBtn    = document.getElementById( 'tsr-modal-kml' );

	var map          = null;
	var trackLayer   = null;
	var hoverMarker  = null;
	// Critical visual properties are inlined (not left to style.css alone) so
	// the marker renders correctly even if the stylesheet hasn't deployed —
	// Leaflet only guarantees position:absolute/left/top via its own CSS.
	var hoverIcon = L.divIcon( {
		className: 'tsr-hover-marker-wrap',
		html:
			'<span class="tsr-hover-marker" style="display:block;position:relative;width:16px;height:16px;' +
			'border-radius:50%;background:#fff;border:2px solid #2ecc71;' +
			'box-shadow:0 1px 4px rgba(0,0,0,.45);box-sizing:border-box;">' +
			'<span class="tsr-hover-marker__dot" style="position:absolute;top:50%;left:50%;' +
			'width:6px;height:6px;border-radius:50%;background:#2ecc71;' +
			'transform:translate(-50%,-50%);"></span></span>',
		iconSize:   [ 16, 16 ],
		iconAnchor: [ 8, 8 ]
	} );
	var gpxCache   = {}; // url → parsed points [{lat, lon, ele, dist}]
	var lastFocus  = null;
	var chartState = null; // geometry + points needed to map hover x → track point

	// ── GPX parsing ─────────────────────────────────────────────────────────

	function haversine( lat1, lon1, lat2, lon2 ) {
		var R  = 6371000;
		var dLat = ( lat2 - lat1 ) * Math.PI / 180;
		var dLon = ( lon2 - lon1 ) * Math.PI / 180;
		var a  = Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
			Math.cos( lat1 * Math.PI / 180 ) * Math.cos( lat2 * Math.PI / 180 ) *
			Math.sin( dLon / 2 ) * Math.sin( dLon / 2 );
		return 2 * R * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
	}

	function parseGpx( xmlText ) {
		var doc = new DOMParser().parseFromString( xmlText, 'application/xml' );
		var pts = doc.querySelectorAll( 'trkpt' );
		var out = [];
		var dist = 0;
		for ( var i = 0; i < pts.length; i++ ) {
			var lat = parseFloat( pts[ i ].getAttribute( 'lat' ) );
			var lon = parseFloat( pts[ i ].getAttribute( 'lon' ) );
			if ( isNaN( lat ) || isNaN( lon ) ) {
				continue;
			}
			var eleNode = pts[ i ].querySelector( 'ele' );
			var ele = eleNode ? parseFloat( eleNode.textContent ) : null;
			if ( out.length ) {
				var prev = out[ out.length - 1 ];
				dist += haversine( prev.lat, prev.lon, lat, lon );
			}
			out.push( { lat: lat, lon: lon, ele: isNaN( ele ) ? null : ele, dist: dist } );
		}
		return out;
	}

	// ── Elevation profile (SVG) ─────────────────────────────────────────────

	function niceStep( totalKm ) {
		if ( totalKm <= 6 )  { return 1; }
		if ( totalKm <= 14 ) { return 2; }
		if ( totalKm <= 30 ) { return 5; }
		return 10;
	}

	function drawChart( points ) {
		var eles = points.filter( function ( p ) { return p.ele !== null; } );
		if ( eles.length < 2 ) {
			chartWrap.hidden = true;
			chartState = null;
			return;
		}
		chartWrap.hidden = false;

		var W = 800, H = 240;
		var padL = 46, padR = 14, padT = 14, padB = 28;
		var iw = W - padL - padR, ih = H - padT - padB;

		var totalM  = points[ points.length - 1 ].dist;
		var totalKm = totalM / 1000;
		var minE = Infinity, maxE = -Infinity;
		eles.forEach( function ( p ) {
			if ( p.ele < minE ) { minE = p.ele; }
			if ( p.ele > maxE ) { maxE = p.ele; }
		} );
		// Round the elevation range to 50 m ticks with a little headroom.
		minE = Math.floor( minE / 50 ) * 50;
		maxE = Math.ceil( maxE / 50 ) * 50;
		if ( maxE - minE < 100 ) { maxE = minE + 100; }

		function x( d ) { return padL + ( d / totalM ) * iw; }
		function y( e ) { return padT + ( 1 - ( e - minE ) / ( maxE - minE ) ) * ih; }

		// Downsample to ~400 points for the path.
		var step = Math.max( 1, Math.floor( eles.length / 400 ) );
		var line = '';
		for ( var i = 0; i < eles.length; i += step ) {
			line += ( line ? 'L' : 'M' ) + x( eles[ i ].dist ).toFixed( 1 ) + ',' + y( eles[ i ].ele ).toFixed( 1 );
		}
		var last = eles[ eles.length - 1 ];
		line += 'L' + x( last.dist ).toFixed( 1 ) + ',' + y( last.ele ).toFixed( 1 );
		var area = line + 'L' + x( last.dist ).toFixed( 1 ) + ',' + ( padT + ih ) +
			'L' + padL + ',' + ( padT + ih ) + 'Z';

		var svg = '<defs><linearGradient id="tsr-ele-grad" x1="0" y1="0" x2="0" y2="1">' +
			'<stop offset="0%" stop-color="#ff9a3c" stop-opacity="0.95"/>' +
			'<stop offset="100%" stop-color="#e05c1e" stop-opacity="0.55"/>' +
			'</linearGradient></defs>';

		// Horizontal gridlines + y labels (4 steps).
		var ySteps = 4;
		for ( var g = 0; g <= ySteps; g++ ) {
			var e  = minE + ( maxE - minE ) * g / ySteps;
			var yy = y( e ).toFixed( 1 );
			svg += '<line x1="' + padL + '" y1="' + yy + '" x2="' + ( W - padR ) + '" y2="' + yy + '" class="tsr-chart__grid"/>';
			svg += '<text x="' + ( padL - 6 ) + '" y="' + yy + '" class="tsr-chart__ylabel">' + Math.round( e ) + '</text>';
		}

		// X ticks every niceStep km.
		var kmStep = niceStep( totalKm );
		for ( var km = 0; km <= totalKm; km += kmStep ) {
			var xx = x( km * 1000 ).toFixed( 1 );
			svg += '<line x1="' + xx + '" y1="' + ( padT + ih ) + '" x2="' + xx + '" y2="' + ( padT + ih + 4 ) + '" class="tsr-chart__tick"/>';
			svg += '<text x="' + xx + '" y="' + ( H - 8 ) + '" class="tsr-chart__xlabel">' + km + ' км</text>';
		}

		svg += '<path d="' + area + '" fill="url(#tsr-ele-grad)"/>';
		svg += '<path d="' + line + '" fill="none" class="tsr-chart__line"/>';

		// Hover guide (vertical line + dot on the profile), updated on
		// mousemove without touching the rest of the markup.
		svg += '<line class="tsr-chart__hover-line" x1="0" y1="' + padT + '" x2="0" y2="' + ( padT + ih ) + '"/>';
		svg += '<circle class="tsr-chart__hover-dot" cx="0" cy="0" r="4"/>';

		chartEl.innerHTML = svg;

		chartState = {
			points: eles, // full-resolution, not the downsampled path
			totalM: totalM,
			padL:   padL,
			padR:   padR,
			iw:     iw,
			W:      W,
			x:      x,
			y:      y,
			hoverLine: chartEl.querySelector( '.tsr-chart__hover-line' ),
			hoverDot:  chartEl.querySelector( '.tsr-chart__hover-dot' )
		};
	}

	/**
	 * Interpolated point at a given cumulative distance (metres) along the
	 * track, via binary search over the (dist-sorted) points array.
	 *
	 * @param {Array<{lat:number, lon:number, ele:number, dist:number}>} points
	 * @param {number} targetDist
	 */
	function pointAtDistance( points, targetDist ) {
		var lo = 0, hi = points.length - 1;
		if ( targetDist <= points[ lo ].dist ) { return points[ lo ]; }
		if ( targetDist >= points[ hi ].dist ) { return points[ hi ]; }
		while ( hi - lo > 1 ) {
			var mid = ( lo + hi ) >> 1;
			if ( points[ mid ].dist < targetDist ) { lo = mid; } else { hi = mid; }
		}
		var a = points[ lo ], b = points[ hi ];
		var span = b.dist - a.dist;
		var t = span > 0 ? ( targetDist - a.dist ) / span : 0;
		return {
			lat:  a.lat + ( b.lat - a.lat ) * t,
			lon:  a.lon + ( b.lon - a.lon ) * t,
			ele:  a.ele + ( b.ele - a.ele ) * t,
			dist: targetDist
		};
	}

	function ensureHoverMarker() {
		if ( ! hoverMarker ) {
			hoverMarker = L.marker( [ 0, 0 ], {
				icon:         hoverIcon,
				interactive:  false,
				keyboard:     false,
				// Above the polyline (which has no explicit zIndexOffset) and
				// above the tile/shadow panes regardless of add order.
				zIndexOffset: 1000
			} );
		}
		if ( map && ! map.hasLayer( hoverMarker ) ) {
			hoverMarker.addTo( map );
		}
		return hoverMarker;
	}

	function hideChartHover() {
		if ( chartState ) {
			chartState.hoverLine.style.opacity = '0';
			chartState.hoverDot.style.opacity  = '0';
		}
		tooltipEl.hidden = true;
		if ( hoverMarker && map && map.hasLayer( hoverMarker ) ) {
			map.removeLayer( hoverMarker );
		}
	}

	function handleChartMove( ev ) {
		if ( ! chartState ) {
			return;
		}
		var rect = chartEl.getBoundingClientRect();
		if ( ! rect.width ) {
			return;
		}
		var scale = rect.width / chartState.W;
		var svgX  = ( ev.clientX - rect.left ) / scale;
		svgX = Math.max( chartState.padL, Math.min( chartState.W - chartState.padR, svgX ) );

		var frac = ( svgX - chartState.padL ) / chartState.iw;
		var targetDist = frac * chartState.totalM;
		var pt = pointAtDistance( chartState.points, targetDist );

		var px = chartState.x( pt.dist ).toFixed( 1 );
		var py = chartState.y( pt.ele ).toFixed( 1 );
		chartState.hoverLine.setAttribute( 'x1', px );
		chartState.hoverLine.setAttribute( 'x2', px );
		chartState.hoverLine.style.opacity = '1';
		chartState.hoverDot.setAttribute( 'cx', px );
		chartState.hoverDot.setAttribute( 'cy', py );
		chartState.hoverDot.style.opacity = '1';

		var wrapRect = chartWrap.getBoundingClientRect();
		tooltipEl.hidden = false;
		tooltipEl.style.left = ( ev.clientX - wrapRect.left ) + 'px';
		tooltipEl.style.top  = ( ev.clientY - wrapRect.top ) + 'px';
		tooltipEl.innerHTML =
			'<span class="tsr-chart-tooltip__row">Дистанция: ' + ( pt.dist / 1000 ).toFixed( 2 ) + ' км</span>' +
			'<span class="tsr-chart-tooltip__row">Височина: ' + Math.round( pt.ele ) + ' м</span>';

		if ( map ) {
			try {
				ensureHoverMarker().setLatLng( [ pt.lat, pt.lon ] );
			} catch ( err ) {
				// Surface it instead of failing silently — a broken marker
				// update should never take the tooltip/chart down with it.
				window.console && console.error( 'tsr-traseta: hover marker update failed', err );
			}
		}
	}

	chartEl.addEventListener( 'mousemove', handleChartMove );
	chartEl.addEventListener( 'mouseleave', hideChartHover );

	// ── Map ─────────────────────────────────────────────────────────────────

	function drawMap( points ) {
		if ( ! map ) {
			map = L.map( 'tsr-modal-map', { scrollWheelZoom: false } );
			L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 17,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
			} ).addTo( map );
		}
		if ( trackLayer ) {
			map.removeLayer( trackLayer );
		}
		var latlngs = points.map( function ( p ) { return [ p.lat, p.lon ]; } );
		trackLayer = L.polyline( latlngs, { color: '#e05c1e', weight: 3, opacity: 0.9 } ).addTo( map );
		map.invalidateSize();
		map.fitBounds( trackLayer.getBounds(), { padding: [ 20, 20 ] } );
	}

	// ── Stats row ───────────────────────────────────────────────────────────

	function fmt( v ) {
		return Number( v ).toLocaleString( 'bg-BG' );
	}

	function fillStats( d ) {
		var items = [];
		if ( d.distance ) { items.push( [ 'Дистанция', fmt( d.distance ) + ' км' ] ); }
		if ( d.ascent )   { items.push( [ 'Изкачване', 'D+ ' + fmt( d.ascent ) + ' м' ] ); }
		if ( d.descent )  { items.push( [ 'Спускане', 'D- ' + fmt( d.descent ) + ' м' ] ); }
		if ( d.lowest && d.highest ) { items.push( [ 'Височина', fmt( d.lowest ) + '–' + fmt( d.highest ) + ' м' ] ); }
		statsEl.innerHTML = items.map( function ( it ) {
			return '<span class="tsr-track__stat"><span class="tsr-track__stat-label">' + it[ 0 ] +
				'</span><span class="tsr-track__stat-value">' + it[ 1 ] + '</span></span>';
		} ).join( '' );
	}

	// ── Modal open / close ──────────────────────────────────────────────────

	function openModal( row ) {
		var d = row.dataset;
		lastFocus = row;

		hideChartHover();
		chartState = null;

		titleEl.textContent = d.title || '';
		fillStats( d );

		gpxBtn.hidden = ! d.gpx;
		if ( d.gpx ) { gpxBtn.href = d.gpx; }
		kmlBtn.hidden = ! d.kml;
		if ( d.kml ) { kmlBtn.href = d.kml; }

		modal.hidden = false;
		document.body.classList.add( 'tsr-modal-open' );
		modal.querySelector( '.tsr-modal__close' ).focus();

		if ( ! d.gpx ) {
			chartWrap.hidden = true;
			document.getElementById( 'tsr-modal-map' ).hidden = true;
			return;
		}
		document.getElementById( 'tsr-modal-map' ).hidden = false;

		if ( gpxCache[ d.gpx ] ) {
			drawMap( gpxCache[ d.gpx ] );
			drawChart( gpxCache[ d.gpx ] );
			return;
		}
		fetch( d.gpx )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
				return r.text();
			} )
			.then( function ( text ) {
				var points = parseGpx( text );
				if ( ! points.length ) { throw new Error( 'no trkpt' ); }
				gpxCache[ d.gpx ] = points;
				// Ignore a stale response if another track was opened meanwhile.
				if ( ! modal.hidden && titleEl.textContent === d.title ) {
					drawMap( points );
					drawChart( points );
				}
			} )
			.catch( function () {
				document.getElementById( 'tsr-modal-map' ).hidden = true;
				chartWrap.hidden = true;
			} );
	}

	function closeModal() {
		hideChartHover();
		modal.hidden = true;
		document.body.classList.remove( 'tsr-modal-open' );
		if ( lastFocus ) {
			lastFocus.focus();
			lastFocus = null;
		}
	}

	// ── Events ──────────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( ev ) {
		var closer = ev.target.closest( '[data-close]' );
		if ( closer && modal.contains( closer ) ) {
			closeModal();
			return;
		}
		if ( ev.target.closest( '.tsr-track__gpx' ) ) {
			return; // download links keep their default behaviour
		}
		var row = ev.target.closest( '.tsr-track[data-title]' );
		if ( row ) {
			openModal( row );
		}
	} );

	document.addEventListener( 'keydown', function ( ev ) {
		if ( modal.hidden ) {
			// Open with Enter/Space when a track row is focused.
			if ( ( ev.key === 'Enter' || ev.key === ' ' ) &&
					document.activeElement &&
					document.activeElement.matches( '.tsr-track[data-title]' ) ) {
				ev.preventDefault();
				openModal( document.activeElement );
			}
			return;
		}
		if ( ev.key === 'Escape' ) {
			closeModal();
		}
	} );
}());
