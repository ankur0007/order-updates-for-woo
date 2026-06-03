<?php
/**
 * "Are you enjoying Order Updates?" admin notice with a link to the
 * WordPress.org review page.
 *
 * Shown to staff users on the orders list and order edit screens once
 * the plugin has been installed long enough to be evaluated fairly and
 * the user has clearly engaged with the feature (at least a few updates
 * exist in the database). The notice is dismissable per user, with a
 * snooze option that re-shows after two weeks for people who want to
 * decide later instead of opting out for good.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notices;

use OrderUpdatesForWoo\Helpers\HposHelper;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Request Notice.
 */
final class ReviewRequestNotice {

	/** Hard floor on install age before we even consider asking. */
	private const MIN_DAYS_INSTALLED = 7;

	/** Number of update threads in the system before the notice kicks in. */
	private const MIN_UPDATE_COUNT = 3;

	/** "Maybe later" snooze duration. */
	private const SNOOZE_DAYS = 14;

	private const FIRST_SEEN_OPTION = 'order_updates_for_woo_review_notice_first_seen';

	private const STATE_USER_META = '_order_updates_for_woo_review_notice_state';

	/** WordPress.org review page — confirmed slug. */
	private const REVIEW_URL = 'https://wordpress.org/support/plugin/order-updates-for-woo/reviews/#new-post';

	/**
	 * Web3Forms access key — same key the public site uses. The key is
	 * already exposed in the docs site HTML, so embedding it here is not
	 * a leak. Web3Forms gates abuse via spam filters + per-key throttling.
	 */
	private const WEB3FORMS_KEY = 'bd8bf772-7e85-4641-b477-0d40c1d1b29b';

	private const NONCE_ACTION = 'order_updates_for_woo_review_notice';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 */
	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// Stamp the install time exactly once so MIN_DAYS_INSTALLED can be
		// measured relative to a stable baseline.
		add_action( 'admin_init', array( $this, 'stamp_first_seen' ) );

		add_action( 'admin_notices', array( $this, 'maybe_render' ) );

		// Three buttons on the notice — each posts back here.
		add_action( 'admin_init', array( $this, 'handle_action' ) );
	}

	/**
	 * Record the moment a logged-in admin first lands inside wp-admin while
	 * the plugin is active. Anchors the "installed for N days" check.
	 */
	public function stamp_first_seen(): void {
		if ( get_option( self::FIRST_SEEN_OPTION ) ) {
			return;
		}
		update_option( self::FIRST_SEEN_OPTION, time(), false );
	}

	/** Render the review-request admin notice when conditions are met. */
	public function maybe_render(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$dismiss_url = $this->action_url( 'dismiss' );
		$snooze_url  = $this->action_url( 'snooze' );
		$mark_rated  = $this->action_url( 'already' );
		$user        = wp_get_current_user();

		?>
		<div class="notice notice-info is-dismissible order-updates-for-woo-review-notice" data-awts-review-notice style="border-left-color: #2563eb;">
			<p style="font-size: 14px; line-height: 1.5;">
				<strong><?php esc_html_e( 'Enjoying Order Updates for WooCommerce?', 'order-updates-for-woo' ); ?></strong>
				<?php esc_html_e( 'Tell other store owners. Your rating shows up on WordPress.org and on the plugin\'s own site, so people can see real feedback from real users.', 'order-updates-for-woo' ); ?>
			</p>

			<!-- Star picker — click to expand the rating form. -->
			<div class="awts-stars" data-awts-stars role="radiogroup" aria-label="<?php esc_attr_e( 'Rate Order Updates for WooCommerce', 'order-updates-for-woo' ); ?>" style="display:flex;gap:4px;font-size:28px;line-height:1;margin:6px 0 4px;cursor:pointer;color:#cbd5e1;user-select:none;">
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<span data-awts-star="<?php echo esc_attr( (string) $i ); ?>" role="radio" tabindex="0" aria-label="
					<?php
						/* translators: %d is a number of stars */
						echo esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'order-updates-for-woo' ), $i ) );
					?>
						" style="transition:color .1s ease;">&#9733;</span>
				<?php endfor; ?>
			</div>

			<!-- Inline rating form — hidden until a star is clicked. -->
			<form data-awts-review-form style="display:none;margin:8px 0 4px;max-width:560px;">
				<input type="hidden" name="access_key" value="<?php echo esc_attr( self::WEB3FORMS_KEY ); ?>">
				<input type="hidden" name="subject" value="Plugin rating from wp-admin — Order Updates for WooCommerce">
				<input type="hidden" name="from_name" value="Order Updates wp-admin review">
				<input type="hidden" name="rating" data-awts-rating-input>
				<input type="hidden" name="name" value="<?php echo esc_attr( $user->display_name ); ?>">
				<input type="hidden" name="email" value="<?php echo esc_attr( $user->user_email ); ?>">
				<input type="checkbox" name="botcheck" style="display:none" tabindex="-1" autocomplete="off">

				<p style="margin:0 0 6px;font-size:13px;color:#475569;">
					<?php esc_html_e( 'Drop a public link (LinkedIn, store URL, etc.) so visitors on the site can verify the review is real. Optional but it\'s the only way the quote shows up publicly.', 'order-updates-for-woo' ); ?>
				</p>

				<input type="url" name="verify_link" placeholder="https://your-public-profile-or-store.com" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;margin-bottom:8px;">

				<textarea name="message" rows="3" placeholder="<?php esc_attr_e( 'A line or two about what worked for you (optional, but useful)', 'order-updates-for-woo' ); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;resize:vertical;"></textarea>

				<p style="margin:10px 0 0;">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Submit rating', 'order-updates-for-woo' ); ?>
					</button>
					&nbsp;
					<a href="<?php echo esc_url( self::REVIEW_URL ); ?>" class="button" target="_blank" rel="noopener">
						<?php esc_html_e( 'Also post on WordPress.org', 'order-updates-for-woo' ); ?>
					</a>
					&nbsp;
					<span data-awts-status style="display:none;font-size:13px;"></span>
				</p>
			</form>

			<p>
				<a href="<?php echo esc_url( $mark_rated ); ?>" class="button-link">
					<?php esc_html_e( 'I already did', 'order-updates-for-woo' ); ?>
				</a>
				&nbsp;&middot;&nbsp;
				<a href="<?php echo esc_url( $snooze_url ); ?>" class="button-link">
					<?php esc_html_e( 'Maybe later', 'order-updates-for-woo' ); ?>
				</a>
				&nbsp;&middot;&nbsp;
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link">
					<?php esc_html_e( 'Don\'t ask again', 'order-updates-for-woo' ); ?>
				</a>
			</p>
		</div>

		<script>
		( function () {
			'use strict';
			var notice = document.querySelector( '[data-awts-review-notice]' );
			if ( ! notice ) return;
			var stars   = notice.querySelectorAll( '[data-awts-star]' );
			var form    = notice.querySelector( '[data-awts-review-form]' );
			var input   = notice.querySelector( '[data-awts-rating-input]' );
			var status  = notice.querySelector( '[data-awts-status]' );
			var markUrl = <?php echo wp_json_encode( $mark_rated ); ?>;

			function paint( picked ) {
				stars.forEach( function ( star, i ) {
					star.style.color = ( i < picked ) ? '#f59e0b' : '#cbd5e1';
				} );
			}

			stars.forEach( function ( star, i ) {
				var rank = i + 1;
				star.addEventListener( 'mouseenter', function () { paint( rank ); } );
				star.addEventListener( 'mouseleave', function () { paint( parseInt( input.value || '0', 10 ) ); } );
				star.addEventListener( 'click', function () {
					input.value = rank;
					paint( rank );
					form.style.display = 'block';
				} );
			} );

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				if ( ! input.value ) return;
				status.style.display = 'inline';
				status.textContent   = 'Sending…';
				status.style.color   = '#475569';

				fetch( 'https://api.web3forms.com/submit', {
					method: 'POST',
					body:   new FormData( form ),
				} )
					.then( function ( r ) { return r.json().catch( function () { return {}; } ); } )
					.then( function ( data ) {
						if ( data && data.success !== false ) {
							status.textContent = '✓ Thanks — submitted!';
							status.style.color = '#059669';
							// Mark the user as rated so the notice doesn't come back.
							setTimeout( function () { window.location.href = markUrl; }, 1200 );
						} else {
							status.textContent = '⚠ ' + ( ( data && data.message ) || 'Could not send. Try again.' );
							status.style.color = '#b91c1c';
						}
					} )
					.catch( function () {
						status.textContent = '⚠ Network error.';
						status.style.color = '#b91c1c';
					} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Gate check — all of these must pass before we put the notice up.
	 * Designed to stay quiet on fresh installs, on screens where it would
	 * intrude, and for users who've already responded.
	 */
	private function should_render(): bool {
		if ( ! function_exists( 'get_current_screen' ) || ! is_user_logged_in() ) {
			return false;
		}

		// Only on the orders list + edit screens — the people seeing those
		// are the ones who use the feature.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$allowed_ids = array(
			HposHelper::order_edit_screen_id(),
			HposHelper::orders_list_screen_id(),
		);
		if ( ! in_array( $screen->id, $allowed_ids, true ) ) {
			return false;
		}

		// Capability check — staff who handle orders, not subscribers.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return false;
		}

		// Per-user state: dismissed forever, already-reviewed, or snoozed-until.
		$state = $this->get_user_state( get_current_user_id() );
		if ( in_array( $state['status'] ?? '', array( 'dismissed', 'already' ), true ) ) {
			return false;
		}
		if ( 'snoozed' === ( $state['status'] ?? '' ) && time() < (int) ( $state['until'] ?? 0 ) ) {
			return false;
		}

		// Install-age gate — give the user time to form an opinion.
		$first_seen = (int) get_option( self::FIRST_SEEN_OPTION, 0 );
		if ( ! $first_seen || ( time() - $first_seen ) < ( self::MIN_DAYS_INSTALLED * DAY_IN_SECONDS ) ) {
			return false;
		}

		// Engagement gate — if the site has barely used the plugin, asking
		// for a review is premature. Count totals across the whole site.
		if ( $this->total_updates_in_site() < self::MIN_UPDATE_COUNT ) {
			return false;
		}

		return true;
	}

	/** Handle a dismiss / remind-later / left-review click (nonce-checked). */
	public function handle_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce check below.
		$action = isset( $_GET['awts_review_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['awts_review_notice'] ) ) : '';
		if ( ! $action ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		switch ( $action ) {
			case 'dismiss':
				$this->set_user_state( $user_id, array( 'status' => 'dismissed' ) );
				break;
			case 'already':
				$this->set_user_state( $user_id, array( 'status' => 'already' ) );
				break;
			case 'snooze':
				$this->set_user_state(
					$user_id,
					array(
						'status' => 'snoozed',
						'until'  => time() + ( self::SNOOZE_DAYS * DAY_IN_SECONDS ),
					) 
				);
				break;
			default:
				return;
		}

		// Strip our query args from the URL after we've recorded the state.
		wp_safe_redirect( remove_query_arg( array( 'awts_review_notice', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Build a same-page action URL with nonce + the requested action verb.
	 *
	 * @param string $action Action verb.
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg( 'awts_review_notice', $action ),
			self::NONCE_ACTION
		);
	}

	/**
	 * The current user's notice state (dismissed / remind-later).
	 *
	 * @param int $user_id User id.
	 * @return array{status?: string, until?: int}
	 */
	private function get_user_state( int $user_id ): array {
		$state = get_user_meta( $user_id, self::STATE_USER_META, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the current user's notice state.
	 *
	 * @param int   $user_id User id.
	 * @param array $state   State to store.
	 */
	private function set_user_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::STATE_USER_META, $state );
	}

	/**
	 * Coarse "is the plugin being used" check — total update rows across
	 * the site. Cached for an hour so the notice path doesn't hit the DB
	 * on every admin page load.
	 */
	private function total_updates_in_site(): int {
		$cached = wp_cache_get( 'review_notice_total', Constants::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$total = $this->order_updates_db->count_all_updates();
		wp_cache_set( 'review_notice_total', $total, Constants::CACHE_GROUP, HOUR_IN_SECONDS );
		return $total;
	}
}
