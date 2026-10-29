/**
 * Check the edited settings before they can be saved.
 *
 * Errors are keyed `option.key` (`loggedin_settings.maximum`), so a page
 * can show each one under its field and the sidebar can flag the page
 * that holds it. While any error stands, Save stays disabled.
 */
import { __, sprintf } from '@wordpress/i18n';
import { errorKey } from './schema';

/**
 * The error for a whole number outside its range, or ''.
 *
 * @param {*}      value Edited value.
 * @param {number} [min] Smallest allowed.
 * @param {number} [max] Largest allowed.
 * @return {string} Error text.
 */
export function numberError( value, min, max ) {
	const tooLow = undefined !== min && value < min;
	const tooHigh = undefined !== max && value > max;

	if ( Number.isInteger( value ) && ! tooLow && ! tooHigh ) {
		return '';
	}

	if ( undefined !== min && undefined !== max ) {
		return sprintf(
			/* translators: 1: Smallest number allowed, 2: Largest number allowed. */
			__( 'Enter a whole number from %1$d to %2$d.', 'loggedin' ),
			min,
			max
		);
	}

	if ( undefined !== min ) {
		return sprintf(
			/* translators: %d: Smallest number allowed. */
			__( 'Enter a whole number, %d or more.', 'loggedin' ),
			min
		);
	}

	return __( 'Enter a whole number.', 'loggedin' );
}

/**
 * Whether two addresses are on the same host.
 *
 * @param {string} url  Address to check.
 * @param {string} home The site's address.
 * @return {boolean} Same host.
 */
const sameHost = ( url, home ) => {
	try {
		return new URL( url ).host === new URL( home ).host;
	} catch ( error ) {
		return false;
	}
};

/**
 * Every error in the edited site settings.
 *
 * @param {Object} site   Edited `root/site` record.
 * @param {Array}  fields Fields declared by add-ons in PHP.
 * @param {Object} limits Largest auto logout values, by setting key.
 * @param {string} [home] The site's address, for same-site checks.
 * @return {Object<string, string>} Error text by `option.key`.
 */
export function validate( site, fields, limits, home ) {
	const errors = {};
	const settings = site?.loggedin_settings;

	if ( settings ) {
		const message = numberError( settings.maximum, 1 );

		if ( message ) {
			errors[ 'loggedin_settings.maximum' ] = message;
		}

		const redirect = ( settings.displaced_redirect || '' ).trim();

		if ( redirect && ! /^https?:\/\/[^\s/]+/i.test( redirect ) ) {
			errors[ 'loggedin_settings.displaced_redirect' ] = __(
				'Enter a full address, starting with https:// or http://.',
				'loggedin'
			);
		} else if ( redirect && home && ! sameHost( redirect, home ) ) {
			errors[ 'loggedin_settings.displaced_redirect' ] = __(
				'Use a page on this site.',
				'loggedin'
			);
		}
	}

	const autoLogout = site?.loggedin_auto_logout_settings;

	if ( autoLogout ) {
		const bounds = {
			// Zero turns idle logout off, which the page does with a toggle.
			idle_timeout: [
				0 === autoLogout.idle_timeout ? 0 : 1,
				limits.idle_timeout,
			],
			warning: [ 0, limits.warning ],
			session_lifetime: [ 0, limits.session_lifetime ],
			remember_lifetime: [ 0, limits.remember_lifetime ],
		};

		Object.entries( bounds ).forEach( ( [ key, [ min, max ] ] ) => {
			const message =
				undefined === autoLogout[ key ]
					? ''
					: numberError( autoLogout[ key ], min, max );

			if ( message ) {
				errors[ `loggedin_auto_logout_settings.${ key }` ] = message;
			}
		} );
	}

	const roleTimeouts = site?.loggedin_auto_logout_role_timeouts;

	if ( roleTimeouts && ! Array.isArray( roleTimeouts ) ) {
		Object.entries( roleTimeouts ).forEach( ( [ role, minutes ] ) => {
			const message = numberError( minutes, 1, limits.idle_timeout );

			if ( message ) {
				errors[ `loggedin_auto_logout_role_timeouts.${ role }` ] =
					message;
			}
		} );
	}

	fields
		.filter( ( field ) => 'number' === field.type )
		.forEach( ( field ) => {
			const option = field.option || 'loggedin_settings';
			const value =
				undefined === field.key
					? site?.[ option ]
					: site?.[ option ]?.[ field.key ];

			// A value never set yet uses the field's default.
			if ( undefined === value ) {
				return;
			}

			const message = numberError( value, field.min, field.max );

			if ( message ) {
				errors[ errorKey( field ) ] = message;
			}
		} );

	fields
		.filter( ( field ) => 'role-table' === field.type )
		.forEach( ( field ) => {
			const option = field.option || 'loggedin_settings';
			const map = site?.[ option ];

			if ( ! map || 'object' !== typeof map || Array.isArray( map ) ) {
				return;
			}

			Object.entries( map ).forEach( ( [ role, value ] ) => {
				const message = numberError( value, field.min ?? 1, field.max );

				if ( message ) {
					errors[ `${ option }.${ role }` ] = message;
				}
			} );
		} );

	return errors;
}
