<?php
/**
 * Members settings — internal team roles + assignment rotation.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Admin\Settings\Fields\AssigneeRotationField;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembersSettingsService {
	public const SECTION_ID = 'members';

	public function label(): string {
		return __( 'Members', 'order-updates-for-woo' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'name' => __( 'Members & rotation', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Pick which roles count as your internal team and the order in which customer-submitted updates are assigned to them.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_members_section',
			),
			array(
				'name'     => __( 'Internal team roles', 'order-updates-for-woo' ),
				'desc'     => __( 'Choose which user roles count as your internal team. Members of these roles can be assigned to updates and tagged in internal notes.', 'order-updates-for-woo' ) . $this->team_refresh_link(),
				'id'       => TeamRosterService::ROLES_OPTION,
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'default'  => TeamRosterService::DEFAULT_ROLES,
				'options'  => $this->role_options(),
				'desc_tip' => false,
				'css'      => 'min-width:300px;',
			),
			array(
				'name'    => __( 'Assignment rotation', 'order-updates-for-woo' ),
				'desc'    => __( 'Customer-submitted updates rotate through the checked members in this order. Drag the handle to set priority. Members not yet in the rotation appear at the bottom — check to include them. If the rotation is empty, the first administrator receives every customer update.', 'order-updates-for-woo' ),
				'id'      => Constants::ASSIGNEE_PRIORITY_LIST_OPTION,
				'type'    => AssigneeRotationField::FIELD_TYPE,
				'default' => array(),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_members_section',
			),
		);
	}

	/**
	 * Help link rendered next to the team-roles field.
	 */
	private function team_refresh_link(): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                         => 'wc-settings',
					'tab'                          => 'order_updates_for_woo',
					'section'                      => self::SECTION_ID,
					'order_updates_for_woo_action' => 'refresh_team_roster',
				),
				admin_url( 'admin.php' )
			),
			'order_updates_for_woo_refresh_team_roster'
		);

		return sprintf(
			' <a href="%1$s" class="button button-secondary" style="margin-left:6px;">%2$s</a><br><span class="description">%3$s</span>',
			esc_url( $url ),
			esc_html__( 'Refresh team list', 'order-updates-for-woo' ),
			esc_html__( 'Team list refreshes automatically when staff is added, removed, or changes role. Use this if you ever see a stale list.', 'order-updates-for-woo' )
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function role_options(): array {
		$options = array();
		$roles   = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();

		foreach ( $roles as $slug => $role ) {
			$name                      = isset( $role['name'] ) ? translate_user_role( (string) $role['name'] ) : (string) $slug;
			$options[ (string) $slug ] = $name;
		}

		return $options;
	}
}
