/**
 * Loggedin's own notices, drawn inside the screen with the kit's Notice.
 *
 * PHP adds them to the `notices` script var (`loggedin_admin_script_vars`)
 * instead of printing WordPress admin notices, which sit above the screen.
 * Each action is a link: answering a notice reloads the page.
 */

/**
 * Internal dependencies
 */
import { Button, Notice } from '../ui';

/**
 * The notices above every page.
 *
 * @param {Object}        props         Props.
 * @param {Array<Object>} props.notices Notices from PHP: `id`, `tone`, `title`, and optionally `text`, `items` and `actions` (`label`, `href`, `primary`).
 * @return {Element|null} Notices.
 */
export default function ScreenNotices( { notices } ) {
	if ( ! notices?.length ) {
		return null;
	}

	return notices.map( ( { id, tone, title, text, items, actions } ) => (
		<Notice
			key={ id }
			tone={ tone }
			title={ title }
			actions={
				actions?.length
					? actions.map( ( action ) => (
							<Button
								key={ action.href }
								variant={
									action.primary ? 'primary' : 'secondary'
								}
								size="compact"
								href={ action.href }
							>
								{ action.label }
							</Button>
					  ) )
					: null
			}
		>
			{ text }
			{ items?.length > 0 && (
				<ul className="loggedin-notice__list">
					{ items.map( ( item ) => (
						<li key={ item }>{ item }</li>
					) ) }
				</ul>
			) }
		</Notice>
	) );
}
