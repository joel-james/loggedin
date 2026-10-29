/**
 * The admin app: sidebar, page header with Save, the current page and the footer.
 *
 * Settings are edited through the core-data `root/site` entity, shared
 * by every page, so changes on two pages save together. Settings pages
 * show Save in the page header, disabled until something changes. Pages
 * are chosen by the `tab` query argument (`&tab=sessions`), so a reload
 * or a shared link opens the same page.
 */

/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	Button,
	ExternalLink,
	Notice,
	PageFooter,
	PageHeader,
	SectionSelect,
	Sidebar,
	Snackbars,
	Spinner,
} from '../ui';
import useSave from '../hooks/use-save';
import ErrorBoundary from './error-boundary';
import { groups as sidebarGroups, LEGACY_HASHES, resolvePages } from './pages';
import { validate } from './validate';

/**
 * Read the page ID from the `tab` query argument.
 *
 * @return {string} Page ID, or ''.
 */
function tabFromUrl() {
	return new URLSearchParams( window.location.search ).get( 'tab' ) || '';
}

/**
 * Address of a page: the current screen with `tab` set.
 *
 * @param {string} id Page ID.
 * @return {string} URL.
 */
function tabUrl( id ) {
	const url = new URL( window.location.href );

	url.searchParams.set( 'tab', id );
	url.hash = '';

	return url.toString();
}

/**
 * The first page to show: the `tab` argument, else a `#hash` from a link
 * made before the kit (`#addons`), which is swapped for `tab` in the
 * address.
 *
 * @return {string} Page ID, or ''.
 */
function initialRoute() {
	const tab = tabFromUrl();
	const hash = LEGACY_HASHES[ window.location.hash.slice( 1 ) ];

	if ( ! tab && hash ) {
		window.history.replaceState( null, '', tabUrl( hash ) );

		return hash;
	}

	return tab;
}

/**
 * The admin app.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Data from PHP (version, URLs, schema, …).
 * @return {Element} App.
 */
export default function App( { boot } ) {
	const pages = useMemo(
		() => resolvePages( boot.schema, boot.roles ),
		[ boot ]
	);
	const [ route, setRoute ] = useState( initialRoute );
	const navigated = useRef( false );
	const wpNotices = useRef();
	const saveState = useSave();

	const page = pages.find( ( item ) => item.id === route ) || pages[ 0 ];

	const site = useSelect(
		( select ) =>
			select( coreStore ).getEditedEntityRecord( 'root', 'site' ),
		[]
	);

	const errors = useMemo(
		() =>
			validate(
				site,
				boot.schema.fields || [],
				boot.autoLogout.max,
				boot.homeUrl
			),
		[ site, boot ]
	);

	const errorCount = Object.keys( errors ).length;

	// Back and forward move between pages without a reload.
	useEffect( () => {
		const onPop = () => {
			navigated.current = true;
			setRoute( tabFromUrl() );
		};

		window.addEventListener( 'popstate', onPop );

		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	/**
	 * Show a page and record it in the address and browser history.
	 *
	 * @param {string} id Page ID.
	 * @return {void}
	 */
	const navigate = ( id ) => {
		if ( id === page.id ) {
			return;
		}

		window.history.pushState( null, '', tabUrl( id ) );
		navigated.current = true;
		setRoute( id );
	};

	/*
	 * WordPress prints admin notices (updates, the review prompt, other
	 * plugins) before the app, where they would sit outside the layout.
	 * Move them into the content column, as core's common.js does for
	 * `.wp-header-end` on classic screens. React renders no children in
	 * that box, so it never touches them.
	 */
	useEffect( () => {
		const box = wpNotices.current;
		const body = document.getElementById( 'wpbody-content' );

		if ( ! box || ! body ) {
			return;
		}

		body.querySelectorAll(
			':scope > .notice, :scope > .updated, :scope > .error, :scope > .update-nag'
		).forEach( ( notice ) => box.appendChild( notice ) );
	}, [] );

	// The browser tab names the page, as a full page load would.
	const baseTitle = useRef( document.title );

	useEffect( () => {
		document.title = sprintf(
			/* translators: 1: Page title, e.g. "Sessions", 2: The screen's own title from WordPress. */
			__( '%1$s ‹ %2$s', 'loggedin' ),
			page.title,
			baseTitle.current
		);
	}, [ page.title ] );

	// Move focus to the new page's title, so keyboard and screen reader users land on it.
	useEffect( () => {
		if ( navigated.current ) {
			document.querySelector( '.loggedin-header__title' )?.focus();
		}
	}, [ page.id ] );

	useEffect( () => {
		if ( ! saveState.isDirty ) {
			return undefined;
		}

		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ saveState.isDirty ] );

	const canSave =
		saveState.hasLoaded &&
		! saveState.isSaving &&
		saveState.isDirty &&
		0 === errorCount;

	const save = () => {
		// Save stays focusable while it can't act (aria-disabled), so guard the click here.
		if ( canSave ) {
			saveState.save();
		}
	};

	const navGroups = sidebarGroups()
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

	let status;

	if ( errorCount ) {
		status = {
			tone: 'error',
			text: sprintf(
				/* translators: %d: Number of settings with an error. */
				_n(
					'%d setting needs fixing',
					'%d settings need fixing',
					errorCount,
					'loggedin'
				),
				errorCount
			),
		};
	} else if ( saveState.isDirty ) {
		status = {
			tone: 'warning',
			text: sprintf(
				/* translators: %d: Number of changed settings. */
				_n(
					'%d unsaved change',
					'%d unsaved changes',
					saveState.count,
					'loggedin'
				),
				saveState.count
			),
		};
	}

	// Save shows on settings pages, and on any page while there is something to save.
	const actions = ( page.saves || saveState.isDirty ) && (
		<>
			{ saveState.isDirty && (
				<Button
					variant="tertiary"
					size="compact"
					onClick={ saveState.discard }
					disabled={ saveState.isSaving }
				>
					{ __( 'Discard', 'loggedin' ) }
				</Button>
			) }
			<Button
				variant="primary"
				size="compact"
				onClick={ save }
				isBusy={ saveState.isSaving }
				aria-disabled={ ! canSave }
			>
				{ saveState.justSaved
					? __( 'Saved', 'loggedin' )
					: __( 'Save', 'loggedin' ) }
			</Button>
		</>
	);

	const Page = page.render;
	const props = { boot, errors, navigate, hrefFor: tabUrl };

	return (
		<>
			<Sidebar
				name="Loggedin"
				label={ __( 'Loggedin sections', 'loggedin' ) }
				status={ {
					tone: 'on',
					text: sprintf(
						/* translators: %s: Plugin version. */
						__( 'On · %s', 'loggedin' ),
						boot.version
					),
				} }
				groups={ navGroups }
				current={ page.id }
				hrefFor={ tabUrl }
				onNavigate={ navigate }
			/>
			<div className="loggedin-main">
				<PageHeader
					title={ page.title }
					nav={
						<SectionSelect
							groups={ navGroups }
							current={ page.id }
							onNavigate={ navigate }
						/>
					}
					status={ status }
					actions={ actions }
				/>
				<main
					className={ `loggedin-content${
						page.wide ? ' loggedin-content--wide' : ''
					}` }
				>
					<div className="loggedin-wp-notices" ref={ wpNotices } />
					{ saveState.error && (
						<Notice
							tone="error"
							title={ __(
								'The settings could not be saved',
								'loggedin'
							) }
							actions={
								<Button
									variant="primary"
									size="compact"
									onClick={ save }
									aria-disabled={ ! canSave }
								>
									{ __( 'Try again', 'loggedin' ) }
								</Button>
							}
						>
							{ sprintf(
								/* translators: %s: Error message from the server. */
								__(
									'%s Your changes are still here, so nothing is lost.',
									'loggedin'
								),
								saveState.error
							) }
						</Notice>
					) }
					{ saveState.hasLoaded ? (
						<ErrorBoundary key={ page.id }>
							{ Page && <Page { ...props } /> }
							{ page.panels.map( ( { id, Component, legacy } ) =>
								legacy ? (
									<div
										key={ id }
										className="loggedin-legacy-panel"
									>
										<Component />
									</div>
								) : (
									<Component key={ id } errors={ errors } />
								)
							) }
						</ErrorBoundary>
					) : (
						<div className="loggedin-loading">
							<Spinner />
						</div>
					) }
				</main>
				<PageFooter
					meta={ createInterpolateElement(
						sprintf(
							/* translators: %s: Plugin version. <a> links to the maker's website. */
							__(
								'Loggedin %s · by <a>Foxe Labs</a>',
								'loggedin'
							),
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
			</div>
			<Snackbars />
		</>
	);
}
