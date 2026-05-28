<?php
/**
 * Server-side replacement for WooCommerce's blocking edit-lock modal.
 *
 * WooCommerce shows a full-screen dialog whenever another team member is
 * editing the same order. That blocks the whole screen — including the
 * Order Updates notes panel, so teammates can't even chat about who's
 * taking over.
 *
 * This class reads the lock state directly from WC's EditLock service on
 * each page load and renders our own non-blocking yellow banner instead.
 *
 * Two body classes drive the visual swap:
 *   - `awts-order-edit-screen` — added on EVERY order edit page load.
 *     The CSS uses it to suppress WC's modal at all times so the user
 *     never sees WC's dialog flash during the brief window between a
 *     heartbeat takeover and our reload.
 *   - `awts-order-locked` — added only when another user holds the lock.
 *     Drives the overlay, top padding, and the raised Order Updates
 *     meta box so chat keeps working over the dimmed page.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\HposHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderLockBanner {

	private const EDIT_LOCK_CLASS = '\\Automattic\\WooCommerce\\Internal\\Admin\\Orders\\EditLock';

	/**
	 * Per-request cache for the resolved order. Sentinel `false` means
	 * "computed, not on an order edit screen"; `\WC_Order` means "this
	 * is the order being edited"; `null` means "not computed yet."
	 *
	 * @var \WC_Order|false|null
	 */
	private $order_cache = null;

	/**
	 * Per-request cache for the lock holder. Sentinel `false` means
	 * "computed, no other holder"; `\WP_User` means "another user holds
	 * the lock"; `null` means "not computed yet."
	 *
	 * @var \WP_User|false|null
	 */
	private $lock_holder_cache = null;

	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'admin_body_class', [ $this, 'maybe_add_body_class' ] );
		// Run after WC's own admin_footer dialog (priority 10). Layout
		// doesn't depend on it (banner is position:fixed) but it keeps
		// the rendered DOM in a predictable order.
		add_action( 'admin_footer', [ $this, 'maybe_render_banner' ], 20 );
	}

	/**
	 * Resolve the order being edited on the current admin screen, with
	 * per-request memoization. Handles HPOS (`?page=wc-orders&action=edit&id=…`)
	 * and classic (`post.php?post=…&action=edit`). Returns null on the
	 * orders list page or anywhere else.
	 */
	private function get_current_order(): ?\WC_Order {
		if ( null !== $this->order_cache ) {
			return $this->order_cache ?: null;
		}

		$this->order_cache = false;

		if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== HposHelper::order_edit_screen_id() ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup, no state change.
		$order_id = 0;
		if ( 'post' === $screen->base ) {
			$order_id = absint( $_GET['post'] ?? 0 );
		} else {
			$action = sanitize_key( wp_unslash( (string) ( $_GET['action'] ?? '' ) ) );
			if ( 'edit' !== $action ) {
				return null;
			}
			$order_id = absint( $_GET['id'] ?? 0 );
		}
		// phpcs:enable

		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$this->order_cache = $order;
		return $order;
	}

	/**
	 * The `WP_User` holding the lock when somebody other than the current
	 * user is editing this order, or null otherwise.
	 */
	private function get_lock_holder(): ?\WP_User {
		if ( null !== $this->lock_holder_cache ) {
			return $this->lock_holder_cache ?: null;
		}

		$this->lock_holder_cache = false;

		$order = $this->get_current_order();
		if ( ! $order ) {
			return null;
		}

		if ( ! class_exists( self::EDIT_LOCK_CLASS ) || ! function_exists( 'wc_get_container' ) ) {
			return null;
		}

		$edit_lock = wc_get_container()->get( self::EDIT_LOCK_CLASS );
		if ( ! $edit_lock->is_locked_by_another_user( $order ) ) {
			return null;
		}

		$lock = $edit_lock->get_lock( $order );
		if ( ! $lock || empty( $lock['user_id'] ) ) {
			return null;
		}

		$user = get_user_by( 'id', (int) $lock['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$this->lock_holder_cache = $user;
		return $user;
	}

	/**
	 * Add body classes so the CSS knows when to suppress WC's modal
	 * (always on order edit screens) and when to show the banner
	 * scaffolding (only when another user holds the lock).
	 */
	public function maybe_add_body_class( string $classes ): string {
		if ( ! $this->get_current_order() ) {
			return $classes;
		}

		$extra = ' awts-order-edit-screen';
		if ( $this->get_lock_holder() ) {
			$extra .= ' awts-order-locked';
		}

		return trim( $classes . $extra );
	}

	/**
	 * Enqueue banner styling and the heartbeat-reload script on every
	 * order edit screen — not only when the banner is currently visible.
	 *
	 * The script also has to run for the lock holder, so we can detect
	 * the moment a teammate takes over and reload into the banner state.
	 * Localizes the rendered holder name so the JS can tell "same lock
	 * holder, no change" (WC heartbeats `error` on every tick while the
	 * lock stays held — reloading on those would loop forever) apart
	 * from a real state change.
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->get_current_order() ) {
			return;
		}

		wp_enqueue_style(
			'order-updates-for-woo-order-lock',
			AssetHelper::url( 'assets/Admin/css/order-lock-banner.css' ),
			[],
			AssetHelper::version( 'assets/Admin/css/order-lock-banner.css' )
		);

		wp_enqueue_script(
			'order-updates-for-woo-order-lock',
			AssetHelper::url( 'assets/Admin/js/order-lock-banner.js' ),
			[ 'jquery', 'heartbeat' ],
			AssetHelper::version( 'assets/Admin/js/order-lock-banner.js' ),
			true
		);

		$holder = $this->get_lock_holder();
		wp_localize_script(
			'order-updates-for-woo-order-lock',
			'awtsOrderLockData',
			[
				'currentHolderName' => $holder ? $holder->display_name : '',
			]
		);
	}

	/**
	 * Render the banner + overlay markup. Mirrors WC's own dialog URLs
	 * (Take over: `claim-lock=1` + nonce, Go back: HTTP referer) so the
	 * server-side flow lands in the same handlers WC already uses.
	 */
	public function maybe_render_banner(): void {
		$user = $this->get_lock_holder();
		if ( ! $user ) {
			return;
		}

		$order = $this->get_current_order();
		if ( ! $order ) {
			return;
		}

		$edit_url      = $this->build_edit_url( $order );
		$take_over_url = add_query_arg(
			'claim-lock',
			'1',
			wp_nonce_url( $edit_url, 'claim-lock-' . $order->get_id() )
		);

		$sendback_url = wp_get_referer();
		if ( ! $sendback_url ) {
			$sendback_url = HposHelper::orders_list_url();
		}

		$avatar_src = get_option( 'show_avatars' ) ? get_avatar_url( $user->ID, [ 'size' => 64 ] ) : '';

		// translators: %s is the team member's display name.
		$message = sprintf( __( '%s is currently editing this order.', 'order-updates-for-woo' ), $user->display_name );

		?>
		<div class="awts_order_lock_overlay" aria-hidden="true"></div>
		<div class="awts_order_lock_banner" role="alert">
			<div class="awts_order_lock_banner__main">
				<span class="awts_order_lock_banner__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
				</span>
				<?php if ( $avatar_src ) : ?>
					<img class="awts_order_lock_banner__avatar" src="<?php echo esc_url( $avatar_src ); ?>" alt="">
				<?php endif; ?>
				<div class="awts_order_lock_banner__text">
					<p class="awts_order_lock_banner__msg">
						<?php echo esc_html( $message ); ?>
						<span class="awts_order_lock_banner__hint"><?php esc_html_e( 'You can still post notes below.', 'order-updates-for-woo' ); ?></span>
					</p>
					<p class="awts_order_lock_banner__footnote">
						<?php esc_html_e( 'Order Updates replaced WooCommerce\'s blocking modal with this banner so chat stays uninterrupted. Order fields stay locked until you take over.', 'order-updates-for-woo' ); ?>
					</p>
				</div>
			</div>
			<div class="awts_order_lock_banner__actions">
				<a class="button button-primary" href="<?php echo esc_url( $take_over_url ); ?>"><?php esc_html_e( 'Take over', 'order-updates-for-woo' ); ?></a>
				<a class="button" href="<?php echo esc_url( $sendback_url ); ?>"><?php esc_html_e( 'Go back', 'order-updates-for-woo' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * The canonical edit URL for the order. HPOS routes through the
	 * `wc-orders` admin page; classic uses `post.php`.
	 */
	private function build_edit_url( \WC_Order $order ): string {
		if ( HposHelper::is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );
		}
		return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
	}
}
