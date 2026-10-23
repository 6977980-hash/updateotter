// Number entry parser (ENG-34). Twin of rbx-data/includes/Format/Input.php;
// both are checked against rbx-data/tests/fixtures/parse_vectors.json.
import { ladderOf } from './format.js';

const MAX_SAFE = 9007199254740991;
const err = ( code ) => ( { ok: false, value: null, error: code } );
const esc = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

export function parseNumber( text, locale = 'en', ladder = null, selectedSuffix = '', opts = {} ) {
	let t = String( text ).replace( / /g, ' ' ).trim();
	if ( t === '' ) {
		return err( 'empty' );
	}
	if ( opts.mult ) {
		t = t.replace( /^(?:x|×)\s*/i, '' ).replace( /\s*(?:x|×)$/i, '' );
	}
	let neg = false;
	if ( t.startsWith( '-' ) ) {
		neg = true;
		t = t.slice( 1 ).replace( /^\s+/, '' );
	}
	const steps = ladderOf( ladder );
	let exp = 0;
	const m = /^(.*?)\s*([a-z]+)$/i.exec( t );
	if ( m ) {
		let found = null;
		for ( const s of steps ) {
			if ( s.s.toLowerCase() === m[ 2 ].toLowerCase() ) {
				found = s;
			}
		}
		if ( ! found ) {
			return err( 'invalid' );
		}
		exp = found.e;
		t = m[ 1 ];
	} else if ( selectedSuffix !== '' ) {
		for ( const s of steps ) {
			if ( s.s === selectedSuffix ) {
				exp = s.e;
			}
		}
	}
	const dec = locale === 'id' ? ',' : '.';
	const grp = locale === 'id' ? '.' : ',';
	const qd = esc( dec );
	const qg = esc( grp );
	let n;
	if ( new RegExp( '^\\d+(?:' + qd + '\\d+)?$' ).test( t ) ) {
		n = t;
	} else if ( new RegExp( '^\\d{1,3}(?:(?:' + qg + '| )\\d{3})+(?:' + qd + '\\d+)?$' ).test( t ) ) {
		n = t.replace( new RegExp( '[' + qg + ' ]', 'g' ), '' );
	} else if ( new RegExp( '^\\d+' + qg + '\\d+$' ).test( t ) ) {
		return err( 'ambiguous' );
	} else {
		return err( 'invalid' );
	}
	let value = parseFloat( n.replace( dec, '.' ) ) * 10 ** exp;
	if ( ! Number.isFinite( value ) ) {
		return err( 'invalid' );
	}
	if ( neg ) {
		if ( ! opts.allow_negative ) {
			return err( 'negative' );
		}
		value = -value;
	}
	if ( opts.integer ) {
		if ( Math.floor( value ) !== value ) {
			return err( 'not_integer' );
		}
		if ( Math.abs( value ) > MAX_SAFE ) {
			return err( 'too_big' );
		}
	}
	if ( opts.max !== undefined && opts.max !== null && value > Number( opts.max ) ) {
		return err( 'too_big' );
	}
	if ( opts.min !== undefined && opts.min !== null && value < Number( opts.min ) ) {
		return err( 'too_small' );
	}
	return { ok: true, value: value + 0, error: null };
}
