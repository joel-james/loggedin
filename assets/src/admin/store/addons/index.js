/**
 * `@wordpress/data` store for the Add-ons page.
 *
 * The Add-ons page calls into the plugin's REST API to fetch the add-on
 * catalogue and the per-add-on license status, and to activate or
 * deactivate license keys. Wrapping those calls in a Redux store gives
 * us three things:
 *
 *   1. Caching — the catalogue is fetched once on first select and
 *      re-used across page switches.
 *   2. Resolvers — `getItems` triggers its REST fetch lazily, the
 *      first time any component reads it.
 *   3. Loading flags — `isResolving` from `@wordpress/data` exposes
 *      the in-flight state without us tracking it manually.
 */
import { createReduxStore, register } from '@wordpress/data';
import * as actions from './actions';
import * as selectors from './selectors';
import * as resolvers from './resolvers';
import controls from './controls';
import reducer from './reducer';

/**
 * Name the store is registered under.
 *
 * @type {string}
 */
export const STORE_KEY = 'loggedin/addons';

/**
 * The add-ons store descriptor, registered on import.
 *
 * @type {Object}
 */
export const store = createReduxStore( STORE_KEY, {
	reducer,
	actions,
	selectors,
	resolvers,
	controls,
} );

register( store );
