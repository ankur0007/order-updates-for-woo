<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Welcome\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Language\Labels;

final class OnboardingController {
	private const DISMISSED_OPTION = 'order_updates_for_woo_onboarding_dismissed';

	public function init(): void {
		add_action( 'wp_ajax_order_updates_for_woo_dismiss_onboarding', array( $this, 'dismiss' ) );
	}

	public function should_show(): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		return ! get_option( self::DISMISSED_OPTION );
	}

	public function dismiss(): void {
		check_ajax_referer( 'wp_rest', '_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}

		update_option( self::DISMISSED_OPTION, '1', false );
		wp_send_json_success();
	}
}
