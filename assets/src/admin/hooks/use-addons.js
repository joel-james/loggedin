/**
 * Addons hook — thin wrapper over the `loggedin/addons` data store.
 *
 * Public API:
 *
 *   const {
 *     items,                // Array<Addon>
 *     isLoading,            // initial fetch in flight
 *     isRefreshing,         // user-triggered refresh in flight
 *     refresh,              // () => void
 *     activateLicense,      // (id, key) => Promise<{success, error?}>
 *     deactivateLicense,    // (id)     => Promise<{success, error?}>
 *   } = useAddons()
 *
 * `isLoading` is read from `@wordpress/data`'s resolution tracker
 * (`hasFinishedResolution`) rather than a flag in the reducer — the
 * tracker flips on both success and failure so a failed REST call
 * never leaves the UI stuck on a spinner.
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_KEY } from '../store/addons';

const useAddons = () => {
	const { items, isLoading, isRefreshing } = useSelect( ( select ) => {
		const store = select( STORE_KEY );

		return {
			items: store.getItems(),
			isLoading: ! store.hasFinishedResolution( 'getItems', [] ),
			isRefreshing: store.isRefreshing(),
		};
	}, [] );

	const { refresh, activateLicense, deactivateLicense } =
		useDispatch( STORE_KEY );

	return {
		items,
		isLoading,
		isRefreshing,
		refresh,
		activateLicense,
		deactivateLicense,
	};
};

export default useAddons;
