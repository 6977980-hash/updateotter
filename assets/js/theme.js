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

function render( q ) {
	const box = $( '[data-results]', panel );
	box.replaceChildren();
	if ( ! index || norm( q ).length < 2 ) {
		return;
	}
	const only = $( '[data-only-game]', panel );
	const game = only && only.checked ? panel.dataset.game : '';
	const n = norm( q );
	const hits = index.items
		.filter( ( it ) => ! game || it.g === game )
		.map( ( it ) => {
			const names = [ it.n, ...String( it.a || '' ).split( /[|,]/ ) ].map( norm );
			const score = names.some( ( x ) => x.startsWith( n ) ) ? 0 : names.some( ( x ) => x.includes( n ) ) ? 1 : 2;
			return [ score, it ];
		} )
		.filter( ( [ s ] ) => s < 2 )
		.sort( ( a, b ) => a[ 0 ] - b[ 0 ] || a[ 1 ].n.localeCompare( b[ 1 ].n ) )
		.slice( 0, 30 );
	if ( ! hits.length ) {
		const p = document.createElement( 'p' );
		p.className = 'rbx-empty';
		p.textContent = 'No results for "' + q + '". Press Enter to search all pages.';
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
			t.textContent = it.t;
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
