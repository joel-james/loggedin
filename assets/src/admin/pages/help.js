/**
 * More → Help: documentation, where to get help, and the versions to quote.
 */

/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { Button, Card, Icon } from '../ui';

/**
 * The Help page.
 *
 * @param {Object} props      Page props.
 * @param {Object} props.boot Boot data.
 * @return {Element} Page content.
 */
export function HelpPage( { boot } ) {
	const { createSuccessNotice } = useDispatch( noticesStore );
	const versions = [
		{ label: __( 'Loggedin', 'loggedin' ), value: boot.version },
		{ label: __( 'WordPress', 'loggedin' ), value: boot.wpVersion },
		{ label: __( 'PHP', 'loggedin' ), value: boot.phpVersion },
	];

	/**
	 * Copy the versions as plain lines, ready to paste into a forum post.
	 *
	 * @return {Promise<void>}
	 */
	const copy = async () => {
		const text = versions
			.map( ( item ) => `${ item.label }: ${ item.value }` )
			.join( '\n' );

		try {
			await window.navigator.clipboard.writeText( text );
			createSuccessNotice( __( 'Versions copied.', 'loggedin' ), {
				type: 'snackbar',
			} );
		} catch {
			createSuccessNotice(
				__(
					'Your browser blocked copying. Select the versions and copy them instead.',
					'loggedin'
				),
				{ type: 'snackbar' }
			);
		}
	};

	const external = (
		<>
			<Icon name="external" size={ 16 } />
			<span className="screen-reader-text">
				{ __( '(opens in a new tab)', 'loggedin' ) }
			</span>
		</>
	);

	return (
		<>
			<Card
				title={ __( 'Documentation', 'loggedin' ) }
				icon="book"
				description={ __(
					'Setting up, every setting, the WP-CLI commands and the hooks reference.',
					'loggedin'
				) }
				action={
					<Button
						variant="primary"
						size="compact"
						href={ boot.urls.docs }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Read the docs', 'loggedin' ) }
						{ external }
					</Button>
				}
			/>
			<Card
				title={ __( 'Get help', 'loggedin' ) }
				icon="support"
				description={ __(
					'Ask in the support forum on WordPress.org. Paste the versions below into your post.',
					'loggedin'
				) }
				action={
					<Button
						variant="secondary"
						size="compact"
						href={ boot.urls.support }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Support forum', 'loggedin' ) }
						{ external }
					</Button>
				}
			/>
			<Card
				title={ __( 'Priority support', 'loggedin' ) }
				icon="user"
				description={ __(
					'For add-on customers. Email us directly.',
					'loggedin'
				) }
				action={
					<Button
						variant="secondary"
						size="compact"
						href={ boot.urls.contact }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Contact us', 'loggedin' ) }
						{ external }
					</Button>
				}
			/>
			<Card
				title={ __( 'Versions', 'loggedin' ) }
				icon="versions"
				description={ __( 'What’s running on this site.', 'loggedin' ) }
				action={
					<Button
						variant="secondary"
						size="compact"
						icon={ <Icon name="copy" size={ 16 } /> }
						onClick={ copy }
					>
						{ __( 'Copy versions', 'loggedin' ) }
					</Button>
				}
			>
				<dl className="loggedin-versions">
					{ versions.map( ( item ) => (
						<div key={ item.label }>
							<dt>{ item.label }</dt>
							<dd>{ item.value }</dd>
						</div>
					) ) }
				</dl>
			</Card>
		</>
	);
}
