// List filters, search and sort. The table works without JS; state lives in the URL fragment (#q=…&rarity=…).
const lists = document.querySelectorAll( '[data-rbx-list]' );

function readHash() {
	const p = new URLSearchParams( window.location.hash.slice( 1 ) );
	return p;
}

lists.forEach( ( list, n ) => {
	const controls = list.querySelector( '[data-rbx-controls]' );
	const tbody = list.querySelector( 'tbody' );
	// GR-24: long lists send the first rows in the page and the rest in a <template>.
	let rest = tbody.querySelector( 'template[data-rbx-rest]' );
	let rows = Array.from( tbody.rows );
	const q = list.querySelector( '[data-rbx-q]' );
	const sort = list.querySelector( '[data-rbx-sort]' );
	const removedBtn = list.querySelector( '[data-rbx-removed]' );
	const clear = list.querySelector( '[data-rbx-clear]' );
	const status = list.querySelector( '[data-rbx-status]' );
	const empty = list.querySelector( '[data-rbx-empty]' );
	const chips = Array.from( list.querySelectorAll( '[data-facet]' ) );
	const filters = list.querySelector( '[data-rbx-filters]' );
	const filterCount = list.querySelector( '[data-rbx-filter-count]' );
	const backTop = list.querySelector( '[data-rbx-backtop]' );
	const useHash = n === 0;
	controls.hidden = false;
	// Filter chips start folded on phones and open from 600 px (UO-30).
	if ( filters && window.matchMedia( '(min-width: 600px)' ).matches ) {
		filters.open = true;
	}
	if ( backTop ) {
		backTop.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			window.scrollTo( { top: 0 } );
			( q || document.body ).focus( { preventScroll: true } );
		} );
		const onScroll = () => {
			backTop.hidden = window.scrollY < window.innerHeight * 2;
		};
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}

	function state() {
		const facets = {};
		chips.filter( ( c ) => c.getAttribute( 'aria-pressed' ) === 'true' ).forEach( ( c ) => {
			( facets[ c.dataset.facet ] ||= [] ).push( c.dataset.value );
		} );
		return {
			q: q.value.trim().toLowerCase(),
			facets,
			sort: sort.value,
			removed: removedBtn ? removedBtn.getAttribute( 'aria-pressed' ) === 'true' : false,
		};
	}

	// UO-55: row data is read once; a filter tap only flips rows whose state changes, and rows
	// move only when the sort changes (re-appending 600+ rows on every tap was the slow part).
	const toMeta = ( r ) => {
		const f = {};
		for ( const a of r.attributes ) {
			if ( a.name.startsWith( 'data-f-' ) ) {
				f[ a.name.slice( 7 ) ] = a.value.split( ' ' );
			}
		}
		return { r, name: r.dataset.name || '', f, removed: r.hasAttribute( 'data-removed' ), shown: ! r.hidden };
	};
	let meta = rows.map( toMeta );
	let totalAll = meta.length;
	let totalLive = meta.filter( ( m ) => ! m.removed ).length;
	let sortVals = {};
	let lastSort = '';

	function sortValues( key ) {
		if ( ! sortVals[ key ] ) {
			const idx = Array.from( list.querySelectorAll( 'thead th' ) ).findIndex( ( th ) => th.dataset.sortKey === key );
			sortVals[ key ] = new Map( rows.map( ( r ) => {
				const c = r.cells[ idx ];
				return [ r, c && c.dataset.sort !== undefined ? parseFloat( c.dataset.sort ) : -Infinity ];
			} ) );
		}
		return sortVals[ key ];
	}

	const LIMIT = 100;
	const more = list.querySelector( '[data-rbx-more]' );
	let limited = false;
	let expanded = false;
	let order = meta;

	// Moves the template rows into the table, once, before anything needs the whole list.
	function hydrate() {
		if ( ! rest ) {
			return;
		}
		tbody.appendChild( rest.content );
		rest.remove();
		rest = null;
		rows = Array.from( tbody.rows );
		meta = rows.map( toMeta );
		totalAll = meta.length;
		totalLive = meta.filter( ( m ) => ! m.removed ).length;
		sortVals = {};
		order = meta;
		lastSort = '';
	}

	if ( more ) {
		more.addEventListener( 'click', () => {
			const before = order.filter( ( m ) => m.shown ).length;
			expanded = true;
			hydrate();
			apply( false );
			// Focus moves to the first row that just appeared, since the button hides itself.
			const next = order.filter( ( m ) => m.shown )[ before ];
			const link = next && next.r.querySelector( 'a' );
			if ( link ) {
				link.focus();
			}
		} );
	}

	function apply( write ) {
		const s = state();
		if ( write || expanded || s.q || Object.keys( s.facets ).length || s.sort || s.removed ) {
			hydrate();
		}
		if ( write ) {
			// The full server-rendered list shows until the first change; then long results are capped.
			limited = true;
			expanded = false;
		}
		if ( s.sort !== lastSort ) {
			order = meta.slice();
			if ( s.sort === 'name-asc' ) {
				order.sort( ( a, b ) => a.name.localeCompare( b.name ) );
			} else if ( s.sort ) {
				const v = sortValues( s.sort.replace( /-desc$/, '' ) );
				order.sort( ( a, b ) => v.get( b.r ) - v.get( a.r ) );
			}
			const frag = document.createDocumentFragment();
			order.forEach( ( m ) => frag.appendChild( m.r ) );
			tbody.appendChild( frag );
			lastSort = s.sort;
		}
		const cap = limited && ! expanded ? LIMIT : Infinity;
		let shown = 0;
		order.forEach( ( m ) => {
			let ok = ! s.q || m.name.includes( s.q );
			for ( const f in s.facets ) {
				const have = m.f[ f ] || [];
				ok = ok && s.facets[ f ].some( ( v ) => have.includes( v ) );
			}
			if ( m.removed && ! s.removed ) {
				ok = false;
			}
			shown += ok ? 1 : 0;
			const vis = ok && shown <= cap;
			if ( vis !== m.shown ) {
				m.r.hidden = ! vis;
				m.shown = vis;
			}
		} );
		if ( more ) {
			// Rows still in the template: offer them all, counted from the server's total.
			const n = rest ? parseInt( more.dataset.total, 10 ) || shown : shown;
			more.parentElement.hidden = ! rest && shown <= cap;
			more.textContent = ( more.dataset.template || '{n}' ).replace( '{n}', new Intl.NumberFormat( document.documentElement.lang || 'en' ).format( n ) );
		}
		empty.hidden = shown > 0;
		const active = s.q || Object.keys( s.facets ).length || s.sort || s.removed;
		clear.hidden = ! active;
		const nf = new Intl.NumberFormat( document.documentElement.lang || 'en' );
		status.textContent = active ? ( status.dataset.template || '{shown} / {total}' ).replace( '{shown}', nf.format( shown ) ).replace( '{total}', nf.format( s.removed ? totalAll : totalLive ) ) : '';
		if ( filterCount ) {
			const nFacets = Object.values( s.facets ).reduce( ( a, v ) => a + v.length, 0 );
			filterCount.textContent = nFacets ? '(' + nFacets + ')' : '';
		}
		if ( write && useHash ) {
			const p = new URLSearchParams();
			if ( s.q ) {
				p.set( 'q', s.q );
			}
			for ( const f in s.facets ) {
				p.set( f, s.facets[ f ].join( ',' ) );
			}
			if ( s.sort ) {
				p.set( 'sort', s.sort );
			}
			if ( s.removed ) {
				p.set( 'removed', '1' );
			}
			const h = p.toString();
			history.replaceState( null, '', h ? '#' + h : window.location.pathname + window.location.search );
		}
	}

	function load() {
		if ( ! useHash ) {
			return;
		}
		const p = readHash();
		// A plain #anchor (row id) is not filter state; a row still in the template is brought in.
		if ( window.location.hash && ! window.location.hash.includes( '=' ) ) {
			const id = decodeURIComponent( window.location.hash.slice( 1 ) );
			if ( rest && ! document.getElementById( id ) && rest.content.getElementById( id ) ) {
				expanded = true;
				hydrate();
				requestAnimationFrame( () => document.getElementById( id )?.scrollIntoView() );
			}
			return;
		}
		q.value = p.get( 'q' ) || '';
		chips.forEach( ( c ) => {
			const v = ( p.get( c.dataset.facet ) || '' ).split( ',' );
			c.setAttribute( 'aria-pressed', v.includes( c.dataset.value ) ? 'true' : 'false' );
		} );
		if ( p.get( 'sort' ) && sort.querySelector( 'option[value="' + CSS.escape( p.get( 'sort' ) ) + '"]' ) ) {
			sort.value = p.get( 'sort' );
		}
		if ( removedBtn ) {
			removedBtn.setAttribute( 'aria-pressed', p.get( 'removed' ) ? 'true' : 'false' );
		}
	}

	let timer;
	q.addEventListener( 'input', () => {
		clearTimeout( timer );
		timer = setTimeout( () => apply( true ), 150 );
	} );
	sort.addEventListener( 'change', () => apply( true ) );
	[ ...chips, ...( removedBtn ? [ removedBtn ] : [] ) ].forEach( ( b ) =>
		b.addEventListener( 'click', () => {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'aria-pressed' ) === 'true' ? 'false' : 'true' );
			apply( true );
		} )
	);
	clear.addEventListener( 'click', () => {
		q.value = '';
		sort.value = '';
		[ ...chips, ...( removedBtn ? [ removedBtn ] : [] ) ].forEach( ( b ) => b.setAttribute( 'aria-pressed', 'false' ) );
		apply( true );
		q.focus();
	} );
	load();
	apply( false );
} );
