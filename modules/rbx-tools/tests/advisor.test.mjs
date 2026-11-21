// "What to get next" advisor rules (advisor.js; Advisor.php is its twin and is checked against it
// in the browser). Run: node --test modules/rbx-tools/tests/advisor.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { compute, texts, order } from '../assets/js/advisor.js';

const zones = [ { name: 'A', speed: 0 }, { name: 'B', speed: 100 }, { name: 'C', speed: 1000 } ];
const egg = ( egg, zone, income, chance, hatch = 60 ) => ( { egg, pet: egg.replace( ' Egg', '' ), url: '', rarity: '', zone, income, chance, hatch } );
const eggs = [
	egg( 'Rare A Egg', 0, 500, 0.01 ),
	egg( 'Good A Egg', 0, 100, 0.05 ),
	egg( 'Edge A Egg', 0, 90, 0.04 ),
	egg( 'Weak A Egg', 0, 10, 0.5 ),
	egg( 'Good B Egg', 1, 1000, 0.2 ),
	egg( 'Odd B Egg', 1, 5000, null, null ),
].sort( order );
const data = { zones, eggs };
const t = new Proxy( {}, { get: ( _, k ) => String( k ) + ( [ 'hunt', 'jackpot', 'jackpot_unknown', 'next', 'next_hunt', 'next_hunt_plain', 'gain', 'gain_plain', 'share', 'odds', 'one_in', 'per_s' ].includes( k ) ? ':%1$s|%2$s|%3$s|%4$s|%5$s' : '' ) } );

test( 'hunt, jackpot and next biome at the start', () => {
	const r = compute( data, 0, null, null, false, 10, 0.04 );
	assert.equal( r.hunt.egg, 'Good A Egg' );
	assert.equal( r.jackpot.egg, 'Rare A Egg' );
	assert.equal( r.next.name, 'B' );
	assert.equal( r.next.gap, 100 );
	assert.equal( r.next.hunt.egg, 'Good B Egg' );
	assert.equal( r.next.times, 10 );
	assert.deepEqual( r.rows.map( ( e ) => e.egg ), [ 'Rare A Egg', 'Good A Egg', 'Edge A Egg', 'Weak A Egg' ] );
} );

test( 'a spawn chance of exactly 4% counts as common', () => {
	const r = compute( { zones, eggs: eggs.filter( ( e ) => e.egg !== 'Good A Egg' ) }, 0, null, null, false, 10, 0.04 );
	assert.equal( r.hunt.egg, 'Edge A Egg' );
} );

test( 'reaching a biome needs Speed equal to its requirement', () => {
	assert.equal( compute( data, 99, null, null, false, 10, 0.04 ).hunt.egg, 'Good A Egg' );
	const r = compute( data, 100, null, null, false, 10, 0.04 );
	assert.equal( r.hunt.egg, 'Good B Egg' );
	assert.equal( r.jackpot.egg, 'Odd B Egg' );
	assert.equal( r.next.name, 'C' );
	assert.equal( r.next.hunt, null );
} );

test( 'weakest pet filters the table and gives the gain', () => {
	const r = compute( data, 100, 100, 2000, false, 10, 0.04 );
	assert.deepEqual( r.rows.map( ( e ) => e.egg ), [ 'Odd B Egg', 'Good B Egg', 'Rare A Egg' ] );
	assert.equal( r.beats, 3 );
	assert.deepEqual( r.gain, { gain: 900, times: 10, share: 0.45 } );
	assert.equal( r.rows[ 1 ].gain, 900 );
} );

test( 'weakest pet of zero: gain without a multiple', () => {
	const r = compute( data, 0, 0, null, false, 10, 0.04 );
	assert.equal( r.gain.times, null );
	assert.equal( r.gain.share, null );
} );

test( 'nothing beats the weakest pet', () => {
	const r = compute( data, 5000, 1e9, null, false, 10, 0.04 );
	assert.equal( r.rows.length, 0 );
	assert.equal( r.next, null );
	const x = texts( r, t, 'en', null );
	assert.equal( x.gain, 'gain_none gain_top' );
	assert.equal( x.empty, 'none_all' );
	const y = texts( compute( data, 0, 1e9, null, false, 10, 0.04 ), t, 'en', null );
	assert.equal( y.gain, 'gain_none gain_speed' );
	assert.equal( y.empty, 'none' );
} );

test( 'hunt below weakest but a rare egg beats it', () => {
	const x = texts( compute( data, 0, 200, null, false, 10, 0.04 ), t, 'en', null );
	assert.equal( x.gain, 'gain_none gain_rare' );
} );

test( 'no jackpot when it earns no more than the egg to hunt', () => {
	const r = compute( { zones, eggs: [ egg( 'Only Egg', 0, 50, 0.3 ) ] }, 0, null, null, false, 10, 0.04 );
	assert.equal( r.jackpot, null );
} );

test( 'no common egg at all', () => {
	const r = compute( { zones, eggs: [ egg( 'Rare Egg', 0, 50, 0.01 ) ] }, 0, 10, null, false, 10, 0.04 );
	assert.equal( r.hunt, null );
	const x = texts( r, t, 'en', null );
	assert.equal( x.hunt, 'no_hunt' );
	assert.equal( x.gain, 'no_hunt' );
} );

test( 'X2 Growth Speed halves hatch times; unknown stays unknown', () => {
	const r = compute( data, 100, null, null, true, 10, 0.04 );
	assert.equal( r.rows.find( ( e ) => e.egg === 'Good B Egg' ).hatch, 30 );
	assert.equal( r.rows.find( ( e ) => e.egg === 'Odd B Egg' ).hatch, null );
} );

test( 'table is capped at top', () => {
	assert.equal( compute( data, 100, null, null, false, 2, 0.04 ).rows.length, 2 );
	assert.equal( compute( data, 100, null, null, false, 2, 0.04 ).beats, 6 );
} );

test( 'one in N rounds and never shows 1 in 0', () => {
	const x = texts( compute( { zones, eggs: [ egg( 'Sure Egg', 0, 5, 0.73 ), egg( 'Q Egg', 0, 4, 1 / 24.6 ) ] }, 0, null, null, false, 10, 0.04 ), t, 'en', null );
	assert.equal( x.rows[ 0 ].odds, 'one_in:1|%2$s|%3$s|%4$s|%5$s' );
	assert.equal( x.rows[ 1 ].odds, 'one_in:25|%2$s|%3$s|%4$s|%5$s' );
} );
