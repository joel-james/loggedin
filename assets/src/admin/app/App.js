/**
 * The admin app: sidebar, page header with Save, the current page and the footer.
 *
 * Settings are edited through the core-data `root/site` entity, shared
 * by every page, so changes on two pages save together. Settings pages
 * show Save in the page header, disabled until something changes
 * (use-save-bar.js). The `tab` query argument picks the page
 * (use-route.js).
 */

/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PageHeader, SectionSelect, Sidebar, Snackbars, Spinner } from '../ui';
import ErrorBoundary from './error-boundary';
import AppFooter from './footer';
import { groupsWithPages, resolvePages } from './pages';
import useRoute, { tabUrl } from './use-route';
import useSaveBar from './use-save-bar';
import { validate } from './validate';

/**
 * The admin app.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Data from PHP (version, URLs, schema, …).
 * @return {Element} App.
 */
export default function App( { boot } ) {
	const pages = useMemo(
		() => resolvePages( boot.schema, boot.roles ),
		[ boot ]
	);
	const { page, navigate } = useRoute( pages );

	const site = useSelect(
		( select ) =>
			select( coreStore ).getEditedEntityRecord( 'root', 'site' ),
		[]
	);

	const errors = useMemo(
		() =>
			validate(
				site,
				boot.schema.fields || [],
				boot.autoLogout.max,
				boot.homeUrl
			),
		[ site, boot ]
	);

	const { hasLoaded, status, actions, notice } = useSaveBar( {
		errorCount: Object.keys( errors ).length,
		saves: page.saves,
	} );

	const navGroups = groupsWithPages( pages, errors );

	const Page = page.render;
	const props = { boot, errors, navigate, hrefFor: tabUrl };

	return (
		<>
			<Sidebar
				name="Loggedin"
				label={ __( 'Loggedin sections', 'loggedin' ) }
				status={ {
					tone: 'on',
					text: sprintf(
						/* translators: %s: Plugin version. */
						__( 'On · %s', 'loggedin' ),
						boot.version
					),
				} }
				groups={ navGroups }
				current={ page.id }
				hrefFor={ tabUrl }
				onNavigate={ navigate }
			/>
			<div className="loggedin-main">
				<PageHeader
					title={ page.title }
					nav={
						<SectionSelect
							groups={ navGroups }
							current={ page.id }
							onNavigate={ navigate }
						/>
					}
					status={ status }
					actions={ actions }
				/>
				<main
					className={ `loggedin-content${
						page.wide ? ' loggedin-content--wide' : ''
					}` }
				>
					{ notice }
					{ hasLoaded ? (
						<ErrorBoundary key={ page.id }>
							{ Page && <Page { ...props } /> }
							{ page.panels.map( ( { id, Component, legacy } ) =>
								legacy ? (
									<div
										key={ id }
										className="loggedin-legacy-panel"
									>
										<Component />
									</div>
								) : (
									<Component key={ id } errors={ errors } />
								)
							) }
						</ErrorBoundary>
					) : (
						<div className="loggedin-loading">
							<Spinner />
						</div>
					) }
				</main>
				<AppFooter boot={ boot } />
			</div>
			<Snackbars />
		</>
	);
}
