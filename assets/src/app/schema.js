/**
 * Settings that add-ons declare in PHP (`loggedin_settings_schema`),
 * drawn with the kit's controls.
 *
 * Values live in the site option each field names (default: the core
 * `loggedin_settings`), edited through core-data, so the page header's
 * Save saves them with everything else. The option must be registered
 * with `show_in_rest`.
 */
import { Card, NumberField, SelectField, TextField, Toggle } from '../ui';
import useOption from '../hooks/use-option';

const CORE_OPTION = 'loggedin_settings';

/**
 * One declared field.
 *
 * @param {Object} props        Props.
 * @param {Object} props.field  Field declaration.
 * @param {Object} props.errors Errors by `option.key`.
 * @return {Element|null} Field.
 */
function SchemaField( { field, errors } ) {
	const option = field.option || CORE_OPTION;
	const { get, set } = useOption( option );

	if ( field.show_if && get( field.show_if.key ) !== field.show_if.equals ) {
		return null;
	}

	switch ( field.type ) {
		case 'toggle':
			return (
				<Toggle
					label={ field.label }
					help={ field.help }
					checked={
						!! Number( get( field.key, field.default ?? 0 ) )
					}
					onChange={ ( on ) => set( field.key, on ? 1 : 0 ) }
				/>
			);

		case 'select': {
			const options = field.options || [];
			const value = String(
				get( field.key, field.default ?? options[ 0 ]?.value ?? '' )
			);
			const chosen = options.find( ( o ) => String( o.value ) === value );

			return (
				<SelectField
					label={ field.label }
					help={ chosen?.help || field.help }
					value={ value }
					options={ options.map( ( o ) => ( {
						value: String( o.value ),
						label: o.label,
					} ) ) }
					onChange={ ( next ) =>
						set(
							field.key,
							'int' === field.cast ? Number( next ) : next
						)
					}
				/>
			);
		}

		case 'number':
			return (
				<NumberField
					label={ field.label }
					unit={ field.unit }
					help={ field.help }
					min={ field.min }
					max={ field.max }
					value={ get( field.key, field.default ?? '' ) }
					error={ errors[ `${ option }.${ field.key }` ] }
					onChange={ ( next ) => set( field.key, next ) }
				/>
			);

		default:
			return (
				<TextField
					label={ field.label }
					help={ field.help }
					value={ get( field.key, field.default ?? '' ) }
					onChange={ ( next ) => set( field.key, next ) }
				/>
			);
	}
}

/**
 * A card declared in PHP, with its fields.
 *
 * @param {Object} props        Props.
 * @param {Object} props.card   Card declaration: { id, title, description }.
 * @param {Array}  props.fields Every declared field.
 * @param {Object} props.errors Errors by `option.key`.
 * @return {Element} Card.
 */
export function SchemaCard( { card, fields, errors } ) {
	return (
		<Card title={ card.title } description={ card.description }>
			{ fields
				.filter( ( field ) => field.card === card.id )
				.map( ( field ) => (
					<SchemaField
						key={ `${ field.option || '' }:${ field.key }` }
						field={ field }
						errors={ errors }
					/>
				) ) }
		</Card>
	);
}
