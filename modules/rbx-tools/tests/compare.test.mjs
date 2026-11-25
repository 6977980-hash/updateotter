// Pet compare rules (compare.js; PetCompare.php is its twin and is checked against it in the
// browser). Run: node --test modules/rbx-tools/tests/compare.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { compute, lines, ratio } from '../assets/js/compare.js';

const rows = [
	[ 'it', 'row_income', 'max:i' ],
	[ 'spt', 'row_speed', 'min:sp' ],
	[ 'ct', 'row_chance', 'max:c' ],
	[ 'hvt', 'row_hunt', 'max:hv' ],
	[ 'how', 'row_how', '' ],
];
const pet = ( n, i, sp, c ) => ( { n, i, sp, spt: sp === null ? '—' : String( sp ), c, hv: c === null ? null : i * c, how: '' } );
const pets = {
	big: pet( 'Big', 1000, 500, 0.01 ),
	mid: pet( 'Mid', 300, 100, 0.2 ),
	twin: pet( 'Twin', 1020, 500, 0.01 ),
	ev: pet( 'Event', 50, null, null ),
	tiny: pet( 'Tiny', 1, 0, 0.5 ),
};
const t = new Proxy( {}, { get: ( _, k ) => String( k ) + ':%1$s|%2$s|%3$s|%4$s' } );

test( 'best marks: highest income, lowest Speed, highest chance and hunt value', () => {
	const r = compute( pets, [ 'big', 'mid' ], rows );
	assert.deepEqual( r.best, { it: [ 0 ], spt: [ 1 ], ct: [ 1 ], hvt: [ 1 ] } );
} );

test( 'no best mark when only one value is known or values tie', () => {
	const r = compute( pets, [ 'mid', 'ev' ], rows );
	assert.equal( r.best.spt, undefined );
	assert.equal( r.best.hvt, undefined );
	const tie = compute( pets, [ 'big', 'twin' ], rows );
	assert.equal( tie.best.spt, undefined );
	assert.deepEqual( tie.best.it, [ 1 ] );
} );

test( 'lines: stronger pet first, slots to match, hunt and Speed winners', () => {
	const r = compute( pets, [ 'mid', 'big' ], rows );
	assert.deepEqual( lines( r, t, 'en' ), [
		'times:Big|3.3|Mid|4',
		'best_hunt:Mid|%2$s|%3$s|%4$s',
		'easiest:Mid|100|%3$s|%4$s',
	] );
} );

test( 'about the same income reads "same"; huge gaps drop the slot count', () => {
	assert.equal( lines( compute( pets, [ 'big', 'twin' ], rows ), t, 'en' )[ 0 ], 'same:Twin|Big|%3$s|%4$s' );
	assert.equal( lines( compute( pets, [ 'tiny', 'big' ], rows ), t, 'en' )[ 0 ], 'times_only:Big|1K|Tiny|%4$s' );
} );

test( 'ratio wording', () => {
	assert.equal( ratio( 2, 'en' ), '2' );
	assert.equal( ratio( 3.33, 'en' ), '3.3' );
	assert.equal( ratio( 12.4, 'en' ), '12' );
	assert.equal( ratio( 12500000000, 'en' ), '12.5B' );
} );
