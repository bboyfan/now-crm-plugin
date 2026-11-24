<?php
/**
 * Custom-field settings panel and contact form adapter.
 *
 * This class owns the custom-field admin seam. `register()` adds the admin-post
 * handler; add the class to Plugin::modules() (or call register() from boot) to
 * wire it. The contact detail page calls render_contact_fields() inside its form
 * and save_contact_fields() from its existing action handler.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CustomFields {

	public const ACTION = 'nowcrm_save_custom_fields';

	public const NONCE = 'nowcrm_save_custom_fields';

	/**
	 * Blank rows appended to the settings panel so an operator can add entries
	 * without JavaScript.
	 */
	private const BLANK_ROWS = 3;

	/**
	 * Register the admin-post handler. Add this class to Plugin::modules().
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save_settings' ) );
	}

	/**
	 * Settings form handler: permission, nonce, validate, redirect locally.
	 */
	public static function handle_save_settings(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die(
				esc_html__( '您沒有權限修改自訂欄位。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		$result = self::save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked above.

		wp_safe_redirect(
			Menu::page_url(
				Menu::SETTINGS_SLUG,
				array(
					'tab'           => 'fields',
					'nowcrm_notice' => is_wp_error( $result ) ? 'error' : 'settings_saved',
				)
			)
		);

		exit;
	}

	/**
	 * Validate and persist the submitted settings form.
	 *
	 * Both lists are validated by CustomFieldDefinitions before any option
	 * write, and unreferenced groups are dropped, so a failed submit leaves the
	 * stored field list and group list unchanged.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return true|WP_Error
	 */
	public static function save_settings( array $post ) {
		$fields = self::parse_field_rows( isset( $post['fields'] ) && is_array( $post['fields'] ) ? $post['fields'] : array() );
		$groups = self::parse_group_rows( isset( $post['groups'] ) && is_array( $post['groups'] ) ? $post['groups'] : array() );

		$saved = CustomFieldDefinitions::save_with_groups( $fields, $groups );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return true;
	}

	/**
	 * Convert submitted field rows into definition arrays, in row order.
	 *
	 * Blank rows are skipped. A group select of "__new" takes the title from
	 * `group_new`. Rows are sorted by their numeric order value.
	 *
	 * @param array<mixed> $rows Submitted rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse_field_rows( array $rows ): array {
		$items = array();

		foreach ( array_values( $rows ) as $position => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}

			$label = self::row_value( $row, 'label' );
			$slug  = self::row_value( $row, 'slug' );

			if ( '' === $label && '' === $slug ) {
				continue;
			}

			$group = self::row_value( $row, 'group' );

			if ( '__new' === $group || '' === $group ) {
				$group = self::row_value( $row, 'group_new' );
			}

			$options = preg_split( '/\r\n|\r|\n/', self::row_value( $row, 'options' ) );
			$options = is_array( $options ) ? $options : array();

			$items[] = array(
				'order'    => self::row_order( $row ),
				'position' => $position,
				'field'    => array(
					'label'   => $label,
					'slug'    => $slug,
					'type'    => self::row_value( $row, 'type' ),
					'options' => $options,
					'group'   => $group,
				),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['position'] <=> $b['position'] );
			}
		);

		return array_map(
			static fn( array $item ): array => $item['field'],
			$items
		);
	}

	/**
	 * Convert submitted group rows into slug/title arrays, in row order.
	 *
	 * @param array<mixed> $rows Submitted rows.
	 * @return array<int, array{slug: string, title: string}>
	 */
	public static function parse_group_rows( array $rows ): array {
		$items = array();

		foreach ( array_values( $rows ) as $position => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}

			$title = self::row_value( $row, 'title' );
			$slug  = self::row_value( $row, 'slug' );

			if ( '' === $title && '' === $slug ) {
				continue;
			}

			$items[] = array(
				'order'    => self::row_order( $row ),
				'position' => $position,
				'group'    => array(
					'slug'  => $slug,
					'title' => $title,
				),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['position'] <=> $b['position'] );
			}
		);

		return array_map(
			static fn( array $item ): array => $item['group'],
			$items
		);
	}

	/**
	 * Trimmed scalar value from a submitted row. Arrays and objects are ignored.
	 *
	 * @param array<string, mixed> $row Submitted row.
	 */
	private static function row_value( array $row, string $key ): string {
		if ( ! isset( $row[ $key ] ) || ! is_scalar( $row[ $key ] ) ) {
			return '';
		}

		return trim( (string) $row[ $key ] );
	}

	/**
	 * Non-negative order value from a submitted row.
	 *
	 * @param array<string, mixed> $row Submitted row.
	 */
	private static function row_order( array $row ): int {
		if ( ! isset( $row['order'] ) || ! is_scalar( $row['order'] ) ) {
			return 0;
		}

		return absint( $row['order'] );
	}

	/**
	 * Settings panel: field definitions and named groups.
	 *
	 * Plain HTML, no script: order is a number field, delete is a checkbox, and
	 * blank rows append entries.
	 */
	public static function render_settings_panel(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			return;
		}

		$fields  = CustomFieldDefinitions::all();
		$groups  = CustomFieldDefinitions::groups();
		$kinds   = CustomFieldDefinitions::kinds();
		$form    = admin_url( 'admin-post.php' );
		$choices = array();

		foreach ( $groups as $group ) {
			$choices[ $group['slug'] ] = $group['title'];
		}

		$field_count = count( $fields ) + self::BLANK_ROWS;
		$group_count = count( $groups ) + self::BLANK_ROWS;
		?>
		<div class="nowcrm-panel" id="nowcrm-custom-fields">
			<h2><?php esc_html_e( '自訂欄位', 'now-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '欄位代稱建立後不可變更；修改名稱或群組不會影響已儲存的聯絡人內容。選項欄位每行一個選項。', 'now-crm' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $form ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<h3><?php esc_html_e( '欄位定義', 'now-crm' ); ?></h3>
				<table class="widefat striped nowcrm-custom-fields-table">
					<thead>
						<tr>
							<th scope="col" class="nowcrm-col-order"><?php esc_html_e( '順序', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '類型', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '群組', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '新群組名稱', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '選項（每行一個）', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '刪除', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $i = 0; $i < $field_count; $i++ ) : ?>
							<?php
							$field = $fields[ $i ] ?? array(
								'label'   => '',
								'slug'    => '',
								'type'    => 'text',
								'options' => array(),
								'group'   => CustomFieldDefinitions::DEFAULT_GROUP,
							);
							$id    = 'nowcrm-cf-' . $i;
							?>
							<tr>
								<td>
									<input type="hidden" name="fields[<?php echo esc_attr( (string) $i ); ?>][slug]" value="<?php echo esc_attr( $field['slug'] ); ?>" />
									<input type="number" class="small-text" min="0" name="fields[<?php echo esc_attr( (string) $i ); ?>][order]" value="<?php echo esc_attr( (string) $i ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-label' ); ?>"><?php esc_html_e( '名稱', 'now-crm' ); ?></label>
									<input type="text" class="regular-text" id="<?php echo esc_attr( $id . '-label' ); ?>" name="fields[<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-type' ); ?>"><?php esc_html_e( '類型', 'now-crm' ); ?></label>
									<select id="<?php echo esc_attr( $id . '-type' ); ?>" name="fields[<?php echo esc_attr( (string) $i ); ?>][type]">
										<?php foreach ( $kinds as $kind => $meta ) : ?>
											<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $field['type'], $kind ); ?>>
												<?php echo esc_html( $kind . ' — ' . $meta['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-group' ); ?>"><?php esc_html_e( '群組', 'now-crm' ); ?></label>
									<select id="<?php echo esc_attr( $id . '-group' ); ?>" name="fields[<?php echo esc_attr( (string) $i ); ?>][group]">
										<option value=""><?php esc_html_e( '預設群組', 'now-crm' ); ?></option>
										<?php foreach ( $choices as $slug => $title ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $field['group'], $slug ); ?>>
												<?php echo esc_html( $title ); ?>
											</option>
										<?php endforeach; ?>
										<option value="__new"><?php esc_html_e( '建立新群組', 'now-crm' ); ?></option>
									</select>
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-group-new' ); ?>"><?php esc_html_e( '新群組名稱', 'now-crm' ); ?></label>
									<input type="text" id="<?php echo esc_attr( $id . '-group-new' ); ?>" name="fields[<?php echo esc_attr( (string) $i ); ?>][group_new]" value="" />
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-options' ); ?>"><?php esc_html_e( '選項', 'now-crm' ); ?></label>
									<textarea id="<?php echo esc_attr( $id . '-options' ); ?>" name="fields[<?php echo esc_attr( (string) $i ); ?>][options]" rows="2" cols="20"><?php echo esc_textarea( implode( "\n", $field['options'] ) ); ?></textarea>
								</td>
								<td>
									<input type="checkbox" name="fields[<?php echo esc_attr( (string) $i ); ?>][delete]" value="1" />
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( '欄位群組', 'now-crm' ); ?></h3>
				<p class="description"><?php esc_html_e( '群組名稱可修改，代稱固定。沒有欄位使用的群組儲存後會移除。', 'now-crm' ); ?></p>
				<table class="widefat striped nowcrm-custom-groups-table">
					<thead>
						<tr>
							<th scope="col" class="nowcrm-col-order"><?php esc_html_e( '順序', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '群組名稱', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '刪除', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $i = 0; $i < $group_count; $i++ ) : ?>
							<?php
							$group = $groups[ $i ] ?? array(
								'slug'  => '',
								'title' => '',
							);
							$id    = 'nowcrm-cfg-' . $i;
							?>
							<tr>
								<td>
									<input type="hidden" name="groups[<?php echo esc_attr( (string) $i ); ?>][slug]" value="<?php echo esc_attr( $group['slug'] ); ?>" />
									<input type="number" class="small-text" min="0" name="groups[<?php echo esc_attr( (string) $i ); ?>][order]" value="<?php echo esc_attr( (string) $i ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-title' ); ?>"><?php esc_html_e( '群組名稱', 'now-crm' ); ?></label>
									<input type="text" class="regular-text" id="<?php echo esc_attr( $id . '-title' ); ?>" name="groups[<?php echo esc_attr( (string) $i ); ?>][title]" value="<?php echo esc_attr( $group['title'] ); ?>" />
								</td>
								<td>
									<input type="checkbox" name="groups[<?php echo esc_attr( (string) $i ); ?>][delete]" value="1" />
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( '儲存自訂欄位', 'now-crm' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the custom-field inputs for one contact.
	 *
	 * Output is plain form controls named nowcrm_cf[field_key] with one hidden
	 * marker per field, so an unchecked multi-value field clears on submit.
	 */
	public static function render_contact_fields( int $contact_id ): void {
		if ( ! Capabilities::can_manage_contacts() || $contact_id <= 0 ) {
			return;
		}

		self::render_fields( ( new CustomFieldValues() )->for_contact( $contact_id ) );
	}

	/**
	 * Render custom-field inputs for a contact that does not exist yet.
	 *
	 * @param array<string, mixed> $values Draft values keyed by field slug.
	 */
	public static function render_new_contact_fields( array $values = array() ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			return;
		}

		self::render_fields( $values );
	}

	/**
	 * Shared renderer for stored or draft values.
	 *
	 * @param array<string, mixed> $values Values keyed by field slug.
	 */
	private static function render_fields( array $values ): void {
		$definitions = CustomFieldDefinitions::all();

		if ( array() === $definitions ) {
			return;
		}

		$titles = array();

		foreach ( CustomFieldDefinitions::groups() as $group ) {
			$titles[ $group['slug'] ] = $group['title'];
		}

		$grouped = array();

		foreach ( $definitions as $field ) {
			$grouped[ $field['group'] ][] = $field;
		}

		foreach ( $grouped as $group_slug => $fields ) {
			?>
			<h3><?php echo esc_html( $titles[ $group_slug ] ?? ucfirst( (string) $group_slug ) ); ?></h3>
			<table class="form-table" role="presentation"><tbody>
				<?php foreach ( $fields as $field ) : ?>
					<?php $value = CustomFieldValues::find( $values, $field['slug'], null ); ?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( 'nowcrm-cf-' . $field['slug'] ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
							</label>
						</th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( CustomFieldValues::FORM_MARKER . '[]' ); ?>" value="<?php echo esc_attr( $field['slug'] ); ?>" />
							<?php self::render_control( $field, $value ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php
		}
	}

	/**
	 * Validate and save the custom values of one contact.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $post       Unslashed POST data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_contact_fields( int $contact_id, array $post ) {
		if ( ! Capabilities::can_manage_contacts() ) {
			return new WP_Error( 'nowcrm_forbidden', __( '您沒有權限修改聯絡人。', 'now-crm' ) );
		}

		return ( new CustomFieldValues() )->save( $contact_id, CustomFieldValues::parse_request( $post ) );
	}

	/**
	 * One input for a field kind.
	 *
	 * @param array<string, mixed> $field Definition.
	 * @param mixed                $value Stored value.
	 */
	private static function render_control( array $field, $value ): void {
		$key  = (string) $field['slug'];
		$id   = 'nowcrm-cf-' . $key;
		$name = CustomFieldValues::form_name( $key );

		switch ( $field['type'] ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( is_scalar( $value ) ? (string) $value : '' )
				);
				break;

			case 'number':
				printf(
					'<input type="number" step="any" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;

			case 'date_time':
				$local = is_scalar( $value ) ? str_replace( ' ', 'T', substr( (string) $value, 0, 16 ) ) : '';

				printf(
					'<input type="datetime-local" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $local )
				);
				break;

			case 'select-one':
				printf( '<select id="%1$s" name="%2$s"><option value="">%3$s</option>', esc_attr( $id ), esc_attr( $name ), esc_html__( '—', 'now-crm' ) );

				foreach ( (array) $field['options'] as $option ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option ),
						selected( is_scalar( $value ) ? (string) $value : '', $option, false ),
						esc_html( $option )
					);
				}

				echo '</select>';
				break;

			case 'radio':
				$current = is_scalar( $value ) ? (string) $value : '';
				$radio   = array( '' => __( '未選擇', 'now-crm' ) );

				foreach ( (array) $field['options'] as $option ) {
					$radio[ $option ] = $option;
				}

				foreach ( $radio as $option => $label ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />',
						esc_attr( $name ),
						esc_attr( $option ),
						checked( $current, $option, false ),
						esc_html( $label )
					);
				}
				break;

			case 'select-multi':
			case 'checkbox':
				$current = is_array( $value ) ? array_map( 'strval', $value ) : array();
				$name    = CustomFieldValues::form_name( $key, true );

				foreach ( (array) $field['options'] as $option ) {
					printf(
						'<label><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />',
						esc_attr( $name ),
						esc_attr( $option ),
						checked( in_array( $option, $current, true ), true, false ),
						esc_html( $option )
					);
				}
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;
		}
	}
}
