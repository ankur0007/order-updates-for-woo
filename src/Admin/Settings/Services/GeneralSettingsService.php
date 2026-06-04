<?php
/**
 * General settings — feature toggles + behaviour defaults.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Admin\Settings\Fields\StatusListField;
use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the general section.
 */
final class GeneralSettingsService {
	public const SECTION_ID = '';

	/**
	 * Inject dependencies.
	 *
	 * @param ?OrderUpdatesSettingsService $settings_service Injected dependency.
	 */
	public function __construct(
		private ?OrderUpdatesSettingsService $settings_service = null
	) {}

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'General', 'order-updates-for-woo' );
	}

	/**
	 * Lazily resolve the shared settings service.
	 */
	private function settings_service(): OrderUpdatesSettingsService {
		if ( ! $this->settings_service instanceof OrderUpdatesSettingsService ) {
			$this->settings_service = new OrderUpdatesSettingsService();
		}

		return $this->settings_service;
	}

	/**
	 * Status key => label map for the select field.
	 *
	 * @return array<string, string> key => label, in admin-configured order.
	 */
	private function status_options_for_select(): array {
		$options = array();

		foreach ( $this->settings_service()->get_statuses() as $status ) {
			$options[ $status['key'] ] = $status['label'];
		}

		return $options;
	}

	/** The status key pre-selected for customer-initiated updates. */
	private function first_status_key(): string {
		// "Notice" is the seeded default for customer-initiated updates —
		// see Constants::DEFAULT_CUSTOMER_STATUS_SEED_KEY. We pre-select it
		// in the dropdown so a fresh install matches the documented default
		// without the admin needing to touch the field.
		$statuses = $this->settings_service()->get_statuses();
		foreach ( $statuses as $status ) {
			if ( Constants::DEFAULT_CUSTOMER_STATUS_SEED_KEY === ( $status['key'] ?? '' ) ) {
				return (string) $status['key'];
			}
		}

		return (string) ( $statuses[0]['key'] ?? '' );
	}

	/**
	 * Settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$fields = array(
			// Group 1 — Update form fields. Admin chooses which fields show
			// up when the team composes an update. Pure store-configuration
			// surface; no customer-facing copy or behavior lives here.
			array(
				'name' => __( 'Update form fields', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Control which fields appear when your team creates an update.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_update_form_section',
			),
			array(
				'name'    => __( 'Enable assignee field', 'order-updates-for-woo' ),
				'desc'    => __( 'Let your team give each update to a specific person, so everyone knows who is handling it.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_assignee',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Enable color field', 'order-updates-for-woo' ),
				'desc'    => __( 'Let your team add a color label to each update, to flag or group them at a glance.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_color',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Enable internal note field', 'order-updates-for-woo' ),
				'desc'    => __( 'Let your team write private notes on an update. Only staff can see these — never the customer.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_internal_note',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Enable customer note field', 'order-updates-for-woo' ),
				'desc'    => __( 'Let your team message the customer on an update — the customer sees these notes and can reply. Turn this off only if you want staff-only notes with no customer conversation.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_customer_note',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Enable solved state', 'order-updates-for-woo' ),
				'desc'    => __( 'Let your team mark an update as solved when it is done. A solved update closes the conversation until someone reopens it.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_solved_state',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'     => __( 'Statuses', 'order-updates-for-woo' ),
				'desc'     => __( 'These appear in the status dropdown when your team creates an update. Drag to reorder; the order here is the order shown in the form.', 'order-updates-for-woo' ),
				'id'       => Constants::STATUSES_OPTION,
				'type'     => StatusListField::FIELD_TYPE,
				'desc_tip' => false,
			),
			array(
				'name'     => __( 'Default status for customer-initiated updates', 'order-updates-for-woo' ),
				'desc'     => __( 'Customers don\'t see the status picker. New updates they open are stamped with this status automatically.', 'order-updates-for-woo' ),
				'id'       => Constants::DEFAULT_CUSTOMER_STATUS_OPTION,
				'type'     => 'select',
				'options'  => $this->status_options_for_select(),
				'default'  => $this->first_status_key(),
				'desc_tip' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_update_form_section',
			),

			// Note-panel appearance — admin overrides for the two note panels
			// on the order edit screen. Empty values fall through to the CSS
			// defaults (slate for internal, teal for customer). Image fields
			// get a "Choose image" media-library picker via JS at runtime.
			array(
				'name' => __( 'Note panel appearance', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Customize the background color and pattern image on the internal-notes and customer-notes panels on the order edit screen. Leave any field blank to use the default look.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_note_panel_appearance_section',
			),
			array(
				'name'    => __( 'Internal notes background color', 'order-updates-for-woo' ),
				'desc'    => __( 'Solid background color behind the internal-notes thread.', 'order-updates-for-woo' ),
				'id'      => Constants::NOTE_PANEL_INTERNAL_BG_OPTION,
				'type'    => 'color',
				'default' => '',
			),
			array(
				'name'     => __( 'Internal notes background image', 'order-updates-for-woo' ),
				'desc'     => __( 'Optional. Image used as a repeating background pattern on the internal-notes panel. Click "Choose image" to pick from the Media Library, or leave blank for the default chat pattern.', 'order-updates-for-woo' ),
				'id'       => Constants::NOTE_PANEL_INTERNAL_IMG_OPTION,
				'type'     => 'text',
				'default'  => '',
				'css'      => 'width:100%; max-width:520px;',
				'desc_tip' => false,
				'class'    => 'awts-media-picker',
			),
			array(
				'name'    => __( 'Customer notes background color', 'order-updates-for-woo' ),
				'desc'    => __( 'Solid background color behind the customer-notes thread.', 'order-updates-for-woo' ),
				'id'      => Constants::NOTE_PANEL_CUSTOMER_BG_OPTION,
				'type'    => 'color',
				'default' => '',
			),
			array(
				'name'     => __( 'Customer notes background image', 'order-updates-for-woo' ),
				'desc'     => __( 'Optional. Image used as a repeating background pattern on the customer-notes panel. Click "Choose image" to pick from the Media Library, or leave blank for the default chat pattern.', 'order-updates-for-woo' ),
				'id'       => Constants::NOTE_PANEL_CUSTOMER_IMG_OPTION,
				'type'     => 'text',
				'default'  => '',
				'css'      => 'width:100%; max-width:520px;',
				'desc_tip' => false,
				'class'    => 'awts-media-picker',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_note_panel_appearance_section',
			),

		);

		return $fields;
	}
}
