<?php
/**
 * Settings field — ordered, drag-and-drop list of staff who receive
 * customer-initiated updates in rotation.
 *
 * Renders as a custom WooCommerce settings type. Persists an ordered array
 * of int user ids under the option keyed by the field's `id`.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Fields;

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Validation\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assignee Rotation Field.
 */
final class AssigneeRotationField {
	public const FIELD_TYPE = 'order_updates_for_woo_assignee_rotation';

	private const ASSET_HANDLE = 'order-updates-for-woo-assignee-rotation';

	/**
	 * Inject dependencies.
	 *
	 * @param TeamRosterService $team_roster Injected dependency.
	 * @param Validator         $validator Injected dependency.
	 */
	public function __construct(
		private TeamRosterService $team_roster,
		private Validator $validator
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render the custom rotation field.
	 *
	 * @param array{id:string,name:string,desc?:string,desc_tip?:bool} $value Field definition.
	 */
	public function render( array $value ): void {
		$option_id = (string) ( $value['id'] ?? '' );

		if ( '' === $option_id ) {
			return;
		}

		$saved   = $this->load_saved( $option_id );
		$ordered = $this->order_members( $this->team_roster->get_team_members(), array_keys( $saved ) );

		$tooltip = ! empty( $value['desc_tip'] ) ? wc_help_tip( (string) ( $value['desc'] ?? '' ) ) : '';
		$desc    = ! empty( $value['desc'] ) && empty( $value['desc_tip'] )
			? '<p class="description">' . wp_kses_post( (string) $value['desc'] ) . '</p>'
			: '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( (string) ( $value['name'] ?? '' ) ); ?></label>
				<?php echo $tooltip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip is safe ?>
			</th>
			<td class="forminp">
				<?php echo $desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( empty( $ordered ) ) : ?>
					<p class="awts_assignee_rotation__empty">
						<?php esc_html_e( 'No team members found for the selected internal-team roles. Add staff to those roles, then refresh the team roster from this page.', 'order-updates-for-woo' ); ?>
					</p>
				<?php else : ?>
					<p class="awts_assignee_rotation__hint">
						<?php esc_html_e( 'Customer-submitted updates rotate through the checked members in this order. Drag to reorder. Uncheck to skip a member without removing them.', 'order-updates-for-woo' ); ?>
					</p>

					<ul class="awts_assignee_rotation__list" data-awts-rotation-list>
						<?php foreach ( $ordered as $member ) : ?>
							<?php $this->render_member_row( $option_id, $member, ! empty( $saved[ (int) ( $member['id'] ?? 0 ) ] ) ); ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize the submitted compound payload. The form ships two arrays:
	 *
	 *   [order]  — every rendered member id in display (drag) order
	 *   [active] — only the checked member ids
	 *
	 * Order and active state are stored independently so a paused member
	 * keeps their slot for when the admin re-enables them. Storage shape:
	 * `[ id => bool ]` keyed associative, insertion order preserved.
	 *
	 * @param mixed                          $value     Value sanitized so far.
	 * @param array{type?:string,id?:string} $option    Field definition.
	 * @param mixed                          $raw_value Raw submitted value.
	 *
	 * @return array<int,bool>|mixed
	 */
	public function sanitize( $value, array $option, $raw_value ) {
		if ( self::FIELD_TYPE !== (string) ( $option['type'] ?? '' ) ) {
			return $value;
		}

		if ( ! is_array( $raw_value ) ) {
			return array();
		}

		$order         = $this->validator->sanitize_mentioned_user_ids( $raw_value['order'] ?? array() );
		$active        = $this->validator->sanitize_mentioned_user_ids( $raw_value['active'] ?? array() );
		$active_lookup = array_fill_keys( $active, true );

		$result = array();
		foreach ( $order as $id ) {
			$result[ $id ] = ! empty( $active_lookup[ $id ] );
		}

		return $result;
	}

	/**
	 * Enqueue the field's assets only on our settings tab. jQuery UI
	 * Sortable ships with WP core — no extra dependency.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'order_updates_for_woo' !== $tab ) {
			return;
		}

		$css_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/assignee-rotation.css';
		$js_file  = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/assignee-rotation.js';

		wp_enqueue_style(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/css/assignee-rotation.css' ),
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0'
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/js/assignee-rotation.js' ),
			array( 'jquery', 'jquery-ui-sortable' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);
	}

	/**
	 * Render one member row in the rotation list.
	 *
	 * @param string                                               $option_id Option name.
	 * @param array{id:int,name:string,email:string,avatar:string} $member    Member data.
	 * @param bool                                                 $is_active Whether the member is active.
	 */
	private function render_member_row( string $option_id, array $member, bool $is_active ): void {
		$member_id = (int) ( $member['id'] ?? 0 );

		/**
		 * Filter the data passed to the rotation row template, so addons can
		 * inject extra columns (role label, last-active, etc.) without
		 * overriding the whole field.
		 *
		 * @param array{id:int,name:string,email:string,avatar:string} $member    Member data.
		 * @param bool                                                  $is_active Whether the member is active.
		 */
		$member = (array) apply_filters( 'order_updates_for_woo_assignee_rotation_member', $member, $is_active );
		?>
		<li class="awts_assignee_rotation__item" data-awts-rotation-id="<?php echo esc_attr( (string) $member_id ); ?>">
			<input
				type="hidden"
				name="<?php echo esc_attr( $option_id ); ?>[order][]"
				value="<?php echo esc_attr( (string) $member_id ); ?>"
			>
			<span class="awts_assignee_rotation__handle dashicons dashicons-menu" aria-hidden="true"></span>

			<label class="awts_assignee_rotation__label">
				<input
					type="checkbox"
					name="<?php echo esc_attr( $option_id ); ?>[active][]"
					value="<?php echo esc_attr( (string) $member_id ); ?>"
					<?php checked( $is_active ); ?>
				>
				<?php if ( ! empty( $member['avatar'] ) ) : ?>
					<img
						class="awts_assignee_rotation__avatar"
						src="<?php echo esc_url( (string) $member['avatar'] ); ?>"
						alt=""
						width="28"
						height="28"
						loading="lazy"
					>
				<?php endif; ?>
				<span class="awts_assignee_rotation__name"><?php echo esc_html( (string) ( $member['name'] ?? '' ) ); ?></span>
				<span class="awts_assignee_rotation__email"><?php echo esc_html( (string) ( $member['email'] ?? '' ) ); ?></span>
				<?php do_action( 'order_updates_for_woo_assignee_rotation_after_member', $member, $is_active ); ?>
			</label>
		</li>
		<?php
	}

	/**
	 * Load the saved rotation map. Returns `[ id => active_bool ]`,
	 * insertion-ordered. Accepts the legacy flat `[id, id]` shape too —
	 * everything in the old list is treated as active.
	 *
	 * @param string $option_id Option name.
	 * @return array<int,bool>
	 */
	private function load_saved( string $option_id ): array {
		$raw = get_option( $option_id, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$saved = array();

		foreach ( $raw as $key => $value ) {
			if ( is_int( $key ) || ctype_digit( (string) $key ) ) {
				$id     = (int) ( is_array( $value ) || is_bool( $value ) ? $key : $value );
				$active = is_bool( $value ) ? $value : true;
			} else {
				continue;
			}

			if ( $id > 0 ) {
				$saved[ $id ] = (bool) $active;
			}
		}

		return $saved;
	}

	/**
	 * Saved-order ids first (in saved order), then any new members not yet
	 * in the saved list. Newly added staff appear at the bottom so the
	 * admin can opt them in.
	 *
	 * @param array<int,array{id:int,name:string,email:string,avatar:string}> $members     All current members.
	 * @param int[]                                                           $saved_order Saved display order.
	 * @return array<int,array{id:int,name:string,email:string,avatar:string}>
	 */
	private function order_members( array $members, array $saved_order ): array {
		$by_id = array();
		foreach ( $members as $member ) {
			$by_id[ (int) ( $member['id'] ?? 0 ) ] = $member;
		}

		$ordered = array();
		foreach ( $saved_order as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
				unset( $by_id[ $id ] );
			}
		}

		return array_merge( $ordered, array_values( $by_id ) );
	}
}
