<?php
/**
 * Shared validation service.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Validation;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use WP_Error;

final class Validator {
	public function __construct(private ?TeamRosterService $team_roster = null) {}

	private function team_roster(): TeamRosterService {
		if ( ! $this->team_roster instanceof TeamRosterService ) {
			$this->team_roster = new TeamRosterService();
		}

		return $this->team_roster;
	}

	/**
	 * Filter and validate a list of mentioned user IDs against the configured team roster.
	 *
	 * @param array<int|string> $raw_ids
	 * @return int[]
	 */
	public function sanitize_mentioned_user_ids( $raw_ids ): array {
		if ( ! is_array( $raw_ids ) ) {
			return array();
		}

		$candidate_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

		if ( empty( $candidate_ids ) ) {
			return array();
		}

		$allowed_roles = $this->team_roster()->get_role_slugs();
		$valid         = array();

		foreach ( $candidate_ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				continue;
			}

			$user_roles = (array) $user->roles;

			if ( array_intersect( $user_roles, $allowed_roles ) || user_can( $user, 'manage_woocommerce' ) ) {
				$valid[] = $user_id;
			}
		}

		return $valid;
	}
	public function validate_update_payload(array $payload): array|WP_Error {
		$sanitized_payload = [];

		foreach ($this->get_update_rules() as $field_name => $field_rules) {
			$value = $payload[$field_name] ?? null;
			$sanitized_value = $this->sanitize_field($field_name, $value, $field_rules);

			if (is_wp_error($sanitized_value)) {
				return $sanitized_value;
			}

			$sanitized_payload[$field_name] = $sanitized_value;
		}

		return $sanitized_payload;
	}

	public function validate_attachment_payload(array $payload): array|WP_Error {
		$update_id = absint($payload['update_id'] ?? 0);
		$note_id   = absint($payload['note_id'] ?? 0);
		$note_type = sanitize_key((string) ($payload['note_type'] ?? ''));
		$file      = $payload['file'] ?? null;

		if (! $update_id) {
			return new WP_Error(
				'order_updates_for_woo_invalid_update',
				__('The selected update could not be found.', 'order-updates-for-woo'),
				[ 'status' => 400 ]
			);
		}

		if (! $note_id) {
			return new WP_Error(
				'order_updates_for_woo_attachment_invalid_context',
				__('Missing order/update/note reference.', 'order-updates-for-woo'),
				[ 'status' => 400 ]
			);
		}

		if (! in_array($note_type, [ Constants::NOTE_TYPE_INTERNAL, Constants::NOTE_TYPE_CUSTOMER ], true)) {
			return new WP_Error(
				'order_updates_for_woo_attachment_invalid_note_type',
				__('Invalid note type.', 'order-updates-for-woo'),
				[ 'status' => 400 ]
			);
		}

		if (! is_array($file)) {
			return new WP_Error(
				'order_updates_for_woo_missing_file',
				__('No file uploaded.', 'order-updates-for-woo'),
				[ 'status' => 400 ]
			);
		}

		return [
			'update_id' => $update_id,
			'note_id'   => $note_id,
			'note_type' => $note_type,
			'file'      => $file,
		];
	}

	private function get_update_rules(): array {
		$rules = require __DIR__ . '/validationRules.php';

		return is_array($rules) ? $rules : [];
	}

	public function sanitize_note(string $raw, int $max_length = 500, string $field_label = ''): string|WP_Error {
		$sanitized = wp_kses_post( wp_unslash( $raw ) );
		$sanitized = self::trim_message( $sanitized );

		if ($max_length && mb_strlen($sanitized) > $max_length) {
			return new WP_Error(
				'order_updates_for_woo_note_too_long',
				/* translators: 1: field label, 2: maximum character count. */
				sprintf( __('%1$s must be %2$d characters or less.', 'order-updates-for-woo'), $field_label, $max_length ),
				[ 'status' => 400 ]
			);
		}

		return $sanitized;
	}

	/**
	 * Strip trailing whitespace, blank lines, and the empty HTML artifacts
	 * a textarea / contenteditable can leave at the end of a message — plain
	 * `trim()` only catches ASCII whitespace, but users routinely end up
	 * with `<br>`, `<br />`, `&nbsp;`, NBSP ( ), or empty `<p>`/`<div>`
	 * tags after pressing Enter a few times before submit. Also normalises
	 * leading whitespace the same way for symmetry.
	 */
	private static function trim_message(string $value): string {
		// Trailing empty containers + breaks + nbsp + whitespace, repeated.
		$pattern = '/(' .
			'\s+|' .                                        // ASCII / unicode whitespace
			'&nbsp;|' .                                     // entity NBSP
			'\xC2\xA0|' .                                   // raw UTF-8 NBSP
			'<br\s*\/?>|' .                                 // <br>, <br/>, <br />
			'<(p|div)[^>]*>\s*(&nbsp;|\xC2\xA0|\s)*<\/\1>'  // empty <p>/<div> with optional nbsp/whitespace
			. ')+$/iu';

		$value = preg_replace( $pattern, '', $value ) ?? $value;

		// Same pattern from the start.
		$lead_pattern = '/^(' .
			'\s+|&nbsp;|\xC2\xA0|<br\s*\/?>|<(p|div)[^>]*>\s*(&nbsp;|\xC2\xA0|\s)*<\/\2>'
			. ')+/iu';

		return preg_replace( $lead_pattern, '', $value ) ?? $value;
	}

	private function sanitize_field(string $field_name, $value, array $field_rules): string|int|float|WP_Error {
		$field_type = $field_rules['type'];
		$is_required = ! empty($field_rules['required']);
		$field_label = $field_rules['label'] ?? $field_name;
		$max_length = isset($field_rules['max_length']) ? (int) $field_rules['max_length'] : 0;

		switch ($field_type) {
			case 'order_id':
				$order_id = absint($value);

				if (! $order_id || ! wc_get_order($order_id)) {
					return new WP_Error(
						'order_updates_for_woo_invalid_order',
						__('A valid order is required.', 'order-updates-for-woo'),
						[ 'status' => 400 ]
					);
				}

				return $order_id;

			case 'text':
				$sanitized_text = sanitize_text_field(wp_unslash((string) $value));

				if ($is_required && '' === $sanitized_text) {
					return new WP_Error(
						'order_updates_for_woo_missing_field',
						/* translators: %s: field label. */
						sprintf( __('%s is required.', 'order-updates-for-woo'), $field_label ),
						[ 'status' => 400 ]
					);
				}

				if ($max_length && mb_strlen($sanitized_text) > $max_length) {
					return new WP_Error(
						'order_updates_for_woo_field_too_long',
						/* translators: 1: field label, 2: maximum character count. */
						sprintf( __('%1$s must be %2$d characters or less.', 'order-updates-for-woo'), $field_label, $max_length ),
						[ 'status' => 400 ]
					);
				}

				return $sanitized_text;

			case 'email':
				$email = sanitize_email(wp_unslash((string) $value));

				if ($is_required && '' === $email) {
					return new WP_Error(
						'order_updates_for_woo_missing_field',
						/* translators: %s: field label. */
						sprintf( __('%s is required.', 'order-updates-for-woo'), $field_label ),
						[ 'status' => 400 ]
					);
				}

				if ('' === $email) {
					return '';
				}

				if (! is_email($email)) {
					return new WP_Error(
						'order_updates_for_woo_invalid_email',
						/* translators: %s: field label. */
						sprintf( __('%s must be a valid email address.', 'order-updates-for-woo'), $field_label ),
						[ 'status' => 400 ]
					);
				}

				return $email;

			case 'number':
				$number = is_scalar($value) ? trim(wp_unslash((string) $value)) : '';

				if ($is_required && '' === $number) {
					return new WP_Error(
						'order_updates_for_woo_missing_field',
						/* translators: %s: field label. */
						sprintf( __('%s is required.', 'order-updates-for-woo'), $field_label ),
						[ 'status' => 400 ]
					);
				}

				if ('' === $number) {
					return 0;
				}

				if (! is_numeric($number)) {
					return new WP_Error(
						'order_updates_for_woo_invalid_number',
						/* translators: %s: field label. */
						sprintf( __('%s must be a valid number.', 'order-updates-for-woo'), $field_label ),
						[ 'status' => 400 ]
					);
				}

				return str_contains($number, '.') ? (float) $number : (int) $number;

			case 'plain_text_note':
				return $this->sanitize_note((string) $value, $max_length, $field_label);

			case 'bool':
				return rest_sanitize_boolean($value) ? 1 : 0;

			case 'color':
				$color = strtoupper(trim(wp_unslash((string) $value)));
				$color = preg_replace('/^#?/', '#', $color);

				if (preg_match('/^#[0-9A-F]{6}$/', (string) $color)) {
					return (string) $color;
				}

				return new WP_Error(
					'order_updates_for_woo_invalid_color',
					/* translators: %s: field label. */
					sprintf( __('%s must be a valid hex value.', 'order-updates-for-woo'), $field_label ),
					[ 'status' => 400 ]
				);

			case 'user_id':
				$user_id = absint($value);

				if (! $user_id) {
					return 0;
				}

				$user = get_user_by('id', $user_id);
				$allowed_roles = $this->team_roster()->get_role_slugs();

				if (
					! $user
					|| (
						! user_can( $user, 'manage_woocommerce' )
						&& ! array_intersect( (array) $user->roles, $allowed_roles )
					)
				) {
					return new WP_Error(
						'order_updates_for_woo_invalid_assignee',
						__('Selected assignee is invalid.', 'order-updates-for-woo'),
						[ 'status' => 400 ]
					);
				}

				return $user_id;
		}

		return new WP_Error(
			'order_updates_for_woo_invalid_field',
			__('Invalid field validation rule.', 'order-updates-for-woo'),
			[ 'status' => 500 ]
		);
	}
}
