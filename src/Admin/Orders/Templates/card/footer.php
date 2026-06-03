<?php
/**
 * Card footer — created-by, assignee, solved-date row.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/footer.php
 *
 * @package OrderUpdatesForWoo
 *
 * @var array $view_data {
 *     @type array $raw       Update row.
 *     @type array $settings  Plugin settings.
 *     @type array $formatted Display-formatted fields (date, names).
 *     @type array $flags     Computed flags from the parent view.
 * }
 */

declare(strict_types=1);

// Local template vars only — this file is required inside View::render()'s
// method scope, so these never touch real WordPress globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

use OrderUpdatesForWoo\Helpers\Avatar;
use OrderUpdatesForWoo\Helpers\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$raw       = $view_data['raw'] ?? array();
$settings  = $view_data['settings'] ?? array();
$formatted = $view_data['formatted'] ?? array();
$flags     = $view_data['flags'] ?? array();

$created_by_name = (string) ( $formatted['created_by_name'] ?? '' );
$created_date    = (string) ( $formatted['created_date'] ?? '' );
$assigned_to     = (string) ( $formatted['assigned_to'] ?? '' );
$solved_date     = (string) ( $flags['solved_date'] ?? '' );

$has_assignee = ! empty( $flags['has_assignee'] );
$can_edit     = ! empty( $flags['can_edit'] );
$is_resolved  = ! empty( $flags['is_resolved'] );

// A resolved update is locked: no assignee / status editing until re-opened.
$can_edit_meta = $can_edit && ! $is_resolved;

$statuses           = isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array();
$current_status_key = (string) ( $raw['status'] ?? '' );
$current_color      = strtolower( (string) ( $raw['color'] ?? '' ) );

// Resolve the current status row from the admin's list. Color and label are
// derived here so the footer pill stays in sync when the admin renames or
// recolors a status — the stored key is the only stable reference.
$current_status = null;
foreach ( $statuses as $row ) {
	if ( ( $row['key'] ?? '' ) === $current_status_key ) {
		$current_status = $row;
		break;
	}
}

$current_color = $current_status ? strtolower( (string) ( $current_status['color'] ?? $current_color ) ) : $current_color;
?>
<div class="awts_footer">

	<?php if ( '' !== $created_by_name || '' !== $created_date ) : ?>
		<span class="awts_footer_item">
			<?php echo Avatar::html( (int) ( $raw['created_by_user_id'] ?? 0 ), $created_by_name, 'awts_assignee_avatar', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Avatar::html escapes internally. ?>
			<strong><?php echo esc_html( $created_by_name ); ?></strong>
			<?php if ( '' !== $created_date ) : ?>
				<span class="awts_footer_sep">·</span>
				<?php echo esc_html( $created_date ); ?>
			<?php endif; ?>
		</span>
	<?php endif; ?>

	<?php if ( ! empty( $settings['enable_assignee'] ) && ( $has_assignee || $can_edit_meta ) ) : ?>
		<span class="awts_footer_item awts_assignee_item" data-awts-assignee-id="<?php echo esc_attr( (string) ( $raw['assignee_user_id'] ?? '' ) ); ?>">
			<?php if ( $has_assignee ) : ?>
				<span class="awts_footer_label awts_footer_label--muted">
					<?php esc_html_e( 'Assigned to', 'order-updates-for-woo' ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $can_edit_meta ) : ?>
				<button type="button" class="awts_inline_edit_btn awts_edit_assignee">
					<?php if ( $has_assignee ) : ?>
						<?php echo Avatar::html( (int) ( $raw['assignee_user_id'] ?? 0 ), $assigned_to, 'awts_assignee_avatar', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Avatar::html escapes internally. ?>
						<strong class="awts_assignee_name"><?php echo esc_html( $assigned_to ); ?></strong>
					<?php else : ?>
						<span class="awts_footer_label awts_footer_label--muted">
							<?php esc_html_e( 'Assign', 'order-updates-for-woo' ); ?>
						</span>
					<?php endif; ?>
					<?php echo Icons::dashicon( 'edit', __( 'Edit assignee', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			<?php elseif ( $has_assignee ) : ?>
				<strong class="awts_assignee_name"><?php echo esc_html( $assigned_to ); ?></strong>
			<?php endif; ?>

			<div class="awts_inline_assignee_wrap" hidden>
				<input
					type="text"
					class="awts_inline_assignee_input"
					placeholder="<?php echo esc_attr__( 'Searches admins, shop managers and editors.', 'order-updates-for-woo' ); ?>"
					autocomplete="off"
					autocorrect="off"
					autocapitalize="off"
					spellcheck="false"
					data-1p-ignore="true"
				>
				<input
					type="hidden"
					class="awts_inline_assignee_id"
					value="<?php echo esc_attr( (string) ( $raw['assignee_user_id'] ?? '' ) ); ?>"
				>
			</div>
		</span>
	<?php endif; ?>

	<?php
	if ( ! empty( $statuses ) ) :
		$current_status_label = $current_status ? (string) ( $current_status['label'] ?? '' ) : '';
		?>
		<span class="awts_footer_item awts_status_item" data-awts-update-id="<?php echo esc_attr( (string) ( $raw['id'] ?? '' ) ); ?>">
			<span class="awts_footer_label awts_footer_label--muted">
				<?php esc_html_e( 'Status', 'order-updates-for-woo' ); ?>
			</span>

			<?php if ( $can_edit_meta ) : ?>
				<button type="button" class="awts_inline_edit_btn awts_edit_status" title="<?php echo esc_attr__( 'Change status', 'order-updates-for-woo' ); ?>">
					<span class="awts_status_pill" style="background:<?php echo esc_attr( $current_color ); ?>1a; color:<?php echo esc_attr( $current_color ); ?>;">
						<span class="awts_status_pill_dot" style="background:<?php echo esc_attr( $current_color ); ?>;"></span>
						<?php echo esc_html( '' !== $current_status_label ? $current_status_label : $current_color ); ?>
					</span>
					<?php echo Icons::dashicon( 'edit', __( 'Change status', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			<?php else : ?>
				<span class="awts_status_pill" style="background:<?php echo esc_attr( $current_color ); ?>1a; color:<?php echo esc_attr( $current_color ); ?>;">
					<span class="awts_status_pill_dot" style="background:<?php echo esc_attr( $current_color ); ?>;"></span>
					<?php echo esc_html( '' !== $current_status_label ? $current_status_label : $current_color ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $can_edit_meta ) : ?>
				<div class="awts_inline_status_wrap" hidden>
					<select class="awts_status_picker" data-awts-status-picker aria-label="<?php echo esc_attr__( 'Pick a new status', 'order-updates-for-woo' ); ?>">
						<?php
						foreach ( $statuses as $status ) :
							$option_key   = (string) ( $status['key'] ?? '' );
							$option_label = (string) ( $status['label'] ?? '' );
							if ( '' === $option_key || '' === $option_label ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, $current_status_key ); ?>>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</span>
	<?php endif; ?>

	<?php if ( ! empty( $settings['enable_solved_state'] ) && $is_resolved ) : ?>
		<span class="awts_footer_item awts_footer_solved">
			<?php echo Icons::dashicon( 'yes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			if ( '' !== $solved_date ) {
				echo esc_html(
					sprintf(
						/* translators: %s: solved date. */
						__( 'Solved · %s', 'order-updates-for-woo' ),
						$solved_date
					)
				);
			} else {
				esc_html_e( 'Solved', 'order-updates-for-woo' );
			}
			?>
		</span>
	<?php endif; ?>

</div>

<?php
$rating_stars   = (int) ( $flags['rating_stars'] ?? 0 );
$rating_comment = (string) ( $flags['rating_comment'] ?? '' );

// Render the rating block only when the customer has actually rated
// (rating_stars > 0 implies a stored rating row with a non-empty
// created_at — see OrderUpdateCardViewModern). The "sent" / "requested"
// pending states keep their compact tag at the top of the card.
if ( $rating_stars > 0 ) :
	?>
	<div class="awts_footer_rating" aria-label="<?php echo esc_attr__( 'Customer rating', 'order-updates-for-woo' ); ?>">
		<div class="awts_footer_rating__row">
			<span class="awts_footer_rating__label">
				<?php esc_html_e( 'Customer rated', 'order-updates-for-woo' ); ?>
			</span>
			<span class="awts_footer_rating__stars" role="img" aria-label="
			<?php
				/* translators: %s: rating value out of 5. */
				echo esc_attr( sprintf( __( '%s out of 5 stars', 'order-updates-for-woo' ), $rating_stars ) );
			?>
			">
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<span class="awts_footer_rating__star<?php echo $i <= $rating_stars ? ' awts_footer_rating__star--filled' : ''; ?>" aria-hidden="true">
						<?php echo $i <= $rating_stars ? '&#9733;' : '&#9734;'; ?>
					</span>
				<?php endfor; ?>
			</span>
			<span class="awts_footer_rating__score">
				<?php echo esc_html( sprintf( '%d/5', $rating_stars ) ); ?>
			</span>
		</div>

		<?php if ( '' !== $rating_comment ) : ?>
			<p class="awts_footer_rating__comment">
				<?php echo esc_html( $rating_comment ); ?>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>
