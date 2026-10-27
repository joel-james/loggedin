/**
 * Check the edited settings before they can be saved.
 *
 * Errors are keyed `option.key` (`loggedin_settings.maximum`), so a page
 * can show each one under its field and the sidebar can flag the page
 * that holds it. While any error stands, Save stays disabled.
 */
import { __, sprintf } from '@wordpress/i18n';

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
 * Every error in the edited site settings.
 *
 * @param {Object} site   Edited `root/site` record.
 * @param {Array}  fields Number fields declared by add-ons in PHP.
 * @param {Object} limits Largest auto logout values, by setting key.
 * @return {Object<string, string>} Error text by `option.key`.
 */
export function validate( site, fields, limits ) {
	const errors = {};
	const settings = site?.loggedin_settings;

	if ( settings ) {
		const message = numberError( settings.maximum, 1 );

		if ( message ) {
			errors[ 'loggedin_settings.maximum' ] = message;
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
			const value = site?.[ option ]?.[ field.key ];

			// A value never set yet uses the field's default.
			if ( undefined === value ) {
				return;
			}

			const message = numberError( value, field.min, field.max );

			if ( message ) {
				errors[ `${ option }.${ field.key }` ] = message;
			}
		} );

	return errors;
}
