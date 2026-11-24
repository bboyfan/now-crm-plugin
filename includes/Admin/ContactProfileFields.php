<?php
/**
 * Contact profile form: inputs for the standard contact columns.
 *
 * Render inside the existing contact form. Input names are the field keys, so
 * the handler can pass the whole POST array to `ProfileFields::normalize()`.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ProfileFields;

defined( 'ABSPATH' ) || exit;

final class ContactProfileFields {

	/**
	 * Fields shown in the address section.
	 *
	 * @var string[]
	 */
	private const ADDRESS = array( 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' );

	/**
	 * Render the basic information rows: identity, birthday, and profile
	 * selects that belong to the same block.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public static function render_basic( array $contact ): void {
		?>
		<table class="form-table nowcrm-contact-detail__fields" role="presentation">
			<tbody>
				<?php
				self::render_row( $contact, 'prefix' );
				self::render_text_row( $contact, 'first_name', __( '名字', 'now-crm' ), 'text', 191 );
				self::render_text_row( $contact, 'last_name', __( '姓氏', 'now-crm' ), 'text', 191 );
				self::render_text_row( $contact, 'email', __( 'Email', 'now-crm' ), 'email', 191, true );
				self::render_text_row( $contact, 'phone', __( '電話', 'now-crm' ), 'tel', 50 );
				self::render_row( $contact, 'date_of_birth' );
				self::render_row( $contact, 'contact_type' );
				self::render_row( $contact, 'timezone' );
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the address rows.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public static function render_address( array $contact ): void {
		?>
		<table class="form-table nowcrm-contact-detail__fields" role="presentation">
			<tbody>
				<?php foreach ( self::ADDRESS as $key ) : ?>
					<?php self::render_row( $contact, $key ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One form-table row for a profile field.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private static function render_row( array $contact, string $key ): void {
		$definition = ProfileFields::definition( $key );

		if ( null === $definition ) {
			return;
		}

		$id = 'nowcrm-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( (string) $definition['label'] ); ?>
				</label>
			</th>
			<td>
				<?php
				self::render_control(
					$key,
					$definition,
					$id,
					ProfileFields::form_value( $contact, $key )
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * One form-table row for a core contact column.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private static function render_text_row( array $contact, string $key, string $label, string $type, int $maxlength, bool $required = false ): void {
		$id = 'nowcrm-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $key ); ?>" class="regular-text"
					value="<?php echo esc_attr( (string) ( $contact[ $key ] ?? '' ) ); ?>"
					maxlength="<?php echo esc_attr( (string) $maxlength ); ?>"<?php echo $required ? ' required="required"' : ''; ?> />
			</td>
		</tr>
		<?php
	}

	/**
	 * One control for a field type.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 */
	private static function render_control( string $key, array $definition, string $id, string $value ): void {
		switch ( $definition['type'] ) {
			case ProfileFields::TYPE_SELECT:
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $key ) );

				// Contact type always has a value; the other selects allow "unset".
				if ( 'contact_type' !== $key ) {
					printf( '<option value="">%s</option>', esc_html__( '—', 'now-crm' ) );
				}

				foreach ( (array) $definition['options'] as $option => $label ) {
					// A plain list uses the label as the stored value.
					if ( is_int( $option ) ) {
						$option = $label;
					}

					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $option ),
						selected( $value, (string) $option, false ),
						esc_html( (string) $label )
					);
				}

				echo '</select>';
				break;

			case ProfileFields::TYPE_DATE:
				printf(
					'<input type="date" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $key ),
					esc_attr( $value )
				);
				printf( '<p class="description">%s</p>', esc_html__( '格式：YYYY-MM-DD。', 'now-crm' ) );
				break;

			case ProfileFields::TYPE_TIMEZONE:
				printf(
					'<select id="%1$s" name="%2$s"><option value="">%3$s</option>',
					esc_attr( $id ),
					esc_attr( $key ),
					esc_html__( '—', 'now-crm' )
				);

				// Core prints grouped, selected option markup.
				echo wp_timezone_choice( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core option markup.

				echo '</select>';
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" class="regular-text" value="%3$s" maxlength="%4$d" />',
					esc_attr( $id ),
					esc_attr( $key ),
					esc_attr( $value ),
					(int) ( $definition['max_length'] ?? 0 )
				);
		}
	}
}
