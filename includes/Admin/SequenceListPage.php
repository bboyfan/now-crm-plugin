<?php
/**
 * Sequence list, draft creation, lifecycle controls, and bounded enrollment.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Segments\SegmentRepository;
use NowCRM\Sequences\AudienceResolver;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Sequences\TrackerRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class SequenceListPage {

	public const PER_PAGE = WorkflowCatalog::PER_PAGE;

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			SequenceRepository::STATUS_DRAFT  => __( '草稿', 'now-crm' ),
			SequenceRepository::STATUS_ACTIVE => __( '啟用中', 'now-crm' ),
			SequenceRepository::STATUS_PAUSED => __( '已暫停', 'now-crm' ),
		);
	}

	/**
	 * Label of one status.
	 */
	public static function status_label( string $status ): string {
		return self::status_labels()[ $status ] ?? $status;
	}

	/**
	 * Human label for a stored audience.
	 *
	 * @param array<string, mixed> $audience Audience shape.
	 */
	public static function audience_label( array $audience ): string {
		$type = (string) ( $audience['type'] ?? 'all' );

		if ( AudienceResolver::TYPE_ALL === $type ) {
			return __( '所有已訂閱聯絡人', 'now-crm' );
		}

		if ( AudienceResolver::TYPE_LIST === $type || AudienceResolver::TYPE_TAG === $type ) {
			$term = ( new TermRepository() )->get( (int) ( $audience['term_id'] ?? 0 ) );
			$name = $term ? (string) $term['name'] : '#' . (int) ( $audience['term_id'] ?? 0 );

			return ( AudienceResolver::TYPE_TAG === $type ? __( '標籤：', 'now-crm' ) : __( '名單：', 'now-crm' ) ) . $name;
		}

		if ( AudienceResolver::TYPE_SEGMENT === $type ) {
			$segment = ( new SegmentRepository() )->find( $audience['segment_id'] ?? 0 );

			return __( '分眾：', 'now-crm' ) . ( $segment ? (string) $segment['name'] : '#' . (string) ( $audience['segment_id'] ?? '' ) );
		}

		if ( AudienceResolver::TYPE_FILTERS === $type ) {
			$groups = (array) ( $audience['filters']['groups'] ?? array() );

			return sprintf(
				/* translators: %d: number of filter groups. */
				__( '進階篩選：%d 組', 'now-crm' ),
				count( $groups )
			);
		}

		return __( '未設定', 'now-crm' );
	}

	/**
	 * Render the list.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$repository = new SequenceRepository();
		$trackers   = new TrackerRepository();
		$search     = sanitize_text_field( Menu::query( 's' ) );
		$status     = sanitize_key( Menu::query( 'status', 'all' ) );
		$statuses   = self::status_labels();

		if ( ! in_array( $status, array_merge( array( 'all' ), array_keys( $statuses ) ), true ) ) {
			$status = 'all';
		}

		$result = $repository->catalog(
			$search,
			$status,
			max( 1, absint( Menu::query( 'paged', '1' ) ) ),
			self::PER_PAGE
		);

		$items   = $result['items'];
		$counts  = $result['counts'];
		$total   = (int) $result['total'];
		$page    = (int) $result['page'];
		$pages   = (int) $result['pages'];
		$library = '' === $search ? (int) $counts['all'] : (int) $repository->catalog( '', 'all', 1, 1 )['counts']['all'];
		$show_form  = '1' === Menu::query( 'new' );
		$focus_id   = absint( Menu::query( 'id' ) );
		$next_after = absint( Menu::query( 'next_after_id' ) );
		$post_type  = get_post_type_object( SequenceRepository::POST_TYPE );
		$rows       = array();

		foreach ( $items as $item ) {
			$id        = (int) $item['id'];
			$edit_url  = Menu::page_url( Menu::SEQUENCE_SLUG, array( 'id' => $id ) );
			$runs_url  = Menu::page_url( Menu::SEQUENCE_RUNS_SLUG, array( 'id' => $id ) );
			$stats     = $trackers->stats( $id );
			$members   = $repository->members( $id );
			$is_active = SequenceRepository::STATUS_ACTIVE === $item['status'];
			$can_run   = $is_active && array() !== $item['steps'];
			$can_delete = ! $post_type || current_user_can( $post_type->cap->delete_post, $id );

			ob_start();
			?>
			<a class="nowcrm-catalog__link" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) $item['name'] ); ?></a>
			<div class="nowcrm-catalog__meta">#<?php echo esc_html( (string) $id ); ?></div>
			<div class="nowcrm-catalog__row-actions">
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '追蹤', 'now-crm' ); ?></a>

				<?php if ( $can_run ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
						<input type="hidden" name="action" value="nowcrm_enroll_sequence" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<?php wp_nonce_field( 'nowcrm_enroll_sequence' ); ?>
						<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '加入受眾', 'now-crm' ); ?></button>
					</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="nowcrm_set_sequence_status" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_set_sequence_status' ); ?>
					<?php if ( $is_active ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( SequenceRepository::STATUS_PAUSED ); ?>" />
						<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '暫停', 'now-crm' ); ?></button>
					<?php else : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( SequenceRepository::STATUS_ACTIVE ); ?>" />
						<button type="submit" class="nowcrm-catalog__row-action"><?php echo esc_html( SequenceRepository::STATUS_PAUSED === $item['status'] ? __( '恢復', 'now-crm' ) : __( '啟用', 'now-crm' ) ); ?></button>
					<?php endif; ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="nowcrm_duplicate_sequence" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_duplicate_sequence' ); ?>
					<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '複製', 'now-crm' ); ?></button>
				</form>

				<?php if ( $can_delete ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form"
						onsubmit="return confirm('<?php echo esc_js( __( '刪除序列也會取消所有進行中的追蹤並取消尚未寄出的佇列。確定要刪除嗎？', 'now-crm' ) ); ?>');">
						<input type="hidden" name="action" value="nowcrm_delete_sequence" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<?php wp_nonce_field( 'nowcrm_delete_sequence' ); ?>
						<button type="submit" class="nowcrm-catalog__row-action nowcrm-catalog__row-action--danger"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php
			$title_cell = (string) ob_get_clean();

			$status_cell = '<span class="nowcrm-status-pill' . ( $is_active ? ' nowcrm-status-pill--active' : '' ) . '">'
				. ( $is_active ? '<span class="nowcrm-status-pill__dot" aria-hidden="true"></span>' : '' )
				. esc_html( self::status_label( (string) $item['status'] ) )
				. '</span>';

			$rows[] = array(
				'id'       => $id,
				'label'    => (string) $item['name'],
				'eligible' => $can_delete,
				'reason'   => __( '您沒有刪除這個序列的權限。', 'now-crm' ),
				'cells'    => array(
					$title_cell,
					'<span class="nowcrm-catalog__muted">' . esc_html( self::audience_label( (array) $item['audience'] ) ) . '</span>',
					'<span class="nowcrm-catalog__number">' . esc_html( (string) count( $item['steps'] ) ) . '</span>',
					'<span class="nowcrm-catalog__number">' . esc_html( is_wp_error( $members ) ? '—' : (string) $members ) . '</span>',
					'<a class="nowcrm-catalog__number" href="' . esc_url( $runs_url ) . '">' . esc_html( (string) $stats['trackers']['active'] ) . '</a>',
					'<span class="nowcrm-catalog__number">' . esc_html( (string) $stats['trackers']['completed'] ) . '</span>',
					$status_cell,
				),
			);
		}

		Actions::render_notice();
		WorkflowCatalog::render_notice( __( '序列', 'now-crm' ) );

		ob_start();
		self::render_sending_state();
		self::render_enrollment_result( $focus_id, $next_after );
		?>
			<?php if ( $show_form ) : ?>
				<div class="nowcrm-panel" id="nowcrm-sequence-create">
					<h2><?php esc_html_e( '新增序列', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '先建立草稿。接著在編輯器設定步驟、延遲與受眾，再啟用序列。', 'now-crm' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-inline-form">
						<input type="hidden" name="action" value="nowcrm_create_sequence" />
						<?php wp_nonce_field( 'nowcrm_create_sequence' ); ?>

						<label>
							<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
							<input type="text" name="name" class="regular-text" required="required" data-testid="sequence-create-name" />
						</label>

						<button type="submit" class="button button-primary"><?php esc_html_e( '建立草稿', 'now-crm' ); ?></button>
						<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SEQUENCES_SLUG ) ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
					</form>
				</div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( '「加入受眾」每次最多處理 100 位符合條件的聯絡人；未訂閱、已抑制與已在序列中的聯絡人會跳過。', 'now-crm' ); ?></p>
		<?php
		$panel = (string) ob_get_clean();

		WorkflowCatalog::render(
			array(
				'slug'               => Menu::SEQUENCES_SLUG,
				'title'              => __( '序列', 'now-crm' ),
				'noun'               => __( '序列', 'now-crm' ),
				'search'             => $search,
				'status'             => $status,
				'statuses'           => $statuses,
				'counts'             => $counts,
				'library_total'      => $library,
				'total'              => $total,
				'page'               => $page,
				'pages'              => $pages,
				'panel'              => $panel,
				'search_placeholder' => __( '搜尋名稱', 'now-crm' ),
				'create'             => array(
					'url'   => Menu::page_url( Menu::SEQUENCES_SLUG, array( 'new' => '1' ) ),
					'label' => __( '新增序列', 'now-crm' ),
				),
				'testid'             => 'sequence-list',
				'columns'            => array(
					array( 'label' => __( '名稱', 'now-crm' ), 'class' => 'nowcrm-catalog__col-main' ),
					array( 'label' => __( '受眾', 'now-crm' ) ),
					array( 'label' => __( '步驟', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '受眾人數', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '進行中', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '完成', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '狀態', 'now-crm' ), 'class' => 'nowcrm-catalog__col-status' ),
				),
				'rows'               => $rows,
				'bulk'               => array(
					'action'  => WorkflowCatalogActions::SEQUENCE_ACTION,
					'nonce'   => WorkflowCatalogActions::SEQUENCE_ACTION,
					'field'   => 'sequence_ids',
					'options' => array( 'delete' => __( '刪除', 'now-crm' ) ),
					'confirm' => array(
						'delete' => __( '確定刪除選取的序列？進行中的追蹤會取消，尚未寄出的佇列也會取消，且無法復原。', 'now-crm' ),
					),
					'note'    => __( '刪除會先取消進行中的追蹤與尚未寄出的佇列。', 'now-crm' ),
				),
				'empty'              => array(
					'filtered' => __( '沒有符合條件的序列。', 'now-crm' ),
					'page'     => __( '這一頁沒有序列。', 'now-crm' ),
					'library'  => __( '還沒有序列，先新增第一個。', 'now-crm' ),
				),
			)
		);
	}

	/**
	 * State of the global send switch.
	 */
	private static function render_sending_state(): void {
		if ( Settings::bool( 'email_sending_enabled' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( '實際寄送未開啟：序列仍會建立佇列與追蹤，但佇列不會寄出任何信件。請到「設定」開啟寄送後再處理佇列。', 'now-crm' )
		);
	}

	/**
	 * Enrollment counts returned by the last batch.
	 */
	private static function render_enrollment_result( int $focus_id, int $next_after ): void {
		if ( 'sequence_enrolled' !== sanitize_key( Menu::query( 'nowcrm_notice' ) ) || $focus_id < 1 ) {
			return;
		}

		$labels = array(
			'enrolled'       => __( '加入', 'now-crm' ),
			'queued'         => __( '建立佇列', 'now-crm' ),
			'not_subscribed' => __( '跳過：未訂閱', 'now-crm' ),
			'suppressed'     => __( '跳過：已抑制', 'now-crm' ),
			'missing'        => __( '跳過：聯絡人不存在', 'now-crm' ),
			'duplicates'     => __( '已在序列中', 'now-crm' ),
			'failed'         => __( '失敗', 'now-crm' ),
		);

		?>
		<div class="nowcrm-panel" data-testid="sequence-enrollment-result">
			<h2><?php esc_html_e( '本批加入結果', 'now-crm' ); ?></h2>
			<ul>
				<?php foreach ( $labels as $key => $label ) : ?>
					<li><?php echo esc_html( $label ); ?>：<?php echo esc_html( (string) absint( Menu::query( $key, '0' ) ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $next_after > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-inline-form">
					<input type="hidden" name="action" value="nowcrm_enroll_sequence" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $focus_id ); ?>" />
					<input type="hidden" name="after_id" value="<?php echo esc_attr( (string) $next_after ); ?>" />
					<?php wp_nonce_field( 'nowcrm_enroll_sequence' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( '繼續加入下一批', 'now-crm' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
