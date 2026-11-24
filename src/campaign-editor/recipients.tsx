import { Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Modal,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

export type Rule = { list: number; tag: number; segment: number | string };
export type Term = { id: number; name: string };
export type Segment = { ref: string; name: string; is_system: boolean };
export type ConditionValue = string | number | Array< string | number > | null;
export type Condition = { provider: string; field: string; operator: string; value: ConditionValue };
export type FilterPlan = { version: 1; groups: Condition[][] };
export type Scope = 'rules' | 'segments' | 'advanced' | 'all';
export type Audience = {
	scope: Scope;
	include: Rule[];
	exclude: Rule[];
	segment?: number | string;
	filters?: FilterPlan;
};
export type FieldDefinition = {
	provider: string;
	field: string;
	label: string;
	type: string;
	ops: string[];
	options?: Record< string, string >;
	term_type?: string;
	number_kind?: string;
	lookup?: boolean;
	help?: string;
};
export type AdvancedCatalog = {
	fields: Record< string, FieldDefinition >;
	operators: Record< string, string >;
	providers: Record< string, string >;
	limits: { groups: number; conditions: number; values: number; days: number };
};
export type Recipient = { id: number; email: string; name: string };
export type RecipientPage = { count: number; page: number; per_page: number; recipients: Recipient[] };
export type RecipientCount = { count: number };
export type RecipientLookup = { value: string; label: string };

type Lookup = ( field: string, search: string, selected: string[] ) => Promise< { options: RecipientLookup[] } >;

const MULTI_OPERATORS: Record< string, true > = { in: true, not_in: true, in_all: true, not_in_all: true };
const VALUELESS_OPERATORS: Record< string, true > = { is_null: true, not_null: true };
const STALE_LABEL = __( '（已失效）', 'now-crm' );

function segmentLabelFor( value: number | string, segments: Segment[] ): string | null {
	const match = segments.find( ( segment ) => String( segment.ref ) === String( value ) );
	return match ? match.name : null;
}

function meaningfulRule( row: Rule ): boolean {
	return row.list > 0 || row.tag > 0 || ( row.segment !== 0 && row.segment !== '' );
}

const EMPTY_RULE: Rule = { list: 0, tag: 0, segment: 0 };

/**
 * Searchable single choice backed by a finite local option list.
 * A current value that no longer exists stays visible with a stale label.
 */
function TermChoice( { label, value, options, placeholder, busy, hideLabel, onChange }: {
	label: string;
	value: string;
	options: Array<{ value: string; label: string }>;
	placeholder?: string;
	busy: boolean;
	hideLabel?: boolean;
	onChange: ( value: string ) => void;
} ) {
	const known = options.some( ( option ) => option.value === value );
	const extended = value && ! known
		? [ ...options, { value, label: value + STALE_LABEL } ]
		: options;
	return (
		<ComboboxControl
			label={ label }
			hideLabelFromVision={ hideLabel }
			value={ value || null }
			options={ extended }
			placeholder={ placeholder }
			__nextHasNoMarginBottom
			onChange={ ( next ) => {
				if ( ! busy ) {
					onChange( next ?? '' );
				}
			} }
		/>
	);
}

function RuleTable( { rows, lists, tags, segments, busy, section, showSegment, onToggleSegment, onChange }: {
	rows: Rule[];
	lists: Term[];
	tags: Term[];
	segments: Segment[];
	busy: boolean;
	section: 'include' | 'exclude';
	showSegment: boolean;
	onToggleSegment?: () => void;
	onChange: ( rows: Rule[] ) => void;
} ) {
	const sectionLabel = section === 'include' ? __( '包含', 'now-crm' ) : __( '排除', 'now-crm' );
	const listOptions = useMemo(
		() => [ { value: '', label: __( '不限名單', 'now-crm' ) }, ...lists.map( ( term ) => ( { value: String( term.id ), label: term.name } ) ) ],
		[ lists ]
	);
	const tagOptions = useMemo(
		() => [ { value: '', label: __( '不限標籤', 'now-crm' ) }, ...tags.map( ( term ) => ( { value: String( term.id ), label: term.name } ) ) ],
		[ tags ]
	);
	const segmentOptions = useMemo( () => {
		const system = segments.filter( ( segment ) => segment.is_system );
		const custom = segments.filter( ( segment ) => ! segment.is_system );
		const group = ( groupSegments: Segment[], prefix: string ) => groupSegments.map( ( segment ) => ( {
			value: String( segment.ref ),
			label: prefix + segment.name,
		} ) );
		return [
			{ value: '', label: __( '不限分眾', 'now-crm' ) },
			...group( system, __( '系統分眾：', 'now-crm' ) ),
			...group( custom, __( '自訂分眾：', 'now-crm' ) ),
		];
	}, [ segments ] );
	const [ draft, setDraft ] = useState< Rule >( EMPTY_RULE );
	const placeholder = rows.length === 0;
	const visible = placeholder ? [ draft ] : rows;

	useEffect( () => {
		if ( rows.length > 0 ) {
			setDraft( EMPTY_RULE );
		}
	}, [ rows.length ] );

	const set = ( index: number, next: Rule ) => {
		if ( placeholder ) {
			setDraft( next );
			if ( meaningfulRule( next ) ) {
				onChange( [ next ] );
			}
			return;
		}
		onChange( rows.map( ( row, position ) => position === index ? next : row ) );
	};

	return (
		<div className="nowcrm-recipients__rule-section">
			<table className="nowcrm-recipients__rule-table">
				<thead>
					<tr>
						<th scope="col">{ __( '名單', 'now-crm' ) }</th>
						<th scope="col">{ __( '標籤', 'now-crm' ) }</th>
						{ showSegment && <th scope="col">{ __( '分眾', 'now-crm' ) }</th> }
						<th scope="col" className="nowcrm-recipients__rule-action"><span className="nowcrm-recipients__sr-only">{ __( '操作', 'now-crm' ) }</span></th>
					</tr>
				</thead>
				<tbody>
					{ visible.map( ( row, index ) => {
						const isPlaceholder = placeholder && index === 0;
						return (
							<Fragment key={ index }>
								<tr className={ isPlaceholder ? 'is-placeholder' : undefined }>
									<td data-label={ __( '名單', 'now-crm' ) }>
										<TermChoice
											hideLabel
											label={ __( '名單', 'now-crm' ) }
											value={ row.list ? String( row.list ) : '' }
											options={ listOptions }
											placeholder={ __( '不限名單', 'now-crm' ) }
											busy={ busy }
											onChange={ ( next ) => set( index, { ...row, list: next ? Number( next ) : 0 } ) }
										/>
									</td>
									<td data-label={ __( '標籤', 'now-crm' ) }>
										<TermChoice
											hideLabel
											label={ __( '標籤', 'now-crm' ) }
											value={ row.tag ? String( row.tag ) : '' }
											options={ tagOptions }
											placeholder={ __( '不限標籤', 'now-crm' ) }
											busy={ busy }
											onChange={ ( next ) => set( index, { ...row, tag: next ? Number( next ) : 0 } ) }
										/>
									</td>
									{ showSegment && (
										<td data-label={ __( '分眾', 'now-crm' ) }>
											<TermChoice
												hideLabel
												label={ __( '分眾', 'now-crm' ) }
												value={ row.segment === 0 || row.segment === '' ? '' : String( row.segment ) }
												options={ segmentOptions }
												placeholder={ __( '不限分眾', 'now-crm' ) }
												busy={ busy }
												onChange={ ( next ) => set( index, { ...row, segment: next === '' ? 0 : ( /^\d+$/.test( next ) ? Number( next ) : next ) } ) }
											/>
										</td>
									) }
									<td className="nowcrm-recipients__rule-action" data-label={ __( '操作', 'now-crm' ) }>
										<Button
											className="nowcrm-recipients__rule-remove"
											variant="tertiary"
											size="small"
											disabled={ busy || isPlaceholder }
											aria-label={ sprintf(
												/* translators: 1: include or exclude section, 2: rule number. */
												__( '移除%1$s第 %2$d 列', 'now-crm' ),
												sectionLabel,
												index + 1
											) }
											onClick={ () => onChange( rows.filter( ( _, position ) => position !== index ) ) }
										>
											<span aria-hidden="true">×</span>
										</Button>
									</td>
								</tr>
								{ ! isPlaceholder && ! meaningfulRule( row ) && (
									<tr className="nowcrm-recipients__rule-note">
										<td colSpan={ showSegment ? 4 : 3 }>
											<p className="description" role="note">{ __( '這一列尚未選擇任何條件，不會影響結果。請完成或移除。', 'now-crm' ) }</p>
										</td>
									</tr>
								) }
							</Fragment>
						);
					} ) }
				</tbody>
			</table>
			<div className="nowcrm-recipients__rule-foot">
				<Button
					variant="secondary"
					size="small"
					disabled={ busy || rows.length >= 50 }
					onClick={ () => onChange( [ ...rows, { ...EMPTY_RULE } ] ) }
				>
					{ __( '＋ 新增一列', 'now-crm' ) }
				</Button>
				{ onToggleSegment && (
					<Button variant="tertiary" size="small" disabled={ busy } onClick={ onToggleSegment }>
						{ showSegment ? __( '隱藏分眾欄位', 'now-crm' ) : __( '顯示分眾欄位', 'now-crm' ) }
					</Button>
				) }
			</div>

		</div>
	);
}

/**
 * Static option set with local search. Multi operators render checkboxes;
 * single operators render one searchable select.
 */
function StaticSetPicker( { label, options, values, operator, maxValues, busy, onChange }: {
	label: string;
	options: Record< string, string >;
	values: string[];
	operator: string;
	maxValues: number;
	busy: boolean;
	onChange: ( values: string[] ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const entries = useMemo( () => Object.entries( options ), [ options ] );
	const visible = useMemo( () => {
		const needle = search.trim().toLowerCase();
		const matches = needle
			? entries.filter( ( [ value, optionLabel ] ) => optionLabel.toLowerCase().includes( needle ) || value.toLowerCase().includes( needle ) )
			: entries;
		return matches.slice( 0, 100 );
	}, [ entries, search ] );
	const multi = MULTI_OPERATORS[ operator ];
	const toggle = ( value: string ) => {
		if ( values.includes( value ) ) {
			onChange( values.filter( ( current ) => current !== value ) );
		} else {
			onChange( [ ...values, value ] );
		}
	};
	return (
		<div className="nowcrm-recipients__set">
			{ entries.length > 8 && (
				<SearchControl label={ sprintf( __( '搜尋%s', 'now-crm' ), label ) } hideLabelFromVision value={ search } onChange={ setSearch } placeholder={ __( '輸入關鍵字篩選…', 'now-crm' ) } __nextHasNoMarginBottom />
			) }
			{ multi ? (
				<div className="nowcrm-recipients__checks" role="group" aria-label={ label }>
					{ visible.map( ( [ value, optionLabel ] ) => (
						<CheckboxControl
							key={ value }
							label={ optionLabel }
							checked={ values.includes( value ) }
							disabled={ busy }
							onChange={ () => toggle( value ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					{ visible.length === 0 && <p className="description">{ __( '沒有符合的選項。', 'now-crm' ) }</p> }
				</div>
			) : (
				<SelectControl
					label={ label }
					value={ values[ 0 ] ?? '' }
					options={ [ { value: '', label: __( '請選擇', 'now-crm' ) }, ...entries.map( ( [ value, optionLabel ] ) => ( { value, label: optionLabel } ) ) ] }
					disabled={ busy }
					onChange={ ( next ) => onChange( next ? [ next ] : [] ) }
					__nextHasNoMarginBottom
				/>
			) }
			{ MULTI_OPERATORS[ operator ] && values.length > maxValues && (
				<p className="nowcrm-recipients__warning" role="note">
					{ sprintf(
						/* translators: %d: maximum number of values. */
						__( '已選 %1$d 個值，一個條件最多接受 %2$d 個值。', 'now-crm' ),
						values.length,
						maxValues
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Server-backed multi picker for term and lookup fields. Search hits and the
 * labels of already-selected values come from `recipient-options`, debounced,
 * and stale responses are ignored.
 */
function RemoteSetPicker( { label, fieldKey, values, maxValues, busy, staticLabels, fetchOptions, onChange }: {
	label: string;
	fieldKey: string;
	values: string[];
	maxValues: number;
	busy: boolean;
	staticLabels: Record< string, string >;
	fetchOptions: Lookup;
	onChange: ( values: string[] ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ hits, setHits ] = useState< RecipientLookup[] >( [] );
	const [ loading, setLoading ] = useState( false );
	const [ labels, setLabels ] = useState< Record< string, string > >( staticLabels );
	const sequence = useRef( 0 );

	useEffect( () => {
		setLabels( ( previous ) => ( { ...staticLabels, ...previous } ) );
	}, [ staticLabels ] );

	useEffect( () => {
		const missing = values.filter( ( value ) => ! ( value in staticLabels ) );
		if ( missing.length === 0 ) {
			return;
		}
		const run = ++sequence.current;
		setLoading( true );
		fetchOptions( fieldKey, '', missing )
			.then( ( result ) => {
				if ( sequence.current !== run ) {
					return;
				}
				const found: Record< string, string > = {};
				for ( const option of result.options ?? [] ) {
					found[ String( option.value ) ] = option.label;
				}
				setLabels( ( previous ) => ( { ...found, ...previous } ) );
				setLoading( false );
			} )
			.catch( () => {
				if ( sequence.current === run ) {
					setLoading( false );
				}
			} );
	}, [ fieldKey, values.join( '|' ), fetchOptions, staticLabels ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const run = ++sequence.current;
		const timer = window.setTimeout( () => {
			setLoading( true );
			fetchOptions( fieldKey, search.trim(), values )
				.then( ( result ) => {
					if ( sequence.current !== run ) {
						return;
					}
					setHits( result.options ?? [] );
					const found: Record< string, string > = {};
					for ( const option of result.options ?? [] ) {
						found[ String( option.value ) ] = option.label;
					}
					setLabels( ( previous ) => ( { ...previous, ...found } ) );
					setLoading( false );
				} )
				.catch( () => {
					if ( sequence.current === run ) {
						setHits( [] );
						setLoading( false );
					}
				} );
		}, search ? 250 : 0 );
		return () => {
			window.clearTimeout( timer );
		};
	}, [ fieldKey, search, values.join( '|' ), fetchOptions ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const toggle = ( value: string ) => {
		if ( values.includes( value ) ) {
			onChange( values.filter( ( current ) => current !== value ) );
		} else {
			onChange( [ ...values, value ] );
		}
	};

	return (
		<div className="nowcrm-recipients__set">
			{ values.length > 0 && (
				<ul className="nowcrm-recipients__tokens" aria-label={ sprintf( __( '已選%s', 'now-crm' ), label ) }>
					{ values.map( ( value ) => (
						<li key={ value }>
							<span>{ labels[ value ] ?? value }</span>
							<Button variant="link" size="small" disabled={ busy } onClick={ () => toggle( value ) }>
								{ __( '移除', 'now-crm' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			<SearchControl
				label={ sprintf( __( '搜尋%s', 'now-crm' ), label ) }
				value={ search }
				onChange={ setSearch }
				placeholder={ __( '輸入關鍵字搜尋…', 'now-crm' ) }
				__nextHasNoMarginBottom
			/>
			{ loading && (
				<p className="description" role="status"><Spinner /> { __( '正在搜尋…', 'now-crm' ) }</p>
			) }
			{ ! loading && (
				<div className="nowcrm-recipients__checks" role="group" aria-label={ label }>
					{ hits.filter( ( hit ) => ! values.includes( String( hit.value ) ) ).slice( 0, 50 ).map( ( hit ) => (
						<CheckboxControl
							key={ hit.value }
							label={ hit.label }
							checked={ false }
							disabled={ busy }
							onChange={ () => toggle( String( hit.value ) ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					{ hits.length === 0 && <p className="description">{ __( '沒有符合的選項。換個關鍵字再試。', 'now-crm' ) }</p> }
				</div>
			) }
			{ values.length > maxValues && (
				<p className="nowcrm-recipients__warning" role="note">
					{ sprintf(
						/* translators: %d: maximum number of values. */
						__( '已選 %1$d 個值，一個條件最多接受 %2$d 個值。', 'now-crm' ),
						values.length,
						maxValues
					) }
				</p>
			) }
		</div>
	);
}

function ConditionValueControl( { condition, definition, catalog, busy, termLabels, fetchOptions, onChange }: {
	condition: Condition;
	definition?: FieldDefinition;
	catalog: AdvancedCatalog;
	busy: boolean;
	termLabels: Record< string, string >;
	fetchOptions: Lookup;
	onChange: ( value: ConditionValue ) => void;
} ) {
	if ( ! definition ) {
		return <p className="nowcrm-recipients__warning" role="note">{ __( '這個欄位已經失效。請選擇其他欄位，或移除此條件。', 'now-crm' ) }</p>;
	}
	if ( VALUELESS_OPERATORS[ condition.operator ] ) {
		return <p className="description">{ __( '這個運算子不需要填寫值。', 'now-crm' ) }</p>;
	}
	const raw = condition.value === null || condition.value === undefined
		? []
		: ( Array.isArray( condition.value ) ? condition.value : [ condition.value ] );
	const strings = raw.map( ( item ) => String( item ) );
	const commitSet = ( next: string[] ) => {
		if ( MULTI_OPERATORS[ condition.operator ] ) {
			onChange( next );
			return;
		}
		onChange( next.length ? next[ 0 ] : '' );
	};

	switch ( definition.type ) {
		case 'text':
		case 'custom_text':
			return (
				<TextControl
					label={ definition.label }
					value={ typeof condition.value === 'string' || typeof condition.value === 'number' ? String( condition.value ) : '' }
					disabled={ busy }
					onChange={ ( next ) => onChange( next ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'number':
		case 'custom_number':
			return (
				<TextControl
					label={ definition.label }
					type="number"
					value={ typeof condition.value === 'string' || typeof condition.value === 'number' ? String( condition.value ) : '' }
					help={ definition.number_kind === 'integer' ? __( '請輸入正整數。', 'now-crm' ) : __( '最多 14 位整數、6 位小數，可為負數。', 'now-crm' ) }
					disabled={ busy }
					onChange={ ( next ) => onChange( next ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'date':
		case 'custom_date':
			if ( condition.operator === 'days_before' || condition.operator === 'days_within' ) {
				return (
					<TextControl
						label={ sprintf( __( '%s（天）', 'now-crm' ), definition.label ) }
						type="number"
						value={ typeof condition.value === 'string' || typeof condition.value === 'number' ? String( condition.value ) : '' }
						help={ sprintf(
							/* translators: %d: maximum number of days. */
							__( '請輸入 0 到 %d 的天數。', 'now-crm' ),
							catalog.limits.days
						) }
						disabled={ busy }
						onChange={ ( next ) => onChange( next ) }
						__nextHasNoMarginBottom
					/>
				);
			}
			return (
				<TextControl
					label={ definition.label }
					type="date"
					value={ typeof condition.value === 'string' ? condition.value : '' }
					help={ __( '格式：YYYY-MM-DD。', 'now-crm' ) }
					disabled={ busy }
					onChange={ ( next ) => onChange( next ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'select':
		case 'custom_select':
		case 'custom_multi':
			if ( definition.options ) {
				return (
					<StaticSetPicker
						label={ definition.label }
						options={ definition.options }
						values={ strings }
						operator={ condition.operator }
						maxValues={ catalog.limits.values }
						busy={ busy }
						onChange={ commitSet }
					/>
				);
			}
			break;
		case 'terms':
			return (
				<RemoteSetPicker
					label={ definition.label }
					fieldKey={ definition.provider + '.' + definition.field }
					values={ strings }
					maxValues={ catalog.limits.values }
					busy={ busy }
					staticLabels={ termLabels }
					fetchOptions={ fetchOptions }
					onChange={ commitSet }
				/>
			);
		default:
			break;
	}
	if ( definition.lookup ) {
		return (
			<RemoteSetPicker
				label={ definition.label }
				fieldKey={ definition.provider + '.' + definition.field }
				values={ strings }
				maxValues={ catalog.limits.values }
				busy={ busy }
				staticLabels={ definition.options ?? {} }
				fetchOptions={ fetchOptions }
				onChange={ commitSet }
			/>
		);
	}
	if ( definition.options ) {
		return (
			<StaticSetPicker
				label={ definition.label }
				options={ definition.options }
				values={ strings }
				operator={ condition.operator }
				maxValues={ catalog.limits.values }
				busy={ busy }
				onChange={ commitSet }
			/>
		);
	}
	return (
		<TextControl
			label={ definition.label }
			value={ typeof condition.value === 'string' || typeof condition.value === 'number' ? String( condition.value ) : '' }
			disabled={ busy }
			onChange={ ( next ) => onChange( next ) }
			__nextHasNoMarginBottom
		/>
	);
}

function ConditionRow( { condition, catalog, busy, termLabels, fetchOptions, onChange, onRemove }: {
	condition: Condition;
	catalog: AdvancedCatalog;
	busy: boolean;
	termLabels: Record< string, string >;
	fetchOptions: Lookup;
	onChange: ( condition: Condition ) => void;
	onRemove: () => void;
} ) {
	const key = condition.provider && condition.field ? condition.provider + '.' + condition.field : '';
	const definition = key ? catalog.fields[ key ] : undefined;
	const providerKnown = ! condition.provider || condition.provider in catalog.providers;
	const providers = Object.entries( catalog.providers );
	const providerOptions = [
		{ value: '', label: __( '選擇來源', 'now-crm' ) },
		...providers.map( ( [ value, label ] ) => ( { value, label } ) ),
		...( ! providerKnown ? [ { value: condition.provider, label: condition.provider + STALE_LABEL } ] : [] ),
	];
	const fields = Object.entries( catalog.fields ).filter( ( [ , entry ] ) => entry.provider === condition.provider );
	const fieldOptions = [
		{ value: '', label: __( '選擇欄位', 'now-crm' ) },
		...fields.map( ( [ entryKey, entry ] ) => ( { value: entry.field, label: entry.label } ) ),
		...( condition.provider && condition.field && ! definition
			? [ { value: condition.field, label: condition.field + STALE_LABEL } ]
			: [] ),
	];
	const operatorOptions = [
		{ value: '', label: __( '選擇運算子', 'now-crm' ) },
		...( definition ? definition.ops : Object.keys( catalog.operators ) ).map( ( operator ) => ( {
			value: operator,
			label: catalog.operators[ operator ] ?? operator + STALE_LABEL,
		} ) ),
	];
	const incomplete = ! definition || ! condition.operator;

	const pickProvider = ( next: string ) => {
		const first = Object.values( catalog.fields ).find( ( entry ) => entry.provider === next );
		onChange( {
			provider: next,
			field: first ? first.field : '',
			operator: first ? first.ops[ 0 ] : '',
			value: first && VALUELESS_OPERATORS[ first.ops[ 0 ] ] ? null : '',
		} );
	};
	const pickField = ( next: string ) => {
		const entry = Object.values( catalog.fields ).find( ( candidate ) => candidate.provider === condition.provider && candidate.field === next );
		onChange( {
			provider: condition.provider,
			field: next,
			operator: entry ? entry.ops[ 0 ] : '',
			value: entry && VALUELESS_OPERATORS[ entry.ops[ 0 ] ] ? null : '',
		} );
	};
	const pickOperator = ( next: string ) => {
		onChange( {
			...condition,
			operator: next,
			value: VALUELESS_OPERATORS[ next ] ? null : ( condition.value === null ? '' : condition.value ),
		} );
	};

	return (
		<div className="nowcrm-recipients__condition">
			<div className="nowcrm-recipients__condition-fields">
				<SelectControl
					label={ __( '來源', 'now-crm' ) }
					value={ condition.provider }
					options={ providerOptions }
					disabled={ busy }
					onChange={ pickProvider }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( '欄位', 'now-crm' ) }
					value={ condition.field }
					options={ fieldOptions }
					disabled={ busy || ! condition.provider }
					onChange={ pickField }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( '運算子', 'now-crm' ) }
					value={ condition.operator }
					options={ operatorOptions }
					disabled={ busy || ! definition }
					onChange={ pickOperator }
					__nextHasNoMarginBottom
				/>
				<div className="nowcrm-recipients__condition-value">
					<ConditionValueControl
						condition={ condition }
						definition={ definition }
						catalog={ catalog }
						busy={ busy }
						termLabels={ termLabels }
						fetchOptions={ fetchOptions }
						onChange={ ( value ) => onChange( { ...condition, value } ) }
					/>
				</div>
			</div>
			{ incomplete && (
				<p className="nowcrm-recipients__warning" role="note">
					{ __( '此條件尚未完成。請選滿來源、欄位與運算子，否則預覽與寄送會將其視為錯誤。', 'now-crm' ) }
				</p>
			) }
			<Button variant="secondary" size="small" disabled={ busy } onClick={ onRemove }>
				{ __( '移除條件', 'now-crm' ) }
			</Button>
		</div>
	);
}

function AdvancedBuilder( { filters, catalog, busy, termLabels, fetchOptions, onChange, onApply }: {
	filters: FilterPlan;
	catalog: AdvancedCatalog;
	busy: boolean;
	termLabels: Record< string, string >;
	fetchOptions: Lookup;
	onChange: ( filters: FilterPlan ) => void;
	onApply: () => void;
} ) {
	const groups = filters.groups;
	const limits = catalog.limits;
	const setGroup = ( index: number, next: Condition[] ) => onChange( {
		...filters,
		groups: groups.map( ( group, position ) => position === index ? next : group ),
	} );
	const addGroup = () => {
		const first = Object.values( catalog.fields )[ 0 ];
		const initial: Condition = first
			? { provider: first.provider, field: first.field, operator: first.ops[ 0 ] ?? '', value: VALUELESS_OPERATORS[ first.ops[ 0 ] ] ? null : '' }
			: { provider: '', field: '', operator: '', value: '' };
		onChange( { ...filters, groups: [ ...groups, [ initial ] ] } );
	};
	const removeCondition = ( groupIndex: number, position: number ) => {
		const group = groups[ groupIndex ];
		if ( group.length <= 1 ) {
			onChange( { ...filters, groups: groups.filter( ( _, at ) => at !== groupIndex ) } );
			return;
		}
		setGroup( groupIndex, group.filter( ( _, at ) => at !== position ) );
	};

	return (
		<div className="nowcrm-recipients__advanced">
			<p className="description">
				{ __( '同一群組內的條件為「且」；符合任一「或」群組即可入選。按「篩選」計算符合人數。', 'now-crm' ) }
			</p>
			{ groups.length === 0 && (
				<fieldset className="nowcrm-recipients__group nowcrm-recipients__group--virtual">
					<legend>
						{ sprintf(
							/* translators: %d: group number. */
							__( '條件群組 %d（且）', 'now-crm' ),
							1
						) }
					</legend>
					<div className="nowcrm-recipients__group-actions">
						<Button
							variant="secondary"
							size="small"
							disabled={ busy || groups.length >= limits.groups }
							onClick={ addGroup }
						>
							{ __( '＋ 新增條件', 'now-crm' ) }
						</Button>
					</div>
				</fieldset>
			) }
			{ groups.map( ( group, index ) => (
				<Fragment key={ index }>
					{ index > 0 && (
						<div className="nowcrm-recipients__or" aria-hidden="true">
							<span>{ __( '或', 'now-crm' ) }</span>
						</div>
					) }
					<fieldset className="nowcrm-recipients__group">
						<legend>
							{ sprintf(
								/* translators: %d: group number. */
								__( '條件群組 %d（且）', 'now-crm' ),
								index + 1
							) }
						</legend>
						{ group.length === 0 && (
							<p className="nowcrm-recipients__warning" role="note">
								{ __( '這個群組還沒有條件。請加入條件，或移除這個群組。', 'now-crm' ) }
							</p>
						) }
						{ group.map( ( condition, position ) => (
							<Fragment key={ position }>
								{ position > 0 && (
									<p className="nowcrm-recipients__and" aria-hidden="true">{ __( '且', 'now-crm' ) }</p>
								) }
								<ConditionRow
									condition={ condition }
									catalog={ catalog }
									busy={ busy }
									termLabels={ termLabels }
									fetchOptions={ fetchOptions }
									onChange={ ( next ) => setGroup( index, group.map( ( current, at ) => at === position ? next : current ) ) }
									onRemove={ () => removeCondition( index, position ) }
								/>
							</Fragment>
						) ) }
						<div className="nowcrm-recipients__group-actions">
							<Button
								variant="secondary"
								size="small"
								disabled={ busy || group.length >= limits.conditions }
								onClick={ () => setGroup( index, [ ...group, { provider: '', field: '', operator: '', value: '' } ] ) }
							>
								{ __( '＋ 新增條件', 'now-crm' ) }
							</Button>
							<Button
								variant="link"
								size="small"
								isDestructive
								disabled={ busy }
								onClick={ () => onChange( { ...filters, groups: groups.filter( ( _, at ) => at !== index ) } ) }
							>
								{ __( '移除群組', 'now-crm' ) }
							</Button>
						</div>
					</fieldset>
				</Fragment>
			) ) }
			<div className="nowcrm-recipients__or-action">
				<span className="nowcrm-recipients__or-rule" aria-hidden="true" />
				<Button
					variant="tertiary"
					size="small"
					disabled={ busy || groups.length >= limits.groups }
					onClick={ addGroup }
				>
					{ __( '＋ 或', 'now-crm' ) }
					<span className="nowcrm-recipients__sr-only">{ __( '（新增條件群組）', 'now-crm' ) }</span>
				</Button>
				<span className="nowcrm-recipients__or-rule" aria-hidden="true" />
			</div>
			<div className="nowcrm-recipients__filter-actions">
				<div className="nowcrm-recipients__filter-submit">
					<Button
						variant="secondary"
						size="small"
						disabled={ busy || groups.length === 0 }
						onClick={ () => onChange( { ...filters, groups: [] } ) }
					>
						{ __( '清除篩選', 'now-crm' ) }
					</Button>
					<Button variant="primary" size="small" disabled={ busy } onClick={ onApply }>
						{ __( '篩選', 'now-crm' ) }
					</Button>
				</div>
			</div>
			{ groups.length > 0 && (
				<p className="description">
					{ sprintf(
						/* translators: 1: group count, 2: group limit, 3: condition limit. */
						__( '目前 %1$d 組（上限 %2$d 組），每組最多 %3$d 個條件。', 'now-crm' ),
						groups.length,
						limits.groups,
						limits.conditions
					) }
				</p>
			) }
		</div>
	);
}

function errorMessageOf( reason: unknown, fallback: string ): string {
	if ( reason && typeof reason === 'object' && 'message' in reason && typeof reason.message === 'string' && reason.message ) {
		return reason.message;
	}
	return fallback;
}

/**
 * Count-only audience for the estimate strip. Inactive selections and blank
 * include rows are dropped so they never change the checked query. Exclusion
 * rows stay intact because they do change the selected set.
 */
function countSnapshot( audience: Audience ): Audience | null {
	switch ( audience.scope ) {
		case 'rules': {
			const include = audience.include.filter( meaningfulRule );
			return include.length > 0 ? { scope: 'rules', include, exclude: audience.exclude } : null;
		}
		case 'segments':
			return audience.segment === undefined || audience.segment === 0 || audience.segment === ''
				? null
				: { scope: 'segments', segment: audience.segment, include: [], exclude: audience.exclude };
		case 'advanced':
			return { scope: 'advanced', filters: audience.filters ?? { version: 1, groups: [] }, include: [], exclude: audience.exclude };
		default:
			return { scope: 'all', include: [], exclude: audience.exclude };
	}
}

type EstimateState = { phase: 'idle' | 'loading' | 'ready' | 'error'; count: number | null; error: string };
type ListState = { phase: 'loading' | 'ready' | 'error'; error: string };

function RecipientEstimate( { audience, busy, applyToken, fetchRecipientCount, fetchRecipients }: {
	audience: Audience;
	busy: boolean;
	applyToken: number;
	fetchRecipientCount: ( audience: Audience, signal: AbortSignal ) => Promise< RecipientCount >;
	fetchRecipients: ( audience: Audience, page: number ) => Promise< RecipientPage >;
} ) {
	const snapshot = countSnapshot( audience );
	const key = snapshot ? JSON.stringify( snapshot ) : '';
	const [ estimate, setEstimate ] = useState< EstimateState >( { phase: 'idle', count: null, error: '' } );
	const [ listOpen, setListOpen ] = useState( false );
	const [ list, setList ] = useState< ListState >( { phase: 'loading', error: '' } );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( 20 );
	const [ total, setTotal ] = useState< number | null >( null );
	const [ rows, setRows ] = useState< Recipient[] >( [] );
	const countSeq = useRef( 0 );
	const listSeq = useRef( 0 );
	const countAbort = useRef< AbortController | null >( null );
	const listAbort = useRef< AbortController | null >( null );
	const appliedToken = useRef( 0 );

	const runCount = ( target: Audience ) => {
		countAbort.current?.abort();
		const controller = new AbortController();
		countAbort.current = controller;
		const run = ++countSeq.current;
		setEstimate( { phase: 'loading', count: null, error: '' } );
		fetchRecipientCount( target, controller.signal )
			.then( ( result ) => {
				if ( countSeq.current === run ) {
					setEstimate( { phase: 'ready', count: result.count, error: '' } );
				}
			} )
			.catch( ( reason: unknown ) => {
				if ( countSeq.current !== run || controller.signal.aborted ) {
					return;
				}
				setEstimate( { phase: 'error', count: null, error: errorMessageOf( reason, __( '無法計算符合人數，請再試一次。', 'now-crm' ) ) } );
			} );
	};

	const retryCount = () => {
		const target = key ? JSON.parse( key ) as Audience : null;
		if ( target ) {
			runCount( target );
		}
	};

	useEffect( () => {
		++countSeq.current;
		++listSeq.current;
		countAbort.current?.abort();
		listAbort.current?.abort();
		setListOpen( false );
		setRows( [] );
		setTotal( null );
		if ( ! key ) {
			setEstimate( { phase: 'idle', count: null, error: '' } );
			return;
		}
		if ( audience.scope === 'advanced' ) {
			setEstimate( { phase: 'idle', count: null, error: '' } );
			return;
		}
		const target = JSON.parse( key ) as Audience;
		setEstimate( { phase: 'loading', count: null, error: '' } );
		const timer = window.setTimeout( () => runCount( target ), 350 );
		return () => window.clearTimeout( timer );
	}, [ key, audience.scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( audience.scope !== 'advanced' || applyToken === appliedToken.current ) {
			return;
		}
		appliedToken.current = applyToken;
		const target = key ? JSON.parse( key ) as Audience : null;
		if ( target ) {
			runCount( target );
		}
	}, [ applyToken, key, audience.scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => () => {
		countAbort.current?.abort();
		listAbort.current?.abort();
	}, [] );

	const closeList = () => {
		++listSeq.current;
		listAbort.current?.abort();
		setListOpen( false );
		setRows( [] );
	};

	const loadPage = ( target: Audience, nextPage: number ) => {
		listAbort.current?.abort();
		const controller = new AbortController();
		listAbort.current = controller;
		const run = ++listSeq.current;
		setList( { phase: 'loading', error: '' } );
		setRows( [] );
		setPage( nextPage );
		fetchRecipients( target, nextPage )
			.then( ( result ) => {
				if ( listSeq.current !== run ) {
					return;
				}
				setList( { phase: 'ready', error: '' } );
				setPage( result.page );
				setPerPage( result.per_page );
				setTotal( result.count );
				setRows( result.recipients ?? [] );
			} )
			.catch( ( reason: unknown ) => {
				if ( listSeq.current !== run || controller.signal.aborted ) {
					return;
				}
				setList( { phase: 'error', error: errorMessageOf( reason, __( '無法載入收件人名單，請再試一次。', 'now-crm' ) ) } );
			} );
	};

	const goToPage = ( nextPage: number ) => {
		const target = key ? JSON.parse( key ) as Audience : null;
		if ( target ) {
			loadPage( target, nextPage );
		}
	};

	const openList = () => {
		const target = key ? JSON.parse( key ) as Audience : null;
		if ( ! target ) {
			return;
		}
		setListOpen( true );
		setTotal( estimate.count );
		loadPage( target, 1 );
	};

	const localEmpty = snapshot === null && audience.scope !== 'advanced';
	const pendingManual = audience.scope === 'advanced';
	const idleNote = pendingManual
		? __( '按「篩選」計算符合人數；修改條件後需重新篩選。', 'now-crm' )
		: audience.scope === 'rules'
			? __( '尚未完成包含條件，目前不會選出任何人。', 'now-crm' )
			: __( '尚未選擇分眾，目前不會選出任何人。', 'now-crm' );

	return (
		<div className="nowcrm-recipients__estimate">
			<div className="nowcrm-recipients__estimate-line" role="status" aria-live="polite">
				{ estimate.phase === 'idle' && pendingManual && (
					<>
						<span className="nowcrm-recipients__badge nowcrm-recipients__badge--pending" aria-hidden="true">—</span>
						<span>{ __( '按「篩選」計算符合人數。', 'now-crm' ) }</span>
					</>
				) }
				{ estimate.phase === 'idle' && localEmpty && (
					<>
						<span>{ __( '符合', 'now-crm' ) }</span>
						<span className="nowcrm-recipients__badge">0</span>
						<span>{ __( '位收件人', 'now-crm' ) }</span>
					</>
				) }
				{ estimate.phase === 'idle' && ! pendingManual && ! localEmpty && (
					<>
						<span className="nowcrm-recipients__badge nowcrm-recipients__badge--pending" aria-hidden="true">—</span>
						<span>{ __( '正在準備計算符合人數…', 'now-crm' ) }</span>
					</>
				) }
				{ estimate.phase === 'loading' && (
					<>
						<Spinner />
						<span>{ __( '正在計算符合人數…', 'now-crm' ) }</span>
					</>
				) }
				{ estimate.phase === 'ready' && estimate.count !== null && (
					<>
						<span>{ __( '符合', 'now-crm' ) }</span>
						<strong className="nowcrm-recipients__badge">{ estimate.count.toLocaleString() }</strong>
						<span>{ __( '位收件人', 'now-crm' ) }</span>
						{ estimate.count > 0 && (
							<Button variant="link" disabled={ busy } onClick={ openList }>
								{ __( '查看收件人', 'now-crm' ) }
							</Button>
						) }
					</>
				) }
				{ estimate.phase === 'error' && (
					<>
						<span className="nowcrm-recipients__estimate-error" role="alert">{ estimate.error }</span>
						<Button variant="link" disabled={ busy } onClick={ retryCount }>{ __( '重試', 'now-crm' ) }</Button>
					</>
				) }
			</div>
			{ estimate.phase === 'idle' && idleNote !== '' && (
				<p className="description nowcrm-recipients__estimate-note">{ idleNote }</p>
			) }
			{ estimate.phase === 'ready' && estimate.count === 0 && (
				<p className="description nowcrm-recipients__estimate-note">{ __( '沒有符合的收件人。請放寬或調整條件。', 'now-crm' ) }</p>
			) }
			{ listOpen && (
				<Modal
					title={ __( '符合條件的收件人', 'now-crm' ) }
					className="nowcrm-recipients__modal"
					onRequestClose={ closeList }
				>
					<p className="description" role="status">
						{ total !== null
							? sprintf(
								/* translators: %d: recipient count. */
								__( '共 %d 位符合的收件人，只顯示姓名與 Email。', 'now-crm' ),
								total
							)
							: __( '正在確認符合人數…', 'now-crm' ) }
					</p>
					{ list.phase === 'loading' && <p role="status"><Spinner /> { __( '正在載入名單…', 'now-crm' ) }</p> }
					{ list.phase === 'error' && (
						<p className="nowcrm-recipients__warning" role="alert">
							{ list.error }
							<Button variant="link" disabled={ busy } onClick={ () => goToPage( page ) }>{ __( '重試', 'now-crm' ) }</Button>
						</p>
					) }
					{ list.phase === 'ready' && rows.length === 0 && (
						<p className="description">{ __( '這一頁沒有收件人。', 'now-crm' ) }</p>
					) }
					{ list.phase === 'ready' && rows.length > 0 && (
						<table className="widefat striped">
							<thead>
								<tr>
									<th scope="col">{ __( '姓名', 'now-crm' ) }</th>
									<th scope="col">Email</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( recipient ) => (
									<tr key={ recipient.id }>
										<td>{ recipient.name || '—' }</td>
										<td>{ recipient.email }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<div className="nowcrm-recipients__pager">
						<Button variant="secondary" size="small" disabled={ list.phase === 'loading' || page <= 1 } onClick={ () => goToPage( page - 1 ) }>
							{ __( '上一頁', 'now-crm' ) }
						</Button>
						<span role="status">
							{ sprintf(
								/* translators: 1: page, 2: page size. */
								__( '第 %1$d 頁（每頁 %2$d 筆）', 'now-crm' ),
								page,
								perPage
							) }
						</span>
						<Button
							variant="secondary"
							size="small"
							disabled={ list.phase === 'loading' || ( total !== null && page * perPage >= total ) }
							onClick={ () => goToPage( page + 1 ) }
						>
							{ __( '下一頁', 'now-crm' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

export function RecipientStep( { audience, lists, tags, segments, catalog, busy, onChange, fetchRecipients, fetchRecipientCount, fetchOptions }: {
	audience: Audience;
	lists: Term[];
	tags: Term[];
	segments: Segment[];
	catalog: AdvancedCatalog | null;
	busy: boolean;
	onChange: ( value: Audience ) => void;
	fetchRecipients: ( audience: Audience, page: number ) => Promise< RecipientPage >;
	fetchRecipientCount: ( audience: Audience, signal: AbortSignal ) => Promise< RecipientCount >;
	fetchOptions: Lookup;
} ) {
	const filters: FilterPlan = audience.filters ?? { version: 1, groups: [] };
	const termLabels = useMemo( () => {
		const labels: Record< string, string > = {};
		for ( const term of [ ...lists, ...tags ] ) {
			labels[ String( term.id ) ] = term.name;
		}
		for ( const segment of segments ) {
			labels[ String( segment.ref ) ] = segment.name;
		}
		return labels;
	}, [ lists, tags, segments ] );
	const segmentLabel = audience.segment === undefined || audience.segment === 0 || audience.segment === ''
		? null
		: segmentLabelFor( audience.segment, segments );
	const [ applyToken, setApplyToken ] = useState( 0 );
	const [ segmentVisible, setSegmentVisible ] = useState( false );
	const [ excludeOpen, setExcludeOpen ] = useState( audience.exclude.length > 0 );
	useEffect( () => {
		if ( audience.exclude.length > 0 ) {
			setExcludeOpen( true );
		}
	}, [ audience.exclude.length ] );
	const activeRules = audience.scope === 'rules' ? [ ...audience.include, ...audience.exclude ] : audience.exclude;
	const segmentUsed = activeRules.some( ( row ) => row.segment !== 0 && row.segment !== '' );
	const showSegment = segmentUsed || segmentVisible;

	const scopeOptions = [
		{ label: __( '依名單與標籤', 'now-crm' ), value: 'rules' as Scope },
		{ label: __( '依分眾', 'now-crm' ), value: 'segments' as Scope },
		{ label: __( '進階篩選', 'now-crm' ), value: 'advanced' as Scope },
		{ label: __( '所有已訂閱', 'now-crm' ), value: 'all' as Scope },
	];
	const advancedReady = catalog !== null;

	return (
		<section className="nowcrm-workspace__panel nowcrm-recipients" aria-labelledby="nowcrm-recipients">
			<h2 id="nowcrm-recipients" tabIndex={ -1 }>{ __( '選擇收件人', 'now-crm' ) }</h2>
			<fieldset className="nowcrm-recipients__modes">
				<legend className="nowcrm-recipients__sr-only">{ __( '寄送範圍', 'now-crm' ) }</legend>
				{ scopeOptions.map( ( option ) => {
					const disabled = busy || ( option.value === 'advanced' && ! advancedReady );
					return (
						<label key={ option.value } className="nowcrm-recipients__mode">
							<input
								type="radio"
								name="nowcrm-recipients-scope"
								value={ option.value }
								checked={ audience.scope === option.value }
								disabled={ disabled }
								onChange={ () => onChange( { ...audience, scope: option.value } ) }
							/>
							<span>{ option.label }</span>
						</label>
					);
				} ) }
			</fieldset>

			{ audience.scope === 'rules' && (
				<div className="nowcrm-recipients__audience-block">
					<h3>{ __( '包含的聯絡人', 'now-crm' ) }</h3>
					<p className="description">{ __( '同一列的名單、標籤與分眾須同時符合；符合任一包含列即可入選。', 'now-crm' ) }</p>
					<RuleTable
						rows={ audience.include }
						lists={ lists }
						tags={ tags }
						segments={ segments }
						busy={ busy }
						section="include"
						showSegment={ showSegment }
						onToggleSegment={ segmentUsed ? undefined : () => setSegmentVisible( ( value ) => ! value ) }
						onChange={ ( include ) => onChange( { ...audience, include } ) }
					/>
				</div>
			) }

			{ audience.scope === 'segments' && (
				<fieldset className="nowcrm-recipients__rules">
					<legend>{ __( '已儲存的分眾', 'now-crm' ) }</legend>
					<p className="description">{ __( '使用一個已儲存的分眾（系統或自訂），再扣除下方的排除列。', 'now-crm' ) }</p>
					<TermChoice
						label={ __( '分眾', 'now-crm' ) }
						value={ audience.segment === undefined || audience.segment === 0 || audience.segment === '' ? '' : String( audience.segment ) }
						options={ segments.flatMap( ( segment ) => segment.is_system ? [] : [ { value: String( segment.ref ), label: __( '自訂分眾：', 'now-crm' ) + segment.name } ] ).concat(
							segments.flatMap( ( segment ) => segment.is_system ? [ { value: String( segment.ref ), label: __( '系統分眾：', 'now-crm' ) + segment.name } ] : [] )
						) }
						placeholder={ __( '搜尋或選擇分眾…', 'now-crm' ) }
						busy={ busy }
						onChange={ ( next ) => onChange( { ...audience, segment: next === '' ? '' : ( /^\d+$/.test( next ) ? Number( next ) : next ) } ) }
					/>
					{ ( audience.segment === undefined || audience.segment === 0 || audience.segment === '' ) && (
						<p className="nowcrm-recipients__warning" role="note">{ __( '尚未選擇分眾，不會選出任何人。', 'now-crm' ) }</p>
					) }
					{ typeof audience.segment !== 'undefined' && audience.segment !== 0 && audience.segment !== '' && segmentLabel === null && (
						<p className="nowcrm-recipients__warning" role="note">{ __( '這個分眾已經封存或刪除。請選擇其他分眾。', 'now-crm' ) }</p>
					) }
				</fieldset>
			) }

			{ audience.scope === 'advanced' && (
				catalog ? (
					<fieldset className="nowcrm-recipients__rules">
						<legend>{ __( '進階條件', 'now-crm' ) }</legend>
						<AdvancedBuilder
							filters={ filters }
							catalog={ catalog }
							busy={ busy }
							termLabels={ termLabels }
							fetchOptions={ fetchOptions }
							onChange={ ( next ) => onChange( { ...audience, filters: next } ) }
							onApply={ () => setApplyToken( ( value ) => value + 1 ) }
						/>
					</fieldset>
				) : (
					<p className="nowcrm-recipients__warning" role="note">{ __( '進階篩選目錄尚未載入，請重新整理後再試。', 'now-crm' ) }</p>
				)
			) }

			{ audience.scope === 'rules' ? (
				<div className="nowcrm-recipients__audience-block">
					<h3>{ __( '排除的聯絡人', 'now-crm' ) }</h3>
					<p className="description">{ __( '符合任一列即從結果移除。', 'now-crm' ) }</p>
					<RuleTable
						rows={ audience.exclude }
						lists={ lists }
						tags={ tags }
						segments={ segments }
						busy={ busy }
						section="exclude"
						showSegment={ showSegment }
						onToggleSegment={ ! segmentUsed ? () => setSegmentVisible( ( value ) => ! value ) : undefined }
						onChange={ ( exclude ) => onChange( { ...audience, exclude } ) }
					/>
				</div>
			) : (
				<details
					className="nowcrm-recipients__exclude"
					open={ excludeOpen }
					onToggle={ ( event ) => setExcludeOpen( event.currentTarget.open ) }
				>
					<summary>{ __( '排除的聯絡人', 'now-crm' ) }</summary>
					<p className="description">{ __( '符合任一列即從結果移除；沒有排除列時不影響結果。', 'now-crm' ) }</p>
					<RuleTable
						rows={ audience.exclude }
						lists={ lists }
						tags={ tags }
						segments={ segments }
						busy={ busy }
						section="exclude"
						showSegment={ showSegment }
						onToggleSegment={ ! segmentUsed ? () => setSegmentVisible( ( value ) => ! value ) : undefined }
						onChange={ ( exclude ) => onChange( { ...audience, exclude } ) }
					/>
				</details>
			) }

			<RecipientEstimate
				audience={ audience }
				busy={ busy }
				applyToken={ applyToken }
				fetchRecipientCount={ fetchRecipientCount }
				fetchRecipients={ fetchRecipients }
			/>
		</section>
	);
}
