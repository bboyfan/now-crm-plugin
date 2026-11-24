<?php
/**
 * NOW CRM overview. One screen with the current state of contacts, the send
 * queue, carts and automations, the new-contact trend, and bounded lists of
 * the newest entries. Every count links to the screen that owns the rows.
 *
 * The overview stays at-a-glance: it links rather than duplicates the logs,
 * queue worker, editors and cart settings. Nothing here mutates data, and
 * actions that need a different capability (settings, manual queue runs) live
 * on their own screens.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;
use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\Queue;
use NowCRM\Email\Tracking;
use NowCRM\Reports\DateRange;
use NowCRM\Reports\ReportService;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	/**
	 * Growth spans offered by the overview. The first match wins; any other
	 * value in the query string falls back to the default span.
	 */
	private const GROWTH_DAYS = array( 7, 30, 90 );

	/**
	 * Default growth span, in local days.
	 */
	private const GROWTH_DEFAULT = 30;

	/**
	 * Rows shown by each bounded list.
	 */
	private const RECENT_LIMIT = 5;

	/**
	 * Render the overview.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$contacts  = new ContactRepository();
		$counts    = $contacts->count_by_status();
		$queue     = ( new Queue() )->status();
		$carts     = ( new CartRepository() )->stats();

		$recent_contacts = $contacts->search(
			array(
				'per_page' => self::RECENT_LIMIT,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);
		$recent_contacts = (array) ( $recent_contacts['items'] ?? array() );

		$repository = new AutomationRepository();
		$automations = $repository->all(
			array(
				'status' => AutomationRepository::STATUS_ACTIVE,
				'limit'  => self::RECENT_LIMIT,
			)
		);

		$campaigns = get_posts(
			array(
				'post_type'           => Campaigns::POST_TYPE,
				'post_status'         => 'any',
				'posts_per_page'      => 8,
				'orderby'             => 'ID',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'meta_query'          => Recurring::exclude_meta_query(),
			)
		);

		$campaigns = array_values(
			array_filter(
				$campaigns,
				static function ( \WP_Post $post ): bool {
					return current_user_can( 'edit_post', $post->ID );
				}
			)
		);

		$post_type  = get_post_type_object( Campaigns::POST_TYPE );
		$can_create = $post_type && current_user_can( $post_type->cap->create_posts );
		$engagement = ( new Tracking() )->campaign_engagement_stats( wp_list_pluck( $campaigns, 'ID' ) );

		$days   = self::growth_days();
		$range  = self::growth_range( $days );
		$growth = ( new ReportService() )->contact_growth( $range );
		?>
		<div class="wrap nowcrm-wrap nowcrm-analytics">
			<div class="nowcrm-contacts__head">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'NOW CRM 總覽', 'now-crm' ); ?></h1>
				<div class="nowcrm-contacts__toolbar">
					<a class="button" href="<?php echo esc_url( Menu::page_url( ReportsPage::SLUG ) ); ?>"><?php esc_html_e( '報表', 'now-crm' ); ?></a>
					<?php if ( $can_create ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( CampaignCreatePage::url() ); ?>"><?php esc_html_e( '新增電子報', 'now-crm' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end" />
			<?php Actions::render_notice(); ?>

			<?php if ( Capabilities::can_manage_settings() && ! Settings::bool( 'email_sending_enabled' ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings URL. */
						esc_html__( '實際寄送目前關閉，佇列只會累積。只有具備設定權限的人可以前往 %s 開啟寄送。', 'now-crm' ),
						'<a href="' . esc_url( Menu::page_url( Menu::SETTINGS_SLUG ) ) . '">' . esc_html__( '設定', 'now-crm' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="nowcrm-analytics__intro">
				<?php esc_html_e( '下方卡片計數是目前的快照；新增聯絡人只計算所選期間內的建立時間，兩者分開呈現、不相加。最近電子報的寄送數字是累計值，另在該表標示。', 'now-crm' ); ?>
			</p>

			<div class="nowcrm-analytics__grid">
				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '聯絡人', 'now-crm' ); ?></h2>
						<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>"><?php esc_html_e( '全部聯絡人', 'now-crm' ); ?></a>
					</div>
					<div class="nowcrm-analytics__metrics">
						<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>">
							<span><?php esc_html_e( '總數', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( (string) array_sum( $counts ) ); ?></strong>
						</a>
						<?php foreach ( Status::all() as $state ) : ?>
							<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array( 'status' => $state ) ) ); ?>">
								<span><?php echo esc_html( Status::label( $state ) ); ?></span>
								<strong><?php echo esc_html( (string) ( $counts[ $state ] ?? 0 ) ); ?></strong>
							</a>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '寄送佇列', 'now-crm' ); ?></h2>
						<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG ) ); ?>"><?php esc_html_e( '寄送紀錄', 'now-crm' ); ?></a>
					</div>
					<div class="nowcrm-analytics__metrics">
						<?php foreach ( self::queue_labels() as $state => $label ) : ?>
							<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'status' => $state ) ) ); ?>">
								<span><?php echo esc_html( $label ); ?></span>
								<strong><?php echo esc_html( (string) ( $queue[ $state ] ?? 0 ) ); ?></strong>
							</a>
						<?php endforeach; ?>
					</div>
					<p class="nowcrm-analytics__subtle">
						<?php esc_html_e( '佇列由 Action Scheduler／WP-Cron 自動推進；此頁不會啟動或重試任何寄送。', 'now-crm' ); ?>
					</p>
				</section>

				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '購物車', 'now-crm' ); ?></h2>
						<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG ) ); ?>"><?php esc_html_e( '購物車與提醒', 'now-crm' ); ?></a>
					</div>
					<div class="nowcrm-analytics__metrics">
						<?php foreach ( CartPage::status_labels() as $state => $label ) : ?>
							<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG, array( 'status' => $state ) ) ); ?>">
								<span><?php echo esc_html( $label ); ?></span>
								<strong><?php echo esc_html( (string) ( $carts[ $state ] ?? 0 ) ); ?></strong>
							</a>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '快速連結', 'now-crm' ); ?></h2>
					</div>
					<ul class="nowcrm-analytics__list">
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::LISTS_SLUG ) ); ?>"><?php esc_html_e( '名單', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::CAMPAIGNS_SLUG ) ); ?>"><?php esc_html_e( '電子報', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATIONS_SLUG ) ); ?>"><?php esc_html_e( '自動化', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::SEQUENCES_SLUG ) ); ?>"><?php esc_html_e( '序列', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG ) ); ?>"><?php esc_html_e( '寄送紀錄', 'now-crm' ); ?></a></li>
						<li><a href="<?php echo esc_url( Menu::page_url( ReportsPage::SLUG ) ); ?>"><?php esc_html_e( '報表', 'now-crm' ); ?></a></li>
					</ul>
				</section>
			</div>

			<section class="nowcrm-analytics__card">
				<div class="nowcrm-analytics__card-head">
					<h2><?php esc_html_e( '新增聯絡人', 'now-crm' ); ?></h2>
					<a href="<?php echo esc_url( Menu::page_url( ReportsPage::SLUG, array( 'tab' => 'contacts', 'from' => $range->from, 'to' => $range->to ) ) ); ?>">
						<?php
						printf(
							/* translators: 1: start date, 2: end date. */
							esc_html__( '在聯絡人報表查看 %1$s 至 %2$s', 'now-crm' ),
							esc_html( $range->from ),
							esc_html( $range->to )
						);
						?>
					</a>
				</div>
				<div class="nowcrm-analytics__filter-fields" role="group" aria-label="<?php esc_attr_e( '成長期間', 'now-crm' ); ?>">
					<?php foreach ( self::GROWTH_DAYS as $option ) : ?>
						<a class="button"
							href="<?php echo esc_url( Menu::page_url( Menu::DASHBOARD_SLUG, array( 'days' => $option ) ) ); ?>"
							<?php echo $option === $days ? 'aria-current="true"' : ''; ?>>
							<?php
							printf(
								/* translators: %d: number of days. */
								esc_html__( '近 %d 天', 'now-crm' ),
								(int) $option
							);
							?>
						</a>
					<?php endforeach; ?>
				</div>
				<p class="nowcrm-analytics__subtle">
					<?php
					printf(
						/* translators: 1: start date, 2: end date. */
						esc_html__( '目前顯示 %1$s 至 %2$s，依聯絡人建立時間計算（網站時區）；沒有新增的日期仍會保留。', 'now-crm' ),
						esc_html( $range->from ),
						esc_html( $range->to )
					);
					?>
				</p>
				<?php
				AnalyticsTrend::render(
					__( '每日新增聯絡人', 'now-crm' ),
					array( __( '新增聯絡人', 'now-crm' ) => $growth )
				);
				?>
			</section>

			<div class="nowcrm-analytics__grid nowcrm-analytics__grid--split">
				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '最近聯絡人', 'now-crm' ); ?></h2>
						<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>"><?php esc_html_e( '全部聯絡人', 'now-crm' ); ?></a>
					</div>
					<?php if ( $recent_contacts ) : ?>
						<ul class="nowcrm-analytics__list">
							<?php foreach ( $recent_contacts as $contact ) : ?>
								<?php
								$contact_id = (int) ( $contact['id'] ?? 0 );
								$email      = (string) ( $contact['email'] ?? '' );
								$name       = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
								$primary    = '' !== $name ? $name : $email;
								?>
								<li>
									<span>
										<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id ) ) ); ?>"><?php echo esc_html( $primary ); ?></a>
										<small>
											<?php echo esc_html( '' !== $name ? $email : __( '沒有姓名', 'now-crm' ) ); ?>
											·
											<?php echo esc_html( Menu::format_datetime( (string) ( $contact['created_at'] ?? '' ) ) ); ?>
										</small>
									</span>
									<span><?php echo esc_html( Status::label( (string) ( $contact['status'] ?? '' ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="nowcrm-analytics__subtle"><?php esc_html_e( '還沒有聯絡人。', 'now-crm' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="nowcrm-analytics__card">
					<div class="nowcrm-analytics__card-head">
						<h2><?php esc_html_e( '啟用中的自動化', 'now-crm' ); ?></h2>
						<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATIONS_SLUG ) ); ?>"><?php esc_html_e( '所有自動化', 'now-crm' ); ?></a>
					</div>
					<?php if ( $automations ) : ?>
						<ul class="nowcrm-analytics__list">
							<?php foreach ( $automations as $automation ) : ?>
								<?php
								$automation_id = (int) $automation['id'];
								$run_counts    = $repository->run_counts( $automation_id );
								?>
								<li>
									<span>
										<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $automation_id ) ) ); ?>"><?php echo esc_html( (string) $automation['name'] ); ?></a>
										<small>
											<?php echo esc_html( AutomationListPage::trigger_label( (string) $automation['trigger_key'] ) ); ?>
											·
											<?php
											printf(
												/* translators: 1: active runs, 2: waiting runs. */
												esc_html__( '進行中 %1$d／等待 %2$d', 'now-crm' ),
												(int) ( $run_counts['active'] ?? 0 ),
												(int) ( $run_counts['waiting'] ?? 0 )
											);
											?>
										</small>
									</span>
									<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $automation_id ) ) ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="nowcrm-analytics__subtle"><?php esc_html_e( '沒有啟用中的自動化；草稿與暫停中的流程不會執行。', 'now-crm' ); ?></p>
						<p><a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATIONS_SLUG, array( 'new' => '1' ) ) ); ?>"><?php esc_html_e( '建立自動化', 'now-crm' ); ?></a></p>
					<?php endif; ?>
				</section>
			</div>

			<section class="nowcrm-analytics__card">
				<div class="nowcrm-analytics__card-head">
					<h2><?php esc_html_e( '最近的電子報', 'now-crm' ); ?></h2>
					<a href="<?php echo esc_url( Menu::page_url( Menu::CAMPAIGNS_SLUG ) ); ?>"><?php esc_html_e( '全部電子報', 'now-crm' ); ?></a>
				</div>
				<?php if ( $campaigns ) : ?>
					<div class="nowcrm-contacts-surface">
						<table class="nowcrm-contacts-table widefat striped">
							<thead><tr>
								<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '已交付（累計）', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '開信（累計）', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '點擊（累計）', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '報表', 'now-crm' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $campaigns as $campaign ) : ?>
								<?php
								$campaign_id = (int) $campaign->ID;
								$state       = Campaigns::state( $campaign_id );
								$counters    = $engagement[ $campaign_id ] ?? null;
								$schedule    = (string) get_post_meta( $campaign_id, Campaigns::META_SCHEDULE, true );
								?>
								<tr>
									<th scope="row"><a href="<?php echo esc_url( CampaignPage::url( $campaign_id ) ); ?>"><?php echo esc_html( get_the_title( $campaign ) ); ?></a></th>
									<td>
										<?php echo esc_html( Campaigns::status_label( $state ) ); ?>
										<?php if ( Campaigns::STATUS_SCHEDULED === $state && '' !== $schedule ) : ?>
											<div class="description">
												<?php
												printf(
													/* translators: %s: scheduled send time. */
													esc_html__( '排程 %s', 'now-crm' ),
													esc_html( get_date_from_gmt( $schedule, 'm-d H:i' ) )
												);
												?>
											</div>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( null === $counters ? '—' : number_format_i18n( (int) $counters['accepted'] ) ); ?></td>
									<td><?php echo esc_html( null === $counters ? '—' : number_format_i18n( (int) $counters['opened'] ) ); ?></td>
									<td><?php echo esc_html( null === $counters ? '—' : number_format_i18n( (int) $counters['clicked'] ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( Menu::page_url( ReportsPage::SLUG, array( 'tab' => 'email', 'campaign_id' => $campaign_id ) ) ); ?>"><?php esc_html_e( '查看期間報表', 'now-crm' ); ?></a>
										<div class="description"><?php esc_html_e( '預設近 30 天', 'now-crm' ); ?></div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p><?php echo $can_create ? esc_html__( '還沒有電子報。建立草稿後，依序編輯內容與選擇收件人。', 'now-crm' ) : esc_html__( '目前沒有可查看的電子報。', 'now-crm' ); ?></p>
				<?php endif; ?>
				<p class="nowcrm-analytics__subtle">
					<?php esc_html_e( '上表數字是各電子報的累計值：不限期間，也不受報表日期篩選；只計入電子報類型且狀態為已接受的寄送工作，開信與點擊是其中不重複的寄送工作。累計值與其他卡片或期間報表的數字各自獨立，不會對帳，也不相加。', 'now-crm' ); ?>
				</p>
				<p class="nowcrm-analytics__subtle">
					<?php esc_html_e( '已交付表示郵件系統接受，不等於送達；開信是弱訊號，郵件用戶端可能預取。每列「查看期間報表」開啟該電子報的報表頁，期間預設為最近 30 天，可再自行調整；累計欄位不會跟著該期間縮小或放大。', 'now-crm' ); ?>
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * Selected growth span from the request, restricted to the offered values.
	 */
	private static function growth_days(): int {
		$days = (int) Menu::query( 'days', (string) self::GROWTH_DEFAULT );

		return in_array( $days, self::GROWTH_DAYS, true ) ? $days : self::GROWTH_DEFAULT;
	}

	/**
	 * Inclusive local-day range ending today in the site timezone.
	 */
	private static function growth_range( int $days ): DateRange {
		$zone = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $zone );
		$from = $now->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );

		return DateRange::parse( $from, $now->format( 'Y-m-d' ), $zone, $now );
	}

	/**
	 * Outcome labels for the send queue counters.
	 *
	 * @return array<string, string>
	 */
	private static function queue_labels(): array {
		return array(
			'queued'     => __( '待寄', 'now-crm' ),
			'sending'    => __( '寄送中', 'now-crm' ),
			'accepted'   => __( '已交付', 'now-crm' ),
			'failed'     => __( '失敗', 'now-crm' ),
			'suppressed' => __( '已抑制', 'now-crm' ),
			'unknown'    => __( '狀態不明', 'now-crm' ),
			'cancelled'  => __( '已取消', 'now-crm' ),
		);
	}
}
