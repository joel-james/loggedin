/**
 * One number per role: auto logout's timeouts per role, and the
 * `role-table` field add-ons declare in PHP (Limit Per Role).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Button, Icon, NumberField, SelectField } from '../ui';

/**
 * Rows of role and number, plus the Add button.
 *
 * Rows come straight from the stored `{ role: number }` map, so there
 * is no local state to fall out of step with Discard or Save.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.value         Stored map.
 * @param {Object}   props.roles         Every role: { slug: name }.
 * @param {number}   props.fallback      Number for a new row.
 * @param {Object}   props.errors        Errors by `option.key`.
 * @param {string}   props.option        Option holding the map, for error keys.
 * @param {string}   props.label         Number field label.
 * @param {string}   [props.help]        One line under the rows.
 * @param {string}   [props.unit]        Unit inside the number field.
 * @param {number}   [props.min]         Smallest number.
 * @param {number}   [props.max]         Largest number.
 * @param {string}   [props.addLabel]    Add button text.
 * @param {string}   [props.removeLabel] Remove button label; `%s` is the role.
 * @param {Function} props.onChange      Receives the new map.
 * @return {Element} Rows and the Add button.
 */
export function RoleTable( {
	value,
	roles,
	fallback,
	errors,
	option,
	label,
	help,
	unit,
	min = 1,
	max,
	addLabel = __( 'Add a role', 'loggedin' ),
	/* translators: %s: Role name. */
	removeLabel = __( 'Remove the setting for %s', 'loggedin' ),
	onChange,
} ) {
	const rows = Object.entries( value );
	const unused = Object.keys( roles ).filter(
		( slug ) => undefined === value[ slug ]
	);

	/**
	 * Rebuild the map in row order, swapping one row.
	 *
	 * @param {string}        role   Row's current role.
	 * @param {string}        next   Row's new role.
	 * @param {number|string} number Row's number.
	 * @return {void}
	 */
	const replace = ( role, next, number ) =>
		onChange(
			Object.fromEntries(
				rows.map( ( [ slug, current ] ) =>
					slug === role ? [ next, number ] : [ slug, current ]
				)
			)
		);

	const remove = ( role ) =>
		onChange(
			Object.fromEntries( rows.filter( ( [ slug ] ) => slug !== role ) )
		);

	return (
		<>
			{ rows.length > 0 && (
				<ul className="loggedin-role-rows">
					{ rows.map( ( [ role, number ], index ) => (
						<li key={ role } className="loggedin-role-row">
							<SelectField
								label={ __( 'Role', 'loggedin' ) }
								hideLabel={ index > 0 }
								value={ role }
								options={ [ role, ...unused ].map(
									( slug ) => ( {
										value: slug,
										label: roles[ slug ] || slug,
									} )
								) }
								onChange={ ( next ) =>
									replace( role, next, number )
								}
							/>
							<NumberField
								label={ label }
								hideLabel={ index > 0 }
								unit={ unit }
								min={ min }
								max={ max }
								value={ number }
								error={ errors[ `${ option }.${ role }` ] }
								onChange={ ( next ) =>
									replace( role, role, next )
								}
							/>
							<Button
								className="loggedin-role-row__remove"
								size="compact"
								icon={ <Icon name="close" /> }
								label={ sprintf(
									removeLabel,
									roles[ role ] || role
								) }
								onClick={ () => remove( role ) }
							/>
						</li>
					) ) }
				</ul>
			) }
			{ help && <p className="loggedin-help">{ help }</p> }
			{ unused.length > 0 && (
				<div>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () =>
							onChange( { ...value, [ unused[ 0 ] ]: fallback } )
						}
					>
						{ addLabel }
					</Button>
				</div>
			) }
		</>
	);
}
