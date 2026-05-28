<?php
/**
 * Admin order panel service.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders\Services;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\RestUrlHelper;
use OrderUpdatesForWoo\Shared\Language\Labels;
use OrderUpdatesForWoo\Helpers\HposHelper;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;

final class OrderEditorPanelService {
	public function __construct(
		private ?TeamRosterService $team_roster = null,
		private ?OrderUpdatesSettingsService $settings_service = null
	) {}

	private function settings_service(): OrderUpdatesSettingsService {
		if ( ! $this->settings_service instanceof OrderUpdatesSettingsService ) {
			$this->settings_service = new OrderUpdatesSettingsService();
		}

		return $this->settings_service;
	}

	private function team_roster(): TeamRosterService {
		if ( ! $this->team_roster instanceof TeamRosterService ) {
			$this->team_roster = new TeamRosterService();
		}

		return $this->team_roster;
	}
	public function get_labels(): array {
		return Labels::all();
	}

	/**
	 * Get the active order screen ID for classic or HPOS order editors.
	 */
	public function get_screen_id(): string {
		return HposHelper::order_edit_screen_id();
	}

	/**
	 * Determine if admin assets should load for the current screen.
	 */
	public function should_enqueue_assets(): bool {
		$screen = get_current_screen();

		return (bool) $screen && $screen->id === $this->get_screen_id();
	}

	/**
	 * Enqueue admin assets for the order updates panel.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'order-updates-for-woo-admin',
			AssetHelper::url( 'assets/Admin/css/update-meta-box.css' ),
			array(),
			AssetHelper::version( 'assets/Admin/css/update-meta-box.css' )
		);

		wp_enqueue_style(
			'order-updates-for-woo-admin-modern',
			AssetHelper::url( 'assets/Admin/css/update-meta-box-modern.css' ),
			array( 'order-updates-for-woo-admin' ),
			AssetHelper::version( 'assets/Admin/css/update-meta-box-modern.css' )
		);

		$panel_override_css = $this->build_panel_appearance_css();
		if ( '' !== $panel_override_css ) {
			wp_add_inline_style( 'order-updates-for-woo-admin-modern', $panel_override_css );
		}

		wp_enqueue_script(
			'order-updates-for-woo-admin-script',
			AssetHelper::url( 'assets/Admin/js/update-meta-box.js' ),
			array( 'jquery' ),
			AssetHelper::version( 'assets/Admin/js/update-meta-box.js' ),
			true
		);

		wp_localize_script(
			'order-updates-for-woo-admin-script',
			'awtsData',
			[
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'searchEndpoint' => RestUrlHelper::route( 'assignee-search' ),
				'listEndpoint' => RestUrlHelper::route( 'order-updates' ),
				'saveEndpoint' => RestUrlHelper::route( 'updates' ),
				'updateEndpointBase' => RestUrlHelper::updates_base(),
				'solveEndpointBase'  => RestUrlHelper::updates_base(),
				'reopenEndpointBase' => RestUrlHelper::updates_base(),
				'historyEndpointBase'  => RestUrlHelper::updates_base(),
				'deleteEndpointBase'   => RestUrlHelper::updates_base(),
				'notifyEndpointBase'   => RestUrlHelper::updates_base(),
				'notesEndpointBase'         => RestUrlHelper::updates_base(),
				'customerNotesEndpointBase' => RestUrlHelper::updates_base(),
				'attachmentsEndpoint'       => RestUrlHelper::route( 'attachments' ),
				'attachmentsEndpointBase'   => RestUrlHelper::attachments_base(),
				'attachmentMaxBytes'        => \OrderUpdatesForWoo\Shared\Attachments\AttachmentService::max_bytes(),
				'attachmentMaxFiles'        => \OrderUpdatesForWoo\Shared\Config\Variables::getMaxAttachmentFiles(),
				'attachmentAllowedMime'     => \OrderUpdatesForWoo\Shared\Attachments\AttachmentService::allowed_mime_types(),
				'team'                      => $this->team_roster()->get_team_members(),
				'currentUserId'             => get_current_user_id(),
				// Mirror the Restricted-features toggles into JS so client-side
				// guards still apply when the cached DOM has stale edit buttons.
				'allowNoteEdit'             => $this->settings_service()->allow_note_edit(),
				'allowNoteDelete'           => $this->settings_service()->allow_note_delete(),
				'successMessage' => __('Update saved successfully.', 'order-updates-for-woo'),
				'heartbeatKey'   => \OrderUpdatesForWoo\Shared\Config\Constants::HEARTBEAT_KEY,
				'emailPrefUrl'   => \OrderUpdatesForWoo\Helpers\RestUrlHelper::route( 'customer-email-preference' ),
				'strings' => apply_filters('order_updates_for_woo_admin_strings', $this->get_labels()),
			]
		);
	}

	/**
	 * Build inline CSS overrides for the admin-configured note panel
	 * backgrounds. Empty values fall through to the defaults baked into
	 * update-meta-box-modern.css — we only emit a rule per field that was
	 * actually customized. Colors are sanitized via `sanitize_hex_color()`;
	 * image URLs via `esc_url_raw()`. Both are belt-and-braces safe:
	 * unsanitizable values render as the CSS default.
	 */
	private function build_panel_appearance_css(): string {
		$appearance = $this->settings_service()->note_panel_appearance();

		$internal_bg    = sanitize_hex_color( $appearance['internal_bg'] );
		$customer_bg    = sanitize_hex_color( $appearance['customer_bg'] );
		$internal_image = esc_url_raw( $appearance['internal_image'] );
		$customer_image = esc_url_raw( $appearance['customer_image'] );

		$root_vars    = array();
		$panel_blocks = array();

		if ( $internal_bg ) {
			$root_vars[] = '--awts-internal-bg: ' . $internal_bg . ';';
		}
		if ( $customer_bg ) {
			$root_vars[] = '--awts-customer-bg: ' . $customer_bg . ';';
		}

		// Custom raster images get a fixed 180px tile + a 75% white overlay,
		// so the image shows through at ~25% strength and stays a pattern
		// instead of stretching across the panel.
		if ( $internal_image ) {
			$panel_blocks[] = ".awts_notes_wrap { background-image: linear-gradient(rgba(255,255,255,0.75), rgba(255,255,255,0.75)), url('" . $internal_image . "'); background-repeat: repeat; background-size: auto, 180px; }";
		}
		if ( $customer_image ) {
			$panel_blocks[] = ".awts_customer_notes_wrap { background-image: linear-gradient(rgba(255,255,255,0.75), rgba(255,255,255,0.75)), url('" . $customer_image . "'); background-repeat: repeat; background-size: auto, 180px; }";
		}

		$css = '';

		if ( ! empty( $root_vars ) ) {
			$css .= '.awts_card { ' . implode( ' ', $root_vars ) . ' }';
		}

		if ( ! empty( $panel_blocks ) ) {
			$css .= ' ' . implode( ' ', $panel_blocks );
		}

		return $css;
	}
}
