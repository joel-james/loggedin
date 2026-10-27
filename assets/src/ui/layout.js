/**
 * Layout components of the Foxe Plugin Admin Kit: Sidebar, SectionSelect,
 * PageHeader, PageFooter, ExternalLink, Card and Notice.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Icon, Logo } from './icons';

/**
 * The plugin's navigation: brand and status, then grouped links to pages.
 *
 * Below 782px only the brand row shows; SectionSelect in the page header
 * takes over the links (CSS switches which one shows).
 *
 * @param {Object}                 props            Props.
 * @param {string}                 props.name       Plugin name.
 * @param {Object}                 props.status     Status line: { tone: 'on'|'off', text }.
 * @param {Array}                  props.groups     Groups: [{ id, label, pages: [{ id, title, icon, flag, badge }] }].
 * @param {string}                 props.current    Current page ID.
 * @param {(id: string) => string} props.hrefFor    Address of a page, so links can open in a new tab.
 * @param {(id: string) => void}   props.onNavigate Called with a page ID when a link is clicked.
 * @param {string}                 props.label      Accessible name of the navigation.
 * @return {Element} Sidebar.
 */
export function Sidebar( {
	name,
	status,
	groups,
	current,
	hrefFor,
	onNavigate,
	label,
} ) {
	/**
	 * Switch pages in place on a plain click; let modified clicks open a new tab.
	 *
	 * @param {MouseEvent} event Click event.
	 * @param {string}     id    Page ID.
	 * @return {void}
	 */
	const onClick = ( event, id ) => {
		if (
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		event.preventDefault();
		onNavigate( id );
	};

	return (
		<aside className="loggedin-sidebar">
			<div className="loggedin-brand">
				<Logo />
				<div>
					<div className="loggedin-brand__name">{ name }</div>
					<div className="loggedin-brand__meta">
						<span
							className={ `loggedin-dot${
								'off' === status.tone
									? ' loggedin-dot--off'
									: ''
							}` }
							aria-hidden="true"
						/>
						{ status.text }
					</div>
				</div>
			</div>
			<nav className="loggedin-nav" aria-label={ label }>
				{ groups.map( ( group ) => (
					<div key={ group.id }>
						{ /* Not a heading: the page's h1 comes after the sidebar, and WordPress's own menu has none. */ }
						<div
							className="loggedin-nav-group"
							id={ `loggedin-nav-${ group.id }` }
						>
							{ group.label }
						</div>
						<ul
							className="loggedin-nav-list"
							aria-labelledby={ `loggedin-nav-${ group.id }` }
						>
							{ group.pages.map( ( page ) => (
								<li key={ page.id }>
									<a
										className="loggedin-nav-item"
										href={ hrefFor( page.id ) }
										onClick={ ( event ) =>
											onClick( event, page.id )
										}
										aria-current={
											page.id === current
												? 'page'
												: undefined
										}
									>
										<Icon name={ page.icon } />
										<span className="loggedin-nav-item__label">
											{ page.title }
										</span>
										{ /* A space, so the link's name reads "General Needs fixing", not "GeneralNeeds fixing". */ }{ ' ' }
										{ page.flag ? (
											<span
												className="loggedin-dot loggedin-dot--error"
												title={ page.flag }
											>
												<span className="screen-reader-text">
													{ page.flag }
												</span>
											</span>
										) : (
											page.badge && (
												<span className="loggedin-badge">
													<span aria-hidden="true">
														{ page.badge.count }
													</span>
													<span className="screen-reader-text">
														{ page.badge.label }
													</span>
												</span>
											)
										) }
									</a>
								</li>
							) ) }
						</ul>
					</div>
				) ) }
			</nav>
		</aside>
	);
}

/**
 * The section dropdown that replaces the sidebar links below 782px.
 *
 * @param {Object}               props            Props.
 * @param {Array}                props.groups     Same groups as the Sidebar.
 * @param {string}               props.current    Current page ID.
 * @param {(id: string) => void} props.onNavigate Called with a page ID.
 * @return {Element} Labelled select.
 */
export function SectionSelect( { groups, current, onNavigate } ) {
	return (
		<div className="loggedin-section-select">
			<label
				className="screen-reader-text"
				htmlFor="loggedin-section-select"
			>
				{ __( 'Section', 'loggedin' ) }
			</label>
			<select
				id="loggedin-section-select"
				value={ current }
				onChange={ ( event ) => onNavigate( event.target.value ) }
			>
				{ groups.map( ( group ) => (
					<optgroup key={ group.id } label={ group.label }>
						{ group.pages.map( ( page ) => (
							<option key={ page.id } value={ page.id }>
								{ optionLabel( page ) }
							</option>
						) ) }
					</optgroup>
				) ) }
			</select>
			<Icon name="chevron" />
		</div>
	);
}

/**
 * The text of a page in the section dropdown, with what its sidebar item
 * shows as a dot or badge, since an option can hold only text.
 *
 * @param {Object} page Page: { title, flag, badge }.
 * @return {string} Option text.
 */
function optionLabel( page ) {
	const extra = page.flag || page.badge?.count;

	return extra
		? sprintf(
				/* translators: 1: Page title, 2: "Needs fixing", or a count such as comments waiting for review. */
				__( '%1$s (%2$s)', 'loggedin' ),
				page.title,
				extra
		  )
		: page.title;
}

/**
 * The strip at the top of a page: its title and, on settings pages, the
 * save status and buttons.
 *
 * @param {Object}  props           Props.
 * @param {string}  props.title     Page title (the page's only h1).
 * @param {Element} [props.nav]     SectionSelect, shown instead of the title on narrow screens.
 * @param {Object}  [props.status]  Save status: { tone: 'warning'|'error', text }.
 * @param {Element} [props.actions] Buttons (Discard, Save).
 * @return {Element} Header.
 */
export function PageHeader( { title, nav, status, actions } ) {
	return (
		<header className="loggedin-header">
			<div className="loggedin-header__row">
				<h1 className="loggedin-header__title" tabIndex="-1">
					{ title }
				</h1>
				{ nav }
				{ /* Always in the page, so screen readers hear the text when it arrives. */ }
				<span
					className={ `loggedin-header__status${
						status
							? ` loggedin-header__status--${ status.tone }`
							: ''
					}` }
					aria-live="polite"
				>
					{ status && (
						<>
							<span
								className={ `loggedin-dot loggedin-dot--${ status.tone }` }
								aria-hidden="true"
							/>
							{ status.text }
						</>
					) }
				</span>
				{ actions && (
					<div className="loggedin-header__actions">{ actions }</div>
				) }
			</div>
		</header>
	);
}

/**
 * A link that opens in a new tab and says so to screen readers.
 *
 * @param {Object}  props             Props.
 * @param {string}  props.href        Address.
 * @param {string}  [props.className] Class names.
 * @param {Element} props.children    Link text.
 * @return {Element} Link.
 */
export function ExternalLink( { href, className, children } ) {
	return (
		<a
			className={ className }
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children }
			<span className="screen-reader-text">
				{ __( '(opens in a new tab)', 'loggedin' ) }
			</span>
		</a>
	);
}

/**
 * The last line of the screen: plugin, version, maker and links.
 *
 * Replaces WordPress's own admin footer, which the plugin hides on its
 * screen. Links open in a new tab and say so to screen readers.
 *
 * @param {Object}                                         props       Props.
 * @param {string|Element}                                 props.meta  Plugin, version and maker, e.g. "Loggedin 1.0.0 · by Foxe Labs"; the maker may be an ExternalLink.
 * @param {string}                                         props.label Name of the link list for screen readers.
 * @param {Array<{ label: string|Element, href: string }>} props.links Links, labelled with what they lead to (Documentation, Homepage).
 * @return {Element} Footer.
 */
export function PageFooter( { meta, label, links } ) {
	return (
		<footer className="loggedin-footer">
			<span className="loggedin-footer__meta">{ meta }</span>
			<nav className="loggedin-footer__links" aria-label={ label }>
				{ links.map( ( link ) => (
					<ExternalLink key={ link.href } href={ link.href }>
						{ link.label }
					</ExternalLink>
				) ) }
			</nav>
		</footer>
	);
}

/**
 * A white bordered panel holding one topic.
 *
 * @param {Object}  props               Props.
 * @param {string}  props.title         Card title.
 * @param {string}  [props.description] One sentence under the title.
 * @param {string}  [props.icon]        Icon name, shown on a tile before the title.
 * @param {Element} [props.action]      One button at the end of the title row.
 * @param {Element} [props.footer]      Closing row: a help line and buttons, under a divider.
 * @param {Element} [props.children]    Controls.
 * @return {Element} Card.
 */
export function Card( { title, description, icon, action, footer, children } ) {
	const head = (
		<div className="loggedin-card__text">
			<h2 className="loggedin-card__title">{ title }</h2>
			{ description && (
				<p className="loggedin-card__desc">{ description }</p>
			) }
		</div>
	);

	return (
		<section className="loggedin-card">
			{ icon || action ? (
				<div className="loggedin-card__head">
					{ icon && (
						<span className="loggedin-card__icon">
							<Icon name={ icon } size={ 20 } />
						</span>
					) }
					{ head }
					{ action }
				</div>
			) : (
				head
			) }
			{ children && (
				<div className="loggedin-card__body">{ children }</div>
			) }
			{ footer && (
				<div className="loggedin-card__footer">{ footer }</div>
			) }
		</section>
	);
}

const NOTICE_ICONS = {
	success: 'success',
	error: 'error',
	warning: 'warning',
	info: 'info',
};

/**
 * A message above the cards, in one of four tones.
 *
 * Errors and warnings describe a state that stays until it is resolved, so
 * they take no Dismiss button; success and info notices may.
 *
 * @param {Object}     props             Props.
 * @param {string}     [props.tone]      'success', 'error', 'warning' or 'info'.
 * @param {string}     props.title       What is true.
 * @param {Element}    [props.children]  What happened or what to do.
 * @param {Element}    [props.actions]   Up to two buttons: the fix first.
 * @param {() => void} [props.onDismiss] Makes the notice dismissible.
 * @return {Element} Notice.
 */
export function Notice( {
	tone = 'info',
	title,
	children,
	actions,
	onDismiss,
} ) {
	let role;

	if ( 'error' === tone ) {
		role = 'alert';
	} else if ( 'success' === tone ) {
		role = 'status';
	}

	return (
		<div
			className={ `loggedin-notice loggedin-notice--${ tone }` }
			role={ role }
		>
			<Icon name={ NOTICE_ICONS[ tone ] || 'info' } size={ 20 } />
			<div className="loggedin-notice__body">
				<div className="loggedin-notice__title">{ title }</div>
				{ children && (
					<div className="loggedin-notice__text">{ children }</div>
				) }
				{ actions && (
					<div className="loggedin-notice__actions">{ actions }</div>
				) }
			</div>
			{ onDismiss && 'error' !== tone && 'warning' !== tone && (
				<Button
					className="loggedin-notice__dismiss"
					size="compact"
					icon={ <Icon name="close" /> }
					label={ __( 'Dismiss', 'loggedin' ) }
					onClick={ onDismiss }
				/>
			) }
		</div>
	);
}
