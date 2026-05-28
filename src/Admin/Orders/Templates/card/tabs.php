<?php
/**
 * Card tab nav — internal notes / customer notes / tracking log.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/tabs.php
 *
 * Hook surface:
 *   - order_updates_for_woo_update_card_tabs (action) — append your own tabs.
 *
 * @var array $view_data {
 *     @type int    $update_id              Update id (for ARIA + JS targeting).
 *     @type bool   $customer_notes_enabled Whether the customer-notes tab renders.
 *     @type string $default_tab            Tab key that opens by default.
 *     @type array  $raw                    Update row (passed to the tabs hook).
 *     @type array  $settings               Plugin settings (passed to the tabs hook).
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$update_id              = (int) ( $view_data['update_id'] ?? 0 );
$customer_notes_enabled = ! empty( $view_data['customer_notes_enabled'] );
$default_tab            = (string) ( $view_data['default_tab'] ?? 'internal' );
$raw                    = $view_data['raw'] ?? array();
$settings               = $view_data['settings'] ?? array();

$tab_id = static fn( string $name ): string => 'awts_tab_' . $name . '_' . $update_id;
$panel  = static fn( string $name ): string => 'awts_panel_' . $name . '_' . $update_id;
?>
<div class="awts_card_tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Update tabs', 'order-updates-for-woo' ); ?>">

	<button
		type="button"
		role="tab"
		class="awts_card_tab<?php echo 'internal' === $default_tab ? ' awts_card_tab--active' : ''; ?>"
		data-awts-tab="internal"
		id="<?php echo esc_attr( $tab_id( 'internal' ) ); ?>"
		aria-controls="<?php echo esc_attr( $panel( 'internal' ) ); ?>"
		aria-selected="<?php echo 'internal' === $default_tab ? 'true' : 'false'; ?>"
		tabindex="<?php echo 'internal' === $default_tab ? '0' : '-1'; ?>"
	>
		<?php esc_html_e( 'Internal Notes', 'order-updates-for-woo' ); ?>
		<span class="awts_tab_count_badge" data-awts-tab-badge="internal" data-awts-count="0"></span>
	</button>

	<?php if ( $customer_notes_enabled ) : ?>
		<button
			type="button"
			role="tab"
			class="awts_card_tab<?php echo 'customer' === $default_tab ? ' awts_card_tab--active' : ''; ?>"
			data-awts-tab="customer"
			id="<?php echo esc_attr( $tab_id( 'customer' ) ); ?>"
			aria-controls="<?php echo esc_attr( $panel( 'customer' ) ); ?>"
			aria-selected="<?php echo 'customer' === $default_tab ? 'true' : 'false'; ?>"
			tabindex="<?php echo 'customer' === $default_tab ? '0' : '-1'; ?>"
		>
			<?php esc_html_e( 'Customer Notes', 'order-updates-for-woo' ); ?>
			<span class="awts_tab_count_badge" data-awts-tab-badge="customer" data-awts-count="0"></span>
		</button>
	<?php endif; ?>

	<button
		type="button"
		role="tab"
		class="awts_card_tab"
		data-awts-tab="participants"
		id="<?php echo esc_attr( $tab_id( 'participants' ) ); ?>"
		aria-controls="<?php echo esc_attr( $panel( 'participants' ) ); ?>"
		aria-selected="false"
		tabindex="-1"
	>
		<?php esc_html_e( 'Participants', 'order-updates-for-woo' ); ?>
	</button>

	<button
		type="button"
		role="tab"
		class="awts_card_tab"
		data-awts-tab="history"
		id="<?php echo esc_attr( $tab_id( 'history' ) ); ?>"
		aria-controls="<?php echo esc_attr( $panel( 'history' ) ); ?>"
		aria-selected="false"
		tabindex="-1"
	>
		<?php esc_html_e( 'Tracking Log', 'order-updates-for-woo' ); ?>
	</button>

	<?php do_action( 'order_updates_for_woo_update_card_tabs', $raw, $settings ); ?>

</div>
