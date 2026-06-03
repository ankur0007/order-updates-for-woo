<?php
/**
 * Order table columns and filters for order updates.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Admin\Orders\Services\OrderTableFiltersService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Adds a compact "Updates" column and assignee / unsolved filters to the WooCommerce orders table.
 * Handles both the classic post list and the HPOS list table.
 */
final class OrderTableUpdateFiltersController {
	/**
	 * In-memory prefetch cache for the current request's order summaries.
	 * Populated in bulk before the column render loop starts.
	 *
	 * @var array<int, array>|null
	 */
	private ?array $prefetched_summaries = null;

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb           $order_updates_db Injected dependency.
	 * @param OrderTableFiltersService $filters_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private OrderTableFiltersService $filters_service
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		if ( $this->filters_service->is_hpos_enabled() ) {
			$this->register_hpos_hooks();
		} else {
			$this->register_classic_hooks();
		}
	}

	// -------------------------------------------------------------------------
	// Asset
	// -------------------------------------------------------------------------

	/** Enqueue the orders-table filter CSS on the orders list screen. */
	public function enqueue_styles(): void {
		if ( ! $this->filters_service->is_orders_list_screen() ) {
			return;
		}

		wp_enqueue_style(
			'order-updates-for-woo-table',
			AssetHelper::url( 'assets/Admin/css/order-table-filters.css' ),
			array(),
			file_exists( ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/order-table-filters.css' ) ? (string) filemtime( ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/order-table-filters.css' ) : '1.0.0'
		);
	}

	// -------------------------------------------------------------------------
	// HPOS hooks
	// -------------------------------------------------------------------------

	/** Wire the orders-table column + filter hooks for HPOS storage. */
	private function register_hpos_hooks(): void {
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_hpos_column' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filters' ) );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'apply_hpos_filter' ), 10, 3 );
		// Prefetch summaries after HPOS loads its order objects.
		add_action( 'woocommerce_shop_order_list_table_prepare_items', array( $this, 'prefetch_hpos_summaries' ) );
	}

	// -------------------------------------------------------------------------
	// Classic hooks
	// -------------------------------------------------------------------------

	/** Wire the orders-table column + filter hooks for classic (post) storage. */
	private function register_classic_hooks(): void {
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_classic_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'maybe_render_filters' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_classic_filter' ) );
		// Prefetch summaries once WP_Query has the post results.
		add_filter( 'posts_results', array( $this, 'prefetch_classic_summaries' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Bulk prefetch
	// -------------------------------------------------------------------------

	/**
	 * Prefetch summaries for all orders on the current classic list page.
	 * Fires via posts_results — runs once, returns posts unmodified.
	 *
	 * @param \WP_Post[] $posts  Posts returned by WP_Query.
	 * @param \WP_Query  $query  Current query.
	 * @return \WP_Post[]
	 */
	public function prefetch_classic_summaries( array $posts, \WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return $posts;
		}

		$order_ids = array_map( static fn( \WP_Post $p ) => (int) $p->ID, $posts );

		if ( ! empty( $order_ids ) ) {
			$this->prefetched_summaries = $this->order_updates_db->get_order_update_summaries( $order_ids );
		}

		return $posts;
	}

	/**
	 * Prefetch summaries for all orders on the current HPOS list page.
	 * Fires after the list table has loaded its items.
	 *
	 * @param \Automattic\WooCommerce\Internal\Admin\Orders\ListTable $list_table The HPOS list table.
	 */
	public function prefetch_hpos_summaries( $list_table ): void {
		if ( ! method_exists( $list_table, 'get_items' ) ) {
			return;
		}

		$order_ids = array_map(
			static fn( \WC_Order $o ) => $o->get_id(),
			(array) $list_table->get_items()
		);

		if ( ! empty( $order_ids ) ) {
			$this->prefetched_summaries = $this->order_updates_db->get_order_update_summaries( $order_ids );
		}
	}

	// -------------------------------------------------------------------------
	// Column
	// -------------------------------------------------------------------------

	/**
	 * Add the Updates column to the orders table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( array $columns ): array {
		$columns['awts_updates'] = esc_html__( 'Updates', 'order-updates-for-woo' );
		return $columns;
	}

	/**
	 * Render the column for HPOS — receives WC_Order.
	 *
	 * @param string    $column Column ID.
	 * @param \WC_Order $order  Order object.
	 */
	public function render_hpos_column( string $column, \WC_Order $order ): void {
		if ( 'awts_updates' !== $column ) {
			return;
		}

		$this->render_column_content( $order->get_id() );
	}

	/**
	 * Render the column for classic post list — receives post ID.
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id Post / order ID.
	 */
	public function render_classic_column( string $column, int $post_id ): void {
		if ( 'awts_updates' !== $column ) {
			return;
		}

		$this->render_column_content( $post_id );
	}

	/**
	 * Output the compact updates summary for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	private function render_column_content( int $order_id ): void {
		// Use prefetched data when available (avoids N+1 during list render).
		if ( null !== $this->prefetched_summaries ) {
			$empty   = array(
				'update_count'         => 0,
				'unsolved_count'       => 0,
				'has_customer_visible' => false,
				'assignee_name'        => '',
			);
			$summary = $this->prefetched_summaries[ $order_id ] ?? $empty;
		} else {
			$summary = $this->order_updates_db->get_order_update_summary( $order_id );
		}

		if ( ! $summary['update_count'] ) {
			echo '<span class="awts-col-none">&#8212;</span>';
			return;
		}

		echo '<div class="awts-col">';

		printf(
			'<span class="awts-col-count">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of updates */
					_n( '%d update', '%d updates', $summary['update_count'], 'order-updates-for-woo' ),
					$summary['update_count']
				)
			)
		);

		if ( $summary['assignee_name'] ) {
			printf(
				'<span class="awts-col-assignee">%s</span>',
				esc_html( $summary['assignee_name'] )
			);
		}

		if ( $summary['unsolved_count'] ) {
			printf(
				'<span class="awts-col-unsolved">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: number of unsolved updates */
						_n( '%d unsolved', '%d unsolved', $summary['unsolved_count'], 'order-updates-for-woo' ),
						$summary['unsolved_count']
					)
				)
			);
		}

		if ( $summary['has_customer_visible'] ) {
			printf(
				'<span class="awts-col-visible">%s</span>',
				esc_html__( 'Customer visible', 'order-updates-for-woo' )
			);
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Filter UI
	// -------------------------------------------------------------------------

	/**
	 * Render filter dropdowns for the HPOS orders table.
	 */
	public function render_filters(): void {
		$this->output_filter_html();
	}

	/**
	 * Render filter dropdowns for classic post list (guard post type).
	 */
	public function maybe_render_filters(): void {
		global $typenow;

		if ( 'shop_order' !== $typenow ) {
			return;
		}

		$this->output_filter_html();
	}

	/**
	 * Output the filter <select> elements.
	 */
	private function output_filter_html(): void {
		$users           = $this->order_updates_db->get_users_with_active_assignments();
		$active_assignee = $this->filters_service->get_active_assignee_filter();
		$active_unsolved = $this->filters_service->get_active_unsolved_filter();
		$assignee_param  = $this->filters_service->assignee_param();
		$unsolved_param  = $this->filters_service->unsolved_param();

		// Assignee dropdown.
		echo '<select name="' . esc_attr( $assignee_param ) . '" id="awts_filter_assignee">';
		echo '<option value="">' . esc_html__( 'All assignees', 'order-updates-for-woo' ) . '</option>';

		foreach ( $users as $user ) {
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $user['id'] ),
				selected( $active_assignee, absint( $user['id'] ), false ),
				esc_html( $user['display_name'] )
			);
		}

		echo '</select>';

		// Unsolved dropdown.
		echo '<select name="' . esc_attr( $unsolved_param ) . '" id="awts_filter_unsolved">';
		echo '<option value="">' . esc_html__( 'All updates', 'order-updates-for-woo' ) . '</option>';
		printf(
			'<option value="1"%s>%s</option>',
			selected( $active_unsolved, true, false ),
			esc_html__( 'Has unsolved updates', 'order-updates-for-woo' )
		);
		echo '</select>';
	}

	// -------------------------------------------------------------------------
	// Filter query — classic
	// -------------------------------------------------------------------------

	/**
	 * Apply filters to the classic WP_Query on the orders list.
	 *
	 * @param \WP_Query $query The main query.
	 */
	public function apply_classic_filter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$order_ids = $this->get_matching_order_ids();

		if ( null === $order_ids ) {
			return;
		}

		$this->filters_service->modify_query( $query, $order_ids );
	}

	// -------------------------------------------------------------------------
	// Filter query — HPOS
	// -------------------------------------------------------------------------

	/**
	 * Apply filters to the HPOS SQL query.
	 *
	 * @param array $clauses    SQL clause fragments.
	 * @param mixed $query      OrdersTableQuery object; kept for the hook signature.
	 * @param array $query_vars Raw query vars; kept for the hook signature.
	 * @return array
	 */
	public function apply_hpos_filter( array $clauses, $query, array $query_vars ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $query/$query_vars are part of the WC filter signature for other code.
		$order_ids = $this->get_matching_order_ids();
		
		if ( null === $order_ids ) {
			return $clauses;
		}

		$clauses = $this->filters_service->modify_clauses( $clauses, $order_ids );
		
		
		return $clauses;
	}

	// -------------------------------------------------------------------------
	// Shared filter logic
	// -------------------------------------------------------------------------

	/**
	 * Return matching order IDs based on active filter params.
	 * Returns null when no filters are active.
	 * Returns an empty array when filters are active but nothing matches.
	 *
	 * @return int[]|null
	 */
	private function get_matching_order_ids(): ?array {
		$assignee_id     = $this->filters_service->get_active_assignee_filter();
		$unsolved_active = $this->filters_service->get_active_unsolved_filter();

		if ( ! $assignee_id && ! $unsolved_active ) {
			return null;
		}

		$sets = array();

		if ( $assignee_id ) {
			$sets[] = $this->order_updates_db->get_assigned_order_ids_for_user( $assignee_id );
		}
		
		if ( $unsolved_active ) {
			$sets[] = $this->order_updates_db->get_order_ids_with_unsolved_updates();
		}

		// Intersect all sets so both filters must match when both are active.
		$result = array_shift( $sets );

		foreach ( $sets as $set ) {
			$result = array_intersect( $result, $set );
		}
		
		return array_values( $result );
	}
}
