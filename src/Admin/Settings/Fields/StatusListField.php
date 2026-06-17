<?php
/**
 * Settings field — admin-managed list of update statuses.
 *
 * Each row carries a label + color and behaves like an entry in the
 * order-update form's status dropdown. Drag to reorder; the saved order
 * is the order the form shows. Renders as a custom WooCommerce settings
 * type stored under the option keyed by the field's `id`.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Fields;

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status List Field.
 */
final class StatusListField {
	public const FIELD_TYPE = 'order_updates_for_woo_status_list';

	private const ASSET_HANDLE = 'order-updates-for-woo-status-list';

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render the custom status-list field.
	 *
	 * @param array{id:string,name:string,desc?:string,desc_tip?:bool} $value Field definition.
	 */
	public function render( array $value ): void {
		$option_id = (string) ( $value['id'] ?? '' );

		if ( '' === $option_id ) {
			return;
		}

		$rows    = $this->load_saved( $option_id );
		$tooltip = ! empty( $value['desc_tip'] ) ? wc_help_tip( (string) ( $value['desc'] ?? '' ) ) : '';
		$desc    = ! empty( $value['desc'] ) && empty( $value['desc_tip'] )
			? '<p class="description">' . wp_kses_post( (string) $value['desc'] ) . '</p>'
			: '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( (string) ( $value['name'] ?? '' ) ); ?></label>
				<?php echo wp_kses_post( $tooltip ); ?>
			</th>
			<td class="forminp">
				<?php echo wp_kses_post( $desc ); ?>

				<ul class="awts_status_list" data-awts-status-list data-awts-option-id="<?php echo esc_attr( $option_id ); ?>">
					<?php foreach ( $rows as $row ) : ?>
						<?php $this->render_row( $option_id, $row ); ?>
					<?php endforeach; ?>
				</ul>

				<button type="button" class="button awts_status_list__add" data-awts-status-add>
					<?php esc_html_e( 'Add status', 'order-updates-for-woo' ); ?>
				</button>

				<template data-awts-status-template>
					<?php
					$this->render_row(
						$option_id,
						array(
							'key'   => '',
							'label' => '',
							'color' => '#2563eb',
						) 
					);
					?>
				</template>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize the submitted status list. The form ships three parallel
	 * arrays — keys, labels, colors — indexed by row position. Status entries
	 * with an empty label OR an invalid hex are dropped silently so a
	 * half-edited row doesn't break the form dropdown.
	 *
	 * @param mixed                          $value     Value sanitized so far.
	 * @param array{type?:string,id?:string} $option    Field definition.
	 * @param mixed                          $raw_value Raw submitted value.
	 *
	 * @return array<int, array{key:string, label:string, color:string}>|mixed
	 */
	public function sanitize( $value, array $option, $raw_value ) {
		if ( self::FIELD_TYPE !== (string) ( $option['type'] ?? '' ) ) {
			return $value;
		}

		if ( ! is_array( $raw_value ) ) {
			return Constants::STATUS_SEED_DEFAULTS;
		}

		$keys   = isset( $raw_value['keys'] ) && is_array( $raw_value['keys'] ) ? $raw_value['keys'] : array();
		$labels = isset( $raw_value['labels'] ) && is_array( $raw_value['labels'] ) ? $raw_value['labels'] : array();
		$colors = isset( $raw_value['colors'] ) && is_array( $raw_value['colors'] ) ? $raw_value['colors'] : array();

		$result    = array();
		$used_keys = array();

		foreach ( $labels as $index => $label_raw ) {
			$label = sanitize_text_field( (string) $label_raw );
			$color = $this->sanitize_hex( (string) ( $colors[ $index ] ?? '' ) );

			if ( '' === $label || '' === $color ) {
				continue;
			}

			$key_raw = isset( $keys[ $index ] ) ? sanitize_key( (string) $keys[ $index ] ) : '';
			$key     = '' !== $key_raw ? $key_raw : $this->derive_key( $label );

			// Collision avoidance — keys must be unique because they're the
			// stable identifier the default-status dropdown points at. If
			// two rows resolve to the same slug, suffix the later one.
			$candidate = $key;
			$suffix    = 2;
			while ( isset( $used_keys[ $candidate ] ) ) {
				$candidate = $key . '-' . $suffix;
				++$suffix;
			}

			$used_keys[ $candidate ] = true;

			$result[] = array(
				'key'   => $candidate,
				'label' => $label,
				'color' => $color,
			);
		}

		return ! empty( $result ) ? $result : Constants::STATUS_SEED_DEFAULTS;
	}

	/**
	 * Enqueue this section's CSS/JS on the WC settings screen.
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

		$css_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/status-list.css';
		$js_file  = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/status-list.js';

		wp_enqueue_style(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/css/status-list.css' ),
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0'
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/js/status-list.js' ),
			array( 'jquery', 'jquery-ui-sortable' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);
	}

	/**
	 * Render one status row in the editable list.
	 *
	 * @param string                                      $option_id Option name.
	 * @param array{key:string,label:string,color:string} $row       Status row data.
	 */
	private function render_row( string $option_id, array $row ): void {
		$key   = (string) ( $row['key'] ?? '' );
		$label = (string) ( $row['label'] ?? '' );
		$color = (string) ( $row['color'] ?? '#2563eb' );
		?>
		<li class="awts_status_list__item" data-awts-status-row>
			<span class="awts_status_list__handle dashicons dashicons-menu" aria-hidden="true"></span>

			<input
				type="hidden"
				name="<?php echo esc_attr( $option_id ); ?>[keys][]"
				value="<?php echo esc_attr( $key ); ?>"
			>

			<input
				type="text"
				class="awts_status_list__label"
				name="<?php echo esc_attr( $option_id ); ?>[labels][]"
				value="<?php echo esc_attr( $label ); ?>"
				placeholder="<?php echo esc_attr__( 'Label, e.g. In progress', 'order-updates-for-woo' ); ?>"
			>

			<input
				type="color"
				class="awts_status_list__color"
				name="<?php echo esc_attr( $option_id ); ?>[colors][]"
				value="<?php echo esc_attr( $color ); ?>"
				aria-label="<?php echo esc_attr__( 'Status color', 'order-updates-for-woo' ); ?>"
			>

			<button
				type="button"
				class="button-link awts_status_list__remove"
				data-awts-status-remove
				aria-label="<?php echo esc_attr__( 'Remove status', 'order-updates-for-woo' ); ?>"
			>&times;</button>
		</li>
		<?php
	}

	/**
	 * Load the saved status rows, falling back to the seed defaults.
	 *
	 * @param string $option_id Option name.
	 * @return array<int, array{key:string, label:string, color:string}>
	 */
	private function load_saved( string $option_id ): array {
		$stored = get_option( $option_id, null );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return Constants::STATUS_SEED_DEFAULTS;
		}

		$rows = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'key'   => (string) ( $row['key'] ?? '' ),
				'label' => (string) ( $row['label'] ?? '' ),
				'color' => (string) ( $row['color'] ?? '#2563eb' ),
			);
		}

		return ! empty( $rows ) ? $rows : Constants::STATUS_SEED_DEFAULTS;
	}

	/**
	 * Derive a URL-safe status key from a label (random suffix if blank).
	 *
	 * @param string $label Status label.
	 */
	private function derive_key( string $label ): string {
		$key = sanitize_key( $label );

		return '' !== $key ? $key : 'status-' . substr( md5( $label . wp_generate_password( 6, false ) ), 0, 6 );
	}

	/**
	 * Normalise a hex color to lowercase `#rrggbb`, or '' if invalid.
	 *
	 * @param string $value Raw color value.
	 */
	private function sanitize_hex( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( '#' !== substr( $value, 0, 1 ) ) {
			$value = '#' . $value;
		}

		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : '';
	}
}
