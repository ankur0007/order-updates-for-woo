<?php
/**
 * Card tag row — visibility, notification status, rating.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/tags.php
 *
 * @var array $view_data {
 *     @type array $flags  Computed flags from the parent view.
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$flags = $view_data['flags'] ?? array();

$is_notified         = ! empty( $flags['is_notified'] );
$rating_status       = (string) ( $flags['rating_status'] ?? '' );
$rating_status_text  = (string) ( $flags['rating_status_text'] ?? '' );
$rating_comment_text = (string) ( $flags['rating_comment_text'] ?? '' );

// "Customer visible" used to live here as a tag, but the footer now carries
// an interactive Customer pill (Status -> Customer -> Assignee) that doubles
// as both the indicator and the toggle. Two places for the same fact reads
// as clutter; the tag row drops it.
$has_any = $is_notified || '' !== $rating_status;

if ( ! $has_any ) {
	return;
}
?>
<div class="awts_card_tags">
	<?php if ( $is_notified ) : ?>
		<span class="awts_tag awts_tag_notified">
			<?php esc_html_e( 'Customer notified', 'order-updates-for-woo' ); ?>
		</span>
	<?php endif; ?>

	<?php if ( '' !== $rating_status ) : ?>
		<span
			class="awts_tag awts_tag_rating awts_tag_rating--<?php echo esc_attr( $rating_status ); ?>"
			<?php if ( '' !== $rating_comment_text ) : ?>
				title="<?php echo esc_attr( $rating_comment_text ); ?>"
			<?php endif; ?>
		>
			<?php echo esc_html( $rating_status_text ); ?>
		</span>
	<?php endif; ?>
</div>
