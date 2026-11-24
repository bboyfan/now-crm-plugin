<?php
/**
 * Form entry persistence. Entries are `nowcrm_form_entry` posts whose parent is
 * the form, so every query and detail view is scoped to one form. The submitted
 * field snapshot is a JSON meta value; titles and content never contain PII.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class EntryRepository {

	public const META_DATA    = '_nowcrm_entry_data';
	public const META_EMAIL   = '_nowcrm_entry_email';
	public const META_CONTACT = '_nowcrm_entry_contact';

	/**
	 * Store one submission.
	 *
	 * @param int                  $form_id Parent form.
	 * @param array<string, mixed> $payload email, contact_id, fields, lists, tags, double_opt_in, submitted_at.
	 * @return int|WP_Error Entry post ID.
	 */
	public function create( int $form_id, array $payload ) {
		$form_id = absint( $form_id );

		if ( $form_id <= 0 || ! FormRepository::get( $form_id ) ) {
			return new WP_Error( 'nowcrm_form_not_found', __( '找不到表單。', 'now-crm' ) );
		}

		$json = wp_json_encode( $payload );

		if ( ! is_string( $json ) ) {
			return new WP_Error( 'nowcrm_form_entry_encode', __( '無法儲存送出資料。', 'now-crm' ) );
		}

		$entry_id = wp_insert_post(
			array(
				'post_type'    => FormRepository::ENTRY_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf(
					/* translators: %s: UTC submission time. */
					__( '表單送出 %s', 'now-crm' ),
					gmdate( 'Y-m-d H:i:s' )
				),
				'post_content' => '',
				'post_parent'  => $form_id,
				'post_author'  => 0,
			),
			true
		);

		if ( is_wp_error( $entry_id ) ) {
			return $entry_id;
		}

		update_post_meta( (int) $entry_id, self::META_DATA, wp_slash( $json ) );
		update_post_meta( (int) $entry_id, self::META_EMAIL, wp_slash( sanitize_email( (string) ( $payload['email'] ?? '' ) ) ) );
		update_post_meta( (int) $entry_id, self::META_CONTACT, (string) absint( $payload['contact_id'] ?? 0 ) );

		return (int) $entry_id;
	}

	/**
	 * One entry, only when it belongs to the requested form.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $entry_id, int $form_id ): ?array {
		$post = $entry_id > 0 ? get_post( $entry_id ) : null;

		if (
			! $post
			|| FormRepository::ENTRY_TYPE !== $post->post_type
			|| (int) $post->post_parent !== $form_id
		) {
			return null;
		}

		$data = json_decode( (string) get_post_meta( $entry_id, self::META_DATA, true ), true );

		return array(
			'id'         => (int) $entry_id,
			'form_id'    => $form_id,
			'created_at' => $this->date_gmt( $post ),
			'email'      => (string) get_post_meta( $entry_id, self::META_EMAIL, true ),
			'contact_id' => absint( get_post_meta( $entry_id, self::META_CONTACT, true ) ),
			'data'       => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * One page of entries for a form.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function entries( int $form_id, int $page = 1, int $per_page = 20 ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );

		$posts = get_posts(
			array(
				'post_type'        => FormRepository::ENTRY_TYPE,
				'post_parent'      => $form_id,
				'post_status'      => array( 'publish', 'private' ),
				'numberposts'      => $per_page,
				'offset'           => ( $page - 1 ) * $per_page,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$items[] = array(
				'id'         => (int) $post->ID,
				'created_at' => $this->date_gmt( $post ),
				'email'      => (string) get_post_meta( $post->ID, self::META_EMAIL, true ),
				'contact_id' => absint( get_post_meta( $post->ID, self::META_CONTACT, true ) ),
			);
		}

		$total = $this->count( $form_id );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Number of entries for one form.
	 */
	public function count( int $form_id ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => FormRepository::ENTRY_TYPE,
				'post_parent'    => $form_id,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * GMT date string for a post, with a local fallback.
	 */
	private function date_gmt( \WP_Post $post ): string {
		$gmt = (string) $post->post_date_gmt;

		if ( '' === $gmt || str_starts_with( $gmt, '0000-' ) ) {
			$gmt = (string) get_gmt_from_date( $post->post_date );
		}

		return $gmt;
	}
}
