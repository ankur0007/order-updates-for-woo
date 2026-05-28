<?php
/**
 * Cache settings — clear-cache actions, no persisted form fields.
 *
 * Each public clear method is the bridge between the admin button and the
 * underlying cache helper. Keeping the bridge here (not in the controller)
 * lets the same primitives be reused by addons or WP-CLI without going
 * through the WC settings page.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupDb;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CacheSettingsService {
	public const SECTION_ID = 'cache';

	public const ACTION_TEAM             = 'clear_team_cache';
	public const ACTION_ANALYTICS        = 'clear_analytics_cache';
	public const ACTION_REBUILD_ANALYTICS = 'rebuild_analytics_lookup';

	public function __construct(
		private TeamRosterService $team_roster,
		private AnalyticsLookupDb $analytics_lookup_db
	) {}

	public function label(): string {
		return __( 'Cache', 'order-updates-for-woo' );
	}

	/**
	 * No persisted fields on this tab — actions only.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * @return array<int, array{id:string, label:string, description:string}>
	 */
	public function action_buttons(): array {
		return array(
			array(
				'id'          => self::ACTION_TEAM,
				'label'       => __( 'Clear team roster cache', 'order-updates-for-woo' ),
				'description' => __( 'Force a fresh lookup of staff in the configured team roles. Use this if the team list looks stale.', 'order-updates-for-woo' ),
			),
			array(
				'id'          => self::ACTION_ANALYTICS,
				'label'       => __( 'Clear analytics cache', 'order-updates-for-woo' ),
				'description' => __( 'Re-aggregate the analytics summary on next dashboard load. Useful after bulk-importing or deleting historical data.', 'order-updates-for-woo' ),
			),
			array(
				'id'          => self::ACTION_REBUILD_ANALYTICS,
				'label'       => __( 'Rebuild analytics lookup', 'order-updates-for-woo' ),
				'description' => __( 'Drop and re-populate the precomputed analytics table from current updates. Runs in the background via Action Scheduler; safe on large stores. Use this if the dashboard numbers look out of step with reality.', 'order-updates-for-woo' ),
			),
		);
	}

	public function clear_team_cache(): void {
		$this->team_roster->flush_cache();
	}

	public function clear_analytics_cache(): void {
		$this->analytics_lookup_db->bust_cache();
	}

	public function rebuild_analytics_lookup(): void {
		$this->analytics_lookup_db->rebuild_from_scratch();
	}
}
