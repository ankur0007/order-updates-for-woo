<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupDb;
use OrderUpdatesForWoo\Shared\Config\Constants;

final class AnalyticsController {
	private const SLUG = 'order-updates-for-woo-analytics';

	public function __construct( private AnalyticsLookupDb $analytics_lookup_db ) {}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'schedule_warmup' ) );
		add_action( Constants::ANALYTICS_CRON_HOOK, array( $this, 'warmup_cache' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			\OrderUpdatesForWoo\Admin\AdminMenuController::PARENT_SLUG,
			__( 'Order Update Analytics', 'order-updates-for-woo' ),
			__( 'Analytics', 'order-updates-for-woo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function schedule_warmup(): void {
		if ( ! wp_next_scheduled( Constants::ANALYTICS_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', Constants::ANALYTICS_CRON_HOOK );
		}
	}

	public function render(): void {
		$css_path = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/analytics.css';
		$js_path  = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/analytics.js';
		$chart_path = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/vendor/chart.umd.min.js';

		wp_enqueue_style(
			'order-updates-for-woo-analytics',
			AssetHelper::url( 'assets/Admin/css/analytics.css' ),
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
		);

		wp_enqueue_script(
			'order-updates-for-woo-chartjs',
			ORDER_UPDATES_FOR_WOO_URL . 'assets/Admin/js/vendor/chart.umd.min.js',
			array(),
			file_exists( $chart_path ) ? (string) filemtime( $chart_path ) : '4',
			true
		);

		wp_enqueue_script(
			'order-updates-for-woo-analytics',
			AssetHelper::url( 'assets/Admin/js/analytics.js' ),
			array( 'jquery', 'order-updates-for-woo-chartjs' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);

		wp_localize_script(
			'order-updates-for-woo-analytics',
			'awtsAnalyticsData',
			array(
				'apiBase'  => rest_url( Constants::REST_NAMESPACE ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminUrl' => admin_url( 'post.php' ),
				'strings' => array(
					'loading'       => __( 'Loading…', 'order-updates-for-woo' ),
					'noData'        => __( 'No data for this period.', 'order-updates-for-woo' ),
					'total'         => __( 'Total', 'order-updates-for-woo' ),
					'solved'        => __( 'Solved', 'order-updates-for-woo' ),
					'pending'       => __( 'Pending', 'order-updates-for-woo' ),
					'avgRating'     => __( 'Avg Rating', 'order-updates-for-woo' ),
					'agent'         => __( 'Agent', 'order-updates-for-woo' ),
					'product'       => __( 'Product', 'order-updates-for-woo' ),
					'ticketsLabel'  => __( 'Tickets', 'order-updates-for-woo' ),
					'solvedLabel'   => __( 'Solved', 'order-updates-for-woo' ),
					'na'            => __( 'N/A', 'order-updates-for-woo' ),
					'thisMonth'     => __( 'This month', 'order-updates-for-woo' ),
					'lastMonth'     => __( 'Last month', 'order-updates-for-woo' ),
					'last3Months'   => __( 'Last 3 months', 'order-updates-for-woo' ),
					'last6Months'   => __( 'Last 6 months', 'order-updates-for-woo' ),
					'lastYear'      => __( 'Last year', 'order-updates-for-woo' ),
					'allTime'       => __( 'All time', 'order-updates-for-woo' ),
					'custom'        => __( 'Custom', 'order-updates-for-woo' ),
					'apply'         => __( 'Apply', 'order-updates-for-woo' ),
					'error'         => __( 'Failed to load analytics. Please try again.', 'order-updates-for-woo' ),
				),
			)
		);

		View::render( 'src/Admin/Analytics/Views/AnalyticsView' );
	}

	public function warmup_cache(): void {
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$today = $now->format( 'Y-m-d' );

		$ranges = array(
			array( $now->modify( 'first day of this month' )->format( 'Y-m-d' ), $today ),
			array( $now->modify( '-3 months' )->format( 'Y-m-d' ), $today ),
			array( $now->modify( '-6 months' )->format( 'Y-m-d' ), $today ),
			array( $now->modify( '-1 year' )->format( 'Y-m-d' ), $today ),
		);

		foreach ( $ranges as [ $from, $to ] ) {
			$this->analytics_lookup_db->summary( $from, $to );
			$this->analytics_lookup_db->by_date( $from, $to );
			$this->analytics_lookup_db->by_assignee( $from, $to );
			$this->analytics_lookup_db->by_product( $from, $to );
		}
	}
}
