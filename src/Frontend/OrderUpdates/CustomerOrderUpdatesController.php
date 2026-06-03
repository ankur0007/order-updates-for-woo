<?php
/**
 * Customer-facing order-updates portal (My Account endpoint + guest link).
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\OrderUpdates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\RestUrlHelper;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Language\Labels;
use OrderUpdatesForWoo\Shared\Updates\SharedLink;
use OrderUpdatesForWoo\Shared\Updates\SignedCustomerUrl;
use WC_Order;

/**
 * Customer-facing order updates.
 *
 * Logged-in customers reach the page through a native WC MyAccount endpoint
 * (/my-account/order-updates/{order_id}/), which inherits the theme's
 * MyAccount shell and left-side nav automatically. Guests reach the same
 * content via a standalone rewrite (/orders/updates/{order_id}/?key=...)
 * because MyAccount is gated behind login.
 */
final class CustomerOrderUpdatesController {
	public const ACCOUNT_ENDPOINT        = 'order-updates';
	public const GUEST_QUERY_VAR         = 'awts_order_updates_id';
	private const GUEST_URL_BASE         = 'orders/updates';
	private const REWRITE_VERSION        = '4';
	private const REWRITE_VERSION_OPTION = 'order_updates_for_woo_rewrite_version';
	private const LEGACY_PAGE_OPTION     = 'order_updates_for_woo_page_id';

	/**
	 * Inject dependencies.
	 *
	 * @param CustomerOrderUpdatesService $service Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service Injected dependency.
	 */
	public function __construct(
		private CustomerOrderUpdatesService $service,
		private OrderUpdatesSettingsService $settings_service
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// Native WC MyAccount endpoint for logged-in customers.
		add_action( 'init', array( $this, 'register_endpoint' ), 5 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_wc_query_var' ) );
		add_action( 'woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_account_endpoint' ) );
		add_filter( 'woocommerce_endpoint_' . self::ACCOUNT_ENDPOINT . '_title', array( $this, 'filter_endpoint_title' ) );
		add_filter( 'woocommerce_account_menu_item_classes', array( $this, 'highlight_orders_menu_item' ), 10, 2 );

		// Guest-facing standalone URL (unchanged).
		add_filter( 'query_vars', array( $this, 'register_guest_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_guest' ), 5 );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_orders_table_action' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'cleanup_legacy_page' ), 6 );
	}

	// ----- Endpoint + rewrite registration -----

	/** Register the My Account endpoint + guest rule, flushing rewrites once per version. */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
		self::add_guest_rule();

		if ( get_option( self::REWRITE_VERSION_OPTION ) !== self::REWRITE_VERSION ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- one-time flush, guarded by a version option.
			flush_rewrite_rules( false );
			update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
		}
	}

	/** Register the guest no-login URL rewrite rule. */
	private static function add_guest_rule(): void {
		add_rewrite_rule(
			'^' . self::GUEST_URL_BASE . '/([0-9]+)/?$',
			'index.php?' . self::GUEST_QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/** Activation hook — register the endpoint + guest rule and flush rewrites. */
	public static function on_activation(): void {
		add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
		self::add_guest_rule();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- one-time flush on activation.
		flush_rewrite_rules( false );
	}

	/** Deactivation hook — flush rewrites so our rules are removed. */
	public static function on_deactivation(): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- one-time flush on deactivation.
		flush_rewrite_rules( false );
	}

	/**
	 * Register the My Account endpoint as a WC query var.
	 *
	 * @param array $vars Existing query vars.
	 */
	public function add_wc_query_var( array $vars ): array {
		$vars[ self::ACCOUNT_ENDPOINT ] = self::ACCOUNT_ENDPOINT;

		return $vars;
	}

	/**
	 * Register the guest query var with WP.
	 *
	 * @param array $vars Existing public query vars.
	 */
	public function register_guest_query_var( array $vars ): array {
		$vars[] = self::GUEST_QUERY_VAR;

		return $vars;
	}

	// ----- URL helpers -----

	/**
	 * Portal URL for an order — native My Account endpoint for logged-in
	 * customers, or a guest URL with the order key when a key is given.
	 *
	 * @param int         $order_id  Order id.
	 * @param string|null $order_key Guest order key, or null for the logged-in path.
	 */
	public static function get_page_url( int $order_id, ?string $order_key = null ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		$has_key = null !== $order_key && '' !== $order_key;

		// Logged-in customers (no guest key needed) → native MyAccount endpoint.
		if ( ! $has_key && function_exists( 'wc_get_account_endpoint_url' ) ) {
			$base = wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT );

			return trailingslashit( $base ) . $order_id . '/';
		}

		// Guests → standalone URL with order_key.
		$structure = (string) get_option( 'permalink_structure' );
		$url       = '' === $structure
			? add_query_arg( self::GUEST_QUERY_VAR, $order_id, home_url( '/' ) )
			: home_url( '/' . self::GUEST_URL_BASE . '/' . $order_id . '/' );

		if ( $has_key ) {
			$url = add_query_arg( 'key', $order_key, $url );
		}

		return $url;
	}

	/**
	 * URL the admin panel offers for staff sharing. The hash is stable —
	 * changing the expiry on this link does not change the URL.
	 *
	 * @param int    $order_id Order id.
	 * @param string $hash     Shared-link hash.
	 */
	public static function get_shared_link_url( int $order_id, string $hash ): string {
		if ( $order_id <= 0 || '' === $hash ) {
			return '';
		}

		$structure = (string) get_option( 'permalink_structure' );
		$url       = '' === $structure
			? add_query_arg( self::GUEST_QUERY_VAR, $order_id, home_url( '/' ) )
			: home_url( '/' . self::GUEST_URL_BASE . '/' . $order_id . '/' );

		return add_query_arg( SharedLink::QUERY_PARAM, $hash, $url );
	}

	/**
	 * URL customer emails embed. Carries a time-boxed signed token instead
	 * of the order_key, so a forwarded email stops working at expiry.
	 *
	 * @param int $order_id Order id.
	 */
	public static function get_signed_email_url( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		$structure = (string) get_option( 'permalink_structure' );
		$url       = '' === $structure
			? add_query_arg( self::GUEST_QUERY_VAR, $order_id, home_url( '/' ) )
			: home_url( '/' . self::GUEST_URL_BASE . '/' . $order_id . '/' );

		$signed = SignedCustomerUrl::sign( $order_id );

		return add_query_arg(
			array(
				SignedCustomerUrl::QUERY_EXPIRES => $signed['expires'],
				SignedCustomerUrl::QUERY_TOKEN   => $signed['token'],
			),
			$url
		);
	}

	/**
	 * Return the order_key the request authenticates with. Checks ?key=,
	 * then the shared link hash, then the email signed token. Returns ''
	 * when none verify so the caller falls back to login / denial.
	 *
	 * @param int $order_id Order id.
	 */
	private static function resolve_request_order_key( int $order_id ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET['key'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link_hash = isset( $_GET[ SharedLink::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ SharedLink::QUERY_PARAM ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET[ SignedCustomerUrl::QUERY_TOKEN ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ SignedCustomerUrl::QUERY_TOKEN ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires = isset( $_GET[ SignedCustomerUrl::QUERY_EXPIRES ] ) ? absint( $_GET[ SignedCustomerUrl::QUERY_EXPIRES ] ) : 0;

		if ( '' === $link_hash && ( '' === $token || $expires <= 0 ) ) {
			return '';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		if ( '' !== $link_hash && SharedLink::verify( $order, $link_hash ) ) {
			return (string) $order->get_order_key();
		}

		if ( '' !== $token && $expires > 0 && SignedCustomerUrl::verify( $order_id, $expires, $token ) ) {
			return (string) $order->get_order_key();
		}

		return '';
	}

	// ----- MyAccount endpoint rendering (WC handles the shell) -----

	/**
	 * Render the My Account endpoint body for an order id.
	 *
	 * @param mixed $value Endpoint value (the order id) from WC.
	 */
	public function render_account_endpoint( $value ): void {
		$order_id = absint( (string) $value );
		$status   = $this->service->resolve_view_status( $order_id, null );

		if ( CustomerOrderUpdatesService::VIEW_ALLOWED !== $status ) {
			echo '<p class="awts_cou_empty">' . esc_html( self::denial_message( $status ) ) . '</p>';

			return;
		}

		$this->render_content( $order_id );
	}

	/**
	 * Customer-facing message for a non-allowed view status.
	 *
	 * @param string $status One of the VIEW_* statuses.
	 */
	private static function denial_message( string $status ): string {
		switch ( $status ) {
			case CustomerOrderUpdatesService::VIEW_RESTRICTED:
				return __( 'Access restricted. You do not have permission to view this page.', 'order-updates-for-woo' );
			case CustomerOrderUpdatesService::VIEW_EXPIRED:
				return __( 'This link has expired or the order is no longer available.', 'order-updates-for-woo' );
			case CustomerOrderUpdatesService::VIEW_INVALID:
			default:
				return __( 'Invalid access link.', 'order-updates-for-woo' );
		}
	}

	/**
	 * Title for the My Account endpoint.
	 *
	 * @param mixed $title Title passed by WC (ignored).
	 */
	public function filter_endpoint_title( $title ): string {
		return __( 'Order updates', 'order-updates-for-woo' );
	}

	/**
	 * Keep the Orders menu item highlighted while on the updates endpoint.
	 *
	 * @param array  $classes  Menu item CSS classes.
	 * @param string $endpoint Endpoint being rendered.
	 */
	public function highlight_orders_menu_item( array $classes, string $endpoint ): array {
		if ( 'orders' !== $endpoint ) {
			return $classes;
		}

		$on_updates = '' !== (string) get_query_var( self::ACCOUNT_ENDPOINT );

		if ( $on_updates && ! in_array( 'is-active', $classes, true ) ) {
			$classes[] = 'is-active';
		}

		return $classes;
	}

	// ----- Guest standalone rendering -----

	/** Render the standalone guest page when the guest query var is present. */
	public function maybe_render_guest(): void {
		$order_id = (int) get_query_var( self::GUEST_QUERY_VAR );

		if ( $order_id <= 0 ) {
			return;
		}

		// Tell "your link expired" from "this URL is wrong" so the page can
		// show an honest message. Tampered tokens fall through to denial.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET[ SignedCustomerUrl::QUERY_TOKEN ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ SignedCustomerUrl::QUERY_TOKEN ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires = isset( $_GET[ SignedCustomerUrl::QUERY_EXPIRES ] ) ? absint( $_GET[ SignedCustomerUrl::QUERY_EXPIRES ] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link_hash = isset( $_GET[ SharedLink::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ SharedLink::QUERY_PARAM ] ) ) : '';

		if ( '' !== $token && $expires > 0 && SignedCustomerUrl::is_expired( $order_id, $expires, $token ) ) {
			$this->render_expired_link_page();
			return;
		}

		if ( '' !== $link_hash ) {
			$order_for_link = wc_get_order( $order_id );
			if ( $order_for_link instanceof WC_Order && SharedLink::is_expired( $order_for_link, $link_hash ) ) {
				$this->render_expired_link_page();
				return;
			}
		}

		$order_key = self::resolve_request_order_key( $order_id );

		// Redirect logged-in owners to the MyAccount endpoint for a consistent UX.
		if ( is_user_logged_in() ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && (int) $order->get_customer_id() === get_current_user_id() ) {
				wp_safe_redirect( self::get_page_url( $order_id ) );
				exit;
			}
		}

		$status   = $this->service->resolve_view_status( $order_id, '' !== $order_key ? $order_key : null );
		$can_view = CustomerOrderUpdatesService::VIEW_ALLOWED === $status;

		status_header( $can_view ? 200 : 403 );
		nocache_headers();

		add_filter( 'body_class', array( $this, 'filter_guest_body_class' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ) );

		$heading_label = __( 'Order updates', 'order-updates-for-woo' );

		get_header();
		echo '<main class="awts_cou_page site-main">';
		echo '<div class="awts_cou_page__inner">';
		echo '<h1 class="awts_cou_page__title">' . esc_html( $heading_label ) . '</h1>';

		if ( ! $can_view ) {
			echo '<p class="awts_cou_empty">' . esc_html( self::denial_message( $status ) ) . '</p>';
		} else {
			$this->render_content( $order_id );
		}

		echo '</div>';
		echo '</main>';
		get_footer();
		exit;
	}

	/** Page for a valid-but-expired link. Login CTA for members, support email for guests. */
	private function render_expired_link_page(): void {
		status_header( 410 );
		nocache_headers();

		add_filter( 'body_class', array( $this, 'filter_guest_body_class' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ) );

		get_header();
		echo '<main class="awts_cou_page site-main">';
		echo '<div class="awts_cou_page__inner">';
		echo '<h1 class="awts_cou_page__title">' . esc_html__( 'Order updates', 'order-updates-for-woo' ) . '</h1>';
		echo '<p class="awts_cou_empty">' . esc_html__( 'This link has expired.', 'order-updates-for-woo' ) . '</p>';

		if ( is_user_logged_in() ) {
			$myaccount = wc_get_page_permalink( 'myaccount' );
			if ( $myaccount ) {
				echo '<p class="awts_cou_expired_cta"><a class="button" href="' . esc_url( (string) $myaccount ) . '">' . esc_html__( 'Open my account', 'order-updates-for-woo' ) . '</a></p>';
			}
		} else {
			$login = wp_login_url();
			echo '<p class="awts_cou_expired_cta"><a class="button" href="' . esc_url( $login ) . '">' . esc_html__( 'Log in to continue', 'order-updates-for-woo' ) . '</a></p>';

			$support = Variables::getSupportContactEmail();
			if ( '' !== $support ) {
				$message = sprintf(
					/* translators: %s: support contact email address */
					__( 'Or email us at %s and we will send you a fresh link.', 'order-updates-for-woo' ),
					'<a href="' . esc_url( 'mailto:' . $support ) . '">' . esc_html( $support ) . '</a>'
				);
				echo '<p class="awts_cou_expired_contact">' . wp_kses( $message, array( 'a' => array( 'href' => true ) ) ) . '</p>';
			}
		}

		echo '</div>';
		echo '</main>';
		get_footer();
		exit;
	}

	/**
	 * Add body classes to the standalone guest page.
	 *
	 * @param array $classes Existing body classes.
	 */
	public function filter_guest_body_class( array $classes ): array {
		$classes[] = 'awts_cou_page_body';
		$classes[] = 'woocommerce';
		$classes[] = 'woocommerce-page';

		return $classes;
	}

	/**
	 * Set the document title on the standalone guest page.
	 *
	 * @param array $parts Title parts.
	 */
	public function filter_title_parts( array $parts ): array {
		$parts['title'] = __( 'Order updates', 'order-updates-for-woo' );

		return $parts;
	}

	// ----- Shared content rendering -----

	/**
	 * Render the portal body — order summary, updates list, write-note UI.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_content( int $order_id ): void {
		$updates = $this->service->get_updates_for_order( $order_id );

		$this->render_order_summary( $order_id );
		$this->render_write_note_trigger();

		if ( empty( $updates ) ) {
			echo '<p class="awts_cou_empty">' . esc_html__( 'No updates available for this order yet.', 'order-updates-for-woo' ) . '</p>';
		} else {
			$feature_settings    = $this->settings_service->get_feature_settings();
			$email_notifications = CustomerEmailPreference::get( $order_id, get_current_user_id() );

			View::render(
				'src/Frontend/OrderUpdates/Views/CustomerOrderUpdatesView',
				array(
					'updates'             => $updates,
					'order_id'            => $order_id,
					'rating'              => $this->service->get_rating_config(),
					'show_assignee'       => ! empty( $feature_settings['show_assignee_to_customers'] ),
					'email_notifications' => $email_notifications,
				)
			);
		}

		$this->render_write_note_modal();
	}

	// ----- Orders table action button -----

	/**
	 * Add an "Updates" action button to the My Account orders table.
	 *
	 * @param array          $actions Existing row actions.
	 * @param WC_Order|mixed $order   Order for the row.
	 */
	public function add_orders_table_action( array $actions, $order ): array {
		if ( ! $order instanceof WC_Order ) {
			return $actions;
		}

		$actions['order-updates'] = array(
			'url'  => self::get_page_url( (int) $order->get_id() ),
			'name' => __( 'Updates', 'order-updates-for-woo' ),
		);

		return $actions;
	}

	// ----- Asset enqueue (on both the endpoint and the guest URL) -----

	/** Enqueue the portal CSS/JS on the account endpoint and guest URL. */
	public function enqueue_assets(): void {
		$order_id = $this->resolve_current_order_id();

		if ( $order_id <= 0 ) {
			return;
		}

		// Legacy ?key=, signed email token, and stateful shared link all
		// resolve to the same order_key for AJAX.
		$order_key = self::resolve_request_order_key( $order_id );

		wp_enqueue_style(
			'order-updates-for-woo-customer',
			AssetHelper::url( 'assets/Frontend/css/customer-order-updates.css' ),
			array(),
			AssetHelper::version( 'assets/Frontend/css/customer-order-updates.css' )
		);

		wp_enqueue_script(
			'order-updates-for-woo-customer',
			AssetHelper::url( 'assets/Frontend/js/customer-order-updates.js' ),
			array(),
			AssetHelper::version( 'assets/Frontend/js/customer-order-updates.js' ),
			true
		);

		$allowed_mime = AttachmentService::allowed_mime_types();

		$rating_config = $this->service->get_rating_config();

		wp_localize_script(
			'order-updates-for-woo-customer',
			'AWTS_COU_CONFIG',
			array(
				'restUrl'                   => esc_url_raw( RestUrlHelper::route( 'customer-updates' ) ),
				'customerNotesEndpointBase' => esc_url_raw( RestUrlHelper::route( 'updates/' ) ),
				'ratingUrlBase'             => esc_url_raw( RestUrlHelper::route( 'updates/' ) ),
				'notesPageSize'             => Constants::CUSTOMER_NOTES_PAGE_SIZE,
				'emailPrefUrl'              => esc_url_raw( RestUrlHelper::route( 'customer-email-preference' ) ),
				'pollUrl'                   => esc_url_raw( RestUrlHelper::route( 'customer-thread/poll' ) ),
				'pollIntervalMin'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_min', Constants::POLL_INTERVAL_MIN_SECONDS ) * 1000,
				'pollIntervalMid'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_mid', Constants::POLL_INTERVAL_MID_SECONDS ) * 1000,
				'pollIntervalMax'           => (int) apply_filters( 'order_updates_for_woo_poll_interval_max', Constants::POLL_INTERVAL_MAX_SECONDS ) * 1000,
				// Addons (e.g. Pusher/WebSocket) inject their config here via
				// this filter. If non-empty the JS driver abstraction picks it
				// up and can skip polling entirely.
				'realtimeConfig'            => (array) apply_filters( 'order_updates_for_woo_realtime_config', array(), $order_id ),
				'nonce'                     => wp_create_nonce( 'wp_rest' ),
				'orderId'                   => $order_id,
				'orderKey'                  => $order_key,
				'pageUrl'                   => self::get_page_url( $order_id, '' !== $order_key ? $order_key : null ),
				'maxFiles'                  => Variables::getMaxAttachmentFiles(),
				'maxBytes'                  => AttachmentService::max_bytes(),
				// Mirror the Restricted-features master toggle so the Up-arrow
				// autofill on the reply field doesn't trigger an edit that the
				// server will 403 anyway.
				'allowNoteEdit'             => $this->settings_service->allow_note_edit(),
				'acceptMime'                => implode( ',', $allowed_mime ),
				'rating'                    => $rating_config,
				'labels'                    => array(
					'submitting'            => (string) Labels::get( 'customerWriteNoteSubmitting' ),
					'success'               => (string) Labels::get( 'customerWriteNoteSuccess' ),
					'genericFail'           => (string) Labels::get( 'customerWriteNoteGenericFail' ),
					'sessionExpired'        => (string) Labels::get( 'sessionExpiredRefresh' ),
					'subjectRequired'       => (string) Labels::get( 'customerWriteNoteSubjectRequired' ),
					'messageRequired'       => (string) Labels::get( 'customerWriteNoteMessageRequired' ),
					'tooManyFiles'          => (string) Labels::get( 'customerWriteNoteTooManyFiles' ),
					'attachmentTooLarge'    => (string) Labels::get( 'attachmentTooLarge' ),
					'attachmentUnsupported' => (string) Labels::get( 'attachmentUnsupported' ),
					'removeFile'            => (string) Labels::get( 'customerWriteNoteRemoveFile' ),
					'replySuccess'          => (string) Labels::get( 'customerReplySuccess' ),
					'ratingMissing'         => (string) Labels::get( 'customerRatingMissing' ),
					'ratingSubmitting'      => (string) Labels::get( 'customerRatingSubmitting' ),
					'ratingSuccess'         => (string) Labels::get( 'customerRatingSuccess' ),
					'ratingThanks'          => (string) Labels::get( 'customerRatingThanks' ),
					'ratingSaveFailed'      => (string) Labels::get( 'customerRatingSaveFailed' ),
					'ratingHeading'         => (string) Labels::get( 'ratingHeading' ),
					'ratingIntro'           => (string) Labels::get( 'ratingIntro' ),
					'ratingCommentLabel'    => (string) Labels::get( 'ratingCommentLabel' ),
					'ratingCommentPh'       => (string) Labels::get( 'ratingCommentPh' ),
					'ratingSubmitLabel'     => (string) Labels::get( 'ratingSubmitLabel' ),
					'ratingStar1Label'      => (string) Labels::get( 'ratingStar1Label' ),
					'ratingStarLabel'       => (string) Labels::get( 'ratingStarLabel' ),
					'reopenButton'          => (string) Labels::get( 'customerReopenButton' ),
					'reopenSubmitting'      => (string) Labels::get( 'customerReopenSubmitting' ),
					'reopenFailed'          => (string) Labels::get( 'customerReopenFailed' ),
					'newBadge'              => (string) Labels::get( 'customerUpdatesNewBadge' ),
					'newBadgeCount'         => (string) Labels::get( 'customerUpdatesNewBadgeCount' ),
					'noNotes'               => (string) Labels::get( 'customerUpdatesNoNotes' ),
					'editNoteAction'        => (string) Labels::get( 'editNoteAction' ),
					'editNotePrompt'        => (string) Labels::get( 'editNotePrompt' ),
					'editedLabel'           => (string) Labels::get( 'editedLabel' ),
					'historyHeading'        => (string) Labels::get( 'noteHistoryHeading' ),
					'historyEmpty'          => (string) Labels::get( 'noteHistoryEmpty' ),
					'saveNoteAction'        => (string) Labels::get( 'saveNoteAction' ),
					'cancelNoteAction'      => (string) Labels::get( 'cancelNoteAction' ),
					'noteUpdated'           => (string) Labels::get( 'customerNoteUpdated' ),
					'loadingLabel'          => (string) Labels::get( 'loadingLabel' ),
				),
			)
		);
	}

	/**
	 * Resolve the order_id from either the WC MyAccount endpoint value or the
	 * standalone guest query var, so one enqueue path covers both URLs.
	 */
	private function resolve_current_order_id(): int {
		$endpoint_value = (string) get_query_var( self::ACCOUNT_ENDPOINT );

		if ( '' !== $endpoint_value ) {
			return absint( $endpoint_value );
		}

		return (int) get_query_var( self::GUEST_QUERY_VAR );
	}

	/**
	 * Remove the auto-created "Order Updates" WP page from earlier versions.
	 * Self-heal one-shot: runs once per install if the legacy option exists.
	 */
	public function cleanup_legacy_page(): void {
		$legacy_id = (int) get_option( self::LEGACY_PAGE_OPTION );

		if ( ! $legacy_id ) {
			return;
		}

		wp_delete_post( $legacy_id, true );
		delete_option( self::LEGACY_PAGE_OPTION );
	}

	// ----- Write-note trigger + modal -----

	/** Render the "write a note" trigger button (gated on the admin opt-in). */
	private function render_write_note_trigger(): void {
		// Gate the entry point on the admin opt-in; if the customer can't
		// create a new thread, the "Write it here" prompt is misleading.
		if ( ! $this->settings_service->allow_customer_create_update() ) {
			return;
		}

		View::render( 'src/Frontend/OrderUpdates/Views/CustomerPortalWriteNoteTriggerView' );
	}

	/**
	 * Render the order summary card at the top of the portal.
	 *
	 * @param int $order_id Order id.
	 */
	private function render_order_summary( int $order_id ): void {
		$summary = $this->service->get_order_summary( $order_id );

		if ( empty( $summary ) ) {
			return;
		}

		View::render(
			'src/Frontend/OrderUpdates/Views/CustomerOrderSummaryView',
			array( 'summary' => $summary )
		);
	}

	/** Render the "write a note" modal markup (gated on the admin opt-in). */
	private function render_write_note_modal(): void {
		if ( ! $this->settings_service->allow_customer_create_update() ) {
			return;
		}

		View::render(
			'src/Frontend/OrderUpdates/Views/CustomerPortalWriteNoteModalView',
			array(
				'attach_hint'  => sprintf(
					/* translators: 1: maximum number of files, 2: maximum file size. */
					__( 'Up to %1$d files, %2$s each. PDF, JPG, PNG, GIF or WEBP.', 'order-updates-for-woo' ),
					Variables::getMaxAttachmentFiles(),
					size_format( AttachmentService::max_bytes() )
				),
				'allowed_mime' => AttachmentService::allowed_mime_types(),
			)
		);
	}
}
