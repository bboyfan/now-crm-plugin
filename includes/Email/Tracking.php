<?php
/**
 * Signed open/click tracking. Opens are explicitly a weak signal: Apple Mail
 * and Gmail prefetch pixels, so the report labels them as "可能預取".
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Support\Schema;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Tracking {

	/**
	 * Register public tracking routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * REST routes.
	 */
	public function routes(): void {
		register_rest_route(
			'nowcrm/v1',
			'/open/(?P<token>[A-Za-z0-9_\-\.]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'open_pixel' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'nowcrm/v1',
			'/click/(?P<token>[A-Za-z0-9_\-\.]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'click_redirect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Build a signed token.
	 *
	 * @param array<string, mixed> $payload Payload (j, c, t, u).
	 */
	public function sign( array $payload ): string {
		$json = wp_json_encode( $payload );
		$body = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', $body, $this->secret(), true );

		return $body . '.' . rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
	}

	/**
	 * Verify a signed token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		[ $body, $sig ] = $parts;

		$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $body, $this->secret(), true ) ), '+/', '-_' ), '=' );

		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}

		$json = base64_decode( strtr( $body, '-_', '+/' ), true );

		if ( false === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Open pixel URL for a job/contact pair.
	 *
	 * @param array<string, mixed> $job     Job row.
	 * @param array<string, mixed> $contact Contact row.
	 */
	public function open_url( array $job, array $contact ): string {
		$token = $this->sign(
			array(
				'j' => (int) $job['id'],
				'c' => (int) $job['campaign_id'],
				't' => (int) ( $contact['id'] ?? $job['contact_id'] ),
			)
		);

		return rest_url( 'nowcrm/v1/open/' . $token );
	}

	/**
	 * Click URL for one link.
	 *
	 * @param array<string, mixed> $job     Job row.
	 * @param array<string, mixed> $contact Contact row.
	 * @param string               $url     Destination URL.
	 */
	public function click_url( array $job, array $contact, string $url ): string {
		$token = $this->sign(
			array(
				'j' => (int) $job['id'],
				'c' => (int) $job['campaign_id'],
				't' => (int) ( $contact['id'] ?? $job['contact_id'] ),
				'u' => $url,
			)
		);

		return rest_url( 'nowcrm/v1/click/' . $token );
	}

	/**
	 * Rewrite links and append the pixel before sending.
	 *
	 * @param array<string, mixed> $job     Job row.
	 * @param array<string, mixed> $contact Contact row.
	 */
	public function inject( string $html, array $job, array $contact ): string {
		$payload  = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$tracking = isset( $payload['tracking'] ) && is_array( $payload['tracking'] ) ? $payload['tracking'] : array();
		$enabled  = Settings::bool( 'campaign_tracking_enabled' );
		$open     = $enabled && ( ! array_key_exists( 'open', $tracking ) || (bool) $tracking['open'] );
		$click    = $enabled && ( ! array_key_exists( 'click', $tracking ) || (bool) $tracking['click'] );
		$utm      = $enabled ? $this->utm_args( $job ) : array();

		return $this->apply_tracking( $html, $job, $contact, $open, $click, $utm );
	}

	/**
	 * Rewrite sequence links and append the pixel without campaign UTM.
	 *
	 * The queue decides whether injection runs. This method only applies the
	 * shared job-scoped signed endpoints.
	 *
	 * @param array<string, mixed> $job     Job row.
	 * @param array<string, mixed> $contact Contact row.
	 */
	public function inject_sequence( string $html, array $job, array $contact ): string {
		return $this->apply_tracking( $html, $job, $contact, true, true, array() );
	}

	/**
	 * Apply the requested link rewriting and open pixel.
	 *
	 * @param array<string, mixed>  $job     Job row.
	 * @param array<string, mixed>  $contact Contact row.
	 * @param array<string, string> $utm     UTM arguments, empty for sequences.
	 */
	private function apply_tracking( string $html, array $job, array $contact, bool $open, bool $click, array $utm ): string {
		if ( $click || $utm ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( 'A' ) ) {
				$url = $tags->get_attribute( 'href' );
				if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) || str_contains( $url, 'nowcrm_unsubscribe=1' ) ) {
					continue;
				}
				$url = $utm ? $this->with_utm( $url, $utm ) : $url;
				$tags->set_attribute( 'href', esc_url_raw( $click ? $this->click_url( $job, $contact, $url ) : $url ) );
			}
			$html = $tags->get_updated_html();
		}

		if ( ! $open ) {
			return $html;
		}

		$pixel = '<img src="' . esc_url( $this->open_url( $job, $contact ) ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />';

		if ( false !== stripos( $html, '</body>' ) ) {
			$html = (string) preg_replace( '#</body>#i', $pixel . '</body>', $html, 1 );
		} else {
			$html .= $pixel;
		}

		return $html;
	}

	/**
	 * UTM parameters for a job.
	 *
	 * A campaign payload wins; without one the historical defaults apply.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, string>
	 */
	private function utm_args( array $job ): array {
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();

		if ( array_key_exists( 'utm', $payload ) ) {
			$utm = is_array( $payload['utm'] ) ? $payload['utm'] : array();

			if ( empty( $utm['enabled'] ) ) {
				return array();
			}

			$map  = array(
				'source'   => 'utm_source',
				'medium'   => 'utm_medium',
				'campaign' => 'utm_campaign',
				'term'     => 'utm_term',
				'content'  => 'utm_content',
			);
			$args = array();

			foreach ( $map as $key => $param ) {
				$value = trim( (string) ( $utm[ $key ] ?? '' ) );

				if ( '' !== $value ) {
					$args[ $param ] = $value;
				}
			}

			return $args;
		}

		$campaign_id = (int) ( $job['campaign_id'] ?? 0 );

		if ( $campaign_id <= 0 ) {
			return array();
		}

		return array(
			'utm_source'   => 'nowcrm',
			'utm_medium'   => 'email',
			'utm_campaign' => (string) $campaign_id,
		);
	}

	/**
	 * Append UTM parameters without duplicating ones already present.
	 *
	 * @param string                $url  Destination URL.
	 * @param array<string, string> $args UTM parameters.
	 */
	private function with_utm( string $url, array $args ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$found = array();

		if ( '' !== $query ) {
			parse_str( $query, $found );
		}

		$args = array_diff_key( $args, $found );

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Pixel callback.
	 */
	public function open_pixel( \WP_REST_Request $request ) {
		$payload = $this->verify( (string) $request->get_param( 'token' ) );

		if ( ! $payload ) {
			return new \WP_REST_Response( null, 404 );
		}

		$this->record_open_callback( $payload, (string) $request->get_header( 'user_agent' ) );

		if ( ! headers_sent() ) {
			header( 'Content-Type: image/gif' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}

		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary GIF.
		exit;
	}

	/**
	 * Click callback with signed destination validation.
	 */
	public function click_redirect( \WP_REST_Request $request ) {
		$payload = $this->verify( (string) $request->get_param( 'token' ) );
		$url     = is_array( $payload ) ? (string) ( $payload['u'] ?? '' ) : '';

		if ( ! is_array( $payload ) || ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_REST_Response( null, 404 );
		}

		$this->record_click_callback( $payload, $url, (string) $request->get_header( 'user_agent' ) );

		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is signed and scheme-checked.
		exit;
	}

	/**
	 * Record an open callback only while the persisted job allows tracking.
	 *
	 * The token carries no kind. Read the stored job so a request cannot choose
	 * the setting that gates recording. A missing job records nothing.
	 *
	 * @param array<string, mixed> $payload    Verified token payload.
	 * @param string               $user_agent Request user agent.
	 */
	public function record_open_callback( array $payload, string $user_agent = '' ): bool {
		return $this->job_allows_tracking( $payload ) && $this->record_open( $payload, $user_agent );
	}

	/**
	 * Record a click callback only while the persisted job allows tracking.
	 *
	 * @param array<string, mixed> $payload    Verified token payload.
	 * @param string               $url        Signed destination URL.
	 * @param string               $user_agent Request user agent.
	 */
	public function record_click_callback( array $payload, string $url, string $user_agent = '' ): bool {
		return $this->job_allows_tracking( $payload ) && $this->record_click( $payload, $url, $user_agent );
	}

	/**
	 * Whether the stored job of a verified token still allows tracking.
	 *
	 * Sequence jobs use the sequence setting. Campaign, cart, custom and
	 * automation jobs use the campaign setting, the same gate as injection.
	 *
	 * @param array<string, mixed> $payload Verified token payload.
	 */
	private function job_allows_tracking( array $payload ): bool {
		$job = ( new EmailJobRepository() )->get( (int) ( $payload['j'] ?? 0 ) );

		if ( ! $job ) {
			return false;
		}

		return Settings::bool(
			'sequence' === (string) ( $job['kind'] ?? '' ) ? 'sequence_tracking_enabled' : 'campaign_tracking_enabled'
		);
	}

	/**
	 * Record a unique open per campaign/contact (or per job when no campaign).
	 *
	 * @param array<string, mixed> $payload Token payload.
	 */
	public function record_open( array $payload, string $user_agent = '' ): bool {
		$campaign = (int) ( $payload['c'] ?? 0 );
		$contact  = (int) ( $payload['t'] ?? 0 );
		$job      = (int) ( $payload['j'] ?? 0 );

		$key = $campaign > 0
			? sprintf( 'open:%d:%d', $campaign, $contact )
			: sprintf( 'open:job:%d:%d', $job, $contact );

		return $this->record( $key, 'open', $campaign, $job, $contact, '', $user_agent );
	}

	/**
	 * Record a unique click per campaign/contact/url.
	 *
	 * @param array<string, mixed> $payload Token payload.
	 */
	public function record_click( array $payload, string $url, string $user_agent = '' ): bool {
		$campaign = (int) ( $payload['c'] ?? 0 );
		$contact  = (int) ( $payload['t'] ?? 0 );
		$job      = (int) ( $payload['j'] ?? 0 );

		$key = $campaign > 0
			? sprintf( 'click:%d:%d:%s', $campaign, $contact, sha1( $url ) )
			: sprintf( 'click:job:%d:%d:%s', $job, $contact, sha1( $url ) );

		return $this->record( $key, 'click', $campaign, $job, $contact, $url, $user_agent );
	}

	/**
	 * Insert an event once.
	 */
	public function record( string $event_key, string $type, int $campaign_id, int $job_id, int $contact_id, string $url = '', string $user_agent = '', array $meta = array() ): bool {
		global $wpdb;

		$table = Schema::table( 'email_events' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$campaign_sql = $campaign_id > 0 ? '%d' : 'NULL';
		$job_sql      = $job_id > 0 ? '%d' : 'NULL';
		$contact_sql  = $contact_id > 0 ? '%d' : 'NULL';

		$sql = "INSERT IGNORE INTO {$table}
			(event_key, type, campaign_id, job_id, contact_id, url, user_agent, ip_hash, meta, occurred_at)
			VALUES (%s, %s, {$campaign_sql}, {$job_sql}, {$contact_sql}, %s, %s, %s, %s, %s)";

		$params = array(
			substr( $event_key, 0, 191 ),
			substr( $type, 0, 30 ),
			substr( $url, 0, 1000 ),
			substr( $user_agent, 0, 255 ),
			$this->ip_hash(),
			wp_json_encode( $meta ),
			$now,
		);

		if ( $campaign_id > 0 ) {
			array_splice( $params, 2, 0, array( $campaign_id ) );
		}

		if ( $job_id > 0 ) {
			array_splice( $params, $campaign_id > 0 ? 3 : 2, 0, array( $job_id ) );
		}

		if ( $contact_id > 0 ) {
			array_splice( $params, 2 + ( $campaign_id > 0 ? 1 : 0 ) + ( $job_id > 0 ? 1 : 0 ), 0, array( $contact_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and NULL tokens are generated above.
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		return (bool) $result;
	}

	/**
	 * Aggregate open/click/unsubscribe counters.
	 *
	 * @return array<string, int>
	 */
	public function campaign_stats( int $campaign_id ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT type, COUNT(*) AS total FROM {$table} WHERE campaign_id = %d GROUP BY type", $campaign_id ),
			ARRAY_A
		);

		$stats = array(
			'open'        => 0,
			'click'       => 0,
			'unsubscribe' => 0,
			'bounce'      => 0,
			'complaint'   => 0,
		);

		foreach ( (array) $rows as $row ) {
			$stats[ (string) $row['type'] ] = (int) $row['total'];
		}

		return $stats;
	}

	/**
	 * Accepted broadcast recipients and unique engaged recipients per campaign.
	 *
	 * Test sends and jobs the transport never accepted are ignored. Opens and
	 * clicks are counted once per recipient job, so several clicked URLs inside
	 * one email still count as one engaged recipient.
	 *
	 * @param int[] $campaign_ids Campaign IDs to report on.
	 * @return array<int, array{accepted: int, opened: int, clicked: int}> Keyed by
	 *         campaign ID; campaigns without an accepted broadcast are absent.
	 */
	public function campaign_engagement_stats( array $campaign_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $campaign_ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$jobs   = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );
		$in     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "SELECT jobs.campaign_id AS campaign_id,
				COUNT(DISTINCT jobs.id) AS accepted,
				COUNT(DISTINCT CASE WHEN events.type = %s THEN events.job_id END) AS opened,
				COUNT(DISTINCT CASE WHEN events.type = %s THEN events.job_id END) AS clicked
			FROM {$jobs} AS jobs
			LEFT JOIN {$events} AS events
				ON events.job_id = jobs.id AND events.type IN (%s, %s)
			WHERE jobs.campaign_id IN ({$in})
				AND jobs.kind = %s
				AND jobs.status = %s
			GROUP BY jobs.campaign_id";

		$params = array_merge(
			array( 'open', 'click', 'open', 'click' ),
			$ids,
			array( 'campaign', EmailJobRepository::STATUS_ACCEPTED )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted table names only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$stats = array();

		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row['campaign_id'] ] = array(
				'accepted' => (int) $row['accepted'],
				'opened'   => (int) $row['opened'],
				'clicked'  => (int) $row['clicked'],
			);
		}

		return $stats;
	}

	public function campaign_links( int $id ): array {
		global $wpdb;
		$jobs = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT events.url, COUNT(DISTINCT events.job_id) AS recipients
				 FROM {$events} events INNER JOIN {$jobs} jobs ON jobs.id = events.job_id
				 WHERE events.campaign_id = %d AND events.type = 'click' AND jobs.campaign_id = %d
				   AND jobs.kind = 'campaign' AND jobs.status = 'accepted'
				 GROUP BY events.url ORDER BY recipients DESC, events.url ASC LIMIT 20",
				$id, $id
			),
			ARRAY_A
		);
		return array_map( static fn( array $row ): array => array( 'url' => esc_url_raw( $row['url'], array( 'http', 'https' ) ), 'recipients' => (int) $row['recipients'] ), (array) $rows );
	}

	/**
	 * Recent events for a campaign.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_events( int $campaign_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY id DESC LIMIT {$limit}",
				$campaign_id
			),
			ARRAY_A
		);
	}

	/**
	 * HMAC secret derived from WordPress salts.
	 */
	private function secret(): string {
		return wp_salt( 'nowcrm_tracking' );
	}

	/**
	 * One-way IP fingerprint, never the raw address.
	 */
	private function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' === $ip ? '' : hash( 'sha256', $ip . wp_salt( 'nowcrm_ip' ) );
	}
}
