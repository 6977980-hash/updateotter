// The one number formatter (DATA-29, ENG-35). Twin of rbx-data/includes/Format/Number.php;
// both are checked against rbx-data/tests/fixtures/format_vectors.json.

export const DEFAULT_LADDER = [
	{ s: 'K', e: 3 },
	{ s: 'M', e: 6 },
	{ s: 'B', e: 9 },
	{ s: 'T', e: 12 },
];

const LOCALES = {
	en: { dec: '.', grp: ',' },
	id: { dec: ',', grp: '.' },
};

const MAX_SAFE = 9007199254740991;

export function ladderOf( ladder ) {
	if ( ! Array.isArray( ladder ) || ! ladder.length ) {
		return DEFAULT_LADDER;
	}
	const out = ladder
		.filter( ( s ) => s && typeof s.s === 'string' && s.e !== undefined && s.e !== null && ! isNaN( Number( s.e ) ) )
		.map( ( s ) => ( { s: s.s, e: Math.trunc( Number( s.e ) ) } ) )
		.sort( ( a, b ) => a.e - b.e );
	return out.length ? out : DEFAULT_LADDER;
}

// Integer-valued double to a plain digit string (PHP sprintf('%.0f')).
const intStr = ( n ) => ( n < 1e21 ? n.toFixed( 0 ) : BigInt( n ).toString() );

function digits( v, d ) {
	const f = 10 ** d;
	const n = Math.floor( Math.abs( v ) * f + 0.5 );
	let s = intStr( n );
	let int;
	let frac;
	if ( d > 0 ) {
		s = s.padStart( d + 1, '0' );
		int = s.slice( 0, -d );
		frac = s.slice( -d );
	} else {
		int = s;
		frac = '';
	}
	const sign = v < 0 && n > 0 ? '-' : '';
	return [ sign, int, frac ];
}

function join( int, frac, locale ) {
	const l = LOCALES[ locale ] || LOCALES.en;
	int = int.replace( /^0+/, '' ) || '0';
	let grp = '';
	const len = int.length;
	for ( let i = 0; i < len; i++ ) {
		if ( i > 0 && ( len - i ) % 3 === 0 ) {
			grp += l.grp;
		}
		grp += int[ i ];
	}
	return frac === '' ? grp : grp + l.dec + frac;
}

function fixed( v, d, trim, locale ) {
	let [ sign, int, frac ] = digits( v, d );
	if ( trim ) {
		frac = frac.replace( /0+$/, '' );
	}
	return sign + join( int, frac, locale );
}

function sig3Decimals( m ) {
	let a = Math.abs( m );
	if ( a >= 100 ) {
		return 0;
	}
	if ( a >= 10 ) {
		return 1;
	}
	if ( a >= 1 ) {
		return 2;
	}
	let d = 2;
	while ( a < 1 && d < 12 ) {
		a *= 10;
		d++;
	}
	return d;
}

function sig3( m ) {
	const f = 10 ** sig3Decimals( m );
	return Math.floor( Math.abs( m ) * f + 0.5 ) / f;
}

function abbr( v, locale, ladder ) {
	if ( v === 0 ) {
		return '0';
	}
	const a = Math.abs( v );
	let step = -1;
	ladder.forEach( ( s, i ) => {
		if ( a >= 10 ** s.e ) {
			step = i;
		}
	} );
	let m = a;
	for ( ;; ) {
		const div = step >= 0 ? 10 ** ladder[ step ].e : 1;
		m = a / div;
		if ( sig3( m ) >= 1000 && ladder[ step + 1 ] ) {
			step++;
			continue;
		}
		break;
	}
	const suffix = step >= 0 ? ladder[ step ].s : '';
	const body = sig3( m ) >= 1000 ? fixed( m, 0, true, locale ) : fixed( m, sig3Decimals( m ), true, locale );
	return ( v < 0 ? '-' : '' ) + body + suffix;
}

function prob( p, locale ) {
	if ( p <= 0 ) {
		return '0%';
	}
	const pct = p * 100;
	if ( pct >= 1 ) {
		return fixed( pct, 1, true, locale ) + '%';
	}
	if ( pct >= 0.0001 ) {
		let d = 1;
		let a = pct;
		while ( a < 10 ) {
			a *= 10;
			d++;
		}
		return fixed( pct, d - 1, true, locale ) + '%';
	}
	return '1 in ' + fixed( Math.floor( 1 / p + 0.5 ), 0, false, locale );
}

function duration( s ) {
	const t = Math.floor( Math.abs( s ) + 0.5 );
	const u = [
		[ 'd', Math.floor( t / 86400 ) ],
		[ 'h', Math.floor( ( t % 86400 ) / 3600 ) ],
		[ 'm', Math.floor( ( t % 3600 ) / 60 ) ],
		[ 's', t % 60 ],
	];
	const out = [];
	let start = false;
	for ( const [ k, n ] of u ) {
		if ( ! start && n === 0 && k !== 's' ) {
			continue;
		}
		out.push( ( start ? String( n ).padStart( 2, '0' ) : String( n ) ) + k );
		start = true;
	}
	return out.join( ' ' );
}

export function formatValue( value, format, locale = 'en', ladder = null ) {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		return '';
	}
	const l = ladderOf( ladder );
	const m = /^decimal\((\d)\)$/.exec( format );
	if ( m ) {
		return fixed( value, Number( m[ 1 ] ), true, locale );
	}
	switch ( format ) {
		case 'int':
			return fixed( value, 0, false, locale );
		case 'mult':
			return '×' + fixed( value, 4, true, locale );
		case 'prob':
			return prob( value, locale );
		case 'duration':
			return duration( value );
		case 'abbr':
			return abbr( value, locale, l );
		default:
			return fixed( value, 4, true, locale );
	}
}

export function formatExact( value, locale = 'en' ) {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		return '';
	}
	if ( Math.abs( value ) > MAX_SAFE ) {
		return abbr( value, locale, DEFAULT_LADDER );
	}
	return fixed( value, 4, true, locale );
}
