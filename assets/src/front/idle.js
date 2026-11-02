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

	/*
	 * How often activity is recorded. Each record writes localStorage,
	 * which fires `storage` in every other open tab, so recording every
	 * mouse move would keep all of them busy. A sixtieth of the timeout,
	 * between one and ten seconds, costs at most that much of the idle
	 * time while keeping cross-tab traffic to a trickle.
	 */
	const recordEveryMs = Math.min( 10000, Math.max( 1000, timeoutMs / 60 ) );

	// Shared across tabs so activity anywhere resets everywhere.
	const STORAGE_KEY = 'loggedin_auto_logout_active';

	let lastActivity = Date.now();
	// When the server last heard from this session. The server ends the
	// session by its own stamp, which can be older than this page view:
	// it rewrites the stamp only every `pingAfter` seconds.
	let serverActive =
		Math.min(
			Date.now(),
			( parseInt( config.lastActive, 10 ) || 0 ) * 1000
		) || Date.now();
	let lastPing = serverActive;
	let warningTimer = null;
	let logoutTimer = null;
	let countdownTimer = null;
	let dialog = null;
	// When the open dialog's countdown reaches zero: last activity plus the timeout.
	let deadline = 0;

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
		serverActive = lastPing;

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				// admin-ajax answers a request with no session with a 400:
				// the server has already signed this one out. The logout
				// link would only ask "Do you really want to log out?".
				if ( 400 === response.status && config.loginUrl ) {
					window.location.href = config.loginUrl;
				}
			} )
			.catch( function () {
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

		const returnTo = dialog && dialog.returnTo;

		if ( dialog && dialog.root.parentNode ) {
			dialog.root.parentNode.removeChild( dialog.root );
		}

		dialog = null;

		// Back to wherever the user was before the dialog took focus.
		if ( returnTo && returnTo.focus && document.contains( returnTo ) ) {
			returnTo.focus();
		}
	}

	/**
	 * Build and show the warning dialog with a live countdown.
	 *
	 * The countdown runs to the real logout time, not to "now plus the
	 * warning": a tab that comes back from the background, or a page
	 * loaded late in the idle window, opens the dialog with less than
	 * the full warning left.
	 *
	 * @param {number} at Timestamp the session signs out at.
	 * @return {void}
	 */
	function openDialog( at ) {
		deadline = at;

		if ( dialog ) {
			return;
		}

		const strings = config.i18n || {};

		const root = document.createElement( 'div' );
		root.className = 'loggedin-al-modal';
		root.setAttribute( 'role', 'alertdialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.setAttribute( 'aria-labelledby', 'loggedin-al-modal-title' );
		root.setAttribute( 'aria-describedby', 'loggedin-al-modal-message' );

		const box = document.createElement( 'div' );
		box.className = 'loggedin-al-modal__box';

		const title = document.createElement( 'h2' );
		title.className = 'loggedin-al-modal__title';
		title.id = 'loggedin-al-modal-title';
		title.textContent = strings.title || '';

		// Read once, as the dialog's description, when it opens. A live
		// region here would announce the countdown every second.
		const message = document.createElement( 'p' );
		message.className = 'loggedin-al-modal__message';
		message.id = 'loggedin-al-modal-message';

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

		dialog = { root, message, returnTo: root.ownerDocument.activeElement };

		// Keep Tab between the two buttons while the dialog is open.
		root.addEventListener( 'keydown', function ( event ) {
			if ( 'Tab' !== event.key ) {
				return;
			}

			const from = event.shiftKey ? stay : out;

			if ( root.ownerDocument.activeElement === from ) {
				event.preventDefault();
				( event.shiftKey ? out : stay ).focus();
			}
		} );

		/**
		 * Repaint the countdown text.
		 *
		 * @return {void}
		 */
		function tick() {
			const remaining = deadline - Date.now();

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
	 * @param {number} from Latest activity this tab knows of.
	 * @return {void}
	 */
	function schedule( from ) {
		window.clearTimeout( warningTimer );
		window.clearTimeout( logoutTimer );

		// The session ends when the server says, so count from its stamp
		// when that is the older one.
		const logoutAt = Math.min( from, serverActive ) + timeoutMs;
		const untilLogout = logoutAt - Date.now();

		const warn = function () {
			unlessUnheard( function () {
				openDialog( logoutAt );
			} );
		};
		const end = function () {
			unlessUnheard( signOut );
		};

		if ( untilLogout <= 0 ) {
			end();
			return;
		}

		if ( warningMs > 0 ) {
			const untilWarning = untilLogout - warningMs;

			if ( untilWarning <= 0 ) {
				warn();
			} else {
				warningTimer = window.setTimeout( warn, untilWarning );
			}
		}

		logoutTimer = window.setTimeout( end, untilLogout );
	}

	/**
	 * Run a timer's action, unless the server hasn't heard of the
	 * latest activity.
	 *
	 * The timers can run from the server's older stamp. If the user has
	 * done something since, tell the server and count again from that
	 * activity instead of warning someone who isn't idle.
	 *
	 * @param {Function} action Warning or sign-out.
	 * @return {void}
	 */
	function unlessUnheard( action ) {
		if ( lastActivity > serverActive ) {
			ping();
			schedule( lastActivity );
			return;
		}

		action();
	}

	/**
	 * Note that the user did something, and reschedule.
	 *
	 * @param {boolean} force Bypass the local throttle (`recordEveryMs`).
	 * @return {void}
	 */
	function recordActivity( force ) {
		// The warning dialog is modal by intent: mouse movement over it
		// must not silently cancel the countdown, only the button does.
		if ( dialog && ! force ) {
			return;
		}

		const now = Date.now();

		if ( ! force && now - lastActivity < recordEveryMs ) {
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
			lastActivity = Math.max( lastActivity, shared() );
			schedule( lastActivity );
		}
	} );

	shared( lastActivity );
	schedule( lastActivity );
} )();
