<?php
/**
 * Automation list, status controls, and draft creation.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AutomationListPage {

	public const PER_PAGE = WorkflowCatalog::PER_PAGE;

	/**
	 * Human labels for automation statuses.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			AutomationRepository::STATUS_DRAFT  => __( '草稿', 'now-crm' ),
			AutomationRepository::STATUS_ACTIVE => __( '啟用中', 'now-crm' ),
			AutomationRepository::STATUS_PAUSED => __( '已暫停', 'now-crm' ),
		);
	}

	/**
	 * Label of one status.
	 */
	public static function status_label( string $status ): string {
		return self::status_labels()[ $status ] ?? $status;
	}

	/**
	 * Label of one trigger key.
	 */
	public static function trigger_label( string $key ): string {
		return TriggerRegistry::triggers()[ $key ] ?? $key;
	}

	/**
	 * Render the list.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$repository = new AutomationRepository();
		$search     = sanitize_text_field( Menu::query( 's' ) );
		$status     = sanitize_key( Menu::query( 'status', '' ) );
		$paged      = absint( Menu::query( 'paged', '0' ) );
		$statuses   = self::status_labels();

		// Row forms return their context as filter_* query arguments.
		if ( '' === $search ) {
			$search = sanitize_text_field( Menu::query( 'filter_search' ) );
		}

		if ( '' === $status ) {
			$status = sanitize_key( Menu::query( 'filter_status', 'all' ) );
		}

		if ( $paged < 1 ) {
			$paged = absint( Menu::query( 'filter_paged', '1' ) );
		}

		if ( ! in_array( $status, array_merge( array( 'all' ), array_keys( $statuses ) ), true ) ) {
			$status = 'all';
		}

		$result = $repository->catalog(
			$search,
			'all' === $status ? '' : $status,
			max( 1, $paged ),
			self::PER_PAGE
		);

		$items   = $result['items'];
		$counts  = $result['counts'];
		$total   = (int) $result['total'];
		$page    = (int) $result['page'];
		$pages   = (int) $result['pages'];
		$library = '' === $search ? (int) $counts['all'] : (int) $repository->catalog( '', '', 1, 1 )['counts']['all'];
		$show_form = '1' === Menu::query( 'new' );
		$rows    = array();

		$filter_args = array();

		if ( '' !== $search ) {
			$filter_args['s'] = $search;
		}

		if ( 'all' !== $status ) {
			$filter_args['status'] = $status;
		}

		if ( $page > 1 ) {
			$filter_args['paged'] = $page;
		}

		$list_url   = Menu::page_url( Menu::AUTOMATIONS_SLUG, $filter_args );
		$create_url = Menu::page_url( Menu::AUTOMATIONS_SLUG, array_merge( $filter_args, array( 'new' => '1' ) ) );
		$triggers   = TriggerRegistry::triggers();

		foreach ( $items as $item ) {
			$id        = (int) $item['id'];
			$edit_url  = Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
			$runs_url  = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id ) );
			$countsrow = $repository->run_counts( $id );
			$is_active = AutomationRepository::STATUS_ACTIVE === $item['status'];
			$active    = (int) ( $countsrow[ RunRepository::STATUS_ACTIVE ] ?? 0 );
			$waiting   = (int) ( $countsrow[ RunRepository::STATUS_WAITING ] ?? 0 );
			$completed = (int) ( $countsrow[ RunRepository::STATUS_COMPLETED ] ?? 0 );

			ob_start();
			?>
			<a class="nowcrm-catalog__link" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) $item['name'] ); ?></a>
			<div class="nowcrm-catalog__meta">#<?php echo esc_html( (string) $id ); ?></div>
			<div class="nowcrm-catalog__row-actions">
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="nowcrm_set_automation_status" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_set_automation_status' ); ?>
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>" />
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status ); ?>" />
					<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $page ); ?>" />
					<?php if ( $is_active ) : ?>
						<input type="hidden" name="status" value="paused" />
						<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '暫停', 'now-crm' ); ?></button>
					<?php else : ?>
						<input type="hidden" name="status" value="active" />
						<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '啟用', 'now-crm' ); ?></button>
					<?php endif; ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="nowcrm_duplicate_automation" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_duplicate_automation' ); ?>
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>" />
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status ); ?>" />
					<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $page ); ?>" />
					<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '複製', 'now-crm' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form"
					onsubmit="return confirm('<?php echo esc_js( __( '刪除自動化也會刪除所有執行紀錄。確定要刪除嗎？', 'now-crm' ) ); ?>');">
					<input type="hidden" name="action" value="nowcrm_delete_automation" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_delete_automation' ); ?>
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>" />
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status ); ?>" />
					<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $page ); ?>" />
					<button type="submit" class="nowcrm-catalog__row-action nowcrm-catalog__row-action--danger"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
				</form>
			</div>
			<?php
			$title_cell = (string) ob_get_clean();

			$status_cell = '<span class="nowcrm-status-pill' . ( $is_active ? ' nowcrm-status-pill--active' : '' ) . '">'
				. ( $is_active ? '<span class="nowcrm-status-pill__dot" aria-hidden="true"></span>' : '' )
				. esc_html( self::status_label( (string) $item['status'] ) )
				. '</span>';

			$active_url    = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id, 'run_status' => RunRepository::STATUS_ACTIVE ) );
			$waiting_url   = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id, 'run_status' => RunRepository::STATUS_WAITING ) );
			$completed_url = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id, 'run_status' => RunRepository::STATUS_COMPLETED ) );

			$rows[] = array(
				'id'       => $id,
				'label'    => (string) $item['name'],
				'eligible' => true,
				'cells'    => array(
					$title_cell,
					'<span class="nowcrm-catalog__muted">' . esc_html( self::trigger_label( (string) $item['trigger_key'] ) ) . '</span>',
					$status_cell,
					'<a class="nowcrm-catalog__number" href="' . esc_url( $active_url ) . '">' . esc_html( (string) $active ) . '</a>',
					'<a class="nowcrm-catalog__number" href="' . esc_url( $waiting_url ) . '">' . esc_html( (string) $waiting ) . '</a>',
					'<a class="nowcrm-catalog__number" href="' . esc_url( $completed_url ) . '">' . esc_html( (string) $completed ) . '</a>',
					'<span class="nowcrm-catalog__time">' . esc_html( Menu::format_datetime( (string) $item['updated_at'] ) ) . '</span>',
				),
			);
		}

		Actions::render_notice();
		WorkflowCatalog::render_notice( __( '自動化', 'now-crm' ) );

		$panel = '';

		$trigger_descriptions = array();

		foreach ( array_keys( $triggers ) as $trigger_key ) {
			$trigger_descriptions[ $trigger_key ] = TriggerRegistry::description( $trigger_key );
		}

		ob_start();
		?>
			<dialog class="nowcrm-drawer" id="nowcrm-automation-drawer"
				aria-labelledby="nowcrm-automation-drawer-title"
				<?php echo $show_form ? 'open' : ''; ?>>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="nowcrm-drawer__form" id="nowcrm-automation-create">
					<input type="hidden" name="action" value="nowcrm_create_automation" />
					<?php wp_nonce_field( 'nowcrm_create_automation' ); ?>

					<div class="nowcrm-drawer__header">
						<h2 id="nowcrm-automation-drawer-title"><?php esc_html_e( '新增自動化', 'now-crm' ); ?></h2>
						<a class="nowcrm-drawer__close" href="<?php echo esc_url( $list_url ); ?>"
							data-nowcrm-close-automation aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</a>
					</div>

					<div class="nowcrm-drawer__body">
						<p class="description">
							<?php esc_html_e( '先選觸發事件。建立後可在編輯器加入動作、等待、Email 與條件分支。', 'now-crm' ); ?>
						</p>

						<label class="nowcrm-field">
							<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
							<input type="text" name="name" class="regular-text" required="required" autofocus="autofocus"
								autocomplete="off" data-nowcrm-automation-name
								data-testid="automation-create-name" />
						</label>

						<label class="nowcrm-field">
							<span><?php esc_html_e( '觸發事件', 'now-crm' ); ?></span>
							<select name="trigger_key" class="regular-text" required="required"
								aria-describedby="nowcrm-automation-trigger-help"
								data-nowcrm-automation-trigger data-testid="automation-create-trigger">
								<option value=""><?php esc_html_e( '請選擇觸發事件', 'now-crm' ); ?></option>
								<?php foreach ( $triggers as $trigger_key => $trigger_label ) : ?>
									<option value="<?php echo esc_attr( $trigger_key ); ?>"><?php echo esc_html( $trigger_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<p class="description" id="nowcrm-automation-trigger-help" data-nowcrm-trigger-help
							data-empty="<?php esc_attr_e( '選擇觸發事件後，這裡會顯示該事件的說明。', 'now-crm' ); ?>">
							<?php esc_html_e( '選擇觸發事件後，這裡會顯示該事件的說明。', 'now-crm' ); ?>
						</p>
					</div>

					<div class="nowcrm-drawer__footer">
						<a class="button" href="<?php echo esc_url( $list_url ); ?>"
							data-nowcrm-close-automation><?php esc_html_e( '取消', 'now-crm' ); ?></a>
						<button type="submit" class="button button-primary"><?php esc_html_e( '建立草稿', 'now-crm' ); ?></button>
					</div>
				</form>
			</dialog>
			<script>
			( function () {
				var dialog = document.getElementById( 'nowcrm-automation-drawer' );

				if ( ! dialog ) {
					return;
				}

				var nameField    = dialog.querySelector( '[data-nowcrm-automation-name]' );
				var trigger      = dialog.querySelector( '[data-nowcrm-automation-trigger]' );
				var help         = dialog.querySelector( '[data-nowcrm-trigger-help]' );
				var descriptions = <?php echo wp_json_encode( $trigger_descriptions ); ?>;
				var pageSlug     = <?php echo wp_json_encode( Menu::AUTOMATIONS_SLUG ); ?>;
				var listUrl      = <?php echo wp_json_encode( $list_url ); ?>;
				var opener       = null;

				function syncHelp() {
					if ( ! help ) {
						return;
					}

					var key = trigger ? trigger.value : '';
					help.textContent = key && descriptions[ key ] ? descriptions[ key ] : ( help.getAttribute( 'data-empty' ) || '' );
				}

				function open( link ) {
					opener = link || document.activeElement;

					if ( typeof dialog.showModal === 'function' ) {
						if ( dialog.open ) {
							dialog.close();
						}

						dialog.showModal();
					} else {
						dialog.setAttribute( 'open', '' );
					}

					if ( nameField && typeof nameField.focus === 'function' ) {
						nameField.focus();
					}
				}

				function close() {
					var direct = '1' === new URL( window.location.href ).searchParams.get( 'new' );

					if ( typeof dialog.close === 'function' && dialog.open ) {
						dialog.close();
					} else {
						dialog.removeAttribute( 'open' );
					}

					if ( direct ) {
						window.location.assign( listUrl );

						return;
					}

					if ( opener && typeof opener.focus === 'function' ) {
						opener.focus();
					}
				}

				document.addEventListener( 'click', function ( event ) {
					if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
						return;
					}

					var link = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;

					if ( ! link || ( link.target && '_self' !== link.target ) ) {
						return;
					}

					var url;

					try {
						url = new URL( link.href, window.location.href );
					} catch ( error ) {
						return;
					}

					if ( pageSlug !== url.searchParams.get( 'page' ) || '1' !== url.searchParams.get( 'new' ) ) {
						return;
					}

					event.preventDefault();
					open( link );
				} );

				dialog.querySelectorAll( '[data-nowcrm-close-automation]' ).forEach( function ( control ) {
					control.addEventListener( 'click', function ( event ) {
						if ( 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
							return;
						}

						event.preventDefault();
						close();
					} );
				} );

				dialog.addEventListener( 'click', function ( event ) {
					if ( event.target === dialog ) {
						close();
					}
				} );

				dialog.addEventListener( 'cancel', function ( event ) {
					event.preventDefault();
					close();
				} );

				if ( trigger ) {
					trigger.addEventListener( 'change', syncHelp );
				}

				syncHelp();

				if ( dialog.hasAttribute( 'open' ) ) {
					if ( typeof dialog.showModal === 'function' ) {
						dialog.close();
						dialog.showModal();
					}

					if ( nameField && typeof nameField.focus === 'function' ) {
						nameField.focus();
					}
				}
			} )();
			</script>
		<?php
		$panel = (string) ob_get_clean();

		WorkflowCatalog::render(
			array(
				'slug'               => Menu::AUTOMATIONS_SLUG,
				'title'              => __( '自動化', 'now-crm' ),
				'noun'               => __( '自動化', 'now-crm' ),
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
					'url'   => $create_url,
					'label' => __( '新增自動化', 'now-crm' ),
				),
				'columns'            => array(
					array( 'label' => __( '名稱', 'now-crm' ), 'class' => 'nowcrm-catalog__col-main' ),
					array( 'label' => __( '觸發事件', 'now-crm' ) ),
					array( 'label' => __( '狀態', 'now-crm' ), 'class' => 'nowcrm-catalog__col-status' ),
					array( 'label' => __( '執行中', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '等待中', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '完成', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '更新時間', 'now-crm' ), 'class' => 'nowcrm-catalog__col-time' ),
				),
				'rows'               => $rows,
				'testid'             => 'automation-list',
				'bulk'               => array(
					'action'   => WorkflowCatalogActions::AUTOMATION_ACTION,
					'nonce'    => WorkflowCatalogActions::AUTOMATION_ACTION,
					'field'    => 'automation_ids',
					'options'  => array(
						'status' => __( '變更狀態', 'now-crm' ),
						'delete' => __( '刪除', 'now-crm' ),
					),
					'statuses' => $statuses,
					'confirm'  => array(
						'status' => __( '確定變更選取自動化的狀態？設為「啟用中」會開始執行；「已暫停」只會停止新的執行。', 'now-crm' ),
						'delete' => __( '確定刪除選取的自動化？所有執行紀錄會一併刪除，且無法復原。', 'now-crm' ),
					),
					'note'     => __( '狀態變更會保留執行紀錄；刪除會一併移除執行紀錄。', 'now-crm' ),
				),
				'empty'              => array(
					'filtered' => __( '沒有符合條件的自動化。', 'now-crm' ),
					'page'     => __( '這一頁沒有自動化。', 'now-crm' ),
					'library'  => __( '還沒有自動化，先建立第一個。', 'now-crm' ),
				),
			)
		);
	}
}
