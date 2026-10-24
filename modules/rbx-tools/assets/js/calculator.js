// Entry: finds [data-rbx-calculator] roots and wires the modules (ENG-31).
import { createUI } from './ui.js';
import { readState, writeState } from './state.js';
import { loadSlices, mergeSlices } from './data-loader.js';

const debounce = ( fn, ms ) => {
	let id;
	return ( ...a ) => {
		clearTimeout( id );
		id = setTimeout( () => fn( ...a ), ms );
	};
};

function init( root, ownsHash ) {
	const cfgEl = root.querySelector( '.rbx-calc-config' );
	if ( ! cfgEl ) {
		return;
	}
	const cfg = JSON.parse( cfgEl.textContent );
	const ui = createUI( root, cfg );
	const notice = root.querySelector( '[data-rbx-notice]' );
	// Built here, not in the page HTML, so text-only readers never quote it as page content.
	let loadError = null;
	const actions = root.querySelector( '[data-rbx-actions]' );
	const schema = { inputs: cfg.inputs, defaults: cfg.defaults };
	let data = { records: cfg.seed.records || {}, params: cfg.seed.params || {} };
	let loaded = false;
	ui.setFormulas( cfg.formulas );

	// Slugs known from the server-rendered options, so links work before the slices arrive.
	const known = ( target, slug ) =>
		cfg.inputs.some( ( d ) => {
			if ( d.target !== target ) {
				return false;
			}
			const c = ui.controls.get( d.key );
			return !! c && ( c.tagName === 'SELECT' ? [ ...c.options ].some( ( o ) => o.value === slug ) : c.value === slug );
		} );

	const showNotices = ( list ) => {
		const text = list.map( ( n ) => cfg.i18n[ n ] ).filter( Boolean ).join( ' ' );
		notice.textContent = text;
		notice.hidden = text === '';
	};

	const saveState = debounce( ( values ) => {
		if ( ownsHash ) {
			history.replaceState( null, '', '#' + writeState( values, schema ) );
		}
	}, 400 );

	const run = ( announce ) => {
		const r = ui.compute( data, { announce, partial: ! loaded } );
		if ( r.ok && announce ) {
			saveState( r.values );
		}
	};

	if ( ownsHash ) {
		const st = readState( window.location.hash, schema, data, known );
		if ( st.present ) {
			ui.write( st.values );
			showNotices( st.notices );
		}
	}
	run( false );

	const onText = debounce( () => run( true ), 150 );
	ui.form.addEventListener( 'input', ( e ) => ( e.target.tagName === 'INPUT' && e.target.type === 'text' ? onText() : run( true ) ) );
	ui.form.addEventListener( 'change', ( e ) => ( e.target.tagName === 'SELECT' || e.target.type === 'checkbox' ) && run( true ) );
	ui.form.addEventListener( 'submit', ( e ) => e.preventDefault() );

	if ( actions ) {
		actions.hidden = false;
		const status = actions.querySelector( '[data-rbx-copy-status]' );
		actions.querySelector( '[data-rbx-copy]' ).addEventListener( 'click', async () => {
			const r = ui.compute( data, { partial: ! loaded } );
			if ( ! r.ok && root.querySelector( '[aria-invalid="true"]' ) ) {
				root.querySelector( '[aria-invalid="true"]' ).focus();
				return;
			}
			if ( ownsHash ) {
				history.replaceState( null, '', '#' + writeState( r.values, schema ) );
			}
			try {
				await navigator.clipboard.writeText( window.location.href );
				status.textContent = cfg.i18n.copied;
			} catch ( e ) {
				status.textContent = cfg.i18n.copyFail;
			}
		} );
		actions.querySelector( '[data-rbx-reset]' ).addEventListener( 'click', () => {
			ui.write( cfg.defaults );
			showNotices( [] );
			if ( ownsHash ) {
				history.replaceState( null, '', window.location.pathname + window.location.search );
			}
			// No announce: an announced run saves the state, which would put the hash right back (UO-36).
			run( false );
		} );
	}

	const fetchData = async () => {
		if ( loadError ) {
			loadError.hidden = true;
		}
		try {
			const slices = await loadSlices( Object.values( cfg.slices ) );
			const merged = mergeSlices( data, slices );
			data = { records: merged.records, params: merged.params };
			if ( merged.formulas ) {
				// Labels and units stay as the page rendered them (translated on /id/ pages).
				ui.setFormulas( merged.formulas.flatMap( ( f ) => {
					const c = cfg.formulas.find( ( x ) => x.key === f.key );
					return c ? [ { ...f, label: c.label, unit: c.unit } ] : [];
				} ) );
			}
			loaded = true;
			run( false );
		} catch ( e ) {
			showLoadError();
			window.dispatchEvent( new CustomEvent( 'rbx:data_load_error', { detail: { game: cfg.game, tool: cfg.tool } } ) );
			// UO-58: a count only (no IP or cookie), so a broken deploy shows up on the server.
			if ( cfg.beacon && navigator.sendBeacon ) {
				navigator.sendBeacon( cfg.beacon, new URLSearchParams( { k: 'calc_load', g: cfg.game } ) );
			}
		}
	};
	const showLoadError = () => {
		if ( ! loadError ) {
			loadError = document.createElement( 'div' );
			loadError.className = 'rbx-calc__error';
			const p = document.createElement( 'p' );
			p.textContent = cfg.i18n.loadError;
			const retry = document.createElement( 'button' );
			retry.type = 'button';
			retry.className = 'button';
			retry.textContent = cfg.i18n.retry;
			retry.addEventListener( 'click', fetchData );
			loadError.append( p, retry );
			( root.querySelector( '.rbx-calc__info' ) || root ).append( loadError );
		}
		loadError.hidden = false;
	};
	const start = () => fetchData();
	if ( 'requestIdleCallback' in window ) {
		requestIdleCallback( start, { timeout: 2000 } );
	} else {
		setTimeout( start, 200 );
	}
}

let hashOwned = false;
document.querySelectorAll( '[data-rbx-calculator]' ).forEach( ( root ) => {
	const mini = root.classList.contains( 'rbx-calc--mini' );
	init( root, ! mini && ! hashOwned );
	hashOwned ||= ! mini;
} );
