<?php
/**
 * Onboarding banner markup.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$nonce = wp_create_nonce( 'wp_rest' );
?>
<div class="awts_onboarding" data-awts-nonce="<?php echo esc_attr( $nonce ); ?>">
	<div class="awts_onboarding_header">
		<h3><?php esc_html_e( 'Welcome to Order Updates', 'order-updates-for-woo' ); ?></h3>
		<button type="button" class="awts_onboarding_dismiss" aria-label="<?php echo esc_attr__( 'Got it, let\'s go!', 'order-updates-for-woo' ); ?>">&times;</button>
	</div>
	<p class="awts_onboarding_intro"><?php esc_html_e( 'Track order progress, collaborate with your team, and keep customers informed — all from the order screen.', 'order-updates-for-woo' ); ?></p>
	<div class="awts_onboarding_steps">
		<div class="awts_onboarding_step">
			<span class="awts_onboarding_step_num">1</span>
			<div>
				<strong><?php esc_html_e( 'Create an update', 'order-updates-for-woo' ); ?></strong>
				<p><?php esc_html_e( 'Click "Add new update" below to log progress, issues, or notes for any order.', 'order-updates-for-woo' ); ?></p>
			</div>
		</div>
		<div class="awts_onboarding_step">
			<span class="awts_onboarding_step_num">2</span>
			<div>
				<strong><?php esc_html_e( 'Assign to team members', 'order-updates-for-woo' ); ?></strong>
				<p><?php esc_html_e( 'Assign updates to team members so everyone knows who is responsible.', 'order-updates-for-woo' ); ?></p>
			</div>
		</div>
		<div class="awts_onboarding_step">
			<span class="awts_onboarding_step_num">3</span>
			<div>
				<strong><?php esc_html_e( 'Communicate internally', 'order-updates-for-woo' ); ?></strong>
				<p><?php esc_html_e( 'Use internal notes for team discussion. Use customer notes to share updates with buyers.', 'order-updates-for-woo' ); ?></p>
			</div>
		</div>
		<div class="awts_onboarding_step">
			<span class="awts_onboarding_step_num">4</span>
			<div>
				<strong><?php esc_html_e( 'Notify customers', 'order-updates-for-woo' ); ?></strong>
				<p><?php esc_html_e( 'Write a customer note and click "Notify customer via email" to send them an update.', 'order-updates-for-woo' ); ?></p>
			</div>
		</div>
	</div>
	<button type="button" class="awts_btn awts_btn_primary awts_onboarding_dismiss"><?php esc_html_e( 'Got it, let\'s go!', 'order-updates-for-woo' ); ?></button>
</div>
