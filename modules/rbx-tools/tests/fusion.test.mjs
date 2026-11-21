// Fusion planner: fusion.js compute() against fusion-vectors.json (written by
// fusion-make-vectors.py from the real recipes plus edge cases). fusion.test.php checks the PHP twin
// against the same file, so the two can never drift apart.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { compute, statusText, changeText } from '../assets/js/fusion.js';

const here = dirname( fileURLToPath( import.meta.url ) );
const cases = JSON.parse( readFileSync( join( here, 'fusion-vectors.json' ), 'utf8' ) );

for ( const c of cases ) {
	test( `compute: ${ c.name }`, () => {
		assert.deepEqual( compute( c.data, c.owned ), c.expect );
	} );
}

test( 'texts', () => {
	const t = { ready: 'R', ready_n: 'R %s', need_1: 'N1', need_n: 'N %s', gain: '+%s', loss: '-%s', unknown: '?', slot_1: 'S1', slot_n: 'S %s', worth: 'W %s' };
	const num = String;
	const fmt = String;
	assert.equal( statusText( { ready: true, times: 3, missing: [] }, t, num ), 'R 3' );
	assert.equal( statusText( { ready: false, times: 0, missing: [ { need: 2 }, { need: 1 } ] }, t, num ), 'N 3' );
	assert.equal( changeText( { change: -5, slots: 2 }, fmt, t, num ), '-5 S 2 W 5' );
	assert.equal( changeText( { change: 0, slots: 1 }, fmt, t, num ), '+0 S1' );
	assert.equal( changeText( { change: null, slots: 1 }, fmt, t, num ), '? S1' );
} );
