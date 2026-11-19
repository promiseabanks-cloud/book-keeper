/* The Bookkeeper reader. Vanilla JS, no build step. */
( function () {
	'use strict';
	var C = window.BK;
	var d = document;
	var app = d.getElementById( 'bk-app' );
	if ( ! C || ! app ) { return; }

	var COLORS = [
		{ k: 'yellow', n: 'Yellow' },
		{ k: 'green', n: 'Green' },
		{ k: 'blue', n: 'Blue' },
		{ k: 'pink', n: 'Pink' },
		{ k: 'purple', n: 'Purple' }
	];
	var S = { book: null, sec: null, route: null, marks: [], entries: {}, active: 0, lastTick: Date.now(), busy: false };

	/* ---------- helpers ---------- */
	function h( tag, attrs, kids ) {
		var e = d.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v === null || v === undefined || v === false ) { return; }
				if ( k === 'class' ) { e.className = v; }
				else if ( k === 'text' ) { e.textContent = v; }
				else if ( k === 'html' ) { e.innerHTML = v; }
				else if ( k.slice( 0, 2 ) === 'on' ) { e.addEventListener( k.slice( 2 ), v ); }
				else { e.setAttribute( k, v === true ? '' : v ); }
			} );
		}
		( kids || [] ).forEach( function ( c ) { if ( c ) { e.appendChild( typeof c === 'string' ? d.createTextNode( c ) : c ); } } );
		return e;
	}
	function icon( name ) {
		var p = {
			menu: 'M4 7h16M4 12h16M4 17h10',
			home: 'M4 11l8-7 8 7M6 9.5V20h4.5v-5.5h3V20H18V9.5',
			out: 'M14 4h5v16h-5M10 8l-4 4 4 4M6 12h11',
			ext: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
			close: 'M6 6l12 12M18 6L6 18',
			left: 'M15 6l-6 6 6 6',
			right: 'M9 6l6 6-6 6',
			note: 'M5 4h14v11l-5 5H5zM14 20v-5h5',
			lock: 'M7 11V8a5 5 0 0110 0v3M6 11h12v9H6z',
			check: 'M5 12.5l4.5 4.5L19 7',
			trash: 'M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13',
			book: 'M5 4h9a4 4 0 014 4v12H9a4 4 0 01-4-4zM5 16a4 4 0 014-4h9',
			spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6',
			copy: 'M8 8h11v12H8zM5 16V4h11',
			cal: 'M4 6h16v14H4zM4 10h16M8 3v5M16 3v5',
			flame: 'M12 3c1 4 5 5.5 5 10a5 5 0 01-10 0c0-2.5 1.5-4 2.5-5 .3 1.6 1 2.6 2 3 0-3-1-5.5.5-8z',
			clock: 'M12 7v5l3 2M12 21a9 9 0 110-18 9 9 0 010 18z'
		}[ name ];
		var s = d.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		s.setAttribute( 'viewBox', '0 0 24 24' );
		s.setAttribute( 'aria-hidden', 'true' );
		s.setAttribute( 'class', 'bk-i' );
		var path = d.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', p );
		s.appendChild( path );
		return s;
	}
	function api( method, path, body ) {
		var opt = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if ( body ) { opt.headers[ 'Content-Type' ] = 'application/json'; opt.body = JSON.stringify( body ); }
		return fetch( C.rest + path, opt ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) { var e = new Error( j.message || 'Something went wrong.' ); e.code = j.code; e.status = r.status; throw e; }
				return j;
			} );
		} );
	}
	var toastT;
	function toast( msg ) {
		var t = d.querySelector( '.bk-toast' ) || d.body.appendChild( h( 'div', { class: 'bk-toast', role: 'status' } ) );
		t.textContent = msg;
		t.classList.add( 'is-on' );
		clearTimeout( toastT );
		toastT = setTimeout( function () { t.classList.remove( 'is-on' ); }, 2600 );
	}
	function debounce( fn, ms ) {
		var t;
		return function () { var a = arguments; clearTimeout( t ); t = setTimeout( function () { fn.apply( null, a ); }, ms ); };
	}
	function chapter( id ) { return S.book.chapters.filter( function ( c ) { return c.id === id; } )[ 0 ]; }
	function flat() {
		var out = [];
		S.book.chapters.forEach( function ( c ) { c.sections.forEach( function ( s, i ) { out.push( { c: c, i: i, s: s } ); } ); } );
		return out;
	}
	function isDone( c, i ) { return S.book.done.indexOf( c + ':' + i ) > -1; }
	function pct() {
		var all = flat();
		return all.length ? Math.round( 100 * all.filter( function ( x ) { return isDone( x.c.id, x.i ); } ).length / all.length ) : 0;
	}
	function go( c, i ) { location.hash = '#/c/' + c + '/' + i; }
	var still = window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* progress wheel: an SVG ring that sweeps to its value */
	function ring( p, size, label ) {
		var r = 44, c = 2 * Math.PI * r;
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 100 100' );
		svg.setAttribute( 'class', 'bk-ring-svg' );
		svg.setAttribute( 'aria-hidden', 'true' );
		[ 'bk-ring-track', 'bk-ring-val' ].forEach( function ( cls ) {
			var o = d.createElementNS( ns, 'circle' );
			o.setAttribute( 'cx', 50 ); o.setAttribute( 'cy', 50 ); o.setAttribute( 'r', r );
			o.setAttribute( 'class', cls );
			if ( cls === 'bk-ring-val' ) {
				o.style.strokeDasharray = c;
				o.style.strokeDashoffset = c;
				requestAnimationFrame( function () { requestAnimationFrame( function () { o.style.strokeDashoffset = c * ( 1 - p / 100 ); } ); } );
			}
			svg.appendChild( o );
		} );
		var num = h( 'strong', { text: '0%' } );
		count( num, p, '%' );
		return h( 'div', { class: 'bk-ring', style: '--s:' + size + 'px', role: 'img', 'aria-label': p + '% ' + ( label || 'read' ) }, [ svg, h( 'div', { class: 'bk-ring-in' }, [ num, label ? h( 'span', { text: label } ) : null ] ) ] );
	}
	/* numbers that count up to their value */
	function count( el, n, suffix ) {
		suffix = suffix || '';
		if ( still || ! n ) { el.textContent = n + suffix; return; }
		var t0 = null, dur = 900;
		function step( t ) {
			if ( ! t0 ) { t0 = t; }
			var k = Math.min( 1, ( t - t0 ) / dur );
			el.textContent = Math.round( n * ( 1 - Math.pow( 1 - k, 3 ) ) ) + suffix;
			if ( k < 1 ) { requestAnimationFrame( step ); }
		}
		requestAnimationFrame( step );
	}
	/* stagger children in as they arrive */
	function rise( root ) {
		if ( still ) { return; }
		var els = root.querySelectorAll( '[data-rise]' );
		els.forEach( function ( el, n ) { el.style.setProperty( '--d', ( n * 60 ) + 'ms' ); } );
		if ( ! ( 'IntersectionObserver' in window ) ) { els.forEach( function ( el ) { el.classList.add( 'is-in' ); } ); return; }
		var io = new IntersectionObserver( function ( list ) {
			list.forEach( function ( x ) { if ( x.isIntersecting ) { x.target.classList.add( 'is-in' ); io.unobserve( x.target ); } } );
		}, { rootMargin: '0px 0px -8% 0px' } );
		els.forEach( function ( el ) { io.observe( el ); } );
	}
	/* a date field that looks like a button and opens the native picker */
	function dateField( value, onChange ) {
		var inp = h( 'input', { type: 'date', value: value || '', 'aria-label': 'Due date (optional)' } );
		var txt = h( 'span', { class: 'bk-date-t' } );
		function show() { txt.textContent = inp.value ? niceDate( inp.value ) : 'Add a date'; wrap.classList.toggle( 'has-value', !! inp.value ); }
		var wrap = h( 'label', { class: 'bk-date' }, [ icon( 'cal' ), txt, inp ] );
		inp.addEventListener( 'change', function () { show(); if ( onChange ) { onChange( inp.value ); } } );
		inp.addEventListener( 'click', function () { try { inp.showPicker(); } catch ( e ) {} } );
		show();
		wrap.input = inp;
		return wrap;
	}
	function niceDate( iso ) {
		var p = iso.split( '-' );
		var dt = new Date( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] );
		return dt.toLocaleDateString( undefined, { weekday: 'short', day: 'numeric', month: 'short' } );
	}
	/* add an action point to a calendar: Google by link, Apple and Outlook by .ics */
	function calDates( e ) {
		var day = e.due ? e.due.replace( /-/g, '' ) : null;
		if ( ! day ) { var t = new Date( Date.now() + 864e5 ); day = t.getFullYear() + String( t.getMonth() + 1 ).padStart( 2, '0' ) + String( t.getDate() ).padStart( 2, '0' ); }
		var n = new Date( +day.slice( 0, 4 ), +day.slice( 4, 6 ) - 1, +day.slice( 6, 8 ) + 1 );
		return [ day, n.getFullYear() + String( n.getMonth() + 1 ).padStart( 2, '0' ) + String( n.getDate() ).padStart( 2, '0' ) ];
	}
	function calLinks( e ) {
		var ds = calDates( e );
		var title = e.content;
		var details = 'Action point from ' + C.title + '. Open the book: ' + location.origin + location.pathname;
		var g = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + encodeURIComponent( title ) + '&dates=' + ds[ 0 ] + '/' + ds[ 1 ] + '&details=' + encodeURIComponent( details );
		var ics = function () {
			var esc = function ( s ) { return String( s ).replace( /[\\;,]/g, '\\$&' ).replace( /\n/g, '\\n' ); };
			var body = [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Bookkeeper//EN', 'BEGIN:VEVENT', 'UID:bk-' + e.id + '@' + location.host, 'DTSTAMP:' + new Date().toISOString().replace( /[-:]/g, '' ).slice( 0, 15 ) + 'Z', 'DTSTART;VALUE=DATE:' + ds[ 0 ], 'DTEND;VALUE=DATE:' + ds[ 1 ], 'SUMMARY:' + esc( title ), 'DESCRIPTION:' + esc( details ), 'BEGIN:VALARM', 'TRIGGER:PT9H', 'ACTION:DISPLAY', 'DESCRIPTION:' + esc( title ), 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR' ].join( '\r\n' );
			var a = h( 'a', { href: URL.createObjectURL( new Blob( [ body ], { type: 'text/calendar' } ) ), download: 'action-point.ics' } );
			d.body.appendChild( a ); a.click(); a.remove();
		};
		return h( 'div', { class: 'bk-cal' }, [
			h( 'a', { class: 'bk-cal-b', href: g, target: '_blank', rel: 'noopener' }, [ icon( 'cal' ), 'Google Calendar' ] ),
			h( 'button', { class: 'bk-cal-b', type: 'button', onclick: ics }, [ icon( 'cal' ), 'Apple or Outlook' ] )
		] );
	}
	function greet() {
		var hr = new Date().getHours();
		return hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
	}

	/* ---------- shell ---------- */
	var ui = {};
	function shell() {
		app.innerHTML = '';
		ui.bar = h( 'header', { class: 'bk-bar' }, [
			h( 'button', { class: 'bk-iconbtn', type: 'button', 'aria-label': 'Contents', title: 'Contents', onclick: function () { openDrawer( 'toc' ); } }, [ icon( 'menu' ) ] ),
			h( 'a', { class: 'bk-iconbtn bk-homebtn', href: '#/', 'aria-label': 'Your dashboard', title: 'Your dashboard' }, [ icon( 'home' ) ] ),
			h( 'a', { class: 'bk-bar-title', href: '#/' }, [
				C.logo ? h( 'img', { src: C.logo, alt: '', width: 28, height: 28 } ) : null,
				h( 'span', { class: 'bk-bar-book', text: C.title } )
			] ),
			ui.where = h( 'span', { class: 'bk-bar-where' } ),
			h( 'div', { class: 'bk-bar-right' }, [
				ui.aa = h( 'button', { class: 'bk-iconbtn bk-aa', type: 'button', 'aria-label': 'Reading settings', 'aria-expanded': 'false', onclick: function ( e ) { e.stopPropagation(); toggleDisplay(); } }, [ h( 'span', { text: 'A' } ), h( 'span', { text: 'a' } ) ] ),
				h( 'button', { class: 'bk-pill', type: 'button', onclick: function () { openDrawer( 'me' ); } }, [ icon( 'note' ), h( 'span', { text: 'My notes' } ) ] ),
				h( 'a', { class: 'bk-iconbtn', href: C.home, 'aria-label': 'Close the book' }, [ icon( 'close' ) ] )
			] ),
			ui.prog = h( 'span', { class: 'bk-bar-prog' }, [ h( 'i' ) ] )
		] );
		ui.main = h( 'main', { class: 'bk-main', id: 'bk-main', tabindex: '-1' } );
		ui.scrim = h( 'div', { class: 'bk-scrim', onclick: closeDrawer } );
		ui.toc = h( 'aside', { class: 'bk-drawer bk-drawer--left', 'aria-label': 'Contents' } );
		ui.me = h( 'aside', { class: 'bk-drawer bk-drawer--right', 'aria-label': 'My notes' } );
		ui.tools = h( 'div', { class: 'bk-tools bk-ui', role: 'toolbar', 'aria-label': 'Highlight' } );
		ui.sticky = h( 'div', { class: 'bk-sticky bk-ui', role: 'dialog', 'aria-label': 'Note' } );
		ui.wm = h( 'div', { class: 'bk-wm', 'aria-hidden': 'true' } );
		ui.disp = h( 'div', { class: 'bk-display', role: 'dialog', 'aria-label': 'Reading settings' } );
		[ ui.bar, ui.main, ui.scrim, ui.toc, ui.me, ui.tools, ui.sticky, ui.wm, ui.disp ].forEach( function ( x ) { app.appendChild( x ); } );
		watermark();
	}
	function watermark() {
		var t = ( C.mark || '' ).replace( /[<&>]/g, '' );
		var dark = prefs.mood === 'dark' || prefs.mood === 'night';
		var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="340" height="220"><text x="0" y="120" transform="rotate(-24 170 110)" font-family="sans-serif" font-size="13" fill="' + ( dark ? 'rgba(255,255,255,0.04)' : 'rgba(29,20,24,0.045)' ) + '">' + t + '</text></svg>';
		ui.wm.style.backgroundImage = 'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg ) + '")';
	}
	function setProgress() {
		ui.prog.firstChild.style.width = pct() + '%';
	}

	/* ---------- reading settings: mood, text size, typeface ---------- */
	var MOODS = [ [ 'paper', 'Paper' ], [ 'white', 'White' ], [ 'warm', 'Warm' ], [ 'dark', 'Dark' ], [ 'night', 'Night' ] ];
	var SIZES = [ 0.88, 1, 1.12, 1.24, 1.38 ];
	var prefs = ( function () {
		var p = {};
		try { p = JSON.parse( localStorage.getItem( 'bk-prefs' ) || '{}' ) || {}; } catch ( e ) {}
		if ( ! p.mood ) { p.mood = window.matchMedia && matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'paper'; }
		if ( SIZES.indexOf( p.size ) < 0 ) { p.size = 1; }
		if ( p.font !== 'serif' ) { p.font = 'sans'; }
		return p;
	}() );
	function applyPrefs( save ) {
		var r = d.documentElement;
		r.setAttribute( 'data-mood', prefs.mood );
		r.setAttribute( 'data-font', prefs.font );
		r.style.setProperty( '--bk-scale', prefs.size );
		var meta = d.querySelector( 'meta[name="theme-color"]' );
		if ( meta ) { meta.setAttribute( 'content', getComputedStyle( r ).getPropertyValue( '--bk-paper' ).trim() ); }
		if ( ui.wm ) { watermark(); }
		if ( save ) { try { localStorage.setItem( 'bk-prefs', JSON.stringify( prefs ) ); } catch ( e ) {} }
	}
	function renderDisplay() {
		ui.disp.innerHTML = '';
		var si = SIZES.indexOf( prefs.size );
		ui.disp.appendChild( h( 'p', { class: 'bk-display-k', text: 'Reading mood' } ) );
		ui.disp.appendChild( h( 'div', { class: 'bk-moods' }, MOODS.map( function ( m ) {
			return h( 'button', { type: 'button', class: 'bk-mood bk-mood--' + m[ 0 ], 'aria-pressed': prefs.mood === m[ 0 ] ? 'true' : 'false', onclick: function () { prefs.mood = m[ 0 ]; applyPrefs( true ); renderDisplay(); } }, [ h( 'i', { text: 'Aa' } ), m[ 1 ] ] );
		} ) ) );
		ui.disp.appendChild( h( 'p', { class: 'bk-display-k', text: 'Text size' } ) );
		var step = function ( dir ) { prefs.size = SIZES[ Math.min( SIZES.length - 1, Math.max( 0, si + dir ) ) ]; applyPrefs( true ); renderDisplay(); };
		ui.disp.appendChild( h( 'div', { class: 'bk-size' }, [
			h( 'button', { type: 'button', 'aria-label': 'Smaller text', disabled: si === 0, onclick: function () { step( -1 ); }, text: 'A' } ),
			h( 'span', { class: 'bk-steps', 'aria-hidden': 'true' }, SIZES.map( function ( x, k ) { return h( 'i', { class: k === si ? 'is-on' : '' } ); } ) ),
			h( 'button', { type: 'button', 'aria-label': 'Larger text', disabled: si === SIZES.length - 1, onclick: function () { step( 1 ); }, text: 'A' } )
		] ) );
		ui.disp.appendChild( h( 'p', { class: 'bk-display-k', text: 'Typeface' } ) );
		ui.disp.appendChild( h( 'div', { class: 'bk-fonts' }, [ [ 'sans', 'Sans' ], [ 'serif', 'Serif' ] ].map( function ( f ) {
			return h( 'button', { type: 'button', 'aria-pressed': prefs.font === f[ 0 ] ? 'true' : 'false', text: f[ 1 ], onclick: function () { prefs.font = f[ 0 ]; applyPrefs( true ); renderDisplay(); } } );
		} ) ) );
	}
	function toggleDisplay( force ) {
		var on = typeof force === 'boolean' ? force : ! ui.disp.classList.contains( 'is-on' );
		if ( on ) { closeTools(); closeDrawer(); renderDisplay(); }
		ui.disp.classList.toggle( 'is-on', on );
		ui.aa.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
	}
	applyPrefs( false );

	/* ---------- drawers ---------- */
	function openDrawer( which, tab ) {
		closeTools();
		if ( which === 'toc' ) { renderToc(); }
		else { renderMe( tab || 'marks' ); }
		app.classList.add( 'is-' + which );
		app.classList.add( 'is-drawer' );
		var el = which === 'toc' ? ui.toc : ui.me;
		setTimeout( function () { var f = el.querySelector( 'button, a' ); if ( f ) { f.focus(); } }, 260 );
	}
	function closeDrawer() { app.classList.remove( 'is-drawer', 'is-toc', 'is-me' ); }

	function renderToc() {
		ui.toc.innerHTML = '';
		ui.toc.appendChild( h( 'div', { class: 'bk-drawer-head' }, [
			h( 'div', null, [ h( 'p', { class: 'bk-drawer-title', text: C.title } ) ] ),
			h( 'button', { class: 'bk-iconbtn', type: 'button', 'aria-label': 'Close', onclick: closeDrawer }, [ icon( 'close' ) ] )
		] ) );
		ui.toc.appendChild( h( 'div', { class: 'bk-meter' }, [ h( 'span', { style: 'width:' + pct() + '%' } ), h( 'p', { text: pct() + '% read' } ) ] ) );
		var list = h( 'ol', { class: 'bk-toc' } );
		S.book.chapters.forEach( function ( c ) {
			var secs = h( 'ol', { class: 'bk-toc-secs' } );
			c.sections.forEach( function ( s, i ) {
				var here = S.route && S.route.c === c.id && S.route.i === i;
				secs.appendChild( h( 'li', null, [ h( 'a', {
					href: '#/c/' + c.id + '/' + i,
					class: ( isDone( c.id, i ) ? 'is-done ' : '' ) + ( here ? 'is-here' : '' ),
					'aria-current': here ? 'page' : null,
					onclick: closeDrawer
				}, [ h( 'span', { class: 'bk-tick' }, [ icon( 'check' ) ] ), h( 'span', { text: s.title } ), h( 'small', { text: s.minutes + ' min' } ) ] ) ] ) );
			} );
			list.appendChild( h( 'li', { class: 'bk-toc-ch' + ( c.locked ? ' is-locked' : '' ) }, [
				h( 'p', { class: 'bk-toc-num' }, [ 'Chapter ' + c.num, c.free && ! S.book.owns ? h( 'span', { class: 'bk-tag', text: 'Free' } ) : null, c.locked ? icon( 'lock' ) : null ] ),
				h( 'p', { class: 'bk-toc-title', text: c.title } ),
				secs
			] ) );
		} );
		ui.toc.appendChild( list );
		if ( ! S.book.owns ) {
			ui.toc.appendChild( h( 'a', { class: 'bk-btn bk-btn--solid bk-btn--block', href: '#/buy', onclick: closeDrawer, text: 'Unlock the full book' } ) );
		}
		ui.toc.appendChild( h( 'div', { class: 'bk-drawer-foot' }, [ h( 'a', { class: 'bk-btn bk-btn--out', href: C.account }, [ icon( 'home' ), 'My account' ] ), signOutBtn() ] ) );
	}

	/* ---------- my notes: highlights, journal, actions, progress ---------- */
	function renderMe( tab ) {
		ui.me.innerHTML = '';
		var tabs = [ [ 'marks', 'Highlights' ], [ 'journal', 'Journal' ], [ 'actions', 'Actions' ], [ 'progress', 'Progress' ], [ 'export', 'Save & sync' ], [ 'account', 'Account' ] ];
		ui.me.appendChild( h( 'div', { class: 'bk-drawer-head' }, [
			h( 'div', null, [ h( 'p', { class: 'bk-drawer-title', text: 'My notes' } ) ] ),
			h( 'button', { class: 'bk-iconbtn', type: 'button', 'aria-label': 'Close', onclick: closeDrawer }, [ icon( 'close' ) ] )
		] ) );
		ui.me.appendChild( h( 'div', { class: 'bk-tabs', role: 'tablist' }, tabs.map( function ( t ) {
			return h( 'button', { type: 'button', role: 'tab', 'aria-selected': t[ 0 ] === tab ? 'true' : 'false', text: t[ 1 ], onclick: function () { renderMe( t[ 0 ] ); } } );
		} ) ) );
		var body = h( 'div', { class: 'bk-me-body' }, [ h( 'p', { class: 'bk-muted', text: 'Loading…' } ) ] );
		ui.me.appendChild( body );
		var act = ui.me.querySelector( '.bk-tabs [aria-selected="true"]' );
		if ( act ) { act.parentNode.scrollLeft = act.offsetLeft - 24; }
		var fill = { marks: meMarks, journal: meJournal, actions: meActions, progress: meProgress, export: meExport, account: meAccount }[ tab ];
		fill( body );
	}
	function where( chId, sec ) {
		var c = chapter( chId );
		return c ? 'Chapter ' + c.num + ( c.sections[ sec ] ? ' · ' + c.sections[ sec ].title : '' ) : '';
	}
	function meMarks( body ) {
		api( 'GET', 'marks?book=' + C.book ).then( function ( rows ) {
			body.innerHTML = '';
			if ( ! rows.length ) {
				body.appendChild( empty( 'No highlights yet', 'Select any words while you read to highlight them and add a sticky note.' ) );
				return;
			}
			var filter = h( 'div', { class: 'bk-chips' } );
			var list = h( 'div', { class: 'bk-cards' } );
			var cur = '';
			function draw() {
				list.innerHTML = '';
				rows.filter( function ( r ) { return ! cur || r.color === cur; } ).forEach( function ( r ) {
					list.appendChild( h( 'article', { class: 'bk-card bk-card--' + r.color }, [
						h( 'p', { class: 'bk-card-meta', text: where( r.chapter, r.section ) } ),
						h( 'blockquote', { text: r.quote } ),
						r.note ? h( 'p', { class: 'bk-card-note' }, [ icon( 'note' ), h( 'span', { text: r.note } ) ] ) : null,
						h( 'a', { class: 'bk-link', href: '#/c/' + r.chapter + '/' + r.section + '/m' + r.id, onclick: closeDrawer, text: 'Go to this passage' } )
					] ) );
				} );
			}
			[ { k: '', n: 'All' } ].concat( COLORS ).forEach( function ( c ) {
				var b = h( 'button', { type: 'button', class: 'bk-chip' + ( c.k ? ' bk-chip--' + c.k : '' ), 'aria-pressed': c.k === cur ? 'true' : 'false', text: c.n, onclick: function () {
					cur = c.k;
					filter.querySelectorAll( 'button' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ); } );
					draw();
				} } );
				filter.appendChild( b );
			} );
			body.appendChild( filter );
			body.appendChild( list );
			draw();
		} ).catch( function ( e ) { body.innerHTML = ''; body.appendChild( empty( 'Could not load', e.message ) ); } );
	}
	function meJournal( body ) {
		Promise.all( [ api( 'GET', 'entries?book=' + C.book + '&kind=reflect' ), api( 'GET', 'entries?book=' + C.book + '&kind=journal' ), api( 'GET', 'entries?book=' + C.book + '&kind=summary' ) ] ).then( function ( r ) {
			var rows = r[ 0 ].concat( r[ 1 ], r[ 2 ] ).sort( function ( a, b ) { return a.updated < b.updated ? 1 : -1; } );
			body.innerHTML = '';
			var ta = h( 'textarea', { class: 'bk-field', rows: 3, placeholder: 'Write a journal entry…' } );
			body.appendChild( h( 'form', { class: 'bk-new', onsubmit: function ( e ) {
				e.preventDefault();
				if ( ! ta.value.trim() ) { return; }
				var ch = S.route ? S.route.c : 0;
				api( 'POST', 'entries', { book: C.book, chapter: ch, section: S.route ? S.route.i : 0, kind: 'journal', content: ta.value } ).then( function () { toast( 'Saved to your journal' ); meJournal( body ); } ).catch( function ( er ) { toast( er.message ); } );
			} }, [ ta, h( 'button', { class: 'bk-btn bk-btn--solid', type: 'submit', text: 'Add entry' } ) ] ) );
			if ( ! rows.length ) {
				body.appendChild( empty( 'Your journal is empty', 'Answers to the reflection questions in each chapter land here, with anything you write yourself.' ) );
				return;
			}
			var list = h( 'div', { class: 'bk-cards' } );
			rows.forEach( function ( x ) {
				list.appendChild( h( 'article', { class: 'bk-card' }, [
					h( 'p', { class: 'bk-card-meta', text: ( x.kind === 'reflect' ? 'Reflection · ' : x.kind === 'summary' ? 'Chapter summary · ' : 'Entry · ' ) + ( x.chapter ? where( x.chapter, x.section ) : new Date( x.updated.replace( ' ', 'T' ) + 'Z' ).toLocaleDateString() ) } ),
					x.prompt ? h( 'p', { class: 'bk-card-prompt', text: x.prompt } ) : null,
					h( 'p', { class: 'bk-card-text', text: x.content } ),
					x.chapter ? h( 'a', { class: 'bk-link', href: '#/c/' + x.chapter + '/' + x.section, onclick: closeDrawer, text: 'Open the section' } ) : null
				] ) );
			} );
			body.appendChild( list );
		} ).catch( function ( e ) { body.innerHTML = ''; body.appendChild( empty( 'Could not load', e.message ) ); } );
	}
	function meActions( body ) {
		api( 'GET', 'entries?book=' + C.book + '&kind=action' ).then( function ( rows ) {
			body.innerHTML = '';
			if ( ! rows.length ) {
				body.appendChild( empty( 'No action points yet', 'When a section asks what you will do next, your answer becomes an action point here.' ) );
				return;
			}
			var open = rows.filter( function ( r ) { return r.status !== 'done'; } ).length;
			body.appendChild( h( 'p', { class: 'bk-muted', text: open + ' open · ' + ( rows.length - open ) + ' done' } ) );
			var list = h( 'ul', { class: 'bk-actions' } );
			rows.sort( function ( a, b ) { return ( a.status === 'done' ) - ( b.status === 'done' ); } ).forEach( function ( r ) {
				var cb = h( 'input', { type: 'checkbox', checked: r.status === 'done', 'aria-label': 'Done' } );
				cb.addEventListener( 'change', function () {
					api( 'PATCH', 'entries/' + r.id, { status: cb.checked ? 'done' : 'open' } ).then( function () { li.classList.toggle( 'is-done', cb.checked ); if ( cb.checked ) { toast( 'Nice work. One step closer.' ); } } );
				} );
				var late = r.due && r.status !== 'done' && r.due < new Date().toISOString().slice( 0, 10 );
				var li = h( 'li', { class: r.status === 'done' ? 'is-done' : '' }, [
					h( 'label', { class: 'bk-check' }, [ cb, h( 'span', { class: 'bk-box' }, [ icon( 'check' ) ] ) ] ),
					h( 'div', null, [
						h( 'p', { class: 'bk-action-text', text: r.content } ),
						h( 'p', { class: 'bk-card-meta' + ( late ? ' is-late' : '' ), text: ( r.due ? ( late ? 'Overdue, was due ' : 'By ' ) + niceDate( r.due ) + '. ' : '' ) + where( r.chapter, r.section ) } ),
						r.status !== 'done' ? calLinks( r ) : null
					] )
				] );
				list.appendChild( li );
			} );
			body.appendChild( list );
		} ).catch( function ( e ) { body.innerHTML = ''; body.appendChild( empty( 'Could not load', e.message ) ); } );
	}
	function meProgress( body ) {
		api( 'GET', 'book/' + C.book ).then( function ( b ) {
			S.book = b;
			setProgress();
			body.innerHTML = '';
			var p = pct();
			var mins = Math.round( b.stats.seconds / 60 );
			var chDone = b.chapters.filter( function ( c ) { return c.sections.every( function ( s, i ) { return isDone( c.id, i ); } ); } ).length;
			body.appendChild( h( 'div', { class: 'bk-big' }, [ ring( p, 168, 'of the book read' ) ] ) );
			body.appendChild( h( 'div', { class: 'bk-stats' }, [
				stat( chDone + '/' + b.chapters.length, 'chapters finished' ),
				stat( mins < 60 ? mins + ' min' : ( mins / 60 ).toFixed( 1 ) + ' h', 'reading time' ),
				stat( b.stats.reflects, 'reflections' ),
				stat( b.stats.actions, 'action points' ),
				stat( b.stats.marks, 'highlights' ),
				stat( b.stats.journal, 'journal entries' )
			] ) );
			var rcb = h( 'input', { type: 'checkbox', checked: !! b.user.remind, 'aria-label': 'Daily reading reminder' } );
			rcb.addEventListener( 'change', function () {
				api( 'POST', 'prefs', { remind: rcb.checked } ).then( function ( r ) { b.user.remind = r.remind; toast( r.remind ? 'Reminders on' : 'Reminders off' ); } ).catch( function ( e ) { rcb.checked = ! rcb.checked; toast( e.message ); } );
			} );
			body.appendChild( h( 'div', { class: 'bk-remind' }, [
				h( 'label', { class: 'bk-check' }, [ rcb, h( 'span', { class: 'bk-box' }, [ icon( 'check' ) ] ) ] ),
				h( 'div', null, [ h( 'p', { class: 'bk-action-text', text: 'Reading reminder' } ), h( 'p', { class: 'bk-card-meta', text: 'One email 24 hours after you stop reading, until you finish the book.' } ) ] )
			] ) );
		} );
	}
	function meExport( body ) {
		body.innerHTML = '';
		var g = C.google || {};
		function row( title, text, btns ) {
			return h( 'section', { class: 'bk-opt' }, [ h( 'div', null, [ h( 'p', { class: 'bk-opt-t', text: title } ), h( 'p', { class: 'bk-opt-d', text: text } ) ] ), h( 'div', { class: 'bk-row' }, btns ) ] );
		}
		function busy( b, on, label ) { b.disabled = on; if ( label ) { b.textContent = label; } }
		var mail = h( 'button', { class: 'bk-btn', type: 'button', text: 'Email me my notes' } );
		mail.addEventListener( 'click', function () {
			busy( mail, true, 'Sending…' );
			api( 'POST', 'notes/email', { book: C.book } ).then( function ( r ) { toast( 'Sent to ' + r.sent ); busy( mail, false, 'Email me my notes' ); } ).catch( function ( e ) { toast( e.message ); busy( mail, false, 'Email me my notes' ); } );
		} );
		body.appendChild( row( 'Email', 'Every summary, highlight, reflection, action point and journal entry, sent to ' + S.book.user.email + '.', [ mail ] ) );

		var gbox = h( 'div', { class: 'bk-row' } );
		function drawG() {
			gbox.innerHTML = '';
			if ( ! g.available ) {
				gbox.appendChild( h( 'p', { class: 'bk-opt-d', text: C.admin ? 'Add a Google OAuth client under Bookkeeper > Settings to turn this on.' : 'Coming soon.' } ) );
				return;
			}
			if ( ! g.connected ) {
				var c = h( 'button', { class: 'bk-btn', type: 'button', text: 'Connect Google Docs' } );
				c.addEventListener( 'click', function () {
					busy( c, true, 'Opening Google…' );
					api( 'GET', 'google/connect?book=' + C.book ).then( function ( r ) { location.href = r.url; } ).catch( function ( e ) { toast( e.message ); busy( c, false, 'Connect Google Docs' ); } );
				} );
				gbox.appendChild( c );
				return;
			}
			var s = h( 'button', { class: 'bk-btn', type: 'button', text: 'Sync now' } );
			s.addEventListener( 'click', function () {
				busy( s, true, 'Syncing…' );
				api( 'POST', 'google/sync', { book: C.book } ).then( function ( r ) { g = C.google = r; toast( 'Your Google Doc is up to date' ); drawG(); } ).catch( function ( e ) { toast( e.message ); busy( s, false, 'Sync now' ); } );
			} );
			if ( g.doc ) { gbox.appendChild( h( 'a', { class: 'bk-btn bk-btn--solid', href: g.doc, target: '_blank', rel: 'noopener', text: 'Open my Google Doc' } ) ); }
			gbox.appendChild( s );
			var off = h( 'button', { class: 'bk-link bk-link--soft', type: 'button', text: 'Disconnect' } );
			off.addEventListener( 'click', function () { api( 'POST', 'google/disconnect', { book: C.book } ).then( function ( r ) { g = C.google = r; toast( 'Google Docs disconnected' ); drawG(); } ); } );
			gbox.appendChild( off );
		}
		drawG();
		body.appendChild( h( 'section', { class: 'bk-opt' }, [ h( 'div', null, [ h( 'p', { class: 'bk-opt-t', text: 'Google Docs' } ), h( 'p', { class: 'bk-opt-d', text: g.connected ? 'Your notes live in a Google Doc and update a couple of minutes after you write.' + ( g.synced ? ' Last synced ' + new Date( g.synced.replace( ' ', 'T' ) + 'Z' ).toLocaleString() + '.' : '' ) : 'Keep a live copy of your notes in your own Google Drive. We can only see the one document we create.' } ) ] ), gbox ] ) );

		function getNotes() { return api( 'GET', 'notes/export?book=' + C.book ); }
		var share = h( 'button', { class: 'bk-btn', type: 'button', text: navigator.share ? 'Send to Notes' : 'Copy for Notes' } );
		share.addEventListener( 'click', function () {
			getNotes().then( function ( r ) {
				if ( r.empty ) { toast( 'You have not written anything yet' ); return; }
				if ( navigator.share ) {
					navigator.share( { title: r.title, text: r.text } ).catch( function () {} );
				} else if ( navigator.clipboard ) {
					navigator.clipboard.writeText( r.text ).then( function () { toast( 'Copied. Paste it into a new note.' ); } );
				}
			} ).catch( function ( e ) { toast( e.message ); } );
		} );
		var dl = h( 'button', { class: 'bk-btn', type: 'button', text: 'Download' } );
		dl.addEventListener( 'click', function () {
			getNotes().then( function ( r ) {
				var a = h( 'a', { href: URL.createObjectURL( new Blob( [ r.markdown ], { type: 'text/markdown' } ) ), download: r.filename } );
				d.body.appendChild( a ); a.click(); a.remove();
			} ).catch( function ( e ) { toast( e.message ); } );
		} );
		body.appendChild( row( 'Apple Notes and files', 'On iPhone, iPad or Mac, Send to Notes opens the share sheet. Pick Notes. Download saves a text file that opens anywhere.', [ share, dl ] ) );
	}
	function meAccount( body ) {
		body.innerHTML = '';
		var u = S.book.user;
		var name = h( 'input', { class: 'bk-input', type: 'text', value: u.name || '', autocomplete: 'given-name' } );
		body.appendChild( h( 'form', { class: 'bk-acc', onsubmit: function ( e ) {
			e.preventDefault();
			api( 'POST', 'account', { name: name.value } ).then( function ( r ) { u.name = r.name; toast( 'Name saved' ); } ).catch( function ( er ) { toast( er.message ); } );
		} }, [
			h( 'p', { class: 'bk-opt-t', text: 'Your details' } ),
			h( 'label', { class: 'bk-lab' }, [ 'First name', name ] ),
			h( 'label', { class: 'bk-lab' }, [ 'Email', h( 'input', { class: 'bk-input bk-input--fixed', type: 'email', value: u.email, readonly: true } ) ] ),
			h( 'button', { class: 'bk-btn', type: 'submit', text: 'Save name' } )
		] ) );
		var cur = h( 'input', { class: 'bk-input', type: 'password', autocomplete: 'current-password', required: true } );
		var nw = h( 'input', { class: 'bk-input', type: 'password', autocomplete: 'new-password', minlength: 8, required: true } );
		body.appendChild( h( 'form', { class: 'bk-acc', onsubmit: function ( e ) {
			e.preventDefault();
			api( 'POST', 'account/password', { current: cur.value, new: nw.value } ).then( function ( r ) { C.nonce = r.nonce; cur.value = nw.value = ''; toast( 'Password changed' ); } ).catch( function ( er ) { toast( er.message ); } );
		} }, [
			h( 'p', { class: 'bk-opt-t', text: 'Change password' } ),
			h( 'label', { class: 'bk-lab' }, [ 'Current password', cur ] ),
			h( 'label', { class: 'bk-lab' }, [ 'New password', nw ] ),
			h( 'button', { class: 'bk-btn', type: 'submit', text: 'Change password' } )
		] ) );
		if ( C.admin ) {
			body.appendChild( h( 'p', { class: 'bk-opt-d', text: 'You are signed in as a site admin, so account deletion is turned off here.' } ) );
		} else {
			var pw = h( 'input', { class: 'bk-input', type: 'password', autocomplete: 'current-password', required: true } );
			var del = h( 'form', { class: 'bk-acc bk-acc--danger', onsubmit: function ( e ) {
				e.preventDefault();
				api( 'POST', 'account/delete', { password: pw.value } ).then( function ( r ) { location.href = r.home; } ).catch( function ( er ) { toast( er.message ); } );
			} }, [
				h( 'p', { class: 'bk-opt-t', text: 'Delete my account' } ),
				h( 'p', { class: 'bk-opt-d', text: 'This removes your account with every highlight, note, reflection, action point and journal entry. It cannot be undone. If you bought the book, you would need to buy it again.' } ),
				h( 'label', { class: 'bk-lab' }, [ 'Type your password to confirm', pw ] ),
				h( 'button', { class: 'bk-btn bk-btn--danger', type: 'submit', text: 'Delete my account' } )
			] );
			body.appendChild( del );
		}
		body.appendChild( h( 'div', { class: 'bk-acc-out' }, [ signOutBtn() ] ) );
	}
	/* sign out without WordPress's confirmation page, then land on the sign-in screen */
	function signOutBtn() {
		var b = h( 'button', { class: 'bk-btn bk-btn--out', type: 'button' }, [ icon( 'out' ), 'Sign out' ] );
		b.addEventListener( 'click', function () {
			b.disabled = true;
			flush();
			api( 'POST', 'account/logout', {} ).then( function () { location.href = location.pathname + '?bk_err=out'; } ).catch( function () { location.href = C.logout; } );
		} );
		return b;
	}
	function stat( n, l ) { return h( 'div', { class: 'bk-stat' }, [ h( 'span', { text: l.charAt( 0 ).toUpperCase() + l.slice( 1 ) } ), h( 'strong', { text: String( n ) } ) ] ); }
	function empty( t, s ) { return h( 'div', { class: 'bk-empty' }, [ h( 'p', { class: 'bk-empty-t', text: t } ), h( 'p', { text: s } ) ] ); }

	/* ---------- home ---------- */
	function home() {
		S.route = null;
		S.sec = null;
		ui.where.textContent = '';
		ui.main.innerHTML = '';
		var last = S.book.last;
		var next = null;
		if ( last && chapter( last.chapter ) ) { next = { c: chapter( last.chapter ), i: last.section }; }
		if ( ! next ) {
			var f = flat().filter( function ( x ) { return ! isDone( x.c.id, x.i ) && ! x.c.locked; } )[ 0 ] || flat()[ 0 ];
			if ( f ) { next = { c: f.c, i: f.i }; }
		}
		var first = ! S.book.done.length;
		var p = pct();
		var all = flat();
		var doneN = all.filter( function ( x ) { return isDone( x.c.id, x.i ); } ).length;
		var name = S.book.user.name || 'reader';
		var st = S.book.stats;
		var mins = Math.round( st.seconds / 60 );

		var wrap = h( 'section', { class: 'bk-home' } );
		wrap.appendChild( h( 'header', { class: 'bk-dash-head', 'data-rise': '' }, [
			h( 'a', { class: 'bk-btn bk-dash-site', href: C.home }, [ icon( 'left' ), 'Go back to website' ] ),
			h( 'h1', { class: 'bk-dash-hi', text: ( first ? 'Welcome, ' : greet() + ', ' ) + name + '.' } ),
			h( 'p', { class: 'bk-dash-sub', text: first ? 'Your copy of ' + C.title + ( C.author ? ' by ' + C.author : '' ) + ' is ready. Start whenever you like; each section takes a few minutes.' : 'You are ' + p + '% of the way through ' + C.title + '. Pick up right where you stopped.' } )
		] ) );

		var hero = h( 'div', { class: 'bk-dash-hero' } );
		if ( next ) {
			hero.appendChild( h( 'a', { class: 'bk-continue', href: '#/c/' + next.c.id + '/' + next.i, 'data-rise': '' }, [
				h( 'span', { class: 'bk-continue-k', text: first ? 'Start reading' : 'Continue reading' } ),
				h( 'span', { class: 'bk-continue-t', text: next.c.sections[ next.i ] ? next.c.sections[ next.i ].title : next.c.title } ),
				h( 'span', { class: 'bk-continue-m', text: 'Chapter ' + next.c.num + ', ' + next.c.title + ( next.c.sections[ next.i ] ? '. ' + next.c.sections[ next.i ].minutes + ' min' : '' ) } ),
				h( 'span', { class: 'bk-continue-go' }, [ icon( 'right' ) ] )
			] ) );
		}
		hero.appendChild( h( 'div', { class: 'bk-dash-ring', 'data-rise': '' }, [
			ring( p, 148, 'read' ),
			h( 'p', { class: 'bk-dash-ring-m', text: doneN + ' of ' + all.length + ' sections' } )
		] ) );
		wrap.appendChild( hero );

		function tile( ic, n, label, suffix ) {
			var num = h( 'strong', { text: '0' } );
			count( num, n, suffix );
			return h( 'div', { class: 'bk-tile', 'data-rise': '' }, [ h( 'span', { class: 'bk-tile-i' }, [ icon( ic ) ] ), num, h( 'span', { class: 'bk-tile-l', text: label } ) ] );
		}
		wrap.appendChild( h( 'div', { class: 'bk-tiles' }, [
			tile( 'clock', mins, 'minutes reading', '' ),
			tile( 'note', st.marks, 'highlights' ),
			tile( 'spark', st.reflects, 'reflections' ),
			tile( 'check', st.actions, 'action points' )
		] ) );

		var lower = h( 'div', { class: 'bk-dash-cols' } );
		var acts = h( 'section', { class: 'bk-panel', 'data-rise': '' }, [ h( 'h2', { class: 'bk-panel-t', text: 'Your next steps' } ), h( 'p', { class: 'bk-muted', text: 'Loading…' } ) ] );
		var hl = h( 'section', { class: 'bk-panel', 'data-rise': '' }, [ h( 'h2', { class: 'bk-panel-t', text: 'Latest highlight' } ), h( 'p', { class: 'bk-muted', text: 'Loading…' } ) ] );
		lower.appendChild( acts );
		lower.appendChild( hl );
		wrap.appendChild( lower );
		api( 'GET', 'entries?book=' + C.book + '&kind=action' ).then( function ( rows ) {
			acts.lastChild.remove();
			var open = rows.filter( function ( r ) { return r.status !== 'done'; } ).sort( function ( a, b ) { return ( a.due || '9' ) < ( b.due || '9' ) ? -1 : 1; } ).slice( 0, 3 );
			if ( ! open.length ) {
				acts.appendChild( h( 'p', { class: 'bk-panel-empty', text: rows.length ? 'Every action point is done. Well done.' : 'When a chapter asks what you will do next, your answer shows up here.' } ) );
				return;
			}
			var ul = h( 'ul', { class: 'bk-steps-list' } );
			open.forEach( function ( r ) {
				var cb = h( 'input', { type: 'checkbox', 'aria-label': 'Mark done' } );
				var li = h( 'li', null, [
					h( 'label', { class: 'bk-check' }, [ cb, h( 'span', { class: 'bk-box' }, [ icon( 'check' ) ] ) ] ),
					h( 'div', null, [ h( 'p', { class: 'bk-action-text', text: r.content } ), h( 'p', { class: 'bk-card-meta', text: r.due ? 'By ' + niceDate( r.due ) : 'No date yet' } ), calLinks( r ) ] )
				] );
				cb.addEventListener( 'change', function () {
					api( 'PATCH', 'entries/' + r.id, { status: cb.checked ? 'done' : 'open' } ).then( function () { li.classList.toggle( 'is-done', cb.checked ); if ( cb.checked ) { toast( 'Nice work. One step closer.' ); } } );
				} );
				ul.appendChild( li );
			} );
			acts.appendChild( ul );
		} ).catch( function () { acts.lastChild.textContent = ''; } );
		api( 'GET', 'marks?book=' + C.book ).then( function ( rows ) {
			hl.lastChild.remove();
			var m = rows.slice().sort( function ( a, b ) { return ( a.updated || '' ) < ( b.updated || '' ) ? 1 : -1; } )[ 0 ];
			if ( ! m ) {
				hl.appendChild( h( 'p', { class: 'bk-panel-empty', text: 'Select any line while you read to highlight it. Your latest one appears here.' } ) );
				return;
			}
			hl.appendChild( h( 'blockquote', { class: 'bk-dash-quote bk-card--' + m.color, text: m.quote } ) );
			if ( m.note ) { hl.appendChild( h( 'p', { class: 'bk-dash-note', text: m.note } ) ); }
			hl.appendChild( h( 'a', { class: 'bk-link', href: '#/c/' + m.chapter + '/' + m.section + '/m' + m.id, text: 'Go to this passage' } ) );
		} ).catch( function () { hl.lastChild.textContent = ''; } );

		var grid = h( 'ol', { class: 'bk-chapters' } );
		S.book.chapters.forEach( function ( c ) {
			var n = c.sections.filter( function ( s, k ) { return isDone( c.id, k ); } ).length;
			var cp = Math.round( 100 * n / c.sections.length );
			grid.appendChild( h( 'li', { 'data-rise': '' }, [ h( 'a', { class: 'bk-chcard' + ( c.locked ? ' is-locked' : '' ) + ( cp === 100 ? ' is-done' : '' ), href: '#/c/' + c.id + '/0' }, [
				h( 'span', { class: 'bk-chcard-num', text: 'Chapter ' + c.num } ),
				h( 'span', { class: 'bk-chcard-title', text: c.title } ),
				h( 'span', { class: 'bk-chcard-meta' }, [ c.locked ? icon( 'lock' ) : null, c.locked ? 'Locked' : ( cp === 100 ? 'Finished' : n + ' of ' + c.sections.length + ' sections' ), c.free && ! S.book.owns ? h( 'span', { class: 'bk-tag', text: 'Free' } ) : null ] ),
				c.locked ? null : h( 'span', { class: 'bk-chcard-bar' }, [ h( 'i', { style: 'width:' + cp + '%' } ) ] )
			] ) ] ) );
		} );
		wrap.appendChild( h( 'h2', { class: 'bk-h2', text: 'Chapters', 'data-rise': '' } ) );
		wrap.appendChild( grid );
		if ( ! S.book.owns && S.book.chapters.some( function ( c ) { return c.locked; } ) ) {
			wrap.appendChild( h( 'a', { class: 'bk-unlock', href: '#/buy', 'data-rise': '' }, [
				h( 'span', { class: 'bk-unlock-t', text: 'Enjoying it? Unlock the whole book.' } ),
				h( 'span', { class: 'bk-unlock-m', text: ( C.pay && C.pay.length ? C.pay.map( function ( x ) { return x.label; } ).join( ' or ' ) + ', one payment' : 'Every chapter, yours for good' ) } ),
				h( 'span', { class: 'bk-continue-go' }, [ icon( 'right' ) ] )
			] ) );
		}
		ui.main.appendChild( wrap );
		rise( wrap );
		window.scrollTo( 0, 0 );
	}

	/* ---------- buying ---------- */
	function buyButtons() {
		var pay = C.pay || [];
		if ( ! pay.length ) {
			return h( 'div', { class: 'bk-row' }, [ h( 'a', { class: 'bk-btn bk-btn--solid', href: C.buy, text: 'Unlock the full book' } ) ] );
		}
		var box = h( 'div', { class: 'bk-buy' } );
		pay.forEach( function ( p, n ) {
			var b = h( 'button', { type: 'button', class: 'bk-btn ' + ( n ? '' : 'bk-btn--solid' ), text: ( n ? 'Pay ' : 'Unlock for ' ) + p.label } );
			b.addEventListener( 'click', function () {
				if ( S.paying ) { return; }
				S.paying = true;
				box.querySelectorAll( 'button' ).forEach( function ( x ) { x.disabled = true; } );
				b.textContent = 'Opening secure checkout…';
				api( 'POST', 'checkout', { book: C.book, currency: p.cur } ).then( function ( r ) {
					location.href = r.url;
				} ).catch( function ( e ) {
					S.paying = false;
					box.querySelectorAll( 'button' ).forEach( function ( x ) { x.disabled = false; } );
					b.textContent = ( n ? 'Pay ' : 'Unlock for ' ) + p.label;
					toast( e.message );
				} );
			} );
			box.appendChild( b );
		} );
		box.appendChild( h( 'p', { class: 'bk-buy-fine', text: 'One payment, yours for good. Secure checkout by Paystack, with cards, bank transfer and USSD.' } ) );
		return box;
	}
	function buy() {
		S.route = null;
		ui.main.innerHTML = '';
		ui.where.textContent = '';
		if ( S.book.owns ) { location.hash = '#/'; return; }
		var locked = S.book.chapters.filter( function ( c ) { return c.locked; } );
		ui.main.appendChild( h( 'section', { class: 'bk-locked' }, [
			h( 'h1', { text: 'Unlock ' + C.title } ),
			h( 'p', { class: 'bk-lead', text: 'Get every chapter, plus your journal, highlights, notes and action points, on any device.' } ),
			h( 'ul', { class: 'bk-locked-list' }, locked.map( function ( c ) { return h( 'li', { text: 'Chapter ' + c.num + ': ' + c.title } ); } ) ),
			buyButtons(),
			h( 'p', null, [ h( 'a', { class: 'bk-link', href: '#/', text: 'Back to chapters' } ) ] )
		] ) );
		window.scrollTo( 0, 0 );
	}

	/* ---------- locked ---------- */
	function locked( c ) {
		ui.main.innerHTML = '';
		ui.where.textContent = 'Chapter ' + c.num;
		ui.main.appendChild( h( 'section', { class: 'bk-locked' }, [
			h( 'span', { class: 'bk-locked-i' }, [ icon( 'lock' ) ] ),
			h( 'p', { class: 'bk-eyebrow', text: 'Chapter ' + c.num } ),
			h( 'h1', { text: c.title } ),
			h( 'p', { class: 'bk-lead', text: 'This chapter is part of the full book. Unlock it once and keep every chapter, your journal and your notes for good.' } ),
			h( 'ul', { class: 'bk-locked-list' }, c.sections.map( function ( s ) { return h( 'li', { text: s.title } ); } ) ),
			buyButtons(),
			h( 'p', null, [ h( 'a', { class: 'bk-link', href: '#/', text: 'Back to chapters' } ) ] )
		] ) );
		window.scrollTo( 0, 0 );
	}

	/* ---------- a section ---------- */
	function load( c, i, markId ) {
		var ch = chapter( c );
		if ( ! ch ) { location.hash = '#/'; return; }
		if ( ch.locked ) { locked( ch ); return; }
		if ( ! ch.sections[ i ] ) { go( c, 0 ); return; }
		flush();
		S.route = { c: c, i: i };
		ui.where.textContent = 'Chapter ' + ch.num + ' · ' + ( i + 1 ) + ' of ' + ch.sections.length;
		ui.main.classList.add( 'is-leaving' );
		api( 'GET', 'section/' + c + '/' + i ).then( function ( sec ) {
			S.sec = sec;
			S.marks = sec.marks;
			S.entries = {};
			sec.entries.forEach( function ( e ) { S.entries[ e.key ] = e; } );
			drawSection( ch, i );
			window.scrollTo( 0, 0 );
			if ( markId ) {
				var m = ui.main.querySelector( '[data-mark="' + markId + '"]' );
				if ( m ) { m.scrollIntoView( { block: 'center' } ); m.classList.add( 'is-flash' ); }
			}
			ui.main.focus( { preventScroll: true } );
		} ).catch( function ( e ) {
			ui.main.classList.remove( 'is-leaving' );
			if ( e.status === 403 ) { ch.locked = true; locked( ch ); } else { toast( e.message ); }
		} );
	}

	function drawSection( ch, i ) {
		var all = flat();
		var idx = all.map( function ( x ) { return x.c.id + ':' + x.i; } ).indexOf( ch.id + ':' + i );
		var prev = all[ idx - 1 ];
		var next = all[ idx + 1 ];
		ui.main.innerHTML = '';
		var text = h( 'div', { class: 'bk-text', html: S.sec.html } );
		var art = h( 'article', { class: 'bk-section' }, [
			h( 'header', { class: 'bk-sec-head' }, [
				h( 'p', { class: 'bk-eyebrow', text: 'Chapter ' + ch.num + ' · ' + ch.title } ),
				h( 'h1', { class: 'bk-sec-title', text: S.sec.title } ),
				h( 'p', { class: 'bk-sec-meta' }, [ S.sec.minutes + ' min read', h( 'span', { class: 'bk-dots', 'aria-hidden': 'true' }, ch.sections.map( function ( s, k ) { return h( 'i', { class: ( k === i ? 'is-here ' : '' ) + ( isDone( ch.id, k ) ? 'is-done' : '' ) } ); } ) ) ] )
			] ),
			text,
			i === ch.sections.length - 1 ? summaryBlock( ch, i ) : null,
			h( 'div', { class: 'bk-end', 'aria-hidden': 'true' } ),
			h( 'nav', { class: 'bk-pager', 'aria-label': 'Sections' }, [
				prev ? h( 'a', { class: 'bk-pager-prev', href: '#/c/' + prev.c.id + '/' + prev.i, title: prev.s.title }, [ icon( 'left' ), h( 'span', null, [ h( 'small', { text: 'Previous' } ), h( 'span', { text: prev.s.title } ) ] ) ] ) : h( 'span', { class: 'bk-pager-gap' } ),
				next ? h( 'a', { class: 'bk-pager-next', href: '#/c/' + next.c.id + '/' + next.i, title: next.s.title }, [ h( 'span', null, [ h( 'small', { text: next.c.id !== ch.id ? 'Next chapter' : 'Next' } ), h( 'span', { text: next.s.title } ) ] ), icon( 'right' ) ] )
					: h( 'a', { class: 'bk-pager-next', href: '#/' }, [ h( 'span', null, [ h( 'small', { text: 'You reached the end' } ), h( 'span', { text: 'Back to chapters' } ) ] ), icon( 'right' ) ] )
			] )
		] );
		ui.main.appendChild( art );
		hydrate( text, ch, i );
		applyMarks( text );
		art.querySelectorAll( '.bk-block, .bk-summary, .bk-pager' ).forEach( function ( x ) { x.setAttribute( 'data-rise', '' ); } );
		rise( art );
		ui.main.classList.remove( 'is-leaving' );
		watchEnd( art.querySelector( '.bk-end' ), ch.id, i );
	}

	/* end-of-chapter summary */
	function summaryBlock( ch, i ) {
		var key = 'summary-' + ch.id;
		var saved = S.entries[ key ];
		var ta = h( 'textarea', { class: 'bk-field', rows: 5, placeholder: 'In your own words, what will you take from this chapter?', 'aria-label': 'Chapter summary' } );
		ta.value = saved ? saved.content : '';
		var state = h( 'span', { class: 'bk-saved', 'aria-live': 'polite', text: saved && saved.content ? 'Saved' : '' } );
		var save = debounce( function () {
			state.textContent = 'Saving…';
			api( 'POST', 'entries', { book: C.book, chapter: ch.id, section: i, key: key, kind: 'summary', prompt: 'Chapter ' + ch.num + ' summary', content: ta.value } )
				.then( function ( e ) { S.entries[ key ] = e; state.textContent = 'Saved'; } )
				.catch( function ( e ) { state.textContent = e.message; } );
		}, 900 );
		ta.addEventListener( 'input', function () { state.textContent = ''; save(); autoGrow( ta ); } );
		var send = h( 'button', { class: 'bk-btn', type: 'button', text: 'Email me this chapter\'s notes' } );
		send.addEventListener( 'click', function () {
			send.disabled = true;
			var go = function () {
				api( 'POST', 'notes/email', { book: C.book, chapter: ch.id } ).then( function ( r ) { toast( 'Sent to ' + r.sent ); } ).catch( function ( e ) { toast( e.message ); } ).then( function () { send.disabled = false; } );
			};
			if ( ta.value.trim() && ( ! S.entries[ key ] || S.entries[ key ].content !== ta.value ) ) {
				api( 'POST', 'entries', { book: C.book, chapter: ch.id, section: i, key: key, kind: 'summary', prompt: 'Chapter ' + ch.num + ' summary', content: ta.value } ).then( function ( e ) { S.entries[ key ] = e; state.textContent = 'Saved'; go(); } );
			} else { go(); }
		} );
		setTimeout( function () { autoGrow( ta ); }, 0 );
		return h( 'section', { class: 'bk-summary' }, [
			h( 'p', { class: 'bk-block-k', text: 'End of chapter ' + ch.num } ),
			h( 'h2', { class: 'bk-summary-t', text: 'Sum up this chapter' } ),
			h( 'p', { class: 'bk-summary-d', text: 'A few lines are enough. We can email it to you with your highlights, reflections and action points from this chapter.' } ),
			ta,
			h( 'div', { class: 'bk-summary-foot' }, [ state, send ] )
		] );
	}

	/* interactive blocks */
	function hydrate( text, ch, i ) {
		var n = { reflect: 0, action: 0, quote: 0 };
		text.querySelectorAll( '.bk-reflect, .bk-action, .bk-quote' ).forEach( function ( el ) {
			var kind = el.classList.contains( 'bk-reflect' ) ? 'reflect' : el.classList.contains( 'bk-action' ) ? 'action' : 'quote';
			var key = ch.id + ':' + i + ':' + kind[ 0 ] + ( n[ kind ]++ );
			var prompt = el.textContent.trim();
			var saved = S.entries[ key ];
			el.classList.add( 'bk-block' );
			if ( kind === 'reflect' ) {
				var ta = h( 'textarea', { class: 'bk-field', rows: 4, placeholder: 'Write your answer. It saves as you type and goes to your journal.', 'aria-label': prompt } );
				ta.value = saved ? saved.content : '';
				var state = h( 'span', { class: 'bk-saved', 'aria-live': 'polite', text: saved && saved.content ? 'Saved to your journal' : '' } );
				var save = debounce( function () {
					state.textContent = 'Saving…';
					api( 'POST', 'entries', { book: C.book, chapter: ch.id, section: i, key: key, kind: 'reflect', prompt: prompt, content: ta.value } )
						.then( function ( e ) { S.entries[ key ] = e; state.textContent = 'Saved to your journal'; } )
						.catch( function ( e ) { state.textContent = e.message; } );
				}, 900 );
				ta.addEventListener( 'input', function () { state.textContent = ''; save(); autoGrow( ta ); } );
				el.appendChild( h( 'div', { class: 'bk-ui bk-block-ui' }, [ h( 'p', { class: 'bk-block-k' }, [ icon( 'note' ), 'Reflection' ] ), ta, state ] ) );
				setTimeout( function () { autoGrow( ta ); }, 0 );
			} else if ( kind === 'action' ) {
				var box = h( 'div', { class: 'bk-ui bk-block-ui' } );
				var draw = function ( e ) {
					box.innerHTML = '';
					box.appendChild( h( 'p', { class: 'bk-block-k' }, [ icon( 'spark' ), 'Action point' ] ) );
					if ( e && e.content ) {
						var cb = h( 'input', { type: 'checkbox', checked: e.status === 'done', 'aria-label': 'Done' } );
						cb.addEventListener( 'change', function () { api( 'PATCH', 'entries/' + e.id, { status: cb.checked ? 'done' : 'open' } ).then( function ( x ) { S.entries[ key ] = x; draw( x ); } ); } );
						box.appendChild( h( 'div', { class: 'bk-committed' + ( e.status === 'done' ? ' is-done' : '' ) }, [
							h( 'label', { class: 'bk-check' }, [ cb, h( 'span', { class: 'bk-box' }, [ icon( 'check' ) ] ) ] ),
							h( 'div', null, [ h( 'p', { class: 'bk-action-text', text: e.content } ), h( 'p', { class: 'bk-card-meta', text: e.due ? 'By ' + niceDate( e.due ) : 'In your action list' } ), e.status !== 'done' ? calLinks( e ) : null ] ),
							h( 'button', { class: 'bk-link', type: 'button', text: 'Edit', onclick: function () { form( e ); } } )
						] ) );
					} else { form( e ); }
				};
				var form = function ( e ) {
					box.innerHTML = '';
					box.appendChild( h( 'p', { class: 'bk-block-k' }, [ icon( 'spark' ), 'Action point' ] ) );
					var inp = h( 'input', { class: 'bk-field', type: 'text', placeholder: 'One step you will take', value: e ? e.content : '', 'aria-label': prompt } );
					var dueW = dateField( e && e.due ? e.due : '' );
					var due = dueW.input;
					box.appendChild( h( 'form', { class: 'bk-action-form', onsubmit: function ( ev ) {
						ev.preventDefault();
						if ( ! inp.value.trim() ) { inp.focus(); return; }
						api( 'POST', 'entries', { book: C.book, chapter: ch.id, section: i, key: key, kind: 'action', prompt: prompt, content: inp.value, due: due.value, status: 'open' } )
							.then( function ( x ) { S.entries[ key ] = x; draw( x ); toast( 'Added to your action points' ); } )
							.catch( function ( er ) { toast( er.message ); } );
					} }, [ inp, dueW, h( 'button', { class: 'bk-btn bk-btn--solid', type: 'submit', text: 'Commit' } ) ] ) );
				};
				draw( saved );
				el.appendChild( box );
			} else {
				var btn = h( 'button', { class: 'bk-btn bk-btn--ghost', type: 'button' } );
				var set = function ( on ) { btn.innerHTML = ''; btn.appendChild( icon( on ? 'check' : 'note' ) ); btn.appendChild( d.createTextNode( on ? 'Saved to your journal' : 'Save this quote' ) ); btn.disabled = !! on; };
				set( saved && saved.content );
				btn.addEventListener( 'click', function () {
					api( 'POST', 'entries', { book: C.book, chapter: ch.id, section: i, key: key, kind: 'journal', prompt: 'Saved quote', content: prompt } ).then( function ( x ) { S.entries[ key ] = x; set( true ); } );
				} );
				el.appendChild( h( 'div', { class: 'bk-ui bk-block-ui bk-block-ui--quote' }, [ btn ] ) );
			}
		} );
	}
	function autoGrow( ta ) { ta.style.height = 'auto'; ta.style.height = Math.max( 110, ta.scrollHeight + 2 ) + 'px'; }

	/* progress: finished when the end of the section has been on screen */
	var endObs;
	function watchEnd( el, c, i ) {
		if ( endObs ) { endObs.disconnect(); }
		if ( ! ( 'IntersectionObserver' in window ) ) { return; }
		endObs = new IntersectionObserver( function ( es ) {
			if ( es[ 0 ].isIntersecting && S.route && S.route.c === c && S.route.i === i ) {
				endObs.disconnect();
				if ( ! isDone( c, i ) ) { S.book.done.push( c + ':' + i ); setProgress(); }
				send( true );
			}
		}, { rootMargin: '0px 0px -10% 0px' } );
		endObs.observe( el );
	}
	function send( done ) {
		if ( ! S.route ) { return; }
		var secs = Math.round( S.active );
		S.active = 0;
		api( 'POST', 'progress', { chapter: S.route.c, section: S.route.i, done: !! done, seconds: secs } ).catch( function () {} );
		S.book.last = { chapter: S.route.c, section: S.route.i };
	}
	function flush() { if ( S.route && S.active > 2 ) { send( false ); } }
	/* reading time: counts only while the tab is visible and the reader was active in the last minute */
	var lastAct = Date.now();
	[ 'scroll', 'pointerdown', 'keydown', 'touchstart' ].forEach( function ( ev ) { window.addEventListener( ev, function () { lastAct = Date.now(); }, { passive: true } ); } );
	setInterval( function () {
		var now = Date.now();
		if ( S.route && ! d.hidden && now - lastAct < 60000 ) { S.active += ( now - S.lastTick ) / 1000; }
		S.lastTick = now;
		if ( S.active >= 30 ) { send( false ); }
	}, 5000 );
	d.addEventListener( 'visibilitychange', function () { if ( d.hidden ) { flush(); } } );

	/* ---------- highlights and sticky notes ---------- */
	function textNodes( root ) {
		var out = [];
		var w = d.createTreeWalker( root, NodeFilter.SHOW_TEXT, { acceptNode: function ( n ) { return n.parentNode.closest( '.bk-ui' ) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; } } );
		while ( w.nextNode() ) { out.push( w.currentNode ); }
		return out;
	}
	function offsetOf( root, node, off ) {
		var nodes = textNodes( root );
		var n = 0;
		for ( var k = 0; k < nodes.length; k++ ) {
			if ( nodes[ k ] === node ) { return n + off; }
			n += nodes[ k ].length;
		}
		if ( node.nodeType === 1 ) {
			/* an element boundary: count text before its child at off */
			var target = node.childNodes[ off ] || null;
			n = 0;
			for ( k = 0; k < nodes.length; k++ ) {
				if ( target && ( target === nodes[ k ] || target.contains( nodes[ k ] ) ) ) { return n; }
				if ( ! target && ! node.contains( nodes[ k ] ) && n > 0 && nodes[ k - 1 ] && node.contains( nodes[ k - 1 ] ) ) { return n; }
				n += nodes[ k ].length;
			}
			return n;
		}
		return -1;
	}
	function wrapRange( root, start, end, mark ) {
		var nodes = textNodes( root );
		var n = 0;
		var first = null;
		var last = null;
		nodes.forEach( function ( t ) {
			var a = n;
			var b = n + t.length;
			n = b;
			if ( b <= start || a >= end || t.parentNode.closest( '.bk-block-ui' ) ) { return; }
			var s = Math.max( start, a ) - a;
			var e = Math.min( end, b ) - a;
			if ( ! t.nodeValue.slice( s, e ).trim() && t.nodeValue.slice( s, e ).length < 2 ) { return; }
			var mid = t;
			if ( s > 0 ) { mid = t.splitText( s ); }
			if ( e - s < mid.length ) { mid.splitText( e - s ); }
			var w = d.createElement( 'mark' );
			w.className = 'bk-hl bk-hl--' + mark.color + ( mark.note ? ' has-note' : '' );
			w.setAttribute( 'data-mark', mark.id );
			mid.parentNode.insertBefore( w, mid );
			w.appendChild( mid );
			if ( ! first ) { first = w; }
			last = w;
		} );
		if ( last && mark.note ) {
			var pin = h( 'button', { type: 'button', class: 'bk-pin bk-ui', 'data-mark': mark.id, 'aria-label': 'Open note' }, [ icon( 'note' ) ] );
			last.parentNode.insertBefore( pin, last.nextSibling );
		}
		if ( first ) { first.classList.add( 'is-first' ); }
	}
	function applyMarks( root ) {
		S.marks.slice().sort( function ( a, b ) { return a.start - b.start; } ).forEach( function ( m ) { wrapRange( root, m.start, m.end, m ); } );
	}
	function redrawMarks() {
		var text = ui.main.querySelector( '.bk-text' );
		if ( ! text ) { return; }
		text.querySelectorAll( '.bk-pin' ).forEach( function ( p ) { p.remove(); } );
		text.querySelectorAll( 'mark.bk-hl' ).forEach( function ( m ) {
			var p = m.parentNode;
			while ( m.firstChild ) { p.insertBefore( m.firstChild, m ); }
			p.removeChild( m );
			p.normalize();
		} );
		applyMarks( text );
	}

	/* toolbar */
	var pending = null;
	function closeTools() {
		ui.tools.classList.remove( 'is-on' );
		ui.sticky.classList.remove( 'is-on' );
		app.classList.remove( 'is-sheet' );
		pending = null;
	}
	function place( el, rect ) {
		var w = el.offsetWidth;
		var x = Math.min( Math.max( 12, rect.left + rect.width / 2 - w / 2 ), window.innerWidth - w - 12 );
		var y = rect.bottom + 12;
		if ( y + el.offsetHeight > window.innerHeight - 12 ) { y = Math.max( 70, rect.top - el.offsetHeight - 12 ); }
		el.style.transform = 'translate(' + Math.round( x ) + 'px,' + Math.round( y ) + 'px)';
	}
	function showTools( rect, mark ) {
		ui.tools.innerHTML = '';
		COLORS.forEach( function ( c ) {
			ui.tools.appendChild( h( 'button', {
				type: 'button', class: 'bk-swatch bk-swatch--' + c.k + ( mark && mark.color === c.k ? ' is-on' : '' ), 'aria-label': 'Highlight ' + c.n,
				onclick: function () { mark ? updateMark( mark, { color: c.k } ) : createMark( c.k, false ); }
			} ) );
		} );
		ui.tools.appendChild( h( 'span', { class: 'bk-tools-sep' } ) );
		ui.tools.appendChild( h( 'button', { type: 'button', class: 'bk-tool', onclick: function () { mark ? openSticky( mark ) : createMark( 'yellow', true ); } }, [ icon( 'note' ), h( 'span', { text: mark && mark.note ? 'Note' : 'Add note' } ) ] ) );
		if ( mark ) {
			ui.tools.appendChild( h( 'button', { type: 'button', class: 'bk-tool', 'aria-label': 'Remove highlight', onclick: function () { removeMark( mark ); } }, [ icon( 'trash' ) ] ) );
		}
		ui.tools.classList.add( 'is-on' );
		place( ui.tools, rect );
	}
	function selectionInText() {
		var sel = window.getSelection();
		if ( ! sel || sel.isCollapsed || ! sel.rangeCount ) { return null; }
		var text = ui.main.querySelector( '.bk-text' );
		if ( ! text ) { return null; }
		var r = sel.getRangeAt( 0 );
		if ( ! text.contains( r.commonAncestorContainer ) ) { return null; }
		var anc = r.commonAncestorContainer.nodeType === 1 ? r.commonAncestorContainer : r.commonAncestorContainer.parentNode;
		if ( anc.closest( '.bk-ui' ) ) { return null; }
		var start = offsetOf( text, r.startContainer, r.startOffset );
		var end = offsetOf( text, r.endContainer, r.endOffset );
		var quote = sel.toString().replace( /\s+/g, ' ' ).trim();
		if ( start < 0 || end <= start || ! quote ) { return null; }
		return { start: start, end: end, quote: quote, rect: r.getBoundingClientRect() };
	}
	var selT;
	function onSelect() {
		clearTimeout( selT );
		selT = setTimeout( function () {
			if ( ui.sticky.classList.contains( 'is-on' ) ) { return; }
			var s = selectionInText();
			if ( s ) { pending = s; showTools( s.rect, null ); }
			else if ( pending && ( ! d.activeElement || ! ui.tools.contains( d.activeElement ) ) ) { pending = null; ui.tools.classList.remove( 'is-on' ); }
		}, 220 );
	}
	d.addEventListener( 'selectionchange', onSelect );
	d.addEventListener( 'mouseup', onSelect );
	d.addEventListener( 'touchend', onSelect );

	function createMark( color, withNote ) {
		var s = pending;
		if ( ! s || S.busy ) { return; }
		S.busy = true;
		api( 'POST', 'marks', { book: C.book, chapter: S.route.c, section: S.route.i, start: s.start, end: s.end, quote: s.quote, color: color } ).then( function ( m ) {
			S.busy = false;
			S.marks.push( m );
			window.getSelection().removeAllRanges();
			redrawMarks();
			ui.tools.classList.remove( 'is-on' );
			if ( withNote ) { openSticky( m ); } else { toast( 'Highlighted' ); }
		} ).catch( function ( e ) { S.busy = false; toast( e.message ); } );
	}
	function updateMark( m, patch ) {
		return api( 'PATCH', 'marks/' + m.id, patch ).then( function ( x ) {
			S.marks = S.marks.map( function ( y ) { return y.id === x.id ? x : y; } );
			redrawMarks();
			closeTools();
			return x;
		} ).catch( function ( e ) { toast( e.message ); } );
	}
	function removeMark( m ) {
		api( 'DELETE', 'marks/' + m.id ).then( function () {
			S.marks = S.marks.filter( function ( y ) { return y.id !== m.id; } );
			redrawMarks();
			closeTools();
			toast( 'Highlight removed' );
		} ).catch( function ( e ) { toast( e.message ); } );
	}
	function markRect( id ) {
		var els = ui.main.querySelectorAll( 'mark[data-mark="' + id + '"]' );
		if ( ! els.length ) { return { left: window.innerWidth / 2, width: 0, top: 120, bottom: 120 }; }
		var a = els[ 0 ].getBoundingClientRect();
		var b = els[ els.length - 1 ].getBoundingClientRect();
		return { left: Math.min( a.left, b.left ), width: Math.abs( b.right - a.left ), top: a.top, bottom: b.bottom };
	}
	function openSticky( m ) {
		ui.tools.classList.remove( 'is-on' );
		ui.sticky.className = 'bk-sticky bk-ui bk-sticky--' + m.color;
		ui.sticky.innerHTML = '';
		var ta = h( 'textarea', { rows: 5, placeholder: 'Write a note on this passage…', 'aria-label': 'Note' } );
		ta.value = m.note || '';
		ui.sticky.appendChild( h( 'p', { class: 'bk-sticky-q', text: '“' + ( m.quote.length > 120 ? m.quote.slice( 0, 117 ) + '…' : m.quote ) + '”' } ) );
		ui.sticky.appendChild( ta );
		ui.sticky.appendChild( h( 'div', { class: 'bk-sticky-row' }, [
			m.note ? h( 'button', { type: 'button', class: 'bk-link', text: 'Delete note', onclick: function () { updateMark( m, { note: '' } ); } } ) : h( 'span' ),
			h( 'div', { class: 'bk-row' }, [
				h( 'button', { type: 'button', class: 'bk-btn', text: 'Cancel', onclick: closeTools } ),
				h( 'button', { type: 'button', class: 'bk-btn bk-btn--solid', text: 'Save note', onclick: function () { updateMark( m, { note: ta.value } ).then( function () { if ( ta.value.trim() ) { toast( 'Note pinned' ); } } ); } } )
			] )
		] ) );
		ui.sticky.classList.add( 'is-on' );
		if ( window.innerWidth < 700 ) { app.classList.add( 'is-sheet' ); ui.sticky.style.transform = ''; }
		else { place( ui.sticky, markRect( m.id ) ); }
		setTimeout( function () { ta.focus(); }, 60 );
	}
	/* tap an existing highlight or pin */
	app.addEventListener( 'click', function ( e ) {
		var pin = e.target.closest( '.bk-pin' );
		var mk = e.target.closest( 'mark.bk-hl' );
		var id = pin ? pin.getAttribute( 'data-mark' ) : mk ? mk.getAttribute( 'data-mark' ) : null;
		if ( id && window.getSelection().isCollapsed ) {
			var m = S.marks.filter( function ( x ) { return String( x.id ) === id; } )[ 0 ];
			if ( m ) { e.preventDefault(); pending = null; pin ? openSticky( m ) : showTools( markRect( m.id ), m ); }
			return;
		}
		if ( e.target.isConnected && ! e.target.closest( '.bk-display, .bk-aa' ) ) { toggleDisplay( false ); }
		if ( ! e.target.closest( '.bk-tools, .bk-sticky' ) && window.getSelection().isCollapsed ) { closeTools(); }
	} );

	/* copying: short passages only, with a credit line */
	d.addEventListener( 'copy', function ( e ) {
		var sel = window.getSelection();
		var text = ui.main.querySelector( '.bk-text' );
		if ( ! sel || ! text || ! sel.rangeCount || ! text.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) { return; }
		var s = sel.toString();
		if ( e.target.closest && e.target.closest( 'textarea, input' ) ) { return; }
		e.preventDefault();
		var cut = s.length > 240 ? s.slice( 0, 240 ).replace( /\s+\S*$/, '' ) + '…' : s;
		e.clipboardData.setData( 'text/plain', '“' + cut.trim() + '”\n' + C.title + ( C.author ? ', ' + C.author : '' ) );
		if ( s.length > 240 ) { toast( 'Only short passages can be copied' ); }
	} );

	/* keyboard */
	d.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) { closeTools(); closeDrawer(); toggleDisplay( false ); return; }
		if ( /input|textarea|select/i.test( ( e.target.tagName || '' ) ) || e.metaKey || e.ctrlKey || e.altKey ) { return; }
		if ( e.key === 'ArrowRight' ) { var n = ui.main.querySelector( '.bk-pager-next' ); if ( n ) { n.click(); } }
		if ( e.key === 'ArrowLeft' ) { var p = ui.main.querySelector( '.bk-pager-prev' ); if ( p ) { p.click(); } }
	} );
	window.addEventListener( 'scroll', function () { if ( ui.tools.classList.contains( 'is-on' ) && ! pending ) { closeTools(); } }, { passive: true } );

	/* ---------- router ---------- */
	function route() {
		closeTools();
		var m = location.hash.match( /^#\/c\/(\d+)\/(\d+)(?:\/m(\d+))?/ );
		if ( m ) { load( +m[ 1 ], +m[ 2 ], m[ 3 ] ); } else if ( location.hash === '#/buy' ) { flush(); buy(); } else if ( location.hash === '#/notes' ) { flush(); home(); openDrawer( 'me', 'export' ); } else if ( location.hash === '#/account' ) { flush(); home(); openDrawer( 'me', 'account' ); } else { flush(); home(); }
	}
	window.addEventListener( 'hashchange', route );
	window.addEventListener( 'pagehide', flush );

	shell();
	api( 'GET', 'book/' + C.book ).then( function ( b ) {
		S.book = b;
		setProgress();
		route();
		if ( C.gflag ) {
			toast( { connected: 'Google Docs connected. Your notes are in your Drive.', denied: 'Google Docs was not connected.', error: 'Google Docs could not connect. Please try again.' }[ C.gflag ] || '' );
			try { history.replaceState( null, '', location.pathname + location.hash ); } catch ( e ) {}
		}
		if ( C.thanks !== -1 ) {
			toast( C.thanks && b.owns ? 'Payment received. The whole book is yours.' : 'The payment did not go through. You have not been charged.' );
			try { history.replaceState( null, '', location.pathname + location.hash ); } catch ( e ) {}
		}
	} ).catch( function ( e ) {
		app.innerHTML = '';
		app.appendChild( h( 'div', { class: 'bk-locked' }, [ h( 'h1', { text: 'We could not open the book' } ), h( 'p', { class: 'bk-lead', text: e.message } ), h( 'a', { class: 'bk-btn bk-btn--solid', href: location.href, text: 'Try again' } ) ] ) );
	} );
}() );
