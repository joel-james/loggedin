/**
 * Form fields of the Foxe Plugin Admin Kit.
 *
 * Each wraps the WordPress component the design system names, so the look
 * can change in one place. Every field takes `label`, `help`, `value`,
 * `onChange` and an optional `error`, which replaces the help line.
 */

/**
 * WordPress dependencies
 */
import {
	BaseControl,
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { Icon } from './icons';

/**
 * Render the help line, or the error in its place.
 *
 * @param {Object}         props       Props.
 * @param {string}         props.id    Element ID, for aria-describedby.
 * @param {string|Element} props.help  Help text.
 * @param {string}         props.error Error text.
 * @return {Element|null} Paragraph.
 */
function Describe( { id, help, error } ) {
	if ( error ) {
		return (
			<p className="loggedin-error" id={ id }>
				<Icon name="error" size={ 16 } />
				<span>{ error }</span>
			</p>
		);
	}

	return help ? (
		<p className="loggedin-help" id={ id }>
			{ help }
		</p>
	) : null;
}

/**
 * Build the class names of a field wrapper.
 *
 * @param {string} kind  Field kind.
 * @param {string} error Error text.
 * @param {string} extra Extra classes.
 * @return {string} Class names.
 */
function fieldClass( kind, error, extra = '' ) {
	return [
		'loggedin-field',
		`loggedin-field--${ kind }`,
		error && 'loggedin-field--invalid',
		extra,
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * Turn one behaviour on or off.
 *
 * @param {Object}                     props          Props.
 * @param {string}                     props.label    What the ON state means.
 * @param {string}                     [props.help]   What changes for readers.
 * @param {boolean}                    props.checked  State.
 * @param {(checked: boolean) => void} props.onChange Receives the new state.
 * @return {Element} Toggle.
 */
export function Toggle( { label, help, checked, onChange } ) {
	return (
		<div className="loggedin-toggle">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ !! checked }
				onChange={ onChange }
			/>
		</div>
	);
}

/**
 * A whole number with its unit inside the field ("25 comments").
 *
 * A native number input in the kit's affix frame, labelled by WordPress's
 * BaseControl: the stable components have no suffix slot. The value passed
 * to onChange is a number, or '' while the field is empty, so validation
 * can say what to enter.
 *
 * @param {Object}                         props          Props.
 * @param {string}                         props.label    Label.
 * @param {string}                         [props.unit]   Plural noun shown after the number.
 * @param {string}                         [props.help]   What the number does.
 * @param {number}                         props.value    Value.
 * @param {number}                         props.min      Minimum.
 * @param {number}                         props.max      Maximum.
 * @param {string}                         [props.error]  Error text.
 * @param {(value: number|string) => void} props.onChange Receives the new value.
 * @return {Element} Field.
 */
export function NumberField( {
	label,
	unit,
	help,
	value,
	min,
	max,
	error,
	onChange,
} ) {
	const id = useInstanceId( NumberField, 'loggedin-number' );

	return (
		<div className={ fieldClass( 'number', error ) }>
			<BaseControl __nextHasNoMarginBottom id={ id } label={ label }>
				<div
					className={ `loggedin-affix${
						error ? ' loggedin-affix--invalid' : ''
					}` }
				>
					<input
						id={ id }
						type="number"
						inputMode="numeric"
						value={ value }
						min={ min }
						max={ max }
						step={ 1 }
						aria-invalid={ !! error }
						aria-describedby={
							unit
								? `${ id }-unit ${ id }-describe`
								: `${ id }-describe`
						}
						onChange={ ( event ) =>
							onChange(
								'' === event.target.value
									? ''
									: Number( event.target.value )
							)
						}
					/>
					{ unit && (
						<span
							className="loggedin-affix__unit"
							id={ `${ id }-unit` }
						>
							{ unit }
						</span>
					) }
				</div>
			</BaseControl>
			<Describe id={ `${ id }-describe` } help={ help } error={ error } />
		</div>
	);
}

/**
 * A single-line text input.
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    Label.
 * @param {string}                  [props.help]   Help.
 * @param {string}                  props.value    Value.
 * @param {string}                  [props.error]  Error text.
 * @param {(value: string) => void} props.onChange Receives the new value.
 * @return {Element} Field.
 */
export function TextField( { label, help, value, error, onChange } ) {
	const id = useInstanceId( TextField, 'loggedin-text' );

	return (
		<div className={ fieldClass( 'text', error ) }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ id }
				label={ label }
				value={ value }
				aria-invalid={ !! error }
				aria-describedby={ `${ id }-describe` }
				onChange={ onChange }
			/>
			<Describe id={ `${ id }-describe` } help={ help } error={ error } />
		</div>
	);
}

/**
 * Pick one value from a plain list.
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    Label.
 * @param {string}                  [props.help]   Help.
 * @param {string}                  props.value    Value.
 * @param {Array}                   props.options  [{ value, label }].
 * @param {string}                  [props.error]  Error text.
 * @param {(value: string) => void} props.onChange Receives the new value.
 * @return {Element} Field.
 */
export function SelectField( {
	label,
	help,
	value,
	options,
	error,
	onChange,
} ) {
	const id = useInstanceId( SelectField, 'loggedin-select' );

	return (
		<div className={ fieldClass( 'select', error ) }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ id }
				label={ label }
				value={ value }
				options={ options }
				aria-invalid={ !! error }
				aria-describedby={ `${ id }-describe` }
				onChange={ onChange }
			/>
			<Describe id={ `${ id }-describe` } help={ help } error={ error } />
		</div>
	);
}

/**
 * Pick several values from a list.
 *
 * @param {Object}                     props          Props.
 * @param {string}                     props.label    Group label.
 * @param {string}                     [props.help]   Help.
 * @param {string[]}                   props.value    Checked values.
 * @param {Array}                      props.options  [{ value, label }].
 * @param {string}                     [props.error]  Error text.
 * @param {(values: string[]) => void} props.onChange Receives the new list, in option order.
 * @return {Element} Field.
 */
export function CheckboxList( {
	label,
	help,
	value,
	options,
	error,
	onChange,
} ) {
	const id = useInstanceId( CheckboxList, 'loggedin-checks' );
	const checked = new Set( value || [] );

	return (
		<fieldset
			className={ fieldClass( 'checks', error, 'loggedin-checks' ) }
			aria-describedby={ `${ id }-describe` }
		>
			<legend className="loggedin-label">{ label }</legend>
			<div className="loggedin-checks__items">
				{ options.map( ( option ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ option.value }
						label={ option.label }
						checked={ checked.has( option.value ) }
						onChange={ ( on ) => {
							const next = new Set( checked );

							if ( on ) {
								next.add( option.value );
							} else {
								next.delete( option.value );
							}

							onChange(
								options
									.map( ( item ) => item.value )
									.filter( ( item ) => next.has( item ) )
							);
						} }
					/>
				) ) }
			</div>
			<Describe id={ `${ id }-describe` } help={ help } error={ error } />
		</fieldset>
	);
}

/**
 * Pick one mode where each option needs a sentence: a radio group of tiles.
 *
 * Native radios, so arrow keys move between tiles.
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    Group label.
 * @param {string}                  props.value    Selected value.
 * @param {Array}                   props.options  [{ value, label, description }].
 * @param {(value: string) => void} props.onChange Receives the new value.
 * @return {Element} Field.
 */
export function ChoiceTiles( { label, value, options, onChange } ) {
	const name = useInstanceId( ChoiceTiles, 'loggedin-tiles' );

	return (
		<fieldset
			className={ `loggedin-tiles${
				4 === options.length ? ' loggedin-tiles--pairs' : ''
			}` }
		>
			<legend className="loggedin-label">{ label }</legend>
			{ options.map( ( option ) => {
				const on = option.value === value;

				return (
					<label
						key={ option.value }
						htmlFor={ `${ name }-${ option.value }` }
						className={ `loggedin-tile${
							on ? ' loggedin-tile--on' : ''
						}` }
					>
						<span className="loggedin-tile__head">
							<input
								id={ `${ name }-${ option.value }` }
								type="radio"
								name={ name }
								value={ option.value }
								checked={ on }
								onChange={ () => onChange( option.value ) }
							/>
							{ option.label }
						</span>
						<span className="loggedin-tile__desc">
							{ option.description }
						</span>
					</label>
				);
			} ) }
		</fieldset>
	);
}
