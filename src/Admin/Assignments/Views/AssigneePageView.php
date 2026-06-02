<?php
/**
 * Assignments page view.
 *
 * Rendered by AssigneePageController::render(). A dumb template — all values
 * arrive in $view_data and are escaped at output here. Reuses the .awts-inbox
 * styles from the Notifications page.
 *
 * @var array $view_data
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows   = isset( $view_data['rows'] ) && is_array( $view_data['rows'] ) ? $view_data['rows'] : array();
$status = (string) ( $view_data['status'] ?? '' );
$slug   = (string) ( $view_data['slug'] ?? '' );

// Status tab links, built from the current URL minus status/paged.
$base = remove_query_arg( array( 'status', 'paged' ) );
$tab  = static function ( string $key, string $label ) use ( $status, $base ): string {
	$url   = '' === $key ? $base : add_query_arg( 'status', $key, $base );
	$class = 'awts-inbox__tab' . ( $key === $status ? ' is-active' : '' );

	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
};
?>
<div class="wrap awts-inbox">
	<h1 class="awts-inbox__title"><?php esc_html_e( 'Assignments', 'order-updates-for-woo' ); ?></h1>

	<div class="awts-inbox__card">
		<div class="awts-inbox__head">
			<nav class="awts-inbox__tabs">
				<?php
				// Links are assembled with esc_attr/esc_url/esc_html above.
				echo $tab( '', __( 'All', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $tab( 'open', __( 'Open', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $tab( 'solved', __( 'Solved', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</nav>

			<form class="awts-inbox__search" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>" />
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $view_data['sees_all'] ) && (int) $view_data['assignee'] > 0 ) : ?>
					<input type="hidden" name="assignee" value="<?php echo esc_attr( (string) (int) $view_data['assignee'] ); ?>" />
				<?php endif; ?>
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input type="search" name="s" value="<?php echo esc_attr( (string) $view_data['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search updates', 'order-updates-for-woo' ); ?>" />
			</form>
		</div>

		<?php if ( ! empty( $view_data['sees_all'] ) && ! empty( $view_data['team'] ) ) : ?>
			<form class="awts-inbox__bulkbar awts-assignee-filter" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>" />
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<label for="awts-assignee-select"><?php esc_html_e( 'Assignee', 'order-updates-for-woo' ); ?></label>
				<select id="awts-assignee-select" name="assignee">
					<option value="0"><?php esc_html_e( 'Everyone', 'order-updates-for-woo' ); ?></option>
					<?php foreach ( (array) $view_data['team'] as $member ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $member['id'] ); ?>" <?php selected( (int) $view_data['assignee'], (int) $member['id'] ); ?>>
							<?php echo esc_html( (string) $member['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'order-updates-for-woo' ), '', 'filter_action', false ); ?>
			</form>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="awts-inbox__empty">
				<?php
				echo ! empty( $view_data['has_filters'] )
					? esc_html__( 'No updates match the current filter.', 'order-updates-for-woo' )
					: esc_html__( 'No assigned updates.', 'order-updates-for-woo' );
				?>
			</p>
		<?php else : ?>
			<ul class="awts-inbox__list">
				<?php foreach ( $rows as $row ) : ?>
					<li class="awts-inbox__row<?php echo empty( $row['resolved'] ) ? ' is-unread' : ' is-read'; ?>">
						<span class="awts-inbox__icon">
							<span class="dashicons <?php echo empty( $row['resolved'] ) ? 'dashicons-clock' : 'dashicons-yes-alt'; ?>"></span>
						</span>

						<?php $open = '' !== (string) $row['edit_url']; ?>
						<?php if ( $open ) : ?>
							<a class="awts-inbox__body" href="<?php echo esc_url( (string) $row['edit_url'] ); ?>">
						<?php else : ?>
							<span class="awts-inbox__body">
						<?php endif; ?>
							<span class="awts-inbox__text"><?php echo esc_html( '' !== (string) $row['title'] ? (string) $row['title'] : __( '(untitled update)', 'order-updates-for-woo' ) ); ?></span>
							<span class="awts-inbox__tags">
								<span class="awts-inbox__tag"><?php echo empty( $row['resolved'] ) ? esc_html__( 'Open', 'order-updates-for-woo' ) : esc_html__( 'Solved', 'order-updates-for-woo' ); ?></span>
								<?php /* translators: %s: order number */ ?>
								<span class="awts-inbox__idtag"><?php printf( esc_html__( 'Order: %s', 'order-updates-for-woo' ), esc_html( (string) $row['order_no'] ) ); ?></span>
								<?php /* translators: %d: update id */ ?>
								<span class="awts-inbox__idtag"><?php printf( esc_html__( 'Update: %d', 'order-updates-for-woo' ), (int) $row['update_id'] ); ?></span>
								<?php if ( '' !== (string) $row['customer'] ) : ?>
									<span class="awts-inbox__meta"><?php echo esc_html( (string) $row['customer'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $view_data['sees_all'] ) && '' !== (string) $row['assignee'] ) : ?>
									<?php /* translators: %s: assignee display name */ ?>
									<span class="awts-inbox__by"><?php printf( esc_html__( 'Assignee: %s', 'order-updates-for-woo' ), esc_html( (string) $row['assignee'] ) ); ?></span>
								<?php endif; ?>
							</span>
						<?php echo $open ? '</a>' : '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static closing tag. ?>

						<span class="awts-inbox__time"><?php echo esc_html( (string) $row['time'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div class="awts-inbox__foot">
			<span class="awts-inbox__range">
				<?php
				printf(
					/* translators: %s: number of updates */
					esc_html( _n( '%s update', '%s updates', (int) $view_data['total'], 'order-updates-for-woo' ) ),
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
	</div>
</div>
