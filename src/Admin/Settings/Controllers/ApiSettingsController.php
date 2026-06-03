<?php
/**
 * API Endpoints settings controller — wires the API sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\ApiSettingsService;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for the api settings section.
 */
final class ApiSettingsController implements SettingsSectionController {
	private const ASSET_HANDLE = 'order-updates-for-woo-api-tab';

	/**
	 * Inject dependencies.
	 *
	 * @param ApiSettingsService $service Injected dependency.
	 */
	public function __construct( private ApiSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'order_updates_for_woo_api_endpoint_params', array( $this, 'document_core_endpoints' ), 10, 3 );
		add_filter( 'order_updates_for_woo_api_endpoint_summary', array( $this, 'document_core_endpoint_summary' ), 10, 3 );
	}

	/**
	 * Provide summary text for the core endpoints. Addons can hook the
	 * same filter at a higher priority to override.
	 *
	 * @param string   $summary Summary set by an earlier filter, if any.
	 * @param string   $path    Route path.
	 * @param string[] $methods HTTP methods the route accepts; kept for the hook signature.
	 */
	public function document_core_endpoint_summary( string $summary, string $path, array $methods ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $methods is part of the documented filter signature for other code.
		if ( '' !== $summary ) {
			return $summary;
		}

		$catalog = $this->core_endpoint_documentation();

		return (string) ( $catalog[ $path ]['summary'] ?? '' );
	}

	/**
	 * Pre-fill body-parameter documentation for the most-used endpoints so
	 * the curl template carries a realistic JSON body straight out of the
	 * box. Uses the raw regex path as the key (that's what
	 * `params_for()` passes through the filter).
	 *
	 * Routes not listed here still appear in the directory — they just
	 * surface their path params only and an empty body. To document a new
	 * endpoint, add it to `core_endpoint_documentation()` below or hook
	 * the same filter from your addon.
	 *
	 * @param array<int, array<string,mixed>> $params  Param list so far.
	 * @param string                          $path    Route path.
	 * @param string[]                        $methods HTTP methods the route accepts.
	 * @return array<int, array<string,mixed>>
	 */
	public function document_core_endpoints( array $params, string $path, array $methods ): array {
		$catalog = $this->core_endpoint_documentation();

		if ( empty( $catalog[ $path ]['params'] ) ) {
			return $params;
		}

		$source = $this->source_label_for( $methods );

		foreach ( $catalog[ $path ]['params'] as $name => $spec ) {
			$params[] = array(
				'name'        => (string) $name,
				'source'      => $source,
				'type'        => (string) ( $spec['type'] ?? 'string' ),
				'required'    => (bool) ( $spec['required'] ?? false ),
				'description' => (string) ( $spec['description'] ?? '' ),
			);
		}

		return $params;
	}

	/**
	 * Single source of truth for core-endpoint documentation. Each entry
	 * is keyed by the raw regex path WC uses internally (the same string
	 * `params_for()` and `summary_for()` pass through their filters), and
	 * carries both a summary and the body / query parameters.
	 *
	 * @return array<string, array{summary:string, params:array<string, array{type:string, required:bool, description:string}>}>
	 */
	private function core_endpoint_documentation(): array {
		$prefix = '/' . \OrderUpdatesForWoo\Shared\Config\Constants::REST_NAMESPACE;

		return array(
			$prefix . '/updates'                           => array(
				'summary' => __( 'List or create order updates. GET filters by order_id; POST creates a new update for an order.', 'order-updates-for-woo' ),
				'params'  => array(
					'order_id'      => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'WooCommerce order ID the update belongs to.', 'order-updates-for-woo' ),
					),
					'title'         => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Update title.', 'order-updates-for-woo' ),
					),
					'internal_note' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Internal staff note saved with the update.', 'order-updates-for-woo' ),
					),
					'customer_note' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Customer-visible note (also creates a customer-thread entry).', 'order-updates-for-woo' ),
					),
					'color'         => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Hex highlight color for the card.', 'order-updates-for-woo' ),
					),
					'assignee_id'   => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Staff user ID to assign. Pass 0 to leave unassigned.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)'        => array(
				'summary' => __( 'Edit or delete a single update. PUT/POST updates fields; DELETE removes the update and its notes.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/updates/(?P<update_id>\d+)/customer-notes' => array(
				'summary' => __( 'List the customer-thread notes on an update, or post a new customer-visible note as staff.', 'order-updates-for-woo' ),
				'params'  => array(
					'note' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Note body. Max 500 chars.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)' => array(
				'summary' => __( 'Edit a previously posted customer note. The original is archived to the edit-history table.', 'order-updates-for-woo' ),
				'params'  => array(
					'note' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Replacement note body.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)/notify' => array(
				'summary' => __( 'Manually email a customer-visible note to the customer. Use when the auto-notify path was skipped.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/updates/(?P<update_id>\d+)/notes'  => array(
				'summary' => __( 'List internal staff notes on an update, or post a new internal note (with optional @mentions).', 'order-updates-for-woo' ),
				'params'  => array(
					'note'               => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Internal note body. Max 500 chars.', 'order-updates-for-woo' ),
					),
					'mentioned_user_ids' => array(
						'type'        => 'array',
						'required'    => false,
						'description' => __( 'Array of staff user IDs to @mention.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/notes/(?P<note_id>\d+)' => array(
				'summary' => __( 'Edit or delete a single internal note. Edit window is enforced by the configured limit.', 'order-updates-for-woo' ),
				'params'  => array(
					'note'               => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Replacement note body.', 'order-updates-for-woo' ),
					),
					'mentioned_user_ids' => array(
						'type'        => 'array',
						'required'    => false,
						'description' => __( 'Updated array of @mention user IDs.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/solve'  => array(
				'summary' => __( 'Mark an update as solved. Triggers the rating-request email if the customer has rating enabled.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/updates/(?P<update_id>\d+)/reopen' => array(
				'summary' => __( 'Re-open a previously solved update so customers and staff can resume the thread.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/customer-updates'                  => array(
				'summary' => __( 'Customer-side write endpoint — creates a new update or replies to an existing one. Accepts logged-in customers and guests (via order_key).', 'order-updates-for-woo' ),
				'params'  => array(
					'order_id'  => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Order ID the customer is writing about.', 'order-updates-for-woo' ),
					),
					'message'   => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Customer message body.', 'order-updates-for-woo' ),
					),
					'title'     => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Subject line — required for new (non-reply) submissions.', 'order-updates-for-woo' ),
					),
					'update_id' => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Existing update ID to reply to. Omit for a brand-new submission.', 'order-updates-for-woo' ),
					),
					'order_key' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Guest auth: the order_key from the customer notification email link.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/rating' => array(
				'summary' => __( 'Submit a customer rating (1–5 stars) on a resolved update. Triggers the follow-up email.', 'order-updates-for-woo' ),
				'params'  => array(
					'stars'   => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Rating 1–5.', 'order-updates-for-woo' ),
					),
					'comment' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Optional rating comment.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/attachments'                       => array(
				'summary' => __( 'Upload an attachment for a note. Multipart upload — file under "file", context fields (order_id, update_id, note_id, note_type) alongside.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/attachments/(?P<attachment_id>\d+)/download' => array(
				'summary' => __( 'Stream a stored attachment back to the requester. Cap-checked; customer-side requests need a signed token.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/attachments/(?P<attachment_id>\d+)' => array(
				'summary' => __( 'Delete a stored attachment. Removes both the DB row and the file on disk.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/order-updates'                     => array(
				'summary' => __( 'List every update (with notes + assignee + counts) for an order — used by the admin meta box and the customer portal.', 'order-updates-for-woo' ),
				'params'  => array(
					'order_id' => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Order to list updates for.', 'order-updates-for-woo' ),
					),
					'limit'    => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Max updates to return. Defaults to the configured page size.', 'order-updates-for-woo' ),
					),
					'offset'   => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Pagination offset for "Load more" calls.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/customer-notes/previous' => array(
				'summary' => __( 'Fetch the next older page of customer-thread notes — backs the "Load previous" button on long threads.', 'order-updates-for-woo' ),
				'params'  => array(
					'before_note_id' => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Return notes older than this id.', 'order-updates-for-woo' ),
					),
					'limit'          => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Page size. Defaults to the configured customer-notes page size.', 'order-updates-for-woo' ),
					),
					'order_key'      => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Guest auth — same order_key as on /customer-updates.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)/history' => array(
				'summary' => __( 'Return the edit history of a customer note (every prior body + who edited + when). Drives the "(edited) View history" panel.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/updates/(?P<update_id>\d+)/history' => array(
				'summary' => __( 'Action timeline for an update — every status change, assignee change, customer/staff event. Used by the Tracking Log tab.', 'order-updates-for-woo' ),
				'params'  => array(),
			),
			$prefix . '/updates/(?P<update_id>\d+)/staff-email-preference' => array(
				'summary' => __( 'Toggle the current staff member\'s personal "email me about this update" preference. Per-user, per-update.', 'order-updates-for-woo' ),
				'params'  => array(
					'enabled' => array(
						'type'        => 'boolean',
						'required'    => true,
						'description' => __( 'Whether the current user wants emails for this update.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/customer-email-preference'         => array(
				'summary' => __( 'Customer-side opt-out toggle. Saves on user_meta for logged-in customers, post_meta for guests.', 'order-updates-for-woo' ),
				'params'  => array(
					'order_id'  => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Order the preference applies to.', 'order-updates-for-woo' ),
					),
					'enabled'   => array(
						'type'        => 'boolean',
						'required'    => true,
						'description' => __( 'true → customer wants email notifications; false → opt out.', 'order-updates-for-woo' ),
					),
					'order_key' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Required for guests — the order_key from the email link.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/customer-thread/poll'              => array(
				'summary' => __( 'Customer-side polling endpoint. Returns notes added since since_note_id so the customer page picks up staff replies in near-realtime.', 'order-updates-for-woo' ),
				'params'  => array(
					'order_id'      => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => __( 'Order whose threads the customer is viewing.', 'order-updates-for-woo' ),
					),
					'since_note_id' => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'Map of update_id → highest note_id the client has seen.', 'order-updates-for-woo' ),
					),
					'order_key'     => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Guest auth.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/assignee-search'                   => array(
				'summary' => __( 'Type-ahead search across the configured Internal Team roles. Backs the inline assignee picker on update cards.', 'order-updates-for-woo' ),
				'params'  => array(
					'q' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Search term — matches against display name and email.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/analytics/summary'                 => array(
				'summary' => __( 'Top-line analytics for the dashboard widget — open / solved / pending counts and SLA stats over the date window.', 'order-updates-for-woo' ),
				'params'  => array(
					'from' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Start date (YYYY-MM-DD). Defaults to 30 days ago.', 'order-updates-for-woo' ),
					),
					'to'   => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'End date (YYYY-MM-DD). Defaults to today.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/analytics/by-date'                 => array(
				'summary' => __( 'Time-series analytics — daily open/solved counts inside the date window, ready for a line chart.', 'order-updates-for-woo' ),
				'params'  => array(
					'from' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Start date (YYYY-MM-DD).', 'order-updates-for-woo' ),
					),
					'to'   => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'End date (YYYY-MM-DD).', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/analytics/products'                => array(
				'summary' => __( 'Top products by update volume — shows which SKUs are generating the most customer questions.', 'order-updates-for-woo' ),
				'params'  => array(
					'from'  => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Start date.', 'order-updates-for-woo' ),
					),
					'to'    => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'End date.', 'order-updates-for-woo' ),
					),
					'limit' => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => __( 'How many top products to return. Defaults to 10.', 'order-updates-for-woo' ),
					),
				),
			),
			$prefix . '/analytics/assignees'               => array(
				'summary' => __( 'Per-assignee breakdown — number of updates handled, average time-to-solve, ratings received.', 'order-updates-for-woo' ),
				'params'  => array(
					'from' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Start date.', 'order-updates-for-woo' ),
					),
					'to'   => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'End date.', 'order-updates-for-woo' ),
					),
				),
			),
		);
	}

	/**
	 * Mirror of ApiSettingsService::source_label_for() — duplicated here so
	 * the controller doesn't have to expose the service's private helper.
	 *
	 * @param string[] $methods HTTP methods the route accepts.
	 */
	private function source_label_for( array $methods ): string {
		$query_only = array_diff( $methods, array( 'GET', 'DELETE' ) ) === array();

		return $query_only
			? __( 'Query', 'order-updates-for-woo' )
			: __( 'Body', 'order-updates-for-woo' );
	}

	/**
	 * Enqueue this section's CSS/JS on the WC settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'order_updates_for_woo' !== $tab || ApiSettingsService::SECTION_ID !== $section ) {
			return;
		}

		$js_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/api-tab.js';

		wp_enqueue_script(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/js/api-tab.js' ),
			array(),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return ApiSettingsService::SECTION_ID;
	}

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return $this->service->label();
	}

	/**
	 * WooCommerce settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->service->get_settings();
	}

	/**
	 * Render the section body.
	 */
	public function render(): void {
		View::render(
			'src/Admin/Settings/Views/api/endpoints',
			array(
				'namespace' => $this->service->namespace(),
				'base_url'  => $this->service->base_url(),
				'endpoints' => $this->service->endpoints(),
			)
		);
	}
}
