/**
 * Admin app entry: boots the React UI on Users → Loggedin.
 *
 * Boot order matters: `api-init` first, so any data hook that runs
 * during the first render has the REST nonce, then the `@wordpress/data`
 * store registrations, then the mount. The kit's stylesheet comes in
 * with `./ui`.
 *
 * The mount node is printed by `Admin\Admin::render_page()`. The app
 * mounts on DOM ready, after every add-on script has added its pages
 * and panels through the `loggedin.admin.tabs` and
 * `loggedin.settings.panels` filters.
 */
import './utils/api-init';
import './store';
import { Popover, SlotFillProvider } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from './app/App';

domReady( () => {
	const root = document.getElementById( 'loggedin-admin' );
	const boot = window.loggedin;

	if ( ! root || ! boot ) {
		return;
	}

	root.innerHTML = '';

	/*
	 * Popovers, dropdowns and menus (add-on pages use them) render into
	 * the Popover slot, so they sit above the screen with the right
	 * position and background.
	 */
	createRoot( root ).render(
		<SlotFillProvider>
			<App boot={ boot } />
			<Popover.Slot />
		</SlotFillProvider>
	);
} );
