// Loads the hashed JSON slices for a calculator (DATA-27) and merges them. Pure apart from fetch.

export function mergeSlices( base, slices ) {
	const out = {
		records: { ...( base.records || {} ) },
		params: { ...( base.params || {} ) },
		formulas: base.formulas || null,
		ladder: base.ladder ?? null,
		dataset: base.dataset || '',
	};
	for ( const s of slices ) {
		for ( const ent in s.records || {} ) {
			out.records[ ent ] = { ...( out.records[ ent ] || {} ), ...s.records[ ent ] };
		}
		if ( s.slice === 'core' ) {
			out.params = s.params || {};
			out.formulas = s.formulas || out.formulas;
			out.ladder = s.ladder ?? null;
		}
		out.dataset = s.dataset || out.dataset;
	}
	return out;
}

export async function loadSlices( urls, fetchFn = globalThis.fetch ) {
	const res = await Promise.all(
		urls.map( async ( u ) => {
			const r = await fetchFn( u, { priority: 'low', credentials: 'omit' } );
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} )
	);
	return res;
}
