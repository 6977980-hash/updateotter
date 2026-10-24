// Header disclosures (UX-20/21), search overlay (UX-24), theme toggle (UX-28), CMP link (UX-25).
const $ = ( s, r = document ) => r.querySelector( s );
const $$ = ( s, r = document ) => [ ...r.querySelectorAll( s ) ];

function closeAll( except ) {
	$$( '[aria-controls][aria-expanded="true"]' ).forEach( ( b ) => {
		if ( b !== except ) {
			b.setAttribute( 'aria-expanded', 'false' );
			const p = document.getElementById( b.getAttribute( 'aria-controls' ) );
			if ( p ) {
				p.hidden = true;
			}
		}
	} );
}

function toggle( btn, open ) {
	const panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
	if ( ! panel ) {
		return false;
	}
	open = open ?? btn.getAttribute( 'aria-expanded' ) !== 'true';
	closeAll( btn );
	btn.setAttribute( 'aria-expanded', String( open ) );
	panel.hidden = ! open;
	return open;
}

$$( '[data-disclosure]' ).forEach( ( b ) => b.addEventListener( 'click', () => toggle( b ) ) );

document.addEventListener( 'keydown', ( e ) => {
	if ( e.key !== 'Escape' ) {
		return;
	}
	const open = $( '[aria-controls][aria-expanded="true"]' );
	if ( open ) {
		toggle( open, false );
		open.focus();
	}
} );
document.addEventListener( 'click', ( e ) => {
	if ( ! e.target.closest( '.site-header' ) ) {
		closeAll();
	}
} );

// Search overlay.
const searchBtn = $( '[data-search-open]' );
const panel = $( '#site-search' );
let index = null;
const norm = ( s ) =>
	String( s )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[\s-]+/g, '' );

// Overlay strings come from the page (translated on /id/), English as a fallback (UO-43).
const i18n = ( () => {
	try {
		return JSON.parse( ( panel && panel.dataset.i18n ) || '{}' );
	} catch ( e ) {
		return {};
	}
} )();

// CR-19: Indonesian words people type for English page names ("kode" for codes).
const SYNONYMS = { kode: 'codes', telur: 'egg', hewan: 'pet', kalkulator: 'calculator', mutasi: 'mutations', panduan: 'guide', daftar: 'list' };
const words = ( s ) => String( s ).toLowerCase().split( /[^a-z0-9]+/ ).filter( Boolean );
const swap = ( q ) => String( q ).replace( /[^\s]+/g, ( w ) => SYNONYMS[ w.toLowerCase() ] || w );

// Edit distance with swapped neighbours counting as one edit ("pheonix" -> "phoenix").
function edits( a, b ) {
	const d = Array.from( { length: a.length + 1 }, ( _, i ) => [ i, ...Array( b.length ).fill( 0 ) ] );
	for ( let j = 1; j <= b.length; j++ ) {
		d[ 0 ][ j ] = j;
	}
	for ( let i = 1; i <= a.length; i++ ) {
		for ( let j = 1; j <= b.length; j++ ) {
			const c = a[ i - 1 ] === b[ j - 1 ] ? 0 : 1;
			d[ i ][ j ] = Math.min( d[ i - 1 ][ j ] + 1, d[ i ][ j - 1 ] + 1, d[ i - 1 ][ j - 1 ] + c );
			if ( i > 1 && j > 1 && a[ i - 1 ] === b[ j - 2 ] && a[ i - 2 ] === b[ j - 1 ] ) {
				d[ i ][ j ] = Math.min( d[ i ][ j ], d[ i - 2 ][ j - 2 ] + 1 );
			}
		}
	}
	return d[ a.length ][ b.length ];
}

// A word of 4+ letters one typo away (two from 7 letters) from a word in the name, or its start.
function typo( q, names ) {
	const qs = words( q ).filter( ( w ) => w.length >= 4 );
	if ( ! qs.length ) {
		return false;
	}
	const ws = names.flatMap( words );
	return qs.every( ( w ) => {
		const max = w.length >= 7 ? 2 : 1;
		return ws.some( ( x ) => x.startsWith( w ) || Math.min( edits( w, x ), edits( w, x.slice( 0, w.length ) ) ) <= max );
	} );
}

function find( q, game ) {
	const n = norm( q );
	const items = index.items.filter( ( it ) => ! game || it.g === game );
	const hits = items
		.map( ( it ) => {
			const names = [ it.n, ...String( it.a || '' ).split( /[|,]/ ) ].map( norm );
			const score = names.some( ( x ) => x.startsWith( n ) ) ? 0 : names.some( ( x ) => x.includes( n ) ) ? 1 : 2;
			return [ score, it ];
		} )
		.filter( ( [ s ] ) => s < 2 );
	// No direct match: allow small typos ("pheonix") before showing "No results".
	const out = hits.length ? hits : items.filter( ( it ) => typo( q, [ it.n, ...String( it.a || '' ).split( /[|,]/ ) ] ) ).map( ( it ) => [ 2, it ] );
	return out.sort( ( a, b ) => a[ 0 ] - b[ 0 ] || a[ 1 ].n.localeCompare( b[ 1 ].n ) ).slice( 0, 30 );
}

function render( q ) {
	const box = $( '[data-results]', panel );
	box.replaceChildren();
	if ( ! index || norm( q ).length < 2 ) {
		return;
	}
	const only = $( '[data-only-game]', panel );
	const game = only && only.checked ? panel.dataset.game : '';
	const w = swap( q );
	let hits = find( w, game );
	// Nothing in this game: show the other games' results instead of a dead end (UO-31).
	if ( ! hits.length && game ) {
		hits = find( w, '' );
		if ( hits.length ) {
			const p = document.createElement( 'p' );
			p.className = 'rbx-note';
			p.textContent = ( i18n.others || 'Nothing in %s. Results from all games:' ).replace( '%s', ( index.games && index.games[ game ] ) || game );
			box.append( p );
		}
	}
	if ( ! hits.length ) {
		const p = document.createElement( 'p' );
		p.className = 'rbx-empty';
		p.textContent = ( i18n.none || 'No results for "%s". Press Enter to search all pages.' ).replace( '%s', q );
		box.append( p );
		return;
	}
	const groups = new Map();
	hits.forEach( ( [ , it ] ) => groups.set( it.g, [ ...( groups.get( it.g ) || [] ), it ] ) );
	for ( const [ g, items ] of groups ) {
		const h = document.createElement( 'h2' );
		h.className = 'search-results__game';
		h.textContent = ( index.games && index.games[ g ] ) || g;
		const ul = document.createElement( 'ul' );
		items.forEach( ( it ) => {
			const li = document.createElement( 'li' );
			const a = document.createElement( 'a' );
			a.href = it.u;
			a.textContent = it.n;
			const t = document.createElement( 'span' );
			t.className = 'rbx-chip';
			t.textContent = it.t === 'Page' && i18n.page ? i18n.page : it.t;
			li.append( a, ' ', t );
			ul.append( li );
		} );
		box.append( h, ul );
	}
}

if ( searchBtn && panel ) {
	searchBtn.addEventListener( 'click', async ( e ) => {
		e.preventDefault();
		const open = toggle( searchBtn );
		const input = $( 'input[type="search"]', panel );
		if ( ! open ) {
			return;
		}
		input.focus();
		if ( ! index && panel.dataset.index ) {
			try {
				const r = await fetch( panel.dataset.index );
				index = await r.json();
				render( input.value );
			} catch ( err ) {
				index = null;
			}
		}
	} );
	let t;
	$( 'input[type="search"]', panel ).addEventListener( 'input', ( e ) => {
		clearTimeout( t );
		t = setTimeout( () => render( e.target.value ), 100 );
	} );
	const only = $( '[data-only-game]', panel );
	if ( only ) {
		only.addEventListener( 'change', () => render( $( 'input[type="search"]', panel ).value ) );
	}
}

// Theme toggle: System / Light / Dark; `theme` is the only localStorage key.
const read = () => {
	try {
		return localStorage.getItem( 'theme' ) || 'system';
	} catch ( e ) {
		return 'system';
	}
};
function apply( v ) {
	try {
		if ( v === 'system' ) {
			localStorage.removeItem( 'theme' );
		} else {
			localStorage.setItem( 'theme', v );
		}
	} catch ( e ) {}
	if ( v === 'system' ) {
		delete document.documentElement.dataset.theme;
	} else {
		document.documentElement.dataset.theme = v;
	}
	$$( '[data-theme-set]' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b.dataset.themeSet === v ) ) );
}
$$( '[data-theme-toggle]' ).forEach( ( g ) => {
	g.hidden = false;
	g.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-theme-set]' );
		if ( b ) {
			apply( b.dataset.themeSet );
		}
	} );
} );
apply( read() );

// "Privacy settings" reopens the Google CMP message once it is on the page.
const ps = $( '[data-privacy-settings]' );
if ( ps ) {
	const ready = () => window.googlefc && window.googlefc.callbackQueue;
	const show = () => {
		if ( ready() ) {
			ps.hidden = false;
			return true;
		}
		return false;
	};
	if ( ! show() ) {
		window.addEventListener( 'load', () => setTimeout( show, 1500 ) );
	}
	ps.addEventListener( 'click', () => ready() && window.googlefc.callbackQueue.push( window.googlefc.showRevocationMessage ) );
}
