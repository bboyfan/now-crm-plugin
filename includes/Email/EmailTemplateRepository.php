<?php
/**
 * Email template rows. Campaigns use the block editor instead; this table
 * holds reusable bodies such as the abandoned-cart reminder.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class EmailTemplateRepository {

	/**
	 * Fetch one template.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'email_templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * First template of a kind.
	 *
	 * @return array<string, mixed>|null
	 */
	public function by_kind( string $kind ): ?array {
		global $wpdb;

		$table = Schema::table( 'email_templates' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s ORDER BY id ASC LIMIT 1", sanitize_key( $kind ) ),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Create a template.
	 *
	 * @param array<string, mixed> $data kind, name, subject, preheader, mode, content.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			Schema::table( 'email_templates' ),
			array(
				'kind'       => substr( sanitize_key( (string) ( $data['kind'] ?? 'custom' ) ), 0, 30 ),
				'name'       => substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 191 ),
				'subject'    => substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 255 ),
				'preheader'  => substr( sanitize_text_field( (string) ( $data['preheader'] ?? '' ) ), 0, 255 ),
				'mode'       => 'blocks' === ( $data['mode'] ?? 'html' ) ? 'blocks' : 'html',
				'content'    => (string) ( $data['content'] ?? '' ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'nowcrm_template_db', $wpdb->last_error ?: 'template_insert_failed' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a template.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$fields = array();

		foreach ( array( 'name', 'subject', 'preheader' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = substr( sanitize_text_field( (string) $data[ $key ] ), 0, 255 );
			}
		}

		if ( array_key_exists( 'mode', $data ) ) {
			$fields['mode'] = 'blocks' === $data['mode'] ? 'blocks' : 'html';
		}

		if ( array_key_exists( 'content', $data ) ) {
			$fields['content'] = (string) $data['content'];
		}

		if ( ! $fields ) {
			return false;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update( Schema::table( 'email_templates' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Cast a row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		$row['id'] = (int) $row['id'];

		return $row;
	}
}
