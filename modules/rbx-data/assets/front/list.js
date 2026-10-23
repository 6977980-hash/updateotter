// List filters, search and sort. The table works without JS; state lives in the URL fragment (#q=…&rarity=…).
const lists = document.querySelectorAll( '[data-rbx-list]' );

function readHash() {
	const p = new URLSearchParams( window.location.hash.slice( 1 ) );
	return p;
}

lists.forEach( ( list, n ) => {
	const controls = list.querySelector( '[data-rbx-controls]' );
	const tbody = list.querySelector( 'tbody' );
	const rows = Array.from( tbody.rows );
	const original = rows.slice();
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

	function apply( write ) {
		const s = state();
		let shown = 0;
		rows.forEach( ( r ) => {
			let ok = ! s.q || ( r.dataset.name || '' ).includes( s.q );
			for ( const f in s.facets ) {
				const have = ( r.getAttribute( 'data-f-' + f ) || '' ).split( ' ' );
				ok = ok && s.facets[ f ].some( ( v ) => have.includes( v ) );
			}
			if ( r.hasAttribute( 'data-removed' ) && ! s.removed ) {
				ok = false;
			}
			r.hidden = ! ok;
			shown += ok ? 1 : 0;
		} );
		let order = original.slice();
		if ( s.sort === 'name-asc' ) {
			order.sort( ( a, b ) => ( a.dataset.name || '' ).localeCompare( b.dataset.name || '' ) );
		} else if ( s.sort ) {
			const key = s.sort.replace( /-desc$/, '' );
			const idx = Array.from( list.querySelectorAll( 'thead th' ) ).findIndex( ( th ) => th.dataset.sortKey === key );
			const val = ( r ) => {
				const c = r.cells[ idx ];
				return c && c.dataset.sort !== undefined ? parseFloat( c.dataset.sort ) : -Infinity;
			};
			order.sort( ( a, b ) => val( b ) - val( a ) );
		}
		order.forEach( ( r ) => tbody.appendChild( r ) );
		empty.hidden = shown > 0;
		const active = s.q || Object.keys( s.facets ).length || s.sort || s.removed;
		clear.hidden = ! active;
		const nf = new Intl.NumberFormat( document.documentElement.lang || 'en' );
		status.textContent = active ? ( status.dataset.template || '{shown} / {total}' ).replace( '{shown}', nf.format( shown ) ).replace( '{total}', nf.format( rows.filter( ( r ) => s.removed || ! r.hasAttribute( 'data-removed' ) ).length ) ) : '';
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
		// A plain #anchor (row id) is not filter state.
		if ( window.location.hash && ! window.location.hash.includes( '=' ) ) {
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
