/**
 * Settings → Lockout & recovery: who the limit leaves alone, what a
 * blocked person sees and can do, and what a signed-out device is told.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Card, TextareaField, TextField, Toggle } from '../ui';
import useOption from '../hooks/use-option';

/**
 * Values shown while a setting has never been saved.
 *
 * @type {Object}
 */
const DEFAULTS = {
	logic: 'allow',
	unlock: true,
	exempt_admins: true,
	message: '',
	displaced_redirect: '',
};

/**
 * The Lockout & recovery page.
 *
 * @param {Object} props        Page props.
 * @param {Object} props.boot   Boot data.
 * @param {Object} props.errors Errors by `option.key`.
 * @return {Element} Page content.
 */
export function LockoutPage( { boot, errors } ) {
	const { get, set } = useOption( 'loggedin_settings', DEFAULTS );
	const blocks = 'block' === get( 'logic' );

	return (
		<>
			<Card
				title={ __( 'Administrators', 'loggedin' ) }
				description={ __(
					'Keep the people who run the site from being locked out of it.',
					'loggedin'
				) }
			>
				<Toggle
					label={ __( 'Don’t limit administrators', 'loggedin' ) }
					help={ __(
						'Users who can manage the site can sign in on any number of devices, and are never signed out by the limit.',
						'loggedin'
					) }
					checked={ get( 'exempt_admins' ) }
					onChange={ ( on ) => set( 'exempt_admins', on ) }
				/>
			</Card>
			<Card
				title={ __( 'Blocked logins', 'loggedin' ) }
				description={
					blocks
						? __(
								'What someone sees when Block New refuses their login, and how they can get back in.',
								'loggedin'
						  )
						: __(
								'Used with Block New. The login limit is set to sign out other devices instead, so nobody is blocked right now.',
								'loggedin'
						  )
				}
			>
				<TextareaField
					label={ __( 'Message', 'loggedin' ) }
					help={ __(
						'Leave empty to use the message shown. A link, bold and italics are allowed.',
						'loggedin'
					) }
					placeholder={ boot.defaultMessage }
					value={ get( 'message' ) }
					onChange={ ( value ) => set( 'message', value ) }
				/>
				<Toggle
					label={ __(
						'Let blocked users sign out their other devices',
						'loggedin'
					) }
					help={ __(
						'The error offers to email a link. Opening it signs the account out everywhere, so the person can log in. Only shown after the right password.',
						'loggedin'
					) }
					checked={ get( 'unlock' ) }
					onChange={ ( on ) => set( 'unlock', on ) }
				/>
			</Card>
			<Card
				title={ __( 'Signed out by a newer login', 'loggedin' ) }
				description={ __(
					'With Logout Oldest or Logout All, a new login signs out an older device. The login screen on that device says why.',
					'loggedin'
				) }
			>
				<TextField
					type="url"
					label={ __( 'Send them to this page instead', 'loggedin' ) }
					help={ __(
						'Optional. Where that device goes on its next page view, such as your membership account page. It must be on this site.',
						'loggedin'
					) }
					placeholder="https://"
					value={ get( 'displaced_redirect' ) }
					error={ errors[ 'loggedin_settings.displaced_redirect' ] }
					onChange={ ( value ) => set( 'displaced_redirect', value ) }
				/>
			</Card>
		</>
	);
}
