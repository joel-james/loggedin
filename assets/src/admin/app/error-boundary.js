/**
 * React error boundary around each page of the admin screen.
 *
 * Catches render-time errors in a page (core's or an add-on's) and
 * shows an inline notice instead of letting the whole app unmount to
 * a blank page. The sidebar stays usable, so the other pages still
 * work.
 *
 * Without this, a single undefined component — e.g. a
 * `@wordpress/components` `__experimental*` export that isn't
 * present in the WordPress-bundled version on a given site — throws
 * during render and React tears down the entire root, leaving a
 * white screen.
 *
 * Error boundaries have to be class components: there is no hook
 * equivalent for `getDerivedStateFromError` / `componentDidCatch`.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '../ui';

class ErrorBoundary extends Component {
	/**
	 * Seed the boundary with no captured error.
	 *
	 * @param {Object} props Standard React props.
	 */
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	/**
	 * Lifecycle hook called by React when a descendant throws during
	 * render. Returns the next state — we store the error so `render`
	 * can switch to the fallback UI on the same commit.
	 *
	 * @param {Error} error The thrown value.
	 *
	 * @return {{error: Error}} Next state for this boundary.
	 */
	static getDerivedStateFromError( error ) {
		return { error };
	}

	/**
	 * Side-effect hook for the same throw. We don't need to update
	 * state again — `getDerivedStateFromError` already did — but we
	 * do want a console trace so developers can see the full stack
	 * + component path in dev tools.
	 *
	 * @param {Error}  error The thrown value.
	 * @param {Object} info  React-supplied `componentStack` debug payload.
	 */
	componentDidCatch( error, info ) {
		// eslint-disable-next-line no-console
		console.error( 'Loggedin admin error:', error, info );
	}

	/**
	 * Render either the children (steady state) or the fallback
	 * notice (post-throw).
	 */
	render() {
		const { error } = this.state;

		if ( error ) {
			return (
				<Notice
					tone="error"
					title={ __(
						'Something went wrong while rendering this page.',
						'loggedin'
					) }
				>
					{ error.message || String( error ) }
				</Notice>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
