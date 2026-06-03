<?php
/**
 * Welcome page markup.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\HposHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$settings_url = (string) ( $view_data['settings_url'] ?? '' );
?>
<div class="wrap awts_welcome">
	<?php
	// WP injects admin notices right after the first `<h1>` inside `.wrap`
	// (or before `.wp-header-end`). The hidden h1 + wp-header-end here keeps
	// notices above the hero card instead of landing inside it.
	?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Welcome to Order Updates for WooCommerce', 'order-updates-for-woo' ); ?></h1>
	<hr class="wp-header-end" />

	<div class="awts_welcome_inner">

		<!-- Hero -->
		<div class="awts_welcome_hero">
			<p class="awts_welcome_hero__title"><?php esc_html_e( 'Welcome to Order Updates for WooCommerce', 'order-updates-for-woo' ); ?></p>
			<p><?php esc_html_e( 'Track order progress, collaborate with your team, and keep customers informed — all from the order screen.', 'order-updates-for-woo' ); ?></p>
		</div>

		<!-- Steps -->
		<div class="awts_welcome_section">
			<h2><?php esc_html_e( 'How it works', 'order-updates-for-woo' ); ?></h2>
			<div class="awts_welcome_steps">
				<div class="awts_welcome_step">
					<div class="awts_welcome_step_icon">1</div>
					<h3><?php esc_html_e( 'Create an update', 'order-updates-for-woo' ); ?></h3>
					<p><?php esc_html_e( 'Open any order and click "Add new update" to log progress, issues, or notes. Assign it to a team member right away.', 'order-updates-for-woo' ); ?></p>
				</div>
				<div class="awts_welcome_step">
					<div class="awts_welcome_step_icon">2</div>
					<h3><?php esc_html_e( 'Collaborate internally', 'order-updates-for-woo' ); ?></h3>
					<p><?php esc_html_e( 'Use internal notes for team discussion. @mention teammates to loop them in — they\'ll get a highlighted notification.', 'order-updates-for-woo' ); ?></p>
				</div>
				<div class="awts_welcome_step">
					<div class="awts_welcome_step_icon">3</div>
					<h3><?php esc_html_e( 'Keep customers informed', 'order-updates-for-woo' ); ?></h3>
					<p><?php esc_html_e( 'Write a customer note and notify them via email. Customers can reply, attach files, and view the full thread — even as guests.', 'order-updates-for-woo' ); ?></p>
				</div>
				<div class="awts_welcome_step">
					<div class="awts_welcome_step_icon">4</div>
					<h3><?php esc_html_e( 'Resolve and collect feedback', 'order-updates-for-woo' ); ?></h3>
					<p><?php esc_html_e( 'Mark the update as resolved. The customer rates their experience and happy customers get a gentle nudge to share their review.', 'order-updates-for-woo' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Features -->
		<div class="awts_welcome_section">
			<h2><?php esc_html_e( 'Everything you need', 'order-updates-for-woo' ); ?></h2>
			<div class="awts_welcome_features">
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#9998;</span>
					<div>
						<strong><?php esc_html_e( 'Order updates', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Create, edit, and track updates for every order with color-coded cards and a full audit trail.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#128101;</span>
					<div>
						<strong><?php esc_html_e( 'Team assignments &amp; @mentions', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Assign updates to team members and @mention colleagues in internal notes. Mentions are highlighted inline so nothing gets missed.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#128172;</span>
					<div>
						<strong><?php esc_html_e( 'Customer portal', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Customers get a secure, shareable link to view and reply to their update thread — no account required for guests.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#128206;</span>
					<div>
						<strong><?php esc_html_e( 'Attachments', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Staff and customers can attach files to notes. Attachments are stored securely and removed when the update is deleted.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#11088;</span>
					<div>
						<strong><?php esc_html_e( 'Customer ratings', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Customers rate resolved updates. Promoters get a prompt to share their review; detractors get an empathy follow-up.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
				<div class="awts_welcome_feature">
					<span class="awts_welcome_feature_icon">&#9993;</span>
					<div>
						<strong><?php esc_html_e( 'Email notifications', 'order-updates-for-woo' ); ?></strong>
						<p><?php esc_html_e( 'Notify customers, assignees, and admins at every step — new update, customer reply, resolution, and rating follow-up.', 'order-updates-for-woo' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<!-- More features (dense list for the diligent reader) -->
		<div class="awts_welcome_section">
			<h2><?php esc_html_e( 'More features', 'order-updates-for-woo' ); ?></h2>
			<ul class="awts_welcome_more">
				<li><strong><?php esc_html_e( 'Realtime updates', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'new messages appear without refreshing (30-second poll).', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Edit with history', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'staff and customers can edit notes; every revision is preserved.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Tracking log', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'full per-update lifecycle: status changes, reassignments, ratings, reopens.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Status workflow', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'customizable status options with color-coded pills.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Customer-initiated updates', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'customers can open a new request from their portal.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Per-update email mute', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'staff can silence notifications for one update without affecting others.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Admin bar badge', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'unread mentions and assignments visible from any admin screen.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Analytics dashboard', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'counts, average ratings, date filters.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Round-robin assignment', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'auto-distribute new updates across a team pool.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Deleted-update audit', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'deleted updates stay on the order audit trail.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'HPOS-ready', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'works with WooCommerce High-Performance Order Storage.', 'order-updates-for-woo' ); ?></li>
				<li><strong><?php esc_html_e( 'Translation-ready', 'order-updates-for-woo' ); ?></strong> &mdash; <?php esc_html_e( 'full .pot file; community translations land via translate.wordpress.org.', 'order-updates-for-woo' ); ?></li>
			</ul>
		</div>

		<!-- Shortcode -->
		<div class="awts_welcome_section">
			<h2><?php esc_html_e( 'Embed the customer portal anywhere', 'order-updates-for-woo' ); ?></h2>
			<div class="awts_welcome_shortcode">
				<p><?php esc_html_e( 'Add the customer portal to any page — including pages built with Elementor, Divi, or Gutenberg — using this shortcode:', 'order-updates-for-woo' ); ?></p>
				<code class="awts_welcome_shortcode_tag">[order_updates_portal]</code>
				<p class="awts_welcome_shortcode_hint"><?php esc_html_e( 'The order ID is detected automatically from the URL. You can also pass it directly:', 'order-updates-for-woo' ); ?> <code>[order_updates_portal order_id="123"]</code></p>
			</div>
		</div>

		<!-- Newsletter -->
		<div class="awts_welcome_section awts_welcome_newsletter">
			<?php
			$newsletter_email = (string) get_option( 'order_updates_for_woo_newsletter_email', '' );
			?>
			<h2><?php esc_html_e( 'Stay in the loop', 'order-updates-for-woo' ); ?></h2>
			<p><?php esc_html_e( 'Subscribe to get feature updates, tips, and early access to new releases. No spam, unsubscribe anytime.', 'order-updates-for-woo' ); ?></p>
			<?php if ( $newsletter_email ) : ?>
				<p class="awts_welcome_subscribed">
					<?php esc_html_e( 'You are already subscribed.', 'order-updates-for-woo' ); ?>
					(<?php echo esc_html( $newsletter_email ); ?>)
					&middot;
					<a href="#" id="awts_welcome_newsletter_change"><?php esc_html_e( 'Change email', 'order-updates-for-woo' ); ?></a>
				</p>
				<p id="awts_welcome_newsletter_feedback" class="awts_welcome_feedback"></p>
			<?php else : ?>
				<div class="awts_welcome_newsletter_form">
					<input
						type="email"
						id="awts_welcome_newsletter_email"
						placeholder="<?php echo esc_attr__( 'Your email address', 'order-updates-for-woo' ); ?>"
					>
					<button
						type="button"
						id="awts_welcome_newsletter_subscribe"
						class="button button-primary"
					><?php esc_html_e( 'Subscribe', 'order-updates-for-woo' ); ?></button>
				</div>
				<p id="awts_welcome_newsletter_feedback" class="awts_welcome_feedback"></p>
			<?php endif; ?>
		</div>

		<!-- CTA -->
		<div class="awts_welcome_cta">
			<a href="<?php echo esc_url( $settings_url ); ?>" class="button"><?php esc_html_e( 'Configure settings', 'order-updates-for-woo' ); ?></a>
			<a href="<?php echo esc_url( HposHelper::orders_list_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Go to orders', 'order-updates-for-woo' ); ?></a>
		</div>

	</div>
</div>
