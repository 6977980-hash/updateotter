// PHP/JS twin parity (ENG-31, ENG-34, ENG-35). Vectors are written by rbx-data/tests/make-vectors.php.
// Run: node --test tests/parity.test.mjs  (RBX_DATA_DIR overrides the rbx-data checkout path)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatValue, formatExact } from '../assets/js/format.js';
import { parseNumber } from '../assets/js/parse-input.js';
import { compile, evaluate, SyntaxError, Unavailable } from '../assets/js/formula-engine.js';

const here = dirname( fileURLToPath( import.meta.url ) );
const dataDir = process.env.RBX_DATA_DIR || join( here, '..', '..', 'rbx-data' );
const load = ( n ) => JSON.parse( readFileSync( join( dataDir, 'tests', 'fixtures', n ), 'utf8' ) );

test( 'format vectors', () => {
	for ( const v of load( 'format_vectors.json' ) ) {
		const out = v.format === 'exact' ? formatExact( v.value, v.locale ) : formatValue( v.value, v.format, v.locale, v.ladder );
		assert.equal( out, v.out, `${ v.value } as ${ v.format }/${ v.locale }` );
	}
} );

test( 'parse vectors', () => {
	for ( const v of load( 'parse_vectors.json' ) ) {
		const out = parseNumber( v.text, v.locale, v.ladder, v.suffix, v.opts );
		assert.deepEqual( out, v.out, `"${ v.text }" + "${ v.suffix }" ${ JSON.stringify( v.opts ) }` );
	}
} );

test( 'formula vectors', () => {
	const f = load( 'formula_vectors.json' );
	for ( const c of f.cases ) {
		let ast;
		try {
			ast = compile( c.expr, f.declared );
		} catch ( e ) {
			assert.ok( e instanceof SyntaxError, c.expr );
			assert.equal( e.message, c.syntax_error, c.expr );
			continue;
		}
		assert.equal( c.syntax_error, undefined, `${ c.expr } should not compile` );
		assert.deepEqual( ast, c.ast, c.expr );
		try {
			const v = evaluate( ast, c.inputs, f.data );
			assert.equal( v, c.value, c.expr );
		} catch ( e ) {
			assert.ok( e instanceof Unavailable, c.expr + ': ' + e.message );
			assert.deepEqual( [ e.reason, e.what ], c.unavailable, c.expr );
		}
	}
} );

// Real-dataset fixtures from `wp rbx fixtures --game=<key>` (DATA-34): JS must give PHP's result.
for ( const name of readdirSync( join( dataDir, 'tests', 'fixtures' ) ).filter( ( n ) => n.endsWith( '-real.json' ) ) ) {
	test( `real dataset ${ name }`, () => {
		const f = load( name );
		const asts = {};
		for ( const [ id, fm ] of Object.entries( f.formulas ) ) {
			asts[ id ] = compile( fm.expr, fm.decl );
			assert.deepEqual( asts[ id ], fm.ast, `${ id } AST` );
		}
		for ( const c of f.cases ) {
			for ( const k of [ 'real', 'verified' ] ) {
				const want = c[ k ];
				const label = `${ c.formula } ${ k } ${ JSON.stringify( c.inputs ) }`;
				try {
					const v = evaluate( asts[ c.formula ], c.inputs, f[ k === 'real' ? 'data' : 'verified' ] );
					assert.ok( 'value' in want, label + ' should be unavailable' );
					assert.ok( Math.abs( v - want.value ) <= 1e-9 * Math.max( 1, Math.abs( want.value ) ), `${ label }: ${ v } vs ${ want.value }` );
				} catch ( e ) {
					if ( e instanceof assert.AssertionError ) {
						throw e;
					}
					assert.ok( e instanceof Unavailable, label + ': ' + e.message );
					assert.deepEqual( [ e.reason, e.what ], want.unavailable, label );
				}
			}
		}
	} );
}
