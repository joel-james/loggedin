/**
 * `react-jsx-runtime` for WordPress 6.5.
 *
 * Bundles built with `@wordpress/scripts` 28+ use React's automatic JSX
 * runtime and depend on the `react-jsx-runtime` script, which WordPress
 * registers from 6.6. On 6.5 that dependency is missing, so WordPress
 * drops the bundle and the screen stays blank. `Admin\Assets` registers
 * this file under that handle only when WordPress hasn't, so Loggedin's
 * bundle and add-on bundles on its screen load on 6.5 too.
 *
 * `jsx()` and `jsxs()` take the key apart from the props; `createElement()`
 * takes it inside them. Children are already in `props.children`.
 *
 * @param {Object} React The `react` script's global.
 */
( function ( React ) {
	const jsx = ( type, props, key ) =>
		React.createElement(
			type,
			undefined === key ? props : { ...props, key }
		);

	window.ReactJSXRuntime = {
		jsx,
		jsxs: jsx,
		Fragment: React.Fragment,
	};
} )( window.React );
