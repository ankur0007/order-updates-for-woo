<?php
/**
 * Registers the [order_updates_portal] shortcode for the customer portal.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\OrderUpdates;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\RestUrlHelper;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Language\Labels;

/**
 * Registers the [order_updates_portal] shortcode.
 *
 * Works in any page builder (Gutenberg, Elementor, Divi) because assets are
 * enqueued inside the render callback rather than relying on a specific
 * WordPress rewrite endpoint being active.
 *
 * Order ID resolution priority:
 *   1. Shortcode attribute  [order_updates_portal order_id="123"]
 *   2. URL query param      ?order_id=123  or  ?order-id=123
 *   3. WooCommerce context  view-order / order-received query var
 *
 * Guest auth: the order_key query param (?key=wc_order_…) or the shortcode
 * attribute [order_updates_portal order_key="wc_order_…"] is required when
 * no user is logged in.
 */
final class OrderUpdatesShortcode {
	public const TAG = 'order_updates_portal';

	/**
	 * Inject dependencies.
	 *
	 * @param CustomerOrderUpdatesService $service Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service Injected dependency.
	 */
	public function __construct(
		private CustomerOrderUpdatesService $service,
		private OrderUpdatesSettingsService $settings_service
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode render callback. Returns HTML (never echoes).
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'order_id'  => '',
				'order_key' => '',
			),
			$atts,
			self::TAG
		);

		$order_id  = $this->resolve_order_id( (string) $atts['order_id'] );
		$order_key = $this->resolve_order_key( (string) $atts['order_key'] );

		if ( $order_id <= 0 ) {
			return '<p class="awts_cou_empty">' . esc_html__( 'No order found. Please open this page from your order confirmation link.', 'order-updates-for-woo' ) . '</p>';
		}

		$status = $this->service->resolve_view_status( $order_id, $order_key );

		if ( CustomerOrderUpdatesService::VIEW_ALLOWED !== $status ) {
			return '<p class="awts_cou_empty">' . esc_html( $this->denial_message( $status ) ) . '</p>';
		}

		$this->enqueue_assets( $order_id, $order_key );

		ob_start();
		$this->render_content( $order_id );

		return (string) ob_get_clean();
	}

	// ----- Order ID / key resolution -----

	/**
	 * Resolve the order id from the attribute, URL params, or WC context.
	 *
	 * @param string $attr_value The shortcode's order_id attribute.
	 */
	private function resolve_order_id( string $attr_value ): int {
		// 1. Shortcode attribute.
		if ( '' !== $attr_value ) {
			return absint( $attr_value );
		}

		// 2. URL query params (page-builder pages don't have WC rewrites).
		// Read-only GET resolve for a shortcode — no state change, nonce
		// verification doesn't apply. absint() is the actual sanitization.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'order_id', 'order-id' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				$value = absint( $_GET[ $param ] );
				if ( $value > 0 ) {
					return $value;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 3. WooCommerce context (order received / account view-order page).
		$wc_vars = array( 'view-order', 'order-received' );
		foreach ( $wc_vars as $var ) {
			$value = absint( (string) get_query_var( $var ) );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Resolve the guest order key from the attribute or URL params.
	 *
	 * @param string $attr_value The shortcode's order_key attribute.
	 */
	private function resolve_order_key( string $attr_value ): string {
		if ( '' !== $attr_value ) {
			return sanitize_text_field( $attr_value );
		}

		// Read-only GET resolve for a shortcode — sanitize + unslash applied.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'key', 'order_key' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	// ----- Access denial messages -----

	/**
	 * Customer-facing message for a non-allowed view status.
	 *
	 * @param string $status One of the VIEW_* statuses.
	 */
	private function denial_message( string $status ): string {
		switch ( $status ) {
			case CustomerOrderUpdatesService::VIEW_RESTRICTED:
				return __( 'Access restricted. You do not have permission to view this page.', 'order-updates-for-woo' );
			case CustomerOrderUpdatesService::VIEW_EXPIRED:
				return __( 'This link has expired or the order is no longer available.', 'order-updates-for-woo' );
			case CustomerOrderUpdatesService::VIEW_INVALID:
			default:
				return __( 'Invalid access link.', 'order-updates-for-woo' );
		}
	}

	// ----- Content rendering -----

	/**
	 * Render the portal body — order summary, updates list, write-note UI.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_content( int $order_id ): void {
		$updates = $this->service->get_updates_for_order( $order_id );

		$this->render_order_summary( $order_id );
		$this->render_write_note_trigger();

		if ( empty( $updates ) ) {
			echo '<p class="awts_cou_empty">' . esc_html__( 'No updates available for this order yet.', 'order-updates-for-woo' ) . '</p>';
		} else {
			$feature_settings    = $this->settings_service->get_feature_settings();
			$email_notifications = CustomerEmailPreference::get( $order_id, get_current_user_id() );

			View::render(
				'src/Frontend/OrderUpdates/Views/CustomerOrderUpdatesView',
				array(
					'updates'             => $updates,
					'order_id'            => $order_id,
					'rating'              => $this->service->get_rating_config(),
					'show_assignee'       => ! empty( $feature_settings['show_assignee_to_customers'] ),
					'email_notifications' => $email_notifications,
				)
			);
		}

		$this->render_write_note_modal();
	}

	// ----- Asset enqueue -----

	/**
	 * Enqueue the portal CSS/JS and localize its config.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $order_key Guest order key, if any.
	 */
	private function enqueue_assets( int $order_id, string $order_key ): void {
		wp_enqueue_style(
			'order-updates-for-woo-customer',
			AssetHelper::url( 'assets/Frontend/css/customer-order-updates.css' ),
			array(),
			AssetHelper::version( 'assets/Frontend/css/customer-order-updates.css' )
		);

		wp_enqueue_script(
			'order-updates-for-woo-customer',
			AssetHelper::url( 'assets/Frontend/js/customer-order-updates.js' ),
			array(),
			AssetHelper::version( 'assets/Frontend/js/customer-order-updates.js' ),
			true
		);

		$allowed_mime  = AttachmentService::allowed_mime_types();
		$rating_config = $this->service->get_rating_config();

		wp_localize_script(
			'order-updates-for-woo-customer',
			'AWTS_COU_CONFIG',
			array(
				'restUrl'                   => esc_url_raw( RestUrlHelper::route( 'customer-updates' ) ),
				'customerNotesEndpointBase' => esc_url_raw( RestUrlHelper::route( 'updates/' ) ),
				'ratingUrlBase'             => esc_url_raw( RestUrlHelper::route( 'updates/' ) ),
				'notesPageSize'             => Constants::CUSTOMER_NOTES_PAGE_SIZE,
				'emailPrefUrl'              => esc_url_raw( RestUrlHelper::route( 'customer-email-preference' ) ),
				'pollUrl'                   => esc_url_raw( RestUrlHelper::route( 'customer-thread/poll' ) ),
				'pollIntervalMin'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_min', Constants::POLL_INTERVAL_MIN_SECONDS ) * 1000,
				'pollIntervalMid'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_mid', Constants::POLL_INTERVAL_MID_SECONDS ) * 1000,
				'pollIntervalMax'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_max', Constants::POLL_INTERVAL_MAX_SECONDS ) * 1000,
				'realtimeConfig'            => (array) apply_filters( 'order_updates_for_woo_realtime_config', array(), $order_id ),
				'nonce'                     => wp_create_nonce( 'wp_rest' ),
				'orderId'                   => $order_id,
				'orderKey'                  => $order_key,
				'pageUrl'                   => get_permalink() ? get_permalink() : '',
				'maxFiles'                  => Variables::getMaxAttachmentFiles(),
				'maxBytes'                  => AttachmentService::max_bytes(),
				'acceptMime'                => implode( ',', $allowed_mime ),
				'allowNoteEdit'             => $this->settings_service->allow_note_edit(),
				'rating'                    => $rating_config,
				'labels'                    => array(
					'submitting'            => (string) Labels::get( 'customerWriteNoteSubmitting' ),
					'success'               => (string) Labels::get( 'customerWriteNoteSuccess' ),
					'genericFail'           => (string) Labels::get( 'customerWriteNoteGenericFail' ),
					'sessionExpired'        => (string) Labels::get( 'sessionExpiredRefresh' ),
					'subjectRequired'       => (string) Labels::get( 'customerWriteNoteSubjectRequired' ),
					'messageRequired'       => (string) Labels::get( 'customerWriteNoteMessageRequired' ),
					'tooManyFiles'          => (string) Labels::get( 'customerWriteNoteTooManyFiles' ),
					'attachmentTooLarge'    => (string) Labels::get( 'attachmentTooLarge' ),
					'attachmentUnsupported' => (string) Labels::get( 'attachmentUnsupported' ),
					'removeFile'            => (string) Labels::get( 'customerWriteNoteRemoveFile' ),
					'replySuccess'          => (string) Labels::get( 'customerReplySuccess' ),
					'ratingMissing'         => (string) Labels::get( 'customerRatingMissing' ),
					'ratingSubmitting'      => (string) Labels::get( 'customerRatingSubmitting' ),
					'ratingSuccess'         => (string) Labels::get( 'customerRatingSuccess' ),
					'ratingThanks'          => (string) Labels::get( 'customerRatingThanks' ),
					'ratingSaveFailed'      => (string) Labels::get( 'customerRatingSaveFailed' ),
					'reopenButton'          => (string) Labels::get( 'customerReopenButton' ),
					'reopenSubmitting'      => (string) Labels::get( 'customerReopenSubmitting' ),
					'reopenFailed'          => (string) Labels::get( 'customerReopenFailed' ),
					'newBadge'              => (string) Labels::get( 'customerUpdatesNewBadge' ),
					'newBadgeCount'         => (string) Labels::get( 'customerUpdatesNewBadgeCount' ),
					'noNotes'               => (string) Labels::get( 'customerUpdatesNoNotes' ),
					'editNoteAction'        => (string) Labels::get( 'editNoteAction' ),
					'editNotePrompt'        => (string) Labels::get( 'editNotePrompt' ),
					'editedLabel'           => (string) Labels::get( 'editedLabel' ),
					'historyHeading'        => (string) Labels::get( 'noteHistoryHeading' ),
					'historyEmpty'          => (string) Labels::get( 'noteHistoryEmpty' ),
					'saveNoteAction'        => (string) Labels::get( 'saveNoteAction' ),
					'cancelNoteAction'      => (string) Labels::get( 'cancelNoteAction' ),
					'noteUpdated'           => (string) Labels::get( 'customerNoteUpdated' ),
					'loadingLabel'          => (string) Labels::get( 'loadingLabel' ),
				),
			)
		);
	}

	// ----- Write-note trigger + modal -----

	/** Render the "write a note" trigger button (gated on the admin opt-in). */
	private function render_write_note_trigger(): void {
		if ( ! $this->settings_service->allow_customer_create_update() ) {
			return;
		}

		View::render( 'src/Frontend/OrderUpdates/Views/CustomerPortalWriteNoteTriggerView' );
	}

	/**
	 * Render the order summary card at the top of the portal.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_order_summary( int $order_id ): void {
		$summary = $this->service->get_order_summary( $order_id );

		if ( empty( $summary ) ) {
			return;
		}

		View::render(
			'src/Frontend/OrderUpdates/Views/CustomerOrderSummaryView',
			array( 'summary' => $summary )
		);
	}

	/** Render the "write a note" modal markup (gated on the admin opt-in). */
	private function render_write_note_modal(): void {
		if ( ! $this->settings_service->allow_customer_create_update() ) {
			return;
		}

		View::render(
			'src/Frontend/OrderUpdates/Views/CustomerPortalWriteNoteModalView',
			array(
				'attach_hint'  => sprintf(
					/* translators: 1: maximum number of files, 2: maximum file size. */
					__( 'Up to %1$d files, %2$s each. PDF, JPG, PNG, GIF or WEBP.', 'order-updates-for-woo' ),
					Variables::getMaxAttachmentFiles(),
					size_format( AttachmentService::max_bytes() )
				),
				'allowed_mime' => AttachmentService::allowed_mime_types(),
			)
		);
	}
}
