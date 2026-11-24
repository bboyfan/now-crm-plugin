import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type TermOption = { id: number; name: string };

export type ConditionalConfig = {
	contactTypes: Record< string, string >;
	statuses: Record< string, string >;
	countries: Record< string, string >;
	lists: TermOption[];
	tags: TermOption[];
};

type Attributes = { field: string; operator: string; value: string };

const config: ConditionalConfig = {
	contactTypes: { lead: '潛在客戶', customer: '客戶' },
	statuses: {
		subscribed: '已訂閱',
		unsubscribed: '已退訂',
		transactional: '僅交易信',
		bounced: '退信',
		complained: '投訴',
	},
	countries: {},
	lists: [],
	tags: [],
};

const provided = window.nowcrmConditionalConfig;

if ( provided ) {
	Object.assign( config, provided );
}

declare global {
	interface Window {
		nowcrmConditionalConfig?: Partial< ConditionalConfig >;
	}
}

function options( values: Record< string, string > ): Array< { value: string; label: string } > {
	return Object.entries( values ).map( ( [ value, label ] ) => ( { value, label } ) );
}

function fieldLabel( field: string ): string {
	return ( {
		contact_type: __( '聯絡人類型', 'now-crm' ),
		status: __( '訂閱狀態', 'now-crm' ),
		country: __( '國家', 'now-crm' ),
		list: __( '名單', 'now-crm' ),
		tag: __( '標籤', 'now-crm' ),
	} as Record< string, string > )[ field ] ?? field;
}

function valueOptions( field: string ): Array< { value: string; label: string } > {
	switch ( field ) {
		case 'contact_type':
			return options( config.contactTypes );
		case 'status':
			return options( config.statuses );
		case 'country':
			return options( config.countries );
		case 'list':
			return config.lists.map( ( term ) => ( { value: String( term.id ), label: term.name } ) );
		case 'tag':
			return config.tags.map( ( term ) => ( { value: String( term.id ), label: term.name } ) );
		default:
			return [];
	}
}

function Edit( { attributes, setAttributes }: {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
} ) {
	const { field, operator, value } = attributes;
	const values = valueOptions( field );
	const membership = 'list' === field || 'tag' === field;
	const summary = __( '條件內容', 'now-crm' ) + '：' + fieldLabel( field ) + ' ' +
		( 'is_not' === operator ? __( '不是', 'now-crm' ) : membership ? __( '包含', 'now-crm' ) : __( '是', 'now-crm' ) ) +
		' 「' + ( valueOptions( field ).find( ( option ) => option.value === value )?.label ?? value ) + '」';
	const fieldOptions: Array< { value: string; label: string } > = [
		{ value: 'contact_type', label: __( '聯絡人類型', 'now-crm' ) },
		{ value: 'status', label: __( '訂閱狀態', 'now-crm' ) },
		{ value: 'country', label: __( '國家', 'now-crm' ) },
		{ value: 'list', label: __( '名單', 'now-crm' ) },
		{ value: 'tag', label: __( '標籤', 'now-crm' ) },
	];
	const operatorOptions: Array< { value: string; label: string } > = [
		{ value: 'is', label: membership ? __( '包含', 'now-crm' ) : __( '是', 'now-crm' ) },
		{ value: 'is_not', label: membership ? __( '不包含', 'now-crm' ) : __( '不是', 'now-crm' ) },
	];

	useEffect( () => {
		if ( value.length === 0 ) {
			setAttributes( { value: valueOptions( field )[ 0 ]?.value ?? '' } );
		}
	}, [ field, value, setAttributes ] );

	return (
		<div { ...useBlockProps( { className: 'nowcrm-conditional' } ) }>
			<InspectorControls>
				<PanelBody title={ __( '顯示條件', 'now-crm' ) }>
					<SelectControl
						label={ __( '欄位', 'now-crm' ) }
						value={ field }
						options={ fieldOptions }
						onChange={ ( next ) => setAttributes( { field: next, operator: 'is', value: valueOptions( next )[ 0 ]?.value ?? '' } ) }
					/>
					<SelectControl
						label={ __( '運算子', 'now-crm' ) }
						value={ operator }
						options={ operatorOptions }
						onChange={ ( next ) => setAttributes( { operator: next } ) }
					/>
					{ 'country' === field && values.length === 0
						? <TextControl
							label={ __( '國家代碼', 'now-crm' ) }
							value={ value }
							placeholder="TW"
							onChange={ ( next ) => setAttributes( { value: next.toUpperCase() } ) }
						/>
						: <SelectControl
							label={ fieldLabel( field ) }
							value={ value }
							options={ values.length > 0 ? values : [ { value: '', label: __( '尚無選項', 'now-crm' ) } ] }
							onChange={ ( next ) => setAttributes( { value: next } ) }
						/> }
					<p>{ __( '收件人符合條件時才會看到此區塊的內容。條件由伺服器依實際聯絡人判斷。', 'now-crm' ) }</p>
				</PanelBody>
			</InspectorControls>
			<p style={ { margin: '0 0 8px', fontSize: '12px', fontWeight: 600, color: '#50575e' } }>{ summary }</p>
			<InnerBlocks />
		</div>
	);
}

export function registerConditionalBlock(): void {
	registerBlockType( 'nowcrm/conditional', {
		apiVersion: 3,
		title: __( '條件內容', 'now-crm' ),
		description: __( '只有在收件人符合條件時，才顯示區塊內的內容。', 'now-crm' ),
		category: 'design',
		icon: 'filter',
		attributes: {
			field: { type: 'string', default: 'contact_type' },
			operator: { type: 'string', default: 'is' },
			value: { type: 'string', default: '' },
		},
		supports: { html: false },
		edit: Edit,
		save: () => <InnerBlocks.Content />,
	} );
}
