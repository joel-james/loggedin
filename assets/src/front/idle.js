/* global loggedinAutoLogout */

/**
 * Front-end idle timer.
 *
 * Counts down locally and signs the user out when the timer runs out,
 * optionally showing a warning dialog first. Deliberately does *not*
 * poll: the only request it ever makes is an activity ping, sent while
 * the user is genuinely interacting and no more often than the server's
 * own write throttle, so that a long-lived single-page screen keeps its
 * server-side stamp current.
 *
 * The server enforces the timeout independently on the next request, so
 * this script is a courtesy — it makes the logout happen at the moment
 * it is due rather than on the next page load, and gives the user a
 * chance to stay.
 *
 * Tabs share their activity through `localStorage`, so typing in one
 * tab keeps the others alive without any server round-trip.
 *
 * Vanilla DOM rather than React so this ships with zero dependencies —
 * it runs on every page of every signed-in visitor, not just admins.
 */
import './idle.scss';

( function () {
	if (
		typeof loggedinAutoLogout === 'undefined' ||
		! loggedinAutoLogout.logoutUrl
	) {
		return;
	}

	const config = loggedinAutoLogout;
	const timeoutMs = ( parseInt( config.timeout, 10 ) || 0 ) * 1000;

	if ( timeoutMs <= 0 ) {
		return;
	}

	const warningMs = ( parseInt( config.warning, 10 ) || 0 ) * 1000;
	const pingMs = ( parseInt( config.pingAfter, 10 ) || 60 ) * 1000;

	// Shared across tabs so activity anywhere resets everywhere.
	const STORAGE_KEY = 'loggedin_auto_logout_active';

	let lastActivity = Date.now();
	let lastPing = Date.now();
	let warningTimer = null;
	let logoutTimer = null;
	let countdownTimer = null;
	let dialog = null;

	/**
	 * Read/write the shared activity stamp. Storage can throw in
	 * private-browsing modes and sandboxed frames, so every access is
	 * guarded and simply degrades to per-tab behaviour.
	 *
	 * @param {number} [value] Stamp to write; omit to read.
	 * @return {number} The stored stamp, or 0 when unavailable.
	 */
	function shared( value ) {
		try {
			if ( undefined !== value ) {
				window.localStorage.setItem( STORAGE_KEY, String( value ) );
				return value;
			}

			return parseInt(
				window.localStorage.getItem( STORAGE_KEY ) || '0',
				10
			);
		} catch ( e ) {
			return 0;
		}
	}

	/**
	 * Tell the server the session is still in use.
	 *
	 * @return {void}
	 */
	function ping() {
		if ( ! config.ajaxUrl || ! config.nonce ) {
			return;
		}

		const body = new URLSearchParams();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );

		lastPing = Date.now();

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {
			// Network blip. The next burst of activity tries again, and
			// the server-side check on the next page load is the real
			// backstop either way.
		} );
	}

	/**
	 * Sign the user out.
	 *
	 * @return {void}
	 */
	function signOut() {
		window.location.href = config.logoutUrl;
	}

	/**
	 * Format remaining milliseconds as `m:ss`.
	 *
	 * @param {number} ms Milliseconds remaining.
	 * @return {string} Formatted countdown.
	 */
	function formatRemaining( ms ) {
		const total = Math.max( 0, Math.ceil( ms / 1000 ) );
		const minutes = Math.floor( total / 60 );
		const seconds = total % 60;

		return minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;
	}

	/**
	 * Remove the warning dialog, if one is open.
	 *
	 * @return {void}
	 */
	function closeDialog() {
		if ( countdownTimer ) {
			window.clearInterval( countdownTimer );
			countdownTimer = null;
		}

		if ( dialog && dialog.root.parentNode ) {
			dialog.root.parentNode.removeChild( dialog.root );
		}

		dialog = null;
	}

	/**
	 * Build and show the warning dialog with a live countdown.
	 *
	 * @return {void}
	 */
	function openDialog() {
		if ( dialog ) {
			return;
		}

		const strings = config.i18n || {};
		const expiresAt = Date.now() + warningMs;

		const root = document.createElement( 'div' );
		root.className = 'loggedin-al-modal';
		root.setAttribute( 'role', 'alertdialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.setAttribute( 'aria-labelledby', 'loggedin-al-modal-title' );

		const box = document.createElement( 'div' );
		box.className = 'loggedin-al-modal__box';

		const title = document.createElement( 'h2' );
		title.className = 'loggedin-al-modal__title';
		title.id = 'loggedin-al-modal-title';
		title.textContent = strings.title || '';

		const message = document.createElement( 'p' );
		message.className = 'loggedin-al-modal__message';
		message.setAttribute( 'aria-live', 'polite' );

		const actions = document.createElement( 'div' );
		actions.className = 'loggedin-al-modal__actions';

		const stay = document.createElement( 'button' );
		stay.type = 'button';
		stay.className = 'loggedin-al-modal__button is-primary';
		stay.textContent = strings.stay || '';

		const out = document.createElement( 'button' );
		out.type = 'button';
		out.className = 'loggedin-al-modal__button';
		out.textContent = strings.logout || '';

		actions.appendChild( stay );
		actions.appendChild( out );
		box.appendChild( title );
		box.appendChild( message );
		box.appendChild( actions );
		root.appendChild( box );
		document.body.appendChild( root );

		dialog = { root, message };

		/**
		 * Repaint the countdown text.
		 *
		 * @return {void}
		 */
		function tick() {
			const remaining = expiresAt - Date.now();

			if ( remaining <= 0 ) {
				message.textContent = strings.expiring || '';
				return;
			}

			message.textContent = ( strings.message || '%s' ).replace(
				'%s',
				formatRemaining( remaining )
			);
		}

		tick();
		countdownTimer = window.setInterval( tick, 1000 );

		stay.addEventListener( 'click', function () {
			closeDialog();
			// An explicit "keep me signed in" always reaches the
			// server, throttle or not — the whole point is that the
			// stamp is refreshed right now.
			ping();
			recordActivity( true );
		} );

		out.addEventListener( 'click', signOut );

		stay.focus();
	}

	/**
	 * Restart the warning / logout timers from a given activity time.
	 *
	 * @param {number} from Timestamp the countdown runs from.
	 * @return {void}
	 */
	function schedule( from ) {
		window.clearTimeout( warningTimer );
		window.clearTimeout( logoutTimer );

		const elapsed = Date.now() - from;
		const untilLogout = timeoutMs - elapsed;

		if ( untilLogout <= 0 ) {
			signOut();
			return;
		}

		if ( warningMs > 0 ) {
			const untilWarning = untilLogout - warningMs;

			if ( untilWarning <= 0 ) {
				openDialog();
			} else {
				warningTimer = window.setTimeout( openDialog, untilWarning );
			}
		}

		logoutTimer = window.setTimeout( signOut, untilLogout );
	}

	/**
	 * Note that the user did something, and reschedule.
	 *
	 * @param {boolean} force Bypass the once-a-second local throttle.
	 * @return {void}
	 */
	function recordActivity( force ) {
		// The warning dialog is modal by intent: mouse movement over it
		// must not silently cancel the countdown, only the button does.
		if ( dialog && ! force ) {
			return;
		}

		const now = Date.now();

		if ( ! force && now - lastActivity < 1000 ) {
			return;
		}

		lastActivity = now;
		shared( now );
		schedule( now );

		// Renew the server-side stamp only when it is old enough to be
		// worth a request. This is the script's only unprompted
		// network traffic, and it stops entirely once the user does.
		if ( now - lastPing >= pingMs ) {
			ping();
		}
	}

	const events = [
		'mousedown',
		'mousemove',
		'keydown',
		'touchstart',
		'scroll',
		'wheel',
	];

	events.forEach( function ( name ) {
		window.addEventListener(
			name,
			function () {
				recordActivity( false );
			},
			{ passive: true }
		);
	} );

	// Activity in a sibling tab resets this one. No network involved —
	// `storage` fires locally in every other tab on the same origin.
	window.addEventListener( 'storage', function ( event ) {
		if ( event.key !== STORAGE_KEY || ! event.newValue ) {
			return;
		}

		const stamp = parseInt( event.newValue, 10 );

		if ( stamp > lastActivity ) {
			lastActivity = stamp;
			closeDialog();
			schedule( stamp );
		}
	} );

	// A tab that was suspended (laptop lid, background throttling) can
	// come back with its timers long overdue; re-evaluate on return
	// rather than waiting for the stale timeout to fire.
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			schedule( Math.max( lastActivity, shared() ) );
		}
	} );

	shared( lastActivity );
	schedule( lastActivity );
} )();
