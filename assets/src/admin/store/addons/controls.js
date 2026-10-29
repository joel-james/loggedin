/**
 * Custom control handlers for the addons store.
 *
 * Actions and resolvers `yield { type: 'API_FETCH', request }`
 * instead of calling `apiFetch` directly. That layer of indirection
 * means tests can swap the handler for a stub, and it keeps the
 * action / resolver code free of `async/await` (the generator
 * runtime in `@wordpress/data` runs the controls for us).
 */
import apiFetch from '@wordpress/api-fetch';

const controls = {
	API_FETCH( { request } ) {
		return apiFetch( request );
	},
};

export default controls;
