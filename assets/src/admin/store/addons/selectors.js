/**
 * Selectors for the addons store.
 */

/**
 * Return the cached catalogue items.
 *
 * The first invocation triggers the `getItems` resolver, which
 * fetches the catalogue from the REST API and dispatches `setItems`.
 * Subsequent reads return the cached array.
 *
 * @param {Object} state Store state.
 */
export function getItems( state ) {
	return state.items;
}

/**
 * Is a user-triggered refresh currently in flight?
 *
 * @param {Object} state Store state.
 */
export function isRefreshing( state ) {
	return state.isRefreshing;
}
