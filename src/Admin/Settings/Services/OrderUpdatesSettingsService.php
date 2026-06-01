<?php
/**
 * Shared settings reader.
 *
 * Field definitions live with their respective section services
 * (`GeneralSettingsService`, `MembersSettingsService`, etc.). This class
 * is the read-side API the rest of the plugin uses to look up current
 * option values without caring which section persists them.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderUpdatesSettingsService {

	/**
	 * @return array{
	 *     enable_assignee:bool,
	 *     enable_color:bool,
	 *     enable_internal_note:bool,
	 *     enable_customer_note:bool,
	 *     enable_solved_state:bool,
	 *     allow_deletion:bool,
	 *     enable_customer_rating:bool,
	 *     enable_customer_rating_comment:bool,
	 *     enable_customer_rating_email:bool,
	 *     enable_customer_rating_followup_email:bool,
	 *     show_assignee_to_customers:bool,
	 *     allow_member_note_delete:bool,
	 *     note_edit_window_minutes:int
	 * }
	 */
	public function get_feature_settings(): array {
		return array(
			'enable_assignee'                       => $this->bool_option( 'order_updates_for_woo_enable_assignee' ),
			'enable_color'                          => $this->bool_option( 'order_updates_for_woo_enable_color' ),
			'enable_internal_note'                  => $this->bool_option( 'order_updates_for_woo_enable_internal_note', 'yes', 'order_updates_for_woo_enable_note' ),
			'enable_customer_note'                  => $this->bool_option( 'order_updates_for_woo_enable_customer_note' ),
			'enable_solved_state'                   => $this->bool_option( 'order_updates_for_woo_enable_solved_state' ),
			'allow_deletion'                        => $this->bool_option( 'order_updates_for_woo_allow_deletion', 'no' ),
			'enable_customer_rating'                => $this->bool_option( 'order_updates_for_woo_enable_customer_rating', 'yes' ),
			'enable_customer_rating_comment'        => $this->bool_option( 'order_updates_for_woo_enable_customer_rating_comment', 'yes' ),
			'enable_customer_rating_email'          => $this->bool_option( 'order_updates_for_woo_enable_customer_rating_email', 'yes' ),
			'enable_customer_rating_followup_email' => $this->bool_option( 'order_updates_for_woo_enable_customer_rating_followup_email', 'yes' ),
			'show_assignee_to_customers'            => $this->bool_option( 'order_updates_for_woo_show_assignee_to_customers', 'no' ),
			'notify_admin_on_customer_create'       => $this->notify_admin_on_customer_create(),
			'allow_member_note_delete'              => $this->bool_option( 'order_updates_for_woo_allow_member_note_delete', 'no' ),
			'note_edit_window_minutes'              => $this->get_note_edit_window_minutes(),
		);
	}

	/**
	 * Whether the site admin (matched by `admin_email`) should be emailed
	 * when a customer opens a new update. Default off — the assigned staff
	 * member is the canonical recipient. Site owners who want a "new customer
	 * thread" heads-up can opt in via Settings → Order Updates → General.
	 */
	public function notify_admin_on_customer_create(): bool {
		return $this->bool_option( Constants::NOTIFY_ADMIN_ON_CUSTOMER_CREATE_OPTION, 'no' );
	}

	/**
	 * Whether the site admin should be emailed when a customer leaves a low
	 * rating (1-3 stars). Default ON — detractor ratings are rare and high-
	 * signal; admin usually wants to know so they can escalate. Can be turned
	 * off in Settings → Order Updates → General if it becomes noisy.
	 */
	public function notify_admin_on_detractor_rating(): bool {
		return $this->bool_option( Constants::NOTIFY_ADMIN_ON_DETRACTOR_RATING_OPTION, 'yes' );
	}

	/**
	 * Admin overrides for the two note-panel backgrounds on the order edit
	 * screen. Each value is a sanitized string; empty means "use the CSS
	 * default." Caller is responsible for escaping before emitting into
	 * inline CSS.
	 *
	 * @return array{
	 *     internal_bg:string,
	 *     internal_image:string,
	 *     customer_bg:string,
	 *     customer_image:string
	 * }
	 */
	public function note_panel_appearance(): array {
		return array(
			'internal_bg'    => trim( (string) get_option( Constants::NOTE_PANEL_INTERNAL_BG_OPTION, '' ) ),
			'internal_image' => trim( (string) get_option( Constants::NOTE_PANEL_INTERNAL_IMG_OPTION, '' ) ),
			'customer_bg'    => trim( (string) get_option( Constants::NOTE_PANEL_CUSTOMER_BG_OPTION, '' ) ),
			'customer_image' => trim( (string) get_option( Constants::NOTE_PANEL_CUSTOMER_IMG_OPTION, '' ) ),
		);
	}

	public function allow_member_note_delete(): bool {
		return $this->bool_option( 'order_updates_for_woo_allow_member_note_delete', 'no' );
	}

	/**
	 * Master switch — does the admin let authors edit their own notes at all?
	 * Default off. Even when on, the per-thread "latest only" rule still
	 * applies (enforced in NoteActionPolicy), so older notes lock once a
	 * follow-up arrives.
	 */
	public function allow_note_edit(): bool {
		return $this->bool_option( Constants::ALLOW_NOTE_EDIT_OPTION, 'no' );
	}

	/**
	 * Master switch — does the admin let authors delete their own notes?
	 * Default off. Latest-only applies here too. The legacy
	 * "allow_member_note_delete" toggle is kept as a fine-grained sub-switch
	 * scoping delete to internal notes only.
	 */
	public function allow_note_delete(): bool {
		return $this->bool_option( Constants::ALLOW_NOTE_DELETE_OPTION, 'no' );
	}

	/**
	 * Does the admin let customers open brand-new update threads from their
	 * order page? Off by default so the inbox doesn't surprise the store.
	 * Replies to existing updates are unaffected — those are part of an
	 * already-opened thread and stay enabled either way.
	 */
	public function allow_customer_create_update(): bool {
		return $this->bool_option( Constants::ALLOW_CUSTOMER_CREATE_UPDATE_OPTION, 'no' );
	}

	/**
	 * Admin-managed update statuses (label + color). Falls back to the
	 * five seed statuses (Urgent/Warning/Notice/Success/Neutral) on a
	 * fresh install so the form dropdown is never empty.
	 *
	 * @return array<int, array{key:string, label:string, color:string}>
	 */
	public function get_statuses(): array {
		$stored = get_option( Constants::STATUSES_OPTION, null );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return Constants::STATUS_SEED_DEFAULTS;
		}

		$clean = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key   = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$color = isset( $row['color'] ) ? $this->sanitize_hex( (string) $row['color'] ) : '';

			if ( '' === $key || '' === $label || '' === $color ) {
				continue;
			}

			$clean[] = array( 'key' => $key, 'label' => $label, 'color' => $color );
		}

		return ! empty( $clean ) ? $clean : Constants::STATUS_SEED_DEFAULTS;
	}

	/**
	 * Resolve the status that should be applied to a customer-initiated
	 * update. Falls back to the first status in the admin's list when the
	 * configured default has been deleted, or to the first seed when the
	 * list itself is empty — customers never see "no status assigned."
	 *
	 * @return array{key:string, label:string, color:string}
	 */
	public function default_customer_status(): array {
		$statuses = $this->get_statuses();
		$key      = (string) get_option( Constants::DEFAULT_CUSTOMER_STATUS_OPTION, Constants::DEFAULT_CUSTOMER_STATUS_SEED_KEY );

		if ( '' !== $key ) {
			foreach ( $statuses as $status ) {
				if ( $status['key'] === $key ) {
					return $status;
				}
			}
		}

		return $statuses[0];
	}

	/**
	 * Find a status by its color hex — used at render time to recover the
	 * status label for an update whose row only carries the color. Returns
	 * null when no status matches (admin removed the status after updates
	 * were already created with that color).
	 *
	 * @return array{key:string, label:string, color:string}|null
	 */
	public function find_status_by_color( string $color ): ?array {
		$color = $this->sanitize_hex( $color );

		if ( '' === $color ) {
			return null;
		}

		foreach ( $this->get_statuses() as $status ) {
			if ( strcasecmp( $status['color'], $color ) === 0 ) {
				return $status;
			}
		}

		return null;
	}

	private function sanitize_hex( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( '#' !== substr( $value, 0, 1 ) ) {
			$value = '#' . $value;
		}

		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : '';
	}

	public function get_note_edit_window_minutes(): int {
		$minutes = absint( get_option( 'order_updates_for_woo_note_edit_window_minutes', 1 ) );

		if ( $minutes < 1 ) {
			return 1;
		}

		return min( 1440, $minutes );
	}

	/**
	 * Read a 'yes'/'no' option as a bool, with optional fallback to a
	 * legacy option name (used when an option was renamed and we still
	 * want to honour the previously-saved value during transition).
	 */
	private function bool_option( string $name, string $default = 'yes', ?string $legacy_name = null ): bool {
		$value = get_option( $name, null );

		if ( null === $value && null !== $legacy_name ) {
			$value = get_option( $legacy_name, $default );
		}

		if ( null === $value ) {
			$value = $default;
		}

		return 'yes' === $value;
	}
}
