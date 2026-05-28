<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-hosted update channel for copies installed from GitHub, before the
 * plugin is listed on WordPress.org.
 *
 * The Plugin Update Checker library lives in lib/plugin-update-checker/ and
 * is bundled ONLY in the GitHub build. The WordPress.org build strips that
 * folder (WP.org forbids external update sources), so boot() finds no loader
 * and quietly does nothing — installs on WP.org get their updates the native
 * way, by slug.
 */
final class GitHubUpdater {

	private const REPO_URL = 'https://github.com/ankur0007/order-updates-for-woo/';
	private const SLUG     = 'order-updates-for-woo';

	public static function boot(): void {
		$loader = ORDER_UPDATES_FOR_WOO_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

		// No bundled library = WordPress.org build (or library removed). Stand down.
		if ( ! is_readable( $loader ) ) {
			return;
		}

		require_once $loader;

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			ORDER_UPDATES_FOR_WOO_FILE,
			self::SLUG
		);

		// Serve the built zip attached to each GitHub release, not the
		// auto-generated source archive (which carries docs, tests, dev deps).
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
