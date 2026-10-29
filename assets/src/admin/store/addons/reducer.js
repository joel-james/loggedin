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
const DEFAULT_STATE = {
	items: [],
	isRefreshing: false,
};

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
