<?php
/**
 * Plugin-wide identifiers, option keys, and enums.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Config;

/**
 * Plugin-wide identifiers and enums. Centralized so a rename happens in
 * exactly one place. Values here are contracts — changing them is a breaking
 * change for stored data, hooked integrations, or persisted routes.
 */
final class Constants {
	public const REST_NAMESPACE       = 'order-updates-for-woo/v1';
	public const CACHE_GROUP          = 'order_updates_for_woo';
	public const ATTACHMENTS_ROOT_DIR = 'order-updates-for-woo';

	public const HOOK_ADMIN_NOTIFICATION      = 'order_updates_for_woo_send_admin_notification';
	public const HOOK_ASSIGNEE_NOTIFICATION   = 'order_updates_for_woo_send_assignee_notification';
	public const HOOK_CUSTOMER_NOTIFICATION   = 'order_updates_for_woo_send_customer_notification';
	public const HOOK_RATING_REQUEST          = 'order_updates_for_woo_send_rating_request';
	public const HOOK_RATING_FOLLOWUP         = 'order_updates_for_woo_send_rating_followup';
	public const HOOK_INTERNAL_MENTION        = 'order_updates_for_woo_send_internal_mention';
	public const HOOK_PARTICIPANT_UPDATE      = 'order_updates_for_woo_send_participant_update';
	public const HOOK_ASSIGNEE_SENT           = 'order_updates_for_woo_assignee_notification_sent';
	public const HOOK_CUSTOMER_SENT           = 'order_updates_for_woo_customer_notification_sent';
	public const HOOK_RATING_REQUEST_SENT     = 'order_updates_for_woo_rating_request_sent';
	public const HOOK_INTERNAL_MENTION_SENT   = 'order_updates_for_woo_internal_mention_sent';
	public const HOOK_PARTICIPANT_UPDATE_SENT = 'order_updates_for_woo_participant_update_sent';
	public const HOOK_SHARED_LINK_EMAIL       = 'order_updates_for_woo_send_shared_link_email';

	// Rating follow-up tunables. Held as option keys + defaults so they can
	// be promoted to a settings field later without touching call sites.
	public const RATING_FOLLOWUP_PROMOTER_MIN_OPTION  = 'order_updates_for_woo_rating_followup_promoter_min';
	public const RATING_FOLLOWUP_PROMOTER_MIN_DEFAULT = 4;
	public const RATING_FOLLOWUP_SHARE_URL_OPTION     = 'order_updates_for_woo_rating_share_url';

	public const NOTE_TYPE_INTERNAL = 'internal';
	public const NOTE_TYPE_CUSTOMER = 'customer';

	// WP Heartbeat key for admin customer-thread updates.
	public const HEARTBEAT_KEY = 'order_updates_for_woo_notes';
	// WP Heartbeat key for admin bar notification count refresh.
	public const HEARTBEAT_ADMIN_BAR_KEY = 'order_updates_for_woo_admin_bar';

	// Customer-page polling: base interval, backoff steps, and cap (seconds).
	// Exposed via localized config so addons or themes can override via filter.
	public const POLL_INTERVAL_MIN_SECONDS = 30;
	public const POLL_INTERVAL_MID_SECONDS = 60;
	public const POLL_INTERVAL_MAX_SECONDS = 120;

	// Transient TTL for the poll endpoint response cache (seconds).
	// Must be shorter than POLL_INTERVAL_MIN_SECONDS so a user never misses
	// a message for more than two poll cycles.
	public const POLL_CACHE_TTL_SECONDS = 15;

	public const ATTACHMENT_CONTEXT_ADMIN    = 'admin';
	public const ATTACHMENT_CONTEXT_CUSTOMER = 'customer';

	// Number of customer-thread notes loaded per page (initial render + "load previous").
	public const CUSTOMER_NOTES_PAGE_SIZE = 10;

	// Analytics page: cache TTL (24 h), daily warmup cron hook, and generation-counter key prefix.
	public const ANALYTICS_CACHE_TTL = 86400;
	public const ANALYTICS_CRON_HOOK = 'order_updates_for_woo_analytics_warmup';
	// Notification retention: daily cleanup + its per-chunk batch action.
	public const NOTIFICATIONS_CLEANUP_HOOK       = 'order_updates_for_woo_notifications_cleanup';
	public const NOTIFICATIONS_CLEANUP_BATCH_HOOK = 'order_updates_for_woo_notifications_cleanup_batch';
	public const ANALYTICS_GEN_PFX                = 'analytics_gen_';
	// Option key prefix for persistent generation counters (used on hosts without a persistent object cache).
	public const ANALYTICS_GEN_OPTION_PFX = 'order_updates_for_woo_analytics_gen_';

	// WooCommerce order item table suffixes (without $wpdb->prefix).
	public const WC_ORDER_ITEMS_TABLE    = 'woocommerce_order_items';
	public const WC_ORDER_ITEMMETA_TABLE = 'woocommerce_order_itemmeta';

	// Meta keys for customer email notification preference.
	public const CUSTOMER_EMAIL_PREF_USER_META  = 'order_updates_for_woo_email_opt_in';
	public const CUSTOMER_EMAIL_PREF_ORDER_META = '_order_updates_for_woo_guest_email_opt_in';

	// Prefix for per-update staff email mute preference (appended with update ID).
	public const STAFF_EMAIL_MUTED_META_PREFIX = 'order_updates_for_woo_staff_muted_';

	// Option key for the editable promoter share-text in the follow-up email.
	public const PROMOTER_SHARE_TEXT_OPTION = 'order_updates_for_woo_promoter_share_text';
	// Default template — %s is replaced with the site name at render time.
	public const PROMOTER_SHARE_TEXT_DEFAULT = 'I just had a great experience with {site_name} — check them out! {site_url}';

	// Editable copy shown to customers who leave a 1-3 star rating, in the
	// detractor follow-up email. Single paragraph, plain text. Routes the
	// customer back to the portal rather than asking them to reply to the
	// email (which is broken if the store's From address isn't monitored).
	public const DETRACTOR_FOLLOWUP_TEXT_OPTION  = 'order_updates_for_woo_detractor_followup_text';
	public const DETRACTOR_FOLLOWUP_TEXT_DEFAULT = "Thanks for sharing your rating — we've passed your feedback to the team, and someone will get back to you on your update shortly. You can keep the conversation going from the same page:";

	// "Powered by" footer in plugin emails. Default OFF per WP.org rules —
	// attribution in user-facing surfaces (incl. emails) requires explicit
	// admin opt-in via the checkbox in Settings → Emails.
	public const POWERED_BY_REVIEW_URL           = 'https://wordpress.org/support/plugin/order-updates-for-woo/reviews/#new-post';
	public const SHOW_EMAIL_FOOTER_CREDIT_OPTION = 'order_updates_for_woo_show_email_footer_credit';

	// Auto-assignment pool + rotation pointer for customer-opened updates.
	// The `round_robin` names stay on disk so old installs keep their data.
	public const ASSIGNEE_PRIORITY_LIST_OPTION    = 'order_updates_for_woo_round_robin_pool';
	public const ASSIGNEE_ROTATION_POINTER_OPTION = 'order_updates_for_woo_round_robin_pointer';

	// Attachments — admin-controlled list of mime types accepted for upload.
	public const ALLOWED_MIMES_OPTION = 'order_updates_for_woo_allowed_mime_types';

	// Admin-bar notification dismiss — AJAX action names + nonce key.
	public const ADMIN_BAR_DISMISS_ACTION            = 'awts_dismiss_notification';
	public const ADMIN_BAR_DISMISS_FOR_UPDATE_ACTION = 'awts_dismiss_update_notifications';
	public const ADMIN_BAR_DISMISS_ALL_ACTION        = 'awts_dismiss_all_notifications';
	public const ADMIN_BAR_DISMISS_NONCE             = 'awts_dismiss_notification';

	// WooCommerce email type IDs (used as array keys in `woocommerce_email_classes`
	// and as the `$this->id` field on each Email subclass).
	public const EMAIL_ID_ADMIN_UPDATE             = 'order_updates_for_woo_admin_update';
	public const EMAIL_ID_CREATOR_UPDATE_DELETED   = 'order_updates_for_woo_creator_update_deleted';
	public const EMAIL_ID_ASSIGNEE_UPDATE          = 'order_updates_for_woo_assignee_update';
	public const EMAIL_ID_INTERNAL_MENTION         = 'order_updates_for_woo_internal_mention';
	public const EMAIL_ID_PARTICIPANT_UPDATE       = 'order_updates_for_woo_participant_update';
	public const EMAIL_ID_CUSTOMER_UPDATE          = 'order_updates_for_woo_customer_update';
	public const EMAIL_ID_CUSTOMER_UPDATE_DELETED  = 'order_updates_for_woo_customer_update_deleted';
	public const EMAIL_ID_CUSTOMER_RATING_REQUEST  = 'order_updates_for_woo_customer_rating_request';
	public const EMAIL_ID_CUSTOMER_RATING_FOLLOWUP = 'order_updates_for_woo_customer_rating_followup';
	public const EMAIL_ID_CUSTOMER_SHARED_LINK     = 'order_updates_for_woo_customer_shared_link';

	// Newsletter (admin welcome screen subscribe form).
	public const NEWSLETTER_EMAIL_OPTION  = 'order_updates_for_woo_newsletter_email';
	public const NEWSLETTER_SUBSCRIBE_URL = 'https://newsletter.orderupdatesforwoo.com/subscribe';

	// Note edit / delete window option (in minutes; default 15, clamped 1–1440).
	public const NOTE_EDIT_WINDOW_OPTION = 'order_updates_for_woo_note_edit_window_minutes';

	// Both default OFF — the thread is a permanent record until the admin
	// turns this on. Even when on, only the latest note in a thread can be
	// edited or deleted.
	public const ALLOW_NOTE_EDIT_OPTION   = 'order_updates_for_woo_allow_note_edit';
	public const ALLOW_NOTE_DELETE_OPTION = 'order_updates_for_woo_allow_note_delete';

	// When on, customers can open new update threads from their order page.
	// Replies to existing updates work either way. Default OFF.
	public const ALLOW_CUSTOMER_CREATE_UPDATE_OPTION = 'order_updates_for_woo_allow_customer_create_update';

	// Emails the site admin (`admin_email`) on each customer-opened update. Default OFF.
	public const NOTIFY_ADMIN_ON_CUSTOMER_CREATE_OPTION = 'order_updates_for_woo_notify_admin_on_customer_create';

	// Emails the site admin on a 1-3 star rating. Default ON — low ratings
	// are rare and worth showing right away.
	public const NOTIFY_ADMIN_ON_DETRACTOR_RATING_OPTION = 'order_updates_for_woo_notify_admin_on_detractor_rating';

	// Background overrides for the two note panels on the order edit screen.
	// Leave any field empty to use the CSS defaults.
	public const NOTE_PANEL_INTERNAL_BG_OPTION  = 'order_updates_for_woo_note_panel_internal_bg';
	public const NOTE_PANEL_INTERNAL_IMG_OPTION = 'order_updates_for_woo_note_panel_internal_image';
	public const NOTE_PANEL_CUSTOMER_BG_OPTION  = 'order_updates_for_woo_note_panel_customer_bg';
	public const NOTE_PANEL_CUSTOMER_IMG_OPTION = 'order_updates_for_woo_note_panel_customer_image';

	// Status list managed by the admin. Each entry: [ key, label, color ].
	// Array order is the order shown in the form dropdown. `key` is the
	// stable id; label and color are for display only.
	// `DEFAULT_CUSTOMER_STATUS_OPTION` sets the status of new customer-opened
	// updates — customers don't see the status picker.
	public const STATUSES_OPTION                = 'order_updates_for_woo_statuses';
	public const DEFAULT_CUSTOMER_STATUS_OPTION = 'order_updates_for_woo_default_customer_status';
	public const STATUS_FALLBACK_COLOR          = '#2563eb';

	/**
	 * Built-in statuses seeded on first install.
	 *
	 * @var array<int, array{key:string, label:string, color:string}>
	 */
	public const STATUS_SEED_DEFAULTS = array(
		array(
			'key'   => 'neutral',
			'label' => 'Neutral',
			'color' => '#6b7280',
		),
		array(
			'key'   => 'notice',
			'label' => 'Notice',
			'color' => '#2271b1',
		),
		array(
			'key'   => 'warning',
			'label' => 'Warning',
			'color' => '#d97706',
		),
		array(
			'key'   => 'urgent',
			'label' => 'Urgent',
			'color' => '#dc2626',
		),
		array(
			'key'   => 'success',
			'label' => 'Success',
			'color' => '#16a34a',
		),
	);

	// Customer-initiated updates land on "Notice" out of the box — it reads
	// as "informational, not urgent," which matches what most customer-opened
	// threads actually are. Admin can override from settings.
	public const DEFAULT_CUSTOMER_STATUS_SEED_KEY = 'notice';

	// Customer-facing email URL controls. Emails carry a signed token that
	// expires after this many days; the support email is the contact shown
	// when an expired link is clicked.
	public const CUSTOMER_LINK_EXPIRY_DAYS_OPTION = 'order_updates_for_woo_customer_link_expiry_days';
	public const SUPPORT_CONTACT_EMAIL_OPTION     = 'order_updates_for_woo_support_contact_email';

	// One-shot migration flags + legacy v1.0 option keys cleaned up on
	// the next admin_init. Kept in Constants so a future grep finds them.
	public const ASSIGNMENT_MIGRATION_V1_FLAG_OPTION = 'order_updates_for_woo_assignment_migration_v1_done';
	public const LEGACY_PRIMARY_ASSIGNEE_OPTION      = 'order_updates_for_woo_primary_assignee';
	public const LEGACY_ASSIGNMENT_MODE_OPTION       = 'order_updates_for_woo_assignment_mode';

	// Tunable plugin variables. Defaults live in Variables.php.
	public const PAGE_SIZE_OPTION                 = 'order_updates_for_woo_page_size';
	public const ADMIN_BAR_MAX_ORDERS_OPTION      = 'order_updates_for_woo_admin_bar_max_orders';
	public const ASSIGNEE_SEARCH_CACHE_TTL_OPTION = 'order_updates_for_woo_assignee_search_cache_ttl';
	public const UPDATE_CACHE_TTL_OPTION          = 'order_updates_for_woo_cache_ttl';
	public const MAX_ATTACHMENT_MB_OPTION         = 'order_updates_for_woo_max_attachment_mb';
	public const MAX_ATTACHMENT_FILES_OPTION      = 'order_updates_for_woo_max_attachment_files';
	public const SIGNED_URL_TTL_OPTION            = 'order_updates_for_woo_signed_url_ttl';
}
