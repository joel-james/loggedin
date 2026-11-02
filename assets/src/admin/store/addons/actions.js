/**
 * Action creators for the addons store.
 *
 * Plain actions (`setItems`, `replaceItem`, `setRefreshing`) and the
 * async generators (`refresh`, `activateLicense`, `deactivateLicense`)
 * that hit REST through the `API_FETCH` control and dispatch results.
 *
 * The generator return values are `{ success: boolean, error?: string }`
 * tuples so the License modal can paint the failure reason inline
 * while still letting the global notice system handle success
 * confirmations.
 */
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/**
 * REST route of the add-ons catalogue and its license actions.
 *
 * @type {string}
 */
const BASE = '/loggedin/v1/addons';

// ---------------------------------------------------------------- //
// Plain action creators.
// ---------------------------------------------------------------- //

/**
 * Replace the cached catalogue with the given array.
 *
 * @param {Array} items Catalogue rows.
 */
export const setItems = ( items ) => ( { type: 'SET_ITEMS', items } );

/**
 * Patch a single catalogue row in place. Used after license actions
 * so only the affected card flips state.
 *
 * @param {Object} addon Shaped addon row.
 */
export const replaceItem = ( addon ) => ( { type: 'REPLACE_ITEM', addon } );

/**
 * Toggle the "user-triggered refresh in flight" flag.
 *
 * @param {boolean} value New value.
 */
export const setRefreshing = ( value ) => ( {
	type: 'SET_REFRESHING',
	isRefreshing: !! value,
} );

// ---------------------------------------------------------------- //
// Thunk-style generators.
// ---------------------------------------------------------------- //

/**
 * Force the server-side Freemius cache to rebuild and replace the
 * cached catalogue with the result.
 */
export function* refresh() {
	yield setRefreshing( true );

	try {
		const data = yield {
			type: 'API_FETCH',
			request: { path: `${ BASE }/refresh`, method: 'POST' },
		};

		yield setItems( Array.isArray( data?.items ) ? data.items : [] );

		dispatch( noticesStore ).createSuccessNotice(
			__( 'Add-ons list refreshed.', 'loggedin' ),
			{ type: 'snackbar' }
		);
	} catch ( error ) {
		dispatch( noticesStore ).createErrorNotice(
			error?.message ||
				__(
					'The add-ons list could not be refreshed. Try again.',
					'loggedin'
				),
			{ type: 'snackbar' }
		);
	} finally {
		yield setRefreshing( false );
	}
}

/**
 * Activate a license key against an addon's Freemius client.
 *
 * @param {number} id  Addon Freemius id.
 * @param {string} key License key.
 *
 * @return {{success: boolean, error?: string}} Whether it worked, and why not.
 */
export function* activateLicense( id, key ) {
	try {
		const data = yield {
			type: 'API_FETCH',
			request: {
				path: `${ BASE }/${ encodeURIComponent( id ) }/license`,
				method: 'POST',
				data: { key },
			},
		};

		if ( data?.success ) {
			yield replaceItem( data.addon );
			dispatch( noticesStore ).createSuccessNotice(
				__( 'License activated.', 'loggedin' ),
				{ type: 'snackbar' }
			);
			return { success: true };
		}

		return {
			success: false,
			error: __(
				'License activation failed. Please double-check the key and try again.',
				'loggedin'
			),
		};
	} catch ( error ) {
		return {
			success: false,
			error:
				error?.message ||
				__(
					'License activation failed. Please try again.',
					'loggedin'
				),
		};
	}
}

/**
 * Deactivate the addon's active license.
 *
 * @param {number} id Addon Freemius id.
 *
 * @return {{success: boolean, error?: string}} Whether it worked, and why not.
 */
export function* deactivateLicense( id ) {
	try {
		const data = yield {
			type: 'API_FETCH',
			request: {
				path: `${ BASE }/${ encodeURIComponent( id ) }/license`,
				method: 'DELETE',
			},
		};

		if ( data?.success ) {
			yield replaceItem( data.addon );
			dispatch( noticesStore ).createSuccessNotice(
				__( 'License deactivated.', 'loggedin' ),
				{ type: 'snackbar' }
			);
			return { success: true };
		}

		return {
			success: false,
			error: __(
				'License deactivation failed. Please try again.',
				'loggedin'
			),
		};
	} catch ( error ) {
		return {
			success: false,
			error:
				error?.message ||
				__(
					'License deactivation failed. Please try again.',
					'loggedin'
				),
		};
	}
}
