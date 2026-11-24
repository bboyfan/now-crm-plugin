<?php
/**
 * Automation and step persistence.
 *
 * Steps are stored flat with a global ordinal. Top-level steps have a null
 * parent and null branch. A condition owns one level of `yes` and `no`
 * children; a split owns one level of `a` and `b` children. Nested branch
 * steps are allowed and resolved by the runtime.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AutomationRepository {

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public const KIND_ACTION    = 'action';
	public const KIND_WAIT      = 'wait';
	public const KIND_EMAIL     = 'email';
	public const KIND_CONDITION = 'condition';
	public const KIND_SPLIT     = 'split';
	public const KIND_GOAL      = 'goal';
	public const KIND_END       = 'end';

	public const GOAL_TERM_ATTACHED  = 'term_attached';
	public const GOAL_TERM_DETACHED  = 'term_detached';
	public const GOAL_STATUS_CHANGED = 'status_changed';

	public const BRANCH_YES = 'yes';
	public const BRANCH_NO  = 'no';
	public const BRANCH_A   = 'a';
	public const BRANCH_B   = 'b';
	public const BRANCH_TIMEOUT = 'timeout';

	public const GOAL_MODE_REQUIRED = 'required';
	public const GOAL_MODE_OPTIONAL = 'optional';

	public const SPLIT_DEFAULT_WEIGHT = 50;

	public const MAX_STEPS = 200;
	public const MAX_DEPTH = 5;
	public const MAX_WAIT  = 31536000;

	/**
	 * Native events a goal step can wait for.
	 *
	 * These keys are the persistence contract for goal config.
	 *
	 * @return array<string, string>
	 */
	public static function goal_events(): array {
		return array(
			self::GOAL_TERM_ATTACHED  => __( '加入名單或標籤', 'now-crm' ),
			self::GOAL_TERM_DETACHED  => __( '移出名單或標籤', 'now-crm' ),
			self::GOAL_STATUS_CHANGED => __( '聯絡人狀態變更', 'now-crm' ),
		);
	}

	/**
	 * Whether a goal config waits for a term event.
	 */
	public static function is_goal_term_event( string $event ): bool {
		return in_array( $event, array( self::GOAL_TERM_ATTACHED, self::GOAL_TERM_DETACHED ), true );
	}

	/**
	 * Fetch one automation with decoded config.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Automations that accept one trigger key, in creation order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active_by_trigger( string $trigger_key ): array {
		global $wpdb;

		$table = Schema::table( 'automations' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND trigger_key = %s ORDER BY id ASC",
				self::STATUS_ACTIVE,
				$trigger_key
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Whether any automation is active.
	 */
	public function has_active(): bool {
		global $wpdb;

		$table = Schema::table( 'automations' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE status = %s LIMIT 1", self::STATUS_ACTIVE )
		);
	}

	/**
	 * List automations.
	 *
	 * @param array<string, mixed> $args status, trigger_key, limit, offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		$table  = Schema::table( 'automations' );
		$where  = array( '1=1' );
		$params = array();

		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );

		if ( in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$trigger = (string) ( $args['trigger_key'] ?? '' );

		if ( '' !== $trigger ) {
			$where[]  = 'trigger_key = %s';
			$params[] = $trigger;
		}

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$sql      = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Filtered, paginated automations with status counts.
	 *
	 * The search matches the automation name literally. Wildcards in the input
	 * are escaped. Counts use the same search scope, so a status tab never
	 * disagrees with the rows.
	 *
	 * @param string $search   Name fragment.
	 * @param string $status   draft, active, paused, or empty for all.
	 * @param int    $page     Page number, clamped to the last page.
	 * @param int    $per_page Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, counts: array<string, int>, total: int, page: int, pages: int}
	 */
	public function catalog( string $search = '', string $status = '', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table    = Schema::table( 'automations' );
		$search   = trim( $search );
		$status   = sanitize_key( $status );
		$per_page = max( 1, min( 100, $per_page ) );
		$where    = array( '1=1' );
		$params   = array();

		if ( '' !== $search ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$clause = implode( ' AND ', $where );
		$counts = array(
			'all'    => 0,
			self::STATUS_DRAFT  => 0,
			self::STATUS_ACTIVE => 0,
			self::STATUS_PAUSED => 0,
		);

		$sql = "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$clause} GROUP BY status";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$rows = array() === $params ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$key = (string) $row['status'];

			if ( array_key_exists( $key, $counts ) && 'all' !== $key ) {
				$counts[ $key ]  = (int) $row['total'];
				$counts['all']  += (int) $row['total'];
			}
		}

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			$status = '';
			$total  = $counts['all'];
		} else {
			$total = $counts[ $status ];
		}

		$pages = (int) ceil( $total / $per_page );
		$page  = max( 1, $page );

		if ( $pages > 0 && $page > $pages ) {
			$page = $pages;
		}

		if ( 0 === $total ) {
			$page = 1;
		}

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$clause   = implode( ' AND ', $where );
		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;

		$sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- clause is built from fixed fragments; table name is trusted and values use placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array(
			'items'  => array_map( array( $this, 'cast' ), (array) $rows ),
			'counts' => $counts,
			'total'  => $total,
			'page'   => $page,
			'pages'  => $pages,
		);
	}

	/**
	 * Steps of one automation, ordered by ordinal.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function steps( int $automation_id ): array {
		global $wpdb;

		$table = Schema::table( 'automation_steps' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d ORDER BY ordinal ASC",
				$automation_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast_step' ), (array) $rows );
	}

	/**
	 * Sequence IDs an automation already references.
	 *
	 * A paused sequence stays valid for an existing reference. It never
	 * becomes valid for a new choice. The copy handler passes this list as
	 * the trusted set of the source automation.
	 *
	 * @return array<int, int>
	 */
	public function referenced_sequence_ids( int $automation_id ): array {
		$ids = array();

		foreach ( $this->steps( $automation_id ) as $step ) {
			if ( self::KIND_ACTION !== $step['kind'] || ActionRegistry::ADD_TO_SEQUENCE !== $step['action_key'] ) {
				continue;
			}

			$sequence_id = (int) ( $step['config']['sequence_id'] ?? 0 );

			if ( $sequence_id > 0 ) {
				$ids[ $sequence_id ] = $sequence_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * One step row.
	 *
	 * @return array<string, mixed>|null
	 */
	public function step( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_steps' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast_step( $row ) : null;
	}

	/**
	 * Nested step tree for the editor and duplication.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nested_steps( int $automation_id ): array {
		$steps = $this->steps( $automation_id );

		return $this->build_tree( $steps, null, null );
	}

	/**
	 * Build the persisted tree from flat rows.
	 *
	 * @param array<int, array<string, mixed>> $steps  Flat rows.
	 * @param int|null                         $parent Parent step ID.
	 * @param string|null                      $branch Branch.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_tree( array $steps, ?int $parent, ?string $branch ): array {
		$tree = array();

		foreach ( $steps as $step ) {
			if ( ( null === $parent ? null : (int) $parent ) !== $step['parent_step_id'] || $branch !== $step['branch'] ) {
				continue;
			}

			$node = array(
				'kind'       => $step['kind'],
				'action_key' => $step['action_key'],
				'config'     => $step['config'],
			);

			if ( self::KIND_WAIT === $step['kind'] ) {
				$node['delay_seconds'] = $step['delay_seconds'];
			}

			if ( self::KIND_CONDITION === $step['kind'] ) {
				$node['yes'] = $this->build_tree( $steps, (int) $step['id'], self::BRANCH_YES );
				$node['no']  = $this->build_tree( $steps, (int) $step['id'], self::BRANCH_NO );
			}

			if ( self::KIND_SPLIT === $step['kind'] ) {
				$node['a'] = $this->build_tree( $steps, (int) $step['id'], self::BRANCH_A );
				$node['b'] = $this->build_tree( $steps, (int) $step['id'], self::BRANCH_B );
			}

			if ( self::KIND_GOAL === $step['kind'] ) {
				$node['timeout'] = $this->build_tree( $steps, (int) $step['id'], self::BRANCH_TIMEOUT );
			}

			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * Run counts of one automation.
	 *
	 * @return array<string, int>
	 */
	public function run_counts( int $automation_id ): array {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE automation_id = %d GROUP BY status", $automation_id ),
			ARRAY_A
		);

		$counts = array(
			RunRepository::STATUS_ACTIVE    => 0,
			RunRepository::STATUS_WAITING   => 0,
			RunRepository::STATUS_COMPLETED => 0,
			RunRepository::STATUS_CANCELLED => 0,
			RunRepository::STATUS_FAILED    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Create or replace one automation and its full step graph.
	 *
	 * An update changes the status only when `status` is present. Without it
	 * the stored status stays as it is. A create without `status` starts as a
	 * draft. Identical step graphs are not replaced, so step IDs survive a
	 * metadata-only save.
	 *
	 * @param array<string, mixed> $data                   name, status, trigger_key, trigger_config, entry_config, steps, created_by.
	 * @param int                  $id                     Existing automation ID, or 0 to create.
	 * @param array<int, int>      $trusted_sequence_ids   Sequence IDs the caller may keep from a copied source.
	 * @return int|WP_Error
	 */
	public function save( array $data, int $id = 0, array $trusted_sequence_ids = array() ) {
		$lock = $id > 0 ? self::acquire_lifecycle_lock( $id ) : null;

		if ( $id > 0 && ! $lock ) {
			return new WP_Error( 'nowcrm_automation_locked', __( '此自動化正在處理中，請稍後再試。', 'now-crm' ) );
		}

		try {
			return $this->save_inner( $data, $id, $trusted_sequence_ids );
		} finally {
			if ( $lock ) {
				self::release_lifecycle_lock( $lock );
			}
		}
	}

	/**
	 * Persist one automation inside the caller-held lifecycle lock.
	 *
	 * @param array<string, mixed> $data                 Automation data.
	 * @param int                  $id                   Existing automation or 0.
	 * @param array<int, int>      $trusted_sequence_ids Trusted references.
	 * @return int|WP_Error
	 */
	private function save_inner( array $data, int $id, array $trusted_sequence_ids ) {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'nowcrm_automation_name', __( '請輸入自動化名稱。', 'now-crm' ) );
		}

		$has_status = array_key_exists( 'status', $data ) && null !== $data['status'] && '' !== $data['status'];
		$status     = $has_status ? sanitize_key( (string) $data['status'] ) : self::STATUS_DRAFT;

		if ( $has_status && ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			return new WP_Error( 'nowcrm_automation_status', __( '自動化狀態不正確。', 'now-crm' ) );
		}

		$trigger_key = sanitize_key( (string) ( $data['trigger_key'] ?? '' ) );

		if ( ! TriggerRegistry::is_registered( $trigger_key ) ) {
			return new WP_Error( 'nowcrm_automation_trigger', __( '請選擇有效的觸發事件。', 'now-crm' ) );
		}

		$trigger_config = $this->normalize_trigger_config( $data['trigger_config'] ?? array() );

		if ( is_wp_error( $trigger_config ) ) {
			return $trigger_config;
		}

		$entry_config = $this->normalize_entry_config( $data['entry_config'] ?? array() );

		if ( is_wp_error( $entry_config ) ) {
			return $entry_config;
		}

		$existing = $id > 0 ? $this->get( $id ) : null;

		$trusted = array_map( 'absint', $trusted_sequence_ids );

		if ( $existing ) {
			$trusted = array_merge( $this->referenced_sequence_ids( $id ), $trusted );
		}

		$error = null;
		$flat  = array();

		if ( ! isset( $data['steps'] ) || ! is_array( $data['steps'] ) ) {
			return new WP_Error( 'nowcrm_automation_steps', __( '自動化至少需要一個步驟。', 'now-crm' ) );
		}

		$this->flatten_steps( array_values( $data['steps'] ), null, null, 0, $flat, $error, $trusted );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( array() === $flat ) {
			return new WP_Error( 'nowcrm_automation_steps', __( '自動化至少需要一個步驟。', 'now-crm' ) );
		}

		if ( $id > 0 && ! $existing ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到自動化。', 'now-crm' ) );
		}

		if ( self::STATUS_ACTIVE === $status && array() === $flat ) {
			return new WP_Error( 'nowcrm_automation_empty', __( '沒有步驟的自動化不能啟用。', 'now-crm' ) );
		}

		if ( $existing ) {
			$structural = $trigger_key !== (string) $existing['trigger_key'] || ! $this->same_step_graph( $flat, $this->steps( $id ) );

			if ( $structural && $this->has_open_cart_runs( $id ) ) {
				return new WP_Error(
					'nowcrm_automation_live_runs',
					__( '此流程仍有進行中的購物車執行，請等待完成或取消後再修改步驟或觸發條件。', 'now-crm' )
				);
			}
		}

		$row = array(
			'name'           => $name,
			'trigger_key'    => $trigger_key,
			'trigger_config' => wp_json_encode( $trigger_config ),
			'entry_config'   => wp_json_encode( $entry_config ),
			'created_by'     => $existing ? $existing['created_by'] : ( ! empty( $data['created_by'] ) ? absint( $data['created_by'] ) : null ),
			'updated_at'     => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( ! $existing || $has_status ) {
			$row['status'] = $status;
			$formats[]     = '%s';
		}

		$wpdb->query( 'START TRANSACTION' );

		if ( $existing ) {
			$updated = $wpdb->update( Schema::table( 'automations' ), $row, array( 'id' => $id ), $formats, array( '%d' ) );

			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新自動化。', 'now-crm' ) );
			}
		} else {
			$row['created_at'] = $now;
			$formats[]         = '%s';

			$inserted = $wpdb->insert( Schema::table( 'automations' ), $row, $formats );

			if ( ! $inserted ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立自動化。', 'now-crm' ) );
			}

			$id = (int) $wpdb->insert_id;
		}

		$unchanged = $existing && $this->same_step_graph( $flat, $this->steps( $id ) );

		$old_ordinals = array();

		if ( $existing && ! $unchanged ) {
			$old_ordinals = $this->referenced_ordinals( $id );

			if ( ! $wpdb->delete( Schema::table( 'automation_steps' ), array( 'automation_id' => $id ), array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新自動化步驟。', 'now-crm' ) );
			}
		}

		$ids = array();

		foreach ( $unchanged ? array() : $flat as $index => $step ) {
			$parent_id = null;

			if ( null !== $step['parent_index'] ) {
				$parent_id = $ids[ $step['parent_index'] ] ?? null;

				if ( ! $parent_id ) {
					$wpdb->query( 'ROLLBACK' );

					return new WP_Error( 'nowcrm_automation_steps', __( '條件分支的階層不正確。', 'now-crm' ) );
				}
			}

			$inserted = $wpdb->insert(
				Schema::table( 'automation_steps' ),
				array(
					'automation_id'            => $id,
					'ordinal'                  => $index + 1,
					'parent_step_id'           => $parent_id,
					'branch'                   => $step['branch'],
					'kind'                     => $step['kind'],
					'action_key'               => $step['action_key'],
					'config'                   => wp_json_encode( $step['config'] ),
					'delay_seconds'            => $step['delay_seconds'],
					'cumulative_delay_seconds' => $step['cumulative_delay_seconds'],
					'created_at'               => $now,
					'updated_at'               => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			if ( ! $inserted ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立自動化步驟。', 'now-crm' ) );
			}

			$ids[ $index ] = (int) $wpdb->insert_id;
		}

		if ( $existing ) {
			$this->repoint_runs( $id, $old_ordinals );
		}

		$wpdb->query( 'COMMIT' );

		AutomationScheduler::sync();

		return $id;
	}

	/**
	 * Change only the status. Pausing keeps runs and their pointers.
	 *
	 * @return bool|WP_Error
	 */
	public function set_status( int $id, string $status ) {
		global $wpdb;

		$status = sanitize_key( $status );

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			return new WP_Error( 'nowcrm_automation_status', __( '自動化狀態不正確。', 'now-crm' ) );
		}

		$automation = $this->get( $id );

		if ( ! $automation ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到自動化。', 'now-crm' ) );
		}

		if ( self::STATUS_ACTIVE === $status && array() === $this->steps( $id ) ) {
			return new WP_Error( 'nowcrm_automation_empty', __( '沒有步驟的自動化不能啟用。', 'now-crm' ) );
		}

		$updated = $wpdb->update(
			Schema::table( 'automations' ),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新自動化。', 'now-crm' ) );
		}

		AutomationScheduler::sync();

		return true;
	}

	/**
	 * Delete an automation, its steps, runs, and run events.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$runs  = Schema::table( 'automation_runs' );
		$steps = Schema::table( 'automation_steps' );
		$table = Schema::table( 'automations' );
		$events = Schema::table( 'automation_run_events' );

		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are trusted.
		$wpdb->query( $wpdb->prepare( "DELETE e FROM {$events} e INNER JOIN {$runs} r ON r.id = e.run_id WHERE r.automation_id = %d", $id ) );
		$wpdb->delete( $runs, array( 'automation_id' => $id ), array( '%d' ) );
		$wpdb->delete( $steps, array( 'automation_id' => $id ), array( '%d' ) );
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		$wpdb->query( 'COMMIT' );

		\NowCRM\Editor\DummyPost::delete_for_target( 'automation', $id );

		AutomationScheduler::sync();

		return (bool) $deleted;
	}

	/**
	 * Flatten nested step input into ordinal rows.
	 *
	 * @param array<int, mixed>      $steps  Step list.
	 * @param int|null               $parent Parent index in $flat.
	 * @param string|null            $branch yes, no, or null at top level.
	 * @param int                    $depth  Nesting depth.
	 * @param array<int, mixed>      $flat   Output rows.
	 * @param WP_Error|mixed|null    $error  First validation error.
	 * @param array<int, int>        $trusted_sequence_ids Sequence IDs an edit may keep.
	 */
	private function flatten_steps( array $steps, ?int $parent, ?string $branch, int $depth, array &$flat, &$error, array $trusted_sequence_ids = array() ): void {
		if ( $depth > self::MAX_DEPTH ) {
			$error = new WP_Error( 'nowcrm_automation_depth', __( '條件分支的層級過深。', 'now-crm' ) );

			return;
		}

		foreach ( $steps as $step ) {
			if ( is_wp_error( $error ) ) {
				return;
			}

			if ( count( $flat ) >= self::MAX_STEPS ) {
				$error = new WP_Error( 'nowcrm_automation_steps', __( '自動化步驟數量超過上限。', 'now-crm' ) );

				return;
			}

			if ( ! is_array( $step ) ) {
				$error = new WP_Error( 'nowcrm_automation_steps', __( '步驟格式不正確。', 'now-crm' ) );

				return;
			}

			$normalized = $this->normalize_step( $step, $trusted_sequence_ids );

			if ( is_wp_error( $normalized ) ) {
				$error = $normalized;

				return;
			}

			$row = array(
				'kind'                     => $normalized['kind'],
				'action_key'               => $normalized['action_key'],
				'config'                   => $normalized['config'],
				'delay_seconds'            => $normalized['delay_seconds'],
				'cumulative_delay_seconds' => 0,
				'parent_index'             => $parent,
				'branch'                   => $branch,
			);

			$index          = count( $flat );
			$flat[ $index ] = $row;

			if ( self::KIND_CONDITION === $normalized['kind'] ) {
				foreach ( array( self::BRANCH_YES, self::BRANCH_NO ) as $side ) {
					$children = $step[ $side ] ?? array();

					if ( ! is_array( $children ) ) {
						$error = new WP_Error( 'nowcrm_automation_branch', __( '條件分支格式不正確。', 'now-crm' ) );

						return;
					}

					$this->flatten_steps( array_values( $children ), $index, $side, $depth + 1, $flat, $error, $trusted_sequence_ids );
				}
			}

			if ( self::KIND_SPLIT === $normalized['kind'] ) {
				foreach ( array( self::BRANCH_A, self::BRANCH_B ) as $side ) {
					$children = $step[ $side ] ?? array();

					if ( ! is_array( $children ) ) {
						$error = new WP_Error( 'nowcrm_automation_branch', __( '分流分支格式不正確。', 'now-crm' ) );

						return;
					}

					$this->flatten_steps( array_values( $children ), $index, $side, $depth + 1, $flat, $error, $trusted_sequence_ids );
				}
			}

			if ( self::KIND_GOAL === $normalized['kind'] ) {
				$children = $step['timeout'] ?? array();

				if ( ! is_array( $children ) ) {
					$error = new WP_Error( 'nowcrm_automation_branch', __( '目標逾時分支格式不正確。', 'now-crm' ) );

					return;
				}

				$this->flatten_steps( array_values( $children ), $index, self::BRANCH_TIMEOUT, $depth + 1, $flat, $error, $trusted_sequence_ids );
			}
		}

		$last_top = 0;

		foreach ( $flat as $index => $row ) {
			$base = null === $row['parent_index']
				? $last_top
				: (int) $flat[ $row['parent_index'] ]['cumulative_delay_seconds'];

			$value = $base + ( self::KIND_WAIT === $row['kind'] ? $row['delay_seconds'] : 0 );

			$flat[ $index ]['cumulative_delay_seconds'] = $value;

			if ( null === $row['parent_index'] ) {
				$last_top = $value;
			}
		}
	}

	/**
	 * Validate one step.
	 *
	 * @param array<string, mixed> $step Raw step.
	 * @param array<int, int>      $trusted_sequence_ids Sequence IDs an edit may keep.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_step( array $step, array $trusted_sequence_ids = array() ) {
		$kind = sanitize_key( (string) ( $step['kind'] ?? self::KIND_ACTION ) );

		$allowed = array( self::KIND_ACTION, self::KIND_WAIT, self::KIND_EMAIL, self::KIND_CONDITION, self::KIND_SPLIT, self::KIND_GOAL, self::KIND_END );

		if ( ! in_array( $kind, $allowed, true ) ) {
			return new WP_Error( 'nowcrm_automation_step_kind', __( '不支援的步驟類型。', 'now-crm' ) );
		}

		$raw    = isset( $step['config'] ) && is_array( $step['config'] ) ? $step['config'] : array();
		$config = array();
		$action = '';

		if ( self::KIND_ACTION === $kind ) {
			$action     = sanitize_key( (string) ( $step['action_key'] ?? '' ) );
			$normalized = ActionRegistry::normalize_config( $action, $raw, $trusted_sequence_ids );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$config = $normalized;
		} elseif ( self::KIND_WAIT === $kind ) {
			$delay = (int) ( $step['delay_seconds'] ?? $raw['delay_seconds'] ?? 0 );

			if ( $delay < 0 || $delay > self::MAX_WAIT ) {
				return new WP_Error( 'nowcrm_automation_wait', __( '等待時間不正確。', 'now-crm' ) );
			}

			$config = array( 'delay_seconds' => $delay );
			$step['delay_seconds'] = $delay;
		} elseif ( self::KIND_EMAIL === $kind ) {
			$content = (string) ( $raw['content'] ?? $raw['html'] ?? '' );
			$subject = trim( (string) ( $raw['subject'] ?? '' ) );

			if ( '' === trim( $content ) || '' === $subject ) {
				return new WP_Error( 'nowcrm_automation_email', __( 'Email 步驟需要主旨與內容。', 'now-crm' ) );
			}

			$mode = 'blocks' === ( $raw['mode'] ?? 'html' ) ? 'blocks' : 'html';

			$config = array(
				'subject'   => mb_substr( sanitize_text_field( $subject ), 0, 255 ),
				'content'   => $content,
				'mode'      => $mode,
				'preheader' => mb_substr( sanitize_text_field( (string) ( $raw['preheader'] ?? '' ) ), 0, 255 ),
			);

			foreach ( array( 'from_name', 'from_address', 'reply_to', 'footer_text' ) as $key ) {
				if ( ! empty( $raw[ $key ] ) ) {
					$config[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
				}
			}

			foreach ( array( 'from_address', 'reply_to' ) as $key ) {
				if ( isset( $config[ $key ] ) && ! is_email( $config[ $key ] ) ) {
					return new WP_Error( 'nowcrm_automation_email', __( 'Email 寄件或回覆地址不正確。', 'now-crm' ) );
				}
			}
		} elseif ( self::KIND_CONDITION === $kind ) {
			$raw_plan = $raw['conditions'] ?? $raw;

			$plan = ConditionEvaluator::normalize( $raw_plan );

			if ( is_wp_error( $plan ) ) {
				return $plan;
			}

			$config = array( 'conditions' => $plan );
		} elseif ( self::KIND_SPLIT === $kind ) {
			$normalized = self::normalize_split_config( $raw );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$config = $normalized;
		} elseif ( self::KIND_GOAL === $kind ) {
			$normalized = self::normalize_goal_config( $raw );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$config = $normalized;
		}

		return array(
			'kind'          => $kind,
			'action_key'    => $action,
			'config'        => $config,
			'delay_seconds' => (int) ( $step['delay_seconds'] ?? $config['delay_seconds'] ?? 0 ),
		);
	}

	/**
	 * Validate one split config.
	 *
	 * Weights are integer percentages that must total exactly 100. Omitting
	 * both weights stores the even 50/50 default.
	 *
	 * @param array<string, mixed> $raw Raw config.
	 * @return array<string, int>|WP_Error
	 */
	private static function normalize_split_config( array $raw ) {
		$has_a = array_key_exists( 'weight_a', $raw );
		$has_b = array_key_exists( 'weight_b', $raw );

		if ( ! $has_a && ! $has_b ) {
			return array(
				'weight_a' => self::SPLIT_DEFAULT_WEIGHT,
				'weight_b' => self::SPLIT_DEFAULT_WEIGHT,
			);
		}

		if ( $has_a !== $has_b ) {
			return new WP_Error( 'nowcrm_automation_split', __( '分流權重必須同時提供 A 與 B。', 'now-crm' ) );
		}

		$a = filter_var( $raw['weight_a'], FILTER_VALIDATE_INT );
		$b = filter_var( $raw['weight_b'], FILTER_VALIDATE_INT );

		if ( false === $a || false === $b || $a < 0 || $a > 100 || $b < 0 || $b > 100 || 100 !== $a + $b ) {
			return new WP_Error( 'nowcrm_automation_split', __( '分流權重必須是 0–100 的整數，且合計 100。', 'now-crm' ) );
		}

		return array(
			'weight_a' => $a,
			'weight_b' => $b,
		);
	}

	/**
	 * Validate one goal config.
	 *
	 * @param array<string, mixed> $raw Raw config.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_goal_config( array $raw ) {
		$event = sanitize_key( (string) ( $raw['event'] ?? '' ) );

		if ( ! isset( self::goal_events()[ $event ] ) ) {
			return new WP_Error( 'nowcrm_automation_goal', __( '請選擇有效的目標事件。', 'now-crm' ) );
		}

		$mode = self::GOAL_MODE_OPTIONAL === sanitize_key( (string) ( $raw['mode'] ?? '' ) )
			? self::GOAL_MODE_OPTIONAL
			: self::GOAL_MODE_REQUIRED;

		$timeout = self::GOAL_MODE_OPTIONAL === $mode ? 0 : (int) ( $raw['timeout_seconds'] ?? 0 );

		if ( $timeout < 0 || $timeout > self::MAX_WAIT ) {
			return new WP_Error( 'nowcrm_automation_goal', __( '目標逾時秒數不正確。', 'now-crm' ) );
		}

		if ( self::is_goal_term_event( $event ) ) {
			$ids = $raw['term_ids'] ?? ( $raw['term_id'] ?? array() );

			if ( ! is_array( $ids ) ) {
				$ids = array( $ids );
			}

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

			if ( array() === $ids || count( $ids ) > 100 ) {
				return new WP_Error( 'nowcrm_automation_goal', __( '目標步驟需要至少一個名單或標籤。', 'now-crm' ) );
			}

			$terms = new TermRepository();

			foreach ( $ids as $term_id ) {
				if ( ! $terms->get( $term_id ) ) {
					return new WP_Error( 'nowcrm_automation_goal', __( '目標步驟的名單或標籤不存在。', 'now-crm' ) );
				}
			}

			return array(
				'event'           => $event,
				'term_ids'        => $ids,
				'mode'            => $mode,
				'timeout_seconds' => $timeout,
			);
		}

		$statuses = $raw['statuses'] ?? ( $raw['status'] ?? array() );

		if ( ! is_array( $statuses ) ) {
			$statuses = array( $statuses );
		}

		$statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $statuses ) ) ) ) );

		if ( array() === $statuses ) {
			return new WP_Error( 'nowcrm_automation_goal', __( '目標步驟需要至少一個聯絡人狀態。', 'now-crm' ) );
		}

		foreach ( $statuses as $status ) {
			if ( ! Status::is_valid( $status ) ) {
				return new WP_Error( 'nowcrm_automation_goal', __( '目標步驟包含無效的聯絡人狀態。', 'now-crm' ) );
			}
		}

		return array(
			'event'           => $event,
			'statuses'        => $statuses,
			'mode'            => $mode,
			'timeout_seconds' => $timeout,
		);
	}

	/**
	 * Validate trigger filters.
	 *
	 * @param mixed $raw Raw config.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_trigger_config( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nowcrm_automation_trigger_config', __( '觸發條件格式不正確。', 'now-crm' ) );
		}

		$config = array();

		foreach ( array( 'statuses', 'term_ids', 'term_types', 'sources', 'conditions' ) as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			if ( 'conditions' === $key ) {
				$plan = ConditionEvaluator::normalize( $raw[ $key ] );

				if ( is_wp_error( $plan ) ) {
					return $plan;
				}

				$config['conditions'] = $plan;
				continue;
			}

			if ( ! is_array( $raw[ $key ] ) ) {
				return new WP_Error( 'nowcrm_automation_trigger_config', __( '觸發條件格式不正確。', 'now-crm' ) );
			}

			$values = array_values( array_unique( array_filter( array_map( 'strval', $raw[ $key ] ) ) ) );

			if ( 'statuses' === $key ) {
				foreach ( $values as $status ) {
					if ( ! Status::is_valid( sanitize_key( $status ) ) ) {
						return new WP_Error( 'nowcrm_automation_trigger_config', __( '觸發條件包含無效的狀態。', 'now-crm' ) );
					}
				}

				$values = array_map( 'sanitize_key', $values );
			} elseif ( 'term_ids' === $key ) {
				$values = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
			} elseif ( 'term_types' === $key ) {
				$values = array_values( array_intersect( $values, array( TermRepository::TYPE_LIST, TermRepository::TYPE_TAG ) ) );

				if ( array() === $values ) {
					return new WP_Error( 'nowcrm_automation_trigger_config', __( '觸發的名單或標籤類型不正確。', 'now-crm' ) );
				}
			} else {
				$values = array_map( 'sanitize_text_field', $values );
			}

			$config[ $key ] = $values;
		}

		return $config;
	}

	/**
	 * Validate entry settings.
	 *
	 * @param mixed $raw Raw config.
	 * @return array<string, bool>|WP_Error
	 */
	private function normalize_entry_config( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nowcrm_automation_entry_config', __( '進入條件格式不正確。', 'now-crm' ) );
		}

		return array(
			'run_once'  => ! empty( $raw['run_once'] ),
			'force_run' => ! empty( $raw['force_run'] ),
		);
	}

	/**
	 * Whether an incoming flat graph equals the stored step structure.
	 *
	 * The comparison ignores row IDs and timestamps. Config key order does
	 * not matter. Step order, parent, branch, kind, action, config values and
	 * delays must match.
	 *
	 * @param array<int, array<string, mixed>> $flat      Incoming normalized rows.
	 * @param array<int, array<string, mixed>> $persisted Stored rows by ordinal.
	 */
	private function same_step_graph( array $flat, array $persisted ): bool {
		$flat      = array_values( $flat );
		$persisted = array_values( $persisted );

		if ( count( $flat ) !== count( $persisted ) ) {
			return false;
		}

		$ordinals = array();

		foreach ( $persisted as $index => $step ) {
			$ordinals[ (int) $step['id'] ] = $index + 1;
		}

		foreach ( $flat as $index => $step ) {
			$stored = $persisted[ $index ];

			$parent = null === $stored['parent_step_id']
				? null
				: ( $ordinals[ (int) $stored['parent_step_id'] ] ?? 0 );

			$incoming_parent = null === $step['parent_index'] ? null : $step['parent_index'] + 1;

			if (
				$incoming_parent !== $parent
				|| $step['kind'] !== $stored['kind']
				|| $step['action_key'] !== $stored['action_key']
				|| $step['branch'] !== $stored['branch']
				|| (int) $step['delay_seconds'] !== (int) $stored['delay_seconds']
				|| self::canonical( $step['config'] ) !== self::canonical( $stored['config'] )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sort associative keys recursively so equal configs compare identical.
	 *
	 * List order is semantic and stays as it is.
	 *
	 * @param mixed $value Config value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array() === $value ) {
			return array();
		}

		$sorted = array();

		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::canonical( $item );
		}

		if ( ! array_is_list( $sorted ) ) {
			ksort( $sorted );
		}

		return $sorted;
	}

	/**
	 * Existing run pointers as ordinals, keyed by run.
	 *
	 * @return array<int, array{next: int|null, last: int|null}>
	 */
	private function referenced_ordinals( int $automation_id ): array {
		global $wpdb;

		$runs  = Schema::table( 'automation_runs' );
		$steps = Schema::table( 'automation_steps' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, next_step_id, last_completed_step_id FROM {$runs} WHERE automation_id = %d",
				$automation_id
			),
			ARRAY_A
		);

		$result = array();

		foreach ( (array) $rows as $row ) {
			$pointers = array(
				'next' => null,
				'last' => null,
			);

			foreach ( array( 'next' => 'next_step_id', 'last' => 'last_completed_step_id' ) as $key => $column ) {
				$step_id = (int) $row[ $column ];

				if ( $step_id > 0 ) {
					$ordinal = $wpdb->get_var( $wpdb->prepare( "SELECT ordinal FROM {$steps} WHERE id = %d", $step_id ) );

					if ( null !== $ordinal ) {
						$pointers[ $key ] = (int) $ordinal;
					}
				}
			}

			$result[ (int) $row['id'] ] = $pointers;
		}

		return $result;
	}

	/**
	 * Re-point runs after their step rows were replaced.
	 *
	 * @param array<int, array{next: int|null, last: int|null}> $old  Old ordinals keyed by run.
	 */
	private function repoint_runs( int $automation_id, array $old ): void {
		global $wpdb;

		if ( array() === $old ) {
			return;
		}

		$runs  = Schema::table( 'automation_runs' );
		$steps = Schema::table( 'automation_steps' );

		$new = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, ordinal FROM {$steps} WHERE automation_id = %d ORDER BY ordinal ASC", $automation_id ),
			ARRAY_A
		);

		$by_ordinal = array();

		foreach ( (array) $new as $row ) {
			$by_ordinal[ (int) $row['ordinal'] ] = (int) $row['id'];
		}

		$first = array() === $by_ordinal ? null : (int) reset( $by_ordinal );

		foreach ( $old as $run_id => $pointers ) {
			$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id ), ARRAY_A );

			if ( ! $run ) {
				continue;
			}

			$last_id = $this->map_before( $pointers['last'], $by_ordinal );
			$next_id = $this->map_after( $pointers['next'], $by_ordinal );

			$data   = array(
				'last_completed_step_id' => $last_id,
				'next_step_id'           => $next_id,
				'updated_at'             => gmdate( 'Y-m-d H:i:s' ),
			);
			$format = array( '%d', '%d', '%s' );

			if ( null === $next_id && in_array( (string) $run['status'], array( 'active', 'waiting' ), true ) ) {
				if ( $first && null === $pointers['next'] ) {
					$data['next_step_id'] = $first;
				} else {
					$data['status']      = 'completed';
					$data['finished_at'] = gmdate( 'Y-m-d H:i:s' );
					$data['next_run_at'] = null;
					$format[]            = '%s';
					$format[]            = '%s';
					$format[]            = '%s';
				}
			}

			if ( null === $last_id ) {
				$data['last_completed_step_id'] = null;
			}

			$wpdb->update( $runs, $data, array( 'id' => $run_id ), $format, array( '%d' ) );
		}
	}

	/**
	 * Largest new ordinal at or below an old ordinal.
	 *
	 * @param int|null         $ordinal Old ordinal.
	 * @param array<int, int>  $map     New IDs by ordinal.
	 */
	private function map_before( ?int $ordinal, array $map ): ?int {
		if ( null === $ordinal ) {
			return null;
		}

		$found = null;

		foreach ( $map as $candidate => $id ) {
			if ( $candidate <= $ordinal ) {
				$found = $id;
			}
		}

		return $found;
	}

	/**
	 * Smallest new ordinal at or above an old ordinal.
	 *
	 * @param int|null        $ordinal Old ordinal.
	 * @param array<int, int> $map     New IDs by ordinal.
	 */
	private function map_after( ?int $ordinal, array $map ): ?int {
		if ( null === $ordinal ) {
			return null;
		}

		foreach ( $map as $candidate => $id ) {
			if ( $candidate >= $ordinal ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Cast an automation row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['created_by']     = null === $row['created_by'] ? null : (int) $row['created_by'];
		$row['trigger_config'] = $this->decode( $row['trigger_config'] ?? '' );
		$row['entry_config']   = $this->decode( $row['entry_config'] ?? '' );

		return $row;
	}

	/**
	 * Run work under the lifecycle lock of one automation.
	 *
	 * Cart starts take this lock first and then the cart lock, matching save().
	 * No path takes the cart lock and then this lock. A structural definition
	 * edit and a new source run can therefore never interleave and orphan a
	 * waiting step pointer.
	 *
	 * @param int      $automation_id Automation ID.
	 * @param callable $callback      Work to run.
	 * @return mixed|WP_Error
	 */
	public static function with_lifecycle_lock( int $automation_id, callable $callback ) {
		$lock = self::acquire_lifecycle_lock( $automation_id );

		if ( ! $lock ) {
			return new WP_Error( 'nowcrm_automation_locked', __( '此自動化正在處理中，請稍後再試。', 'now-crm' ) );
		}

		try {
			return $callback();
		} finally {
			self::release_lifecycle_lock( $lock );
		}
	}

	/**
	 * Whether an automation has unfinished cart-scoped runs.
	 */
	private function has_open_cart_runs( int $automation_id ): bool {
		global $wpdb;

		if ( $automation_id <= 0 ) {
			return false;
		}

		$table = Schema::table( 'automation_runs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE automation_id = %d AND status IN (%s, %s) AND (cart_id IS NOT NULL OR source_key IS NOT NULL) LIMIT 1",
				$automation_id,
				RunRepository::STATUS_ACTIVE,
				RunRepository::STATUS_WAITING
			)
		);
	}

	private static function acquire_lifecycle_lock( int $automation_id ): ?string {
		global $wpdb;

		if ( $automation_id <= 0 ) {
			return null;
		}

		$key = 'nowcrm-flow-' . $automation_id . '-' . md5( DB_NAME . ':' . $wpdb->prefix );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) )
			? $key
			: null;
	}

	private static function release_lifecycle_lock( string $key ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	/**
	 * Cast a step row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast_step( array $row ): array {
		$row['id']                       = (int) $row['id'];
		$row['automation_id']            = (int) $row['automation_id'];
		$row['ordinal']                  = (int) $row['ordinal'];
		$row['parent_step_id']           = null === $row['parent_step_id'] ? null : (int) $row['parent_step_id'];
		$row['config']                   = $this->decode( $row['config'] ?? '' );
		$row['delay_seconds']            = (int) $row['delay_seconds'];
		$row['cumulative_delay_seconds'] = (int) $row['cumulative_delay_seconds'];

		return $row;
	}

	/**
	 * Decode a JSON text column.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private function decode( $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
