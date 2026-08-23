/**
 * Трасета — track detail modal.
 *
 * Click a track row → modal with a Leaflet map (full-resolution GPX
 * polyline) and an SVG elevation profile, both colored by local grade —
 * climbs red, descents green, flat blue (classifyGrade(), ~100 m smoothing
 * window) — plus stats, discreet per-km markers/ticks on both, and GPX/KML
 * downloads. GPX files are fetched and parsed lazily on first open, cached.
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
	var stravaBtn = document.getElementById( 'tsr-modal-strava' );
	var statusEl  = document.getElementById( 'tsr-modal-status' );

	/**
	 * Fetch status line: 'loading' (spinner + text), 'error' (message), or
	 * null to hide. Lives above the map so the dialog never sits silently
	 * empty while a large GPX downloads on a slow connection.
	 */
	function setStatus( state ) {
		if ( ! statusEl ) {
			return;
		}
		if ( ! state ) {
			statusEl.hidden = true;
			statusEl.innerHTML = '';
			return;
		}
		statusEl.hidden = false;
		statusEl.className = 'tsr-modal__status tsr-modal__status--' + state;
		statusEl.innerHTML = 'loading' === state
			? '<span class="tsr-spinner" aria-hidden="true"></span> Зареждане на трасето…'
			: 'Трасето не можа да се зареди. Провери връзката и опитай пак — или свали GPX файла директно.';
	}

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
	// Discreet per-km distance reference — deliberately much quieter than
	// the hover marker (small, low-opacity, no permanent label; "N км"
	// shows in a tooltip on hover only). Styles inlined for the same
	// pre-stylesheet-load resilience as hoverIcon above.
	var kmIcon = L.divIcon( {
		className: 'tsr-km-marker-wrap',
		html:
			'<span class="tsr-km-marker" style="display:block;width:7px;height:7px;' +
			'border-radius:50%;background:#fff;border:1.5px solid #0a1628;opacity:.75;' +
			'box-sizing:border-box;"></span>',
		iconSize:   [ 7, 7 ],
		iconAnchor: [ 3.5, 3.5 ]
	} );
	var gpxCache   = {}; // url → parsed points [{lat, lon, ele, dist}]
	var lastFocus  = null;
	var chartState = null; // geometry + points needed to map hover x → track point

	// ── GPX parsing ─────────────────────────────────────────────────────────

	// ── Slope classification (изкачване / равно / спускане) ────────────────
	//
	// Point-to-point grade is too noisy to color by directly — ordinary GPS
	// elevation jitter flickers between up/down on flat ground. Classify by
	// the grade across a centered ~300 m window instead, so a segment only
	// counts as a climb/descent once it's sustained for a real stretch — a
	// gently undulating trail can still cross the grade threshold every few
	// dozen metres even smoothed this way, so smoothRuns() below also folds
	// any short-lived run into its longer neighbor.

	var GRADE_WINDOW_M  = 300;
	var GRADE_UP_PCT    = 3;
	var GRADE_DOWN_PCT  = -3;
	var MIN_RUN_SPAN_M  = 250;

	var COLOR_CLIMB   = '#dc2626'; // var(--tsr-climb) in style.css — kept in
	var COLOR_DESCENT = '#16a34a'; // sync manually; Leaflet's SVG renderer
	var COLOR_FLAT    = '#00aadd'; // sets `stroke` as a plain attribute, not
	                                // a style property, so var() won't resolve there.

	function classColor( cls ) {
		return 'up' === cls ? COLOR_CLIMB : ( 'down' === cls ? COLOR_DESCENT : COLOR_FLAT );
	}

	/**
	 * Classify every point in a dist-sorted array as 'up' | 'down' | 'flat'
	 * by the elevation change across a centered window (metres) around it.
	 *
	 * @param {Array<{dist:number, ele:number}>} points
	 * @param {number} windowM
	 * @return {string[]} One class per input point, same order/length.
	 */
	function classifyGrade( points, windowM ) {
		var n = points.length;
		var classes = new Array( n );
		var lo = 0, hi = 0;
		for ( var i = 0; i < n; i++ ) {
			var d = points[ i ].dist;
			while ( lo < i && d - points[ lo ].dist > windowM / 2 ) { lo++; }
			while ( hi < n - 1 && points[ hi + 1 ].dist - d <= windowM / 2 ) { hi++; }
			var span  = points[ hi ].dist - points[ lo ].dist;
			var grade = span > 5 ? ( ( points[ hi ].ele - points[ lo ].ele ) / span ) * 100 : 0;
			classes[ i ] = grade >= GRADE_UP_PCT ? 'up' : ( grade <= GRADE_DOWN_PCT ? 'down' : 'flat' );
		}
		return classes;
	}

	/**
	 * Group a list of { ..., cls } items into contiguous same-class runs.
	 * Each run (after the first) starts by repeating the previous run's
	 * last item, so adjacent segments share a boundary point and draw with
	 * no visual gap between them.
	 *
	 * @param {Array<Object>} items Each item must carry a `cls` property.
	 * @return {Array<{cls: string, items: Array<Object>}>}
	 */
	function groupRuns( items ) {
		var runs  = [];
		var start = 0;
		for ( var i = 1; i < items.length; i++ ) {
			if ( items[ i ].cls !== items[ i - 1 ].cls ) {
				runs.push( { cls: items[ start ].cls, items: items.slice( start, i + 1 ) } );
				start = i;
			}
		}
		if ( start < items.length ) {
			runs.push( { cls: items[ start ].cls, items: items.slice( start ) } );
		}
		return runs;
	}

	/**
	 * Reclassify any contiguous run shorter than minSpanM (metres) to match
	 * whichever neighboring run is longer — without this, a trail that
	 * repeatedly crosses the grade threshold over short stretches (a series
	 * of small rollers) renders as visual noise: many thin alternating
	 * stripes instead of a few real climb/descent bands. Mutates `vertices`
	 * in place (rewrites `.cls`) and returns it. Two fixed passes catch runs
	 * that only become mergeable after an earlier pass absorbs a neighbor;
	 * fixed rather than loop-until-stable so it can never hang.
	 *
	 * @param {Array<{dist:number, cls:string}>} vertices
	 * @param {number} minSpanM
	 * @return {Array<Object>} The same array.
	 */
	function smoothRuns( vertices, minSpanM ) {
		for ( var pass = 0; pass < 2; pass++ ) {
			var runs = groupRuns( vertices );
			if ( runs.length < 2 ) { break; }
			runs.forEach( function ( run, r ) {
				var span = run.items[ run.items.length - 1 ].dist - run.items[ 0 ].dist;
				if ( span >= minSpanM ) { return; }
				var prev = runs[ r - 1 ], next = runs[ r + 1 ];
				var prevSpan = prev ? ( prev.items[ prev.items.length - 1 ].dist - prev.items[ 0 ].dist ) : -1;
				var nextSpan = next ? ( next.items[ next.items.length - 1 ].dist - next.items[ 0 ].dist ) : -1;
				var target = nextSpan > prevSpan ? ( next && next.cls ) : ( prev && prev.cls );
				if ( ! target ) { return; }
				var lo = run.items[ 0 ].dist, hi = run.items[ run.items.length - 1 ].dist;
				vertices.forEach( function ( v ) {
					if ( v.dist >= lo && v.dist <= hi ) { v.cls = target; }
				} );
			} );
		}
		return vertices;
	}

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

	function niceStep( totalKm, isMobile ) {
		if ( isMobile ) {
			if ( totalKm <= 4 )  { return 1; }
			if ( totalKm <= 10 ) { return 2; }
			if ( totalKm <= 20 ) { return 5; }
			if ( totalKm <= 40 ) { return 10; }
			return 20;
		}
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

		// Narrower viewBox on mobile: the SVG scales via width:100%, so at a
		// fixed 800-unit viewBox a ~340px-wide modal renders 11px labels at
		// ~4-5px — illegible. Halving the viewBox width doubles the effective
		// scale factor at the same screen size, and the CSS font-size bump
		// below (mobile media query) stacks with that for real legibility.
		var isMobile = window.matchMedia( '(max-width: 640px)' ).matches;
		var W = isMobile ? 440 : 800, H = 240;
		var padL = isMobile ? 50 : 46, padR = 14, padT = 14, padB = isMobile ? 34 : 28;
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

		// Downsample to ~400 points for the path, classifying each by local
		// grade so the profile draws as climb/flat/descent-colored segments
		// instead of one flat-colored fill.
		var step    = Math.max( 1, Math.floor( eles.length / 400 ) );
		var classes = classifyGrade( eles, GRADE_WINDOW_M );
		var vertices = [];
		for ( var i = 0; i < eles.length; i += step ) {
			vertices.push( { dist: eles[ i ].dist, ele: eles[ i ].ele, cls: classes[ i ] } );
		}
		var last = eles[ eles.length - 1 ];
		if ( vertices[ vertices.length - 1 ].dist !== last.dist ) {
			vertices.push( { dist: last.dist, ele: last.ele, cls: classes[ classes.length - 1 ] } );
		}
		smoothRuns( vertices, MIN_RUN_SPAN_M );

		var svg = '';

		// Horizontal gridlines + y labels (fewer on mobile — larger text needs
		// more vertical room to avoid overlapping).
		var ySteps = isMobile ? 3 : 4;
		for ( var g = 0; g <= ySteps; g++ ) {
			var e  = minE + ( maxE - minE ) * g / ySteps;
			var yy = y( e ).toFixed( 1 );
			svg += '<line x1="' + padL + '" y1="' + yy + '" x2="' + ( W - padR ) + '" y2="' + yy + '" class="tsr-chart__grid"/>';
			svg += '<text x="' + ( padL - 6 ) + '" y="' + yy + '" class="tsr-chart__ylabel">' + Math.round( e ) + '</text>';
		}

		// Discreet, unlabeled tick at every whole km — a subtle distance
		// reference denser than the labeled ticks below.
		for ( var dkm = 1; dkm < totalKm; dkm++ ) {
			var dxx = x( dkm * 1000 ).toFixed( 1 );
			svg += '<line x1="' + dxx + '" y1="' + ( padT + ih ) + '" x2="' + dxx + '" y2="' + ( padT + ih + 3 ) + '" class="tsr-chart__km-tick"/>';
		}

		// Labeled X ticks every niceStep km.
		var kmStep = niceStep( totalKm, isMobile );
		for ( var km = 0; km <= totalKm; km += kmStep ) {
			var xx = x( km * 1000 ).toFixed( 1 );
			svg += '<line x1="' + xx + '" y1="' + ( padT + ih ) + '" x2="' + xx + '" y2="' + ( padT + ih + 4 ) + '" class="tsr-chart__tick"/>';
			svg += '<text x="' + xx + '" y="' + ( H - 8 ) + '" class="tsr-chart__xlabel">' + km + ' км</text>';
		}

		groupRuns( vertices ).forEach( function ( run ) {
			var segLine = '';
			run.items.forEach( function ( v ) {
				segLine += ( segLine ? 'L' : 'M' ) + x( v.dist ).toFixed( 1 ) + ',' + y( v.ele ).toFixed( 1 );
			} );
			var segFirst = run.items[ 0 ];
			var segLast  = run.items[ run.items.length - 1 ];
			var segArea  = segLine + 'L' + x( segLast.dist ).toFixed( 1 ) + ',' + ( padT + ih ) +
				'L' + x( segFirst.dist ).toFixed( 1 ) + ',' + ( padT + ih ) + 'Z';
			var color = classColor( run.cls );
			svg += '<path d="' + segArea + '" style="fill:' + color + ';fill-opacity:.28;stroke:none;"/>';
			svg += '<path d="' + segLine + '" fill="none" class="tsr-chart__line" style="stroke:' + color + ';"/>';
		} );

		// Hover guide (vertical line + dot on the profile), updated on
		// mousemove without touching the rest of the markup.
		svg += '<line class="tsr-chart__hover-line" x1="0" y1="' + padT + '" x2="0" y2="' + ( padT + ih ) + '"/>';
		svg += '<circle class="tsr-chart__hover-dot" cx="0" cy="0" r="4"/>';

		chartEl.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
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

	/**
	 * Touch equivalent of handleChartMove — mousemove/mouseleave never fire
	 * on touch devices, so without this the chart/map hover-link is
	 * desktop-only. Scrubs the profile with a finger; preventDefault stops
	 * the page from scrolling vertically while dragging across the chart.
	 */
	function handleChartTouch( ev ) {
		if ( ! ev.touches || ! ev.touches.length ) {
			return;
		}
		ev.preventDefault();
		var touch = ev.touches[ 0 ];
		handleChartMove( { clientX: touch.clientX, clientY: touch.clientY } );
	}

	chartEl.addEventListener( 'touchstart', handleChartTouch, { passive: false } );
	chartEl.addEventListener( 'touchmove', handleChartTouch, { passive: false } );
	chartEl.addEventListener( 'touchend', hideChartHover );
	chartEl.addEventListener( 'touchcancel', hideChartHover );

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
		trackLayer = L.featureGroup();

		// Color the polyline itself by grade, same climb/flat/descent rule
		// and window as the elevation profile. Falls back to a single flat-
		// colored line when the GPX carries no elevation at all.
		var elePoints = points.filter( function ( p ) { return p.ele !== null; } );
		if ( elePoints.length >= 2 ) {
			var classes  = classifyGrade( elePoints, GRADE_WINDOW_M );
			var vertices = elePoints.map( function ( p, i ) {
				return { lat: p.lat, lon: p.lon, dist: p.dist, cls: classes[ i ] };
			} );
			smoothRuns( vertices, MIN_RUN_SPAN_M );
			groupRuns( vertices ).forEach( function ( run ) {
				var latlngs = run.items.map( function ( v ) { return [ v.lat, v.lon ]; } );
				L.polyline( latlngs, { color: classColor( run.cls ), weight: 3, opacity: 0.9 } ).addTo( trackLayer );
			} );
		} else {
			var flatLatlngs = points.map( function ( p ) { return [ p.lat, p.lon ]; } );
			L.polyline( flatLatlngs, { color: COLOR_FLAT, weight: 3, opacity: 0.9 } ).addTo( trackLayer );
		}

		// Discreet distance markers, one per whole km (endpoints excluded —
		// the start/finish are already obvious on the map).
		var totalM = points[ points.length - 1 ].dist;
		for ( var km = 1; km * 1000 < totalM; km++ ) {
			var pt = pointAtDistance( points, km * 1000 );
			L.marker( [ pt.lat, pt.lon ], { icon: kmIcon, keyboard: false } )
				.bindTooltip( km + ' км', { direction: 'top', opacity: 0.9 } )
				.addTo( trackLayer );
		}

		trackLayer.addTo( map );
		map.invalidateSize();
		map.fitBounds( trackLayer.getBounds(), { padding: [ 20, 20 ] } );
	}

	// ── Title / stats row ───────────────────────────────────────────────────
	//
	// Same icon + label/value markup as tsr_track_row()'s stat cells
	// (page-traseta.php) — the modal reuses .tsr-track__meta's grid, so both
	// views need matching structure or the CSS grid has nothing to align.

	var STAT_ICON_PATHS = {
		distance:  '<circle cx="4" cy="12" r="2"/><circle cx="20" cy="12" r="2"/><path d="M6 12h12" stroke-dasharray="3 3"/>',
		ascent:    '<path d="M4 20 20 4M20 4H10M20 4v10"/>',
		descent:   '<path d="M4 4 20 20M20 20H10M20 20V10"/>',
		elevation: '<path d="M3 20 9 8l4 6 2-3 6 9H3z"/>'
	};

	function statIcon( key ) {
		return '<svg class="tsr-track__stat-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"' +
			' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			STAT_ICON_PATHS[ key ] + '</svg>';
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function fmt( v ) {
		return Number( v ).toLocaleString( 'bg-BG' );
	}

	function fillStats( d ) {
		var items = [];
		if ( d.distance ) { items.push( [ 'distance', 'Дистанция', fmt( d.distance ) + ' км' ] ); }
		if ( d.ascent )   { items.push( [ 'ascent', 'Изкачване', 'D+ ' + fmt( d.ascent ) + ' м' ] ); }
		if ( d.descent )  { items.push( [ 'descent', 'Спускане', 'D- ' + fmt( d.descent ) + ' м' ] ); }
		if ( d.lowest && d.highest ) { items.push( [ 'elevation', 'Височина', fmt( d.lowest ) + '–' + fmt( d.highest ) + ' м' ] ); }
		statsEl.innerHTML = items.map( function ( it ) {
			return '<span class="tsr-track__stat">' + statIcon( it[ 0 ] ) +
				'<span class="tsr-track__stat-body"><span class="tsr-track__stat-label">' + it[ 1 ] +
				'</span><span class="tsr-track__stat-value">' + it[ 2 ] + '</span></span></span>';
		} ).join( '' );
	}

	/**
	 * Modal heading: muted event name + "– variant" in the title's own
	 * navy/800. The variant comes precomputed from tracks.json (normalized
	 * "6 км" spelling, same as the list rows); the title-string split is
	 * only the fallback for rows rendered before the variant field existed.
	 */
	function buildTitleHtml( title, eventName, variant ) {
		var rest = variant || '';
		if ( ! rest ) {
			if ( ! eventName || title.indexOf( eventName ) !== 0 ) {
				return escapeHtml( title );
			}
			rest = title.slice( eventName.length ).trim().replace( /^[-–—]\s*/, '' );
		}
		if ( ! eventName || ! rest ) {
			return escapeHtml( title );
		}
		return '<span class="tsr-modal-title__event">' + escapeHtml( eventName ) + '</span>' +
			' <span class="tsr-modal-title__dist">– ' + escapeHtml( rest ) + '</span>';
	}

	// ── Modal open / close ──────────────────────────────────────────────────

	var openTitle = null; // raw d.title of the currently-open track — stale-fetch guard.

	function openModal( row ) {
		var d = row.dataset;
		lastFocus = row;
		openTitle = d.title || '';

		hideChartHover();
		chartState = null;

		titleEl.innerHTML = buildTitleHtml( openTitle, d.event || '', d.variant || '' );
		fillStats( d );

		gpxBtn.hidden = ! d.gpx;
		if ( d.gpx ) { gpxBtn.href = d.gpx; }
		kmlBtn.hidden = ! d.kml;
		if ( d.kml ) { kmlBtn.href = d.kml; }
		stravaBtn.hidden = ! d.strava;
		if ( d.strava ) { stravaBtn.href = d.strava; }

		modal.hidden = false;
		document.body.classList.add( 'tsr-modal-open' );
		modal.querySelector( '.tsr-modal__close' ).focus();

		var mapEl = document.getElementById( 'tsr-modal-map' );
		if ( ! d.gpx ) {
			setStatus( null );
			chartWrap.hidden = true;
			mapEl.hidden = true;
			return;
		}

		if ( gpxCache[ d.gpx ] ) {
			setStatus( null );
			mapEl.hidden = false;
			drawMap( gpxCache[ d.gpx ] );
			drawChart( gpxCache[ d.gpx ] );
			return;
		}

		// Hide the map/chart while fetching — the map object persists between
		// opens, so leaving it visible would show the PREVIOUS track's map
		// under this track's title until the new GPX arrives.
		setStatus( 'loading' );
		mapEl.hidden = true;
		chartWrap.hidden = true;

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
				if ( ! modal.hidden && openTitle === d.title ) {
					setStatus( null );
					// Unhide BEFORE drawing — Leaflet's invalidateSize/fitBounds
					// need real dimensions, and a hidden container has none.
					mapEl.hidden = false;
					drawMap( points );
					drawChart( points );
				}
			} )
			.catch( function () {
				if ( ! modal.hidden && openTitle === d.title ) {
					setStatus( 'error' );
					mapEl.hidden = true;
					chartWrap.hidden = true;
				}
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
