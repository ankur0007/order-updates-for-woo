<?php
/**
 * Customer portal "write a note" modal markup.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data    = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$attach_hint  = isset( $view_data['attach_hint'] ) ? (string) $view_data['attach_hint'] : '';
$allowed_mime = isset( $view_data['allowed_mime'] ) && is_array( $view_data['allowed_mime'] ) ? $view_data['allowed_mime'] : array();
?>
<div class="awts_cou_modal" data-awts-cou-modal hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="awts_cou_modal_title">
	<div class="awts_cou_modal__backdrop" data-awts-cou-close tabindex="-1"></div>
	<div class="awts_cou_modal__dialog" role="document">
		<header class="awts_cou_modal__header">
			<h2 id="awts_cou_modal_title" class="awts_cou_modal__title">
				<?php esc_html_e( 'Write a note to our team', 'order-updates-for-woo' ); ?>
			</h2>
			<button type="button" class="awts_cou_modal__close" data-awts-cou-close aria-label="<?php echo esc_attr__( 'Close', 'order-updates-for-woo' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 3.5 12.5 12.5M12.5 3.5 3.5 12.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
		</header>

		<form class="awts_cou_modal__form" data-awts-cou-form novalidate>
			<div class="awts_drop_overlay" aria-hidden="true"><span><?php esc_html_e( 'Drop files to attach', 'order-updates-for-woo' ); ?></span></div>
			<p class="awts_cou_modal__intro"><?php esc_html_e( 'Have a question about this order? Send us a note and we\'ll get back to you.', 'order-updates-for-woo' ); ?></p>

			<label class="awts_cou_field">
				<span class="awts_cou_field__label"><?php esc_html_e( 'Subject', 'order-updates-for-woo' ); ?></span>
				<input type="text" name="title" maxlength="191" required
					placeholder="<?php echo esc_attr__( 'Brief summary of your question', 'order-updates-for-woo' ); ?>"
					data-awts-cou-subject />
			</label>

			<label class="awts_cou_field">
				<span class="awts_cou_field__label"><?php esc_html_e( 'Message', 'order-updates-for-woo' ); ?></span>
				<textarea name="message" rows="5" maxlength="500" required
					placeholder="<?php echo esc_attr__( 'Describe your question or request…', 'order-updates-for-woo' ); ?>"
					data-awts-cou-message></textarea>
			</label>

			<div class="awts_cou_field">
				<span class="awts_cou_field__label"><?php esc_html_e( 'Attach files (optional)', 'order-updates-for-woo' ); ?></span>
				<input type="file" name="files[]" multiple data-awts-cou-files
					accept="<?php echo esc_attr( implode( ',', $allowed_mime ) ); ?>" />
				<span class="awts_cou_field__hint"><?php echo esc_html( $attach_hint ); ?></span>
				<span class="awts_cou_field__hint awts_cou_field__hint--drag"><?php esc_html_e( 'Drag and drop files supported', 'order-updates-for-woo' ); ?></span>
				<ul class="awts_cou_file_list" data-awts-cou-file-list></ul>
			</div>

			<div class="awts_cou_modal__feedback" data-awts-cou-feedback aria-live="polite"></div>

			<footer class="awts_cou_modal__footer">
				<button type="button" class="awts_cou_btn awts_cou_btn--ghost" data-awts-cou-close>
					<?php esc_html_e( 'Cancel', 'order-updates-for-woo' ); ?>
				</button>
				<button type="submit" class="awts_cou_btn awts_cou_btn--primary" data-awts-cou-submit>
					<?php esc_html_e( 'Send note', 'order-updates-for-woo' ); ?>
				</button>
			</footer>
		</form>
	</div>
</div>
