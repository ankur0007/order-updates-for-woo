<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Helpers\ParticipantResolver;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Helpers\UpdatePresentationHelper;

final class UpdateCardVariableParser {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb      $order_updates_db     Injected dependency.
	 * @param ParticipantResolver $participant_resolver Injected dependency.
	 */
	public function __construct(
		private ?OrderUpdatesDb $order_updates_db = null,
		private ?ParticipantResolver $participant_resolver = null
	) {}

	public function parse( array $order_update ): array {
		$formatted = UpdatePresentationHelper::get_card_details( $order_update );

		$rating    = array();
		$update_id = absint( $order_update['id'] ?? 0 );

		if ( $update_id > 0 && $this->order_updates_db instanceof OrderUpdatesDb ) {
			$rating = $this->order_updates_db->get_rating_for_update( $update_id );
		}

		$participants = array();

		if ( $update_id > 0 && $this->participant_resolver instanceof ParticipantResolver ) {
			$participants = $this->participant_resolver->rows_for( $update_id );
		}

		return apply_filters(
			'order_updates_for_woo_update_card_variables',
			array(
				'raw'               => $order_update,
				'formatted'         => $formatted,
				'rating'            => $rating,
				'participants'      => $participants,
				'staff_email_muted' => StaffEmailPreference::is_muted( $update_id, get_current_user_id() ),
			),
			$order_update
		);
	}
}
