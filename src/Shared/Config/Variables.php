<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Config;

/**
 * Tunable plugin variables. Each getter reads the option so values can be
 * surfaced as settings without touching call sites. Defaults live here.
 * Option keys are centralised in Constants.
 */
final class Variables {

	/** Max orders shown per page in the update list. */
	public static function getUpdatesPageSize(): int {
		return (int) get_option( Constants::PAGE_SIZE_OPTION, 5 );
	}

	/** Max assigned orders shown in the admin bar dropdown. */
	public static function getAdminBarMaxOrders(): int {
		return (int) get_option( Constants::ADMIN_BAR_MAX_ORDERS_OPTION, 10 );
	}

	/** Cache lifetime in seconds for the assignee search results. */
	public static function getAssigneeSearchCacheTtl(): int {
		return (int) get_option( Constants::ASSIGNEE_SEARCH_CACHE_TTL_OPTION, 180 );
	}

	/** Cache lifetime in seconds for individual update records and order summaries. */
	public static function getUpdateCacheTtl(): int {
		return (int) get_option( Constants::UPDATE_CACHE_TTL_OPTION, 3600 );
	}

	/** Maximum upload size per attachment, in bytes (option stored as MB for UX). */
	public static function getMaxAttachmentBytes(): int {
		$configured = max( 1, (int) get_option( Constants::MAX_ATTACHMENT_MB_OPTION, 10 ) ) * 1024 * 1024;
		$php_max    = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : PHP_INT_MAX;
		return min( $configured, $php_max );
	}

	/** Maximum number of files a customer can attach to a single submission. */
	public static function getMaxAttachmentFiles(): int {
		return (int) get_option( Constants::MAX_ATTACHMENT_FILES_OPTION, 5 );
	}

	/** Lifetime in seconds for signed attachment download URLs. */
	public static function getSignedUrlTtl(): int {
		return (int) get_option( Constants::SIGNED_URL_TTL_OPTION, 3600 );
	}

	/** Days a signed customer-facing email URL stays valid (clamped 1–365). */
	public static function getCustomerLinkExpiryDays(): int {
		$days = (int) get_option( Constants::CUSTOMER_LINK_EXPIRY_DAYS_OPTION, 30 );

		return max( 1, min( 365, $days ) );
	}

	/** Whether the admin opted in to the "Powered by" credit in email footers. Default OFF per WP.org rules. */
	public static function shouldShowEmailFooterCredit(): bool {
		return 'yes' === get_option( Constants::SHOW_EMAIL_FOOTER_CREDIT_OPTION, 'no' );
	}

	/** Support contact email shown when a customer's link has expired. */
	public static function getSupportContactEmail(): string {
		$configured = (string) get_option( Constants::SUPPORT_CONTACT_EMAIL_OPTION, '' );
		$email      = '' !== $configured ? $configured : (string) get_option( 'admin_email', '' );

		return is_email( $email ) ? $email : '';
	}
}
