/**
 * Read and edit one site option through the core-data `root/site` entity.
 *
 * The option must be registered with `show_in_rest`. Edits go to the
 * entity's edit buffer, not the server: the page header's Save (see
 * {@see useSave}) sends every edited option in one request.
 */
import { useEntityProp } from '@wordpress/core-data';

/**
 * Keys of an object option, read and written one at a time.
 *
 * Writes spread the rest of the stored object, so keys this screen
 * doesn't know (an add-on's) are never dropped.
 *
 * @param {string} option     Option name.
 * @param {Object} [defaults] Values used while a key is unset.
 * @return {{ values: Object, get: Function, set: Function, replace: Function }} Accessors.
 */
const useOption = ( option, defaults = {} ) => {
	const [ stored, setStored ] = useEntityProp( 'root', 'site', option );

	// An empty PHP array can arrive as `[]`; treat it as no values.
	const values =
		stored && typeof stored === 'object' && ! Array.isArray( stored )
			? stored
			: {};

	return {
		values,
		get: ( key, fallback = defaults[ key ] ) =>
			values[ key ] === undefined ? fallback : values[ key ],
		set: ( key, value ) => setStored( { ...values, [ key ]: value } ),
		// For map options whose keys are the data (role → minutes).
		replace: setStored,
	};
};

export default useOption;
