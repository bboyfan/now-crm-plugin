<?php
/**
 * Server-rendered sequence editor.
 *
 * Inline JavaScript adds, removes, swaps, and reindexes step cards, keeps the
 * lossless delay fields in sync, and refreshes the from-enrollment timeline.
 * The browser posts a normal `steps[]` array; the repository validates it again.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Segments\SegmentRepository;
use NowCRM\Sequences\AudienceResolver;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class SequenceEditorPage {

	/**
	 * Render the editor.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id       = absint( Menu::query( 'id' ) );
		$repo     = new SequenceRepository();
		$sequence = $repo->get( $id );

		if ( ! $sequence ) {
			printf(
				'<div class="wrap nowcrm-wrap"><div class="notice notice-error"><p>%s</p> <a href="%s">%s</a></div></div>',
				esc_html__( '找不到序列。', 'now-crm' ),
				esc_url( Menu::page_url( Menu::SEQUENCES_SLUG ) ),
				esc_html__( '返回列表', 'now-crm' )
			);

			return;
		}

		$steps        = (array) $sequence['steps'];
		$audience     = (array) $sequence['audience'];
		$audience_type = (string) ( $audience['type'] ?? AudienceResolver::TYPE_ALL );
		$lists        = ( new TermRepository() )->all( TermRepository::TYPE_LIST );
		$tags         = ( new TermRepository() )->all( TermRepository::TYPE_TAG );
		$segments     = ( new SegmentRepository() )->all(
			array(
				'status'         => 'active',
				'include_system' => false,
			)
		);
		$system       = \NowCRM\Segments\SystemSegments::all();
		$filters      = AudienceResolver::TYPE_FILTERS === $audience_type ? ( $audience['filters'] ?? null ) : null;
		$form_url     = admin_url( 'admin-post.php' );
		$list_url     = Menu::page_url( Menu::SEQUENCES_SLUG );
		$runs_url     = Menu::page_url( Menu::SEQUENCE_RUNS_SLUG, array( 'id' => $id ) );
		?>
		<div class="wrap nowcrm-wrap nowcrm-form-workspace nowcrm-sequence-editor">
			<div class="nowcrm-form-workspace__header">
				<div class="nowcrm-form-workspace__heading">
					<h1><?php esc_html_e( '序列編輯器', 'now-crm' ); ?></h1>
					<p class="nowcrm-form-workspace__meta">
						<?php
						printf(
							/* translators: %d: number of steps. */
							esc_html__( '%d 個步驟', 'now-crm' ),
							count( $steps )
						);
						?>
						· <?php echo esc_html( SequenceListPage::audience_label( $audience ) ); ?>
						· <?php echo esc_html( SequenceListPage::status_label( (string) $sequence['status'] ) ); ?>
					</p>
				</div>
				<div class="nowcrm-form-workspace__actions">
					<button type="button" class="button button-primary" id="nowcrm-add-step"><?php esc_html_e( '新增郵件', 'now-crm' ); ?></button>
					<a class="button" href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '追蹤紀錄', 'now-crm' ); ?></a>
					<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '返回列表', 'now-crm' ); ?></a>
				</div>
			</div>

			<?php Actions::render_notice(); ?>

			<?php if ( ! Settings::bool( 'email_sending_enabled' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( '實際寄送未開啟：序列會建立佇列，但佇列不會寄出任何信件。', 'now-crm' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="nowcrm-sequence-form" data-testid="sequence-form">
				<input type="hidden" name="action" value="nowcrm_save_sequence" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'nowcrm_save_sequence' ); ?>

				<details class="nowcrm-panel nowcrm-sequence-settings" id="nowcrm-sequence-settings">
					<summary><?php esc_html_e( '序列設定', 'now-crm' ); ?></summary>
					<div class="nowcrm-sequence-settings__body">
					<h2><?php esc_html_e( '基本設定', 'now-crm' ); ?></h2>
					<div class="nowcrm-inline-form">
						<label>
							<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
							<input type="text" name="name" class="regular-text" required="required"
								value="<?php echo esc_attr( (string) $sequence['name'] ); ?>" data-testid="sequence-name" />
						</label>

						<label>
							<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
							<select name="status" data-testid="sequence-status">
								<?php foreach ( SequenceListPage::status_labels() as $status => $label ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( (string) $sequence['status'], $status ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<label>
							<input type="checkbox" name="allow_reentry" value="1" <?php checked( ! empty( $sequence['allow_reentry'] ) ); ?> />
							<?php esc_html_e( '完成後允許再次加入', 'now-crm' ); ?>
						</label>
					</div>
					<p class="description"><?php esc_html_e( '預設不允許重複加入。勾選後，只有在上一輪完成時才會建立新的追蹤；已取消的追蹤可直接重新加入。暫停中的序列不會處理下一步。', 'now-crm' ); ?></p>

				<div class="nowcrm-panel nowcrm-sequence-settings__group">
					<h2><?php esc_html_e( '受眾', 'now-crm' ); ?></h2>
					<div class="nowcrm-audience-options">
						<?php
						$types = array(
							AudienceResolver::TYPE_ALL     => __( '所有已訂閱聯絡人', 'now-crm' ),
							AudienceResolver::TYPE_LIST    => __( '名單', 'now-crm' ),
							AudienceResolver::TYPE_TAG     => __( '標籤', 'now-crm' ),
							AudienceResolver::TYPE_SEGMENT => __( '儲存的分眾', 'now-crm' ),
							AudienceResolver::TYPE_FILTERS => __( '進階篩選條件', 'now-crm' ),
						);
						?>
						<?php foreach ( $types as $type => $label ) : ?>
							<label>
								<input type="radio" name="audience_type" value="<?php echo esc_attr( $type ); ?>"
									<?php checked( $audience_type, $type ); ?> data-audience-type="<?php echo esc_attr( $type ); ?>" />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="nowcrm-audience-panel" data-audience-panel="all">
						<p class="description"><?php esc_html_e( '寄送前會再次檢查訂閱狀態與抑制名單。', 'now-crm' ); ?></p>
					</div>

					<div class="nowcrm-audience-panel" data-audience-panel="list">
						<label>
							<span><?php esc_html_e( '名單', 'now-crm' ); ?></span>
							<select name="list_term_id">
								<option value="0"><?php esc_html_e( '選擇名單', 'now-crm' ); ?></option>
								<?php foreach ( $lists as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>"
										<?php selected( (int) ( $audience['term_id'] ?? 0 ), (int) $term['id'] ); ?>>
										<?php echo esc_html( (string) $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php if ( array() === $lists ) : ?>
							<p class="description"><?php esc_html_e( '目前沒有名單，請先到「名單」建立。', 'now-crm' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="nowcrm-audience-panel" data-audience-panel="tag">
						<label>
							<span><?php esc_html_e( '標籤', 'now-crm' ); ?></span>
							<select name="tag_term_id">
								<option value="0"><?php esc_html_e( '選擇標籤', 'now-crm' ); ?></option>
								<?php foreach ( $tags as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>"
										<?php selected( (int) ( $audience['term_id'] ?? 0 ), (int) $term['id'] ); ?>>
										<?php echo esc_html( (string) $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php if ( array() === $tags ) : ?>
							<p class="description"><?php esc_html_e( '目前沒有標籤，請先到「標籤」建立。', 'now-crm' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="nowcrm-audience-panel" data-audience-panel="segment">
						<label>
							<span><?php esc_html_e( '分眾', 'now-crm' ); ?></span>
							<select name="segment_id">
								<option value=""><?php esc_html_e( '選擇分眾', 'now-crm' ); ?></option>
								<?php foreach ( $system as $segment ) : ?>
									<option value="<?php echo esc_attr( (string) $segment['ref'] ); ?>"
										<?php selected( (string) ( $audience['segment_id'] ?? '' ), (string) $segment['ref'] ); ?>>
										<?php echo esc_html( (string) $segment['name'] ); ?>
									</option>
								<?php endforeach; ?>
								<?php foreach ( $segments as $segment ) : ?>
									<option value="<?php echo esc_attr( (string) $segment['id'] ); ?>"
										<?php selected( (string) ( $audience['segment_id'] ?? '' ), (string) $segment['id'] ); ?>>
										<?php echo esc_html( (string) $segment['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div class="nowcrm-audience-panel" data-audience-panel="filters">
						<?php ContactAdvancedFilters::render( $filters ); ?>
					</div>
					</div>
					</div>
				</details>

				<div class="nowcrm-sequence-editor__bar" data-sequence-editor-bar hidden>
					<button type="button" class="button" data-sequence-back><?php esc_html_e( '← 返回序列', 'now-crm' ); ?></button>
					<strong data-sequence-editor-title></strong>
					<div class="nowcrm-step-card__actions">
						<button type="button" class="button button-small" data-step-action="up"><?php esc_html_e( '上移', 'now-crm' ); ?></button>
						<button type="button" class="button button-small" data-step-action="down"><?php esc_html_e( '下移', 'now-crm' ); ?></button>
						<button type="button" class="button button-small button-link-delete" data-step-action="remove"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
					</div>
				</div>

				<div class="nowcrm-panel nowcrm-sequence-steps-panel">
					<h2><?php esc_html_e( '郵件步驟', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '點選步驟即可編輯；變更步驟會影響進行中的追蹤，建議先暫停序列。', 'now-crm' ); ?></p>

					<div id="nowcrm-sequence-steps" data-testid="sequence-steps">
						<?php foreach ( $steps as $index => $step ) : ?>
							<?php
							self::render_step(
								(int) $index,
								$step,
								false,
								$id,
								(int) ( $steps[ $index - 1 ]['delay_seconds'] ?? 0 )
							);
							?>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="nowcrm-form-workspace__submit">
					<button type="submit" class="button button-primary" data-testid="sequence-save"><?php esc_html_e( '儲存序列', 'now-crm' ); ?></button>
					<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '返回列表', 'now-crm' ); ?></a>
				</div>
			</form>

			<?php if ( SequenceRepository::STATUS_ACTIVE === $sequence['status'] && array() !== $steps ) : ?>
				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '加入受眾', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '每次最多 100 位。已在序列中、未訂閱或已抑制的聯絡人會跳過，並在列表頁顯示結果。', 'now-crm' ); ?></p>
					<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-inline-form">
						<input type="hidden" name="action" value="nowcrm_enroll_sequence" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<?php wp_nonce_field( 'nowcrm_enroll_sequence' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( '加入下一批受眾', 'now-crm' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<template id="nowcrm-sequence-step-template">
				<?php self::render_step( 0, array(), true, $id ); ?>
			</template>

			<script>
			( function () {
				var root = document.querySelector( '.nowcrm-sequence-editor' );
				var steps = document.getElementById( 'nowcrm-sequence-steps' );
				var template = document.getElementById( 'nowcrm-sequence-step-template' );
				var add = document.getElementById( 'nowcrm-add-step' );
				var bar = document.querySelector( '[data-sequence-editor-bar]' );
				var barTitle = document.querySelector( '[data-sequence-editor-title]' );
				var back = document.querySelector( '[data-sequence-back]' );
				var form = document.getElementById( 'nowcrm-sequence-form' );
				var config = <?php echo wp_json_encode( array(
					'blockEditorUrl' => ContentEditorPage::url( 'sequence', $id, true ),
					'loading'        => __( '正在載入區塊編輯器…', 'now-crm' ),
					'failed'         => __( '無法載入區塊編輯器，請切換為 HTML 或重新載入頁面。', 'now-crm' ),
					'empty'          => __( '請先輸入內容再儲存。', 'now-crm' ),
				) ); ?>;

				if ( ! root || ! steps || ! template || ! add ) {
					return;
				}

				function reindex() {
					steps.querySelectorAll( '[data-nowcrm-step]' ).forEach( function ( card, index ) {
						card.querySelectorAll( '[name]' ).forEach( function ( field ) {
							field.name = field.name.replace( /^steps\[\d+\]/, 'steps[' + index + ']' );
						} );

						var number = card.querySelector( '[data-step-number]' );

						if ( number ) {
							number.textContent = String( index + 1 );
						}

						var badge = card.querySelector( '[data-step-badge]' );

						if ( badge ) {
							badge.textContent = String( index + 1 );
						}
					} );
				}

				function textareaOf( card ) {
					return card.querySelector( '[data-workflow-field="content"]' );
				}

				function isBlocks( card ) {
					var mode = card.querySelector( '[data-workflow-field="mode"]' );
					return !! mode && 'blocks' === mode.value;
				}

				function frameOf( card ) {
					return card.querySelector( '[data-workflow-frame]' );
				}

				function frameBridge( card ) {
					var frame = frameOf( card );

					if ( ! frame ) {
						return null;
					}

					try {
						return frame.contentWindow && frame.contentWindow.nowcrmEditorBridge ? frame.contentWindow.nowcrmEditorBridge : null;
					} catch ( error ) {
						return null;
					}
				}

				function frameStatus( card, message, isError ) {
					var node = card.querySelector( '[data-sequence-frame-status]' );

					if ( node ) {
						node.textContent = message || '';
						node.classList.toggle( 'is-error', !! isError );
					}
				}

				function syncFrame( card ) {
					var bridge = card.nowcrmBridge;
					var area = textareaOf( card );

					if ( ! bridge || ! area || ! bridge.isReady() ) {
						return false;
					}

					var value = bridge.blocks();

					if ( typeof value !== 'string' ) {
						return false;
					}

					// Untouched content keeps its persisted bytes; the frame only
					// writes back when the user actually changed the blocks.
					if ( bridge.isDirty ? bridge.isDirty() : value !== card.nowcrmSeed ) {
						area.value = value;
					}

					return true;
				}

				function demountFrame( card ) {
					var frame = frameOf( card );

					if ( frame ) {
						frame.remove();
					}

					card.nowcrmBridge = null;
				}

				function seedFrame( card ) {
					var bridge = frameBridge( card );
					var area = textareaOf( card );

					if ( ! bridge || ! bridge.isReady() || ! area ) {
						return false;
					}

					if ( typeof bridge.replaceBlocks !== 'function' ) {
						frameStatus( card, config.failed, true );
						return false;
					}

					var result = bridge.replaceBlocks( area.value );

					if ( ! result || ! result.ready ) {
						frameStatus( card, config.failed, true );
						return false;
					}

					card.nowcrmBridge = bridge;
					card.nowcrmSeed = typeof result.blocks === 'string' ? result.blocks : area.value;
					frameStatus( card, '' );

					return true;
				}

				function mountFrame( card ) {
					var host = card.querySelector( '[data-sequence-frame-host]' );

					if ( ! host || host.querySelector( 'iframe' ) ) {
						return;
					}

					frameStatus( card, config.loading, false );

					var frame = document.createElement( 'iframe' );
					frame.className = 'nowcrm-sequence-frame';
					frame.setAttribute( 'data-workflow-frame', '' );
					frame.title = card.querySelector( '[data-workflow-field="subject"]' )
						? card.querySelector( '[data-workflow-field="subject"]' ).value || '內容編輯器'
						: '內容編輯器';
					frame.src = config.blockEditorUrl;
					host.appendChild( frame );

					var deadline = Date.now() + 25000;

					( function waitForBridge() {
						if ( ! frame.isConnected ) {
							return;
						}

						var bridge = frameBridge( card );

						if ( bridge && bridge.isReady() ) {
							seedFrame( card );
							return;
						}

						if ( Date.now() > deadline ) {
							frameStatus( card, config.failed, true );
							return;
						}

						window.setTimeout( waitForBridge, 250 );
					}() );
				}

				function applyMode( card, mount ) {
					var blocks = isBlocks( card );
					var htmlPane = card.querySelector( '[data-content-html]' );
					var blockPane = card.querySelector( '[data-content-blocks]' );
					var area = textareaOf( card );

					if ( htmlPane ) {
						htmlPane.hidden = blocks;
					}

					if ( blockPane ) {
						blockPane.hidden = ! blocks;
					}

					// Required only in the plain textarea mode: a hidden control
					// must never block native validation, blocks are validated
					// explicitly on submit instead.
					if ( area ) {
						area.required = ! blocks;
					}

					card.setAttribute( 'data-mode', blocks ? 'blocks' : 'html' );

					if ( blocks && mount ) {
						mountFrame( card );
					}
				}

				function changeMode( card ) {
					var area = textareaOf( card );
					var previous = card.getAttribute( 'data-mode' ) || 'html';

					if ( area && 'blocks' === previous ) {
						syncFrame( card );
						card.nowcrmBlocks = area.value;
					} else if ( area ) {
						card.nowcrmHtml = area.value;
					}

					var next = isBlocks( card ) ? 'blocks' : 'html';

					if ( area ) {
						if ( 'blocks' === next ) {
							area.value = typeof card.nowcrmBlocks === 'string' ? card.nowcrmBlocks : ( typeof card.nowcrmHtml === 'string' ? card.nowcrmHtml : area.value );
						} else {
							area.value = typeof card.nowcrmHtml === 'string' ? card.nowcrmHtml : ( typeof card.nowcrmBlocks === 'string' ? card.nowcrmBlocks : '' );
						}
					}

					demountFrame( card );
					applyMode( card, false );

					if ( 'blocks' === next && card.classList.contains( 'is-editing' ) ) {
						mountFrame( card );
					}
				}

				function editingCard() {
					return steps.querySelector( '.is-editing' );
				}

				function updateBar( card ) {
					if ( ! barTitle || ! card ) {
						return;
					}

					var number = card.querySelector( '[data-step-number]' );
					var subject = card.querySelector( '[data-workflow-field="subject"]' );
					var label = '步驟 ' + ( number ? number.textContent : '' );

					if ( subject && subject.value.trim() ) {
						label += ' · ' + subject.value.trim();
					}

					barTitle.textContent = label;
				}

				function openEditor( card, focusSubject ) {
					if ( ! card ) {
						return;
					}

					var current = editingCard();

					if ( current && current !== card ) {
						closeEditor( false );
					}

					steps.querySelectorAll( '.is-editing' ).forEach( function ( other ) {
						if ( other !== card ) {
							other.classList.remove( 'is-editing' );
							demountFrame( other );
						}
					} );

					card.classList.add( 'is-editing' );
					root.classList.add( 'nowcrm-sequence-editor--editing' );

					if ( bar ) {
						bar.hidden = false;
					}

					updateBar( card );
					applyMode( card, false );

					if ( isBlocks( card ) ) {
						mountFrame( card );
					}

					if ( focusSubject ) {
						var subject = card.querySelector( '[data-workflow-field="subject"]' );

						if ( subject ) {
							subject.focus();
						}
					}
				}

				function closeEditor( focusRow ) {
					var card = editingCard();

					if ( card ) {
						syncFrame( card );
						demountFrame( card );
						card.classList.remove( 'is-editing' );
					}

					root.classList.remove( 'nowcrm-sequence-editor--editing' );

					if ( bar ) {
						bar.hidden = true;
					}

					if ( focusRow && card ) {
						var button = card.querySelector( '[data-step-action="edit"]' );

						if ( button ) {
							button.focus();
						}
					}
				}

				function swapDelay( first, second ) {
					[ '[data-delay-value]', '[data-delay-unit]', '[data-delay-remainder]', '[data-delay-seconds]' ].forEach( function ( selector ) {
						var a = first.querySelector( selector );
						var b = second.querySelector( selector );

						if ( a && b ) {
							var value = a.value;
							a.value = b.value;
							b.value = value;
						}
					} );
				}

				function delaySeconds( card ) {
					var value = card.querySelector( '[data-delay-value]' );
					var unit = card.querySelector( '[data-delay-unit]' );
					var remainder = card.querySelector( '[data-delay-remainder]' );
					var hidden = card.querySelector( '[data-delay-seconds]' );

					if ( ! value || ! unit || ! hidden ) {
						return hidden ? Math.max( 0, parseInt( hidden.value, 10 ) || 0 ) : 0;
					}

					var multipliers = { minutes: 60, hours: 3600, days: 86400 };
					var amount = Math.max( 0, parseInt( value.value, 10 ) || 0 );
					var seconds = Math.min( 59, Math.max( 0, parseInt( remainder ? remainder.value : '0', 10 ) || 0 ) );
					var total = amount * ( multipliers[ unit.value ] || 60 ) + seconds;

					return Math.max( 0, Math.min( <?php echo (int) SequenceRepository::MAX_DELAY_SECONDS; ?>, total ) );
				}

				function delayText( seconds ) {
					if ( seconds < 1 ) {
						return '立即';
					}

					var days = Math.floor( seconds / 86400 );
					var hours = Math.floor( ( seconds % 86400 ) / 3600 );
					var minutes = Math.floor( ( seconds % 3600 ) / 60 );
					var rest = seconds % 60;
					var parts = [];

					if ( days ) {
						parts.push( days + ' 天' );
					}

					if ( hours ) {
						parts.push( hours + ' 小時' );
					}

					if ( minutes ) {
						parts.push( minutes + ' 分鐘' );
					}

					if ( rest ) {
						parts.push( rest + ' 秒' );
					}

					return parts.join( ' ' );
				}

				function refreshSummary( card ) {
					var node = card.querySelector( '[data-step-summary]' );
					var subject = card.querySelector( '[data-workflow-field="subject"]' );
					var value = subject ? subject.value.trim() : '';

					if ( node ) {
						node.textContent = delayText( delaySeconds( card ) ) + ' · ' + ( value || '未設定主旨' );
					}
				}

				function refreshTimeline() {
					var previous = 0;

					steps.querySelectorAll( '[data-nowcrm-step]' ).forEach( function ( card, index ) {
						var seconds = delaySeconds( card );
						var hidden = card.querySelector( '[data-delay-seconds]' );
						var summary = card.querySelector( '[data-delay-summary]' );

						if ( hidden ) {
							hidden.value = String( seconds );
						}

						if ( summary ) {
							var text = '加入後 ' + delayText( seconds ) + '（' + seconds + ' 秒）';

							if ( index > 0 ) {
								text += ' · 距上一步 ' + delayText( Math.max( 0, seconds - previous ) );
							}

							summary.textContent = text;
						}

						refreshSummary( card );
						previous = seconds;
					} );
				}

				function initWorkflowEmail( card ) {
					if ( ! card ) {
						return;
					}

					card.dispatchEvent( new CustomEvent( 'nowcrm:workflow-email:init', {
						bubbles: true,
						detail: { scope: card }
					} ) );

					if ( window.NowCRMWorkflowEmail && typeof window.NowCRMWorkflowEmail.init === 'function' ) {
						window.NowCRMWorkflowEmail.init( card );
					}
				}

				add.addEventListener( 'click', function () {
					var fragment = template.content.cloneNode( true );
					steps.appendChild( fragment );

					var card = steps.lastElementChild;

					reindex();
					applyMode( card, false );
					refreshTimeline();
					initWorkflowEmail( card );
					openEditor( card, true );
				} );

				steps.addEventListener( 'input', function ( event ) {
					var card = event.target.closest( '[data-nowcrm-step]' );

					if ( ! card ) {
						return;
					}

					if ( event.target.closest( '[data-nowcrm-delay]' ) ) {
						refreshTimeline();
					} else if ( event.target.closest( '[data-workflow-field="subject"], [data-step-name]' ) ) {
						refreshSummary( card );

						if ( card.classList.contains( 'is-editing' ) ) {
							updateBar( card );
						}
					}
				} );

				steps.addEventListener( 'change', function ( event ) {
					if ( event.target.closest( '[data-nowcrm-delay]' ) ) {
						refreshTimeline();
						return;
					}

					var card = event.target.closest( '[data-nowcrm-step]' );

					if ( card && event.target.matches( '[data-workflow-field="mode"]' ) ) {
						changeMode( card );
					}
				} );

				steps.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( 'button[data-step-action]' );

					if ( ! button ) {
						return;
					}

					var action = button.getAttribute( 'data-step-action' );
					var card = button.closest( '[data-nowcrm-step]' ) || editingCard();

					if ( ! card ) {
						return;
					}

					if ( 'edit' === action ) {
						openEditor( card, true );
						return;
					}

					if ( 'remove' === action ) {
						if ( card === editingCard() ) {
							closeEditor( false );
						}
						card.remove();
					} else if ( 'up' === action && card.previousElementSibling ) {
						var previous = card.previousElementSibling;
						swapDelay( previous, card );
						steps.insertBefore( card, previous );
					} else if ( 'down' === action && card.nextElementSibling ) {
						var next = card.nextElementSibling;
						swapDelay( next, card );
						steps.insertBefore( next, card );
					}

					reindex();

					if ( card.classList.contains( 'is-editing' ) ) {
						updateBar( card );
					}

					refreshTimeline();
				} );

				if ( back ) {
					back.addEventListener( 'click', function () {
						closeEditor( true );
					} );
				}

				if ( form ) {
					form.addEventListener( 'invalid', function ( event ) {
						var field = event.target;
						var card = field.closest ? field.closest( '[data-nowcrm-step]' ) : null;

						if ( card ) {
							// A collapsed step may hold the invalid required field:
							// reveal its editor before the browser focuses it.
							openEditor( card, false );
							return;
						}

						var settings = document.getElementById( 'nowcrm-sequence-settings' );

						if ( settings && settings.contains( field ) ) {
							settings.open = true;
							closeEditor( false );
						}
					}, true );

					form.addEventListener( 'submit', function ( event ) {
						var active = editingCard();
						var invalid = null;

						if ( active ) {
							syncFrame( active );
						}

						steps.querySelectorAll( '[data-nowcrm-step]' ).forEach( function ( card ) {
							var error = card.querySelector( '[data-content-error]' );

							if ( error ) {
								error.hidden = true;
							}

							if ( ! isBlocks( card ) ) {
								return;
							}

							var area = textareaOf( card );

							if ( area && ! String( area.value || '' ).trim() ) {
								invalid = invalid || card;
							}
						} );

						if ( ! invalid ) {
							return;
						}

						event.preventDefault();
						openEditor( invalid, false );

						var error = invalid.querySelector( '[data-content-error]' );

						if ( error ) {
							error.textContent = config.empty;
							error.hidden = false;
						}
					} );
				}

				function toggleAudience() {
					var selected = document.querySelector( 'input[name="audience_type"]:checked' );
					var type = selected ? selected.value : 'all';

					document.querySelectorAll( '[data-audience-panel]' ).forEach( function ( panel ) {
						panel.hidden = panel.getAttribute( 'data-audience-panel' ) !== type;
					} );
				}

				document.querySelectorAll( 'input[name="audience_type"]' ).forEach( function ( input ) {
					input.addEventListener( 'change', toggleAudience );
				} );

				root.classList.add( 'nowcrm-sequence-editor--js' );
				steps.querySelectorAll( '[data-nowcrm-step]' ).forEach( function ( card ) {
					applyMode( card, false );
				} );

				toggleAudience();
				reindex();
				refreshTimeline();
			}() );
			</script>
		</div>
		<?php
	}

	/**
	 * Units offered by the step delay control.
	 *
	 * @return array<string, string>
	 */
	public static function delay_units(): array {
		return array(
			'minutes' => __( '分鐘', 'now-crm' ),
			'hours'   => __( '小時', 'now-crm' ),
			'days'    => __( '天', 'now-crm' ),
		);
	}

	/**
	 * Split exact seconds into a lossless editor value, unit, and second remainder.
	 *
	 * Legacy values that do not divide evenly use minutes plus the remaining
	 * seconds, so the stored second count never changes.
	 *
	 * @return array{value: int, unit: string, seconds: int}
	 */
	public static function delay_parts( int $seconds ): array {
		$seconds = max( 0, $seconds );

		if ( 0 === $seconds ) {
			return array( 'value' => 0, 'unit' => 'minutes', 'seconds' => 0 );
		}

		if ( 0 === $seconds % DAY_IN_SECONDS ) {
			return array( 'value' => intdiv( $seconds, DAY_IN_SECONDS ), 'unit' => 'days', 'seconds' => 0 );
		}

		if ( 0 === $seconds % HOUR_IN_SECONDS ) {
			return array( 'value' => intdiv( $seconds, HOUR_IN_SECONDS ), 'unit' => 'hours', 'seconds' => 0 );
		}

		return array(
			'value'   => intdiv( $seconds, MINUTE_IN_SECONDS ),
			'unit'    => 'minutes',
			'seconds' => $seconds % MINUTE_IN_SECONDS,
		);
	}

	/**
	 * Human-readable duration.
	 */
	public static function format_delay( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( 0 === $seconds ) {
			return __( '立即', 'now-crm' );
		}

		$days    = intdiv( $seconds, DAY_IN_SECONDS );
		$hours   = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$rest    = $seconds % MINUTE_IN_SECONDS;
		$parts   = array();

		if ( $days > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of days. */
				__( '%d 天', 'now-crm' ),
				$days
			);
		}

		if ( $hours > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of hours. */
				__( '%d 小時', 'now-crm' ),
				$hours
			);
		}

		if ( $minutes > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of minutes. */
				__( '%d 分鐘', 'now-crm' ),
				$minutes
			);
		}

		if ( $rest > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of seconds. */
				__( '%d 秒', 'now-crm' ),
				$rest
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * One step card.
	 *
	 * @param int                  $index         Step position.
	 * @param array<string, mixed> $step          Step values.
	 * @param bool                 $is_template   Render the blank template.
	 * @param int                  $sequence_id   Persisted owning sequence ID.
	 * @param int                  $previous_delay Previous step delay in seconds.
	 */
	private static function render_step( int $index, array $step = array(), bool $is_template = false, int $sequence_id = 0, int $previous_delay = 0 ): void {
		$name      = (string) ( $step['name'] ?? ( $is_template ? '' : '步驟 ' . ( $index + 1 ) ) );
		$subject   = (string) ( $step['subject'] ?? '' );
		$preheader = (string) ( $step['preheader'] ?? '' );
		$mode      = (string) ( $step['mode'] ?? ( $is_template ? 'blocks' : 'html' ) );
		$content   = (string) ( $step['content'] ?? '' );
		$delay     = (int) ( $step['delay_seconds'] ?? 0 );
		$uuid      = (string) ( $step['uuid'] ?? '' );
		$parts     = self::delay_parts( $delay );
		$interval  = max( 0, $delay - $previous_delay );
		$prefix    = 'steps[' . $index . ']';
		$summary   = self::format_delay( $delay ) . ' · ' . ( '' !== $subject ? $subject : __( '未設定主旨', 'now-crm' ) );
		?>
		<div class="nowcrm-sequence-step" data-nowcrm-step
			data-workflow-email data-workflow-source-id="<?php echo esc_attr( (string) $sequence_id ); ?>">
			<div class="nowcrm-step-card__head">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[uuid]" value="<?php echo esc_attr( $uuid ); ?>" data-step-uuid />
				<span class="nowcrm-step-card__badge" data-step-badge aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
				<div class="nowcrm-step-card__identity">
					<span class="nowcrm-step-card__label"><?php esc_html_e( '步驟', 'now-crm' ); ?> <span data-step-number><?php echo esc_html( (string) ( $index + 1 ) ); ?></span></span>
					<p class="nowcrm-step-card__summary" data-step-summary><?php echo esc_html( $summary ); ?></p>
				</div>
				<div class="nowcrm-step-card__actions">
					<button type="button" class="button button-small" data-step-action="edit"><?php esc_html_e( '編輯', 'now-crm' ); ?></button>
					<button type="button" class="button button-small" data-step-action="up"><?php esc_html_e( '上移', 'now-crm' ); ?></button>
					<button type="button" class="button button-small" data-step-action="down"><?php esc_html_e( '下移', 'now-crm' ); ?></button>
					<button type="button" class="button button-small button-link-delete" data-step-action="remove"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
				</div>
			</div>
			<div class="nowcrm-step-card__body" data-step-body>
				<div class="nowcrm-step-grid">
					<label>
						<span><?php esc_html_e( '步驟名稱', 'now-crm' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" data-step-name />
					</label>
					<div class="nowcrm-step-delay" data-nowcrm-delay>
						<span><?php esc_html_e( '延遲（從加入起算）', 'now-crm' ); ?></span>
						<div class="nowcrm-step-delay__fields">
							<input type="number" min="0" step="1" data-delay-value
								name="<?php echo esc_attr( $prefix ); ?>[delay_value]"
								value="<?php echo esc_attr( (string) $parts['value'] ); ?>"
								aria-label="<?php esc_attr_e( '延遲數量', 'now-crm' ); ?>" />
							<select name="<?php echo esc_attr( $prefix ); ?>[delay_unit]" data-delay-unit
								aria-label="<?php esc_attr_e( '延遲單位', 'now-crm' ); ?>">
								<?php foreach ( self::delay_units() as $unit => $label ) : ?>
									<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $parts['unit'], $unit ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<label class="nowcrm-step-delay__seconds">
								<span><?php esc_html_e( '秒', 'now-crm' ); ?></span>
								<input type="number" min="0" max="59" step="1" data-delay-remainder
									name="<?php echo esc_attr( $prefix ); ?>[delay_seconds_part]"
									value="<?php echo esc_attr( (string) $parts['seconds'] ); ?>"
									aria-label="<?php esc_attr_e( '延遲餘秒', 'now-crm' ); ?>" />
							</label>
						</div>
						<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[delay_seconds]"
							value="<?php echo esc_attr( (string) $delay ); ?>" data-delay-seconds />
						<p class="description" data-delay-summary>
							<?php
							printf(
								/* translators: 1: readable duration, 2: seconds. */
								esc_html__( '加入後 %1$s（%2$d 秒）', 'now-crm' ),
								esc_html( self::format_delay( $delay ) ),
								(int) $delay
							);
							?>
							<?php if ( $index > 0 ) : ?>
								<span>· <?php echo esc_html( sprintf( /* translators: %s: readable duration. */ __( '距上一步 %s', 'now-crm' ), self::format_delay( $interval ) ) ); ?></span>
							<?php endif; ?>
						</p>
					</div>
					<label>
						<span><?php esc_html_e( '內容格式', 'now-crm' ); ?></span>
						<select name="<?php echo esc_attr( $prefix ); ?>[mode]" data-workflow-field="mode">
							<option value="html" <?php selected( $mode, 'html' ); ?>><?php esc_html_e( 'HTML', 'now-crm' ); ?></option>
							<option value="blocks" <?php selected( $mode, 'blocks' ); ?>><?php esc_html_e( 'Gutenberg Blocks', 'now-crm' ); ?></option>
						</select>
					</label>
				</div>

				<div class="nowcrm-step-meta">
					<label>
						<span><?php esc_html_e( '主旨', 'now-crm' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[subject]" value="<?php echo esc_attr( $subject ); ?>"
							required="required" data-workflow-field="subject" />
					</label>

					<label>
						<span><?php esc_html_e( 'Preheader', 'now-crm' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[preheader]" value="<?php echo esc_attr( $preheader ); ?>"
							data-workflow-field="preheader" />
					</label>
				</div>

				<div class="nowcrm-step-content">
					<div class="nowcrm-step-content__pane" data-content-html>
						<label>
							<span><?php esc_html_e( '內容（Raw HTML）', 'now-crm' ); ?></span>
							<textarea name="<?php echo esc_attr( $prefix ); ?>[content]" rows="12" class="large-text code"
								required="required" data-workflow-field="content"><?php echo esc_textarea( $content ); ?></textarea>
						</label>
					</div>

					<div class="nowcrm-step-content__pane" data-content-blocks hidden>
						<span class="nowcrm-step-content__label"><?php esc_html_e( '內容（Gutenberg Blocks）', 'now-crm' ); ?></span>
						<div class="nowcrm-step-content__frame" data-sequence-frame-host></div>
						<p class="description nowcrm-step-content__status" data-sequence-frame-status role="status" aria-live="polite"></p>
					</div>

					<p class="nowcrm-step-content__error" data-content-error role="alert" hidden></p>
				</div>

				<div class="nowcrm-workflow-email__tools">
					<?php WorkflowEmail::render_tools( 'sequence' ); ?>
				</div>
			</div>
		</div>
		<?php
	}
}
