// Safe formula language (DATA-25). Twin of rbx-data/includes/Formula/{Parser,Interpreter}.php.
// No eval. compile() returns the same array AST as PHP; evaluate() throws Unavailable like PHP.

export const FUNCTIONS = {
	min: [ 1, 99 ],
	max: [ 1, 99 ],
	floor: [ 1, 1 ],
	ceil: [ 1, 1 ],
	abs: [ 1, 1 ],
	round: [ 1, 2 ],
	clamp: [ 3, 3 ],
	product: [ 1, 1 ],
	sum: [ 1, 1 ],
	if: [ 3, 3 ],
	has: [ 1, 1 ],
	param: [ 2, 2 ],
};

export class SyntaxError extends Error {}

export class Unavailable extends Error {
	constructor( reason, what = '' ) {
		super( reason + ( what !== '' ? ': ' + what : '' ) );
		this.reason = reason;
		this.what = what;
	}
}

function lex( s ) {
	const out = [];
	let i = 0;
	const re = [
		[ 'num', /^\d+(?:\.\d+)?/ ],
		[ 'id', /^[A-Za-z_][A-Za-z0-9_]*/ ],
		[ 'op', /^(<=|>=|==|!=|[-+*/^(),.<>])/ ],
	];
	while ( i < s.length ) {
		if ( /\s/.test( s[ i ] ) ) {
			i++;
			continue;
		}
		const rest = s.slice( i );
		let hit = null;
		for ( const [ type, r ] of re ) {
			const m = rest.match( r );
			if ( m ) {
				hit = [ type, m[ 0 ] ];
				break;
			}
		}
		if ( ! hit ) {
			throw new SyntaxError( 'Unexpected character "' + s[ i ] + '".' );
		}
		out.push( hit );
		i += hit[ 1 ].length;
	}
	return out;
}

export function compile( expr, inputs, fieldChecker = null ) {
	if ( expr.length > 2000 ) {
		throw new SyntaxError( 'Expression too long.' );
	}
	const tokens = lex( expr );
	let pos = 0;
	const peek = ( type, val = null ) => {
		const t = tokens[ pos ];
		return !! t && t[ 0 ] === type && ( val === null || t[ 1 ] === val );
	};
	const take = ( type, val = null ) => {
		if ( ! peek( type, val ) ) {
			const got = tokens[ pos ] ? tokens[ pos ][ 1 ] : 'end of formula';
			throw new SyntaxError( 'Expected ' + ( val ?? type ) + ', got "' + got + '".' );
		}
		return tokens[ pos++ ][ 1 ];
	};
	const inputName = () => {
		let n = take( 'id' );
		if ( n === 'input' && peek( 'op', '.' ) ) {
			pos++;
			n = take( 'id' );
		}
		if ( ! Object.prototype.hasOwnProperty.call( inputs, n ) ) {
			throw new SyntaxError( 'Undeclared input "' + n + '".' );
		}
		return n;
	};
	function cmp() {
		const a = add();
		for ( const op of [ '<=', '>=', '==', '!=', '<', '>' ] ) {
			if ( peek( 'op', op ) ) {
				pos++;
				return [ 'bin', op, a, add() ];
			}
		}
		return a;
	}
	function add() {
		let a = mul();
		while ( peek( 'op', '+' ) || peek( 'op', '-' ) ) {
			const op = tokens[ pos++ ][ 1 ];
			a = [ 'bin', op, a, mul() ];
		}
		return a;
	}
	function mul() {
		let a = unary();
		while ( peek( 'op', '*' ) || peek( 'op', '/' ) ) {
			const op = tokens[ pos++ ][ 1 ];
			a = [ 'bin', op, a, unary() ];
		}
		return a;
	}
	function unary() {
		if ( peek( 'op', '-' ) ) {
			pos++;
			return [ 'neg', unary() ];
		}
		const a = primary();
		if ( peek( 'op', '^' ) ) {
			pos++;
			return [ 'bin', '^', a, unary() ];
		}
		return a;
	}
	function primary() {
		if ( peek( 'num' ) ) {
			return [ 'num', parseFloat( take( 'num' ) ) ];
		}
		if ( peek( 'op', '(' ) ) {
			pos++;
			const e = cmp();
			take( 'op', ')' );
			return e;
		}
		const name = take( 'id' );
		if ( name === 'ref' || name === 'refs' ) {
			take( 'op', '(' );
			const ent = take( 'id' );
			take( 'op', ',' );
			const inp = inputName();
			take( 'op', ')' );
			take( 'op', '.' );
			const field = take( 'id' );
			const decl = inputs[ inp ];
			const type = String( decl.type ?? '' );
			if ( name === 'refs' && type !== 'ref_list' ) {
				throw new SyntaxError( 'refs() needs a list input; "' + inp + '" is ' + type + '.' );
			}
			if ( name === 'ref' && type !== 'ref' ) {
				throw new SyntaxError( 'ref() needs a record input; "' + inp + '" is ' + type + '.' );
			}
			if ( decl.target !== undefined && decl.target !== ent ) {
				throw new SyntaxError( 'Input "' + inp + '" points to ' + decl.target + ', not ' + ent + '.' );
			}
			if ( fieldChecker && ! fieldChecker( ent, field ) ) {
				throw new SyntaxError( 'Unknown or non-numeric field ' + ent + '.' + field + '.' );
			}
			return [ name, ent, inp, field ];
		}
		if ( peek( 'op', '(' ) ) {
			if ( ! Object.prototype.hasOwnProperty.call( FUNCTIONS, name ) ) {
				throw new SyntaxError( 'Unknown function "' + name + '".' );
			}
			pos++;
			if ( name === 'has' ) {
				const inp = inputName();
				take( 'op', ')' );
				return [ 'has', inp ];
			}
			if ( name === 'param' ) {
				const key = take( 'id' );
				take( 'op', ',' );
				const pn = take( 'id' );
				take( 'op', ')' );
				return [ 'param', key, pn ];
			}
			const args = [];
			if ( ! peek( 'op', ')' ) ) {
				args.push( cmp() );
				while ( peek( 'op', ',' ) ) {
					pos++;
					args.push( cmp() );
				}
			}
			take( 'op', ')' );
			const [ lo, hi ] = FUNCTIONS[ name ];
			if ( args.length < lo || args.length > hi ) {
				throw new SyntaxError( 'Wrong number of arguments for ' + name + '().' );
			}
			return [ 'call', name, args ];
		}
		pos--;
		const inp = inputName();
		const t = String( inputs[ inp ].type ?? '' );
		if ( t !== 'number' && t !== 'bool' ) {
			throw new SyntaxError( 'Input "' + inp + '" is a record; use ref(' + ( inputs[ inp ].target ?? 'ENT' ) + ', ' + inp + ').field.' );
		}
		return [ 'in', inp ];
	}
	const ast = cmp();
	if ( pos < tokens.length ) {
		throw new SyntaxError( 'Unexpected "' + tokens[ pos ][ 1 ] + '".' );
	}
	return ast;
}

const isNum = ( x ) => typeof x === 'number';

function value( v, what ) {
	if ( isNum( v ) ) {
		return v;
	}
	if ( v && typeof v === 'object' && v.s === 'c' && isNum( v.v ) ) {
		return v.v;
	}
	const state = v && typeof v === 'object' ? String( v.s ?? 'u' ) : 'u';
	throw new Unavailable( 'state_' + state, what );
}

function field( data, ent, id, f ) {
	const r = data.records && data.records[ ent ] && data.records[ ent ][ id ];
	if ( ! r ) {
		throw new Unavailable( 'record', id );
	}
	return value( r[ f ] ?? null, id + '.' + f );
}

const num = ( v ) => {
	if ( Array.isArray( v ) ) {
		throw new Unavailable( 'type', 'list' );
	}
	return v;
};
const lst = ( v ) => ( Array.isArray( v ) ? v : [ v ] );

function ev( n, inputs, data ) {
	switch ( n[ 0 ] ) {
		case 'num':
			return n[ 1 ];
		case 'in': {
			const x = inputs[ n[ 1 ] ];
			if ( typeof x === 'boolean' ) {
				return x ? 1 : 0;
			}
			if ( ! isNum( x ) ) {
				throw new Unavailable( 'input', n[ 1 ] );
			}
			return x;
		}
		case 'has': {
			const x = inputs[ n[ 1 ] ];
			return x === undefined || x === null || x === '' || ( Array.isArray( x ) && x.length === 0 ) ? 0 : 1;
		}
		case 'ref': {
			const id = inputs[ n[ 2 ] ];
			if ( typeof id !== 'string' || id === '' ) {
				throw new Unavailable( 'input', n[ 2 ] );
			}
			return field( data, n[ 1 ], id, n[ 3 ] );
		}
		case 'refs':
			return lst( inputs[ n[ 2 ] ] ?? [] ).map( ( id ) => field( data, n[ 1 ], String( id ), n[ 3 ] ) );
		case 'param':
			return value( data.params && data.params[ n[ 1 ] ] ? data.params[ n[ 1 ] ][ n[ 2 ] ] ?? null : null, 'param ' + n[ 1 ] + '.' + n[ 2 ] );
		case 'neg':
			return -num( ev( n[ 1 ], inputs, data ) );
		case 'bin': {
			const a = num( ev( n[ 2 ], inputs, data ) );
			const b = num( ev( n[ 3 ], inputs, data ) );
			switch ( n[ 1 ] ) {
				case '+':
					return a + b;
				case '-':
					return a - b;
				case '*':
					return a * b;
				case '/':
					if ( b === 0 ) {
						throw new Unavailable( 'math', 'division by zero' );
					}
					return a / b;
				case '^':
					return a ** b;
				case '<':
					return a < b ? 1 : 0;
				case '<=':
					return a <= b ? 1 : 0;
				case '>':
					return a > b ? 1 : 0;
				case '>=':
					return a >= b ? 1 : 0;
				case '==':
					return a === b ? 1 : 0;
				case '!=':
					return a !== b ? 1 : 0;
			}
			throw new Unavailable( 'op', n[ 1 ] );
		}
		case 'call':
			return call( n[ 1 ], n[ 2 ], inputs, data );
	}
	throw new Unavailable( 'node', String( n[ 0 ] ) );
}

function call( fn, args, inputs, data ) {
	if ( fn === 'if' ) {
		const c = num( ev( args[ 0 ], inputs, data ) );
		return num( ev( c !== 0 ? args[ 1 ] : args[ 2 ], inputs, data ) );
	}
	const vals = args.map( ( a ) => ev( a, inputs, data ) );
	switch ( fn ) {
		case 'product':
			return lst( vals[ 0 ] ).reduce( ( p, x ) => p * x, 1 );
		case 'sum':
			return lst( vals[ 0 ] ).reduce( ( s, x ) => s + x, 0 );
		case 'min':
		case 'max': {
			const flat = vals.flatMap( lst );
			if ( ! flat.length ) {
				throw new Unavailable( 'math', 'empty list' );
			}
			return fn === 'min' ? Math.min( ...flat ) : Math.max( ...flat );
		}
		case 'floor':
			return Math.floor( num( vals[ 0 ] ) );
		case 'ceil':
			return Math.ceil( num( vals[ 0 ] ) );
		case 'abs':
			return Math.abs( num( vals[ 0 ] ) );
		case 'round': {
			const x = num( vals[ 0 ] );
			const d = vals.length > 1 ? Math.trunc( num( vals[ 1 ] ) ) : 0;
			const f = 10 ** d;
			const r = Math.floor( Math.abs( x ) * f + 0.5 ) / f;
			return x < 0 ? -r : r;
		}
		case 'clamp':
			return Math.min( Math.max( num( vals[ 0 ] ), num( vals[ 1 ] ) ), num( vals[ 2 ] ) );
	}
	throw new Unavailable( 'function', fn );
}

export function evaluate( ast, inputs, data ) {
	const v = ev( ast, inputs, data );
	if ( Array.isArray( v ) ) {
		throw new Unavailable( 'type', 'list' );
	}
	if ( ! Number.isFinite( v ) ) {
		throw new Unavailable( 'math' );
	}
	return v;
}
