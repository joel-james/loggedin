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
import { Button, Card, Icon, OpensInNewTab } from '../ui';

/**
 * A compact button that opens an outside page in a new tab, with the
 * external-link icon after its label and the new-tab note for screen
 * readers.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.variant  Button variant.
 * @param {string}  props.href     Address.
 * @param {Element} props.children Label.
 * @return {Element} Button.
 */
function ExternalButton( { variant, href, children } ) {
	return (
		<Button
			variant={ variant }
			size="compact"
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children }
			<Icon name="external" size={ 16 } />
			<OpensInNewTab />
		</Button>
	);
}

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
					<ExternalButton variant="primary" href={ boot.urls.docs }>
						{ __( 'Read the docs', 'loggedin' ) }
					</ExternalButton>
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
					<ExternalButton
						variant="secondary"
						href={ boot.urls.support }
					>
						{ __( 'Support forum', 'loggedin' ) }
					</ExternalButton>
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
					<ExternalButton
						variant="secondary"
						href={ boot.urls.contact }
					>
						{ __( 'Contact us', 'loggedin' ) }
					</ExternalButton>
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
