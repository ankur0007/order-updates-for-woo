<?php
/**
 * Shortcodes section — usage cards.
 *
 * @var array $view_data {
 *     @type array<int, array{
 *         tag:string,
 *         summary:string,
 *         attributes:array<int, array{name:string, required:bool, description:string}>,
 *         url_params:array<int, array{name:string, description:string, example:string}>,
 *         examples:string[]
 *     }> $shortcodes
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$shortcodes = isset( $view_data['shortcodes'] ) && is_array( $view_data['shortcodes'] ) ? $view_data['shortcodes'] : array();
?>
<h2 style="margin-top:1em;"><?php esc_html_e( 'Available shortcodes', 'order-updates-for-woo' ); ?></h2>

<?php foreach ( $shortcodes as $shortcode ) : ?>
	<div style="border:1px solid #d1d5db; border-radius:6px; padding:14px 16px; margin:0 0 14px; max-width:1000px; background:#fff;">

		<p style="margin:0 0 6px;">
			<code style="font-size:14px;"><?php echo esc_html( (string) ( $shortcode['tag'] ?? '' ) ); ?></code>
		</p>

		<p style="margin:0 0 12px; color:#374151;">
			<?php echo esc_html( (string) ( $shortcode['summary'] ?? '' ) ); ?>
		</p>

		<?php $attributes = isset( $shortcode['attributes'] ) && is_array( $shortcode['attributes'] ) ? $shortcode['attributes'] : array(); ?>

		<?php if ( ! empty( $attributes ) ) : ?>
			<p style="margin:0 0 4px; font-size:12px; font-weight:600; color:#374151;">
				<?php esc_html_e( 'Shortcode attributes', 'order-updates-for-woo' ); ?>
			</p>
			<table class="widefat" style="margin-bottom:14px;">
				<thead>
					<tr>
						<th style="width:20%;"><?php esc_html_e( 'Name', 'order-updates-for-woo' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Required', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'order-updates-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $attributes as $attr ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $attr['name'] ?? '' ) ); ?></code></td>
							<td><?php echo ! empty( $attr['required'] ) ? esc_html__( 'yes', 'order-updates-for-woo' ) : esc_html__( 'no', 'order-updates-for-woo' ); ?></td>
							<td><?php echo esc_html( (string) ( $attr['description'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php $url_params = isset( $shortcode['url_params'] ) && is_array( $shortcode['url_params'] ) ? $shortcode['url_params'] : array(); ?>

		<?php if ( ! empty( $url_params ) ) : ?>
			<p style="margin:0 0 4px; font-size:12px; font-weight:600; color:#374151;">
				<?php esc_html_e( 'URL auto-detection', 'order-updates-for-woo' ); ?>
			</p>
			<p style="margin:0 0 8px; font-size:12.5px; color:#6b7280;">
				<?php esc_html_e( 'When the shortcode is rendered without explicit attributes, it reads the page URL\'s query string. Any of the parameter names below resolve to the same target — pick whichever your email-link generator already uses.', 'order-updates-for-woo' ); ?>
			</p>
			<table class="widefat" style="margin-bottom:14px;">
				<thead>
					<tr>
						<th style="width:20%;"><?php esc_html_e( 'Query param', 'order-updates-for-woo' ); ?></th>
						<th style="width:30%;"><?php esc_html_e( 'Example', 'order-updates-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'order-updates-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $url_params as $param ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $param['name'] ?? '' ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $param['example'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $param['description'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $shortcode['examples'] ) ) : ?>
			<p style="margin:0 0 4px; font-size:12px; font-weight:600; color:#374151;">
				<?php esc_html_e( 'Examples', 'order-updates-for-woo' ); ?>
			</p>
			<ul style="margin:4px 0 0 16px;">
				<?php foreach ( (array) $shortcode['examples'] as $example ) : ?>
					<li><code><?php echo esc_html( (string) $example ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
