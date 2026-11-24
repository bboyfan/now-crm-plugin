<?php
/**
 * Advanced recipient filters for campaign review and preview.
 *
 * The catalog covers data the contact filters do not: accepted broadcast
 * sends with local open and click events, counted WooCommerce order links
 * and order items, and native automation and sequence runs. Contact columns,
 * terms and custom fields stay in Contacts\AdvancedFilters. A campaign plan
 * may mix both kinds, and this class compiles the union.
 *
 * Interface:
 * - normalize( $raw ) returns a typed plan or WP_Error.
 * - compile( array $plan ) returns `array( 'sql', 'params' )` for the contact
 *   alias `c`.
 * - catalog() returns the shared contract: fields, operators, providers and
 *   limits. The catalog carries UI metadata only, never raw SQL.
 * - options( $field, $search, $selected ) returns bounded option rows for a
 *   lookup field, or WP_Error.
 *
 * Every predicate fails closed. An unknown, stale or unavailable reference
 * makes its group match nothing. "Not opened" and "not clicked" require an
 * accepted send of the same campaign, so contacts outside that historical
 * send are never included. Eligibility (subscribed status, suppressions)
 * stays in Campaign\Audience and is never added here.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use DateTimeImmutable;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RecipientFilters {

	public const PROVIDER_EMAIL      = 'email';
	public const PROVIDER_WOO        = 'woo';
	public const PROVIDER_AUTOMATION = 'automation';
	public const PROVIDER_SEQUENCE   = 'sequence';

	/**
	 * Option rows returned for one search query.
	 */
	public const OPTIONS_SEARCH_LIMIT = 20;

	/**
	 * Selected references resolved next to one search query.
	 */
	public const OPTIONS_SELECTED_LIMIT = 100;

	/**
	 * Generated per-currency fields never exceed this count.
	 */
	public const MAX_CURRENCIES = 20;

	/**
	 * Option sources that stay small enough for a catalog preview map.
	 *
	 * @var string[]
	 */
	private const CATALOG_OPTION_SOURCES = array( 'campaigns', 'automations', 'sequences' );

	// ------------------------------------------------------------------
	// Public interface
	// ------------------------------------------------------------------

	/**
	 * Validate raw groups and return the typed plan.
	 *
	 * Accepts a list of groups, or a plan from this method. Campaign field
	 * definitions are appended for this call only; Contacts and Segments
	 * never see them.
	 *
	 * @param mixed $raw Groups of conditions.
	 * @return array{version: int, groups: array<int, array<int, array<string, mixed>>>}|WP_Error
	 */
	public static function normalize( $raw ) {
		return AdvancedFilters::normalize( $raw, self::definitions() );
	}

	/**
	 * Compile a plan into a WHERE fragment with contact alias `c`.
	 *
	 * A group that contains any condition the compiler cannot resolve becomes
	 * `1=0`. Eligibility stays outside: this fragment never filters by
	 * status or suppressions.
	 *
	 * @param array<string, mixed> $plan Validated plan.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	public static function compile( array $plan ): array {
		return AdvancedFilters::compile( $plan, self::definitions() );
	}

	/**
	 * Shared contract for the advanced rule builder.
	 *
	 * @return array{fields: array<string, array<string, mixed>>, operators: array<string, string>, providers: array<string, string>, limits: array<string, int>}
	 */
	public static function catalog(): array {
		$fields = array();

		foreach ( AdvancedFilters::fields() as $key => $definition ) {
			$fields[ $key ] = self::public_builtin_field( $definition );
		}

		foreach ( self::definitions() as $key => $definition ) {
			$fields[ $key ] = self::public_field( $key, $definition );
		}

		return array(
			'fields'    => $fields,
			'operators' => self::operator_labels(),
			'providers' => self::provider_labels(),
			'limits'    => array(
				'groups'     => AdvancedFilters::MAX_GROUPS,
				'conditions' => AdvancedFilters::MAX_CONDITIONS,
				'values'     => AdvancedFilters::MAX_VALUES,
				'days'       => AdvancedFilters::MAX_DAYS,
			),
		);
	}

	/**
	 * Bounded option rows for one lookup field.
	 *
	 * `$field` is a full `provider.field` key. Selected references are
	 * returned next to the search hits, so the UI can keep their labels.
	 *
	 * @param string $field    Full `provider.field` key.
	 * @param string $search   Free text filter.
	 * @param array  $selected References to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}|WP_Error
	 */
	public static function options( string $field, string $search = '', array $selected = array() ) {
		$key         = strtolower( trim( $field ) );
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $key ] ) ) {
			return new WP_Error(
				'nowcrm_advanced_filter_invalid',
				sprintf(
					/* translators: %s: field name from the request. */
					__( '不支援的篩選欄位：%s。', 'now-crm' ),
					$field
				)
			);
		}

		$source = $definitions[ $key ]['options_source'] ?? null;

		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error(
				'nowcrm_advanced_filter_invalid',
				__( '此欄位沒有選項。', 'now-crm' )
			);
		}

		switch ( $source ) {
			case 'campaigns':
				return self::campaign_options( $search, $selected );

			case 'products':
				return self::product_options( $search, $selected );

			case 'categories':
				return self::term_options( 'product_cat', $search, $selected );

			case 'tags':
				return self::term_options( 'product_tag', $search, $selected );

			case 'coupons':
				return self::coupon_options( $search, $selected );

			case 'automations':
				return self::automation_options( $search, $selected );

			case 'sequences':
				return self::sequence_options( $search, $selected );
		}

		return new WP_Error(
			'nowcrm_advanced_filter_invalid',
			__( '此欄位沒有選項。', 'now-crm' )
		);
	}

	/**
	 * Human labels for every operator the catalog offers.
	 *
	 * @return array<string, string>
	 */
	public static function operator_labels(): array {
		return array_merge(
			AdvancedFilters::operator_labels(),
			array(
				'completed' => __( '已完成', 'now-crm' ),
				'active'    => __( '進行中', 'now-crm' ),
				'cancelled' => __( '已取消', 'now-crm' ),
				'waiting'   => __( '等待中', 'now-crm' ),
			)
		);
	}

	/**
	 * Provider labels for grouped controls.
	 *
	 * @return array<string, string>
	 */
	public static function provider_labels(): array {
		return array_merge(
			AdvancedFilters::provider_labels(),
			array(
				self::PROVIDER_EMAIL      => __( 'Email 互動', 'now-crm' ),
				self::PROVIDER_WOO        => __( 'WooCommerce 訂購', 'now-crm' ),
				self::PROVIDER_AUTOMATION => __( '自動化', 'now-crm' ),
				self::PROVIDER_SEQUENCE   => __( '序列郵件', 'now-crm' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Definitions
	// ------------------------------------------------------------------

	/**
	 * Campaign field definitions appended to AdvancedFilters for one call.
	 *
	 * Every definition carries `validate` and `compile` closures. The
	 * catalog projection strips those keys, so raw SQL never leaves this
	 * class.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$choice = array( 'in', 'not_in', '=', '!=' );
		$dates  = array( 'before', 'after', 'date_equal', 'days_before', 'days_within', 'is_null', 'not_null' );
		$number = array( '>', '<', '=', '!=' );

		$definitions = array();

		$email_activities = array(
			'sent'     => array(
				__( '已寄出電子報', 'now-crm' ),
				__( '只比對正式寄送且郵件系統已接受的工作；測試寄送不計入。', 'now-crm' ),
			),
			'received' => array(
				__( '已送達電子報', 'now-crm' ),
				__( '正式寄送已接受，且沒有本站記錄的退信。', 'now-crm' ),
			),
			'opened'   => array(
				__( '已開啟電子報', 'now-crm' ),
				__( '只比對寄出過所選電子報的聯絡人；未寄出者不算開啟，也不算未開啟。部分郵件軟體會自動載入圖片，開啟數據僅供參考。', 'now-crm' ),
			),
			'clicked'  => array(
				__( '已點擊電子報連結', 'now-crm' ),
				__( '只比對寄出過所選電子報的聯絡人；未寄出者不算在內。', 'now-crm' ),
			),
		);

		foreach ( $email_activities as $field => $meta ) {
			$definitions[ self::PROVIDER_EMAIL . '.' . $field ] = array(
				'provider'       => self::PROVIDER_EMAIL,
				'field'          => $field,
				'label'          => $meta[0],
				'type'           => 'select',
				'ops'            => $choice,
				'lookup'         => true,
				'help'           => $meta[1],
				'options_source' => 'campaigns',
				'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
					return self::validate_id_set( $value, $operator, array( '=', '!=' ), $groupNo, $rowNo );
				},
				'compile'        => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_email_activity( $definition, $operator, $value );
				},
			);
		}

		$last_dates = array(
			'last_sent'    => array(
				__( '最後寄出時間', 'now-crm' ),
				__( '正式寄送被郵件系統接受的時間。', 'now-crm' ),
			),
			'last_opened'  => array(
				__( '最後開啟時間', 'now-crm' ),
				__( '依本站記錄的開啟事件；部分郵件軟體會自動載入圖片，數據僅供參考。', 'now-crm' ),
			),
			'last_clicked' => array(
				__( '最後點擊時間', 'now-crm' ),
				__( '依本站記錄的點擊事件。', 'now-crm' ),
			),
		);

		foreach ( $last_dates as $field => $meta ) {
			$definitions[ self::PROVIDER_EMAIL . '.' . $field ] = array(
				'provider' => self::PROVIDER_EMAIL,
				'field'    => $field,
				'label'    => $meta[0],
				'type'     => 'date',
				'ops'      => $dates,
				'help'     => $meta[1],
				'compile'  => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_last_date( $definition, $operator, $value );
				},
			);
		}

		$definitions[ self::PROVIDER_WOO . '.order_count' ] = array(
			'provider'    => self::PROVIDER_WOO,
			'field'       => 'order_count',
			'label'       => __( '訂單數（已計入）', 'now-crm' ),
			'type'        => 'number',
			'ops'         => $number,
			'number_kind' => 'integer',
			'help'        => __( '只計算已收款狀態的訂單連結。', 'now-crm' ),
			'compile'     => static function ( array $definition, string $operator, $value ): ?array {
				return self::compile_order_metric( $definition, $operator, $value );
			},
		);

		$order_dates = array(
			'first_order_date' => __( '首筆計入訂單日期', 'now-crm' ),
			'last_order_date'  => __( '最近計入訂單日期', 'now-crm' ),
		);

		foreach ( $order_dates as $field => $label ) {
			$definitions[ self::PROVIDER_WOO . '.' . $field ] = array(
				'provider' => self::PROVIDER_WOO,
				'field'    => $field,
				'label'    => $label,
				'type'     => 'date',
				'ops'      => $dates,
				'help'     => __( '只計算已收款狀態的訂單連結；沒有訂單時為空白。', 'now-crm' ),
				'compile'  => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_order_metric( $definition, $operator, $value );
				},
			);
		}

		foreach ( self::currencies() as $currency ) {
			$field = 'total_spent_' . strtolower( $currency );

			$definitions[ self::PROVIDER_WOO . '.' . $field ] = array(
				'provider'    => self::PROVIDER_WOO,
				'field'       => $field,
				'label'       => sprintf(
					/* translators: %s: currency code. */
					__( '累計消費淨額（%s）', 'now-crm' ),
					$currency
				),
				'type'        => 'number',
				'ops'         => $number,
				'number_kind' => 'decimal',
				'currency'    => $currency,
				'help'        => __( '只加總同一幣別的已計入訂單淨額；跨幣別請分開篩選。', 'now-crm' ),
				'compile'     => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_order_metric( $definition, $operator, $value );
				},
			);
		}

		if ( self::woo_available() ) {
			$definitions[ self::PROVIDER_WOO . '.products' ] = array(
				'provider'       => self::PROVIDER_WOO,
				'field'          => 'products',
				'label'          => __( '購買過商品', 'now-crm' ),
				'type'           => 'select',
				'ops'            => array_merge( $choice, array( 'is_null', 'not_null' ) ),
				'lookup'         => true,
				'help'           => __( '比對已計入訂單的商品與商品變化 ID；商品刪除後歷史購買仍可比對。', 'now-crm' ),
				'options_source' => 'products',
				'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
					return self::validate_id_set( $value, $operator, array( '=', '!=' ), $groupNo, $rowNo );
				},
				'compile'        => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_products( $definition, $operator, $value );
				},
			);

			$taxonomies = array(
				'categories' => array( 'product_cat', __( '購買過商品分類', 'now-crm' ) ),
				'tags'       => array( 'product_tag', __( '購買過商品標籤', 'now-crm' ) ),
			);

			foreach ( $taxonomies as $field => $meta ) {
				$definitions[ self::PROVIDER_WOO . '.' . $field ] = array(
					'provider'       => self::PROVIDER_WOO,
					'field'          => $field,
					'label'          => $meta[1],
					'type'           => 'select',
					'ops'            => array_merge( $choice, array( 'is_null', 'not_null' ) ),
					'lookup'         => true,
					'taxonomy'       => $meta[0],
					'options_source' => 'categories' === $field ? 'categories' : 'tags',
					'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
						return self::validate_id_set( $value, $operator, array( '=', '!=' ), $groupNo, $rowNo );
					},
					'compile'        => static function ( array $definition, string $operator, $value ): ?array {
						return self::compile_taxonomy( $definition, $operator, $value );
					},
				);
			}

			$definitions[ self::PROVIDER_WOO . '.coupons' ] = array(
				'provider'       => self::PROVIDER_WOO,
				'field'          => 'coupons',
				'label'          => __( '使用過優惠碼', 'now-crm' ),
				'type'           => 'select',
				'ops'            => array_merge( $choice, array( 'is_null', 'not_null' ) ),
				'lookup'         => true,
				'help'           => __( '比對訂單上的優惠碼名稱，不分大小寫。', 'now-crm' ),
				'options_source' => 'coupons',
				'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
					return self::validate_code_set( $value, $operator, array( '=', '!=' ), $groupNo, $rowNo );
				},
				'compile'        => static function ( array $definition, string $operator, $value ): ?array {
					return self::compile_coupons( $definition, $operator, $value );
				},
			);
		}

		$definitions[ self::PROVIDER_AUTOMATION . '.activity' ] = array(
			'provider'       => self::PROVIDER_AUTOMATION,
			'field'          => 'activity',
			'label'          => __( '自動化活動', 'now-crm' ),
			'type'           => 'select',
			'ops'            => array( 'in', 'not_in', 'completed', 'active', 'cancelled', 'waiting' ),
			'lookup'         => true,
			'help'           => __( 'in 表示進入過所選自動化；狀態運算子比對該次參與的目前狀態。', 'now-crm' ),
			'options_source' => 'automations',
			'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
				return self::validate_id_set( $value, $operator, array( '=', '!=', 'completed', 'active', 'cancelled', 'waiting' ), $groupNo, $rowNo );
			},
			'compile'        => static function ( array $definition, string $operator, $value ): ?array {
				return self::compile_participation( $definition, $operator, $value );
			},
		);

		$definitions[ self::PROVIDER_SEQUENCE . '.activity' ] = array(
			'provider'       => self::PROVIDER_SEQUENCE,
			'field'          => 'activity',
			'label'          => __( '序列活動', 'now-crm' ),
			'type'           => 'select',
			'ops'            => array( 'in', 'not_in', 'completed', 'active', 'cancelled' ),
			'lookup'         => true,
			'help'           => __( 'in 表示進入過所選序列；狀態運算子比對該次參與的目前狀態。', 'now-crm' ),
			'options_source' => 'sequences',
			'validate'       => static function ( $value, string $operator, int $groupNo, int $rowNo ) {
				return self::validate_id_set( $value, $operator, array( '=', '!=', 'completed', 'active', 'cancelled' ), $groupNo, $rowNo );
			},
			'compile'        => static function ( array $definition, string $operator, $value ): ?array {
				return self::compile_participation( $definition, $operator, $value );
			},
		);

		return $definitions;
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Validate a set of positive integer IDs.
	 *
	 * @param mixed    $value      Raw value.
	 * @param string   $operator   Operator.
	 * @param string[] $single_ops Operators that take one ID.
	 * @param int      $groupNo    Group number, for the message.
	 * @param int      $rowNo      Row number, for the message.
	 * @return int|int[]|WP_Error
	 */
	private static function validate_id_set( $value, string $operator, array $single_ops, int $groupNo, int $rowNo ) {
		if ( AdvancedFilters::is_valueless( $operator ) ) {
			return null;
		}

		$items = is_array( $value ) ? array_values( $value ) : array( $value );

		if ( count( $items ) > AdvancedFilters::MAX_VALUES ) {
			return self::row_error(
				'value_limit',
				sprintf(
					/* translators: %d: maximum number of values. */
					__( '一個條件最多只能有 %d 個值。', 'now-crm' ),
					AdvancedFilters::MAX_VALUES
				),
				$groupNo,
				$rowNo
			);
		}

		$ids = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || ! preg_match( '/^\d{1,15}$/', trim( (string) $item ) ) || (int) $item <= 0 ) {
				return self::row_error( 'value_format', __( '值必須是正整數 ID。', 'now-crm' ), $groupNo, $rowNo );
			}

			$id = (int) $item;

			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( array() === $ids ) {
			return self::row_error( 'empty_value', __( '請至少選擇一個值。', 'now-crm' ), $groupNo, $rowNo );
		}

		if ( in_array( $operator, $single_ops, true ) ) {
			if ( 1 !== count( $ids ) ) {
				return self::row_error( 'value_single', __( '此運算子需要單一值。', 'now-crm' ), $groupNo, $rowNo );
			}

			return $ids[0];
		}

		return $ids;
	}

	/**
	 * Validate a set of coupon codes.
	 *
	 * @param mixed    $value      Raw value.
	 * @param string   $operator   Operator.
	 * @param string[] $single_ops Operators that take one code.
	 * @param int      $groupNo    Group number, for the message.
	 * @param int      $rowNo      Row number, for the message.
	 * @return string|string[]|WP_Error
	 */
	private static function validate_code_set( $value, string $operator, array $single_ops, int $groupNo, int $rowNo ) {
		if ( AdvancedFilters::is_valueless( $operator ) ) {
			return null;
		}

		$items = is_array( $value ) ? array_values( $value ) : array( $value );

		if ( count( $items ) > AdvancedFilters::MAX_VALUES ) {
			return self::row_error(
				'value_limit',
				sprintf(
					/* translators: %d: maximum number of values. */
					__( '一個條件最多只能有 %d 個值。', 'now-crm' ),
					AdvancedFilters::MAX_VALUES
				),
				$groupNo,
				$rowNo
			);
		}

		$codes = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return self::row_error( 'value_format', __( '優惠碼格式不正確。', 'now-crm' ), $groupNo, $rowNo );
			}

			$code = strtolower( trim( (string) $item ) );

			if ( '' === $code ) {
				continue;
			}

			if ( strlen( $code ) > 191 || ! preg_match( '//u', $code ) ) {
				return self::row_error( 'value_format', __( '優惠碼格式不正確。', 'now-crm' ), $groupNo, $rowNo );
			}

			if ( ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}

		if ( array() === $codes ) {
			return self::row_error( 'empty_value', __( '請至少選擇一個值。', 'now-crm' ), $groupNo, $rowNo );
		}

		if ( in_array( $operator, $single_ops, true ) ) {
			if ( 1 !== count( $codes ) ) {
				return self::row_error( 'value_single', __( '此運算子需要單一值。', 'now-crm' ), $groupNo, $rowNo );
			}

			return $codes[0];
		}

		return $codes;
	}

	/**
	 * A validation error with the group and row it belongs to.
	 */
	private static function row_error( string $reason, string $message, int $groupNo, int $rowNo ): WP_Error {
		return new WP_Error(
			'nowcrm_advanced_filter_invalid',
			sprintf(
				/* translators: 1: group number, 2: row number, 3: reason. */
				__( '第 %1$d 組第 %2$d 個條件：%3$s', 'now-crm' ),
				$groupNo,
				$rowNo,
				$message
			),
			array(
				'group'  => $groupNo,
				'row'    => $rowNo,
				'reason' => $reason,
			)
		);
	}

	// ------------------------------------------------------------------
	// Compilation
	// ------------------------------------------------------------------

	/**
	 * Compile one email activity condition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_email_activity( array $definition, string $operator, $value ): ?array {
		$ids = self::clean_ids( $value );

		if ( null === $ids ) {
			return null;
		}

		if ( in_array( $operator, array( '=', '!=' ), true ) && 1 !== count( $ids ) ) {
			return null;
		}

		if ( count( $ids ) > AdvancedFilters::MAX_VALUES ) {
			return null;
		}

		$live = self::live_campaign_ids( $ids );

		if ( count( $live ) !== count( $ids ) ) {
			// A stale or draft campaign reference must not widen the rule.
			return null;
		}

		$field    = (string) $definition['field'];
		$negative = in_array( $operator, array( 'not_in', '!=' ), true );

		if ( ! in_array( $operator, array( 'in', 'not_in', '=', '!=' ), true ) ) {
			return null;
		}

		switch ( $field ) {
			case 'sent':
			case 'received':
			case 'opened':
			case 'clicked':
				return self::campaign_predicate( $ids, $field, $negative );
		}

		return null;
	}

	/**
	 * One campaign predicate scoped to accepted broadcast jobs.
	 *
	 * Test sends never match: only kind `campaign` jobs count. "Not opened"
	 * and "not clicked" require an accepted send of the same campaign, so a
	 * contact outside that historical send is never included.
	 *
	 * @param int[]  $ids      Live campaign IDs.
	 * @param string $kind     sent, received, opened or clicked.
	 * @param bool   $negative Negate the predicate.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function campaign_predicate( array $ids, string $kind, bool $negative ): ?array {
		if ( array() === $ids ) {
			return null;
		}

		$jobs   = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );
		$in     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$scope  = '(j.contact_id = c.id OR (j.contact_id IS NULL AND j.recipient = c.email))';
		$base   = "j.kind = 'campaign' AND j.status = %s AND j.campaign_id IN ({$in}) AND {$scope}";
		$params = array_merge( array( EmailJobRepository::STATUS_ACCEPTED ), $ids );

		switch ( $kind ) {
			case 'sent':
				$sql = "EXISTS (SELECT 1 FROM {$jobs} j WHERE {$base})";

				return self::fragment( $negative ? 'NOT ' . $sql : $sql, $params );

			case 'received':
				$bounce = "NOT EXISTS (SELECT 1 FROM {$events} e WHERE e.type = 'bounce'"
					. ' AND (e.job_id = j.id OR (e.contact_id = c.id AND e.campaign_id = j.campaign_id)))';
				$sql    = "EXISTS (SELECT 1 FROM {$jobs} j WHERE {$base} AND {$bounce})";

				return self::fragment( $negative ? 'NOT ' . $sql : $sql, $params );

			case 'opened':
			case 'clicked':
				$type  = 'opened' === $kind ? 'open' : 'click';
				$event = "EXISTS (SELECT 1 FROM {$events} e WHERE e.job_id = j.id AND e.type = '{$type}')";

				if ( $negative ) {
					return self::fragment( "EXISTS (SELECT 1 FROM {$jobs} j WHERE {$base} AND NOT {$event})", $params );
				}

				return self::fragment( "EXISTS (SELECT 1 FROM {$jobs} j WHERE {$base} AND {$event})", $params );
		}

		return null;
	}

	/**
	 * Compile one last-activity date condition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_last_date( array $definition, string $operator, $value ): ?array {
		if ( AdvancedFilters::is_valueless( $operator ) ) {
			$value = null;
		} elseif ( ! is_scalar( $value ) ) {
			return null;
		}

		$jobs   = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );

		switch ( (string) $definition['field'] ) {
			case 'last_sent':
				$scope  = '(j.contact_id = c.id OR (j.contact_id IS NULL AND j.recipient = c.email))';
				$scalar = "SELECT MAX(j.accepted_at) FROM {$jobs} j"
					. " WHERE j.kind = 'campaign' AND j.status = %s AND {$scope}";
				$params = array( EmailJobRepository::STATUS_ACCEPTED );
				break;

			case 'last_opened':
			case 'last_clicked':
				$type   = 'last_opened' === $definition['field'] ? 'open' : 'click';
				$scalar = "SELECT MAX(e.occurred_at) FROM {$events} e"
					. " WHERE e.contact_id = c.id AND e.campaign_id IS NOT NULL AND e.type = '{$type}'";
				$params = array();
				break;

			default:
				return null;
		}

		return self::compile_scalar_date( $scalar, $params, $operator, $value );
	}

	/**
	 * Compile one order metric condition from the stored order links.
	 *
	 * The links are native data, so these fields work with or without
	 * WooCommerce. Totals stay in one currency; cross-currency sums never
	 * happen because each currency is its own field.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_order_metric( array $definition, string $operator, $value ): ?array {
		$orders = Schema::table( 'order_links' );
		$counted = "ol.contact_id = c.id AND ol.is_counted = 1";

		switch ( (string) $definition['field'] ) {
			case 'order_count':
				if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,15}$/', trim( (string) $value ) ) ) {
					return null;
				}

				if ( ! in_array( $operator, array( '>', '<', '=', '!=' ), true ) ) {
					return null;
				}

				$symbol = '!=' === $operator ? '<>' : $operator;

				return self::fragment(
					"(SELECT COUNT(*) FROM {$orders} ol WHERE {$counted}) {$symbol} %d",
					array( (int) $value )
				);

			case 'first_order_date':
			case 'last_order_date':
				$aggregate = 'first_order_date' === $definition['field'] ? 'MIN' : 'MAX';
				$scalar    = "SELECT {$aggregate}(ol.date_created) FROM {$orders} ol WHERE {$counted}";

				return self::compile_scalar_date( $scalar, array(), $operator, is_scalar( $value ) ? $value : null );

			default:
				break;
		}

		$currency = (string) ( $definition['currency'] ?? '' );

		if ( '' === $currency || ! str_starts_with( (string) $definition['field'], 'total_spent_' ) ) {
			return null;
		}

		if ( ! is_scalar( $value ) || ! preg_match( '/^-?\d{1,14}(\.\d{1,6})?$/', trim( (string) $value ) ) ) {
			return null;
		}

		if ( ! in_array( $operator, array( '>', '<', '=', '!=' ), true ) ) {
			return null;
		}

		$symbol = '!=' === $operator ? '<>' : $operator;
		$scalar = "SELECT COALESCE(SUM(ol.net_total), 0) FROM {$orders} ol WHERE {$counted} AND ol.currency = %s";

		return self::fragment(
			"({$scalar}) {$symbol} CAST(%s AS DECIMAL(20,6))",
			array( $currency, trim( (string) $value ) )
		);
	}

	/**
	 * Compile one purchased-product condition.
	 *
	 * WooCommerce keeps order items in the same tables for HPOS and legacy
	 * storage, so one query covers both. A requested ID matches a product or
	 * a variation row.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_products( array $definition, string $operator, $value ): ?array {
		if ( ! self::woo_available() ) {
			return null;
		}

		if ( AdvancedFilters::is_valueless( $operator ) ) {
			return self::item_predicate( array(), 'line_item', 'not_null' === $operator );
		}

		$ids = self::clean_ids( $value );

		if ( null === $ids || ( in_array( $operator, array( '=', '!=' ), true ) && 1 !== count( $ids ) ) ) {
			return null;
		}

		if ( ! in_array( $operator, array( 'in', 'not_in', '=', '!=' ), true ) ) {
			return null;
		}

		return self::item_predicate( $ids, 'line_item', in_array( $operator, array( 'in', '=' ), true ) );
	}

	/**
	 * Compile one product taxonomy condition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_taxonomy( array $definition, string $operator, $value ): ?array {
		$taxonomy = (string) ( $definition['taxonomy'] ?? '' );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! self::woo_available() ) {
			return null;
		}

		if ( AdvancedFilters::is_valueless( $operator ) ) {
			return self::taxonomy_predicate( $taxonomy, array(), 'not_null' === $operator );
		}

		$ids = self::clean_ids( $value );

		if ( null === $ids || ( in_array( $operator, array( '=', '!=' ), true ) && 1 !== count( $ids ) ) ) {
			return null;
		}

		if ( ! in_array( $operator, array( 'in', 'not_in', '=', '!=' ), true ) ) {
			return null;
		}

		$live = self::live_term_ids( $taxonomy, $ids );

		if ( null === $live ) {
			// A deleted or moved term must not widen the rule.
			return null;
		}

		return self::taxonomy_predicate( $taxonomy, $live, in_array( $operator, array( 'in', '=' ), true ) );
	}

	/**
	 * Compile one used-coupon condition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_coupons( array $definition, string $operator, $value ): ?array {
		global $wpdb;

		if ( ! self::woo_available() ) {
			return null;
		}

		if ( AdvancedFilters::is_valueless( $operator ) ) {
			$orders = Schema::table( 'order_links' );
			$items  = $wpdb->prefix . 'woocommerce_order_items';
			$sql    = "EXISTS (SELECT 1 FROM {$orders} ol INNER JOIN {$items} oi"
				. " ON oi.order_id = ol.order_id AND oi.order_item_type = 'coupon'"
				. ' WHERE ol.contact_id = c.id AND ol.is_counted = 1)';

			return self::fragment( 'not_null' === $operator ? $sql : 'NOT ' . $sql, array() );
		}

		$codes = self::clean_codes( $value );

		if ( null === $codes || ( in_array( $operator, array( '=', '!=' ), true ) && 1 !== count( $codes ) ) ) {
			return null;
		}

		if ( ! in_array( $operator, array( 'in', 'not_in', '=', '!=' ), true ) ) {
			return null;
		}

		$orders = Schema::table( 'order_links' );
		$items  = $wpdb->prefix . 'woocommerce_order_items';
		$in     = implode( ', ', array_fill( 0, count( $codes ), '%s' ) );

		$sql = "EXISTS (SELECT 1 FROM {$orders} ol INNER JOIN {$items} oi"
			. " ON oi.order_id = ol.order_id AND oi.order_item_type = 'coupon'"
			. " WHERE ol.contact_id = c.id AND ol.is_counted = 1 AND LOWER(oi.order_item_name) IN ({$in}))";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
		return self::fragment(
			in_array( $operator, array( 'in', '=' ), true ) ? $sql : 'NOT ' . $sql,
			$codes
		);
	}

	/**
	 * Compile one automation or sequence participation condition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_participation( array $definition, string $operator, $value ): ?array {
		$statuses = array();

		if ( self::PROVIDER_AUTOMATION === $definition['provider'] ) {
			$table    = Schema::table( 'automation_runs' );
			$ref      = 'automation_id';
			$statuses = array( 'completed', 'active', 'cancelled', 'waiting' );
		} elseif ( self::PROVIDER_SEQUENCE === $definition['provider'] ) {
			$table    = Schema::table( 'sequence_trackers' );
			$ref      = 'sequence_id';
			$statuses = array( 'completed', 'active', 'cancelled' );
		} else {
			return null;
		}

		$single_ops = array_merge( array( '=', '!=' ), $statuses );

		if ( self::PROVIDER_AUTOMATION === $definition['provider'] ) {
			// The filter stores automation IDs, not automation run IDs.
			$reference = self::existing_table_ids( Schema::table( 'automations' ), $value, $operator, $single_ops );
		} else {
			$reference = self::existing_sequence_ids( $value, $operator, $single_ops );
		}

		if ( null === $reference ) {
			return null;
		}

		list( $ids, $single ) = $reference;

		if ( in_array( $operator, array( 'in', 'not_in' ), true ) ) {
			$in  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql = "EXISTS (SELECT 1 FROM {$table} r WHERE r.contact_id = c.id AND r.{$ref} IN ({$in}))";

			return self::fragment( 'in' === $operator ? $sql : 'NOT ' . $sql, $ids );
		}

		if ( ! in_array( $operator, $statuses, true ) || ! $single ) {
			return null;
		}

		$sql = "EXISTS (SELECT 1 FROM {$table} r WHERE r.contact_id = c.id AND r.{$ref} = %d AND r.status = %s)";

		return self::fragment( $sql, array( $ids[0], $operator ) );
	}

	/**
	 * Line-item predicate for counted orders.
	 *
	 * @param int[]  $ids      Product or variation IDs. Empty means any item.
	 * @param string $type     Order item type.
	 * @param bool   $positive Match contacts with the items, or without them.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function item_predicate( array $ids, string $type, bool $positive ): array {
		global $wpdb;

		$orders = Schema::table( 'order_links' );
		$items  = $wpdb->prefix . 'woocommerce_order_items';
		$meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$params = array();

		if ( array() === $ids ) {
			$sql = "EXISTS (SELECT 1 FROM {$orders} ol INNER JOIN {$items} oi"
				. ' ON oi.order_id = ol.order_id'
				. " WHERE ol.contact_id = c.id AND ol.is_counted = 1 AND oi.order_item_type = %s)";
			$params[] = $type;
		} else {
			$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql  = "EXISTS (SELECT 1 FROM {$orders} ol INNER JOIN {$items} oi"
				. ' ON oi.order_id = ol.order_id'
				. " INNER JOIN {$meta} pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'"
				. " INNER JOIN {$meta} vm ON vm.order_item_id = oi.order_item_id AND vm.meta_key = '_variation_id'"
				. " WHERE ol.contact_id = c.id AND ol.is_counted = 1 AND oi.order_item_type = %s"
				. " AND (pm.meta_value IN ({$in}) OR vm.meta_value IN ({$in})))";
			$params = array_merge( array( $type ), $ids, $ids );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
		return self::fragment( $positive ? $sql : 'NOT ' . $sql, $params );
	}

	/**
	 * Product taxonomy predicate for counted orders.
	 *
	 * @param string $taxonomy Taxonomy name from the field definition.
	 * @param int[]  $ids      Term IDs. Empty means any term of the taxonomy.
	 * @param bool   $positive Match contacts with the terms, or without them.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function taxonomy_predicate( string $taxonomy, array $ids, bool $positive ): array {
		global $wpdb;

		$orders = Schema::table( 'order_links' );
		$items  = $wpdb->prefix . 'woocommerce_order_items';
		$meta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$params = array( $taxonomy );

		$term_filter = '';

		if ( array() !== $ids ) {
			$in          = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$term_filter = " AND tt.term_id IN ({$in})";
			$params      = array_merge( $params, $ids );
		}

		$sql = "EXISTS (SELECT 1 FROM {$orders} ol INNER JOIN {$items} oi"
			. ' ON oi.order_id = ol.order_id'
			. " INNER JOIN {$meta} pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'"
			. " INNER JOIN {$meta} vm ON vm.order_item_id = oi.order_item_id AND vm.meta_key = '_variation_id'"
			. " INNER JOIN {$wpdb->term_relationships} tr ON (tr.object_id = pm.meta_value OR tr.object_id = vm.meta_value)"
			. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
			. " AND tt.taxonomy = %s{$term_filter}"
			. " WHERE ol.contact_id = c.id AND ol.is_counted = 1 AND oi.order_item_type = %s)";
		$params[] = 'line_item';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
		return self::fragment( $positive ? $sql : 'NOT ' . $sql, $params );
	}

	/**
	 * Date comparison against a scalar subquery.
	 *
	 * @param string $scalar        Subquery SQL without outer parentheses.
	 * @param array  $scalar_params Parameters of the subquery, in order.
	 * @param string $operator      Operator.
	 * @param mixed  $value         Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_scalar_date( string $scalar, array $scalar_params, string $operator, $value ): ?array {
		if ( AdvancedFilters::is_valueless( $operator ) ) {
			return self::fragment(
				'not_null' === $operator ? "({$scalar}) IS NOT NULL" : "({$scalar}) IS NULL",
				$scalar_params
			);
		}

		if ( in_array( $operator, array( 'days_before', 'days_within' ), true ) ) {
			if ( ! is_int( $value ) ) {
				return null;
			}

			$cutoff = self::cutoff_date( $value );

			if ( 'days_before' === $operator ) {
				return self::fragment( "({$scalar}) < %s", array_merge( $scalar_params, array( $cutoff ) ) );
			}

			return self::fragment(
				"(({$scalar}) >= %s AND ({$scalar}) <= %s)",
				array_merge( $scalar_params, array( $cutoff ), $scalar_params, array( self::today() . ' 23:59:59' ) )
			);
		}

		if ( ! is_string( $value ) || ! self::is_date( $value ) ) {
			return null;
		}

		switch ( $operator ) {
			case 'before':
				return self::fragment( "({$scalar}) < %s", array_merge( $scalar_params, array( $value ) ) );

			case 'after':
				return self::fragment( "({$scalar}) > %s", array_merge( $scalar_params, array( $value ) ) );

			case 'date_equal':
				return self::fragment(
					"(({$scalar}) >= %s AND ({$scalar}) <= %s)",
					array_merge( $scalar_params, array( $value . ' 00:00:00' ), $scalar_params, array( $value . ' 23:59:59' ) )
				);
		}

		return null;
	}

	// ------------------------------------------------------------------
	// Reference checks
	// ------------------------------------------------------------------

	/**
	 * Campaign IDs that are live choices: existing, non-draft posts with an
	 * accepted broadcast send. Test sends never qualify a campaign.
	 *
	 * @param int[] $ids Requested campaign IDs.
	 * @return int[] Live IDs among the requested ones.
	 */
	private static function live_campaign_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$jobs = Schema::table( 'email_jobs' );
		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p"
				. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
				. " INNER JOIN {$jobs} j ON j.campaign_id = p.ID AND j.kind = 'campaign' AND j.status = %s"
				. " WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')"
				. " AND pm.meta_value <> 'draft' AND p.ID IN ({$in})",
				array_merge(
					array( Campaigns::META_STATUS, EmailJobRepository::STATUS_ACCEPTED, Campaigns::POST_TYPE ),
					$ids
				)
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Term IDs that still exist in a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $ids      Requested term IDs.
	 * @return int[]|null Live IDs, or null when the source is unavailable.
	 */
	private static function live_term_ids( string $taxonomy, array $ids ): ?array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'number'     => count( $ids ),
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return null;
		}

		$found = array_map( 'intval', (array) $terms );

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $found, true ) ) {
				return null;
			}
		}

		return $ids;
	}

	/**
	 * Validate automation or sequence IDs from a bare plan value.
	 *
	 * @param mixed    $value      Raw value.
	 * @param string   $operator   Operator.
	 * @param string[] $single_ops Operators that take one ID.
	 * @return array{0: int[], 1: bool}|null IDs and whether exactly one was given.
	 */
	private static function reference_ids( $value, string $operator, array $single_ops ): ?array {
		$ids = self::clean_ids( $value );

		if ( null === $ids ) {
			return null;
		}

		if ( count( $ids ) > AdvancedFilters::MAX_VALUES ) {
			return null;
		}

		if ( in_array( $operator, $single_ops, true ) && 1 !== count( $ids ) ) {
			return null;
		}

		if ( ! in_array( $operator, array_merge( array( 'in', 'not_in' ), $single_ops ), true ) ) {
			return null;
		}

		return array( $ids, 1 === count( $ids ) );
	}

	/**
	 * Table row IDs among the requested ones that still exist.
	 *
	 * @param string   $table      Trusted table name.
	 * @param mixed    $value      Raw value.
	 * @param string   $operator   Operator.
	 * @param string[] $single_ops Operators that take one ID.
	 * @return array{0: int[], 1: bool}|null
	 */
	private static function existing_table_ids( string $table, $value, string $operator, array $single_ops ): ?array {
		$reference = self::reference_ids( $value, $operator, $single_ops );

		if ( null === $reference ) {
			return null;
		}

		list( $ids ) = $reference;

		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted table only.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$in})", $ids ) );
		$rows = array_map( 'intval', (array) $rows );

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $rows, true ) ) {
				return null;
			}
		}

		return $reference;
	}

	/**
	 * Sequence IDs among the requested ones that still exist.
	 *
	 * @param mixed    $value      Raw value.
	 * @param string   $operator   Operator.
	 * @param string[] $single_ops Operators that take one ID.
	 * @return array{0: int[], 1: bool}|null
	 */
	private static function existing_sequence_ids( $value, string $operator, array $single_ops ): ?array {
		$reference = self::reference_ids( $value, $operator, $single_ops );

		if ( null === $reference ) {
			return null;
		}

		list( $ids ) = $reference;

		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ID IN ({$in})",
				array_merge( array( SequenceRepository::POST_TYPE ), $ids )
			)
		);
		$rows = array_map( 'intval', (array) $rows );

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $rows, true ) ) {
				return null;
			}
		}

		return $reference;
	}

	// ------------------------------------------------------------------
	// Catalog
	// ------------------------------------------------------------------

	/**
	 * UI metadata for one built-in contact field.
	 *
	 * @param array<string, mixed> $definition Raw definition.
	 * @return array<string, mixed>
	 */
	private static function public_builtin_field( array $definition ): array {
		$public = array(
			'provider' => (string) $definition['provider'],
			'field'    => (string) $definition['field'],
			'label'    => (string) $definition['label'],
			'type'     => (string) $definition['type'],
			'ops'      => array_values( (array) $definition['ops'] ),
		);

		foreach ( array( 'options', 'term_type', 'number_kind' ) as $key ) {
			if ( isset( $definition[ $key ] ) ) {
				$public[ $key ] = $definition[ $key ];
			}
		}

		return $public;
	}

	/**
	 * UI metadata for one campaign field. Raw SQL never leaves this class.
	 *
	 * @param string               $key        Full `provider.field` key.
	 * @param array<string, mixed> $definition Raw definition.
	 * @return array<string, mixed>
	 */
	private static function public_field( string $key, array $definition ): array {
		$public = array(
			'provider' => (string) $definition['provider'],
			'field'    => (string) $definition['field'],
			'label'    => (string) $definition['label'],
			'type'     => (string) $definition['type'],
			'ops'      => array_values( (array) $definition['ops'] ),
		);

		foreach ( array( 'number_kind', 'lookup', 'help' ) as $name ) {
			if ( isset( $definition[ $name ] ) ) {
				$public[ $name ] = $definition[ $name ];
			}
		}

		$source = $definition['options_source'] ?? null;

		if ( is_string( $source ) && '' !== $source ) {
			$public['lookup'] = true;

			if ( in_array( $source, self::CATALOG_OPTION_SOURCES, true ) ) {
				$result = self::options( $key, '', array() );

				if ( ! is_wp_error( $result ) ) {
					$map = array();

					foreach ( $result['options'] as $row ) {
						$map[ (string) $row['value'] ] = (string) $row['label'];
					}

					$public['options'] = $map;
				}
			}
		}

		return $public;
	}

	// ------------------------------------------------------------------
	// Options
	// ------------------------------------------------------------------

	/**
	 * Campaign choices: existing non-draft campaigns with accepted broadcast
	 * history. Campaigns with test sends are marked; test-only campaigns are
	 * absent.
	 *
	 * @param string $search   Free text filter.
	 * @param array  $selected Campaign IDs to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}|WP_Error
	 */
	private static function campaign_options( string $search, array $selected ) {
		global $wpdb;

		$jobs     = Schema::table( 'email_jobs' );
		$ids      = self::selected_ids( $selected );
		$search   = mb_substr( trim( $search ), 0, 100 );
		$rows     = array();
		$seen     = array();
		$accepted = EmailJobRepository::STATUS_ACCEPTED;

		$select = "SELECT DISTINCT p.ID, p.post_title,"
			. " EXISTS (SELECT 1 FROM {$jobs} t WHERE t.campaign_id = p.ID AND t.kind = 'test') AS has_test"
			. " FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
			. " INNER JOIN {$jobs} j ON j.campaign_id = p.ID AND j.kind = 'campaign' AND j.status = %s"
			. " WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')"
			. ' AND pm.meta_value <> %s';

		$base_params = array( Campaigns::META_STATUS, $accepted, Campaigns::POST_TYPE, 'draft' );

		if ( array() !== $ids ) {
			$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted tables only.
			$found = $wpdb->get_results(
				$wpdb->prepare( "{$select} AND p.ID IN ({$in}) ORDER BY p.post_title ASC", array_merge( $base_params, $ids ) ),
				ARRAY_A
			);

			foreach ( (array) $found as $row ) {
				$seen[ (int) $row['ID'] ] = true;
				$rows[]                   = self::campaign_row( $row );
			}
		}

		$limit = self::OPTIONS_SEARCH_LIMIT;

		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders, trusted tables and integer only.
			$found = $wpdb->get_results(
				$wpdb->prepare( "{$select} ORDER BY p.post_title ASC LIMIT {$limit}", $base_params ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders, trusted tables and integer only.
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"{$select} AND p.post_title LIKE %s ORDER BY p.post_title ASC LIMIT {$limit}",
					array_merge( $base_params, array( '%' . $wpdb->esc_like( $search ) . '%' ) )
				),
				ARRAY_A
			);
		}

		foreach ( (array) $found as $row ) {
			if ( isset( $seen[ (int) $row['ID'] ] ) ) {
				continue;
			}

			$rows[] = self::campaign_row( $row );
		}

		return array( 'options' => $rows );
	}

	/**
	 * One campaign option row. A test-send marker distinguishes test jobs.
	 *
	 * @param array<string, mixed> $row Campaign row.
	 * @return array{value: string, label: string}
	 */
	private static function campaign_row( array $row ): array {
		$title = trim( (string) ( $row['post_title'] ?? '' ) );

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %d: campaign ID. */
				__( '電子報 #%d', 'now-crm' ),
				(int) $row['ID']
			);
		}

		if ( ! empty( $row['has_test'] ) ) {
			$title .= __( '（含測試寄送）', 'now-crm' );
		}

		return array(
			'value' => (string) (int) $row['ID'],
			'label' => $title,
		);
	}

	/**
	 * Product and variation choices.
	 *
	 * @param string $search   Free text filter.
	 * @param array  $selected Product or variation IDs to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}|WP_Error
	 */
	private static function product_options( string $search, array $selected ) {
		if ( ! self::woo_available() ) {
			return self::unavailable();
		}

		$ids    = self::selected_ids( $selected );
		$rows   = array();
		$seen   = array();
		$search = mb_substr( trim( $search ), 0, 100 );

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post || 'trash' === $post->post_status ) {
				continue;
			}

			if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$rows[]      = array(
				'value' => (string) $id,
				'label' => self::product_label( $id, $post->post_title ),
			);
		}

		$args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => 'publish',
			'numberposts'    => self::OPTIONS_SEARCH_LIMIT,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'suppress_filters' => false,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( array() !== $seen ) {
			$args['post__not_in'] = array_keys( $seen );
		}

		$found = get_posts( $args );

		foreach ( (array) $found as $id ) {
			$post = get_post( (int) $id );

			if ( ! $post ) {
				continue;
			}

			$rows[] = array(
				'value' => (string) (int) $id,
				'label' => self::product_label( (int) $id, $post->post_title ),
			);
		}

		return array( 'options' => $rows );
	}

	/**
	 * Product label with variation attributes when WooCommerce is present.
	 */
	private static function product_label( int $id, string $fallback ): string {
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );

			if ( $product && method_exists( $product, 'get_formatted_name' ) ) {
				$name = (string) $product->get_formatted_name();

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		$fallback = trim( $fallback );

		return '' !== $fallback ? $fallback . ' (#' . $id . ')' : '#' . $id;
	}

	/**
	 * Product category or tag choices.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $search   Free text filter.
	 * @param array  $selected Term IDs to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}|WP_Error
	 */
	private static function term_options( string $taxonomy, string $search, array $selected ) {
		if ( ! taxonomy_exists( $taxonomy ) || ! self::woo_available() ) {
			return self::unavailable();
		}

		$ids    = self::selected_ids( $selected );
		$terms  = array();
		$seen   = array();
		$search = mb_substr( trim( $search ), 0, 100 );

		if ( array() !== $ids ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'include'    => $ids,
					'number'     => count( $ids ),
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $found ) ) {
				return $found;
			}

			foreach ( (array) $found as $term ) {
				$seen[ (int) $term->term_id ] = true;
				$terms[]                      = $term;
			}
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'number'     => self::OPTIONS_SEARCH_LIMIT,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		if ( array() !== $seen ) {
			$args['exclude'] = array_keys( $seen );
		}

		$found = get_terms( $args );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		foreach ( (array) $found as $term ) {
			$terms[] = $term;
		}

		$rows = array();

		foreach ( $terms as $term ) {
			$rows[] = array(
				'value' => (string) (int) $term->term_id,
				'label' => (string) $term->name,
			);
		}

		return array( 'options' => $rows );
	}

	/**
	 * Used coupon code choices from the order item tables.
	 *
	 * @param string $search   Free text filter.
	 * @param array  $selected Codes to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}|WP_Error
	 */
	private static function coupon_options( string $search, array $selected ) {
		global $wpdb;

		if ( ! self::woo_available() ) {
			return self::unavailable();
		}

		$items  = $wpdb->prefix . 'woocommerce_order_items';
		$search = mb_substr( trim( $search ), 0, 100 );
		$codes  = self::selected_codes( $selected );
		$rows   = array();
		$seen   = array();

		if ( array() !== $codes ) {
			$in = implode( ', ', array_fill( 0, count( $codes ), '%s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted table only.
			$stored = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_item_name FROM {$items} WHERE order_item_type = 'coupon' AND LOWER(order_item_name) IN ({$in})",
					$codes
				)
			);

			foreach ( (array) $stored as $name ) {
				$name = (string) $name;

				if ( '' === $name ) {
					continue;
				}

				$seen[ strtolower( $name ) ] = true;
				$rows[]                      = array(
					'value' => $name,
					'label' => $name,
				);
			}

			foreach ( $codes as $code ) {
				if ( ! isset( $seen[ $code ] ) ) {
					$seen[ $code ] = true;
					$rows[]        = array(
						'value' => $code,
						'label' => $code,
					);
				}
			}
		}

		$limit = self::OPTIONS_SEARCH_LIMIT;

		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table and integer only.
			$stored = $wpdb->get_col( "SELECT DISTINCT order_item_name FROM {$items} WHERE order_item_type = 'coupon' AND order_item_name <> '' ORDER BY order_item_name ASC LIMIT {$limit}" );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders, trusted table and integer only.
			$stored = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_item_name FROM {$items} WHERE order_item_type = 'coupon' AND order_item_name LIKE %s ORDER BY order_item_name ASC LIMIT {$limit}",
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		}

		foreach ( (array) $stored as $name ) {
			$name = (string) $name;

			if ( '' === $name || isset( $seen[ strtolower( $name ) ] ) ) {
				continue;
			}

			$seen[ strtolower( $name ) ] = true;
			$rows[]                      = array(
				'value' => $name,
				'label' => $name,
			);
		}

		return array( 'options' => $rows );
	}

	/**
	 * Automation choices.
	 *
	 * @param string $search   Free text filter.
	 * @param array  $selected Automation IDs to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}
	 */
	private static function automation_options( string $search, array $selected ) {
		$repo   = new AutomationRepository();
		$ids    = self::selected_ids( $selected );
		$search = mb_strtolower( trim( $search ), 'UTF-8' );
		$rows   = array();
		$seen   = array();

		foreach ( $ids as $id ) {
			$row = $repo->get( $id );

			if ( ! $row ) {
				continue;
			}

			$seen[ $id ] = true;
			$rows[]      = array(
				'value' => (string) $id,
				'label' => self::entity_label( (string) ( $row['name'] ?? '' ), $id ),
			);
		}

		$count = 0;

		foreach ( $repo->all( array( 'limit' => 200 ) ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}

			$name = (string) ( $row['name'] ?? '' );

			if ( '' !== $search && false === mb_strpos( mb_strtolower( $name, 'UTF-8' ), $search, 0, 'UTF-8' ) ) {
				continue;
			}

			$rows[] = array(
				'value' => (string) $id,
				'label' => self::entity_label( $name, $id ),
			);

			if ( ++$count >= self::OPTIONS_SEARCH_LIMIT ) {
				break;
			}
		}

		return array( 'options' => $rows );
	}

	/**
	 * Sequence choices.
	 *
	 * @param string $search   Free text filter.
	 * @param array  $selected Sequence IDs to keep.
	 * @return array{options: array<int, array{value: string, label: string}>}
	 */
	private static function sequence_options( string $search, array $selected ) {
		$repo   = new SequenceRepository();
		$ids    = self::selected_ids( $selected );
		$search = mb_strtolower( trim( $search ), 'UTF-8' );
		$rows   = array();
		$seen   = array();

		foreach ( $ids as $id ) {
			$row = $repo->get( $id );

			if ( ! $row ) {
				continue;
			}

			$seen[ $id ] = true;
			$rows[]      = array(
				'value' => (string) $id,
				'label' => self::entity_label( (string) ( $row['name'] ?? '' ), $id ),
			);
		}

		$count = 0;

		foreach ( $repo->all( array( 'status' => 'all', 'limit' => SequenceRepository::MAX_SEQUENCES ) ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}

			$name = (string) ( $row['name'] ?? '' );

			if ( '' !== $search && false === mb_strpos( mb_strtolower( $name, 'UTF-8' ), $search, 0, 'UTF-8' ) ) {
				continue;
			}

			$rows[] = array(
				'value' => (string) $id,
				'label' => self::entity_label( $name, $id ),
			);

			if ( ++$count >= self::OPTIONS_SEARCH_LIMIT ) {
				break;
			}
		}

		return array( 'options' => $rows );
	}

	/**
	 * Display label with an ID fallback.
	 */
	private static function entity_label( string $name, int $id ): string {
		$name = trim( $name );

		return '' !== $name ? $name : '#' . $id;
	}

	/**
	 * Unavailable source error for option queries.
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error(
			'nowcrm_recipient_unavailable',
			__( '此欄位的選項目前無法使用。', 'now-crm' )
		);
	}

	// ------------------------------------------------------------------
	// Sources
	// ------------------------------------------------------------------

	/**
	 * Whether WooCommerce order item data is available.
	 *
	 * The item tables are shared by HPOS and legacy storage, so one check
	 * covers both modes.
	 */
	public static function woo_available(): bool {
		global $wpdb;

		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'woocommerce_order_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- availability check, no placeholders needed beyond LIKE.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Currency codes present in counted order links, plus the store currency.
	 *
	 * The currency stays explicit in the field key and label, so totals never
	 * mix currencies.
	 *
	 * @return string[]
	 */
	private static function currencies(): array {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded distinct list for the catalog.
		$stored = $wpdb->get_col( "SELECT DISTINCT currency FROM {$table} WHERE currency <> '' ORDER BY currency ASC LIMIT 50" );
		$codes  = array();

		foreach ( (array) $stored as $code ) {
			$code = strtoupper( trim( (string) $code ) );

			if ( preg_match( '/^[A-Z]{3}$/', $code ) && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$default = strtoupper( trim( (string) get_woocommerce_currency() ) );

			if ( preg_match( '/^[A-Z]{3}$/', $default ) && ! in_array( $default, $codes, true ) ) {
				array_unshift( $codes, $default );
			}
		}

		return array_slice( $codes, 0, self::MAX_CURRENCIES );
	}

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

	/**
	 * Positive integer IDs from a bare plan value. Null means unusable.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]|null
	 */
	private static function clean_ids( $value ): ?array {
		$items = is_array( $value ) ? array_values( $value ) : array( $value );
		$ids   = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || ! preg_match( '/^\d{1,15}$/', trim( (string) $item ) ) ) {
				return null;
			}

			$id = (int) $item;

			if ( $id <= 0 ) {
				return null;
			}

			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( array() === $ids || count( $ids ) > AdvancedFilters::MAX_VALUES ) {
			return null;
		}

		return $ids;
	}

	/**
	 * Lowercase coupon codes from a bare plan value. Null means unusable.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]|null
	 */
	private static function clean_codes( $value ): ?array {
		$items = is_array( $value ) ? array_values( $value ) : array( $value );
		$codes = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return null;
			}

			$code = strtolower( trim( (string) $item ) );

			if ( '' === $code || strlen( $code ) > 191 ) {
				return null;
			}

			if ( ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}

		if ( array() === $codes || count( $codes ) > AdvancedFilters::MAX_VALUES ) {
			return null;
		}

		return $codes;
	}

	/**
	 * Selected ID references from an option query.
	 *
	 * @param array $selected Raw references.
	 * @return int[]
	 */
	private static function selected_ids( array $selected ): array {
		$ids = array();

		foreach ( $selected as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$id = absint( $item );

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}

			if ( count( $ids ) >= self::OPTIONS_SELECTED_LIMIT ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Selected coupon codes from an option query.
	 *
	 * @param array $selected Raw references.
	 * @return string[]
	 */
	private static function selected_codes( array $selected ): array {
		$codes = array();

		foreach ( $selected as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$code = strtolower( trim( (string) $item ) );

			if ( '' === $code || strlen( $code ) > 191 || in_array( $code, $codes, true ) ) {
				continue;
			}

			$codes[] = $code;

			if ( count( $codes ) >= self::OPTIONS_SELECTED_LIMIT ) {
				break;
			}
		}

		return $codes;
	}

	/**
	 * Wrap one SQL fragment and its parameters.
	 *
	 * @param string           $sql    SQL fragment with placeholders.
	 * @param array<int, mixed> $params Parameters in placeholder order.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function fragment( string $sql, array $params ): array {
		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	/**
	 * Strict calendar date.
	 */
	private static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return false;
		}

		return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
	}

	/**
	 * Site-local date of "today".
	 */
	private static function today(): string {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * Site-local date N days before today.
	 */
	private static function cutoff_date( int $days ): string {
		$today = self::today();
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() );

		if ( ! $start ) {
			return $today;
		}

		return $start->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
	}
}
