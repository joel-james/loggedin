/**
 * Which page the screen shows, kept in the address.
 *
 * Pages are chosen by the `tab` query argument (`&tab=sessions`), so a
 * reload or a shared link opens the same page. Switching pages pushes a
 * history entry instead of reloading, and Back and Forward move between
 * them.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { LEGACY_HASHES } from './pages';

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
export function tabUrl( id ) {
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
 * The current page and a way to switch pages.
 *
 * Also names the browser tab after the page, and after a switch moves
 * focus to the new page's title.
 *
 * @param {Array} pages Resolved pages; an unknown `tab` shows the first.
 * @return {{ page: Object, navigate: (id: string) => void }} Route.
 */
export default function useRoute( pages ) {
	const [ route, setRoute ] = useState( initialRoute );

	// Focus moves only after a switch, never on the first load.
	const navigated = useRef( false );

	const page = pages.find( ( item ) => item.id === route ) || pages[ 0 ];

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

	return { page, navigate };
}
