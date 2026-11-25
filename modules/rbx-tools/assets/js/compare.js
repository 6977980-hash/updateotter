// Pet compare (SAE). Twin of RBX\Tools\PetCompare::compute(), ratio() and lines(); the server
// renders the default pets, this module re-renders as the player picks. DOM nodes only.
import { formatValue } from './format.js';

// One placeholder may be written %s (translator convention); it is the first argument.
const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.split( '%' + ( i + 1 ) + '$s' ).join( a ), args.length === 1 ? s.split( '%s' ).join( args[ 0 ] ) : s );

export function compute( pets, slugs, rows ) {
	const list = slugs.map( ( s ) => pets[ s ] );
	const best = {};
	for ( const row of rows ) {
		if ( row[ 2 ] === '' ) {
			continue;
		}
		const [ dir, key ] = row[ 2 ].split( ':' );
		const vals = new Map();
		list.forEach( ( p, i ) => {
			if ( p[ key ] !== null && p[ key ] !== undefined ) {
				vals.set( i, Number( p[ key ] ) );
			}
		} );
		const all = [ ...vals.values() ];
		if ( all.length < 2 || Math.max( ...all ) === Math.min( ...all ) ) {
			continue;
		}
		const target = dir === 'max' ? Math.max( ...all ) : Math.min( ...all );
		best[ row[ 0 ] ] = [ ...vals.entries() ].filter( ( [ , v ] ) => v === target ).map( ( [ i ] ) => i );
	}
	return { pets: list, best };
}

// PHP round() rounds halves away from zero; Math.round() rounds them up. Same for positives.
const round = ( v, d = 0 ) => {
	const f = 10 ** d;
	return Math.round( v * f ) / f;
};

export function ratio( r, locale, ladder = null ) {
	if ( r >= 1000 ) {
		return formatValue( r, 'abbr', locale, ladder );
	}
	if ( r >= 10 ) {
		return formatValue( round( r ), 'int', locale );
	}
	const v = round( r, 1 );
	return Math.floor( v ) === v ? formatValue( v, 'int', locale ) : formatValue( v, 'decimal(1)', locale );
}

export function lines( r, t, locale, ladder = null, slots = 100 ) {
	const list = r.pets;
	let top = 0;
	list.forEach( ( p, i ) => {
		if ( p.i > list[ top ].i ) {
			top = i;
		}
	} );
	const out = [];
	const s = list[ top ];
	list.forEach( ( p, i ) => {
		if ( i === top ) {
			return;
		}
		const x = s.i / p.i;
		if ( Math.abs( x - 1 ) < 0.05 ) {
			out.push( fill( t.same, s.n, p.n ) );
		} else if ( x >= slots ) {
			out.push( fill( t.times_only, s.n, ratio( x, locale, ladder ), p.n ) );
		} else {
			out.push( fill( t.times, s.n, ratio( x, locale, ladder ), p.n, formatValue( Math.ceil( x - 1e-9 ), 'int', locale ) ) );
		}
	} );
	if ( r.best.hvt && r.best.hvt.length === 1 ) {
		out.push( fill( t.best_hunt, list[ r.best.hvt[ 0 ] ].n ) );
	}
	if ( r.best.spt && r.best.spt.length === 1 ) {
		const e = list[ r.best.spt[ 0 ] ];
		out.push( fill( t.easiest, e.n, e.spt ) );
	}
	return out;
}

function init( root ) {
	const cfgEl = root.querySelector( '.rbx-compare-config' );
	if ( ! cfgEl ) {
		return;
	}
	const cfg = JSON.parse( cfgEl.textContent );
	const t = cfg.i18n;
	const inputs = Array.from( root.querySelectorAll( '[data-rbx-cmp]' ) );
	const ul = root.querySelector( '[data-rbx-lines]' );
	const table = root.querySelector( '[data-rbx-table]' );
	const live = root.querySelector( '[data-rbx-live]' );
	const byName = new Map( Object.entries( cfg.pets ).map( ( [ slug, p ] ) => [ p.n.toLowerCase(), slug ] ) );
	let timer;

	const setErr = ( input, msg ) => {
		const err = document.getElementById( input.id + '-e' );
		err.textContent = msg;
		err.hidden = ! msg;
		if ( msg ) {
			input.setAttribute( 'aria-invalid', 'true' );
		} else {
			input.removeAttribute( 'aria-invalid' );
		}
	};

	const render = ( slugs ) => {
		const r = compute( cfg.pets, slugs, cfg.rows );
		const ls = lines( r, t, cfg.locale, cfg.ladder, cfg.slots );
		ul.replaceChildren( ...ls.map( ( l ) => {
			const li = document.createElement( 'li' );
			li.textContent = l;
			return li;
		} ) );
		const thead = document.createElement( 'thead' );
		const htr = document.createElement( 'tr' );
		htr.append( document.createElement( 'td' ) );
		for ( const p of r.pets ) {
			const th = document.createElement( 'th' );
			th.scope = 'col';
			if ( p.u ) {
				const a = document.createElement( 'a' );
				a.href = p.u;
				a.textContent = p.n;
				th.append( a );
			} else {
				th.textContent = p.n;
			}
			htr.append( th );
		}
		thead.append( htr );
		const tbody = document.createElement( 'tbody' );
		for ( const row of cfg.rows ) {
			const tr = document.createElement( 'tr' );
			const th = document.createElement( 'th' );
			th.scope = 'row';
			th.textContent = t[ row[ 1 ] ];
			tr.append( th );
			r.pets.forEach( ( p, i ) => {
				const td = document.createElement( 'td' );
				td.textContent = String( p[ row[ 0 ] ] );
				if ( ( r.best[ row[ 0 ] ] || [] ).includes( i ) ) {
					td.className = 'rbx-compare__best';
					const mark = document.createElement( 'span' );
					mark.className = 'rbx-compare__mark';
					mark.textContent = t.best;
					td.append( ' ', mark );
				}
				tr.append( td );
			} );
			tbody.append( tr );
		}
		table.replaceChildren( thead, tbody );
		return ls;
	};

	const update = ( announce, write ) => {
		const slugs = [];
		let bad = false;
		inputs.forEach( ( input, i ) => {
			const v = input.value.trim().toLowerCase();
			if ( v === '' ) {
				setErr( input, i < 2 ? t.need_two : '' );
				bad = bad || i < 2;
				return;
			}
			const slug = byName.get( v );
			if ( ! slug ) {
				setErr( input, t.unknown_pet );
				bad = true;
				return;
			}
			if ( slugs.includes( slug ) ) {
				setErr( input, t.twice );
				bad = true;
				return;
			}
			setErr( input, '' );
			slugs.push( slug );
		} );
		if ( bad || slugs.length < 2 ) {
			return;
		}
		const ls = render( slugs );
		if ( write ) {
			window.history.replaceState( null, '', window.location.pathname + '?pets=' + slugs.join( ',' ) + window.location.hash );
		}
		if ( announce ) {
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = ls.join( ' ' );
			}, 600 );
		}
	};

	// A shared link (?pets=a,b,c) fills the boxes.
	const shared = new URL( window.location.href ).searchParams.get( 'pets' );
	if ( shared ) {
		const slugs = shared.split( ',' ).filter( ( s ) => cfg.pets[ s ] ).slice( 0, cfg.max );
		if ( slugs.length >= 2 ) {
			inputs.forEach( ( input, i ) => {
				input.value = slugs[ i ] ? cfg.pets[ slugs[ i ] ].n : '';
			} );
			update( false, false );
		}
	}
	inputs.forEach( ( el ) => {
		el.addEventListener( 'change', () => update( true, true ) );
		el.addEventListener( 'input', () => {
			if ( byName.has( el.value.trim().toLowerCase() ) || el.value.trim() === '' ) {
				update( true, true );
			}
		} );
	} );
	root.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		update( true, true );
	} );
}

if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '[data-rbx-compare]' ).forEach( init );
}
