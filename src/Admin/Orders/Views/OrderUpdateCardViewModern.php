<?php
/**
 * Admin order update card view — modern layout.
 *
 * This file is the orchestrator: it computes display flags from the raw
 * update row, then delegates each visual block to a theme-overridable
 * template under `src/Admin/Orders/Templates/card/`. To customize the UI,
 * override the partials in your theme rather than editing this file.
 *
 * Override paths (drop in your theme):
 *   - your-theme/order-updates-for-woo/admin/orders/card/header.php
 *   - your-theme/order-updates-for-woo/admin/orders/card/tags.php
 *   - your-theme/order-updates-for-woo/admin/orders/card/footer.php
 *   - your-theme/order-updates-for-woo/admin/orders/card/tabs.php
 *   - your-theme/order-updates-for-woo/admin/orders/card/note-thread.php
 *   - your-theme/order-updates-for-woo/admin/orders/card/customer-visibility-notice.php
 *
 * Hook surface:
 *   - order_updates_for_woo_update_card_actions          (action) — buttons in the card actions row.
 *   - order_updates_for_woo_update_card_before_details   (action) — fires before the footer.
 *   - order_updates_for_woo_update_card_after_details    (action) — fires after the footer.
 *   - order_updates_for_woo_update_card_tabs             (action) — append custom tabs.
 *   - order_updates_for_woo_customer_notes_before_thread (action) — inject notices above the customer thread.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data      = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$settings       = isset( $view_data['settings'] ) && is_array( $view_data['settings'] ) ? $view_data['settings'] : array();
$card_variables = isset( $view_data['card_variables'] ) && is_array( $view_data['card_variables'] ) ? $view_data['card_variables'] : array();
$raw            = isset( $card_variables['raw'] ) && is_array( $card_variables['raw'] ) ? $card_variables['raw'] : array();
$formatted      = isset( $card_variables['formatted'] ) && is_array( $card_variables['formatted'] ) ? $card_variables['formatted'] : array();
$rating         = isset( $card_variables['rating'] ) && is_array( $card_variables['rating'] ) ? $card_variables['rating'] : array();

// Computed display flags. Pass these to the partials in $view_data['flags']
// so partials don't recompute from the raw row.
$flags = array(
	'is_customer_visible' => UpdateState::is_customer_visible( $raw ),
	'is_notified'         => UpdateState::is_customer_notified( $raw ),
	'can_edit'            => UpdateState::can_edit( $raw ),
	'has_assignee'        => UpdateState::has_assignee( $raw ),
	'is_resolved'         => UpdateState::is_resolved( $raw ),
	'staff_email_muted'   => ! empty( $card_variables['staff_email_muted'] ),
	'rating_status'       => '',
	'rating_status_text'  => '',
	'rating_comment_text' => '',
	'rating_stars'        => 0,
	'rating_comment'      => '',
	'solved_date'         => '',
);

if ( $flags['is_resolved'] && ! empty( $raw['solved_at'] ) ) {
	$flags['solved_date'] = DateHelper::format_date( (string) $raw['solved_at'] );
}

if ( ! empty( $settings['enable_customer_rating'] ) && $flags['is_customer_visible'] && ! empty( $rating ) ) {
	if ( ! empty( $rating['created_at'] ) ) {
		$flags['rating_status'] = 'received';
		$stars                  = max( 0, min( 5, (int) ( $rating['stars'] ?? 0 ) ) );
		$flags['rating_stars']  = $stars;
		$flags['rating_status_text'] = sprintf(
			/* translators: %s: rating value out of 5. */
			__( 'Customer rated %s/5', 'order-updates-for-woo' ),
			$stars
		);

		if ( ! empty( $rating['comment'] ) ) {
			$flags['rating_comment']      = (string) $rating['comment'];
			$flags['rating_comment_text'] = sprintf(
				/* translators: %s: customer rating comment. */
				__( 'Comment: %s', 'order-updates-for-woo' ),
				(string) $rating['comment']
			);
		}
	} elseif ( $flags['is_resolved'] && ! empty( $rating['request_notified_at'] ) ) {
		$flags['rating_status']      = 'sent';
		$flags['rating_status_text'] = __( 'Rating email sent · awaiting customer', 'order-updates-for-woo' );
	} elseif ( $flags['is_resolved'] && ! empty( $rating['requested_at'] ) ) {
		$flags['rating_status']      = 'requested';
		$flags['rating_status_text'] = __( 'Rating email queued · awaiting customer', 'order-updates-for-woo' );
	}
}

$update_id    = absint( $raw['id'] ?? 0 );
$update_color = ! empty( $raw['color'] ) ? (string) $raw['color'] : '#dc3232';

// Resolve the human-readable status label for this color by matching against
// the admin-configured status list. Stays empty when the color doesn't map —
// the side-stripe still shows, but no pill is rendered. Lookup keys come
// via $view_data so the view doesn't reach for a service directly.
$status_lookup_by_color = isset( $view_data['status_lookup_by_color'] ) && is_array( $view_data['status_lookup_by_color'] )
	? $view_data['status_lookup_by_color']
	: array();
$flags['status_label'] = (string) ( $status_lookup_by_color[ strtolower( $update_color ) ]['label'] ?? '' );

$customer_notes_enabled = ! empty( $settings['enable_customer_note'] );
$default_tab            = 'internal';

// Guest detection — `$raw` does not include order_id (it isn't in the DB
// SELECT for get_order_updates), so the panel passes it via $view_data.
$panel_order_id = isset( $view_data['order_id'] ) ? absint( $view_data['order_id'] ) : 0;
$customer_order = $panel_order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $panel_order_id ) : null;
$is_guest_order = $customer_order && 0 === (int) $customer_order->get_customer_id();

$tab_id_for   = static fn( string $name ): string => 'awts_tab_' . $name . '_' . $update_id;
$panel_id_for = static fn( string $name ): string => 'awts_panel_' . $name . '_' . $update_id;
?>
<div
	class="awts_card<?php echo $flags['is_resolved'] ? ' awts_card--collapsed awts_card--resolved' : ''; ?>"
	id="awts-update-<?php echo esc_attr( (string) $update_id ); ?>"
	data-awts-update-id="<?php echo esc_attr( (string) $update_id ); ?>"
	data-awts-customer-visible="<?php echo $flags['is_customer_visible'] ? '1' : '0'; ?>"
>
	<div class="awts_card_left_border" style="background:<?php echo esc_attr( $update_color ); ?>;"></div>

	<div class="awts_card_content">

		<?php
		View::render( 'src/Admin/Orders/Templates/card/header', array(
			'raw'      => $raw,
			'settings' => $settings,
			'flags'    => $flags,
		) );
		?>

		<?php
		View::render( 'src/Admin/Orders/Templates/card/tags', array(
			'flags' => $flags,
		) );
		?>

		<div class="awts_card_body">

			<?php do_action( 'order_updates_for_woo_update_card_before_details', $raw, $settings ); ?>

			<?php
			View::render( 'src/Admin/Orders/Templates/card/footer', array(
				'raw'       => $raw,
				'settings'  => $settings,
				'formatted' => $formatted,
				'flags'     => $flags,
				'statuses'  => isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array(),
			) );
			?>

			<?php do_action( 'order_updates_for_woo_update_card_after_details', $raw, $settings ); ?>

		</div>

		<?php
		View::render( 'src/Admin/Orders/Templates/card/tabs', array(
			'update_id'              => $update_id,
			'customer_notes_enabled' => $customer_notes_enabled,
			'default_tab'            => $default_tab,
			'raw'                    => $raw,
			'settings'               => $settings,
		) );
		?>

		<?php
		// Participants are derived server-side by the panel controller and
		// passed through `card_variables['participants']`. Small list (rarely
		// more than ~10 names), so no AJAX needed for v1.
		View::render( 'src/Admin/Orders/Templates/card/participants', array(
			'update_id'    => $update_id,
			'tab_id'       => $tab_id_for( 'participants' ),
			'panel_id'     => $panel_id_for( 'participants' ),
			'participants' => isset( $card_variables['participants'] ) && is_array( $card_variables['participants'] ) ? $card_variables['participants'] : array(),
		) );
		?>

		<!-- Tracking-log panel — JS lazy-loads the rows on tab activation. -->
		<div
			class="awts_history_wrap awts_card_tab_panel"
			role="tabpanel"
			id="<?php echo esc_attr( $panel_id_for( 'history' ) ); ?>"
			aria-labelledby="<?php echo esc_attr( $tab_id_for( 'history' ) ); ?>"
			hidden
		>
			<div class="awts_history_inline" data-awts-update-id="<?php echo esc_attr( (string) $update_id ); ?>"></div>
		</div>

		<?php
		// Internal notes thread.
		View::render( 'src/Admin/Orders/Templates/card/note-thread', array(
			'type'                 => 'internal',
			'update_id'            => $update_id,
			'tab_id'               => $tab_id_for( 'internal' ),
			'panel_id'             => $panel_id_for( 'internal' ),
			'is_active'            => 'internal' === $default_tab,
			'is_resolved'          => $flags['is_resolved'],
			'composer_placeholder' => __( 'Write a note...', 'order-updates-for-woo' ),
			'submit_label'         => __( 'Add Note', 'order-updates-for-woo' ),
		) );
		?>

		<?php if ( $customer_notes_enabled ) : ?>
			<?php
			// The visibility notice renders inside the customer-notes panel,
			// above the thread. Hook it onto the slot action that note-thread
			// fires; clean up after to keep the closure scoped to this card.
			$is_customer_visible = ! empty( $raw['customer_visible'] );
			$render_visibility_notice = static function () use ( $is_guest_order, $is_customer_visible ): void {
				View::render( 'src/Admin/Orders/Templates/card/customer-visibility-notice', array(
					'is_guest_order'      => $is_guest_order,
					'is_customer_visible' => $is_customer_visible,
				) );
			};
			add_action( 'order_updates_for_woo_customer_notes_before_thread', $render_visibility_notice );

			View::render( 'src/Admin/Orders/Templates/card/note-thread', array(
				'type'                 => 'customer',
				'update_id'            => $update_id,
				'tab_id'               => $tab_id_for( 'customer' ),
				'panel_id'             => $panel_id_for( 'customer' ),
				'is_active'            => 'customer' === $default_tab,
				'is_resolved'          => $flags['is_resolved'],
				'composer_placeholder' => __( 'Write a note for the customer...', 'order-updates-for-woo' ),
				'submit_label'         => __( 'Add Note', 'order-updates-for-woo' ),
			) );

			remove_action( 'order_updates_for_woo_customer_notes_before_thread', $render_visibility_notice );
			?>
		<?php endif; ?>

	</div>
</div>
