import type { FormEvent } from 'react';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export type RecurringChild = {
	id: number;
	title: string;
	status: string;
	status_label: string;
	created: string;
	url: string;
};

export type RecurringSchedule = {
	status: string;
	enabled: boolean;
	frequency: 'daily' | 'weekly' | 'monthly';
	time: string;
	day: string;
	auto_send: boolean;
	next_run_at: string;
	last_run_at: string;
	error: string;
	next_run_label: string;
	last_run_label: string;
	child_count: number;
	last_child: RecurringChild | null;
};

export type RecurringPayload = {
	campaign_id: number;
	is_draft: boolean;
	is_child: boolean;
	recurring: RecurringSchedule;
	children: RecurringChild[];
	child_count: number;
};

export type RecurringActions = {
	adminPostUrl: string;
	duplicateAction: string;
	duplicateNonce: string;
	deleteAction: string;
	deleteNonce: string;
	canDelete: boolean;
};

export type ScheduleValues = {
	frequency: RecurringSchedule[ 'frequency' ];
	time: string;
	day: string;
	auto_send: boolean;
};

export type ScheduleAction = 'pause' | 'resume' | 'disable' | 'generate';

const WEEKDAYS: Array< [ string, string ] > = [
	[ 'mon', __( '週一', 'now-crm' ) ],
	[ 'tue', __( '週二', 'now-crm' ) ],
	[ 'wed', __( '週三', 'now-crm' ) ],
	[ 'thu', __( '週四', 'now-crm' ) ],
	[ 'fri', __( '週五', 'now-crm' ) ],
	[ 'sat', __( '週六', 'now-crm' ) ],
	[ 'sun', __( '週日', 'now-crm' ) ],
];

export function recurringStatusLabel( status: string ): string {
	if ( status === 'active' ) {
		return __( '啟用中', 'now-crm' );
	}

	if ( status === 'paused' ) {
		return __( '已暫停', 'now-crm' );
	}

	return __( '未啟用', 'now-crm' );
}

export function recurringStatusTone( status: string ): string {
	if ( status === 'active' ) {
		return 'nowcrm-status-pill--active';
	}

	if ( status === 'paused' ) {
		return 'nowcrm-status-pill--paused';
	}

	return 'nowcrm-status-pill--inactive';
}

export function recurringFrequencyLabel( schedule: RecurringSchedule ): string {
	const time = schedule.time || '09:00';

	if ( schedule.frequency === 'weekly' ) {
		const label = WEEKDAYS.find( ( [ key ] ) => key === schedule.day )?.[ 1 ] ?? WEEKDAYS[ 0 ][ 1 ];

		return sprintf( __( '每週%1$s %2$s', 'now-crm' ), label.replace( '週', '' ), time );
	}

	if ( schedule.frequency === 'monthly' ) {
		return sprintf( __( '每月 %1$d 日 %2$s', 'now-crm' ), Math.min( 31, Math.max( 1, Number.parseInt( schedule.day, 10 ) || 1 ) ), time );
	}

	return sprintf( __( '每日 %s', 'now-crm' ), time );
}

export function RecurringHistory( { data }: { data: RecurringPayload } ) {
	return (
		<section className="nowcrm-workspace__panel" aria-labelledby="nowcrm-recurring-history">
			<h2 id="nowcrm-recurring-history" tabIndex={ -1 }>{ __( '寄送紀錄', 'now-crm' ) }</h2>
			<p className="description">
				{ sprintf( __( '最近 %1$d 封子電子報，共 %2$d 封。', 'now-crm' ), data.children.length, data.child_count ) }
			</p>
			{ data.children.length === 0 ? (
				<p className="description">{ __( '還沒有子電子報。啟用後會在每個期間自動建立，或在設定按「立即產生這一期」。', 'now-crm' ) }</p>
			) : (
				<div className="nowcrm-workspace__table-scroll">
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">{ __( '名稱', 'now-crm' ) }</th>
								<th scope="col">{ __( '狀態', 'now-crm' ) }</th>
								<th scope="col">{ __( '建立時間', 'now-crm' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.children.map( ( child ) => (
								<tr key={ child.id }>
									<td><a href={ child.url }>{ child.title || __( '（未命名）', 'now-crm' ) }</a></td>
									<td>{ child.status_label }</td>
									<td>{ child.created }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</section>
	);
}

export function ScheduleSection( { data, busy, error, notice, actions, onSave, onAction, onDuplicate, onDelete }: {
	data: RecurringPayload;
	busy: boolean;
	error: string;
	notice: string;
	actions: RecurringActions | null;
	onSave: ( values: ScheduleValues ) => void;
	onAction: ( op: ScheduleAction ) => void;
	onDuplicate: ( event: FormEvent< HTMLFormElement > ) => void;
	onDelete: ( event: FormEvent< HTMLFormElement > ) => void;
} ) {
	const { recurring } = data;
	const [ frequency, setFrequency ] = useState< RecurringSchedule[ 'frequency' ] >( recurring.frequency );
	const [ day, setDay ] = useState( recurring.day );
	const [ time, setTime ] = useState( recurring.time );
	const [ autoSend, setAutoSend ] = useState( recurring.auto_send );

	useEffect( () => {
		setFrequency( recurring.frequency );
		setDay( recurring.day );
		setTime( recurring.time );
		setAutoSend( recurring.auto_send );
	}, [ recurring.frequency, recurring.day, recurring.time, recurring.auto_send ] );

	const weekly = frequency === 'weekly';
	const monthly = frequency === 'monthly';
	const weekday = WEEKDAYS.some( ( [ key ] ) => key === day ) ? day : 'mon';
	const monthDay = /^\d+$/.test( day ) ? Math.min( 31, Math.max( 1, Number( day ) ) ) : 1;

	const changeFrequency = ( value: RecurringSchedule[ 'frequency' ] ) => {
		setFrequency( value );

		if ( value === 'weekly' && ! WEEKDAYS.some( ( [ key ] ) => key === day ) ) {
			setDay( 'mon' );
		}

		if ( value === 'monthly' && ! /^\d+$/.test( day ) ) {
			setDay( '1' );
		}
	};

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		onSave( {
			frequency,
			time,
			day: weekly ? weekday : monthly ? String( monthDay ) : '',
			auto_send: autoSend,
		} );
	};

	return (
		<section className="nowcrm-workspace__panel" aria-labelledby="nowcrm-schedule">
			<h2 id="nowcrm-schedule" tabIndex={ -1 }>{ __( '寄送排程', 'now-crm' ) }</h2>
			{ error && <p className="nowcrm-workspace__message" role="alert">{ error }</p> }
			{ notice && <p className="nowcrm-workspace__message" role="status">{ notice }</p> }
			{ recurring.error && <p className="description">{ recurring.error }</p> }
			<form onSubmit={ submit } className="nowcrm-workspace__schedule">
				<label className="nowcrm-workspace__field">
					<span>{ __( '頻率', 'now-crm' ) }</span>
					<select value={ frequency } disabled={ busy } onChange={ ( event ) => changeFrequency( event.target.value === 'weekly' ? 'weekly' : event.target.value === 'monthly' ? 'monthly' : 'daily' ) }>
						<option value="daily">{ __( '每日', 'now-crm' ) }</option>
						<option value="weekly">{ __( '每週', 'now-crm' ) }</option>
						<option value="monthly">{ __( '每月', 'now-crm' ) }</option>
					</select>
				</label>
				{ weekly && (
					<label className="nowcrm-workspace__field">
						<span>{ __( '星期', 'now-crm' ) }</span>
						<select value={ weekday } disabled={ busy } onChange={ ( event ) => setDay( event.target.value ) }>
							{ WEEKDAYS.map( ( [ key, label ] ) => <option key={ key } value={ key }>{ label }</option> ) }
						</select>
					</label>
				) }
				{ monthly && (
					<label className="nowcrm-workspace__field">
						<span>{ __( '日期', 'now-crm' ) }</span>
						<input type="number" min="1" max="31" step="1" value={ monthDay } disabled={ busy } onChange={ ( event ) => setDay( event.target.value ) } />
					</label>
				) }
				<label className="nowcrm-workspace__field">
					<span>{ __( '時間', 'now-crm' ) }</span>
					<input type="time" value={ time } required disabled={ busy } onChange={ ( event ) => setTime( event.target.value ) } />
				</label>
				<label className="nowcrm-workspace__check">
					<input type="checkbox" checked={ autoSend } disabled={ busy } onChange={ ( event ) => setAutoSend( event.target.checked ) } />
					<span>{ __( '自動寄送', 'now-crm' ) }</span>
				</label>
				<div className="nowcrm-workspace__actions">
					<button type="submit" className="button button-primary" disabled={ busy }>{ __( '儲存排程', 'now-crm' ) }</button>
				</div>
			</form>
			<p className="description">{ __( '啟用前請先填寫主旨與正文。缺少內容時不會變更啟用狀態。', 'now-crm' ) }</p>
			<div className="nowcrm-workspace__schedule-actions nowcrm-workspace__actions">
				<button type="button" className="button" disabled={ busy } onClick={ () => onAction( 'generate' ) }>{ __( '立即產生這一期', 'now-crm' ) }</button>
				{ recurring.status === 'active'
					? <button type="button" className="button" disabled={ busy } onClick={ () => onAction( 'pause' ) }>{ __( '暫停', 'now-crm' ) }</button>
					: <button type="button" className="button" disabled={ busy } onClick={ () => onAction( 'resume' ) }>{ __( '恢復', 'now-crm' ) }</button> }
				{ recurring.status !== 'inactive' && <button type="button" className="button" disabled={ busy } onClick={ () => onAction( 'disable' ) }>{ __( '停用', 'now-crm' ) }</button> }
				{ actions && (
					<form method="post" action={ actions.adminPostUrl } onSubmit={ onDuplicate }>
						<input type="hidden" name="action" value={ actions.duplicateAction } />
						<input type="hidden" name="campaign_id" value={ String( data.campaign_id ) } />
						<input type="hidden" name="_wpnonce" value={ actions.duplicateNonce } />
						<button type="submit" className="button" disabled={ busy }>{ __( '複製', 'now-crm' ) }</button>
					</form>
				) }
				{ actions?.canDelete && (
					<form method="post" action={ actions.adminPostUrl } onSubmit={ onDelete }>
						<input type="hidden" name="action" value={ actions.deleteAction } />
						<input type="hidden" name="campaign_id" value={ String( data.campaign_id ) } />
						<input type="hidden" name="_wpnonce" value={ actions.deleteNonce } />
						<button type="submit" className="button button-link-delete" disabled={ busy }>{ __( '刪除', 'now-crm' ) }</button>
					</form>
				) }
			</div>
			<p className="description">{ __( '「立即產生這一期」只建立一封子電子報；自動寄送開啟時，子電子報會依既有規則排程或寄送。', 'now-crm' ) }</p>
		</section>
	);
}
