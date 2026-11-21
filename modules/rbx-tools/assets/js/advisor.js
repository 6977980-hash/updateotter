// "What to get next" advisor (SAE). Twin of RBX\Tools\Advisor::compute() and its text helpers;
// the server renders the example, this module recomputes as the player types. DOM nodes only.
import { parseNumber } from './parse-input.js';
import { formatValue } from './format.js';

// Same order as Advisor::order(): income, then the egg that spawns more often, then name.
export function order( a, b ) {
	return ( b.income - a.income ) || ( ( b.chance ?? 0 ) - ( a.chance ?? 0 ) ) || ( a.egg < b.egg ? -1 : ( a.egg > b.egg ? 1 : 0 ) );
}

function row( e, zones, weakest, pass ) {
	return {
		egg: e.egg,
		pet: e.pet,
		url: e.url,
		rarity: e.rarity,
		zone: zones[ e.zone ].name,
		income: e.income,
		chance: e.chance,
		hatch: e.hatch === null ? null : ( pass ? e.hatch / 2 : e.hatch ),
		gain: weakest === null ? null : e.income - weakest,
		times: weakest !== null && weakest > 0 ? e.income / weakest : null,
	};
}

export function compute( data, speed, weakest, total, pass, top, common ) {
	const zones = data.zones;
	let far = null;
	let next = null;
	zones.forEach( ( z, i ) => {
		if ( z.speed <= speed ) {
			far = i;
		} else if ( next === null ) {
			next = i;
		}
	} );
	let hunt = null;
	let jackpot = null;
	let nhunt = null;
	const rows = [];
	let beats = 0;
	for ( const e of data.eggs ) {
		const reach = zones[ e.zone ].speed <= speed;
		const isCommon = e.chance !== null && e.chance >= common;
		if ( ! reach ) {
			if ( nhunt === null && isCommon && e.zone === next ) {
				nhunt = e;
			}
			continue;
		}
		if ( jackpot === null ) {
			jackpot = e;
		}
		if ( hunt === null && isCommon ) {
			hunt = e;
		}
		if ( weakest !== null && e.income <= weakest ) {
			continue;
		}
		beats++;
		if ( rows.length < top ) {
			rows.push( row( e, zones, weakest, pass ) );
		}
	}
	if ( jackpot !== null && hunt !== null && jackpot.income <= hunt.income ) {
		jackpot = null;
	}
	let gain = null;
	if ( hunt !== null && weakest !== null ) {
		const g = hunt.income - weakest;
		gain = {
			gain: g,
			times: weakest > 0 ? hunt.income / weakest : null,
			share: total !== null && total > 0 && g > 0 ? g / total : null,
		};
	}
	return {
		far: far === null ? '' : zones[ far ].name,
		hunt: hunt === null ? null : row( hunt, zones, weakest, pass ),
		jackpot: jackpot === null ? null : row( jackpot, zones, weakest, pass ),
		gain,
		weakest,
		next: next === null ? null : {
			name: zones[ next ].name,
			speed: zones[ next ].speed,
			gap: zones[ next ].speed - speed,
			hunt: nhunt === null ? null : row( nhunt, zones, weakest, pass ),
			times: nhunt !== null && hunt !== null ? nhunt.income / hunt.income : null,
		},
		beats,
		rows,
	};
}

// One placeholder may be written %s (translator convention); it is the first argument.
const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.split( '%' + ( i + 1 ) + '$s' ).join( a ), args.length === 1 ? s.split( '%s' ).join( args[ 0 ] ) : s );

// Text helpers: twins of the private helpers in Advisor.php, so both sides print the same words.
export function texts( r, t, locale, ladder ) {
	const fmt = ( v ) => formatValue( v, 'abbr', locale, ladder );
	const oneIn = ( p ) => formatValue( Math.max( 1, Math.floor( 1 / p + 0.5 ) ), 'int', locale );
	const odds = ( p ) => ( p === null ? t.odds_unknown : fill( t.odds, oneIn( p ) ) );
	const times = ( x ) => formatValue( x, 'decimal(' + ( x < 10 ? 1 : 0 ) + ')', locale ) + '×';
	const pct = ( s ) => {
		const v = s * 100;
		return formatValue( v, 'decimal(' + ( v < 10 ? 1 : 0 ) + ')', locale ) + '%';
	};
	let hunt = t.no_hunt;
	if ( r.hunt !== null ) {
		hunt = fill( t.hunt, r.hunt.egg, r.hunt.zone, r.hunt.pet, fmt( r.hunt.income ), odds( r.hunt.chance ) );
	}
	let jackpot = t.no_jackpot;
	if ( r.jackpot !== null ) {
		const j = r.jackpot;
		jackpot = j.chance === null ? fill( t.jackpot_unknown, j.egg, j.zone, fmt( j.income ) ) : fill( t.jackpot, j.egg, j.zone, fmt( j.income ), odds( j.chance ) );
	}
	let gain;
	if ( r.weakest === null ) {
		gain = t.gain_ask;
	} else if ( r.hunt === null ) {
		gain = t.no_hunt;
	} else if ( r.gain === null || r.gain.gain <= 0 ) {
		gain = t.gain_none + ' ' + ( r.beats > 0 ? t.gain_rare : ( r.next !== null ? t.gain_speed : t.gain_top ) );
	} else {
		const g = r.gain;
		gain = g.times === null ? fill( t.gain_plain, fmt( g.gain ) ) : fill( t.gain, fmt( g.gain ), times( g.times ) );
		if ( g.share !== null ) {
			gain += ' ' + fill( t.share, pct( g.share ) );
		}
	}
	let next = t.all;
	if ( r.next !== null ) {
		const n = r.next;
		next = fill( t.next, n.name, fmt( n.speed ), fmt( n.gap ) );
		if ( n.hunt !== null ) {
			next += ' ' + ( n.times !== null && n.times > 1
				? fill( t.next_hunt, n.hunt.egg, fmt( n.hunt.income ), times( n.times ) )
				: fill( t.next_hunt_plain, n.hunt.egg, fmt( n.hunt.income ) ) );
		}
	}
	const rows = r.rows.map( ( e ) => ( {
		egg: e.egg,
		pet: e.pet,
		url: e.url,
		rarity: e.rarity,
		zone: e.zone,
		income: fill( t.per_s, fmt( e.income ) ),
		odds: e.chance === null ? t.unknown : fill( t.one_in, oneIn( e.chance ) ),
		hatch: e.hatch === null ? t.unknown : formatValue( e.hatch, 'duration', locale ),
		more: e.gain === null ? '—' : '+' + fmt( e.gain ) + ( e.times !== null ? ' (' + times( e.times ) + ')' : '' ),
	} ) );
	return { hunt, jackpot, gain, next, rows, empty: r.next === null ? t.none_all : t.none };
}

function cell( tag, text, label ) {
	const c = document.createElement( tag );
	c.textContent = text;
	if ( tag === 'th' ) {
		c.scope = 'row';
	}
	if ( label ) {
		c.dataset.label = label;
	}
	return c;
}

function init( root ) {
	const cfgEl = root.querySelector( '.rbx-advisor-config' );
	const inputs = {};
	root.querySelectorAll( '[data-rbx-adv]' ).forEach( ( el ) => {
		inputs[ el.dataset.rbxAdv ] = el;
	} );
	if ( ! cfgEl || ! inputs.speed ) {
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

	// A text box: its number, null when left empty (optional boxes only), or false on an error.
	const read = ( key, required ) => {
		const input = inputs[ key ];
		const err = document.getElementById( input.id + '-e' );
		const p = parseNumber( input.value, cfg.locale, cfg.ladder, '', { max: 1e21 } );
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
		const speed = read( 'speed', true );
		const weakest = inputs.weakest ? read( 'weakest', false ) : null;
		const total = inputs.total ? read( 'total', false ) : null;
		if ( speed === false || weakest === false || total === false ) {
			return;
		}
		const pass = !! ( inputs.pass && inputs.pass.checked );
		const r = compute( cfg, speed, weakest, total, pass, cfg.top, cfg.common );
		const x = texts( r, t, cfg.locale, cfg.ladder );
		out.hunt.textContent = x.hunt;
		out.jackpot.textContent = x.jackpot;
		out.gain.textContent = x.gain;
		out.next.textContent = x.next;
		empty.hidden = x.rows.length > 0;
		empty.textContent = x.empty;
		body.replaceChildren(
			...x.rows.map( ( e ) => {
				const tr = document.createElement( 'tr' );
				const th = cell( 'th', e.egg );
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
				if ( e.rarity ) {
					span.append( ' · ' + e.rarity );
				}
				th.append( span );
				tr.append(
					th,
					cell( 'td', e.zone, t.col_biome ),
					cell( 'td', e.income, t.col_income ),
					cell( 'td', e.odds, t.col_odds ),
					cell( 'td', e.hatch, t.col_hatch ),
					cell( 'td', e.more, t.col_more )
				);
				return tr;
			} )
		);
		if ( announce ) {
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = x.hunt + ' ' + x.gain;
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
	document.querySelectorAll( '[data-rbx-advisor]' ).forEach( init );
}
