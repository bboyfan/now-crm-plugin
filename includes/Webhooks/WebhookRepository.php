<?php
/**
 * Outbound webhook configuration stored in one option.
 *
 * Each webhook owns a name, endpoint, event subscription, active flag and a
 * random signing secret. The secret is generated here and never derived from
 * user input. URLs must use HTTPS, except for loopback and private-network
 * destinations where plain HTTP is allowed for local development.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WebhookRepository {

	public const OPTION = 'nowcrm_webhooks';

	public const MAX_WEBHOOKS = 50;

	public const MAX_EVENTS = 20;

	public const MAX_NAME = 100;

	public const MAX_URL = 2000;

	/**
	 * Every stored webhook, keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rows   = array();

		foreach ( $stored as $key => $row ) {
			$config = $this->normalize( is_array( $row ) ? $row : array(), is_string( $key ) ? $key : '' );

			if ( null !== $config ) {
				$rows[ $config['id'] ] = $config;
			}
		}

		return $rows;
	}

	/**
	 * One webhook by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Active webhooks subscribed to one event.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active_for_event( string $event ): array {
		if ( ! Events::is_valid( $event ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->all(),
				static fn( array $webhook ): bool => ! empty( $webhook['active'] ) && in_array( $event, $webhook['events'], true )
			)
		);
	}

	/**
	 * Create a webhook.
	 *
	 * @param array<string, mixed> $data name, url, events, active.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $data ) {
		$webhooks = $this->all();

		if ( count( $webhooks ) >= self::MAX_WEBHOOKS ) {
			return new WP_Error( 'nowcrm_webhook_too_many', __( '已達 Webhook 數量上限。', 'now-crm' ) );
		}

		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$id = wp_generate_uuid4();

		while ( isset( $webhooks[ $id ] ) ) {
			$id = wp_generate_uuid4();
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$webhooks[ $id ] = array_merge(
			$validated,
			array(
				'id'         => $id,
				'secret'     => bin2hex( random_bytes( 32 ) ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$this->store( $webhooks );

		return $webhooks[ $id ];
	}

	/**
	 * Update a webhook. The signing secret is never replaced here.
	 *
	 * @param array<string, mixed> $data name, url, events, active.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( string $id, array $data ) {
		$webhooks = $this->all();
		$existing = $webhooks[ $id ] ?? null;

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_webhook_not_found', __( '找不到這個 Webhook。', 'now-crm' ) );
		}

		$validated = $this->validate( array_merge( $existing, $data ) );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$webhooks[ $id ] = array_merge(
			$existing,
			$validated,
			array(
				'id'         => $id,
				'secret'     => (string) $existing['secret'],
				'created_at' => (string) $existing['created_at'],
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->store( $webhooks );

		return $webhooks[ $id ];
	}

	/**
	 * Delete a webhook.
	 */
	public function delete( string $id ): bool {
		$webhooks = $this->all();

		if ( ! isset( $webhooks[ $id ] ) ) {
			return false;
		}

		unset( $webhooks[ $id ] );
		$this->store( $webhooks );

		return true;
	}

	/**
	 * Replace the signing secret with a new random value.
	 *
	 * @return string|WP_Error New secret.
	 */
	public function rotate_secret( string $id ) {
		$webhooks = $this->all();

		if ( ! isset( $webhooks[ $id ] ) ) {
			return new WP_Error( 'nowcrm_webhook_not_found', __( '找不到這個 Webhook。', 'now-crm' ) );
		}

		$secret = bin2hex( random_bytes( 32 ) );

		$webhooks[ $id ]['secret']     = $secret;
		$webhooks[ $id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$this->store( $webhooks );

		return $secret;
	}

	/**
	 * Validate and normalize user input.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate( array $data ) {
		$name = isset( $data['name'] ) && is_scalar( $data['name'] )
			? trim( sanitize_text_field( (string) $data['name'] ) )
			: '';

		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
			return new WP_Error( 'nowcrm_webhook_invalid_name', __( '請輸入 100 字以內的 Webhook 名稱。', 'now-crm' ) );
		}

		$url = $this->validate_url( isset( $data['url'] ) && is_scalar( $data['url'] ) ? (string) $data['url'] : '' );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$events = array();

		foreach ( (array) ( $data['events'] ?? array() ) as $event ) {
			if ( ! is_scalar( $event ) ) {
				continue;
			}

			$event = sanitize_text_field( (string) $event );

			if ( Events::is_valid( $event ) ) {
				$events[] = $event;
			}
		}

		$events = array_values( array_unique( $events ) );

		if ( array() === $events ) {
			return new WP_Error( 'nowcrm_webhook_invalid_events', __( '請至少選擇一個有效事件。', 'now-crm' ) );
		}

		if ( count( $events ) > self::MAX_EVENTS ) {
			return new WP_Error( 'nowcrm_webhook_invalid_events', __( '選擇的事件太多。', 'now-crm' ) );
		}

		return array(
			'name'   => $name,
			'url'    => $url,
			'events' => $events,
			'active' => ! empty( $data['active'] ),
		);
	}

	/**
	 * Validate an endpoint URL.
	 *
	 * @return string|WP_Error
	 */
	private function validate_url( string $url ) {
		$url = trim( $url );

		if ( '' === $url || strlen( $url ) > self::MAX_URL ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( '請輸入有效的 Webhook 網址。', 'now-crm' ) );
		}

		if ( preg_match( '/[\s<>"\']/', $url ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( 'Webhook 網址含有不允許的字元。', 'now-crm' ) );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( '請輸入完整的 Webhook 網址。', 'now-crm' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$host   = trim( $host, '[]' );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( 'Webhook 只支援 HTTP 或 HTTPS。', 'now-crm' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( 'Webhook 網址不可包含帳號密碼。', 'now-crm' ) );
		}

		if ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_url', __( 'Webhook 連接埠不正確。', 'now-crm' ) );
		}

		if ( 'http' === $scheme && ! $this->is_local_host( $host ) ) {
			return new WP_Error( 'nowcrm_webhook_https_required', __( '外部 Webhook 必須使用 HTTPS；HTTP 只允許本機或私有網路。', 'now-crm' ) );
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Whether a host is loopback, a reserved local name, or a private address.
	 */
	private function is_local_host( string $host ): bool {
		if ( in_array( $host, array( 'localhost', '::1' ), true ) ) {
			return true;
		}

		if ( str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets = array_map( 'intval', explode( '.', $host ) );
			$first  = $octets[0] ?? -1;
			$second = $octets[1] ?? -1;

			return 10 === $first
				|| 127 === $first
				|| ( 192 === $first && 168 === $second )
				|| ( 172 === $first && $second >= 16 && $second <= 31 )
				|| ( 169 === $first && 254 === $second );
		}

		if ( str_contains( $host, ':' ) ) {
			return str_starts_with( $host, 'fc' )
				|| str_starts_with( $host, 'fd' )
				|| str_starts_with( $host, 'fe80' );
		}

		return false;
	}

	/**
	 * Normalize one stored row.
	 *
	 * @param array<string, mixed> $row Stored value.
	 * @param string               $key Stored array key.
	 * @return array<string, mixed>|null
	 */
	private function normalize( array $row, string $key ): ?array {
		$id = isset( $row['id'] ) && is_string( $row['id'] ) ? $row['id'] : $key;

		if ( '' === $id ) {
			return null;
		}

		$events = array();

		foreach ( (array) ( $row['events'] ?? array() ) as $event ) {
			if ( is_string( $event ) && Events::is_valid( $event ) ) {
				$events[] = $event;
			}
		}

		return array(
			'id'         => $id,
			'name'       => (string) ( $row['name'] ?? '' ),
			'url'        => (string) ( $row['url'] ?? '' ),
			'events'     => array_values( array_unique( $events ) ),
			'active'     => ! empty( $row['active'] ),
			'secret'     => (string) ( $row['secret'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Persist the full map without autoloading it.
	 *
	 * @param array<string, array<string, mixed>> $webhooks Rows.
	 */
	private function store( array $webhooks ): void {
		update_option( self::OPTION, $webhooks, false );
	}
}
