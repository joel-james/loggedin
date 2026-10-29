/**
 * Settings → Login limits: how many sessions one account may have, and
 * what happens when a new login would go over that.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { AddonHint, Card, ChoiceTiles, NumberField } from '../ui';
import useOption from '../hooks/use-option';

const DEFAULTS = {
	maximum: 1,
	logic: 'allow',
};

/**
 * The Login limits page.
 *
 * @param {Object}   props          Page props.
 * @param {Object}   props.boot     Boot data.
 * @param {Object}   props.errors   Errors by `option.key`.
 * @param {Function} props.navigate Switches page in place.
 * @param {Function} props.hrefFor  Address of a page.
 * @return {Element} Page content.
 */
export function LoginLimitsPage( { boot, errors, navigate, hrefFor } ) {
	const { get, set } = useOption( 'loggedin_settings', DEFAULTS );
	const maximum = get( 'maximum' );

	// The logics come from PHP (`loggedin_logics`), so add-ons can add one.
	const logics = Object.entries( boot.logics || {} ).map(
		( [ value, meta ] ) => ( {
			value,
			label: meta.label,
			description: meta.desc,
		} )
	);

	return (
		<>
			<Card
				title={ __( 'Login limit', 'loggedin' ) }
				description={ __(
					'How many devices one account can be signed in on at the same time.',
					'loggedin'
				) }
			>
				<NumberField
					label={ __( 'Maximum active sessions', 'loggedin' ) }
					unit={
						1 === maximum
							? __( 'session', 'loggedin' )
							: __( 'sessions', 'loggedin' )
					}
					help={ __(
						'Applies to every user. Each browser or device counts as one session.',
						'loggedin'
					) }
					min={ 1 }
					value={ maximum }
					error={ errors[ 'loggedin_settings.maximum' ] }
					onChange={ ( value ) => set( 'maximum', value ) }
				/>
				{ ! boot.activeAddons.includes( 'loggedin-limit-per-role' ) && (
					<AddonHint
						href={ hrefFor( 'addons' ) }
						linkText={ __( 'Limit Per Role', 'loggedin' ) }
						onOpen={ () => navigate( 'addons' ) }
					>
						{ __(
							'Need a different limit for some roles?',
							'loggedin'
						) }
					</AddonHint>
				) }
				{ ! boot.activeAddons.includes( 'loggedin-limit-per-user' ) && (
					<AddonHint
						href={ hrefFor( 'addons' ) }
						linkText={ __( 'Limit Per User', 'loggedin' ) }
						onOpen={ () => navigate( 'addons' ) }
					>
						{ __(
							'Need a different limit for one account?',
							'loggedin'
						) }
					</AddonHint>
				) }
			</Card>
			<Card
				title={ __( 'When the limit is reached', 'loggedin' ) }
				description={ __(
					'What happens when someone signs in on one device more than the limit allows.',
					'loggedin'
				) }
			>
				<ChoiceTiles
					label={ __( 'New login', 'loggedin' ) }
					value={ get( 'logic' ) }
					options={ logics }
					onChange={ ( value ) => set( 'logic', value ) }
				/>
			</Card>
		</>
	);
}
