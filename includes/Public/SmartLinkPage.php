<?php
/**
 * Public smart link route: `?nowcrm_smart=<slug>`.
 *
 * Active links answer with a 307 redirect to the stored target. A paused or
 * missing link answers 404. A signed contact token applies the configured
 * memberships and counts the click as a contact click; without a valid token
 * the visitor still redirects and the click counts as anonymous.
 *
 * @package NowCRM
 */

namespace NowCRM\Public;

use NowCRM\Contacts\ContactRepository;
use NowCRM\SmartLinks\ContactToken;
use NowCRM\SmartLinks\SmartLinkRepository;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class SmartLinkPage {

	/**
	 * Hooks. Loaded from the smart link module.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Short URL carrying an optional signed contact token.
	 */
	public static function url( string $slug, string $token = '' ): string {
		return SmartLinkRepository::short_url( $slug, $token );
	}

	/**
	 * Intercept the public route before the theme renders.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only route; the token is the capability.
		if ( ! isset( $_GET[ SmartLinkRepository::QUERY_VAR ] ) || ! is_string( $_GET[ SmartLinkRepository::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only route.
		$slug = sanitize_title( wp_unslash( (string) $_GET[ SmartLinkRepository::QUERY_VAR ] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only route; the token is the capability.
		$token = isset( $_GET[ SmartLinkRepository::TOKEN_QUERY_VAR ] ) && is_string( $_GET[ SmartLinkRepository::TOKEN_QUERY_VAR ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ SmartLinkRepository::TOKEN_QUERY_VAR ] ) )
			: '';

		$result = $this->handle( $slug, $token );

		nocache_headers();

		if ( 307 !== $result['status'] || '' === (string) ( $result['location'] ?? '' ) ) {
			status_header( 404 );
			wp_die(
				esc_html__( '這個智慧連結不存在或已暫停。', 'now-crm' ),
				'',
				array( 'response' => 404 )
			);
		}

		wp_redirect( (string) $result['location'], 307 );
		exit;
	}

	/**
	 * Resolve, apply actions, count, and return the response without emitting it.
	 *
	 * @return array{status: int, location?: string, link_id?: int, contact_id?: int, counts?: array{all: int, contact: int}}
	 */
	public function handle( string $slug, string $raw_token = '' ): array {
		$repo = new SmartLinkRepository();
		$link = $repo->find_by_slug( $slug );

		if ( ! $link || SmartLinkRepository::STATUS_ACTIVE !== $link['status'] ) {
			return array( 'status' => 404 );
		}

		$target = SmartLinkRepository::sanitize_target( (string) $link['target_url'] );

		if ( null === $target ) {
			return array( 'status' => 404 );
		}

		$contact_id = 0;

		if ( '' !== $raw_token ) {
			$candidate = ContactToken::validate( $raw_token );

			if ( $candidate > 0 && ( new ContactRepository() )->get( $candidate ) ) {
				$contact_id = $candidate;
			}
		}

		if ( $contact_id > 0 ) {
			$this->apply_actions( $link, $contact_id );
		}

		$counts = $repo->record_click( (int) $link['id'], $contact_id );

		return array(
			'status'     => 307,
			'location'   => $target,
			'link_id'    => (int) $link['id'],
			'contact_id' => $contact_id,
			'counts'     => $counts,
		);
	}

	/**
	 * Apply and remove memberships. Terms that vanished after save are skipped
	 * so a click never fails because of drift; the redirect still happens.
	 *
	 * @param array<string, mixed> $link Link row.
	 */
	private function apply_actions( array $link, int $contact_id ): void {
		$terms = new TermRepository();

		$this->apply_group( $terms, $contact_id, (array) $link['apply_lists'], TermRepository::TYPE_LIST, true );
		$this->apply_group( $terms, $contact_id, (array) $link['apply_tags'], TermRepository::TYPE_TAG, true );
		$this->apply_group( $terms, $contact_id, (array) $link['remove_lists'], TermRepository::TYPE_LIST, false );
		$this->apply_group( $terms, $contact_id, (array) $link['remove_tags'], TermRepository::TYPE_TAG, false );
	}

	/**
	 * Apply one group once per term. Attach and detach are idempotent, so a
	 * repeated click cannot duplicate a membership.
	 *
	 * @param int[] $term_ids Term IDs.
	 */
	private function apply_group( TermRepository $terms, int $contact_id, array $term_ids, string $type, bool $attach ): void {
		foreach ( array_unique( array_map( 'absint', $term_ids ) ) as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}

			$term = $terms->get( (int) $term_id );

			if ( ! $term || (string) $term['type'] !== $type ) {
				continue;
			}

			if ( $attach ) {
				$terms->attach( $contact_id, (int) $term_id );
			} else {
				$terms->detach( $contact_id, (int) $term_id );
			}
		}
	}
}
