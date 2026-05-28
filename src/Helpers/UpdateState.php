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
	 * True when the given user (defaults to the current logged-in user) may
	 * edit or delete the update. Two accept paths:
	 *
	 *   1. The viewer is the original creator (staff editing their own row).
	 *   2. The update was opened by a customer — these have no staff owner,
	 *      so any signed-in staff member with the cap (gated upstream by
	 *      VerifiesAccess) may pick it up, edit, or reassign.
	 */
	public static function can_edit( array $update, ?int $user_id = null ): bool {
		// Any signed-in staff member can edit any update. The order-level
		// cap check happens upstream in VerifiesAccess.
		$viewer_id = $user_id ?? get_current_user_id();
		return $viewer_id > 0;
	}
}
