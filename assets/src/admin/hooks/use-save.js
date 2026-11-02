/**
 * One save state for the whole admin screen.
 *
 * Every setting on the screen (the core `loggedin_settings` option and
 * any add-on option registered with `show_in_rest`) is edited through
 * the core-data `root/site` entity, so every page shares one change
 * count, one Save and one Discard. Add-on panels built before the kit
 * edit the same entity with `useEntityProp`, so they save with it too.
 */
import { __ } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';

// Stable fallback so mapSelect does not hand a fresh `{}`, and force a
// re-render, on every store change.
const EMPTY = {};

/**
 * Number of values that differ between a stored and an edited option.
 * Object options (our settings arrays) count per key.
 *
 * @param {*} saved  Stored value.
 * @param {*} edited Edited value.
 * @return {number} Changed values.
 */
const diffCount = ( saved, edited ) => {
	const isObject = ( v ) =>
		v && typeof v === 'object' && ! Array.isArray( v );

	if ( ! isObject( saved ) || ! isObject( edited ) ) {
		return JSON.stringify( saved ) === JSON.stringify( edited ) ? 0 : 1;
	}

	const keys = new Set( [
		...Object.keys( saved ),
		...Object.keys( edited ),
	] );

	return [ ...keys ].filter(
		( key ) =>
			JSON.stringify( saved[ key ] ) !== JSON.stringify( edited[ key ] )
	).length;
};

/**
 * The screen's save state: what changed, and Save and Discard for it.
 *
 * Save sends every edited `root/site` value in one request; a second
 * click while it runs is ignored. A failed save keeps the edits and
 * reports the server's message in `error`.
 *
 * @return {{ count: number, isDirty: boolean, isSaving: boolean, hasLoaded: boolean, justSaved: boolean, error: string, save: () => Promise<void>, discard: () => void }}
 *     Changed values, flags, the last save error ('' when none), and the two actions.
 */
const useSave = () => {
	const { record, edits, isSaving, hasLoaded } = useSelect( ( select ) => {
		const core = select( coreStore );

		return {
			// Reading the record also starts its first fetch.
			record: core.getEntityRecord( 'root', 'site' ) || EMPTY,
			edits: core.getEntityRecordEdits( 'root', 'site' ) || EMPTY,
			isSaving: core.isSavingEntityRecord( 'root', 'site' ),
			hasLoaded: core.hasFinishedResolution( 'getEntityRecord', [
				'root',
				'site',
			] ),
		};
	}, [] );

	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( coreStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const { getLastEntitySaveError } = useSelect( coreStore );

	const [ error, setError ] = useState( '' );
	const [ justSaved, setJustSaved ] = useState( false );

	const count = Object.keys( edits ).reduce(
		( sum, key ) => sum + diffCount( record[ key ], edits[ key ] ),
		0
	);

	// Gates a second Save click while the first is still in flight.
	const inFlight = useRef( false );

	const save = async () => {
		if ( inFlight.current ) {
			return;
		}

		inFlight.current = true;
		setError( '' );

		try {
			const saved = await saveEditedEntityRecord( 'root', 'site' );

			if ( saved ) {
				setJustSaved( true );
				createSuccessNotice( __( 'Settings saved.', 'loggedin' ), {
					type: 'snackbar',
				} );
			} else {
				setError(
					getLastEntitySaveError( 'root', 'site' )?.message ||
						__( 'The server didn’t answer.', 'loggedin' )
				);
			}
		} finally {
			inFlight.current = false;
		}
	};

	const discard = () => {
		// core-data drops an edit that equals the stored value.
		const restore = Object.fromEntries(
			Object.keys( edits ).map( ( key ) => [ key, record[ key ] ] )
		);

		if ( Object.keys( restore ).length ) {
			editEntityRecord( 'root', 'site', undefined, restore );
		}

		setError( '' );
	};

	return {
		count,
		isDirty: count > 0,
		isSaving,
		hasLoaded,
		// "Saved" shows until the next change.
		justSaved: justSaved && 0 === count,
		error,
		save,
		discard,
	};
};

export default useSave;
