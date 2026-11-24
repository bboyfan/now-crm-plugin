<?php
/**
 * Form persistence. Forms are `nowcrm_form` posts; settings live in post meta.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class FormRepository {

	public const POST_TYPE  = 'nowcrm_form';
	public const ENTRY_TYPE = 'nowcrm_form_entry';

	public const META_FIELDS        = '_nowcrm_form_fields';
	public const META_LISTS         = '_nowcrm_form_lists';
	public const META_TAGS          = '_nowcrm_form_tags';
	public const META_SUBMIT_LABEL  = '_nowcrm_form_submit_label';
	public const META_SUCCESS       = '_nowcrm_form_success';
	public const META_DOUBLE_OPT_IN = '_nowcrm_form_double_opt_in';

	public const DEFAULT_SUBMIT_LABEL = '送出';
	public const DEFAULT_SUCCESS      = '感謝您的填寫，我們會盡快與您聯絡。';

	/**
	 * Register both private post types. No rewrite rules: the public form is a
	 * shortcode and submissions go through admin-post.php.
	 */
	public static function register_post_types(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( '表單', 'now-crm' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);

		register_post_type(
			self::ENTRY_TYPE,
			array(
				'label'              => __( '表單送出紀錄', 'now-crm' ),
				'public'             => false,
				'show_ui'            => false,
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'rewrite'            => false,
				'query_var'          => false,
				'supports'           => array( 'title' ),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * One form with its ordered fields and delivery settings.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		$fields = Fields::normalize_definitions( self::decode_array( $post->ID, self::META_FIELDS ) );
		$label  = (string) get_post_meta( $post->ID, self::META_SUBMIT_LABEL, true );
		$notice = (string) get_post_meta( $post->ID, self::META_SUCCESS, true );

		return array(
			'id'              => (int) $post->ID,
			'status'          => (string) $post->post_status,
			'title'           => (string) get_the_title( $post ),
			'submit_label'    => '' !== $label ? $label : self::DEFAULT_SUBMIT_LABEL,
			'success_message' => '' !== trim( $notice ) ? $notice : self::DEFAULT_SUCCESS,
			'fields'          => $fields,
			'email_key'       => Fields::email_key( $fields ),
			'lists'           => self::decode_ids( $post->ID, self::META_LISTS ),
			'tags'            => self::decode_ids( $post->ID, self::META_TAGS ),
			'double_opt_in'   => (bool) get_post_meta( $post->ID, self::META_DOUBLE_OPT_IN, true ),
			'created_at'      => (string) $post->post_date_gmt,
			'updated_at'      => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Every form, alphabetical.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$form = self::get( (int) $post->ID );

			if ( $form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Create a form.
	 *
	 * @param array<string, mixed> $data title, fields, submit_label, success_message, lists, tags, double_opt_in.
	 * @return int|WP_Error Form post ID.
	 */
	public static function create( array $data ) {
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

		if ( '' === $title ) {
			return new WP_Error( 'nowcrm_form_title', __( '請輸入表單名稱。', 'now-crm' ) );
		}

		$config = self::prepare_config( $data );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => 0,
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::write_meta( (int) $id, $config );

		return (int) $id;
	}

	/**
	 * Update form settings. Missing keys keep the stored value.
	 *
	 * @param array<string, mixed> $data Same keys as create().
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_form_not_found', __( '找不到表單。', 'now-crm' ) );
		}

		$title = array_key_exists( 'title', $data )
			? sanitize_text_field( (string) $data['title'] )
			: (string) $existing['title'];

		if ( '' === $title ) {
			return new WP_Error( 'nowcrm_form_title', __( '請輸入表單名稱。', 'now-crm' ) );
		}

		$merged = array(
			'fields'          => array_key_exists( 'fields', $data ) ? $data['fields'] : $existing['fields'],
			'lists'           => array_key_exists( 'lists', $data ) ? $data['lists'] : $existing['lists'],
			'tags'            => array_key_exists( 'tags', $data ) ? $data['tags'] : $existing['tags'],
			'submit_label'    => array_key_exists( 'submit_label', $data ) ? $data['submit_label'] : $existing['submit_label'],
			'success_message' => array_key_exists( 'success_message', $data ) ? $data['success_message'] : $existing['success_message'],
			'double_opt_in'   => array_key_exists( 'double_opt_in', $data ) ? $data['double_opt_in'] : $existing['double_opt_in'],
		);

		$config = self::prepare_config( $merged );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $title,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		self::write_meta( $id, $config );

		return true;
	}

	/**
	 * Delete a form and every entry that belongs to it.
	 */
	public static function delete( int $id ): bool {
		if ( ! self::get( $id ) ) {
			return false;
		}

		$entries = get_posts(
			array(
				'post_type'   => self::ENTRY_TYPE,
				'post_parent' => $id,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $entries as $entry_id ) {
			wp_delete_post( (int) $entry_id, true );
		}

		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Validate and prepare stored settings.
	 *
	 * @param array<string, mixed> $data Raw settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function prepare_config( array $data ) {
		$fields = Fields::normalize_definitions( (array) ( $data['fields'] ?? array() ) );

		if ( array() === $fields ) {
			return new WP_Error( 'nowcrm_form_no_fields', __( '請至少新增一個欄位。', 'now-crm' ) );
		}

		if ( 0 === Fields::email_count( $fields ) ) {
			return new WP_Error( 'nowcrm_form_needs_email', __( '表單必須包含一個 Email 欄位。', 'now-crm' ) );
		}

		if ( Fields::email_count( $fields ) > 1 ) {
			return new WP_Error( 'nowcrm_form_needs_email', __( '表單只能有一個 Email 欄位。', 'now-crm' ) );
		}

		foreach ( $fields as $index => $field ) {
			if ( Fields::EMAIL === $field['type'] ) {
				$fields[ $index ]['required'] = true;
				continue;
			}

			if ( in_array( $field['type'], array( Fields::SELECT, Fields::RADIO, Fields::CHECKBOX ), true ) && array() === $field['options'] ) {
				return new WP_Error(
					'nowcrm_form_options',
					sprintf(
						/* translators: %s: field label. */
						__( '欄位「%s」至少需要一個選項。', 'now-crm' ),
						$field['label']
					)
				);
			}
		}

		$submit = sanitize_text_field( (string) ( $data['submit_label'] ?? '' ) );
		$notice = trim( wp_kses_post( (string) ( $data['success_message'] ?? '' ) ) );

		return array(
			'fields'          => $fields,
			'lists'           => self::sanitize_term_ids( (array) ( $data['lists'] ?? array() ), TermRepository::TYPE_LIST ),
			'tags'            => self::sanitize_term_ids( (array) ( $data['tags'] ?? array() ), TermRepository::TYPE_TAG ),
			'submit_label'    => '' !== $submit ? $submit : self::DEFAULT_SUBMIT_LABEL,
			'success_message' => '' !== $notice ? $notice : self::DEFAULT_SUCCESS,
			'double_opt_in'   => ! empty( $data['double_opt_in'] ),
		);
	}

	/**
	 * Persist prepared settings.
	 *
	 * @param array<string, mixed> $config Prepared settings.
	 */
	private static function write_meta( int $id, array $config ): void {
		update_post_meta( $id, self::META_FIELDS, wp_slash( (string) wp_json_encode( $config['fields'] ) ) );
		update_post_meta( $id, self::META_LISTS, wp_slash( (string) wp_json_encode( $config['lists'] ) ) );
		update_post_meta( $id, self::META_TAGS, wp_slash( (string) wp_json_encode( $config['tags'] ) ) );
		update_post_meta( $id, self::META_SUBMIT_LABEL, wp_slash( (string) $config['submit_label'] ) );
		update_post_meta( $id, self::META_SUCCESS, wp_slash( (string) $config['success_message'] ) );
		update_post_meta( $id, self::META_DOUBLE_OPT_IN, $config['double_opt_in'] ? '1' : '' );
	}

	/**
	 * Keep only existing term IDs of the expected type.
	 *
	 * @param array<int, mixed> $ids  Candidate IDs.
	 * @param string            $type list or tag.
	 * @return int[]
	 */
	private static function sanitize_term_ids( array $ids, string $type ): array {
		$terms  = new TermRepository();
		$clean  = array();

		foreach ( $ids as $candidate ) {
			$id = absint( $candidate );

			if ( $id <= 0 || in_array( $id, $clean, true ) ) {
				continue;
			}

			$term = $terms->get( $id );

			if ( $term && $type === ( $term['type'] ?? '' ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Decode a JSON array meta value.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function decode_array( int $id, string $key ): array {
		$raw = get_post_meta( $id, $key, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Decode a JSON array of positive term IDs.
	 *
	 * @return int[]
	 */
	private static function decode_ids( int $id, string $key ): array {
		$ids = self::decode_array( $id, $key );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}
