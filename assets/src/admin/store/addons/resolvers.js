/**
 * Resolvers for the addons store.
 *
 * `@wordpress/data` triggers a resolver the first time a selector is
 * called for a given set of arguments. The resolver runs its side
 * effects (a REST fetch), dispatches actions to populate state, and
 * is then never called again unless the cache is invalidated.
 *
 * A failed fetch is caught rather than thrown. `@wordpress/data` would
 * mark the resolution finished on a throw too, so the spinner stops
 * either way; catching is what lets us tell the user. On failure we
 * dispatch an empty list and an error snackbar saying why the
 * catalogue is empty.
 */
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { setItems } from './actions';

/**
 * Resolver paired with `getItems` — fetches the catalogue on first
 * read.
 */
export function* getItems() {
	try {
		const data = yield {
			type: 'API_FETCH',
			request: { path: '/loggedin/v1/addons' },
		};

		yield setItems( Array.isArray( data?.items ) ? data.items : [] );
	} catch ( error ) {
		yield setItems( [] );

		dispatch( noticesStore ).createErrorNotice(
			error?.message ||
				__( 'The add-ons list could not be loaded.', 'loggedin' ),
			{ type: 'snackbar' }
		);
	}
}
