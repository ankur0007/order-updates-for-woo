<?php
/**
 * Analytics page view.
 *
 * Rendered by AnalyticsController::render(). All dynamic data is loaded
 * via the REST API after page load; this file is static markup only.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap awts-analytics">
	<h1><?php esc_html_e( 'Order Update Analytics', 'order-updates-for-woo' ); ?></h1>

	<div class="awts-analytics__presets" role="toolbar" aria-label="<?php esc_attr_e( 'Date range', 'order-updates-for-woo' ); ?>">
		<div class="awts-analytics__preset-buttons">
			<button class="button awts-analytics__preset-btn" data-preset="today">
				<?php esc_html_e( 'Today', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn awts-analytics__preset-btn--active" data-preset="this_month">
				<?php esc_html_e( 'This month', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="last_month">
				<?php esc_html_e( 'Last month', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="last_3_months">
				<?php esc_html_e( 'Last 3 months', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="last_6_months">
				<?php esc_html_e( 'Last 6 months', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="last_year">
				<?php esc_html_e( 'Last year', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="all_time">
				<?php esc_html_e( 'All time', 'order-updates-for-woo' ); ?>
			</button>
			<button class="button awts-analytics__preset-btn" data-preset="custom">
				<?php esc_html_e( 'Custom', 'order-updates-for-woo' ); ?>
			</button>
		</div>

		<div class="awts-analytics__custom-range" hidden>
			<label class="screen-reader-text" for="awts-analytics-from"><?php esc_html_e( 'From', 'order-updates-for-woo' ); ?></label>
			<input type="date" id="awts-analytics-from" class="awts-analytics__date-input">
			<span aria-hidden="true">&ndash;</span>
			<label class="screen-reader-text" for="awts-analytics-to"><?php esc_html_e( 'To', 'order-updates-for-woo' ); ?></label>
			<input type="date" id="awts-analytics-to" class="awts-analytics__date-input">
			<button class="button button-primary awts-analytics__apply-btn">
				<?php esc_html_e( 'Apply', 'order-updates-for-woo' ); ?>
			</button>
		</div>
	</div>

	<div class="awts-analytics__error" hidden></div>

	<div class="awts-analytics__cards" aria-live="polite">
		<div class="awts-analytics__card">
			<div class="awts-analytics__card-label"><?php esc_html_e( 'Total', 'order-updates-for-woo' ); ?></div>
			<div class="awts-analytics__card-value" data-stat="total">&mdash;</div>
		</div>
		<div class="awts-analytics__card">
			<div class="awts-analytics__card-label"><?php esc_html_e( 'Solved', 'order-updates-for-woo' ); ?></div>
			<div class="awts-analytics__card-value" data-stat="solved">&mdash;</div>
		</div>
		<div class="awts-analytics__card">
			<div class="awts-analytics__card-label"><?php esc_html_e( 'Pending', 'order-updates-for-woo' ); ?></div>
			<div class="awts-analytics__card-value" data-stat="pending">&mdash;</div>
		</div>
		<div class="awts-analytics__card">
			<div class="awts-analytics__card-label"><?php esc_html_e( 'Avg Rating', 'order-updates-for-woo' ); ?></div>
			<div class="awts-analytics__card-value" data-stat="avg_rating">&mdash;</div>
		</div>
	</div>

	<div class="awts-analytics__chart-wrap">
		<canvas id="awts-analytics-chart" aria-label="<?php esc_attr_e( 'Tickets over time', 'order-updates-for-woo' ); ?>" role="img"></canvas>
	</div>

	<div class="awts-analytics__tables">
		<div class="awts-analytics__table-section">
			<h2 class="awts-analytics__table-title"><?php esc_html_e( 'By Agent', 'order-updates-for-woo' ); ?></h2>
			<table class="awts-analytics__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Agent', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Total', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Solved', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Pending', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Avg Rating', 'order-updates-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody id="awts-analytics-assignees-tbody">
					<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'order-updates-for-woo' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="awts-analytics__table-section">
			<h2 class="awts-analytics__table-title"><?php esc_html_e( 'By Product', 'order-updates-for-woo' ); ?></h2>
			<table class="awts-analytics__table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Total', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Solved', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Pending', 'order-updates-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody id="awts-analytics-products-tbody">
					<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'order-updates-for-woo' ); ?></td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
