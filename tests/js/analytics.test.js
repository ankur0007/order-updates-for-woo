const { computeRange, fmt, esc } = require( '../../assets/Admin/js/analytics' );

// ---------------------------------------------------------------------------
// fmt
// ---------------------------------------------------------------------------

describe( 'fmt', () => {
	test( 'zero-pads single-digit month and day', () => {
		expect( fmt( new Date( 2026, 0, 5 ) ) ).toBe( '2026-01-05' );
	} );

	test( 'formats double-digit month and day unchanged', () => {
		expect( fmt( new Date( 2026, 11, 31 ) ) ).toBe( '2026-12-31' );
	} );
} );

// ---------------------------------------------------------------------------
// esc
// ---------------------------------------------------------------------------

describe( 'esc', () => {
	test( 'escapes < and >', () => {
		expect( esc( '<script>' ) ).toBe( '&lt;script&gt;' );
	} );

	test( 'coerces non-string values to string before escaping', () => {
		expect( typeof esc( 42 ) ).toBe( 'string' );
	} );
} );

// ---------------------------------------------------------------------------
// computeRange
// ---------------------------------------------------------------------------

describe( 'computeRange', () => {
	test( 'all_time from is 2000-01-01', () => {
		expect( computeRange( 'all_time' ).from ).toBe( '2000-01-01' );
	} );

	test( 'all_time to equals today', () => {
		expect( computeRange( 'all_time' ).to ).toBe( fmt( new Date() ) );
	} );

	test( 'this_month from is the first of the current month', () => {
		const now   = new Date();
		const first = fmt( new Date( now.getFullYear(), now.getMonth(), 1 ) );
		expect( computeRange( 'this_month' ).from ).toBe( first );
	} );

	test( 'this_month to equals today', () => {
		expect( computeRange( 'this_month' ).to ).toBe( fmt( new Date() ) );
	} );

	test( 'last_month covers correct first and last day', () => {
		const now   = new Date();
		const first = fmt( new Date( now.getFullYear(), now.getMonth() - 1, 1 ) );
		const last  = fmt( new Date( now.getFullYear(), now.getMonth(), 0 ) );
		const range = computeRange( 'last_month' );
		expect( range.from ).toBe( first );
		expect( range.to ).toBe( last );
	} );

	test( 'last_3_months to equals today', () => {
		expect( computeRange( 'last_3_months' ).to ).toBe( fmt( new Date() ) );
	} );

	test( 'unknown preset returns today for both from and to', () => {
		const today = fmt( new Date() );
		const range = computeRange( 'unknown_preset' );
		expect( range.from ).toBe( today );
		expect( range.to ).toBe( today );
	} );
} );
