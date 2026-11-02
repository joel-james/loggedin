/**
 * The screen's footer: version, maker and the links people ask for most.
 */

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ExternalLink, PageFooter } from '../ui';

/**
 * The footer under every page.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Data from PHP: `version` and `urls`.
 * @return {Element} Footer.
 */
export default function AppFooter( { boot } ) {
	return (
		<PageFooter
			meta={ createInterpolateElement(
				sprintf(
					/* translators: %s: Plugin version. <a> links to the maker's website. */
					__( 'Loggedin %s · by <a>Foxe Labs</a>', 'loggedin' ),
					boot.version
				),
				{ a: <ExternalLink href={ boot.urls.foxelabs } /> }
			) }
			label={ __( 'About Loggedin', 'loggedin' ) }
			links={ [
				{
					label: __( 'Documentation', 'loggedin' ),
					href: boot.urls.docs,
				},
				{
					label: __( 'Support forum', 'loggedin' ),
					href: boot.urls.support,
				},
				{
					// The stars are decorative; screen readers hear "five-star" instead.
					label: createInterpolateElement(
						__(
							/* translators: <stars> holds five star symbols, hidden from screen readers; <sr> is read to screen readers only. */
							'Leave a <stars>★★★★★</stars><sr>five-star</sr> review',
							'loggedin'
						),
						{
							stars: (
								<span
									className="loggedin-footer__stars"
									aria-hidden="true"
								/>
							),
							sr: <span className="screen-reader-text" />,
						}
					),
					href: boot.urls.review,
				},
			] }
		/>
	);
}
