// "What can I catch here?" (Fisch). Twin of RBX\Tools\CatchFinder::compute() and its text helpers;
// the server renders the example, this module recomputes as the player changes a pick. DOM nodes only.
import { formatValue } from './format.js';

const DIMS = [ 'time', 'weather', 'season' ];

// Same order as CatchFinder::order(): value (TBA last), then rarity (rarest first), then name.
export function order( a, b ) {
	if ( ( a.v === null ) !== ( b.v === null ) ) {
		return a.v === null ? 1 : -1;
	}
	const c = ( b.v ?? 0 ) - ( a.v ?? 0 );
	if ( c !== 0 ) {
		return c > 0 ? 1 : -1;
	}
	if ( b.ro !== a.ro ) {
		return b.ro > a.ro ? 1 : -1;
	}
	return a.name < b.name ? -1 : ( a.name > b.name ? 1 : 0 );
}

export function compute( data, pick ) {
	const rows = [];
	let here = 0;
	let only = 0;
	for ( const f of data.fish ) {
		if ( ! f.l.includes( pick.spot ) ) {
			continue;
		}
		here++;
		let ok = true;
		let needs = false;
		let unknown = false;
		for ( const key of DIMS ) {
			const want = pick[ key ] || '';
			const has = f[ key ];
			if ( has === null ) {
				unknown = true;
				continue;
			}
			if ( want === '' || has.length === 0 ) {
				continue;
			}
			if ( has.includes( want ) ) {
				needs = true;
			} else {
				ok = false;
			}
		}
		if ( ! ok ) {
			continue;
		}
		only += needs ? 1 : 0;
		rows.push( { ...f, needs, unknown } );
	}
	rows.sort( order );
	const best = rows.find( ( r ) => r.v !== null ) || null;
	return { rows, here, other: here - rows.length, only, best };
}

// One placeholder may be written %s (translator convention); it is the first argument.
const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.split( '%' + ( i + 1 ) + '$s' ).join( a ), args.length === 1 ? s.split( '%s' ).join( args[ 0 ] ) : s );

export function needsText( f, t ) {
	const parts = [];
	for ( const key of DIMS ) {
		if ( Array.isArray( f[ key ] ) && f[ key ].length ) {
			parts.push( f[ key ].join( ' ' + t.or + ' ' ) );
		}
	}
	let txt = parts.length ? parts.join( ', ' ) : t.any;
	if ( f.unknown ) {
		txt += ' (' + t.unknown + ')';
	}
	return txt;
}

export function texts( r, t, locale, ladder ) {
	const fmt = ( v ) => formatValue( v, 'abbr', locale, ladder );
	const int = ( n ) => formatValue( n, 'int', locale );
	const n = r.rows.length;
	let count = fill( n === 1 ? t.count_one : t.count, int( n ) );
	if ( r.other > 0 ) {
		count += ' ' + fill( r.other === 1 ? t.other_one : t.other, int( r.other ) );
	}
	const best = r.best === null ? t.no_best : fill( t.best, r.best.name, fmt( r.best.v ) );
	const only = r.only === 0 ? t.no_only : fill( r.only === 1 ? t.only_one : t.only, int( r.only ) );
	const rows = r.rows.map( ( f ) => ( {
		name: f.name,
		url: f.url,
		r: f.r === '' ? '—' : f.r,
		needs: needsText( f, t ),
		now: f.needs ? t.now : '',
		b: f.b === null ? '—' : ( f.b === '' ? t.any_bait : f.b ),
		v: f.v === null ? t.tba : fmt( f.v ),
	} ) );
	return { count, best, only, rows };
}

function cell( tag, text, label ) {
	const c = document.createElement( tag );
	c.textContent = text;
	if ( label ) {
		c.dataset.label = label;
	}
	return c;
}

function init( root ) {
	const cfgEl = root.querySelector( '.rbx-catch-config' );
	const inputs = {};
	root.querySelectorAll( '[data-rbx-catch]' ).forEach( ( el ) => {
		inputs[ el.dataset.rbxCatch ] = el;
	} );
	if ( ! cfgEl || ! inputs.spot ) {
		return;
	}
	const cfg = JSON.parse( cfgEl.textContent );
	const t = cfg.i18n;
	const out = {};
	root.querySelectorAll( '[data-rbx-out]' ).forEach( ( el ) => {
		out[ el.dataset.rbxOut ] = el;
	} );
	const body = root.querySelector( '[data-rbx-rows]' );
	const empty = root.querySelector( '[data-rbx-empty]' );
	const live = root.querySelector( '[data-rbx-live]' );
	let timer;

	// Picks are kept in the URL fragment so a result can be shared: #spot=2&weather=Rain.
	const readHash = () => {
		const p = new URLSearchParams( window.location.hash.replace( /^#/, '' ) );
		for ( const key of [ 'spot', ...DIMS ] ) {
			const v = p.get( key );
			if ( v !== null && [ ...inputs[ key ].options ].some( ( o ) => o.value === v ) ) {
				inputs[ key ].value = v;
			}
		}
	};

	const update = ( announce ) => {
		const pick = { spot: parseInt( inputs.spot.value, 10 ) || 0 };
		DIMS.forEach( ( k ) => {
			pick[ k ] = inputs[ k ] ? inputs[ k ].value : '';
		} );
		const x = texts( compute( cfg, pick ), t, cfg.locale, cfg.ladder );
		out.count.textContent = x.count;
		out.best.textContent = x.best;
		out.only.textContent = x.only;
		empty.hidden = x.rows.length > 0;
		body.replaceChildren(
			...x.rows.map( ( e ) => {
				const tr = document.createElement( 'tr' );
				const th = document.createElement( 'th' );
				th.scope = 'row';
				th.className = 'rbx-cell-name';
				if ( e.url ) {
					const a = document.createElement( 'a' );
					a.href = e.url;
					a.textContent = e.name;
					th.append( a );
				} else {
					th.textContent = e.name;
				}
				const needs = cell( 'td', e.needs, t.col_needs );
				if ( e.now ) {
					const chip = document.createElement( 'span' );
					chip.className = 'rbx-chip';
					chip.textContent = e.now;
					needs.append( ' ', chip );
				}
				tr.append( th, cell( 'td', e.r, t.col_rarity ), needs, cell( 'td', e.b, t.col_bait ), cell( 'td', e.v, t.col_value ) );
				return tr;
			} )
		);
		if ( announce ) {
			const p = new URLSearchParams();
			p.set( 'spot', String( pick.spot ) );
			DIMS.forEach( ( k ) => pick[ k ] && p.set( k, pick[ k ] ) );
			history.replaceState( null, '', '#' + p.toString() );
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = x.count + ' ' + x.best;
			}, 600 );
		}
	};

	Object.values( inputs ).forEach( ( el ) => el.addEventListener( 'change', () => update( true ) ) );
	root.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		update( true );
	} );
	if ( window.location.hash ) {
		readHash();
		update( false );
	}
}

if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '[data-rbx-catch-finder]' ).forEach( init );
}
