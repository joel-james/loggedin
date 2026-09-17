/**
 * Force-logout panel.
 *
 * Renders on the Settings tab below the General Settings panel.
 * Accepts a single identifier — user id, email, or username — and
 * fires `POST /loggedin/v1/sessions/destroy`. The server resolves
 * the identifier and destroys every active session for that user
 * (same effect as the legacy `?loggedin_logout=1` admin link).
 *
 * Success and failure both surface through the global snackbar
 * stack so the UX matches the rest of the admin app. A successful
 * call also clears the input so the panel is ready for the next
 * lookup without further interaction.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	PanelBody,
	PanelRow,
	TextControl,
	// The dialog has shipped unchanged since WP 5.9 and is what core
	// itself uses for destructive confirmations; the underscore prefix
	// is the only "experimental" thing left about it.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Default cross-sell Notice promoting the Active Sessions addon.
 *
 * Lives at the bottom of the Force Logout panel and is routed through
 * the `loggedin.settings.force_logout.cross_sell` filter so the addon
 * — once loaded on this same screen — returns `null` to hide it.
 *
 * The CTA is an inline text link in the body copy rather than the
 * Notice `actions` button: the button forces the banner to a taller
 * two-row layout, so a plain link keeps the notice compact.
 *
 * The Notice is non-dismissible: a dismissible CTA would persist its
 * dismissed state in component memory only (no server round-trip), so
 * it'd come straight back on the next page load. Better to render a
 * stable banner the addon can swap out entirely.
 */
const DefaultCrossSell = () => (
	<PanelRow className="loggedin-cross-sell">
		<Notice status="info" isDismissible={ false }>
			<p className="loggedin-cross-sell__title">
				<strong>
					{ __(
						'Need to log someone out without knowing who?',
						'loggedin'
					) }
				</strong>
			</p>
			<p>
				{ __(
					'Install the Active Sessions addon to browse every user with a live session, drill into each device they’re signed in from, and sign them out one at a time — or all at once.',
					'loggedin'
				) }{ ' ' }
				<a
					href="https://foxelabs.com/software/plugins/loggedin/active-sessions"
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'Get Active Sessions', 'loggedin' ) }
				</a>
			</p>
		</Notice>
	</PanelRow>
);

const ForceLogoutPanel = () => {
	// Local input state. Plain `useState` — the panel doesn't need
	// to share its value with anything else in the React tree.
	const [ identifier, setIdentifier ] = useState( '' );

	// Gates double-clicks and drives the button's busy spinner.
	const [ isWorking, setIsWorking ] = useState( false );

	// "Logout all users" flow: confirmation dialog visibility and its
	// own busy flag, kept separate so the two buttons don't disable
	// each other.
	const [ isConfirmingAll, setIsConfirmingAll ] = useState( false );
	const [ isWorkingAll, setIsWorkingAll ] = useState( false );

	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	/*
	 * Cross-sell slot. Defaults to <DefaultCrossSell />; the Active
	 * Sessions addon returns `null` here to suppress the promo once
	 * installed. Kept as its own filter so future addons can replace
	 * the banner with a different recommendation without parent-side
	 * changes.
	 */
	const crossSell = applyFilters(
		'loggedin.settings.force_logout.cross_sell',
		<DefaultCrossSell />
	);

	/**
	 * Submit handler — POST the identifier and dispatch a snackbar
	 * reflecting the result.
	 */
	const handleSubmit = async () => {
		const value = identifier.trim();

		if ( ! value || isWorking ) {
			return;
		}

		setIsWorking( true );

		try {
			const result = await apiFetch( {
				path: '/loggedin/v1/sessions/destroy',
				method: 'POST',
				data: { user: value },
			} );

			createSuccessNotice(
				sprintf(
					// translators: %s user login of the destroyed user.
					__(
						'All sessions destroyed for "%s".',
						'loggedin'
					),
					result?.user?.login || value
				),
				{ type: 'snackbar' }
			);

			setIdentifier( '' );
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'Could not destroy sessions. Please try again.',
						'loggedin'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsWorking( false );
		}
	};

	/**
	 * Confirmed "logout everyone" handler — POST the destroy-all
	 * endpoint. The server keeps the current admin's session alive, so
	 * no client-side re-auth handling is needed here.
	 */
	const handleLogoutAll = async () => {
		setIsConfirmingAll( false );
		setIsWorkingAll( true );

		try {
			await apiFetch( {
				path: '/loggedin/v1/sessions/destroy-all',
				method: 'POST',
			} );

			createSuccessNotice(
				__(
					'All users have been logged out. Your own session stays active.',
					'loggedin'
				),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'Could not log out all users. Please try again.',
						'loggedin'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsWorkingAll( false );
		}
	};

	return (
		<PanelBody
			title={ __( 'Force Logout', 'loggedin' ) }
			initialOpen
		>
			<PanelRow>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'User', 'loggedin' ) }
					help={ __(
						'Enter a user id, email address, or username. All active sessions for that user will be destroyed.',
						'loggedin'
					) }
					value={ identifier }
					disabled={ isWorking }
					onChange={ setIdentifier }
				/>
			</PanelRow>

			<PanelRow>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isDestructive
					isBusy={ isWorking }
					disabled={ isWorking || ! identifier.trim() }
					onClick={ handleSubmit }
				>
					{ isWorking
						? __( 'Logging out…', 'loggedin' )
						: __( 'Force Logout', 'loggedin' ) }
				</Button>
			</PanelRow>

			<PanelRow>
				<div>
					<p className="description">
						{ __(
							'Or log out every user on the site at once. Sessions are invalidated instantly — even on sites with thousands of users — and your own session stays active.',
							'loggedin'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						isBusy={ isWorkingAll }
						disabled={ isWorkingAll }
						onClick={ () => setIsConfirmingAll( true ) }
					>
						{ isWorkingAll
							? __( 'Logging out everyone…', 'loggedin' )
							: __( 'Logout All Users', 'loggedin' ) }
					</Button>
				</div>
			</PanelRow>

			<ConfirmDialog
				isOpen={ isConfirmingAll }
				confirmButtonText={ __( 'Logout All Users', 'loggedin' ) }
				onConfirm={ handleLogoutAll }
				onCancel={ () => setIsConfirmingAll( false ) }
			>
				{ __(
					'Every user will be logged out from all of their devices. Your own session will stay active. Continue?',
					'loggedin'
				) }
			</ConfirmDialog>

			{ crossSell }
		</PanelBody>
	);
};

export default ForceLogoutPanel;
