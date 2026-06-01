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
$pg   = isset( $view_data['pagination'] ) && is_array( $view_data['pagination'] ) ? $view_data['pagination'] : array();
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
					placeholder="<?php esc_attr_e( 'Search notifications…', 'order-updates-for-woo' ); ?>"
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

			<?php if ( ! empty( $view_data['is_archived'] ) && (int) $view_data['auto_delete_days'] > 0 ) : ?>
				<p class="awts-inbox__notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: number of days */
						esc_html( _n( 'Archived notifications are removed after %s day.', 'Archived notifications are removed after %s days.', (int) $view_data['auto_delete_days'], 'order-updates-for-woo' ) ),
						esc_html( number_format_i18n( (int) $view_data['auto_delete_days'] ) )
					);
					?>
				</p>
			<?php elseif ( empty( $view_data['is_archived'] ) && (int) $view_data['auto_archive_days'] > 0 ) : ?>
				<p class="awts-inbox__notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: number of days */
						esc_html( _n( 'Notifications are moved to Archived after %s day.', 'Notifications are moved to Archived after %s days.', (int) $view_data['auto_archive_days'], 'order-updates-for-woo' ) ),
						esc_html( number_format_i18n( (int) $view_data['auto_archive_days'] ) )
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

						// Primary line is the message (shown in quotes); fall back
						// to the type label when there's no message (e.g. assigned
						// / deleted rows).
						$is_message = '' !== (string) $row['snippet'];
						$primary    = $is_message
							? wp_trim_words( (string) $row['snippet'], 18, '…' )
							: (string) $row['label'];

						$read_tip    = ! empty( $row['unread'] ) ? __( 'Mark as read', 'order-updates-for-woo' ) : __( 'Mark as unread', 'order-updates-for-woo' );
						$read_action = ! empty( $row['unread'] ) ? 'mark_read' : 'mark_unread';
						$fav_tip     = ! empty( $row['favorited'] ) ? __( 'Remove favorite', 'order-updates-for-woo' ) : __( 'Favorite', 'order-updates-for-woo' );
						$fav_action  = ! empty( $row['favorited'] ) ? 'unfavorite' : 'favorite';
						$arch_tip    = ! empty( $row['archived'] ) ? __( 'Unarchive', 'order-updates-for-woo' ) : __( 'Archive', 'order-updates-for-woo' );
						$arch_action = ! empty( $row['archived'] ) ? 'unarchive' : 'archive';
						?>
						<li class="awts-inbox__row<?php echo esc_attr( $row_classes ); ?>">
							<input
								type="checkbox"
								class="awts-inbox__check"
								name="notif_keys[]"
								value="<?php echo esc_attr( (string) $row['key'] ); ?>"
								aria-label="<?php esc_attr_e( 'Select notification', 'order-updates-for-woo' ); ?>"
							/>
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
									<?php echo $is_message ? '&ldquo;' . esc_html( $primary ) . '&rdquo;' : esc_html( $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message escaped, quotes static. ?>
								</span>
								<span class="awts-inbox__tags">
									<?php if ( ! empty( $row['deleted'] ) ) : ?>
										<span class="awts-inbox__tag is-danger"><?php esc_html_e( 'Deleted', 'order-updates-for-woo' ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== (string) $row['context'] ) : ?>
										<span class="awts-inbox__tag"><?php echo esc_html( (string) $row['context'] ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $row['order_id'] > 0 ) : ?>
										<span class="awts-inbox__idtag"><?php printf( /* translators: %d: order id */ esc_html__( 'Order: %d', 'order-updates-for-woo' ), (int) $row['order_id'] ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $row['update_id'] > 0 ) : ?>
										<span class="awts-inbox__idtag"><?php printf( /* translators: %d: update id */ esc_html__( 'Update: %d', 'order-updates-for-woo' ), (int) $row['update_id'] ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $row['note_id'] > 0 ) : ?>
										<span class="awts-inbox__idtag"><?php printf( /* translators: %d: note id */ esc_html__( 'Note: %d', 'order-updates-for-woo' ), (int) $row['note_id'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== (string) $row['actor'] ) : ?>
										<?php /* translators: %s: sender display name */ ?>
										<span class="awts-inbox__by"><?php echo esc_html( sprintf( __( 'By %s', 'order-updates-for-woo' ), (string) $row['actor'] ) ); ?></span>
									<?php endif; ?>
								</span>
							<?php echo $open ? '</a>' : '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static closing tag. ?>

							<span class="awts-inbox__time"><?php echo esc_html( (string) $row['time'] ); ?></span>

							<span class="awts-inbox__actions">
								<a class="awts-inbox__action awts-inbox__read" rel="nofollow" href="<?php echo esc_url( (string) $row['read_url'] ); ?>" data-action="<?php echo esc_attr( $read_action ); ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-awts-tip="<?php echo esc_attr( $read_tip ); ?>" aria-label="<?php echo esc_attr( $read_tip ); ?>">
									<span class="awts-inbox__readmark" aria-hidden="true"></span>
								</a>

								<?php if ( '' !== (string) $row['reply_url'] ) : ?>
									<a class="awts-inbox__action" href="<?php echo esc_url( (string) $row['reply_url'] ); ?>" target="_blank" rel="noopener noreferrer" data-awts-tip="<?php esc_attr_e( 'Reply (opens in a new tab)', 'order-updates-for-woo' ); ?>" aria-label="<?php esc_attr_e( 'Reply (opens in a new tab)', 'order-updates-for-woo' ); ?>">
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
				<div class="awts-inbox__perpage">
					<select id="awts-inbox-perpage" aria-label="<?php esc_attr_e( 'Rows per page', 'order-updates-for-woo' ); ?>">
						<?php foreach ( (array) $view_data['per_page_options'] as $opt ) : ?>
							<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( (int) $view_data['per_page'], (int) $opt ); ?>>
								<?php
								/* translators: %s: number of rows */
								printf( esc_html__( '%s per page', 'order-updates-for-woo' ), esc_html( number_format_i18n( (int) $opt ) ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php if ( (int) ( $pg['total_pages'] ?? 1 ) > 1 ) : ?>
					<span class="awts-inbox__pager">
						<?php
						$nav = array(
							array( 'url' => (string) ( $pg['first_url'] ?? '' ), 'glyph' => '«', 'label' => __( 'First page', 'order-updates-for-woo' ) ),
							array( 'url' => (string) ( $pg['prev_url'] ?? '' ), 'glyph' => '‹', 'label' => __( 'Previous page', 'order-updates-for-woo' ) ),
						);
						foreach ( $nav as $item ) :
							if ( '' !== $item['url'] ) :
								?>
								<a class="awts-inbox__page-link" href="<?php echo esc_url( $item['url'] ); ?>" aria-label="<?php echo esc_attr( $item['label'] ); ?>"><?php echo esc_html( $item['glyph'] ); ?></a>
							<?php else : ?>
								<span class="awts-inbox__page-link is-disabled" aria-hidden="true"><?php echo esc_html( $item['glyph'] ); ?></span>
								<?php
							endif;
						endforeach;

						foreach ( (array) ( $pg['links'] ?? array() ) as $link ) :
							if ( ! empty( $link['ellipsis'] ) ) :
								?>
								<span class="awts-inbox__page-ellipsis">…</span>
								<?php
							elseif ( ! empty( $link['current'] ) ) :
								?>
								<span class="awts-inbox__page-link is-current"><?php echo esc_html( number_format_i18n( (int) $link['page'] ) ); ?></span>
							<?php else : ?>
								<a class="awts-inbox__page-link" href="<?php echo esc_url( (string) $link['url'] ); ?>"><?php echo esc_html( number_format_i18n( (int) $link['page'] ) ); ?></a>
								<?php
							endif;
						endforeach;

						$nav = array(
							array( 'url' => (string) ( $pg['next_url'] ?? '' ), 'glyph' => '›', 'label' => __( 'Next page', 'order-updates-for-woo' ) ),
							array( 'url' => (string) ( $pg['last_url'] ?? '' ), 'glyph' => '»', 'label' => __( 'Last page', 'order-updates-for-woo' ) ),
						);
						foreach ( $nav as $item ) :
							if ( '' !== $item['url'] ) :
								?>
								<a class="awts-inbox__page-link" href="<?php echo esc_url( $item['url'] ); ?>" aria-label="<?php echo esc_attr( $item['label'] ); ?>"><?php echo esc_html( $item['glyph'] ); ?></a>
							<?php else : ?>
								<span class="awts-inbox__page-link is-disabled" aria-hidden="true"><?php echo esc_html( $item['glyph'] ); ?></span>
								<?php
							endif;
						endforeach;
						?>
					</span>
				<?php endif; ?>

				<span class="awts-inbox__range">
					<?php
					if ( (int) ( $pg['total'] ?? 0 ) > 0 ) {
						printf(
							/* translators: 1: first row, 2: last row, 3: total */
							esc_html__( 'Showing %1$s to %2$s of %3$s', 'order-updates-for-woo' ),
							esc_html( number_format_i18n( (int) ( $pg['range_from'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (int) ( $pg['range_to'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (int) ( $pg['total'] ?? 0 ) ) )
						);
					}
					?>
				</span>
			</div>
		</form>
	</div>
</div>
