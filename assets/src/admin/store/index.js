/**
 * Store registration barrel.
 *
 * Importing this file once at boot triggers the side-effect imports of
 * every `@wordpress/data` store the plugin ships, so any
 * `useSelect( select => select( STORE_KEY )... )` call elsewhere in
 * the React tree resolves against an already-registered store.
 *
 * Keep this list short — only register stores whose data is shared
 * across components or whose async resolution needs to be cached. Tab-
 * local state belongs in component-level `useState` instead.
 */
import './addons';
