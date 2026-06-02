<?php
/**
 * Builds social share links for the rating follow-up email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Build share-on-social links for the rating follow-up email.
 *
 * Stateless: each `build()` call hands back a list of
 * `{ platform, label, url }` rows the email template can render. The set is
 * filterable via `order_updates_for_woo_rating_share_links` so addons can
 * add platforms (Reddit, Threads, ...) or override URLs without forking
 * the email.
 */
final class RatingShareLinks {
	/**
	 * Build the list of share links, filterable so addons can add platforms.
	 *
	 * @param string $site_name Store name to put in the share text.
	 * @param string $site_url  URL to share; falls back to the home URL.
	 * @return array<int, array{platform:string, label:string, url:string}>
	 */
	public static function build( string $site_name, string $site_url ): array {
		$site_url  = '' !== $site_url ? $site_url : (string) home_url( '/' );
		$site_name = '' !== $site_name ? $site_name : (string) wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$share_text_template = (string) get_option(
			Constants::PROMOTER_SHARE_TEXT_OPTION,
			Constants::PROMOTER_SHARE_TEXT_DEFAULT
		);

		if ( '' === trim( $share_text_template ) ) {
			$share_text_template = Constants::PROMOTER_SHARE_TEXT_DEFAULT;
		}

		$share_text = str_replace(
			array( '{site_name}', '{site_url}' ),
			array( $site_name, $site_url ),
			$share_text_template
		);

		$encoded_url      = rawurlencode( $site_url );
		$encoded_text     = rawurlencode( $share_text );
		$encoded_combined = rawurlencode( $share_text . ' ' . $site_url );

		$links = array(
			array(
				'platform' => 'facebook',
				'label'    => __( 'Share on Facebook', 'order-updates-for-woo' ),
				// Facebook reads the link preview from the page's Open Graph
				// tags; `quote` pre-fills the sharer's comment box.
				'url'      => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url . '&quote=' . $encoded_text,
			),
			array(
				'platform' => 'x',
				'label'    => __( 'Share on X', 'order-updates-for-woo' ),
				'url'      => 'https://twitter.com/intent/tweet?text=' . $encoded_text . '&url=' . $encoded_url,
			),
			array(
				'platform' => 'whatsapp',
				'label'    => __( 'Share on WhatsApp', 'order-updates-for-woo' ),
				'url'      => 'https://wa.me/?text=' . $encoded_combined,
			),
			array(
				'platform' => 'linkedin',
				'label'    => __( 'Share on LinkedIn', 'order-updates-for-woo' ),
				'url'      => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded_url,
			),
		);

		return (array) apply_filters(
			'order_updates_for_woo_rating_share_links',
			$links,
			$site_name,
			$site_url,
			$share_text
		);
	}
}
