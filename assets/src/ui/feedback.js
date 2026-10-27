/**
 * Feedback and data components of the Foxe Plugin Admin Kit: StatusList,
 * StatTiles and Snackbars.
 */

/**
 * WordPress dependencies
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { Icon } from './icons';

/**
 * Read-only facts, each with a check or a dash beside its label.
 *
 * @param {Object}  props       Props.
 * @param {Array}   props.rows  [{ label, on, value }]; rows with a null `on` and value are left out.
 * @param {boolean} [props.end] Whether values sit at the end of the row (counts).
 * @return {Element} List.
 */
export function StatusList( { rows, end = false } ) {
	return (
		<ul className="loggedin-status-list">
			{ rows
				.filter( ( row ) => null !== row.value )
				.map( ( row ) => (
					<li className="loggedin-status-item" key={ row.label }>
						<span
							className={ `loggedin-status-item__icon loggedin-status-item__icon--${
								row.on ? 'yes' : 'no'
							}` }
						>
							<Icon
								name={ row.on ? 'check' : 'dash' }
								size={ 20 }
							/>
						</span>
						<span>{ row.label }</span>
						{ undefined !== row.value && (
							<span
								className={ `loggedin-status-item__value${
									end
										? ' loggedin-status-item__value--end'
										: ''
								}` }
							>
								{ row.value }
							</span>
						) }
					</li>
				) ) }
		</ul>
	);
}

/**
 * Two to four headline numbers.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.tiles [{ value, label }].
 * @return {Element} Description list.
 */
export function StatTiles( { tiles } ) {
	return (
		<dl className="loggedin-stats">
			{ tiles.map( ( tile ) => (
				<div className="loggedin-stat" key={ tile.label }>
					<dt className="loggedin-stat__label">{ tile.label }</dt>
					<dd className="loggedin-stat__value">{ tile.value }</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * The snackbars of the screen, from the core notices store.
 *
 * Create one with `createSuccessNotice( text, { type: 'snackbar' } )`.
 *
 * @return {Element} Snackbar list.
 */
export function Snackbars() {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => 'snackbar' === notice.type ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className="loggedin-snackbars"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}
