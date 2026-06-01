<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

/**
 * Plain yes/no checks against a single update row. Every caller that asks
 * "is this resolved?" / "does it have an assignee?" / "can this user edit
 * it?" goes through here so a future column rename or policy change lands
 * in one place. Used by views, endpoints, and addons.
 */
final class UpdateState {
	/** True when the update has been marked solved. */
	public static function is_resolved( array $update ): bool {
		return ! empty( $update['is_resolved'] );
	}

	/** True when the update is visible on the customer-facing page. */
	public static function is_customer_visible( array $update ): bool {
		return ! empty( $update['customer_visible'] );
	}

	/** True when the update is currently assigned to a staff member. */
	public static function has_assignee( array $update ): bool {
		return ! empty( $update['assignee_user_id'] );
	}

	/** True when the customer has been emailed about this update. */
	public static function is_customer_notified( array $update ): bool {
		return ! empty( $update['notified_customer_at'] );
	}

	/**
	 * UI-only flag: whether to render the edit button on an update card.
	 * NOT an authorisation check — the real cap gate lives upstream in
	 * VerifiesAccess. Returns true for any signed-in user, so a caller
	 * MUST already be inside a surface that's restricted to staff (the
	 * admin order panel only renders for users with the order cap, so
	 * the precondition holds there). Do NOT use this method to gate a
	 * write endpoint.
	 */
	public static function should_render_edit_ui( array $update, ?int $user_id = null ): bool {
		$viewer_id = $user_id ?? get_current_user_id();
		return $viewer_id > 0;
	}
}
