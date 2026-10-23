// Shareable state in the URL fragment: #v=1&pet=slug&count=3 (ENG-33). Pure.
export const STATE_VERSION = 1;

const hasSlug = ( data, target, slug ) => {
	const recs = ( data.records && data.records[ target ] ) || {};
	return Object.values( recs ).some( ( r ) => r.slug === slug );
};

// Returns { values, notices[], present }; present = the fragment held calculator state.
export function readState( hash, toolSchema, data, knownSlugs = null ) {
	const values = { ...toolSchema.defaults };
	const notices = [];
	const p = new URLSearchParams( String( hash || '' ).replace( /^#/, '' ) );
	if ( ! p.has( 'v' ) ) {
		return { values, notices, present: false };
	}
	const v = parseInt( p.get( 'v' ), 10 );
	if ( ! ( v >= 1 ) || v > STATE_VERSION ) {
		notices.push( 'newer' );
		return { values, notices, present: true };
	}
	for ( const d of toolSchema.inputs ) {
		if ( ! p.has( d.key ) ) {
			continue;
		}
		const raw = p.get( d.key );
		if ( d.type === 'ref' ) {
			if ( raw === '' && d.optional ) {
				values[ d.key ] = '';
			} else if ( knownSlugs ? knownSlugs( d.target, raw ) : hasSlug( data, d.target, raw ) ) {
				values[ d.key ] = raw;
			} else if ( ! notices.includes( 'reset' ) ) {
				notices.push( 'reset' );
			}
		} else if ( d.type === 'bool' ) {
			values[ d.key ] = raw === '1';
		} else {
			let n = /^-?\d+(\.\d+)?$/.test( raw ) ? Number( raw ) : NaN;
			if ( ! Number.isFinite( n ) ) {
				notices.includes( 'reset' ) || notices.push( 'reset' );
				continue;
			}
			let clamped = false;
			if ( d.integer && Math.floor( n ) !== n ) {
				n = Math.round( n );
				clamped = true;
			}
			if ( d.max !== undefined && n > d.max ) {
				n = d.max;
				clamped = true;
			}
			if ( d.min !== undefined && n < d.min ) {
				n = d.min;
				clamped = true;
			}
			if ( clamped && ! notices.includes( 'clamped' ) ) {
				notices.push( 'clamped' );
			}
			values[ d.key ] = n;
		}
	}
	return { values, notices, present: true };
}

export function writeState( values, toolSchema ) {
	const p = new URLSearchParams();
	p.set( 'v', String( STATE_VERSION ) );
	for ( const d of toolSchema.inputs ) {
		const v = values[ d.key ];
		if ( v === undefined || v === null ) {
			continue;
		}
		if ( d.type === 'bool' ) {
			p.set( d.key, v ? '1' : '0' );
		} else if ( d.type === 'ref' ) {
			p.set( d.key, String( v ) );
		} else if ( typeof v === 'number' && Number.isFinite( v ) ) {
			p.set( d.key, String( v ) );
		}
	}
	return p.toString();
}
