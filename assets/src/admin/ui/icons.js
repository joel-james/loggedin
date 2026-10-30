/**
 * Stroke icons for the admin UI.
 *
 * 24px grid, 1.5px stroke in currentColor, as the Foxe Plugin Admin Kit asks
 * for. Drawn here rather than importing `@wordpress/icons`, which would be
 * bundled into the script.
 */

/**
 * Render a stroke icon.
 *
 * @param {Object} props        Props.
 * @param {string} props.name   Icon name (a key of PATHS).
 * @param {number} [props.size] Rendered size in px.
 * @return {Element} SVG element.
 */
export function Icon( { name, size = 18 } ) {
	return (
		<svg
			className={ `loggedin-icon loggedin-icon--${ name }` }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			{ PATHS[ name ] || PATHS.dot }
		</svg>
	);
}

/**
 * Each icon's strokes, by name, drawn inside a 24×24 viewBox.
 *
 * @type {Object<string, Element>}
 */
const PATHS = {
	limits: (
		<>
			<rect x="3.5" y="5" width="11" height="8" rx="1.5" />
			<path d="M7 17h4M9 13v4" />
			<rect x="15.5" y="8" width="5" height="9" rx="1.2" />
		</>
	),
	lockout: (
		<>
			<circle cx="8" cy="12" r="3.5" />
			<path d="M11.5 12H20M17 12v3M20 12v2.5" />
		</>
	),
	clock: (
		<>
			<circle cx="12" cy="12" r="8" />
			<path d="M12 7.5V12l3 2" />
		</>
	),
	notifications: (
		<>
			<path d="M6.5 16.5V11a5.5 5.5 0 0 1 11 0v5.5L19 18H5z" />
			<path d="M10 20.5h4" />
		</>
	),
	sessions: (
		<>
			<path d="M14 5h4a1.5 1.5 0 0 1 1.5 1.5v11A1.5 1.5 0 0 1 18 19h-4" />
			<path d="M10 16 14 12 10 8M14 12H4.5" />
		</>
	),
	history: (
		<>
			<path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3L4.5 9" />
			<path d="M4.5 4.5V9H9M12 8v4l2.5 2" />
		</>
	),
	list: (
		<>
			<path d="M9 7h11M9 12h11M9 17h11" />
			<path d="M4.5 7h.01M4.5 12h.01M4.5 17h.01" />
		</>
	),
	addons: (
		<>
			<rect x="4" y="4" width="7" height="7" rx="1.5" />
			<rect x="13" y="4" width="7" height="7" rx="1.5" />
			<rect x="4" y="13" width="7" height="7" rx="1.5" />
			<path d="M16.5 13.5v6M13.5 16.5h6" />
		</>
	),
	help: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01" />
		</>
	),
	book: (
		<>
			<path d="M5 5.5A1.5 1.5 0 0 1 6.5 4H19v13H6.5A1.5 1.5 0 0 0 5 18.5z" />
			<path d="M5 18.5A1.5 1.5 0 0 0 6.5 20H19v-3M9 8h6" />
		</>
	),
	support: (
		<>
			<path d="M5 17.5V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H9z" />
			<path d="M9 9.5h6M9 12.5h4" />
		</>
	),
	versions: (
		<>
			<path d="m12 4 8 4-8 4-8-4z" />
			<path d="m4 12 8 4 8-4M4 16l8 4 8-4" />
		</>
	),
	user: (
		<>
			<circle cx="12" cy="8.5" r="3.5" />
			<path d="M5 19.5a7 7 0 0 1 14 0" />
		</>
	),
	users: (
		<>
			<circle cx="9" cy="8.5" r="3.5" />
			<path d="M2.5 19.5a6.5 6.5 0 0 1 13 0M15.5 5.2a3.5 3.5 0 0 1 0 6.6M18 14a6.5 6.5 0 0 1 3.5 5.5" />
		</>
	),
	success: (
		<>
			<circle cx="12" cy="12" r="8" />
			<path d="m8.5 12.2 2.4 2.4 4.6-4.8" />
		</>
	),
	error: (
		<>
			<circle cx="12" cy="12" r="8" />
			<path d="M12 8v5M12 16h.01" />
		</>
	),
	warning: (
		<>
			<path d="M12 4.5 20 18.5H4z" />
			<path d="M12 10v3.5M12 16h.01" />
		</>
	),
	info: (
		<>
			<circle cx="12" cy="12" r="8" />
			<path d="M12 11v5M12 8h.01" />
		</>
	),
	check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
	dash: <path d="M6 12h12" />,
	close: <path d="M7 7l10 10M17 7 7 17" />,
	chevron: <path d="M6 9l6 6 6-6" />,
	external: (
		<path d="M14 5h5v5M19 5l-8 8M17 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h4" />
	),
	copy: (
		<>
			<rect x="9" y="9" width="11" height="11" rx="2" />
			<path d="M5 15V6a1 1 0 0 1 1-1h9" />
		</>
	),
	search: (
		<>
			<circle cx="10.5" cy="10.5" r="6" />
			<path d="M15 15l5 5" />
		</>
	),
	refresh: (
		<>
			<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3L19.5 9" />
			<path d="M19.5 4.5V9H15" />
		</>
	),
	cart: (
		<>
			<path d="M3.5 4.5h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h7.9a1.5 1.5 0 0 0 1.4-1.1l1.5-5.8H6.4" />
			<circle cx="9.5" cy="19.5" r="1" />
			<circle cx="17" cy="19.5" r="1" />
		</>
	),
	download: (
		<>
			<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5" />
			<path d="M5 19.5h14" />
		</>
	),
	dot: <circle cx="12" cy="12" r="3" />,
};

/**
 * Render the Loggedin mark: a white padlock on a black rounded square.
 *
 * A placeholder in black and white until Loggedin has a real logo.
 *
 * @return {Element} SVG element.
 */
export function Logo() {
	return (
		<svg
			width="32"
			height="32"
			viewBox="0 0 32 32"
			aria-hidden="true"
			focusable="false"
		>
			<rect width="32" height="32" rx="8" fill="#000" />
			<path
				d="M11.5 14v-2.5a4.5 4.5 0 0 1 9 0V14"
				fill="none"
				stroke="#fff"
				strokeWidth="2.4"
				strokeLinecap="round"
			/>
			<rect x="9" y="14" width="14" height="10" rx="2.5" fill="#fff" />
			<circle cx="16" cy="19" r="1.7" fill="#000" />
		</svg>
	);
}
