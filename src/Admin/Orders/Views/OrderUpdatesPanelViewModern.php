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
$shared_link            = isset($view_data['shared_link']) && is_array($view_data['shared_link']) ? $view_data['shared_link'] : array();
$link_days_left         = isset($shared_link['days_left']) ? (int) $shared_link['days_left'] : 0;
$link_default_days      = isset($shared_link['default_days']) ? (int) $shared_link['default_days'] : 30;
$link_expiry_endpoint   = isset($shared_link['expiry_endpoint']) ? (string) $shared_link['expiry_endpoint'] : '';
$link_regen_endpoint    = isset($shared_link['regenerate_endpoint']) ? (string) $shared_link['regenerate_endpoint'] : '';

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
		<div
			class="awts_panel__customer_link"
			data-awts-link-expiry-endpoint="<?php echo esc_url( $link_expiry_endpoint ); ?>"
			data-awts-link-regenerate-endpoint="<?php echo esc_url( $link_regen_endpoint ); ?>"
			style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;margin:0 0 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:13px;"
		>
			<strong><?php esc_html_e( 'Customers no-login chat link:', 'order-updates-for-woo' ); ?></strong>
			<code
				data-awts-link-display
				style="flex:1 1 240px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:2px 6px;background:#fff;border:1px solid #dcdcde;border-radius:3px;"
			><?php echo esc_html( $customer_url ); ?></code>
			<button
				type="button"
				class="button"
				data-awts-copy-link="<?php echo esc_attr( $customer_url ); ?>"
				data-copied-label="<?php echo esc_attr__( 'Copied!', 'order-updates-for-woo' ); ?>"
			><?php esc_html_e( 'Copy link', 'order-updates-for-woo' ); ?></button>

			<label style="display:flex;align-items:center;gap:6px;color:#646970;">
				<?php esc_html_e( 'Expires in', 'order-updates-for-woo' ); ?>
				<input
					type="number"
					min="1"
					max="365"
					step="1"
					value="<?php echo esc_attr( (string) ( $link_days_left > 0 ? $link_days_left : $link_default_days ) ); ?>"
					data-awts-link-days
					style="width:64px;"
				/>
				<?php esc_html_e( 'days', 'order-updates-for-woo' ); ?>
			</label>

			<button
				type="button"
				class="button button-link-delete"
				data-awts-link-regenerate
			><?php esc_html_e( 'Regenerate', 'order-updates-for-woo' ); ?></button>

			<span
				data-awts-link-status
				class="awts_panel__customer_link_status"
				style="color:#646970;flex-basis:100%;"
				role="status"
				aria-live="polite"
			></span>

			<div
				data-awts-link-confirm
				hidden
				style="flex-basis:100%;padding:10px 12px;margin-top:6px;background:#fff;border:1px solid #dcdcde;border-radius:4px;"
			>
				<p style="margin:0 0 8px;"><?php esc_html_e( 'Regenerate the link? The current one will stop working immediately.', 'order-updates-for-woo' ); ?></p>
				<label style="display:flex;align-items:center;gap:6px;margin:0 0 10px;">
					<input type="checkbox" data-awts-link-notify />
					<?php esc_html_e( 'Email the new link to the customer', 'order-updates-for-woo' ); ?>
				</label>
				<button type="button" class="button button-primary" data-awts-link-confirm-go><?php esc_html_e( 'Regenerate now', 'order-updates-for-woo' ); ?></button>
				<button type="button" class="button" data-awts-link-confirm-cancel><?php esc_html_e( 'Cancel', 'order-updates-for-woo' ); ?></button>
			</div>
		</div>
		<script>
		( function () {
			var panel = document.querySelector( '.awts_panel__customer_link' );
			if ( ! panel ) { return; }

			var copyBtn   = panel.querySelector( '[data-awts-copy-link]' );
			var display   = panel.querySelector( '[data-awts-link-display]' );
			var daysInput = panel.querySelector( '[data-awts-link-days]' );
			var regenBtn  = panel.querySelector( '[data-awts-link-regenerate]' );
			var status    = panel.querySelector( '[data-awts-link-status]' );

			var expiryEndpoint = panel.getAttribute( 'data-awts-link-expiry-endpoint' ) || '';
			var regenEndpoint  = panel.getAttribute( 'data-awts-link-regenerate-endpoint' ) || '';
			var nonce          = ( window.awtsData && window.awtsData.nonce ) || '';

			function setStatus( text ) {
				if ( status ) { status.textContent = text || ''; }
			}

			function applyState( payload ) {
				if ( ! payload ) { return; }
				if ( payload.url && display ) { display.textContent = payload.url; }
				if ( payload.url && copyBtn ) { copyBtn.setAttribute( 'data-awts-copy-link', payload.url ); }
				if ( daysInput && typeof payload.daysLeft !== 'undefined' ) {
					daysInput.value = String( payload.daysLeft );
				}
			}

			function post( url, body, onDone ) {
				if ( ! url ) { return; }
				fetch( url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin',
					body: JSON.stringify( body || {} )
				} ).then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, data: data };
					} );
				} ).then( function ( result ) {
					if ( result.ok ) {
						applyState( result.data );
						onDone( null, result.data );
					} else {
						var message = ( result.data && result.data.message ) || '<?php echo esc_js( __( 'Could not save. Please try again.', 'order-updates-for-woo' ) ); ?>';
						onDone( message, null );
					}
				} ).catch( function () {
					onDone( '<?php echo esc_js( __( 'Network error. Please try again.', 'order-updates-for-woo' ) ); ?>', null );
				} );
			}

			if ( copyBtn ) {
				var defaultLabel = copyBtn.textContent;
				var copiedLabel  = copyBtn.getAttribute( 'data-copied-label' ) || 'Copied!';
				copyBtn.addEventListener( 'click', function () {
					var url = copyBtn.getAttribute( 'data-awts-copy-link' ) || '';
					var done = function () {
						copyBtn.textContent = copiedLabel;
						setTimeout( function () { copyBtn.textContent = defaultLabel; }, 1500 );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( url ).then( done, function () {
							window.prompt( '', url );
						} );
						return;
					}
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
			}

			if ( daysInput ) {
				var lastSent = parseInt( daysInput.value, 10 );
				daysInput.addEventListener( 'change', function () {
					var days = parseInt( daysInput.value, 10 );
					if ( isNaN( days ) || days < 1 || days > 365 ) {
						daysInput.value = String( lastSent || 30 );
						return;
					}
					if ( days === lastSent ) { return; }
					setStatus( '<?php echo esc_js( __( 'Saving…', 'order-updates-for-woo' ) ); ?>' );
					post( expiryEndpoint, { days: days }, function ( err ) {
						if ( err ) { setStatus( err ); return; }
						lastSent = days;
						setStatus( '<?php echo esc_js( __( 'Saved.', 'order-updates-for-woo' ) ); ?>' );
						setTimeout( function () { setStatus( '' ); }, 2000 );
					} );
				} );
			}

			var confirmBox = panel.querySelector( '[data-awts-link-confirm]' );
			var notifyChk  = panel.querySelector( '[data-awts-link-notify]' );
			var confirmGo  = panel.querySelector( '[data-awts-link-confirm-go]' );
			var cancelBtn  = panel.querySelector( '[data-awts-link-confirm-cancel]' );

			if ( regenBtn && confirmBox ) {
				regenBtn.addEventListener( 'click', function () {
					if ( notifyChk ) { notifyChk.checked = false; }
					confirmBox.hidden = false;
				} );
			}

			if ( cancelBtn && confirmBox ) {
				cancelBtn.addEventListener( 'click', function () { confirmBox.hidden = true; } );
			}

			if ( confirmGo && confirmBox ) {
				confirmGo.addEventListener( 'click', function () {
					var days   = parseInt( ( daysInput && daysInput.value ) || '0', 10 );
					var notify = !! ( notifyChk && notifyChk.checked );
					confirmBox.hidden = true;
					setStatus( '<?php echo esc_js( __( 'Regenerating…', 'order-updates-for-woo' ) ); ?>' );
					post( regenEndpoint, { days: days, notify_customer: notify }, function ( err, data ) {
						if ( err ) { setStatus( err ); return; }
						var msg = ( data && data.emailQueued )
							? '<?php echo esc_js( __( 'New link generated and emailed to the customer.', 'order-updates-for-woo' ) ); ?>'
							: '<?php echo esc_js( __( 'New link generated. The old one no longer works.', 'order-updates-for-woo' ) ); ?>';
						setStatus( msg );
					} );
				} );
			}
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
