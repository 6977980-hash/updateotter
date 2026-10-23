// Input checks shared by the URL state reader and the form (ENG-34). Pure.

// values: key => slug ('' = none) | number (null = left empty, optional inputs only) | boolean.
export function validateInputs( values, toolSchema ) {
	const errors = [];
	for ( const d of toolSchema.inputs ) {
		const v = values[ d.key ];
		if ( d.type === 'ref' ) {
			if ( ( v === undefined || v === '' ) && ! d.optional ) {
				errors.push( { key: d.key, code: 'empty' } );
			}
		} else if ( d.type === 'number' ) {
			if ( d.optional && ( v === undefined || v === null ) ) {
				continue;
			}
			if ( typeof v !== 'number' || ! Number.isFinite( v ) ) {
				errors.push( { key: d.key, code: 'empty' } );
			} else if ( d.integer && Math.floor( v ) !== v ) {
				errors.push( { key: d.key, code: 'not_integer' } );
			} else if ( d.max !== undefined && v > d.max ) {
				errors.push( { key: d.key, code: 'too_big' } );
			} else if ( d.min !== undefined && v < d.min ) {
				errors.push( { key: d.key, code: 'too_small' } );
			}
		}
	}
	return { ok: errors.length === 0, errors };
}
