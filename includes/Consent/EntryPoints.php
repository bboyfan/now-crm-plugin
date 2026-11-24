<?php
/**
 * Explicit double opt-in entry points for native forms and CSV imports.
 *
 * Both producers attach lists themselves and never send mail; this subscriber
 * only queues a confirmation when double opt-in is actually configured for the
 * involved lists. A contact created subscribed by one of these flows is moved
 * to `transactional` first, so the address cannot receive marketing before
 * the signed confirmation. Existing contacts keep their status: mere list
 * attachment never triggers a send.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class EntryPoints {

	private ContactRepository $contacts;
	private TermRepository $terms;
	private DoubleOptin $doi;

	public function __construct(
		?ContactRepository $contacts = null,
		?TermRepository $terms = null,
		?DoubleOptin $doi = null
	) {
		$this->contacts = $contacts ?: new ContactRepository();
		$this->terms    = $terms ?: new TermRepository();
		$this->doi      = $doi ?: new DoubleOptin( $this->contacts, $this->terms );
	}

	/**
	 * Hooks. Registered from `Plugin::modules()`.
	 */
	public function register(): void {
		add_action( 'nowcrm_form_submitted', array( $this, 'on_form_submitted' ), 10, 4 );
		add_action( 'nowcrm_csv_contact_imported', array( $this, 'on_csv_imported' ), 10, 3 );
		add_action( 'nowcrm_woo_order_synced', array( $this, 'on_woo_order_synced' ), 10, 4 );
	}

	/**
	 * Woo order handoff. Only a newly created contact with an explicit saved
	 * yes can start confirmation. An existing contact is never downgraded or
	 * re-invited, even when the order repeats.
	 *
	 * @param int[]     $lists  Lists attached by the Woo sync.
	 * @param bool|null $optin  Normalized checkout consent.
	 */
	public function on_woo_order_synced( int $contact_id, bool $created_contact, array $lists, ?bool $optin ): void {
		if ( ! $created_contact || true !== $optin ) {
			return;
		}

		$lists = $this->clean_ids( $lists );

		if ( ! Config::is_double_optin_required( $lists ) ) {
			return;
		}

		$this->start_pending( $contact_id, $lists, 'woo-double-opt-in', false );
	}

	/**
	 * Native form handoff. The form owns its `double_opt_in` flag; the consent
	 * layer additionally requires configured DOI for the submitted lists.
	 *
	 * @param array<string, mixed> $payload Email, lists, tags, DOI flag, snapshot.
	 */
	public function on_form_submitted( int $entry_id, int $form_id, int $contact_id, array $payload ): void {
		if ( empty( $payload['double_opt_in'] ) || empty( $payload['contact_created'] ) ) {
			return;
		}

		$lists = $this->clean_ids( (array) ( $payload['lists'] ?? array() ) );

		if ( ! Config::is_double_optin_required( $lists ) ) {
			return;
		}

		$this->start_pending( $contact_id, $lists, 'form-double-opt-in' );
	}

	/**
	 * CSV import handoff. Fires only for newly inserted contacts.
	 *
	 * @param array<string, mixed> $values Mapped raw row values.
	 */
	public function on_csv_imported( int $contact_id, string $email, array $values ): void {
		unset( $email, $values );

		$lists = $this->contact_lists( $contact_id );

		if ( ! Config::is_double_optin_required( $lists ) ) {
			return;
		}

		$this->start_pending( $contact_id, $lists, 'csv-double-opt-in' );
	}

	/**
	 * Move a fresh subscribed contact to `transactional` and queue the
	 * confirmation. Anything else (suppressed, already pending, already
	 * subscribed by an earlier consent) is left for `DoubleOptin::send()`.
	 *
	 * @param int[] $lists
	 */
	private function start_pending( int $contact_id, array $lists, string $source, bool $allow_demote = true ): void {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact || Status::is_suppressed( (string) $contact['status'] ) ) {
			return;
		}

		if ( Status::SUBSCRIBED === (string) $contact['status'] ) {
			if ( ! $allow_demote ) {
				return;
			}

			$demoted = $this->contacts->set_status( $contact_id, Status::TRANSACTIONAL, $source );

			if ( is_wp_error( $demoted ) ) {
				return;
			}
		}

		$this->doi->send( $contact_id, $lists );
	}

	/**
	 * List IDs currently attached to a contact.
	 *
	 * @return int[]
	 */
	private function contact_lists( int $contact_id ): array {
		$lists = array();

		foreach ( $this->terms->for_contact( $contact_id ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$lists[] = (int) $term['id'];
			}
		}

		return $lists;
	}

	/**
	 * @param mixed[] $ids
	 * @return int[]
	 */
	private function clean_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
