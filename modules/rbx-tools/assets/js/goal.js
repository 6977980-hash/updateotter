// Time-to-goal calculator (SAE). Twin of RBX\Tools\Goal::after(), compute(), span() and texts();
// the server renders the example, this module recomputes as the player types. DOM nodes only.
import { parseNumber } from './parse-input.js';
import { formatValue } from './format.js';

// One placeholder may be written %s (translator convention); it is the first argument.
const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.split( '%' + ( i + 1 ) + '$s' ).join( a ), args.length === 1 ? s.split( '%s' ).join( args[ 0 ] ) : s );

// Seconds to earn `need` at `income`, when income becomes `next` after `wait` seconds.
export function after( need, income, wait, next ) {
	if ( need <= 0 ) {
		return 0;
	}
	if ( income > 0 && income * wait >= need ) {
		return need / income;
	}
	if ( next <= 0 ) {
		return null;
	}
	return wait + ( need - Math.max( 0, income ) * wait ) / next;
}

export function compute( data, cash, goal, income, speed, weakest, pass, top, common ) {
	const need = Math.max( 0, goal - cash );
	const now = need <= 0 ? 0 : ( income > 0 ? need / income : null );
	let rows = [];
	for ( const e of data.eggs ) {
		if ( data.zones[ e.zone ].speed > speed || ( weakest !== null && e.income <= weakest ) ) {
			continue;
		}
		const hatch = e.hatch === null ? 0 : ( pass ? e.hatch / 2 : e.hatch );
		const next = income - ( weakest ?? 0 ) + e.income;
		const time = after( need, income, hatch, next );
		if ( time === null ) {
			continue;
		}
		rows.push( {
			egg: e.egg,
			pet: e.pet,
			url: e.url,
			zone: data.zones[ e.zone ].name,
			income: e.income,
			chance: e.chance,
			hatch: e.hatch === null ? null : hatch,
			time,
			saved: now === null ? null : now - time,
		} );
	}
	rows.sort( ( a, b ) => ( a.time - b.time ) || ( ( b.chance ?? 0 ) - ( a.chance ?? 0 ) ) || ( a.egg < b.egg ? -1 : ( a.egg > b.egg ? 1 : 0 ) ) );
	const best = rows.find( ( r ) => r.chance !== null && r.chance >= common && ( now === null || r.time < now ) ) || null;
	rows = rows.slice( 0, top );
	return { need, now, best, rows };
}

export function span( s, t, locale, ladder ) {
	s = Math.max( 0, s );
	if ( s >= 365 * 86400 ) {
		const y = s / ( 365 * 86400 );
		return fill( t.years, y >= 1000 ? formatValue( y, 'abbr', locale, ladder ) : formatValue( Math.round( y ), 'int', locale ) );
	}
	const n = Math.floor( s + 0.5 );
	const pad = ( x ) => String( x ).padStart( 2, '0' );
	if ( n < 60 ) {
		return n + 's';
	}
	if ( n < 3600 ) {
		return Math.floor( n / 60 ) + 'm ' + pad( n % 60 ) + 's';
	}
	if ( n < 86400 ) {
		return Math.floor( n / 3600 ) + 'h ' + pad( Math.floor( ( n % 3600 ) / 60 ) ) + 'm';
	}
	return Math.floor( n / 86400 ) + 'd ' + Math.floor( ( n % 86400 ) / 3600 ) + 'h';
}

export function texts( r, t, locale, ladder ) {
	const sp = ( s ) => span( s, t, locale, ladder );
	const fmt = ( v ) => formatValue( v, 'abbr', locale, ladder );
	let now;
	if ( r.need <= 0 ) {
		now = t.done;
	} else if ( r.now === null ) {
		now = t.no_income;
	} else {
		now = sp( r.now );
	}
	let best = t.no_best;
	if ( r.need <= 0 ) {
		best = t.done;
	} else if ( r.best !== null ) {
		const b = r.best;
		best = b.saved === null ? fill( t.best_plain, b.egg, b.zone, sp( b.time ) ) : fill( t.best, b.egg, b.zone, sp( b.time ), sp( b.saved ) );
	}
	const rows = r.rows.map( ( e ) => ( {
		egg: e.egg,
		pet: e.pet,
		url: e.url,
		zone: e.zone,
		income: fill( t.per_s, fmt( e.income ) ),
		odds: e.chance === null ? t.unknown : fill( t.one_in, formatValue( Math.max( 1, Math.floor( 1 / e.chance + 0.5 ) ), 'int', locale ) ),
		hatch: e.hatch === null ? t.unknown : sp( e.hatch ),
		time: sp( e.time ),
		saved: e.saved === null ? '—' : ( e.saved > 0 ? sp( e.saved ) : t.not_sooner ),
	} ) );
	return { now, best, empty: r.need <= 0 ? t.done : t.none, rows: r.need <= 0 ? [] : rows };
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
	const cfgEl = root.querySelector( '.rbx-goal-config' );
	const inputs = {};
	root.querySelectorAll( '[data-rbx-goal]' ).forEach( ( el ) => {
		inputs[ el.dataset.rbxGoal ] = el;
	} );
	if ( ! cfgEl || ! inputs.goal ) {
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

	const read = ( key, required ) => {
		const input = inputs[ key ];
		const err = document.getElementById( input.id + '-e' );
		const p = parseNumber( input.value, cfg.locale, cfg.ladder, '', { max: 1e30 } );
		if ( ! p.ok && p.error === 'empty' && ! required ) {
			err.hidden = true;
			input.removeAttribute( 'aria-invalid' );
			return null;
		}
		if ( ! p.ok ) {
			err.textContent = t[ p.error ] || t.invalid;
			err.hidden = false;
			input.setAttribute( 'aria-invalid', 'true' );
			return false;
		}
		err.hidden = true;
		input.removeAttribute( 'aria-invalid' );
		return p.value;
	};

	const update = ( announce ) => {
		const goal = read( 'goal', true );
		const cash = read( 'cash', false );
		const income = read( 'income', true );
		const speed = read( 'speed', true );
		const weakest = read( 'weakest', false );
		if ( [ goal, cash, income, speed, weakest ].includes( false ) ) {
			return;
		}
		const pass = !! ( inputs.pass && inputs.pass.checked );
		const r = compute( cfg, cash ?? 0, goal, income, speed, weakest, pass, cfg.top, cfg.common );
		const x = texts( r, t, cfg.locale, cfg.ladder );
		out.now.textContent = x.now;
		out.best.textContent = x.best;
		empty.hidden = x.rows.length > 0;
		empty.textContent = x.empty;
		body.replaceChildren(
			...x.rows.map( ( e ) => {
				const tr = document.createElement( 'tr' );
				const th = cell( 'th', e.egg );
				th.scope = 'row';
				th.className = 'rbx-cell-name';
				const span = document.createElement( 'span' );
				span.className = 'rbx-advisor__pet';
				span.append( t.hatches + ' ' );
				if ( e.url ) {
					const a = document.createElement( 'a' );
					a.href = e.url;
					a.textContent = e.pet;
					span.append( a );
				} else {
					span.append( e.pet );
				}
				span.append( ' · ' + e.zone );
				th.append( span );
				tr.append(
					th,
					cell( 'td', e.income, t.col_income ),
					cell( 'td', e.odds, t.col_odds ),
					cell( 'td', e.hatch, t.col_hatch ),
					cell( 'td', e.time, t.col_time ),
					cell( 'td', e.saved, t.col_saved )
				);
				return tr;
			} )
		);
		if ( announce ) {
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = x.now + '. ' + x.best;
			}, 600 );
		}
	};

	Object.values( inputs ).forEach( ( el ) => {
		el.addEventListener( el.type === 'checkbox' ? 'change' : 'input', () => update( true ) );
	} );
	root.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		update( true );
	} );
}

if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '[data-rbx-goal-tool]' ).forEach( init );
}
