<?php
/**
 * Cache section — list of clear-cache action buttons.
 *
 * @package OrderUpdatesForWoo
 *
 * @var array $view_data {
 *     @type array<int, array{id:string, label:string, description:string, url:string}> $buttons
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$buttons = isset( $view_data['buttons'] ) && is_array( $view_data['buttons'] ) ? $view_data['buttons'] : array();
?>
<h2 style="margin-top:1em;"><?php esc_html_e( 'Cache controls', 'order-updates-for-woo' ); ?></h2>
<p class="description" style="margin:0 0 12px;">
	<?php esc_html_e( 'These actions are safe to run at any time. Caches rebuild automatically on the next request that needs them.', 'order-updates-for-woo' ); ?>
</p>

<table class="form-table"><tbody>
	<?php foreach ( $buttons as $button ) : ?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( (string) ( $button['label'] ?? '' ) ); ?></label>
			</th>
			<td class="forminp">
				<p class="description" style="margin-top:0;">
					<?php echo esc_html( (string) ( $button['description'] ?? '' ) ); ?>
				</p>
				<a href="<?php echo esc_url( (string) ( $button['url'] ?? '' ) ); ?>" class="button button-secondary" style="margin-top:6px;">
					<?php echo esc_html( (string) ( $button['label'] ?? '' ) ); ?>
				</a>
			</td>
		</tr>
	<?php endforeach; ?>
</tbody></table>
