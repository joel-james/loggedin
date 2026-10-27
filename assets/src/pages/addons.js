/**
 * More → Add-ons: every add-on, its state, and its license.
 *
 * Data flow: `useAddons` → the `loggedin/addons` store → REST. The first
 * render starts the store's `getItems` resolver, which fetches
 * `/loggedin/v1/addons`.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	AddonCard,
	Button,
	Icon,
	Modal,
	Notice,
	SectionHead,
	Spinner,
	StoreButton,
	TextField,
} from '../ui';
import useAddons from '../hooks/use-addons';

/**
 * What an add-on's footer says, and the one action it offers.
 *
 * @param {Object}   addon    Catalogue row from REST.
 * @param {Function} onManage Opens the license modal.
 * @return {{ status: Object, action: ?Element }} Footer parts.
 */
function footerFor( addon, onManage ) {
	if ( addon.is_active && ! addon.is_premium ) {
		return { status: { tone: 'on', text: __( 'Active', 'loggedin' ) } };
	}

	if ( addon.is_active && addon.is_license_active ) {
		return {
			status: { tone: 'on', text: __( 'Active · licensed', 'loggedin' ) },
			action: (
				<Button variant="tertiary" size="compact" onClick={ onManage }>
					{ __( 'Manage license', 'loggedin' ) }
				</Button>
			),
		};
	}

	if ( addon.is_active ) {
		return {
			status: {
				tone: 'warning',
				text: __( 'Installed · no license', 'loggedin' ),
			},
			action: (
				<Button variant="primary" size="compact" onClick={ onManage }>
					{ __( 'Activate license', 'loggedin' ) }
				</Button>
			),
		};
	}

	const href = addon.link || addon.homepage;

	return {
		status: {
			tone: 'off',
			text: addon.is_premium
				? __( 'Premium · not installed', 'loggedin' )
				: __( 'Free · not installed', 'loggedin' ),
		},
		action: href && (
			<StoreButton href={ href } icon="cart" variant="secondary">
				{ __( 'Get add-on', 'loggedin' ) }
			</StoreButton>
		),
	};
}

/**
 * The license key modal for one add-on.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.addon        Catalogue row.
 * @param {Function} props.onActivate   `(id, key) => Promise<{ success, error? }>`.
 * @param {Function} props.onDeactivate `(id) => Promise<{ success, error? }>`.
 * @param {Function} props.onClose      Closes the modal.
 * @return {Element} Modal.
 */
function LicenseModal( { addon, onActivate, onDeactivate, onClose } ) {
	const [ key, setKey ] = useState( addon.license_key || '' );
	const [ working, setWorking ] = useState( false );
	const [ error, setError ] = useState( '' );
	const active = addon.is_license_active;

	// Follow the stored key when the row changes, e.g. after activation.
	useEffect( () => {
		setKey( addon.license_key || '' );
	}, [ addon.license_key ] );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( working ) {
			return;
		}

		if ( ! active && ! key.trim() ) {
			setError(
				__(
					'Enter the license key from your purchase email.',
					'loggedin'
				)
			);
			return;
		}

		setWorking( true );
		setError( '' );

		const result = active
			? await onDeactivate( addon.id )
			: await onActivate( addon.id, key.trim() );

		setWorking( false );

		if ( result?.success ) {
			onClose();
			return;
		}

		setError(
			result?.error ||
				__( 'Something went wrong. Try again.', 'loggedin' )
		);
	};

	return (
		<Modal
			className="loggedin-modal"
			title={ sprintf(
				/* translators: %s: Add-on name. */
				__( '%s license', 'loggedin' ),
				addon.title || ''
			) }
			size="medium"
			onRequestClose={ onClose }
		>
			<form className="loggedin-modal__body" onSubmit={ submit }>
				{ error && (
					<Notice
						tone="error"
						title={ __(
							'The license was not changed',
							'loggedin'
						) }
					>
						{ error }
					</Notice>
				) }
				{ active ? (
					<p>
						{ __(
							'This site is licensed. Deactivate the license to move it to another site.',
							'loggedin'
						) }
					</p>
				) : (
					<TextField
						label={ __( 'License key', 'loggedin' ) }
						value={ key }
						onChange={ setKey }
					/>
				) }
				<div className="loggedin-modal__actions">
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ working }
					>
						{ __( 'Cancel', 'loggedin' ) }
					</Button>
					<Button
						type="submit"
						variant={ active ? 'secondary' : 'primary' }
						isDestructive={ active }
						isBusy={ working }
						aria-disabled={ working }
					>
						{ active
							? __( 'Deactivate license', 'loggedin' )
							: __( 'Activate license', 'loggedin' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * The Add-ons page.
 *
 * @return {Element} Page content.
 */
export function AddonsPage() {
	const {
		items,
		isLoading,
		isRefreshing,
		refresh,
		activateLicense,
		deactivateLicense,
	} = useAddons();

	// The row is looked up again on each render, so the modal follows
	// a license change without being reopened.
	const [ managing, setManaging ] = useState( null );
	const addon = items.find(
		( item ) => Number( item.id ) === Number( managing )
	);

	return (
		<>
			<SectionHead
				title={ __( 'Add-ons', 'loggedin' ) }
				action={
					<Button
						variant="tertiary"
						size="compact"
						icon={ <Icon name="refresh" size={ 16 } /> }
						isBusy={ isRefreshing }
						aria-disabled={ isRefreshing }
						onClick={ () => ! isRefreshing && refresh() }
					>
						{ __( 'Refresh list', 'loggedin' ) }
					</Button>
				}
			/>
			{ isLoading && (
				<div className="loggedin-loading">
					<Spinner />
				</div>
			) }
			{ ! isLoading && ! items.length && (
				<Notice
					tone="info"
					title={ __( 'No add-ons to show', 'loggedin' ) }
				>
					{ __(
						'The list could not be loaded. Refresh it, or try again later.',
						'loggedin'
					) }
				</Notice>
			) }
			{ ! isLoading && items.length > 0 && (
				<div className="loggedin-addons">
					{ items.map( ( item ) => {
						const { status, action } = footerFor( item, () =>
							setManaging( item.id )
						);

						return (
							<AddonCard
								key={ item.id }
								title={ item.title }
								href={ item.homepage || item.link }
								image={ item.icon }
								description={ item.description }
								status={ status }
								action={ action }
							/>
						);
					} ) }
				</div>
			) }
			{ addon && (
				<LicenseModal
					addon={ addon }
					onActivate={ activateLicense }
					onDeactivate={ deactivateLicense }
					onClose={ () => setManaging( null ) }
				/>
			) }
		</>
	);
}
