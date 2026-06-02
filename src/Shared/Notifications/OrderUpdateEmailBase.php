<?php
/**
 * Base WooCommerce email for order update notifications.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

abstract class OrderUpdateEmailBase extends \WC_Email {
	protected OrderUpdatesDb $order_updates_db;
	protected ?AttachmentsDb $attachments_db = null;
	protected array $order_update            = array();
	protected ?\WC_Order $order              = null;
	protected string $greeting_name          = '';
	protected string $intro_text             = '';
	protected string $note_content           = '';
	protected string $note_label             = '';
	protected string $secondary_note_content = '';
	protected string $secondary_note_label   = '';
	protected array $detail_rows             = array();
	protected array $note_attachments        = array();
	protected string $note_attachments_label = '';
	protected string $action_url             = '';
	protected string $action_label           = '';
	protected string $status_label           = '';
	protected bool $customer_visible_pill    = false;

	// Author + timestamp shown as attribution under the message body
	// in the email template — "— Author Name · May 7, 1:20 PM".
	protected string $note_author     = '';
	protected string $note_created_at = '';

	// Declared explicitly to silence PHP 8.2 dynamic-property deprecation —
	// WC_Email inherits these from WC_Settings_API but doesn't declare them
	// on the class itself, so PHP 8.2+ warns when we assign to them below.
	public $additional_content = '';
	public $email_type         = 'html';

	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->order_updates_db = $order_updates_db;
		// Plugin root. Fallback covers PHPUnit, where the constant isn't defined.
		$this->template_base = defined( 'ORDER_UPDATES_FOR_WOO_PATH' )
			? ORDER_UPDATES_FOR_WOO_PATH
			: trailingslashit( dirname( __DIR__, 3 ) );

		parent::__construct();

		$this->subject            = $this->get_option( 'subject', $this->get_default_subject() );
		$this->heading            = $this->get_option( 'heading', $this->get_default_heading() );
		$this->additional_content = $this->get_option( 'additional_content', '' );
		$this->email_type         = $this->get_option( 'email_type', 'html' );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'order-updates-for-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'order-updates-for-woo' ),
				'default' => 'yes',
			),
			'subject'            => array(
				'title'       => __( 'Subject', 'order-updates-for-woo' ),
				'type'        => 'text',
				'desc_tip'    => true,
				/* translators: %s: default subject. */
				'description' => sprintf( __( 'Leave blank to use the default subject: %s', 'order-updates-for-woo' ), $this->get_default_subject() ),
				'placeholder' => $this->get_default_subject(),
				'default'     => $this->get_default_subject(),
			),
			'heading'            => array(
				'title'       => __( 'Email heading', 'order-updates-for-woo' ),
				'type'        => 'text',
				'desc_tip'    => true,
				/* translators: %s: default heading. */
				'description' => sprintf( __( 'Leave blank to use the default heading: %s', 'order-updates-for-woo' ), $this->get_default_heading() ),
				'placeholder' => $this->get_default_heading(),
				'default'     => $this->get_default_heading(),
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'order-updates-for-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Text to appear below the main email content.', 'order-updates-for-woo' ),
				'default'     => '',
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'order-updates-for-woo' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'order-updates-for-woo' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
			),
		);
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'email_heading'          => $this->get_heading(),
				'email'                  => $this,
				'order'                  => $this->order,
				'order_update'           => $this->order_update,
				'greeting_name'          => $this->greeting_name,
				'intro_text'             => $this->intro_text,
				'note_label'             => $this->note_label,
				'note_content'           => $this->note_content,
				'secondary_note_label'   => $this->secondary_note_label,
				'secondary_note_content' => $this->secondary_note_content,
				'detail_rows'            => $this->detail_rows,
				'note_attachments'       => $this->note_attachments,
				'note_attachments_label' => $this->note_attachments_label,
				'action_url'             => $this->action_url,
				'action_label'           => $this->action_label,
				'status_label'           => $this->status_label,
				'customer_visible_pill'  => $this->customer_visible_pill,
				'note_author'            => $this->note_author,
				'note_created_at'        => $this->note_created_at,
				'additional_content'     => $this->get_additional_content(),
				'sent_to_admin'          => ! $this->customer_email,
				'plain_text'             => false,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain(): string {
		return wp_strip_all_tags( $this->get_content_html() );
	}

	/**
	 * Reset every per-trigger property to its empty default. Call at the
	 * top of any subclass `trigger()` so a prior dispatch in the same
	 * request doesn't bleed state (`WC()->mailer()` reuses the same email
	 * instance across calls). Cheap; safe to call repeatedly.
	 */
	protected function reset_trigger_state(): void {
		$this->note_label             = '';
		$this->note_content           = '';
		$this->note_author            = '';
		$this->note_created_at        = '';
		$this->secondary_note_label   = '';
		$this->secondary_note_content = '';
		$this->note_attachments       = array();
		$this->note_attachments_label = '';
		$this->detail_rows            = array();
		$this->action_url             = '';
		$this->action_label           = '';
		$this->status_label           = '';
		$this->customer_visible_pill  = false;
		$this->greeting_name          = '';
	}

	/**
	 * Run the WC mailer's locale dance — switch into the email recipient's
	 * locale, dispatch, switch back. Every trigger() in every subclass needs
	 * exactly this three-call sequence, so route them through one helper to
	 * keep the contract obvious and avoid drift if WC ever changes how
	 * setup/restore_locale pair.
	 */
	protected function send_with_locale(): bool {
		$this->setup_locale();
		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * Populate $note_attachments from the attachment store for the given note.
	 * Every email that quotes a note body should call this so the recipient
	 * sees the same file list the author attached — staff or customer side,
	 * internal or customer-visible. No-op when AttachmentsDb wasn't wired in
	 * (older subclasses or test doubles) so the email still renders without
	 * the attachment block instead of erroring.
	 *
	 * Uses Constants::ATTACHMENT_CONTEXT_CUSTOMER for the public-facing URL
	 * shape, which is what every recipient (staff or customer) lands on when
	 * they click through from the email.
	 *
	 * @param string $note_type Constants::NOTE_TYPE_INTERNAL or NOTE_TYPE_CUSTOMER.
	 */
	protected function populate_note_attachments( int $note_id, string $note_type ): void {
		if ( ! $this->attachments_db instanceof AttachmentsDb || $note_id <= 0 ) {
			return;
		}

		if ( Constants::NOTE_TYPE_INTERNAL !== $note_type && Constants::NOTE_TYPE_CUSTOMER !== $note_type ) {
			return;
		}

		$rows = $this->attachments_db->get_for_note( $note_id, $note_type );

		if ( empty( $rows ) ) {
			return;
		}

		$this->note_attachments       = AttachmentPresenter::format_many( $rows, Constants::ATTACHMENT_CONTEXT_CUSTOMER );
		$this->note_attachments_label = __( 'Attachments', 'order-updates-for-woo' );
	}

	/**
	 * Populate the message body + attribution (author, timestamp) from a
	 * raw note row (customer_notes or update_notes shape — both have the
	 * same `note` / `created_by_name` / `created_at` columns).
	 */
	protected function set_note_from_row( array $row ): void {
		$this->note_content    = (string) ( $row['note'] ?? '' );
		$this->note_author     = (string) ( $row['created_by_name'] ?? '' );
		$created_at_raw        = (string) ( $row['created_at'] ?? '' );
		$this->note_created_at = '' !== $created_at_raw
			? \OrderUpdatesForWoo\Helpers\DateHelper::format_date( $created_at_raw )
			: '';
	}

	/**
	 * Latest customer-thread note on the loaded update, or an empty array
	 * if there are none. Returns the full row so callers can extract body
	 * + author + timestamp via {@see set_note_from_row()}.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_latest_customer_note_row(): array {
		$update_id = (int) ( $this->order_update['id'] ?? 0 );

		if ( ! $update_id ) {
			return array();
		}

		$notes = $this->order_updates_db->get_customer_notes( $update_id );

		return empty( $notes ) ? array() : (array) end( $notes );
	}

	/**
	 * Latest internal note on the loaded update, or an empty array if
	 * there are none.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_latest_internal_note_row(): array {
		$update_id = (int) ( $this->order_update['id'] ?? 0 );

		if ( ! $update_id ) {
			return array();
		}

		$notes = $this->order_updates_db->get_update_notes( $update_id );

		return empty( $notes ) ? array() : (array) end( $notes );
	}

	protected function load_context( int $update_id ): bool {
		$this->order_update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $this->order_update['order_id'] ) ) {
			return false;
		}

		$this->order = wc_get_order( absint( $this->order_update['order_id'] ) );

		if ( $this->order instanceof \WC_Order ) {
			$this->placeholders = array(
				'{site_title}'   => $this->get_blogname(),
				'{order_number}' => $this->order->get_order_number(),
			);
		}

		return $this->order instanceof \WC_Order;
	}
}
