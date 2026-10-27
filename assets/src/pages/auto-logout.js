/**
 * Settings → Auto logout: sign out sessions that go unused, and decide
 * how long any login lasts.
 *
 * Both are enforced on the server (`includes/auto-logout/`); this page
 * only edits the two options they read.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Button, Card, Icon, NumberField, SelectField, Toggle } from '../ui';
import useOption from '../hooks/use-option';

const OPTION = 'loggedin_auto_logout_settings';
const ROLE_OPTION = 'loggedin_auto_logout_role_timeouts';

const DEFAULTS = {
	idle_timeout: 0,
	warning: 60,
	session_lifetime: 0,
	remember_lifetime: 0,
};

// Offered when idle logout is first turned on.
const STARTER_TIMEOUT = 15;

/**
 * Timeouts for single roles: one row per role, with its own minutes.
 *
 * Rows come straight from the stored `{ role: minutes }` map, so there
 * is no local state to fall out of step with Discard or Save.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    Stored map.
 * @param {Object}   props.roles    Every role: { slug: name }.
 * @param {number}   props.fallback Minutes for a new row.
 * @param {Object}   props.errors   Errors by `option.key`.
 * @param {Function} props.onChange Receives the new map.
 * @return {Element} Rows and the Add button.
 */
function RoleTimeouts( { value, roles, fallback, errors, onChange } ) {
	const rows = Object.entries( value );
	const unused = Object.keys( roles ).filter( ( slug ) => ! value[ slug ] );

	/**
	 * Rebuild the map in row order, swapping one row.
	 *
	 * @param {string}        role    Row's current role.
	 * @param {string}        next    Row's new role.
	 * @param {number|string} minutes Row's minutes.
	 * @return {void}
	 */
	const replace = ( role, next, minutes ) =>
		onChange(
			Object.fromEntries(
				rows.map( ( [ slug, current ] ) =>
					slug === role ? [ next, minutes ] : [ slug, current ]
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
					{ rows.map( ( [ role, minutes ] ) => (
						<li key={ role } className="loggedin-role-row">
							<SelectField
								label={ __( 'Role', 'loggedin' ) }
								value={ role }
								options={ [ role, ...unused ].map(
									( slug ) => ( {
										value: slug,
										label: roles[ slug ] || slug,
									} )
								) }
								onChange={ ( next ) =>
									replace( role, next, minutes )
								}
							/>
							<NumberField
								label={ __( 'Sign out after', 'loggedin' ) }
								unit={ __( 'minutes', 'loggedin' ) }
								min={ 1 }
								value={ minutes }
								error={ errors[ `${ ROLE_OPTION }.${ role }` ] }
								onChange={ ( next ) =>
									replace( role, role, next )
								}
							/>
							<Button
								className="loggedin-role-row__remove"
								size="compact"
								icon={ <Icon name="close" /> }
								label={ sprintf(
									/* translators: %s: Role name. */
									__(
										'Remove the timeout for %s',
										'loggedin'
									),
									roles[ role ] || role
								) }
								onClick={ () => remove( role ) }
							/>
						</li>
					) ) }
				</ul>
			) }
			{ unused.length > 0 && (
				<div>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () =>
							onChange( { ...value, [ unused[ 0 ] ]: fallback } )
						}
					>
						{ __( 'Add a role', 'loggedin' ) }
					</Button>
				</div>
			) }
		</>
	);
}

/**
 * Sign out the other devices when a password changes.
 *
 * Kept in `loggedin_settings` with the core settings, not with the
 * two auto logout options.
 *
 * @return {Element} Card.
 */
function PasswordChangeCard() {
	const { get, set } = useOption( 'loggedin_settings', {
		password_signout: true,
	} );

	return (
		<Card
			title={ __( 'Password changes', 'loggedin' ) }
			description={ __(
				'What happens to a user’s other sessions when their password changes or is reset.',
				'loggedin'
			) }
		>
			<Toggle
				label={ __( 'Sign out the other devices', 'loggedin' ) }
				help={ __(
					'WordPress already refuses the old logins, but their sessions stay on record and count towards the login limit until they expire. Someone changing their own password stays signed in where they did it.',
					'loggedin'
				) }
				checked={ get( 'password_signout' ) }
				onChange={ ( on ) => set( 'password_signout', on ) }
			/>
		</Card>
	);
}

/**
 * The Auto logout page.
 *
 * @param {Object} props        Page props.
 * @param {Object} props.boot   Boot data.
 * @param {Object} props.errors Errors by `option.key`.
 * @return {Element} Page content.
 */
export function AutoLogoutPage( { boot, errors } ) {
	const { get, set } = useOption( OPTION, DEFAULTS );
	const roleTimeouts = useOption( ROLE_OPTION );
	const idle = get( 'idle_timeout' );

	// Zero is off; anything else, an empty field included, is on.
	const idleOn = 0 !== idle;

	return (
		<>
			<Card
				title={ __( 'Idle logout', 'loggedin' ) }
				description={ __(
					'Signs out a session nobody has used for a while. Checked on the server, so leaving the tab open doesn’t keep it alive.',
					'loggedin'
				) }
			>
				<Toggle
					label={ __( 'Sign out idle sessions', 'loggedin' ) }
					help={ __(
						'Only the device that went idle is signed out. Other devices keep their own sessions.',
						'loggedin'
					) }
					checked={ idleOn }
					onChange={ ( on ) =>
						set( 'idle_timeout', on ? STARTER_TIMEOUT : 0 )
					}
				/>
				{ idleOn && (
					<>
						<NumberField
							label={ __( 'Sign out after', 'loggedin' ) }
							unit={ __( 'minutes', 'loggedin' ) }
							help={ __(
								'How long a session can go unused.',
								'loggedin'
							) }
							min={ 1 }
							max={ boot.autoLogout.max.idle_timeout }
							value={ idle }
							error={ errors[ `${ OPTION }.idle_timeout` ] }
							onChange={ ( value ) =>
								set( 'idle_timeout', value )
							}
						/>
						<NumberField
							label={ __( 'Warn first', 'loggedin' ) }
							unit={ __( 'seconds', 'loggedin' ) }
							help={ __(
								'A countdown lets someone in the middle of a form stay signed in. 0 signs out without warning. Never more than half the time above.',
								'loggedin'
							) }
							min={ 0 }
							max={ boot.autoLogout.max.warning }
							value={ get( 'warning' ) }
							error={ errors[ `${ OPTION }.warning` ] }
							onChange={ ( value ) => set( 'warning', value ) }
						/>
					</>
				) }
			</Card>
			{ idleOn && (
				<Card
					title={ __( 'Timeouts per role', 'loggedin' ) }
					description={ __(
						'Roles without their own timeout use the one above. When a user has several roles with one, the shortest applies.',
						'loggedin'
					) }
				>
					<RoleTimeouts
						value={ roleTimeouts.values }
						roles={ boot.roles }
						fallback={
							Number.isInteger( idle ) ? idle : STARTER_TIMEOUT
						}
						errors={ errors }
						onChange={ ( map ) =>
							// The whole map is the option, so it replaces the stored one.
							roleTimeouts.replace( map )
						}
					/>
				</Card>
			) }
			<PasswordChangeCard />
			<Card
				title={ __( 'Session length', 'loggedin' ) }
				description={ __(
					'How long a login lasts, however busy it is. Sessions that already exist keep their old length until they end.',
					'loggedin'
				) }
			>
				<NumberField
					label={ __( 'Login lasts', 'loggedin' ) }
					unit={ __( 'hours', 'loggedin' ) }
					help={ __(
						'0 keeps the WordPress default of 48 hours.',
						'loggedin'
					) }
					min={ 0 }
					max={ boot.autoLogout.max.session_lifetime }
					value={ get( 'session_lifetime' ) }
					error={ errors[ `${ OPTION }.session_lifetime` ] }
					onChange={ ( value ) => set( 'session_lifetime', value ) }
				/>
				<NumberField
					label={ __( 'With “Remember Me”', 'loggedin' ) }
					unit={ __( 'days', 'loggedin' ) }
					help={ __(
						'0 keeps the WordPress default of 14 days.',
						'loggedin'
					) }
					min={ 0 }
					max={ boot.autoLogout.max.remember_lifetime }
					value={ get( 'remember_lifetime' ) }
					error={ errors[ `${ OPTION }.remember_lifetime` ] }
					onChange={ ( value ) => set( 'remember_lifetime', value ) }
				/>
			</Card>
		</>
	);
}
