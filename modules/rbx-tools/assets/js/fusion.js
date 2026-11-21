// Fusion planner. Twin of RBX\Tools\FusionPlanner::compute(); the server renders the planner
// with nothing ticked and this module recomputes as the player ticks. DOM nodes only.
import { formatValue } from './format.js';

export function compute( data, owned ) {
	const rows = data.recipes.map( ( rec, ri ) => {
		const missing = [];
		let times = Infinity;
		let cost = 0;
		let slots = -1;
		for ( const inp of rec.inputs ) {
			const have = Math.max( 0, Math.trunc( Number( owned[ inp.pet ] ) || 0 ) );
			if ( have < inp.qty ) {
				missing.push( { pet: inp.pet, need: inp.qty - have } );
			}
			times = Math.min( times, Math.floor( have / inp.qty ) );
			const inc = data.pets[ inp.pet ].income;
			cost = cost === null || inc === null ? null : cost + inc * inp.qty;
			slots += inp.qty;
		}
		return {
			recipe: ri,
			ready: missing.length === 0,
			times: times === Infinity ? 0 : times,
			missing,
			cost,
			change: cost === null || rec.income === null ? null : rec.income - cost,
			slots,
		};
	} );
	rows.sort( ( a, b ) => {
		if ( a.ready !== b.ready ) {
			return a.ready ? -1 : 1;
		}
		if ( a.missing.length !== b.missing.length ) {
			return a.missing.length - b.missing.length;
		}
		if ( a.change !== b.change ) {
			if ( a.change === null || b.change === null ) {
				return a.change === null ? 1 : -1;
			}
			return b.change - a.change;
		}
		return a.recipe - b.recipe;
	} );
	const need = new Map();
	for ( const row of rows ) {
		for ( const m of row.missing ) {
			if ( ! need.has( m.pet ) ) {
				need.set( m.pet, { pet: m.pet, need: 0, for: [] } );
			}
			const n = need.get( m.pet );
			n.need = Math.max( n.need, m.need );
			n.for.push( row.recipe );
		}
	}
	return {
		ready: rows.filter( ( r ) => r.ready ).length,
		total: rows.length,
		rows,
		missing: [ ...need.values() ],
	};
}

const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.replace( '%' + ( i + 1 ) + '$s', a ).replace( '%s', a ), s );

export function statusText( row, t, num ) {
	if ( row.ready ) {
		return row.times > 1 ? fill( t.ready_n, num( row.times ) ) : t.ready;
	}
	const n = row.missing.reduce( ( s, m ) => s + m.need, 0 );
	return n === 1 ? t.need_1 : fill( t.need_n, num( n ) );
}

export function changeText( row, fmt, t, num ) {
	const slots = row.slots === 1 ? t.slot_1 : fill( t.slot_n, num( row.slots ) );
	if ( row.change === null ) {
		return t.unknown + ' ' + slots;
	}
	if ( row.change >= 0 ) {
		return fill( t.gain, fmt( row.change ) ) + ' ' + slots;
	}
	return fill( t.loss, fmt( -row.change ) ) + ' ' + slots + ' ' + fill( t.worth, fmt( -row.change ) );
}

function el( tag, text, cls ) {
	const e = document.createElement( tag );
	if ( text !== undefined && text !== null ) {
		e.textContent = text;
	}
	if ( cls ) {
		e.className = cls;
	}
	return e;
}

function nameNode( name, url ) {
	if ( ! url ) {
		return document.createTextNode( name );
	}
	const a = el( 'a', name );
	a.href = url;
	return a;
}

function init( root ) {
	const cfgEl = root.querySelector( '.rbx-fusion-config' );
	if ( ! cfgEl ) {
		return;
	}
	const cfg = JSON.parse( cfgEl.textContent );
	const t = cfg.i18n;
	const num = ( v ) => formatValue( v, 'int', cfg.locale );
	const fmt = ( v ) => ( v === null ? '—' : formatValue( v, 'abbr', cfg.locale, cfg.ladder ) );
	const inputs = [ ...root.querySelectorAll( '[data-rbx-pet]' ) ];
	const summary = root.querySelector( '[data-rbx-out="summary"]' );
	const recBody = root.querySelector( '[data-rbx-recipes]' );
	const missBody = root.querySelector( '[data-rbx-missing]' );
	const live = root.querySelector( '[data-rbx-live]' );
	const key = 'uo-fusion-' + cfg.game;
	let timer;

	const owned = () => {
		const o = cfg.pets.map( () => 0 );
		for ( const inp of inputs ) {
			const i = Number( inp.dataset.rbxPet );
			if ( inp.type === 'checkbox' ) {
				o[ i ] = inp.checked ? 1 : 0;
			} else {
				const v = Math.trunc( Number( inp.value ) );
				o[ i ] = Number.isFinite( v ) ? Math.min( cfg.max, Math.max( 0, v ) ) : 0;
			}
		}
		return o;
	};

	const save = ( o ) => {
		try {
			const names = {};
			o.forEach( ( n, i ) => {
				if ( n > 0 ) {
					names[ cfg.pets[ i ].name ] = n;
				}
			} );
			window.localStorage.setItem( key, JSON.stringify( names ) );
		} catch ( e ) {
			// Storage blocked (private window): the planner still works, it just forgets.
		}
	};

	const restore = () => {
		let names = null;
		try {
			names = JSON.parse( window.localStorage.getItem( key ) || 'null' );
		} catch ( e ) {
			names = null;
		}
		if ( ! names || typeof names !== 'object' ) {
			return false;
		}
		let any = false;
		for ( const inp of inputs ) {
			const n = Number( names[ cfg.pets[ Number( inp.dataset.rbxPet ) ].name ] ) || 0;
			if ( n > 0 ) {
				any = true;
			}
			if ( inp.type === 'checkbox' ) {
				inp.checked = n > 0;
			} else {
				inp.value = String( Math.min( cfg.max, Math.max( 0, Math.trunc( n ) ) ) );
			}
		}
		return any;
	};

	const update = ( announce ) => {
		const o = owned();
		const r = compute( cfg, o );
		const sum = r.ready === 0 ? fill( t.none, num( r.total ) ) : fill( t.some, num( r.ready ), num( r.total ) );
		summary.textContent = sum;
		recBody.replaceChildren(
			...r.rows.map( ( row ) => {
				const rec = cfg.recipes[ row.recipe ];
				const miss = new Set( row.missing.map( ( m ) => m.pet ) );
				const tr = el( 'tr' );
				if ( row.ready ) {
					tr.className = 'is-ready';
				}
				const th = el( 'th' );
				th.scope = 'row';
				th.append( nameNode( rec.name, rec.url ) );
				if ( rec.income !== null ) {
					th.append( el( 'br' ), el( 'span', fill( t.per_s, fmt( rec.income ) ), 'rbx-fusion__inc' ) );
				}
				const ins = el( 'td' );
				ins.dataset.label = t.ingredients;
				const ul = el( 'ul', null, 'rbx-fusion__ins' );
				for ( const inp of rec.inputs ) {
					const p = cfg.pets[ inp.pet ];
					const li = el( 'li' );
					if ( inp.qty > 1 ) {
						li.append( num( inp.qty ) + ' × ' );
					}
					li.append( nameNode( p.name, p.url ), ' ', el( 'span', miss.has( inp.pet ) ? t.missing : t.have, miss.has( inp.pet ) ? 'rbx-fusion__no' : 'rbx-fusion__yes' ) );
					ul.append( li );
				}
				ins.append( ul );
				if ( rec.station ) {
					ins.append( el( 'span', rec.station, 'rbx-fusion__station' ) );
				}
				if ( rec.also ) {
					ins.append( el( 'span', fill( t.also, rec.also ), 'rbx-fusion__station' ) );
				}
				const st = el( 'td', statusText( row, t, num ) );
				st.dataset.label = t.status;
				const ch = el( 'td', changeText( row, fmt, t, num ) );
				ch.dataset.label = t.change;
				tr.append( th, ins, st, ch );
				return tr;
			} )
		);
		const mrows = r.missing.map( ( m ) => {
			const p = cfg.pets[ m.pet ];
			const tr = el( 'tr' );
			const th = el( 'th' );
			th.scope = 'row';
			if ( m.need > 1 ) {
				th.append( num( m.need ) + ' × ' );
			}
			th.append( nameNode( p.name, p.url ) );
			const cells = [
				[ m.for.map( ( ri ) => cfg.recipes[ ri ].name ).join( ', ' ), t.for ],
				[ p.income === null ? '—' : fill( t.per_s, fmt( p.income ) ), t.income ],
				[ p.how || '—', t.how ],
			].map( ( [ text, label ] ) => {
				const td = el( 'td', text );
				td.dataset.label = label;
				return td;
			} );
			tr.append( th, ...cells );
			return tr;
		} );
		if ( mrows.length === 0 ) {
			const tr = el( 'tr' );
			const td = el( 'td', t.all );
			td.colSpan = 4;
			tr.append( td );
			mrows.push( tr );
		}
		missBody.replaceChildren( ...mrows );
		if ( announce ) {
			save( o );
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = sum;
			}, 600 );
		}
	};

	for ( const inp of inputs ) {
		inp.addEventListener( inp.type === 'checkbox' ? 'change' : 'input', () => update( true ) );
	}
	const form = root.querySelector( 'form' );
	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		update( true );
	} );
	// The reset button clears the boxes first, then the planner reads them.
	form.addEventListener( 'reset', () => setTimeout( () => update( true ), 0 ) );
	if ( restore() ) {
		update( false );
	}
}

if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '[data-rbx-fusion]' ).forEach( init );
}
