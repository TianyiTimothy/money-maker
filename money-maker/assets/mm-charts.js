/**
 * Questrade Tracker & Tax Assistant — tiny inline-SVG charts (Milestone 3e).
 *
 * No dependencies, no network. Reads window.mmCharts (set via wp_localize_script)
 * and renders one chart into each matching [data-mm-chart="<key>"] container:
 *
 *   mmCharts.<key> = {
 *     type: 'line' | 'bar',
 *     points: [ { x: 'YYYY-MM-DD', y: Number }, ... ]   // line
 *     bars:   [ { label: String, value: Number }, ... ] // bar
 *     unit:   'US$' (optional currency prefix for value labels; the minus sign
 *                   is placed before it, not after)
 *   }
 */
( function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var W = 720;
	var H = 240;
	var PAD = { top: 16, right: 16, bottom: 28, left: 56 };

	function el( name, attrs, text ) {
		var node = document.createElementNS( SVG_NS, name );
		for ( var key in attrs ) {
			if ( Object.prototype.hasOwnProperty.call( attrs, key ) ) {
				node.setAttribute( key, attrs[ key ] );
			}
		}
		if ( text !== undefined ) {
			node.appendChild( document.createTextNode( text ) );
		}
		return node;
	}

	function money( value, unit ) {
		var rounded = Math.round( value * 100 ) / 100;
		var str = Math.abs( rounded ).toLocaleString( undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 } );
		// Sign outside the symbol: "-C$1,350", not "C$-1,350". Barely mattered
		// while the unit was a bare "$"; it reads wrong with a currency prefix.
		return ( rounded < 0 ? '-' : '' ) + ( unit || '' ) + str;
	}

	function niceTicks( min, max, count ) {
		if ( min === max ) {
			min -= 1;
			max += 1;
		}
		var span = max - min;
		var step = Math.pow( 10, Math.floor( Math.log( span / count ) / Math.LN10 ) );
		var err = ( count * step ) / span;
		if ( err <= 0.15 ) {
			step *= 10;
		} else if ( err <= 0.35 ) {
			step *= 5;
		} else if ( err <= 0.75 ) {
			step *= 2;
		}
		var ticks = [];
		var start = Math.floor( min / step ) * step;
		for ( var v = start; v <= max + step * 0.5; v += step ) {
			ticks.push( Math.round( v * 1e6 ) / 1e6 );
		}
		return ticks;
	}

	function baseSvg() {
		var svg = el( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			preserveAspectRatio: 'xMidYMid meet',
			role: 'img'
		} );
		return svg;
	}

	function drawAxes( svg, ticks, yScale, unit ) {
		ticks.forEach( function ( t ) {
			var y = yScale( t );
			svg.appendChild( el( 'line', {
				x1: PAD.left, y1: y, x2: W - PAD.right, y2: y,
				class: 'mm-chart__grid'
			} ) );
			svg.appendChild( el( 'text', {
				x: PAD.left - 8, y: y + 4, 'text-anchor': 'end',
				class: 'mm-chart__label'
			}, money( t, unit ) ) );
		} );
	}

	function renderLine( container, data ) {
		var points = ( data.points || [] ).filter( function ( p ) {
			return p && isFinite( p.y );
		} );

		if ( points.length < 2 ) {
			container.textContent = container.getAttribute( 'data-mm-empty' ) || 'Not enough history yet.';
			return;
		}

		var svg = baseSvg();
		var ys = points.map( function ( p ) { return p.y; } );
		var yMin = Math.min.apply( null, ys.concat( [ 0 ] ) );
		var yMax = Math.max.apply( null, ys );
		var ticks = niceTicks( yMin, yMax, 4 );
		yMin = Math.min( yMin, ticks[ 0 ] );
		yMax = Math.max( yMax, ticks[ ticks.length - 1 ] );

		var xScale = function ( i ) {
			return PAD.left + ( i / ( points.length - 1 ) ) * ( W - PAD.left - PAD.right );
		};
		var yScale = function ( v ) {
			return H - PAD.bottom - ( ( v - yMin ) / ( yMax - yMin ) ) * ( H - PAD.top - PAD.bottom );
		};

		drawAxes( svg, ticks, yScale, data.unit );

		var d = points.map( function ( p, i ) {
			return ( i === 0 ? 'M' : 'L' ) + xScale( i ) + ' ' + yScale( p.y );
		} ).join( ' ' );

		var area = d + ' L' + xScale( points.length - 1 ) + ' ' + yScale( yMin ) +
			' L' + xScale( 0 ) + ' ' + yScale( yMin ) + ' Z';

		svg.appendChild( el( 'path', { d: area, class: 'mm-chart__area' } ) );
		svg.appendChild( el( 'path', { d: d, class: 'mm-chart__line' } ) );

		points.forEach( function ( p, i ) {
			var dot = el( 'circle', { cx: xScale( i ), cy: yScale( p.y ), r: 3, class: 'mm-chart__dot' } );
			dot.appendChild( el( 'title', {}, p.x + ' · ' + money( p.y, data.unit ) ) );
			svg.appendChild( dot );
		} );

		// First / mid / last x labels.
		[ 0, Math.floor( ( points.length - 1 ) / 2 ), points.length - 1 ].forEach( function ( i, n, arr ) {
			if ( arr.indexOf( i ) !== n ) {
				return;
			}
			svg.appendChild( el( 'text', {
				x: xScale( i ), y: H - PAD.bottom + 18,
				'text-anchor': n === 0 ? 'start' : ( n === arr.length - 1 ? 'end' : 'middle' ),
				class: 'mm-chart__label'
			}, points[ i ].x ) );
		} );

		container.innerHTML = '';
		container.appendChild( svg );
	}

	function renderBar( container, data ) {
		var bars = ( data.bars || [] ).filter( function ( b ) {
			return b && isFinite( b.value );
		} );

		if ( ! bars.length ) {
			container.textContent = container.getAttribute( 'data-mm-empty' ) || 'Nothing to chart yet.';
			return;
		}

		var svg = baseSvg();
		var values = bars.map( function ( b ) { return b.value; } );
		var yMin = Math.min.apply( null, values.concat( [ 0 ] ) );
		var yMax = Math.max.apply( null, values.concat( [ 0 ] ) );
		var ticks = niceTicks( yMin, yMax, 4 );
		yMin = Math.min( yMin, ticks[ 0 ] );
		yMax = Math.max( yMax, ticks[ ticks.length - 1 ] );

		var yScale = function ( v ) {
			return H - PAD.bottom - ( ( v - yMin ) / ( yMax - yMin ) ) * ( H - PAD.top - PAD.bottom );
		};

		drawAxes( svg, ticks, yScale, data.unit );

		var slot = ( W - PAD.left - PAD.right ) / bars.length;
		var barW = Math.min( 64, slot * 0.6 );
		var zeroY = yScale( 0 );

		bars.forEach( function ( b, i ) {
			var cx = PAD.left + slot * ( i + 0.5 );
			var y = yScale( b.value );
			var rect = el( 'rect', {
				x: cx - barW / 2,
				y: Math.min( y, zeroY ),
				width: barW,
				height: Math.max( 1, Math.abs( y - zeroY ) ),
				class: 'mm-chart__bar ' + ( b.value < 0 ? 'is-neg' : 'is-pos' )
			} );
			rect.appendChild( el( 'title', {}, b.label + ' · ' + money( b.value, data.unit ) ) );
			svg.appendChild( rect );

			svg.appendChild( el( 'text', {
				x: cx, y: H - PAD.bottom + 18, 'text-anchor': 'middle', class: 'mm-chart__label'
			}, b.label ) );
		} );

		svg.appendChild( el( 'line', {
			x1: PAD.left, y1: zeroY, x2: W - PAD.right, y2: zeroY, class: 'mm-chart__grid is-zero'
		} ) );

		container.innerHTML = '';
		container.appendChild( svg );
	}

	function init() {
		var store = window.mmCharts || {};
		var nodes = document.querySelectorAll( '[data-mm-chart]' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			var key = node.getAttribute( 'data-mm-chart' );
			var data = store[ key ];
			if ( ! data ) {
				return;
			}
			if ( 'bar' === data.type ) {
				renderBar( node, data );
			} else {
				renderLine( node, data );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
