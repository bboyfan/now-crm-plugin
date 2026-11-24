<?php
/**
 * Public shortcode renderer. `[nowcrm_form id="N"]` prints one server-rendered
 * form with a nonce, a honeypot, and inline validation feedback.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

defined( 'ABSPATH' ) || exit;

final class FormRenderer {

	/**
	 * Register the exact public shortcode.
	 */
	public static function register(): void {
		add_shortcode( 'nowcrm_form', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Render one form. Invalid IDs render nothing so no data leaks.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			(array) $atts,
			'nowcrm_form'
		);

		$form_id = absint( $atts['id'] );

		if ( $form_id <= 0 ) {
			return '';
		}

		$form = FormRepository::get( $form_id );

		if ( ! $form ) {
			return '';
		}

		if ( self::is_success( $form_id ) ) {
			return '<div class="nowcrm-form nowcrm-form--success" role="status">' . wp_kses_post( (string) $form['success_message'] ) . '</div>';
		}

		$state = self::state( $form_id );

		if ( array() === $form['fields'] ) {
			return '';
		}

		return self::form_markup( $form, $state );
	}

	/**
	 * Validation state carried by error query arguments.
	 *
	 * @return array{message: string, errors: array<string, string>, values: array<string, mixed>}
	 */
	public static function state( int $form_id ): array {
		$state = array(
			'message' => '',
			'errors'  => array(),
			'values'  => array(),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only arguments set by the submission handler.
		$error = isset( $_GET['nowcrm_form_error'] ) && is_string( $_GET['nowcrm_form_error'] )
			? sanitize_key( wp_unslash( $_GET['nowcrm_form_error'] ) )
			: '';

		if ( 'rate_limited' === $error ) {
			$state['message'] = __( '送出過於頻繁，請稍後再試。', 'now-crm' );

			return $state;
		}

		if ( 'error' === $error ) {
			$state['message'] = __( '表單送出失敗，請再試一次。', 'now-crm' );

			return $state;
		}

		if ( 'validation' !== $error ) {
			return $state;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- single-use token for a previous submission.
		$token = isset( $_GET['nowcrm_form_token'] ) && is_string( $_GET['nowcrm_form_token'] )
			? sanitize_key( wp_unslash( $_GET['nowcrm_form_token'] ) )
			: '';

		if ( '' === $token ) {
			$state['message'] = __( '表單資料不正確，請重新填寫。', 'now-crm' );

			return $state;
		}

		$stored = get_transient( Submission::error_transient_key( $token ) );

		if ( ! is_array( $stored ) || (int) ( $stored['form_id'] ?? 0 ) !== $form_id ) {
			return $state;
		}

		delete_transient( Submission::error_transient_key( $token ) );

		$state['message'] = (string) ( $stored['message'] ?? '' );
		$state['errors']  = is_array( $stored['errors'] ?? null ) ? $stored['errors'] : array();
		$state['values']  = is_array( $stored['values'] ?? null ) ? $stored['values'] : array();

		return $state;
	}

	/**
	 * Full form markup.
	 *
	 * @param array<string, mixed> $form  Form settings.
	 * @param array<string, mixed> $state Validation state.
	 */
	private static function form_markup( array $form, array $state ): string {
		$form_id = (int) $form['id'];
		$uid     = wp_unique_id( 'nowcrm-form-' );
		$return  = get_permalink();

		if ( ! is_string( $return ) || '' === $return ) {
			$return = home_url( '/' );
		}

		$token = wp_generate_password( 20, false, false );

		ob_start();
		?>
		<form class="nowcrm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Submission::ACTION ); ?>" />
			<input type="hidden" name="nowcrm_form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<input type="hidden" name="nowcrm_form_token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="nowcrm_form_return" value="<?php echo esc_url( $return ); ?>" />
			<?php
			echo wp_nonce_field( Submission::ACTION . '_' . $form_id, '_nowcrm_form_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated field.
			?>

			<div class="nowcrm-form__honeypot" aria-hidden="true"
				style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
				<label for="<?php echo esc_attr( $uid . '-website' ); ?>"><?php esc_html_e( '網站', 'now-crm' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid . '-website' ); ?>" name="nowcrm_form_hp"
					value="" tabindex="-1" autocomplete="off" />
			</div>

			<?php if ( '' !== (string) $state['message'] ) : ?>
				<div class="nowcrm-form__error" role="alert">
					<?php echo esc_html( (string) $state['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $form['fields'] as $field ) : ?>
				<?php self::field_markup( $uid, $field, $state ); ?>
			<?php endforeach; ?>

			<p class="nowcrm-form__actions">
				<button type="submit" class="nowcrm-form__submit">
					<?php echo esc_html( (string) $form['submit_label'] ); ?>
				</button>
			</p>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * One field, with its label, control, and error.
	 *
	 * @param string               $uid     Unique page prefix.
	 * @param array<string, mixed> $field   Field definition.
	 * @param array<string, mixed> $state   Validation state.
	 */
	private static function field_markup( string $uid, array $field, array $state ): void {
		$key      = (string) ( $field['key'] ?? '' );
		$type     = (string) ( $field['type'] ?? Fields::TEXT );
		$label    = (string) ( $field['label'] ?? $key );
		$required = ! empty( $field['required'] );
		$options  = is_array( $field['options'] ?? null ) ? $field['options'] : array();
		$input_id = $uid . '-' . sanitize_html_class( $key );
		$name     = 'nowcrm_fields[' . $key . ']';
		$error    = isset( $state['errors'][ $key ] ) ? (string) $state['errors'][ $key ] : '';
		$old      = $state['values'][ $key ] ?? null;
		$value    = is_scalar( $old ) ? (string) $old : '';
		$selected = is_array( $old ) ? array_map( 'strval', $old ) : array();
		$described = '' !== $error ? ' aria-describedby="' . esc_attr( $input_id . '-error' ) . '"' : '';
		$invalid   = '' !== $error ? ' aria-invalid="true"' : '';
		$needed    = $required ? ' required' : '';
		?>
		<div class="nowcrm-form__field nowcrm-form__field--<?php echo esc_attr( $type ); ?>">
			<?php if ( Fields::CHECKBOX === $type || Fields::RADIO === $type ) : ?>
				<fieldset<?php echo $described; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped attributes. ?>>
					<legend>
						<?php echo esc_html( $label ); ?>
						<?php if ( $required ) : ?>
							<span class="nowcrm-form__required" aria-hidden="true">*</span>
						<?php endif; ?>
					</legend>
					<?php foreach ( $options as $option ) : ?>
						<?php $option = (string) $option; ?>
						<label>
							<input
								type="<?php echo Fields::CHECKBOX === $type ? 'checkbox' : 'radio'; ?>"
								name="<?php echo esc_attr( Fields::CHECKBOX === $type ? $name . '[]' : $name ); ?>"
								value="<?php echo esc_attr( $option ); ?>"
								<?php if ( Fields::CHECKBOX === $type ) : ?>
									<?php checked( in_array( $option, $selected, true ) ); ?>
								<?php else : ?>
									<?php checked( $value, $option ); ?>
									<?php echo $needed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute. ?>
								<?php endif; ?>
							/>
							<?php echo esc_html( $option ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php else : ?>
				<label for="<?php echo esc_attr( $input_id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $required ) : ?>
						<span class="nowcrm-form__required" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>

				<?php if ( Fields::TEXTAREA === $type ) : ?>
					<textarea id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $name ); ?>"
						rows="5"<?php echo $needed . $described . $invalid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped attributes. ?>><?php echo esc_textarea( $value ); ?></textarea>
				<?php elseif ( Fields::SELECT === $type ) : ?>
					<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $name ); ?>"
						<?php echo $needed . $described . $invalid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped attributes. ?>>
						<option value=""><?php esc_html_e( '請選擇', 'now-crm' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<?php $option = (string) $option; ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>>
								<?php echo esc_html( $option ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<?php
					$input_type = Fields::EMAIL === $type ? 'email' : ( Fields::NUMBER === $type ? 'number' : ( Fields::DATE === $type ? 'date' : 'text' ) );
					$extra      = Fields::EMAIL === $type ? ' autocomplete="email"' : ( Fields::NUMBER === $type ? ' step="any" inputmode="decimal"' : '' );
					?>
					<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
						<?php echo $needed . $extra . $described . $invalid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped attributes. ?> />
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<p class="nowcrm-form__field-error" id="<?php echo esc_attr( $input_id . '-error' ); ?>">
					<?php echo esc_html( $error ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether the current request is the success redirect for this form.
	 */
	private static function is_success( int $form_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only argument set by the submission handler.
		return isset( $_GET['nowcrm_form_success'] )
			&& is_scalar( $_GET['nowcrm_form_success'] )
			&& absint( wp_unslash( (string) $_GET['nowcrm_form_success'] ) ) === $form_id;
	}
}
