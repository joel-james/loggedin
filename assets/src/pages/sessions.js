/**
 * Manage → Sessions: sign one user, or every user, out of every device.
 *
 * Both act at once through their own REST routes (no Save), so they stay
 * out of the settings edit buffer. Results show as snackbars.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { AddonHint, Button, Card, Modal, TextField } from '../ui';

const FORM_ID = 'loggedin-force-logout';

/**
 * The Sessions page.
 *
 * @param {Object}   props          Page props.
 * @param {Object}   props.boot     Boot data.
 * @param {Function} props.navigate Switches page in place.
 * @param {Function} props.hrefFor  Address of a page.
 * @return {Element} Page content.
 */
export function SessionsPage( { boot, navigate, hrefFor } ) {
	const [ identifier, setIdentifier ] = useState( '' );
	const [ working, setWorking ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ workingAll, setWorkingAll ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	/*
	 * One line pointing at Active Sessions, through a filter so an add-on
	 * can swap or remove it. Active Sessions 1.x returns null here once
	 * installed.
	 */
	const hint = applyFilters(
		'loggedin.settings.force_logout.cross_sell',
		boot.activeAddons.includes( 'loggedin-active-sessions' ) ? null : (
			<AddonHint
				href={ hrefFor( 'addons' ) }
				linkText={ __( 'Active Sessions', 'loggedin' ) }
				onOpen={ () => navigate( 'addons' ) }
			>
				{ __(
					'Want to see who is signed in, or end one device at a time?',
					'loggedin'
				) }
			</AddonHint>
		)
	);

	const signOut = async ( event ) => {
		event.preventDefault();

		const value = identifier.trim();

		if ( ! value || working ) {
			return;
		}

		setWorking( true );

		try {
			const result = await apiFetch( {
				path: '/loggedin/v1/sessions/destroy',
				method: 'POST',
				data: { user: value },
			} );

			createSuccessNotice(
				sprintf(
					/* translators: %s: Username of the signed-out user. */
					__( '%s was signed out of every device.', 'loggedin' ),
					result?.user?.login || value
				),
				{ type: 'snackbar' }
			);
			setIdentifier( '' );
		} catch ( failure ) {
			createErrorNotice(
				failure?.message ||
					__(
						'The user could not be signed out. Try again.',
						'loggedin'
					),
				{ type: 'snackbar' }
			);
		}

		setWorking( false );
	};

	const signOutAll = async () => {
		setConfirming( false );
		setWorkingAll( true );

		try {
			await apiFetch( {
				path: '/loggedin/v1/sessions/destroy-all',
				method: 'POST',
			} );

			createSuccessNotice(
				__(
					'Every user was signed out. You are still signed in.',
					'loggedin'
				),
				{ type: 'snackbar' }
			);
		} catch ( failure ) {
			createErrorNotice(
				failure?.message ||
					__(
						'The users could not be signed out. Try again.',
						'loggedin'
					),
				{ type: 'snackbar' }
			);
		}

		setWorkingAll( false );
	};

	return (
		<>
			<Card
				title={ __( 'Sign out a user', 'loggedin' ) }
				description={ __(
					'Ends every session the user has. They have to log in again on each device.',
					'loggedin'
				) }
				footer={
					<Button
						variant="secondary"
						size="compact"
						type="submit"
						form={ FORM_ID }
						isBusy={ working }
						aria-disabled={ working || ! identifier.trim() }
					>
						{ __( 'Sign out everywhere', 'loggedin' ) }
					</Button>
				}
			>
				<form id={ FORM_ID } onSubmit={ signOut }>
					<TextField
						label={ __( 'User', 'loggedin' ) }
						help={ __(
							'User ID, email address or username.',
							'loggedin'
						) }
						value={ identifier }
						onChange={ setIdentifier }
					/>
				</form>
				{ hint }
			</Card>
			<Card
				title={ __( 'Sign out all users', 'loggedin' ) }
				description={ __(
					'Ends every session on the site at once, however many users it has. You stay signed in.',
					'loggedin'
				) }
				action={
					<Button
						variant="secondary"
						size="compact"
						isDestructive
						isBusy={ workingAll }
						aria-disabled={ workingAll }
						onClick={ () => ! workingAll && setConfirming( true ) }
					>
						{ __( 'Sign out all users', 'loggedin' ) }
					</Button>
				}
			/>
			{ confirming && (
				<Modal
					className="loggedin-modal"
					title={ __( 'Sign out all users?', 'loggedin' ) }
					size="medium"
					onRequestClose={ () => setConfirming( false ) }
				>
					<div className="loggedin-modal__body">
						<p>
							{ __(
								'Every user is signed out of all of their devices. You stay signed in.',
								'loggedin'
							) }
						</p>
						<div className="loggedin-modal__actions">
							<Button
								variant="tertiary"
								onClick={ () => setConfirming( false ) }
							>
								{ __( 'Cancel', 'loggedin' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ signOutAll }
							>
								{ __( 'Sign out all users', 'loggedin' ) }
							</Button>
						</div>
					</div>
				</Modal>
			) }
		</>
	);
}
