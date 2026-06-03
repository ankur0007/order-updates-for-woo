<?php
/**
 * Participants tab panel — avatar + name + role chip for every staff user
 * who follows this update. The list is derived (creator, current assignee,
 *
 * @package OrderUpdatesForWoo
 *
 * @mentioned users, prior repliers) — there's no manual add/remove in v1.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/participants.php
 *
 * @var array $view_data {
 *     @type int   $update_id    Update id (panel + tab id targeting).
 *     @type string $tab_id      ARIA id of the controlling tab button.
 *     @type string $panel_id    DOM id for this panel.
 *     @type array  $participants Rows from ParticipantResolver::rows_for().
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tab_id       = (string) ( $view_data['tab_id'] ?? '' );
$panel_id     = (string) ( $view_data['panel_id'] ?? '' );
$participants = isset( $view_data['participants'] ) && is_array( $view_data['participants'] ) ? $view_data['participants'] : array();
?>
<div
	class="awts_participants_wrap awts_card_tab_panel"
	role="tabpanel"
	id="<?php echo esc_attr( $panel_id ); ?>"
	aria-labelledby="<?php echo esc_attr( $tab_id ); ?>"
	hidden
>
	<?php if ( empty( $participants ) ) : ?>
		<p class="awts_participants_empty">
			<?php esc_html_e( 'No participants yet. Anyone tagged, assigned, or replying on this update will appear here automatically.', 'order-updates-for-woo' ); ?>
		</p>
	<?php else : ?>
		<ul class="awts_participants_list">
			<?php foreach ( $participants as $participant ) : ?>
				<li class="awts_participant_row">
					<img
						class="awts_participant_avatar"
						src="<?php echo esc_url( (string) ( $participant['avatar_url'] ?? '' ) ); ?>"
						alt=""
						width="32"
						height="32"
					/>
					<span class="awts_participant_name">
						<?php echo esc_html( (string) ( $participant['name'] ?? '' ) ); ?>
					</span>
					<span class="awts_participant_role awts_participant_role--<?php echo esc_attr( (string) ( $participant['role'] ?? '' ) ); ?>">
						<?php echo esc_html( (string) ( $participant['role_label'] ?? '' ) ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
