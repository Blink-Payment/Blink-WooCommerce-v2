/**
 * Whether two amounts are the same number, however they are formatted.
 *
 * The block's total uses the store's decimal setting ("12" on a store with no
 * decimals) but the server always sends two decimals ("12.00"). Comparing the
 * strings never matched, so the block asked for a new intent again and again.
 *
 * @param {string|number} a First amount.
 * @param {string|number} b Second amount.
 * @return {boolean} True when both are numbers and equal.
 */
export function amountsMatch( a, b ) {
	if ( a === '' || b === '' || a === null || b === null || a === undefined || b === undefined ) {
		return false;
	}
	const first = Number( a );
	const second = Number( b );
	return Number.isFinite( first ) && Number.isFinite( second ) && first === second;
}

/**
 * Tracks which set-intent request is the latest one.
 *
 * Block checkout asks for a new payment intent whenever the cart total changes.
 * Only the response to the most recent request may update the card fields:
 * an older response is for a total the shopper no longer has.
 *
 * Staleness is decided by request order, not by React effect cleanup. The
 * set-intent effect re-runs when its own loading flag changes, so a cleanup
 * based check discarded every response and left the fields loading forever.
 *
 * @return {{start: function(): number, isCurrent: function(number): boolean, invalidate: function(): void}} Tracker.
 */
export function createIntentRequestTracker() {
	let latest = 0;

	return {
		// Begin a request and return its ID.
		start() {
			latest += 1;
			return latest;
		},
		// Whether the request with this ID is still the latest one.
		isCurrent( id ) {
			return id === latest;
		},
		// Make every request in flight stale, e.g. when the total drops to zero.
		invalidate() {
			latest += 1;
		},
	};
}
