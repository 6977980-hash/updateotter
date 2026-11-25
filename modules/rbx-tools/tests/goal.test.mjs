// Time-to-goal rules (goal.js; Goal.php is its twin and is checked against it in the browser).
// Run: node --test modules/rbx-tools/tests/goal.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { after, compute, span, texts } from '../assets/js/goal.js';

const zones = [ { name: 'A', speed: 0 }, { name: 'B', speed: 100 } ];
const egg = ( egg, zone, income, chance, hatch ) => ( { egg, pet: egg.replace( ' Egg', '' ), url: '', zone, income, chance, hatch } );
const data = { zones, eggs: [
	egg( 'Rare Egg', 0, 1000, 0.01, 10 ),
	egg( 'Good Egg', 0, 100, 0.2, 100 ),
	egg( 'Fast Egg', 0, 50, 0.3, 1 ),
	egg( 'Far Egg', 1, 5000, 0.5, 10 ),
] };
const t = new Proxy( {}, { get: ( _, k ) => String( k ) + ( [ 'best', 'best_plain', 'years', 'per_s', 'one_in' ].includes( k ) ? ':%1$s|%2$s|%3$s|%4$s' : '' ) } );

test( 'after(): old income runs during the hatch, then the new one', () => {
	assert.equal( after( 1000, 10, 0, 20 ), 50 );
	assert.equal( after( 1000, 10, 100, 20 ), 100 );
	assert.equal( after( 1000, 10, 50, 20 ), 75 );
	assert.equal( after( 0, 0, 10, 0 ), 0 );
	assert.equal( after( 10, 0, 5, 0 ), null );
} );

test( 'reach, swap and order', () => {
	const r = compute( data, 0, 10000, 10, 0, 5, false, 8, 0.04 );
	assert.equal( r.now, 1000 );
	assert.deepEqual( r.rows.map( ( x ) => x.egg ), [ 'Rare Egg', 'Fast Egg', 'Good Egg' ] );
	assert.equal( r.best.egg, 'Fast Egg' );
	assert.equal( r.rows[ 1 ].time, 1 + ( 10000 - 10 ) / 55 );
} );

test( 'weakest pet filters eggs that do not beat it; Speed opens zones', () => {
	const r = compute( data, 0, 10000, 10, 100, 60, false, 8, 0.04 );
	assert.deepEqual( r.rows.map( ( x ) => x.egg ), [ 'Far Egg', 'Rare Egg', 'Good Egg' ] );
	assert.equal( r.best.egg, 'Far Egg' );
} );

test( 'cash already enough, no income, and the pass halves hatch time', () => {
	assert.equal( texts( compute( data, 50, 10, 1, 0, null, false, 8, 0.04 ), t, 'en', null ).now, 'done' );
	const z = compute( data, 0, 100, 0, 0, null, false, 8, 0.04 );
	assert.equal( z.now, null );
	assert.equal( texts( z, t, 'en', null ).now, 'no_income' );
	assert.equal( compute( data, 0, 10000, 10, 0, null, true, 8, 0.04 ).rows.find( ( x ) => x.egg === 'Good Egg' ).hatch, 50 );
} );

test( 'a slow egg on a near goal is not sooner', () => {
	const r = compute( data, 0, 100, 10, 0, null, false, 8, 0.04 );
	const g = r.rows.find( ( x ) => x.egg === 'Good Egg' );
	assert.equal( g.time, 10 );
	assert.equal( texts( r, t, 'en', null ).rows.find( ( x ) => x.egg === 'Good Egg' ).saved, 'not_sooner' );
} );

test( 'span wording', () => {
	assert.equal( span( 45, t, 'en', null ), '45s' );
	assert.equal( span( 725, t, 'en', null ), '12m 05s' );
	assert.equal( span( 3 * 3600 + 20 * 60, t, 'en', null ), '3h 20m' );
	assert.equal( span( 5 * 86400 + 4 * 3600, t, 'en', null ), '5d 4h' );
	assert.equal( span( 3 * 365 * 86400, t, 'en', null ), 'years:3|%2$s|%3$s|%4$s' );
} );
