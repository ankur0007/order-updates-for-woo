<?php
/**
 * Customer rating follow-up email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications\Emails;

use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Helpers\RatingShareLinks;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Sent once after a customer submits a rating. Tone branches on stars:
 * promoters (>= threshold) get a thank-you + social share buttons;
 * detractors get an empathetic note + a reply CTA.
 */
final class CustomerRatingFollowupEmail extends OrderUpdateEmailBase {
	private bool $is_promoter = false;
	private int $stars = 0;
	private string $rating_comment = '';
	/** @var array<int, array{platform:string, label:string, url:string}> */
	private array $share_links = array();

	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->id             = Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP;
		$this->title          = __( 'Customer rating follow-up', 'order-updates-for-woo' );
		$this->description    = __( 'After a customer submits a rating, thank them and (depending on the rating) invite them to share or invite a reply.', 'order-updates-for-woo' );
		$this->customer_email = true;

		parent::__construct( $order_updates_db );
		$this->template_html = 'src/Frontend/Notifications/Templates/rating-followup.php';
	}

	public function trigger( int $update_id ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) ) {
			return false;
		}

		$rating = $this->order_updates_db->get_rating_for_update( $update_id );

		if ( empty( $rating['stars'] ) ) {
			return false;
		}

		$billing_email = $this->order ? $this->order->get_billing_email() : '';

		if ( ! $billing_email ) {
			return false;
		}

		$this->stars          = (int) $rating['stars'];
		$this->rating_comment = (string) ( $rating['comment'] ?? '' );

		$threshold = (int) apply_filters(
			'order_updates_for_woo_rating_followup_promoter_threshold',
			(int) get_option( Constants::RATING_FOLLOWUP_PROMOTER_MIN_OPTION, Constants::RATING_FOLLOWUP_PROMOTER_MIN_DEFAULT ),
			$this->stars,
			$this->order_update,
			$this->order
		);
		$this->is_promoter = $this->stars >= $threshold;

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$share_url = (string) get_option( Constants::RATING_FOLLOWUP_SHARE_URL_OPTION, '' );

		if ( '' === $share_url ) {
			$share_url = (string) home_url( '/' );
		}

		$this->recipient     = sanitize_email( $billing_email );
		$this->greeting_name = (string) $this->order->get_billing_first_name();

		if ( $this->is_promoter ) {
			$this->intro_text = sprintf(
				/* translators: %d: number of stars the customer gave. */
				_n(
					'Thanks for the %d-star rating — that really means a lot to our team.',
					'Thanks for the %d-star rating — that really means a lot to our team.',
					$this->stars,
					'order-updates-for-woo'
				),
				$this->stars
			);
			$this->share_links  = RatingShareLinks::build( $site_name, $share_url );
			$this->action_url   = '';
			$this->action_label = '';
			$this->status_label = __( 'Thanks for the rating', 'order-updates-for-woo' );
		} else {
			$this->intro_text = __( 'Thanks for taking the time to rate your experience. We hear you, and we want to make this right.', 'order-updates-for-woo' );
			$this->share_links = array();
			$this->action_url  = CustomerOrderUpdatesController::get_signed_email_url(
				(int) $this->order->get_id()
			) . '#awts-update-' . $update_id;
			$this->action_label = __( 'View this update', 'order-updates-for-woo' );
			$this->status_label = __( 'Following up on your rating', 'order-updates-for-woo' );
		}

		$this->object = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'email_heading'      => $this->get_heading(),
				'email'              => $this,
				'order'              => $this->order,
				'order_update'       => $this->order_update,
				'greeting_name'      => $this->greeting_name,
				'intro_text'         => $this->intro_text,
				'is_promoter'        => $this->is_promoter,
				'stars'              => $this->stars,
				'rating_comment'     => $this->rating_comment,
				'share_links'        => $this->share_links,
				'detractor_text'     => $this->resolve_detractor_text(),
				'action_url'         => $this->action_url,
				'action_label'       => $this->action_label,
				'status_label'       => $this->status_label,
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
			),
			'',
			$this->template_base
		);
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] Thanks for your feedback on order #{order_number}', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Thanks for your feedback', 'order-updates-for-woo' );
	}

	/**
	 * Resolve the editable detractor follow-up copy. Reads the admin-configured
	 * option, falls back to the canonical default when the option is blank
	 * (admin cleared the textarea — that's not an "opt out", just an oversight
	 * that the default fills).
	 */
	private function resolve_detractor_text(): string {
		$stored = (string) get_option( Constants::DETRACTOR_FOLLOWUP_TEXT_OPTION, '' );
		$stored = trim( $stored );

		return '' !== $stored ? $stored : Constants::DETRACTOR_FOLLOWUP_TEXT_DEFAULT;
	}
}
