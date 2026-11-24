import { amountsMatch, createIntentRequestTracker } from '../src/intent-request';

describe( 'Blink set-intent request tracker', () => {
	it( 'keeps a request current while it is the only one in flight', () => {
		const tracker = createIntentRequestTracker();
		const id = tracker.start();

		// Re-rendering or re-running the effect without a new request must not
		// make the response stale. That was the cause of stuck card fields.
		expect( tracker.isCurrent( id ) ).toBe( true );
	} );

	it( 'makes an older request stale when a newer one starts', () => {
		const tracker = createIntentRequestTracker();
		const first = tracker.start();
		const second = tracker.start();

		expect( tracker.isCurrent( first ) ).toBe( false );
		expect( tracker.isCurrent( second ) ).toBe( true );
	} );

	it( 'makes requests in flight stale when invalidated', () => {
		const tracker = createIntentRequestTracker();
		const id = tracker.start();
		tracker.invalidate();

		expect( tracker.isCurrent( id ) ).toBe( false );
	} );

	it( 'keeps separate trackers independent', () => {
		const a = createIntentRequestTracker();
		const b = createIntentRequestTracker();
		const id = a.start();
		b.start();
		b.start();

		expect( a.isCurrent( id ) ).toBe( true );
	} );
} );

describe( 'Blink amount comparison', () => {
	it( 'matches amounts formatted with different decimals', () => {
		// A store set to 0 decimals has a client total of "12"; the server sends "12.00".
		expect( amountsMatch( '12', '12.00' ) ).toBe( true );
		expect( amountsMatch( '12.50', '12.5' ) ).toBe( true );
	} );

	it( 'does not match different amounts', () => {
		expect( amountsMatch( '12.00', '13.00' ) ).toBe( false );
		expect( amountsMatch( '12.345', '12.35' ) ).toBe( false );
	} );

	it( 'does not match missing or non-numeric amounts', () => {
		expect( amountsMatch( '', '0' ) ).toBe( false );
		expect( amountsMatch( null, null ) ).toBe( false );
		expect( amountsMatch( undefined, '12.00' ) ).toBe( false );
		expect( amountsMatch( 'abc', 'abc' ) ).toBe( false );
	} );
} );
