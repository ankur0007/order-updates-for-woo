<?php
/**
 * Order edit meta box that surfaces the audit log of deleted updates.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Helpers\HposHelper;
use OrderUpdatesForWoo\Shared\Audit\DeletedUpdatesLog;
use WC_Order;

/**
 * Read-only meta box. We own the rendering so there's no per-record delete
 * button to hide — the audit is tamper-resistant by virtue of having no UI
 * surface that mutates it.
 */
final class DeletedUpdatesMetaBox {
	private const META_BOX_ID = 'awts-deleted-updates-log';

	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Deleted update history', 'order-updates-for-woo' ),
			array( $this, 'render' ),
			HposHelper::order_edit_screen_id(),
			'side',
			'low'
		);
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( $post_or_order ) : null );

		if ( ! $order instanceof WC_Order ) {
			echo '<p class="awts_deleted_updates_log__empty">' . esc_html__( 'No deleted updates yet.', 'order-updates-for-woo' ) . '</p>';
			return;
		}

		$records = DeletedUpdatesLog::get_for_order( $order );

		if ( empty( $records ) ) {
			echo '<p class="awts_deleted_updates_log__empty">' . esc_html__( 'No deleted updates yet.', 'order-updates-for-woo' ) . '</p>';
			return;
		}

		// Most recent first reads better in an audit context.
		$records = array_reverse( $records );

		echo '<ul class="awts_deleted_updates_log">';

		foreach ( $records as $record ) {
			$this->render_record( (array) $record );
		}

		echo '</ul>';
	}

	private function render_record( array $record ): void {
		$title           = (string) ( $record['title'] ?? '' );
		$update_id       = absint( $record['update_id'] ?? 0 );
		$deleted_at_raw  = (string) ( $record['deleted_at'] ?? '' );
		$deleted_at      = '' !== $deleted_at_raw ? DateHelper::format_date( $deleted_at_raw ) : '';
		$deleted_by_name = (string) ( $record['deleted_by_name'] ?? __( 'Unknown user', 'order-updates-for-woo' ) );
		$events          = is_array( $record['events'] ?? null ) ? $record['events'] : array();

		?>
		<li class="awts_deleted_updates_log__item">
			<details>
				<summary class="awts_deleted_updates_log__summary">
					<span class="awts_deleted_updates_log__title"><?php echo esc_html( '' !== $title ? $title : __( '(untitled update)', 'order-updates-for-woo' ) ); ?></span>
					<span class="awts_deleted_updates_log__meta">
						<?php
						/* translators: 1: actor display name, 2: timestamp. */
						echo esc_html( sprintf( __( 'Deleted by %1$s · %2$s', 'order-updates-for-woo' ), $deleted_by_name, $deleted_at ) );
						?>
					</span>
				</summary>
				<ol class="awts_deleted_updates_log__events">
					<?php foreach ( $events as $event ) :
						$line = $this->format_event( (array) $event );
						if ( '' === $line ) {
							continue;
						}
						?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ol>
				<?php if ( $update_id > 0 ) : ?>
					<p class="awts_deleted_updates_log__id">
						<?php
						/* translators: %d: deleted update id. */
						echo esc_html( sprintf( __( 'Update ID #%d', 'order-updates-for-woo' ), $update_id ) );
						?>
					</p>
				<?php endif; ?>
			</details>
		</li>
		<?php
	}

	private function format_event( array $event ): string {
		$type      = (string) ( $event['type'] ?? '' );
		$timestamp = DateHelper::format_date( (string) ( $event['timestamp'] ?? '' ) );
		$by        = (string) ( $event['performed_by_name'] ?? __( 'Unknown user', 'order-updates-for-woo' ) );
		$assignee  = (string) ( $event['assignee_name'] ?? __( 'Unknown user', 'order-updates-for-woo' ) );

		switch ( $type ) {
			case 'created':
				return sprintf( /* translators: 1: actor, 2: timestamp. */ __( 'Created by %1$s at %2$s', 'order-updates-for-woo' ), $by, $timestamp );
			case 'assigned':
				return sprintf( /* translators: 1: assignee, 2: actor, 3: timestamp. */ __( 'Assigned to %1$s by %2$s at %3$s', 'order-updates-for-woo' ), $assignee, $by, $timestamp );
			case 'unassigned':
				return sprintf( /* translators: 1: assignee, 2: actor, 3: timestamp. */ __( 'Unassigned from %1$s by %2$s at %3$s', 'order-updates-for-woo' ), $assignee, $by, $timestamp );
			case 'notified_assignee':
				return sprintf( /* translators: 1: assignee, 2: timestamp. */ __( 'Notified assignee (%1$s) at %2$s', 'order-updates-for-woo' ), $assignee, $timestamp );
			case 'solved':
				return sprintf( /* translators: 1: actor, 2: timestamp. */ __( 'Marked solved by %1$s at %2$s', 'order-updates-for-woo' ), $by, $timestamp );
			case 'reopened':
				return sprintf( /* translators: 1: actor, 2: timestamp. */ __( 'Reopened by %1$s at %2$s', 'order-updates-for-woo' ), $by, $timestamp );
			case 'notified_customer':
				return sprintf( /* translators: %s: timestamp. */ __( 'Notified customer at %s', 'order-updates-for-woo' ), $timestamp );
			case 'status_changed':
			case 'title_changed':
				$message = (string) ( $event['message'] ?? '' );
				return sprintf( /* translators: 1: change message, 2: actor, 3: timestamp. */ __( '%1$s by %2$s at %3$s', 'order-updates-for-woo' ), $message, $by, $timestamp );
			case 'rated':
				// Message already reads "Customer rated X/5 …" so we skip
				// the "by <actor>" suffix — it would render "by Unknown
				// user" for guest raters and feels redundant when the
				// actor is implied in the message itself.
				$message = (string) ( $event['message'] ?? '' );
				return sprintf( /* translators: 1: rating message, 2: timestamp. */ __( '%1$s at %2$s', 'order-updates-for-woo' ), $message, $timestamp );
			default:
				return '';
		}
	}
}
