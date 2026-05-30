<?php
/**
 * Admin order updates panel view — modern layout.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\Icons;
use OrderUpdatesForWoo\Helpers\View;

if (! defined('ABSPATH')) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data           = isset($view_data) && is_array($view_data) ? $view_data : [];
$settings            = isset($view_data['settings']) && is_array($view_data['settings']) ? $view_data['settings'] : [];
$order_id            = isset($view_data['order_id']) ? absint($view_data['order_id']) : 0;
$order_updates       = isset($view_data['order_updates']) && is_array($view_data['order_updates']) ? $view_data['order_updates'] : [];
$order_updates_total = isset($view_data['order_updates_total']) ? absint($view_data['order_updates_total']) : count($order_updates);

$show_onboarding = isset($view_data['show_onboarding']) ? (bool) $view_data['show_onboarding'] : false;
$statuses        = isset($view_data['statuses']) && is_array($view_data['statuses']) ? $view_data['statuses'] : array();
$customer_url    = isset($view_data['customer_url']) ? (string) $view_data['customer_url'] : '';
$shared_link     = isset($view_data['shared_link']) && is_array($view_data['shared_link']) ? $view_data['shared_link'] : array();
$link_days_left  = isset($shared_link['days_left']) ? (int) $shared_link['days_left'] : 0;

// Build a color → status lookup once per render so every card view can
// resolve its label by a single array access. Lowercased to match the
// strtolower() compare in the card view; an addon that injects custom
// statuses with uppercase hex still maps correctly.
$status_lookup_by_color = array();
foreach ($statuses as $status) {
	$color = isset($status['color']) ? strtolower((string) $status['color']) : '';
	if ('' !== $color) {
		$status_lookup_by_color[$color] = $status;
	}
}

$settings = wp_parse_args(
	$settings,
	[
		'enable_assignee'            => true,
		'enable_color'               => true,
		'enable_internal_note'       => true,
		'enable_customer_note'       => true,
		'enable_solved_state'        => true,
		'allow_deletion'             => false,
	]
);
?>
<div class="awts_panel awts_container">

	<?php if ( $show_onboarding ) : ?>
		<?php View::render( 'src/Welcome/Views/OnboardingBannerView' ); ?>
	<?php endif; ?>

	<?php if ( '' !== $customer_url ) : ?>
		<!-- Stateful hash in order meta. Changing the expiry does not change this URL. -->
		<div class="awts_panel__customer_link" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;margin:0 0 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:13px;">
			<strong><?php esc_html_e( 'No-login chat link:', 'order-updates-for-woo' ); ?></strong>
			<code style="flex:1 1 240px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:2px 6px;background:#fff;border:1px solid #dcdcde;border-radius:3px;"><?php echo esc_html( $customer_url ); ?></code>
			<button
				type="button"
				class="button"
				data-awts-copy-link="<?php echo esc_attr( $customer_url ); ?>"
				data-copied-label="<?php echo esc_attr__( 'Copied!', 'order-updates-for-woo' ); ?>"
			><?php esc_html_e( 'Copy link', 'order-updates-for-woo' ); ?></button>
			<?php if ( $link_days_left > 0 ) : ?>
				<span class="awts_panel__customer_link_expiry" style="color:#646970;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days the link stays valid */
							_n( 'Expires in %d day', 'Expires in %d days', $link_days_left, 'order-updates-for-woo' ),
							$link_days_left
						)
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var btn = document.querySelector( '[data-awts-copy-link]' );
			if ( ! btn ) { return; }
			var defaultLabel = btn.textContent;
			var copiedLabel  = btn.getAttribute( 'data-copied-label' ) || 'Copied!';
			btn.addEventListener( 'click', function () {
				var url = btn.getAttribute( 'data-awts-copy-link' ) || '';
				var done = function () {
					btn.textContent = copiedLabel;
					setTimeout( function () { btn.textContent = defaultLabel; }, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( done, function () {
						window.prompt( '', url );
					} );
					return;
				}
				// Fallback for older browsers / non-HTTPS contexts.
				var ta = document.createElement( 'textarea' );
				ta.value = url;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				document.body.removeChild( ta );
			} );
		} )();
		</script>
	<?php endif; ?>

	<!-- Update cards -->
	<div class="awts_update_list">
		<?php if (! empty($order_updates)) : ?>
			<?php foreach ($order_updates as $card_variables) : ?>
				<?php View::render('src/Admin/Orders/Views/OrderUpdateCardViewModern', [
					'settings'               => $settings,
					'card_variables'         => $card_variables,
					'order_id'               => $order_id,
					'statuses'               => $statuses,
					'status_lookup_by_color' => $status_lookup_by_color,
				]); ?>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="awts_empty"><?php esc_html_e( 'No updates have been saved for this order yet.', 'order-updates-for-woo' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Load more -->
	<?php if ($order_updates_total > count($order_updates)) : ?>
		<div style="margin-bottom:12px;">
			<button
				type="button"
				class="awts_btn awts_btn_light awts_load_more_updates"
				data-awts-order-id="<?php echo esc_attr((string) $order_id); ?>"
				data-awts-offset="<?php echo esc_attr((string) count($order_updates)); ?>"
			><?php esc_html_e( 'Load more', 'order-updates-for-woo' ); ?></button>
		</div>
	<?php endif; ?>

	<!-- Add Update -->
	<div class="awts_form_trigger">
		<button type="button" class="awts_btn awts_btn_primary awts_toggle_form" data-awts-mode="add">
			<?php esc_html_e( 'Add new update', 'order-updates-for-woo' ); ?>
		</button>
		<button type="button" class="awts_refresh_updates" data-awts-order-id="<?php echo esc_attr((string) $order_id); ?>" title="<?php echo esc_attr__( 'Refresh updates', 'order-updates-for-woo' ); ?>">
			<?php echo Icons::dashicon( 'update-alt', __( 'Refresh updates', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</div>

</div>

<div class="awts_popover" hidden>
	<?php View::render('src/Admin/Orders/Views/OrderUpdateFormView', [
		'settings' => $settings,
		'order_id' => $order_id,
		'statuses' => isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array(),
	] ); ?>
</div>
