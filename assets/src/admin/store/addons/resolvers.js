/**
 * Resolvers for the addons store.
 *
 * `@wordpress/data` triggers a resolver the first time a selector is
 * called for a given set of arguments. The resolver runs its side
 * effects (a REST fetch), dispatches actions to populate state, and
 * is then never called again unless the cache is invalidated.
 *
 * We *always* dispatch `setItems`, even on failure — without this
 * `hasFinishedResolution` also stays false and the React UI is
 * stuck on a spinner. On failure we dispatch an empty array and an
 * error snackbar so the user understands why the catalogue is empty.
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
