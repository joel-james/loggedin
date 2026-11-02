/**
 * The screen's pages: core's, plus whatever add-ons bring.
 *
 * Add-ons extend the screen three ways, oldest first:
 *
 *   - `loggedin.admin.tabs` (JS filter): a whole page. Entries are
 *     `{ key, label, component, wide?, saves?, group?, icon?, before?, after? }`;
 *     they join the Manage group unless `group` says otherwise. A key
 *     that matches a core page replaces that page's content. The
 *     component gets the same props as a core page:
 *       - `boot` (Object): the `window.loggedin` data from PHP.
 *       - `errors` (Object): error text by `option.key`, for every page.
 *       - `navigate` (Function): `( id ) => void`, shows another page in
 *         place and records it in the address.
 *       - `hrefFor` (Function): `( id ) => string`, the address of a page,
 *         for links that should also open in a new tab.
 *   - `loggedin.settings.panels` (JS filter): a panel on a page. Entries
 *     are `{ id, Component, page? }`; they join the Login limits page
 *     unless `page` names another. Panels built before the kit are
 *     WordPress `PanelBody` blocks, drawn as Cards by the stylesheet.
 *     They get no props.
 *   - `loggedin_settings_schema` (PHP filter): pages, cards and fields
 *     declared in PHP and drawn with the kit's controls (see schema.js).
 *     Each card gets `errors`, as above.
 *
 * A page with no content of its own and nothing added to it is left
 * out.
 */
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { errorKey, SchemaCard } from './schema';
import { LoginLimitsPage } from '../pages/login-limits';
import { LockoutPage } from '../pages/lockout';
import { AutoLogoutPage } from '../pages/auto-logout';
import { SessionsPage } from '../pages/sessions';
import { AddonsPage } from '../pages/addons';
import { HelpPage } from '../pages/help';

/**
 * Sidebar groups, top to bottom by task.
 *
 * @return {Array<{ id: string, label: string }>} Groups.
 */
export const groups = () => [
	{ id: 'settings', label: __( 'Settings', 'loggedin' ) },
	{ id: 'manage', label: __( 'Manage', 'loggedin' ) },
	{ id: 'more', label: __( 'More', 'loggedin' ) },
];

/**
 * Sidebar groups holding their pages, each page flagged when a setting on
 * it has an error. Groups with no pages are left out.
 *
 * @param {Array}  pages  Resolved pages (see resolvePages).
 * @param {Object} errors Error text by `option.key`.
 * @return {Array} Groups: [{ id, label, pages: [{ ...page, flag }] }].
 */
export const groupsWithPages = ( pages, errors ) =>
	groups()
		.map( ( group ) => ( {
			...group,
			pages: pages
				.filter( ( item ) => item.group === group.id )
				.map( ( item ) => ( {
					...item,
					flag: Object.keys( errors ).some( ( error ) =>
						item.keys.some( ( key ) =>
							key.endsWith( '.' )
								? error.startsWith( key )
								: error === key
						)
					)
						? __( 'Needs fixing', 'loggedin' )
						: '',
				} ) ),
		} ) )
		.filter( ( group ) => group.pages.length );

/**
 * Core pages, in sidebar order.
 *
 * @return {Array} Pages: { id, group, title, icon, render?, saves?, wide?, keys? }.
 */
const corePages = () => [
	{
		id: 'login-limits',
		group: 'settings',
		title: __( 'Login limits', 'loggedin' ),
		icon: 'limits',
		render: LoginLimitsPage,
		saves: true,
		keys: [ 'loggedin_settings.maximum' ],
	},
	{
		id: 'lockout',
		group: 'settings',
		title: __( 'Lockout & recovery', 'loggedin' ),
		icon: 'lockout',
		render: LockoutPage,
		saves: true,
		keys: [ 'loggedin_settings.displaced_redirect' ],
	},
	{
		id: 'auto-logout',
		group: 'settings',
		title: __( 'Auto logout', 'loggedin' ),
		icon: 'clock',
		render: AutoLogoutPage,
		saves: true,
		// A key ending in `.` flags every error under that option.
		keys: [
			'loggedin_auto_logout_settings.',
			'loggedin_auto_logout_role_timeouts.',
		],
	},
	{
		id: 'sessions',
		group: 'manage',
		title: __( 'Sessions', 'loggedin' ),
		icon: 'sessions',
		render: SessionsPage,
	},
	{
		id: 'addons',
		group: 'more',
		title: __( 'Add-ons', 'loggedin' ),
		icon: 'addons',
		render: AddonsPage,
	},
	{
		id: 'help',
		group: 'more',
		title: __( 'Help', 'loggedin' ),
		icon: 'help',
		render: HelpPage,
	},
];

/**
 * Pages for schema cards declared with the pre-kit `tab` key.
 */
const LEGACY_TAB_PAGES = {
	settings: 'login-limits',
	sessions: 'sessions',
};

/**
 * Settle a tab from an add-on built before the kit.
 *
 * Active Sessions 1.x labels its tab "Sessions", which core now uses
 * for Force logout, and slots it before Add-ons. It becomes "Active
 * sessions", right after core's Sessions page.
 *
 * @param {Object} tab Filter entry.
 * @return {Object} Entry to use.
 */
const settleLegacyTab = ( tab ) =>
	// 1.2.0 and later say where they go, with `group`.
	'active-sessions' === tab.key && ! tab.group
		? {
				...tab,
				label: __( 'Active sessions', 'loggedin' ),
				icon: tab.icon || 'users',
				before: undefined,
				after: 'sessions',
		  }
		: tab;

/**
 * Put a page into the list before or after the page it names.
 *
 * @param {Array}  list Pages so far (changed in place).
 * @param {Object} page Page with optional `before` / `after`.
 * @return {void}
 */
const place = ( list, page ) => {
	const anchor = list.findIndex(
		( item ) => item.id === ( page.before || page.after )
	);

	if ( -1 === anchor ) {
		list.push( page );
	} else {
		list.splice( page.before ? anchor : anchor + 1, 0, page );
	}
};

/**
 * Resolve every page and what it holds.
 *
 * Called once, when the app mounts: add-on scripts have run by then (the
 * app mounts on DOM ready), and resolving once keeps every Component
 * reference stable, so panels never remount and drop focus while typing.
 *
 * @param {Object} schema  `loggedin_settings_schema`: { pages, cards, fields }.
 * @param {Object} [roles] Every role: { slug: name }, for role tables.
 * @return {Array} Pages with `panels`: [{ id, Component, legacy }].
 */
export function resolvePages( schema, roles = {} ) {
	const list = corePages();
	const groupIds = groups().map( ( group ) => group.id );
	const fields = schema.fields || [];

	( schema.pages || [] )
		.filter( ( page ) => page && page.id && page.title )
		.filter( ( page ) => ! list.some( ( item ) => item.id === page.id ) )
		.forEach( ( page ) =>
			place( list, {
				id: page.id,
				group: groupIds.includes( page.group )
					? page.group
					: 'settings',
				title: page.title,
				icon: page.icon || 'dot',
				saves: true,
				before: page.before,
				after: page.after,
			} )
		);

	applyFilters( 'loggedin.admin.tabs', [] )
		.filter(
			( tab ) =>
				tab && 'string' === typeof tab.key && tab.key && tab.component
		)
		.map( settleLegacyTab )
		.forEach( ( tab ) => {
			const existing = list.find( ( item ) => item.id === tab.key );

			if ( existing ) {
				existing.render = tab.component;
				return;
			}

			place( list, {
				id: tab.key,
				group: groupIds.includes( tab.group ) ? tab.group : 'manage',
				title: tab.label,
				icon: tab.icon || 'list',
				render: tab.component,
				wide: !! tab.wide,
				saves: !! tab.saves,
				before: tab.before,
				after: tab.after,
			} );
		} );

	const pageOf = ( wanted, fallback ) =>
		list.some( ( item ) => item.id === wanted ) ? wanted : fallback;

	const panels = [];

	( schema.cards || [] )
		.filter( ( card ) => card && card.id )
		.forEach( ( card ) =>
			panels.push( {
				id: card.id,
				page: pageOf(
					card.page || LEGACY_TAB_PAGES[ card.tab ],
					'login-limits'
				),
				keys: fields
					.filter(
						( field ) =>
							field.card === card.id &&
							[ 'number', 'role-table' ].includes( field.type )
					)
					.map( errorKey ),
				Component: ( { errors } ) => (
					<SchemaCard
						card={ card }
						fields={ fields }
						errors={ errors }
						roles={ roles }
					/>
				),
			} )
		);

	applyFilters( 'loggedin.settings.panels', [] )
		.filter( ( panel ) => panel && panel.id && panel.Component )
		.forEach( ( panel ) => {
			// A later entry with the same id replaces the earlier one.
			const existing = panels.findIndex(
				( item ) => item.id === panel.id
			);

			if ( -1 !== existing ) {
				panels.splice( existing, 1 );
			}

			panels.push( {
				id: panel.id,
				page: pageOf( panel.page, 'login-limits' ),
				keys: [],
				legacy: true,
				Component: panel.Component,
			} );
		} );

	return list
		.map( ( page ) => {
			const own = panels.filter( ( panel ) => panel.page === page.id );

			return {
				...page,
				panels: own,
				keys: [
					...( page.keys || [] ),
					...own.flatMap( ( panel ) => panel.keys ),
				],
			};
		} )
		.filter( ( page ) => page.render || page.panels.length );
}

/**
 * Page IDs used by the screen before the kit, as `#hash` links.
 */
export const LEGACY_HASHES = {
	settings: 'login-limits',
	sessions: 'sessions',
	addons: 'addons',
	support: 'help',
	help: 'help',
	'active-sessions': 'active-sessions',
};
