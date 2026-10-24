// Speed calculator (GR-25). Twin of RBX\Tools\SpeedCalculator::compute(); the server renders
// the example Speed, this module recomputes as the player types. DOM nodes only.
import { parseNumber } from './parse-input.js';
import { formatValue } from './format.js';

export function compute( data, speed, top ) {
	let far = 0;
	let next = null;
	data.zones.forEach( ( z, i ) => {
		if ( z.speed <= speed ) {
			far = i;
		} else if ( next === null ) {
			next = z;
		}
	} );
	const gap = next === null ? 0 : next.speed - speed;
	const eggs = [];
	for ( const e of data.eggs ) {
		if ( eggs.length >= top ) {
			break;
		}
		if ( data.zones[ e.zone ].speed > speed ) {
			continue;
		}
		eggs.push( {
			name: e.name,
			zone: data.zones[ e.zone ].name,
			reward: e.reward,
			need: next === null ? null : Math.ceil( gap / e.reward ),
		} );
	}
	return { far: data.zones[ far ].name, next, gap, eggs };
}

const fill = ( s, ...args ) => args.reduce( ( out, a, i ) => out.replace( '%' + ( i + 1 ) + '$s', a ), s );

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
	const cfgEl = root.querySelector( '.rbx-speed-config' );
	const input = root.querySelector( '[data-rbx-speed-input]' );
	if ( ! cfgEl || ! input ) {
		return;
	}
	const cfg = JSON.parse( cfgEl.textContent );
	const t = cfg.i18n;
	const fmt = ( v ) => formatValue( v, 'abbr', cfg.locale, cfg.ladder );
	const err = document.getElementById( input.getAttribute( 'aria-describedby' ) );
	const far = root.querySelector( '[data-rbx-out="far"]' );
	const nextEl = root.querySelector( '[data-rbx-out="next"]' );
	const body = root.querySelector( '[data-rbx-eggs]' );
	const live = root.querySelector( '[data-rbx-live]' );
	const rows = root.querySelectorAll( '[data-rbx-zone]' );
	let timer;

	const update = ( announce ) => {
		const p = parseNumber( input.value, cfg.locale, cfg.ladder, '', { max: 1e21 } );
		if ( ! p.ok ) {
			err.textContent = t[ p.error ] || t.invalid;
			err.hidden = false;
			input.setAttribute( 'aria-invalid', 'true' );
			return;
		}
		err.hidden = true;
		input.removeAttribute( 'aria-invalid' );
		const r = compute( cfg, p.value, cfg.top );
		const nextText = r.next === null ? t.all : fill( t.next, r.next.name, fmt( r.next.speed ), fmt( r.gap ) );
		far.textContent = r.far;
		nextEl.textContent = nextText;
		body.replaceChildren(
			...r.eggs.map( ( e ) => {
				const tr = document.createElement( 'tr' );
				tr.append(
					cell( 'th', e.name ),
					cell( 'td', e.zone, t.biome ),
					cell( 'td', fmt( e.reward ), t.reward ),
					cell( 'td', e.need === null ? '—' : formatValue( e.need, 'int', cfg.locale ), t.need )
				);
				return tr;
			} )
		);
		rows.forEach( ( tr ) => {
			const ok = cfg.zones[ Number( tr.dataset.rbxZone ) ].speed <= p.value;
			tr.classList.toggle( 'is-reached', ok );
			tr.querySelector( '[data-rbx-reach]' ).textContent = ok ? t.yes : t.no;
		} );
		if ( announce ) {
			clearTimeout( timer );
			timer = setTimeout( () => {
				live.textContent = fill( t.live, r.far, nextText );
			}, 600 );
		}
	};

	input.addEventListener( 'input', () => update( true ) );
	root.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		update( true );
	} );
}

if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '[data-rbx-speed]' ).forEach( init );
}
