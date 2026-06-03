<?php
/**
 * Newsletter opt-in handler on the welcome page.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Welcome\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Newsletter controller.
 */
final class NewsletterController {

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'wp_ajax_order_updates_for_woo_newsletter_subscribe', array( $this, 'subscribe' ) );
		add_action( 'wp_ajax_order_updates_for_woo_newsletter_reset', array( $this, 'reset' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_script' ) );
	}

	/**
	 * Enqueue the opt-in script on the settings + welcome pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function maybe_enqueue_script( string $hook ): void {
		$target_pages = array(
			'woocommerce_page_wc-settings',
			'woocommerce_page_order-updates-for-woo-welcome',
		);

		if ( ! in_array( $hook, $target_pages, true ) ) {
			return;
		}

		$js_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/newsletter.js';

		wp_enqueue_script(
			'order-updates-for-woo-newsletter',
			AssetHelper::url( 'assets/Admin/js/newsletter.js' ),
			array( 'jquery' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);

		wp_localize_script(
			'order-updates-for-woo-newsletter',
			'awtsNewsletter',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'subscribe'    => __( 'Subscribe', 'order-updates-for-woo' ),
				'subscribing'  => __( 'Subscribing...', 'order-updates-for-woo' ),
				'invalidEmail' => __( 'Please enter a valid email address.', 'order-updates-for-woo' ),
				'failed'       => __( 'Something went wrong. Please try again.', 'order-updates-for-woo' ),
			) 
		);
	}

	/** AJAX: subscribe the submitted email to the newsletter. */
	public function subscribe(): void {
		check_ajax_referer( 'wp_rest', '_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'order-updates-for-woo' ) ) );
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'order-updates-for-woo' ) ) );
		}

		$error = $this->send_to_worker( $email );

		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}

		update_option( Constants::NEWSLETTER_EMAIL_OPTION, $email, false );

		wp_send_json_success( array( 'message' => __( 'Thanks for subscribing!', 'order-updates-for-woo' ) ) );
	}

	/**
	 * Clears the saved newsletter email so the form reappears.
	 * Does NOT unsubscribe from Mailchimp — only resets local state.
	 */
	public function reset(): void {
		check_ajax_referer( 'wp_rest', '_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'order-updates-for-woo' ) ), 403 );
		}

		delete_option( Constants::NEWSLETTER_EMAIL_OPTION );

		wp_send_json_success();
	}

	/**
	 * POSTs the email to the Cloudflare Worker that fronts Mailchimp.
	 * Returns null on success, or an error string suitable for display.
	 *
	 * @param string $email Email address to subscribe.
	 */
	private function send_to_worker( string $email ): ?string {
		$response = wp_remote_post(
			Constants::NEWSLETTER_SUBSCRIBE_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'email' => $email,
						'site'  => home_url(),
					) 
				),
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- admin-only opt-in, not a front-end request; the worker can be slow on a cold start.
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return __( 'Network error — please try again in a minute.', 'order-updates-for-woo' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$worker_success = is_array( $body ) && ! empty( $body['success'] );

		if ( $code >= 200 && $code < 300 && $worker_success ) {
			return null;
		}

		if ( is_array( $body ) ) {
			if ( ! empty( $body['mailchimp']['detail'] ) ) {
				return (string) $body['mailchimp']['detail'];
			}
			if ( ! empty( $body['message'] ) ) {
				return (string) $body['message'];
			}
			if ( ! empty( $body['error'] ) && 'invalid_email' === $body['error'] ) {
				return __( 'Please enter a valid email address.', 'order-updates-for-woo' );
			}
		}

		return __( 'Something went wrong. Please try again.', 'order-updates-for-woo' );
	}
}
