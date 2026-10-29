/**
 * Add-on components of the Foxe Plugin Admin Kit: AddonHint, AddonCard and
 * the caption row above the AddonCard grid.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Icon } from './icons';
import { ExternalLink } from './layout';

/**
 * One help line under a setting an add-on extends, linking to the Add-ons
 * page. The only mention of an add-on inside settings.
 *
 * @param {Object}     props          Props.
 * @param {string}     props.href     Address of the plugin's Add-ons page.
 * @param {string}     props.linkText Link text: the add-on's name.
 * @param {() => void} props.onOpen   Switches to the Add-ons page in place.
 * @param {Element}    props.children The question naming the need.
 * @return {Element} Paragraph.
 */
export function AddonHint( { href, linkText, onOpen, children } ) {
	return (
		<p className="loggedin-help loggedin-hint">
			{ children }{ ' ' }
			<a
				href={ href }
				onClick={ ( event ) => {
					if (
						0 !== event.button ||
						event.metaKey ||
						event.ctrlKey ||
						event.shiftKey ||
						event.altKey
					) {
						return;
					}

					event.preventDefault();
					onOpen();
				} }
			>
				{ linkText }
			</a>
		</p>
	);
}

/**
 * The caption row above a grid, with one action at the end.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.title    Caption.
 * @param {Element} [props.action] One tertiary button.
 * @return {Element} Row.
 */
export function SectionHead( { title, action } ) {
	return (
		<div className="loggedin-section-head">
			<h2>{ title }</h2>
			{ action }
		</div>
	);
}

/**
 * One add-on in the Add-ons page's grid.
 *
 * @param {Object}  props             Props.
 * @param {string}  props.title       Add-on name.
 * @param {string}  [props.href]      Its page on the plugin's site.
 * @param {string}  [props.image]     Its own icon image; a stroke icon is used without one.
 * @param {string}  props.description One sentence on what it adds.
 * @param {Object}  props.status      State: { tone: 'on'|'warning'|'off', text }.
 * @param {Element} [props.action]    The one action for that state.
 * @return {Element} Card.
 */
export function AddonCard( {
	title,
	href,
	image,
	description,
	status,
	action,
} ) {
	return (
		<section className="loggedin-addon">
			<div className="loggedin-addon__head">
				<span className="loggedin-addon__icon">
					{ image ? (
						<img src={ image } alt="" />
					) : (
						<Icon name="addons" size={ 20 } />
					) }
				</span>
				<h3 className="loggedin-addon__title">
					{ href ? (
						<ExternalLink href={ href }>{ title }</ExternalLink>
					) : (
						title
					) }
				</h3>
			</div>
			<p className="loggedin-addon__desc">{ description }</p>
			<div className="loggedin-addon__footer">
				<span className="loggedin-addon__status">
					<span
						className={ `loggedin-dot${
							'on' === status.tone
								? ''
								: ` loggedin-dot--${ status.tone }`
						}` }
						aria-hidden="true"
					/>
					{ status.text }
				</span>
				{ action }
			</div>
		</section>
	);
}

/**
 * A grey stand-in for an AddonCard while the list loads.
 *
 * Same frame and rows as the card, so the grid doesn't jump when the
 * real cards arrive. Hidden from screen readers: the page announces
 * the loading once.
 *
 * @return {Element} Placeholder card.
 */
export function AddonCardSkeleton() {
	return (
		<div
			className="loggedin-addon loggedin-addon--skeleton"
			aria-hidden="true"
		>
			<div className="loggedin-addon__head">
				<span className="loggedin-addon__icon loggedin-skeleton" />
				<span className="loggedin-skeleton loggedin-skeleton--title" />
			</div>
			<div className="loggedin-addon__desc">
				<span className="loggedin-skeleton loggedin-skeleton--line" />
				<span className="loggedin-skeleton loggedin-skeleton--line loggedin-skeleton--short" />
			</div>
			<div className="loggedin-addon__footer">
				<span className="loggedin-skeleton loggedin-skeleton--status" />
				<span className="loggedin-skeleton loggedin-skeleton--button" />
			</div>
		</div>
	);
}

/**
 * A button that opens a store page in a new tab, led by an icon naming
 * the action (cart, download) rather than the external-link icon.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.href     Address.
 * @param {string}  props.icon     Icon name.
 * @param {string}  props.variant  Button variant.
 * @param {Element} props.children Label.
 * @return {Element} Button.
 */
export function StoreButton( { href, icon, variant, children } ) {
	return (
		<Button
			variant={ variant }
			size="compact"
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
			icon={ <Icon name={ icon } size={ 16 } /> }
		>
			{ children }
			<span className="screen-reader-text">
				{ __( '(opens in a new tab)', 'loggedin' ) }
			</span>
		</Button>
	);
}
