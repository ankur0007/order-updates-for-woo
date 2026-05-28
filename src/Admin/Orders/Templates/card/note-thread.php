<?php
/**
 * Shared note-thread panel — used for both internal notes and customer notes.
 *
 * Renders the loading placeholder, then a composer (textarea + emoji button +
 * attach button + submit button) — or a "this update is resolved" notice when
 * the update is locked.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/note-thread.php
 *
 * @var array $view_data {
 *     @type string $type             'internal' | 'customer'
 *     @type int    $update_id        Update id (used in data attrs and ARIA wiring).
 *     @type string $tab_id           ARIA id of the controlling tab button.
 *     @type string $panel_id         ARIA id for this panel.
 *     @type bool   $is_active        Whether this panel is the default-open one.
 *     @type bool   $is_resolved      True when the update is closed (composer disabled).
 *     @type string $composer_placeholder  Textarea placeholder text.
 *     @type string $submit_label     Submit button label.
 * }
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$type      = (string) ( $view_data['type'] ?? 'internal' );
$update_id = (int) ( $view_data['update_id'] ?? 0 );
$tab_id    = (string) ( $view_data['tab_id'] ?? '' );
$panel_id  = (string) ( $view_data['panel_id'] ?? '' );
$is_active = ! empty( $view_data['is_active'] );
$is_resolved = ! empty( $view_data['is_resolved'] );
$composer_placeholder = (string) ( $view_data['composer_placeholder'] ?? '' );
$submit_label = (string) ( $view_data['submit_label'] ?? __( 'Add Note', 'order-updates-for-woo' ) );

// Class names differ between the two thread types so existing CSS and JS
// selectors keep working without changes.
$is_customer = 'customer' === $type;
$wrap_class    = $is_customer ? 'awts_customer_notes_wrap' : 'awts_notes_wrap';
$thread_class  = $is_customer ? 'awts_customer_notes_thread' : 'awts_notes_thread';
$loading_class = $is_customer ? 'awts_customer_notes_loading' : 'awts_notes_loading';
$loading_text  = $is_customer
	? __( 'Loading customer notes...', 'order-updates-for-woo' )
	: __( 'Loading notes...', 'order-updates-for-woo' );
$input_wrap_class    = $is_customer ? 'awts_customer_notes_input_wrap' : 'awts_notes_input_wrap';
$input_class         = $is_customer ? 'awts_customer_notes_input' : 'awts_notes_input';
$input_actions_class = $is_customer ? 'awts_customer_notes_input_actions' : 'awts_notes_input_actions';
$submit_class        = $is_customer ? 'awts_customer_notes_submit' : 'awts_notes_submit';
?>
<div
	class="<?php echo esc_attr( $wrap_class ); ?> awts_card_tab_panel"
	role="tabpanel"
	id="<?php echo esc_attr( $panel_id ); ?>"
	aria-labelledby="<?php echo esc_attr( $tab_id ); ?>"
	<?php echo $is_active ? '' : 'hidden'; ?>
>

	<?php if ( $is_customer ) : ?>
		<?php
		/**
		 * Notice slot above the customer-notes thread.
		 * Action lets addons inject extra notices.
		 */
		do_action( 'order_updates_for_woo_customer_notes_before_thread', $update_id );
		?>
	<?php endif; ?>

	<div class="<?php echo esc_attr( $thread_class ); ?>" data-awts-update-id="<?php echo esc_attr( (string) $update_id ); ?>">
		<p class="<?php echo esc_attr( $loading_class ); ?>"><?php echo esc_html( $loading_text ); ?></p>
	</div>

	<div class="<?php echo esc_attr( $input_wrap_class ); ?>">
		<?php if ( $is_resolved ) : ?>
			<p class="awts_notes_resolved_notice">
				<?php esc_html_e( 'This update is resolved. Re-open it to add notes.', 'order-updates-for-woo' ); ?>
			</p>
		<?php else : ?>
			<div class="awts_drop_overlay" aria-hidden="true">
				<span><?php esc_html_e( 'Drop files to attach', 'order-updates-for-woo' ); ?></span>
			</div>

			<textarea
				class="<?php echo esc_attr( $input_class ); ?>"
				placeholder="<?php echo esc_attr( $composer_placeholder ); ?>"
				maxlength="500"
			></textarea>

			<div class="awts_pending_attachments" hidden></div>

			<div class="<?php echo esc_attr( $input_actions_class ); ?>">
				<button type="button" class="awts_emoji_trigger" title="<?php echo esc_attr__( 'Add emoji', 'order-updates-for-woo' ); ?>" aria-label="<?php echo esc_attr__( 'Add emoji', 'order-updates-for-woo' ); ?>">
					<span class="awts_emoji_trigger__glyph" aria-hidden="true">&#x1F60A;</span>
				</button>

				<button type="button" class="awts_attach_trigger" title="<?php echo esc_attr__( 'Attach file', 'order-updates-for-woo' ); ?>">
					<?php echo Icons::dashicon( 'paperclip', __( 'Attach file', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>

				<input
					type="file"
					class="awts_attach_input"
					accept="<?php echo esc_attr( implode( ',', \OrderUpdatesForWoo\Shared\Attachments\AttachmentService::allowed_mime_types() ) ); ?>"
					multiple
					hidden
				>

				<span class="awts_drop_supported_hint">
					<?php esc_html_e( 'Drag and drop files supported', 'order-updates-for-woo' ); ?>
				</span>

				<label class="awts_enter_to_send" title="<?php echo esc_attr__( 'Press Enter to send. Use Shift+Enter for a new line.', 'order-updates-for-woo' ); ?>">
					<input type="checkbox" data-awts-enter-to-send>
					<?php esc_html_e( 'Enter = Send', 'order-updates-for-woo' ); ?>
				</label>

				<button type="button" class="awts_primary_button <?php echo esc_attr( $submit_class ); ?>">
					<?php echo esc_html( $submit_label ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</div>
