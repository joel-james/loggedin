/**
 * Helpers shared by the kit's components. Not exported from the kit.
 */

/**
 * Whether a click is a plain left click, with no modifier key.
 *
 * Links that switch pages in place handle only these; a middle click
 * or Ctrl/Cmd/Shift/Alt click falls through to the browser, so the
 * link can still open in a new tab or window.
 *
 * @param {MouseEvent} event Click event.
 * @return {boolean} Plain left click.
 */
export const isPlainClick = ( event ) =>
	0 === event.button &&
	! event.metaKey &&
	! event.ctrlKey &&
	! event.shiftKey &&
	! event.altKey;
