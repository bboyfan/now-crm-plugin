<?php
/**
 * Automation action catalog and config validation.
 *
 * Stable action keys are the persistence contract. The admin editor is a later
 * phase and must use these keys.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Companies\CompanyRepository;
use NowCRM\Contacts\Status;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ActionRegistry {

	public const APPLY_LIST     = 'apply_list';
	public const REMOVE_LIST    = 'remove_list';
	public const APPLY_TAG      = 'apply_tag';
	public const REMOVE_TAG     = 'remove_tag';
	public const SET_STATUS     = 'set_status';
	public const APPLY_COMPANY  = 'apply_company';
	public const DETACH_COMPANY = 'detach_company';
	public const ADD_TO_SEQUENCE = 'add_to_sequence';

	/**
	 * Every implemented action key.
	 *
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			self::APPLY_LIST     => __( '加入名單', 'now-crm' ),
			self::REMOVE_LIST    => __( '移除名單', 'now-crm' ),
			self::APPLY_TAG      => __( '加入標籤', 'now-crm' ),
			self::REMOVE_TAG     => __( '移除標籤', 'now-crm' ),
			self::SET_STATUS     => __( '變更聯絡人狀態', 'now-crm' ),
			self::APPLY_COMPANY  => __( '加入公司', 'now-crm' ),
			self::DETACH_COMPANY => __( '移除公司', 'now-crm' ),
			self::ADD_TO_SEQUENCE => __( '加入序列', 'now-crm' ),
		);
	}

	/**
	 * Whether an action key is implemented.
	 */
	public static function is_valid( string $key ): bool {
		return isset( self::actions()[ $key ] );
	}

	/**
	 * Term type touched by a term action, or an empty string.
	 */
	public static function term_type( string $key ): string {
		return match ( $key ) {
			self::APPLY_LIST, self::REMOVE_LIST => TermRepository::TYPE_LIST,
			self::APPLY_TAG, self::REMOVE_TAG   => TermRepository::TYPE_TAG,
			default                             => '',
		};
	}

	/**
	 * Validate and normalize one action config.
	 *
	 * A sequence that is not active is valid only when it is in the trusted
	 * set. That set holds references the saved automation already has, so an
	 * edit or a copy does not lose a paused target. A new choice must be
	 * active.
	 *
	 * @param string               $key    Action key.
	 * @param array<string, mixed> $config Raw config.
	 * @param array<int, int>      $trusted_sequence_ids Sequence IDs to keep without the active check.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize_config( string $key, array $config, array $trusted_sequence_ids = array() ) {
		if ( ! self::is_valid( $key ) ) {
			return new WP_Error( 'nowcrm_automation_invalid_action', __( '不支援的自動化動作。', 'now-crm' ) );
		}

		$type = self::term_type( $key );

		if ( '' !== $type ) {
			$ids = $config['term_ids'] ?? ( $config['term_id'] ?? array() );

			if ( ! is_array( $ids ) ) {
				$ids = array( $ids );
			}

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

			if ( array() === $ids || count( $ids ) > 100 ) {
				return new WP_Error( 'nowcrm_automation_invalid_term', __( '請選擇一個有效的名單或標籤。', 'now-crm' ) );
			}

			$terms = new TermRepository();

			foreach ( $ids as $term_id ) {
				$term = $terms->get( $term_id );

				if ( ! $term || $type !== $term['type'] ) {
					return new WP_Error( 'nowcrm_automation_invalid_term', __( '名單或標籤不存在或類型不符。', 'now-crm' ) );
				}
			}

			return array( 'term_ids' => $ids );
		}

		if ( self::SET_STATUS === $key ) {
			$status = sanitize_key( (string) ( $config['status'] ?? '' ) );

			if ( ! Status::is_valid( $status ) ) {
				return new WP_Error( 'nowcrm_automation_invalid_status', __( '聯絡人狀態不正確。', 'now-crm' ) );
			}

			return array( 'status' => $status );
		}

		if ( self::ADD_TO_SEQUENCE === $key ) {
			$sequence_id = absint( $config['sequence_id'] ?? 0 );
			$sequence    = $sequence_id > 0 ? ( new SequenceRepository() )->get( $sequence_id ) : null;

			if ( ! $sequence ) {
				return new WP_Error( 'nowcrm_automation_invalid_sequence', __( '找不到指定的序列。', 'now-crm' ) );
			}

			if ( SequenceRepository::STATUS_ACTIVE !== $sequence['status'] && ! in_array( $sequence_id, array_map( 'absint', $trusted_sequence_ids ), true ) ) {
				return new WP_Error( 'nowcrm_automation_invalid_sequence', __( '只有啟用中的序列可以加入聯絡人。', 'now-crm' ) );
			}

			return array( 'sequence_id' => $sequence_id );
		}

		$company_id = absint( $config['company_id'] ?? 0 );

		if ( $company_id <= 0 || ! ( new CompanyRepository() )->get( $company_id ) ) {
			return new WP_Error( 'nowcrm_automation_invalid_company', __( '找不到指定的公司。', 'now-crm' ) );
		}

		return array( 'company_id' => $company_id );
	}
}
