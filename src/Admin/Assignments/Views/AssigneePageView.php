<?php
/**
 * Assignments page view (per the design handoff).
 *
 * Rendered by AssigneePageController::render(). A dumb template — every value
 * arrives in $view_data and is escaped at output here. Data + view are kept
 * separate so a future front-end assignee page can reuse this template.
 *
 * @package OrderUpdatesForWoo
 *
 * @var array $view_data
 */

declare(strict_types=1);

// Local template vars only — this file is required inside View::render()'s
// method scope, so these never touch real WordPress globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows    = isset( $view_data['rows'] ) && is_array( $view_data['rows'] ) ? $view_data['rows'] : array();
$summary = isset( $view_data['summary'] ) && is_array( $view_data['summary'] ) ? $view_data['summary'] : array();
$status  = (string) ( $view_data['status'] ?? '' );
$total   = (int) ( $view_data['total'] ?? 0 );

// Tab links, built from the current URL minus status/paged.
$tab_base = remove_query_arg( array( 'status', 'paged' ) );
$tab      = static function ( string $key, string $label ) use ( $status, $tab_base ): string {
	$url   = '' === $key ? $tab_base : add_query_arg( 'status', $key, $tab_base );
	$class = 'awts-asg__tab' . ( $key === $status ? ' is-active' : '' );

	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
};

$longest = (string) ( $summary['longest_label'] ?? '' );
?>
<div class="wrap awts-asg">

	<?php // Accessible page heading + anchor so WordPress drops admin notices here, above the cards, instead of over them. ?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Assignments', 'order-updates-for-woo' ); ?></h1>
	<hr class="wp-header-end" />

	<nav class="awts-asg__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'order-updates-for-woo' ); ?>">
		<span><?php esc_html_e( 'WooCommerce', 'order-updates-for-woo' ); ?></span>
		<span class="awts-asg__crumb-sep" aria-hidden="true">&rsaquo;</span>
		<span class="awts-asg__crumb-strong"><?php esc_html_e( 'Order Updates', 'order-updates-for-woo' ); ?></span>
		<span class="awts-asg__crumb-sep" aria-hidden="true">&rsaquo;</span>
		<span class="awts-asg__crumb-strong" aria-current="page"><?php esc_html_e( 'Assignments', 'order-updates-for-woo' ); ?></span>
	</nav>

	<div class="awts-asg__stats">
		<div class="awts-asg__stat">
			<div class="awts-asg__stat-top">
				<span class="awts-asg__stat-label"><?php esc_html_e( 'Total updates', 'order-updates-for-woo' ); ?></span>
				<span class="awts-asg__stat-icon is-total"><span class="dashicons dashicons-archive"></span></span>
			</div>
			<div class="awts-asg__stat-value">
				<b><?php echo esc_html( number_format_i18n( (int) ( $summary['total'] ?? 0 ) ) ); ?></b>
				<span class="awts-asg__stat-sub"><?php echo ! empty( $view_data['sees_all'] ) ? esc_html__( 'store-wide', 'order-updates-for-woo' ) : esc_html__( 'assigned to you', 'order-updates-for-woo' ); ?></span>
			</div>
		</div>

		<div class="awts-asg__stat">
			<div class="awts-asg__stat-top">
				<span class="awts-asg__stat-label"><?php esc_html_e( 'Waiting', 'order-updates-for-woo' ); ?></span>
				<span class="awts-asg__stat-icon is-waiting"><span class="dashicons dashicons-clock"></span></span>
			</div>
			<div class="awts-asg__stat-value">
				<b><?php echo esc_html( number_format_i18n( (int) ( $summary['waiting'] ?? 0 ) ) ); ?></b>
				<span class="awts-asg__stat-sub"><?php esc_html_e( 'needs action', 'order-updates-for-woo' ); ?></span>
			</div>
		</div>

		<div class="awts-asg__stat">
			<div class="awts-asg__stat-top">
				<span class="awts-asg__stat-label"><?php esc_html_e( 'Resolved', 'order-updates-for-woo' ); ?></span>
				<span class="awts-asg__stat-icon is-resolved"><span class="dashicons dashicons-yes-alt"></span></span>
			</div>
			<div class="awts-asg__stat-value">
				<b><?php echo esc_html( number_format_i18n( (int) ( $summary['resolved'] ?? 0 ) ) ); ?></b>
			</div>
		</div>

		<div class="awts-asg__stat">
			<div class="awts-asg__stat-top">
				<span class="awts-asg__stat-label"><?php esc_html_e( 'Longest wait', 'order-updates-for-woo' ); ?></span>
				<span class="awts-asg__stat-icon is-longest"><span class="dashicons dashicons-warning"></span></span>
			</div>
			<div class="awts-asg__stat-value">
				<b><?php echo '' !== $longest ? esc_html( $longest ) : '—'; ?></b>
				<span class="awts-asg__stat-sub"><?php echo '' !== $longest ? esc_html__( 'oldest open', 'order-updates-for-woo' ) : esc_html__( 'none open', 'order-updates-for-woo' ); ?></span>
			</div>
		</div>
	</div>

	<div class="awts-asg__card">
		<div class="awts-asg__head">
			<div class="awts-asg__heading">
				<h2 class="awts-asg__title"><?php esc_html_e( 'Assignments', 'order-updates-for-woo' ); ?></h2>
				<span class="awts-asg__count">
					<?php
					/* translators: %s: number of updates */
					printf( esc_html( _n( '%s update', '%s updates', $total, 'order-updates-for-woo' ) ), esc_html( number_format_i18n( $total ) ) );
					?>
				</span>
			</div>
			<nav class="awts-asg__tabs">
				<?php
				// Links are assembled with esc_* in the $tab closure above.
				echo $tab( '', __( 'All', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $tab( 'open', __( 'Waiting', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $tab( 'solved', __( 'Resolved', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</nav>
		</div>

		<form class="awts-asg__filters" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( (string) $view_data['slug'] ); ?>" />
			<?php if ( '' !== $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
			<?php endif; ?>
			<div class="awts-asg__search">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input type="search" name="s" value="<?php echo esc_attr( (string) $view_data['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search updates', 'order-updates-for-woo' ); ?>" />
			</div>
			<?php $orderby = (string) ( $view_data['orderby'] ?? 'newest' ); ?>
			<select class="awts-asg__select" name="orderby" aria-label="<?php esc_attr_e( 'Sort by', 'order-updates-for-woo' ); ?>">
				<option value="newest" <?php selected( $orderby, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'order-updates-for-woo' ); ?></option>
				<option value="oldest" <?php selected( $orderby, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'order-updates-for-woo' ); ?></option>
				<option value="assignee" <?php selected( $orderby, 'assignee' ); ?>><?php esc_html_e( 'By assignee', 'order-updates-for-woo' ); ?></option>
			</select>
			<?php if ( ! empty( $view_data['sees_all'] ) && ! empty( $view_data['team'] ) ) : ?>
				<select class="awts-asg__select" name="assignee" aria-label="<?php esc_attr_e( 'Filter by assignee', 'order-updates-for-woo' ); ?>">
					<option value="0"><?php esc_html_e( 'All assignees', 'order-updates-for-woo' ); ?></option>
					<?php foreach ( (array) $view_data['team'] as $member ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $member['id'] ); ?>" <?php selected( (int) $view_data['assignee'], (int) $member['id'] ); ?>>
							<?php echo esc_html( (string) $member['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<button type="submit" class="awts-asg__filter-go"><?php esc_html_e( 'Filter', 'order-updates-for-woo' ); ?></button>
		</form>

		<?php if ( empty( $rows ) ) : ?>
			<p class="awts-asg__empty">
				<?php
				echo ! empty( $view_data['has_filters'] )
					? esc_html__( 'No updates match this filter.', 'order-updates-for-woo' )
					: esc_html__( 'No assigned updates.', 'order-updates-for-woo' );
				?>
			</p>
		<?php else : ?>
			<?php
			foreach ( $rows as $row ) :
				$waiting     = ! empty( $row['waiting'] );
				$resolved    = ! empty( $row['resolved'] );
				$open        = '' !== (string) $row['edit_url'];
				$tag         = $open ? 'a' : 'div';
				$href        = $open ? ' href="' . esc_url( (string) $row['edit_url'] ) . '"' : '';
				$accent      = $waiting ? '#f59e0b' : (string) $row['status_color'];
				$created     = (string) ( $row['created_avatar'] ?? '' );
				$assignee_av = (string) ( $row['assignee_avatar'] ?? '' );
				?>
				<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'a' or 'div'. ?> class="awts-asg__row"<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url'd above. ?>>
					<span class="awts-asg__accent" style="background: <?php echo esc_attr( $accent ); ?>"></span>

					<span class="awts-asg__icon <?php echo $resolved ? 'is-done' : 'is-waiting'; ?>">
						<span class="dashicons <?php echo $resolved ? 'dashicons-yes-alt' : 'dashicons-clock'; ?>"></span>
					</span>

					<span class="awts-asg__main">
						<span class="awts-asg__chips">
							<?php /* translators: %s: order number */ ?>
							<span class="awts-asg__chip"><?php printf( esc_html__( 'Order #%s', 'order-updates-for-woo' ), esc_html( (string) $row['order_no'] ) ); ?></span>
							<?php /* translators: %d: update id */ ?>
							<span class="awts-asg__chip"><?php printf( esc_html__( 'Update #%d', 'order-updates-for-woo' ), (int) $row['update_id'] ); ?></span>
							<span class="awts-asg__divider"></span>
							<span class="awts-asg__status" style="color: <?php echo esc_attr( (string) $row['status_color'] ); ?>">
								<span class="awts-asg__status-dot" style="background: <?php echo esc_attr( (string) $row['status_color'] ); ?>"></span>
								<?php echo esc_html( (string) $row['status'] ); ?>
							</span>
						</span>

						<span class="awts-asg__row-title"><?php echo esc_html( '' !== (string) $row['title'] ? (string) $row['title'] : __( '(untitled update)', 'order-updates-for-woo' ) ); ?></span>

						<span class="awts-asg__meta">
							<span class="awts-asg__person">
								<?php echo $created; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Avatar::html escapes internally. ?>
								<span><span class="awts-asg__muted"><?php esc_html_e( 'by', 'order-updates-for-woo' ); ?></span> <span class="awts-asg__name"><?php echo esc_html( (string) $row['created_by'] ); ?></span></span>
							</span>
							<?php if ( '' !== (string) $row['created_date'] ) : ?>
								<span class="awts-asg__date"><?php echo esc_html( (string) $row['created_date'] ); ?></span>
							<?php endif; ?>
							<span class="awts-asg__person">
								<span class="awts-asg__muted"><?php esc_html_e( 'assigned to', 'order-updates-for-woo' ); ?></span>
								<?php if ( '' !== (string) $row['assignee'] ) : ?>
									<?php echo $assignee_av; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Avatar::html escapes internally. ?>
									<span class="awts-asg__name"><?php echo esc_html( (string) $row['assignee'] ); ?></span>
								<?php else : ?>
									<span class="awts-asg__name"><?php esc_html_e( 'Unassigned', 'order-updates-for-woo' ); ?></span>
								<?php endif; ?>
							</span>
						</span>
					</span>

					<span class="awts-asg__right">
						<?php if ( $resolved ) : ?>
							<span class="awts-asg__pill is-done"><?php esc_html_e( 'Resolved', 'order-updates-for-woo' ); ?></span>
						<?php elseif ( $waiting ) : ?>
							<span class="awts-asg__pill is-waiting">
								<span class="dashicons dashicons-clock"></span>
								<?php /* translators: %s: wait time, e.g. "16h" */ ?>
								<?php printf( esc_html__( 'Waiting %s', 'order-updates-for-woo' ), esc_html( (string) $row['waiting_label'] ) ); ?>
							</span>
						<?php endif; ?>
						<span class="awts-asg__arrow" aria-hidden="true">&rarr;</span>
					</span>
				</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'a' or 'div'. ?>>
			<?php endforeach; ?>
		<?php endif; ?>

		<div class="awts-asg__foot">
			<span class="awts-asg__range">
				<?php
				printf(
					/* translators: 1: first row, 2: last row, 3: total */
					esc_html__( 'Showing %1$s of %2$s', 'order-updates-for-woo' ),
					'<b>' . esc_html( number_format_i18n( (int) ( $view_data['range_from'] ?? 0 ) ) ) . '&ndash;' . esc_html( number_format_i18n( (int) ( $view_data['range_to'] ?? 0 ) ) ) . '</b>',
					'<b>' . esc_html( number_format_i18n( $total ) ) . '</b>'
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers escaped above; only <b> wrappers are literal.
				?>
			</span>

			<?php
			$pages    = (int) ( $view_data['total_pages'] ?? 1 );
			$current  = (int) ( $view_data['page'] ?? 1 );
			$page_url = (string) ( $view_data['page_url'] ?? '' );
			$prev_url = (string) ( $view_data['prev_url'] ?? '' );
			$next_url = (string) ( $view_data['next_url'] ?? '' );
			if ( $pages > 1 ) :
				?>
				<div class="awts-asg__pager">
					<?php if ( '' !== $prev_url ) : ?>
						<a class="awts-asg__page" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'order-updates-for-woo' ); ?>">&lsaquo;</a>
					<?php else : ?>
						<span class="awts-asg__page is-disabled" aria-hidden="true">&lsaquo;</span>
					<?php endif; ?>

					<?php for ( $n = 1; $n <= $pages; $n++ ) : ?>
						<?php if ( $n === $current ) : ?>
							<span class="awts-asg__page is-active"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						<?php else : ?>
							<a class="awts-asg__page" href="<?php echo esc_url( add_query_arg( 'paged', $n, $page_url ) ); ?>"><?php echo esc_html( number_format_i18n( $n ) ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>

					<?php if ( '' !== $next_url ) : ?>
						<a class="awts-asg__page" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'order-updates-for-woo' ); ?>">&rsaquo;</a>
					<?php else : ?>
						<span class="awts-asg__page is-disabled" aria-hidden="true">&rsaquo;</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
