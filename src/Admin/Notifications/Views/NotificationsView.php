<?php
/**
 * Notifications inbox view.
 *
 * Rendered by NotificationsPageController::render(). A lightweight, custom
 * list — no WP_List_Table. All values arrive pre-built in $view_data and
 * are escaped at output here.
 *
 * @var array $view_data
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = isset( $view_data['rows'] ) && is_array( $view_data['rows'] ) ? $view_data['rows'] : array();
$tabs = isset( $view_data['tabs'] ) && is_array( $view_data['tabs'] ) ? $view_data['tabs'] : array();
?>
<div class="wrap awts-inbox">
	<h1 class="awts-inbox__title"><?php esc_html_e( 'Notifications', 'order-updates-for-woo' ); ?></h1>

	<div class="awts-inbox__card">
		<div class="awts-inbox__head">
			<nav class="awts-inbox__tabs">
				<?php foreach ( $tabs as $tab ) : ?>
					<a
						class="awts-inbox__tab<?php echo ! empty( $tab['active'] ) ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( (string) $tab['url'] ); ?>"
					>
						<?php echo esc_html( (string) $tab['label'] ); ?>
						<span class="awts-inbox__tab-count"><?php echo esc_html( number_format_i18n( (int) $tab['count'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<form class="awts-inbox__search" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( (string) $view_data['slug'] ); ?>" />
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input
					type="search"
					name="s"
					value="<?php echo esc_attr( (string) $view_data['search'] ); ?>"
					placeholder="<?php esc_attr_e( 'Search notifications', 'order-updates-for-woo' ); ?>"
				/>
			</form>
		</div>

		<form class="awts-inbox__list-form" method="post" action="<?php echo esc_url( (string) $view_data['form_action'] ); ?>">
			<?php echo $view_data['bulk_nonce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output. ?>

			<div class="awts-inbox__bulkbar">
				<label class="awts-inbox__selectall">
					<input type="checkbox" id="awts-inbox-select-all" />
					<?php esc_html_e( 'Select all', 'order-updates-for-woo' ); ?>
				</label>
				<button type="submit" name="action" value="mark_read" class="button awts-inbox__bulk-btn"><?php esc_html_e( 'Mark as read', 'order-updates-for-woo' ); ?></button>
				<button type="submit" name="action" value="archive" class="button awts-inbox__bulk-btn"><?php esc_html_e( 'Archive', 'order-updates-for-woo' ); ?></button>
				<button type="submit" name="action" value="delete" class="button awts-inbox__bulk-btn awts-inbox__bulk-btn--danger"><?php esc_html_e( 'Delete', 'order-updates-for-woo' ); ?></button>
			</div>

			<?php if ( ! empty( $view_data['is_archived'] ) ) : ?>
				<p class="awts-inbox__notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: number of days */
						esc_html( _n( 'Archived notifications are deleted automatically after %s day.', 'Archived notifications are deleted automatically after %s days.', (int) $view_data['archive_days'], 'order-updates-for-woo' ) ),
						esc_html( number_format_i18n( (int) $view_data['archive_days'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p class="awts-inbox__empty">
					<?php
					echo ! empty( $view_data['has_filters'] )
						? esc_html__( 'No notifications match the current filter.', 'order-updates-for-woo' )
						: esc_html__( 'You have no notifications yet.', 'order-updates-for-woo' );
					?>
				</p>
			<?php else : ?>
				<ul class="awts-inbox__list">
					<?php
					foreach ( $rows as $row ) :
						$row_classes  = ! empty( $row['unread'] ) ? ' is-unread' : ' is-read';
						$row_classes .= ! empty( $row['favorited'] ) ? ' is-favorited' : '';

						// "By Jane Doe · Customer note" — whichever parts are present.
						$byline = '';
						if ( '' !== (string) $row['actor'] ) {
							/* translators: %s: sender display name */
							$byline = sprintf( __( 'By %s', 'order-updates-for-woo' ), (string) $row['actor'] );
						}
						if ( '' !== (string) $row['context'] ) {
							$byline = '' !== $byline ? $byline . ' · ' . (string) $row['context'] : (string) $row['context'];
						}
						?>
						<li class="awts-inbox__row<?php echo esc_attr( $row_classes ); ?>">
							<input
								type="checkbox"
								class="awts-inbox__check"
								name="notif_keys[]"
								value="<?php echo esc_attr( (string) $row['key'] ); ?>"
								aria-label="<?php esc_attr_e( 'Select notification', 'order-updates-for-woo' ); ?>"
							/>
							<span class="awts-inbox__dot" aria-hidden="true"></span>
							<span class="awts-inbox__icon">
								<span class="dashicons <?php echo esc_attr( (string) $row['icon'] ); ?>"></span>
							</span>

							<?php
							$open = '' !== (string) $row['deep_url'];
							if ( $open ) :
								?>
								<a class="awts-inbox__body" href="<?php echo esc_url( (string) $row['deep_url'] ); ?>">
							<?php else : ?>
								<span class="awts-inbox__body">
							<?php endif; ?>
								<span class="awts-inbox__text">
									<span class="awts-inbox__label"><?php echo esc_html( (string) $row['label'] ); ?></span>
									<?php if ( '' !== (string) $row['snippet'] ) : ?>
										<span class="awts-inbox__snippet"><?php echo esc_html( wp_trim_words( (string) $row['snippet'], 18, '…' ) ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( '' !== $byline ) : ?>
									<span class="awts-inbox__byline"><?php echo esc_html( $byline ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== (string) $row['meta'] ) : ?>
									<span class="awts-inbox__meta"><?php echo esc_html( (string) $row['meta'] ); ?></span>
								<?php endif; ?>
							<?php echo $open ? '</a>' : '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static closing tag. ?>

							<span class="awts-inbox__time"><?php echo esc_html( (string) $row['time'] ); ?></span>

							<span class="awts-inbox__actions">
								<?php
								$read_tip    = ! empty( $row['unread'] ) ? __( 'Mark as read', 'order-updates-for-woo' ) : __( 'Mark as unread', 'order-updates-for-woo' );
								$read_icon   = ! empty( $row['unread'] ) ? 'dashicons-yes-alt' : 'dashicons-marker';
								$read_action = ! empty( $row['unread'] ) ? 'mark_read' : 'mark_unread';
								$fav_tip     = ! empty( $row['favorited'] ) ? __( 'Remove favorite', 'order-updates-for-woo' ) : __( 'Favorite', 'order-updates-for-woo' );
								$fav_action  = ! empty( $row['favorited'] ) ? 'unfavorite' : 'favorite';
								$arch_tip    = ! empty( $row['archived'] ) ? __( 'Unarchive', 'order-updates-for-woo' ) : __( 'Archive', 'order-updates-for-woo' );
								$arch_action = ! empty( $row['archived'] ) ? 'unarchive' : 'archive';
								?>
								<a class="awts-inbox__action" rel="nofollow" href="<?php echo esc_url( (string) $row['read_url'] ); ?>" data-action="<?php echo esc_attr( $read_action ); ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-awts-tip="<?php echo esc_attr( $read_tip ); ?>" aria-label="<?php echo esc_attr( $read_tip ); ?>">
									<span class="dashicons <?php echo esc_attr( $read_icon ); ?>" aria-hidden="true"></span>
								</a>

								<?php if ( '' !== (string) $row['reply_url'] ) : ?>
									<a class="awts-inbox__action" href="<?php echo esc_url( (string) $row['reply_url'] ); ?>" data-awts-tip="<?php esc_attr_e( 'Reply', 'order-updates-for-woo' ); ?>" aria-label="<?php esc_attr_e( 'Reply', 'order-updates-for-woo' ); ?>">
										<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
									</a>
								<?php endif; ?>

								<a class="awts-inbox__action awts-inbox__fav" rel="nofollow" href="<?php echo esc_url( (string) $row['fav_url'] ); ?>" data-action="<?php echo esc_attr( $fav_action ); ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-awts-tip="<?php echo esc_attr( $fav_tip ); ?>" aria-label="<?php echo esc_attr( $fav_tip ); ?>">
									<span class="dashicons <?php echo ! empty( $row['favorited'] ) ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span>
								</a>

								<a class="awts-inbox__action" rel="nofollow" href="<?php echo esc_url( (string) $row['archive_url'] ); ?>" data-action="<?php echo esc_attr( $arch_action ); ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-awts-tip="<?php echo esc_attr( $arch_tip ); ?>" aria-label="<?php echo esc_attr( $arch_tip ); ?>">
									<span class="dashicons dashicons-archive" aria-hidden="true"></span>
								</a>

								<a class="awts-inbox__action awts-inbox__action--danger" rel="nofollow" href="<?php echo esc_url( (string) $row['delete_url'] ); ?>" data-action="delete" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-awts-tip="<?php esc_attr_e( 'Delete', 'order-updates-for-woo' ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'order-updates-for-woo' ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								</a>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="awts-inbox__foot">
				<span class="awts-inbox__total">
					<?php
					printf(
						/* translators: %s: number of notifications */
						esc_html( _n( '%s notification', '%s notifications', (int) $view_data['total'], 'order-updates-for-woo' ) ),
						esc_html( number_format_i18n( (int) $view_data['total'] ) )
					);
					?>
				</span>

				<?php if ( (int) $view_data['total_pages'] > 1 ) : ?>
					<span class="awts-inbox__pager">
						<?php if ( '' !== (string) $view_data['prev_url'] ) : ?>
							<a class="awts-inbox__page-link" href="<?php echo esc_url( (string) $view_data['prev_url'] ); ?>"><?php esc_html_e( '‹ Prev', 'order-updates-for-woo' ); ?></a>
						<?php endif; ?>
						<span class="awts-inbox__page-of">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages */
								esc_html__( 'Page %1$s of %2$s', 'order-updates-for-woo' ),
								esc_html( number_format_i18n( (int) $view_data['page'] ) ),
								esc_html( number_format_i18n( (int) $view_data['total_pages'] ) )
							);
							?>
						</span>
						<?php if ( '' !== (string) $view_data['next_url'] ) : ?>
							<a class="awts-inbox__page-link" href="<?php echo esc_url( (string) $view_data['next_url'] ); ?>"><?php esc_html_e( 'Next ›', 'order-updates-for-woo' ); ?></a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</div>
		</form>
	</div>
</div>
