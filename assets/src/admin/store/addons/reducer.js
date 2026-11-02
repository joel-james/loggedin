/**
 * Reducer for the addons store.
 *
 * State shape:
 *   {
 *     items:        Array<Addon>   // shaped catalogue rows from REST
 *     isRefreshing: boolean        // user-triggered refresh in flight
 *   }
 *
 * We deliberately don't track an initial-load flag here —
 * `@wordpress/data` already exposes per-selector resolution state
 * via `hasFinishedResolution()`, so duplicating it in the reducer
 * is error-prone (easy to forget to flip it on the failure path).
 */
/**
 * State before the catalogue loads.
 *
 * @type {{ items: Array, isRefreshing: boolean }}
 */
const DEFAULT_STATE = {
	items: [],
	isRefreshing: false,
};

/**
 * Apply one action to the add-ons state.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Dispatched action.
 * @return {Object} Next state.
 */
const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_ITEMS':
			return { ...state, items: action.items };

		case 'REPLACE_ITEM':
			return {
				...state,
				items: state.items.map( ( item ) =>
					Number( item.id ) === Number( action.addon?.id )
						? action.addon
						: item
				),
			};

		case 'SET_REFRESHING':
			return { ...state, isRefreshing: !! action.isRefreshing };

		default:
			return state;
	}
};

export default reducer;
