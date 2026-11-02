/**
 * The page header's save status and Save / Discard buttons, and the
 * notice shown when a save fails.
 */

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Button, Notice } from '../ui';
import useSave from '../hooks/use-save';

/**
 * The save controls for the current page.
 *
 * While anything is unsaved, leaving the screen asks first.
 *
 * @param {Object}  args            Arguments.
 * @param {number}  args.errorCount Settings with an error; Save waits for none.
 * @param {boolean} args.saves      Whether the page holds settings, so shows Save even with nothing changed.
 * @return {{ hasLoaded: boolean, status: (Object|undefined), actions: (Element|false), notice: (Element|false) }}
 *     Whether the settings have loaded, the header's status and buttons, and the save-failed notice.
 */
export default function useSaveBar( { errorCount, saves } ) {
	const saveState = useSave();

	useEffect( () => {
		if ( ! saveState.isDirty ) {
			return undefined;
		}

		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ saveState.isDirty ] );

	const canSave =
		saveState.hasLoaded &&
		! saveState.isSaving &&
		saveState.isDirty &&
		0 === errorCount;

	const save = () => {
		// Save stays focusable while it can't act (aria-disabled), so guard the click here.
		if ( canSave ) {
			saveState.save();
		}
	};

	let status;

	if ( errorCount ) {
		status = {
			tone: 'error',
			text: sprintf(
				/* translators: %d: Number of settings with an error. */
				_n(
					'%d setting needs fixing',
					'%d settings need fixing',
					errorCount,
					'loggedin'
				),
				errorCount
			),
		};
	} else if ( saveState.isDirty ) {
		status = {
			tone: 'warning',
			text: sprintf(
				/* translators: %d: Number of changed settings. */
				_n(
					'%d unsaved change',
					'%d unsaved changes',
					saveState.count,
					'loggedin'
				),
				saveState.count
			),
		};
	}

	// Save shows on settings pages, and on any page while there is something to save.
	const actions = ( saves || saveState.isDirty ) && (
		<>
			{ saveState.isDirty && (
				<Button
					variant="tertiary"
					size="compact"
					onClick={ saveState.discard }
					disabled={ saveState.isSaving }
				>
					{ __( 'Discard', 'loggedin' ) }
				</Button>
			) }
			<Button
				variant="primary"
				size="compact"
				onClick={ save }
				isBusy={ saveState.isSaving }
				aria-disabled={ ! canSave }
			>
				{ saveState.justSaved
					? __( 'Saved', 'loggedin' )
					: __( 'Save', 'loggedin' ) }
			</Button>
		</>
	);

	const notice = !! saveState.error && (
		<Notice
			tone="error"
			title={ __( 'The settings could not be saved', 'loggedin' ) }
			actions={
				<Button
					variant="primary"
					size="compact"
					onClick={ save }
					aria-disabled={ ! canSave }
				>
					{ __( 'Try again', 'loggedin' ) }
				</Button>
			}
		>
			{ sprintf(
				/* translators: %s: Error message from the server. */
				__(
					'%s Your changes are still here, so nothing is lost.',
					'loggedin'
				),
				saveState.error
			) }
		</Notice>
	);

	return { hasLoaded: saveState.hasLoaded, status, actions, notice };
}
