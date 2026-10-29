/* global loggedin */

/**
 * Configure `@wordpress/api-fetch` for the plugin's REST surface.
 *
 * The PHP side (`Admin\Assets::script_vars()`) localises a `loggedin`
 * global containing the REST nonce. We attach it via the nonce
 * middleware so every outgoing request carries `X-WP-Nonce` and the
 * REST permission callbacks see an authenticated user.
 *
 * We intentionally do NOT install a custom root-URL middleware: the
 * WordPress-bundled `wp-api-fetch` is already configured against the
 * site's `/wp-json/` root, so calling `apiFetch({ path: '/loggedin/v1/addons' })`
 * resolves correctly out of the box. Layering a second root-URL
 * middleware that also injects the namespace into the path leads to
 * "No route was found" errors when something slips through with
 * just `'addons'` as the path.
 *
 * The function is a no-op when the `loggedin` global is missing —
 * for example, when the bundle is loaded in a unit-test context.
 */
import apiFetch from '@wordpress/api-fetch';

const initApi = () => {
	if ( typeof loggedin === 'undefined' ) {
		return;
	}

	if ( loggedin.restNonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( loggedin.restNonce ) );
	}

	/*
	 * An idle session ended on the server answers with
	 * `loggedin_idle_logout` and the login URL. Send the user there
	 * instead of leaving the screen showing a failed request. Every
	 * admin app on this screen (add-on pages too) shares this apiFetch.
	 */
	apiFetch.use( ( options, next ) =>
		next( options ).catch( ( error ) => {
			if (
				'loggedin_idle_logout' === error?.code &&
				error?.data?.login_url
			) {
				window.location.assign( error.data.login_url );
			}

			throw error;
		} )
	);
};

initApi();
