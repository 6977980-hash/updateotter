// "What can I catch here?": catch.js compute() and texts against the vectors written by
// catch-make-vectors.py from the real Fisch CSVs (path in CATCH_VECTORS). catch.test.php checks the
// PHP twin against the same file, so the two can never drift apart.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { compute, texts, needsText } from '../assets/js/catch.js';

const v = JSON.parse( readFileSync( process.env.CATCH_VECTORS || new URL( './catch-vectors.json', import.meta.url ), 'utf8' ) );

test( `compute: ${ v.cases.length } real spot and condition picks`, () => {
	for ( const c of v.cases ) {
		const r = compute( v.data, c.pick );
		assert.deepEqual(
			{ names: r.rows.map( ( f ) => f.name ), here: r.here, other: r.other, only: r.only, best: r.best ? r.best.name : null },
			c.expect,
			JSON.stringify( c.pick )
		);
	}
} );

const t = { count: '%s fish', count_one: '%s fish (1)', other: '+%s other', other_one: '+%s other (1)', best: '%1$s %2$s', no_best: 'NB', only: '%s only', only_one: '%s only (1)', no_only: 'NO', any: 'ANY', or: 'or', unknown: '?', now: 'NOW', any_bait: 'AB', tba: 'TBA' };
const data = {
	fish: [
		{ name: 'A', url: '', r: 'Rare', ro: 5, l: [ 0 ], time: [], weather: [ 'Rain' ], season: [], b: '', v: 100 },
		{ name: 'B', url: '', r: 'Secret', ro: 9, l: [ 0, 1 ], time: [ 'Night' ], weather: [], season: [ 'Winter', 'Spring' ], b: 'Worm', v: null },
		{ name: 'C', url: '', r: '', ro: 0, l: [ 0 ], time: null, weather: [], season: [], b: null, v: 2500 },
	],
};

test( 'rules: unknown conditions bite, a picked condition marks "now", others drop out', () => {
	let r = compute( data, { spot: 0, time: '', weather: '', season: '' } );
	assert.deepEqual( r.rows.map( ( f ) => f.name ), [ 'C', 'A', 'B' ] );
	assert.equal( r.only, 0 );
	r = compute( data, { spot: 0, time: 'Day', weather: 'Rain', season: '' } );
	assert.deepEqual( r.rows.map( ( f ) => [ f.name, f.needs ] ), [ [ 'C', false ], [ 'A', true ] ] );
	assert.equal( r.other, 1 );
	assert.equal( r.best.name, 'C' );
	r = compute( data, { spot: 1, time: 'Night', weather: '', season: '' } );
	assert.equal( r.best, null );
	assert.equal( r.only, 1 );
} );

test( 'texts', () => {
	const r = compute( data, { spot: 0, time: 'Day', weather: 'Rain', season: '' } );
	const x = texts( r, t, 'en', null );
	assert.equal( x.count, '2 fish +1 other (1)' );
	assert.equal( x.only, '1 only (1)' );
	assert.equal( x.rows[ 0 ].needs, 'ANY (?)' );
	assert.equal( x.rows[ 0 ].b, '—' );
	assert.equal( x.rows[ 1 ].b, 'AB' );
	assert.equal( x.rows[ 1 ].now, 'NOW' );
	assert.equal( needsText( data.fish[ 1 ], t ), 'Night, Winter or Spring' );
	const y = texts( compute( data, { spot: 1, time: '', weather: '', season: '' } ), t, 'en', null );
	assert.equal( y.best, 'NB' );
	assert.equal( y.rows[ 0 ].v, 'TBA' );
} );
