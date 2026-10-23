// DOM binding for one calculator root (ENG-32 to ENG-36). Text only via textContent.
import { compile, evaluate, Unavailable } from './formula-engine.js';
import { formatValue, formatExact } from './format.js';
import { parseNumber } from './parse-input.js';
import { validateInputs } from './validate.js';

export function createUI( root, cfg ) {
	const t = cfg.i18n;
	const form = root.querySelector( 'form' );
	const live = root.querySelector( '[data-rbx-live]' );
	const fix = root.querySelector( '[data-rbx-fix]' );
	const outs = new Map( [ ...root.querySelectorAll( '[data-rbx-output]' ) ].map( ( n ) => [ n.dataset.rbxOutput, n ] ) );
	const controls = new Map( [ ...form.querySelectorAll( '[data-rbx-input]' ) ].map( ( n ) => [ n.dataset.rbxInput, n ] ) );
	const suffixes = new Map( [ ...form.querySelectorAll( '[data-rbx-suffix]' ) ].map( ( n ) => [ n.dataset.rbxSuffix, n ] ) );
	const decl = Object.fromEntries( cfg.inputs.map( ( d ) => [ d.key, d ] ) );
	let compiled = [];
	let lastSpoken = 0;
	let speakTimer = null;

	function setFormulas( list ) {
		compiled = list.map( ( f ) => {
			if ( f.na || ! f.expr ) {
				return { ...f, ast: null };
			}
			try {
				return { ...f, ast: compile( f.expr, f.inputs || {} ) };
			} catch ( e ) {
				return { ...f, ast: null, na: 'syntax' };
			}
		} );
	}

	// slug (or option data-id) -> record ID for the engine.
	function idFor( key, slug ) {
		const c = controls.get( key );
		if ( ! c || slug === '' ) {
			return '';
		}
		if ( c.tagName === 'SELECT' ) {
			const o = [ ...c.options ].find( ( x ) => x.value === slug );
			return o ? o.dataset.id || '' : '';
		}
		return c.dataset.id || '';
	}

	function setError( key, code ) {
		const c = controls.get( key );
		const e = c && c.id ? root.querySelector( '#' + CSS.escape( c.id + '-e' ) ) : null;
		if ( ! c ) {
			return;
		}
		if ( code ) {
			c.setAttribute( 'aria-invalid', 'true' );
			if ( e ) {
				e.textContent = t[ code ] || t.invalid;
				e.hidden = false;
			}
		} else {
			c.removeAttribute( 'aria-invalid' );
			if ( e ) {
				e.hidden = true;
				e.textContent = '';
			}
		}
	}

	// Reads the form: { values, errors }.
	function read() {
		const values = {};
		const errors = {};
		for ( const [ key, c ] of controls ) {
			const d = decl[ key ];
			if ( ! d ) {
				continue;
			}
			if ( d.type === 'ref' ) {
				values[ key ] = c.value;
			} else if ( d.type === 'bool' ) {
				values[ key ] = c.checked;
			} else if ( d.optional && c.value.trim() === '' ) {
				values[ key ] = null;
			} else {
				const s = suffixes.get( key );
				const r = parseNumber( c.value, cfg.locale, cfg.ladder, s ? s.value : '', {
					integer: !! d.integer,
					min: d.min,
					max: d.max,
					allow_negative: !! d.allow_negative,
					mult: d.format === 'mult',
				} );
				if ( r.ok ) {
					values[ key ] = r.value;
				} else {
					errors[ key ] = r.error;
				}
			}
		}
		const v = validateInputs( values, { inputs: cfg.inputs.filter( ( d ) => ! ( d.key in errors ) ) } );
		for ( const e of v.errors ) {
			errors[ e.key ] = e.code;
		}
		return { values, errors };
	}

	function write( values ) {
		for ( const [ key, c ] of controls ) {
			const d = decl[ key ];
			if ( ! d || ! ( key in values ) || cfg.fixed.includes( key ) ) {
				continue;
			}
			if ( d.type === 'bool' ) {
				c.checked = !! values[ key ];
			} else if ( d.type === 'ref' ) {
				c.value = values[ key ];
			} else {
				c.value = typeof values[ key ] === 'number' ? formatValue( values[ key ], d.integer ? 'int' : 'plain', cfg.locale ) : '';
				const s = suffixes.get( key );
				if ( s ) {
					s.value = '';
				}
			}
			setError( key, null );
		}
	}

	function show( node, text, exact, unit ) {
		const dd = node.querySelector( 'dd' );
		dd.replaceChildren();
		const v = document.createElement( 'span' );
		if ( exact === null ) {
			v.className = 'rbx-calc__na';
			v.textContent = text;
			dd.append( v );
			return;
		}
		v.className = 'rbx-calc__v';
		v.dataset.exact = exact;
		if ( text === exact ) {
			v.textContent = text;
		} else {
			const a = document.createElement( 'span' );
			a.setAttribute( 'aria-hidden', 'true' );
			a.textContent = text;
			const b = document.createElement( 'span' );
			b.className = 'screen-reader-text';
			b.textContent = exact;
			v.append( a, b );
		}
		dd.append( v );
		if ( unit ) {
			const u = document.createElement( 'span' );
			u.className = 'rbx-calc__unit';
			u.textContent = unit;
			dd.append( ' ', u );
		}
	}

	function speak( sentence ) {
		clearTimeout( speakTimer );
		const wait = Math.max( 0, 1000 - ( Date.now() - lastSpoken ) );
		speakTimer = setTimeout( () => {
			live.textContent = sentence;
			lastSpoken = Date.now();
		}, wait );
	}

	// Recalculates every output; data = { records, params }. Returns false while a record is not loaded yet.
	function compute( data, opts = {} ) {
		const { values, errors } = read();
		for ( const key of controls.keys() ) {
			setError( key, errors[ key ] || null );
		}
		const bad = Object.keys( errors ).length > 0;
		fix.hidden = ! bad;
		root.classList.toggle( 'is-stale', bad );
		if ( bad ) {
			return { values, ok: false };
		}
		const engineInputs = {};
		for ( const d of cfg.inputs ) {
			engineInputs[ d.key ] = d.type === 'ref' ? idFor( d.key, values[ d.key ] ?? '' ) : values[ d.key ];
		}
		let first = null;
		let waiting = false;
		for ( const f of compiled ) {
			const node = outs.get( f.key );
			if ( ! node ) {
				continue;
			}
			if ( ! f.ast ) {
				show( node, f.na === 'recheck' ? t.recheck : t.na, null );
				continue;
			}
			try {
				const v = evaluate( f.ast, engineInputs, data );
				const text = formatValue( v, f.format, cfg.locale, cfg.ladder );
				show( node, text, formatExact( v, cfg.locale ), f.unit );
				first ??= text + ( f.unit ? ' ' + f.unit : '' );
			} catch ( e ) {
				if ( e instanceof Unavailable && e.reason === 'record' && opts.partial ) {
					waiting = true;
					continue;
				}
				const r = e instanceof Unavailable ? e.reason : '';
				show( node, r.startsWith( 'state_' ) && r !== 'state_m' ? t.unknown : t.na, null );
			}
		}
		if ( first !== null && opts.announce ) {
			speak( t.result + ' ' + first );
		}
		return { values, ok: ! waiting };
	}

	return { setFormulas, read, write, compute, controls, form };
}
