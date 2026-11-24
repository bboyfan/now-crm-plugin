<?php
/**
 * Reports screen and CSV export.
 *
 * The screen is server-rendered and reads only the plugin's own tables. It is
 * split into explicit tasks — contacts, email, automation, commerce and cart —
 * so one visit loads one task instead of every metric. The date range is
 * bounded by DateRange, the campaign table is paginated, and every action
 * needs the manage-contacts capability. Export uses the same campaign filters
 * as the email tab and streams rows without loading the full result set twice.
 *
 * A comparison range is optional. It runs the same period queries over a
 * second bounded range so the page can show current/previous side by side; it
 * never invents attribution, rates or revenue that the native tables cannot
 * prove. Snapshot counters are shown once and never compared. Month, quarter
 * and year comparisons use the complete calendar period before the from-date's
 * month, quarter or year; they never overlap the current range. The page names
 * that anchor and the actual comparison dates.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Reports\DateRange;
use NowCRM\Reports\ReportCsv;
use NowCRM\Reports\ReportService;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ReportsPage {

	public const SLUG = 'nowcrm-reports';

	/**
	 * Nonce action for the CSV export.
	 */
	public const NONCE_EXPORT = 'nowcrm_report_export';

	/**
	 * Task tabs. The first entry is the default for a bare report URL.
	 *
	 * @var array<string, string>
	 */
	private const TABS = array(
		'contacts'   => '聯絡人',
		'email'      => '電子報',
		'automation' => '自動化',
		'commerce'   => '訂單與營收',
		'cart'       => '購物車',
	);

	/**
	 * Comparison modes accepted by the range picker.
	 *
	 * @var array<string, string>
	 */
	private const COMPARE = array(
		'previous_period'  => '前一段等長期間',
		'previous_month'   => '上一個完整日曆月',
		'previous_quarter' => '上一個完整日曆季',
		'previous_year'    => '上一個完整日曆年',
		'custom'           => '自訂範圍',
	);

	/**
	 * Quick period presets, in days.
	 *
	 * @var int[]
	 */
	private const PRESETS = array( 7, 30, 90 );

	/**
	 * Register the export handler. Called from Admin\Menu::register().
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::NONCE_EXPORT, array( __CLASS__, 'export' ) );
	}

	/**
	 * Render the report.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$filters = self::filters();
		$range   = self::range( $filters );
		$compare = self::compare_range( $filters );
		$service = new ReportService();
		?>
		<div class="wrap nowcrm-wrap nowcrm-analytics">
			<div class="nowcrm-contacts__head">
				<h1 class="wp-heading-inline"><?php esc_html_e( '報表', 'now-crm' ); ?></h1>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_tabs( $filters ); ?>
			<?php self::render_presets( $filters, $range ); ?>
			<?php self::render_filters( $filters, $range, $compare ); ?>
			<?php self::render_summary( $filters, $range, $compare ); ?>

			<?php
			switch ( $filters['tab'] ) {
				case 'email':
					self::render_email( $service, $range, $compare, $filters );
					break;
				case 'automation':
					self::render_automation( $service, $range, $compare );
					break;
				case 'commerce':
					self::render_commerce( $service, $range, $compare );
					break;
				case 'cart':
					self::render_cart( $service, $range, $compare );
					break;
				default:
					self::render_contacts( $service, $range, $compare, $filters );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Stream the campaign table as CSV.
	 */
	public static function export(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_EXPORT );

		$filters = self::filters();
		$range   = self::range( $filters );
		$rows    = ( new ReportService() )->campaign_rows( $range, $filters );

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ReportCsv::filename( $range ) . '"' );

		$output = fopen( 'php://output', 'wb' );

		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, ReportCsv::header(), ',', '"', '\\' );

		foreach ( $rows as $row ) {
			fputcsv( $output, ReportCsv::row( $row ), ',', '"', '\\' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- the CSV export writes to php://output; WP_Filesystem cannot stream the response handle.
		fclose( $output );
		exit;
	}

	/**
	 * Task tabs. Every tab is a real link; the active one carries aria-current.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function render_tabs( array $filters ): void {
		?>
		<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '報表', 'now-crm' ); ?>">
			<?php foreach ( self::TABS as $key => $label ) : ?>
				<a class="nowcrm-contact-tabs__item" href="<?php echo esc_url( self::tab_url( $filters, $key ) ); ?>"
					<?php echo $key === $filters['tab'] ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Quick period links. The date inputs are always available; these only
	 * save a submit for the common spans.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @param DateRange            $range   Resolved range.
	 */
	private static function render_presets( array $filters, DateRange $range ): void {
		$zone = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $zone );
		?>
		<nav class="nowcrm-analytics__filter-fields" aria-label="<?php esc_attr_e( '快速期間', 'now-crm' ); ?>">
			<span class="nowcrm-analytics__subtle"><?php esc_html_e( '快速期間', 'now-crm' ); ?></span>
			<?php foreach ( self::PRESETS as $days ) : ?>
				<?php
				$to      = $now->format( 'Y-m-d' );
				$from    = $now->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
				$current = $range->from === $from && $range->to === $to;
				?>
				<a class="button" href="<?php echo esc_url( self::report_url( $filters, array( 'from' => $from, 'to' => $to ) ) ); ?>"
					<?php echo $current ? 'aria-current="true"' : ''; ?>>
					<?php
					printf(
						/* translators: %d: number of days. */
						esc_html__( '近 %d 天', 'now-crm' ),
						(int) $days
					);
					?>
				</a>
			<?php endforeach; ?>
			<span class="nowcrm-analytics__subtle"><?php esc_html_e( '自訂期間請使用下方日期欄位。', 'now-crm' ); ?></span>
		</nav>
		<?php
	}

	/**
	 * One filter form for the active tab.
	 *
	 * The comparison date fields are always visible. Choosing the custom mode
	 * therefore does not need a second submit to reveal them.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @param DateRange            $range   Active range.
	 * @param DateRange|null       $compare Comparison range, when valid.
	 */
	private static function render_filters( array $filters, DateRange $range, ?DateRange $compare ): void {
		$email    = 'email' === $filters['tab'];
		$contacts = 'contacts' === $filters['tab'];
		$options  = $email ? ( new ReportService() )->campaign_options( (int) $filters['campaign_id'] ) : array();
		$terms    = $contacts ? new TermRepository() : null;
		?>
		<form id="nowcrm-report-filters" method="get" class="nowcrm-panel nowcrm-analytics__card nowcrm-contacts-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( (string) $filters['tab'] ); ?>">
			<div class="nowcrm-analytics__filter-fields">
				<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '起始日', 'now-crm' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $range->from ); ?>">
				</label>
				<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '結束日', 'now-crm' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $range->to ); ?>">
				</label>
				<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '比較', 'now-crm' ); ?>
					<select name="compare">
						<option value=""><?php esc_html_e( '不比較', 'now-crm' ); ?></option>
						<?php foreach ( self::COMPARE as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['compare'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '比較起日（自訂）', 'now-crm' ); ?>
					<input type="date" name="compare_from" value="<?php echo esc_attr( (string) $filters['compare_from'] ); ?>">
				</label>
				<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '比較迄日（自訂）', 'now-crm' ); ?>
					<input type="date" name="compare_to" value="<?php echo esc_attr( (string) $filters['compare_to'] ); ?>">
				</label>
			</div>
			<div class="nowcrm-analytics__filter-fields">
				<?php if ( $email ) : ?>
					<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '電子報', 'now-crm' ); ?>
						<select name="campaign_id">
							<option value=""><?php esc_html_e( '全部', 'now-crm' ); ?></option>
							<?php foreach ( $options as $option_id => $option_name ) : ?>
								<option value="<?php echo esc_attr( (string) $option_id ); ?>" <?php selected( (int) $filters['campaign_id'], (int) $option_id ); ?>><?php echo esc_html( $option_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '電子報狀態', 'now-crm' ); ?>
						<select name="status">
							<option value=""><?php esc_html_e( '全部', 'now-crm' ); ?></option>
							<?php foreach ( self::states() as $state ) : ?>
								<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $filters['status'], $state ); ?>><?php echo esc_html( Campaigns::status_label( $state ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( $contacts && null !== $terms ) : ?>
					<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '名單', 'now-crm' ); ?>
						<select name="list_id">
							<option value=""><?php esc_html_e( '全部', 'now-crm' ); ?></option>
							<?php foreach ( $terms->all( TermRepository::TYPE_LIST ) as $term ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $term['id'] ); ?>" <?php selected( (int) $filters['list_id'], (int) $term['id'] ); ?>><?php echo esc_html( (string) $term['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="nowcrm-analytics__filter-field"><?php esc_html_e( '標籤', 'now-crm' ); ?>
						<select name="tag_id">
							<option value=""><?php esc_html_e( '全部', 'now-crm' ); ?></option>
							<?php foreach ( $terms->all( TermRepository::TYPE_TAG ) as $term ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $term['id'] ); ?>" <?php selected( (int) $filters['tag_id'], (int) $term['id'] ); ?>><?php echo esc_html( (string) $term['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<button type="submit" class="button"><?php esc_html_e( '套用', 'now-crm' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::reset_url( $filters ) ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
			</div>
		</form>
		<?php
	}

	/**
	 * Range, comparison and active-scope summary.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @param DateRange            $range   Active range.
	 * @param DateRange|null       $compare Comparison range, when valid.
	 */
	private static function render_summary( array $filters, DateRange $range, ?DateRange $compare ): void {
		$parts = array();

		if ( 'email' === $filters['tab'] && '' !== $filters['status'] ) {
			$parts[] = sprintf(
				/* translators: %s: campaign status label or raw key. */
				__( '電子報狀態：%s', 'now-crm' ),
				Campaigns::status_label( (string) $filters['status'] )
			);
		}

		if ( 'email' === $filters['tab'] && (int) $filters['campaign_id'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: campaign id. */
				__( '電子報 #%d', 'now-crm' ),
				(int) $filters['campaign_id']
			);
		}

		if ( 'contacts' === $filters['tab'] ) {
			if ( (int) $filters['list_id'] > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: list id. */
					__( '名單 #%d', 'now-crm' ),
					(int) $filters['list_id']
				);
			}

			if ( (int) $filters['tag_id'] > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: tag id. */
					__( '標籤 #%d', 'now-crm' ),
					(int) $filters['tag_id']
				);
			}
		}
		?>
		<p class="nowcrm-analytics__intro">
			<?php
			printf(
				/* translators: 1: first local date, 2: last local date, 3: day count, 4: timezone. */
				esc_html__( '目前範圍：%1$s 至 %2$s（%3$s 天，最多 366 天）；日界線依網站時區 %4$s。', 'now-crm' ),
				esc_html( $range->from ),
				esc_html( $range->to ),
				esc_html( (string) $range->days() ),
				esc_html( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' )
			);
			?>
			<?php if ( null !== $compare ) : ?>
				<?php
				printf(
					/* translators: 1: first local date, 2: last local date, 3: day count. */
					esc_html__( '比較範圍：%1$s 至 %2$s（%3$s 天）。', 'now-crm' ),
					esc_html( $compare->from ),
					esc_html( $compare->to ),
					esc_html( (string) $compare->days() )
				);
				?>
			<?php elseif ( '' !== $filters['compare'] ) : ?>
				<?php esc_html_e( '比較範圍無效，已省略比較。', 'now-crm' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( null !== $compare ) : ?>
			<?php
			$calendar_unit = self::compare_calendar_unit( (string) $filters['compare'] );
			$overlap       = self::overlap_range( $range, $compare );
			?>
			<p class="nowcrm-analytics__subtle">
				<?php
				if ( 'custom' === $filters['compare'] ) {
					esc_html_e( '自訂比較只採用上方填寫的比較起迄日，不會自動延長、對齊或改成與本期相同長度。', 'now-crm' );
				} elseif ( '' !== $calendar_unit ) {
					printf(
						/* translators: 1: from-date anchor, 2: calendar unit, 3: first comparison date, 4: last comparison date. */
						esc_html__( '以本期起始日 %1$s 為錨點，比較採前一個完整日曆%2$s（%3$s 至 %4$s）；長度不必與本期相同，也不會與本期重疊。', 'now-crm' ),
						esc_html( $range->from ),
						esc_html( $calendar_unit ),
						esc_html( $compare->from ),
						esc_html( $compare->to )
					);
				} else {
					esc_html_e( '此比較是緊接在本期開始日之前、天數相同的期間；不會改變本期的起迄日。', 'now-crm' );
				}
				?>
			</p>
			<?php if ( null !== $overlap ) : ?>
				<p class="nowcrm-analytics__subtle">
					<?php
					printf(
						/* translators: 1: first overlapping local date, 2: last overlapping local date. */
						esc_html__( '注意：比較範圍與目前範圍重疊 %1$s 至 %2$s，重疊期間的事件會同時計入兩邊；兩欄差距只反映各自範圍的總量，不能當成單純的前後變化。', 'now-crm' ),
						esc_html( $overlap[0] ),
						esc_html( $overlap[1] )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $parts ) : ?>
			<p class="nowcrm-analytics__subtle">
				<?php echo esc_html( implode( ' · ', $parts ) ); ?>
				<a href="<?php echo esc_url( self::reset_url( $filters ) ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Contacts task: filtered growth, the current status mix and bounded
	 * current rankings.
	 *
	 * @param ReportService        $service Report data source.
	 * @param DateRange            $range   Active range.
	 * @param DateRange|null       $compare Comparison range, when valid.
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function render_contacts( ReportService $service, DateRange $range, ?DateRange $compare, array $filters ): void {
		$list_id = (int) $filters['list_id'];
		$tag_id  = (int) $filters['tag_id'];
		$terms   = new TermRepository();
		$list    = $list_id > 0 ? $terms->get( $list_id ) : null;
		$tag     = $tag_id > 0 ? $terms->get( $tag_id ) : null;

		$list_ok = 0 === $list_id || ( null !== $list && TermRepository::TYPE_LIST === (string) $list['type'] );
		$tag_ok  = 0 === $tag_id || ( null !== $tag && TermRepository::TYPE_TAG === (string) $tag['type'] );

		self::render_panel_open( 'nowcrm-report-contacts', __( '聯絡人', 'now-crm' ) );

		if ( ! $list_ok || ! $tag_ok ) {
			?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( '名單或標籤篩選無效，未顯示任何資料；不會自動改成全部聯絡人。', 'now-crm' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( self::reset_url( $filters ) ); ?>"><?php esc_html_e( '清除無效篩選', 'now-crm' ); ?></a></p>
			<?php
			self::render_panel_close();

			return;
		}

		$contacts        = $service->contact_summary( $range, $list_id, $tag_id );
		$previous_growth = null !== $compare ? $service->contact_growth( $compare, $list_id, $tag_id ) : null;
		$previous_new    = null !== $previous_growth ? array_sum( $previous_growth ) : null;
		$growth          = $service->contact_growth( $range, $list_id, $tag_id );
		$breakdowns      = $service->contact_breakdowns();

		$scope = array();

		if ( null !== $list ) {
			$scope[] = sprintf(
				/* translators: %s: list name. */
				esc_html__( '名單「%s」', 'now-crm' ),
				(string) $list['name']
			);
		}

		if ( null !== $tag ) {
			$scope[] = sprintf(
				/* translators: %s: tag name. */
				esc_html__( '標籤「%s」', 'now-crm' ),
				(string) $tag['name']
			);
		}
		?>
		<p class="nowcrm-analytics__subtle">
			<?php if ( $scope ) : ?>
				<?php
				printf(
					/* translators: %s: filter scope. */
					esc_html__( '新增與每日趨勢套用目前成員資格：%s（同時成立）。狀態與排行榜是目前全部聯絡人的快照，不受此篩選影響。', 'now-crm' ),
					esc_html( implode( '＋', $scope ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( '新增與每日趨勢以聯絡人建立時間計算；狀態與排行榜是目前全部聯絡人的快照，兩者分開呈現、不相加。', 'now-crm' ); ?>
			<?php endif; ?>
		</p>
		<?php
		self::render_metrics(
			array(
				array(
					'label'    => __( '期間內新增', 'now-crm' ),
					'current'  => $contacts['new'],
					'previous' => $previous_new,
					'type'     => 'int',
				),
				array(
					'label'    => __( '目前總數', 'now-crm' ),
					'current'  => $contacts['total'],
					'previous' => null,
					'type'     => 'snapshot',
				),
			),
			$compare
		);

		$chart_title = __( '每日新增聯絡人', 'now-crm' );

		if ( $scope ) {
			$chart_title .= '（' . implode( '＋', $scope ) . '）';
		}

		$trend_series = array( __( '本期新增', 'now-crm' ) => $growth );

		if ( null !== $previous_growth ) {
			$trend_series[ __( '前期新增', 'now-crm' ) ] = $previous_growth;
		}

		AnalyticsTrend::render( $chart_title, $trend_series );
		?>
		<div class="nowcrm-analytics__grid nowcrm-analytics__grid--split">
			<section class="nowcrm-analytics__card">
				<div class="nowcrm-analytics__card-head">
					<h3><?php esc_html_e( '目前狀態分佈', 'now-crm' ); ?></h3>
					<span class="nowcrm-analytics__subtle"><?php esc_html_e( '目前快照', 'now-crm' ); ?></span>
				</div>
				<div class="nowcrm-contacts-surface">
					<table class="widefat striped nowcrm-contacts-table">
						<thead><tr><th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th><th scope="col"><?php esc_html_e( '人數', 'now-crm' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $contacts['status_mix'] as $state => $count ) : ?>
							<tr data-nowcrm-contact-status="<?php echo esc_attr( (string) $state ); ?>">
								<td>
									<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array( 'status' => (string) $state ) ) ); ?>">
										<?php echo esc_html( Status::label( (string) $state ) ); ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<?php self::render_rankings( $breakdowns ); ?>
		</div>
		<p class="description"><?php esc_html_e( '狀態分佈是目前快照；新增只計算建立時間落在範圍內的聯絡人。', 'now-crm' ); ?></p>
		<?php if ( null !== $compare ) : ?>
			<p class="description"><?php esc_html_e( '比較只套用在期間內新增；目前總數與狀態分佈不隨範圍變動。', 'now-crm' ); ?></p>
		<?php endif; ?>
		<?php
		self::render_panel_close();
	}

	/**
	 * Bounded current rankings for lists, tags and countries.
	 *
	 * @param array<string, mixed> $breakdowns Service payload.
	 */
	private static function render_rankings( array $breakdowns ): void {
		$groups = array(
			'lists'     => array( __( '目前名單人數', 'now-crm' ), TermRepository::TYPE_LIST ),
			'tags'      => array( __( '目前標籤人數', 'now-crm' ), TermRepository::TYPE_TAG ),
			'countries' => array( __( '目前國家分佈', 'now-crm' ), '' ),
		);

		foreach ( $groups as $key => [ $title, $type ] ) {
			$rows = (array) ( $breakdowns[ $key ] ?? array() );
			?>
			<section class="nowcrm-analytics__card">
				<div class="nowcrm-analytics__card-head">
					<h3><?php echo esc_html( $title ); ?></h3>
					<span class="nowcrm-analytics__subtle"><?php esc_html_e( '目前快照・最多 5 筆', 'now-crm' ); ?></span>
				</div>
				<?php if ( $rows ) : ?>
					<ul class="nowcrm-analytics__list">
						<?php foreach ( $rows as $row ) : ?>
							<li>
								<span>
									<?php if ( 'countries' === $key ) : ?>
										<a href="<?php echo esc_url( self::country_url( (string) $row['code'] ) ); ?>"><?php echo esc_html( (string) $row['code'] ); ?></a>
									<?php else : ?>
										<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array( ( TermRepository::TYPE_LIST === $type ? 'lists' : 'tags' ) => array( (int) $row['id'] ) ) ) ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a>
									<?php endif; ?>
								</span>
								<strong><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="nowcrm-analytics__subtle"><?php esc_html_e( '目前沒有可排名的人員。', 'now-crm' ); ?></p>
				<?php endif; ?>
			</section>
			<?php
		}
	}

	/**
	 * Email task: totals, daily counters, optional comparison and the
	 * paginated campaign table with drilldown.
	 *
	 * @param ReportService        $service Report data source.
	 * @param DateRange            $range   Active range.
	 * @param DateRange|null       $compare Comparison range, when valid.
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function render_email( ReportService $service, DateRange $range, ?DateRange $compare, array $filters ): void {
		$campaign_id = (int) $filters['campaign_id'];
		$bad_status  = (bool) $filters['status_invalid'];
		$post        = ! $bad_status && $campaign_id > 0 ? Campaigns::get( $campaign_id ) : null;
		$missing     = ! $bad_status && $campaign_id > 0 && null === $post;
		$state       = null !== $post ? Campaigns::state( $campaign_id ) : '';
		$mismatch    = ! $bad_status && ! $missing && null !== $post && '' !== (string) $filters['status'] && $state !== (string) $filters['status'];
		$detail      = ! $bad_status && ! $mismatch && null !== $post ? $service->campaign_detail( $campaign_id, $range ) : null;
		$table       = $service->campaign_table( $range, $filters );
		$previous    = null !== $compare ? $service->campaign_table( $compare, array_merge( $filters, array( 'page' => 1 ) ) ) : null;
		$trends      = $service->email_trends( $range, $filters );
		$totals      = $table['totals'];
		$prior       = $previous['totals'] ?? array();

		self::render_panel_open( 'nowcrm-report-campaigns', __( '電子報', 'now-crm' ) );
		?>
		<?php if ( $missing ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( '找不到這封電子報，原電子報目前無法使用；不套用狀態篩選時，下方仍會列出保留的歷史寄送與互動資料。', 'now-crm' ); ?></p>
			</div>
			<p><a class="button" href="<?php echo esc_url( self::report_url( $filters, array( 'campaign_id' => '', 'paged' => '' ) ) ); ?>"><?php esc_html_e( '清除電子報篩選', 'now-crm' ); ?></a></p>
		<?php endif; ?>
		<?php if ( $bad_status ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( '電子報狀態篩選無效，未顯示任何電子報；不會自動改成全部狀態。', 'now-crm' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $mismatch ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: current campaign status, 2: selected status. */
						esc_html__( '這封電子報目前的狀態是「%1$s」，與所選的「%2$s」不同；表格、明細與 CSV 都不顯示它的資料。', 'now-crm' ),
						esc_html( Campaigns::status_label( $state ) ),
						esc_html( Campaigns::status_label( (string) $filters['status'] ) )
					);
					?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( self::report_url( $filters, array( 'status' => '', 'paged' => '' ) ) ); ?>"><?php esc_html_e( '清除狀態篩選', 'now-crm' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::report_url( $filters, array( 'campaign_id' => '', 'paged' => '' ) ) ); ?>"><?php esc_html_e( '清除電子報篩選', 'now-crm' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<p>
			<a class="button" id="nowcrm-report-export" href="<?php echo esc_url( self::export_url( $filters ) ); ?>"><?php esc_html_e( '匯出 CSV', 'now-crm' ); ?></a>
			<span class="nowcrm-analytics__subtle"><?php esc_html_e( '匯出只含目前電子報篩選與目前期間。', 'now-crm' ); ?></span>
		</p>
		<?php
		self::render_metrics(
			array(
				array( 'label' => __( '已交付', 'now-crm' ), 'current' => $totals['accepted'], 'previous' => $prior['accepted'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '開信', 'now-crm' ), 'current' => $totals['opens'], 'previous' => $prior['opens'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '點擊', 'now-crm' ), 'current' => $totals['clicks'], 'previous' => $prior['clicks'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '退信', 'now-crm' ), 'current' => $totals['bounces'], 'previous' => $prior['bounces'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '投訴', 'now-crm' ), 'current' => $totals['complaints'], 'previous' => $prior['complaints'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '退訂', 'now-crm' ), 'current' => $totals['unsubscribes'], 'previous' => $prior['unsubscribes'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '開信率', 'now-crm' ), 'current' => $totals['open_rate'], 'previous' => $prior['open_rate'] ?? null, 'type' => 'rate' ),
				array( 'label' => __( '點擊率', 'now-crm' ), 'current' => $totals['click_rate'], 'previous' => $prior['click_rate'] ?? null, 'type' => 'rate' ),
			),
			$compare
		);

		$activity = array_sum( $trends['accepted'] ) + array_sum( $trends['opens'] ) + array_sum( $trends['clicks'] ) + array_sum( $trends['unsubscribes'] );

		if ( $activity > 0 ) {
			?>
			<div class="nowcrm-analytics__grid nowcrm-analytics__grid--split">
				<div><?php AnalyticsTrend::render( __( '每日已交付', 'now-crm' ), array( __( '本期已交付', 'now-crm' ) => $trends['accepted'] ) ); ?></div>
				<div><?php AnalyticsTrend::render( __( '每日開信', 'now-crm' ), array( __( '本期開信', 'now-crm' ) => $trends['opens'] ) ); ?></div>
				<div><?php AnalyticsTrend::render( __( '每日點擊', 'now-crm' ), array( __( '本期點擊', 'now-crm' ) => $trends['clicks'] ) ); ?></div>
				<div><?php AnalyticsTrend::render( __( '每日退訂', 'now-crm' ), array( __( '本期退訂', 'now-crm' ) => $trends['unsubscribes'] ) ); ?></div>
			</div>
			<?php
		} else {
			?>
			<p class="nowcrm-analytics__subtle"><?php esc_html_e( '範圍內沒有可繪製的每日寄送或互動資料；總計仍以上方指標為準。', 'now-crm' ); ?></p>
			<?php
		}
		?>

		<?php if ( null !== $detail ) : ?>
			<?php self::render_detail( $detail, $filters ); ?>
		<?php endif; ?>

		<div class="nowcrm-contacts-surface">
			<table class="widefat striped nowcrm-contacts-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( '電子報', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '已交付', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '開信', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '點擊', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '退信', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '投訴', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '退訂', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '開信率', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '點擊率', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '退信率', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '投訴率', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '退訂率', 'now-crm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $table['rows'] ) : ?>
					<tr><td colspan="13">
						<?php
						if ( $missing ) {
							esc_html_e( '找不到這封電子報，沒有可顯示的資料。', 'now-crm' );
						} elseif ( $bad_status ) {
							esc_html_e( '電子報狀態篩選無效，未顯示任何電子報。', 'now-crm' );
						} elseif ( $mismatch ) {
							esc_html_e( '這封電子報目前的狀態不符合所選狀態，未顯示任何資料。', 'now-crm' );
						} elseif ( $campaign_id > 0 ) {
							esc_html_e( '這封電子報在目前範圍內沒有寄送或互動紀錄。', 'now-crm' );
						} else {
							esc_html_e( '範圍內沒有電子報活動。', 'now-crm' );
						}
						?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $table['rows'] as $row ) : ?>
					<tr data-nowcrm-campaign="<?php echo esc_attr( (string) $row['campaign_id'] ); ?>">
						<td>
							<?php if ( $row['detail_available'] ) : ?>
								<a href="<?php echo esc_url( self::report_url( $filters, array( 'tab' => 'email', 'campaign_id' => (int) $row['campaign_id'] ) ) . '#nowcrm-report-campaign-detail' ); ?>">
									<?php echo esc_html( (string) $row['name'] ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( (string) $row['name'] ); ?>
								<span class="description"><?php esc_html_e( '歷史資料，沒有可開啟的明細', 'now-crm' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Campaigns::status_label( (string) $row['status'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['accepted'] ); ?></td>
						<td><?php echo esc_html( (string) $row['opens'] ); ?></td>
						<td><?php echo esc_html( (string) $row['clicks'] ); ?></td>
						<td><?php echo esc_html( (string) $row['bounces'] ); ?></td>
						<td><?php echo esc_html( (string) $row['complaints'] ); ?></td>
						<td><?php echo esc_html( (string) $row['unsubscribes'] ); ?></td>
						<td><?php echo esc_html( self::percent( $row['open_rate'] ) ); ?></td>
						<td><?php echo esc_html( self::percent( $row['click_rate'] ) ); ?></td>
						<td><?php echo esc_html( self::percent( $row['bounce_rate'] ) ); ?></td>
						<td><?php echo esc_html( self::percent( $row['complaint_rate'] ) ); ?></td>
						<td><?php echo esc_html( self::percent( $row['unsubscribe_rate'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $table['pages'] > 1 ) : ?>
			<p class="nowcrm-pagination">
				<?php if ( $table['page'] > 1 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( self::report_url( $filters, array( 'paged' => $table['page'] - 1 ) ) ); ?>"><?php esc_html_e( '上一頁', 'now-crm' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					printf(
						/* translators: 1: current page, 2: total pages. */
						esc_html__( '第 %1$s / %2$s 頁', 'now-crm' ),
						esc_html( (string) $table['page'] ),
						esc_html( (string) $table['pages'] )
					);
					?>
				</span>
				<?php if ( $table['page'] < $table['pages'] ) : ?>
					<a class="button button-small" href="<?php echo esc_url( self::report_url( $filters, array( 'paged' => $table['page'] + 1 ) ) ); ?>"><?php esc_html_e( '下一頁', 'now-crm' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( 0 === $campaign_id && ! $bad_status ) : ?>
			<?php self::render_top_links( $service, $range, $filters ); ?>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( '已交付表示郵件系統接受，不等於送達。開信是弱訊號，郵件用戶端可能預取。', 'now-crm' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '每日趨勢在每一天內去重；上方總計與 CSV 在整段期間內去重。同一位收件人跨兩天活動會出現在兩個每日點，但整段期間只算一次，兩者不可相加。', 'now-crm' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '開信率與點擊率沿用既有契約：期間內不重複事件數除以期間內已交付數。事件可能來自期間之前寄出的電子報，所以比率可能超過 100%，此讀數不是修正後的隊列比率。', 'now-crm' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '營收無法歸因到單一電子報：order_links 沒有 campaign 欄位，因此本表不列每檔營收，也不以推測補值。', 'now-crm' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '沒有 campaign_id 的退訂（例如偏好中心）不列入電子報表。', 'now-crm' ); ?>
		</p>
		<?php if ( null !== $compare ) : ?>
			<p class="description"><?php esc_html_e( '比較套用在整份篩選後的電子報總計；下方清單與單檔明細仍只列本期。', 'now-crm' ); ?></p>
		<?php endif; ?>
		<?php
		self::render_panel_close();
	}

	/**
	 * Bounded top clicked links across the filtered campaign set.
	 *
	 * @param ReportService        $service Report data source.
	 * @param DateRange            $range   Active range.
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function render_top_links( ReportService $service, DateRange $range, array $filters ): void {
		$links = $service->campaign_top_links( $range, $filters, 10 );

		if ( ! $links ) {
			return;
		}
		?>
		<h3><?php esc_html_e( '最多點擊的連結（全部電子報）', 'now-crm' ); ?></h3>
		<div class="nowcrm-contacts-surface">
			<table class="widefat striped nowcrm-contacts-table" id="nowcrm-report-top-links">
				<thead><tr>
					<th scope="col"><?php esc_html_e( '電子報', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '網址', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '不重複點擊人數', 'now-crm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td>
							<?php if ( $link['detail_available'] ) : ?>
								<a href="<?php echo esc_url( self::report_url( $filters, array( 'campaign_id' => (int) $link['campaign_id'] ) ) . '#nowcrm-report-campaign-detail' ); ?>"><?php echo esc_html( (string) $link['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( (string) $link['name'] ); ?>
								<span class="description"><?php esc_html_e( '歷史資料', 'now-crm' ); ?></span>
							<?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( (string) $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( mb_substr( (string) $link['url'], 0, 120 ) ); ?></a></td>
						<td><?php echo esc_html( (string) $link['recipients'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description"><?php esc_html_e( '只列目前篩選與期間內最多 10 個連結；不查詢全部可見聯絡人。', 'now-crm' ); ?></p>
		<?php
	}

	/**
	 * Automation task: bounded retained run starts grouped by current state.
	 *
	 * @param ReportService  $service Report data source.
	 * @param DateRange      $range   Active range.
	 * @param DateRange|null $compare Comparison range, when valid.
	 */
	private static function render_automation( ReportService $service, DateRange $range, ?DateRange $compare ): void {
		$summary  = $service->automation_summary( $range );
		$previous = null !== $compare ? $service->automation_summary( $compare ) : null;
		$trend    = $service->automation_trend( $range );

		self::render_panel_open( 'nowcrm-report-automation', __( '自動化', 'now-crm' ) );
		?>
		<p class="nowcrm-analytics__subtle"><?php esc_html_e( '只計算目前保留的自動化＋聯絡人列，開始時間落在範圍內；不是完整註冊歷史，也不顯示步驟漏斗。', 'now-crm' ); ?></p>
		<?php
		self::render_metrics(
			array(
				array( 'label' => __( '期間內開始的執行（僅保留列）', 'now-crm' ), 'current' => $summary['total'], 'previous' => $previous['total'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '有開始紀錄的自動化（僅保留列）', 'now-crm' ), 'current' => $summary['automations'], 'previous' => $previous['automations'] ?? null, 'type' => 'int' ),
			),
			$compare
		);
		AnalyticsTrend::render(
			__( '每日保留列的執行開始數', 'now-crm' ),
			array( __( '本期開始（僅保留列）', 'now-crm' ) => $trend )
		);

		if ( $summary['rows'] ) :
			?>
			<div class="nowcrm-contacts-surface">
				<table class="widefat striped nowcrm-contacts-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( '自動化', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '期間開始數（僅保留列）', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '目前狀態分佈', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '最近開始', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '紀錄', 'now-crm' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $summary['rows'] as $row ) : ?>
						<tr data-nowcrm-automation="<?php echo esc_attr( (string) $row['automation_id'] ); ?>">
							<td><a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => (int) $row['automation_id'] ) ) ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a></td>
							<td><?php echo esc_html( (string) $row['total'] ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: active, 2: waiting, 3: completed, 4: cancelled, 5: failed. */
									esc_html__( '進行中 %1$d／等待 %2$d／完成 %3$d／取消 %4$d／失敗 %5$d', 'now-crm' ),
									(int) $row['active'],
									(int) $row['waiting'],
									(int) $row['completed'],
									(int) $row['cancelled'],
									(int) $row['failed']
								);
								?>
							</td>
							<td><?php echo esc_html( Menu::format_datetime( (string) $row['last_start'] ) ); ?></td>
							<td><a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => (int) $row['automation_id'] ) ) ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="description">
				<?php esc_html_e( '最多列出 50 個自動化，依期間開始數排序。狀態是每一列的目前狀態，不是開始當時的狀態。', 'now-crm' ); ?>
				<?php if ( (int) $summary['automations'] > count( $summary['rows'] ) ) : ?>
					<?php
					printf(
						/* translators: 1: listed automations, 2: total automations. */
						esc_html__( '目前清單顯示前 %1$d 個，共 %2$d 個自動化有開始紀錄。', 'now-crm' ),
						count( $summary['rows'] ),
						(int) $summary['automations']
					);
					?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p class="nowcrm-analytics__subtle"><?php esc_html_e( '範圍內沒有自動化執行紀錄。自動化只保留每個流程與聯絡人的一列，不會補造註冊歷史。', 'now-crm' ); ?></p>
			<p><a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATIONS_SLUG ) ); ?>"><?php esc_html_e( '查看自動化', 'now-crm' ); ?></a></p>
		<?php endif; ?>
		<?php if ( null !== $compare ) : ?>
			<p class="description"><?php esc_html_e( '比較只套用在期間內開始的執行總數與有紀錄的自動化數；下方狀態分佈仍只列本期。', 'now-crm' ); ?></p>
		<?php endif; ?>
		<?php
		self::render_panel_close();
	}

	/**
	 * Commerce task: counted orders and revenue grouped by currency.
	 *
	 * @param ReportService  $service Report data source.
	 * @param DateRange      $range   Active range.
	 * @param DateRange|null $compare Comparison range, when valid.
	 */
	private static function render_commerce( ReportService $service, DateRange $range, ?DateRange $compare ): void {
		$orders   = $service->order_summary( $range );
		$previous = null !== $compare ? $service->order_summary( $compare ) : null;
		$trend    = $service->order_trend( $range );

		self::render_panel_open( 'nowcrm-report-orders', __( '訂單與營收', 'now-crm' ) );
		self::render_metrics(
			array(
				array( 'label' => __( '計入營收的訂單數', 'now-crm' ), 'current' => $orders['orders'], 'previous' => $previous['orders'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '下單聯絡人數', 'now-crm' ), 'current' => $orders['customers'], 'previous' => $previous['customers'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '平均每聯絡人訂單', 'now-crm' ), 'current' => $orders['average_orders'], 'previous' => $previous['average_orders'] ?? null, 'type' => 'decimal' ),
			),
			$compare
		);

		AnalyticsTrend::render(
			__( '每日計入營收的訂單數', 'now-crm' ),
			array( __( '本期訂單', 'now-crm' ) => $trend )
		);

		$currencies = array_keys( $orders['by_currency'] );

		if ( null !== $previous ) {
			$currencies = array_unique( array_merge( $currencies, array_keys( $previous['by_currency'] ) ) );
		}

		sort( $currencies );

		if ( $currencies ) :
			?>
			<div class="nowcrm-contacts-surface">
				<table class="widefat striped nowcrm-contacts-table">
					<thead>
						<tr>
							<th scope="col" rowspan="2"><?php esc_html_e( '幣別', 'now-crm' ); ?></th>
							<th scope="colgroup" colspan="3"><?php esc_html_e( '本期', 'now-crm' ); ?></th>
							<?php if ( null !== $previous ) : ?>
								<th scope="colgroup" colspan="2"><?php esc_html_e( '前期', 'now-crm' ); ?></th>
							<?php endif; ?>
						</tr>
						<tr>
							<th scope="col"><?php esc_html_e( '訂單數', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '營收', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '平均客單', 'now-crm' ); ?></th>
							<?php if ( null !== $previous ) : ?>
								<th scope="col"><?php esc_html_e( '訂單數', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '營收', 'now-crm' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $currencies as $currency ) : ?>
						<?php
						$now  = $orders['by_currency'][ $currency ] ?? array( 'orders' => 0, 'revenue' => 0.0, 'average' => null );
						$then = $previous['by_currency'][ $currency ] ?? array( 'orders' => 0, 'revenue' => 0.0, 'average' => null );
						$code = '—' === $currency ? '' : (string) $currency;
						?>
						<tr data-nowcrm-currency="<?php echo esc_attr( (string) $currency ); ?>">
							<td><?php echo esc_html( (string) $currency ); ?></td>
							<td><?php echo esc_html( (string) $now['orders'] ); ?></td>
							<td><?php echo esc_html( Menu::format_amount( (float) $now['revenue'], $code ) ); ?></td>
							<td><?php echo esc_html( null === $now['average'] ? '—' : Menu::format_amount( (float) $now['average'], $code ) ); ?></td>
							<?php if ( null !== $previous ) : ?>
								<td><?php echo esc_html( (string) $then['orders'] ); ?></td>
								<td><?php echo esc_html( Menu::format_amount( (float) $then['revenue'], $code ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( '範圍內沒有計入營收的訂單。', 'now-crm' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( '只使用 is_counted=1 的 order_links，並依幣別分開，不跨幣別加總；平均客單也只依幣別計算。', 'now-crm' ); ?></p>
		<p class="description"><?php esc_html_e( '電子報營收無法歸因：order_links 沒有 campaign 欄位，不以推測補值。', 'now-crm' ); ?></p>
		<?php
		self::render_panel_close();
	}

	/**
	 * Cart task: the abandoned-cart cohort, its current state and the bounded
	 * period event counts. State counters describe now; event counts follow
	 * occurred_at. Paid value comes only from attributed counted orders.
	 *
	 * @param ReportService  $service Report data source.
	 * @param DateRange      $range   Active range.
	 * @param DateRange|null $compare Comparison range, when valid.
	 */
	private static function render_cart( ReportService $service, DateRange $range, ?DateRange $compare ): void {
		$carts    = $service->cart_summary( $range );
		$previous = null !== $compare ? $service->cart_summary( $compare ) : null;
		$trend    = $service->cart_trend( $range );

		self::render_panel_open( 'nowcrm-report-carts', __( '購物車挽回', 'now-crm' ) );
		self::render_metrics(
			array(
				array( 'label' => __( '放棄的購物車', 'now-crm' ), 'current' => $carts['abandoned'], 'previous' => $previous['abandoned'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '仍放棄中（目前狀態）', 'now-crm' ), 'current' => $carts['abandoned_open'], 'previous' => $previous['abandoned_open'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '待付款訂單（目前狀態）', 'now-crm' ), 'current' => $carts['order_pending'], 'previous' => $previous['order_pending'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '已流失（目前狀態）', 'now-crm' ), 'current' => $carts['lost'], 'previous' => $previous['lost'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '郵件挽回（目前狀態，不代表已付款）', 'now-crm' ), 'current' => $carts['recovered'], 'previous' => $previous['recovered'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '郵件挽回已付款訂單', 'now-crm' ), 'current' => $carts['recovered_paid'], 'previous' => $previous['recovered_paid'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '自然完成（未確認付款）', 'now-crm' ), 'current' => $carts['completed'], 'previous' => $previous['completed'] ?? null, 'type' => 'int' ),
				array( 'label' => __( '挽回率（已付款訂單／放棄購物車）', 'now-crm' ), 'current' => $carts['recovery_rate'], 'previous' => $previous['recovery_rate'] ?? null, 'type' => 'rate' ),
			),
			$compare
		);
		if ( null !== $previous ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( '狀態類指標的前期欄位是前期 cohort 的目前狀態，不是當時快照；期間事件則依各自期間計算。', 'now-crm' )
			);
		}
		AnalyticsTrend::render(
			__( '每日放棄的購物車', 'now-crm' ),
			array( __( '本期放棄', 'now-crm' ) => $trend )
		);

		$event_rows = array(
			'reminder_queued' => __( '提醒已排入佇列', 'now-crm' ),
			'reminder_sent'   => __( '提醒已交寄（transport 接受，非送達）', 'now-crm' ),
			'recovery_click'  => __( '挽回連結點擊', 'now-crm' ),
			'cart_restored'   => __( '購物車已還原', 'now-crm' ),
			'order_placed'    => __( '訂單已建立（事件，含自然完成）', 'now-crm' ),
			'order_paid'      => __( '訂單已付款（事件，含自然完成）', 'now-crm' ),
		);
		?>
		<h3><?php esc_html_e( '期間事件（依事件發生時間）', 'now-crm' ); ?></h3>
		<div class="nowcrm-contacts-surface">
			<table class="widefat striped nowcrm-contacts-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( '事件', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '本期筆數', 'now-crm' ); ?></th>
					<?php if ( null !== $previous ) : ?>
						<th scope="col"><?php esc_html_e( '前期筆數', 'now-crm' ); ?></th>
					<?php endif; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $event_rows as $event_key => $event_label ) : ?>
					<tr data-nowcrm-cart-event="<?php echo esc_attr( $event_key ); ?>">
						<td><?php echo esc_html( $event_label ); ?></td>
						<td><?php echo esc_html( (string) (int) $carts['period_events'][ $event_key ] ); ?></td>
						<?php if ( null !== $previous ) : ?>
							<td><?php echo esc_html( (string) (int) ( $previous['period_events'][ $event_key ] ?? 0 ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php

		$currencies = array_keys( $carts['recovered_value'] );

		if ( null !== $previous ) {
			$currencies = array_unique( array_merge( $currencies, array_keys( $previous['recovered_value'] ) ) );
		}

		sort( $currencies );

		if ( $currencies ) :
			?>
			<div class="nowcrm-contacts-surface">
				<table class="widefat striped nowcrm-contacts-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( '幣別', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '挽回金額（已付款，扣除退款）', 'now-crm' ); ?></th>
						<?php if ( null !== $previous ) : ?>
							<th scope="col"><?php esc_html_e( '前期挽回金額（已付款，扣除退款）', 'now-crm' ); ?></th>
						<?php endif; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( $currencies as $currency ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $currency ); ?></td>
							<td><?php echo esc_html( Menu::format_amount( (float) ( $carts['recovered_value'][ $currency ] ?? 0 ), '—' === $currency ? '' : (string) $currency ) ); ?></td>
							<?php if ( null !== $previous ) : ?>
								<td><?php echo esc_html( Menu::format_amount( (float) ( $previous['recovered_value'][ $currency ] ?? 0 ), '—' === $currency ? '' : (string) $currency ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( '分母是本範圍內記錄到 abandoned 事件的購物車；同一台購物車只計一次。目前狀態的「待付款訂單」「郵件挽回」與「自然完成」都不代表已付款：建立訂單只進入待付款，未取得 WooCommerce 付款時間前不算成交；升級前的「郵件挽回」列可能只有狀態、沒有可核對的付款，因此不保證已付款。「郵件挽回已付款訂單」只計目前狀態為「郵件挽回」、具郵件歸因，且 is_counted=1 並有實際付款時間的訂單；待付款狀態即使 WooCommerce 已有付款時間也不提前計入。挽回金額是這批已付款訂單的實際淨額（扣除退款），依幣別分開，不跨幣別加總；挽回率是已付款挽回訂單數除以不重複的放棄購物車數，分母為 0 時顯示「—」。期間事件依事件的 occurred_at 落在本範圍計算，只計固定事件類型，同一事件可重複計次；事件筆數不是目前狀態，也不是營收，「提醒已交寄」只代表 transport 接受。只使用 WooCommerce 既有紀錄，沒有訂單連結時不推估補值。', 'now-crm' ); ?></p>
		<p class="description">
			<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG ) ); ?>"><?php esc_html_e( '查看購物車清單與提醒設定', 'now-crm' ); ?></a>
		</p>
		<?php
		self::render_panel_close();
	}

	/**
	 * Open a report panel.
	 */
	private static function render_panel_open( string $id, string $title ): void {
		?>
		<section class="nowcrm-panel nowcrm-analytics__card" id="<?php echo esc_attr( $id ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
		<?php
	}

	/**
	 * Close a report panel.
	 */
	private static function render_panel_close(): void {
		?>
		</section>
		<?php
	}

	/**
	 * Render a metric set as a plain list, or current/previous when comparing.
	 *
	 * @param array<int, array{label: string, current: mixed, previous: mixed, type: string}> $metrics Metric definitions.
	 * @param DateRange|null                                                                  $compare Comparison range, when valid.
	 */
	private static function render_metrics( array $metrics, ?DateRange $compare ): void {
		if ( null === $compare ) {
			echo '<div class="nowcrm-analytics__metrics">';

			foreach ( $metrics as $metric ) {
				printf(
					'<div><span>%s</span><strong>%s</strong></div>',
					esc_html( (string) $metric['label'] ),
					esc_html( self::metric_value( $metric['current'], (string) $metric['type'] ) )
				);
			}

			echo '</div>';

			return;
		}
		?>
		<div class="nowcrm-contacts-surface">
			<table class="widefat striped nowcrm-contacts-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( '指標', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '本期', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '前期', 'now-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( '變化', 'now-crm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $metrics as $metric ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $metric['label'] ); ?></td>
						<td><?php echo esc_html( self::metric_value( $metric['current'], (string) $metric['type'] ) ); ?></td>
						<td><?php echo esc_html( self::metric_value( $metric['previous'], (string) $metric['type'] ) ); ?></td>
						<td><?php echo esc_html( self::metric_delta( $metric['current'], $metric['previous'], (string) $metric['type'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description">
			<?php esc_html_e( '前期為上方比較範圍；變化是本期減前期。比率變化以百分點表示，分母為 0 或為快照值時顯示「—」。', 'now-crm' ); ?>
		</p>
		<?php
	}

	/**
	 * Format one metric value.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  int, rate, decimal or snapshot.
	 */
	private static function metric_value( mixed $value, string $type ): string {
		if ( 'rate' === $type ) {
			return self::percent( null === $value ? null : (float) $value );
		}

		if ( 'decimal' === $type ) {
			return null === $value ? '—' : number_format_i18n( (float) $value, 2 );
		}

		return null === $value ? '—' : number_format_i18n( (int) $value );
	}

	/**
	 * Current-minus-previous delta, or an em dash when it cannot be computed.
	 *
	 * @param mixed  $current  Current value.
	 * @param mixed  $previous Previous value.
	 * @param string $type     int, rate, decimal or snapshot.
	 */
	private static function metric_delta( mixed $current, mixed $previous, string $type ): string {
		if ( 'snapshot' === $type || null === $previous || null === $current ) {
			return '—';
		}

		if ( 'rate' === $type ) {
			$delta = ( (float) $current - (float) $previous ) * 100;

			return ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta, 1 ) . ' 個百分點';
		}

		if ( 'decimal' === $type ) {
			$delta = (float) $current - (float) $previous;

			return ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta, 2 );
		}

		$delta = (int) $current - (int) $previous;

		return ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta );
	}

	/**
	 * Per-campaign drilldown block.
	 *
	 * @param array{campaign: array<string, mixed>, links: array<int, array<string, int|string>>, events: array<int, array<string, mixed>>} $detail Detail payload.
	 * @param array<string, mixed>                                                                                                      $filters Current filters.
	 */
	private static function render_detail( array $detail, array $filters ): void {
		$campaign = $detail['campaign'];
		?>
		<div id="nowcrm-report-campaign-detail">
			<div class="nowcrm-analytics__card-head">
				<h3><?php echo esc_html( (string) $campaign['name'] ); ?></h3>
				<a href="<?php echo esc_url( self::report_url( $filters, array( 'campaign_id' => '', 'paged' => '' ) ) ); ?>"><?php esc_html_e( '清除電子報篩選（保留期間）', 'now-crm' ); ?></a>
			</div>
			<div class="nowcrm-analytics__metrics">
				<div><span><?php esc_html_e( '已交付', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['accepted'] ); ?></strong></div>
				<div><span><?php esc_html_e( '開信', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['opens'] ); ?></strong></div>
				<div><span><?php esc_html_e( '點擊', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['clicks'] ); ?></strong></div>
				<div><span><?php esc_html_e( '退信', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['bounces'] ); ?></strong></div>
				<div><span><?php esc_html_e( '投訴', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['complaints'] ); ?></strong></div>
				<div><span><?php esc_html_e( '退訂', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $campaign['unsubscribes'] ); ?></strong></div>
			</div>

			<?php if ( $detail['links'] ) : ?>
				<h4><?php esc_html_e( '點擊連結', 'now-crm' ); ?></h4>
				<div class="nowcrm-contacts-surface">
					<table class="widefat striped nowcrm-contacts-table" id="nowcrm-report-campaign-links">
						<thead><tr><th scope="col"><?php esc_html_e( '網址', 'now-crm' ); ?></th><th scope="col"><?php esc_html_e( '收件人數', 'now-crm' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $detail['links'] as $link ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( mb_substr( (string) $link['url'], 0, 120 ) ); ?></a></td>
								<td><?php echo esc_html( (string) $link['recipients'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="nowcrm-analytics__subtle"><?php esc_html_e( '目前範圍內沒有點擊連結。', 'now-crm' ); ?></p>
			<?php endif; ?>

			<?php if ( $detail['events'] ) : ?>
				<h4><?php esc_html_e( '最近事件', 'now-crm' ); ?></h4>
				<div class="nowcrm-contacts-surface">
					<table class="widefat striped nowcrm-contacts-table" id="nowcrm-report-campaign-events">
						<thead><tr>
							<th scope="col"><?php esc_html_e( '時間', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '類型', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Job', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '網址', 'now-crm' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $detail['events'] as $event ) : ?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $event['occurred_at'] ) ); ?></td>
								<td><?php echo esc_html( self::event_label( (string) $event['type'] ) ); ?></td>
								<td>
									<?php if ( (int) $event['contact_id'] > 0 ) : ?>
										<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $event['contact_id'] ) ) ); ?>"><?php echo esc_html( '#' . (string) $event['contact_id'] ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (int) $event['job_id'] > 0 ? (string) $event['job_id'] : '—' ); ?></td>
								<td><?php echo '' === (string) $event['url'] ? '—' : esc_html( mb_substr( (string) $event['url'], 0, 80 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="description">
				<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'campaign_id' => (int) $campaign['campaign_id'] ) ) ); ?>"><?php esc_html_e( '查看這封電子報的寄送紀錄', 'now-crm' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( '此明細只列目前期間；切換期間請使用上方日期欄位，篩選會保留。', 'now-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Request filters.
	 *
	 * @return array{tab: string, from: string, to: string, compare: string, compare_from: string, compare_to: string, campaign_id: int, status: string, status_invalid: bool, list_id: int, tag_id: int, page: int}
	 */
	private static function filters(): array {
		$campaign_id    = absint( Menu::query( 'campaign_id' ) );
		$page           = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$raw_status     = sanitize_key( Menu::query( 'status' ) );
		$status_invalid = '' !== $raw_status && ! in_array( $raw_status, self::states(), true );
		$status         = $raw_status;
		$list_id        = absint( Menu::query( 'list_id' ) );
		$tag_id         = absint( Menu::query( 'tag_id' ) );

		$tab = sanitize_key( Menu::query( 'tab' ) );

		if ( ! isset( self::TABS[ $tab ] ) ) {
			// Legacy report URLs only ever put the campaign filter, status or
			// page on the campaign table. Keep them landing there instead of
			// silently dropping that state on the contacts tab.
			$tab = ( $campaign_id > 0 || '' !== $status || $page > 1 ) ? 'email' : 'contacts';
		}

		$compare = sanitize_key( Menu::query( 'compare' ) );

		if ( ! isset( self::COMPARE[ $compare ] ) ) {
			$compare = '';
		}

		return array(
			'tab'            => $tab,
			'from'           => sanitize_text_field( Menu::query( 'from' ) ),
			'to'             => sanitize_text_field( Menu::query( 'to' ) ),
			'compare'        => $compare,
			'compare_from'   => sanitize_text_field( Menu::query( 'compare_from' ) ),
			'compare_to'     => sanitize_text_field( Menu::query( 'compare_to' ) ),
			'campaign_id'    => $campaign_id,
			'status'         => $status,
			'status_invalid' => $status_invalid,
			'list_id'        => $list_id,
			'tag_id'         => $tag_id,
			'page'           => $page,
		);
	}

	/**
	 * Build the range from filters.
	 *
	 * @param array<string, mixed> $filters Request filters.
	 */
	private static function range( array $filters ): DateRange {
		return DateRange::parse( (string) $filters['from'], (string) $filters['to'], wp_timezone() );
	}

	/**
	 * Build the optional comparison range. Invalid values fall back to no
	 * comparison instead of a silently different period.
	 *
	 * @param array<string, mixed> $filters Request filters.
	 */
	private static function compare_range( array $filters ): ?DateRange {
		$mode = (string) $filters['compare'];

		if ( '' === $mode ) {
			return null;
		}

		$tz = wp_timezone();

		if ( 'custom' === $mode ) {
			$from = (string) $filters['compare_from'];
			$to   = (string) $filters['compare_to'];

			if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) ) {
				return null;
			}

			return DateRange::parse( $from, $to, $tz );
		}

		$range = self::range( $filters );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $range->from, new \DateTimeZone( 'UTC' ) );

		if ( ! $start instanceof \DateTimeImmutable ) {
			return null;
		}

		switch ( $mode ) {
			case 'previous_month':
				// The complete calendar month before the from-date's month.
				$first = $start->modify( 'first day of this month' )->modify( '-1 month' );
				$from  = $first->format( 'Y-m-d' );
				$to    = $first->modify( 'last day of this month' )->format( 'Y-m-d' );
				break;
			case 'previous_quarter':
				// The complete calendar quarter before the from-date's quarter.
				$quarter = intdiv( (int) $start->format( 'n' ) - 1, 3 ) * 3 + 1;
				$first   = $start->setDate( (int) $start->format( 'Y' ), $quarter, 1 )->modify( '-3 months' );
				$from    = $first->format( 'Y-m-d' );
				$to      = $first->modify( '+3 months -1 day' )->format( 'Y-m-d' );
				break;
			case 'previous_year':
				// The complete calendar year before the from-date's year.
				$year = (int) $start->format( 'Y' ) - 1;
				$from = $year . '-01-01';
				$to   = $year . '-12-31';
				break;
			default:
				$days = $range->days();

				if ( $days < 1 ) {
					return null;
				}

				$from = $start->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
				$to   = $start->modify( '-1 day' )->format( 'Y-m-d' );
		}

		return DateRange::parse( $from, $to, $tz );
	}

	/**
	 * Calendar unit label for the complete-period comparison modes.
	 * previous_period and custom return an empty string.
	 */
	private static function compare_calendar_unit( string $mode ): string {
		switch ( $mode ) {
			case 'previous_month':
				return '月';
			case 'previous_quarter':
				return '季';
			case 'previous_year':
				return '年';
			default:
				return '';
		}
	}

	/**
	 * Inclusive intersection of two ranges, or null when they do not overlap.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function overlap_range( DateRange $range, DateRange $compare ): ?array {
		$from = max( $range->from, $compare->from );
		$to   = min( $range->to, $compare->to );

		return $from <= $to ? array( $from, $to ) : null;
	}

	/**
	 * Strict Y-m-d validation for the custom comparison range.
	 */
	private static function valid_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Campaign states accepted by the filter.
	 *
	 * @return string[]
	 */
	private static function states(): array {
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
	 * Report URL carrying the current filters.
	 *
	 * @param array<string, mixed> $filters   Current filters.
	 * @param array<string, mixed> $overrides Changed values.
	 */
	private static function report_url( array $filters, array $overrides = array() ): string {
		$args = array_merge(
			array(
				'tab'          => $filters['tab'],
				'from'         => $filters['from'],
				'to'           => $filters['to'],
				'compare'      => $filters['compare'],
				'compare_from' => $filters['compare_from'],
				'compare_to'   => $filters['compare_to'],
			),
			self::scope_args( $filters ),
			$overrides
		);

		return Menu::page_url( self::SLUG, self::compact( $args ) );
	}

	/**
	 * Tab-specific filters that belong in internal links.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @return array<string, mixed>
	 */
	private static function scope_args( array $filters ): array {
		if ( 'email' === $filters['tab'] ) {
			return array(
				'campaign_id' => $filters['campaign_id'],
				'status'      => $filters['status'],
				'paged'       => $filters['page'] > 1 ? $filters['page'] : '',
			);
		}

		if ( 'contacts' === $filters['tab'] ) {
			return array(
				'list_id' => $filters['list_id'],
				'tag_id'  => $filters['tag_id'],
			);
		}

		return array();
	}

	/**
	 * Tab link that keeps the range and comparison, but only the filters the
	 * target tab actually uses.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @param string               $tab     Target tab.
	 */
	private static function tab_url( array $filters, string $tab ): string {
		$args = array(
			'tab'          => $tab,
			'from'         => $filters['from'],
			'to'           => $filters['to'],
			'compare'      => $filters['compare'],
			'compare_from' => $filters['compare_from'],
			'compare_to'   => $filters['compare_to'],
		);

		if ( 'email' === $tab ) {
			$args['campaign_id'] = $filters['campaign_id'];
			$args['status']      = $filters['status'];
			$args['paged']       = $filters['page'] > 1 ? $filters['page'] : '';
		}

		if ( 'contacts' === $tab ) {
			$args['list_id'] = $filters['list_id'];
			$args['tag_id']  = $filters['tag_id'];
		}

		return Menu::page_url( self::SLUG, self::compact( $args ) );
	}

	/**
	 * Reset URL for the active tab.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function reset_url( array $filters ): string {
		return self::report_url(
			$filters,
			array(
				'from'         => '',
				'to'           => '',
				'compare'      => '',
				'compare_from' => '',
				'compare_to'   => '',
				'campaign_id'  => '',
				'status'       => '',
				'list_id'      => '',
				'tag_id'       => '',
				'paged'        => '',
			)
		);
	}

	/**
	 * Signed export URL carrying the campaign filters.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 */
	private static function export_url( array $filters ): string {
		$args = self::compact(
			array(
				'action'      => self::NONCE_EXPORT,
				'from'        => $filters['from'],
				'to'          => $filters['to'],
				'campaign_id' => $filters['campaign_id'],
				'status'      => $filters['status'],
			)
		);

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE_EXPORT );
	}

	/**
	 * Contact-list URL for a country ranking row.
	 */
	private static function country_url( string $code ): string {
		return Menu::page_url(
			Menu::SLUG,
			array(
				'mode'      => 'advanced',
				'nowcrm_adv' => array(
					array(
						array(
							'provider' => 'contact',
							'field'    => 'country',
							'operator' => 'in',
							'value'    => array( $code ),
						),
					),
				),
			)
		);
	}

	/**
	 * Drop empty query values.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	private static function compact( array $args ): array {
		return array_filter(
			$args,
			static function ( $value ): bool {
				return '' !== $value && 0 !== $value && null !== $value;
			}
		);
	}

	/**
	 * Render a rate as a percentage, or an em dash when the denominator is zero.
	 */
	private static function percent( ?float $rate ): string {
		return null === $rate ? '—' : number_format_i18n( $rate * 100, 1 ) . '%';
	}

	/**
	 * Event type label.
	 */
	private static function event_label( string $type ): string {
		$labels = array(
			'open'        => __( '開信', 'now-crm' ),
			'click'       => __( '點擊', 'now-crm' ),
			'bounce'      => __( '退信', 'now-crm' ),
			'complaint'   => __( '投訴', 'now-crm' ),
			'unsubscribe' => __( '退訂', 'now-crm' ),
		);

		return $labels[ $type ] ?? $type;
	}
}
