<?php
/**
 * Versioned plugin settings stored in a single option.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nowcrm_settings';

	/**
	 * Default settings. Booleans gate behaviour, integers are clamped on write,
	 * strings are sanitized. Production defaults never send mail: both the
	 * sending switch and the abandonment engine start disabled.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = array(
		'woo_sync_enabled'                    => false,
		'auto_subscribe_new_woo_contacts'      => false,
		'show_checkout_subscription_checkbox' => false,
		'email_sending_enabled'               => false,
		'abandoned_cart_enabled'              => false,
		'email_transport'                     => 'wp_mail',
		'email_from_name'                     => '',
		'email_from_address'                  => '',
		'email_reply_to'                      => '',
		'email_batch_size'                    => 50,
		'email_throttle_per_second'           => 15,
		'email_max_attempts'                  => 3,
		'email_lease_minutes'                 => 10,
		'campaign_tracking_enabled'           => false,
		'sequence_tracking_enabled'           => false,
		'ses_configuration_set'               => '',
		'ses_topic_arn'                       => '',
		'cart_idle_minutes'                   => 20,
		'cart_reminder_delay_minutes'         => 0,
		'cart_lost_days'                      => 5,
		'cart_cooldown_days'                  => 3,
		'cart_max_reminders'                  => 1,
		'cart_reminder_template_id'           => 0,
		'cart_recovery_days'                  => 7,
		'cart_automation_id'                  => 0,
	);

	/**
	 * Integer range limits. Missing keys are unbounded but still cast to int.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private const RANGES = array(
		'email_batch_size'            => array( 1, 500 ),
		'email_throttle_per_second'   => array( 1, 100 ),
		'email_max_attempts'          => array( 1, 10 ),
		'email_lease_minutes'         => array( 1, 60 ),
		'cart_idle_minutes'           => array( 1, 1440 ),
		'cart_reminder_delay_minutes' => array( 0, 1440 ),
		'cart_lost_days'              => array( 1, 60 ),
		'cart_cooldown_days'          => array( 0, 60 ),
		'cart_max_reminders'          => array( 0, 3 ),
		'cart_recovery_days'          => array( 1, 30 ),
	);

	/**
	 * All settings, defaults merged over the stored values.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = self::DEFAULTS;

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$settings[ $key ] = self::cast( $key, $stored[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Boolean helper.
	 */
	public static function bool( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * Integer helper.
	 */
	public static function int( string $key ): int {
		return (int) self::get( $key, 0 );
	}

	/**
	 * String helper.
	 */
	public static function string( string $key ): string {
		return (string) self::get( $key, '' );
	}

	/**
	 * Merge a patch into the settings. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $patch Partial settings.
	 * @return array<string, mixed>
	 */
	public static function update( array $patch ): array {
		$settings = self::all();

		foreach ( $patch as $key => $value ) {
			if ( array_key_exists( $key, self::DEFAULTS ) ) {
				$settings[ $key ] = self::cast( $key, $value );
			}
		}

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Restore defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function reset(): array {
		update_option( self::OPTION, self::DEFAULTS, false );

		return self::DEFAULTS;
	}

	/**
	 * Write defaults without clobbering existing values (activation path).
	 */
	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::DEFAULTS, '', false );
		}
	}

	/**
	 * Cast one value using the default's type.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function cast( string $key, $value ) {
		$default = self::DEFAULTS[ $key ] ?? null;

		if ( is_bool( $default ) ) {
			if ( is_string( $value ) ) {
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}

			return (bool) $value;
		}

		if ( is_int( $default ) ) {
			$int = (int) $value;

			if ( isset( self::RANGES[ $key ] ) ) {
				[ $min, $max ] = self::RANGES[ $key ];
				$int           = max( $min, min( $max, $int ) );
			}

			return $int;
		}

		$string = sanitize_text_field( (string) $value );

		if ( 'email_from_address' === $key || 'email_reply_to' === $key ) {
			$string = sanitize_email( $string );
		}

		if ( 'email_transport' === $key && ! in_array( $string, array( 'wp_mail', 'capture' ), true ) ) {
			$string = 'wp_mail';
		}

		// AWS accepts only ASCII letters, digits, hyphens and underscores; an
		// empty value lets SES use the identity default.
		if ( 'ses_configuration_set' === $key ) {
			$string = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $string ), 0, 64 );
		}

		return $string;
	}
}
