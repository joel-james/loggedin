/**
 * Settings that add-ons declare in PHP (`loggedin_settings_schema`),
 * drawn with the kit's controls.
 *
 * Values live in the site option each field names (default: the core
 * `loggedin_settings`), edited through core-data, so the page header's
 * Save saves them with everything else. The option must be registered
 * with `show_in_rest`.
 *
 * A field with a `key` edits that key of an object option. A field
 * without one edits the whole option: a single value, or for
 * `role-table` a `{ role: number }` map.
 */
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { Card, NumberField, SelectField, TextField, Toggle } from '../ui';
import useOption from '../hooks/use-option';
import { RoleTable } from './role-table';

/**
 * The option a field edits when it names none.
 *
 * @type {string}
 */
const CORE_OPTION = 'loggedin_settings';

/**
 * The option a field edits: its own `option`, else the core settings.
 *
 * @param {Object} field Field declaration.
 * @return {string} Option name.
 */
export const optionOf = ( field ) => field.option || CORE_OPTION;

/**
 * Error key for a field: `option.key`, or the option alone.
 *
 * @param {Object} field Field declaration.
 * @return {string} Key.
 */
export const errorKey = ( field ) => {
	const option = optionOf( field );

	if ( 'role-table' === field.type ) {
		return `${ option }.`;
	}

	return undefined === field.key ? option : `${ option }.${ field.key }`;
};

/**
 * A value named `option.key` in the edited site settings, if it's a number.
 *
 * @param {Object} site Edited `root/site` record.
 * @param {string} path `option.key`, e.g. `loggedin_settings.maximum`.
 * @return {number|undefined} Value.
 */
const fromOption = ( site, path ) => {
	if ( ! path ) {
		return undefined;
	}

	const [ option, key ] = path.split( '.' );
	const value = Number( site?.[ option ]?.[ key ] );

	return Number.isInteger( value ) ? value : undefined;
};

/**
 * Read and edit one field's value, in an object option or a whole one.
 *
 * `keyed` reads any key of the same option, for `show_if`.
 *
 * @param {Object} field Field declaration.
 * @return {{ get: Function, set: Function, values: Object, keyed: Object }} Accessors.
 */
function useFieldValue( field ) {
	const option = optionOf( field );
	const keyed = useOption( option );
	const [ whole, setWhole ] = useEntityProp( 'root', 'site', option );

	if ( undefined !== field.key ) {
		return { ...keyed, keyed };
	}

	return {
		values: keyed.values,
		get: ( key, fallback ) => ( undefined === whole ? fallback : whole ),
		set: ( key, value ) => setWhole( value ),
		keyed,
	};
}

/**
 * One declared field.
 *
 * @param {Object} props        Props.
 * @param {Object} props.field  Field declaration.
 * @param {Object} props.errors Errors by `option.key`.
 * @param {Object} props.roles  Every role: { slug: name }.
 * @return {Element|null} Field.
 */
function SchemaField( { field, errors, roles } ) {
	const { get, set, values, keyed } = useFieldValue( field );

	/*
	 * Select the one number a role table falls back to, not the whole
	 * edited record: that record changes on every keystroke anywhere on
	 * the screen, and would re-render every field with it.
	 */
	const inherited = useSelect(
		( select ) =>
			fromOption(
				select( coreStore ).getEditedEntityRecord( 'root', 'site' ),
				field.default_from
			),
		[ field.default_from ]
	);

	if (
		field.show_if &&
		keyed.get( field.show_if.key ) !== field.show_if.equals
	) {
		return null;
	}

	switch ( field.type ) {
		case 'role-table':
			return (
				<RoleTable
					value={ values }
					roles={ roles }
					fallback={ inherited ?? field.default ?? field.min ?? 1 }
					help={ field.help }
					errors={ errors }
					option={ optionOf( field ) }
					label={ field.label }
					unit={ field.unit }
					min={ field.min ?? 1 }
					max={ field.max }
					addLabel={ field.add_label }
					onChange={ ( map ) => set( undefined, map ) }
				/>
			);

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
					unit={
						1 === Number( get( field.key, field.default ) ) &&
						field.unit_one
							? field.unit_one
							: field.unit
					}
					help={ field.help }
					min={ field.min }
					max={ field.max }
					value={ get( field.key, field.default ?? '' ) }
					error={ errors[ errorKey( field ) ] }
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
 * @param {Object} props.roles  Every role: { slug: name }.
 * @return {Element} Card.
 */
export function SchemaCard( { card, fields, errors, roles } ) {
	return (
		<Card title={ card.title } description={ card.description }>
			{ fields
				.filter( ( field ) => field.card === card.id )
				.map( ( field ) => (
					<SchemaField
						key={ `${ field.option || '' }:${ field.key }` }
						field={ field }
						errors={ errors }
						roles={ roles }
					/>
				) ) }
		</Card>
	);
}
