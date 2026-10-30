/* global loggedin */

/**
 * Configure `@wordpress/api-fetch` for the plugin's screen.
 *
 * No nonce or root-URL middleware here: WordPress adds both in an inline
 * script after `wp-api-fetch` (`wp_default_packages_inline_scripts()`,
 * since 5.0) on every screen that loads it, and this bundle depends on
 * it. WordPress's nonce middleware is also the one api-fetch refreshes
 * when the nonce expires, so a second one with the nonce printed at page
 * load would only be stale weight.
 *
 * The function is a no-op when the `loggedin` global is missing —
 * for example, when the bundle is loaded in a unit-test context.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Add the plugin's api-fetch middleware.
 *
 * @return {void}
 */
const initApi = () => {
	if ( typeof loggedin === 'undefined' ) {
		return;
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
