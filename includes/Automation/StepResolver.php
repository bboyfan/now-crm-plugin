<?php
/**
 * Branch-aware step traversal.
 *
 * After a normal step, the next step is the next sibling in the same branch.
 * When a branch is exhausted, traversal ascends to the parent branching step
 * and continues after it. A condition jumps to the first child of its chosen
 * `yes`/`no` branch; a split does the same for `a`/`b`. Either continues after
 * itself when the chosen branch is empty.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class StepResolver {

	private AutomationRepository $repository;

	public function __construct( ?AutomationRepository $repository = null ) {
		$this->repository = $repository ?? new AutomationRepository();
	}

	/**
	 * First top-level step of an automation.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first( int $automation_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_steps' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d AND parent_step_id IS NULL ORDER BY ordinal ASC LIMIT 1",
				$automation_id
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * First step of one branch of a branching step.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first_child( int $condition_id, string $branch ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_steps' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE parent_step_id = %d AND branch = %s ORDER BY ordinal ASC LIMIT 1",
				$condition_id,
				$branch
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Step that follows one step in execution order.
	 *
	 * @param array<string, mixed> $step Step row.
	 * @return array<string, mixed>|null
	 */
	public function next_after( array $step ): ?array {
		$parent = $step['parent_step_id'];
		$branch = $step['branch'];

		if ( null === $parent ) {
			$sibling = $this->sibling( (int) $step['automation_id'], null, $branch, (int) $step['ordinal'] );

			return $sibling ?: null;
		}

		$sibling = $this->sibling( (int) $step['automation_id'], (int) $parent, $branch, (int) $step['ordinal'] );

		if ( $sibling ) {
			return $sibling;
		}

		$parent_step = $this->repository->step( (int) $parent );

		return $parent_step ? $this->next_after( $parent_step ) : null;
	}

	/**
	 * Next step after a branching step chose one branch.
	 *
	 * @param array<string, mixed> $condition Condition or split step.
	 * @param string               $branch    yes, no, a, or b.
	 * @return array<string, mixed>|null
	 */
	public function branch_next( array $condition, string $branch ): ?array {
		$child = $this->first_child( (int) $condition['id'], $branch );

		return $child ?: $this->next_after( $condition );
	}

	/**
	 * Next sibling under the same parent and branch.
	 *
	 * @return array<string, mixed>|null
	 */
	private function sibling( int $automation_id, ?int $parent, ?string $branch, int $ordinal ): ?array {
		global $wpdb;

		$table  = Schema::table( 'automation_steps' );
		$where  = array( 'automation_id = %d', 'ordinal > %d' );
		$params = array( $automation_id, $ordinal );

		if ( null === $parent ) {
			$where[] = 'parent_step_id IS NULL';
		} else {
			$where[]  = 'parent_step_id = %d';
			$params[] = $parent;
		}

		if ( null === $branch ) {
			$where[] = 'branch IS NULL';
		} else {
			$where[]  = 'branch = %s';
			$params[] = $branch;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY ordinal ASC LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Cast a step row without a second repository call.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		$row['id']                       = (int) $row['id'];
		$row['automation_id']            = (int) $row['automation_id'];
		$row['ordinal']                  = (int) $row['ordinal'];
		$row['parent_step_id']           = null === $row['parent_step_id'] ? null : (int) $row['parent_step_id'];
		$row['config']                   = json_decode( (string) $row['config'], true );
		$row['config']                   = is_array( $row['config'] ) ? $row['config'] : array();
		$row['delay_seconds']            = (int) $row['delay_seconds'];
		$row['cumulative_delay_seconds'] = (int) $row['cumulative_delay_seconds'];

		return $row;
	}
}
