<?php
/**
 * Newsletter campaign list and inline draft creation.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Audience;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;
use NowCRM\Email\Tracking;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class CampaignListPage {

	/**
	 * Register the admin-post handler for the creation form.
	 */
	public static function register(): void {
		add_action( 'admin_post_nowcrm_duplicate_campaign', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_nowcrm_delete_campaign', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Render the campaign list, plus the inline creation panel when requested.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id = absint( Menu::query( 'campaign' ) );
		if ( $id ) {
			CampaignPage::render( $id );
			return;
		}

		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( ! $post_type || ! current_user_can( $post_type->cap->edit_posts ) ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$search   = sanitize_text_field( Menu::query( 's' ) );
		$status   = sanitize_key( Menu::query( 'status', 'all' ) );
		$statuses = array_merge( array( 'all' ), self::status_options() );

		if ( ! in_array( $status, $statuses, true ) ) {
			$status = 'all';
		}

		$per_page = WorkflowCatalog::PER_PAGE;
		$counts   = self::status_counts( $search );
		$total    = (int) ( $counts[ $status ] ?? 0 );
		$pages    = (int) ceil( $total / $per_page );
		$paged    = max( 1, absint( Menu::query( 'paged', '1' ) ) );

		if ( $pages > 0 && $paged > $pages ) {
			$paged = $pages;
		}

		if ( 0 === $total ) {
			$paged = 1;
		}

		$args = self::query_args( $status, $search );
		$args['posts_per_page'] = $per_page;
		$args['paged']          = $paged;
		$args['orderby']        = 'date';
		$args['order']          = 'DESC';

		$query     = new \WP_Query( $args );
		$campaigns = array_values(
			array_filter(
				$query->posts,
				static function ( $post ): bool {
					return current_user_can( 'edit_post', $post->ID );
				}
			)
		);
		$library   = '' === $search ? (int) $counts['all'] : self::count_campaigns( 'all', '' );

		$can_create = current_user_can( $post_type->cap->create_posts );
		$create_url = CampaignCreatePage::url();
		$tracking   = new Tracking();
		$engagement = $tracking->campaign_engagement_stats( wp_list_pluck( $campaigns, 'ID' ) );
		$can_bulk   = Capabilities::can_manage_contacts() && current_user_can( $post_type->cap->delete_posts );
		$rows       = array();

		foreach ( $campaigns as $post ) {
			$edit_link  = CampaignPage::url( $post->ID );
			$state      = Campaigns::state( $post->ID );
			$is_live    = in_array( $state, array( Campaigns::STATUS_SENDING, Campaigns::STATUS_SENT ), true );
			$counters   = $engagement[ $post->ID ] ?? null;
			$open_rate  = null === $counters ? '—' : self::rate( (int) $counters['opened'], (int) $counters['accepted'] );
			$click_rate = null === $counters ? '—' : self::rate( (int) $counters['clicked'], (int) $counters['accepted'] );
			$recipients = null === $counters ? '—' : number_format_i18n( (int) $counters['accepted'] );
			$audience   = Audience::describe(
				Audience::stored( get_post_meta( $post->ID, Campaigns::META_AUDIENCE, true ) )
			);
			$schedule   = (string) get_post_meta( $post->ID, Campaigns::META_SCHEDULE, true );
			$modified   = (string) get_post_modified_time( 'Y-m-d H:i', false, $post );
			$can_delete = current_user_can( 'delete_post', $post->ID );
			$eligible   = $can_delete && in_array( $state, WorkflowCatalogActions::CAMPAIGN_BULK_STATES, true );

			ob_start();
			?>
			<a class="nowcrm-catalog__link" href="<?php echo esc_url( $edit_link ); ?>">
				<?php echo esc_html( get_the_title( $post ) ); ?>
			</a>
			<div class="nowcrm-catalog__meta">
				<span class="nowcrm-catalog__audience"><?php echo esc_html( $audience ); ?></span>
			</div>
			<?php if ( $can_create || $can_delete ) : ?>
				<div class="nowcrm-catalog__row-actions">
					<?php if ( $can_create ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
							<input type="hidden" name="action" value="nowcrm_duplicate_campaign">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
							<?php wp_nonce_field( 'nowcrm_duplicate_campaign' ); ?>
							<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '複製', 'now-crm' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( $eligible ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
							<input type="hidden" name="action" value="nowcrm_delete_campaign">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
							<?php wp_nonce_field( 'nowcrm_delete_campaign' ); ?>
							<button type="submit" class="nowcrm-catalog__row-action nowcrm-catalog__row-action--danger" onclick="return window.confirm('<?php echo esc_js( __( '確定刪除這封電子報？', 'now-crm' ) ); ?>');"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
						</form>
					<?php elseif ( $can_delete ) : ?>
						<span class="nowcrm-catalog__muted" title="<?php esc_attr_e( '寄送中、已排程或已暫停的電子報不能刪除。', 'now-crm' ); ?>"><?php esc_html_e( '此狀態不可刪除', 'now-crm' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php
			$title_cell = (string) ob_get_clean();

			ob_start();
			?>
			<span class="nowcrm-status-pill<?php echo $is_live ? ' nowcrm-status-pill--active' : ''; ?>">
				<?php if ( $is_live ) : ?>
					<span class="nowcrm-status-pill__dot" aria-hidden="true"></span>
				<?php endif; ?>
				<?php echo esc_html( Campaigns::status_label( $state ) ); ?>
			</span>
			<?php if ( Campaigns::STATUS_SCHEDULED === $state && '' !== $schedule ) : ?>
				<span class="nowcrm-catalog__aside">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: scheduled send time */
							__( '排程 %s', 'now-crm' ),
							get_date_from_gmt( $schedule, 'm-d H:i' )
						)
					);
					?>
				</span>
			<?php endif; ?>
			<?php
			$status_cell = (string) ob_get_clean();

			$rows[] = array(
				'id'       => (int) $post->ID,
				'label'    => get_the_title( $post ),
				'eligible' => $eligible,
				'reason'   => $can_delete
					? __( '寄送中、已排程或已暫停的電子報不能批次移至回收桶。', 'now-crm' )
					: __( '您沒有刪除這封電子報的權限。', 'now-crm' ),
				'cells'    => array(
					$title_cell,
					$status_cell,
					'<span class="nowcrm-catalog__number">' . esc_html( $recipients ) . '</span>',
					'<span class="nowcrm-catalog__number">' . esc_html( $open_rate ) . '</span>',
					'<span class="nowcrm-catalog__number">' . esc_html( $click_rate ) . '</span>',
					'<span class="nowcrm-catalog__time">' . esc_html( $modified ) . '</span>',
				),
			);
		}

		$tabs = array();
		foreach ( self::status_options() as $option ) {
			$tabs[ $option ] = Campaigns::status_label( $option );
		}
		?>
		<?php Actions::render_notice(); ?>
		<?php WorkflowCatalog::render_notice( __( '電子報', 'now-crm' ) ); ?>
		<?php
		WorkflowCatalog::render(
			array(
				'slug'              => Menu::CAMPAIGNS_SLUG,
				'title'             => __( '電子報', 'now-crm' ),
				'noun'              => __( '電子報', 'now-crm' ),
				'search'            => $search,
				'status'            => $status,
				'statuses'          => $tabs,
				'counts'            => $counts,
				'library_total'     => $library,
				'total'             => $total,
				'page'              => $paged,
				'pages'             => $pages,
				'search_placeholder' => __( '搜尋標題', 'now-crm' ),
				'create'            => $can_create ? array(
					'url'   => $create_url,
					'label' => __( '新增電子報', 'now-crm' ),
				) : null,
				'columns'           => array(
					array( 'label' => __( '標題', 'now-crm' ), 'class' => 'nowcrm-catalog__col-main' ),
					array( 'label' => __( '狀態', 'now-crm' ), 'class' => 'nowcrm-catalog__col-status' ),
					array( 'label' => __( '收件人', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '開信率', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '點擊率', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '更新時間', 'now-crm' ), 'class' => 'nowcrm-catalog__col-time' ),
				),
				'rows'              => $rows,
				'bulk'              => $can_bulk ? array(
					'action'  => WorkflowCatalogActions::CAMPAIGN_ACTION,
					'nonce'   => WorkflowCatalogActions::CAMPAIGN_ACTION,
					'field'   => 'campaign_ids',
					'options' => array( 'trash' => __( '刪除', 'now-crm' ) ),
					'confirm' => array(
						'trash' => __( '確定將選取的電子報移至回收桶？寄送中、已排程或已暫停的電子報不會被處理。', 'now-crm' ),
					),
					'note'    => __( '只處理草稿、寄送結束與已取消的電子報。', 'now-crm' ),
				) : null,
				'empty'             => array(
					'filtered' => __( '沒有符合條件的電子報。', 'now-crm' ),
					'page'     => __( '這一頁沒有電子報。', 'now-crm' ),
					'library'  => __( '還沒有電子報，先建立第一封。', 'now-crm' ),
				),
			)
		);
		?>
		<?php
	}

	/**
	 * Duplicate a campaign and open the copy.
	 */
	public static function handle_duplicate(): void {
		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( ! Capabilities::can_manage_contacts() || ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_duplicate_campaign' );

		$id   = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( (string) $_POST['campaign_id'] ) ) : 0;
		$post = Campaigns::get( $id );

		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			self::list_redirect( array( 'nowcrm_notice' => 'not_found' ) );
		}

		$copy = Campaigns::duplicate( $post );

		if ( is_wp_error( $copy ) || ! $copy ) {
			self::list_redirect( array( 'nowcrm_notice' => 'campaign_create_failed' ) );
		}

		wp_safe_redirect( CampaignPage::url( (int) $copy ) );
		exit;
	}

	/**
	 * Move one campaign to the trash under the shared state guard.
	 */
	public static function handle_delete(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_delete_campaign' );

		$id     = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( (string) $_POST['campaign_id'] ) ) : 0;
		$result = WorkflowCatalogActions::trash_campaign( $id );

		if ( is_wp_error( $result ) ) {
			$notice = match ( $result->get_error_code() ) {
				'nowcrm_campaign_state'  => 'state_blocked',
				'nowcrm_campaign_delete' => 'error',
				default                  => 'not_found',
			};

			self::list_redirect( array( 'nowcrm_notice' => $notice ) );
		}

		self::list_redirect( array( 'nowcrm_notice' => 'deleted' ) );
	}

	/**
	 * Campaign states shown in the filter tabs, in workflow order.
	 *
	 * @return string[]
	 */
	private static function status_options(): array {
		return array(
			Campaigns::STATUS_DRAFT,
			Campaigns::STATUS_SCHEDULED,
			Campaigns::STATUS_SENDING,
			Campaigns::STATUS_PAUSED,
			Campaigns::STATUS_SENT,
			Campaigns::STATUS_CANCELLED,
		);
	}

	/**
	 * Query arguments shared by the list and the status counts.
	 *
	 * The arguments restrict the result to campaigns the current user can
	 * actually edit, so counts and rows agree.
	 *
	 * @param string $status Campaign state, or "all".
	 * @param string $search Search term.
	 * @return array<string, mixed>
	 */
	private static function query_args( string $status, string $search ): array {
		$post_type = get_post_type_object( Campaigns::POST_TYPE );
		$args      = array(
			'post_type'           => Campaigns::POST_TYPE,
			'post_status'         => array( 'draft', 'publish', 'pending', 'future' ),
			'ignore_sticky_posts' => true,
		);

		if ( $post_type && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}

		if ( $post_type && current_user_can( $post_type->cap->edit_private_posts ) ) {
			$args['post_status'][] = 'private';
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$exclude = Recurring::exclude_meta_query();

		if ( Campaigns::STATUS_DRAFT === $status ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				$exclude,
				array(
					'relation' => 'OR',
					array(
						'key'   => Campaigns::META_STATUS,
						'value' => Campaigns::STATUS_DRAFT,
					),
					array(
						'key'     => Campaigns::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
				),
			);
		} elseif ( 'all' !== $status ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				$exclude,
				array(
					'key'   => Campaigns::META_STATUS,
					'value' => $status,
				),
			);
		} else {
			$args['meta_query'] = $exclude;
		}

		return $args;
	}

	/**
	 * Number of campaigns in one state, honoring the search term.
	 *
	 * @param string $status Campaign state, or "all".
	 * @param string $search Search term.
	 */
	private static function count_campaigns( string $status, string $search ): int {
		$args                           = self::query_args( $status, $search );
		$args['posts_per_page']         = 1;
		$args['fields']                 = 'ids';
		$args['orderby']                = 'none';
		$args['update_post_meta_cache'] = false;
		$args['update_post_term_cache'] = false;

		return (int) ( new \WP_Query( $args ) )->found_posts;
	}

	/**
	 * Campaign counts for the filter tabs, keyed by state plus "all".
	 *
	 * @param string $search Search term.
	 * @return array<string, int>
	 */
	private static function status_counts( string $search ): array {
		$counts = array( 'all' => self::count_campaigns( 'all', $search ) );

		foreach ( self::status_options() as $option ) {
			$counts[ $option ] = self::count_campaigns( $option, $search );
		}

		return $counts;
	}

	/**
	 * Percentage of accepted recipients that produced an event, or an em dash.
	 *
	 * @param int $events   Recipients with the event.
	 * @param int $accepted Accepted broadcast recipients.
	 */
	private static function rate( int $events, int $accepted ): string {
		if ( $accepted <= 0 ) {
			return '—';
		}

		$rate = (int) round( ( $events / $accepted ) * 100 );

		return sprintf( '%s%%', number_format_i18n( $rate ) );
	}

	/**
	 * Redirect back to the campaign list with an allow-listed notice.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function list_redirect( array $args = array() ): void {
		wp_safe_redirect( Menu::page_url( Menu::CAMPAIGNS_SLUG, $args ) );
		exit;
	}
}
