<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Config;

/**
 * Tunable plugin variables. Each getter reads the option so values can be
 * surfaced as settings without touching call sites. Defaults live here.
 */
final class Variables {

	/** Max orders shown per page in the update list. */
	public static function getUpdatesPageSize(): int {
		return (int) get_option( 'order_updates_for_woo_page_size', 5 );
	}

	/** Max assigned orders shown in the admin bar dropdown. */
	public static function getAdminBarMaxOrders(): int {
		return (int) get_option( 'order_updates_for_woo_admin_bar_max_orders', 10 );
	}

	/** Cache lifetime in seconds for the assignee search results. */
	public static function getAssigneeSearchCacheTtl(): int {
		return (int) get_option( 'order_updates_for_woo_assignee_search_cache_ttl', 180 );
	}

	/** Cache lifetime in seconds for individual update records and order summaries. */
	public static function getUpdateCacheTtl(): int {
		return (int) get_option( 'order_updates_for_woo_cache_ttl', 3600 );
	}

	/** Maximum upload size per attachment, in bytes (option stored as MB for UX). */
	public static function getMaxAttachmentBytes(): int {
		$configured = max( 1, (int) get_option( 'order_updates_for_woo_max_attachment_mb', 10 ) ) * 1024 * 1024;
		$php_max    = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : PHP_INT_MAX;
		return min( $configured, $php_max );
	}

	/** Maximum number of files a customer can attach to a single submission. */
	public static function getMaxAttachmentFiles(): int {
		return (int) get_option( 'order_updates_for_woo_max_attachment_files', 5 );
	}

	/** Lifetime in seconds for signed attachment download URLs. */
	public static function getSignedUrlTtl(): int {
		return (int) get_option( 'order_updates_for_woo_signed_url_ttl', 3600 );
	}

	/** Days a signed customer-facing email URL stays valid (clamped 1–365). */
	public static function getCustomerLinkExpiryDays(): int {
		$days = (int) get_option( 'order_updates_for_woo_customer_link_expiry_days', 30 );

		return max( 1, min( 365, $days ) );
	}

	/** Support contact email shown when a customer's link has expired. */
	public static function getSupportContactEmail(): string {
		$configured = (string) get_option( 'order_updates_for_woo_support_contact_email', '' );
		$email      = '' !== $configured ? $configured : (string) get_option( 'admin_email', '' );

		return is_email( $email ) ? $email : '';
	}
}
