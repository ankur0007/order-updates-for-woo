<?php
/**
 * Plugin Name: Order Updates for WooCommerce — Customer Support
 * Description: Customer-facing and internal order updates for WooCommerce.
 * Version: 1.0.0
 * Author: the ank
 * Text Domain: order-updates-for-woo
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define( 'ORDER_UPDATES_FOR_WOO_FILE', __FILE__ );
define( 'ORDER_UPDATES_FOR_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORDER_UPDATES_FOR_WOO_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

add_action('plugins_loaded', 'order_updates_for_woo_load_plugin');
add_action('before_woocommerce_init', 'order_updates_for_woo_declare_hpos_compatibility');
add_action('admin_init', 'order_updates_for_woo_boot_github_updater');
register_activation_hook(__FILE__, 'order_updates_for_woo_activate');

/**
 * Check GitHub Releases for updates on installs that came from GitHub.
 *
 * Skipped if the host site explicitly opts out, and a no-op on the
 * WordPress.org build (which ships without the update-checker library).
 */
function order_updates_for_woo_boot_github_updater(): void {
	if ( defined( 'ORDER_UPDATES_FOR_WOO_DISABLE_GITHUB_UPDATER' ) && ORDER_UPDATES_FOR_WOO_DISABLE_GITHUB_UPDATER ) {
		return;
	}

	// The WordPress.org build strips src/Updater. Skip the call when the file
	// is absent so the WP.org copy never references an external update checker.
	if ( ! is_readable( ORDER_UPDATES_FOR_WOO_PATH . 'src/Updater/GitHubUpdater.php' ) ) {
		return;
	}

	\OrderUpdatesForWoo\Updater\GitHubUpdater::boot();
}

// Multisite: when a new subsite is provisioned on a network where this
// plugin is already active, fire a clean table install in that subsite's
// context. The `init` hook would lazily create tables on the new site's
// first request anyway — this just makes sure they exist immediately.
add_action('wp_initialize_site', 'order_updates_for_woo_install_on_new_subsite', 20, 1);

function order_updates_for_woo_install_on_new_subsite($new_site): void {
	if (! function_exists('is_plugin_active_for_network')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Only act when the plugin is genuinely active for the new site.
	// If the admin didn't network-activate, the new subsite gets the
	// plugin on its own terms via standard per-site activation.
	$plugin = plugin_basename(__FILE__);
	if (! is_plugin_active_for_network($plugin)) {
		return;
	}

	$site_id = is_object($new_site) ? (int) $new_site->blog_id : (int) $new_site;
	if (! $site_id) {
		return;
	}

	switch_to_blog($site_id);
	try {
		// Tables auto-create on next `init` tick, but force a fast path here
		// so the new subsite is fully provisioned without waiting for a request.
		( new \OrderUpdatesForWoo\Shared\Updates\UpdatesTable() )->maybe_create_tables();
		( new \OrderUpdatesForWoo\Shared\Attachments\AttachmentsTable() )->maybe_create_tables();
		( new \OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupTable() )->maybe_create_table();
		\OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController::on_activation();
	} finally {
		restore_current_blog();
	}
}

function order_updates_for_woo_activate(): void {
	\OrderUpdatesForWoo\Welcome\Controllers\WelcomeController::set_redirect_flag();
	\OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController::on_activation();
}

register_deactivation_hook(__FILE__, 'order_updates_for_woo_deactivate');

/**
 * Runs when the plugin is deactivated (NOT when it is deleted).
 *
 * Stop scheduled events and flush rewrite rules so nothing keeps firing
 * in the background. Tables, options, attachments, and customer data
 * stay intact — uninstall.php is the only place that deletes user data.
 */
function order_updates_for_woo_deactivate(): void {
	\OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController::on_deactivation();

	// Stop the daily analytics cache warmup. Re-scheduled by AnalyticsController
	// on the next admin_init when the plugin is reactivated.
	wp_clear_scheduled_hook( \OrderUpdatesForWoo\Shared\Config\Constants::ANALYTICS_CRON_HOOK );
}

/**
 * Declare HPOS compatibility.
 */
function order_updates_for_woo_declare_hpos_compatibility(): void {
	if (! class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		__FILE__,
		true
	);
}

/**
 * Load the plugin after dependencies are available.
 */
function order_updates_for_woo_load_plugin(): void {
	if (! class_exists(\WooCommerce::class)) {
		add_action('admin_notices', 'order_updates_for_woo_missing_woocommerce_notice');

		return;
	}

	$plugin = new \OrderUpdatesForWoo\Plugin();
	$plugin->powerOn();
}

/**
 * Render an admin notice when WooCommerce is unavailable.
 */
function order_updates_for_woo_missing_woocommerce_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e('Order Updates for WooCommerce requires WooCommerce to be installed and active.', 'order-updates-for-woo'); ?>
		</p>
	</div>
	<?php
}
